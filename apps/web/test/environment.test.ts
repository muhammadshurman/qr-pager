import { describe, expect, it } from 'vitest'

import { alertEnvironmentFrom, soundTheAlert, type AlertEnvironment } from '../src/pager/alert.ts'
import { displayMode, installGuidance, isIosFamily, pushCanReach } from '../src/pager/install.ts'
import { FALLBACK, browserLocale, resolveLocale } from '../src/pager/locale.ts'
import { forgetToken, recallToken, rememberToken, storageFrom } from '../src/pager/storage.ts'
import type { PresentedToken } from '../src/pager/token.ts'
import { holdScreenAwake, wakeEnvironmentFrom } from '../src/pager/wake.ts'

const TOKEN = 'AbCdEfGhIjKlMnOpQrStUv' as PresentedToken

function memoryStore() {
  const items = new Map<string, string>()

  return {
    getItem: (key: string) => items.get(key) ?? null,
    setItem: (key: string, value: string) => {
      items.set(key, value)
    },
    removeItem: (key: string) => {
      items.delete(key)
    },
    items,
  }
}

// ======================================================================= locale

describe('the locale, from the browsers own list', () => {
  it('matches on the PRIMARY SUBTAG, not by equality', () => {
    /**
     * ⚠ THE CASE THAT DECIDES WHETHER ARABIC LAUNCHES AT ALL. Real phones send `ar-JO`,
     * `ar-SA`, `ar-EG` -- never a bare `ar`. A `===` test against `LOCALES` would resolve
     * every Arabic phone in the region this product launches in to English, and it would
     * pass a test written with a bare `'ar'`.
     */
    expect(resolveLocale(['ar-JO'])).toBe('ar')
    expect(resolveLocale(['ar-SA', 'en-US'])).toBe('ar')
    expect(resolveLocale(['AR-eg'])).toBe('ar')
    expect(resolveLocale(['en-GB'])).toBe('en')
  })

  it('honours the order the user configured', () => {
    expect(resolveLocale(['en-US', 'ar-JO'])).toBe('en')
    expect(resolveLocale(['ar-JO', 'en-US'])).toBe('ar')
  })

  it('falls back to English for a language with no catalogue, and for an empty list', () => {
    expect(resolveLocale(['fr-FR', 'de-DE'])).toBe('en')
    expect(resolveLocale([])).toBe(FALLBACK)
    expect(FALLBACK).toBe('en')
  })

  it('reads `languages` and falls back to `language` when the list is empty', () => {
    expect(browserLocale({ languages: ['ar-JO'], language: 'en-US' })).toBe('ar')
    expect(browserLocale({ languages: [], language: 'ar-EG' })).toBe('ar')
  })
})

// ====================================================================== storage

