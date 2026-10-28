<?php

declare(strict_types=1);

namespace App\Features\Landing;

use App\Domain\Landing\CertificateIssuer;
use App\Domain\Landing\DnsChallenge;
use App\Domain\Landing\DnsVerifier;
use App\Domain\Landing\DomainStatus;
use App\Platform\Database\DriverError;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * Custom domains and their TLS lifecycle (§12.3, decisions D7 and D8, SR-2).
 *
 * ================================================================================
 * ONE IDEMPOTENT STEP FUNCTION, AND THERE IS NO SWEEP -- SR-3
 * ================================================================================
 *
 * `advance()` moves ONE domain at most one state, and is safe to call repeatedly: every
 * transition is derived from the row's current status and from a port that is itself
 * idempotent, so a command killed mid-run writes nothing it cannot re-derive (§6.6).
 *
 * It takes a tenant id and runs inside that tenant's scope. There is no method here that
 * walks every tenant's domains, and that absence is the design: walking them is a
 * cross-tenant read, `§4 › Tenancy` names four such paths and this is not one of them,
 * and adding a fifth is a rank-2 edit to PROJECT_INSTRUCTIONS.md rather than a build
 * step. The product residual -- a certificate becomes `active` when someone next looks
 * rather than the moment it issues -- is R-130, owned by Task 13.
 *
 * ================================================================================
 * NOTHING REACHES `active` WITHOUT EARNING IT, THREE TIMES OVER
 * ================================================================================
 *
 * This class's own transitions, DomainStatus::allows(), and the CHECK constraint
 * tenant_domains_active_is_earned -- which binds the owner connection too. SR-2 calls
 * that the falsifiable half of §3 row 7, and DomainLifecycleTest falsifies it from each
 * of the three positions.
 */
final class TenantDomains
{
    /**
     * At most this many domains per tenant.
     *
     * ⚠ THERE WAS NO BOUND HERE AT ALL, and `hostname` is unique across the WHOLE
     * platform. So one tenant's owner could claim an unlimited number of hostnames --
     * including a competitor's real domain -- permanently, at the panel's request rate,
     * and no path exists to release one. R-129 accepts the enumeration oracle of the
     * global index; it never considered the index plus an unbounded, irreversible
     * `add()`. Found by the §11 QA review, 2026-10-28.
     *
     * Eight, because a restaurant with an apex, a `www`, a legacy domain and a couple of
     * campaign hostnames is a real shape, and forty is not. The residual -- no release
     * path, so a typo is permanent -- is R-138.
     */
    public const MAX_DOMAINS = 8;

    public function __construct(
        private readonly DnsVerifier $dns,
        private readonly CertificateIssuer $certificates,
    ) {}

    /**
     * Add a hostname to this tenant, at `pending_dns` with nothing earned.
     *
     * @throws DomainRefused
     */
    public function add(string $tenantId, string $hostname): string
    {
        $hostname = strtolower(trim($hostname));

        if (! PublishedSite::isSafeHostname($hostname)) {
            throw new DomainRefused('that is not a hostname we can serve');
        }

        if (str_ends_with($hostname, '.'.config('landing.platform_domain'))) {
            throw new DomainRefused('that hostname belongs to the platform and cannot be claimed');
        }

        $id = (string) Str::uuid7();

        return withTenant($tenantId, function () use ($tenantId, $id, $hostname): string {
            if (DB::table('tenant_domains')->count() >= self::MAX_DOMAINS) {
                throw new DomainRefused(
                    'you already have '.self::MAX_DOMAINS.' domains; remove one to add another'
                );
            }

            try {
                DB::table('tenant_domains')->insert([
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'hostname' => $hostname,
                    'verification_token' => bin2hex(random_bytes(16)),
                ]);
            } catch (QueryException $e) {
                // R-129: the unique index is checked against the real table and so
                // bypasses RLS, which means a 23505 here can mean "another tenant holds
                // it". The message says only that it is unavailable -- never which
                // tenant, and never anything about them -- so the refusal discloses no
                // more than `dig` already would.
                //
                // ⚠ DriverError::sqlState() AND NOT getCode(), which is the trap
                // `§4 › Postgres traps` names and R-013 measured: QueryException::getCode()
                // is the integer 7 for every connection-level failure, so a comparison
                // against it would read `08006` as "not a unique violation" by luck
                // rather than by reading the code. The first version of this method used
                // getCode().
                if (DriverError::sqlState($e) === DriverError::UNIQUE_VIOLATION) {
                    throw new DomainRefused('that hostname is not available');
                }

                throw $e;
            }

            return $id;
        });
    }

