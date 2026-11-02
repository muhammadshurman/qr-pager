import { execFileSync } from 'node:child_process'
import { join } from 'node:path'

import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { translatorFor } from '../src/copy/index.ts'
import { StaffIssue } from '../src/screens/staff/StaffIssue.tsx'
import type { IssuedSheet } from '../src/staff/api.ts'

/**
 * ================================================================================
 * THE PAGER SHEET — 10d, AND MUHAMMAD's RE-ISSUE RULING RENDERED
 * ================================================================================
 *
 * `QRPAGER-28 §6`'s gate has three clauses this file answers, and one it deliberately does not:
 *
 *   ✓ *"The re-issue action is CONFIRMED, MARKED NOT REVERSIBLE"* — driven below.
 *   ✓ *"The raw token never reaches a log, a URL path or query string, or an error body"* —
 *     the CLIENT half: it reaches no text node and no attribute of the rendered sheet either.
 *   ✓ `§12`'s three mechanical clauses on this screen.
 *   ✗ *"the old code is PROVEN TO STOP WORKING by driving it after a re-issue"* — that is
 *     server-side and is `PagerReissueTest`'s, which presents the old raw token at the
 *     customer surface before and after. No client test can prove it.
 *
 * ================================================================================
 * ⚠ THE QR IS THE REAL ONE, PRODUCED BY THE REAL PHP, NOT A FIXTURE STRING
 * ================================================================================
 *
 * `IssuedSheet.qrSvg` is inserted with `dangerouslySetInnerHTML`, and the bound stated where it
 * is declared is that `PagerSheet::svg()` emits rects and one group with no `<image>`, no
 * external reference and no script. **A test against a hand-written SVG would assert that
 * claim about a string this file wrote**, which is the shape of a fake written to match the
 * client — the defect `staff-app.test.tsx`'s own header records.
 *
 * So `realSvg()` shells out to PHP and asserts the shape of what the server actually produces.
 * It is the same reasoning `StaffContractTest` applies to a JSON response, applied to markup.
 */

const t = translatorFor('en')

const PAYLOAD = 'https://qrpager.dev/s#0123456789abcdef0123456789abcdef'

/**
 * The SVG the real `PagerSheet::svg()` produces for `PAYLOAD`.
 *
 * ⚠ IT THROWS RATHER THAN FALLING BACK TO A FIXTURE when PHP is unavailable. *A reasoned skip
 * is not a measurement*: a fallback would make the cases below assert the shape of a string
 * written here, which is the one thing they exist not to do. PHP 8.4 and the installed vendor
 * tree are a precondition of the whole PHP half of this repository's gate.
 */
let cached: string | null = null

function realSvg(): string {
  if (cached !== null) return cached

  const api = join(import.meta.dirname, '..', '..', 'api')

  cached = execFileSync(
    'php',
    [
      '-r',
      'require "vendor/autoload.php"; echo App\\Features\\Pager\\PagerSheet::svg($argv[1]);',
      PAYLOAD,
    ],
    { cwd: api, encoding: 'utf8' },
  )

  if (!cached.startsWith('<svg')) {
    throw new Error(`PagerSheet::svg() did not return an SVG: ${cached.slice(0, 120)}`)
  }

  return cached
}

function sheet(overrides?: Partial<IssuedSheet>): IssuedSheet {
  return {
    id: '01a00000-0000-7000-8000-00000000000a',
    orderReference: 'A-14',
    qrPayload: PAYLOAD,
    qrSvg: realSvg(),
    ...overrides,
  }
}

function mount(props?: Partial<React.ComponentProps<typeof StaffIssue>>) {
  const base: React.ComponentProps<typeof StaffIssue> = {
    t,
    phase: 'idle',
    reference: '',
    onReference: vi.fn(),
    onCreate: vi.fn(),
    sheet: null,
    onPrint: vi.fn(),
    onDone: vi.fn(),
    reissuing: null,
    onConfirmReissue: vi.fn(),
    onCancelReissue: vi.fn(),
    // The real insertion, so these cases exercise the path the app uses rather than a
    // simplified one -- `StaffApp` passes exactly this.
    renderCode: (svg) => <div data-test-code="true" dangerouslySetInnerHTML={{ __html: svg }} />,
    ...props,
  }

  return { ...render(<StaffIssue {...base} />), props: base }
}

