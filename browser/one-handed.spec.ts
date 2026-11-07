import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

import { expect, test, type Page, type Route } from '@playwright/test'

import { origin } from './support/e2e.ts'

/**
 * ⚠ READ FROM A GENERATED ARTEFACT, NOT IMPORTED. This spec must iterate exactly the reducer's
 * own states -- *every table is generated or gate-checked* -- and importing
 * `apps/web/src/pager/journey.ts` here fails: `@qrpager/contracts` re-exports
 * `./generated/status.ts` with an explicit `.ts` extension and Playwright's loader answers
 * `request for './generated/status.ts' is from a module not been linked`.
 *
 * `docs/contracts/journey-states.json` is emitted from that union and byte-compared by
 * `apps/web/test/journey.test.ts`, which runs BEFORE this suite in `pnpm gate`. So the subject
 * list still cannot drift, and the staleness check is somewhere the types are real.
 */
type ScreenState = string

const JOURNEY_STATES: readonly ScreenState[] = (
  JSON.parse(
    readFileSync(
      join(import.meta.dirname, '..', 'docs', 'contracts', 'journey-states.json'),
      'utf8',
    ),
  ) as { states: string[] }
).states

/**
 * ================================================================================
 * "ONE-HANDED OPERATION VERIFIED AT 390px" -- `§3.1` ROW 9 (2), AS FOUR MEASUREMENTS
 * ================================================================================
 *
 * The method is `§3.1` row 9 and `QRPAGER-16 §4.1`, written 2026-11-01 BEFORE this task
 * started. Four properties, at **390x844 DPR 3**, on **every screen in `§4.1` in every
 * state**, in **both LTR and RTL**:
 *
 *   (a) NO HORIZONTAL SCROLL          `document.scrollingElement.scrollWidth <= 390`
 *   (b) EVERY JOURNEY-ADVANCING CONTROL IN THE THUMB ZONE   whole bounding box at `y >= 563`
 *   (c) TARGET SIZE AND SEPARATION     every interactive element `>= 44x44`, no two adjacent
 *                                      interactive boxes closer than 8 px -- measured from
 *                                      BOUNDING BOXES, not from CSS, and **without**
 *                                      `R-174`'s WCAG 2.5.8 exception, per that row's own
 *                                      instruction
 *   (d) NO TWO-CONTACT GESTURE         no `gesturestart`/`gesturechange` handler and no
 *                                      `touch-action: none` on a journey element
 *
 * ⚠ THE SUBJECT LIST IS `JOURNEY_STATES`, IMPORTED FROM THE REDUCER. *Every table is
 * generated or gate-checked* -- and a subject list typed into a spec is the same defect one
 * layer down. A state that exists and is not measured here is impossible rather than
 * unlikely, and `journey.test.ts` iterates the same export.
 *
 * ⚠ THE API ANSWERS ARE CONTROLLED AND THE APP IS NOT. Each state is reached by routing the
 * two customer endpoints, then driving the app's OWN UI and its own reducer. This is a
 * GEOMETRY measurement: reaching all 17 states deterministically matters more than the
 * transport, and the transport is what `pager.spec.ts` crosses for real. Said here rather
 * than left for a reviewer to notice.
 */

const VIEWPORT_WIDTH = 390
const VIEWPORT_HEIGHT = 844
/** The bottom third of 844. `§3.1` row 9 states the heuristic rather than implying it. */
const THUMB_ZONE_TOP = 563
const MIN_TARGET = 44
const MIN_SEPARATION = 8

/** Every interactive thing this app renders. Not a guess: each appears in a screen's source. */
const INTERACTIVE =
  'button, a[href], input, select, textarea, summary, [tabindex]:not([tabindex="-1"])'

/**
 * A journey-advancing control is one inside the actions block, plus the order-ready
 * acknowledge.
 *
 * That is not a judgement about which button matters: `.pg-actions` is the element
 * `pager.css` pins to the bottom of the column with `margin-block-start: auto`, so it IS the
 * set of controls the layout promises to put in the thumb zone. `.qr-ready__acknowledge` is
 * the order-ready screen's only control and is floored the same way.
 */
const ADVANCING = '.pg-actions button, .pg-actions a[href], .qr-ready__acknowledge'

