import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

import type { KdsOrder } from '../src/kds/api.ts'
import {
  KDS_INTERVAL_SECONDS,
  isKdsStale,
  kdsElapsedSeconds,
  reconcile,
} from '../src/kds/resync.ts'
import { BOARD_INTERVAL_SECONDS } from '../src/staff/board.ts'

/**
 * ================================================================================
 * 10c's INVARIANT, SECOND HALF — *shows NO duplicate row*
 * ================================================================================
 *
 * `QRPAGER-27 §0`, verbatim: *"A KDS that loses the network mid-shift and reconnects re-sends
 * NO notification and shows NO duplicate row."*
 *
 * The browser proof (`tools/gate/bin/kds-resync-proof`) drives the whole clause end to end
 * against a real network drop and a real server-side dispatch count. **This file is what makes
 * the RULE falsifiable on its own**, which the browser proof cannot be: a green browser run
 * tells you the one scenario it drove came out right, and says nothing about the three merge
 * shapes `resync.ts`'s header names.
 *
 * So every one of those three is driven below as an explicit NEGATIVE case — a table of what
 * `reconcile` must NOT do — rather than only asserting what it does. A test that only checks
 * the happy path would pass against `[...cached, ...fresh]`.
 */

function order(id: string, reference: string, createdAt = '2026-11-02T10:00:00Z'): KdsOrder {
  return { id, orderReference: reference, status: 'waiting', createdAt }
}

const A = order('01a00000-0000-7000-8000-000000000001', 'A-1')
const B = order('01a00000-0000-7000-8000-000000000002', 'B-2')
const C = order('01a00000-0000-7000-8000-000000000003', 'C-3')

describe('reconcile — the resync rule', () => {
  it('replaces the cache with the server answer, and keeps the server ordering', () => {
    // Exact equality on the whole list, not a length check: the ORDER is `board()`'s `order by
    // created_at desc` and a client that reversed it would put the oldest order at the top of
    // a kitchen screen while passing every count-based assertion.
    expect(reconcile([A, B], [C, A])).toEqual([C, A])
  })

  it('keeps the cache untouched when the poll FAILED, which is the offline case', () => {
    // `toBe`, not `toEqual`: the identical array comes back, so React's own `===` bail-out
    // applies and an outage does not re-render the whole kitchen screen every five seconds.
    const cached = [A, B]

    expect(reconcile(cached, null)).toBe(cached)
  })

  it('DROPS a row the server no longer mentions — the merge shape that produces no duplicate id and still lies', () => {
    /*
     * ⚠ THIS IS THE CASE `resync.ts`'s HEADER CALLS THE DANGEROUS ONE, and it is the reason
     * `reconcile` exists rather than a bare `setOrders(fresh)`.
     *
     * The `Map`-seeded-with-cached shape produces NO duplicate id, so an assertion that only
     * counted ids would pass against it — and it keeps a row the server dropped for ever,
     * because no future poll will ever mention that id again to remove it. An order completed
     * during an outage stays on the kitchen screen until the tablet is rebooted, and a cook
     * plates a dish nobody is waiting for.
     */
    expect(reconcile([A, B], [A])).toEqual([A])
    expect(reconcile([A, B], [A]).some((row) => row.id === B.id)).toBe(false)
  })

  it('drops EVERY row when the server answers an empty board', () => {
    // The boundary of the case above, and the one a `fresh.length === 0 ? cached : fresh`
    // guard would get wrong — a guard somebody adds in good faith to stop the board
    // "flickering empty", which permanently freezes a kitchen screen the moment the last
    // order of a service is collected.
    expect(reconcile([A, B], [])).toEqual([])
  })

  it('never appends the cache to the answer, in either order', () => {
    const replaced = reconcile([A, B], [C])

    // Both naive concatenations, asserted as negatives rather than inferred from the
    // positive case above.
    expect(replaced).not.toEqual([A, B, C])
    expect(replaced).not.toEqual([C, A, B])
    expect(replaced).toHaveLength(1)
  })

  it('renders one row per id even if one answer carried the same id twice', () => {
    /*
     * `pager_sessions.id` is a primary key so one response cannot carry a row twice today.
     * What this asserts is the SHAPE of the clause: *shows no duplicate row*. A client that
     * would render a duplicate if it ever received one has satisfied the clause by luck.
     */
    const twice = reconcile([], [A, A, B])

    expect(twice).toHaveLength(2)
    expect(twice.map((row) => row.id)).toEqual([A.id, B.id])
  })

  it('keeps the FIRST occurrence of a repeated id, so the server ordering survives the dedupe', () => {
    // A last-wins dedupe (`new Map(answer.map(r => [r.id, r]))`) silently reverses nothing
    // here but DOES move the duplicated row to its last position, which on a newest-first
    // board moves an order down the screen. First-wins is the only dedupe that is a no-op on
    // the ordering.
    const first = order(A.id, 'A-1-first')
    const second = order(A.id, 'A-1-second')

    expect(reconcile([], [first, B, second])).toEqual([first, B])
  })

  it('is a pure function of its two arguments — the same inputs twice give the same answer', () => {
    // A resync that accumulated state across calls is the defect in another form: it would
    // pass every single-call case above and still grow across a shift.
    const cached = [A]
    const answer = [B, C]

    expect(reconcile(cached, answer)).toEqual(reconcile(cached, answer))
    // And it does not mutate what it was given, which is how an "append" can hide inside a
    // function whose RETURN value looks correct.
    expect(cached).toEqual([A])
    expect(answer).toEqual([B, C])
  })
})

