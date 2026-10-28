<?php

declare(strict_types=1);

namespace App\Domain\Landing;

use App\Domain\Concerns\HasDatabaseValues;
use App\Domain\DatabaseEnum;

/**
 * Mirrored by the Postgres enum type [domain_status] (PLAN.md §2.3).
 *
 * ================================================================================
 * THE TRANSITION TABLE IS NOT THE CONTROL, AND THAT IS THE POINT OF THIS DOCBLOCK
 * ================================================================================
 *
 * QRPAGER-14's SR-2 bounds what this task can demonstrate: an ACM certificate existing in
 * an AWS account is Task 13's. What IS demonstrable here is the falsifiable half -- "no
 * domain reaches `active` without a verified DNS challenge and a recorded certificate".
 *
 * A transition table binds only the code that reads it. So the same rule is ALSO a CHECK
 * constraint on tenant_domains (tenant_domains_active_is_earned), which binds every writer
 * including qrpager_owner with the bypass GUC on. This enum is the readable statement of
 * the rule; the constraint is the one that cannot be walked around. Two independent halves,
 * the same argument as D5's validator-plus-escaping.
 *
 * `failed` is re-enterable from any pre-active state and exits only back to `pending_dns`,
 * which is the retry: a failed verification does not keep a dns_verified_at it never earned.
 *
 * `redirecting` is where a superseded hostname goes. It is NOT a failure state and it is
 * not terminal in the sense `completed` is for provisioning: a tenant may promote an old
 * domain back to primary, which is `redirecting -> active`, and that move is legal only
 * because the row still carries the dns_verified_at and the certificate it earned before.
 *
 * In App\Domain, so no framework and no database -- deptrac enforces it.
 */
enum DomainStatus: string implements DatabaseEnum
{
    use HasDatabaseValues;

    case PendingDns = 'pending_dns';
    case DnsVerified = 'dns_verified';
    case CertificatePending = 'certificate_pending';
    case Active = 'active';
    case Redirecting = 'redirecting';
    case Failed = 'failed';

    /**
     * Every transition this domain permits, and nothing else.
     *
     * The ONE path to `active` runs pending_dns -> dns_verified -> certificate_pending ->
     * active, or back from `redirecting`. Nothing jumps the queue: `pending_dns` cannot
     * reach `active`, `dns_verified` cannot, and neither can `failed`.
     *
     * @return array<string, list<self>>
     */
    public static function allows(): array
    {
        return [
            self::PendingDns->value => [self::DnsVerified, self::Failed],
            self::DnsVerified->value => [self::CertificatePending, self::Failed],
            self::CertificatePending->value => [self::Active, self::Failed],
            self::Active->value => [self::Redirecting],
            self::Redirecting->value => [self::Active],
            self::Failed->value => [self::PendingDns],
        ];
    }

    /** Whether this status may move to $next. */
    public function allowsMoveTo(self $next): bool
    {
        return in_array($next, self::allows()[$this->value], true);
    }

    /** Whether a domain in this status has earned a verified DNS challenge. */
    public function hasVerifiedDns(): bool
    {
        return in_array($this, [self::DnsVerified, self::CertificatePending, self::Active, self::Redirecting], true);
    }

    /** Whether a domain in this status is serving, as primary or as a redirect. */
    public function isServing(): bool
    {
        return $this === self::Active || $this === self::Redirecting;
    }
}