const SNAPSHOT: Record<string, unknown> = {
  status: 'waiting',
  order_reference: 'A-42',
  expires_at: '2099-01-01T00:00:00Z',
  as_of: '2026-11-01T12:00:00Z',
  // ⚠ ONE SECOND, NOT SEVEN, AND IT IS A HARNESS VALUE RATHER THAN A PRODUCT ONE. The
  // degraded state needs one failed poll to arrive, and at the real 7 s interval that is 7 s
  // of wall clock per project -- six projects, for a GEOMETRY measurement. The client reads
  // this number from the server, so lowering it exercises the same code path at a shorter
  // cadence; `poll.test.ts` asserts the arithmetic at the real value.
  poll_after_seconds: 1,
}

const TOKEN = 'AbCdEfGhIjKlMnOpQrStUv'

interface Measurement {
  readonly state: ScreenState
  readonly scrollWidth: number
  readonly advancing: { readonly selector: string; readonly top: number; readonly bottom: number }[]
  readonly targets: { readonly label: string; readonly width: number; readonly height: number }[]
  readonly minSeparation: number | null
  readonly gestureHandlers: number
  readonly touchActionNone: number
}

/** Force the permission surface, before any page script runs. */
async function withPermission(
  page: Page,
  shape: 'notAsked' | 'granted' | 'denied' | 'unsupported',
): Promise<void> {
  await page.addInitScript((kind: string) => {
    const scope = globalThis as unknown as Record<string, unknown>

    if (kind === 'unsupported') {
      Reflect.deleteProperty(scope, 'Notification')
      Reflect.deleteProperty(scope, 'PushManager')

      return
    }

    // Named rather than anonymous and empty: `pushCapability()` tests `'PushManager' in
    // window`, so what the value IS does not matter -- only that the name exists. A named
    // class with a field answers the lint rule's objection to an empty one rather than
    // silencing it.
    scope.PushManager = class PushManagerStub {
      readonly stub = true
    }
    /**
     * ⚠ THE WORKER CONTAINER IS STUBBED TOO, AND THAT IS WHAT MAKES THIS A GEOMETRY
     * MEASUREMENT RATHER THAN A TLS ONE.
     *
     * `alerts.granted` timed out at 35 s on WebKit LTR and RTL, every run, while the same
     * state passed on Chromium and Firefox. The cause is not the layout: with permission
     * granted the orchestrator registers the real `/sw.js`, and the harness serves it over a
     * SELF-SIGNED certificate. `ignoreHTTPSErrors` is a Playwright option and a worker script
     * is fetched by the browser's own NETWORK STACK, so it does not reach that fetch --
     * `playwright.config.ts` says so where the journey projects pass Chromium
     * `--ignore-certificate-errors`, a flag WebKit has no equivalent for. So the registration
     * genuinely fails there, and the page could never hold the granted state.
     *
     * ⚠ AND THE FAILURE WAS A PRODUCT DEFECT BEFORE IT WAS A HARNESS ONE. The reconcile
     * awaited `register()` with no `catch`, so a rejection became an unhandled rejection and
     * the screen kept claiming alerts were on. That is fixed in `PagerJourney.tsx` and
     * asserted in `orchestrator.test.tsx`; with the fix, WebKit now correctly renders
     * `alerts.unsupported` -- which is still not the state THIS file is measuring.
     *
     * A registration that resolves and a `subscribe()` that rejects is the smallest stub that
     * leaves the journey where this spec needs it: `reconcileSubscription` catches the
     * rejection and returns `failed`, so there is no request, no state change and no
     * dependence on any engine's certificate handling. The real registration is the SUBJECT
     * of `sw.spec.ts`, where it is driven on the engine that can do it.
     */
    const registration = {
      pushManager: {
        getSubscription: () => Promise.resolve(null),
        subscribe: () => Promise.reject(new Error('one-handed: geometry only, no real push')),
      },
    }

    Object.defineProperty(scope.navigator, 'serviceWorker', {
      configurable: true,
      value: {
        register: () => Promise.resolve(registration),
        getRegistration: () => Promise.resolve(registration),
        ready: Promise.resolve(registration),
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
      },
    })

    scope.Notification = Object.assign(
      function NotificationStub() {
        // A constructor the app never calls: it reads `Notification.permission` and
        // `requestPermission`, both attached below. The body is empty because there is nothing
        // for it to do, which the comment says instead of the rule guessing.
        return undefined
      },
      {
        permission: kind === 'notAsked' ? 'default' : kind,
        requestPermission: () => Promise.resolve(kind === 'notAsked' ? 'default' : kind),
      },
    )
  }, shape)
}

