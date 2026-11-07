<?php

declare(strict_types=1);

namespace App\Features\Landing;

/**
 * ================================================================================
 * THE GENERATED TYPESCRIPT MIRROR OF THE PUBLISHED-SITE KEY LAYOUT (ADR-005, §6.2)
 * ================================================================================
 *
 * `PublishedSite` is the one place the key layout of a published page is decided, and its
 * own docblock says why there is no hostname lookup anywhere: *"The CDN maps `Host` -> that
 * prefix (Task 13), so serving a published page involves NO application process, no database
 * query and no tenant context at all."*
 *
 * **Task 11 needs that mapping in TypeScript, and `ADR-005` says why.** `apps/site` is the
 * local stand-in for the CDN's half of it, so it resolves a request's `Host` to
 * `sites/{host}/index.html` and serves those bytes. To do that it needs two things this
 * class emits and nothing else: **the hostname rule**, so a hostile `Host` is refused before
 * a key is built from it, and **the key layout**, so the key it builds is the key the
 * publisher wrote.
 *
 * WHY GENERATED RATHER THAN WRITTEN, in `status.ts`'s own words: *a hand-maintained copy is
 * a second source wearing a disguise -- it agrees on the day it is written and drifts
 * silently after.* The drift here would be the worst kind on this surface: a stand-in that
 * builds a key the publisher never wrote serves a 404 where a page exists, and one that
 * accepts a hostname the publisher would have refused is a key outside the prefix.
 *
 * ⚠ **THE ONE THING THIS MIRROR CANNOT CARRY IS THAT TWO REGEX ENGINES AGREE.** The pattern
 * is emitted verbatim, so the two files hold the same characters -- but PCRE and ECMAScript
 * are different dialects, and "the same characters" is not "the same language". The pattern
 * is deliberately written in the intersection (anchors, character classes, groups, `?`, `+`)
 * and `docs/contracts/hostnames.json` drives the same curated cases through BOTH
 * engines: `PublishedSiteKeyMirrorTest` in PHP, `site-keys.test.ts` in Vitest, each
 * asserting the fixture's own expected verdict. That is the measurement; this class is only
 * the transport.
 *
 * No framework and no filesystem in here -- a string out, the command does the I/O, the same
 * split `StatusMirror` and `LandingSchema` use.
 *
 * ⚠ **IT LIVES IN `Features\\Landing` AND NOT BESIDE `StatusMirror` IN `Domain\\Contracts`, AND
 * `composer deptrac` IS WHY.** `Domain` may depend on nothing (`deptrac.yaml`'s ruleset:
 * `Domain: ~`), and this mirror's SOURCE is `PublishedSite`, which is a Feature -- so the
 * first placement produced six `DependsOnDisallowedLayer` violations in one file.
 * `StatusMirror` sits in `Domain` legitimately because everything it reads is Domain
 * (`DatabaseEnums`, `PagerToken`). A mirror belongs in the layer of the thing it mirrors, and
 * the alternative -- hoisting the key layout into `Domain` -- would be a refactor of Task 7's
 * code across every call site to satisfy a file placement.
 */
final class SiteKeyMirror
{
    /** Where the generated mirror lives, relative to the repository root. */
    public const RELATIVE_PATH = 'packages/contracts/src/generated/site-keys.ts';

