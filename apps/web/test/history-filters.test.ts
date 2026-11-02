import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { PAGER_STATUS } from '@qrpager/contracts'
import { describe, expect, it } from 'vitest'

import {
  HISTORY_DIMENSIONS,
  NO_HISTORY_FILTERS,
  historyQueryString,
  type HistoryFilters,
} from '../src/staff/api.ts'

/**
 * ================================================================================
 * 10d's INVARIANT, THE CLIENT END — *every filter combination REACHABLE FROM THE UI*
 * ================================================================================
 *
 * `QRPAGER-28 §0`: *"Every history filter combination reachable from the UI returns only rows
 * of the caller's own tenant, proven by issuing each combination cross-tenant over HTTP."* And
 * `§6`: *"The combination list is DERIVED, not hand-written."*
 *
 * `PagerHistoryIsolationTest` derives its **128 combinations** from
 * `PagerHistoryFilters::DIMENSIONS` and drives every one across the tenant boundary in both
 * directions. That proof is only as total as that constant is accurate about this screen: **a
 * filter this client can send that the PHP constant does not name would be a combination the
 * sweep never drives.**
 *
 * So this file is the join between the two ends, and it reads the PHP source rather than
 * trusting the two lists to agree. Three things are pinned across the boundary:
 *
 *   the DIMENSION NAMES      so a fifth control cannot appear on one side only;
 *   the MAX RANGE            so the client's own pre-check and the server's rule agree;
 *   the STATUS VALUES        which both sides take from the generated enum mirror.
 *
 * ⚠ **READING A SOURCE FILE IS THE POINT, NOT A SHORTCUT.** The alternative — asserting the
 * TypeScript list against a hard-coded copy of the PHP list — is a third copy, and a third
 * copy is what `§6.2` mechanises against. A cross-language contract has to be asserted against
 * the other language, which is `StaffContractTest`'s own reasoning pointed the other way.
 */

const API = join(import.meta.dirname, '..', '..', 'api')
const FILTERS_PHP = join(API, 'app', 'Features', 'Pager', 'PagerHistoryFilters.php')

function filtersSource(): string {
  const source = readFileSync(FILTERS_PHP, 'utf8')

  if (source === '') {
    throw new Error(`${FILTERS_PHP} is empty, so this cross-language pin would assert nothing`)
  }

  return source
}

describe('the filter dimensions, pinned across the language boundary', () => {
  it('names exactly the dimensions the PHP constant names, in the same order', () => {
    const source = filtersSource()
    const match = /public const DIMENSIONS = \[([^\]]*)\]/.exec(source)

    // Not vacuous: the regex found the constant. A derivation that silently matched nothing
    // would otherwise compare an empty list against an empty list and pass.
    expect(match, 'PagerHistoryFilters::DIMENSIONS was not found in the PHP source').not.toBeNull()

    const php = [...(match?.[1] ?? '').matchAll(/'([a-z_]+)'/g)].map((m) => m[1])

    expect(php).toHaveLength(4)
    // ORDER included, because both sides use it: the PHP constant's own comment says it is the
    // screen's reading order so a failing combination can be found on the screen.
    expect([...HISTORY_DIMENSIONS]).toEqual(php)
  })

  it('agrees with the PHP on how wide a range may be, so the client pre-check is not a second rule', () => {
    /*
     * `StaffApp`'s `rangeProblem` answers before a round trip, which is `§12`'s *a screen never
     * offers an action the server refuses*. It is NOT a second implementation of validation —
     * the server still refuses — but the two numbers have to be the same number, or the screen
     * would either block a range the server accepts or send one it refuses.
     */
    const match = /public const MAX_RANGE_DAYS = (\d+)/.exec(filtersSource())

    expect(match, 'PagerHistoryFilters::MAX_RANGE_DAYS was not found').not.toBeNull()
    // The literal 92 is in `StaffApp`'s memo. Asserted here against the PHP rather than
    // imported, because importing it would need a generated constant for one number.
    expect(Number(match?.[1])).toBe(92)
  })

  it('agrees with the PHP on the page size, so the screen does not promise more rows than it gets', () => {
    const match = /public const PAGE_SIZE = (\d+)/.exec(filtersSource())

    expect(match, 'PagerHistoryFilters::PAGE_SIZE was not found').not.toBeNull()
    expect(Number(match?.[1])).toBe(100)
  })

  it('takes its status values from the generated mirror, which is what makes the sweep grow by itself', () => {
    /*
     * The PHP sweep's combination count is `2^|statuses| × 2 × 2`, derived from
     * `PagerStatus::values()`. The screen's toggles are derived from `PAGER_STATUS`, which is
     * GENERATED from that same enum — so a status added in PHP grows a toggle here AND doubles
     * the sweep there, with no edit to either.
     *
     * Asserted as the count the sweep's own arithmetic depends on, so the two cannot drift:
     * if this is 5, that sweep is 128, which is the figure its first case pins.
     */
    expect(PAGER_STATUS).toHaveLength(5)
    expect(2 ** PAGER_STATUS.length * 2 * 2).toBe(128)
  })
})

