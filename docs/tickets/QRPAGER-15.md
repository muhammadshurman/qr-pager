# QRPAGER-15 — Task 8a: workspace, approved direction, tokens, UI kit, landing templates

| | |
| --- | --- |
| Ticket | `QRPAGER-15` (Task 8a). Task 8b is `QRPAGER-23` and is not this task. |
| Branch | `task-8a-design-system` |
| **Base commit** | **`049934b8d95c64742a667597adbacd65b45909b7`** — see §0.1. Reviewers diff `049934b...task-8a`. |
| Approved direction | **Direction B (SERVICE)**, approved in writing by Muhammad 2026-10-31, `QRPAGER-15` comment *"§2 APPROVAL GATE MET"*. Direction A (SIGNAL) is rejected; no component is written against it. |
| Named invariant (§11) | `QRPAGER-15 §5.1`: *no component carries a hard-coded colour, spacing or font size, and no tenant-supplied theme value can reach the order-ready screen's colour pair.* |

This file answers the seven `§6` pre-coding questions. It is committed **before any
implementation commit**, which is what `§6` and the ticket's `§4` require. It restates no
rule from `PROJECT_INSTRUCTIONS.md` or `PLAN.md`.

---

## 0. Stop-and-reports raised before the work started

`§0` says never silently resolve a conflict. Four were raised at the start of this task;
all four were ruled on by Muhammad on 2026-10-31 **before** the first commit.

### SR-1 — the branch point was wrong, and it would have made a green gate certify a known defect

The rank-1 prompt said `task-8a-design-system` off `task-7` **at `61b220f`**. Measured, that
tree:

* does **not** carry `PLAN.md`'s 2026-10-31 clauses — `grep -c 'STATE AS OF 2026-10-31'`
  returns **0** at `61b220f` and **1** at `049934b`, a difference of **165 lines** in
  `docs/PLAN.md`;
* does **not** carry the studio upload fix, which `PLAN §0` records as *"never an accepted
  risk"*;
* **does** carry `RouteSurfaceTest`'s assertion that the Livewire `upload-file` 404 is
  correct behaviour — the exact assertion `PLAN §0` records as the defect.

So 8a's own green gate would have certified it, and 8a's rank-3 edits to `§0`/`§1` would
have landed on a record 165 lines behind the live one.

**Ruled:** re-cut 8a onto `fix-studio-upload-endpoint`. `§6`'s order rule is satisfied —
`task-7` is green and tagged at `61b220f`, and the base is a descendant of that tag.

### SR-2 — the prompt's own order of work contradicted its closing line on the tag

Prompt item 9 said *"Tag `task-8a` and push it"*; the prompt's closing line said *"When the
gates are green, post the evidence and STOP"*, with QA and Security after. `§11`'s merge
rule, `.claude/commands/task.md` (*"Do not tag `task-N`"*) and this ticket's own `§7`
ordering all put the tag after both reviews.

**Ruled 2026-10-31:** item 9 is **corrected in place** — the tag moves to **after** the two
reviews and after every Critical and High is fixed with a regression test. Three reasons, in
Muhammad's order of weight: (1) `§11` says it outright and `task-6`/`task-7` are the
precedent; (2) the tag **message** cannot be final before the reviews, because it must name
every unmet clause with its `§5` row, owner and date, and those rows do not exist until the
Medium and Low findings are dispositioned; (3) `§10` makes the tag the delivery record and
there is no PR behind it, so a tag at the green gate would point the delivery record at a
commit a review may then prove carries a Critical, with nothing downstream to catch it.

So: **gates green → evidence posted → branch pushed → STOP.** The tag message is drafted as
a committed file and finalised after the dispositions.

### SR-3 — the dark theme does not derive from the approved eleven values

See `§2.2`. Raised, measured, and ruled with seven conditions.

### SR-4 — `R-073`'s decision window had passed

`PLAN §0 › Blocking anything?` and `§5 R-073 › Review by` both make it Muhammad's decision
**"before Task 8 starts, not after"**: whether to buy back the iOS Web Push risk with a
minimal Home-Screen install harness in Task 8. Nothing in the 8a prompt or ticket recorded
it as taken, and Task 8a had started.

