<?php

declare(strict_types=1);

use App\Domain\Landing\CertificateIssuer;
use App\Domain\Landing\DnsVerifier;
use App\Domain\Landing\DomainStatus;
use App\Features\Landing\DomainRefused;
use App\Features\Landing\FixtureDnsVerifier;
use App\Features\Landing\LocalCertificateIssuer;
use App\Features\Landing\TenantDomains;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\LandingFixtures;

/*
 * §3 row 7, clause 4: "custom domain issues TLS automatically and the old domain
 * redirects" -- bounded by SR-2, which this file states rather than works around.
 *
 * WHAT IS PROVEN HERE: that no domain reaches `active` without a verified DNS challenge
 * AND a recorded certificate, that the lifecycle is idempotent and re-runnable, and that
 * every refusal is fail-closed. That is the falsifiable half.
 *
 * WHAT IS NOT: that an ACM certificate exists in an AWS account. The issuer is a port
 * with a local driver; the AWS driver is Task 13's, and asking for it today throws by
 * name rather than resolving to something that does nothing.
 */

beforeEach(function (): void {
    LandingFixtures::bucket();

    // A clean local zone and issuance ledger per test: both live on the `local` disk,
    // which is real storage and therefore cross-test shared state of exactly the kind
    // TestCase::RESETTABLE exists to prevent one layer down.
    Storage::disk('local')->delete([FixtureDnsVerifier::ZONE, LocalCertificateIssuer::LEDGER]);
});

function domains(): TenantDomains
{
    return new TenantDomains(app(DnsVerifier::class), app(CertificateIssuer::class));
}

function statusOf(string $tenantId, string $domainId): string
{
    return (string) withTenant($tenantId, fn () => DB::table('tenant_domains')->where('id', $domainId)->value('status'));
}

it('adds a domain at pending_dns with nothing earned, and shows the record to create', function (): void {
    $tenant = $this->makeTenant();
    $id = domains()->add($tenant, 'Olive.Example.Test');

    $row = withTenant($tenant, fn () => DB::table('tenant_domains')->where('id', $id)->first());

    expect($row->hostname)->toBe('olive.example.test')
        ->and($row->status)->toBe(DomainStatus::PendingDns->value)
        ->and($row->dns_verified_at)->toBeNull()
        ->and($row->certificate_identifier)->toBeNull()
        ->and($row->activated_at)->toBeNull()
        ->and($row->verification_token)->toMatch('/^[0-9a-f]{32}$/');

    // §12.3's "pending DNS" state: "Add this TXT record at your DNS provider."
    $challenge = domains()->challenge($tenant, $id);

    expect($challenge['name'])->toBe('_qrpager-challenge.olive.example.test')
        ->and($challenge['type'])->toBe('TXT')
        ->and($challenge['value'])->toBe('qrpager-verification='.$row->verification_token);
});

it('refuses a hostname that is not one, and one that belongs to the platform', function (): void {
    $tenant = $this->makeTenant();

    foreach (['not a host', 'https://olive.example.test', 'olive.example.test/x', 'localhost', ''] as $bad) {
        expect(fn () => domains()->add($tenant, $bad))->toThrow(DomainRefused::class);
    }

    // A tenant claiming `other.pages.qrpager.test` would be claiming a platform hostname
    // another tenant is served at.
    expect(fn () => domains()->add($tenant, 'other.'.config('landing.platform_domain')))
        ->toThrow(DomainRefused::class, 'belongs to the platform');

    /*
     * ⚠ **AND THE APEX ITSELF, WHICH WAS `R-150` AND WHICH THIS CASE DID NOT COVER.** The
     * check was `str_ends_with($hostname, '.'.$platform)`, so the subdomain above was refused
     * and `pages.qrpager.test` was ACCEPTED. `R-150`'s own text names where that leads:
     * `LandingPages::publishedPrefixes()` would feed the apex to `deleteDirectory` on
     * unpublish, so one tenant unpublishing deletes a prefix that is not theirs.
     *
     * Closed at Task 11, the task that proves the `Host` -> prefix mapping. Both halves are
     * driven, because `===` alone would let the subdomain through and `str_ends_with` alone
     * let the apex through -- and this surface has had the second half wrong once already.
     */
    expect(fn () => domains()->add($tenant, (string) config('landing.platform_domain')))
        ->toThrow(DomainRefused::class, 'belongs to the platform');
});

