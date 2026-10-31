# QRPAGER-23 — Task 8b: the Filament restyle and the zero-technical-background walk

| | |
| --- | --- |
| Ticket | `QRPAGER-23` (Task 8b). Task 8a is `QRPAGER-15` and is not this task. |
| Branch | `task-8b-panel-restyle` — **not yet cut.** Held on `task-8b-precoding`, branched off `d8c9652`, the commit `task-8a` is expected to tag. See §8. |
| Base commit | **Undecided, and deliberately so: it is the `task-8a` tag, which does not exist yet.** See §8. |
| Named invariant (§11) | `QRPAGER-23 §4.1`, quoted nowhere in this file because restating it is how a clause gets softened. |
| State of this file | The seven `§3` answers only. **No implementation commit.** |

This file answers the seven `§3` pre-coding questions. It restates no rule from
`PROJECT_INSTRUCTIONS.md`, `PLAN.md` or the ticket.

**Every figure below names the commit and the machine it was measured at**, and every table
carries the command that re-derives it. Nothing here is transcribed from a previous
session's report.

**Measurement context for every figure in this file unless stated otherwise:**

```
commit  d8c96521baf033e2e70b9b4feb8a45d61c1e3e8d   (task-8a-design-system, pushed)
host    Linux 6.18.54.1-microsoft-standard-WSL2 (WSL2)
php     8.4.27    laravel 13.38.0    filament 5.11.0    livewire 4.4.8
node    v22.23.4  pnpm 12.16.1
```

Re-derive the stack row with:

```
cd apps/api && php -v | head -1 && php artisan --version && php -r '$l=json_decode(file_get_contents("composer.lock"),true);
foreach($l["packages"] as $p){ if($p["name"]==="filament/filament"||$p["name"]==="livewire/livewire"
||$p["name"]==="laravel/framework"){ printf("%-20s %s\n",$p["name"],$p["version"]); } }'
```

---

## 0. Stop-and-reports, raised before any code

`§0` says never silently resolve a conflict. **Four.** Three were raised before any code and
**all three were ruled on 2026-10-31**, each recorded below with the measurement it produced.
**SR-4 was raised on 2026-11-01, during implementation, and is OPEN** — it could not have been
raised earlier, because it is a property of Filament's colour system that only reading its
resolver revealed, and the pre-coding reading had measured the theming mechanism rather than
the palette one.

**SR-1's ruling overturned my reading, and the record says so rather than quietly adopting it:** I
reported the redesign as unavailable; it was available, and I had attempted it in the wrong direction.
One namespace of four is a genuine exception, measured.

### SR-1 — RULED, and the ruling was right: the redesign IS available. Measured outcome is a SPLIT

**Raised** that trap 1's prescribed proof was not satisfiable, because three attempts to clear Tailwind's
default theme each broke Filament's own stylesheet.

**Ruled 2026-10-31:** the redesign was attempted in the wrong direction. *Every error said a **variable
does not exist**, not that a value is wrong. Clearing removes the name; redefining keeps the name and
replaces the value — and Filament cannot tell the difference, because it consumes the variable, not the
default value.*

**That is correct, and the measurement below is the proof.** It also produced one exception, which the
ruling's second branch anticipated; recording **which of the two outcomes** was measured is what it asked
for, so it is recorded per namespace rather than as a single verdict.

#### What the mechanism turned out to be — and it is cleaner than redefining Tailwind's palette

Working through it namespace by namespace surfaced a fact that changes the shape of the answer:
**Filament defines its own colour families in its own `@theme inline` block**, fed by the panel provider's
`->colors()`:

```
$ grep -oE '^\s+--color-[a-z]+-[0-9]+:' apps/api/vendor/filament/support/resources/css/index.css \
    | sed -E 's/^\s+--color-([a-z]+)-[0-9]+:/\1/' | sort -u
color  custom  danger  gray  info  primary  success  warning
```

```css
/* vendor/filament/support/resources/css/index.css */
@theme inline {
    --color-gray-50: var(--gray-50);     /* ← fed by ->colors(['gray' => …]) */
    …
}
```

So **`bg-gray-50` never resolved through Tailwind's default gray at all** — Filament's own entry already
shadows it. The first `--color-*: initial` attempt failed on `bg-gray-50` because the wildcard wiped
**Filament's** entry, not Tailwind's. **The gray ladder is therefore already a token input through the
sanctioned extension point (`->colors()`), with no vendor-internal CSS enumeration at all** — which
answers the "second source for the framework's internals" objection I raised against this option.

The mechanism is therefore:

* **colour** — clear Tailwind's default families **by name** (21 of them; `gray` is deliberately *not*
  cleared, because Filament's own entry shadows it), re-supply the one default name Filament still
  references directly (`--color-sky-400`), and add the approved palette under its own names. The gray /
  primary / danger / success / info / warning ladders come from `->colors()` with token values — *that is
  the restyle*, as the ruling says.
* **type** — clear `--text-*`, re-supply Filament's seven names (`xs sm base lg xl 2xl 3xl`) at approved
  sizes, plus the token names (`--text-13 … --text-96`).
* **radius** — clear `--radius-*`, re-supply Filament's names (`sm md lg xl full none`) **and the bare
  `--radius`**, which Filament `@apply`s as plain `rounded`.
* **spacing** — redefine the **bare `--spacing`** to the approved 4px base. Never cleared. See the
  exception below.
* **font** — nothing in CSS: Filament's `--font-sans` is `var(--font-family), …`, and `--font-family` is
  what the provider's `->font()` sets.

#### The acceptance, measured over the COMPILED panel CSS

Compiled `vendor/filament/filament/resources/css/theme.css` + that `@theme` block through Tailwind 4.3.3,
with the three candidates the ruling names passed in as scanned candidates — which is what a bad fixture
in the source set amounts to, since `source(none)` means a utility appears only if some scanned source
carries it.

**It builds: 722,971 bytes, against 734,094 for the untouched Filament theme** — about 11 KB of default
palette gone.

| candidate | compiled panel CSS | verdict |
| --- | --- | --- |
| `bg-red-500` | **ABSENT** | ✅ limb one — *emits nothing* |
| `text-sm` | `font-size: var(--text-sm)`, and `--text-sm: 0.8125rem` = the approved **13** step | ✅ limb two — *emits only token values* |
| `p-5` | `padding: calc(var(--spacing) * 5)` = 4px × 5 = **20px** | ❌ **off the approved scale** (4 8 12 16 24 32 48 64 96) |

Everything else behaves:

| | |
| --- | --- |
| `bg-emerald-600` · `bg-blue-500` · `bg-indigo-500` | **ABSENT** — the whole default palette is gone |
| `bg-gray-50` · `text-gray-500` | `var(--gray-50)` / `var(--gray-500)` — i.e. through `->colors()` |
| `rounded-md` · `rounded-lg` · `rounded-full` | token radii |
| `text-xs` · `text-base` · `text-lg` | token sizes |
| `bg-paper` · `text-ink` · `bg-brand` · `text-13` · `rounded-16` | token-named utilities compile |

#### Re-measured after the pin — both toolchains, and one figure of mine was wrong

Ruled 2026-10-31: *"those figures [were] measured against a parent that is about to change, which this
project does not accept as a figure. Pin first, then re-run the three candidates and the two byte counts,
and report both sets with the versions beside them."*

**Pinned, installed, re-run. The two toolchains are byte-identical, and that is the result.**

| | `tailwindcss` | candidates | Filament theme untouched | with the `@theme` block |
| --- | --- | --- | --- | --- |
| workspace (`node_modules/.pnpm`) | **4.3.3** | 28 | **734,389** | **722,971** |
| panel (`apps/api/node_modules`) | **4.3.3** | 28 | **734,389** | **722,971** |

| candidate | workspace | panel |
| --- | --- | --- |
| `bg-red-500` | **ABSENT** | **ABSENT** |
| `text-sm` | `font-size: var(--text-sm)` | `font-size: var(--text-sm)` |
| `p-5` | `padding: calc(var(--spacing) * 5)` | `padding: calc(var(--spacing) * 5)` |

Identical in every cell. And the two installs are the same bytes, not merely the same version string:

