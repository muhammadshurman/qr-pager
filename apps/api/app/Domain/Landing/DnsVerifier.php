<?php

declare(strict_types=1);

namespace App\Domain\Landing;

/**
 * "Has this tenant proven they control this hostname?" (decision D7.)
 *
 * A PORT, declared in Domain because the domain layer is what states the rule and
 * `Domain: ~` means it may import nothing -- no framework, no resolver, no HTTP client.
 * The drivers live in Features: a local one that runs here, and an AWS one deferred to
 * Task 13 (SR-2).
 *
 * THE CONTRACT HAS ONE CLAUSE AND IT IS FAIL-CLOSED: `verify()` returns true only when
 * the challenge record was FOUND and MATCHED. A lookup that errors, times out, returns
 * nothing or returns something else is false -- never an exception the caller might
 * treat as "probably fine", and never true-by-default.
 */
interface DnsVerifier
{
    /**
     * The record name a tenant has to create. §12.3 shows it, copyable.
     *
     * A fixed label under the hostname rather than the hostname itself, so verifying
     * does not require the tenant to put a TXT record on a name that already serves
     * their site.
     */
    public function recordName(string $hostname): string;

    /** Was the challenge found and does it match? False for every other outcome. */
    public function verify(string $hostname, string $token): bool;

    /** Why the last verify() was false, for §12.3's "failed" state. */
    public function lastFailure(): ?string;
}
