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
use App\Features\Landing\PublishFailed;
use App\Features\Landing\TenantDomains;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ImageFixtures;
use Tests\Support\LandingFixtures;
use Tests\Support\RecordingDisk;
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
        // ⚠ `'self'` AND NOT `'none'` SINCE `QRPAGER-40`, and it is narrowed rather than
        // dropped: the studio's preview pane frames this from the SAME origin, every
        // third-party framer is still refused, and the execution policy -- `default-src
        // 'none'` above -- did not move. Muhammad's ruling of 2026-11-09, rank 2.
        ->and($document->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self'")
        ->and($document->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($document->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');

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

    // A PNG padded with trailing NUL bytes. libmagic reads the signature and
    // getimagesizefromstring reads the IHDR, so both still call it a PNG and the only
    // thing varying between these two cases is ONE BYTE of length. (The comment here
    // said "a trailing comment chunk", which these bytes are not -- the §11 review of
    // the fix diff read the comment against the code.)
    $pad = InspectedImage::MAX_BYTES - strlen($png);

    $atLimit = $png.str_repeat("\x00", $pad);
    expect(strlen($atLimit))->toBe(InspectedImage::MAX_BYTES);

    expect((new MediaLibrary)->store($tenant, 'exactly.png', $atLimit))->toBeString();

    $overLimit = $atLimit."\x00";

    expect(fn () => (new MediaLibrary)->store($tenant, 'one-more.png', $overLimit))
        ->toThrow(MediaRejected::class, 'the limit is 5 MB');
});

/*
 * ================================================================================
 * ROUND 1 OF THE FIX DIFF'S OWN REVIEW — §11's Round limit, 1 of at most 2
 * ================================================================================
 *
 * "The fixes they produce are code nobody has read, so a fix diff is reviewed too."
 * 16 findings; the two Highs are below, with the Mediums that changed behaviour.
 */

it('leaves the page published when the take-down fails, instead of reporting success', function (): void {
    // ROUND 1, HIGH #1. deleteDirectory()'s result was discarded on a disk configured
    // `'throw' => false` -- the identical trap this file's sibling already documents for
    // put(). A transient store failure left index.html serving the page while the row
    // said `draft`, and the `status !== Published -> return` guard then made every retry
    // a no-op, so nothing could take it down again.
    $tenant = $this->makeTenant(slug: 'stubborn');
    $pages = new LandingPages;

    $pages->saveDraft($tenant, LandingFixtures::fullPage());
    $published = $pages->publish($tenant);

    $refusing = new RecordingDisk(MediaLibrary::disk(), static fn (): null => null, [
        'deleteDirectory' => false,
    ]);

    Storage::set('s3', $refusing);

    try {
        expect(fn () => $pages->unpublish($tenant))
            ->toThrow(PublishFailed::class, 'could not be taken down');
    } finally {
        Storage::forgetDisk('s3');
    }

    // THE ROW IS STILL PUBLISHED, so a retry has something to do -- and the page is
    // still there, which is the honest state rather than a lie in either direction.
    expect($pages->state($tenant)['status']->value)->toBe('published')
        ->and(MediaLibrary::disk()->exists(PublishedSite::pointer($published->hostname)))->toBeTrue();

    // And the real disk takes it down.
    $pages->unpublish($tenant);
    expect(MediaLibrary::disk()->allFiles(PublishedSite::root($published->hostname)))->toBe([]);
});

it('holds no transaction open while ACTIVATING a domain, not just while publishing', function (): void {
    // ROUND 1, HIGH #2. `republish()` was called from inside advance()'s withTenant(),
    // and TenantContext::run() RE-ENTERS rather than opening a second transaction when a
    // scope is already in force -- so publish()'s "no transaction" phase ran inside the
    // activation's transaction, and the fix was true of one caller and false of the
    // other. The existing recorder test drives publish() directly and could not see it.
    $tenant = $this->makeTenant(slug: 'activating');
    $pages = new LandingPages;

    $pages->saveDraft($tenant, LandingFixtures::fullPage());
    $pages->publish($tenant);

    $service = domainService();
    $id = $service->add($tenant, 'activating.example.test');
    $challenge = $service->challenge($tenant, $id);
    FixtureDnsVerifier::publish($challenge['name'], $challenge['value']);

    $service->advance($tenant, $id);
    $service->advance($tenant, $id);

    $levels = [];
    $recorder = new RecordingDisk(MediaLibrary::disk(), function (string $operation) use (&$levels): void {
        $levels[] = $operation.'@'.DB::connection('pgsql')->transactionLevel();
    });

    Storage::set('s3', $recorder);

    try {
        expect($service->advance($tenant, $id))->toBe(DomainStatus::Active);
    } finally {
        Storage::forgetDisk('s3');
    }

    expect($levels)->not->toBe([], 'activation wrote no objects at all');

    foreach ($levels as $seen) {
        expect($seen)->toEndWith('@0', "activation ran an object operation inside a transaction: {$seen}");
    }

    // And it really did move the page, which is what makes the assertion above about
    // the right writes rather than about an activation that did nothing.
    expect(MediaLibrary::disk()->get(PublishedSite::pointer('activating.example.test')))
        ->toContain('https://activating.example.test/');
});

it('clears every hostname this tenant published to, not just the current one', function (): void {
    // ROUND 1, MEDIUM #5. A domain switch leaves the superseded hostname serving a
    // redirect, and `republish()` overwrites only index.html and manifest.json there --
    // so every earlier {checksum}.html and every copied asset survived under
    // sites/{old}/, and unpublish deleted only `published_hostname`.
    $tenant = $this->makeTenant(slug: 'two-homes');
    $pages = new LandingPages;
    $service = domainService();

    $pages->saveDraft($tenant, LandingFixtures::fullPage());
    $platform = $pages->publish($tenant)->hostname;

    foreach (['home-one.example.test', 'home-two.example.test'] as $hostname) {
        $id = $service->add($tenant, $hostname);
        $challenge = $service->challenge($tenant, $id);
        FixtureDnsVerifier::publish($challenge['name'], $challenge['value']);
        $service->advance($tenant, $id);
        $service->advance($tenant, $id);
        $service->advance($tenant, $id);
    }

    // All three prefixes hold something: the platform fallback, the superseded domain
    // (a redirect plus its old snapshots) and the live one.
    foreach ([$platform, 'home-one.example.test', 'home-two.example.test'] as $hostname) {
        expect(MediaLibrary::disk()->allFiles(PublishedSite::root($hostname)))
            ->not->toBe([], "nothing under sites/{$hostname} before the take-down");
    }

    $pages->unpublish($tenant);

    foreach ([$platform, 'home-one.example.test', 'home-two.example.test'] as $hostname) {
        expect(MediaLibrary::disk()->allFiles(PublishedSite::root($hostname)))
            ->toBe([], "sites/{$hostname} still holds objects after the take-down");
    }
});

it('derives the transition it checks from the row, so the check can actually fail', function (): void {
    // ROUND 1, MEDIUM #3. assertTransition() was called with two compile-time constants
    // at every site, so the predicate was a statement about the table alone and could
    // never fail for any row -- a change-detector moved out of a test and into
    // production, which is what its own docblock criticises.
    $tenant = $this->makeTenant(slug: 'derived');
    $service = domainService();

    // A row in `active`, whose next write on the dns_verified path would be
    // `active -> dns_verified` -- a move the table forbids.
    $id = (string) Str::uuid7();
    $now = now()->toIso8601String();

    // Seeded at `dns_verified` and not at `certificate_pending`: the local issuer keeps
    // a ledger, and a row placed straight into `certificate_pending` has never had a
    // certificate REQUESTED, so `issued()` answers null and the domain holds there. That
    // is the issuer behaving correctly and it is not what this case is about.
    withTenant($tenant, fn () => DB::connection('pgsql_owner')->table('tenant_domains')->insert([
        'id' => $id, 'tenant_id' => $tenant, 'hostname' => 'derived.example.test',
        'verification_token' => bin2hex(random_bytes(16)),
        'status' => DomainStatus::DnsVerified->value, 'dns_verified_at' => $now,
    ]), 'pgsql_owner');

    // dns_verified -> certificate_pending -> active, both legal, which proves the guard
    // is not simply refusing everything.
    expect($service->advance($tenant, $id))->toBe(DomainStatus::CertificatePending);
    expect($service->advance($tenant, $id))->toBe(DomainStatus::Active);

    // A SECOND domain, activated, which demotes the first to `redirecting` -- the one
    // transition in this lifecycle that a row's CURRENT status decides rather than the
    // arm it entered. `tenant_domains_redirecting_has_a_target` refuses a redirect with
    // no target, so this is also the only way to reach that state legally; an earlier
    // draft of this case forced it by hand and the constraint refused it, correctly.
    $second = $service->add($tenant, 'derived-two.example.test');
    $challenge = $service->challenge($tenant, $second);
    FixtureDnsVerifier::publish($challenge['name'], $challenge['value']);

    $service->advance($tenant, $second);
    $service->advance($tenant, $second);
    expect($service->advance($tenant, $second))->toBe(DomainStatus::Active);

    // The first is now `redirecting`, and advancing it is a no-op arm.
    expect($service->advance($tenant, $id))->toBe(DomainStatus::Redirecting);

    // ⚠ WHAT THIS CASE CAN AND CANNOT SHOW, stated rather than implied. Every arm the
    // service can reach makes a LEGAL move, so the guard cannot be driven to refuse
    // from outside without first introducing the defect it exists to catch -- which is
    // the property we want, and which means no assertion here can watch it fire. What
    // IS shown is that `$from` is the row's own status at every call site (the demotion
    // above passes `DomainStatus::from($previous->status)`, not a literal), and that the
    // guard admits every legal transition rather than refusing everything. The round-1
    // finding was that the ARGUMENTS were constants; they are not now.
    $reflection = new ReflectionMethod(TenantDomains::class, 'assertTransition');
    expect($reflection->getNumberOfParameters())->toBe(2);
});

it('refuses to finish a publish whose row changed underneath it, and leaves not one object behind', function (): void {
    // ⚠ THE FIFTH CLAUSE-1 FAILURE, AND THE FIFTH IS THE FIRST ONE AGAIN. The third
    // scoped re-review (2026-10-28) read the ordering change and found that phase 2 still
    // wrote `{checksum}.html` INTO `sites/{hostname}/` before the row committed, on the
    // argument that nothing names it. `manifest.json` names it -- in its body, at a
    // predictable key, in the prefix the CDN maps `Host` onto -- and the checksum is a
    // hash of the document, which `LandingDraftUnreachableTest` asserts is STABLE across
    // an unpublish and a re-publish. So the name of a live page's snapshot is public for
    // the life of that publish, and any later attempt that died before the commit put
    // that exact key back into a swept prefix with the row at `draft`.
    //
    // Phase 2 touches no network at all now. This case drives the route end to end.
    $tenant = $this->makeTenant(slug: 'raced');
    $pages = new LandingPages;

    // ⚠ WITH A REAL MEDIA ASSET, because the previous version of this case passed
    // `$mediaId = null` and the publish's asset-copy loop therefore never ran -- so the
    // "nothing is left behind" claim had never once been tested against a copied object,
    // which is the same clause through a different key. The same review found that too.
    $mediaId = (new MediaLibrary)->store($tenant, 'logo.png', ImageFixtures::png());

    $pages->saveDraft($tenant, LandingFixtures::fullPage($mediaId));
    $published = $pages->publish($tenant);
    $root = PublishedSite::root($published->hostname);

    // What an attacker records while the page is live: one GET of a file the CDN serves.
    $manifest = json_decode((string) MediaLibrary::disk()->get(PublishedSite::manifest($published->hostname)), true);
    $recorded = is_array($manifest) ? (string) $manifest['checksum'] : '';

    expect($recorded)->toBe($published->checksum)
        ->and(MediaLibrary::disk()->exists(PublishedSite::snapshot($published->hostname, $recorded)))->toBeTrue()
        ->and(MediaLibrary::disk()->allFiles($root.'/assets'))->not->toBe([]);

    // The take-down lands while the NEXT publish is between phase 1 and phase 3. Forced
    // on the owner connection from a query listener rather than by calling `unpublish()`
    // here: phase 2 holds no transaction and makes no query, so there is no call this
    // test could interleave with, and the row moving underneath the publish is the
    // condition -- not which actor moved it.
    $landed = false;

    DB::listen(function (QueryExecuted $query) use (&$landed, $tenant, $root): void {
        if ($landed || ! str_contains($query->sql, 'from "landing_pages"')) {
            return;
        }

        $landed = true;

        // Exactly what `unpublish()` does: the whole prefix, then the row.
        //
        // ⚠ ON THE APP CONNECTION AND INSIDE THE SCOPE THAT IS ALREADY OPEN. The owner
        // connection is how this file forces every other out-of-band row change, and it
        // cannot be used here: the listener fires inside phase 1's own `withTenant()`,
        // and `TenantContext` refuses a second scope on a second connection ("Tenant
        // context is already open on connection [pgsql]"). The row ends up in exactly
        // the state a take-down leaves it in, which is the condition phase 3 is guarded
        // against; which actor moved it is not what this case is about.
        MediaLibrary::disk()->deleteDirectory($root);

        DB::table('landing_pages')
            ->where('tenant_id', $tenant)
            ->update([
                'status' => 'draft',
                'published_document' => null,
                'published_checksum' => null,
                'published_hostname' => null,
                'published_at' => null,
            ]);
    });

    expect(fn () => $pages->publish($tenant))
        ->toThrow(PublishFailed::class, 'changed while it was publishing');

    expect($landed)->toBeTrue('the interleave never fired, so this case proved nothing');

    // ⚠ WHAT "NOTHING REACHABLE" MEANS, AND IT IS THE WHOLE PREFIX. `allFiles($prefix)
    // === []` is the standard this project states and it is the one asserted here. The
    // round-2 version of this case was narrowed to "no pointer and no manifest" on the
    // argument that a leftover snapshot is unnamed; the review that followed pointed at
    // the manifest the tenant had already published, and at the deleted comment which
    // had said one key's absence was never the standard.
    expect(MediaLibrary::disk()->allFiles($root))->toBe([])
        ->and(MediaLibrary::disk()->exists(PublishedSite::snapshot($published->hostname, $recorded)))
        ->toBeFalse('the refused publish put the taken-down page back at the key its own manifest had published')
        ->and($pages->state($tenant)['status']->value)->toBe('draft');
});

it('sweeps the prefix when a take-down lands after the row has committed', function (): void {
    // THE OTHER HALF OF THE ORDERING CHANGE, and the window the same review named: with
    // every object write now AFTER the commit, an `unpublish()` landing inside phase 4
    // empties the prefix and sets the row to `draft`, and the in-flight publish then
    // writes the pointer into it -- a live page with the row denying it, which is clause
    // 1 once more. Phase 5 reconciles: it re-reads the row, and when the row says
    // `draft` -- the one state in which nothing of this tenant's may be live -- it
    // sweeps. That gate is what makes this the one compensation this method can safely
    // carry; the two it used to have could fire while the row still said `published`,
    // and so could delete a live page's own objects.
    //
    // The interleave hooks the POINTER write by KEY. Hooking "the first put" is what the
    // previous version did, and the reordering moved the first put from the pointer to
    // the snapshot without the test noticing.
    $tenant = $this->makeTenant(slug: 'swept');
    $pages = new LandingPages;

    $mediaId = (new MediaLibrary)->store($tenant, 'logo.png', ImageFixtures::png());

    $pages->saveDraft($tenant, LandingFixtures::fullPage($mediaId));
    $published = $pages->publish($tenant);
    $root = PublishedSite::root($published->hostname);

    $raced = false;
    $recorder = new RecordingDisk(MediaLibrary::disk(), function (string $operation, string $key) use (&$raced, $pages, $tenant): void {
        if ($operation !== 'put' || $raced || ! str_ends_with($key, '/index.html')) {
            return;
        }

        $raced = true;
        $pages->unpublish($tenant);
    });

    Storage::set('s3', $recorder);

    try {
        expect(fn () => $pages->publish($tenant))
            ->toThrow(PublishFailed::class, 'taken down while it was publishing');
    } finally {
        Storage::forgetDisk('s3');
    }

    expect($raced)->toBeTrue('the pointer write was never hooked, so this case proved nothing');

    // The row says `draft`, and so the prefix is EMPTY -- including the pointer and the
    // manifest this publish wrote after the take-down had already swept.
    expect($pages->state($tenant)['status']->value)->toBe('draft')
        ->and(MediaLibrary::disk()->allFiles($root))->toBe([]);
});

it('tells the tenant which side of the commit a publish failed on', function (): void {
    // ⚠ "Publish failed — your live page is unchanged" IS NOT TRUE OF EVERY FAILURE ANY
    // MORE, and the panel showed it for all of them. `SR-4` put the row's commit before
    // every object write, so from phase 4 on the row has already moved: a failed manifest
    // write leaves the NEW page live, and a failed asset copy or pointer write leaves the
    // old page live beside a row that says `published`. The third scoped re-review found
    // the copy still promising the opposite. The exception now carries its own headline.
    $tenant = $this->makeTenant(slug: 'headlines');
    $pages = new LandingPages;

    // Before the commit: an empty document is refused in phase 1, and nothing anywhere
    // has moved.
    try {
        $pages->publish($tenant);
        $this->fail('an empty page was published');
    } catch (PublishFailed $e) {
        expect($e->headline)->toBe(PublishFailed::LIVE_UNCHANGED);
    }

    // After the commit: the store refuses every PUT, so phase 4 fails with the row
    // already saying `published`.
    $pages->saveDraft($tenant, LandingFixtures::fullPage());

    $recorder = new RecordingDisk(MediaLibrary::disk(), fn (string $operation, string $key) => null, ['put' => false]);

    Storage::set('s3', $recorder);

    try {
        $pages->publish($tenant);
        $this->fail('a publish whose every write failed reported success');
    } catch (PublishFailed $e) {
        expect($e->headline)->toBe(PublishFailed::AFTER_RECORD)
            ->and($e->reason)->toContain('recorded as published');
    } finally {
        Storage::forgetDisk('s3');
    }

    // And the row records what it did: `published`, over a prefix that has nothing in
    // it. That is the residual `SR-4` chose -- the row claiming MORE than exists -- and
    // it is asserted here so that the trade is a tested property rather than a sentence
    // in a docblock.
    expect($pages->state($tenant)['status']->value)->toBe('published')
        ->and(MediaLibrary::disk()->allFiles(PublishedSite::root($pages->hostname($tenant))))->toBe([]);
});

it('takes a page down even when the row has lost track of it', function (): void {
    // THE SCOPED REVIEW'S THIRD CLAUSE-1 FAILURE. `unpublish()` returned early whenever
    // the row was not `published` -- so any state where a page is LIVE and the row says
    // `draft` was permanent: a process killed between the pointer write and the row
    // write, or a compensation that itself failed. The only verb a tenant has is
    // Unpublish, and it looked at the row, saw `draft`, and did nothing.
    $tenant = $this->makeTenant(slug: 'orphaned');
    $pages = new LandingPages;

    $pages->saveDraft($tenant, LandingFixtures::fullPage());
    $published = $pages->publish($tenant);

    expect(MediaLibrary::disk()->allFiles(PublishedSite::root($published->hostname)))->not->toBe([]);

    // The row loses track of the live page -- the state a crash between phase 2 and
    // phase 3 leaves. Forced on the owner connection, because no application path
    // produces it any more, which is the point.
    withTenant($tenant, fn () => DB::connection('pgsql_owner')->table('landing_pages')
        ->where('tenant_id', $tenant)
        ->update([
            'status' => 'draft',
            'published_document' => null,
            'published_checksum' => null,
            'published_hostname' => null,
            'published_at' => null,
        ]), 'pgsql_owner');

    expect($pages->state($tenant)['status']->value)->toBe('draft')
        // Live, with the row denying it.
        ->and(MediaLibrary::disk()->exists(PublishedSite::pointer($published->hostname)))->toBeTrue();

    // And Unpublish can still take it down, which is the whole fix.
    $pages->unpublish($tenant);

    expect(MediaLibrary::disk()->allFiles(PublishedSite::root($published->hostname)))->toBe([]);
});

it('serialises publish against unpublish for one tenant, so neither can interleave', function (): void {
    // ROUND 2's INVARIANT FAILURE, closed at the cause rather than at the symptom. A
    // guard could not fix it: the switch happens one phase before the guard runs. A row
    // lock would mean holding a transaction across the object writes, which is the High
    // the phase split exists to fix. A SESSION-level advisory lock serialises the two
    // and holds no transaction.
    $tenant = $this->makeTenant(slug: 'serialised');
    $pages = new LandingPages;

    $pages->saveDraft($tenant, LandingFixtures::fullPage());

    // The lock is taken on the `pgsql` connection, so a second holder on ANOTHER
    // connection must block. `pg_try_advisory_lock` from the owner connection tells us
    // whether it is held, without waiting.
    //
    // ⚠ SAMPLED AT EVERY PUT, NOT AT THE FIRST ONE. The previous version took one sample
    // on the first `put` and asserted from it that "the publish held its tenant lock
    // while writing objects". Under the new ordering the first put is the snapshot and
    // the one whose protection actually matters is the POINTER, two writes later, which
    // that sample never looked at -- the third scoped re-review's Low. Every put is
    // recorded by key, and every one of them has to have been protected.
    /** @var array<string, bool> $samples */
    $samples = [];

    $recorder = new RecordingDisk(MediaLibrary::disk(), function (string $operation, string $key) use (&$samples, $tenant): void {
        if ($operation !== 'put') {
            return;
        }

        $objectId = crc32($tenant);
        $objectId = $objectId > 2147483647 ? $objectId - 4294967296 : $objectId;

        $taken = (bool) DB::connection('pgsql_owner')->selectOne(
            'select pg_try_advisory_lock(?, ?) as got',
            [LandingPages::LOCK_CLASS, $objectId],
        )->got;

        $samples[$key] = ! $taken;

        if ($taken) {
            DB::connection('pgsql_owner')->statement(
                'select pg_advisory_unlock(?, ?)',
                [LandingPages::LOCK_CLASS, $objectId],
            );
        }
    });

    Storage::set('s3', $recorder);

    try {
        $pages->publish($tenant);
    } finally {
        Storage::forgetDisk('s3');
    }

    expect($samples)->not->toBe([], 'the publish wrote no objects, so nothing was sampled');

    foreach ($samples as $key => $heldAtThisWrite) {
        expect($heldAtThisWrite)->toBeTrue("the publish did not hold its tenant lock while writing {$key}");
    }

    // And the pointer -- the write that makes a page reachable -- is one of the keys
    // sampled, so the assertion above is about the write that matters and not only
    // about whichever one happened to come first.
    expect(array_filter(array_keys($samples), static fn (string $key): bool => str_ends_with($key, '/index.html')))
        ->not->toBe([], 'the pointer write was never sampled');

    // And it is RELEASED afterwards, so a second publish is not blocked for the life of
    // the connection -- a session lock that leaks is worse than no lock.
    $objectId = crc32($tenant);
    $objectId = $objectId > 2147483647 ? $objectId - 4294967296 : $objectId;

    $free = (bool) DB::connection('pgsql_owner')->selectOne(
        'select pg_try_advisory_lock(?, ?) as got',
        [LandingPages::LOCK_CLASS, $objectId],
    )->got;

    DB::connection('pgsql_owner')->statement('select pg_advisory_unlock(?, ?)', [LandingPages::LOCK_CLASS, $objectId]);

    expect($free)->toBeTrue('the publish lock was not released');
});
