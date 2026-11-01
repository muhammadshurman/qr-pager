import { createHash } from 'node:crypto'
import { gzipSync } from 'node:zlib'
import { existsSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs'
import { join, relative } from 'node:path'

import { describe, expect, it } from 'vitest'

/**
 * ================================================================================
 * THE THREE LOAD-INVARIANT ASSERTIONS THAT CARRY THE LIGHTHOUSE CLAUSE
 * ================================================================================
 *
 * `§3.1` row 9 and `QRPAGER-16 §4.1`: *"**Not on the score.** Three **load-invariant**
 * assertions carry it, each derivable from the build rather than from a run: the critical-path
 * **transferred bytes** of that route against a stated budget, the **count of render-blocking
 * requests**, and **zero third-party origins in the built output** -- which is also `§3` row
 * 9's own clause (b), asserted against the build and not against intent."*
 *
 * Why the weight is here and not on the score: under `§11`'s round limit a misleading red
 * costs a round, and a Lighthouse score moves with the runner. These three do not move with
 * anything but the code.
 *
 * ⚠ IT READS THE BUILD, SO IT NEEDS ONE. `pnpm gate` builds both apps -- but AFTER Vitest, so
 * on a fresh clone the first `pnpm gate` would run this against no build at all. It therefore
 * FAILS rather than skips when `.next` is absent, and says what to run: a skip here is a
 * green gate with the three load-bearing assertions silently not evaluated, which is the
 * vacuous shape this project records four instances of.
 */

const WEB = join(import.meta.dirname, '..')
const NEXT = join(WEB, '.next')
const PAGE = join(NEXT, 'server', 'app', 's.html')
const CSP_SURFACE = join(WEB, '..', '..', 'docs', 'contracts', 'web-csp-surface.json')

/**
 * The modern critical path's gzipped budget, in bytes.
 *
 * Measured at this task's head: **120,469**. The budget is 135,000 -- about 12 % of
 * headroom, which is enough for a patch bump of React or Next and not enough to absorb a
 * new dependency. A budget with room for a library is not a budget.
 */
const BUDGET_BYTES = 135_000

/** Exactly two: the kit's stylesheet and the app's. Every script Next emits is `async`. */
const RENDER_BLOCKING = 2

function requireBuild(): string {
  if (!existsSync(PAGE)) {
    throw new Error(
      `the built scan landing is absent at ${relative(WEB, PAGE)}. Run ` +
        `\`pnpm --filter @qrpager/web run build\` first. This assertion is not skipped when ` +
        `the build is missing: a skip would make the three load-invariant clauses of the ` +
        `Lighthouse method pass without being evaluated.`,
    )
  }

  return readFileSync(PAGE, 'utf8')
}

function gz(bytes: Buffer | string): number {
  return gzipSync(typeof bytes === 'string' ? Buffer.from(bytes) : bytes, { level: 9 }).length
}

/** Every `<script src>` with its attributes, so `async` and `noModule` are readable. */
function scriptTags(html: string): { src: string; async: boolean; noModule: boolean }[] {
  return [...html.matchAll(/<script\b([^>]*)\bsrc="([^"]+)"([^>]*)>/g)].map((match) => {
    const attributes = `${match[1] ?? ''}${match[3] ?? ''}`

    return {
      src: match[2] ?? '',
      async: /\basync\b/.test(attributes),
      noModule: /\bnoModule\b/i.test(attributes),
    }
  })
}

function stylesheetHrefs(html: string): string[] {
  return [...html.matchAll(/<link\b[^>]*rel="stylesheet"[^>]*href="([^"]+)"/g)].map(
    (match) => match[1] ?? '',
  )
}

function assetBytes(url: string): Buffer {
  const marker = '/_next/static/'

  if (!url.startsWith(marker)) throw new Error(`not a built asset: ${url}`)

  return readFileSync(join(NEXT, 'static', url.slice(marker.length)))
}