    /**
     * The TypeScript source, in full.
     *
     * Deterministic: no timestamp, no iteration over anything unordered. The gate compares
     * BYTES, and a generated file carrying the time it was generated can never be
     * byte-compared -- which makes the staleness check impossible to write.
     */
    public static function encode(): string
    {
        $pattern = PublishedSite::HOSTNAME_PATTERN;
        $maxLength = PublishedSite::HOSTNAME_MAX_LENGTH;
        $prefix = PublishedSite::PREFIX;

        // Built from the real functions rather than from a format string, so an example that
        // disagrees with the code cannot be committed. `example.test` passes the rule.
        $pointer = PublishedSite::pointer('example.test');
        $manifest = PublishedSite::manifest('example.test');
        $asset = PublishedSite::asset('example.test', 'deadbeefdeadbeefdeadbeefdeadbeef');

        return <<<TS
        /*
         * GENERATED FILE -- DO NOT EDIT.
         *
         * The TypeScript mirror of the published-site key layout, emitted from
         * App\\Features\\Landing\\PublishedSite by `php artisan contracts:export-site-keys`.
         * `composer contracts:check` byte-diffs this file against the PHP source and fails
         * the gate when they disagree.
         *
         * WHAT READS IT. `apps/site` -- the local stand-in for the CDN's `Host` -> prefix
         * mapping (ADR-005). It resolves a request's host to the pointer key below and
         * serves those bytes. It renders no blocks, reads no database and holds no tenant
         * context, because serving a published page involves none of those (PLAN.md §6.3).
         *
         * WHY THE HOSTNAME RULE IS HERE TOO. A key built from an unchecked host is a read
         * outside the host's own prefix, which on this surface is the whole cross-tenant
         * question. The publisher refuses such a hostname before it writes; the stand-in
         * refuses it before it reads, with the same rule rather than a second one.
         *
         * ⚠ TWO ENGINES, ONE PATTERN, AND THE AGREEMENT IS MEASURED. These characters are
         * the PHP pattern verbatim, but PCRE and ECMAScript are different dialects. The
         * pattern is written in their intersection, and docs/contracts/hostnames.json
         * drives the same cases through both -- PublishedSiteKeyMirrorTest in PHP and
         * site-keys.test.ts in Vitest. Neither file decides the verdicts; the fixture does.
         */

        /** The key prefix every published object lives under. */
        export const SITE_PREFIX = '{$prefix}'

        /** The longest hostname the publisher will build a key from. */
        export const HOSTNAME_MAX_LENGTH = {$maxLength}

        /**
         * The hostname rule, as the PHP source states it.
         *
         * Lower-case DNS labels joined by dots, at least two of them, no label starting or
         * ending in a hyphen. Anchored at both ends: a partial match is not a match.
         */
        export const HOSTNAME_PATTERN = /{$pattern}/

        /**
         * Does this hostname pass the rule the publisher applies before it writes?
         *
         * The length check comes FIRST and is on the string, not on the match, because a
         * pathological host header should be refused without the regex seeing it.
         *
         * ⚠ PHP counts BYTES here (`strlen`) and JavaScript counts UTF-16 code units
         * (`.length`), so the two disagree on any non-ASCII input -- and that disagreement
         * cannot change the VERDICT, which is why the two rules are still one rule. A
         * non-ASCII hostname fails `HOSTNAME_PATTERN` in both engines whatever its measured
         * length, so the only strings whose length decides the answer are pure ASCII, where
         * bytes and code units are the same number. The fixture carries a case for this.
         */
        export function isSafeHostname(hostname: string): boolean {
          return hostname.length <= HOSTNAME_MAX_LENGTH && HOSTNAME_PATTERN.test(hostname)
        }

        /** The prefix every object for one host lives under, e.g. `{$prefix}/example.test`. */
        export function siteRoot(hostname: string): string | null {
          return isSafeHostname(hostname) ? `\${SITE_PREFIX}/\${hostname}` : null
        }

        /** The stable pointer: `{$pointer}`. Writing it IS the switch (decision D8). */
        export function sitePointer(hostname: string): string | null {
          const root = siteRoot(hostname)

          return root === null ? null : `\${root}/index.html`
        }

        /** What is live, and the checksum of the snapshot it points at: `{$manifest}`. */
        export function siteManifest(hostname: string): string | null {
          const root = siteRoot(hostname)

          return root === null ? null : `\${root}/manifest.json`
        }

        /**
         * One published asset: `{$asset}`.
         *
         * `leaf` is the last path segment and nothing else -- the publisher reuses the
         * private key's 32 hex characters of CSPRNG as the leaf, so a published asset path
         * is no more guessable than the private one. A leaf carrying a slash or a dot
         * segment is refused rather than normalised, because normalising is how a read
         * escapes a prefix.
         */
        export function siteAsset(hostname: string, leaf: string): string | null {
          const root = siteRoot(hostname)

          if (root === null || !/^[A-Za-z0-9._-]+$/.test(leaf) || leaf === '.' || leaf === '..') {
            return null
          }

          return `\${root}/assets/\${leaf}`
        }

        TS;
    }
}
