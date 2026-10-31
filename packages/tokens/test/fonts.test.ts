import { createHash } from 'node:crypto'
import { readFileSync, readdirSync, existsSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

import { FONTS } from '../src/source.ts'

const PKG = join(import.meta.dirname, '..')
const FONT_DIR = join(PKG, 'fonts')
const REPO = join(PKG, '..', '..')

/**
 * ================================================================================
 * "NO CDN FONT REQUEST ANYWHERE" -- AS A TEST, NOT A COMMENT
 * ================================================================================
 *
 * The approved direction: the two families are *"SELF-HOSTED from `packages/tokens`, subset
 * to Arabic + Latin -- no CDN request, because §12's cold-3G bar is a gate clause and a
 * third-party font request is a dependency on someone else's uptime."*
 *
 * The fetch script needs the network and so is NOT in the gate -- a gate step that needs
 * the network fails for reasons that have nothing to do with the code. What is in the gate
 * is this file, which asserts the PROPERTY: every font URL in the repository is relative,
 * every face is `font-display: swap`, every referenced file exists, and the payload is
 * bounded. A hand edit reintroducing a Google Fonts `<link>` fails here.
 */

const fontsCss = () => readFileSync(join(FONT_DIR, 'fonts.css'), 'utf8')

interface Provenance {
  readonly license: string
  readonly totalBytes: number
  readonly faces: readonly {
    readonly family: string
    readonly file: string
    readonly bytes: number
    readonly sha256: string
    readonly weights: readonly string[]
  }[]
}

const provenance = (): Provenance =>
  JSON.parse(readFileSync(join(FONT_DIR, 'PROVENANCE.json'), 'utf8')) as Provenance

describe('the two self-hosted families', () => {
  it('names exactly the two approved families, both OFL-1.1', () => {
    expect(FONTS.latin.family).toBe('Plus Jakarta Sans')
    expect(FONTS.arabic.family).toBe('Tajawal')
    expect(FONTS.latin.license).toBe('OFL-1.1')
    expect(FONTS.arabic.license).toBe('OFL-1.1')
    expect(provenance().license).toBe('OFL-1.1')
  })

  it('ships a @font-face for each family, with the right subset', () => {
    const css = fontsCss()
    expect(css).toContain("font-family: 'Plus Jakarta Sans'")
    expect(css).toContain("font-family: 'Tajawal'")
    // The latin block and the arabic block, by their unicode-range markers rather than by
    // a filename -- the range is what actually decides which glyphs a browser fetches.
    expect(css).toContain('U+0000-00FF')
    expect(css).toContain('U+0600-06FF')
  })

  it('carries NO absolute URL -- every src is same-origin and relative', () => {
    // The clause, mechanically. A Google Fonts link reintroduced by hand fails here.
    const css = fontsCss()
    const urls = [...css.matchAll(/url\(([^)]+)\)/g)].map((m) => m[1]!.replace(/['"]/g, ''))
    expect(urls.length).toBeGreaterThan(0)
    for (const url of urls) {
      expect(url.startsWith('./'), `${url} is not a relative URL`).toBe(true)
      expect(url).not.toMatch(/^https?:/)
      expect(url).not.toMatch(/^\/\//)
    }
  })

  it('sets font-display: swap on EVERY face, so text is readable while loading', () => {
    const css = fontsCss()
    const faces = css.split('@font-face').slice(1)
    expect(faces.length).toBeGreaterThan(0)
    for (const face of faces) expect(face).toContain('font-display: swap')
  })

  it('references only files that exist, and ships no file nothing references', () => {
    const css = fontsCss()
    const referenced = new Set([...css.matchAll(/url\('\.\/([^']+)'\)/g)].map((m) => m[1]!))
    const onDisk = new Set(readdirSync(FONT_DIR).filter((f) => f.endsWith('.woff2')))

    for (const file of referenced) {
      expect(existsSync(join(FONT_DIR, file)), `${file} is referenced but absent`).toBe(true)
    }
    // Both directions: an orphan file is dead payload in the package.
    expect([...onDisk].sort()).toEqual([...referenced].sort())
  })

  it('is real woff2 -- wOF2 magic on every file, not an HTML error page', () => {
    for (const file of readdirSync(FONT_DIR).filter((f) => f.endsWith('.woff2'))) {
      const bytes = readFileSync(join(FONT_DIR, file))
      expect(bytes.subarray(0, 4).toString('ascii'), `${file} is not woff2`).toBe('wOF2')
    }
  })

  it('matches the hash recorded at fetch time, so a file cannot be swapped silently', () => {
    for (const face of provenance().faces) {
      const bytes = readFileSync(join(FONT_DIR, face.file))
      expect(createHash('sha256').update(bytes).digest('hex')).toBe(face.sha256)
      expect(bytes.length).toBe(face.bytes)
    }
  })

  it('ships ONE file per distinct content -- the variable font is not duplicated', () => {
    // ⚠ THIS FAILED BY CONSTRUCTION ONCE. Plus Jakarta Sans is a variable font and Google
    // serves the SAME woff2 for weight 400 and weight 600, so the first fetch wrote two
    // byte-identical 27,348-byte files -- 27 KB of duplicate payload on the cold 3G start
    // §12's bar is about. One file per hash now, with a weight RANGE on the face.
    const hashes = readdirSync(FONT_DIR)
      .filter((f) => f.endsWith('.woff2'))
      .map((f) =>
        createHash('sha256')
          .update(readFileSync(join(FONT_DIR, f)))
          .digest('hex'),
      )
    expect(new Set(hashes).size).toBe(hashes.length)

    // And the variable face declares a range rather than a single weight.
    expect(fontsCss()).toContain('font-weight: 400 600')
  })

  it('keeps the whole font payload under 64 KB -- §12’s cold 3G start', () => {
    // A BUDGET, asserted, not a preference. A third weight or an unsubset family added
    // later fails here rather than being discovered on a phone. Measured: 45,304 bytes.
    const total = readdirSync(FONT_DIR)
      .filter((f) => f.endsWith('.woff2'))
      .reduce((sum, f) => sum + readFileSync(join(FONT_DIR, f)).length, 0)
    expect(total).toBe(45304)
    expect(total).toBeLessThan(64 * 1024)
    expect(provenance().totalBytes).toBe(total)
  })

  it('fetches only the subsets the two launch languages need', () => {
    // Plus Jakarta Sans also publishes cyrillic-ext, latin-ext and vietnamese; Tajawal also
    // publishes latin. None is shipped: a subset nobody renders is bytes on a cold start.
    const files = readdirSync(FONT_DIR).filter((f) => f.endsWith('.woff2'))
    expect(files).toHaveLength(3)
    for (const file of files) {
      expect(file).toMatch(/^(?:plus-jakarta-sans-latin|tajawal-arabic)/)
    }
  })
})

describe('no stylesheet in the workspace reaches a third-party origin', () => {
  it('has no remote url() and no @import of an absolute URL in any CSS we ship', () => {
    // The property from the other side, over every stylesheet rather than the font file
    // alone -- because the way this clause actually breaks is a `<link>` or an `@import`
    // added to a component stylesheet, not an edit to fonts.css.
    const stylesheets = [
      'packages/tokens/generated/tokens.css',
      'packages/tokens/fonts/fonts.css',
      'packages/ui/src/styles/kit.css',
      'packages/ui/src/styles/templates.css',
    ]
    for (const sheet of stylesheets) {
      const css = readFileSync(join(REPO, sheet), 'utf8').replace(/\/\*[\s\S]*?\*\//g, ' ')
      expect(css, `${sheet} fetches from a remote origin`).not.toMatch(
        /url\(\s*['"]?(?:https?:)?\/\//i,
      )
      expect(css, `${sheet} imports from a remote origin`).not.toMatch(
        /@import\s+(?:url\()?\s*['"]?(?:https?:)?\/\//i,
      )
      expect(css, `${sheet} references a known font CDN`).not.toMatch(
        /fonts\.(?:googleapis|gstatic)\.com|use\.typekit|fonts\.bunny\.net/i,
      )
    }
  })
})
