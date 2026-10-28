<?php

declare(strict_types=1);

use App\Domain\Landing\BlockSchema;
use App\Domain\Landing\CertificateIssuer;
use App\Domain\Landing\DnsVerifier;
use App\Domain\Landing\DomainStatus;
use App\Domain\Landing\LandingDocument;
use App\Domain\Landing\LandingDocumentInvalid;
use App\Domain\Tenancy\TenantRole;
use App\Features\Landing\DomainRefused;
use App\Features\Landing\FixtureDnsVerifier;
use App\Features\Landing\InspectedImage;
use App\Features\Landing\LandingPages;
use App\Features\Landing\LocalCertificateIssuer;
use App\Features\Landing\MediaLibrary;
use App\Features\Landing\MediaRejected;
use App\Features\Landing\PublishedSite;
use App\Features\Landing\TenantDomains;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ImageFixtures;
use Tests\Support\LandingFixtures;
use Tests\Support\StubMediaReferences;

/*
 * ================================================================================
 * THE §11 FIX PASS — one case per finding that produced a behaviour change
 * ================================================================================
 *
 * The QA and Security reviews of 2026-10-28 produced 29 findings between them. The ones
 * that changed behaviour are driven here, each named by its finding, so that a later
 * change which undoes one fails by name rather than by a count moving.
 *
 * The three findings whose regression tests belong beside existing cases are NOT here:
 * the SVG root-attribute Critical is in MediaUploadTest (where the hostile fixtures
 * live), the unpublish hole is in LandingDraftUnreachableTest (where "not reachable"
 * is), and the open-transaction High is in LandingPublishTest (where publish is).
 */

beforeEach(function (): void {
    LandingFixtures::bucket();
    Storage::disk('local')->delete([FixtureDnsVerifier::ZONE, LocalCertificateIssuer::LEDGER]);
});

function domainService(): TenantDomains
{
    return new TenantDomains(app(DnsVerifier::class), app(CertificateIssuer::class));
}

it('refuses a document over 256 KB through the validator, not through a driver error', function (): void {
    // QA #4. The bound was a column CHECK and a number in the generated contract and
    // nothing in between, so a tenant met a raw 23514 instead of §12.1's named refusal.
    // The shape below is the review's own: blocks at the schema's maxima with 4-byte
    // characters, every one of which every other rule accepts.
    $fourByte = str_repeat('𝐀', 1);

    $items = [];

    for ($i = 0; $i < BlockSchema::MAX_ITEMS; $i++) {
        $items[] = [
            'name' => str_repeat($fourByte, 80),
            'description' => str_repeat($fourByte, 200),
            'price' => str_repeat($fourByte, 20),
            'image' => null,
        ];
    }

    $blocks = [];

    for ($i = 0; $i < BlockSchema::MAX_BLOCKS; $i++) {
        $blocks[] = ['type' => 'menu_highlights', 'fields' => [
            'heading' => str_repeat($fourByte, 80),
            'items' => $items,
        ]];
    }

    $refusal = null;

    try {
        LandingDocument::fromInput(['blocks' => $blocks], new StubMediaReferences);
    } catch (LandingDocumentInvalid $e) {
        $refusal = $e;
    }

    expect($refusal)->not->toBeNull('a 370 KB document was accepted by the validator')
        ->and($refusal?->field)->toBe('document')
        ->and($refusal?->reason)->toContain('the limit is '.BlockSchema::MAX_DOCUMENT_BYTES);

    // And it reaches the tenant as a named refusal rather than a QueryException.
    $tenant = $this->makeTenant(slug: 'oversized');

    expect(fn () => (new LandingPages)->saveDraft($tenant, ['blocks' => $blocks]))
        ->toThrow(LandingDocumentInvalid::class);

    expect(withTenant($tenant, fn () => DB::table('landing_pages')->count()))->toBe(0);
});

it('caps the number of domains a tenant may claim', function (): void {
    // QA #7. `hostname` is unique across the platform and `add()` had no bound and no
    // release path, so one tenant could permanently claim any number of hostnames.
    $tenant = $this->makeTenant(slug: 'claimer');

    for ($i = 0; $i < TenantDomains::MAX_DOMAINS; $i++) {
        domainService()->add($tenant, "d{$i}.example.test");
    }

    expect(domainService()->all($tenant))->toHaveCount(TenantDomains::MAX_DOMAINS);

    expect(fn () => domainService()->add($tenant, 'one-too-many.example.test'))
        ->toThrow(DomainRefused::class, 'remove one to add another');

    // The refusal is per TENANT, not global: another tenant is unaffected.
    $other = $this->makeTenant(slug: 'other-claimer');
    expect(domainService()->add($other, 'one-too-many.example.test'))->toBeString();
});