describe('the token between launches', () => {
  it('is remembered and recalled, which is what makes a Home-Screen install usable', () => {
    // A Home-Screen install launches at the manifest's `start_url`, which carries NO
    // fragment -- so a token held only in `location.hash` is gone the moment the customer
    // does the one thing `§4 › Realtime` requires for notifications to work on iOS.
    const store = { storage: memoryStore(), now: () => Date.parse('2026-11-01T12:00:00Z') }

    rememberToken(store, TOKEN, '2026-11-01T14:00:00Z')

    expect(recallToken(store)).toBe(TOKEN)
  })

  it('DELETES an entry whose session has expired, on read', () => {
    /**
     * ⚠ DELETED, NOT IGNORED. Ignoring it leaves a 128-bit bearer secret in `localStorage`
     * for as long as the browser keeps it, which is indefinitely. And the expiry is computed
     * from `expires_at` rather than from a status, which is `ResolvedPagerSession::hasExpired()`'s
     * own reasoning: `pager:expire` is scheduled nowhere (`R-058`), so status lags.
     */
    const store = { storage: memoryStore(), now: () => Date.parse('2026-11-01T14:00:01Z') }

    rememberToken(store, TOKEN, '2026-11-01T14:00:00Z')

    expect(recallToken(store)).toBeNull()
    expect(store.storage.items.size).toBe(0)
  })

  it('keeps an entry one second before its expiry', () => {
    const store = { storage: memoryStore(), now: () => Date.parse('2026-11-01T13:59:59Z') }

    rememberToken(store, TOKEN, '2026-11-01T14:00:00Z')

    expect(recallToken(store)).toBe(TOKEN)
  })

  it('deletes a stored value that is not shaped like a token', () => {
    const storage = memoryStore()
    storage.setItem('qrpager.pager.token', 'something-else')

    expect(recallToken({ storage, now: () => 0 })).toBeNull()
    expect(storage.items.size).toBe(0)
  })

  it('forgets both keys together', () => {
    const store = { storage: memoryStore(), now: () => 0 }

    rememberToken(store, TOKEN, '2026-11-01T14:00:00Z')
    expect(store.storage.items.size).toBe(2)

    forgetToken(store)
    expect(store.storage.items.size).toBe(0)
  })

  it('tolerates an unparseable expiry rather than discarding a live token', () => {
    const storage = memoryStore()
    storage.setItem('qrpager.pager.token', TOKEN)
    storage.setItem('qrpager.pager.expires', 'not a date')

    expect(recallToken({ storage, now: () => 0 })).toBe(TOKEN)
  })

  it('returns null, not a throw, when storage itself throws on access', () => {
    /**
     * ⚠ A PROBE, NOT A PRESENCE CHECK, AND THE REASON IS MEASURED SHAPE. `window.localStorage`
     * EXISTS and THROWS on access in Safari private browsing and when a site's storage is
     * blocked -- so `'localStorage' in window` is true in exactly the cases where using it
     * crashes the page.
     */
    const hostile = {
      get localStorage() {
        throw new DOMException('blocked')
      },
    } as unknown as Window

    expect(storageFrom(hostile)).toBeNull()
  })

  it('finds the real localStorage when it works, and leaves no probe behind', () => {
    const storage = storageFrom(window)

    expect(storage).not.toBeNull()
    expect(window.localStorage.getItem('__qrpager_probe__')).toBeNull()
  })
})

// ====================================================================== install

const IPHONE =
  'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1'
const IPAD_DESKTOP_UA =
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15'
const MAC =
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36'
const ANDROID =
  'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36'

function install(ua: string, standalone: boolean, touch = 0) {
  return {
    matchMedia: (query: string) => ({ matches: standalone && query.includes('standalone') }),
    navigatorStandalone: undefined,
    userAgent: ua,
    maxTouchPoints: touch,
  }
}