**Measured, so the question carried evidence rather than a quote.** Across `apps/`:
`beforeinstallprompt` **0**, `display: standalone` **0**, web app manifest **0**, service
worker **0** (the 14 `manifest.json` hits are Laravel's Vite build manifest).
`WebPushDispatcherTest` exists with 8 cases against a recorded PSR-18 edge and pins
`aes128gcm` over the library default, exactly 404/410 as a dead subscription, payload
encryption, `Topic`/`TTL`/`Urgency`, the exact endpoint and no redirect followed. **So what
an iOS device would receive is specified and tested; whether it arrives is untested, and the
install path exists in no form in either half of the repo.**

**Ruled 2026-10-31: a separate ticket, neither 8a nor 8b.** Both of those were rejected on
the same ground — `QRPAGER-23 §2` excludes *"Customer PWA screens → Task 9"* in the same
words 8a's `§3` does, so a harness in 8b breaks 8b's scope exactly as much as one in 8a
breaks 8a's. Leaving it to Task 9 was also rejected: the premise carries three built tasks
and three unbuilt ones. The new ticket is **blocked by `QRPAGER-15`** and **blocks Task 9
(`QRPAGER-16`)**; scope is one installable route in `apps/web` — manifest with
`display: standalone`, a service worker with a push handler, a subscribe button driving Task
4's real dispatcher — **unstyled**, no designed screens, no copy, no tokens. The precedent is
this repo's own: Task 5 built *"a minimal unstyled harness page purely to exercise the
WebAuthn browser API"* with the designed screen left to Task 10.

**Precondition (b), answered in-session and verified rather than recalled:** the harness
needs an HTTPS origin reachable from the phone, because iOS will not install or subscribe
over plain `http` and `localhost` is not reachable from the device. **`cloudflared` quick
tunnel** — **Apache-2.0** (GitHub API, `cloudflare/cloudflared`), actively maintained
(pushed `2026-10-30`, release `2026.10.7`, not archived), and **no account or domain**, in
Cloudflare's own words. Not currently installed; neither is `ngrok`, `localtunnel`,
`tailscale` or `bore`. `ngrok` and `tailscale funnel` were rejected for requiring an
account. **The operational constraint that bites:** the quick-tunnel hostname is random per
run and the tunnel dies with the process, while a Home-Screen-installed PWA pins its origin
and a push subscription is origin-bound — so **install → permission → subscribe → receive
must all happen inside one `cloudflared` session**. Cloudflare's *"no SSE"* caveat does not
bite: `O2` removed the SSE hub.

iOS requirements, from [WebKit's own announcement](https://webkit.org/blog/13878/web-push-for-web-apps-on-ios-and-ipados/)
and [Apple's WWDC23 session](https://developer.apple.com/videos/play/wwdc2023/10120/):
**iOS/iPadOS 16.4+**, manifest `display: standalone` (`fullscreen` falls back to it,
`minimal-ui` becomes a browser shortcut), Home Screen install required — Web Push is
unavailable in a Safari tab — permission from a user gesture, secure context, and **no Apple
Developer Program membership**. The server must reach `*.push.apple.com`.

### 0.1 The base commit, and why it is under no tag

**`049934b8d95c64742a667597adbacd65b45909b7`**, the tip of `fix-studio-upload-endpoint`.
Measured: identical local and origin (same tree sha), a descendant of the `task-7` tag by
exactly three commits (`d7b1269`, `f0ab88d`, `049934b`), and **under no tag**. It is the only
tree in the repository carrying all three of Task 7's code, the upload fix, and the current
docs; `task-7` itself carries the first only.

**Read by tags alone the chain is `task-7` → `task-8a` with `049934b` under none**, which is
why it is stated here and in `PLAN §0` rather than left to be rediscovered.

No inherited-fix commit was manufactured: `d7b1269` is already an ancestor of the base, so
there was nothing to carry forward.

**Baseline before the first 8a commit**, so a later red is attributable to 8a rather than
inherited: `docs/tickets/QRPAGER-15-baseline-gate.txt`. **Two runs, and the first was red.**

* **Run 1** — `php artisan test`: **1089 passed, 1 failed**, 8734 assertions, 241.27s. The
  failure is **`R-092` fault two by exact identity** —
  `OutboxWorkerTest › it sends across tenants in one pass`, `claimed = 0` where 2 was
  expected. `R-092` names that test and that case verbatim and records the mechanism as
  **not established**; this task has not established one either and does not claim to. The
  file passes in isolation (14/14, 3.66s), so it is order-dependent, which is what `R-092`
  already says.
* **Run 2** — `composer gate`: **all 7 steps green, 1090 passed**, 8737 assertions,
  298.05s, exit 0.

**So the honest baseline is green, with one known intermittent red in the claim-shortfall
family.** `R-092` measured 7 green / 3 red over ten runs at `056d7a1`; 1 red then 1 green is
consistent with that and establishes nothing new. The working rule for this branch is
`R-092`'s own, as agreed for Task 5: a red that is `08006` or the claim-shortfall family does
not block the branch, every other red does, and every full-suite run is kept with its
identity so a new symptom is a new identity rather than a shrug.

---

## 1. Q1 — tables, columns, roles, GUCs

**None. This task touches no database at all, and that is stated explicitly rather than
skipped.**

* **No migration.** No table is created, altered or dropped. `PLAN §2`, the data contract, is
  unchanged by this task and needs no `§6.1` update-first pass.
* **No column, no index, no constraint, no trigger, no policy, no grant.**
* **No role.** Neither `qrpager_owner`, `qrpager_app` nor `qrpager_worker` is used, and no
  new role is introduced.
* **No GUC.** Neither `app.tenant_id` nor `app.bypass_tenant` is read or written (C1), and no
  new `app.*` name is introduced. Nothing in this task calls `set_config` in any form, so C2
  is not engaged.
* **No Redis key.** `PLAN §2.13`'s keyspace is unchanged.
* **One file lands under `apps/api`, and it is generated config, not schema:**
  `apps/api/config/design-tokens.php` (D5). It is a flat PHP array of token values. It is
  consumed in 8b and read by nothing in this task but its own staleness check.

The one thing this task does that touches the PHP half's behaviour is **add gate steps** —
`composer contracts:check` and the token staleness checks — which fail the build when a
generated artefact is stale. They read files; they open no connection.

---

## 2. Q2 — shared contracts: one source each, and the staleness check for each

Four generated artefacts, two sources, and **no artefact is hand-written**.

### 2.1 The four artefacts

| # | Artefact | Its **one** source | Generated by | Staleness check | Consumers |
| --- | --- | --- | --- | --- | --- |
| 1 | `packages/contracts/src/generated/status.ts` | the PHP enums in `apps/api/app/Domain` | `php artisan contracts:export` | **`composer contracts:check`** — byte diff, in `bin/gate` and CI's PHP job | `@qrpager/contracts` → `apps/web`, `apps/site`, `packages/ui` |
| 2 | `packages/tokens/generated/tokens.css` | `packages/tokens/src/source.ts` | `pnpm --filter @qrpager/tokens generate` | **`pnpm tokens:check`** — byte diff, in CI's frontend job | `apps/web`, `apps/site` import it directly; **Filament in 8b** (D5) |
| 3 | `apps/api/config/design-tokens.php` | `packages/tokens/src/source.ts` | the same generator | the same `pnpm tokens:check` | **Filament in 8b** (D5) |
| 4 | `packages/tokens/generated/tokens.ts` | `packages/tokens/src/source.ts` | the same generator | the same `pnpm tokens:check` | `@qrpager/tokens` → `packages/ui`, `apps/web`, `apps/site` |

**The direction of each generator is the direction of its source, which is why there are
two.** The status machine lives in PHP, so PHP generates the TypeScript — a Node script
parsing PHP would be a second reader of the same contract. The token set lives in
TypeScript, so Node generates the PHP config — an artisan command would have to parse TS for
the same reason. Each artefact is emitted by the language that owns its source and diffed by
the gate that language runs in.

**Each of the four is proven to fail on a deliberately stale file**, which is the clause
`§6` states and the shape `openapi:check` and `landing:check` already established in this
repo.

### 2.2 The token source, and the dark theme — SR-3, ruled with seven conditions

**Raised:** the approved comment fixes eleven values. The dark theme needs roles none of them
can fill. Measured by WCAG 2.1 relative luminance:

| dark role needed | nearest approved value | measured | verdict |
| --- | --- | --- | --- |
| `surface` — a card on the dark ground | `ink` `#10231B` on `ready-field` | **1.05:1** | invisible; no ladder exists in the approved set |
| `hairline` | `ink-mid` `#5A6A62` | **3.01:1** | reads as a solid rule, not a hairline |
| `ink-mid` — secondary text | `ink-mid` `#5A6A62` | **3.01:1** | fails AA 4.5:1; the next value up is 12.06:1 |

**Ruled 2026-10-31: the computed mix ladder is approved, with seven conditions**, each of
which this task implements:

1. **The derivation lives in `packages/tokens`, never the result.** Each dark role is stored
   as a function of two approved tokens and the generator emits the hex. A committed dark hex
   that would survive a change to `paper` is a second source in disguise (`§6.2`).
2. **The colour space is stated in the token source:** the mix is **component-wise linear
   interpolation on 8-bit sRGB channels, rounded half-up**. sRGB and OkLab give different
   hexes for the same percentage, so a percentage without the space is not reproducible.
3. **`ink-mid` is raised until the generated value clears ≥ 4.6:1.** The approved 4.52:1 was
   a 0.02 margin over AA and moves with the mix implementation — `F10`'s class. It is raised
   past that, and against the **hardest** dark background rather than the ground alone.
4. **Every derived value's ratio is asserted against the generated output**, so the test
   fails when a mix percentage moves. The table below is a measurement, not coverage.
5. **The order-ready screen reads no part of the ladder.** It is outside theming (D4), so its
   pair is `ready-field` + `paper` + `signal` in both themes, and a test asserts the pair is
   identical under light and dark.
6. **`border-strong`, a new role, in both themes.** A border that is the only visual boundary
   of an interactive control must reach 3:1 against its adjacent colour (WCAG 1.4.11).
   Measured: `hairline` `#D3DAD2` on `paper` is **1.29:1** and the derived dark hairline is
   **2.08:1** — neither qualifies. So **`hairline` is decorative separation only, in both
   themes**, and `border-strong` is derived by the same mix mechanism for input and control
   boundaries.
7. The ladder and `border-strong` are recorded in `PLAN §1`'s decision log with their
   derivations, and the final generated hexes and ratios are reported so the `QRPAGER-15`
   approval comment can be amended to carry them.

### 2.3 The generated token set — final values, and the ratio for each

Every dark value is a mix of two **approved** tokens. Nothing invents a hue.

**⚠ THREE CELLS OF THIS TABLE WERE SUPERSEDED AND ARE CORRECTED IN PLACE (QA #6).** It
carried `border-strong`'s **first** solution — light `#7D8981` at 44% and dark `#798580` at
48%, the pair that cleared 3:1 by 0.02 and 0.01 — and dark `alert-ink` as `= alert-fill`
`#E4572E`, **which is the shipped AA breach the generated table then found at 3.78 and
3.13**. All three were replaced in the code the same day and this table was not updated, so
it contradicted its own ratios table twenty lines below. Hand-typed, read by nothing: the
fifth instance of the shape in this round.

**`tools/gate/test/ticket-tables.test.mjs` now checks every cell below against
`resolveColour()` and `describeRule()`**, so a value corrected in the source and not here
reddens the gate.

⚠⚠ **THESE ARE RAIL'S VALUES, AS OF 2026-11-08 (`QRPAGER-35`), NOT THE ONES TASK 8a
DELIVERED.** Task 8a approved and shipped **Direction B (SERVICE)**; Rail superseded its
eleven colours on 2026-11-07. This table is updated in place rather than left stale for one
reason: `ticket-tables.test.mjs` holds every cell of it to `resolveColour()`, and a
hand-typed table that disagrees with the source is the exact defect that test was built
after — *"five record findings were one defect"*, above. **The delivery record is not
touched by this: the tag `task-8a` carries this file as Task 8a shipped it**, with Direction
B's eleven, and `git show task-8a:docs/tickets/QRPAGER-15.md` is the way to read it.

Three cells moved for reasons beyond the colour swap and are worth naming here: **dark
`brand` was `= signal` (approved) and is now `= paper`** — Rail reserves the accent for the
ready state, so a dark primary action is a paper fill, not a green one; **dark `alert-fill`
was `= alert-fill` (approved) and is now a mix**, because Rail's muted oxblood is too dark
to carry its own label on the dark ground (1.67:1 against a 3.0 bar); and **dark
`alert-ink`'s mix rose from 37% to 57%** for the same reason.

| role | LIGHT | DARK | light derivation | dark derivation |
| --- | --- | --- | --- | --- |
| `paper` | `#FCFCFA` | `#0A1F17` | `= paper` (approved) | `= ready-field` (approved) |
| `surface` | `#FFFFFF` | `#1D3129` | `= surface` (approved) | `mix(ready-field, paper, 8%)` |
| `surface-2` | `#F7F7F3` | `#2C3E37` | `= surface-2` (approved) | `mix(ready-field, paper, 14%)` |
| `hairline` | `#DFDFD7` | `#44544D` | `= hairline` (approved) | `mix(ready-field, paper, 24%)` — **decorative only** |
| `border-strong` | `#828382` | `#838E89` | `mix(hairline, ink, 47%)` | `mix(ready-field, paper, 50%)` |
| `ink` | `#191C22` | `#FCFCFA` | `= ink` (approved) | `= paper` (approved) |
| `ink-mid` | `#6B6F7A` | `#ABADB2` | `= ink-mid` (approved) | `mix(ink-mid, paper, 44%)` |
| `brand` | `#191C22` | `#FCFCFA` | `= brand` (approved) | `= paper` (approved) |
| `signal` | `#18C880` | `#18C880` | `= signal` (approved) | `= signal` (approved) |
| `alert-fill` | `#8C2F27` | `#B37771` | `= alert-fill` (approved) | `mix(alert-fill, paper, 35%)` |
| `alert-ink` | `#8C2F27` | `#CCA49F` | `= alert-ink` (approved) | `mix(alert-fill, paper, 57%)` |

**`ready-field` is no longer a row here**, and that is the redesign rather than an omission:
the order-ready colours stopped being theme roles (defect 1) and live in the `--ready-*`
namespace, emitted once outside both theme blocks. `§6.1`'s table is where they are listed.

**⚠ THE BARS TABLE IS NOW GENERATED, AND THAT IS A CORRECTION. The first version of this
section covered `ink`, `ink-mid` and `border-strong` only, and three defects hid behind the
roles it did not list** — dark `alert-ink` failing AA on two of three dark backgrounds,
`border-strong` clearing its bar by 0.02 and 0.01, and the danger button's label on a fill,
a shape no role-against-background table has a row for at all. The table below is quoted
from **`packages/tokens/generated/contrast.md`**, which the generator emits and
`pnpm tokens:check` keeps current; **42 pairs, 0 failures**.

**Figures are TRUNCATED at two decimals, never rounded up**: every threshold is a minimum,
so flooring can only understate compliance while rounding up can overstate it. Every
derived role is solved against **bar + 0.10** headroom, because a value clearing a minimum
by 0.01 moves with the mix implementation.

| role | bar | LIGHT paper / surface / surface-2 | DARK paper / surface / surface-2 |
| --- | --- | --- | --- |
| `ink` | ≥ 7.0 | 14.85 / 16.42 / 13.63 | 15.54 / 12.60 / 10.43 |
| `ink-mid` | ≥ 4.5 | 5.16 / 5.71 / 4.74 | 6.94 / 5.62 / 4.65 |
| `alert-ink` | ≥ 4.5 | 6.73 / 7.44 / 6.17 | 7.18 / 5.82 / 4.82 |
| `brand` | ≥ 4.5 | 5.87 / 6.50 / 5.39 | 9.72 / 7.89 / 6.53 |
| `border-strong` | ≥ 3.0 | 3.53 / 3.90 / 3.24 | 4.79 / 3.88 / 3.21 |
| `hairline` | — decorative | 1.28 / 1.42 / 1.18 | 2.07 / 1.68 / 1.39 |

**Text ON a fill** — the shape the first table had no row for:

| pair | bar | LIGHT | DARK |
| --- | --- | --- | --- |
| `surface` on `brand` | ≥ 4.5 | 6.50 | 7.89 |
| `surface` on `alert-ink` | ≥ 4.5 | 7.44 | 5.82 |
| `surface` on `alert-fill` | ≥ 3.0 | 3.68 | 3.78 |

`surface` on `alert-fill` is **3.68** and the approval allows that token for *"fills and
≥ 24px text only"* — so it is a pass for a 24px label and a **failure** for the danger
button's `--size-16` one. The button's fill is `alert-ink`; both tokens are approved and
only the PAIRING changed. The last row stays in the table to keep `alert-fill`'s own bound
stated.

**The three order-ready ratios, quoted from the generated table — arithmetic, no browser.
⚠ 15.54 / 9.72 / 9.72 AND NOT 15.55 / 9.73 / 9.73:** the raw values are 15.545990… and
9.729662…, which the prompt and the approval comment both carried in their rounded-up form.
All three clear AAA's 7.0 by a wide margin either way, so no conclusion changes — but a
published figure that rounds up against a minimum is exactly what the convention removes.

| pair | ratio | bar |
| --- | --- | --- |
| `--ready-ink` on `--ready-field` | **15.54:1** | AAA ✅ |
| `--ready-signal` on `--ready-field` | **9.72:1** | AAA ✅ |
| `--ready-field` on `--ready-signal` | **9.72:1** | AAA ✅ |

They differ from the rank-1 prompt's three figures by one hundredth each, in the only direction the convention allows.

### 2.4 Two figures in the approval comment that my arithmetic does not reproduce

Reported rather than silently adjusted, and neither changes a conclusion the approval drew.

| pair | approval comment | computed here | note |
| --- | --- | --- | --- |
| `ink` on `paper` | 14.86:1 | **14.85** | 14.8528 — rounds up in the comment |
| `alert-fill` on `paper` | 3.44:1 | **3.32** | a real difference; raw 3.329999… . The comment's own claim that the fill fails 4.5:1 as text holds either way |
| `paper` on `ready-field` | 15.55:1 | **15.54** | raw 15.545990… |
| `signal` on `ready-field` | 9.73:1 | **9.72** | raw 9.729662… |
| `signal` on `paper` | 1.60:1 | **1.59** | raw 1.597793… |

**All five are the same defect: a figure typed by hand and rounded up against a minimum.**
That is why `generated/contrast.json` now exists and why every test and document quotes it
rather than anybody's arithmetic.

### 2.5 `packages/contracts` — the generated TS status mirror

Source: the PHP enums, which are already singular and already carry their transition tables.
The mirror is emitted for `PagerStatus`, `LandingPageStatus`, `DomainStatus`, `TenantStatus`,
`ProvisioningStatus` and `OutboxStatus` — value unions and the transition maps, never
hand-written. `ADR-003`: *"a hand-maintained copy is a second source wearing a disguise."*

---

## 3. Q3 — the path by which tenant context reaches every query

**Not applicable, and the reason is mechanical rather than a judgement:** this task issues no
query. There is no Eloquent model, no query builder call, no raw statement and no Redis
command in anything it adds. With no query there is no tenant-context path to show.

What this task does touch that is *adjacent* to tenancy is the **tenant theme**, and that is
Q6's subject, not Q3's: a theme is a set of validated token values travelling from a row Task
7 already writes to a CSS custom property, never a query this task performs.

The one file this task adds under `apps/api` is `config/design-tokens.php`, a flat array of
colour and length literals. It holds no tenant data and is identical for every tenant.

---

## 4. Q4 — security: license, maintenance and CVEs for every dependency added

`§11.7`. Every row verified in-session by running `npm view`; see `§7` for the raw output.

### 4.1 Fonts — the only third-party *content* this task ships

| Font | Use | License | Delivery |
| --- | --- | --- | --- |
| **Plus Jakarta Sans** | Latin | **OFL-1.1** | **self-hosted** from `packages/tokens`, subset to Latin |
| **Tajawal** | Arabic | **OFL-1.1** | **self-hosted** from `packages/tokens`, subset to Arabic |

**No CDN font request anywhere.** `§12`'s cold-3G bar is a gate clause and a third-party font
request is a dependency on someone else's uptime; it is also a per-visitor disclosure of the
visitor's IP to a third party on a page a customer reaches by scanning a QR code. The fonts
are `woff2`, subset, `font-display: swap`, and preloaded from the same origin.

### 4.2 Runtime dependencies — deliberately almost none

`packages/ui` ships **no runtime dependency** beyond `react`. No component library, no
`clsx`, no `tailwind-merge`, no icon package, no animation library, no headless-UI kit.

That is a decision, not an omission, and it serves three clauses at once: `§12`'s *"no library
default look — if a reviewer can name the UI framework from a screenshot, the screen is
rejected"*; `§12`'s cold-3G bar; and `§11.7`, since a dependency that does not exist has no
license, no maintenance risk and no CVE. The accessibility primitives this kit needs — focus
trap, live region, roving tabindex — are a few dozen lines each and are written here against
the WAI-ARIA patterns, which is cheaper than auditing a kit.

### 4.3 Build and test dependencies

All `devDependencies`, none reaching a browser.

| Package | Version | License | Maintenance |
| --- | --- | --- | --- |
| `next` | **15.5.28** | MIT | the latest 15.x — the `backport` dist-tag. `§1` locks **Next.js 15**, so `latest` (16.4.3) is **not** taken |
| `react`, `react-dom` | 19.3.1 | MIT | satisfies next 15.5.28's peer `^19.0.0` |
| `typescript` | **6.0.3** | Apache-2.0 | the latest **under 6.1.0** — see the binding constraint in `§7` |
| `vitest`, `@vitest/coverage-v8` | 5.0.5 | MIT | engines `^22.12.0 \|\| ^24 \|\| >=26` — the Node constraint |
| `vite` | 8.3.5 | MIT | engines `^20.19.0 \|\| >=22.12.0` |
| `eslint` | **10.13.0** | MIT | ⚠ **NOT 10.14.0, and this row said 10.14.0 (Security L-7).** 10.14.0 was published 0 days before this task and `pnpm 12`'s minimum-release-age window refused it; the exclusion pnpm wrote by itself was removed and the dependency moved back. The reasoning is in `pnpm-workspace.yaml`; this row was simply not updated, and `ticket-tables.test.mjs` now checks every row here against the pin |
| `typescript-eslint` | 8.72.1 | MIT | peer `typescript >=4.8.4 <6.1.0` — **this is what pins TypeScript** |
| `prettier` | 3.9.10 | MIT | current |
| `playwright` | **1.64.1, pinned exactly** | Apache-2.0 | its browser build is tied to the package version |
| `tailwindcss`, `@tailwindcss/postcss` | 4.3.3 | MIT | D3 |
| `jsdom` | 30.1.3 | MIT | Vitest's DOM for unit tests |
| `@testing-library/react` | 16.3.3 | MIT | peer `react ^19` |

**`playwright` is pinned to an exact `1.64.1`, not a range.** The browser build is tied to
the package version: a different version re-downloads a different Chromium, makes the
verified cache stale, and CI then tests against a browser nobody verified. Installed here as
the **Linux** build from inside WSL — a browser installed by the Windows Node is the wrong
platform and cannot be driven from WSL.

**CVEs. ⚠ THIS PARAGRAPH SAID `pnpm audit` WAS ALREADY RUN AS PART OF THE GATE AND ITS
OUTPUT COMMITTED. IT WAS NOT (Security H-2), and the sentence is corrected here in the same
change that builds the control.** It was in `tools/gate/bin/gate.mjs`, root `package.json`,
`ci.yml`, `apps/api/bin/gate` and no evidence file. Run for the first time on 2026-10-31 it
returned **five advisories — 2 high, 3 moderate** — against a diff that adds 3,184 lockfile
lines with no CVE gate behind them. The absent control was the durable half of the finding.

**What exists now:**

* `pnpm audit --audit-level low` is **gate step seven** (`tools/gate/bin/gate.mjs`) and a
  **CI step** of the frontend job. `low`, so no severity is filtered out silently. The
  CI-parity test asserts both, in both directions.
* Output committed at `docs/tickets/QRPAGER-15-audit.txt`, with the exit code read directly.
* **All five are CLOSED by two `overrides` in `pnpm-workspace.yaml`**, each a range rather
  than a pin so a later patch is taken without editing the file again:
  `postcss >= 8.5.23` closes `GHSA-6g55-p6wh-862q` (high), `GHSA-r28c-9q8g-f849` (high),
  `GHSA-qx2v-qp2m-jg93` and `GHSA-fxqj-rqcc-2cmp` — four advisories, all postcss reached
  through `next` in both apps; `minimatch >= 11.0.1` closes `GHSA-g3hv-75wg-m249`
  (moderate), reached through `eslint` on 33 paths.
* **An override was the only instrument, measured rather than assumed:** `next@15.5.28`
  hard-pins `"postcss": "8.4.31"` — an exact version — and 15.5.28 is the latest 15.x,
  which is what `§1` locks. No bump inside the lock closes it.
* **Verified after the override:** `pnpm audit` → *"No known vulnerabilities found"*, exit 0;
  both Next apps build, statically prerendered, **102 kB First Load JS — the same figure as
  before**; the full `pnpm gate` green.
* **One consequence recorded rather than discovered later:** minimatch 11 relicensed from
  ISC to **Blue Oak 1.0.0**, so closing that advisory introduced a licence family the tree
  did not previously carry. It is dispositioned in
  `docs/contracts/dependency-licences.json`.

**Licences, over the whole tree rather than a hand-typed row per package.** The tables in
`§4.1` and `§4.3` name the dependencies this task CHOSE; they are not an inventory.
`docs/contracts/dependency-licences.json` is, and `tools/gate/test/licences.test.mjs`
enumerates the installed store and fails on a licence family in neither its allow-list nor
its dispositions — see `§4.5`.

### 4.4 The rest of `§11.4`'s checklist, against this task's actual surface

* **authn / authz** — this task adds no route, no endpoint and no handler. `apps/web` and
  `apps/site` are created as skeletons with no authenticated surface; the screens are Tasks
  9, 10 and 11.
* **input validation** — the one external input this task's code touches is a **tenant theme
  value**, and it is validated before it arrives: Task 7's `ThemeTokens::accepts()` admits
  only lower-case hex, a bounded length-with-unit, or one of four font keys. Q6 shows what
  this task adds on top.
* **rate limit** — no endpoint added, so nothing to limit.
* **log redaction / secrets** — this task logs nothing and holds no secret. No `.env` key is
  added. The generated artefacts contain colour and length literals only.

### 4.5 Licences over the WHOLE tree — Security L-6, and `§10`'s inherited obligation

**⚠ `§4.3`'s table listed 13 packages and said they were all MIT or Apache-2.0. The
installed tree is 229 packages and four licence families are neither.** Measured
2026-10-31 on linux-x64:

| family | count | packages | reached through |
| --- | --- | --- | --- |
| MIT | 173 | — | — |
| Apache-2.0 | 23 | — | — |
| ISC · BSD-2 · BSD-3 · 0BSD · MIT-0 | 22 | — | permissive, functionally MIT |
| **Blue Oak 1.0.0** | 4 | `minimatch`, `lru-cache`, … | ⚠ **arrived with this task's own minimatch override** — minimatch 11 relicensed from ISC |
| **MPL-2.0** | 4 | `lightningcss`, `lightningcss-linux-x64-gnu` | Tailwind v4, **build-time only** |
| **LGPL-3.0-or-later** | 1 | `@img/sharp-libvips-linux-x64` | `sharp` → Next.js image optimiser, **build-time and server-side** |
| **CC-BY-4.0** | 1 | `caniuse-lite` | `next` → `browserslist`, **build-time DATA** |
| **CC0-1.0** | 1 | `mdn-data` | the CSS tooling, **build-time DATA** |

None of the four is a problem. All four were **unexamined**, which is the finding — and
`§10` is why it matters past this repository: the client receives it by **Transfer
Ownership**, so this inventory becomes an obligation they inherit, not only ours.

**The disposition for each, with its reach and its obligation, is in
`docs/contracts/dependency-licences.json`.** The one condition worth repeating here because
it is a future trap rather than a present fact: **LGPL's relinking requirement is discharged
only while libvips stays an unmodified, dynamically-loaded, unbundled binary.** A future task
producing a single-file or statically-linked artefact containing it has to answer that
again; Next.js 15's output does not.

**The control, not the table:** `tools/gate/test/licences.test.mjs` enumerates the installed
pnpm store and fails if any licence family is in neither the allow-list nor the dispositions,
and separately refuses GPL/AGPL/SSPL/BUSL/UNLICENSED outright — a decision taken in advance
rather than an argument had when a dependency arrives. A licence-FAMILY list rather than a
per-package one, because the per-package list differs by platform (`-linux-x64-gnu` versus
`-darwin-arm64`) and would be red on one machine and green on another, which is the
"works on the builder's machine" shape the fresh-clone proof exists for.

---

## 5. Q5 — scale: bundle cost on a cold 3G start

`§12`: *"usable on a cold 3G start"*, and `§12`'s PWA bar is Lighthouse ≥ 95 at Task 9. What
**this** task is accountable for is the floor it sets for every screen built on it.

**What is bounded, and by what:**

| Cost | Bound | Why it is bounded rather than estimated |
| --- | --- | --- |
| token CSS | one stylesheet of custom properties, **no JS** | tokens are CSS custom properties (D3), so a theme switch is an attribute flip and costs no bundle |
| `packages/ui` runtime deps | **zero** beyond `react` | `§4.2` |
| fonts | **two** families, subset, self-hosted, `woff2` | one subset per script, not a 400-weight variable family per language |
| icons | **inline SVG per use site**, no icon package | an icon font or a package pulls a payload sized by the set, not by what is used |
| theme values | `ThemeTokens::NAMES` is **12 names**, each bounded by a regex | a tenant cannot add a 13th, so the per-tenant CSS payload has a fixed ceiling |

**The one number this task reports and the budget it sets:** a per-package production bundle
size, measured and committed with the gate evidence, asserted by a test so a dependency added
later fails the suite rather than quietly costing 80 KB. A budget nobody asserts is a
preference.

**Cost per request and per active session: unchanged by this task.** It adds no request path.

---

## 6. Q6 — failure: a tenant supplies a hostile theme value

Two halves, and the first is the named invariant's second clause.

### 6.1 The order-ready screen — the UNREACHABILITY mechanism, not the intention

D4 excludes the order-ready screen from theming. The trap in `§6` is explicit: *"if you find
yourself writing contrast-adjustment code for the order-ready screen, the design has drifted
from the decision."* **There is no clamp anywhere in this task.** What follows is the
mechanism.

**The whole tenant-theme path, as Task 7 built it:**

```
tenant types a value in /studio
  -> LandingDocument validates it against ThemeTokens::accepts()   (allow-list + typed regex)
  -> stored on the landing page row
  -> ThemeStylesheet::declarations($theme) emits  --color-accent:#abc;--font-body:...
  -> the template puts that list in  style="{{ … }}"  on the root element, Blade-escaped
  -> the page's own rules read var(--color-accent)
```

**The mechanism, in one sentence:** `ThemeTokens::NAMES` is a **closed allow-list of twelve
names**, `ThemeTokens::property()` maps a name to a CSS custom property by a pure string
transform, and **the three properties the order-ready screen reads are outside the image of
that function over that list.**

**⚠ THE THIRD ROW BELOW WAS WRONG AND IS CORRECTED IN PLACE (Security L-4).** It read
`--color-ready-field` `--color-paper` `--color-signal` — the namespace this round's own
**defect 1** removed, when the order-ready colours stopped being theme roles. Two of those
three names are now in NEITHER set. The table was hand-typed and nothing read it, which is
the shape five findings in this round shared. **`tools/gate/test/ticket-tables.test.mjs`
now checks every row of this table against its source** — the themeable names parsed out of
`ThemeTokens::NAMES`, the produced properties pushed through the real transform, the
protected set parsed out of `ORDER_READY` — and re-derives the intersection rather than
quoting the last row.

| | |
| --- | --- |
| themeable names (all 12) | `color.background` `color.surface` `color.text` `color.muted` `color.accent` `color.accent_text` `font.heading` `font.body` `radius.small` `radius.large` `space.section` `size.heading` |
| the properties they can produce | `--color-background` `--color-surface` `--color-text` `--color-muted` `--color-accent` `--color-accent-text` `--font-heading` `--font-body` `--radius-small` `--radius-large` `--space-section` `--size-heading` |
| the properties the order-ready screen reads | `--ready-field` `--ready-ink` `--ready-signal` |
| intersection | **empty** |

**Three assertions, each of which can fail, and none of which is a clamp:**

1. **Disjointness, over generated artefacts, so it cannot drift.** The themeable property set
   is derived from `ThemeTokens::NAMES` by parsing it — never by a copy, which is the shape
   guard rule 11c already exists to prevent — and the protected set is derived from the token
   source. A test asserts the two are disjoint. Adding a 13th themeable token that collided
   would fail the suite.
2. **The emitter cannot emit a protected property, for any input.** Hostile names and values
   are driven through `ThemeStylesheet::declarations()` — invented names, a name that
   *spells* a protected property, mixed case, a value carrying `;`, `}`, `)`, a quote, a
   `var()`, a `url()`, an embedded newline — and the output is asserted to contain none of
   the three protected properties. `accepts()` refuses an unknown name, so an invented one is
   dropped rather than passed through.

   **⚠ WHERE THIS LIVES, CORRECTED (Security L-5).** This sentence described a PHP test and
   no file under `apps/api` contained those three property names — the review measured it.
   The property *was* covered, on the TypeScript side, by `invariant.test.tsx`, which parses
   this PHP rather than driving it. So the claim pointed at a layer that did not hold the
   control. **Both halves exist now:** the PHP case this sentence describes is
   `ThemeTokenTest` › *"can emit none of the order-ready screen's three properties, for any
   input"*, which drives all twelve allow-listed names plus twelve forged ones through the
   real emitter with the protected list **parsed from `packages/tokens/src/source.ts`** and
   asserts the output contains none of them — and the TypeScript assertions below are
   unchanged. A claim about where a control lives is now true where it is made.
3. **The rendered pair is unchanged, and identical in both themes.** The order-ready
   component is rendered with a hostile theme applied to its ancestor — every one of the 12
   properties set to an attacking value — and its resolved colour pair is asserted to be
   exactly `ready-field` + `paper` + `signal`. The same assertion runs under light and dark
   (condition 5), because the screen sits outside theming in both.

**Why this is unreachability and not defence in depth:** the order-ready screen does not read
a themeable custom property at all. There is no value a tenant could supply that the screen
would consult, correct or reject — the question never reaches it.

### 6.2 Everywhere else — a theme overrides tokens, never components

Task 7 already carries three mechanisms (`PLAN §3.1` row 7, closed 2026-10-28): the
allow-list, the typed patterns that admit no character able to end a declaration or start a
rule, and `composer guard` rule 11. This task adds the **consumer-side** half, which did not
exist because no component did:

* every component reads a token through a CSS custom property; **none reads a tenant value
  directly**;
* a tenant value can only ever change what a property *resolves to*, never which property a
  component reads, because the component's rule is authored in `packages/ui` and the lint rule
  in `§6.3` forbids it carrying a literal;
* **a tenant cannot introduce, remove or re-target a rule** — their entire reach is twelve
  values in one `style` attribute.

**The failure modes, and what each does:** a value that fails its regex is **refused at the
panel** (Task 7, with a named notification); a token the tenant never set **falls through to
the default** in the component's own rule, which is what makes a theme an override rather
than a replacement; an invented name is **refused, not ignored**; and a theme that is
entirely absent renders the approved direction unchanged.

### 6.3 The remaining failure modes this task owns

* **The lint rule failing open.** An unproven rule is not a rule (`§6` trap 1). It is proven
  against a **committed bad fixture** carrying every shape it must catch — a raw hex, an
  `rgb()`, a raw `px` spacing, a raw font size, and the Tailwind arbitrary values `text-[#fff]`
  and `p-[13px]` (D3) — and the fixture also carries legitimate shapes that must **not** fire.
* **A generated artefact going stale.** Four byte diffs, each proven red on a deliberately
  stale file.
* **`prefers-reduced-motion` meaning *less* motion rather than *none*.** The token is `0ms`,
  not a shorter duration, and the browser check asserts a resolved duration of exactly 0.
* **RTL treated as `direction: rtl`.** Icons, progress, timers and anything with directional
  meaning mirror; **logos and numbers do not**. Asserted per component, under `ar` and `en`.
* **Crash mid-way:** not applicable — this task has no multi-step write, no transaction and no
  network call. A failed generator run writes nothing, because it compares before it writes.

---

## 7. Q7 — research: every version verified by running code, with the output

### 7.1 On this machine

```
$ node -v
v22.23.4
$ pnpm -v
12.16.1
$ npm -v
10.9.9
$ which node
/usr/bin/node
$ ls ~/.cache/ms-playwright/
chromium-1248
chromium_headless_shell-1248
ffmpeg-1013
```

Node is the NodeSource build **inside WSL**, at `/usr/bin/node` — not a Windows install
under `/mnt/c`. Playwright's browsers are the Linux builds, already cached.

### 7.2 The binding constraints, measured — and they are not the obvious ones

```
$ npm view vitest engines
{ node: '^22.12.0 || ^24.0.0 || >=26.0.0' }

$ npm view next@15.5.28 engines
{ node: '^18.18.0 || ^19.8.0 || >= 20.0.0' }

$ npm view typescript-eslint peerDependencies
{ eslint: '^8.57.0 || ^9.0.0 || ^10.0.0', typescript: '>=4.8.4 <6.1.0' }

$ npm view typescript dist-tags
{ latest: '7.0.2', ... }
```

**Two constraints bind, and neither is the newest thing available.**

1. **Node is pinned by Vitest 5, not by Next.** Vitest's engines field excludes Node 23 and
   25. Next 15.5.28 asks only for `>= 20.0.0`, so it does not decide. Node **22.23.4**
   satisfies both.
2. **TypeScript is pinned by `typescript-eslint`, not by TypeScript.** `latest` is **7.0.2**,
   and `typescript-eslint@8.72.1` peer-requires **`<6.1.0`**. Taking `latest` would break
   linting. The latest version under the ceiling is **6.0.3**:

```
$ npm view 'typescript@<6.1.0' version | tail -3
typescript@5.9.3 '5.9.3'
typescript@6.0.2 '6.0.2'
typescript@6.0.3 '6.0.3'
```

**And `next` is pinned by `§1`, not by the registry.**

```
$ npm view next dist-tags
{ latest: '16.4.3', backport: '15.5.28', ... }
$ npm view 'next@^15' version | tail -1
next@15.5.28 '15.5.28'
```

`§1` locks **Next.js 15**. `latest` is 16.4.3 and is **not** taken; 16 would need an ADR.
15.5.28 is the latest 15.x.

### 7.3 Everything else, as resolved

```
$ npm view vitest version          -> 5.0.5
$ npm view vite version engines    -> 8.3.5  { node: '^20.19.0 || >=22.12.0' }
$ npm view react version           -> 19.3.1
$ npm view next@15.5.28 peerDependencies
   react: '^18.2.0 || 19.0.0-rc-de68d2f4-20241204 || ^19.0.0'
$ npm view tailwindcss version            -> 4.3.3
$ npm view @tailwindcss/postcss version   -> 4.3.3
$ npm view playwright version      -> 1.64.1
$ npm view eslint version          -> 10.14.0
$ npm view prettier version        -> 3.9.10
$ npm view jsdom version           -> 30.1.3
$ npm view @testing-library/react version peerDependencies
   16.3.3  { react: '^18.0.0 || ^19.0.0', ... }
```

### 7.4 Pinned in the repo, not in a shell history

A version verified in a session and not written down is a version the next fresh clone does
not get.

* root `package.json`: `"packageManager": "pnpm@12.16.1"`, `"engines": { "node": ">=22.12.0" }`
* `.nvmrc` at the repo root: `22`
* **CI's frontend job reads the major from `.nvmrc`**, never a hardcoded `setup-node` value,
  so the pin has one source
* `playwright` as an **exact** `1.64.1`

**Recorded in `PLAN §1`** beside the 2026-10-25 local-toolchain entry, which covers PHP,
Composer and Docker only — Node, pnpm and Playwright are added there by this task (rank 3).
**A new `§1` row in `PROJECT_INSTRUCTIONS.md` would be a rank-2 edit and is raised, not
made.**

### 7.5 Verified by running code, not by reading a registry

The three order-ready contrast ratios, the full light and dark ladders and every bar in
`§2.3` were computed by running the relative-luminance arithmetic in-session, and the same
arithmetic is what the committed Vitest assertions run. `§2.4` reports the two figures where
my result and the approval comment's differ.