describe('the cadence', () => {
  it('is five seconds, declared independently of the staff board', () => {
    expect(KDS_INTERVAL_SECONDS).toBe(5)

    /*
     * ⚠ THE TWO FIGURES ARE EQUAL AND THE CONSTANTS ARE SEPARATE, which is what this case
     * exists to pin. `resync.ts`'s header has the argument: the staff board's cost scales
     * with staff headcount and a KDS's with screens-per-kitchen on a device nobody closes, so
     * a change made for one must not silently re-pace the other. That is `staff/board.ts`'s
     * own reasoning about `config('pager.poll_interval')`, one surface over.
     *
     * The assertion is that each is its own value, not that they differ — so this passes today
     * and keeps passing if one of them moves, while a later "tidy-up" that collapses one into
     * the other fails the source read in the next case.
     */
    expect(BOARD_INTERVAL_SECONDS).toBe(5)
  })

  it('declares its own interval rather than importing the board’s, read from the source', () => {
    /*
     * ⚠ THE CASE ABOVE CANNOT SEE THIS, AND THAT IS WHY THIS ONE EXISTS. Both figures are 5
     * today, so `KDS_INTERVAL_SECONDS = BOARD_INTERVAL_SECONDS` would satisfy every runtime
     * assertion in this file — and would be exactly the coupling `resync.ts`'s header argues
     * against. The property is about where the value COMES FROM, which is a property of the
     * source rather than of the value, so it is read from the source.
     *
     * The same shape as `apps/web/test/copy.test.ts`'s literal-JSX scan and
     * `staff-build-surface.test.ts`'s route derivation: a claim about the code's structure is
     * asserted against the code.
     */
    const source = readFileSync(join(import.meta.dirname, '..', 'src', 'kds', 'resync.ts'), 'utf8')

    /*
     * ⚠ COMMENTS ARE STRIPPED FIRST, AND THE FIRST VERSION OF THIS CASE WENT RED BECAUSE THEY
     * WERE NOT. `resync.ts`'s own docblock ARGUES against importing `BOARD_INTERVAL_SECONDS`
     * and therefore names it, so a raw `includes` over the whole file matched the prose that
     * explains why the import is absent — the assertion failing on the very comment that
     * documents the property it is asserting. `copy.test.ts`'s literal-JSX scan strips
     * comments first for exactly this reason, and this follows it.
     */
    const code = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')

    // It is a literal declaration...
    expect(/export const KDS_INTERVAL_SECONDS = \d+$/m.test(code)).toBe(true)
    // ...and the staff board's constant reaches none of the executable code, under any name.
    expect(code.includes('BOARD_INTERVAL_SECONDS')).toBe(false)
    expect(code.includes('staff/board.ts')).toBe(false)
  })

  it('says nothing is stale before the first confirmed answer', () => {
    expect(isKdsStale(null, 1_000_000)).toBe(false)
  })

  it('is stale strictly AFTER one interval, measured from a timestamp', () => {
    const at = 1_000_000

    // Exact values at the boundary rather than a sampled range -- the project's own
    // *assert exact values, not ranges* discipline.
    expect(isKdsStale(at, at + KDS_INTERVAL_SECONDS * 1000)).toBe(false)
    expect(isKdsStale(at, at + KDS_INTERVAL_SECONDS * 1000 + 1)).toBe(true)
  })
})

describe('the elapsed readout', () => {
  it('counts whole seconds from the server timestamp', () => {
    const created = Date.parse('2026-11-02T10:00:00Z')

    expect(kdsElapsedSeconds('2026-11-02T10:00:00Z', created + 61_500)).toBe(61)
  })

  it('clamps a tablet whose clock is behind the server to zero rather than rendering a negative', () => {
    // ⚠ NOT DEFENSIVE PADDING. Kitchen tablets are cheap, rarely updated and in a room nobody
    // administers, and an unclamped subtraction renders `-00:04` for a few seconds after every
    // order — which on a screen read across a kitchen looks like a defect in the ORDER.
    const created = Date.parse('2026-11-02T10:00:00Z')

    expect(kdsElapsedSeconds('2026-11-02T10:00:00Z', created - 4000)).toBe(0)
  })

  it('answers zero for an unparseable timestamp rather than NaN', () => {
    // `formatElapsed(NaN)` would render `NaN:NaN`. A row with a missing `created_at` is
    // dropped by `fetchKdsBoard`, so this is the second line of defence rather than the
    // first -- and it is here because the first is a `continue` somebody could remove.
    expect(kdsElapsedSeconds('not a date', 1_000_000)).toBe(0)
  })
})
