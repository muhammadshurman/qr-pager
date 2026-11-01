import {
  PAGER_TOKEN_ALPHABET,
  PAGER_TOKEN_LENGTH,
  isWellFormedPagerToken,
} from '@qrpager/contracts'
import { beforeEach, describe, expect, it } from 'vitest'

import { asPresentedToken, parseFragment, readPresentedToken } from '../src/pager/token.ts'

/**
 * ================================================================================
 * `§3` ROW 9's CLAUSE (a), ASSERTED. And the generated shape, asserted against PHP's.
 * ================================================================================
 *
 * The clause: *"the handler reads `location.hash` once and immediately strips it with
 * `history.replaceState`, proven by asserting `location.hash` is empty after first paint and
 * that the token is in no history entry."*
 *
 * The history half is asserted twice, once in each environment it can be: here, where
 * `history.length` and `location.href` are readable after the call, and in
 * `browser/pager.spec.ts`, where it is asserted after a real first paint in three engines.
 */

const VALID = 'AbCdEfGhIjKlMnOpQrStUv'

describe('the generated token shape', () => {
  it('is the PHP constants, not a typed copy', () => {
    // ⚠ THESE ARE THE VALUES `App\Domain\Pager\PagerToken::LENGTH` AND `::ALPHABET` EMIT.
    // `composer contracts:check` byte-diffs the generated file, so a change on the PHP side
    // that is not regenerated fails the gate -- and a change that IS regenerated fails
    // these two assertions until somebody reads them. That is the point of asserting a
    // generated value: the figure moves only deliberately.
    expect(PAGER_TOKEN_LENGTH).toBe(22)
    expect(PAGER_TOKEN_ALPHABET).toBe('A-Za-z0-9_-')
    expect(VALID.length).toBe(PAGER_TOKEN_LENGTH)
  })

  it('accepts and refuses exactly what the PHP validator does', () => {
    expect(isWellFormedPagerToken(VALID)).toBe(true)
    // 21 and 23: unpadded base64url of 16 bytes is ALWAYS 22, so the length is exact rather
    // than a minimum, which is what `size:22` on the server asserts too.
    expect(isWellFormedPagerToken(VALID.slice(0, 21))).toBe(false)
    expect(isWellFormedPagerToken(`${VALID}x`)).toBe(false)
    // The alphabet is base64url: `-` and `_`, never `+` or `/`, and never `=` padding.
    expect(isWellFormedPagerToken('Ab-dEfGhIjKlMnOpQrStUv')).toBe(true)
    expect(isWellFormedPagerToken('Ab_dEfGhIjKlMnOpQrStUv')).toBe(true)
    expect(isWellFormedPagerToken('Ab+dEfGhIjKlMnOpQrStUv')).toBe(false)
    expect(isWellFormedPagerToken('Ab/dEfGhIjKlMnOpQrStUv')).toBe(false)
    expect(isWellFormedPagerToken('AbCdEfGhIjKlMnOpQrStU=')).toBe(false)
    // A newline inside an otherwise valid run: the pattern is anchored at both ends.
    expect(isWellFormedPagerToken(`${VALID}\n`)).toBe(false)
    expect(isWellFormedPagerToken(`\n${VALID}`)).toBe(false)
  })

  it('brands a checked string and refuses an unchecked one', () => {
    expect(asPresentedToken(VALID)).toBe(VALID)
    expect(asPresentedToken('nope')).toBeNull()
  })
})

describe('parseFragment', () => {
  it('reads the bare form a scanned QR code actually carries', () => {
    // `PagerToken::qrPayload()` emits `{landing_url}#{token}` -- a BARE token. This is the
    // only form a camera produces, so it is the one that must work.
    expect(parseFragment(`#${VALID}`)).toEqual({ kind: 'present', token: VALID })
  })

  it('reads the prefixed form QRPAGER-24s operator script uses', () => {
    expect(parseFragment(`#t=${VALID}`)).toEqual({ kind: 'present', token: VALID })
  })

  it('reports absent for no fragment and for a bare hash', () => {
    expect(parseFragment('')).toEqual({ kind: 'absent' })
    expect(parseFragment('#')).toEqual({ kind: 'absent' })
  })

  it('reports malformed for anything else, and never guesses', () => {
    expect(parseFragment('#short')).toEqual({ kind: 'malformed' })
    expect(parseFragment(`#t=${VALID}&x=1`)).toEqual({ kind: 'malformed' })
    expect(parseFragment(`#${VALID}#${VALID}`)).toEqual({ kind: 'malformed' })
    expect(parseFragment('#t=')).toEqual({ kind: 'malformed' })
    // A query-shaped fragment: refused rather than parsed, because a parser here is one
    // more place a token could be read from a shape nobody specified.
    expect(parseFragment(`#?token=${VALID}`)).toEqual({ kind: 'malformed' })
  })
})

describe('readPresentedToken', () => {
  beforeEach(() => {
    window.history.replaceState(null, '', '/s')
  })

  it('returns the token AND strips the fragment, in that order', () => {
    window.history.replaceState(null, '', `/s#${VALID}`)
    expect(window.location.hash).toBe(`#${VALID}`)

    const outcome = readPresentedToken(window)

    expect(outcome).toEqual({ kind: 'present', token: VALID })
    // `§3` row 9 clause (a), both halves.
    expect(window.location.hash).toBe('')
    expect(window.location.href).not.toContain(VALID)
    expect(window.document.URL).not.toContain(VALID)
  })

  it('does not grow the history, so no entry ever carried the token', () => {
    const before = window.history.length
    window.history.replaceState(null, '', `/s#${VALID}`)
    readPresentedToken(window)

    // ⚠ THE WHOLE REASON IT IS `replaceState` AND NOT `pushState`. `§3` row 9 lists
    // *"it is written to browser history, which syncs to a cloud profile by default"* as one
    // of the four exposures the fragment leaves. A `pushState` would leave a token-bearing
    // URL in an entry and this number would be `before + 1`.
    expect(window.history.length).toBe(before)
  })

  it('strips a MALFORMED fragment too', () => {
    window.history.replaceState(null, '', '/s#not-a-token')

    expect(readPresentedToken(window)).toEqual({ kind: 'malformed' })
    // ⚠ THE CONTROL IS NOT CONDITIONAL ON THE INPUT BEING VALID. A malformed fragment is
    // still an attacker-supplied value in `document.URL` and in history; leaving it there
    // because it failed our shape test would be the wrong way round.
    expect(window.location.hash).toBe('')
    expect(window.location.href).not.toContain('not-a-token')
  })

  it('leaves the URL alone when there is no fragment at all', () => {
    const href = window.location.href

    expect(readPresentedToken(window)).toEqual({ kind: 'absent' })
    expect(window.location.href).toBe(href)
  })

  it('drops the search string as well as the hash when it strips', () => {
    // `location.search` is empty on this route by construction, and carrying it forward
    // would be a second place a value could hide.
    window.history.replaceState(null, '', `/s?x=1#${VALID}`)
    readPresentedToken(window)

    expect(window.location.search).toBe('')
    expect(window.location.pathname).toBe('/s')
  })
})