describe('the iOS Home-Screen install path', () => {
  it('detects an iPad reporting a DESKTOP Mac user-agent, via maxTouchPoints', () => {
    /**
     * ⚠ WITHOUT THIS AN iPAD NEVER SEES THE INSTALL GUIDANCE. iPadOS 13 and later send
     * `Macintosh; Intel Mac OS X` by default, so a UA test alone reports an iPad as a Mac --
     * and `§4 › Realtime` makes the install the ONLY path to a notification on the iOS
     * family. A Mac reports `maxTouchPoints === 0`; an iPad reports 5.
     */
    expect(isIosFamily(install(IPAD_DESKTOP_UA, false, 5))).toBe(true)
    expect(isIosFamily(install(MAC, false, 0))).toBe(false)
    expect(isIosFamily(install(IPHONE, false, 5))).toBe(true)
    expect(isIosFamily(install(ANDROID, false, 5))).toBe(false)
  })

  it('reads the display mode from either probe, because they answer on different engines', () => {
    // `(display-mode: standalone)` is the standard one, honoured by iOS since 16.4;
    // `navigator.standalone` is Safari's own and is what older iOS reports.
    expect(displayMode(install(IPHONE, true, 5))).toBe('standalone')
    expect(displayMode(install(IPHONE, false, 5))).toBe('browser')
    expect(displayMode({ ...install(IPHONE, false, 5), navigatorStandalone: true })).toBe(
      'standalone',
    )
  })

  it('shows the guidance only when the install is ACTUALLY the blocker', () => {
    // An iPhone in a tab needs it. An installed iPhone must not be told to install, and a
    // desktop Chrome must not be told about a Share sheet it does not have.
    expect(installGuidance(install(IPHONE, false, 5))).toEqual({ kind: 'required' })
    expect(installGuidance(install(IPHONE, true, 5))).toEqual({ kind: 'installed' })
    expect(installGuidance(install(ANDROID, false, 5))).toEqual({ kind: 'not-applicable' })
    expect(installGuidance(install(MAC, false, 0))).toEqual({ kind: 'not-applicable' })
    expect(installGuidance(install(IPAD_DESKTOP_UA, false, 5))).toEqual({ kind: 'required' })
  })

  it('reports that a push cannot reach an iOS TAB, which is QRPAGER-24s own clause', () => {
    // *"A push received in Safari WITHOUT a Home Screen install does not satisfy it."*
    expect(pushCanReach(install(IPHONE, false, 5))).toBe(false)
    expect(pushCanReach(install(IPHONE, true, 5))).toBe(true)
    expect(pushCanReach(install(ANDROID, false, 5))).toBe(true)
  })
})

// ========================================================================= wake

describe('the keep-screen-awake toggle', () => {
  it('reports unavailable when the API is ABSENT', () => {
    expect(wakeEnvironmentFrom({} as Navigator).request).toBeNull()
  })

  it('reports unavailable when `request` REJECTS -- the iOS 16.4-18.4 standalone case', async () => {
    /**
     * ⚠ MEASURED BOTH WAYS, WHICH IS WHY THIS IS NOT A DETECTION. MDN's compat data puts
     * `WakeLock` at safari_ios 18.4 full, 16.4-18.4 partial, with the note verbatim: *"Does
     * not work in standalone Home Screen Web Apps."* And the probe measured
     * `navigator.wakeLock` PRESENT in all three engines this gate drives. Presence is not
     * capability, so the toggle is driven by the outcome of a real request.
     */
    const handle = await holdScreenAwake(
      { request: () => Promise.reject(new Error('nope')) },
      () => undefined,
    )

    expect(handle.outcome).toBe('unavailable')
    // And releasing a lock that was never held is a no-op rather than a throw.
    await expect(handle.release()).resolves.toBeUndefined()
  })

  it('holds the lock when request resolves, and releases it on demand', async () => {
    let released = false
    const handle = await holdScreenAwake(
      {
        request: () =>
          Promise.resolve({
            release: () => {
              released = true

              return Promise.resolve()
            },
            addEventListener: () => undefined,
          }),
      },
      () => undefined,
    )

    expect(handle.outcome).toBe('held')
    await handle.release()
    expect(released).toBe(true)
  })

  it('calls back when the ENGINE releases it, which is not a failure', async () => {
    // The engine releases the lock whenever the document hides, by specification. The label
    // must not flip to "unavailable" for that -- the caller re-requests on the next
    // `visibilitychange`.
    let lost = 0
    // ⚠ A HOLDER OBJECT, not a `let`. TypeScript's control-flow analysis does not see the
    // assignment inside the callback below, so a `let listener: (() => void) | null = null`
    // stays narrowed to `null` at the call site and `listener?.()` is a compile error. A
    // property read is not narrowed, which is the standard answer and not a workaround.
    const captured: { release: (() => void) | null } = { release: null }

    const handle = await holdScreenAwake(
      {
        request: () =>
          Promise.resolve({
            release: () => Promise.resolve(),
            addEventListener: (_type: 'release', fn: () => void) => {
              captured.release = fn
            },
          }),
      },
      () => {
        lost += 1
      },
    )

    expect(handle.outcome).toBe('held')
    expect(captured.release).not.toBeNull()
    captured.release?.()
    expect(lost).toBe(1)
  })

  it('swallows a release that the engine already performed', async () => {
    const handle = await holdScreenAwake(
      {
        request: () =>
          Promise.resolve({
            release: () => Promise.reject(new Error('already released')),
            addEventListener: () => undefined,
          }),
      },
      () => undefined,
    )

    await expect(handle.release()).resolves.toBeUndefined()
  })
})

