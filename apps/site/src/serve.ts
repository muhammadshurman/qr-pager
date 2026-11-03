import { siteAsset, sitePointer } from '@qrpager/contracts'

import { StoreUnreachable, type ObjectReader } from './store.ts'

/**
 * ================================================================================
 * `Host` + PATH -> ONE KEY, AND THE INVARIANT LIVES HERE — Task 11 (QRPAGER-18)
 * ================================================================================
 *
 * `§3` row 11's invariant: **tenant A's page can never render tenant B's content, and can
 * never be served from tenant B's cache entry.** Of the three mechanisms Muhammad named, two
 * are decided in this file and the third is decided by not existing:
 *
 *   **the custom-domain mapping** — `resolveKey()` below. Every key it returns begins with
 *   the request's own host, or it returns null. There is no table, no lookup and no fallback,
 *   so there is no row to get wrong and no default host to fall back TO. A host with nothing
 *   published is a 404, never somebody else's page.
 *
 *   **the cache key** — the response carries `Vary: Host`, so any cache between here and a
 *   visitor must key on the host it was served for. This is the one lever this layer has over
 *   an intermediary: it cannot configure the CDN, but it can refuse to be cached host-blind.
 *
 *   **the revalidation path** — there isn't one. Nothing here regenerates a page: the bytes
 *   were written by `LandingPages::publish()` and the pointer write IS the switch (decision
 *   D8). A reader that cannot regenerate cannot serve a half-regenerated page, and that is
 *   the strongest form of the clause rather than a gap in it.
 *
 * ⚠ **THE HOSTNAME RULE AND THE KEY LAYOUT ARE IMPORTED, NEVER RESTATED.**
 * `@qrpager/contracts` carries them, generated from `App\Features\Landing\PublishedSite` by
 * `php artisan contracts:export-site-keys` and byte-diffed by `composer contracts:check`. A
 * second copy of that regex here would agree on the day it was written and drift after — and
 * the drift on this surface is a key outside the host's own prefix.
 */

/** What a request resolved to: a key to read, or a refusal with a reason worth logging. */
export type Resolution =
  | { readonly kind: 'key'; readonly key: string; readonly contentType: string }
  | { readonly kind: 'refused'; readonly because: 'host' | 'path' }

/**
 * Strip what a `Host` header carries that a key must not.
 *
 * ⚠ **ONLY THE PORT IS STRIPPED, AND NOTHING ELSE IS NORMALISED.** Lower-casing an
 * upper-case host, or trimming a trailing dot, would make this process accept a host the
 * PUBLISHER would have refused — and then look up a key under a name nobody published to.
 * The two sides have to refuse the same set, so everything except the port is left for
 * `sitePointer()` to reject. A port is different in kind: it is part of the authority and
 * never part of a key, and `Host: alpha.test:3099` is what a local run actually sends.
 *
 * An IPv6 authority (`[::1]:80`) is left intact and therefore refused by the rule, which is
 * correct: no published key is ever named by an IP literal in brackets.
 */
export function hostnameFromHeader(header: string | null): string | null {
  if (header === null || header === '') return null
  if (header.startsWith('[')) return header

  const colon = header.indexOf(':')

  return colon === -1 ? header : header.slice(0, colon)
}

/** The content types this surface serves, by extension. Everything else is refused. */
const ASSET_TYPES: Readonly<Record<string, string>> = {
  avif: 'image/avif',
  webp: 'image/webp',
  png: 'image/png',
  jpg: 'image/jpeg',
  jpeg: 'image/jpeg',
  svg: 'image/svg+xml',
  ico: 'image/x-icon',
}

/**
 * `Host` and a pathname -> the one key this request may read.
 *
 * Two paths exist and no others: `/` is the page, and `/assets/{leaf}` is one published
 * asset. **An unknown path is refused rather than passed through**, because a reader that
 * forwards an arbitrary path to a key builder is a reader whose safety depends on the key
 * builder alone. `§6.4`'s rule in its strongest form: the surface is a closed list.
 */