    /**
     * Move one domain at most one state. Re-runnable, and never skips a step.
     *
     * @throws DomainRefused
     */
    public function advance(string $tenantId, string $domainId): DomainStatus
    {
        return withTenant($tenantId, function () use ($tenantId, $domainId): DomainStatus {
            $row = DB::table('tenant_domains')->where('id', $domainId)->first();

            if ($row === null) {
                // Identical to the message for a domain that does not exist at all, so
                // the method is not an existence oracle across tenants.
                throw new DomainRefused('that domain is not on your account');
            }

            $status = DomainStatus::from((string) $row->status);
            $hostname = (string) $row->hostname;

            return match ($status) {
                DomainStatus::PendingDns => $this->checkDns($domainId, $hostname, (string) $row->verification_token),
                DomainStatus::DnsVerified => $this->requestCertificate($domainId, $hostname),
                DomainStatus::CertificatePending => $this->activateIfIssued($tenantId, $domainId, $hostname),
                // Nothing to advance. `active` is where a domain is meant to end up, and
                // `redirecting` is a superseded domain still doing its job.
                //
                // ⚠ `failed` IS HERE AND USED TO SHARE THE pending_dns ARM, which was a
                // transition the enum's own table forbids: `allows()[failed]` permits
                // only `failed -> pending_dns`, and checkDns() on success writes
                // `dns_verified`. Nothing in this application ever writes `failed` -- a
                // DNS check that fails leaves the row at `pending_dns` with a reason,
                // which is what §6.6's table prescribes -- so the arm was unreachable
                // AND wrong, and the §11 QA review found both. The state is now a
                // documented no-op and its unreachability is R-137.
                DomainStatus::Active, DomainStatus::Redirecting, DomainStatus::Failed => $status,
            };
        });
    }

    /**
     * The exact TXT record §12.3 shows, copyable.
     *
     * @return array{name: string, type: string, value: string}
     */
    public function challenge(string $tenantId, string $domainId): array
    {
        return withTenant($tenantId, function () use ($domainId): array {
            $row = DB::table('tenant_domains')->where('id', $domainId)->first();

            if ($row === null) {
                throw new DomainRefused('that domain is not on your account');
            }

            return [
                'name' => $this->dns->recordName((string) $row->hostname),
                'type' => 'TXT',
                'value' => DnsChallenge::value((string) $row->verification_token),
            ];
        });
    }

    /** @return list<TenantDomainRow> */
    public function all(string $tenantId): array
    {
        return withTenant($tenantId, static fn (): array => array_values(
            DB::table('tenant_domains')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(static fn (object $row): TenantDomainRow => TenantDomainRow::from($row))
                ->all()
        ));
    }

    private function checkDns(string $domainId, string $hostname, string $token): DomainStatus
    {
        $verified = $this->dns->verify($hostname, $token);
        $now = now();

        if (! $verified) {
            // Stays where it is, with the reason. NOT moved to `failed`: a name that does
            // not resolve yet is the ordinary state of a domain somebody added a minute
            // ago, and calling that a failure would make §12.3's "pending DNS" screen --
            // the one that tells them what record to create -- unreachable after the
            // first poll.
            DB::table('tenant_domains')->where('id', $domainId)->update([
                'last_error' => $this->dns->lastFailure(),
                'checked_at' => $now,
                'updated_at' => $now,
            ]);

            return DomainStatus::PendingDns;
        }

        $this->assertTransition(DomainStatus::PendingDns, DomainStatus::DnsVerified);

        DB::table('tenant_domains')->where('id', $domainId)->update([
            'status' => DomainStatus::DnsVerified->value,
            'dns_verified_at' => $now,
            'last_error' => null,
            'checked_at' => $now,
            'updated_at' => $now,
        ]);

        return DomainStatus::DnsVerified;
    }

    private function requestCertificate(string $domainId, string $hostname): DomainStatus
    {
        $this->certificates->request($hostname);

        $this->assertTransition(DomainStatus::DnsVerified, DomainStatus::CertificatePending);

        $now = now();

        DB::table('tenant_domains')->where('id', $domainId)->update([
            'status' => DomainStatus::CertificatePending->value,
            'checked_at' => $now,
            'updated_at' => $now,
        ]);

        return DomainStatus::CertificatePending;
    }