describe('the critical path', () => {
  it('is under the stated gzipped budget, with every component reported', () => {
    const html = requireBuild()
    const rows: { name: string; gzipped: number }[] = [{ name: 's.html', gzipped: gz(html) }]

    for (const href of stylesheetHrefs(html)) {
      rows.push({ name: href.split('/').pop() ?? href, gzipped: gz(assetBytes(href)) })
    }

    for (const script of scriptTags(html)) {
      // ⚠ `noModule` IS EXCLUDED, AND THE REASON IS WHY THE FIGURE IS HONEST RATHER THAN
      // FLATTERING: Next's polyfill bundle is 39 kB gzipped and carries `noModule`, so no
      // module-supporting engine requests it at all. Counting it would overstate the modern
      // critical path by a third; pretending it does not exist would understate the legacy
      // one, which is why it is asserted separately below rather than dropped.
      if (script.noModule) continue

      rows.push({
        name: script.src.split('/').pop() ?? script.src,
        gzipped: gz(assetBytes(script.src)),
      })
    }

    const total = rows.reduce((sum, row) => sum + row.gzipped, 0)

    // Reported, not just asserted: a budget failure has to say WHICH asset grew.
    // `console.warn`, because the lint rule allows only `warn` and `error` -- and this line
    // is evidence the report quotes, so it has to reach the gate log either way.
    console.warn(
      `critical path (gzip): ${String(total)} / ${String(BUDGET_BYTES)} bytes\n` +
        rows.map((r) => `  ${r.name.padEnd(44)} ${String(r.gzipped).padStart(7)}`).join('\n'),
    )

    expect(total).toBeLessThanOrEqual(BUDGET_BYTES)
    // A floor as well as a ceiling: a build that emitted nothing would otherwise pass.
    expect(total).toBeGreaterThan(50_000)
  })

  it('carries the legacy polyfill bundle behind noModule, so modern engines skip it', () => {
    const scripts = scriptTags(requireBuild())
    const polyfills = scripts.filter((script) => script.noModule)

    expect(polyfills.length).toBe(1)
    expect(polyfills[0]?.src).toContain('polyfills')
  })

  it('has exactly two render-blocking requests, and every script is async', () => {
    const html = requireBuild()
    const scripts = scriptTags(html)
    const blocking = scripts.filter((script) => !script.async && !script.noModule)

    // ⚠ EXACT, NOT A CEILING. *Assert exact values, never ranges.* A third stylesheet is a
    // real regression on a cold 3G start and this is the only thing that would notice it.
    expect(stylesheetHrefs(html).length).toBe(RENDER_BLOCKING)
    expect(blocking).toEqual([])
    expect(scripts.length).toBeGreaterThan(0)
  })
})

