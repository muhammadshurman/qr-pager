/**
 * ================================================================================
 * WHAT COUNTS AS A LEAK IN THE CROSS-TENANT SWEEP
 * ================================================================================
 *
 * `browser/staff.spec.ts` drives every mounted staff route across the tenant boundary with a
 * real session cookie and then asks, of every answer: *does any of beta's identifiers appear
 * in it?* This module owns the one subtlety in that question, and it owns it as a pure
 * function so the rule itself can be TESTED rather than only exercised.
 *
 * ⚠ IT EXISTS BECAUSE THE EXEMPTION USED TO BE VACUOUS — `§11` QA M-3 / SECURITY L-3. The
 * sweep read:
 *
 *     if (result.body.includes(id) && results.indexOf(result) < 7) continue
 *
 * — *"if this is one of the seven keyed attempts and its body contains the id, skip the
 * check"*. The condition it meant to express was **the caller's own path id is echoed back in
 * the problem document's `instance`, and that is the caller's own input rather than a
 * disclosure**. What it actually expressed was: for seven of the eleven results, the leak
 * assertion is skipped *exactly when it would have failed*. A response to
 * `POST /v1/pager-sessions/<beta session>/ready` that also carried beta's `membership_id`,
 * `device_id` or any other identifier passed the sweep silently — and those are the
 * disclosures the invariant is about.
 *
 * So the tolerated appearance is narrowed to what the reasoning actually justifies:
 *
 *   1. the id must be the one THIS request put in its own path (`ownId`), and
 *   2. the body must be a problem document whose `instance` carries it, and
 *   3. the id must appear NOWHERE else in that document.
 *
 * Anything else is a leak, including a body that is not a problem document at all and a
 * second occurrence outside `instance`.
 *
 * `support/test/isolation.test.ts` proves each clause against a fabricated leaky answer,
 * which is the half the browser round cannot give cheaply: reproducing a real cross-tenant
 * disclosure would mean introducing one.
 */

/** One answer from the sweep. */
export interface SweepAnswer {
  /** The attempt's name, for the failure message. */
  readonly name: string
  readonly status: number
  readonly body: string
  /**
   * The identifier THIS request put in its own URL path, or `null` for the reads that take
   * no id. The old exemption had no equivalent, which is precisely why it could not tell an
   * echo from a disclosure.
   */
  readonly ownId: string | null
}

export type Appearance =
  /** The id is not in the body at all. */
  | { readonly kind: 'absent' }
  /** The id is the caller's own path id and appears only in `instance`. */
  | { readonly kind: 'echo' }
  /** Anything else. `reason` is the assertion message. */
  | { readonly kind: 'leak'; readonly reason: string }

/**
 * Is beta's `id` (named `what`) tolerable in this answer?
 *
 * Pure, total, and deliberately strict: every path returns, and the only non-leak appearance
 * is the narrow echo described above.
 */
export function classifyAppearance(answer: SweepAnswer, what: string, id: string): Appearance {
  if (!answer.body.includes(id)) return { kind: 'absent' }

  const where = `${answer.name} (HTTP ${String(answer.status)})`

  if (answer.ownId !== id) {
    return {
      kind: 'leak',
      reason: `${where} carried beta's ${what}, which this request never named`,
    }
  }

  let document: unknown

  try {
    document = JSON.parse(answer.body)
  } catch {
    return {
      kind: 'leak',
      reason: `${where} echoed beta's ${what} in a body that is not a problem document`,
    }
  }

  if (typeof document !== 'object' || document === null || Array.isArray(document)) {
    return {
      kind: 'leak',
      reason: `${where} echoed beta's ${what} in a body that is not a problem document`,
    }
  }

  const fields = document as Record<string, unknown>
  const instance = fields.instance

  if (typeof instance !== 'string' || !instance.includes(id)) {
    return {
      kind: 'leak',
      reason: `${where} carried beta's ${what} outside the problem document's instance`,
    }
  }

  // The same document with `instance` blanked. Any remaining occurrence is an appearance the
  // echo argument does not cover -- in `detail`, in a validation message, in an extension
  // member somebody adds later.
  const rest = JSON.stringify({ ...fields, instance: '' })

  if (rest.includes(id)) {
    return {
      kind: 'leak',
      reason: `${where} carried beta's ${what} a second time, outside instance`,
    }
  }

  return { kind: 'echo' }
}

/**
 * Every leak in the whole sweep, as assertion messages.
 *
 * `beta.name` is checked too and has no echo exemption at all: a restaurant's NAME is never
 * the caller's own input on these paths, so any appearance of it is a disclosure.
 */
export function leaksIn(
  answers: readonly SweepAnswer[],
  beta: Readonly<Record<string, string>>,
): readonly string[] {
  const reasons: string[] = []

  for (const answer of answers) {
    for (const [what, id] of Object.entries(beta)) {
      if (what === 'name') {
        if (answer.body.includes(id)) {
          reasons.push(`${answer.name} named beta ("${id}")`)
        }

        continue
      }

      const appearance = classifyAppearance(answer, what, id)

      if (appearance.kind === 'leak') reasons.push(appearance.reason)
    }
  }

  return reasons
}

/** How many answers echoed their own path id. Pinned by the spec, so the echo stays rare. */
export function echoCount(
  answers: readonly SweepAnswer[],
  beta: Readonly<Record<string, string>>,
): number {
  let echoes = 0

  for (const answer of answers) {
    for (const [what, id] of Object.entries(beta)) {
      if (what === 'name') continue
      if (classifyAppearance(answer, what, id).kind === 'echo') echoes += 1
    }
  }

  return echoes
}
