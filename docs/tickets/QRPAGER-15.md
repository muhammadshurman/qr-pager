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

| role | LIGHT | DARK | dark derivation |
| --- | --- | --- | --- |
| `paper` | `#F2F4F1` | `#0A1F17` | `= ready-field` (approved) |
| `surface` | `#FFFFFF` | `#1D3028` | `mix(ready-field, paper, 8%)` |
| `surface-2` | `#E7EBE6` | `#2A3D36` | `mix(ready-field, paper, 14%)` |
| `hairline` | `#D3DAD2` | `#42524B` | `mix(ready-field, paper, 24%)` — **decorative only** |
| `border-strong` | `#7D8981` | `#798580` | light `mix(hairline, ink, 44%)`; dark `mix(ready-field, paper, 48%)` |
| `ink` | `#10231B` | `#F2F4F1` | `= paper` (approved) |
| `ink-mid` | `#5A6A62` | `#9DA7A1` | `mix(ink-mid, paper, 44%)` |
| `brand` | `#0B6B4F` | `#3DDC97` | `= signal` (approved) |
| `signal` | `#3DDC97` | `#3DDC97` | `= signal` (approved) |
| `ready-field` | `#0A1F17` | `#0A1F17` | `= ready-field` (approved) — **outside theming, D4** |
| `alert-fill` | `#E4572E` | `#E4572E` | `= alert-fill` (approved) |
| `alert-ink` | `#96351A` | `#E4572E` | `= alert-fill` (approved), promoted to the text role |

**Every bar met, against all three backgrounds in each theme** (condition 4 asserts these):

| role | bar | LIGHT: paper / surface / surface-2 | DARK: paper / surface / surface-2 |
| --- | --- | --- | --- |
| `ink` | ≥ 7.0 AAA | 14.85 / 16.43 / 13.63 | 15.55 / 12.61 / 10.44 |
| `ink-mid` | ≥ 4.6 | 5.17 / 5.72 / 4.74 | 6.94 / 5.63 / 4.66 |
| `border-strong` | ≥ 3.0 (1.4.11) | 3.29 / 3.64 / 3.02 | 4.49 / 3.64 / 3.01 |

**The three order-ready ratios, computed from the token source — arithmetic, no browser:**

| pair | ratio | bar |
| --- | --- | --- |
| `paper` on `ready-field` | **15.55:1** | AAA ✅ |
| `signal` on `ready-field` | **9.73:1** | AAA ✅ |
| `ready-field` on `signal` | **9.73:1** | AAA ✅ |

These match the rank-1 prompt's three figures exactly.

### 2.4 Two figures in the approval comment that my arithmetic does not reproduce

Reported rather than silently adjusted, and neither changes a conclusion the approval drew.

| pair | approval comment | computed here | note |
| --- | --- | --- | --- |
| `ink` on `paper` | 14.86:1 | **14.85:1** | 14.8528 — a rounding direction, not a disagreement |
| `alert-fill` on `paper` | 3.44:1 | **3.33:1** | a real difference. The comment's claim — *"the fill fails 4.5:1 as text"* — holds either way, and `alert-fill` stays restricted to fills and ≥24px text |

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
| `eslint` | 10.14.0 | MIT | current major |
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

**CVEs.** No advisory is asserted from memory. `pnpm audit` is run as part of the gate and its
output is committed with the gate evidence; a non-empty result is a finding, not a note.

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

| | |
| --- | --- |
| themeable names (all 12) | `color.background` `color.surface` `color.text` `color.muted` `color.accent` `color.accent_text` `font.heading` `font.body` `radius.small` `radius.large` `space.section` `size.heading` |
| the properties they can produce | `--color-background` `--color-surface` `--color-text` `--color-muted` `--color-accent` `--color-accent-text` `--font-heading` `--font-body` `--radius-small` `--radius-large` `--space-section` `--size-heading` |
| the properties the order-ready screen reads | `--color-ready-field` `--color-paper` `--color-signal` |
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
