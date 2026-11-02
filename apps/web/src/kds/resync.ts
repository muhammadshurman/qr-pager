/**
 * ================================================================================
 * THE RESYNC. `QRPAGER-27 §0`'s SECOND HALF — *shows NO duplicate row*
 * ================================================================================
 *
 * The invariant, verbatim: *"A KDS that loses the network mid-shift and reconnects re-sends NO
 * notification and shows NO duplicate row."*
 *
 * The first half is structural and is `api.ts`'s: there is no write in this client, so there
 * is no request a reconnect could replay. **This file is the second half**, and it is the one
 * with a real decision in it, because a kitchen screen is required to keep showing its orders
 * while the network is down — which means there are two lists in play the moment it comes back.
 *
 * ================================================================================
 * THE RULE: A SUCCESSFUL POLL REPLACES. IT NEVER MERGES. EVER.
 * ================================================================================
 *
 * `QRPAGER-27 §5` names the trap: *"A naive refetch after reconnect can re-trigger sends"* —
 * and the sibling defect, which is the one a UI reaches first, is a naive refetch that
 * **appends**. The shapes that produce a duplicate row are all merges wearing different
 * clothes:
 *
 *   `[...cached, ...fresh]`                      the obvious one
 *   `[...fresh, ...cached.filter(stillLive)]`    "keep what the server has not confirmed yet"
 *   a `Map` seeded with `cached` then written
 *       over by `fresh`                          looks safe, and KEEPS A ROW THE SERVER DROPPED
 *
 * The third is the interesting one and is why this function exists rather than a bare
 * `setOrders(fresh)` at the call site. It produces no duplicate *id*, so an assertion counting
 * ids would pass — and it still shows a row that is gone: an order completed during the
 * outage stays on the kitchen screen for ever, because no future poll will ever mention it
 * again to remove it. A cook plates a dish nobody is waiting for.
 *
 * **So the server's answer is the whole truth about which orders exist.** `reconcile` returns
 * the fresh list and nothing from the cache. The cache is only ever shown while a poll is
 * FAILING, which is `§4.1`'s *polling degraded* one surface over, and `staleSince` is what
 * makes keeping it honest rather than a lie — the same reasoning `staff/board.ts`'s `isStale`
 * records for the staff board.
 *
 * ================================================================================
 * WHY THIS IS A FUNCTION AND NOT THREE LINES IN A `useEffect`
 * ================================================================================
 *
 * Because the property it holds is 10c's gate clause, and a clause asserted against a pure
 * function with a table of cases is asserted; the same three lines inside an effect are
 * reachable only through a rendered component, a fake clock and a mocked `fetch`. The browser
 * proof (`tools/gate/bin/kds-resync-proof`) drives the real thing end to end; this is what
 * makes the rule itself falsifiable in isolation, and `apps/web/test/kds-resync.test.ts`
 * drives the merge shapes above as explicit NEGATIVE cases.
 */

import type { KdsOrder } from './api.ts'

/**
 * What the screen should show, given what it has and what the server just said.
 *
 * @param cached what is on screen now.
 * @param answer the fresh list from a SUCCESSFUL poll, or `null` when the poll failed.
 */
export function reconcile(
  cached: readonly KdsOrder[],
  answer: readonly KdsOrder[] | null,
): readonly KdsOrder[] {
  // A FAILED poll changes nothing. The rows are not wrong, they are merely not fresh, and the
  // staleness marker is what says so.
  if (answer === null) return cached

  /*
   * A SUCCESSFUL poll replaces — and is DEDUPED BY ID on the way in.
   *
   * ⚠ THE DEDUPE IS NOT DEFENSIVE PROGRAMMING AGAINST A SERVER THAT CANNOT MISBEHAVE, which
   * is the objection to it and is worth answering. `pager_sessions.id` is a primary key, so
   * one response cannot carry a row twice today. What it guards is the SHAPE of the
   * assertion: 10c's clause is *shows no duplicate row*, and a client that would render a
   * duplicate if it ever received one has satisfied the clause by luck rather than by
   * construction. It costs one pass over at most 100 rows and it makes
   * `kds-resync.test.ts`'s duplicate case a test of this code instead of a test of Postgres.
   *
   * FIRST OCCURRENCE WINS, so the server's own ordering (newest first, `board()`'s `order by
   * created_at desc`) is preserved rather than being quietly reversed by a last-wins write.
   */
  const seen = new Set<string>()
  const out: KdsOrder[] = []

  for (const order of answer) {
    if (seen.has(order.id)) continue

    seen.add(order.id)
    out.push(order)
  }

  return out
}

/**
 * How often a kitchen screen asks, in seconds.
 *
 * ⚠ THE SAME AS THE STAFF BOARD'S FIVE, AND IT IS A SEPARATE CONSTANT ON PURPOSE —
 * `staff/board.ts`'s own argument, applied one surface over. That file states why the board
 * does not read `config('pager.poll_interval')`: *"making the board read `pager.poll_interval`
 * would be tidier and would mean a change made for the customer's sake silently re-paced the
 * kitchen."* Importing `BOARD_INTERVAL_SECONDS` here would be the same mistake between the
 * two STAFF surfaces: a change made for a hand-held staff board would silently re-pace every
 * wall screen in every kitchen, and the two scale on different axes — the staff board's cost
 * is staff headcount, a KDS's is screens-per-kitchen and runs 24 hours a day on a device
 * nobody closes.
 *
 * Five seconds, same figure, two reasons, two constants. `kds-cadence.test.ts` pins both
 * values and asserts they are declared independently, so a later "tidy-up" that collapses them
 * fails rather than passing.
 */
export const KDS_INTERVAL_SECONDS = 5

/**
 * Whether what is on screen is older than one interval, so the screen should say when it was
 * last confirmed.
 *
 * Identical arithmetic to `staff/board.ts`'s `isStale` and identical reasoning: computed from a
 * TIMESTAMP, never from a tick count, because a `setInterval` in a backgrounded tab is
 * throttled or stopped by every engine — and a wall tablet whose screen has blanked for the
 * night is the most backgrounded tab this product has. A counter that increments itself would
 * be wrong by however long the tablet slept, in the direction that makes stale data look fresh.
 */
export function isKdsStale(lastConfirmedMs: number | null, nowMs: number): boolean {
  if (lastConfirmedMs === null) return false

  return nowMs - lastConfirmedMs > KDS_INTERVAL_SECONDS * 1000
}

/**
 * Whole seconds since an order was created, from the SERVER's own timestamp.
 *
 * ⚠ CLAMPED AT ZERO. The server's clock and a wall tablet's clock are two clocks, and a
 * kitchen tablet's is frequently the wrong one — they are cheap, they are rarely updated and
 * `§12`'s own subject is a device *"viewed from two metres"* in a room nobody administers. An
 * unclamped subtraction renders `-00:04` for a few seconds after every order is created, which
 * on a screen read across a kitchen looks like a defect in the order rather than in a clock.
 */
export function kdsElapsedSeconds(createdAtIso: string, nowMs: number): number {
  const created = Date.parse(createdAtIso)

  if (Number.isNaN(created)) return 0

  return Math.max(0, Math.floor((nowMs - created) / 1000))
}
