import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

import { describe, expect, it } from 'vitest'

import {
  HOSTNAME_MAX_LENGTH,
  HOSTNAME_PATTERN,
  SITE_PREFIX,
  isSafeHostname,
  siteAsset,
  siteManifest,
  sitePointer,
  siteRoot,
} from '../src/generated/site-keys.ts'

/**
 * ================================================================================
 * THE TYPESCRIPT HALF OF A TWO-ENGINE MEASUREMENT — Task 11 (QRPAGER-18)
 * ================================================================================
 *
 * `docs/contracts/hostnames.json` holds the cases and their verdicts. `PublishedSiteKeyMirrorTest`
 * drives them through PHP's `PublishedSite::isSafeHostname()`; this file drives the SAME cases
 * through the generated TypeScript rule. Neither file decides a verdict — the fixture does — so
 * if PCRE and ECMAScript ever disagree about one, exactly one of these two suites goes red and
 * the case names itself.
 *
 * ⚠ **THAT IS NOT HYPOTHETICAL. IT ALREADY HAPPENED, ON THE FIRST RUN, AND IT WAS THE REASON TO
 * WRITE THE FIXTURE.** `"example.test\n"` was **accepted** by the PHP rule and refused here:
 * PCRE's `$` matches at the end of the subject *or immediately before a final newline*, while
 * ECMAScript's `$` matches only at the very end. The same characters, two languages. PHP now
 * applies the `D` modifier, which makes its `$` mean what this engine's `$` already meant —
 * chosen over `\z` precisely so the pattern STRING stays identical and mirrorable.
 *
 * Without this file the hole would have stayed open, because the PHP side alone was green.
 */

const here = dirname(fileURLToPath(import.meta.url))

interface HostnameCase {
  readonly hostname: string
  readonly safe: boolean
  readonly why: string
}

interface LengthCase {
  readonly label: string
  readonly pattern: string
  readonly safe: boolean
  readonly why: string
}

const fixture = JSON.parse(
  readFileSync(resolve(here, '../../../docs/contracts/hostnames.json'), 'utf8'),
) as { cases: readonly HostnameCase[]; lengthCases: readonly LengthCase[] }

/** `a{248}.test` -> the 253-character hostname it describes. */
function expand(pattern: string): string {
  const match = /^a\{(\d+)\}(.*)$/.exec(pattern)

  if (match === null) throw new Error(`lengthCases pattern not understood: ${pattern}`)

  return 'a'.repeat(Number(match[1])) + (match[2] ?? '')
}

describe('the hostname rule, against the fixture both engines read', () => {
  it('reads a fixture with enough cases to be worth calling a measurement', () => {
    // Not vacuous: a fixture that failed to load would make every case below pass by
    // iterating nothing, which is the one way this file could be green and useless.
    expect(fixture.cases.length).toBeGreaterThanOrEqual(30)
    expect(fixture.lengthCases.length).toBe(2)
    expect(fixture.cases.filter((c) => c.safe).length).toBeGreaterThanOrEqual(5)
    expect(fixture.cases.filter((c) => !c.safe).length).toBeGreaterThanOrEqual(20)
  })

  it('agrees with every recorded verdict', () => {
    for (const testCase of fixture.cases) {
      expect(
        isSafeHostname(testCase.hostname),
        `${JSON.stringify(testCase.hostname)} — ${testCase.why}`,
      ).toBe(testCase.safe)
    }
  })

  it('agrees on both sides of the length boundary', () => {
    for (const testCase of fixture.lengthCases) {
      const hostname = expand(testCase.pattern)

      expect(
        isSafeHostname(hostname),
        `${testCase.label}: ${String(hostname.length)} characters — ${testCase.why}`,
      ).toBe(testCase.safe)
    }
  })

  it('refuses a trailing newline, which is the case the two engines disagreed about', () => {
    // Named on its own as well as in the sweep above, because a case that once divided the
    // two engines is worth a test that says so rather than a row in a loop.
    expect(isSafeHostname('example.test')).toBe(true)
    expect(isSafeHostname('example.test\n')).toBe(false)
  })

  it('anchors at both ends, so a hostile host cannot be a prefix of a legal one', () => {
    expect(HOSTNAME_PATTERN.source.startsWith('^')).toBe(true)
    expect(HOSTNAME_PATTERN.source.endsWith('$')).toBe(true)
    // No `g` flag: a shared regex with one would carry `lastIndex` between calls and make
    // the same input answer differently on alternate calls.
    expect(HOSTNAME_PATTERN.flags).toBe('')
  })
})

describe('the key layout', () => {
  it('puts every object for one host under that host’s own prefix', () => {
    expect(siteRoot('alpha.test')).toBe(`${SITE_PREFIX}/alpha.test`)
    expect(sitePointer('alpha.test')).toBe('sites/alpha.test/index.html')
    expect(siteManifest('alpha.test')).toBe('sites/alpha.test/manifest.json')
    expect(siteAsset('alpha.test', 'deadbeef')).toBe('sites/alpha.test/assets/deadbeef')
  })

  it('answers null rather than a key for every hostname the rule refuses', () => {
    /*
     * ⚠ **NULL AND NOT A THROW, AND NULL AND NOT A BEST EFFORT.** The caller is a request
     * handler reading an attacker-controlled `Host`, so the refusal has to be an ordinary
     * value it must handle — a thrown error invites a `catch` that swallows, and a
     * "sanitised" string invites a key built from a host nobody published to.
     */
    for (const hostname of ['', '..', 'Example.test', 'a.test:8099', 'a.test/../b.test']) {
      expect(siteRoot(hostname), hostname).toBeNull()
      expect(sitePointer(hostname), hostname).toBeNull()
      expect(siteManifest(hostname), hostname).toBeNull()
      expect(siteAsset(hostname, 'deadbeef'), hostname).toBeNull()
    }
  })

  it('refuses an asset leaf that could escape the host’s prefix', () => {
    // The leaf is the last path segment of a published asset and nothing else. A leaf that
    // normalises its way upward is how a read leaves the prefix, so it is refused rather
    // than normalised — `..` is the case, and a slash is the mechanism.
    for (const leaf of ['..', '.', '../other', 'a/b', '/etc/passwd', '', 'a b']) {
      expect(siteAsset('alpha.test', leaf), leaf).toBeNull()
    }

    expect(siteAsset('alpha.test', 'a1b2c3d4e5f6.webp')).toBe(
      'sites/alpha.test/assets/a1b2c3d4e5f6.webp',
    )
  })

  it('never builds one host’s key from another host’s name', () => {
    /*
     * The invariant of `§3` row 11 at the level this module can carry it: the key is a pure
     * function of the hostname, so two different hosts cannot produce one key and one host
     * cannot produce another's. Asserted as a property over the fixture's own legal hosts
     * rather than on a pair chosen to pass.
     */
    const legal = fixture.cases.filter((c) => c.safe).map((c) => c.hostname)

    expect(legal.length).toBeGreaterThanOrEqual(5)

    const keys = legal.map((hostname) => sitePointer(hostname))

    expect(new Set(keys).size, 'two hosts share a pointer key').toBe(legal.length)

    for (const [index, hostname] of legal.entries()) {
      expect(keys[index]).toBe(`sites/${hostname}/index.html`)

      for (const other of legal) {
        if (other === hostname) continue

        expect(keys[index]?.includes(`/${other}/`), `${hostname} key mentions ${other}`).toBe(false)
      }
    }
  })

  it('is capped at the length the publisher caps it at', () => {
    expect(HOSTNAME_MAX_LENGTH).toBe(253)
  })
})