it('does not advance a failed or redirecting domain, and never makes a move the table forbids', function (): void {
    // QA #3 and #5. `failed` shared the `pending_dns` arm, so advancing one would have
    // written `dns_verified` -- which allows()[failed] forbids. And the table was
    // consulted by nothing in production, so it could not have caught that.
    $tenant = $this->makeTenant(slug: 'arms');
    $now = now()->toIso8601String();

    // A `failed` row, which no application path writes -- hence the owner connection.
    $failed = (string) Str::uuid7();
    withTenant($tenant, fn () => DB::connection('pgsql_owner')->table('tenant_domains')->insert([
        'id' => $failed, 'tenant_id' => $tenant,
        'hostname' => 'failed.example.test',
        'verification_token' => bin2hex(random_bytes(16)),
        'status' => DomainStatus::Failed->value,
    ]), 'pgsql_owner');

    expect(domainService()->advance($tenant, $failed))->toBe(DomainStatus::Failed);
    expect(withTenant($tenant, fn () => DB::table('tenant_domains')->where('id', $failed)->value('status')))
        ->toBe(DomainStatus::Failed->value);

    // A `redirecting` row: also a no-op, and also never driven before.
    $active = (string) Str::uuid7();
    $redirecting = (string) Str::uuid7();

    withTenant($tenant, function () use ($tenant, $active, $redirecting, $now): void {
        $owner = DB::connection('pgsql_owner');

        $owner->table('tenant_domains')->insert([
            'id' => $active, 'tenant_id' => $tenant, 'hostname' => 'live.example.test',
            'verification_token' => bin2hex(random_bytes(16)),
            'status' => DomainStatus::Active->value, 'dns_verified_at' => $now,
            'certificate_identifier' => 'c', 'certificate_issued_at' => $now, 'activated_at' => $now,
        ]);

        $owner->table('tenant_domains')->insert([
            'id' => $redirecting, 'tenant_id' => $tenant, 'hostname' => 'old.example.test',
            'verification_token' => bin2hex(random_bytes(16)),
            'status' => DomainStatus::Redirecting->value, 'dns_verified_at' => $now,
            'redirects_to_domain_id' => $active,
        ]);
    }, 'pgsql_owner');

    expect(domainService()->advance($tenant, $redirecting))->toBe(DomainStatus::Redirecting)
        ->and(domainService()->advance($tenant, $active))->toBe(DomainStatus::Active);
});

it('refuses the challenge for another tenants domain, in the same words as one that does not exist', function (): void {
    // Security #11. `advance()` had this case and `challenge()` -- the method that
    // returns a credential-shaped value -- did not.
    $alpha = $this->makeTenant(slug: 'ch-alpha');
    $beta = $this->makeTenant(slug: 'ch-beta');

    $id = domainService()->add($alpha, 'ch.example.test');

    $foreign = null;
    $absent = null;

    try {
        domainService()->challenge($beta, $id);
    } catch (DomainRefused $e) {
        $foreign = $e->reason;
    }

    try {
        domainService()->challenge($beta, (string) Str::uuid7());
    } catch (DomainRefused $e) {
        $absent = $e->reason;
    }

    expect($foreign)->toBe($absent)
        ->and($foreign)->toContain('not on your account');

    // And alpha's own challenge still works, so the refusal is discriminating.
    expect(domainService()->challenge($alpha, $id)['value'])->toStartWith('qrpager-verification=');
});

it('answers a malformed id the same way it answers an unknown one', function (): void {
    // Security #8 / QA #11, R-110's shape: a non-uuid reaching a uuid column raises
    // 22P02, so "malformed" answered differently from "not yours".
    $this->actingForTenant(role: TenantRole::Owner);

    foreach (['not-a-uuid', '../etc/passwd', '%00', '1 OR 1=1'] as $bad) {
        expect($this->get('/studio/preview/asset/'.urlencode($bad))->status())
            ->toBe(404, "[{$bad}] did not answer 404");
    }

    expect($this->get('/studio/preview/asset/'.Str::uuid7())->status())->toBe(404);
});

it('puts Filaments Authenticate on every studio route, including the preview', function (): void {
    // Security #4. The preview was registered with routes() rather than
    // authenticatedRoutes(), so it got the panel's middleware stack WITHOUT
    // Authenticate -- while two places in the diff said it got both.
    $missing = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with('/'.ltrim($route->uri(), '/'), '/studio')) {
            continue;
        }

        if (! in_array(Authenticate::class, $route->gatherMiddleware(), true)) {
            $missing[] = implode('|', $route->methods()).' /'.ltrim($route->uri(), '/');
        }
    }

    expect($missing)->toBe([], 'studio routes without Filament\Http\Middleware\Authenticate: '.implode(', ', $missing));
});

