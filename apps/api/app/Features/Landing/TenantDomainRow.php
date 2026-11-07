<?php

declare(strict_types=1);

namespace App\Features\Landing;

use App\Domain\Landing\DomainStatus;

/**
 * One `tenant_domains` row, typed.
 *
 * The sibling of LandingPageRow and for the same reason: `$row->certificate_identifier`
 * on a query builder `stdClass` is untyped to PHPStan AND to the reader, so a column
 * renamed in a migration fails at runtime in the panel rather than in `composer stan`.
 * from() is the single place a column name is read.
 */
final class TenantDomainRow
{
    public function __construct(
        public readonly string $id,
        public readonly string $hostname,
        public readonly DomainStatus $status,
        public readonly string $verificationToken,
        public readonly ?string $dnsVerifiedAt,
        public readonly ?string $certificateIdentifier,
        public readonly ?string $certificateIssuedAt,
        public readonly ?string $activatedAt,
        public readonly ?string $redirectsToDomainId,
        public readonly ?string $lastError,
        public readonly ?string $checkedAt,
    ) {}

    public static function from(object $row): self
    {
        $string = static fn (mixed $value): ?string => is_string($value) ? $value : null;

        return new self(
            (string) ($row->id ?? ''),
            (string) ($row->hostname ?? ''),
            DomainStatus::from((string) ($row->status ?? 'pending_dns')),
            (string) ($row->verification_token ?? ''),
            $string($row->dns_verified_at ?? null),
            $string($row->certificate_identifier ?? null),
            $string($row->certificate_issued_at ?? null),
            $string($row->activated_at ?? null),
            $string($row->redirects_to_domain_id ?? null),
            $string($row->last_error ?? null),
            $string($row->checked_at ?? null),
        );
    }
}
