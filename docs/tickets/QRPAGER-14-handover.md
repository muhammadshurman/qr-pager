STATUS: TASK-7 BLOCKED — four reviews, 77 findings, 48 fixed, 29 into `§5`; clause 1 of the invariant fell three times and holds now; the tag is waiting on a re-run of every `§6` gate at the final commit.

# QRPAGER-14 — Task 7 handover

> The ticket is `docs/tickets/QRPAGER-14.md`. This file is the **running record** of the
> build: what is done, what it was measured at, and what the tag is waiting on. The STATUS
> line above is the first line of the file and is rewritten at **every** phase boundary —
> corrections, each build step, the reviews, the fixes, the tag — and pushed with the commit
> that changes it. It has exactly two forms, `TASK-7 TAGGED` and `TASK-7 BLOCKED — <one
> line>`, so there is no third state in which the file can be read as ambiguous. Until the
> tag exists the line is the BLOCKED form and the one line names what the tag is waiting on.

---

## 1. Why this file exists, and what it replaces

`QRPAGER-14.md §0` claimed this file existed from the moment the ticket was committed at
`84c919b`, and it did not. That is corrected in `§0` of the ticket, in the same commit that
created this file, and the correction is kept rather than overwritten. The rule it leaves
behind is the one this file is written under: **every line here names the commit it was
measured at, or it is marked as not yet measured.** A figure with no commit beside it is the
defect `PLAN.md §4a` records most often.

---

## 2. SR-1 and SR-2 — ruled, not assumed

Both stop-and-reports in `QRPAGER-14.md §0` were **accepted by Muhammad on 2026-10-28**
before the build began, in the instruction that started it. They are quoted here in full so
that the reasoning behind the two largest design choices in this task is readable without
reconstructing it, and so that it is visible as a **ruling** rather than as the builder's
own preference.

### SR-1 — the builder is tenant-scoped, and the platform panel is not an option

The ruling, verbatim:

> SR-1 and SR-2 are accepted as you recorded them. The builder is tenant-scoped — the
> 2026-10-28 ruling makes a platform-panel builder impossible, and you read that correctly.

What was recorded, and is therefore what was accepted, is `QRPAGER-14.md §0 › SR-1`:
`PLAN.md §4.3` lists the landing page builder among the *Filament admin (Tasks 6–7)*
screens, while `PROJECT_INSTRUCTIONS.md §4 › Tenancy` (added 2026-10-28) says the platform
admin panel "operates only on tables belonging to no tenant", that a platform admin "holds
no tenant ability and no cross-tenant read", and that "**a panel query that reaches a tenant
table is a rejected design**". A landing page, its media and its domains are tenant content.
So the builder is a **tenant-scoped panel** (decision D1) and `§4.3`'s wording is corrected
by this task's `§5` row `R-127` rather than by an edit to `§4`.

### SR-3 — the ticket's worker grant assumed a sweep that `§4` does not allow

Raised by this build, at the data-contract step, and recorded in `QRPAGER-14.md §0 › SR-3`,
`PLAN.md §2.6` and `PLAN.md §2.7` rather than resolved in passing. The ticket's `§6.1`
granted `qrpager_worker` `SELECT` on two of the three new tables, justified by "the
verification and issuance polling commands run as the worker". A worker that walks every
tenant's domains is a **fifth cross-tenant read**, and `§4 › Tenancy` names four and says
adding one "requires editing this file first" — a rank-2 edit, which is a stop.

So there is no sweep. The lifecycle runs inside the tenant's own scope, from the panel and
from `domains:advance {tenant}`, and **the worker is granted nothing on any of the three new
tables**. The residual is `R-130` (Task 13).

### SR-2 — the certificate infrastructure is Task 13's; the lifecycle is Task 7's

The ruling, verbatim:

> TLS infrastructure is Task 13's; the application-side lifecycle is yours. Build on both
> without re-opening them.

So this task owns verification, the issuance request, status and activation, and the
redirect, behind two ports with local drivers (decision D7). What it **cannot** demonstrate
is an ACM certificate existing in an AWS account. What it **does** demonstrate is the
falsifiable half: **no domain reaches `active` without a verified DNS challenge and a
recorded certificate.**

---

## 2a. ⚠ THE NAMED INVARIANT FAILED AT REVIEW AND HOLDS AFTER THE FIX PASSES

**Four reviews, 77 findings, four fix passes — and clause 1 of the invariant failed THREE times,
each time on the fix for the one before it.** The `§11` QA and Security pair found 29 (1 Critical, 4 High) and disagreed about the
invariant; Security said it failed and was right. Round 1 of the fix diff's review found 16 more,
**both of its Highs inside the first fix pass**. Round 2 — the cap — found 19, and the invariant
**failed again on an interleaving the round-1 fix had introduced**: the guard it asked for refuses
one phase after the pointer write, so a refused publish left an unrecorded page live and
`unpublish()`'s early return made it permanent.

