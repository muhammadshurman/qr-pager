import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

import { isSafeHostname } from '@qrpager/contracts'
import { describe, expect, it, vi } from 'vitest'

import {
  INVARIANT_HEADERS,
  hostnameFromHeader,
  resolveKey,
  serve,
  type SiteResponse,
} from '../src/serve.ts'
import { StoreUnreachable, type ObjectReader } from '../src/store.ts'

/**
 * ================================================================================
 * §3 ROW 11's INVARIANT, AT THE LAYER THAT DECIDES IT — Task 11 (QRPAGER-18)
 * ================================================================================
 *
 * *Tenant A's page can never render tenant B's content, and can never be served from tenant
 * B's cache entry.* Two of the three mechanisms Muhammad named are decided in `serve.ts`, so
 * they are driven here:
 *
 *   **the custom-domain mapping** — every key a request can produce begins with that
 *   request's own host, for every `Host` a header can carry. The cases are not invented here:
 *   they are `docs/contracts/hostnames.json`, the same fixture the publisher's rule is held
 *   to, so the two sides refuse the same set by construction rather than by agreement.
 *
 *   **the cache key** — `Vary: Host` on every answer including the refusals, because a cached
 *   404 keyed without the host is one host's "nothing here" served to a host that has a page.
 *
 * The third, **the revalidation path**, is asserted by its absence: the reader is injected and
 * nothing in this module can write, regenerate or recompute a page. There is no second code
 * path for a stale entry, so there is no window in which a host could serve bytes that are
 * not its own.
 *
 * ⚠ **THE READER IS A SPY, AND THAT IS THE POINT RATHER THAN A CONVENIENCE.** Asserting the
 * RESPONSE only proves what came back; asserting the KEY ASKED FOR proves the request never
 * went looking in another tenant's prefix in the first place. A leak that read B's bytes and
 * then discarded them would still be a leak — the store's access log would show it — and only
 * the key assertion catches that.
 */

const here = dirname(fileURLToPath(import.meta.url))

const fixture = JSON.parse(
  readFileSync(resolve(here, '../../../docs/contracts/hostnames.json'), 'utf8'),
) as { cases: readonly { hostname: string; safe: boolean; why: string }[] }

const LEGAL_HOSTS = fixture.cases.filter((c) => c.safe).map((c) => c.hostname)

/**
 * ⚠ **THE FIXTURE'S REFUSED LIST IS NOT THE SET A `Host` HEADER REFUSES, AND CONFLATING THE
 * TWO IS A MISTAKE THIS FILE MADE AND THE RUN CAUGHT.**
 *
 * `example.test:8099` is correctly `safe: false` as a HOSTNAME — a port is never part of one,
 * and the publisher must never build a key containing it. It is a perfectly ordinary `Host`
 * HEADER, and a local run sends exactly that shape. `hostnameFromHeader()` is the boundary
 * between the two rules, so the partition is derived THROUGH it rather than by picking cases
 * out by name: a case belongs to `PORT_ONLY` when stripping the port makes it legal, and to
 * `REFUSED_HEADERS` otherwise. Both halves are then asserted, which says something true about
 * the layer boundary where skipping the case would have said nothing.
 */
const refused = fixture.cases.filter((c) => !c.safe).map((c) => c.hostname)
const stripped = (host: string): string | null => hostnameFromHeader(host)

/*
 * ⚠ THE PARTITION USES THE REAL RULE, AND A LOOKALIKE GOT IT WRONG ONCE. The first version
 * tested the stripped host against `/^[a-z0-9.-]+$/` — close enough to look right, and it put
 * `http://example.test` in the port bucket, because that strips to `http`, which matches that
 * pattern. `http` is NOT a legal hostname (the rule wants at least two labels), so the case
 * belongs in the refused bucket and the run said so. `isSafeHostname` is the rule; nothing
 * that merely resembles it will do, which is the same lesson the mirror exists for.
 */
