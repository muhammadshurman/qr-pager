# Claude Code — Task 2 (QRPAGER-9), epic E1: API core

## 0. Who you are and what this run is

You are the **BUILDER** role defined in `docs/PROJECT_INSTRUCTIONS.md §11`. One task = one branch = one
ticket. Branch name: `task-2-api-core`.

**This run has two phases and a hard stop between them.**

- **Phase 1 — the ticket.** Produce the §6 pre-coding answers and the scope confirmation described in
  section 3 below. Write **no application code** in phase 1. End your turn and wait for the user's
  approval.
- **Phase 2 — the build.** Only after the user approves phase 1. Then implement, run the three gates
  green against real Postgres + Redis, and open the PR.

`PROJECT_INSTRUCTIONS.md §7` is explicit: research → write the stable plan → get approval → **then**
code. Task 1 cost nine build rounds and eight reviews because things were decided late, under pressure,
after work already existed. Do not repeat that here.

## 1. Read before anything else

In this order, in full:

1. `docs/PROJECT_INSTRUCTIONS.md` — all of it. §0 precedence, §1 locked stack, §3 C1–C7, §4 invariants,
   §6 universal gate, §8 anti-hallucination, §9 output format, §11 agent workflow.
2. `docs/PLAN.md §0` — current state.
3. `docs/PLAN.md §2` — the data contract. **Task 2 adds no table, column, enum, role or GUC.** If you
   believe it must, stop and say so; a data-contract change updates §2 *first* (`§6.1`).
4. `docs/PLAN.md §3`, the Task 2 row — the acceptance gate, verbatim. This is what the task ships on.
5. `docs/PLAN.md §3.1`, the Task 2 row — the invariant a review can falsify. Audited 2026-10-26: this
   gate needs nothing before you start.
6. `docs/PLAN.md §3.2` — the ledger sweep. **Read every row whose Task column is 2.**
7. `docs/PLAN.md §5` — every row whose Owner names Task 2 or `QRPAGER-9`.
8. `docs/PLAN.md §6 Open items` — three bullets bear on this task, one of them directly
   (`QueryException::getCode()` is not the SQLSTATE).
9. `docs/adr/001`, `002`, `003`.

Then read the Task 1 code you will be touching: `app/Platform/Database/TenantContext.php`,
`RuntimeRoleGuard`, `RoleBootstrapper`, `config/tenancy.php`, `config/database.php`,
`tests/TestCase.php`, `tests/Support/Composition.php`, `RoleTimeoutTest`, `RuntimeRoleGuardTest`,
`TenantContextTest`, `BootstrapDatabaseCommandTest`.

**This is not a greenfield task.** It modifies code that survived nine rounds of review. See section 6.

## 2. Scope

### 2.1 The §3 acceptance gate — copy it into the ticket verbatim, do not paraphrase

It is in `docs/PLAN.md §3`, row 2. Every clause is demonstrated with evidence shown in the chat.

### 2.2 The §5 debt this task owns — 17 rows

`§0`'s "Open debt that must die in Task 2" row lists **six** items. `§5`'s owner column assigns
**seventeen**. That disagreement was found on 2026-10-26 and is recorded in `§3.2`. **Treat all
seventeen as in scope** (see section 4, decision B1 — this is a recommendation from the planning
chat, not a user-confirmed decision; restate it at the top of your ticket and ask).

R-005, R-007 (Task 2 half), R-009, R-010, R-012 (Task 2 half), R-013, R-014, R-015, R-016, R-017,
R-018, R-019, R-020, R-021, R-022, R-023, R-024.

Read each row's own "Review by" cell. It states the acceptance condition for that row. Several name
the exact test to write.

Two of them are **Medium**: R-017 and R-022. Under `§11`'s merge rule a Medium is fixed or carried
with an owner and a date; both already carry Task 2 and this gate as their date, so deferring either
again is a decision the user must make explicitly.

### 2.3 Out of scope — name the owner, do not build it

