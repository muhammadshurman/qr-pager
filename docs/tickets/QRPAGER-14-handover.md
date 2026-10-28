STATUS: TASK-7 BLOCKED — the build has not started; `84c919b` carries the `§6` answers and no code, so `task-7` is waiting on the whole of Task 7.

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
| 1 | Data contract — `PLAN.md §2.3/§2.5/§2.6/§2.7` before the migration | not started | — | — |
| 2 | Migration — three tenant tables, two enums, RLS, grants | not started | — | — |
| 3 | Block schema, generated contract, `landing-schema-check` | not started | — | — |
| 4 | Media library — upload validation, isolation | not started | — | — |
| 5 | Publish — render, snapshot, pointer, manifest | not started | — | — |
| 6 | Domains — DNS verification, issuance, redirect | not started | — | — |
| 7 | The `/studio` panel | not started | — | — |
| 8 | `composer guard` rule 11 — theme tokens | not started | — | — |
| 9 | Gates: gate log, coverage, fresh clone, rollback, three runs | not started | — | — |
| 10 | `§11` QA + Security, concurrently | not started | — | — |
| 11 | Disposition and the fix pass | not started | — | — |
| 12 | Review of the fix diff (at most twice) | not started | — | — |
| 13 | `PLAN.md §0`, `§4a`, `§5` | not started | — | — |
| 14 | Tag `task-7`, pushed | not started | — | — |

---

## 5. Gate evidence

Each clause is filled in when it is demonstrated, with the commit it was measured at. An
empty row is an unmet clause, not an unwritten one.

| `§3` row 7 clause | Evidence | Commit |
| ----------------- | -------- | ------ |
| publish produces a statically rendered, CDN-cached page | — | — |
| an unpublished draft is not reachable | — | — |
| a tenant cannot inject script through any block | — | — |
| custom domain issues TLS automatically and the old domain redirects | — | — |
| theme overrides tokens only, never components | — | — |
| an upload is validated by real content type and size, not by extension | — | — |
| one tenant's asset path cannot be guessed or reached from another | — | — |

| `§6` after-coding clause | Evidence | Commit |
| ------------------------ | -------- | ------ |
| `composer gate` green | — | — |
| unit coverage ≥ 90% on this task's code (`bin/coverage`) | — | — |
| fresh clone | — | — |
| migrations apply from empty, twice, and roll back clean | — | — |
| three consecutive green runs | — | — |
| every new route in the generated OpenAPI spec, `/v1`-versioned | — | — |
| every shared contract generated, CI red when stale | — | — |

---

## 6. Repository state at the last push

Rewritten at every push, and confirmed before the task is called finished.

| Check | Result | When |
| ----- | ------ | ---- |
| `git status --porcelain` empty | — | — |
| local and `origin` at the same commit | — | — |
| `task-7` tag pushed | — | — |