// ======================================================================== alert

function alertEnv(overrides: Partial<AlertEnvironment> = {}): AlertEnvironment {
  return { audioContext: null, vibrate: null, prefersReducedMotion: false, ...overrides }
}

describe('the order-ready alert', () => {
  it('does NOT vibrate under prefers-reduced-motion, and says so in its outcome', async () => {
    /**
     * ⚠ THE JUDGEMENT, STATED RATHER THAN BURIED. `QRPAGER-15 §6` trap 3 and the approved
     * direction both say reduced motion means NO motion, not less -- and a buzz is the most
     * physical motion this product produces. A user who asked their operating system for no
     * motion asked for this too. The notification and the colour change carry the alert.
     */
    let vibrated = 0
    const outcome = await soundTheAlert(
      alertEnv({
        prefersReducedMotion: true,
        vibrate: () => {
          vibrated += 1

          return true
        },
      }),
    )

    expect(vibrated).toBe(0)
    expect(outcome.vibrated).toBe(false)
    expect(outcome.vibrationSuppressedByMotionPreference).toBe(true)
  })

  it('DOES vibrate when motion is not suppressed -- the control for the test above', async () => {
    // Without this case, "does not vibrate under reduced motion" would pass on a build where
    // vibration never happens at all.
    let pattern: unknown = null
    const outcome = await soundTheAlert(
      alertEnv({
        vibrate: (p) => {
          pattern = p

          return true
        },
      }),
    )

    expect(outcome.vibrated).toBe(true)
    expect(pattern).toEqual([120, 80, 200])
    expect(outcome.vibrationSuppressedByMotionPreference).toBe(false)
  })

  it('reports nothing suppressed when the engine has no vibration at all', async () => {
    // firefox 157.0 and webkit 27.2, measured. A screen that required it would be silent on
    // two of the three engines and on every iPhone.
    const outcome = await soundTheAlert(alertEnv({ prefersReducedMotion: true }))

    expect(outcome.vibrated).toBe(false)
    expect(outcome.vibrationSuppressedByMotionPreference).toBe(false)
  })

  it('is silent, without throwing, when there is no AudioContext', async () => {
    const outcome = await soundTheAlert(alertEnv())

    expect(outcome.sounded).toBe(false)
  })

  it('is silent, without throwing, when the context stays suspended', async () => {
    // Every engine starts a context `suspended` without a prior gesture, and on the push
    // path there has been no gesture at all. Correct behaviour, not a bug to work around:
    // an alert that could bypass autoplay policy could also be an ad.
    const outcome = await soundTheAlert(
      alertEnv({
        audioContext: () =>
          ({
            state: 'suspended',
            resume: () => Promise.reject(new Error('autoplay blocked')),
          }) as unknown as AudioContext,
      }),
    )

    expect(outcome.sounded).toBe(false)
  })

  it('plays two tones when the context is running', async () => {
    const started: number[] = []
    const context = {
      state: 'running',
      currentTime: 0,
      destination: {},
      createGain: () => ({ gain: { value: 0 }, connect: () => undefined }),
      createOscillator: () => ({
        type: '',
        frequency: { value: 0 },
        connect: () => undefined,
        start: (at: number) => started.push(at),
        stop: () => undefined,
      }),
    } as unknown as AudioContext

    const outcome = await soundTheAlert(alertEnv({ audioContext: () => context }))

    expect(outcome.sounded).toBe(true)
    // An interval, not a beep: two tones at two offsets.
    expect(started).toEqual([0, 0.16])
  })

  it('reads vibration through an index signature, because lib.dom lies about it', () => {
    /**
     * ⚠ THE DOM TYPES DECLARE `navigator.vibrate` AS ALWAYS PRESENT AND THE PROBE MEASURED IT
     * ABSENT in firefox 157.0 and webkit 27.2. jsdom is the same: it implements no
     * `vibrate`, which is exactly the shape those two engines have -- so this environment is
     * the right one to assert the guard in.
     */
    const env = alertEnvironmentFrom(window)

    expect(env.vibrate).toBeNull()
    expect(env.prefersReducedMotion).toBe(false)
  })
})

