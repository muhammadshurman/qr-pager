<?php

declare(strict_types=1);

namespace App\Features\Landing;

use App\Domain\Landing\DnsVerifier;
use Illuminate\Support\Facades\Storage;

/**
 * The LOCAL driver for the DNS port (decision D7).
 *
 * ================================================================================
 * WHY A FILE AND NOT A NETWORK LOOKUP, AND WHY IT IS NOT A MOCK
 * ================================================================================
 *
 * The thing being tested is this application's STATE MACHINE -- that no domain reaches
 * `active` without a verified challenge -- and not whether PHP can resolve a name. A
 * local zone the developer can edit is the stand-in for the zone a tenant edits at their
 * registrar: the same port, the same contract, the same fail-closed behaviour, and one
 * driver swapped by config. SystemDnsVerifier is what runs where DNS resolves.
 *
 * It is a DRIVER rather than a mock because nothing in the application branches on which
 * one is bound, and because the suite exercises it through the real service and the real
 * port -- not by stubbing TenantDomains' own collaborators out.
 *
 * The zone lives on the `local` disk under `landing/dns.json`, which is git-ignored
 * storage. A missing file means "no records", which is the correct answer for a hostname
 * nobody has configured and is the state every new domain starts in.
 */
final class FixtureDnsVerifier implements DnsVerifier
{
    public const ZONE = 'landing/dns.json';

    private ?string $lastFailure = null;

    public function recordName(string $hostname): string
    {
        return SystemDnsVerifier::LABEL.'.'.$hostname;
    }

    public function verify(string $hostname, string $token): bool
    {
        $this->lastFailure = null;
        $zone = self::zone();
        $name = $this->recordName($hostname);

        if (! array_key_exists($name, $zone)) {
            $this->lastFailure = 'no TXT record was found at '.$name;

            return false;
        }

        foreach ((array) $zone[$name] as $value) {
            if (hash_equals(SystemDnsVerifier::PREFIX.$token, (string) $value)) {
                return true;
            }
        }

        $this->lastFailure = 'a TXT record exists at '.$name
            .' but it does not carry this verification value';

        return false;
    }

    public function lastFailure(): ?string
    {
        return $this->lastFailure;
    }

    /**
     * Publish a TXT value into the local zone -- what a tenant does at their registrar.
     *
     * In app/ and not in tests/, because `php artisan domains:advance` on a developer
     * machine needs it too: without a way to satisfy the challenge locally the whole
     * lifecycle is unreachable outside the suite, which is the "demonstration that lives
     * in a session" shape R-043 exists for.
     */
    public static function publish(string $recordName, string $value): void
    {
        $zone = self::zone();
        $zone[$recordName] = [$value];

        Storage::disk('local')->put(self::ZONE, (string) json_encode($zone, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    public static function forget(string $recordName): void
    {
        $zone = self::zone();
        unset($zone[$recordName]);

        Storage::disk('local')->put(self::ZONE, (string) json_encode($zone, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /** @return array<string, list<string>> */
    public static function zone(): array
    {
        $contents = Storage::disk('local')->get(self::ZONE);

        if (! is_string($contents)) {
            return [];
        }

        $decoded = json_decode($contents, true, 8, JSON_INVALID_UTF8_SUBSTITUTE);

        return is_array($decoded) ? $decoded : [];
    }
}