it('stays at pending_dns while the challenge is absent or wrong, with a reason and no credit', function (): void {
    // FAIL-CLOSED, and it does NOT move to `failed`: a name that does not resolve yet is
    // the ordinary state of a domain somebody added a minute ago, and calling that a
    // failure would make §12.3's "here is the record to create" screen unreachable after
    // the first poll.
    $tenant = $this->makeTenant();
    $id = domains()->add($tenant, 'olive.example.test');

    expect(domains()->advance($tenant, $id))->toBe(DomainStatus::PendingDns);

    $row = withTenant($tenant, fn () => DB::table('tenant_domains')->where('id', $id)->first());
    expect($row->last_error)->toContain('no TXT record was found')
        ->and($row->dns_verified_at)->toBeNull()
        ->and($row->checked_at)->not->toBeNull();

    // A record that exists but carries the wrong value is also refused, and says so
    // differently -- the tenant needs to know which of the two mistakes they made.
    FixtureDnsVerifier::publish('_qrpager-challenge.olive.example.test', 'qrpager-verification=wrong');

    expect(domains()->advance($tenant, $id))->toBe(DomainStatus::PendingDns);

    $row = withTenant($tenant, fn () => DB::table('tenant_domains')->where('id', $id)->first());
    expect($row->last_error)->toContain('does not carry this verification value')
        ->and($row->dns_verified_at)->toBeNull();
});

it('walks the whole lifecycle one step at a time and never skips one', function (): void {
    $tenant = $this->makeTenant(slug: 'walker');
    $id = domains()->add($tenant, 'olive.example.test');

    $challenge = domains()->challenge($tenant, $id);
    FixtureDnsVerifier::publish($challenge['name'], $challenge['value']);

    // ONE STEP PER CALL. A single advance() that ran the whole chain would make the
    // intermediate states unobservable, and §12.3 has a screen for each of them.
    expect(domains()->advance($tenant, $id))->toBe(DomainStatus::DnsVerified);
    expect(domains()->advance($tenant, $id))->toBe(DomainStatus::CertificatePending);
    expect(domains()->advance($tenant, $id))->toBe(DomainStatus::Active);

    $row = withTenant($tenant, fn () => DB::table('tenant_domains')->where('id', $id)->first());

    expect($row->dns_verified_at)->not->toBeNull()
        ->and($row->certificate_identifier)->toStartWith('local-cert-')
        ->and($row->certificate_issued_at)->not->toBeNull()
        ->and($row->activated_at)->not->toBeNull();

    // Idempotent past the end: an active domain is not advanced by a further poll.
    expect(domains()->advance($tenant, $id))->toBe(DomainStatus::Active);
});

it('holds at certificate_pending while the certificate has not issued, and the old domain keeps serving', function (): void {
    // §12.3's "certificate pending, slow" state, and §6.6's posture for it: "the domain
    // stays certificate_pending; the old domain keeps serving because it is still the
    // primary." The local issuer has a delay so this state is reachable at all.
    config()->set('landing.local_certificate_delay', 3600);

    $tenant = $this->makeTenant(slug: 'slow');
    $id = domains()->add($tenant, 'slow.example.test');

    $challenge = domains()->challenge($tenant, $id);
    FixtureDnsVerifier::publish($challenge['name'], $challenge['value']);

    domains()->advance($tenant, $id);
    expect(domains()->advance($tenant, $id))->toBe(DomainStatus::CertificatePending);

    // Polled repeatedly: still pending, still nothing recorded, still not active.
    foreach (range(1, 3) as $ignored) {
        expect(domains()->advance($tenant, $id))->toBe(DomainStatus::CertificatePending);
    }

    $row = withTenant($tenant, fn () => DB::table('tenant_domains')->where('id', $id)->first());

    expect($row->certificate_identifier)->toBeNull()
        ->and($row->activated_at)->toBeNull();

    // And the moment it issues, the same call that was holding advances it.
    config()->set('landing.local_certificate_delay', 0);
    expect(domains()->advance($tenant, $id))->toBe(DomainStatus::Active);
});

