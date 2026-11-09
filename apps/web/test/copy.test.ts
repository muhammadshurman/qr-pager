import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

import { AR, EN, translatorFor } from '../src/copy/index.ts'
import type { CopyKey } from '../src/copy/en.ts'

/**
 * ================================================================================
 * THE COPY CATALOGUE. `§12`: the exact copy is written BEFORE the screen is coded.
 * ================================================================================
 *
 * The specification is `docs/tickets/QRPAGER-16.md §3`, committed before the first
 * implementation commit. These assertions are what stop the code and that document drifting.
 *
 * ⚠ THE KEY-PARITY HALF IS A TYPE, NOT A TEST. `ar.ts` is declared
 * `Record<CopyKey, string>`, so a key added to `en.ts` and not translated does not compile.
 * What is asserted here is the half a type cannot see: an EXTRA key in `ar.ts` (excess
 * property checking does not apply to a non-literal-typed import), an empty value, a value
 * still in English, and a `{placeholder}` nobody substitutes.
 */

const SCREEN_DIR = join(import.meta.dirname, '..', 'src', 'screens')

/** Every `.tsx` under `src/screens`, at any depth. Absolute paths. */
function screenFiles(dir: string): string[] {
  const out: string[] = []

  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name)

    if (entry.isDirectory()) out.push(...screenFiles(full))
    else if (entry.name.endsWith('.tsx')) out.push(full)
  }

  return out.sort()
}

