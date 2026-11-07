<?php

declare(strict_types=1);

use App\Domain\Landing\CertificateIssuer;
use App\Domain\Landing\DnsVerifier;
use App\Domain\Landing\DomainStatus;
use App\Features\Landing\FixtureDnsVerifier;
use App\Features\Landing\LandingPages;
use App\Features\Landing\LocalCertificateIssuer;
use App\Features\Landing\MediaLibrary;
use App\Features\Landing\PublishedSite;
use App\Features\Landing\TenantDomains;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LandingFixtures;

/*
 * §3 row 7, clause 4, second half: "the old domain redirects".
 *
 * ⚠ A STATIC OBJECT CANNOT SEND A 301, and this file says so rather than the handover
 * claiming one. A 301 at the edge is a CloudFront function or a bucket redirection rule,
 * both Terraform and therefore Task 13's (SR-2). What Task 7 owns, and what is asserted
 * here, is that the old hostname STOPS SERVING THE PAGE and points at the new one -- by
 * canonical link, meta refresh and a visible link, which work with and without
 * JavaScript and tell a search engine which hostname is real.
 */

beforeEach(function (): void {
    LandingFixtures::bucket();
    Storage::disk('local')->delete([FixtureDnsVerifier::ZONE, LocalCertificateIssuer::LEDGER]);
});

/**
 * Add a hostname, satisfy its challenge, and walk it to `active`.
 *
 * It takes no TestCase: Pest binds test closures to its OWN subclass (P\Tests\…), so a
 * `TestCase` parameter type on a file-scope helper is a TypeError at every call site.
 */
function activate(string $tenant, string $hostname): string
{
    $service = new TenantDomains(app(DnsVerifier::class), app(CertificateIssuer::class));
    $id = $service->add($tenant, $hostname);

    $challenge = $service->challenge($tenant, $id);
    FixtureDnsVerifier::publish($challenge['name'], $challenge['value']);

    $service->advance($tenant, $id);
    $service->advance($tenant, $id);
    $service->advance($tenant, $id);

    return $id;
}

it('moves the live page to the new hostname and leaves a redirect on the old one', function (): void {
    $tenant = $this->makeTenant(slug: 'moving');
    $pages = new LandingPages;

    $pages->saveDraft($tenant, LandingFixtures::fullPage());
    $first = $pages->publish($tenant);

    expect($first->hostname)->toBe('moving.'.config('landing.platform_domain'));

    // The first custom domain goes live.
    activate($tenant, 'one.example.test');

    // The page is now served at the custom hostname, and its canonical URL says so --
    // which is why activation RE-PUBLISHES rather than copying an object: a published
    // page's bytes carry their own hostname.
    $onePointer = (string) MediaLibrary::disk()->get(PublishedSite::pointer('one.example.test'));

    expect($onePointer)->toContain('https://one.example.test/')
        ->and($onePointer)->toContain('Welcome to Olive');

    // The platform hostname is NOT redirected: it was never an `active` tenant_domains
    // row, it is the fallback the page was published to before any domain existed. That
    // is a real gap and it is R-133, not something this test papers over.
    expect(MediaLibrary::disk()->exists(PublishedSite::pointer($first->hostname)))->toBeTrue();

    // A SECOND custom domain goes live, and the first becomes the old one.
    $second = activate($tenant, 'two.example.test');

    $rows = collect((new TenantDomains(app(DnsVerifier::class), app(CertificateIssuer::class)))->all($tenant))
        ->keyBy('hostname');

    expect($rows['two.example.test']->status)->toBe(DomainStatus::Active)
        ->and($rows['one.example.test']->status)->toBe(DomainStatus::Redirecting)
        ->and($rows['one.example.test']->redirectsToDomainId)->toBe($second);

    // THE OLD HOSTNAME NO LONGER SERVES THE PAGE. It serves a redirect document.
    $oldPointer = (string) MediaLibrary::disk()->get(PublishedSite::pointer('one.example.test'));

    expect($oldPointer)->not->toContain('Welcome to Olive')
        ->and($oldPointer)->toContain('<link rel="canonical" href="https://two.example.test/">')
        ->and($oldPointer)->toContain('content="0; url=https://two.example.test/"')
        ->and($oldPointer)->toContain('<a href="https://two.example.test/">two.example.test</a>')
        ->and($oldPointer)->toContain('name="robots" content="noindex"');

    // And the manifest records the redirect, which is what Task 13's edge rule will read
    // to turn this into a real 301.
    $manifest = json_decode(
        (string) MediaLibrary::disk()->get(PublishedSite::manifest('one.example.test')),
        true, 512, JSON_THROW_ON_ERROR,
    );

    expect($manifest['redirect_to'])->toBe('two.example.test');

    // The new hostname serves the real page.
    expect(MediaLibrary::disk()->get(PublishedSite::pointer('two.example.test')))
        ->toContain('Welcome to Olive');
});

it('keeps exactly one active domain per tenant, enforced by the database', function (): void {
    $tenant = $this->makeTenant(slug: 'oneactive');

    activate($tenant, 'first.example.test');
    activate($tenant, 'second.example.test');

    $active = withTenant($tenant, fn () => DB::table('tenant_domains')
        ->where('status', DomainStatus::Active->value)->count());

    expect($active)->toBe(1);

    // And the index is what guarantees it, not the service's own ordering: forcing a
    // second active row from the owner connection with the bypass on is refused.
    $redirecting = withTenant($tenant, fn () => DB::table('tenant_domains')
        ->where('status', DomainStatus::Redirecting->value)->first());

    $state = $this->sqlStateOf(fn () => withoutTenantScope(
        'test: a second active domain for one tenant',
        fn () => DB::connection('pgsql_owner')->table('tenant_domains')->where('id', $redirecting->id)->update([
            'status' => DomainStatus::Active->value,
            'certificate_identifier' => 'forced',
            'certificate_issued_at' => now(),
            'activated_at' => now(),
        ]),
        'pgsql_owner',
    ));

    expect($state)->toBe('23505');
});

it('redirects a domain only within its own tenant, enforced by a composite foreign key', function (): void {
    // The redirect graph cannot leave the tenant. Referential integrity is checked
    // against the real table and bypasses RLS, so this does not depend on a policy.
    $alpha = $this->makeTenant(slug: 'ralpha');
    $beta = $this->makeTenant(slug: 'rbeta');

    $alphaDomain = activate($alpha, 'alpha-r.example.test');
    activate($beta, 'beta-r.example.test');

    $betaRow = withTenant($beta, fn () => DB::table('tenant_domains')->first());

    $state = $this->sqlStateOf(fn () => withoutTenantScope(
        'test: point one tenants domain at anothers',
        fn () => DB::connection('pgsql_owner')->table('tenant_domains')->where('id', $betaRow->id)->update([
            'status' => DomainStatus::Redirecting->value,
            'redirects_to_domain_id' => $alphaDomain,
        ]),
        'pgsql_owner',
    ));

    expect($state)->toBe('23503');
});

it('activates a domain even when the tenant has never published, and writes no page', function (): void {
    // The lifecycle must not depend on there being a page: a tenant can perfectly well
    // add their domain before they have written anything, and a certificate that waited
    // for a publish would be a surprising coupling.
    $tenant = $this->makeTenant(slug: 'nopage');

    activate($tenant, 'nopage.example.test');

    expect(withTenant($tenant, fn () => DB::table('tenant_domains')->value('status')))
        ->toBe(DomainStatus::Active->value)
        ->and(MediaLibrary::disk()->exists(PublishedSite::pointer('nopage.example.test')))->toBeFalse();
});