it('cannot be made active by any route that skips a step', function (): void {
    // SR-2's falsifiable half, falsified from all three positions it is defended at.
    $tenant = $this->makeTenant(slug: 'skipper');
    $id = domains()->add($tenant, 'skip.example.test');

    // 1. THE SERVICE. advance() on a pending_dns row checks DNS; it cannot reach active.
    expect(domains()->advance($tenant, $id))->toBe(DomainStatus::PendingDns);
    expect(statusOf($tenant, $id))->toBe(DomainStatus::PendingDns->value);

    // 2. THE TRANSITION TABLE.
    expect(DomainStatus::PendingDns->allowsMoveTo(DomainStatus::Active))->toBeFalse()
        ->and(DomainStatus::DnsVerified->allowsMoveTo(DomainStatus::Active))->toBeFalse()
        ->and(DomainStatus::Failed->allowsMoveTo(DomainStatus::Active))->toBeFalse()
        ->and(DomainStatus::CertificatePending->allowsMoveTo(DomainStatus::Active))->toBeTrue();

    // 3. THE DATABASE, from the OWNER connection with the bypass GUC on -- the one
    // position that gets past every policy. A CHECK does not care.
    $state = $this->sqlStateOf(fn () => withoutTenantScope(
        'test: force a domain active without earning it',
        fn () => DB::connection('pgsql_owner')->table('tenant_domains')->where('id', $id)->update([
            'status' => DomainStatus::Active->value,
            'activated_at' => now(),
        ]),
        'pgsql_owner',
    ));

    expect($state)->toBe('23514')
        ->and(statusOf($tenant, $id))->toBe(DomainStatus::PendingDns->value);
});

it('refuses to advance another tenants domain, in the same words as one that does not exist', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();

    $id = domains()->add($alpha, 'alpha.example.test');

    $foreign = null;
    $absent = null;

    try {
        domains()->advance($beta, $id);
    } catch (DomainRefused $e) {
        $foreign = $e->reason;
    }

    try {
        domains()->advance($beta, (string) Str::uuid7());
    } catch (DomainRefused $e) {
        $absent = $e->reason;
    }

    expect($foreign)->toBe($absent)
        ->and($foreign)->toContain('not on your account');

    // And beta cannot see it at all.
    expect(domains()->all($beta))->toBe([]);
});

it('refuses a hostname another tenant already holds, without saying who holds it', function (): void {
    // R-129, driven. The unique index is checked against the real table and so bypasses
    // RLS -- the refusal is real, and the message discloses no more than `dig` would.
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();

    domains()->add($alpha, 'contested.example.test');

    $refusal = null;

    try {
        domains()->add($beta, 'contested.example.test');
    } catch (DomainRefused $e) {
        $refusal = $e->reason;
    }

    expect($refusal)->toBe('that hostname is not available')
        ->and($refusal)->not->toContain($alpha)
        ->and(domains()->all($beta))->toBe([]);
});

it('names Task 13 rather than resolving to something that does nothing', function (): void {
    // The AWS drivers are ABSENT, not stubbed. A binding that resolved to a class doing
    // nothing would read as capability and be none -- the dead-control shape §4a records
    // four instances of.
    config()->set('landing.certificate_driver', 'aws');
    app()->forgetInstance(CertificateIssuer::class);

    expect(fn () => app(CertificateIssuer::class))
        ->toThrow(RuntimeException::class, 'Task 13');

    config()->set('landing.dns_driver', 'aws');
    app()->forgetInstance(DnsVerifier::class);

    expect(fn () => app(DnsVerifier::class))->toThrow(RuntimeException::class, 'Task 13');
});

it('advances a tenants domains from the command line, by id or by slug', function (): void {
    // SR-3's shape as a signature: the command takes a TENANT, because a command that
    // swept every tenant's domains would be a fifth cross-tenant read and §4 names four.
    $tenant = $this->makeTenant(slug: 'cli-tenant');
    $id = domains()->add($tenant, 'cli.example.test');

    $challenge = domains()->challenge($tenant, $id);
    FixtureDnsVerifier::publish($challenge['name'], $challenge['value']);

    $this->artisan('domains:advance', ['tenant' => 'cli-tenant'])
        ->expectsOutputToContain('pending_dns -> dns_verified')
        ->assertExitCode(0);

    // By id as well as by slug, so an operator holding either can act.
    $this->artisan('domains:advance', ['tenant' => $tenant])
        ->expectsOutputToContain('dns_verified -> certificate_pending')
        ->assertExitCode(0);

    $this->artisan('domains:advance', ['tenant' => $tenant])->assertExitCode(0);

    expect(statusOf($tenant, $id))->toBe(DomainStatus::Active->value);

    // Re-running past the end says so rather than doing something.
    $this->artisan('domains:advance', ['tenant' => $tenant])
        ->expectsOutputToContain('active (unchanged)')
        ->assertExitCode(0);
});

it('fails the command on an unknown tenant rather than doing nothing quietly', function (): void {
    $this->artisan('domains:advance', ['tenant' => 'no-such-restaurant'])
        ->expectsOutputToContain('No tenant matches')
        ->assertExitCode(1);
});

it('says so when a tenant has no custom domains', function (): void {
    $this->makeTenant(slug: 'bare-tenant');

    $this->artisan('domains:advance', ['tenant' => 'bare-tenant'])
        ->expectsOutputToContain('No custom domains')
        ->assertExitCode(0);
});
