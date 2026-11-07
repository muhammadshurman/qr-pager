import { asPresentedToken, type PresentedToken } from './token.ts'

/**
 * ================================================================================
 * WHERE THE TOKEN LIVES BETWEEN LAUNCHES, AND WHY IT HAS TO LIVE ANYWHERE
 * ================================================================================
 *
 * ⚠ THE REASON IS THE HOME-SCREEN INSTALL, and it is not a convenience.
 *
 * A Home-Screen install launches at the manifest's `start_url`, which carries **no
 * fragment**. So a token held only in memory or only in `location.hash` is gone the moment
 * the customer does the one thing `§4 › Realtime` requires them to do for notifications to
 * work at all. QRPAGER-24's harness page states exactly this and persists for the same
 * reason; this inherits it.
 *
 * ⚠ AND IT IS DELETED, WHICH IS THE HALF THAT MATTERS. A 128-bit bearer token with a
 * two-hour life, on a phone that gets handed to a friend, left on a table or sold. So:
 *
 *   - `sessionStorage` is NOT used: it does not survive the install-and-relaunch this exists
 *     for, which is the only reason persistence is here.
 *   - the entry is removed on every TERMINAL status, and on unsubscribe.
 *   - it is removed on read when the session's own `expires_at` has passed, so a token that
 *     outlived its session never reaches a request -- a client-side mirror of
 *     `ResolvedPagerSession::hasExpired()`, which computes from `expires_at` and never from
 *     status for the reason `R-058` records (`pager:expire` is scheduled nowhere, so status
 *     lags).
 *
 * Both of the token's homes -- this one and the service worker's `Cache` -- are cleared
 * together by `endSession()`, and `apps/web/test/storage.test.ts` asserts both are empty
 * afterwards. Two homes cleared by one call, rather than two calls anyone could forget one of.
 */

const TOKEN_KEY = 'qrpager.pager.token'
const EXPIRES_KEY = 'qrpager.pager.expires'

export interface TokenStore {
  readonly storage: Pick<Storage, 'getItem' | 'setItem' | 'removeItem'> | null
  readonly now: () => number
}

/**
 * Remember the token and the session's own expiry.
 *
 * The expiry is stored BESIDE the token rather than derived from it: the token carries no
 * structure -- it is 128 random bits -- so there is nothing in it to read an expiry from.
 */
export function rememberToken(
  store: TokenStore,
  token: PresentedToken,
  expiresAtIso: string,
): void {
  store.storage?.setItem(TOKEN_KEY, token)
  store.storage?.setItem(EXPIRES_KEY, expiresAtIso)
}

/**
 * The remembered token, if there is one and it has not outlived its session.
 *
 * ⚠ AN EXPIRED ENTRY IS DELETED ON READ, not merely ignored. Ignoring it leaves the secret
 * on the device for as long as the browser keeps `localStorage`, which is indefinitely.
 */
export function recallToken(store: TokenStore): PresentedToken | null {
  const raw = store.storage?.getItem(TOKEN_KEY) ?? null

  if (raw === null) return null

  const token = asPresentedToken(raw)

  if (token === null) {
    // Not shaped like a token: either a different version of this app wrote it or something
    // else did. Either way it is not usable and it is not kept.
    forgetToken(store)

    return null
  }

  const expires = store.storage?.getItem(EXPIRES_KEY) ?? null

  if (expires !== null) {
    const at = Date.parse(expires)

    if (!Number.isNaN(at) && at <= store.now()) {
      forgetToken(store)

      return null
    }
  }

  return token
}

export function forgetToken(store: TokenStore): void {
  store.storage?.removeItem(TOKEN_KEY)
  store.storage?.removeItem(EXPIRES_KEY)
}

/** `localStorage` when it is reachable, `null` when it is not. */
export function storageFrom(
  win: Window,
): Pick<Storage, 'getItem' | 'setItem' | 'removeItem'> | null {
  try {
    // ⚠ A PROBE, NOT A PRESENCE CHECK. `window.localStorage` EXISTS and THROWS on access in
    // Safari private browsing and when a site's storage is blocked -- so `'localStorage' in
    // window` is true in exactly the cases where using it crashes the page. Measured shape,
    // and the reason the whole module takes storage as `| null`.
    const storage = win.localStorage
    const probe = '__qrpager_probe__'
    storage.setItem(probe, '1')
    storage.removeItem(probe)

    return storage
  } catch {
    return null
  }
}