describe('zero third-party origins in the built output -- §3 row 9 clause (b)', () => {
  /**
   * ⚠ ASSERTED AGAINST THE BUILD, NOT AGAINST INTENT, which is the clause's own wording.
   *
   * The scan is for things that MAKE A REQUEST: `src`/`href` in the HTML, `url()` in the CSS,
   * and a `fetch`/`import` of an absolute URL in the JavaScript. A bare URL inside an error
   * message -- React's minified-error links, for instance -- loads nothing and is not a
   * finding; treating it as one would make this assertion so noisy it would be relaxed, and a
   * relaxed version of this assertion is worse than none.
   *
   * It matters because of WHICH origin this is: Task 3 puts the raw QR token in the URL
   * fragment, where any script on this origin reads `location.hash`. Every third-party script
   * on this page would be a script that can read a customer's credential.
   */
  function builtFiles(extension: string): string[] {
    const found: string[] = []

    const walk = (dir: string) => {
      for (const entry of readdirSync(dir)) {
        const full = join(dir, entry)

        if (statSync(full).isDirectory()) walk(full)
        else if (full.endsWith(extension)) found.push(full)
      }
    }

    walk(join(NEXT, 'static'))

    return found
  }

  it('references no absolute origin from the built HTML', () => {
    const html = requireBuild()
    const referenced = [
      ...[...html.matchAll(/\bsrc="([^"]+)"/g)].map((m) => m[1] ?? ''),
      ...[...html.matchAll(/\bhref="([^"]+)"/g)].map((m) => m[1] ?? ''),
    ]

    expect(referenced.length).toBeGreaterThan(5)

    for (const url of referenced) {
      expect(/^(https?:)?\/\//.test(url), `${url} is an absolute reference`).toBe(false)
    }
  })

  it('loads no third-party font or image from any built stylesheet', () => {
    const files = builtFiles('.css')

    expect(files.length).toBeGreaterThan(0)

    for (const file of files) {
      const css = readFileSync(file, 'utf8')

      for (const match of css.matchAll(/url\(\s*['"]?([^'")]+)['"]?\s*\)/g)) {
        const url = match[1] ?? ''

        expect(/^(https?:)?\/\//.test(url), `${relative(NEXT, file)} reaches ${url}`).toBe(false)
      }
    }
  })

  it('fetches and imports no absolute URL from any built script', () => {
    const files = builtFiles('.js')

    expect(files.length).toBeGreaterThan(0)

    for (const file of files) {
      const js = readFileSync(file, 'utf8')
      const calls = [
        ...js.matchAll(/\bfetch\(\s*["'`](https?:\/\/[^"'`]+)/g),
        ...js.matchAll(/\bimport\(\s*["'`](https?:\/\/[^"'`]+)/g),
        ...js.matchAll(/<script[^>]+src=\\?["'](https?:\/\/[^"'\\]+)/g),
      ].map((match) => match[1] ?? '')

      expect(calls, `${relative(NEXT, file)} requests a third-party origin`).toEqual([])
    }
  })
})

describe('the iOS install meta tags -- BOTH names, measured on the built page', () => {
  it('emits apple-mobile-web-app-capable AND mobile-web-app-capable', () => {
    /**
     * ⚠ QRPAGER-24 MEASURED THIS AND IT IS WHY THE TAG IS WRITTEN BY HAND. Next 15.5.28
     * renders `appleWebApp.capable: true` as the STANDARD `mobile-web-app-capable` and emits
     * no `apple-` prefixed tag -- while emitting `apple-mobile-web-app-title` and
     * `apple-mobile-web-app-status-bar-style` from the same object, which is what makes the
     * omission easy to miss. Older iOS reads only the prefixed name for a standalone launch,
     * and `§4 › Realtime` makes the install the only path to a notification there.
     *
     * Asserted on the BUILT HTML so the hand-written tag cannot be tidied away by someone who
     * reads the `appleWebApp` object and assumes it covers both.
     */
    const html = requireBuild()

    expect(html).toContain('<meta name="apple-mobile-web-app-capable" content="yes"/>')
    expect(html).toContain('<meta name="mobile-web-app-capable" content="yes"/>')
    expect(html).toContain('<meta name="apple-mobile-web-app-title" content="Your order"/>')
    expect(html).toContain('<link rel="manifest" href="/pager.webmanifest"/>')
  })

  it('does not disable pinch-zoom, which would be a §12 failure', () => {
    const html = requireBuild()
    const viewport = /<meta name="viewport" content="([^"]+)"/.exec(html)?.[1] ?? ''

    expect(viewport).not.toContain('user-scalable=no')
    expect(viewport).not.toContain('maximum-scale')
    expect(viewport).toContain('width=device-width')
  })

  it('tells crawlers not to index a page whose URL is a per-session secret', () => {
    expect(requireBuild()).toContain('noindex')
  })
})

describe('the CSP surface -- what R-171 has to cover, derived rather than invented', () => {
  /**
   * ================================================================================
   * `R-171` IS NOT CLOSED HERE, AND THIS IS WHAT THIS TASK OWES IT INSTEAD
   * ================================================================================
   *
   * `§3` row 9's clause (c) asks for *"a CSP whose `script-src` makes (b) impossible rather
   * than merely untrue"*. This task does NOT meet it, and that is stated in
   * `docs/tickets/QRPAGER-16.md §2` question 4 rather than discovered in a review.
   *
   * `R-171`'s own reasoning is why: both apps are statically prerendered, so in production
   * the bytes come from CloudFront in front of S3 (Task 13) and `next.config.mjs`'s
   * `headers()` applies only to what `next start` serves. A policy committed here would be
   * green locally and absent where it matters -- `R-126`'s shape -- and the row's acceptance
   * clause is *"a **captured response** from a deployed environment carries every one of the
   * five"*, which needs an environment that does not exist.
   *
   * ⚠ WHAT THIS TASK CAN DO, AND DOES: make the policy **derivable instead of invented**.
   * The artefact below records exactly what the built page needs, and this test fails when it
   * changes. So Task 12 writes `default-src 'self'` plus this file rather than guessing, and
   * a future diff that would need a wider policy reddens a test instead of quietly widening
   * one.
   *
   * ⚠ AND IT RECORDS ONE THING NEITHER ROW KNEW: the built page carries **6 inline scripts**
   * -- Next's RSC payload pushes. So `script-src 'self'` ALONE WOULD BREAK THIS PAGE. A nonce
   * is not available either, because a nonce has to be minted per response and this document
   * is a static file. That leaves per-build hashes or `'unsafe-inline'`, and it is a real
   * decision rather than a detail, which is why it is written into the artefact and named in
   * `§7` of the ticket file.
   */
  it('matches the committed surface, and emits it when it does not exist', () => {
    const html = requireBuild()
    const inline = [...html.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)]
      .map((match) => match[1] ?? '')
      .filter((body) => body.trim() !== '')

    const surface = {
      _README: [
        'GENERATED by apps/web/test/build-surface.test.ts from the BUILT output of',
        'apps/web. It records what a Content-Security-Policy for the scan landing origin',
        'must permit, so that Task 12 writes the policy from a measurement rather than',
        'from a guess (PLAN R-171, owned by Task 12 for the content and Task 13 for the',
        'mechanism, review-by 2026-12-06).',
        '',
        'Regenerate: pnpm --filter @qrpager/web run build && pnpm exec vitest run --project web',
      ],
      route: '/s',
      externalOrigins: [],
      stylesheetOrigins: ['self'],
      scriptOrigins: ['self'],
      connectOrigins: [
        'self',
        'NEXT_PUBLIC_QRPAGER_API_BASE when the API is served from another origin',
        'the push service endpoint the browser chooses (set by the engine, not by us)',
      ],
      inlineScriptCount: inline.length,
      /**
       * ⚠ THE HASHES ARE **NOT** COMMITTED, AND THE REASON IS A MEASUREMENT RATHER THAN A
       * PREFERENCE. `§11` round one, SEC L-4, asked for either the hashes or a corrected
       * sentence; the first attempt emitted them, and `fresh-clone-proof` reddened within the
       * hour.
       *
       * MEASURED, same commit, two builds — this machine and a fresh clone of `80e3223`:
       * **four of the six hashes are identical and exactly two differ.** The two that move are
       * Next's RSC payload pushes, which carry build-specific content. So a committed hash list
       * is not merely fragile, it is WRONG on any machine but the one that wrote it — which is
       * the same defect class as QA C-1, and the CI step added for C-1 is what caught it.
       *
       * ⚠ THAT IS THE FINDING TASK 12 ACTUALLY NEEDS, and it is stronger than a hash list: a
       * `script-src` policy for this page **cannot be static**. It has to be emitted by the
       * build (hashes computed from the artefact that build produced) or by the edge (a nonce
       * per response). `R-171`'s acceptance clause is written against that.
       *
       * What is pinned here is what is reproducible: the COUNT, and the fact that every inline
       * script hashes to a well-formed `sha256-` token. The values are recomputed below and
       * asserted for shape, so the capability is proven without pinning a build artefact.
       */
      inlineScriptHashesNote:
        'NOT LISTED HERE, AND MEASURED RATHER THAN OMITTED: across two builds of one commit ' +
        '(this machine and a fresh clone of 80e3223) four of the six hashes were identical and ' +
        'exactly two differed — the RSC payload pushes carry build-specific content. A ' +
        'committed hash list would therefore be wrong on every machine but the one that wrote ' +
        'it, which `tools/gate/bin/fresh-clone-proof` proved as a CI step within the hour. The ' +
        'consequence for Task 12 is the useful part: a script-src policy for this page CANNOT ' +
        'be static — it must be emitted by the build from that build’s own output, or by the ' +
        'edge as a per-response nonce. `build-surface.test.ts` computes the hashes on every ' +
        'run and asserts their count and shape, so the capability is proven without pinning a ' +
        'build artefact.',
      renderBlockingRequests: RENDER_BLOCKING,
      imageOrigins: ['self'],
      fontOrigins: ['self'],
      frameAncestors: ['none'],
      note:
        'The scan landing carries the raw QR token in its URL fragment, so any script on ' +
        'this origin can read it. That is why clause (b) of PLAN §3 row 9 is asserted ' +
        'against the build and why clause (c) wants a policy that makes it impossible.',
    }

    const encoded = `${JSON.stringify(surface, null, 2)}\n`

    if (!existsSync(CSP_SURFACE)) {
      writeFileSync(CSP_SURFACE, encoded)
    }

    // The committed artefact and the built reality, byte for byte -- the same mechanism
    // `tokens:check` and `contracts:check` already use one contract over.
    expect(readFileSync(CSP_SURFACE, 'utf8')).toBe(encoded)
    // And the figure that makes the note above true rather than a guess.
    expect(inline.length).toBe(6)

    /**
     * The hashes themselves: computed, counted and shape-checked, never committed — see
     * `inlineScriptHashesNote` above for the two-build measurement that decided that. This is
     * what makes the note a demonstrated capability rather than a promise to Task 12.
     */
    const hashes = inline.map(
      (body) => `sha256-${createHash('sha256').update(body, 'utf8').digest('base64')}`,
    )

    expect(hashes).toHaveLength(6)
    for (const hash of hashes) {
      expect(hash, 'an inline script did not hash to a well-formed script-src token').toMatch(
        /^sha256-[A-Za-z0-9+/]{43}=$/,
      )
    }
    // Distinct, because six identical hashes would mean the extraction matched one script six
    // times — a shape that would pass every assertion above.
    expect(new Set(hashes).size).toBe(6)
  })
})