    private function activateIfIssued(string $tenantId, string $domainId, string $hostname): DomainStatus
    {
        $identifier = $this->certificates->issued($hostname);

        if ($identifier === null) {
            // §12.3's "certificate pending, slow". The old domain keeps serving, because
            // it is still the primary -- which is §6.6's posture for this failure.
            DB::table('tenant_domains')->where('id', $domainId)->update([
                'checked_at' => now(),
                'updated_at' => now(),
            ]);

            return DomainStatus::CertificatePending;
        }

        $now = now();

        // THE OLD DOMAIN FIRST. The partial unique index allows exactly one `active` row
        // per tenant, so promoting the new one before demoting the old would raise 23505
        // -- the constraint deciding the order, which is the right way round.
        $previous = DB::table('tenant_domains')
            ->where('status', DomainStatus::Active->value)
            ->where('id', '!=', $domainId)
            ->first();

        $this->assertTransition(DomainStatus::CertificatePending, DomainStatus::Active);

        if ($previous !== null) {
            $this->assertTransition(DomainStatus::Active, DomainStatus::Redirecting);

            DB::table('tenant_domains')->where('id', $previous->id)->update([
                'status' => DomainStatus::Redirecting->value,
                'redirects_to_domain_id' => $domainId,
                'updated_at' => $now,
            ]);
        }

        DB::table('tenant_domains')->where('id', $domainId)->update([
            'status' => DomainStatus::Active->value,
            'certificate_identifier' => $identifier,
            'certificate_issued_at' => $now,
            'activated_at' => $now,
            'last_error' => null,
            'checked_at' => $now,
            'updated_at' => $now,
        ]);

        // The page moves to the new hostname, and the old one starts redirecting. Both
        // are object writes, and both are after the database has committed to the new
        // state -- an object that is written and then rolled back is an orphan, while a
        // row that is committed without its object is repaired by the next publish.
        $this->republish($tenantId, $previous === null ? null : (string) $previous->hostname, $hostname);

        return DomainStatus::Active;
    }

    /**
     * Every status write in this class goes through the enum's own table first.
     *
     * ⚠ THE TABLE USED TO BE DECORATIVE, which the §11 QA review measured: nothing in
     * production called `DomainStatus::allowsMoveTo()`, `advance()` re-implemented the
     * transitions in a `match`, and the only reader was a test comparing the table to a
     * literal copy of itself. That is a change-detector, not a control -- and it could
     * not catch the service diverging from the table, which it already had (the `failed`
     * arm wrote `dns_verified`, a move the table forbids).
     *
     * So the table is now the guard, which is what `TenantProvisioning` already does
     * with `ProvisioningStatus` one task over. A divergence is an exception here rather
     * than a row in an impossible state.
     */
    private function assertTransition(DomainStatus $from, DomainStatus $to): void
    {
        if (! $from->allowsMoveTo($to)) {
            throw new DomainRefused(
                'that domain cannot move from '.$from->value.' to '.$to->value
            );
        }
    }

    /**
     * Re-publish onto the new hostname, and leave a redirect on the old one.
     *
     * A published page's bytes carry its own canonical URL, so moving hostname really is
     * a re-render rather than a copy -- and the tenant's page must not be dark for the
     * minute between activation and their next edit.
     */
    private function republish(string $tenantId, ?string $previousHostname, string $hostname): void
    {
        $page = DB::table('landing_pages')->where('tenant_id', $tenantId)->first();

        if ($page !== null && (string) $page->status === 'published') {
            // A failure here must not undo the activation: the certificate is real and
            // the row is correct, and the page is one `publish` away from being right.
            // Swallowed deliberately, with the reason, as TenantContext::assertScopeSurvived
            // swallows its rollback -- replacing a success with this failure would hide
            // the state that actually matters.
            try {
                (new LandingPages)->publish($tenantId);
            } catch (PublishFailed) {
                // The domain is active; the page is where the last publish left it.
            }
        }

        if ($previousHostname === null) {
            return;
        }

        MediaLibrary::disk()->put(
            PublishedSite::pointer($previousHostname),
            View::make('landing.redirect', ['hostname' => $hostname])->render(),
            [
                'ContentType' => 'text/html; charset=utf-8',
                // SHORT, unlike a published page: a redirect is the one object here that
                // a tenant may want to change quickly, and it is cheap to re-fetch.
                'CacheControl' => 'public, max-age=60, s-maxage=3600',
            ],
        );

        MediaLibrary::disk()->put(
            PublishedSite::manifest($previousHostname),
            (string) json_encode([
                'hostname' => $previousHostname,
                'redirect_to' => $hostname,
                'redirected_at' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ['ContentType' => 'application/json'],
        );
    }
}
