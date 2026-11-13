import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

import { studioUrl } from '../src/staff/studio'

/**
 * `QRPAGER-48` item 3. Muhammad's condition: *"a test asserts the DEFAULT is the same-origin
 * path, with no env set. Otherwise the production shape is only ever exercised in
 * production."*
 */
describe('where the staff app thinks the studio is', () => {
  it('defaults to the same-origin path when nothing is configured', () => {
    // ⚠ THE WHOLE POINT OF THIS CASE. Local development sets the override, so without this
    // the production shape -- one origin, routed by path, which `§1` decided on 2026-11-13 --
    // would never be exercised anywhere but production.
    expect(studioUrl(undefined)).toBe('/studio')
    expect(studioUrl('')).toBe('/studio')
    expect(studioUrl('   ')).toBe('/studio')
  })

  it('honours an absolute override, which is how dev reaches the other port', () => {
    expect(studioUrl('http://localhost:8000/studio')).toBe('http://localhost:8000/studio')
  })

  it('honours a different same-origin path', () => {
    expect(studioUrl('/panel/studio')).toBe('/panel/studio')
  })

  it('refuses anything that is not a path or an absolute http(s) URL', () => {
    // ⚠ `//evil.example` IS THE CASE WORTH NAMING: it starts with `/` and reads as a path,
    // and a browser treats it as protocol-relative and leaves the origin. "A URL the client
    // can influence is an open redirect wearing a button" -- so a value that cannot be
    // trusted is ignored rather than honoured.
    for (const hostile of [
      '//evil.example/studio',
      // ⚠ AND ITS TWIN, WHICH THE FIRST VERSION OF THE GUARD LET THROUGH (`§11` SECURITY
      // L-2). `\` is not a path separator in a URL -- the WHATWG parser normalises it to `/`
      // for special schemes -- so this leaves the origin exactly as `//` does, while reading
      // as a path to a guard that only looked at the second character being `/`.
      '/\\evil.example',
      '/\\evil.example/studio',
      'javascript:alert(1)',
      'data:text/html,<script>alert(1)</script>',
      'ftp://example.test/studio',
      'studio',
    ]) {
      expect(studioUrl(hostile)).toBe('/studio')
    }
  })

  /**
   * The same two values, resolved by the parser a BROWSER uses rather than by our reading of
   * them -- because the claim the guard rests on is about resolution, not about spelling.
   *
   * Without this, "`/\evil.example` leaves the origin" is an assertion in a comment. With it,
   * the test fails if that ever stops being true of `URL`, which is the only reason the
   * character is refused at all.
   */
  it('refuses exactly the values a browser would resolve to another origin', () => {
    const base = 'https://staff.example.test/staff'

    for (const hostile of ['//evil.example', '/\\evil.example']) {
      expect(new URL(hostile, base).origin).not.toBe('https://staff.example.test')
      expect(studioUrl(hostile)).toBe('/studio')
    }

    // And the control: a value the guard HONOURS must stay on this origin.
    expect(new URL(studioUrl('/panel/studio'), base).origin).toBe('https://staff.example.test')
  })
})

/**
 * ⚠⚠ THE GUARD FOR A DEFECT NO UNIT TEST IN THIS FILE COULD CATCH.
 *
 * The first version of `studioUrl()` took an ENVIRONMENT and read the key off it. Every case
 * above passed, and the feature was broken for every real user: Next.js inlines
 * `NEXT_PUBLIC_*` by replacing **that exact expression** textually at build time, so a
 * property read off a variable is never substituted, `process.env` is `{}` in the browser,
 * and the default is returned always. It was caught by an `e2e` case clicking the link and
 * landing on a Next.js 404.
 *
 * The cases above cannot catch it, because they pass the value in — which is the one path a
 * browser never takes. So this reads the SOURCE and requires the literal expression to be
 * there. It is a weaker control than a browser, and it is aimed at the specific thing that
 * happened: somebody tidying the signature back into "take an env, it's more testable".
 */
describe('the build-time contract this file depends on', () => {
  it('reads the env key as the literal expression Next.js can inline', () => {
    const raw = readFileSync(join(import.meta.dirname, '../src/staff/studio.ts'), 'utf8')

    /*
     * ⚠ COMMENTS ARE STRIPPED FIRST, AND THE FIRST VERSION OF THIS CASE DID NOT — it failed
     * on its own docblock, which explains the defect and therefore CONTAINS the forbidden
     * spelling. That is `R-260`'s family (*the scanner reads prose too*), which `QRPAGER-47`
     * hit with two hex colours in a comment. The check is about CODE.
     */
    const source = raw.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, '')

    expect(source).toContain('process.env.NEXT_PUBLIC_QRPAGER_STUDIO_URL')

    /*
     * And NOT off a variable, which is the shape that silently stopped being inlined.
     *
     * ⚠ THE LOOKBEHIND IS LOAD-BEARING. Without it this pattern matches the LEGITIMATE
     * expression: `\b` finds a word boundary at the `.` in `process.env.`, so `env.NEXT_…`
     * is a substring of the very spelling the line above requires, and the case failed
     * against correct code.
     */
    expect(source).not.toMatch(/(?<!process\.)\benv\.NEXT_PUBLIC_QRPAGER_STUDIO_URL\b/)
  })
})