describe('the query string, which is the shape the FormRequest validates', () => {
  it('sends nothing at all for no filters', () => {
    // So the first paint is `GET /v1/pager-sessions/history` with no query -- the same request
    // `PagerHistoryIsolationTest`'s `(no filters)` combination drives.
    expect(historyQueryString(NO_HISTORY_FILTERS)).toBe('')
  })

  it('repeats `status[]` rather than comma-joining, which is the array rule the server has', () => {
    /*
     * ⚠ THE SHAPE MATTERS AND A COMMA LIST WOULD VALIDATE AS A STRING AND THEN FILTER NOTHING.
     * `PagerHistoryRequest` declares `status` as an `array` rule with an `in:` rule on
     * `status.*`; a comma-joined value fails the `array` rule, so it is a 422 rather than a
     * silent miss — but only because that rule is there. The internal notes on that class say
     * why a parser was refused: it would be a second place where *which statuses were asked
     * for* is decided.
     */
    const query = historyQueryString({ ...NO_HISTORY_FILTERS, statuses: ['ready', 'completed'] })

    expect(query).toBe('status%5B%5D=ready&status%5B%5D=completed')
    // Decoded, so the assertion is also readable as the thing the server receives.
    expect(decodeURIComponent(query)).toBe('status[]=ready&status[]=completed')
  })

  it('omits a null date rather than sending an empty one', () => {
    /*
     * ⚠ `from=` WOULD PASS VALIDATION AND THEN NOT FILTER. The rule is `sometimes|nullable`,
     * so a present-but-blank value is accepted and `PagerHistoryFilters::day()` answers null
     * for it — a date control that silently does nothing, which is the `§12` failure of a
     * screen that looks like it worked.
     */
    expect(historyQueryString({ ...NO_HISTORY_FILTERS, from: null, to: null })).toBe('')
    expect(historyQueryString({ ...NO_HISTORY_FILTERS, from: '2026-01-01' })).toBe(
      'from=2026-01-01',
    )
  })

  it('sends every dimension together, in the dimension order', () => {
    const all: HistoryFilters = {
      statuses: ['waiting'],
      from: '2026-01-01',
      to: '2026-02-01',
      reference: 'A-14',
    }

    expect(decodeURIComponent(historyQueryString(all))).toBe(
      'status[]=waiting&from=2026-01-01&to=2026-02-01&reference=A-14',
    )
  })

  it('percent-encodes a reference containing characters the column allows', () => {
    // `order_reference` admits `#`, `/`, `.`, `-`, `_` and spaces, and three of those are
    // special in a query string. An unencoded `#` would truncate the query at the fragment --
    // silently dropping every filter after it.
    const query = historyQueryString({ ...NO_HISTORY_FILTERS, reference: 'A #1/2' })

    expect(query).toBe('reference=A+%231%2F2')
    expect(new URLSearchParams(query).get('reference')).toBe('A #1/2')
  })

  it('cannot smuggle a second parameter through the reference', () => {
    /*
     * A defensive case about the ENCODER rather than about validation: `URLSearchParams`
     * escapes `&` and `=`, so a reference cannot introduce a parameter the screen did not
     * intend. The server's regex also refuses both characters, so this is the second of two
     * independent bounds — and this is the one that holds if the regex is ever relaxed.
     */
    const query = historyQueryString({ ...NO_HISTORY_FILTERS, reference: 'x&status[]=ready' })
    const parsed = new URLSearchParams(query)

    expect(parsed.getAll('status[]')).toEqual([])
    expect(parsed.get('reference')).toBe('x&status[]=ready')
  })
})