const PORT_ONLY = refused.filter((h) => {
  const bare = stripped(h)

  return bare !== null && bare !== h && isSafeHostname(bare)
})
const REFUSED_HEADERS = refused.filter((h) => !PORT_ONLY.includes(h))

/** A reader that records every key it is asked for and answers with the key itself. */
function spyReader(answer: (key: string) => string | null = (key) => key): {
  read: ObjectReader
  keys: string[]
} {
  const keys: string[] = []
  const read: ObjectReader = (key) => {
    keys.push(key)
    const body = answer(key)

    return Promise.resolve(
      body === null
        ? null
        : { body: new TextEncoder().encode(body).buffer, contentType: 'text/html' },
    )
  }

  return { read, keys }
}

const text = (response: SiteResponse): string =>
  typeof response.body === 'string' ? response.body : new TextDecoder().decode(response.body)

describe('the host header', () => {
  it('drops a port and nothing else', () => {
    expect(hostnameFromHeader('alpha.test:3099')).toBe('alpha.test')
    expect(hostnameFromHeader('alpha.test')).toBe('alpha.test')
  })

  it('normalises NOTHING else, so the two sides refuse the same set', () => {
    /*
     * ⚠ THE TEMPTING BUG, NAMED. Lower-casing `Alpha.test` or trimming the dot off
     * `alpha.test.` would make this process accept a host the PUBLISHER refuses, and then
     * read a key under a name nobody ever published to. Worse, two different `Host` values
     * would map to one key — which is a cache entry shared between two authorities.
     */
    expect(hostnameFromHeader('Alpha.test')).toBe('Alpha.test')
    expect(hostnameFromHeader('alpha.test.')).toBe('alpha.test.')
    expect(resolveKey('Alpha.test', '/').kind).toBe('refused')
    expect(resolveKey('alpha.test.', '/').kind).toBe('refused')
  })

  it('treats an absent or empty header as a refusal, never as a default host', () => {
    expect(hostnameFromHeader(null)).toBeNull()
    expect(hostnameFromHeader('')).toBeNull()
    expect(resolveKey(null, '/')).toEqual({ kind: 'refused', because: 'host' })
  })

  it('leaves an IPv6 authority intact, so the rule refuses it', () => {
    // Bracketed literals are never a published key's name. Left whole rather than
    // part-parsed, because a half-parsed `[::1]:80` is how `[` ends up inside a key.
    expect(hostnameFromHeader('[::1]:80')).toBe('[::1]:80')
    expect(resolveKey('[::1]:80', '/').kind).toBe('refused')
  })
})

