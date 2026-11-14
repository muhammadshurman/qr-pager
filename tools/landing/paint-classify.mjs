/*
 * ================================================================================
 * THE CLASSIFIER — what counts as a finding, in one pure module — QRPAGER-49A
 * ================================================================================
 *
 * `browser/theme-paint.spec.ts` collects; this module JUDGES. It exists as its own file for one
 * reason: it is the code that decides what a finding is, so if it mis-sorts, every number the
 * report prints is wrong — and nothing downstream can tell. Muhammad's ruling of 2026-11-14
 * (blocker 2): *"it is the artefact that DECIDES what counts as a finding … Unit tests and a
 * coverage scope, both."* It has neither a DOM nor a filesystem, so a test can feed it any record
 * it likes and assert the answer.
 *
 * ================================================================================
 * ⚠⚠ COMPUTED-STYLE SEMANTICS, NOT SOURCE-CSS SEMANTICS — THE ERROR THIS FILE CORRECTS
 * ================================================================================
 *
 * Phase 1's declared non-themed set had two members, `transparent` and `currentcolor`, and
 * Muhammad's ruling is that it was written against the wrong language: *"The engine resolves
 * currentcolor to an rgb() triple before returning computed style, so that member is
 * unreachable by this instrument — not merely unexercised."* It was worse than unreachable, and
 * the same mistake sat in `transparent`: the engine never returns the KEYWORD `transparent`
 * either, it returns `rgba(0, 0, 0, 0)`, and Phase 1's collector hid that by rewriting every
 * zero-alpha value to the string `transparent` BEFORE the report saw it. A browser-side shim made
 * the world match the set, which is the set bounding nothing.
 *
 * So the set is keyed on what an engine actually serialises, the shim is gone (the collector now
 * records the raw string and this module reads it), and `currentcolor` is not a member — if the
 * keyword ever DOES reach this module it is reported loudly as an unevaluable value, which is the
 * correct answer to *"something the instrument was never built to see has appeared"*.
 *
 * THE RECORD this module reads is what `browser/theme-paint.spec.ts` writes: one JSON document
 * per template per width, whose `samples` are
 *
 *     { el, path, shape, pseudo, property, named, value, colour, box: {w, h}, text, facts }
 *
 * `colour` is ONE colour token cut out of the engine's computed `value`; `facts` are the raw
 * numbers and keywords the paint verdict below needs and nothing else. The collector decides
 * nothing — that is this file's job and the split is the point.
 */

/*
 * ------------------------------------------------------------------------------------------
 * THE DECLARED NON-THEMED SET
 * ------------------------------------------------------------------------------------------
 *
 * ONE MEMBER, and each member has to pass a test: it must be a value an engine can actually
 * return, and it must be something no restaurant could have chosen as a colour.
 *
 * ⚠ IT DOES NOT GROW TO FIT A FINDING. *"Adding a member is a ruling I make, not an exemption
 * you write."* Phase 1's 66 findings were all `#000000` on `html`; the root cause was fixed in
 * the stylesheet and `#000000` was NOT admitted here.
 *
 * ⚠ AND IT IS STRICTER THAN `any zero-alpha colour`, deliberately. `rgba(1, 2, 3, 0)` paints
 * nothing, but it is a literal somebody WROTE, and a set that quietly absorbed it would let an
 * invisible literal through while a visible one reddened the report. The member is the engine's
 * serialisation of the keyword `transparent` — fully transparent BLACK — and any other colour
 * at zero alpha is reported as what it is.
 */
export const NON_THEMED = Object.freeze({
  'rgba(0, 0, 0, 0)': Object.freeze({
    reason:
      'the engine’s serialisation of the keyword `transparent`: nothing is painted, so there is ' +
      'no pixel for a theme to reach. Keyed on the computed form because that is the only form an ' +
      'engine returns — a computed style never contains the keyword.',
  }),
})

