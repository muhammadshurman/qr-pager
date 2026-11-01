import type { Translate } from '../copy/index.ts'
import type { EndedState } from '../pager/journey.ts'
import { Frame, type BrandSlot } from './Frame.tsx'

/**
 * `§3.5` of the ticket. `§4.1`: *"Expired or completed, with a path back to the restaurant
 * page."*
 *
 * ⚠ NOTHING RUNS ON THIS SCREEN. Polling has stopped, the token is cleared from both of its
 * homes, and the push subscription is removed on `completed` and `cancelled` -- the
 * orchestrator does all three before this renders. There is no timer, no retry and no
 * request: all three are over, and a product that keeps polling a completed session is
 * spending a customer's battery to learn nothing.
 *
 * ⚠ AND `§4.1`'s *"path back to the restaurant page"* IS `SR-4`. No response on this surface
 * carries a tenant landing URL and Task 11 owns that page, so `homeHref` renders a link ONLY
 * when one is supplied. A link to a guessed URL would be a dead end wearing the costume of
 * a path, which is the opposite of what that clause asks for.
 *
 * ⚠ ITS THREE STATES ARE THE PAGER ENUM'S THREE TERMINAL STATUSES, AND THAT IS WHY THIS
 * SCREEN NAMES NEITHER THEM NOR ITS COPY KEYS. `EndedState` is imported, derived from
 * `JOURNEY_STATES`; and the title/body keys are built by TEMPLATE rather than looked up in a
 * map, so the compiler -- checking `` `ended.${EndedState}.title` `` against `CopyKey` --
 * is what requires copy for every one of them. The three sibling screens keep the ordinary
 * map, which is the more readable form and is exhaustive the same way; this one cannot,
 * because a map with `completed:`, `cancelled:` and `expired:` for keys is a second,
 * hand-written declaration of the status enum (`§6.2`) whatever its values are, and
 * `StatusMirrorContractTest` is right to refuse it. Measured: that guard went red on this
 * file and on `journey.ts` at `fcdfd8f`, and green at the commit that removed both.
 */

export interface SessionEndedProps {
  readonly state: EndedState
  readonly t: Translate
  readonly reference: string | null
  readonly brand?: BrandSlot | undefined
}

export function SessionEnded({ state, t, reference, brand }: SessionEndedProps) {
  const home = brand?.homeHref

  return (
    <Frame
      screen="ended"
      state={state}
      t={t}
      brand={brand}
      actions={
        home === undefined ? undefined : (
          // `rel="noopener noreferrer"`: the same posture `packages/ui`'s templates already
          // assert on every external link. A tenant-supplied URL is not ours.
          <a className="qr-button qr-button--secondary" href={home} rel="noopener noreferrer">
            <span className="qr-button__label">
              {t('ended.home', { restaurant: brand?.name ?? '' })}
            </span>
          </a>
        )
      }
    >
      <h1 className="pg-title">{t(`ended.${state}.title`)}</h1>
      {reference !== null && (
        <p className="pg-note" dir="ltr">
          {`${t('landing.ref.label')} ${reference}`}
        </p>
      )}
      <p className="pg-note">{t(`ended.${state}.body`)}</p>
      {/* The way forward with no link available: a physical action, named. `§4.1`'s *"no dead
          end"* is satisfied by an instruction the customer can act on, not by a button. */}
      <p className="pg-note">{t('ended.rescan')}</p>
    </Frame>
  )
}