describe('the mapping cannot reach another host’s prefix', () => {
  it('serves its own host for the one refused shape a Host header legitimately carries', async () => {
    // The port cases: refused as hostnames, ordinary as headers. Non-empty by assertion, so
    // a fixture that stopped carrying one would not quietly make this case vacuous.
    expect(PORT_ONLY.length).toBeGreaterThanOrEqual(1)

    for (const header of PORT_ONLY) {
      const spy = spyReader()
      const response = await serve(spy.read, header, '/')

      expect(response.status, header).toBe(200)
      expect(spy.keys, header).toEqual([`sites/${String(stripped(header))}/index.html`])
    }
  })

  it('reads nothing at all for every Host the rule refuses', async () => {
    expect(REFUSED_HEADERS.length).toBeGreaterThanOrEqual(20)

    for (const hostname of REFUSED_HEADERS) {
      const spy = spyReader()
      const response = await serve(spy.read, hostname, '/')

      expect(response.status, JSON.stringify(hostname)).toBe(404)
      // ⚠ NOT ONE READ. A refusal that still asked the store for something would be a key
      // built from an unchecked host — which is the whole failure this layer prevents.
      expect(spy.keys, JSON.stringify(hostname)).toEqual([])
    }
  })

  it('asks only for its own host’s key, for every hostname the rule allows', async () => {
    expect(LEGAL_HOSTS.length).toBeGreaterThanOrEqual(5)

    for (const hostname of LEGAL_HOSTS) {
      const spy = spyReader()

      await serve(spy.read, `${hostname}:3099`, '/')

      expect(spy.keys).toEqual([`sites/${hostname}/index.html`])

      for (const other of LEGAL_HOSTS) {
        if (other === hostname) continue

        expect(spy.keys[0]?.includes(`/${other}/`), `${hostname} read ${other}`).toBe(false)
      }
    }
  })

  it('serves a 404 and NEVER a fallback when this host has no page', async () => {
    /*
     * ⚠ **THIS IS THE BRANCH A CROSS-TENANT LEAK WOULD LIVE IN.** "No page for this host, so
     * serve the platform one" is the most natural mistake on this surface and the most
     * damaging: it publishes one tenant's page under another tenant's domain, and the CDN
     * then caches it there — which outlives the fix, because the cache does not know the bug
     * is gone. So the empty case is driven with a reader that HAS another host's page and
     * would happily hand it over if anything asked.
     */
    const spy = spyReader((key) => (key === 'sites/beta.test/index.html' ? '<p>beta</p>' : null))

    const response = await serve(spy.read, 'alpha.test', '/')

    expect(response.status).toBe(404)
    expect(text(response)).not.toContain('beta')
    expect(spy.keys).toEqual(['sites/alpha.test/index.html'])
  })

  it('serves each of two hosts its own bytes, in both orders', async () => {
    const pages: Record<string, string> = {
      'sites/alpha.test/index.html': '<h1>Alpha Cafe</h1>',
      'sites/beta.test/index.html': '<h1>Beta Grill</h1>',
    }
    const spy = spyReader((key) => pages[key] ?? null)

    for (const [host, mine, theirs] of [
      ['alpha.test', 'Alpha Cafe', 'Beta Grill'],
      ['beta.test', 'Beta Grill', 'Alpha Cafe'],
      ['alpha.test', 'Alpha Cafe', 'Beta Grill'],
    ] as const) {
      const response = await serve(spy.read, host, '/')

      expect(response.status).toBe(200)
      expect(text(response)).toContain(mine)
      // Asked a second way: not merely "mine is present" but "theirs is absent". A renderer
      // that concatenated both would satisfy the first assertion alone.
      expect(text(response)).not.toContain(theirs)
    }
  })
})

describe('the asset path', () => {
  it('serves one published asset from its own host’s prefix', async () => {
    const spy = spyReader()

    const response = await serve(spy.read, 'alpha.test', '/assets/a1b2c3.webp')

    expect(response.status).toBe(200)
    expect(response.headers['content-type']).toBe('image/webp')
    expect(spy.keys).toEqual(['sites/alpha.test/assets/a1b2c3.webp'])
  })

  it('refuses a leaf that could climb out of the prefix, without reading anything', async () => {
    for (const path of [
      '/assets/../index.html',
      '/assets/../../other.test/index.html',
      '/assets/',
      '/assets/a/b.webp',
      '/assets/..%2fx.webp',
    ]) {
      const spy = spyReader()
      const response = await serve(spy.read, 'alpha.test', path)

      expect(response.status, path).toBe(404)
      expect(spy.keys, path).toEqual([])
    }
  })

  it('refuses an extension that is not on the list, rather than guessing a type', async () => {
    // A tenant-authored byte stream served as a type this reader did not choose is how an
    // asset URL on the tenant's own domain becomes an HTML page on it.
    for (const path of ['/assets/x.html', '/assets/x.js', '/assets/x', '/assets/x.svgz']) {
      const spy = spyReader()

      expect((await serve(spy.read, 'alpha.test', path)).status, path).toBe(404)
      expect(spy.keys, path).toEqual([])
    }
  })

  it('refuses every path that is not the page or one asset', async () => {
    for (const path of [
      '/index.html',
      '/manifest.json',
      '/sites/alpha.test/index.html',
      '/../manifest.json',
      '/assets',
      '/anything/else',
    ]) {
      const spy = spyReader()

      expect((await serve(spy.read, 'alpha.test', path)).status, path).toBe(404)
      expect(spy.keys, path).toEqual([])
    }
  })
})

