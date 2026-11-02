import { Button, Field } from '@qrpager/ui'

import type { Translate } from '../../copy/index.ts'
import type { IssuedSheet } from '../../staff/api.ts'

/**
 * ================================================================================
 * S9 — STARTING A PAGER, AND RE-ISSUING A SHEET. 10d (`QRPAGER-28`).
 * ================================================================================
 *
 * `§4.2`'s *Create pager + print QR*, and Muhammad's ruling `QRPAGER-28 §2` is the whole shape
 * of the second half:
 *
 *   *"A sheet is RE-ISSUED, never reprinted. The QR token is 128-bit CSPRNG stored as a hash
 *   only, so the raw value exists once, at generation, and can never be re-rendered from the
 *   database. Reprint is not a feature being declined — it is physically unavailable, and `§4`
 *   forbids making it available."*
 *
 * Three things that ruling requires of this screen, and each is below:
 *
 *   1. it says so **in plain words, not system vocabulary** — *printing a new sheet makes the
 *      old code stop working*, which is `staff.issue.reissue.body` almost verbatim;
 *   2. the action is **confirmed**, because it is destructive in effect;
 *   3. it is **clearly marked as not reversible** — *you cannot undo this*, in the body rather
 *      than in a tooltip.
 *
 * ================================================================================
 * ⚠ THERE IS NO *PRINT AGAIN* CONTROL, AND ITS ABSENCE IS THE DESIGN
 * ================================================================================
 *
 * The obvious screen has a *Reprint* button beside each order. It cannot exist: the raw token
 * is unrecoverable, so there is nothing to re-render. A button that regenerated the QR from
 * the database would be a button that cannot work, and one that silently re-issued under the
 * word *reprint* would be worse — a staff member would void a customer's working sheet while
 * believing they had printed a copy of it.
 *
 * So the only path is the one named: *Make a new sheet for this order*, confirmed. The word
 * *print* appears exactly once on this screen, on the control that prints the sheet currently
 * on it.
 *
 * ================================================================================
 * THE CODE IS IN COMPONENT STATE AND NOWHERE ELSE
 * ================================================================================
 *
 * `qrPayload` is a credential. It is held in the caller's state until this screen is left and
 * is never stored, logged or put in a URL — `apps/web/test/staff-issue.test.tsx` asserts it
 * reaches no `localStorage` and no `location`. The copy tells the person the same fact
 * (`staff.issue.sheet.once`) so that losing the sheet is a known consequence rather than a
 * surprise.
 */

export type IssuePhase =
  | 'idle'
  | 'working'
  | 'issued'
  | 'confirm-reissue'
  | 'reissuing'
  | 'error'
  | 'offline'
  | 'invalid'
  | 'finished'
  | 'expired'
  | 'gone'

export interface StaffIssueProps {
  readonly t: Translate
  readonly phase: IssuePhase
  readonly reference: string
  readonly onReference: (value: string) => void
  readonly onCreate: () => void
  /** The sheet to print, or null when there is none on screen. */
  readonly sheet: IssuedSheet | null
  readonly onPrint: () => void
  readonly onDone: () => void
  /**
   * The order a re-issue is being considered for, by its reference.
   *
   * A reference rather than an id, because it is what the confirmation SHOWS — and a
   * confirmation that names nothing a person recognises is one they cannot check before
   * pressing.
   */
  readonly reissuing: string | null
  readonly onConfirmReissue: () => void
  readonly onCancelReissue: () => void
  /**
   * Renders the server's SVG.
   *
   * Injected rather than done here, so this component holds no opinion about how markup
   * reaches the DOM and its tests need no `dangerouslySetInnerHTML` of their own. The caller
   * is `StaffApp`, which has the one comment explaining the insertion and its bound.
   */
  readonly renderCode: (svg: string) => React.ReactNode
}

/** The sentence states, each one a real server answer rather than a generic failure. */
const MESSAGE: Readonly<
  Partial<
    Record<
      IssuePhase,
      | 'staff.issue.error.title'
      | 'staff.issue.offline.title'
      | 'staff.issue.reference.invalid'
      | 'staff.issue.reissue.finished'
      | 'staff.issue.reissue.expired'
      | 'staff.issue.reissue.gone'
    >
  >
> = {
  error: 'staff.issue.error.title',
  offline: 'staff.issue.offline.title',
  invalid: 'staff.issue.reference.invalid',
  finished: 'staff.issue.reissue.finished',
  expired: 'staff.issue.reissue.expired',
  gone: 'staff.issue.reissue.gone',
}