it('serves a content security policy on the preview document, not only on its assets', function (): void {
    // Security #6. The document is the tenant's own draft rendered to HTML on the
    // origin that carries the panel's session cookies; the image inside it had the
    // stricter headers and the document had none.
    $tenant = $this->actingForTenant(role: TenantRole::Owner);
    $asset = (new MediaLibrary)->store($tenant, 'logo.png', ImageFixtures::png());

    (new LandingPages)->saveDraft($tenant, LandingFixtures::fullPage($asset));

    $document = $this->get('/studio/preview');

    expect($document->status())->toBe(200)
        ->and($document->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")
        ->and($document->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'")
        ->and($document->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($document->headers->get('X-Frame-Options'))->toBe('DENY');

    // The asset keeps its own, tighter policy -- an image needs no images.
    $image = $this->get('/studio/preview/asset/'.$asset);

    expect($image->headers->get('Content-Security-Policy'))->toContain('sandbox')
        ->and($image->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('refuses a fixture lifecycle driver outside local and testing', function (): void {
    // Security #7 / QA #6. Both drivers default to `local`, neither was in
    // .env.example, and `local` verifies DNS from a file on this disk and fabricates
    // certificate identifiers -- which is what satisfies the CHECK that SR-2 calls the
    // falsifiable half.
    app()->detectEnvironment(fn (): string => 'production');

    try {
        app()->forgetInstance(DnsVerifier::class);
        app()->forgetInstance(CertificateIssuer::class);

        expect(fn () => app(DnsVerifier::class))
            ->toThrow(RuntimeException::class, 'FIXTURE driver');
        expect(fn () => app(CertificateIssuer::class))
            ->toThrow(RuntimeException::class, 'FIXTURE driver');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
        app()->forgetInstance(DnsVerifier::class);
        app()->forgetInstance(CertificateIssuer::class);
    }

    // And it resolves normally in testing, so the refusal is conditional rather than total.
    expect(app(DnsVerifier::class))->toBeInstanceOf(FixtureDnsVerifier::class);
});

it('gives a published page exactly one h1 whatever blocks it has', function (): void {
    // QA #8. The h1 lived inside the `hero` case; hero is optional and blocks repeat, so
    // a page could have none (and open at h2, a skipped level) or two.
    $documents = [
        'no hero' => ['blocks' => [
            ['type' => 'gallery', 'fields' => ['images' => []]],
            ['type' => 'contact', 'fields' => ['heading' => 'Contact']],
        ]],
        'two heroes' => ['blocks' => [
            ['type' => 'hero', 'fields' => ['heading' => 'First']],
            ['type' => 'hero', 'fields' => ['heading' => 'Second']],
        ]],
        'one hero' => ['blocks' => [['type' => 'hero', 'fields' => ['heading' => 'Only']]]],
    ];

    foreach ($documents as $what => $input) {
        $tenant = $this->makeTenant(slug: 'h1-'.substr(md5($what), 0, 8));
        $pages = new LandingPages;

        $pages->saveDraft($tenant, $input);
        $published = $pages->publish($tenant);

        $html = (string) MediaLibrary::disk()->get(PublishedSite::pointer($published->hostname));

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);

        expect($xpath->query('//h1')?->length)->toBe(1, "[{$what}] does not have exactly one h1");

        // And no skipped level, walked the way the studio pages are.
        $levels = [];

        foreach ($xpath->query('//h1|//h2|//h3|//h4|//h5|//h6') ?: [] as $heading) {
            $levels[] = (int) substr($heading->nodeName, 1);
        }

        $seen = 0;

        foreach ($levels as $level) {
            expect($level - $seen)->toBeLessThanOrEqual(1, "[{$what}] skips from h{$seen} to h{$level}");
            $seen = max($seen, $level);
        }
    }
});

it('accepts an upload of exactly 5 MB and refuses one byte more', function (): void {
    // QA #16. The size bound was tested only well past the limit, so an off-by-one
    // between `>` and `>=` would have passed.
    $tenant = $this->makeTenant(slug: 'boundary');
    $png = ImageFixtures::png();

    // A PNG padded with a trailing comment chunk is still a PNG to libmagic and still
    // decodes, so the only thing varying between these two cases is one byte of size.
    $pad = InspectedImage::MAX_BYTES - strlen($png);

    $atLimit = $png.str_repeat("\x00", $pad);
    expect(strlen($atLimit))->toBe(InspectedImage::MAX_BYTES);

    expect((new MediaLibrary)->store($tenant, 'exactly.png', $atLimit))->toBeString();

    $overLimit = $atLimit."\x00";

    expect(fn () => (new MediaLibrary)->store($tenant, 'one-more.png', $overLimit))
        ->toThrow(MediaRejected::class, 'the limit is 5 MB');
});