| Excluded | Owner |
|---|---|
| Any route that creates, reads or mutates a pager session | Task 3 |
| QR token generation, hashing, lookup | Task 3 |
| The outbox worker, Web Push, Redis status projection, the polling endpoint | Task 4 |
| Real authentication, users, passkeys, 2FA, RBAC | Task 5 |
| Any Filament panel or page | Tasks 6–7 |
| Any frontend workspace, token package, TypeScript | Task 8 |
| CSP, CORS, SAST/DAST, ASVS L2 table | Task 12 |
| Terraform, deployment, `sslmode=verify-full`, connection-limit sizing | Task 13 |

## 3. Phase 1 deliverable — the ticket

Produce, as one document:

### 3.1 The seven §6 pre-coding answers

Answer each one concretely, naming files, classes and tests. Not prose about intent.

1. **Tables / columns / roles / GUCs touched.** Expected answer: no schema change; both GUCs read-only
   via `TenantContext`; all four roles read-only via the boot guard. If any item in section 2.2 forces
   a §2 change, name it and stop.
2. **Shared contracts used or added.** Task 2 adds the first HTTP-facing contracts (error envelope,
   health/readiness shape, the OpenAPI spec). Name the single source for each and the mechanism that
   makes CI fail when it is stale. `packages/contracts` does not exist until Task 8 — do not create it.
3. **The exact path by which tenant context reaches every query.** Include: where a scope opens, what
   enforces that a tenant-table query outside a scope cannot happen, and which routes legitimately have
   no tenant and how they are exempted.
4. **Security.** authn (none exists — see D-stub below), authz, input validation per entry point, rate
   limit, log redaction, secrets, config validation at boot.
5. **Scale.** Cost per request and per active session. State the per-scope statement count as a number
   (Task 1 measured 4) and what is bounded.
6. **Failure.** timeout, retry, duplicate, crash mid-way, SIGTERM, pool saturation, Redis down,
   a connection returning to the pool dirty.
7. **Research.** See section 5. State what you verified by running code, with the output.

### 3.2 The invariant this task ships on

From `§3.1`, row 2, verbatim:

- A request without tenant context never reaches the DB
- Logs carry no SQL, bound parameters or secrets
- Each named guard fails on a deliberately bad file

Under `§11 › Round limit`, after the second review of a fix diff **only a failure of these three
blocks the tag.** Everything else goes to `§5` with an owner and a date.

### 3.3 Proposed gate additions

`§3.2` found that ten of the seventeen §5 rows are named by **nothing** in the Task 2 gate, so a
reviewer handed the gate alone has no reason to look for them. Three clauses cover those ten. Propose
them in the ticket for the user to approve before they are added to `§3` (a rank-3 edit):

- **(a)** A connection returned to the pool carries no open transaction and no tenant setting, proven
  by two consecutive operations on one connection. → R-022, R-020
- **(b)** No error response body carries a database role name, a constraint name, SQL or a bound
  parameter, proven against captured response bodies. → R-021, R-007
- **(c)** No test mutates the attributes of a permanent database role, proven by killing the suite
  mid-test and reading `pg_roles`. → R-018

The existing repo-guard clause already covers R-010, R-019 and the lint half of R-017.

## 4. Decisions carried from the planning chat

**These are recommendations, not user-confirmed decisions.** Restate every one at the top of your
ticket, flag that it is unconfirmed, and ask before phase 2. If the user changes one, the ticket
changes before any code exists.

