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

    public static function isSafeHostname(string $hostname): bool
    {
        return strlen($hostname) <= 253
            && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $hostname) === 1;
    }
}