export function resolveKey(hostHeader: string | null, pathname: string): Resolution {
  const hostname = hostnameFromHeader(hostHeader)

  if (hostname === null) return { kind: 'refused', because: 'host' }

  if (pathname === '/' || pathname === '') {
    const key = sitePointer(hostname)

    return key === null
      ? { kind: 'refused', because: 'host' }
      : { kind: 'key', key, contentType: 'text/html; charset=utf-8' }
  }

  const asset = /^\/assets\/([^/]+)$/.exec(pathname)

  if (asset === null) return { kind: 'refused', because: 'path' }

  const leaf = asset[1] ?? ''
  const key = siteAsset(hostname, leaf)

  if (key === null) {
    // `siteAsset` refuses a bad leaf AND a bad host, so the reason is read from the host
    // rather than assumed -- a refusal attributed to the wrong half is a misleading log.
    return { kind: 'refused', because: sitePointer(hostname) === null ? 'host' : 'path' }
  }

  const extension = (leaf.split('.').pop() ?? '').toLowerCase()
  const contentType = ASSET_TYPES[extension]

  return contentType === undefined
    ? { kind: 'refused', because: 'path' }
    : { kind: 'key', key, contentType }
}

/**
 * The headers every answer carries, whatever the answer is.
 *
 * `Vary: Host` is the cache-key half of the invariant and is on the REFUSALS too, because a
 * cached 404 keyed without the host is the same defect as a cached page keyed without it —
 * one host's "no page here" served to a host that has one.
 *
 * `X-Content-Type-Options: nosniff` because this surface serves tenant-authored bytes under
 * a content type it chose from an extension; sniffing is how `image/webp` becomes
 * `text/html`. `R-171`'s header work is Task 12's and these two are not it: they are
 * properties of this reader's own answers.
 */
export const INVARIANT_HEADERS: Readonly<Record<string, string>> = {
  vary: 'Host',
  'x-content-type-options': 'nosniff',
}

export interface SiteResponse {
  readonly status: number
  readonly headers: Readonly<Record<string, string>>
  readonly body: ArrayBuffer | string
}

/**
 * Serve one request: resolve, read, answer.
 *
 * `read` is injected so the mapping can be driven without a bucket — which is also how the
 * cross-tenant cases are driven, by recording every key asked for and asserting that none of
 * them belongs to another host.
 */
export async function serve(
  read: ObjectReader,
  hostHeader: string | null,
  pathname: string,
): Promise<SiteResponse> {
  const resolution = resolveKey(hostHeader, pathname)

  if (resolution.kind === 'refused') {
    return {
      status: 404,
      headers: { ...INVARIANT_HEADERS, 'content-type': 'text/plain; charset=utf-8' },
      body: 'Not found\n',
    }
  }

  let found: Awaited<ReturnType<ObjectReader>>

  try {
    found = await read(resolution.key)
  } catch (error) {
    if (!(error instanceof StoreUnreachable)) throw error

    /*
     * 503 AND NOT 404, for the reason `StoreUnreachable`'s own docblock gives: a CDN may
     * cache a 404 and will not cache a 503, so answering "not found" during an outage can
     * persist it past its own end. The body says nothing about the store, the key or the
     * cause -- a visitor on a restaurant's domain is not the audience for our infrastructure.
     */
    return {
      status: 503,
      headers: {
        ...INVARIANT_HEADERS,
        'content-type': 'text/plain; charset=utf-8',
        'retry-after': '30',
      },
      body: 'This page is temporarily unavailable. Please try again shortly.\n',
    }
  }

  if (found === null) {
    // ⚠ A 404 AND NEVER A FALLBACK. This is the branch a cross-tenant leak would live in:
    // "no page for this host, serve the platform one" is the single most natural mistake on
    // this surface and the most damaging, because it publishes one tenant's page under
    // another's domain and the CDN then caches it there.
    return {
      status: 404,
      headers: { ...INVARIANT_HEADERS, 'content-type': 'text/plain; charset=utf-8' },
      body: 'Not found\n',
    }
  }

  return {
    status: 200,
    headers: {
      ...INVARIANT_HEADERS,
      // The type is this reader's decision, from the closed list above, NOT the store's --
      // a tenant who could choose the content type of their own uploaded bytes could serve
      // `text/html` from an asset URL on their own domain.
      'content-type': resolution.contentType,
    },
    body: found.body,
  }
}
