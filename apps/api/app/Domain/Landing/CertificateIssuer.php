<?php

declare(strict_types=1);

namespace App\Domain\Landing;

/**
 * The application-side half of TLS issuance (decision D7, SR-2).
 *
 * ================================================================================
 * WHAT THIS PORT CAN AND CANNOT DEMONSTRATE, STATED ON THE INTERFACE ITSELF
 * ================================================================================
 *
 * SR-2: the certificate INFRASTRUCTURE -- CloudFront, WAF, ACM -- is Task 13's, as
 * Terraform. What Task 7 owns is the lifecycle: verification, the issuance request,
 * status, activation and the redirect. So this task cannot demonstrate an ACM
 * certificate existing in an AWS account, and does not claim to.
 *
 * What it DOES demonstrate is the falsifiable half: no domain reaches `active` without a
 * verified DNS challenge and a recorded certificate. That is enforced three ways -- this
 * port's contract, DomainStatus' transition table, and the CHECK constraint
 * tenant_domains_active_is_earned, which binds every writer including the owner.
 *
 * KEYED BY HOSTNAME AND NOT BY A REQUEST ID. ACM can be asked for the certificate
 * covering a domain name, so this is workable there too, and it keeps the schema to the
 * identifier/issued_at PAIR that the certificate-is-whole CHECK enforces. If Task 13's
 * driver needs to keep the request's own ARN between calls, that is a column it adds --
 * recorded in the handover rather than guessed at now.
 */
interface CertificateIssuer
{
    /** Ask for a certificate. Idempotent: asking twice for the same hostname is one request. */
    public function request(string $hostname): void;

    /**
     * The issued certificate's identifier, or null while it is still pending.
     *
     * Null is "not yet", never "failed" -- a driver that cannot tell the difference
     * leaves the domain in `certificate_pending`, which is the fail-closed direction:
     * the old domain keeps serving because it is still the primary (§6.6).
     */
    public function issued(string $hostname): ?string;
}
