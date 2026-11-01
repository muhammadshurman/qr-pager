import { isWellFormedPagerToken } from '@qrpager/contracts'

/**
 * ================================================================================
 * THE QR TOKEN ON THE CLIENT. `§3` row 9's clause (a), and it is the whole file.
 * ================================================================================
 *
 * `§3` row 3 as amended puts the raw token in the URL **fragment**: RFC 3986 §3.5 has the
 * client resolve it, so it reaches no access log, no `Referer` and no CDN cache key, and the
 * path stays constant so a scan burst is one cache entry rather than one per scan. See
 * `docs/tickets/QRPAGER-16.md SR-1` for the conflict that spelling has with `§4.1`'s
 * `/s/{token}`, which is reported rather than resolved here.
 *
 * WHAT THE FRAGMENT STILL LEAVES, which no wording removes and `§3` row 9 lists:
 *
 *   - it is in `location.hash` and `document.URL`, so any script on this origin reads it;
 *   - it is written to browser history, which syncs to a cloud profile by default;
 *   - a client error reporter captures `location.href` including the fragment;
 *   - a client-side redirect re-attaches it to the new URL.
 *
 * So clause (a) is mechanical: read it ONCE and strip it IMMEDIATELY.
 *
 * ⚠ `replaceState`, NEVER `pushState`, and never an assignment to `location.hash`. Both
 * alternatives leave the token-bearing URL in a history ENTRY, which is the second bullet
 * above. `replaceState` rewrites the current entry in place, so `history.length` does not
 * grow and no entry's URL ever carried the token after this function returns. Asserted in
 * a real engine, both properties.
 *
 * ⚠ AND THE SHAPE TEST COMES FROM THE GENERATED CONTRACT, not from a literal here.
 * `isWellFormedPagerToken` is emitted into `packages/contracts` from
 * `App\Domain\Pager\PagerToken::LENGTH` and `::ALPHABET` by `php artisan contracts:export`,
 * and `composer contracts:check` byte-diffs it. A typed `22` would be `§6.2`'s second
 * source: it agrees on the day it is written and drifts silently after.
 */

/** A token that passed the generated shape test. Branded, so an unchecked string cannot be used as one. */
export type PresentedToken = string & { readonly __presented: unique symbol }

export function asPresentedToken(value: string): PresentedToken | null {
  return isWellFormedPagerToken(value) ? (value as PresentedToken) : null
}

/**
 * The fragment forms this client accepts.
 *
 * TWO, and the second is not legacy tolerance. `PagerToken::qrPayload()` emits
 * `{landing_url}#{token}` -- a bare token -- and that is what a camera scans. QRPAGER-24's
 * harness uses `#t={token}` because an operator pastes it by hand and a prefix makes the
 * string readable as a parameter. Task 9 `extends` that harness (ticket `§2`), the operator
 * script is a committed document, and refusing its form would break a flow this project
 * still runs. Both are anchored and both go through the same shape test.
 */
const BARE = /^#([^#&=?]+)$/
const PREFIXED = /^#t=([^#&=?]+)$/

export type TokenReadOutcome =
  /** A fragment was present and is shaped like a token. */
  | { readonly kind: 'present'; readonly token: PresentedToken }
  /** A fragment was present and is NOT shaped like a token. No request is made for this. */
  | { readonly kind: 'malformed' }
  /** No fragment. Either a Home-Screen launch (`start_url` carries none) or a direct visit. */
  | { readonly kind: 'absent' }

/**
 * Parse a fragment. Pure, so the regexes and the shape test are unit-testable without a DOM.
 */
export function parseFragment(hash: string): TokenReadOutcome {
  if (hash === '' || hash === '#') return { kind: 'absent' }

  const match = PREFIXED.exec(hash) ?? BARE.exec(hash)

  if (match === null) return { kind: 'malformed' }

  // `match[1]` is `string | undefined` under noUncheckedIndexedAccess. A matched group can
  // still be typed optional and `!` is what that flag exists to prevent, so the `??` is the
  // check and not noise.
  const candidate = match[1] ?? ''
  const token = asPresentedToken(candidate)

  return token === null ? { kind: 'malformed' } : { kind: 'present', token }
}

/**
 * Read the fragment and strip it, in that order, exactly once.
 *
 * ⚠ IT STRIPS EVEN WHEN THE FRAGMENT IS MALFORMED. A malformed fragment is still an
 * attacker-supplied value in `document.URL` and in history, and leaving it there because it
 * failed our shape test would make the control conditional on the input being well formed --
 * which is the wrong way round. Asserted with a deliberately bad fragment.
 *
 * The caller is responsible for calling this once; `PagerJourney` does it from a mount
 * effect with an empty dependency list and a ref guard, because React 19 strict mode mounts
 * twice in development and a second read would find the fragment already gone.
 */
export function readPresentedToken(win: Window = window): TokenReadOutcome {
  const outcome = parseFragment(win.location.hash)

  if (outcome.kind !== 'absent') {
    // pathname only: no search, no hash. `location.search` is empty on this route by
    // construction (`§4 › Data` forbids the token in a query string and nothing else is
    // passed), and carrying it forward would be a second place a value could hide.
    win.history.replaceState(null, '', win.location.pathname)
  }

  return outcome
}
