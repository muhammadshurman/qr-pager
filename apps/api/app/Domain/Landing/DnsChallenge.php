<?php

declare(strict_types=1);

namespace App\Domain\Landing;

/**
 * The DNS challenge itself: its record name, its value, and whether a set of TXT values
 * carries it.
 *
 * ================================================================================
 * ONE COMPARISON, BECAUSE THERE ARE TWO DRIVERS
 * ================================================================================
 *
 * `SystemDnsVerifier` and `FixtureDnsVerifier` both had their own copy of this loop. Two
 * copies of a security comparison is one too many -- they can drift, and only one of them
 * is reachable in the suite, so the one that matters in production would be the untested
 * one. The drivers now differ only in WHERE the TXT values come from, which is the only
 * thing a driver should differ in.
 *
 * `hash_equals` because this compares a secret the tenant was given against one presented
 * back. A timing side channel on a DNS challenge is not much of an attack; writing the
 * comparison the other way in the one place this project compares a token would be a
 * pattern worth copying wrongly.
 *
 * In App\Domain, so no framework and no resolver -- deptrac enforces it, which is also
 * what makes this testable without DNS.
 */
final class DnsChallenge
{
    /** The label the challenge lives under, below the hostname being verified. */
    public const LABEL = '_qrpager-challenge';

    /** What the TXT value starts with, so an unrelated TXT record cannot match by accident. */
    public const PREFIX = 'qrpager-verification=';

    /**
     * A fixed label UNDER the hostname rather than the hostname itself, so verifying does
     * not require the tenant to put a TXT record on a name that already serves their site.
     */
    public static function recordName(string $hostname): string
    {
        return self::LABEL.'.'.$hostname;
    }

    public static function value(string $token): string
    {
        return self::PREFIX.$token;
    }

    /**
     * Does any of these TXT values carry this challenge?
     *
     * @param  iterable<mixed>  $values
     */
    public static function matches(iterable $values, string $token): bool
    {
        $expected = self::value($token);

        foreach ($values as $value) {
            if (is_string($value) && hash_equals($expected, $value)) {
                return true;
            }
        }

        return false;
    }
}
