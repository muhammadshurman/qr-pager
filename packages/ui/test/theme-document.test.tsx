import { cleanup, render } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'

import { TENANT_LANGUAGE } from '@qrpager/contracts'

import { LOCALES, ThemeProvider, directionFor } from '../src/index.ts'

/**
 * ================================================================================
 * `R-221`, CLOSED BY `QRPAGER-30`: THE DOCUMENT ELEMENT CARRIES THE SAME PAIR
 * ================================================================================
 *
 * `R-221`: *"`<html lang="en" dir="ltr">` is hard-coded for every route in `apps/web`, and
 * direction is carried on a nested element instead … the DOCUMENT's language and direction
 * are wrong for every Arabic visitor, on both surfaces."*
 *
 * The row's own *why it is acceptable* column is the reason this sat open: every stylesheet
 * uses logical properties, the engine resolves `dir` from the nearest ancestor that declares
 * it, and the browser projects measure a correctly mirrored layout. All true, and all about
 * LAYOUT. What it does not cover is the row's last sentence: *"a consumer that reads the
 * document: a screen reader's language"* -- plus `Intl` digit shaping, which
 * `PagerJourney.tsx` reads straight off `document.documentElement.lang`.
 *
 * ⚠ **THESE CASES READ THE REAL `document.documentElement`, NOT THE RENDERED SUBTREE.** The
 * subtree is what the old assertions already covered; a case that read the provider's own
 * element would pass before this change and prove nothing. Each case resets the attributes
 * first, so an attribute left behind by an earlier render cannot be mistaken for this one's.
 */
beforeEach(() => {
  document.documentElement.lang = ''
  document.documentElement.dir = ''
})

afterEach(cleanup)

describe('ThemeProvider and the document element', () => {
  it.each(LOCALES)('publishes %s and its derived direction onto <html>', (locale) => {
    render(
      <ThemeProvider locale={locale}>
        <p>page</p>
      </ThemeProvider>,
    )

    expect(document.documentElement.lang).toBe(locale)
    expect(document.documentElement.dir).toBe(directionFor(locale))
  })

  it('sets the two together, so neither can be right while the other is wrong', () => {
    // The pair is the property, not the two attributes separately: `lang="ar" dir="ltr"` is
    // an Arabic page laid out left to right, which is the failure the ruling of 2026-11-03
    // describes as the one that "does not fail loudly".
    render(
      <ThemeProvider locale="ar">
        <p>page</p>
      </ThemeProvider>,
    )

    expect([document.documentElement.lang, document.documentElement.dir]).toEqual(['ar', 'rtl'])
  })

  it('updates the document when the locale changes, rather than only on first paint', () => {
    // Every surface resolves the locale in an effect AFTER mount (`browserLocale(navigator)`),
    // so the provider's first render carries the fallback and the second carries the answer.
    // A sync that ran once would leave the document on the fallback forever -- which is
    // exactly the bug, one layer in.
    const { rerender } = render(
      <ThemeProvider locale="en">
        <p>page</p>
      </ThemeProvider>,
    )

    expect(document.documentElement.lang).toBe('en')

    rerender(
      <ThemeProvider locale="ar">
        <p>page</p>
      </ThemeProvider>,
    )

    expect([document.documentElement.lang, document.documentElement.dir]).toEqual(['ar', 'rtl'])
  })
})

describe('the launch-language list', () => {
  it('is the generated contract and not a copy of it', () => {
    // ⚠ IDENTITY, NOT EQUALITY. `toBe` on the array itself is what fails if somebody
    // re-types the values here while keeping them equal -- which is the second-source shape
    // this assertion exists for, and `toEqual` would pass through it.
    expect(LOCALES).toBe(TENANT_LANGUAGE)
  })

  it('derives a direction for every language the column can hold', () => {
    // Totality over the CONTRACT rather than over a list here: a third launch language added
    // as a PHP case and a migration regenerates `TENANT_LANGUAGE`, and this fails until the
    // kit's RTL map knows about it.
    for (const locale of TENANT_LANGUAGE) {
      expect(['ltr', 'rtl']).toContain(directionFor(locale))
    }

    expect(directionFor('ar')).toBe('rtl')
    expect(directionFor('en')).toBe('ltr')
  })
})