```
$ cmp apps/api/node_modules/tailwindcss/index.css    node_modules/.pnpm/tailwindcss@4.3.3/…/index.css
$ cmp apps/api/node_modules/tailwindcss/dist/lib.mjs node_modules/.pnpm/tailwindcss@4.3.3/…/dist/lib.mjs
   (both silent — identical)
```

**⚠ AND THE RE-MEASUREMENT CAUGHT AN INVALID FIGURE OF MINE.** This file previously read
*"722,971 bytes, against 734,094 for the untouched Filament theme — about 11 KB of default palette
gone."* **Those two numbers were never comparable.** 722,971 came from a run passing **28** candidates;
734,094 came from an earlier run passing **12**. A compiled-CSS byte count is a function of the candidate
set as well as the theme, so pairing them measured the candidate list as much as the palette. The correct
pair, both at 28 candidates, is **734,389 → 722,971 = 11,418 bytes removed**. The conclusion survives
(~11 KB), the pairing did not.

That is the same defect one layer down from the one this delta was raised about: *a figure measured
against a parent that has since changed is not a figure* — and a byte count measured against a different
candidate set is not the same byte count. Recorded rather than quietly corrected.

**What the pin was worth, stated precisely.** `^4.0.0` resolves to **4.3.3 today**, so the caret was not
*wrong* — it was *unpinned*, and the figures happened to agree. The pin's value is that they will still
agree at the next `npm install`, which is the whole content of `§6`'s fresh-clone clause. See `§9.4`.

#### The one exception, and it is measurably forced

**`text-sm` could not be made absent and did not need to be:** Filament `@apply`s `text-sm` itself, so the
name must exist — and the ruling's second limb covers it, because its value is a token.

**`p-5` is the real exception.** Filament's CSS calls the `--spacing(…)` theme function at three sites:

```
vendor/filament/support/resources/css/utilities.css:777   margin-block: --spacing(8);
vendor/filament/support/resources/css/utilities.css:779   margin-top: --spacing(8);
vendor/filament/forms/resources/css/components/date-time-picker.css:68
      @apply grid grid-cols-[repeat(7,minmax(--spacing(7),1fr))] gap-1;
```

so the **bare `--spacing` theme variable must exist** — and in Tailwind v4 its existence is exactly what
enables the *dynamic* `p-<any-number>` utility. The two requirements are mutually exclusive, and that is
measured rather than argued: naming only the nine approved steps as explicit `--spacing-N` keys and
omitting the bare one **fails the build**:

```
--spacing-*: initial;
--spacing-1: 4px; --spacing-2: 8px; … --spacing-24: 96px;    /* no bare --spacing */
→ BUILD FAILED: The --spacing(…) function requires that the `--spacing` theme variable
  exists, but it was not found.
```

There is no configuration in which Filament's CSS compiles and `p-5` does not. **The approved scale is
still expressible** — `p-1 p-2 p-3 p-4 p-6 p-8 p-12 p-16 p-24` are exactly 4 8 12 16 24 32 48 64 96 px —
but nothing *stops* `p-5`.

#### So: which of the two outcomes, per namespace

| namespace | outcome | instrument |
| --- | --- | --- |
| **colour** | **1 — the redesign works.** `bg-red-500` and every other default family compile to nothing | the `@theme` block; no source rule needed for colour |
| **type** | **1 — the redesign works**, by the second limb: the name must exist because Filament applies it, and its value is a token | as above |
| **radius** | **1 — the redesign works**, same shape as type | as above |
| **spacing** | **2 — measurably unavailable.** The vendor's CSS forces the bare key; the bare key enables every multiple | `§8` is satisfied by the strongest mechanism *available*, and that is **stronger than a source rule** — see `§0.1` below |

#### 0.1 The spacing mechanism — ruled, and stronger than the source rule I proposed

**Ruled 2026-10-31.** The `§5` row stands, and the exclusivity proof is accepted — *"no value of
`--spacing` fixes it: any bare key generates every multiple, so `p-5` = 20px exists whatever we set."*
**Verified on the panel's own pinned toolchain, four values:**

```
$ --spacing: 4px      -> p-5 EMITS padding: calc(var(--spacing) * 5);
$ --spacing: 1px      -> p-5 EMITS padding: calc(var(--spacing) * 5);
$ --spacing: 0.25rem  -> p-5 EMITS padding: calc(var(--spacing) * 5);
$ --spacing: 0        -> p-5 EMITS padding: calc(var(--spacing) * 5);
$ nine named steps, bare key omitted
      -> FAILS: The --spacing(…) function requires that the `--spacing` theme variable exists
```

**But the ruling corrects my instrument, and the correction is the important part.** I proposed a source
rule over the files we author. That is **not** the strongest mechanism available, because it misses a
value entering through

* a **Filament component we configure** rather than author,
* a **plugin**, or
* a **class string built at runtime**.

**The mechanism is an assertion over the COMPILED panel CSS: every spacing value resolves to one of the
nine approved steps, with an allowlist of exactly the three vendor call sites — by their VALUE, and with
a HARD COUNT of 3.** The compiled assertion catches all three leaks the source rule misses, because by
then every path has become CSS.

**The allowlist, measured — and the count is the property that matters:**

```
$ grep -rhoE -- '--spacing\([0-9]+\)' apps/api/vendor/filament/*/resources/css/ | sort | uniq -c
      2 --spacing(8)
      1 --spacing(7)
   → count = 3
```

| site | value |
| --- | --- |
| `support/resources/css/utilities.css:777` `margin-block` | `--spacing(8)` |
| `support/resources/css/utilities.css:779` `margin-top` | `--spacing(8)` |
| `forms/resources/css/components/date-time-picker.css:68` `grid-cols` | `--spacing(7)` |

**The hard count of 3 is there so the vendor's exposure cannot grow silently** — the same mechanism as
8a's hard count of twelve themeable names, which makes any thirteenth fail. A Filament patch that adds a
fourth `--spacing(…)` site, or changes `7` to some other off-scale multiplier, reddens the gate and gets
read rather than absorbed. An allowlist by *value* rather than by *file path* is deliberate: a vendor
refactor that moves the call to another file must not silently widen the allowance.

**The source rule is kept as well, not replaced.** It fails earlier and it names the file, which the
compiled assertion cannot — a compiled-CSS failure says *a 20px padding exists*, not *which Blade template
wrote `p-5`*. Two instruments, different jobs: one for the authoring moment, one for the artefact.

**So the `§5` row carries:** the exclusivity measurement above (four `--spacing` values and the omitted-key
build failure), the three vendor line numbers and their values, the hard count, and the review-by that
re-tests them against a later Filament. It is a row because the residual cannot be removed, not because
nothing was built for it.

**`§8` forbids choosing a patch over a redesign; it does not require a redesign the vendor makes
impossible.** For three of the four namespaces there is no patch — the shape is gone. For spacing the
shape is held open by three lines of vendor CSS, and the row will carry those three line numbers so the
next reader can re-test them against a later Filament.

**What the implementation commit owes, stated now:** the acceptance above asserted over the **real**
compiled panel theme (not this probe), from a committed bad fixture carrying `bg-red-500 p-5 text-sm`;
the 21-family clear list **derived from Tailwind's own theme** rather than typed; and a check that every
default-palette name Filament's vendor CSS still references is redefined — so a Filament patch that starts
using `blue-500` goes red rather than silently shipping an off-palette colour.

### SR-2 — RULED: the clause is in force and the REPO copy is the stale one

**Ruled 2026-10-31.** The 2026-10-30 `§12` clause *"exists verbatim in the claude.ai Project instructions
— rank 2 as maintained there — and is missing from `docs/PROJECT_INSTRUCTIONS.md`. So it binds, and what
is missing is its record."*

**So the clause binds, and Task 8b's answers and screen walk are written against it.** The measurement
stands and is not in dispute: six distinctive phrases, zero hits in the repository, and no 2026-10-30 edit
in that file's history.

**The paste is Muhammad's (rank 2):** `§12` comes from the Project copy wholesale, and is **verified by
grep rather than by reading** — after it, `§12` must contain all three of:

```
grep -c 'The admin and studio panels are used by people with no technical background' docs/PROJECT_INSTRUCTIONS.md
grep -c 'Where that bar applies, and when'                                            docs/PROJECT_INSTRUCTIONS.md
grep -c '^- \*\*Order.\*\*'                                                            docs/PROJECT_INSTRUCTIONS.md
```

all three → `1`.