export function StaffIssue({
  t,
  phase,
  reference,
  onReference,
  onCreate,
  sheet,
  onPrint,
  onDone,
  reissuing,
  onConfirmReissue,
  onCancelReissue,
  renderCode,
}: StaffIssueProps) {
  /*
   * THE CONFIRMATION COMES FIRST, because while it is up it is the only thing on screen that
   * should be actionable. A confirmation rendered beside a live form is one somebody dismisses
   * by pressing something else.
   */
  if (phase === 'confirm-reissue' || phase === 'reissuing') {
    return (
      <section
        className="qr-staff-form"
        aria-label={t('staff.issue.reissue.title')}
        data-issue="confirm-reissue"
      >
        <h1 className="qr-staff-state__title">{t('staff.issue.reissue.title')}</h1>
        {reissuing !== null && (
          <p className="qr-staff-list__name">
            {t('staff.issue.sheet.order', { reference: reissuing })}
          </p>
        )}
        {/*
          `role="alert"`, because this is the consequence the person is deciding about and it
          is the one thing on the screen worth interrupting for. The body carries *you cannot
          undo this*, which is `§12`'s not-reversible marking rendered as a sentence rather
          than as an icon.
        */}
        <p className="qr-staff-warning" role="alert">
          {t('staff.issue.reissue.body')}
        </p>
        <div className="qr-staff-actions">
          <Button
            variant="danger"
            disabled={phase === 'reissuing'}
            data-issue-action="confirm-reissue"
            onClick={onConfirmReissue}
          >
            {phase === 'reissuing'
              ? t('staff.issue.reissue.working')
              : t('staff.issue.reissue.confirm')}
          </Button>
          <Button variant="quiet" data-issue-action="keep" onClick={onCancelReissue}>
            {t('staff.issue.reissue.keep')}
          </Button>
        </div>
      </section>
    )
  }

  if (phase === 'issued' && sheet !== null) {
    return (
      <section
        className="qr-staff-form"
        aria-label={t('staff.issue.sheet.title')}
        data-issue="issued"
      >
        <h1 className="qr-staff-state__title">{t('staff.issue.sheet.title')}</h1>
        <p className="qr-staff-list__name" data-issue-order={sheet.orderReference}>
          {t('staff.issue.sheet.order', { reference: sheet.orderReference })}
        </p>

        {/*
          ⚠ THE QR IS THE ONLY PLACE THE CODE APPEARS, AND THE PAYLOAD IS NEVER RENDERED AS
          TEXT. The raw token is a credential; a person does not need to read it and a
          screenshot of it is a working pager. What is rendered is the SERVER's SVG of it, and
          `staff-issue.test.tsx` asserts the payload string reaches no text node and no
          attribute at all.
        */}
        <div className="qr-staff-sheet__code" data-issue-code="true">
          {renderCode(sheet.qrSvg)}
        </div>

        <p className="qr-staff-state__description">{t('staff.issue.sheet.instruction')}</p>
        <p className="qr-staff-warning">{t('staff.issue.sheet.once')}</p>

        <div className="qr-staff-actions">
          <Button variant="primary" data-issue-action="print" onClick={onPrint}>
            {t('staff.issue.sheet.print')}
          </Button>
          <Button variant="quiet" data-issue-action="done" onClick={onDone}>
            {t('staff.issue.sheet.done')}
          </Button>
        </div>
      </section>
    )
  }

  const message = MESSAGE[phase]

  return (
    <section aria-label={t('staff.issue.title')} data-issue="idle">
      <h1 className="qr-staff-state__title">{t('staff.issue.title')}</h1>
      <p className="qr-staff-state__description">{t('staff.issue.lede')}</p>

      {message !== undefined && (
        <p className="qr-staff-warning" role="alert" data-issue-message={phase}>
          {t(message)}
        </p>
      )}

      <form
        className="qr-staff-form"
        onSubmit={(event) => {
          // A form, so Enter submits -- which is what somebody who has just typed an order
          // number at a counter will press.
          event.preventDefault()
          onCreate()
        }}
      >
        <Field
          label={t('staff.issue.reference.label')}
          hint={t('staff.issue.reference.hint')}
          value={reference}
          data-issue-reference="true"
          autoComplete="off"
          onChange={(event) => {
            onReference(event.target.value)
          }}
        />
        <div className="qr-staff-actions">
          <Button
            variant="primary"
            type="submit"
            // Empty is not submittable: the request would be a 422 the person can see the
            // cause of, and a refusal they did not cause reads as the screen being broken.
            disabled={phase === 'working' || reference.trim() === ''}
          >
            {phase === 'working' ? t('staff.issue.creating') : t('staff.issue.create')}
          </Button>
        </div>
      </form>
    </section>
  )
}
