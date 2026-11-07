import { Button } from '@qrpager/ui'

import type { Translate } from '../copy/index.ts'
import type { AlertsState } from '../pager/journey.ts'
import { Frame, type BrandSlot } from './Frame.tsx'

/**
 * `§3.2` of the ticket. `§4.1`: *"One-sentence value statement, permission button, 'why we
 * ask', **iOS Home-Screen install path as a first-class flow**"*.
 *
 * ⚠ THE PERMISSION BUTTON IS THE ONLY PATH TO `requestPermission()` IN THIS APP. Apple
 * requires the request to be *"in response to direct user interaction"* (WebKit's own
 * announcement, `§1.2` of the ticket file), and a request made from an effect is silently
 * refused on iOS -- which costs the customer the alert and tells them nothing.
 *
 * ⚠ ITS STATE UNION IS IMPORTED, NOT DECLARED. `journey.ts` derives all six per-screen unions
 * from `JOURNEY_STATES` with a template-literal `infer`, so this screen's `state` prop cannot
 * fall out of step with the journey that drives it. The copy map below stays -- it is
 * exhaustive because the compiler requires a key for every member of the imported union,
 * which is the same derivation, and none of these state names collides with a `pager_status`
 * value. `SessionEnded`'s three do, which is why that one screen has no map.
 */

export type { AlertsState }

export interface EnableAlertsProps {
  readonly state: AlertsState
  readonly t: Translate
  /** `installGuidance()`'s verdict. Orthogonal to `state`: an iOS tab can be in any of them. */
  readonly install: 'required' | 'installed' | 'not-applicable'
  readonly onAsk: () => void
  readonly onContinue: () => void
  readonly brand?: BrandSlot | undefined
}

export function EnableAlerts({ state, t, install, onAsk, onContinue, brand }: EnableAlertsProps) {
  /**
   * ⚠ RENDERED ON ALL FOUR STATES, AND IT WAS NOT. This was shown only on `notAsked` and
   * `granted` -- and `unsupported` IS THE STATE AN iOS TAB LANDS IN.
   *
   * Measured reasoning: Apple exposes `Notification` and `PushManager` only to a web app
   * launched from the HOME SCREEN (MDN's compat note on `api/PushManager.json`:
   * *"Notifications are supported in web apps saved to the home screen"*). So an iPhone
   * reading this page in a Safari tab has no push path AT ALL, reports `unsupported`, and
   * was the one customer shown no install instructions -- while `§4 › Realtime` calls that
   * guidance *"a required product path, not a nice-to-have"* and the install is the only
   * thing that would fix their state.
   *
   * The gate is `install === 'required'`, which is already the right question: it is true
   * only on the iOS family and only when not standalone. So showing it on every state costs
   * nothing anywhere else.
   */
  const installFlow = install === 'required' ? <InstallFlow t={t} /> : null

  if (state === 'denied' || state === 'unsupported') {
    const prefix = state === 'denied' ? 'alerts.denied' : 'alerts.unsupported'

    return (
      <Frame
        screen="alerts"
        state={state}
        t={t}
        brand={brand}
        actions={
          <Button variant="primary" onClick={onContinue}>
            {t('alerts.continue')}
          </Button>
        }
      >
        {/* `role="alert"` is deliberately NOT here. Neither of these is an interruption: the
            customer has just acted and is looking at the screen, and an assertive
            announcement is reserved for the order being ready (`a11y.tsx`: *"a product that
            announces everything assertively has announced nothing"*). */}
        <h1 className="pg-title">{t(`${prefix}.title` as 'alerts.denied.title')}</h1>
        <p className="pg-note">{t(`${prefix}.body` as 'alerts.denied.body')}</p>
        {installFlow}
        {/* NO RETRY, and that is structural rather than an omission. The browser will not
            ask again after a denial, so a button offering to try is a lie -- which is why
            the kit's `StateView` gives `permission-denied` no retry either. */}
      </Frame>
    )
  }

  if (state === 'granted') {
    return (
      <Frame
        screen="alerts"
        state="granted"
        t={t}
        brand={brand}
        actions={
          <Button variant="primary" onClick={onContinue}>
            {t('alerts.continue')}
          </Button>
        }
      >
        <h1 className="pg-title">{t('alerts.granted')}</h1>
        {installFlow}
      </Frame>
    )
  }

  return (
    <Frame
      screen="alerts"
      state="notAsked"
      t={t}
      brand={brand}
      actions={
        <>
          <Button variant="primary" onClick={onAsk}>
            {t('alerts.cta')}
          </Button>
          <Button variant="quiet" onClick={onContinue}>
            {t('alerts.notNow')}
          </Button>
        </>
      }
    >
      <p className="pg-lede">{t('alerts.lede')}</p>
      {installFlow}
      <details className="pg-disclosure">
        <summary className="pg-disclosure__summary">{t('alerts.why.summary')}</summary>
        <p className="pg-disclosure__body">{t('alerts.why.body')}</p>
      </details>
    </Frame>
  )
}

/**
 * The install path, as its own flow.
 *
 * ⚠ IT SELF-LABELS, which is QRPAGER-24's inherited pattern. Its §3 says *"a push received in
 * Safari WITHOUT a Home Screen install does not satisfy it"*, and the harness stamps every
 * log line with the display mode so an operator cannot misread a transcript. The screen's
 * equivalent is `alerts.install.tabNotice`: the customer is told, in copy they can read, that
 * alerts cannot reach them yet.
 */
function InstallFlow({ t }: { readonly t: Translate }) {
  return (
    <section className="pg-install" aria-labelledby="pg-install-title">
      <h2 className="pg-install__title" id="pg-install-title">
        {t('alerts.install.title')}
      </h2>
      <p className="pg-note">{t('alerts.install.body')}</p>
      <ol className="pg-install__steps">
        <li>{t('alerts.install.step1')}</li>
        <li>{t('alerts.install.step2')}</li>
        <li>{t('alerts.install.step3')}</li>
      </ol>
      <p className="pg-note">{t('alerts.install.tabNotice')}</p>
    </section>
  )
}
