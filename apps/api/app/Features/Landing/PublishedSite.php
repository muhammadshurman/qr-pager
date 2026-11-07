<?php

declare(strict_types=1);

namespace App\Features\Landing;

/**
 * The key layout of a published site, and the one place it is decided.
 *
 * ================================================================================
 * THIS IS HOW A PUBLISHED PAGE IS ADDRESSED, AND IT IS WHY THERE IS NO LOOKUP (§6.3)
 * ================================================================================
 *
 * `sites/{hostname}/index.html`. The CDN maps `Host` -> that prefix (Task 13), so serving
 * a published page involves NO application process, no database query and no tenant
 * context at all.
 *
 * That is deliberate and it answers the ticket's own question -- how a published page is
 * addressed by custom domain before any tenant context exists. A hostname -> tenant lookup
 * at request time would be a FIFTH cross-tenant read, which `§4 › Tenancy` forbids without
 * a rank-2 edit first. So the design does not need one: the mapping lives in the key
 * layout and in the CDN, where the hostname already is.
 *
 * A hostname reaching these methods has been through tenant_domains_hostname_is_dns_name,
 * so it is lower-case labels and dots with no slash, no scheme and no traversal -- which
 * is what makes it safe to concatenate into a key. isSafeHostname() states that rather
 * than assuming it, because a key built from a bad hostname is a write outside this
 * tenant's prefix.
 */
final class PublishedSite
{
    public const PREFIX = 'sites';

    /** The stable pointer. Writing it IS the switch (decision D8). */
    public static function pointer(string $hostname): string
    {
        return self::root($hostname).'/index.html';
    }

    /** The immutable snapshot, named by the checksum of its own bytes. */
    public static function snapshot(string $hostname, string $checksum): string
    {
        return self::root($hostname).'/'.$checksum.'.html';
    }

    /** What the CDN and Task 11 read to learn what is live. */
    public static function manifest(string $hostname): string
    {
        return self::root($hostname).'/manifest.json';
    }

    /**
     * A published copy of one media asset.
     *
     * The leaf is REUSED from the private key, which is already 32 hex characters of
     * CSPRNG -- so the published path is no more guessable than the private one, and the
     * mapping from a document's media id to its published URL can be computed without a
     * second random draw or a second round trip.
     */
    public static function asset(string $hostname, string $privateKey): string
    {
        return self::root($hostname).'/assets/'.basename($privateKey);
    }

    /** The path the rendered HTML references. Root-relative: the page IS the host. */
    public static function assetUrl(string $privateKey): string
    {
        return '/assets/'.basename($privateKey);
    }

    public static function root(string $hostname): string
    {
        if (! self::isSafeHostname($hostname)) {
            // Never a tenant's input directly -- a hostname arrives from tenant_domains,
            // behind a CHECK constraint, or is built from a slug that carries its own.
            // This refuses the case that constraint is ever relaxed, because a key built
            // from a bad hostname is a write outside this tenant's prefix.
            //
            // ⚠ PublishFailed AND NOT InvalidArgumentException. Every other refusal on
            // the publish path is a PublishFailed, which LandingBuilderPage turns into
            // §12.1's "Publish failed — your live page is unchanged". This one escaped
            // to a 500, so the one guard that fires when a constraint has been relaxed
            // was also the one that told the tenant nothing. Found by the §11 QA review.
            throw new PublishFailed('your site address is not one we can publish to');
        }

        return self::PREFIX.'/'.$hostname;
    }

    /**
     * The hostname rule, as DATA rather than as a literal inside the method.
     *
     * ⚠ **THESE TWO CONSTANTS EXIST SO THE RULE CAN BE READ BY SOMETHING OTHER THAN PHP, AND
     * TASK 11 IS WHY.** `apps/site` is the local stand-in for the CDN's `Host` -> prefix
     * mapping (`ADR-005`), so it has to refuse a hostile `Host` header before it builds a
     * key -- the same refusal, for the same reason, in TypeScript. A hand-written copy of
     * this regex over there would be the "second source wearing a disguise" that
     * `packages/contracts/src/generated/status.ts` opens by warning about: it agrees on the
     * day it is written and drifts in silence after.
     *
     * So `App\Features\Landing\SiteKeyMirror` READS these constants and emits them, and
     * `composer contracts:check` byte-diffs the result. The pattern is written in a syntax
     * both PCRE and ECMAScript accept -- anchors, character classes, groups, `?` and `+`,
     * and nothing else -- and `docs/contracts/hostnames.json` drives the same
     * curated cases through both engines, so the equivalence is MEASURED rather than taken
     * on the similarity of two regex dialects.
     *
     * `isSafeHostname()` is unchanged in behaviour; only the literals moved out of it.
     */
    public const HOSTNAME_MAX_LENGTH = 253;

    public const HOSTNAME_PATTERN = '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$';

    /**
     * ⚠ **THE `D` MODIFIER IS LOAD-BEARING AND ITS ABSENCE WAS A HOLE IN THIS GUARD.**
     *
     * PCRE's `$` matches at the end of the subject **or immediately before a final
     * newline**; ECMAScript's `$` matches only at the very end. So the same pattern
     * characters meant two different things in the two engines, and the direction of the
     * difference was the bad one: `"example.test\n"` was **ACCEPTED here** and refused by
     * the mirror. Measured 2026-11-03, PHP 8.4: `preg_match` returned 1 without `D` and 0
     * with it, while the JavaScript regex returned false either way. `root()` would have
     * built the key `sites/example.test\n` -- a *different* prefix from the host's own,
     * which is the one thing this guard exists to make impossible.
     *
     * `D` is the fix rather than `\z`, and that choice is the point: `D` leaves the PATTERN
     * STRING untouched, so `HOSTNAME_PATTERN` stays a single source that both engines can
     * read, and only the PHP-side modifier differs. `\z` would have made the two patterns
     * textually different and reopened the drift the mirror exists to close.
     *
     * Found by `docs/contracts/hostnames.json` -- a fixture written to prove the two engines
     * agree, which found that they did not. Its `example.test\n` case is the regression.
     */
    public static function isSafeHostname(string $hostname): bool
    {
        return strlen($hostname) <= self::HOSTNAME_MAX_LENGTH
            && preg_match('/'.self::HOSTNAME_PATTERN.'/D', $hostname) === 1;
    }
}
