import { describe, expect, it } from 'vitest'

import {
  deviceStorageFrom,
  forgetDeviceToken,
  recallDeviceToken,
  rememberDeviceToken,
} from '../src/kds/storage.ts'

/**
 * ================================================================================
 * WHERE A KITCHEN SCREEN's CREDENTIAL LIVES — 10c
 * ================================================================================
 *
 * A thirty-day bearer credential for a whole restaurant's order board, on a tablet screwed to
 * a wall. `src/kds/storage.ts`'s header carries why it is persisted at all (a kiosk browser
 * relaunches with the lights and there is nobody to re-enter it) and why it is not in the URL.
 *
 * What is asserted here is the handling: a trimmed read, an empty value treated as absence,
 * deletion, and the two ways a locked-down browser can take `localStorage` away.
 */

/** A store backed by a Map, which is what the module's `Pick<Storage, …>` shape admits. */
function memory(initial?: Record<string, string>) {
  const map = new Map<string, string>(Object.entries(initial ?? {}))

  return {
    store: {
      storage: {
        getItem: (key: string) => map.get(key) ?? null,
        setItem: (key: string, value: string) => {
          map.set(key, value)
        },
        removeItem: (key: string) => {
          map.delete(key)
        },
      },
    },
    map,
  }
}

const KEY = 'qrpager.kds.token'

describe('remembering and recalling a code', () => {
  it('writes under one key and reads it back', () => {
    const { store, map } = memory()

    rememberDeviceToken(store, 'a-code')

    expect(map.get(KEY)).toBe('a-code')
    expect(recallDeviceToken(store)).toBe('a-code')
  })

  it('answers null when nothing is stored', () => {
    expect(recallDeviceToken(memory().store)).toBeNull()
  })

  it('trims what it reads, because the value arrives by PASTE', () => {
    // A trailing newline from a copied line is the ordinary case on a tablet, and sending
    // `Bearer a-code\n` is a 401 that names nothing.
    expect(recallDeviceToken(memory({ [KEY]: '  a-code \n' }).store)).toBe('a-code')
  })

  it('treats an empty or whitespace-only value as ABSENT rather than as a code', () => {
    /*
     * The difference matters: absent shows the setup field, which is actionable, while a code
     * of `''` would send `Authorization: Bearer ` and render the 401 as a refused code — a
     * refusal the person did not cause.
     */
    for (const value of ['', '   ', '\n\t']) {
      expect(recallDeviceToken(memory({ [KEY]: value }).store), `"${value}"`).toBeNull()
    }
  })

  it('deletes the entry rather than blanking it', () => {
    const { store, map } = memory({ [KEY]: 'a-code' })

    forgetDeviceToken(store)

    expect(map.has(KEY)).toBe(false)
    expect(recallDeviceToken(store)).toBeNull()
  })

  it('is a no-op on every call when there is no storage at all', () => {
    /*
     * ⚠ THE NULL-STORAGE PATH IS DRIVEN RATHER THAN ASSUMED SAFE. A kiosk tablet is exactly
     * the kind of device somebody has locked down, and all three functions have to survive it:
     * a throw here would blank the screen instead of showing a field that says what it needs.
     */
    const none = { storage: null }

    expect(() => {
      rememberDeviceToken(none, 'a-code')
    }).not.toThrow()
    expect(recallDeviceToken(none)).toBeNull()
    expect(() => {
      forgetDeviceToken(none)
    }).not.toThrow()
  })
})

describe('resolving the browser’s storage', () => {
  it('answers a null store when there is no window, which is the prerender', () => {
    // These routes are statically prerendered, so the server render has no `window` at all.
    expect(deviceStorageFrom(undefined).storage).toBeNull()
  })

  it('answers the real localStorage when there is one', () => {
    const resolved = deviceStorageFrom(window)

    expect(resolved.storage).not.toBeNull()

    // Driven end to end against the real thing, so the `Pick<Storage, …>` shape is proven to
    // match what a browser actually provides rather than only what the fake does.
    rememberDeviceToken(resolved, 'real-code')
    expect(recallDeviceToken(resolved)).toBe('real-code')
    forgetDeviceToken(resolved)
    expect(recallDeviceToken(resolved)).toBeNull()
  })

  it('answers a null store when touching localStorage THROWS', () => {
    /*
     * ⚠ THE REASON IT IS A `try` AND NOT A `typeof` CHECK, driven. Accessing
     * `window.localStorage` THROWS rather than answering `undefined` in a Safari private
     * window and under a blocked-cookies policy — so a `typeof` guard would pass the check and
     * then throw on the next line, which on a wall screen is a blank page.
     */
    const hostile = {} as unknown as Window

    Object.defineProperty(hostile, 'localStorage', {
      get() {
        throw new DOMException('The operation is insecure.', 'SecurityError')
      },
    })

    expect(deviceStorageFrom(hostile).storage).toBeNull()
  })
})