/** Reach one state in the real app, with the two endpoints answered as that state needs. */
async function reach(page: Page, state: ScreenState): Promise<void> {
  // `?? ''` on BOTH halves: `split` is typed to return `(string | undefined)[]` under
  // `noUncheckedIndexedAccess`, and a state id without a dot is not a state this app has --
  // but the types do not know that, and asserting it away with `!` is what the strict config
  // exists to prevent.
  const screen = state.split('.')[0] ?? ''
  const detail = state.split('.')[1] ?? ''

  const answer = (route: Route, status: number, body: unknown) =>
    route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })

  // The resolve, per state.
  await page.route(`${origin()}/v1/pager/session`, async (route) => {
    if (state === 'error.offline') return route.abort()
    if (state === 'error.server') return answer(route, 500, { title: 'no' })
    // A 200 whose body this build cannot read -- the ONLY route to `error.unknown`.
    if (state === 'error.unknown') return answer(route, 200, { nonsense: true })
    if (state === 'landing.invalid') return answer(route, 404, { type: 'x/not-found' })
    if (screen === 'ended') {
      return answer(route, 200, { ...SNAPSHOT, status: detail })
    }
    if (screen === 'ready') return answer(route, 200, { ...SNAPSHOT, status: 'ready' })

    return answer(route, 200, SNAPSHOT)
  })

  // The poll, per state. `waiting.degraded` and `waiting.offline` are properties of the
  // CONNECTION, so they are reached by answering the poll badly rather than by any UI step.
  let polls = 0

  await page.route(`${origin()}/v1/pager/status`, async (route) => {
    polls += 1

    if (state === 'waiting.degraded') {
      return answer(route, 503, { type: 'x/unavailable' })
    }

    if (state === 'waiting.offline') return route.abort()

    return answer(route, 200, { ...SNAPSHOT, status: screen === 'ready' ? 'ready' : 'waiting' })
  })

  if (screen === 'alerts') {
    await withPermission(page, detail as 'notAsked')
  }

  await page.goto(`${origin()}/s#${state === 'landing.invalid' ? 'not-a-token' : TOKEN}`)
  await expect(page.locator('[data-screen]')).toBeVisible()

  /**
   * ⚠ LOCATED BY CLASS, NOT BY COPY, AND THE RTL PROJECTS ARE WHY. Half of these projects run
   * at `locale: 'ar-JO'`, so every label on screen is Arabic -- and
   * `getByRole('button', { name: /enable alerts/i })` matched nothing there: three projects
   * timed out at 30 s per state against a UI that was behaving perfectly.
   *
   * `.pg-actions`'s primary and quiet buttons are the two journey-advancing controls on every
   * framed screen, and `.qr-ready__acknowledge` is the order-ready screen's only one. All
   * three are language-independent by construction, which is what a GEOMETRY measurement
   * needs -- the copy is asserted in `copy.test.ts` and `screens.test.tsx`, where the
   * language is the subject rather than an obstacle.
   */
  if (screen === 'alerts') {
    await page.locator('.pg-actions .qr-button--primary').first().click()
  }

  if (screen === 'waiting') {
    await page.locator('.pg-actions .qr-button--quiet').first().click()
  }

  if (state === 'waiting.degraded') {
    // One failed poll is enough for `degraded`, and the harness interval above makes that
    // about a second.
    await expect(page.locator('[data-screen="waiting"][data-state="degraded"]')).toBeVisible({
      timeout: 30_000,
    })
  }

  if (state === 'waiting.offline') {
    /**
     * ⚠ THE REAL `offline` EVENT, NOT SIX FAILED POLLS. Both routes reach this state and the
     * reducer treats them the same, but the backoff route costs 1 + 2 + 4 + 8 + 16 + 32
     * seconds before the sixth failure -- per project, six projects, for a measurement about
     * geometry. `context.setOffline(true)` fires the engine's own `offline` event, which is
     * the signal a customer walking into a basement actually produces, and it arrives at
     * once. The backoff route is asserted at its real cadence in `journey.test.ts` and in
     * `pager.spec.ts`.
     */
    await page.context().setOffline(true)
    await expect(page.locator('[data-screen="waiting"][data-state="offline"]')).toBeVisible({
      timeout: 30_000,
    })
  }

  if (state === 'ready.acknowledged') {
    await page.locator('.qr-ready__acknowledge').click()
  }

  /**
   * ⚠ POLLED FOR THE PAIR AS ONE STRING, SO A FAILURE NAMES WHAT DID RENDER. This was
   * `toBeVisible()` on a `[data-screen][data-state]` selector, and when `alerts.granted`
   * never appeared on WebKit the message was *"element(s) not found"* -- which is every
   * wrong state at once and names none of them. Three cases failed that way and the log
   * could not say whether the engine had rendered `notAsked`, `unsupported` or nothing;
   * diagnosing it needed a second run with a probe bolted on. `§4a`'s own argument about a
   * generic 500 that names nothing, in a browser.
   */
  await expect
    .poll(
      () =>
        page
          .locator('[data-screen]')
          .first()
          .evaluate(
            (el) =>
              `${el.getAttribute('data-screen') ?? '?'}.${el.getAttribute('data-state') ?? '?'}`,
          ),
      { timeout: 30_000, message: `reach(${state}) never rendered that state` },
    )
    .toBe(state)

  /**
   * ⚠ THIS WAS `expect(polls).toBeGreaterThanOrEqual(0)` -- TRUE FOR EVERY POSSIBLE VALUE,
   * including a poller that never ran. A vacuous assertion is worse than none: it occupies
   * the place where the real one would have gone, and the suite reports it as a check.
   *
   * ⚠ AND THE FIRST REPLACEMENT WAS WRONG, WHICH IS WHY THE PARTITION BELOW IS NARROW. It
   * asserted `polls > 0` for every state with a live session -- the valid landing, all four
   * alerts states, all three waiting states, both ready states -- on the reasoning that
   * polling starts the moment a session resolves. It does start; it has not FIRED yet. 54
   * cases went red with `polls=0`, and the measurement is in `§4a`: `landing.valid`,
   * `alerts.*`, `waiting.healthy`, `waiting.offline` and `ready.*` all reach their state
   * before the first interval elapses. So that was an assertion about the machine's speed
   * wearing the costume of one about the journey.
   *
   * What is left is what is a PROPERTY of the journey rather than of the clock:
   *
   *   polls === 0   there is no live session to poll, and no amount of waiting creates one:
   *                 an invalid token, a resolve that answered a TERMINAL status, and the
   *                 three error states, where the resolve itself failed so polling never
   *                 began. A regression that polled a completed session fails here.
   *   polls > 0     the state is reachable ONLY THROUGH a poll answer: `waiting.degraded` is
   *                 what a 503 from the polling route produces, and the wait above does not
   *                 return until that screen is on. One state, and it is guaranteed rather
   *                 than likely.
   *
   * The other nine are asserted at their real cadence where the clock is the subject --
   * `journey.test.ts` drives the interval, the jitter and the backoff against a fake timer,
   * and `pager.spec.ts` drives a real poll discovering `ready` over HTTP. Counting them here
   * would be a race, and a test that sometimes passes is the thing this file is careful about.
   */
  const pollsAreImpossible = state === 'landing.invalid' || screen === 'ended' || screen === 'error'

  if (pollsAreImpossible) {
    expect(polls, `${state} polled a session that cannot be polled`).toBe(0)
  }

  if (state === 'waiting.degraded') {
    expect(polls > 0, `${state}: polls=${String(polls)}`).toBe(true)
  }
}