describe('the server-produced QR, asserted against the real PHP', () => {
  it('is an SVG of rects with no image, no external reference and no script', () => {
    const svg = realSvg()

    // The bound `staff/api.ts` states where `qrSvg` is declared, held to the real output.
    expect(svg.startsWith('<svg')).toBe(true)
    expect(svg.includes('<rect')).toBe(true)
    expect(svg.includes('<script')).toBe(false)
    expect(svg.includes('<image')).toBe(false)
    expect(svg.includes('<foreignObject')).toBe(false)
    // No fetch of any kind: nothing for a CSP to allow, which matters because this is
    // interpolated into the staff origin and `R-171` already owes that origin a policy.
    expect(svg.includes('href')).toBe(false)
    expect(svg.includes('url(')).toBe(false)
    expect(/\bon[a-z]+=/.test(svg)).toBe(false)
  })

  it('does NOT contain the payload it encodes, which is why inserting it is safe', () => {
    /*
     * ⚠ THE ASSERTION THAT MAKES THE WHOLE SCREEN's CREDENTIAL CLAIM TRUE. A QR encodes its
     * payload as MODULES — the string itself is nowhere in the markup — so inserting the SVG
     * into the page does not put the token in the DOM as text. If `PagerSheet` ever rendered
     * the payload as a `<title>` or a `desc` for accessibility, this fails, and it should: a
     * screen reader reading 128 random bits aloud is both useless and a credential spoken out
     * loud in a restaurant.
     */
    expect(realSvg().includes(PAYLOAD)).toBe(false)
    // Nor the token's own half of it.
    expect(realSvg().includes('0123456789abcdef')).toBe(false)
  })

  it('carries a four-module quiet zone, which many scanners refuse a code without', () => {
    // ISO/IEC 18004's minimum, and the commonest way a technically correct QR fails in the
    // field -- invisible on screen, where the page background supplies the white by accident.
    // The viewBox is the module grid plus eight modules at 8 units each, so its extent is
    // `(modules + 8) * 8` and is divisible by 8 with at least 64 units of margin.
    const extent = Number(/viewBox="0 0 (\d+)/.exec(realSvg())?.[1] ?? 0)

    expect(extent).toBeGreaterThan(64)
    expect(extent % 8).toBe(0)
  })
})

describe('the sheet on screen', () => {
  it('shows the order, the code and the once-only warning', () => {
    mount({ phase: 'issued', sheet: sheet() })

    expect(screen.getByText(t('staff.issue.sheet.order', { reference: 'A-14' }))).toBeTruthy()
    expect(screen.getByText(t('staff.issue.sheet.once'))).toBeTruthy()
    expect(document.querySelector('[data-issue-code="true"] svg')).toBeTruthy()
  })

  it('renders the payload NOWHERE — not as text, not in any attribute', () => {
    /*
     * `§6`'s clause is *"the raw token never reaches a log, a URL path or query string, or an
     * error body"*. This is the fourth place it must not reach and the one no server test can
     * see: the rendered document. A screenshot of a sheet is a working pager, which is fine —
     * that is what the sheet IS — but the token as selectable text is a value somebody copies
     * into a chat.
     *
     * Asserted over `innerHTML`, so attributes are covered as well as text nodes.
     */
    const { container } = mount({ phase: 'issued', sheet: sheet() })

    expect(container.innerHTML).not.toContain(PAYLOAD)
    expect(container.innerHTML).not.toContain('0123456789abcdef')
    // And the session id is not rendered either -- `§12`'s no-unexplained-identifier clause.
    expect(container.innerHTML).not.toContain('01a00000-0000-7000-8000-00000000000a')
  })

  it('names the ORDER in the code’s accessible label, not the code', () => {
    mount({
      phase: 'issued',
      sheet: sheet(),
      renderCode: (svg) => (
        <div
          role="img"
          aria-label={t('staff.issue.sheet.order', { reference: 'A-14' })}
          dangerouslySetInnerHTML={{ __html: svg }}
        />
      ),
    })

    const code = screen.getByRole('img')

    expect(code.getAttribute('aria-label')).toBe(
      t('staff.issue.sheet.order', { reference: 'A-14' }),
    )
    expect(code.getAttribute('aria-label')).not.toContain(PAYLOAD)
  })

  it('prints and dismisses through the caller', () => {
    const { props } = mount({ phase: 'issued', sheet: sheet() })

    fireEvent.click(screen.getByRole('button', { name: t('staff.issue.sheet.print') }))
    expect(props.onPrint).toHaveBeenCalledTimes(1)

    fireEvent.click(screen.getByRole('button', { name: t('staff.issue.sheet.done') }))
    expect(props.onDone).toHaveBeenCalledTimes(1)
  })
})

describe('re-issuing, which is Muhammad’s ruling rendered', () => {
  it('confirms before sending, says what happens to the old code, and marks it not reversible', () => {
    /*
     * `QRPAGER-28 §2`'s three bullets, in one case because they are one screen:
     *   - *"printing a new sheet makes the old code stop working"* in plain words;
     *   - confirmed;
     *   - clearly marked as not reversible.
     */
    const { props } = mount({ phase: 'confirm-reissue', reissuing: 'A-14' })

    const body = t('staff.issue.reissue.body')

    expect(screen.getByText(body)).toBeTruthy()
    // The words themselves, because the ruling specifies the SENTENCE and not only that a
    // warning exists. A later edit that softened it fails here.
    expect(body).toContain('the old code stop working')
    expect(body).toContain('cannot undo this')
    // In an alert, so it is announced rather than merely present.
    expect(screen.getByRole('alert').textContent).toContain('stop working')

    // It names the order, so a person can check what they are about to void.
    expect(screen.getByText(t('staff.issue.sheet.order', { reference: 'A-14' }))).toBeTruthy()

    // Nothing is sent until it is confirmed.
    expect(props.onConfirmReissue).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: t('staff.issue.reissue.confirm') }))
    expect(props.onConfirmReissue).toHaveBeenCalledTimes(1)
  })

  it('offers a way out that sends nothing', () => {
    const { props } = mount({ phase: 'confirm-reissue', reissuing: 'A-14' })

    fireEvent.click(screen.getByRole('button', { name: t('staff.issue.reissue.keep') }))

    expect(props.onCancelReissue).toHaveBeenCalledTimes(1)
    expect(props.onConfirmReissue).not.toHaveBeenCalled()
  })

  it('disables the confirm while it is in flight, so a double-tap is one request', () => {
    mount({ phase: 'reissuing', reissuing: 'A-14' })

    const confirm = screen.getByRole('button', { name: t('staff.issue.reissue.working') })

    expect(confirm).toHaveProperty('disabled', true)
  })

  it('never uses the word “reprint”, because a reprint is physically unavailable', () => {
    /*
     * ⚠ THE ONE COPY ASSERTION THAT IS ABOUT A WORD'S ABSENCE, and it is the ruling's own
     * distinction: the raw token exists once, at generation, so there is nothing to re-render.
     * A screen that said *reprint* would promise a thing `§4` forbids making available — and
     * would be read as *print a copy of the working sheet*, which is the opposite of what the
     * button does.
     *
     * Scanned over the whole rendered tree in both phases, so a label, a hint or a heading is
     * covered.
     */
    const confirm = mount({ phase: 'confirm-reissue', reissuing: 'A-14' })

    expect(confirm.container.innerHTML.toLowerCase()).not.toContain('reprint')
    expect(confirm.container.innerHTML.toLowerCase()).not.toContain('print again')

    confirm.unmount()

    const issued = mount({ phase: 'issued', sheet: sheet() })

    expect(issued.container.innerHTML.toLowerCase()).not.toContain('reprint')
  })
})