| # | Decision |
|---|---|
| **B1** | Scope is all 17 §5 rows, not §0's 6. |
| **B2** | The three gate clauses in 3.3 are added to `§3` before the branch work is considered complete. |
| **B3** | R-017's depth-counter half shipped in Task 1's ninth round and is closed in §5; the lint-guard half merges into R-010. **F4 of the seventh review moves to Task 4**, whose gate already carries the only clause that can falsify it (every Redis key tenant-namespaced, one tenant's projection unreadable through another's context). |
| **B4** | Tenant scope opens **per unit of work in the service layer**, not per request. A request-scoped transaction would hold a Postgres connection for the whole request and collide with the 40-connection cap and the 503 clause. The "never reaches the DB" invariant is enforced by a **query listener that throws** when a tenant-table query runs with no open scope. |
| **B5** | The pre-Task-5 auth stub resolves its tenant id from a **container binding set only by the test harness** — never a header, never a route parameter, so no HTTP input reaches it. The third architecture guard ("production code imports no test helpers") is pointed at its namespace, so the stub surviving Task 5 is a CI failure rather than a reviewer's responsibility. |
| **B6** | Readiness opens **no DB connection on the request path**. It reads pool saturation from the pool and a cached Postgres probe refreshed off the request path. This satisfies R-012 literally and needs no `§2.1` change. |
| **B7** | Rate limiter is **fail-open** on Redis unavailability; anything carrying tenant data is **fail-closed**. The residual (an attacker who downs Redis removes rate limiting) goes to `§5` with owner Task 12. |
| **B8** | R-022 is fixed by **request-boundary cleanup** (rollback + purge on Octane's request-terminated hook), not by a precondition refusing to open inside an existing transaction — the latter would have to be reconciled with `§2.10`, where transaction level 1 is normal for every migration. |
| **D1** | Layers: `App\Domain` (no framework, no DB) · `App\Platform` (framework + DB, imports no feature module) · `App\Features\*` created empty here. Guard tool: deptrac, chosen only after verifying it supports PHP 8.4 in-session. |
| **D2** | OpenAPI generator chosen on the branch after verifying Laravel 13 / PHP 8.4 support. **No ADR** — §1 locks no OpenAPI tool, so this is a dependency choice under §11.7 (license, maintenance, CVEs), not a stack substitution. |
| **D3** | Error envelope: RFC 7807 `application/problem+json`. `type` values come from one PHP enum in `App\Domain\Http`; the OpenAPI spec is generated from it. This is the single source §6.2 requires. |
| **D4** | Shedding thresholds: pool saturation measured against `config/tenancy.php`'s app limit (40), shed at ≥ 90 %. Memory/CPU read from the Octane worker. All numbers live in `config/`, set from a measurement taken on the branch — never hard-coded in a class. |
| **D5** | The per-tenant rate limit is built **now**, keyed by tenant id per `§4 › Realtime`, exercised through the stub. Task 3/5 then wire a real tenant into an existing limiter. |
| **D6** | The `/v1` breaking-change policy is written into **`PLAN.md §6`** (rank 3), not `PROJECT_INSTRUCTIONS.md §6`. |
| **D7** | R-005's failed-bypass trace goes to a **redacted log sink**, not a second DB connection. A second connection is a new `§2.1` surface and a new failure mode. |
| **D8** | The boot refusal of `sslmode` in `{disable, allow, prefer}` outside `local`/`testing` is **built here** (boot checks are Task 2's). The `.env.example` line stays R-011's, owned by Task 13. |
| **D9** | Owner/root credentials are kept out of the runtime process by a **boot assertion that `DB_OWNER_*` and `DB_ROOT_*` are absent** outside `local`/`testing` — not by making the connection definitions conditional in `config/database.php`, which would change what migrations and `db:bootstrap` see and could break Task 1's suite silently. |
| **D10** | `TenantContext` is bound **fresh per request**, not as a singleton with an explicit flush. A singleton is the stale-state surface that produced findings in four separate Task 1 rounds. Verify Octane's container-reset semantics by running code before relying on either. |
| **D11** | Four statements per scope is **accepted deliberately** and recorded in `§2.11` as the per-request floor, to be re-measured in Task 14. Optimising a layer before any load number exists is what `§7` forbids. |

## 5. Research — verify by running code, never from memory

`PROJECT_INSTRUCTIONS.md §1` and `§8`: do not claim a library API exists without verifying it in this
session. Nothing below has been verified. Verify each before choosing, and show the output in the
ticket.

- `laravel/octane` + FrankenPHP: version compatible with Laravel 13 / PHP 8.4; the actual graceful
  shutdown API; which lifecycle hooks fire at request termination.
- Octane container-reset semantics: which bindings are flushed per request. This decides D10.
- Monolog processor API in Laravel 13, for redaction.
- OpenAPI generator candidates: Laravel 13 / PHP 8.4 support, license, maintenance, known CVEs.
- deptrac (or an alternative) on PHP 8.4, and that it can be made to fail on a deliberately bad file.
- `SET log_statement = 'none'` on the bootstrap session actually suppressing the six verifier
  occurrences R-009 measured.
- The source you will read pool saturation from, and that it reports what you think it reports.

If a version has moved, read the primary changelog before choosing.

## 6. Protocol for touching Task 1's code

Task 1's branch produced the same defect twice: a fix silently orphaned the regression test that
proved an older fix, and the suite stayed green. Both are recorded in `§4a`.

- Every fix you make is **proven by revert**: revert it, run the suite, and the named test fails.
- **The failure count must be explained.** If a revert fails a number of tests you did not predict,
  stop and find out why before reporting anything. That is how the ninth round caught a test matching
  a row left by an earlier run (R-016's predicted shape).
- If you change code that an existing test covers, check that the test still *reaches* the code. A
  test that passes because the path moved is not coverage.
- `audit_log` is never truncated in the local test database (R-016). Scope every assertion against it
  by tenant, action, or a marker unique to that run. Never a bare count.

## 7. Known traps for this task

- **`QueryException::getCode()` is not the SQLSTATE.** The real code is at
  `getPrevious()->errorInfo[0]`. `tests/TestCase.php::sqlState()` has the working shape. `§6 Open
  items` says to start there rather than re-find it.
- Error mapping reads real driver codes: `42501` RLS denied, `23505` unique, `23514` check,
  `23503` FK (`§4 › Postgres traps`).
- `pgsql_root` and `pgsql_bootstrap` are defined **unconditionally in every environment**
  (`config/database.php`). That is why R-015's real control is the boot-time credential check (D9) and
  not anything in `TenantContext`.
- C2: `set_config(name, value, true)`, third argument always a literal `true`. A session-scoped tenant
  setting is a hard failure. No new `app.*` name without editing `PROJECT_INSTRUCTIONS.md` first.
- `§4 › Edge & ops`: health and readiness must keep answering while everything else sheds. A health
  check that sheds with the rest makes the load balancer kill a task that is merely busy.
- Every dependency gets a declared fail-open or fail-closed posture, **tested by taking the dependency
  down**. "It stayed up in testing" is not a posture.

## 8. Phase 2 — after approval

Implement. Then, before opening the PR, show in the chat:

- `composer pint`, `composer stan`, `php artisan test` all green against the **real** composed
  Postgres and Redis. No DB mocks for DB behaviour.
- The same gates from a **fresh clone of the branch**, not just a fresh database. Migrations apply
  from empty; a second apply is a no-op; a rollback of this task's migrations runs clean.
- **Unit coverage ≥ 90 % on the code this task adds**, reported as a number, naming the CI run and the
  commit that produced it. Never a local measurement — the build machine has neither PCOV nor Xdebug.
- Every bug found in this task carries a regression test that fails without the fix.
- All of Task 1's tests still pass.
- Every added REST endpoint is in the committed, generated OpenAPI spec, under `/v1`.
- `PLAN.md` updated: §0, §4a with the gate evidence, §5 dispositions, §2.11 for D11, §6 for D6.

Then open the PR. The QA and Security reviews are separate agent sessions that receive the diff and
the ticket only — never your reasoning. Do not write their findings for them.

## 9. Output format

`PROJECT_INSTRUCTIONS.md §9`, binding on every message: no greetings, no apologies, no closing
remarks. CLI command first, then the code. Only the specific logic, no framework boilerplate. No
explanation unless the user's message starts with `EXPLAIN:`. When modifying existing code, never
print the whole file — use:

```
// REPLACE THIS:

// WITH THIS:
```

## 10. Start here

Begin phase 1. Read section 1's list, then produce the ticket described in section 3, with section 4's
decisions restated and flagged as unconfirmed. Then stop.