Round 2's fix added a compensating delete, and the **scoped re-review** `§11` prescribes found that
it removed `index.html` alone — leaving `manifest.json`, which carries the checksum, beside
`{checksum}.html`. The identical two-hop route this task had already closed once in `unpublish()`.
It clears the whole prefix now, with its return checked, and `unpublish()` no longer refuses to
sweep when the row says `draft` — which is what had made every such state permanent.

**48 fixed with a regression test, 29 accepted into `§5` as `R-134`…`R-162`** — four of them Highs,
which is `§11`'s round limit working as written rather than a corner cut.


The `§11` security review falsified two of its three clauses on measured evidence, and both were
reproduced in this session before the verdict was accepted. The QA review, running concurrently,
concluded the invariant HELD — the two disagreed, and the security review was right.

1. **No tenant-authored value can inject script — FAILED.** `SvgPolicy::refuse()` ended in
   `walk($root)`, and `walk()` applies the attribute allow-list to its argument's CHILDREN. The root
   `<svg>`'s own attributes were never examined, so `<svg xmlns="…" onload="…">` was accepted,
   stored, and copied by a publish to the published site with `ContentType: image/svg+xml`. All 21
   hostile fixtures put their payload on a child. **Fixed:** the walk starts at the DOCUMENT, which
   also makes the processing-instruction rule reachable for the only position a PI is written in.
2. **An unpublished draft is not reachable — FAILED.** `unpublish()` deleted `index.html` and left
   `manifest.json` (which carries the checksum) and `{checksum}.html` in the CDN-served prefix.
   **Fixed:** the whole prefix is deleted, and the re-publish round trip is asserted so "recoverable"
   is measured rather than assumed.
3. **One tenant's media cannot be reached or guessed — HELD**, in both reviews, with no way found in.

## 3. The named invariant this task ships on

> **An unpublished draft is not reachable, no tenant-authored value can inject script or
> markup into a published page, and one tenant's media cannot be reached or guessed from
> another.**