**The part worth carrying is the process defect, and it is recorded in `PLAN §0.1(a)`** beside the
sentence it falsifies (*"the repo copy is complete"*). This is the **second** divergence between the two
copies and the two point in **opposite directions** — `W-1` has the Project copy running 27 paragraphs
behind the repo, including `§11`'s Relay rule and Round limit; this one has the repo behind the Project
copy. **The repo file is the source and the Project copy the mirror, so an edit made in the mirror first
is the defect:** a rank-2 clause living only in the mirror is one paste from never having existed, and a
task can be judged against a standard its own repository cannot show it.

### SR-3 — RULED, and measured per screen: five inventory lines wrong, ZERO new rows needed

**Ruled 2026-10-31:** determine, per screen, whether **(a)** the capability exists on another surface — in
which case `§4.3`'s inventory is what is wrong and it is corrected in place to name the real surface — or
**(b)** it does not exist at all, in which case it is a finding against Task 6, which is **tagged**, and
8b does not build it. And *"Task 6's gate claims a restaurant goes zero to scannable QR in one wizard
pass, so (b) for the wizard would be a discharged gate clause with nothing behind it — report that one
separately if it is what you find."*

**Determined. `§4.3` is corrected in place, and the answer to the separate question is that the gate was
NOT discharged on an empty claim.**