describe('the cache key', () => {
  it('varies on Host, on a page, an asset and both refusals', async () => {
    const spy = spyReader((key) => (key.endsWith('index.html') ? '<p>ok</p>' : null))

    const answers = await Promise.all([
      serve(spy.read, 'alpha.test', '/'),
      serve(spy.read, 'alpha.test', '/assets/a1b2c3.webp'),
      serve(spy.read, 'alpha.test', '/nope'),
      serve(spy.read, 'nope', '/'),
    ])

    for (const answer of answers) {
      expect(answer.headers.vary).toBe('Host')
      expect(answer.headers['x-content-type-options']).toBe('nosniff')
    }

    // The 200 and the 404s all carry it: a cached refusal keyed without the host is the same
    // defect as a cached page keyed without it.
    expect(answers.map((a) => a.status)).toEqual([200, 404, 404, 404])
  })

  it('declares the two headers once, so no answer can be built without them', () => {
    expect(INVARIANT_HEADERS).toEqual({ vary: 'Host', 'x-content-type-options': 'nosniff' })
  })
})

describe('an outage is not a missing page', () => {
  it('answers 503 with a retry hint, and says nothing about the store', async () => {
    /*
     * ⚠ **503 AND NOT 404, AND THE REASON IS A CACHE REASON.** A CDN may cache a 404 and will
     * not cache a 503, so answering "not found" during a store outage can outlive the outage
     * — the same shape as the cross-tenant leak this whole file is about, where the cache
     * keeps serving something after the cause is gone.
     */
    const read: ObjectReader = () => Promise.reject(new StoreUnreachable(new Error('ECONNREFUSED')))

    const response = await serve(read, 'alpha.test', '/')

    expect(response.status).toBe(503)
    expect(response.headers['retry-after']).toBe('30')
    expect(response.headers.vary).toBe('Host')

    // Nothing about the store, the key, the bucket or the cause reaches a visitor standing on
    // a restaurant's own domain.
    for (const leak of ['ECONNREFUSED', 'sites/', 'bucket', 'qrpager-local', 's3', 'Store']) {
      expect(text(response), leak).not.toContain(leak)
    }
  })

  it('lets an unexpected error through rather than dressing it as an outage', async () => {
    // Only `StoreUnreachable` becomes a 503. A programming error must not be laundered into
    // a polite "try again shortly" that hides it from every log and every alert.
    const read: ObjectReader = () => Promise.reject(new TypeError('read is not a function'))

    await expect(serve(read, 'alpha.test', '/')).rejects.toBeInstanceOf(TypeError)
  })
})

describe('the reader is the only way out of this module', () => {
  it('cannot write, regenerate or revalidate, which is why there is no stale window', async () => {
    /*
     * The third mechanism, asserted as an absence. `serve()` takes a single-argument reader
     * and the module imports nothing else with I/O in it — so there is no code path that
     * could produce a half-regenerated page, and no revalidation window for a host to be
     * served another host's bytes inside.
     */
    const read = vi.fn<ObjectReader>(() => Promise.resolve(null))

    await serve(read, 'alpha.test', '/')
    await serve(read, 'alpha.test', '/')

    // Two identical requests, two reads, no memo: nothing is cached in this process, so
    // nothing in this process can serve a cached answer to the wrong host.
    expect(read).toHaveBeenCalledTimes(2)
    expect(read.mock.calls.map((c) => c[0])).toEqual([
      'sites/alpha.test/index.html',
      'sites/alpha.test/index.html',
    ])
  })
})