`PLAN.md §3.1` row 7's wording, unchanged. Under `PROJECT_INSTRUCTIONS.md §11 › Round
limit`, this is the only clause that blocks `task-7` once the review cap is reached.

---

## 4. Phase log — what is done, and at which commit

| # | Phase | State | Commit | Pushed |
| - | ----- | ----- | ------ | ------ |
| 0 | Corrections: `§0` status, this file | **done** | *this commit* | yes |
| 1 | Data contract — `PLAN.md §2.3/§2.5/§2.6/§2.7` before the migration | **done** | *this commit* | yes |
| 2 | Migration — three tenant tables, two enums, RLS, grants | **done** | *this commit* | yes |
| 3 | Block schema, generated contract, `landing-schema-check` | **done** | *this commit* | yes |
| 4 | Media library — upload validation, isolation | **done** | *this commit* | yes |
| 5 | Publish — render, snapshot, pointer, manifest | **done** | *this commit* | yes |
| 6 | Domains — DNS verification, issuance, redirect | **done** | *this commit* | yes |
| 7 | The `/studio` panel | **done** | *this commit* | yes |
| 8 | `composer guard` rule 11 — theme tokens | **done** | *this commit* | yes |
| 9 | Gates: gate log, coverage, fresh clone, rollback, three runs | **done** | *this commit* | yes |
| 10 | `§11` QA + Security, concurrently | **done** — 29 findings: QA 18 (0C 2H 7M 9L) · Security 11 (1C 2H 4M 4L) | — | — |
| 11 | Disposition and the fix pass | **done** — 22 fixed with a regression test, 7 into `§5` (`R-134`…`R-140`) | *this commit* | yes |
| 12 | Review of the fix diff, round 1 of 2 | **done** — 16 findings (0C 2H 6M 8L), both Highs in the fixes; 13 fixed, 3 into `§5` | — | yes |
| 12b | Review of the fix diff, round 2 of 2 — **the cap** | **done** — 19 findings (0C 4H 7M 8L); the invariant failed again on the round-1 fix | — | yes |
| 12c | **Scoped re-review** — `§11`'s loop for a past-the-cap invariant failure | **done** — 13 findings (1C 3H 5M 4L); clause 1 failed a THIRD time, on round 2's own compensation; 8 fixed, 5 into `§5` | *this commit* | yes |
| 13 | `PLAN.md §0`, `§4a`, `§5` | **done** (re-checked after the reviews) | *this commit* | yes |
| 14 | Tag `task-7`, pushed | not started | — | — |

---

## 5. Gate evidence

Each clause is filled in when it is demonstrated, with the commit it was measured at. An
empty row is an unmet clause, not an unwritten one.

| `§3` row 7 clause | Evidence | Commit |
| ----------------- | -------- | ------ |
| publish produces a statically rendered, CDN-cached page | `LandingPublishTest` — the object exists, its bytes hash to the recorded checksum, and `ContentType` and `Cache-Control` are read back out of the store through `headObject`. The CDN's own behaviour is Task 13's and is not claimed | *this commit* |
| an unpublished draft is not reachable | `LandingDraftUnreachableTest` — the whole `sites/{hostname}/` prefix is empty before a publish and after a preview; the preview refuses anonymously, to `staff` and to a platform admin, takes **no input at all**, serves `no-store` + `noindex` + `no-referrer`, and answers 404 (not 403) for another tenant's asset | *this commit* |
| a tenant cannot inject script through any block | `LandingXssFuzzTest` — 46 payloads into every text field of every block, asserted structurally by PARSING the output (no script, iframe, object, embed, base, form, `on*`, `javascript:`/`data:` URL, or `url()` in the theme attribute) while the payload is still present as text; the non-text fields refuse all 46; the fuzz paths are checked against `BlockSchema` so the sweep cannot go stale | *this commit* |
| custom domain issues TLS automatically and the old domain redirects | `DomainLifecycleTest` + `DomainRedirectTest` — the lifecycle walks one step per call and never skips one; `active` is unreachable without a verified challenge and a recorded certificate, falsified from the service, the transition table and the owner connection with the bypass GUC on; the superseded hostname stops serving the page and serves a canonical + meta-refresh + link document. **SR-2 bounds this: no ACM certificate is demonstrated, and a static object cannot send a 301 — a real 301 at the edge is Task 13's** | *this commit* |
| theme overrides tokens only, never components | `ThemeTokenTest` + `composer guard` **rule 11**, proven on `guard/fixtures/theme/styles-a-component.php.fixture`: all four clauses fire (a `<style` literal, a CSS at-rule, a class rule-set, a copied token name, a declaration built outside the one emitter) and four legitimate shapes in the same file are not flagged. The guard READS the token list out of `ThemeTokens` rather than holding a copy | *this commit* |
| an upload is validated by real content type and size, not by extension | `MediaUploadTest` — an HTML file called `logo.png` is refused naming `text/html`; the five types round-trip through the real composed store; the stored extension comes from the detected type, not the filename; empty, 5 MB and 21 hostile SVGs refused | *this commit* |
| one tenant's asset path cannot be guessed or reached from another | `MediaIsolationTest` — RLS, the two key CHECKs, identical refusals for foreign and absent ids, and a reflection assertion that no public method takes an object key from a caller | *this commit* |

| `§6` after-coding clause | Evidence | Commit |
| ------------------------ | -------- | ------ |
| `composer gate` green | all 7 steps — pint, stan, guard, deptrac, openapi:check, landing:check, test. **1049 passed, 8474 assertions.** `docs/tickets/QRPAGER-14-gate.txt` | `14e5f8a` |
| unit coverage ≥ 90% on this task's code (`bin/coverage`) | **94.24 %** on this task's 51 files (1571/1667); whole tree 94.6 %. Every file still under 90 % is named in `docs/tickets/QRPAGER-14-coverage.txt` rather than averaged away | `8d243a9` |
| fresh clone | PASSED — all 7 gate steps green **in the clone**, 1063 passed. `docs/tickets/QRPAGER-14-fresh-clone.txt` | `e81cbcd` |
| migrations apply from empty, twice, and roll back clean | all five checks passed on a throwaway database, including 20 privilege assertions with positive controls. `docs/tickets/QRPAGER-14-rollback.txt` | `e81cbcd` |
| three consecutive green runs | PASSED — 3/3 in 3 attempts, 1063 passed on each, no red of any kind. `docs/tickets/QRPAGER-14-three-runs.txt` | `e81cbcd` |
| every new route in the generated OpenAPI spec, `/v1`-versioned | **no new `/v1` route exists** (decision D2): the builder is panel routes and console commands, so the public API surface does not grow and `openapi:check` is green with no new paths. The six `/studio` paths are in `RouteSurfaceTest`'s allowlist one by one, with the argument for why an admin UI is not versioned | `14e5f8a` |
| every shared contract generated, CI red when stale | `landing-page.schema.json` generated from `BlockSchema`; `composer landing:check` in `bin/gate` and in CI; `LandingSchemaContractTest` drives the check to exit 1 on a modified copy and 0 on the real one | *this commit* |

---

## 6. Repository state at the last push

Rewritten at every push, and confirmed before the task is called finished.

| Check | Result | When |
| ----- | ------ | ---- |
| `git status --porcelain` empty | — | confirmed at the end |
| local and `origin` at the same commit | — | confirmed at the end |
| `task-7` tag pushed | — | not yet — the reviews come first |