/**
 * The properties whose INITIAL value is `currentcolor`, so that an author who sets none of them
 * gets the element's own `color` in every one.
 *
 * ⚠ WHY THE REPORT COLLAPSES THEM. Phase 1 printed one root defect — `html` keeping the user
 * agent's black because the stylesheet set colour on `body` only — as SIXTY-SIX rows: eleven
 * properties, six renders. They are one fact seen eleven times, and Muhammad's reporter ruling
 * is that *"one root defect inflating to 66 rows buries the second real defect when it
 * arrives."* A derived property is reported as a CONSEQUENCE of its source `color`, never as a
 * finding of its own.
 *
 * ⚠ THE TEST FOR "DERIVED" IS EQUALITY WITH THE ELEMENT'S OWN `color`, AND IT IS CONSERVATIVE IN
 * THE RIGHT DIRECTION. A computed style cannot say whether a border colour was inherited from
 * `currentcolor` or written to the same value, so a property is collapsed only when it is in
 * this list AND its value equals that element's own `color`. If the two later diverge — the
 * root fixed, one border still black — the border stops collapsing and is reported on its own,
 * which is exactly when it should be.
 */
export const CURRENTCOLOR_DERIVED = Object.freeze([
  'border-top-color',
  'border-right-color',
  'border-bottom-color',
  'border-left-color',
  'outline-color',
  'text-decoration-color',
  'caret-color',
  'column-rule-color',
  'text-emphasis-color',
  '-webkit-text-fill-color',
  '-webkit-text-stroke-color',
])