describe('the copy catalogue', () => {
  it('has exactly the same keys in both launch languages, in both directions', () => {
    const en = Object.keys(EN).sort()
    const ar = Object.keys(AR).sort()

    // Exact, not a subset check in one direction: a missing Arabic key is caught by the
    // type, and an EXTRA Arabic key -- a renamed English id leaving its translation behind
    // -- is caught only here.
    expect(ar).toEqual(en)
    /*
     * 62 at Task 9, 127 at Task 10a, 163 at 10b: the staff surface's sign-in, shell, lock,
     * kitchen screens, the add-a-passkey outcome, the two `staff.signedout.*` keys that
     * `§11` QA L-3 added so a person who PRESSED "Sign out" does not read "Screen locked.
     * Nobody is signed in on this screen.", and `staff.devices.creating` — the busy label on
     * the issue-device submit that `§11` SECURITY L-2 required, because a disabled button
     * with no reason reads as a lockout rather than as work in progress.
     *
     * ⚠ 10b's THIRTY-SIX are the BOARD and the PAGER DETAIL — every state in
     * `QRPAGER-26-precoding.md §12` S6/S7 — plus the two `R-224` strings whose whole point
     * is that they DIFFER: `staff.board.throttled.*` says the person need do nothing, and
     * `staff.signin.throttled` says they must wait and try again. Muhammad's ruling is that
     * those are two situations, not one message, so they are two keys and `§12` S1's own
     * stage case asserts each is silent on the other's sentence.
     *
     * ⚠ **STILL 163 AFTER 10b's FIX ROUND, AND THE ARITHMETIC IS WORTH STATING BECAUSE THE
     * NUMBER NOT MOVING IS EXACTLY WHAT A PINNED COUNT CANNOT SEE.** Three keys left and
     * three arrived, so the total is unchanged while the catalogue is not:
     *
     *   OUT  `staff.home.title` · `.body` · `.action` — `StaffHome`'s, deleted with the
     *        screen, and one of them had become FALSE (*"The order board arrives next."* on a
     *        surface whose board had arrived). `§11` QA L-19: nothing read them, so the pin
     *        was counting dead copy.
     *   IN   `staff.shell.throttled.title` · `.body` — `R-224`'s third surface (`§11` QA H-3)
     *        — and `staff.signin.throttled.body`, the sign-in notice's own second sentence,
     *        which was rendering the passkey BUTTON'S LABEL (`§11` QA M-11).
     *   AND  `staff.board.waiting` is renamed `staff.board.waitingLabel`, because its
     *        `{duration}` placeholder never rendered (`§11` QA M-13): the duration is a live
     *        readout, not a string this catalogue can interpolate.
     *
     * The count is pinned rather than omitted because a key SILENTLY DELETED from both files
     * at once is the one change key-parity cannot see — and this round is the case where that
     * guard had to be read rather than trusted.
     *
     * ⚠ **194 AT 10c, AND THE THIRTY-ONE ARE THE KITCHEN DISPLAY** (`/kds`, `QRPAGER-27`): the
     * setup screen and its one refusal, the orders and their five status words, the four
     * states (`empty`, `error`, `offline`, `throttled`) and the two-step removal of the
     * screen's own code. They are `kds.*` rather than `staff.*` because the surface has a
     * different reader — **nobody is signed in**, so no string addresses a known person and
     * none says *you* — and because the word for the pasted credential is *code* throughout,
     * never *token*. `en.ts`'s own 10c header has both rules.
     *
     * The five status words are deliberately present and four of them are UNREACHABLE on that
     * screen: `PagerSessions::board()` selects only `waiting` and `ready`. `StatusBadge` takes
     * a `Record<PagerStatus, string>`, so the generated mirror requires an entry for every
     * status PHP declares — which is `§6.2` doing its job on a screen where the extra entries
     * cannot render, and is cheaper than a partial map that would let a new PHP status reach a
     * kitchen as its raw enum value.
     */
    /*
     * ⚠ **290 AT 10e, AND THE EIGHTY-EIGHT ARE 10d's AND 10e's THREE SCREENS.** Past orders
     * with its four filter controls and its TWO empty states; starting a pager and the sheet;
     * Muhammad's re-issue ruling rendered as a confirmation; and the staff screen with the two
     * refusals `QRPAGER-29 §6` calls the hard half of that ticket.
     *
     * Three groups of keys are worth finding in the catalogue rather than only counting:
     *
     *   **The two history empty states** (`staff.history.empty.*` and `staff.history.none.*`).
     *   They are two because the NEXT ACTION differs — with filters on, widen; with none, start
     *   an order — and `§12` requires an empty state to offer one that works. One state with
     *   one button would be wrong in one of the two cases, and wrong in the worse direction.
     *
     *   **The re-issue copy** (`staff.issue.reissue.*`). `QRPAGER-28 §2` requires the screen to
     *   say *printing a new sheet makes the old code stop working* in plain words, confirmed
     *   and marked not reversible. The word *reprint* appears nowhere in either catalogue,
     *   because a reprint is physically unavailable and naming it would promise it.
     *
     *   **The three member refusals** (`staff.team.denied.*`). None of them says *role*,
     *   *privilege*, *permission* or *senior*, and each says what to DO.
     */
    /*
     * ⚠ **288 AT QRPAGER-38, AND THE TWO THAT LEFT ARE THE ONLY KEYS EVER REMOVED.** The
     * rebuilt sign-in card retired two controls, so it retired their words:
     * `staff.signin.password` ("Use a password instead") was the DISCLOSURE that opened the
     * email and password fields, and those fields are now on the screen from the first
     * paint; `staff.signin.failed` was the one-sentence refusal, replaced by
     * `staff.signin.failedHead` + `staff.signin.failedBody` so the alert can say what
     * happened and what to do in two lines rather than one run-on.
     *
     * They were pruned rather than left in place because a key nothing renders cannot be
     * reviewed on a screen — its Arabic in particular is unseen by anyone, and the next
     * person to need that sentence finds two candidates and no way to tell which is live.
     * The count is the only thing that makes a removal deliberate, which is why it is here
     * and not merely in the diff.
     */
    expect(en.length).toBe(288)
  })

  it('has no empty value in either language', () => {
    for (const [key, value] of Object.entries(EN)) {
      expect(value.trim(), `EN ${key}`).not.toBe('')
    }

    for (const [key, value] of Object.entries(AR)) {
      expect(value.trim(), `AR ${key}`).not.toBe('')
    }
  })

  it('has no Arabic value that is still the English string', () => {
    // ⚠ THE CONTROL AGAINST A PLACEHOLDER TRANSLATION. A copy-paste of the English into
    // `ar.ts` satisfies the type, satisfies the key parity and satisfies the emptiness
    // check, and ships an Arabic launch language that is English.
    for (const key of Object.keys(EN) as CopyKey[]) {
      expect(AR[key], `AR ${key} is still the English string`).not.toBe(EN[key])
    }
  })

  it('writes every Arabic value in Arabic script, so a stray latin paste is caught', () => {
    // Four keys legitimately carry latin: the product mark's own name and the install steps
    // that name Apple's UI. Everything else must be Arabic script.
    const LATIN_ALLOWED: readonly CopyKey[] = [
      'alerts.install.body',
      'alerts.install.step2',
      'a11y.productMark',
    ]
    const ARABIC = /[؀-ۿ]/

    for (const key of Object.keys(EN) as CopyKey[]) {
      if (LATIN_ALLOWED.includes(key)) continue

      expect(ARABIC.test(AR[key]), `AR ${key} carries no Arabic script`).toBe(true)
    }
  })

  it('substitutes every placeholder, and leaves an unsupplied one visible', () => {
    const t = translatorFor('en')

    expect(t('ready.announce', { reference: 'A-42' })).toBe('Your order is ready. Order A-42.')
    expect(t('waiting.degraded.asOf', { time: '13:40' })).toBe('Last confirmed 13:40')

    // ⚠ VISIBLE, NOT BLANK. A silent empty string produces "Order ." on the one screen that
    // has to be read across a room, and nothing fails. The literal brace is caught in a
    // screenshot and by `screens.test.tsx`.
    expect(t('ready.announce')).toBe('Your order is ready. Order {reference}.')
    expect(t('ready.announce', {})).toBe('Your order is ready. Order {reference}.')
  })

  it('translates the placeholders into the Arabic word order rather than concatenating', () => {
    const t = translatorFor('ar')
    const announced = t('ready.announce', { reference: 'A-42' })

    expect(announced).toContain('A-42')
    // The reference is not at the end of the Arabic sentence the way it is in English --
    // which is the whole reason the placeholder is inside the copy rather than appended by
    // the caller.
    expect(announced.endsWith('A-42')).toBe(false)
  })

  it('leaves no customer-visible literal in any screen component', () => {
    /**
     * ⚠ THE RULE `§12` ACTUALLY STATES, ASSERTED AGAINST THE SOURCE. *"Every screen is
     * specified before it is coded: … and the exact copy."* A component that carries its own
     * string has copy that is in no catalogue, in one language, reviewed by nobody -- and it
     * is invisible to the key-parity assertions above because it never reaches them.
     *
     * The scan is for JSX TEXT: a run of letters between `>` and `<`. An attribute is not
     * caught and does not need to be -- `className`, `dir` and `data-*` are not copy -- and
     * the two `aria-label`s that ARE copy are built from `t()` calls, which this would not
     * flag either way.
     */
    /**
     * ⚠ RECURSIVE SINCE TASK 10a, AND THE FLAT VERSION WOULD HAVE MISSED THE WHOLE STAFF
     * SURFACE. `readdirSync(SCREEN_DIR)` saw seven files and no directories; 10a put its
     * screens in `screens/staff/`, which a flat read does not enter -- so this assertion
     * would have gone on passing while covering none of the new code. The defect would have
     * been invisible from the result: a green case that scans nothing looks exactly like a
     * green case that scans everything.
     */
    const files = screenFiles(SCREEN_DIR)

    /*
     * 7 at Task 9, 10 at Task 10a, 12 at 10c. An exact count, so a screen added in a directory
     * this walk does not reach fails here rather than going unscanned — which is the defect
     * this case's own header records from Task 10a, when the flat read saw seven files and
     * entered no directories.
     *
     * 10c's two are `screens/kds/KdsSetup.tsx` and `screens/kds/KdsBoard.tsx`, in a THIRD
     * directory under `src/screens` — so this count moving is also the evidence that the
     * recursive walk reached a directory that did not exist when it was written.
     */
    /*
     * 15 at 10e: `StaffHistory`, `StaffIssue` and `StaffTeam` join `screens/staff/`. The count
     * is exact so a screen added in a directory this walk does not reach fails here rather than
     * going unscanned — which is the defect this case's header records from Task 10a, when the
     * flat read saw seven files and entered no directories.
     */
    expect(files.length).toBe(15)

    for (const file of files) {
      const source = readFileSync(file, 'utf8')
      // Strip comments first: a prose docblock is full of sentences.
      const code = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '')
      const text = [...code.matchAll(/>\s*([A-Za-z][A-Za-z ,.'"!?-]{3,})\s*</g)].map((m) => m[1])

      expect(text, `${file} carries literal JSX text`).toEqual([])
    }
  })
})
