<?php

declare(strict_types=1);

namespace App\Features\Landing;

use App\Domain\Landing\DnsChallenge;
use App\Domain\Landing\DnsVerifier;

/**
 * The real DNS driver: a TXT lookup through the system resolver.
 *
 * THE LOCAL STAND-IN IS A DRIVER AND NOT A MOCK (§1's rule for stand-ins, applied to a
 * port rather than to a service): this class is what runs wherever DNS resolves, and
 * `FixtureDnsVerifier` is what runs in the suite and on a laptop with no zone to edit.
 * Which one is bound is config, not an `if` in here.
 *
 * FAIL-CLOSED IN EVERY DIRECTION. `dns_get_record` emits a warning and returns false on
 * a lookup failure, returns an empty array for a name with no TXT record, and can return
 * records that are not the challenge. All three are false, and the reason is kept for
 * §12.3's "failed" state -- "We could not verify {hostname}: {reason}".
 */
final class SystemDnsVerifier implements DnsVerifier
{
    private ?string $lastFailure = null;

    public function recordName(string $hostname): string
    {
        return DnsChallenge::recordName($hostname);
    }

    public function verify(string $hostname, string $token): bool
    {
        $this->lastFailure = null;

        // @ on purpose, and the return value is what is read: dns_get_record raises a
        // PHP WARNING for an unresolvable name -- which in this application is turned
        // into an exception by the error handler -- and `false` is the documented
        // failure return. Suppressing the warning and testing the value is the only way
        // to treat "this name does not resolve" as an ANSWER, which it is: it is the
        // most common state of a domain a tenant has just added.
        $records = @dns_get_record($this->recordName($hostname), DNS_TXT);

        if ($records === false) {
            $this->lastFailure = 'the DNS lookup failed; the name may not exist yet';

            return false;
        }

        if ($records === []) {
            $this->lastFailure = 'no TXT record was found at '.$this->recordName($hostname);

            return false;
        }

        // ONE comparison, in DnsChallenge, shared with the local driver. The two drivers
        // differ only in where the TXT values come from, which is the only thing a driver
        // should differ in.
        if (DnsChallenge::matches(self::values($records), $token)) {
            return true;
        }

        $this->lastFailure = 'a TXT record exists at '.$this->recordName($hostname)
            .' but it does not carry this verification value';

        return false;
    }

    public function lastFailure(): ?string
    {
        return $this->lastFailure;
    }

    /**
     * The TXT strings in a dns_get_record result.
     *
     * `entries` is the full set of strings in a record and `txt` is them concatenated;
     * a record may carry either depending on the resolver, so both are read.
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<string>
     */
    private static function values(array $records): array
    {
        $values = [];

        foreach ($records as $record) {
            foreach ((array) ($record['entries'] ?? [$record['txt'] ?? '']) as $value) {
                $values[] = (string) $value;
            }
        }

        return $values;
    }
}