async function measure(page: Page, state: ScreenState): Promise<Measurement> {
  return page.evaluate(
    ([interactive, advancing, stateName]) => {
      const root = document.scrollingElement ?? document.documentElement
      const boxes = [...document.querySelectorAll(interactive)]
        .map((element) => ({ element, box: element.getBoundingClientRect() }))
        .filter((entry) => entry.box.width > 0 && entry.box.height > 0)

      // (c) separation: the smallest gap between any two interactive boxes that overlap on
      // the other axis -- which is what "adjacent" means. Boxes that do not line up are not
      // adjacent and a gap between them is not a mis-tap risk.
      let minSeparation: number | null = null

      for (let i = 0; i < boxes.length; i += 1) {
        for (let j = i + 1; j < boxes.length; j += 1) {
          const a = boxes[i]!.box
          const b = boxes[j]!.box
          const overlapX = a.left < b.right && b.left < a.right
          const overlapY = a.top < b.bottom && b.top < a.bottom

          if (overlapX && !overlapY) {
            const gap = a.top < b.top ? b.top - a.bottom : a.top - b.bottom
            minSeparation = minSeparation === null ? gap : Math.min(minSeparation, gap)
          } else if (overlapY && !overlapX) {
            const gap = a.left < b.left ? b.left - a.right : a.left - b.right
            minSeparation = minSeparation === null ? gap : Math.min(minSeparation, gap)
          }
        }
      }

      // (d) no multi-touch requirement, asserted against the live document.
      const gestureHandlers = [...document.querySelectorAll('*')].filter((element) => {
        const withHandlers = element as unknown as Record<string, unknown>

        return (
          typeof withHandlers.ongesturestart === 'function' ||
          typeof withHandlers.ongesturechange === 'function'
        )
      }).length

      const touchActionNone = [...document.querySelectorAll('*')].filter(
        (element) => getComputedStyle(element).touchAction === 'none',
      ).length

      return {
        state: stateName,
        scrollWidth: root.scrollWidth,
        advancing: [...document.querySelectorAll(advancing)].map((element) => {
          const box = element.getBoundingClientRect()

          return {
            selector: `${element.tagName.toLowerCase()}.${element.className.split(' ')[0] ?? 'none'}`,
            top: Math.round(box.top),
            bottom: Math.round(box.bottom),
          }
        }),
        targets: boxes.map((entry) => ({
          label: `${entry.element.tagName.toLowerCase()}.${entry.element.className.split(' ')[0] ?? 'none'}`,
          width: Math.round(entry.box.width * 100) / 100,
          height: Math.round(entry.box.height * 100) / 100,
        })),
        minSeparation: minSeparation === null ? null : Math.round(minSeparation * 100) / 100,
        gestureHandlers,
        touchActionNone,
      }
    },
    [INTERACTIVE, ADVANCING, state] as const,
  )
}