| `§4.3` line | determination | disposition |
| --- | --- | --- |
| **Create-restaurant wizard** *(identity → branding → staff → QR batch → publish)* | **(a)** — the capability exists on another surface: a **three-field modal action** on `/platform/restaurants` (`Action::make('provision')`, label *New restaurant*: name, address, owner's email) that *requests* provisioning. The five-step shape never existed; its missing steps are each owned elsewhere — QR batch and staff `R-115`, branding `R-116` (**closed** 2026-10-31, ruled not worth building) | `§4.3` corrected in place |
| **Restaurant detail** *(branding, domains, staff, QR batches, usage)* | **(b)** — no route, and `RestaurantResource` is `->recordActions([])`. **But every constituent is already owned**: staff + QR batches `R-115`, branding `R-116` (closed), domains `/studio/domains` (Task 7, tenant panel), usage the dashboard's aggregates | `§4.3` corrected; **no new row** — nothing is unowned |
| **QR batches** | **(b)** — no surface anywhere. The only `QrCode` hits under `app/` are 2FA's `twoFactorQrCodeSvg()`; no migration, no route, no page | **already `R-115`, owner Task 10** |
| **Platform settings** | **(b)** — no route, **and no requirement**: the string appears in that inventory line and **nowhere else** in `PLAN`, any ticket or any gate clause. An inventory artefact, not an unbuilt screen | `§4.3` corrected; **no new row** |
| **Landing page builder** as a platform screen | `R-127`, already recorded | `§4.3` corrected |

#### The separate question, answered: Task 6's gate was discharged honestly

`§3` row 6 names *"restaurant create wizard end-to-end, staff management + invites, QR batch generation +
printable sheets"*. `§4a`'s Task 6 record carries a **"What this task does NOT deliver — named, with
owners"** section which states:

* *"a scannable QR in one wizard pass"* — needs `pager_sessions`. **`R-115`, Task 10.**
* *"Staff-management screens and QR batch generation"* — **`R-115`**.
* *"a staff invite is actually delivered"* — **`R-124`, Task 10.**

**The shortfall was declared when the tag was applied, not discovered now.** So there is nothing to file
against a tagged task, and `R-115`'s own review-by already requires Task 10's gate to name all three.
**Staff management is also not absent as a capability** — it is an API surface, not a panel one:
`GET|POST /v1/members` and `PATCH|DELETE /v1/members/{member}` (Task 5).

#### And trap 7 applied to the walk itself

`§4.3` now carries the route table **with the command that derives it from the router**, and the
correction states plainly that **the walk's subject list is derived from that table, never hand-listed** —
a walk measured against a typed inventory is the same defect one layer up. The derivation becomes a
gate-checked assertion with the first implementation commit, together with the other tables (§8.2).

### SR-4 — OPEN. `->colors()` CANNOT CARRY AN APPROVED VALUE, and the default fails AA in dark mode

Raised 2026-11-01, mid-implementation. **This blocks the colour half of the restyle and nothing
else** — `->viteTheme()`, `->font()`, the panel theme, the lint rule and the ladder migration are
all landed and green without it.

**The conflict.** `§12` says the panel is restyled from the approved tokens at Task 8, and the
approved palette has **one** brand, **one** signal and **one** alert-fill. Filament's only
sanctioned colour extension point is `->colors()`, and it wants **eleven shades per family** for
six families. Neither horn is available as stated: feeding it the approved value discards the
value, and inventing the other ten is the in-between-value invention `§12` forbids.

**Measured, in `vendor/`, not reasoned.** `ColorManager::getColors()` passes a STRING through
`Color::generatePalette()`, and that function (`Color.php:667`) keeps **only the hue**:

```
brand #0B6B4F -> oklch(0.47 0.094 166.529)
  generated 500 => oklch(0.68270588235294 0.17009090909091 166.529)
  generated 600 => oklch(0.59782352941176 0.16913636363636 166.529)
```

Lightness and chroma are Filament's fixed constants at every shade. Three consequences, each
measured:

1. **The approved value appears at NO shade.** `#0B6B4F` is lightness 0.47 / chroma 0.094; the
   nearest generated entries are 700 (0.515 / 0.149) and 800 (0.446 / 0.123).
2. **Two approved colours collapse into one family.** `ink` is hue 165.122 and `brand` is 166.529
   — **1.4° apart** — so their generated ladders are indistinguishable.
3. **Chroma below 0.03 collapses to zero.** `paper #F2F4F1` is chroma 0.004, so its whole ladder
   is grey. (That one happens to suit `gray`.)

**And the shades are not decorative — they are the input to Filament's own WCAG arithmetic.**
`ButtonComponentColorMap` takes the whole palette, runs `Color::calculateContrastRatio` over it
at `WCAG_AA_TEXT` (4.5), and picks bg/hover/text per component from candidate triplets
(`ButtonComponent.php:43-48`: `lightBackground(600, 500)`, `lightBackground(400, 300, 500)`,
`darkBackground(600, 500, 700)`). ⚠ A **missing** shade is not an error: `probeTextLightness`
does `$this->palette[$textShade] ?? 'oklch(1 0 0)'`, so a partial ladder silently resolves to
white — the dead-control shape `§4a` records four instances of.

**The three options, with what each actually produced when driven through that resolver.**

| | what it does | Filament's resolved light bg | light contrast | dark contrast |
| --- | --- | --- | --- | --- |
| (a) | `->colors(['primary' => '#0B6B4F'])` — the one-liner | shade **400**, a value in no approved set | 7.18 | **3.43 — FAILS AA** |
| (b) | eleven explicit values per family | — | — | — |
| (c) | a ladder DERIVED by one rule from the approved value | shade **600** = **`#0B6B4F` exactly** | **6.50** | **6.50** |

(b) is refused on sight: 66 values nobody has approved, which is exactly what `§12`'s "one ratio,
no in-between sizes" forbids one category over.

**(c) is the recommendation, and it is inside a mechanism that was already approved.** 8a's dark
theme was approved on the rule *`mix(from, to, amount%) in srgb-8bit-component-linear-round-half-up`*,
with the derivation stated per role. The ladder is the same move: **shade 600 is the approved value
itself** (it is what Filament's light-mode button asks for), lighter shades mix toward `paper`,
darker toward `ink`. One rule, two endpoints, both approved — not eleven decisions. Measured over
the generated ladder: monotonic in luminance across 50..950 for every family, `brand-600` at
5.87:1 on `paper` and 5.46:1 on `brand-50`.

**What (c) does NOT settle, stated rather than glossed.** Driven through the same resolver,
`alert-fill`'s derived ladder gives a dark pair of **3.68** — below the 4.5 text bar. That is not a
defect in the rule: the token source already says of `alert-fill` that *"a fill is read against its
own text, not against the ground"*, and its approved text partner is `alert-ink`. So `danger` needs
either a tuned mix toward `ink` on its dark shades or an explicit `dark:text` partner, and that is a
second decision inside the first.

**THE QUESTION.** Adopt (c) — generate an 11-shade ladder per Filament family from the approved
value by the ruled mix, anchored at 600, and add a gate assertion that Filament's own resolver
clears AA for every family it resolves? And if so, how should `danger`'s dark pair be closed:
tune its mix, or give it `alert-ink` as an explicit partner?

**Until it is ruled, neither provider calls `->colors()`**, so both panels keep
`ColorManager::DEFAULT_COLORS` — `primary => Amber`. The panel is therefore correctly themed for
type, spacing, radius and face, and **wrong for colour**, which is a visible half-finished state
rather than a hidden one. `R-118`'s four computed-style clauses are not measurable until this is
ruled, because the colours they would measure are not the final ones.


---

## 1. Q1 — no schema change

**None. No migration, and that is stated rather than skipped.**

* **No table, column, index, constraint, trigger, policy or grant** is created, altered or
  dropped. `PLAN §2`, the data contract, is unchanged and needs no `§6.1` update-first pass.
* **No role, no GUC, no Redis key.** Neither `app.tenant_id` nor `app.bypass_tenant` is read
  or written by anything this task adds, and nothing it adds calls `set_config`.
* **This task changes presentation and copy. Behaviour is unchanged**, which is why all
  previous suites must pass *unaltered* rather than *updated*.

**The one place a copy fix could appear to need a schema change, named in advance so it is
recognised rather than rationalised:** `/platform/audit-log` renders `subject_id` as a bare
uuid7 (`PlatformAuditResource.php:66`, label `Subject id`). The fix a reader reaches for is
"store a human label beside the id" — **a column, i.e. a schema change, and `audit_log` is
append-only with its own immutability trigger (`PLAN §2.9`).** It is not needed: the id's
*meaning* is resolvable at read time from `subject_type` + `subject_id`, and what the clause
requires is that the screen not show the identifier, not that the row stop carrying it.
**If that turns out to be wrong during implementation, it is a stop-and-report, not a
migration.**

---

## 2. Q2 — the tokens are 8a's, and here is each import path with the check that guards it

**Four artefacts reach this task. None is hand-written and none is re-typed.**

| # | artefact | how panel code consumes it | staleness check | which job runs that check |
| --- | --- | --- | --- | --- |
| 1 | `packages/tokens/generated/tokens.css` | `@import` from each panel theme — `../../../../../../packages/tokens/generated/tokens.css` | `pnpm tokens:check` (byte diff) | CI **frontend** job |
| 2 | `packages/tokens/generated/theme.css` | same, same relative path | `pnpm tokens:check` | CI **frontend** job |
| 3 | `apps/api/config/design-tokens.php` | `config('design-tokens.…')` in PHP — no path | `pnpm tokens:check` | CI **frontend** job |
| 4 | `packages/contracts/src/generated/status.ts` | not consumed by the panels | `composer contracts:check` | CI **gates** job |

Re-derive the relative paths (counted by the machine, not by eye):

```
python3 -c "
import os
for panel in ['platform','studio']:
    t=f'apps/api/resources/css/filament/{panel}/theme.css'
    for a in ['packages/tokens/generated/tokens.css','packages/tokens/generated/theme.css']:
        print(t,'->',os.path.relpath(a, os.path.dirname(t)))"
```

Re-derive the guarded set:

```
pnpm tokens:check     # names each artefact it checked, then the count
```

**`resources/css/filament/<panel>/theme.css` is where `make:filament-theme` puts a panel
theme** — read from the command's own source, not from documentation:

```
grep -n 'themePath\|viteTheme\|input' apps/api/vendor/filament/filament/src/Commands/MakeThemeCommand.php
```

It also requires two registrations, which the command prints as pending actions: the path
added to `vite.config.js`'s Laravel plugin `input` array, and `->viteTheme('<path>')` on the
panel provider. **Neither panel provider calls `->viteTheme()` today** — measured — so both
panels currently serve Filament's precompiled vendor CSS and `resources/css/app.css` reaches
neither of them.

### 2.1 ⚠ Three things about this wiring that are not obvious, each measured

**(a) `apps/api` is outside the pnpm workspace, so there is no package specifier to import.**
`pnpm-workspace.yaml` excludes it deliberately (`ADR-003`: listing it "would invite a
`package.json` that competes with `composer.json` for ownership"). So `@qrpager/tokens/…`
does not resolve from `apps/api`, and the import must be the **relative path** above. A
relative path across the two halves of the repo is fragile in one specific way — a moved
theme file breaks it silently at build time — so **the first implementation commit asserts
both paths resolve**, in the same shape `artefacts.test.ts` asserts for 8a's artefacts.

**(b) `apps/api` has its own npm/Vite/Tailwind install, and it is not installed.**
Measured: `apps/api/node_modules` is **absent**; `apps/api/package.json` pins
`@tailwindcss/vite ^4`, `tailwindcss ^4`, `vite ^8`, `laravel-vite-plugin ^3.1`. So the panel
theme compiles through **a second Tailwind** from the one the workspace uses, and
`composer setup` runs `npm install` to get it. **Two Tailwind majors must stay compatible for
one `@theme` block to work in both**, which is a fresh-clone concern and a gate-step concern,
and is the reason `pnpm tokens:check` running only in the *frontend* job matters →

**(c) the artefact the PHP half consumes is guarded only by the job the PHP half does not
run.** `apps/api/config/design-tokens.php` is checked by `pnpm tokens:check` — a frontend-job
step. `apps/api/bin/gate` does not check it. So a panel change needing a token change is
caught by CI but **not** by a local `composer gate`. 8a's `ci-parity.test.mjs` now covers both
gates in both directions, so this is a visible asymmetry rather than a hidden one; whether to
close it by adding the check to the PHP gate is a question for the implementation commit and
is named here so it is not discovered later.

---

## 3. Q3 — not applicable, and the reason is mechanical

**This task issues no query.** No Eloquent model, query-builder call, raw statement or Redis
command is added. Restyling changes how an existing screen renders what it already reads; the
reads are Tasks 6's and 7's and are untouched.

So there is no new path by which tenant context reaches a query — **and, the part worth
stating, no existing one is widened.** `/studio` is tenant-scoped through
`TenantPanelAccess::tenantId()` and `/platform` operates only on tables belonging to no
tenant (`§4 › Tenancy`). **A copy or style change cannot move that boundary, and the way it
could appear to is `§4`'s subject, not this one:** a friendlier error that names the tenant a
record belongs to would leak across it. That is Q4.

---

## 4. Q4 — restyling must not widen anything, and the sharp edge is refusal copy

### 4.1 The clause, and exactly which panel surfaces it binds

Several surfaces in this repository answer *"indistinguishable from unknown"* on purpose —
measured, eight call sites carry that reasoning in a comment:

```
grep -rn 'indistinguishable' apps/api/app --include='*.php'
```

**Of those, the ones a panel screen can reach are the narrow part, and being precise matters
because an over-broad reading of this clause would freeze copy that is safe to fix:**

| surface | reached by a panel screen? | what must not change |
| --- | --- | --- |
| `ProblemRenderer` — "no such session" vs "not your session" | **no** — API, not a panel | — |
| `AuthenticatedTenantResolver` — "not yours" vs "suspended" | **yes**, at panel sign-in | the two must stay one message |
| `DeviceController` — unknown vs other-tenant device | **no** — API | — |
| `DatabaseErrorMapper` — an RLS refusal | **yes**, if a panel write is refused | must not become "that belongs to another restaurant" |
| `AuthController` — account enumeration | **yes**, at panel sign-in | one message for unknown and wrong-password |

### 4.2 The rule I will hold myself to, stated so a reviewer can falsify it

**A refusal may say what to do. It may not say what exists.** "Check the address and try
again" is a fix. "No account with that address" is an oracle. Both are friendlier than a raw
401; only one is safe, and the difference is not obvious at the moment of writing copy, which
is why it is written down before any copy is written.

**The mechanical half:** the existing tests that pin these equalities are Tasks 5's and 6's
and **must pass unchanged** — they are the regression net for this entire question. If a copy
change reddens one of them, the copy is wrong, not the test. I will not relax one.

### 4.3 The other way a restyle widens something, which the ticket does not name

**A style value is an information channel.** Colour-coding a restaurants-list row by a state
the reader is not authorised to know is a leak with no text in it, and `R-118`'s row already
records the inverse discipline for Task 6 — each restaurant's state is *text inside its
badge rather than colour alone*. The restyle must not turn text into colour; it may add
colour to text.

---

## 5. Q5 — scale: what is not optimised, and why that is the right call

**The panels are low-traffic by construction, and the bound is a fact about the user set
rather than an estimate:** `§12`'s clause names *a restaurant owner and a platform admin* as
their only users. There is one platform admin and one owner per tenant, each using a panel
occasionally and interactively.

**What is deliberately not optimised, each with the reason:**

| not optimised | why |
| --- | --- |
| Panel CSS payload | The panel theme is a Tailwind build served once and cached. `§12`'s cold-3G bar is the **customer PWA's**, and a restaurant owner on a laptop is not that user. Trading panel clarity for bytes would be optimising the wrong budget |
| Per-request cost of the panels | Unchanged by this task — it adds no query and no request path. The existing readiness and load-shedding machinery is Task 2's and is untouched |
| Panel table pagination and query shape | Behaviour, not presentation. `§2` puts it out of scope, and the restaurants list and audit log already paginate |

**What IS bounded and must stay bounded:** the panel theme adds **no third-party request**.
This is not a performance point, it is `§12`'s reasoning and a privacy one, and →

**⚠ it is currently violated on the Laravel side, measured.** `apps/api/vite.config.js` uses
`laravel-vite-plugin`'s `bunny('Instrument Sans', { weights: [400, 500, 600] })` helper, which
sources that family from **fonts.bunny.net**, a third-party CDN — and `resources/css/app.css`
sets `--font-sans` to `'Instrument Sans'`. 8a's `fonts.test.ts` asserts no workspace
stylesheet reaches a font CDN **and greps for `fonts.bunny.net` by name**, but its file list
covers the four workspace stylesheets only, so `apps/api` was never in scope for it.

**Ruled into 8b scope 2026-10-31 and BLOCKING — the full disposition is `§9.1`**, including the third
clause I had not drawn out here: Instrument Sans is the Laravel starter-kit face, so it fails
*"a reviewer cannot name the UI framework from a screenshot"* in the one channel a screenshot
shows most plainly. The fix is `->font()` plus the self-hosted faces, not a CSS edit, and 8a's
no-CDN assertion is **widened** to cover the panel build.

---

## 6. Q6 — three different screens, and they are three because the user's next move differs

The ticket is explicit that *data source down*, *lacks the ability* and *list is empty* are
three screens. **They are three because the reader's next action differs: wait, ask someone,
or create something.** A single "Nothing to show" collapses all three into the one that is
most often wrong.

| | the reader's situation | what the screen must say | what it must never say |
| --- | --- | --- | --- |
| **Empty** | Nothing exists yet, and they can make one | the next action, as the action's own name | "No records" with no way forward |
| **Not permitted** | It exists, or might; they may not see it | who can help, in one sentence | whether it exists — that is Q4's oracle |
| **Source down** | It exists; we cannot read it right now | that it is us, not them, and whether to wait or retry | an exception class, a SQLSTATE, a connection string |

### 6.1 What the seven screens do today, measured

```
grep -rn 'emptyState\|placeholder(' apps/api/app/Features/Provisioning/Panel apps/api/app/Features/Landing/Panel
```

| screen | empty | not permitted | source down |
| --- | --- | --- | --- |
| `/platform` (dashboard) | n/a — widgets | panel access gate | **partly done**: `PlatformDashboard` shows the aggregates' staleness as text, on the stated reasoning that "a staleness that is not shown is a staleness a reader will assume is zero" |
| `/platform/restaurants` | **done** — `No restaurants yet` + `Use "New restaurant" to provision the first one.`, and that action **exists** (`Action::make('provision')->label('New restaurant')`), so it is a live next action and not a dead one | panel access gate | **unexamined** |
| `/platform/audit-log` | **⚠ heading only** — `Nothing recorded yet`, no description, `recordActions([])`, `toolbarActions([])`. An empty state with **no next action**, which is `§4.1`'s first half | panel access gate | **unexamined** |
| `/studio` (builder) | **unexamined** | tenant scope | **unexamined** |
| `/studio/domains` | **⚠ `R-154`, confirmed** — `challenge()` returns `['name'=>'','type'=>'TXT','value'=>'']` on **both** the guard path and the exception path (`DomainsPage.php:86–97`), rendered as a blank TXT row with no notification. A tenant is shown an empty record they are told to copy | tenant scope | **same defect** — the down case is one of the two paths that returns the blank row |
| `/studio/media` | **unexamined** | tenant scope | **unexamined** |
| `/studio/preview` | n/a | tenant scope | **unexamined** |

**"Unexamined" was the honest word and is now spent:** the walk ran on 2026-11-01 and every
*source down* cell has a measured value. `PanelWalkTest` is the instrument — the APP connection
is pointed at a **closed port** and its cached PDO dropped, so the next read fails to CONNECT.
That is the service being unreachable rather than a mocked exception, which is the same
distinction `bin/deps` draws between an audit that found nothing and one that could not run.

### 6.1a The walk, run — what every screen actually does with its database down

**All seven screens behave identically, and the uniformity is the first finding:**

| | measured |
| --- | --- |
| status | **503** on all seven — "come back", not "something broke". Already right. |
| content type | **`application/problem+json`** |
| body | `{"type":"https://qrpager.dev/problems/unavailable","title":"The service is temporarily unable to handle the request.","status":503,"instance":"<path>"}` — 157–172 bytes |
| disclosure | **NONE**, at `APP_DEBUG=true`: no SQLSTATE, no `QueryException`, no `PDOException`, no `qrpager_app`, no `qrpager_owner`, no libpq connect message, no stack trace, no framework path, no credential field name |

**The disclosure half is the clause `ProblemRenderer` exists for and it holds.** Measured at
`7f1c8f6`, before that renderer existed, the same failure produced an HTML page of **862,104
bytes** carrying the SQL and a role name. Nine needles were driven across all seven screens and
none appears.

**⚠ THE PRESENTATION HALF IS THE FINDING.** A panel screen is an HTML surface a person
navigates to, and it answers an RFC 7807 document — correct for `/v1`, **raw JSON in a
restaurant owner's browser**. Against `§6`'s third column: *"that it is us, not them, and
whether to wait or retry"* — the title says the first and **nothing says the second**, and
"temporarily unable to handle the request" is API vocabulary, not a sentence the reader `SR-2`'s
clause names would parse.

**FIXED in the same task.** `PanelProblemPage` renders the same problem as **HTML for panel
paths only** — 503 `text/html`, the type's own fixed title, and `§6`'s missing half: *"This is on
our side, not yours. Nothing you did caused it and nothing is lost. Wait a minute and reload this
page."*

⚠ **WHY THAT IS SAFE IS THE ONLY INTERESTING QUESTION ABOUT IT, and the answer is structural
rather than careful.** `ProblemRenderer` is registered for **all** requests deliberately, because
HTML was the worst leak — measured at `7f1c8f6`, an HTML-rendered `QueryException` was **862,104
bytes** carrying the statement and the bound uuid. So:

* the `Problem` is produced FIRST, by the same renderer, and only then handed to the page;
* **`PanelProblemPage` never sees the throwable.** It takes a `Problem` — a `ProblemType` case
  and a path — and the view takes four scalars from it. There is no code path from an exception
  to an HTML body;
* the disclosure sweep runs over the **HTML** body, with `Accept: text/html`, at
  `APP_DEBUG=true`. Proven by injecting `$error->getMessage()` into the response: the sweep
  reddens.

**And it introduces no new distinction.** `§4.2`: a refusal may say what to do, never what exists.
The title is the enum's own string, verbatim. The *what to do* sentence is chosen by **status
class** — one for 5xx, one for 4xx — never by reason, so a 404 and a 403 that already read alike
still read alike. A sentence per `ProblemType` case is how a friendly error page becomes an
oracle.

**Three things the content negotiation has to get right, each driven:**

| client | gets | why |
| --- | --- | --- |
| a browser on `/platform` | the HTML page | the reader this clause is about |
| Livewire (`Accept: application/json`, `X-Livewire: 1`, measured from `livewire.esm.js`) | the document | every panel write arrives at `POST /livewire-<hash>/update`; a page there shows the reader nothing |
| an XHR client sending `Accept: */*` | the document | ⚠ `*/*` **accepts html**, so `acceptsHtml()` says yes and only `expectsJson()` refuses it — removing that guard left every other assertion GREEN, which is the dead-control shape, so this row exists to separate them |
| anything on `/v1` | the document | `/v1` is not a panel path, so the spec's media type is unchanged |

The page depends on **no asset manifest, no stylesheet and no Livewire component** — asserted,
because the page that exists for things being unavailable must not need the thing that is most
often unavailable. Its colours, lengths and sizes come from `config('design-tokens.…')`; the seven
literals it does carry are declared and asserted as an exact set (two `0`s, two unitless
line-heights, `100vh`, the `1px` hairline, and `42rem` as the measure — the one value the token
set has no scale for).

### 6.1b And the walk found a `§12` breach nobody had looked for — fixed in the same pass

`§12`: *"a reviewer cannot name the UI framework from a screenshot."* **It was false in the one
place a screenshot always shows.** Every page of both panels rendered

```
<title>Media - Laravel</title>
```

because `Panel::getBrandName()` falls back to `config('app.name')`
(`HasBrandName.php:21`) and `.env.example` ships `APP_NAME=Laravel`.

**Fixed with `->brandName((string) config('app.brand'))` on both providers**, and `app.brand` is
a NEW key rather than a change to `app.name` for a measured reason: `config/cache.php:121`,
`config/session.php:132` and `config/database.php:175` each derive a **storage prefix** from
`app.name` through `Str::slug`, and `TwoFactorController` uses it as the **TOTP issuer label**.
Renaming it would move the session cookie, the cache prefix and the Redis prefix in one commit —
a deploy concern on surfaces Task 5 owns — in order to fix a title. **That coupling is itself a
defect and is recorded with an owner rather than fixed here.** "QR Pager" is not a new name:
`config/scramble.php` already calls the API *"QR Pager API"*, with the same reasoning written
beside it.

⚠ And `/studio/preview` carries the **tenant's** title, measured as `Tenant 01a19423` from the
fixture's own tenant name — correctly NOT our brand, because that page is the restaurant's. The
first version of the assertion required the brand on every titled page and reddened on exactly
that, which is why the subject list is now derived structurally (a `Filament\Pages\BasePage`
action) rather than from the route prefix.

### 6.2 The two breaches already located, and what they cost

Both are `§4.1`'s first half and both are in the ticket's own trap list, so neither is a
discovery — but both are now **confirmed in the source** rather than taken on trust:

1. `PlatformAuditResource.php:66` — `TextColumn::make('subject_id')->label('Subject id')`: a
   raw uuid7 on screen under a system-vocabulary label. Two breaches in one column.
2. `DomainsPage.php:86–97` — `R-154`'s blank TXT row, which is simultaneously the empty case
   and the source-down case.

### 6.3 What this task must not do when a screen cannot be fixed by copy

`§2`: a screen needing a new verb is **a finding with an owner and a date**. The candidate is
already visible — `§4.3` promises a create-restaurant wizard that has no route (SR-3). I will
not build it, and I will not quietly drop the inventory line either; the rank-3 correction and
the finding are both recorded.

---

## 7. Q7 — Filament 5 theming on Laravel 13, verified by running code

### 7.1 The stack, from the lockfile and the runtime

```
$ php -v | head -1
PHP 8.4.27 (cli) (built: Oct 21 2026 14:35:21) (NTS)
$ php artisan --version
Laravel Framework 13.38.0
$ php artisan filament:about
 Version .. v5.11.0      Views .. NOT PUBLISHED
 Packages filament, forms, notifications, support, tables, actions, infolists, schemas, widgets
$ php -r '…composer.lock…'
filament/filament   v5.11.0
livewire/livewire   v4.4.8
laravel/framework   v13.38.0
```

All ten `filament/*` packages are `v5.11.0`. **Views are NOT PUBLISHED**, which matters: the
restyle is a *theme*, not a fork of Filament's Blade — publishing views would make every
Filament upgrade a merge.

### 7.2 The theming mechanism, read from the command rather than from docs

```
$ php artisan help make:filament-theme
Usage: make:filament-theme [options] [--] [<panel>]
Options: --panel=PANEL  --pm=PM (npm, yarn)  --skip-install  --skip-build  -F|--force
```

From `MakeThemeCommand.php` — the three things it does and the two it asks you to do:

* writes `resources/css/filament/{panelId}/theme.css` from the `ThemeCss` stub;
* installs `tailwindcss@latest` and `@tailwindcss/vite`;
* builds the assets.
* **pending action 1:** add the path to `vite.config.js`'s Laravel plugin `input` array.
* **pending action 2:** register `->viteTheme('resources/css/filament/{panelId}/theme.css')`
  on the panel provider.

The stub, verbatim:

```
@import '{{ filamentThemeCssPath }}';
@source '../../../../app/Filament/{{ classDirectory }}**/*';
@source '../../../../resources/views/filament/{{ viewDirectory }}**/*';
```

⚠ **Both `@source` paths point at `app/Filament/…`, which this repository does not have** —
its panels live in `app/Features/Provisioning/Panel` and `app/Features/Landing/Panel`
(`deptrac`-enforced feature layout). So the generated stub's source globs match nothing here
and **must be corrected to the real panel directories, or the theme will not see the classes
it is supposed to scan.** That is a silent failure: Tailwind emits no utility for a class it
never saw, so the panel would simply render unstyled rather than erroring.

### 7.3 The current theming state of both panels, measured

```
$ grep -n 'viteTheme\|->theme(\|colors(\|font(' app/Features/*/Panel/*PanelProvider.php
```

**No `->viteTheme()`, no `->colors()`, no `->font()` in either provider** — only comments
explaining their deliberate absence, which is `§12`'s Tasks 6–7 exemption working as
intended. So:

* both panels serve Filament's **precompiled vendor CSS**;
* `apps/api/resources/css/app.css` — the `@import 'tailwindcss'` + `--font-sans` file —
  reaches **neither panel**; and
* 8b's first structural change is creating the two themes and registering them.

### 7.4 What `apps/api/resources/css/app.css` carries today, and why it is a second instance of trap 1

```css
@import 'tailwindcss';
@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';
@theme { --font-sans: 'Instrument Sans', ui-sans-serif, system-ui, …; }
```

It is a plain `@theme` that **sets one key and clears nothing**, so Tailwind's full default
palette, spacing and type scale are live in that build too — and it is the build the Laravel
app's own non-panel Blade uses. Trap 1 has two doors in `apps/api`, not one. Whichever way
SR-1 is ruled, both need the same treatment.

### 7.5 What is NOT verified in-session, stated rather than implied

* **No panel page has been rendered in a browser.** `apps/api/node_modules` is absent, so no
  panel theme has been compiled, and `R-118`'s four computed-style clauses are untouched.
  8a built the Playwright instrument those clauses need; pointing it at a Laravel-served,
  authenticated panel is a new harness and is implementation work.
* **The Tailwind used for every probe above, and for every figure in `SR-1`, is the
  workspace's 4.3.3** — not the `@tailwindcss/vite ^4` caret range `apps/api/package.json`
  would resolve, because `apps/api/node_modules` is absent. The probes are therefore evidence
  about Filament's CSS and Tailwind 4.3.3's behaviour, **not about the exact bytes the panel
  build will emit.** Re-running them against the installed panel toolchain is the first
  implementation step, and if the result differs the probe was wrong, not the plan.
  **This is why `§9.4` pins the panel's Tailwind to the workspace's exact version** rather
  than carrying a row: a caret range means the CSS measured here is not the CSS a fresh clone
  compiles.

---

## 8. What this session did not do, and why

**No implementation commit, and no branch.** `QRPAGER-23` is blocked by `QRPAGER-15`, and
`task-8a` is **not tagged** — `git tag -l 'task-8*'` is empty and the last tag is `task-7`.
`§6`'s order rule is that no task starts before the previous one is green **and tagged**, so
`task-8b-panel-restyle` is cut from the `task-8a` tag when it exists, never from a loose sha.

### 8.1 What `task-8a`'s tag is waiting on — and one item of it closed by measurement

`QRPAGER-15` comment `10044` lists three. The third was *"to be confirmed and stated, not
assumed"*, so it is confirmed here:

> *The three-run and fresh-clone figures are measured at `f7177ed` and `1b38260`, not at
> `d8c9652`. They describe the head only if every commit between them touches evidence files
> alone.*

**Measured, and the answer is yes for the frontend half and needed one extra check for the
PHP half.**

```
git diff --name-only 1b38260..d8c9652
  docs/PLAN.md
  docs/tickets/QRPAGER-15-fresh-clone.txt
  docs/tickets/QRPAGER-15-tag-message.txt
  docs/tickets/QRPAGER-15-three-runs.txt
```

Four files: `PLAN.md` and three evidence transcripts. **The set of `docs/` paths any gate step
reads** is `docs/contracts/dependency-licences.json`,
`docs/contracts/landing-page.schema.json`, `docs/tickets/QRPAGER-15.md`,
`docs/tickets/QRPAGER-15-contrast-table.md` (a `tokens:check` artefact) and `docs/PLAN.md`
(`composer guard` C7). **None of the first four was touched**, so the frontend figures —
`pnpm gate`, the three runs, the fresh clone — describe `d8c9652`.

`PLAN.md` **was** touched, and `composer guard` reads it, so that one was re-run at head
rather than reasoned about:

```
$ cd apps/api && composer guard; echo "exit=${PIPESTATUS[0]}"
repo guard: C1 and C2 hold across 362 file(s) in 6 path(s), C7 and rules 9-11 across 1 workflow(s)…
exit=0
```

Every other PHP gate step (`pint`, `stan`, `deptrac`, `openapi:check`, `landing:check`,
`contracts:check`, `php artisan test`) reads no `docs/` path, so a docs-only change cannot
move them. **So item 3 is discharged: the figures do describe the head.** Items 1 (the CI run
id) and 2 (one review round on the fix diff) stand, and neither is 8b's.

*(`${PIPESTATUS[0]}` rather than `$?`, because the command is piped — trap 6.)*

### 8.2 Trap 7, and the one deliberate deferral in this file

Trap 7 requires every table in the ticket and in `PLAN` to be **generated or gate-checked,
never typed**. The tables above are typed, and that is a stated deferral rather than an
oversight: a gate-check is a test, a test is an implementation commit, and this session is
barred from making one.

**What stands in for it until then:** every table above carries the command that re-derives
it, which is `PLAN §0`'s own standard — *a figure anyone can re-derive in one command is the
only kind this row should carry*. **The gate-check lands with the first implementation
commit**, covering the stack row, the artefact/import-path table and the route inventory,
each against its source, in the shape 8a's `ticket-tables.test.mjs` already established.

### 8.3 The §5 rows this task inherits

| row | what it is | state |
| --- | --- | --- |
| `R-118` | the **panel's** share of four computed-style clauses — focus ring, 44 px target, AA contrast, `prefers-reduced-motion` | 8a closed the kit's share; this is the rest. Its review-by names *"Task 9's Playwright harness as the instrument"* and 8a built that instrument, so the blocker is gone |
| `R-127` | `§4.3` lists the landing builder as a platform screen | 8b's. **Extended by SR-3** — four more inventory lines are wrong the same way |
| `R-154` | `/studio/domains`' blank TXT row | 8b's, and **confirmed in the source** (`DomainsPage.php:86–97`). Squarely `§4.1`'s first half |
| `R-141` | `SvgPolicy`'s false-refusal rate against real design-tool exports is unmeasured | **not 8b's work.** It needs real exports from tools not installed here, and measuring it changes no presentation or copy. Stays as the row describes |
| `R-156` · `R-157` | Task 7 process records | **Nothing to do, and I had this wrong on the first pass.** I read `R-157`'s risk column — *"`§2.5` and `§2.11` both still attributed 256 KB to the CONSTRAINTS"* — and wrote it up as a live `PLAN §2` inaccuracy needing a rank-3 fix. Its *control* column says it was **corrected in the same pass that wrote the row**, and `PLAN §2.5` and `§2.11` both now state both numbers with the mechanism that carries each, pinned by `LandingTableContractTest` at 384 KB and `ReviewFixesTest` at 256 KB. Both rows record a **process** failure, not a standing defect. Reading a row's risk without its control is how a closed row gets reopened as work — the same shape `R-003`'s missing closure record cost this project once |

---

## 9. Four things the reading measured that are now 8b scope

Ruled into scope 2026-10-31. Each was found by this session's reading rather than by a review, and each
is recorded with its measurement so the implementation commit inherits the evidence and not a summary.

### 9.1 BLOCKING for 8b — the panels load a third-party CDN font, and it is Filament's default face

```
$ grep -n "bunny(" apps/api/vite.config.js
            bunny('Instrument Sans', { weights: [400, 500, 600] }),
$ grep -n 'font-sans' apps/api/resources/css/app.css
    --font-sans: 'Instrument Sans', ui-sans-serif, system-ui, …
```

`laravel-vite-plugin`'s `bunny()` helper sources that family from **fonts.bunny.net**. Three separate
clauses, not one:

* **a third-party request on the panel surface** — against the approved direction's self-hosting and
  against `§12`'s reasoning (someone else's uptime, and a per-visitor IP disclosure);
* **off-direction** — the approved families are Plus Jakarta Sans (Latin) and Tajawal (Arabic), self-hosted
  from `packages/tokens`;
* **`§12`'s "a reviewer cannot name the UI framework from a screenshot"** — Instrument Sans is the Laravel
  starter-kit face, so it is a framework signature in the one channel a screenshot shows most plainly.

**The fix is `->font()` on each provider plus the self-hosted faces, not a CSS edit:** Filament's own
`@theme inline` sets `--font-sans: var(--font-family), …`, and `--font-family` is what `->font()` writes.

**And the assertion is widened so the next one cannot enter the same way.** 8a's `fonts.test.ts` already
greps for `fonts.bunny.net` by name — but over a file list of **four workspace stylesheets**, which is why
`apps/api` was never in scope for it. Widening that list to cover the panel build is **extending an
existing assertion, not narrowing one**, so `§8` wants no declaration; it is stated here anyway so a
reviewer reads it before the diff.

### 9.2 `design-tokens.php` is guarded only by the job that does not consume it

Measured in §2: the PHP config is byte-diffed by `pnpm tokens:check`, a **frontend-job** step, and
`apps/api/bin/gate` does not run it. **A staleness check that does not run on the side that consumes the
artefact** is a gate clause with a hole in it: a panel change needing a token change is caught by CI and
not by a local `composer gate`.

**Ruled: `composer gate` runs it, and `ci-parity` covers that both ways** — which 8a's parity test already
does for the PHP gate against the `gates` job, so the shape exists and this is one step added to both
sides of it.

### 9.2a ⚠ AND THE SAME SHAPE ONE LAYER OVER, FOUND BY RUNNING THE §11 CHECKLISTS ON MY OWN DIFF

**Security checklist item 7 (*"any new package: license, maintenance status, known CVEs"*) against
`task-8b-precoding`'s own commits.** Committing `apps/api/package-lock.json` pinned **85 installed
packages** into the repository — and they are covered by **neither** of 8a's two dependency controls:

| control | what it walks | sees `apps/api/node_modules`? |
| --- | --- | --- |
| `pnpm audit` (gate step 7, CI step) | the pnpm workspace | **No** — `apps/api` is deliberately not a workspace member (`ADR-003`) |
| `licences.test.mjs` | `node_modules/.pnpm` | **No** — that path is the pnpm store |

```
$ grep -n 'apps/api' pnpm-workspace.yaml
2:# `apps/api` is the Laravel half and is deliberately NOT a workspace member
$ grep -n "node_modules" tools/gate/test/licences.test.mjs | head -1
48:  const store = join(REPO, 'node_modules', '.pnpm')
```

**This is `§9.2`'s shape one layer over:** there, a staleness check did not run on the side that consumes
the artefact; here, the **dependency controls do not run on the side that has the dependencies.** And it
is `SEC H-2`'s shape from 8a exactly — *the absent control was the durable part*.

**Measured now, so the finding is about the control and not about a live exposure:**

```
$ cd apps/api && npm audit
found 0 vulnerabilities
```

| licence family | count | against 8a's inventory |
| --- | --- | --- |
| MIT | 71 | `permissive` |
| ISC | 7 | `permissive` |
| Apache-2.0 | 2 | `permissive` |
| **MPL-2.0** | 2 | `dispositioned` — `lightningcss`, already carried for the same reason |
| BSD-3-Clause | 1 | `permissive` |
| 0BSD | 1 | `permissive` |
| **`(MIT OR CC0-1.0)`** | 1 | **in neither map** — `type-fest@5.10.0`, a dual licence whose *both* halves are known (MIT permissive, CC0-1.0 dispositioned), so it needs a **composite-string disposition**, not a decision |

**No CVE, no refused licence, no secret** — the lockfile's only URLs are `registry.npmjs.org`,
`github.com`, `opencollective.com` and `tidelift.com`, and it carries no `_auth`, `_authToken`, `password`,
`token` or `username` key (`grep -c` → 0).

**One drift worth naming:** `lightningcss` is **1.32.0** in the panel tree and the workspace carries
**1.32.0 and 1.33.0**. Two trees, two resolutions of one transitive dependency — harmless today
(build-time CSS tooling, MPL-2.0 dispositioned either way) and exactly why `§9.4` pins Tailwind rather
than trusting a range.

**So the implementation commit owes, as part of `§9.2`:** `npm audit` as a step of `composer gate` and of
CI's `gates` job with its output committed; `licences.test.mjs` extended to enumerate **both** trees, with
a composite-licence rule (a string of the form `(A OR B)` is acceptable only when **every** alternative is
in a map); and the `(MIT OR CC0-1.0)` disposition written. Covered by `ci-parity` both ways, like the
staleness check beside it.

**And a decision this hands back:** the lockfile could instead be **un-committed**, which closes the gap
by removal and returns `apps/api` to resolving its ranges at install time. I argued for committing it —
*a pin without a lockfile is half a pin* — and that argument is unchanged, but it is the reason the gap
exists, so it is named here rather than defended silently.

### 9.3 The theme stub's `@source` globs point at a directory this repo does not have

```
# vendor/filament/filament/stubs/ThemeCss.stub
@source '../../../../app/Filament/{{ classDirectory }}**/*';
@source '../../../../resources/views/filament/{{ viewDirectory }}**/*';

$ ls apps/api/app/Filament
ls: cannot access 'apps/api/app/Filament': No such file or directory
```

The panels live in `app/Features/Provisioning/Panel` and `app/Features/Landing/Panel` (the `deptrac`-
enforced feature layout). **An unscanned class emits no utility, so the failure is a silently unstyled
panel rather than an error** — the worst available shape, because the build goes green.

**Ruled: guard it.** Assert the **compiled** panel CSS contains a rule for classes the panels actually
use, and prove the guard by pointing a glob at nothing — so the proof is that the assertion *fails* when
the globs are wrong, not that it passes when they are right.

### 9.4 `apps/api`'s Tailwind is a second install — my call is to pin it, with the measurement

```
$ grep -E '"(tailwindcss|@tailwindcss/vite|vite)"' apps/api/package.json
    "@tailwindcss/vite": "^4.0.0",
    "tailwindcss": "^4.0.0",
    "vite": "^8.0.0"
$ grep -E '"(tailwindcss|vite)"' package.json          # the workspace, save-exact
    "vite": "8.3.5",
    "vitest": "5.0.5"
$ ls node_modules/.pnpm | grep -E '^tailwindcss@'
tailwindcss@4.3.3
$ ls apps/api/node_modules
ls: cannot access 'apps/api/node_modules': No such file or directory
```

**The measurement that decides it:** the workspace pins `tailwindcss` to an exact **4.3.3** (`.npmrc` is
`save-exact=true`), `apps/api` carries a **caret range** `^4.0.0`, and `apps/api/node_modules` is
**absent** — so the panel theme has never been compiled on this machine by the toolchain that will compile
it. **Every figure in SR-1 was measured with the workspace's 4.3.3**, which means a caret range makes the
panel CSS I measured not the panel CSS a fresh clone produces. That is the fresh-clone class, and 8a
already paid for it twice (the `.gitignore` guard's floor, and `mawk` versus `gawk`).

**My call: pin it, do not carry a row — and the call was confirmed and the pin is DONE.**
`apps/api/package.json` now pins both to the workspace's exact version:

```
$ grep -E '"(tailwindcss|@tailwindcss/vite)"' apps/api/package.json
        "@tailwindcss/vite": "4.3.3",
        "tailwindcss": "4.3.3",
$ cd apps/api && npm install && node -p "require('./node_modules/tailwindcss/package.json').version"
4.3.3
```

A `§5` row would carry a known drift forward for the life of the project when the fix is one line and the
check is three; the `playwright` precedent in 8a is the same argument (*"its browser build is tied to the
package version, so a range re-downloads a different Chromium"*).

**What the pin was actually worth, measured rather than asserted:** `^4.0.0` resolves to **4.3.3 today**,
so the caret was **not wrong — it was unpinned**, and every `SR-1` figure happened to be measured against
the version the panel would have resolved anyway. `SR-1`'s re-measurement confirms the two toolchains are
**byte-identical**, including `index.css` and `dist/lib.mjs` compared with `cmp`. So the pin changed no
figure; it changed whether the figures survive the next `npm install`, which is the whole content of
`§6`'s fresh-clone clause.

**Still owed by the implementation commit:** the gate-checked assertion that **the two `package.json`
files name the same `tailwindcss` version**. One source for the toolchain, mechanically — without it the
pin is a fact about today rather than a control.

**`vite` is deliberately NOT pinned**, and the asymmetry is a decision: `apps/api`'s Vite builds the
Laravel assets and the workspace's builds the Next apps; they are genuinely separate toolchains that need
not move together. Tailwind is different because **one `@theme` block must mean the same thing in both
builds**.

**What I am not deciding:** whether `vite` should be pinned the same way. `apps/api`'s Vite builds the
Laravel assets and the workspace's builds the Next apps; they are genuinely separate toolchains and a
shared pin would couple two things that need not move together. Tailwind is different because **one
`@theme` block must mean the same thing in both builds**. Stated so the asymmetry is a decision and not
an oversight.

---

## 10. The branch, and the one explanation its history gets

**Ruled 2026-10-31, twice, and the second ruling supersedes the first.**

**First:** the deviation of holding this work on `task-8b-precoding` rather than `main` was *"accepted and
is better than my instruction"* — `main`'s `PLAN.md` is 81 commits behind the live one, so it is the wrong
base for a state-file edit. The `main` cherry-pick and revert are **dropped, moot**; `c6fbba0` stays on
`main` as the record of where the `§3` answers were first written.

**Second, and this is the one that let the task start:** `§6`'s order rule is deviated from —
**8b proceeds in parallel with the one outstanding review round on 8a's fix diff**, rather than waiting
for the tag. The ruling, its reason (speed, chosen knowingly), its single cost (if the fix-diff review
forces a code change in 8a, this branch rebases) and the condition that makes it safe are recorded in
**`PLAN §0.0`**, committed before the first implementation commit.

**The condition, because it governs every commit on this branch:** never edit a line that
`a1f1263..d8c9652` changed. Additive only — new gate steps, new `ARTEFACTS` entries, new assertions, new
files. For the 67 files that diff touched, and especially for the ones it **created** (where every line is
a changed line), the move is a **sibling file**, not a modification. A scope item that cannot be done
additively is a stop-and-report.

**The sequence, pending only on the tag:**

1. `task-8a` is tagged at **`d8c9652`** — CI run **#151** reports SUCCESS for that sha, so `§5`'s CI
   clause is discharged for it; what remains is the fix diff's first review round.
2. **`task-8b-panel-restyle` is cut FROM THE TAG.**
3. These commits **fast-forward onto it — no rebase**, because the base is the same commit:

```
$ git merge-base task-8b-precoding origin/task-8a-design-system
d8c96521baf033e2e70b9b4feb8a45d61c1e3e8d          # == 8a's head == the expected tag commit
```

4. **The `task-8b-precoding` name is retired.** It was a holding name for the interval between the `§3`
   answers and the tag, and it means nothing after step 3.

**Why this is written down rather than left to the moment.** A branch that appears, carries a task's work
and vanishes is the kind of history that gets reconstructed wrongly a month later. One explanation, here,
so the name needs no archaeology: it exists because the `§3` answers had to be committed before the tag
existed, the state file they edit lives on an unmerged branch, and a ruling then let the implementation
proceed on the same base rather than wait.
