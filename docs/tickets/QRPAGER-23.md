# QRPAGER-23 — Task 8b: the Filament restyle and the zero-technical-background walk

| | |
| --- | --- |
| Ticket | `QRPAGER-23` (Task 8b). Task 8a is `QRPAGER-15` and is not this task. |
| Branch | `task-8b-panel-restyle` — **not yet cut.** See §0.1. |
| Base commit | **Undecided, and deliberately so: it is the `task-8a` tag, which does not exist yet.** See §0.1. |
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

`§0` says never silently resolve a conflict. **Three are raised here. SR-1 blocks the
approach the rank-1 prompt prescribes and I have not chosen around it.**

### SR-1 — trap 1's prescribed proof is not satisfiable against Filament 5.11, measured three ways

**The instruction.** The rank-1 prompt's trap 1: *"8a neutralised Tailwind's default palette,
spacing and type scale with a generated `@theme reference` block — if Filament's build
re-enables the default theme, clause 1 is back through a door 8a closed. **Prove
`bg-red-500`, `p-5` and `text-sm` emit nothing in the PANEL CSS too**, on a committed bad
fixture."*

**The first half is true and worse than stated.** A Filament theme exactly as
`make:filament-theme` writes it carries Tailwind's **entire** default theme. Filament's own
`resources/css/theme.css` is two lines:

```css
@import 'tailwindcss' source(none);
@import './index.css';
```

`source(none)` disables automatic *source scanning*; it does not remove the *theme*.
Compiled against Tailwind 4.3.3 with those three candidates plus five more:

```
bg-red-500       EMITTED  background-color: var(--color-red-500);
p-5              EMITTED  padding: calc(var(--spacing) * 5);
text-sm          EMITTED  font-size: var(--text-sm); …
text-xs          EMITTED  font-size: var(--text-xs); …
rounded-lg       EMITTED  border-radius: var(--radius-lg);
shadow-md        EMITTED  --tw-shadow: 0 4px 6px -1px …
bg-emerald-600   EMITTED  background-color: var(--color-emerald-600);
tracking-wide    EMITTED  --tw-tracking: var(--tracking-wide); …
```

So clause 1 is reachable through the panel build. That part of trap 1 is confirmed by
measurement rather than by reading.

**The second half — the prescribed fix — does not build. Three independent clears each break
Filament's own stylesheet:**

| what was cleared | result |
| --- | --- |
| 8a's generated `@theme reference` block, imported as-is | `The --spacing(…) function requires that the --spacing theme variable exists, but it was not found.` |
| `--color-* --text-* --font-* --radius-* --shadow-* --tracking-* --leading-*`, keeping `--spacing` | `Cannot apply unknown utility class 'rounded-md'` |
| as above plus `--spacing-*` (keeping the bare `--spacing`) | the `--spacing(…)` failure again — the wildcard covers the bare key |

**The cause, located exactly.** Filament's shipped CSS *consumes* the default theme it would
be asked to give up:

```
vendor/filament/support/resources/css/utilities.css:777   margin-block: --spacing(8);
vendor/filament/support/resources/css/utilities.css:779   margin-top: --spacing(8);
vendor/filament/forms/resources/css/components/date-time-picker.css:68
      @apply grid grid-cols-[repeat(7,minmax(--spacing(7),1fr))] gap-1;
```

and it `@apply`s default-theme utilities (`rounded-md` above is one; it is not the only one).
**Removing Tailwind's default theme from a Filament theme removes Filament's own CSS.**
Re-derive:

```
node /dev/stdin <<'EOF'   # or see the probe recorded in this session's transcript
# compiles vendor/filament/filament/resources/css/theme.css with and without 8a's
# generated @theme reference block and prints which candidates emit
EOF
grep -rn -- '--spacing(' apps/api/vendor/filament/*/resources/css/
```

**Why this is `§0` and not mine to resolve.** 8a's answer to clause 1 falling twice was `§8`'s
redesign — *make the shape impossible*. Here the redesign is **measurably unavailable**: the
shape is held open by the framework's own stylesheet, not by our code. That changes `§8`'s
calculus, and changing it is a ruling, not a builder's judgement call.

**Three options, each with what it costs. I have not picked one.**

| | option | what it satisfies | what it does not |
| --- | --- | --- | --- |
| **a** | Scope the clause-1 proof to **panel-authored sources** — a lint rule over the panel's own Blade/PHP/CSS refusing default-theme-backed utility names *and* raw hex/px, proven on a committed bad fixture | `§4.1`'s second half as written ("no panel style value is **hand-written**"); nothing off-palette can be authored | the utility does not *cease to exist*, so it is the "pattern, not redesign" shape `§8` refused for `apps/web`. The difference is that there the redesign existed and here it does not — which is the part that needs ruling |
| **b** | Clear the namespaces and **re-supply** every default-theme key Filament consumes, from the token source | the letter of trap 1 | a hand-maintained enumeration of Filament's internal CSS dependencies — a second source for the framework's internals, which a 5.11.x patch can extend silently. Exactly the duplicated-list shape `guard` rule 11c exists to prevent |
| **c** | **Redefine** rather than clear: point the default palette's keys at token values (`--color-red-500: var(--color-alert-fill)`) | nothing off-palette *renders*, and Filament keeps building | `bg-red-500` still compiles, so the utility exists and reads as approved. Weaker on clause 1's letter; arguably sound on its intent |

**My reading, offered as a reading and not acted on:** (a) is the honest instrument and (c) is
a defensible belt-and-braces addition to it, because (c) alone leaves a developer writing
`bg-red-500` and getting a plausible colour, which is worse than a lint error. (b) I would
argue against on the second-source ground. **But trap 1 as written asks for something the
framework does not permit, so this needs Muhammad's ruling before the first line of panel CSS.**