describe('the capability probes read what the engine actually has', () => {
  it('builds a working AudioContext factory and vibrate wrapper when the engine has them', async () => {
    /**
     * ⚠ THE CLOSURES THEMSELVES, NOT JUST THE GUARDS. `alertEnvironmentFrom` reads both
     * capabilities through an index signature because `lib.dom.d.ts` declares them present
     * when the probe measured them absent -- and a factory that is built but never invoked
     * is a branch nothing has executed. This drives both.
     */
    let constructed = 0
    let vibratedWith: unknown = null

    class FakeContext {
      state = 'running'
      currentTime = 0
      destination = {}

      constructor() {
        constructed += 1
      }

      createGain() {
        return { gain: { value: 0 }, connect: () => undefined }
      }

      createOscillator() {
        return {
          type: '',
          frequency: { value: 0 },
          connect: () => undefined,
          start: () => undefined,
          stop: () => undefined,
        }
      }
    }

    Object.defineProperty(window, 'AudioContext', { configurable: true, value: FakeContext })
    Object.defineProperty(window.navigator, 'vibrate', {
      configurable: true,
      value: function vibrate(this: Navigator, pattern: number | readonly number[]) {
        // ⚠ `this` IS ASSERTED. `navigator.vibrate` is a method; detaching it loses its
        // receiver, and in the one engine that implements it a detached call throws rather
        // than vibrating.
        expect(this).toBe(window.navigator)
        vibratedWith = pattern

        return true
      },
    })

    const env = alertEnvironmentFrom(window)

    expect(env.audioContext).not.toBeNull()
    expect(env.vibrate).not.toBeNull()

    const outcome = await soundTheAlert(env)

    expect(constructed).toBe(1)
    expect(outcome.sounded).toBe(true)
    expect(outcome.vibrated).toBe(true)
    expect(vibratedWith).toEqual([120, 80, 200])

    Reflect.deleteProperty(window, 'AudioContext')
    Reflect.deleteProperty(window.navigator, 'vibrate')
  })

  it('reports no wake lock for a `wakeLock` that is not an object, or has no `request`', () => {
    // Both shapes are reachable: a polyfill that assigned a boolean, and a partial
    // implementation that exposed the namespace without the method.
    expect(wakeEnvironmentFrom({ wakeLock: true } as unknown as Navigator).request).toBeNull()
    expect(wakeEnvironmentFrom({ wakeLock: null } as unknown as Navigator).request).toBeNull()
    expect(wakeEnvironmentFrom({ wakeLock: {} } as unknown as Navigator).request).toBeNull()
  })

  it('calls `wakeLock.request` ON the lock object, never detached', async () => {
    const lock = {
      request(this: unknown) {
        expect(this).toBe(lock)

        return Promise.resolve({
          release: () => Promise.resolve(),
          addEventListener: () => undefined,
        })
      },
    }

    const env = wakeEnvironmentFrom({ wakeLock: lock } as unknown as Navigator)

    expect(env.request).not.toBeNull()
    const handle = await holdScreenAwake(env, () => undefined)

    expect(handle.outcome).toBe('held')
  })

  it('resolves the fallback locale for a tag with no primary subtag at all', () => {
    expect(resolveLocale([''])).toBe('en')
    expect(resolveLocale(['-'])).toBe('en')
  })
})
