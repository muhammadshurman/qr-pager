import { describe, expect, it } from 'vitest'

import { classifyAppearance, echoCount, leaksIn, type SweepAnswer } from '../isolation.ts'

/**
 * ================================================================================
 * THE SWEEP'S EXEMPTION, PROVEN AGAINST A LEAK IT USED TO LET THROUGH
 * ================================================================================
 *
 * `§11` QA M-3 / SECURITY L-3. The browser sweep's own round cannot prove this: showing that
 * a leak fails the check would mean introducing a real cross-tenant disclosure into the API.
 * The rule is a pure function for exactly that reason, and these cases drive the shapes the
 * old `if (body.includes(id) && index < 7) continue` silently accepted.
 *
 * ⚠ THE FIRST CASE IS THE DEFECT ITSELF, written as a test rather than described: a keyed
 * 404 that carries an id the request never named. Under the old exemption it passed. If
 * `classifyAppearance` ever returns anything but `leak` for it, the sweep is back to
 * measuring nothing for seven of its eleven answers.
 */

const SESSION = '01a199c4-7f3e-7000-8000-00000000s001'
const MEMBERSHIP = '01a199c4-7f3e-7000-8000-00000000m001'
const DEVICE = '01a199c4-7f3e-7000-8000-00000000d001'

const BETA = {
  session_id: SESSION,
  membership_id: MEMBERSHIP,
  device_id: DEVICE,
  name: 'Beta Shawarma',
} as const

/** A real-shaped RFC 9457 problem document, as the API's 404 answers one. */
function problem(instance: string, extra: Record<string, unknown> = {}): string {
  return JSON.stringify({
    type: 'https://qrpager.test/problems/not-found',
    title: 'Not Found',
    status: 404,
    detail: 'No such pager session.',
    instance,
    ...extra,
  })
}

function answer(overrides: Partial<SweepAnswer> = {}): SweepAnswer {
  return {
    name: 'ready',
    status: 404,
    body: problem(`/v1/pager-sessions/${SESSION}/ready`),
    ownId: SESSION,
    ...overrides,
  }
}

describe('the one tolerated appearance', () => {
  it('accepts the caller own path id echoed in instance, and only there', () => {
    expect(classifyAppearance(answer(), 'session_id', SESSION)).toEqual({ kind: 'echo' })
  })

  it('reports nothing at all for an answer that carries no id', () => {
    const clean = answer({ name: 'board', status: 200, body: '{"sessions":[]}', ownId: null })

    expect(classifyAppearance(clean, 'session_id', SESSION)).toEqual({ kind: 'absent' })
    expect(leaksIn([clean], BETA)).toEqual([])
  })
})

describe('the leak the old exemption let through', () => {
  it('refuses an id the request never named, in a keyed answer', () => {
    /*
     * The exact defect: a 404 for beta's SESSION whose body also carries beta's MEMBERSHIP.
     * `ownId` is the session, so the membership has no echo argument behind it — and under
     * the old rule this was skipped because the body "contained an id" and the answer was
     * one of the first seven.
     */
    const leaky = answer({
      body: problem(`/v1/pager-sessions/${SESSION}/ready`, { membership: MEMBERSHIP }),
    })

    const verdict = classifyAppearance(leaky, 'membership_id', MEMBERSHIP)

    expect(verdict.kind).toBe('leak')
    expect(verdict.kind === 'leak' ? verdict.reason : '').toBe(
      'ready (HTTP 404) carried beta’s membership_id, which this request never named'.replace(
        '’',
        "'",
      ),
    )

    // And the whole-sweep helper reports it once, not zero times.
    expect(leaksIn([leaky], BETA)).toHaveLength(1)
  })

  it('refuses the own id when it appears a SECOND time, outside instance', () => {
    const leaky = answer({
      body: problem(`/v1/pager-sessions/${SESSION}/ready`, {
        detail: `No such pager session ${SESSION}.`,
      }),
    })

    const verdict = classifyAppearance(leaky, 'session_id', SESSION)

    expect(verdict.kind).toBe('leak')
    expect(verdict.kind === 'leak' ? verdict.reason : '').toContain('a second time')
  })

  it('refuses the own id echoed in a body that is not a problem document', () => {
    // A 200 that happens to carry the id is not an echo of a refusal -- it is an answer, and
    // the invariant is that alpha's session gets none about beta's rows.
    const leaky = answer({
      name: 'devices',
      status: 200,
      body: `{"devices":["${DEVICE}"]}`,
      ownId: DEVICE,
    })

    const verdict = classifyAppearance(leaky, 'device_id', DEVICE)

    expect(verdict.kind).toBe('leak')
    expect(verdict.kind === 'leak' ? verdict.reason : '').toContain('outside the problem document')
  })

  it('refuses a plain-text body that carries the id', () => {
    const leaky = answer({ body: `Not Found: ${SESSION}` })

    expect(classifyAppearance(leaky, 'session_id', SESSION).kind).toBe('leak')
  })

  it('refuses a JSON array, which has no instance to appeal to', () => {
    const leaky = answer({ body: `["${SESSION}"]` })

    expect(classifyAppearance(leaky, 'session_id', SESSION).kind).toBe('leak')
  })

  it('refuses a problem document whose instance does NOT carry the id', () => {
    // The id is in the document but `instance` names a different path, so the echo argument
    // does not apply to it.
    const leaky = answer({ body: problem('/v1/pager-sessions', { subject: SESSION }) })

    expect(classifyAppearance(leaky, 'session_id', SESSION).kind).toBe('leak')
  })

  it('refuses the restaurant NAME with no exemption whatsoever', () => {
    // A name is never the caller's own input on these paths. There is no echo case for it,
    // which is why `leaksIn` handles it before `classifyAppearance` is consulted.
    const leaky = answer({
      body: problem(`/v1/pager-sessions/${SESSION}/ready`, { tenant: 'Beta Shawarma' }),
    })

    expect(leaksIn([leaky], BETA)).toEqual(['ready named beta ("Beta Shawarma")'])
  })
})

describe('the echo stays countable', () => {
  it('counts exactly one echo per keyed answer, and none for the reads', () => {
    const keyed = [
      answer({ name: 'ready' }),
      answer({
        name: 'member.update',
        body: problem(`/v1/members/${MEMBERSHIP}`),
        ownId: MEMBERSHIP,
      }),
      answer({ name: 'device.destroy', body: problem(`/v1/devices/${DEVICE}`), ownId: DEVICE }),
    ]

    const reads = [answer({ name: 'tenant', status: 200, body: '{"tenant":{}}', ownId: null })]

    // ⚠ EXACT, NOT "at least". The count is what the spec pins against the real stack, so a
    // fourth echo appearing from somewhere is a change somebody has to look at.
    expect(echoCount([...keyed, ...reads], BETA)).toBe(3)
    expect(leaksIn([...keyed, ...reads], BETA)).toEqual([])
  })
})