### SR-2 — the 2026-10-30 `§12` clause is in neither the repo's `§12` nor anywhere in the repo

**The conflict.** The rank-1 prompt instructs: *"READ FIRST, IN FULL: PROJECT_INSTRUCTIONS.md
§0, §6, §8, §11, §12 **(especially the 2026-10-30 clause on users with no technical
background)**."* `QRPAGER-23 §0` quotes that clause as a block quote **from `§12`**.

**Measured: `docs/PROJECT_INSTRUCTIONS.md §12` does not contain it, and neither does any file
in the repository.** Six distinctive phrases, zero hits each:

```
for p in "no technical background" "neither reads documentation" "unaided on the first attempt" \
         "requires explanation is rejected" "system vocabulary" "unexplained identifier"; do
  printf '%-36s %s\n' "$p" "$(grep -rl "$p" --include='*.md' --include='*.php' --include='*.json' . \
    | grep -v node_modules | tr '\n' ' ')"
done
```

`git log --follow -- docs/PROJECT_INSTRUCTIONS.md` records **no 2026-10-30 edit at all**; the
most recent `§12`-era commits are `689bdc8`, `889ee48` and `c346098`, all earlier rank-2
mirrors.

**Why it is raised rather than fixed.** Mirroring the clause into `§12` is a **rank-2 edit**,
and `QRPAGER-15 §7.4` set this task family's precedent for those: *raised, not made*. This is
`W-1`'s shape in the opposite direction — `PLAN §0.1` records the claude.ai Project
instructions running behind the repo; here a rank-1 prompt and the ticket both attribute a
clause to the repo copy that the repo copy does not carry.

**Why it does not block these answers.** The clause's full text is quoted verbatim in
`QRPAGER-23 §0`, so no substance is missing — I answer `§6` and the walk against that text.
What is missing is the clause's *home*, and a binding standard that lives only in a ticket is
one ticket away from being lost. **The rank-2 edit wanted:** add the quoted clause to
`docs/PROJECT_INSTRUCTIONS.md §12`, dated, so the panels' bar outlives this ticket.

### SR-3 — `§4.3`'s panel inventory names four more screens that were never built as panel screens

`R-127` already records one instance: `§4.3` lists the landing page builder among the
*Filament admin (Tasks 6–7)* screens when Task 7 built it as a tenant-scoped `/studio` panel.
**Measured, the inventory is wrong in four further places.** The panels' actual routes:

```
cd apps/api && php artisan route:list --json | python3 -c "
import json,sys
rs=json.load(sys.stdin)
for p in ['platform','studio']:
    print('===',p); [print(' /'+u,n) for u,n in sorted({(r['uri'],r.get('name') or '') for r in rs
        if r['uri']==p or r['uri'].startswith(p+'/')})]"
```

| `§4.3` names | exists as a panel screen? |
| --- | --- |
| Platform dashboard | **yes** — `/platform` |
| Restaurants list | **yes** — `/platform/restaurants` |
| Audit log viewer | **yes** — `/platform/audit-log` |
| **Create-restaurant wizard** (identity → branding → staff → QR batch → publish) | **no route.** The provisioning path is `TenantProvisioning` plus `ProvisionTenantsCommand`, reached from the restaurants list by an `Action::make('provision')` — not a wizard screen |
| **Restaurant detail** (branding, domains, staff, QR batches, usage) | **no route** |
| **QR batches** | **no route** |
| **Platform settings** | **no route** |
| Landing page builder | not a platform screen — `/studio`, and that is `R-127` |

**So the walk's true scope is seven screens, not the eight-plus `§4.3` implies:**
`/platform`, `/platform/restaurants`, `/platform/audit-log`, `/studio` (the builder),
`/studio/domains`, `/studio/media`, `/studio/preview` — plus each panel's `logout`.

This is raised rather than assumed because **the walk is a gate clause** (*"for every screen
in both panels"*) and a walk measured against an inventory describing screens that do not
exist is a walk nobody can check. **It extends `R-127` rather than needing a new row**, and
the rank-3 correction to `§4.3` belongs in this task either way. ⚠ It is also the honest
answer to a question this file must not duck: *the create-restaurant wizard `§4.3` promises
does not exist*, and building one is **out of scope by `§2`** — so it is a finding with an
owner, not work for 8b.

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

Two consequences, both 8b's: the approved direction's families are **Plus Jakarta Sans +
Tajawal, self-hosted from `packages/tokens`**, so `Instrument Sans` is off-direction as well
as off-origin; and the fix is to drop the `bunny()` helper and point the panel theme at 8a's
self-hosted faces. **Whether that assertion should be extended to cover `apps/api` is a
one-line change to an 8a test, and extending a test is not narrowing one**, so I intend to do
it in the implementation commit and am flagging it here rather than surprising a reviewer.

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

**"Unexamined" is the honest word and is used deliberately:** I have not driven those screens
with their data source down, and asserting a verdict now is the shape `R-156` was filed for —
*a verdict asserted in the same commit that wrote it*. The walk is where each cell gets a
measured value.

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
* **The Tailwind used for every probe above is the workspace's 4.3.3**, not the
  `@tailwindcss/vite ^4` that `apps/api/package.json` will resolve. The probes are therefore
  evidence about Filament's CSS and Tailwind 4's behaviour, not about the exact bytes the
  panel build will emit. Re-running them against the installed panel toolchain is the first
  implementation step, and if the result differs, the probe was wrong and not the plan.

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