const NUMBER = /^[+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?$/

/** A number, or a percentage resolved against `percentOf`; null when it is neither. */
function number(token, percentOf) {
  if (token.endsWith('%')) {
    const body = token.slice(0, -1)

    return NUMBER.test(body) && percentOf !== null ? (Number(body) * percentOf) / 100 : null
  }

  return NUMBER.test(token) ? Number(token) : null
}

const clamp = (value, low, high) => Math.min(high, Math.max(low, value))
const channel = (value) => clamp(Math.round(value), 0, 255)

/** Hue in degrees from `120`, `120deg`, `0.5turn`, `1.5708rad` or `100grad`; null otherwise. */
function hue(token) {
  const found = /^([+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?)(deg|grad|rad|turn)?$/.exec(token)

  if (found === null) return null

  const value = Number(found[1])
  const scale = { deg: 1, grad: 0.9, rad: 180 / Math.PI, turn: 360 }[found[2] ?? 'deg']

  return (((value * scale) % 360) + 360) % 360
}

/** CSS Color 4's HSL -> sRGB, in 0..255 floats. */
function hslToRgb(h, s, l) {
  const k = (n) => (n + h / 30) % 12
  const a = s * Math.min(l, 1 - l)
  const f = (n) => l - a * Math.max(-1, Math.min(k(n) - 3, Math.min(9 - k(n), 1)))

  return [f(0) * 255, f(8) * 255, f(4) * 255]
}

/**
 * One CSS colour as `{ r, g, b, a }` in sRGB, or null when this module cannot evaluate it.
 *
 * ⚠ NULL IS A VERDICT, NOT A FAILURE TO FIND ONE. A colour in a space this cannot evaluate —
 * `oklch()`, `lab()`, `display-p3`, a named colour, an unevaluated `color-mix()` — is never
 * guessed at: the caller reports it as a finding with its raw text, because *"I could not tell
 * whether this is a sentinel"* must read as unproven rather than as clean.
 *
 * What is evaluated: `#rgb`, `#rgba`, `#rrggbb`, `#rrggbbaa`, `transparent`, `rgb()`/`rgba()`
 * in comma and space syntax with `/ alpha`, `hsl()`/`hsla()`, and `color(srgb …)` — which is the
 * form an engine serialises a `color-mix()` result in, so a mixed colour that lands exactly on a
 * sentinel is recognised as one.
 *
 * @param {unknown} input
 * @returns {{ r: number, g: number, b: number, a: number } | null}
 */
export function parseColour(input) {
  if (typeof input !== 'string') return null

  const text = input.trim().toLowerCase()

  if (text === 'transparent') return { r: 0, g: 0, b: 0, a: 0 }

  const hex = /^#([0-9a-f]+)$/.exec(text)

  if (hex !== null) {
    const digits = hex[1]

    if (![3, 4, 6, 8].includes(digits.length)) return null

    const wide = digits.length <= 4
    const part = (index) =>
      Number.parseInt(wide ? digits[index].repeat(2) : digits.slice(index * 2, index * 2 + 2), 16)
    const hasAlpha = digits.length === 4 || digits.length === 8

    return { r: part(0), g: part(1), b: part(2), a: hasAlpha ? part(3) / 255 : 1 }
  }

  const fn = /^(rgba?|hsla?|color)\(([^()]*)\)$/.exec(text)

  if (fn === null) return null

  const tokens = fn[2]
    .trim()
    .split(/[\s,/]+/)
    .filter(Boolean)
  const name = fn[1]
  let head
  let rest

  if (name === 'color') {
    // Only sRGB is evaluated. `display-p3`, `rec2020` and the like have no 8-bit sRGB reading
    // that is not a gamut-mapping decision, and this module does not make one.
    ;[head, ...rest] = tokens

    if (head !== 'srgb') return null
  } else {
    rest = tokens
  }

  if (rest.length !== 3 && rest.length !== 4) return null

  let alpha = 1

  if (rest.length === 4) {
    alpha = number(rest[3], 1)

    if (alpha === null) return null
  }

  alpha = clamp(alpha, 0, 1)

  let rgb

  if (name === 'hsl' || name === 'hsla') {
    const h = hue(rest[0])
    const s = number(rest[1], 1)
    const l = number(rest[2], 1)

    if (h === null || s === null || l === null) return null

    // A bare number is read as a percentage, which is what CSS Color 4's space-separated form
    // permits and what an engine never emits — accepted so a synthetic record cannot be
    // mis-sorted by a notation this module could have read.
    const unit = (token, value) => (token.endsWith('%') ? value : value / 100)

    rgb = hslToRgb(h, clamp(unit(rest[1], s), 0, 1), clamp(unit(rest[2], l), 0, 1))
  } else {
    // `color(srgb …)` channels are 0..1; `rgb()` channels are 0..255. A percentage resolves
    // against whichever range the function uses.
    const raw = rest.slice(0, 3).map((token) => number(token, name === 'color' ? 1 : 255))

    if (raw.some((value) => value === null)) return null

    rgb = name === 'color' ? raw.map((value) => value * 255) : raw
  }

  if (rgb.some((value) => !Number.isFinite(value)) || !Number.isFinite(alpha)) return null

  const colour = { r: channel(rgb[0]), g: channel(rgb[1]), b: channel(rgb[2]), a: alpha }

  /*
   * ⚠ EXACT TO THE 8-BIT CHANNEL, AND NOW SAID AND TESTED RATHER THAN IMPLIED. Rounding a channel
   * to the nearest integer made `rgb(254.5, 0, 228)` and `color(srgb 1 0 0.8959)` read as the
   * sentinel `#ff00e4` — QA's L5, found by running both — while the docblock above `classify()`
   * said *"EXACT, NOT NEAR"*. A browser serialises a computed colour as 8-bit integers (or a
   * `color(srgb …)` that is an integer over 255 to within its printed precision), so a channel that
   * is NOT within 0.01 of an integer, or that had to be clamped into range, is not something an
   * engine returned and cannot be a sentinel. It is marked and `classify()` sorts it as `other`.
   */
  const exact = (value) =>
    value >= -0.01 && value <= 255.01 && Math.abs(value - Math.round(value)) <= 0.01

  if (!rgb.every(exact)) colour.inexact = true

  return colour
}

/** `#rrggbb` for an opaque colour, `rgba(r, g, b, a)` otherwise. */
export function describeColour(parsed) {
  if (parsed.a === 1) {
    const hex = (value) => value.toString(16).padStart(2, '0')

    return `#${hex(parsed.r)}${hex(parsed.g)}${hex(parsed.b)}`
  }

  let alpha = parsed.a === 0 ? '0' : String(Number(parsed.a.toFixed(6)))

  // ⚠ A NEAR-OPAQUE ALPHA MUST NOT PRINT AS `1`. `rgba(255,0,228,0.9996)` once reported
  // `value: 'rgba(255, 0, 228, 1)'`, which reads as opaque, and alphas that rounded alike merged
  // into one group (QA L6).
  if (alpha === '1' && parsed.a !== 1) alpha = '0.999999'

  return `rgba(${String(parsed.r)}, ${String(parsed.g)}, ${String(parsed.b)}, ${alpha})`
}

/**
 * Sort ONE raw colour string into the three categories.
 *
 *   sentinel   — fully opaque and EXACTLY one of the six published values, to the 8-bit channel
 *   nonThemed  — a member of `NON_THEMED`
 *   other      — everything else, including anything this module cannot evaluate
 *
 * ⚠ EXACT, NOT NEAR — to the 8-bit channel. `rgb(255, 0, 229)` is one digit off the background sentinel and is `other`,
 * as is the sentinel at 99% alpha: a tint of the tenant's colour is a colour the page painted
 * that the tenant did not choose, and a tolerance here would let a near-miss literal pass.
 *
 * @param {unknown} raw
 * @param {Map<string, string>} sentinels `#rrggbb` -> the role that publishes it
 */
export function classify(raw, sentinels) {
  const text = typeof raw === 'string' ? raw.trim().toLowerCase() : String(raw)
  const parsed = parseColour(raw)

  if (parsed === null) {
    return { kind: 'other', token: text, why: 'not a colour this classifier can evaluate to sRGB' }
  }

  if (parsed.inexact === true) {
    return {
      kind: 'other',
      token: text,
      why: 'a channel that is not an exact 8-bit value, which no engine returns',
    }
  }

  const token = describeColour(parsed)

  if (parsed.a === 1) {
    const role = sentinels.get(token)

    if (role !== undefined) return { kind: 'sentinel', role, token }
  }

  /*
   * ⚠ DECIDED ON THE PARSED VALUE, NOT ON A ROUNDED STRING. `rgba(0, 0, 0, 0.0004)` used to be
   * printed with its alpha rounded to three places, which made it the member `rgba(0, 0, 0, 0)`
   * while `paintVerdict` said it paints — contradicting this set's own claim to be stricter than
   * "any zero-alpha colour" (QA L4). Exactly zero alpha on exactly black, or it is a finding.
   */
  if (parsed.a === 0 && parsed.r === 0 && parsed.g === 0 && parsed.b === 0) {
    return { kind: 'nonThemed', member: 'rgba(0, 0, 0, 0)', token: 'rgba(0, 0, 0, 0)' }
  }

  return {
    kind: 'other',
    token,
    why:
      parsed.a === 1
        ? 'an opaque colour that is not one of the six published sentinels'
        : 'a colour with alpha that is not the declared non-themed member',
  }
}

const NONE = new Set(['none', 'hidden'])

/**
 * Whether a recorded colour value can reach a pixel, from the facts the collector recorded.
 *
 * ⚠ THE COLLECTOR USED TO DECIDE THIS IN THE BROWSER, and a decision made inside a serialised
 * `page.evaluate` closure cannot be unit-tested at all. It is the half of the dead-control check
 * that matters — a role is DEAD when nothing paints with it — so it moves here, where a case can
 * drive every branch.
 *
 * It is recorded rather than filtered on: *"it computes but never paints"* is itself the answer
 * to the dead-control question, and dropping those samples would be answering it by not asking.
 *
 * @returns {{ paints: boolean, reason: string }}
 */
export function paintVerdict(sample) {
  const { box, property } = sample
  const facts = sample.facts ?? {}
  const render = sample.render ?? {}

  /*
   * ⚠ THIS USED TO BE `box.w > 2 && box.h > 2`, AND BOTH REVIEWERS FOUND IT WRONG IN OPPOSITE
   * DIRECTIONS. A 1px or 2px rule painted with `--color-accent` read as UNPAINTED (QA M5) — a
   * false dead control, which would have forced a baseline entry for a role that does paint — and
   * nothing about visibility, opacity, clipping or text size was considered, so a role carried
   * only by an invisible element read as painted (QA M4, Security 7). The threshold existed for
   * ONE reason, `.sr`'s 1px clipped box, and `.sr` is now excluded by what actually hides it:
   * its own `clip-path`.
   *
   * What is judged now is MEASURED on the element — its own box, its computed `visibility`, its
   * EFFECTIVE opacity (the product up the ancestor chain), `clip-path` and `clip`, and its font
   * size for text. ⚠ STILL NOT JUDGED, and named in `R-318` rather than implied: an element
   * covered by another, one positioned off-screen, `overflow` clipping by an ancestor, and a
   * `color` overridden by `-webkit-text-fill-color`.
   */
  if (!(box.w > 0 && box.h > 0)) {
    return { paints: false, reason: 'the element has no box' }
  }

  /*
   * ⚠ A GENERATED BOX IS NOT MEASURED. The collector reads a `::before`'s geometry from the
   * element that generates it, so a `::before{content:"";background:var(--color-accent)}` with no
   * size of its own inherited its parent's 300×40 and read as painting. Its own size is not
   * available to `getBoundingClientRect`, so it counts as unpainted here — the conservative
   * direction, and a boundary (`R-318`).
   */
  if (sample.pseudo !== null && sample.pseudo !== undefined) {
    return {
      paints: false,
      reason: 'a generated box\u2019s own size is not measured, so it counts as unpainted',
    }
  }

  if (render.visibility === 'hidden' || render.visibility === 'collapse') {
    return { paints: false, reason: `visibility is ${render.visibility}` }
  }

  if (render.opacity === 0) {
    return { paints: false, reason: 'the element\u2019s effective opacity is 0' }
  }

  if (render.clipPath !== undefined && render.clipPath !== 'none') {
    return { paints: false, reason: `clipped by clip-path: ${render.clipPath}` }
  }

  if (render.clip !== undefined && render.clip !== 'auto') {
    return { paints: false, reason: `clipped by clip: ${render.clip}` }
  }

  const parsed = parseColour(sample.colour)

  if (parsed !== null && parsed.a === 0) {
    return { paints: false, reason: 'the value is fully transparent' }
  }

  const textual = [
    'color',
    '-webkit-text-fill-color',
    '-webkit-text-stroke-color',
    'text-shadow',
  ].includes(property)

  if (textual && render.fontSize === 0) {
    return { paints: false, reason: 'the font size is 0' }
  }

  const drawn = (width, style) => width > 0 && !NONE.has(style)

  switch (property) {
    case 'color':
    case '-webkit-text-fill-color':
    case 'text-shadow':
      return facts.hasText === true
        ? { paints: true, reason: 'the element carries its own text' }
        : { paints: false, reason: 'the element carries no text node of its own' }

    case '-webkit-text-stroke-color':
      return facts.hasText === true && facts.width > 0
        ? { paints: true, reason: `the text is stroked at ${String(facts.width)}px` }
        : { paints: false, reason: 'the text has no stroke' }

    case 'background-color':
    case 'background-image':
      return { paints: true, reason: 'the element has a box to fill' }

    case 'border-top-color':
    case 'border-right-color':
    case 'border-bottom-color':
    case 'border-left-color': {
      const side = property.split('-')[1]
      const label = `${String(facts.width)}px ${String(facts.style)}`

      return drawn(facts.width, facts.style)
        ? { paints: true, reason: `border-${side}: ${label}` }
        : { paints: false, reason: `border-${side} is ${label}` }
    }

    case 'outline-color':
      return drawn(facts.width, facts.style)
        ? { paints: true, reason: `outline: ${String(facts.width)}px ${String(facts.style)}` }
        : {
            paints: false,
            reason: `outline is ${String(facts.width)}px ${String(facts.style)} in this state`,
          }

    case 'text-decoration-color':
      return facts.line !== undefined && facts.line !== 'none'
        ? { paints: true, reason: `text-decoration-line: ${facts.line}` }
        : { paints: false, reason: 'text-decoration-line is none' }

    case 'column-rule-color':
      return drawn(facts.width, facts.style)
        ? { paints: true, reason: `column-rule: ${String(facts.width)}px` }
        : { paints: false, reason: 'there is no column rule' }

    case 'text-emphasis-color':
      return facts.style !== undefined && facts.style !== 'none'
        ? { paints: true, reason: 'text-emphasis-style is set' }
        : { paints: false, reason: 'text-emphasis-style is none' }

    case 'caret-color':
      return { paints: false, reason: 'a caret needs an editable element in focus' }

    case 'box-shadow':
      return { paints: true, reason: 'a shadow is drawn' }

    case 'fill':
    case 'stroke':
      return { paints: true, reason: 'an SVG shape' }

    default:
      return { paints: true, reason: 'assumed to paint' }
  }
}

/** One element, and one of its generated boxes: the identity a derived property collapses on. */
const elementKey = (sample) => `${String(sample.el)}|${sample.pseudo ?? ''}`

/**
 * Classify every sample of one rendered page.
 *
 * @param {{ samples: object[] }} page
 * @param {Map<string, string>} sentinels
 * @returns {{
 *   counts: { samples: number, sentinel: number, nonThemed: number, other: number, derived: number },
 *   findings: object[],
 *   roles: Record<string, { total: number, painting: number }>,
 * }}
 */
export function analysePage(page, sentinels) {
  const counts = { samples: 0, sentinel: 0, nonThemed: 0, other: 0, derived: 0 }
  const roles = Object.fromEntries(
    [...sentinels.values()].map((role) => [role, { total: 0, painting: 0 }]),
  )

  // Pass 1: each element's own `color`, and what it classifies as. A derived property is
  // judged against THIS, so it has to be known before any of them is.
  const own = new Map()

  for (const sample of page.samples) {
    if (sample.property === 'color') {
      own.set(elementKey(sample), classify(sample.colour, sentinels).token)
    }
  }

  const derivedOf = new Map()
  const grouped = new Map()

  for (const sample of page.samples) {
    counts.samples += 1

    const result = classify(sample.colour, sentinels)
    const verdict = paintVerdict(sample)

    if (result.kind === 'sentinel') {
      counts.sentinel += 1
      roles[result.role].total += 1

      if (verdict.paints) roles[result.role].painting += 1

      continue
    }

    if (result.kind === 'nonThemed') {
      counts.nonThemed += 1

      continue
    }

    counts.other += 1

    const key = elementKey(sample)

    if (CURRENTCOLOR_DERIVED.includes(sample.property) && own.get(key) === result.token) {
      counts.derived += 1

      const seen = derivedOf.get(key) ?? new Set()

      seen.add(sample.property)
      derivedOf.set(key, seen)

      continue
    }

    const groupKey = [sample.shape, sample.property, result.token, verdict.paints].join('|')
    const group = grouped.get(groupKey)

    if (group === undefined) {
      grouped.set(groupKey, {
        shape: sample.shape,
        property: sample.property,
        named: sample.named === true,
        value: result.token,
        raw: sample.value,
        paints: verdict.paints,
        reason: verdict.reason,
        why: result.why,
        path: sample.path,
        text: sample.text,
        count: 1,
        elements: [key],
        derived: new Set(),
      })
    } else {
      group.count += 1
      group.elements.push(key)
    }
  }

  const findings = [...grouped.values()].map((group) => {
    const derived = new Set()

    for (const key of group.elements) {
      for (const property of derivedOf.get(key) ?? []) derived.add(property)
    }

    const finding = { ...group }

    // `elements` is bookkeeping for the collapse above and is not part of a finding.
    delete finding.elements

    // Only a source `color` can have consequences; a border finding has none to list.
    return { ...finding, derived: group.property === 'color' ? [...derived].sort() : [] }
  })

  return { counts, findings, roles }
}

/**
 * Which (template, role) pairs paint nowhere, across BOTH widths.
 *
 * ⚠ A ROLE PAINTED AT ONE WIDTH IS PAINTED. A rule inside a media query is a real use of a
 * token, and the claim is about the page a visitor receives at either of the two widths.
 *
 * @param {Record<string, Record<string, Record<string, { total: number, painting: number }>>>} byTemplate
 *   template -> width -> role -> counts
 */
export function deadControls(byTemplate, roleNames) {
  const dead = []

  for (const [template, widths] of Object.entries(byTemplate)) {
    for (const role of roleNames) {
      const painting = Object.values(widths).reduce(
        (sum, perRole) => sum + (perRole[role]?.painting ?? 0),
        0,
      )

      if (painting === 0) {
        const total = Object.values(widths).reduce(
          (sum, perRole) => sum + (perRole[role]?.total ?? 0),
          0,
        )

        dead.push({ template, role, total })
      }
    }
  }

  return dead
}

/**
 * The frozen allowance for known-dead controls, and the three ways it can be red.
 *
 * ⚠ IT IS NOT THE NON-THEMED SET AND IT MUST NOT BE READ AS ONE. That set bounds what a COLOUR
 * may be; this bounds which controls are KNOWN dead. It exists because two pickers are dead
 * today (`rail`/`color.surface`, `ember`/`color.accent_text`) and Muhammad ruled not to fix them
 * — their designs are replaced by `QRPAGER-49` — while also ruling the check permanent. A
 * permanent check that is red on `main` is not a gate, so the two are enumerated BY NAME with an
 * owner and a date, and the list can only SHRINK:
 *
 *   unexpected — a dead pair that is not in the baseline: a new dead control. RED.
 *   stale      — a baselined pair that now paints: the claim of debt is false. RED, so the
 *                entry is deleted rather than left to excuse a regression later.
 *   gone       — a baselined pair whose template is no longer in the enum: RED, so the
 *                `QRPAGER-49` author is forced to delete the entry rather than inherit it.
 *
 * That last one is what turns the baseline into `QRPAGER-49`'s acceptance criterion: replacing
 * the three arrangements makes every entry `gone`, and the only way to green is to delete them.
 *
 * @param {{ template: string, role: string }[]} dead
 * @param {{ dead?: { template: string, role: string }[] } | null} baseline
 */
export function compareBaseline(dead, baseline, templates, roleNames) {
  const allowed = baseline?.dead ?? []
  const same = (a, b) => a.template === b.template && a.role === b.role

  return {
    unexpected: dead.filter((pair) => !allowed.some((entry) => same(entry, pair))),
    stale: allowed.filter(
      (entry) =>
        templates.includes(entry.template) &&
        roleNames.includes(entry.role) &&
        !dead.some((pair) => same(entry, pair)),
    ),
    gone: allowed.filter(
      (entry) => !templates.includes(entry.template) || !roleNames.includes(entry.role),
    ),
  }
}

/**
 * Whether a record can be TRUSTED as a measurement, before any of it is classified.
 *
 * ⚠ A MEASUREMENT PROBLEM IS NOT A FINDING AND IS NEVER FOLDED INTO ONE. A record that cannot
 * say which browser produced it, or that was rendered at a width other than the one asked for,
 * is not a smaller or noisier result — it is not evidence, and the report refuses it.
 *
 * `innerWidth` is READ FROM THE RENDERING ENVIRONMENT by the collector, and `requestedWidth` is
 * what the test asked for. Phase 1 recorded only the second and called it the first, which
 * Muhammad's review caught: *"derived provenance is not captured provenance."*
 *
 * @returns {string[]} one sentence per problem; empty when the record is sound
 */
export function validateRecord(record, expected) {
  const problems = []
  const where = `${expected.template} @ ${expected.width}`

  if (record.template !== expected.template) {
    problems.push(`${where}: the record says it is ${String(record.template)}`)
  }

  if (record.requestedWidth !== expected.width) {
    problems.push(`${where}: the record was asked for ${String(record.requestedWidth)}`)
  }

  if (record.innerWidth !== expected.width) {
    problems.push(
      `${where}: window.innerWidth is ${String(record.innerWidth)}, not ${String(expected.width)} — ` +
        'the page was not rendered at the width the claim names',
    )
  }

  if (typeof record.browser?.version !== 'string' || record.browser.version === '') {
    problems.push(`${where}: the record does not say which browser build produced it`)
  }

  if (!Array.isArray(record.samples) || record.samples.length === 0) {
    problems.push(`${where}: the record carries no samples`)
  }

  return problems
}
