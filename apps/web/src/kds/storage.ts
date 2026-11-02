/**
 * ================================================================================
 * WHERE A KITCHEN SCREEN'S CREDENTIAL LIVES, AND WHY IT LIVES ANYWHERE AT ALL
 * ================================================================================
 *
 * A KDS device token is minted once on `/staff/devices`, shown once, and then has to reach a
 * wall-mounted tablet that nobody signs in to. `staff.devices.lede` is the product promise in
 * one sentence: *"A kitchen screen shows the orders without anybody signing in to it."* So the
 * token is pasted into this screen once, at setup, and must survive every relaunch of a device
 * that is power-cycled with the kitchen lights.
 *
 * That rules out both alternatives:
 *
 *   - **A token in the URL** — `/kds?token=…` or `/kds#…` — is what the customer surface does,
 *     and it is right there and wrong here. A customer's token is a two-hour per-session
 *     secret delivered by a QR code nobody types; this one is a **thirty-day** credential
 *     (`DEVICE_TTL_MINUTES`) for a whole restaurant's order board, and a URL is the one place
 *     a credential is copied by accident: a bookmark, a kiosk-mode start page, a screenshot of
 *     the wall tablet, a browser's own history sync. `§4 › Data` keeps raw tokens out of paths
 *     and query strings for the customer's and this is the same rule.
 *   - **`sessionStorage`** does not survive the relaunch this exists for, which is the only
 *     reason persistence is here at all.
 *
 * ================================================================================
 * AND IT IS DELETABLE FROM THE SCREEN ITSELF, WHICH IS THE HALF THAT MATTERS
 * ================================================================================
 *
 * A tablet comes off the wall and goes in a drawer, or a restaurant closes. `forgetDeviceToken`
 * is wired to a control on the screen (`kds.forget`) so the person holding the device can
 * revoke its local copy without finding a manager, a laptop and the staff surface — and the
 * server-side half (`DELETE /v1/devices/{id}`) is 10a's and is the one that actually stops the
 * credential working. Both halves are named on the screen rather than one being implied.
 *
 * ⚠ AND A 401 DELETES IT ON THE SPOT. A device token expires (thirty days) or is revoked from
 * `/staff/devices`, and in both cases every subsequent poll is a 401. Keeping the dead value
 * would leave a useless credential at rest on a shared device for as long as the browser keeps
 * `localStorage`, which is indefinitely — so `KdsApp` forgets it and returns to setup, which is
 * also the only honest screen to show: this device needs a new code.
 */

/** One key, one value. There is no expiry to store beside it: see below. */
const TOKEN_KEY = 'qrpager.kds.token'

export interface DeviceTokenStore {
  readonly storage: Pick<Storage, 'getItem' | 'setItem' | 'removeItem'> | null
}

/**
 * Remember this screen's token.
 *
 * ⚠ NO EXPIRY IS STORED BESIDE IT, AND THAT IS A DIFFERENCE FROM THE CUSTOMER STORE RATHER
 * THAN AN OMISSION. `src/pager/storage.ts` keeps `expires_at` because it is handed the
 * session's expiry in the same response and can therefore delete a token that outlived its
 * session before it reaches a request. Here there is no such response: the token is PASTED,
 * so the client is told nothing about it, and a client-side expiry would have to be guessed
 * from `DEVICE_TTL_MINUTES` — a guess that is wrong the moment a token is issued with a
 * different TTL or revoked early. The server's 401 is the authority, and the branch above
 * acts on it.
 */
export function rememberDeviceToken(store: DeviceTokenStore, token: string): void {
  store.storage?.setItem(TOKEN_KEY, token)
}

/**
 * The remembered token, or null.
 *
 * Trimmed and emptiness-checked, because the value's origin is a PASTE into a text field on a
 * greasy tablet: a trailing newline from a copied line, or a whitespace-only paste, both reach
 * here. An empty string is `null` rather than a token, so the screen shows setup instead of
 * sending `Authorization: Bearer ` and rendering a 401 as a mysterious failure.
 */
export function recallDeviceToken(store: DeviceTokenStore): string | null {
  const raw = store.storage?.getItem(TOKEN_KEY) ?? null

  if (raw === null) return null

  const trimmed = raw.trim()

  return trimmed === '' ? null : trimmed
}

export function forgetDeviceToken(store: DeviceTokenStore): void {
  store.storage?.removeItem(TOKEN_KEY)
}

/**
 * `localStorage`, or null where there is none.
 *
 * ⚠ IT IS A `try`, NOT A `typeof` CHECK. Accessing `window.localStorage` THROWS rather than
 * answering `undefined` in a Safari private window and under a blocked-cookies policy, and a
 * kitchen tablet in kiosk mode is exactly the kind of device somebody has locked down. A throw
 * here would blank the screen; `null` makes the setup field reappear after every reload, which
 * is poor but is a working screen that says what it needs.
 */
export function deviceStorageFrom(window: Window | undefined): DeviceTokenStore {
  if (window === undefined) return { storage: null }

  try {
    return { storage: window.localStorage }
  } catch {
    return { storage: null }
  }
}