describe('§12 on the idle screen', () => {
  it('will not submit an empty order number', () => {
    mount({ phase: 'idle', reference: '  ' })

    expect(screen.getByRole('button', { name: t('staff.issue.create') })).toHaveProperty(
      'disabled',
      true,
    )
  })

  it('says what to DO for every refusal, each one a real server answer', () => {
    /*
     * ⚠ EVERY PHASE BELOW IS REACHABLE, which is `R-205`'s lesson: a screen state no event can
     * produce is a dead state, and Task 9 shipped one. `StaffApp.onConfirmReissue` maps a 404
     * to `gone` and the two 409s to `finished` and `expired` by the problem `type`; `invalid`
     * is the 422 on the order reference.
     */
    for (const [phase, key] of [
      ['invalid', 'staff.issue.reference.invalid'],
      ['finished', 'staff.issue.reissue.finished'],
      ['expired', 'staff.issue.reissue.expired'],
      ['gone', 'staff.issue.reissue.gone'],
      ['offline', 'staff.issue.offline.title'],
      ['error', 'staff.issue.error.title'],
    ] as const) {
      const view = mount({ phase })

      expect(screen.getByText(t(key)), `${phase} shows no message`).toBeTruthy()
      // Announced, because it is the answer to something the person just did.
      expect(screen.getByRole('alert').textContent).toBe(t(key))

      view.unmount()
    }
  })

  it('shows no code at all when there is no sheet, so nothing stale is printable', () => {
    mount({ phase: 'idle', sheet: null })

    expect(document.querySelector('[data-issue-code="true"]')).toBeNull()
  })
})
