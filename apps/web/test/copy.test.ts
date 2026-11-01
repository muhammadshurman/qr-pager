import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

import { AR, EN, translatorFor } from '../src/copy/index.ts'
import type { CopyKey } from '../src/copy/en.ts'

/**
 * ================================================================================
 * THE COPY CATALOGUE. `§12`: the exact copy is written BEFORE the screen is coded.
 * ================================================================================
 *
 * The specification is `docs/tickets/QRPAGER-16.md §3`, committed before the first
 * implementation commit. These assertions are what stop the code and that document drifting.
 *
 * ⚠ THE KEY-PARITY HALF IS A TYPE, NOT A TEST. `ar.ts` is declared
 * `Record<CopyKey, string>`, so a key added to `en.ts` and not translated does not compile.
 * What is asserted here is the half a type cannot see: an EXTRA key in `ar.ts` (excess
 * property checking does not apply to a non-literal-typed import), an empty value, a value
 * still in English, and a `{placeholder}` nobody substitutes.
 */

const SCREEN_DIR = join(import.meta.dirname, '..', 'src', 'screens')

describe('the copy catalogue', () => {
  it('has exactly the same keys in both launch languages, in both directions', () => {
    const en = Object.keys(EN).sort()
    const ar = Object.keys(AR).sort()

    // Exact, not a subset check in one direction: a missing Arabic key is caught by the
    // type, and an EXTRA Arabic key -- a renamed English id leaving its translation behind
    // -- is caught only here.
    expect(ar).toEqual(en)
    expect(en.length).toBe(60)
  })

  it('has no empty value in either language', () => {
    for (const [key, value] of Object.entries(EN)) {
      expect(value.trim(), `EN ${key}`).not.toBe('')
    }

    for (const [key, value] of Object.entries(AR)) {
      expect(value.trim(), `AR ${key}`).not.toBe('')
    }
  })

  it('has no Arabic value that is still the English string', () => {
    // ⚠ THE CONTROL AGAINST A PLACEHOLDER TRANSLATION. A copy-paste of the English into
    // `ar.ts` satisfies the type, satisfies the key parity and satisfies the emptiness
    // check, and ships an Arabic launch language that is English.
    for (const key of Object.keys(EN) as CopyKey[]) {
      expect(AR[key], `AR ${key} is still the English string`).not.toBe(EN[key])
    }
  })

  it('writes every Arabic value in Arabic script, so a stray latin paste is caught', () => {
    // Four keys legitimately carry latin: the product mark's own name and the install steps
    // that name Apple's UI. Everything else must be Arabic script.
    const LATIN_ALLOWED: readonly CopyKey[] = [
      'alerts.install.body',
      'alerts.install.step2',
      'a11y.productMark',
    ]
    const ARABIC = /[؀-ۿ]/

    for (const key of Object.keys(EN) as CopyKey[]) {
      if (LATIN_ALLOWED.includes(key)) continue

      expect(ARABIC.test(AR[key]), `AR ${key} carries no Arabic script`).toBe(true)
    }
  })

  it('substitutes every placeholder, and leaves an unsupplied one visible', () => {
    const t = translatorFor('en')

    expect(t('ready.announce', { reference: 'A-42' })).toBe('Your order is ready. Order A-42.')
    expect(t('waiting.degraded.asOf', { time: '13:40' })).toBe('Last confirmed 13:40')

    // ⚠ VISIBLE, NOT BLANK. A silent empty string produces "Order ." on the one screen that
    // has to be read across a room, and nothing fails. The literal brace is caught in a
    // screenshot and by `screens.test.tsx`.
    expect(t('ready.announce')).toBe('Your order is ready. Order {reference}.')
    expect(t('ready.announce', {})).toBe('Your order is ready. Order {reference}.')
  })

  it('translates the placeholders into the Arabic word order rather than concatenating', () => {
    const t = translatorFor('ar')
    const announced = t('ready.announce', { reference: 'A-42' })

    expect(announced).toContain('A-42')
    // The reference is not at the end of the Arabic sentence the way it is in English --
    // which is the whole reason the placeholder is inside the copy rather than appended by
    // the caller.
    expect(announced.endsWith('A-42')).toBe(false)
  })

  it('leaves no customer-visible literal in any screen component', () => {
    /**
     * ⚠ THE RULE `§12` ACTUALLY STATES, ASSERTED AGAINST THE SOURCE. *"Every screen is
     * specified before it is coded: … and the exact copy."* A component that carries its own
     * string has copy that is in no catalogue, in one language, reviewed by nobody -- and it
     * is invisible to the key-parity assertions above because it never reaches them.
     *
     * The scan is for JSX TEXT: a run of letters between `>` and `<`. An attribute is not
     * caught and does not need to be -- `className`, `dir` and `data-*` are not copy -- and
     * the two `aria-label`s that ARE copy are built from `t()` calls, which this would not
     * flag either way.
     */
    const files = readdirSync(SCREEN_DIR).filter((name) => name.endsWith('.tsx'))

    expect(files.length).toBe(7)

    for (const file of files) {
      const source = readFileSync(join(SCREEN_DIR, file), 'utf8')
      // Strip comments first: a prose docblock is full of sentences.
      const code = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')
      const text = [...code.matchAll(/>\s*([A-Za-z][A-Za-z ,.'"!?-]{3,})\s*</g)].map((m) => m[1])

      expect(text, `${file} carries literal JSX text`).toEqual([])
    }
  })
})