const collected: Measurement[] = []

test.describe('one-handed operation at 390x844, DPR 3', () => {
  test('measures the viewport it claims to, in this project', async ({ page }) => {
    // The whole measurement is meaningless at another size, so the size is asserted rather
    // than configured and trusted.
    await page.goto(`${origin()}/s`)

    const seen = await page.evaluate(() => ({
      width: window.innerWidth,
      height: window.innerHeight,
      dpr: window.devicePixelRatio,
      dir: document.documentElement.dir || document.querySelector('[dir]')?.getAttribute('dir'),
    }))

    expect(seen.width).toBe(VIEWPORT_WIDTH)
    expect(seen.height).toBe(VIEWPORT_HEIGHT)
    expect(seen.dpr).toBe(3)
  })

  for (const state of JOURNEY_STATES) {
    test(`${state}: no horizontal scroll, thumb zone, 44px, 8px, no gesture`, async ({ page }) => {
      await reach(page, state)

      const m = await measure(page, state)
      collected.push(m)

      // Put the network back before anything else runs in this project: a context left
      // offline would make the NEXT state unreachable for a reason that is not its own.
      if (state === 'waiting.offline') await page.context().setOffline(false)

      // (a) EXACT, and the commonest way a phone layout fails one-handed use.
      expect(m.scrollWidth, `${state}: horizontal scroll`).toBeLessThanOrEqual(VIEWPORT_WIDTH)

      // (b) every journey-advancing control WHOLLY in the bottom third.
      for (const control of m.advancing) {
        expect(control.top, `${state}: ${control.selector} top`).toBeGreaterThanOrEqual(
          THUMB_ZONE_TOP,
        )
      }

      // (c) ⚠ WITHOUT `R-174`'s WCAG 2.5.8 EXCEPTION, which is that row's own instruction:
      // *"clause (c) here is measured WITHOUT the exception until `R-174` is ruled."* So a
      // text-inside-a-sentence link would FAIL here rather than being excused, and this app
      // renders none for that reason.
      for (const target of m.targets) {
        expect(target.width, `${state}: ${target.label} width`).toBeGreaterThanOrEqual(MIN_TARGET)
        expect(target.height, `${state}: ${target.label} height`).toBeGreaterThanOrEqual(MIN_TARGET)
      }

      if (m.minSeparation !== null) {
        expect(m.minSeparation, `${state}: adjacent separation`).toBeGreaterThanOrEqual(
          MIN_SEPARATION,
        )
      }

      // (d)
      expect(m.gestureHandlers, `${state}: multi-touch handler`).toBe(0)
      expect(m.touchActionNone, `${state}: touch-action none`).toBe(0)
    })
  }
})

