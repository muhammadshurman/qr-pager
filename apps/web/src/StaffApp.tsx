'use client'

import { Button, LockScreen, StaffShell, StaffState, ThemeProvider, type Locale } from '@qrpager/ui'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'

import { translatorFor } from './copy/index.ts'
import { StaffDevices } from './screens/staff/StaffDevices.tsx'
import { StaffHome } from './screens/staff/StaffHome.tsx'
import { StaffSignIn, roleKey, type SignInStage } from './screens/staff/StaffSignIn.tsx'
import {
  fetchDevices,
  fetchIdentity,
  fetchTenant,
  issueDevice,
  revokeDevice,
  selectTenant,
  signInWithPassword,
  signOut,
  completeTwoFactor,
  type IssuedDevice,
  type StaffDevice,
  type StaffIdentity,
  type StaffTenant,
} from './staff/api.ts'
import { browserLocale } from './pager/locale.ts'
import { registerPasskey, signInWithPasskey, passkeysAvailable } from './staff/passkey.ts'
import { watchForIdle } from './staff/session.ts'

/**
 * ================================================================================
 * THE STAFF ORCHESTRATOR. One client component, four views, and a lock over all of them.
 * ================================================================================
 *
 * `apps/web/app/staff/*` are statically prerendered documents that each render this with a
 * `view`. **Nothing protected is server-rendered at any URL**, which is why sign-in is a
 * VIEW here rather than a route to redirect to: a redirect means a protected document
 * existed and was navigated away from, and the shape of that document is observable. An
 * unauthenticated request to `/staff/devices` gets the sign-in view at that URL, with no
 * flash of chrome and nothing to screenshot.
 *
 * ================================================================================
 * THE THREE PIECES OF TENANT DATA, AND WHERE THEY LIVE
 * ================================================================================
 *
 * `identity` (the person's name and their memberships), `tenant` (the restaurant's name) and
 * `devices`. All three are REACT STATE AND NOTHING ELSE: no `localStorage`, no
 * `sessionStorage`, no cookie this app writes, no URL. `§12`'s lock clause is about a shared
 * device, and the strongest form of "the lock reveals no tenant datum" is that there is
 * nowhere for a datum to survive the lock — `clearTenantData()` below is one function and it
 * is the whole of it.
 *
 * Task 9's own `R-210`/`SEC M-3` is the precedent read in the other direction: there, a value
 * written back into stored data was the defect, and moving a cache NAME while leaving the
 * mechanism was the fix that did not fix it. So this app stores nothing at all.
 *
 * ================================================================================
 * THE LOCK, IN ORDER, BECAUSE THE ORDER IS THE SECURITY PROPERTY
 * ================================================================================
 *
 *   1. `POST /v1/auth/logout` — the SERVER session is destroyed first;
 *   2. every byte of tenant data is dropped from memory;
 *   3. the overlay renders.
 *
 * A lock that did 3 and not 1 would leave a working cookie in a browser a passer-by can open
 * devtools on. Step 1 is awaited but its FAILURE DOES NOT STOP STEPS 2 AND 3: if the network
 * is down, the right answer is still to cover the screen and forget the data. The session
 * then expires on its own, which is the fallback rather than the mechanism.
 */

export type StaffView = 'home' | 'devices'

export interface StaffAppProps {
  readonly apiBase: string
  readonly view: StaffView
  /** Injected in tests. The real app reads the BROWSER's own language list. */
  readonly locale?: Locale | undefined
  /** Injected in tests, so the lock does not need fifteen real minutes to fire. */
  readonly idleSeconds?: number
  /** Injected in tests. The real app navigates. */
  readonly onNavigate?: (view: StaffView) => void
}

type Phase = 'loading' | 'signed-out' | 'ready' | 'locked' | 'error' | 'offline'

