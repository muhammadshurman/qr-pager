/*
 * GENERATED FILE -- DO NOT EDIT.
 *
 * The TypeScript mirror of the published-site key layout, emitted from
 * App\Features\Landing\PublishedSite by `php artisan contracts:export-site-keys`.
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
export const SITE_PREFIX = 'sites'

/** The longest hostname the publisher will build a key from. */
export const HOSTNAME_MAX_LENGTH = 253

/**
 * The hostname rule, as the PHP source states it.
 *
 * Lower-case DNS labels joined by dots, at least two of them, no label starting or
 * ending in a hyphen. Anchored at both ends: a partial match is not a match.
 */
export const HOSTNAME_PATTERN = /^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/

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

/** The prefix every object for one host lives under, e.g. `sites/example.test`. */
export function siteRoot(hostname: string): string | null {
  return isSafeHostname(hostname) ? `${SITE_PREFIX}/${hostname}` : null
}

/** The stable pointer: `sites/example.test/index.html`. Writing it IS the switch (decision D8). */
export function sitePointer(hostname: string): string | null {
  const root = siteRoot(hostname)

  return root === null ? null : `${root}/index.html`
}

/** What is live, and the checksum of the snapshot it points at: `sites/example.test/manifest.json`. */
export function siteManifest(hostname: string): string | null {
  const root = siteRoot(hostname)

  return root === null ? null : `${root}/manifest.json`
}

/**
 * One published asset: `sites/example.test/assets/deadbeefdeadbeefdeadbeefdeadbeef`.
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

  return `${root}/assets/${leaf}`
}