/**
 * The generated table.
 *
 * ⚠ ONE FILE PER PROJECT, each written and byte-compared by the project that measured it. The
 * alternative -- one file accumulated across six projects -- depends on the order Playwright
 * happens to run them in, and an artefact whose correctness depends on scheduling is not an
 * artefact. `§5` of the ticket points at the set.
 */
/**
 * ⚠ `{ browserName }` RATHER THAN `{}`. Playwright requires this hook's first argument to be a
 * destructuring pattern, and `@typescript-eslint`'s `no-empty-pattern` refuses an empty one --
 * so a real fixture is destructured and used. `browserName` is worker-scoped and is worth
 * naming in the artefact anyway: an engine is part of a measurement's provenance.
 */
test.afterAll(({ browserName }, testInfo) => {
  if (collected.length !== JOURNEY_STATES.length) {
    // A partial run must not rewrite the artefact into something smaller: that would turn a
    // failed suite into a quietly narrowed table.
    return
  }

  const project = testInfo.project.name
  const rows = collected.map((m) => {
    const lowest = m.advancing.length === 0 ? null : Math.min(...m.advancing.map((a) => a.top))
    const smallest =
      m.targets.length === 0 ? null : Math.min(...m.targets.map((t) => Math.min(t.width, t.height)))

    return `| \`${m.state}\` | ${String(m.scrollWidth)} | ${
      lowest === null ? '—' : String(lowest)
    } | ${smallest === null ? '—' : smallest.toFixed(2)} | ${
      m.minSeparation === null ? '—' : m.minSeparation.toFixed(2)
    } | ${String(m.gestureHandlers)} | ${String(m.touchActionNone)} |`
  })

  const markdown = [
    `# QRPAGER-16 — one-handed measurement, \`${project}\``,
    '',
    '> GENERATED by `browser/one-handed.spec.ts`. Do not edit: the spec byte-compares this',
    '> file and fails when it differs, which is what makes the table evidence rather than',
    `> prose. Regenerate with \`tools/browsers/bin/e2e --project=${project}\`.`,
    '',
    `Engine **${browserName}**, viewport **${String(VIEWPORT_WIDTH)}×${String(VIEWPORT_HEIGHT)} at DPR ${String(PHONE_DPR_FOR_DOC)}**.`,
    `Thumb zone **y ≥ ${String(THUMB_ZONE_TOP)}**, target floor **${String(MIN_TARGET)} px**,`,
    `separation floor **${String(MIN_SEPARATION)} px**.`,
    '',
    'Subjects are `JOURNEY_STATES` from `apps/web/src/pager/journey.ts` — the same export the',
    "reducer's own test iterates, so a state that exists cannot be unmeasured.",
    '',
    '| state | scrollWidth | lowest advancing control top | smallest target side | min adjacent gap | gesture handlers | `touch-action: none` |',
    '| --- | --- | --- | --- | --- | --- | --- |',
    ...rows,
    '',
  ].join('\n')

  const dir = join(import.meta.dirname, '..', 'docs', 'tickets')
  mkdirSync(dir, { recursive: true })
  // The project name already carries the `one-handed-` prefix, so it is used whole:
  // `one-handed-chromium-ltr` -> `QRPAGER-16-one-handed-chromium-ltr.md`.
  const path = join(dir, `QRPAGER-16-${project}.md`)

  if (!existsSync(path)) {
    writeFileSync(path, markdown)

    return
  }

  expect(readFileSync(path, 'utf8'), `${path} is stale -- regenerate and commit it`).toBe(markdown)
})

/** DPR is a project setting; named here so the generated document can state it. */
const PHONE_DPR_FOR_DOC = 3