export function StaffApp({ apiBase, view, locale, idleSeconds, onNavigate }: StaffAppProps) {
  /*
   * ⚠ THE LANGUAGE COMES FROM THE BROWSER, AND THE FIRST VERSION OF THIS HARD-CODED `'en'`.
   *
   * It defaulted the prop to `'en'` and nothing ever overrode it, so the staff surface was
   * English-only -- `§12` names Arabic a launch language and requires an RTL-safe layout, and
   * a surface that never renders RTL satisfies neither however carefully its stylesheet uses
   * logical properties. **Found by the browser gate**: the three `staff-*-rtl` projects set
   * `locale: 'ar-JO'` on the context and measured `dir` as `ltr`.
   *
   * `browserLocale()` is Task 9's resolver and is reused rather than re-implemented: it
   * matches on the PRIMARY SUBTAG, which is the half a `===` against `LOCALES` gets wrong --
   * real devices send `ar-JO` and `en-GB`, never a bare `ar`.
   *
   * ⚠ DETECTED IN AN EFFECT, NOT AT FIRST RENDER, which is `PagerJourney`'s own pattern and
   * for its reason: these routes are statically prerendered, so the server render has no
   * `navigator` and a direct read would be a hydration mismatch. The first paint is the
   * fallback language and the effect corrects it before anything but the loading state is on
   * screen.
   */
  const [detected, setDetected] = useState<Locale | null>(locale ?? null)

  useEffect(() => {
    if (locale !== undefined) return
    if (typeof navigator === 'undefined') return

    setDetected(browserLocale(navigator))
  }, [locale])

  const resolved: Locale = detected ?? 'en'
  const t = useMemo(() => translatorFor(resolved), [resolved])

  const [phase, setPhase] = useState<Phase>('loading')
  const [signInStage, setSignInStage] = useState<SignInStage>('idle')
  const [identity, setIdentity] = useState<StaffIdentity | null>(null)
  const [tenant, setTenant] = useState<StaffTenant | null>(null)
  const [devices, setDevices] = useState<readonly StaffDevice[] | null>(null)
  const [devicesPhase, setDevicesPhase] = useState<
    'loading' | 'ready' | 'error' | 'offline' | 'permission-denied'
  >('loading')
  const [issued, setIssued] = useState<IssuedDevice | null>(null)
  const [passkeyNotice, setPasskeyNotice] = useState<'added' | 'failed' | null>(null)

  /** One function, and it is the whole of "the shell holds no tenant datum". */
  const clearTenantData = useCallback((): void => {
    setIdentity(null)
    setTenant(null)
    setDevices(null)
    setIssued(null)
    setPasskeyNotice(null)
    setDevicesPhase('loading')
  }, [])

  const load = useCallback(async (): Promise<void> => {
    const who = await fetchIdentity(apiBase)

    if (who.kind === 'unauthorised') {
      clearTenantData()
      setPhase('signed-out')

      return
    }

    if (who.kind === 'unreachable') {
      setPhase('offline')

      return
    }

    if (who.kind !== 'ok') {
      setPhase('error')

      return
    }

    setIdentity(who.value)

    // More than one membership and none selected: the person has to choose, and until they
    // do, every staff route answers 401 because the resolver refuses to pick. That is
    // correct server behaviour and this is the screen for it.
    if (who.value.activeTenant === null && who.value.memberships.length !== 1) {
      setPhase('signed-out')
      setSignInStage('choose')

      return
    }

    const restaurant = await fetchTenant(apiBase)

    if (restaurant.kind === 'unauthorised') {
      clearTenantData()
      setPhase('signed-out')

      return
    }

    if (restaurant.kind === 'unreachable') {
      setPhase('offline')

      return
    }

    if (restaurant.kind !== 'ok') {
      setPhase('error')

      return
    }

    setTenant(restaurant.value)
    setPhase('ready')
    setSignInStage('idle')
  }, [apiBase, clearTenantData])

  useEffect(() => {
    void load()
  }, [load])

  /* ------------------------------------------------------------------------ the lock */

  // A ref, so the idle watch's callback always sees the current phase without the watch
  // being torn down and rebuilt on every phase change -- a rebuilt watch resets its own
  // deadline, which would mean a screen that never locks while anything re-renders.
  const phaseRef = useRef(phase)

  phaseRef.current = phase

  const lock = useCallback(async (): Promise<void> => {
    // 1. THE SERVER FIRST. Awaited, and its failure is deliberately not checked: if the
    //    network is down the right answer is still to forget everything and cover up.
    await signOut(apiBase)
    // 2. then the memory, 3. then the overlay.
    clearTenantData()
    setPhase('locked')
    setSignInStage('idle')
  }, [apiBase, clearTenantData])

  useEffect(() => {
    if (typeof window === 'undefined') return
    if (phase !== 'ready') return

    const watch = watchForIdle(window, {
      ...(idleSeconds === undefined ? {} : { seconds: idleSeconds }),
      onIdle: () => {
        // Only a signed-in shell locks. A sign-in screen left alone is already locked, and
        // locking it again would sign out a session that does not exist.
        if (phaseRef.current === 'ready') void lock()
      },
    })

    return () => {
      watch.stop()
    }
  }, [phase, idleSeconds, lock])

  /* --------------------------------------------------------------------- signing in */

  const afterCredential = useCallback(async (): Promise<void> => {
    setSignInStage('idle')
    setPhase('loading')
    await load()
  }, [load])

  const onPasskey = useCallback(async (): Promise<void> => {
    setSignInStage('working')

    const outcome = await signInWithPasskey(apiBase)

    if (outcome.kind === 'ok') {
      await afterCredential()

      return
    }

    setSignInStage(outcome.kind === 'unsupported' ? 'unsupported' : 'failed')
  }, [apiBase, afterCredential])

  const onPassword = useCallback(
    async (email: string, password: string): Promise<void> => {
      setSignInStage('working')

      const result = await signInWithPassword(apiBase, email, password)

      if (result.kind === 'ok') {
        await afterCredential()

        return
      }

      // ⚠ `two-factor` IS A 200, NOT A 4xx, AND THE FIRST VERSION OF THIS MAPPED 403 TO IT
      // -- which made the code field a state no event could produce. `signInWithPassword`
      // reads `two_factor_required` out of the body for that reason; the mapping is in one
      // place and the screen just follows it.
      if (result.kind === 'two-factor') {
        setSignInStage('two-factor')

        return
      }

      if (result.kind === 'unreachable') {
        setSignInStage('offline')

        return
      }

      // Everything else is one message. Separating a wrong password from an unknown address
      // is the oracle `§4 › Security`'s single-message refusal rule exists to close, and the
      // server already answers alike -- a client that split them would re-open it from this
      // side.
      setSignInStage('failed')
    },
    [apiBase, afterCredential],
  )

  const onTwoFactor = useCallback(
    async (code: string): Promise<void> => {
      const result = await completeTwoFactor(apiBase, code)

      if (result.kind === 'ok') {
        await afterCredential()

        return
      }

      setSignInStage(result.kind === 'unreachable' ? 'offline' : 'failed')
    },
    [apiBase, afterCredential],
  )

  const onChoose = useCallback(
    async (tenantId: string): Promise<void> => {
      const result = await selectTenant(apiBase, tenantId)

      if (result.kind !== 'ok') {
        setSignInStage('failed')

        return
      }

      await afterCredential()
    },
    [apiBase, afterCredential],
  )

  /* ------------------------------------------------------------------ kitchen screens */

  const loadDevices = useCallback(async (): Promise<void> => {
    setDevicesPhase('loading')

    const result = await fetchDevices(apiBase)

    if (result.kind === 'ok') {
      setDevices(result.value)
      setDevicesPhase('ready')

      return
    }

    if (result.kind === 'forbidden') {
      setDevicesPhase('permission-denied')

      return
    }

    if (result.kind === 'unauthorised') {
      clearTenantData()
      setPhase('signed-out')

      return
    }

    setDevicesPhase(result.kind === 'unreachable' ? 'offline' : 'error')
  }, [apiBase, clearTenantData])

  useEffect(() => {
    if (phase !== 'ready' || view !== 'devices') return
    void loadDevices()
  }, [phase, view, loadDevices])

  const onIssue = useCallback(
    async (name: string): Promise<void> => {
      const result = await issueDevice(apiBase, name)

      if (result.kind !== 'ok') {
        setDevicesPhase(result.kind === 'forbidden' ? 'permission-denied' : 'error')

        return
      }

      setIssued(result.value)
      await loadDevices()
    },
    [apiBase, loadDevices],
  )

  const onRemove = useCallback(
    async (id: string): Promise<void> => {
      const result = await revokeDevice(apiBase, id)

      if (result.kind !== 'ok' && result.kind !== 'notFound') {
        setDevicesPhase(result.kind === 'forbidden' ? 'permission-denied' : 'error')

        return
      }

      // A 404 is treated as success and that is deliberate: it means the screen is already
      // gone, which is what the person asked for. Reporting an error for a state they wanted
      // would be system vocabulary dressed as a failure.
      await loadDevices()
    },
    [apiBase, loadDevices],
  )

  const onAddPasskey = useCallback(async (): Promise<void> => {
    setPasskeyNotice(null)

    const outcome = await registerPasskey(apiBase, 'this device')

    // ⚠ THE OUTCOME IS SHOWN, AND THE FIRST VERSION THREW IT AWAY. `registerPasskey()`
    // returns a discriminated result precisely so a screen can say what happened; a call
    // that ignores it leaves the person tapping a button that does nothing observable.
    //
    // `cancelled` shows NOTHING, deliberately: the person dismissed the system prompt
    // themselves, and telling them their own action did not work is noise.
    if (outcome.kind === 'ok') {
      setPasskeyNotice('added')

      return
    }

    if (outcome.kind !== 'cancelled') setPasskeyNotice('failed')
  }, [apiBase])

  /* ----------------------------------------------------------------------- rendering */

  const formatDate = useCallback(
    (iso: string): string => {
      const when = new Date(iso)

      if (Number.isNaN(when.getTime())) return iso

      // The restaurant's own timezone, which is why `GET /v1/tenant` carries it: a kitchen
      // screen showing UTC is showing a time nobody in the building is living in.
      return new Intl.DateTimeFormat(resolved, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: tenant?.timezone ?? 'UTC',
      }).format(when)
    },
    [resolved, tenant],
  )

  /** The loading / error / offline view, used with and without the chrome. */
  const unresolved = (): React.ReactNode => {
    if (phase === 'loading') {
      return <StaffState state="loading" loadingLabel={t('staff.shell.loading')} />
    }

    if (phase === 'offline') {
      return (
        <StaffState
          state="offline"
          title={t('staff.shell.offline.title')}
          description={t('staff.shell.offline.body')}
          action={{ label: t('staff.shell.error.retry'), onAction: () => void load() }}
        />
      )
    }

    return (
      <StaffState
        state="error"
        title={t('staff.shell.error.title')}
        description={t('staff.shell.error.body')}
        action={{ label: t('staff.shell.error.retry'), onAction: () => void load() }}
      />
    )
  }

  const body = (): React.ReactNode => {
    if (phase !== 'ready') return unresolved()

    if (view === 'devices') {
      return (
        <StaffDevices
          t={t}
          state={devicesPhase}
          devices={devices ?? []}
          issued={issued}
          onRetry={() => void loadDevices()}
          onIssue={(name) => void onIssue(name)}
          onDismissIssued={() => {
            setIssued(null)
          }}
          onRemove={(id) => void onRemove(id)}
          formatDate={formatDate}
        />
      )
    }

    return (
      <StaffHome
        t={t}
        onDevices={() => onNavigate?.('devices')}
        onAddPasskey={passkeysAvailable() ? () => void onAddPasskey() : undefined}
        passkeyNotice={passkeyNotice}
      />
    )
  }

  if (phase === 'locked') {
    return (
      <ThemeProvider locale={resolved} as="div">
        <LockScreen
          title={t('staff.lock.title')}
          description={t('staff.lock.body')}
          signInLabel={t('staff.lock.signIn')}
          onSignIn={() => {
            setPhase('signed-out')
            setSignInStage('idle')
          }}
        />
      </ThemeProvider>
    )
  }

  if (phase === 'signed-out') {
    return (
      <ThemeProvider locale={resolved} as="div">
        <StaffSignIn
          t={t}
          stage={signInStage}
          onPasskey={() => void onPasskey()}
          onPassword={(email, password) => void onPassword(email, password)}
          onTwoFactor={(code) => void onTwoFactor(code)}
          memberships={identity?.memberships ?? []}
          restaurantNames={{}}
          onChoose={(tenantId) => void onChoose(tenantId)}
        />
      </ThemeProvider>
    )
  }

  /*
   * ⚠ NO CHROME UNTIL THE RESTAURANT IS KNOWN, AND THIS WAS A DEFECT CAUGHT IN THE BUILD
   * OUTPUT RATHER THAN BY A TEST.
   *
   * The first version of this component rendered the shell for every phase but `locked` and
   * `signed-out`, with `unresolved()` inside it -- so the prerendered `/staff` document
   * carried `qr-staff__restaurant`, `Signed in as ` with an empty name, and a `Sign out`
   * button, for a caller who had presented no credential at all. Measured on the built HTML:
   * one occurrence of each in `.next/server/app/staff.html`.
   *
   * Two separate failures in one render. **`§12`**: *"Signed in as "* with the name missing is
   * a sentence with a hole in it, which is the defect `interpolate()` leaves a visible
   * `{name}` to prevent. And **the invariant's browser half**: a document that ships staff
   * chrome to an unauthenticated caller is a protected-looking surface at a public URL, and
   * the flash of it on every load is exactly what an authenticated shell should never do.
   *
   * So the chrome is rendered only when `phase === 'ready'`, which is the only phase in which
   * `tenant` and `identity` are both known. `apps/web/test/staff-build-surface.test.ts`
   * asserts the absence against the BUILT HTML of every staff route, derived from the build.
   */
  if (phase !== 'ready' || tenant === null || identity === null) {
    return (
      <ThemeProvider locale={resolved} as="div">
        <div className="qr-staff">
          <main className="qr-staff__main">{unresolved()}</main>
        </div>
      </ThemeProvider>
    )
  }

  const role = identity.memberships.find((m) => m.tenantId === identity.activeTenant)?.role ?? ''

  return (
    <ThemeProvider locale={resolved} as="div">
      <StaffShell
        restaurant={tenant.name}
        person={identity.name}
        roleWord={t(roleKey(role))}
        signedInLabel={t('staff.shell.signedIn', { name: identity.name })}
        signOutLabel={t('staff.shell.signOut')}
        onSignOut={() => void lock()}
        nav={
          /*
           * ⚠ NAVIGATION, AND ITS ABSENCE WAS A REAL HOLE RATHER THAN AN OMISSION OF POLISH.
           * `QRPAGER-25 §12` S2's content inventory names it, and the first version passed
           * `nav={null}`: a person who reached `/staff/devices` had no way back to `/staff`
           * except the browser's back button, which on a wall-mounted kitchen tablet in
           * kiosk mode is not reachable at all.
           *
           * Two entries, because there are two staff routes. `aria-current="page"` rather
           * than colour alone, so which one you are on is in the accessible tree and not
           * only in the paint -- `§12`'s *visible focus ring* clause's sibling.
           */
          <>
            <Button
              variant={view === 'home' ? 'secondary' : 'quiet'}
              onClick={() => onNavigate?.('home')}
              aria-current={view === 'home' ? 'page' : undefined}
              data-nav="home"
            >
              {t('staff.nav.today')}
            </Button>
            <Button
              variant={view === 'devices' ? 'secondary' : 'quiet'}
              onClick={() => onNavigate?.('devices')}
              aria-current={view === 'devices' ? 'page' : undefined}
              data-nav="devices"
            >
              {t('staff.nav.devices')}
            </Button>
          </>
        }
      >
        {body()}
      </StaffShell>
    </ThemeProvider>
  )
}
