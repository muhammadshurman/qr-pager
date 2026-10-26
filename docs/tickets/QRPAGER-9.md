# QRPAGER-9 — Task 2, API core — the ticket

Role: BUILDER (`PROJECT_INSTRUCTIONS.md §11`). Branch `task-2-api-core`. Epic E1 (`QRPAGER-2`).
Written 2026-10-26, phase 1. **No application code exists from this phase.**

Baseline measured at `7f1c8f6` before anything was planned, so phase 2 has a figure to regress against:
`composer pint` **passed** · `composer stan` (level 8) **0 errors** · `php artisan test`
**198 passed, 580 assertions** in 36.44s against the composed Postgres 16.14 and Redis 7. That matches
`§0`'s record for `8dcc2ba`, so `§0`'s suite figure is accurate as written.

---

## 0. Stop-and-ask — this ticket does not start until these are settled

### 0.1 The planning chat's decisions, restated. **All unconfirmed.**

| # | Decision, as written in the prompt | Status |
|---|---|---|
| **B1** | Scope is all 17 §5 rows, not §0's 6 | **unconfirmed — Q1** |
| **B2** | The three gate clauses in §3 below are added to `PLAN.md §3` before the branch is complete | **unconfirmed — Q2** (rank-3 edit) |
| **B3** | R-017's depth-counter half is closed; its lint half merges into R-010; **F4 moves to Task 4** | **unconfirmed — Q3.** The depth counter *is* in the code at `7f1c8f6` (`TenantContext::$openScopes` is `array<string,int>`, incremented in the transaction body and decremented-then-dropped in the `finally`), so that half is genuinely done and §5's row text is stale |
| **B4** | Tenant scope opens per unit of work in the service layer; the invariant is enforced by a query listener that throws | **CHANGED BY MEASUREMENT — see 0.2 (i).** The per-unit-of-work half stands; "query listener" does not |
| **B5** | The pre-Task-5 auth stub resolves its tenant id from a container binding set only by the test harness; deptrac's third guard points at its namespace | unconfirmed, and verified buildable — deptrac catches `App\Platform\*` importing `Tests\*` (0.2 proof 5) |
| **B6** | Readiness opens no DB connection on the request path; reads pool saturation plus a cached Postgres probe refreshed off the request path | **REFINED BY MEASUREMENT — see 0.2 (iii).** FrankenPHP has no tick, so "off the request path" needs a named carrier |
| **B7** | Rate limiter fail-open on Redis unavailability; anything carrying tenant data fail-closed; residual to §5 owner Task 12 | unconfirmed, and **confirmed to need explicit code** — see 0.2 (v) |
| **B8** | R-022 is fixed by request-boundary cleanup on Octane's request-terminated hook, not by a precondition | **CHANGED BY MEASUREMENT — see 0.2 (ii).** That hook alone leaves the hole open |
| **D1** | `App\Domain` / `App\Platform` / `App\Features\*`; guard tool deptrac, after verifying PHP 8.4 | verified — deptrac 4.7.2, exit 1 on a bad file, exit 0 clean |
| **D2** | OpenAPI generator chosen on the branch after verifying Laravel 13 / PHP 8.4; no ADR | verified — **`dedoc/scramble` 0.13.50** recommended. **Q5**, because it is pre-1.0 |
| **D3** | RFC 7807 `application/problem+json`; `type` values from one PHP enum in `App\Domain\Http`; the spec generated from it | **verified end to end** — Scramble emitted the enum as `#/components/schemas/ProblemType` with all four cases |
| **D4** | Shedding thresholds in `config/`, from a measurement on the branch; pool saturation against the app limit (40), shed at ≥ 90 % | **REFINED — see 0.2 (iv).** The saturation *signal* is not the SQLSTATE anyone would guess, and `sys_getloadavg()` is the wrong CPU source in a container |
| **D5** | The per-tenant rate limit is built now, keyed by tenant id, exercised through the stub | unconfirmed |
| **D6** | The `/v1` breaking-change policy goes in `PLAN.md §6` (rank 3), not `PROJECT_INSTRUCTIONS.md §6` | unconfirmed — and **Q4** asks what `/v1` covers |
| **D7** | R-005's failed-bypass trace goes to a redacted log sink, not a second DB connection | unconfirmed |
| **D8** | The boot refusal of `sslmode` in `{disable, allow, prefer}` outside `local`/`testing` is built here; `.env.example` stays R-011's | unconfirmed |
| **D9** | Owner/root credentials kept out of the runtime process by a boot assertion that `DB_OWNER_*`/`DB_ROOT_*` are absent outside `local`/`testing` | unconfirmed |
| **D10** | `TenantContext` bound fresh per request, not a singleton with an explicit flush; verify Octane's container-reset semantics by running code | **verified, and the repo is accidentally right — see 0.2 (vi).** Recommend making it explicit |
| **D11** | Four statements per scope accepted deliberately, recorded in `§2.11`, re-measured in Task 14 | **measured exactly — see 0.2 (vii).** The number four is right for what `DB::listen` reports and wrong for the wire |

### 0.2 What measurement changed. Every item below was run in this session; output is in §7.

**(i) B4's mechanism is wrong, and it is the mechanism the task's own invariant stands on.**
`DB::listen()` fires **after** execution — the event carries the elapsed time. Measured on
`password_reset_tokens`: a `listen()` callback that throws raised the exception **and the row was
written** (`rows written = 1`). `Connection::beforeExecuting()` fires before execution: the same throw
left **`rows written = 0`**. A `listen()`-based guard would make "a request without tenant context never
reaches the DB" **test green while being false**.
The guard must be `beforeExecuting`, registered from an `Illuminate\Database\Events\ConnectionEstablished`
listener so it reattaches to connections created after an Octane purge or reconnect. Proven to cover
`select`, `insert`, `update`, `delete`, `unprepared` and the query builder — all of them funnel through
`Connection::run()`, which invokes the callbacks at `Connection.php:808` — and proven to survive
`DB::purge()` and to let an unrelated statement through.

**(ii) B8's hook is necessary and not sufficient.** Driving the real `Laravel\Octane\Worker` with
`FakeClient`: a request that leaves a transaction open fires `RequestTerminated` (which implements
`Laravel\Octane\Contracts\OperationTerminated`, the key the stub config listens on), the cleanup rolls
back, and the next request enters at level 0 with `app.tenant_id` empty. A request that **throws out of
the gateway** never fires it — `Worker::handle()`'s `catch` goes to `handleWorkerError()`, which
dispatches `WorkerErrorOccurred` only. Measured: the next request then entered at
**`transactionLevel = 1`, `inTransaction = true`, `app.tenant_id` still holding the previous request's
uuid**. And `FrankenPhpClient` does **not** implement `StoppableClient`, so `StopWorkerIfNecessary` does
nothing under the locked runtime and the worker keeps serving on that connection.
The cleanup therefore registers on **both** `OperationTerminated` and `WorkerErrorOccurred`.

**(iii) B6's "off the request path" has no carrier under FrankenPHP.** `Worker::handleTick()` is called
from exactly one place in the package, `bin/swoole-server`. Ticks are Swoole-only, so `TickReceived`
never fires under FrankenPHP. The replacement is **`readiness:refresh`**, a sub-minute scheduled command
on the **worker** connection writing one Redis key, with readiness reading only that key — specified
and measured in **§6.3**, including the part that decides it: the probe must not run on the connection
whose saturation it measures.

**(iv) The saturation signal is `08006`, not `53300`.** Held 40 `qrpager_app` PDO connections against
the live cluster; the 41st failed with `errorInfo[0] = 08006` and the message
`FATAL: too many connections for role "qrpager_app"`. A mapper keyed on `53300 too_many_connections`
would **never fire**. From an already-open connection the probe reads
`{"app_backends":40,"max_connections":"100","reserved":"3","role_limit":40}`, which is the number D4
wants. Separately: `sys_getloadavg()` works but reports the host, which under Fargate is the instance and
not the task — rejected. cgroup v2 is the right source (`memory.current`, `memory.max`, `cpu.max`,
`cpu.stat`'s `usage_usec`/`nr_throttled`), with the caveat that `memory.max` reads `max` where no limit
is set, so the code needs an absolute configured ceiling as a fallback.

**(v) Redis down does not degrade, it throws.** With `REDIS_PORT` pointed at a dead port before boot,
`Redis::ping()`, `Cache::store('redis')->get()`, `RateLimiter::attempt()` and
`RateLimiter::tooManyAttempts()` **all** threw `RedisException: Connection refused` (phpredis 6.3.0).
So "the API keeps serving with Redis down" is false by default and B7's fail-open posture is code that
has to be written and tested, not a property to assert.

**(vi) D10 is already true by accident.** Three requests through the real worker resolved
`App\Platform\Database\TenantContext` as object ids 675, 580, 566 — a different instance each time,
although `AppServiceProvider` binds it with `singleton()`. The reason is that it is not in `octane.warm`,
so it is never resolved in the root app and each request resolves it inside `clone $this->app`, which
`$sandbox->flush()` discards. Anything that resolves it during boot moves it into the root app and it
becomes shared for the worker's life. Recommend `scoped()` instead of `singleton()`, which
`FlushTemporaryContainerInstances` forgets on `OperationTerminated` — the guarantee then survives
someone warming it. The `db` manager is warmed and shared (object id 283 on all three requests), and the
PDO handle was identical across all three (676).

**(vii) The per-scope cost, from the Postgres log rather than from PHP.** One `withTenant()` scope
containing one caller query is **4 statements as `DB::listen` reports them** — `readState`, `set_config`,
the caller's query, the `MATERIALIZED` read-back-and-clear — which is 3 of overhead, independent of the
work inside (a scope with three caller queries reported 6). On the wire it is **6 round trips**, because
`BEGIN` and `COMMIT` go through PDO and `DB::listen` never sees them. The log also shows `readState`
running **before** `BEGIN`, which is R-022 visible in a server log.

**(viii) Two defects measured that no §5 row covers.** Both are contained and both are in scope under
§11's merge rule once found:
- `QueryException::getCode()` returns the SQLSTATE for statement errors (`42P01`, `22P02`, `22012` all
  agreed with `errorInfo[0]`) but returns the **integer `7`** for connection-level failures, where the
  real SQLSTATE is `08006` — measured for both a refused connection and a wrong password. So
  `TenantContextException::statementFailed()` and `RoleBootstrapper::withoutLeakingCredentials()`, which
  both use `getCode()`, print **"SQLSTATE 7"** — not a SQLSTATE at all — for every connectivity failure.
  R-013 names this for the bootstrapper only; the five `statementFailed()` call sites in `TenantContext`
  have the same bug and are not in any row.
- Default Laravel behaviour **leaks SQL and bound parameters into both the log and the 500 body.**
  Measured: `Log::error($e->getMessage(), ['exception' => $e])` for a `QueryException` wrote 22 lines
  containing the SQL and the interpolated uuid; the JSON 500 body for the same exception carried both;
  the JSON 500 body for `UnsafeDatabaseRoleException` carried `qrpager_app` and the reason (R-021,
  reproduced); the HTML debug page was 862 KB and carried everything. Two of the gate's clauses are
  therefore false until this task writes the redaction and the mapper.

### 0.3 Questions — **answered 2026-10-26.** What phase 2 is now bound to.

| | Answer | What it binds |
|---|---|---|
| **Q1 scope** | **All 17 §5 rows** (B1 confirmed) | R-005, R-007 (T2 half), R-009, R-010, R-012 (T2 half), R-013, R-014, R-015, R-016, R-017, R-018, R-019, R-020, R-021, R-022, R-023, R-024 — §4 below is the whole of it |
| **Q2 gate clauses** | **Approved** | The three clauses in §3 go into `PLAN.md §3` row 2 (rank-3 edit) before the branch is complete (B2) |
| **Q3 R-017** | **Approved per B3** | Depth-counter half marked closed in §5 (it is in the code at `7f1c8f6`); lint half ships under R-010; **F4 moves to Task 4** |
| **Q4 `/v1`** | **Approved — the probes stay unversioned.** Verified before removal, as asked: the **only** reference to `/up` anywhere outside `vendor/` is `apps/api/bootstrap/app.php:13` (`health: '/up'`). No compose healthcheck targets the application — `compose.yaml` has only `postgres` (`pg_isready`) and `redis` (`redis-cli ping`), each checking itself. The CI workflow never curls it; its only `--health-*` flags belong to the two service containers. No test and no doc references it. **Removing it breaks nothing** | `/healthz` + `/readyz`, unversioned; `/up` removed; D6's policy text in `§6` lists them as the explicit exception |
| **Q5 OpenAPI** | **`dedoc/scramble`, pinned to exactly `0.13.50` — no caret.** The pre-1.0 risk goes to `PLAN.md §5` as a new row, **owner Task 12** | `composer require dedoc/scramble:0.13.50`. The committed spec means a generator change surfaces as a spec diff in CI |
| **Q6 §4 rank-2 edits** | **Approved — both.** "A **repo guard in lint**" → "**in CI**"; "No secrets in logs" gains "…or in an error response body, at any `APP_DEBUG` setting" | **Action owed to the user:** when the two edits land, tell them, so they paste the same text into the claude.ai Project instructions. `§0`'s "Where everything lives" already records that the Project copy of `§11` is out of step; this adds `§4` to that debt unless it is pasted |
| **Q7 FrankenPHP** | **Container, and the drain proof runs in CI, not only locally.** Image pinned to **`dunglas/frankenphp:1.13.3-php8.4-bookworm`** | A `frankenphp` service in `compose.yaml` and a CI job that sends `SIGTERM` to it mid-request and asserts the in-flight request completes and the process exits 0 |

**Q7's verification, run in this session as asked:**

```
$ docker run --rm dunglas/frankenphp:1.13.3-php8.4-bookworm php -v
PHP 8.4.27 (cli) (built: Oct 21 2026 15:55:55) (ZTS)
Copyright (c) The PHP Group
Built by https://github.com/docker-library/php
Zend Engine v4.4.27, Copyright (c) Zend Technologies
    with Zend OPcache v8.4.27, Copyright (c), by Zend Technologies

$ docker run --rm dunglas/frankenphp:1.13.3-php8.4-bookworm frankenphp version
FrankenPHP v1.13.3 PHP 8.4.27 Caddy v2.11.7 h1:yj0Y4fYZGPkSvibBJ1sTWE33xC0fxztVyXEW5iIdUT4=
```

**PHP 8.4.27** — the same patch version as this machine's CLI, so `§1`'s locked PHP 8.4 holds. It is a
**ZTS** build, which is correct and not incidental: FrankenPHP's worker mode is threaded, and an
extension compiled for NTS will not load in it.

Two things that follow, both measured rather than assumed:

- **The tag must name the PHP version.** `dunglas/frankenphp:1.13.3` alone is ambiguous —
  `1.13.3-php8.5` and `1.13.3-php8.5.12` exist in the same release, so the unqualified tag can float to
  a PHP this project does not run. Task 0 already lost time to a file telling agents to install PHP 8.5
  against `§1` (`§4a`). Pin `1.13.3-php8.4-bookworm`, or `1.13.3-php8.4.27-bookworm` to pin the patch.
- **The image ships almost none of the extensions this app needs.** Measured inside it: `PDO`,
  `mbstring` and `posix` are present; **`pdo_pgsql`, `redis`, `pcntl`, `intl` and `bcmath` are not.**
  `install-php-extensions` is at `/usr/local/bin/install-php-extensions`, so the Dockerfile installs
  them. `pcntl` is not optional: Laravel's `trap()` is how `schedule:work` — the readiness carrier in
  §6.3 — drains on `SIGTERM`. The FrankenPHP drain itself is Go-side and needs no extension.

**No `§2` data-contract change is required by anything in this ticket** — no table, column, enum value,
role, GUC or grant. Both GUCs are read-only through `TenantContext`; all four roles are read-only through
the boot guard. D7's log sink is a Monolog channel, not the second connection `§2.1` would have had to
describe. If phase 2 finds otherwise it stops and `§2` is updated first (`§6.1`).

---

## 1. The §3 acceptance gate — verbatim from `PLAN.md §3`, row 2

> Boot refuses an unsafe DB role (exit 1, reason logged); a request without tenant context never reaches
> the DB; health reflects real Postgres + Redis state; `SIGTERM` drains in-flight requests and exits 0
> (proven against the compiled app); captured logs contain no SQL, no bound parameters, no secrets;
> **the repo guard and each named architecture guard fail on a deliberately bad file** (C1/C2 enforced
> mechanically); **every public route is under `/v1`, and the versioning policy for a breaking change is
> written into §6**; **the rate limiter covers unknown routes (404s), or the gap is written into §5 with
> the control that covers it**; **a saturated DB pool returns a fast 503, and memory/CPU pressure returns
> 503 — while health and readiness keep answering**; **Redis is taken down and the API keeps serving,
> with the rate limiter's fail-open/fail-closed posture stated and tested**; **the OpenAPI spec is
> generated from the routes, committed, and CI fails when it is stale** (Blueprint §7 API).

Task column, for what has to exist: Octane/FrankenPHP boot, config validation, tenant-context middleware
(`set_config(..., true)` per transaction), safe-role boot refusal, error mapping to real driver codes,
Monolog redaction, rate limiting, health + readiness, graceful shutdown, **named architecture guards in
CI** (domain logic imports no framework or DB code · platform layer imports no feature modules ·
production code imports no test helpers), **a repo guard that rejects session-scoped tenant settings and
unknown `app.*` names**, **OpenAPI spec generation wired**, **`/v1` route versioning**.

## 2. The invariant this task ships on — verbatim from `PLAN.md §3.1`, row 2

> - A request without tenant context never reaches the DB
> - Logs carry no SQL, bound parameters or secrets
> - Each named guard fails on a deliberately bad file

`§3.1` audited this gate on 2026-10-26 and recorded **"Nothing. Strong, and mechanically testable."**
Under `§11 › Round limit`, after the second review of a fix diff only a failure of those three blocks the
tag; everything else goes to `§5` with an owner and a date.

## 3. Proposed gate additions — rank-3 edit, Q2

`§3.2` found that ten of the seventeen rows are named by nothing in the gate, so a reviewer handed the
gate alone has no reason to look for them. Three clauses cover those ten:

- **(a)** A connection returned to the pool carries no open transaction and no tenant setting, proven by
  two consecutive operations on one connection. → **R-022, R-020**
- **(b)** No error response body carries a database role name, a constraint name, SQL or a bound
  parameter, proven against captured response bodies. → **R-021, R-007**
- **(c)** No test mutates the attributes of a permanent database role, proven by killing the suite
  mid-test and reading `pg_roles`. → **R-018**

The existing repo-guard clause already covers R-010, R-019 and the lint half of R-017. Clause (a) is
already measured as failing today (0.2 ii), so it is a clause with a red test behind it from the start,
not an aspiration.

## 4. The §5 debt this task owns — 17 rows, each with its fix shape and the test that proves it

Every row's acceptance condition is its own "Review by" cell in `§5`; the shapes below are built from
those cells, not invented. Every fix is proven by revert, and **the failure count is explained before it
is reported** (§6 of the prompt).

| Row | Sev | Fix shape | The test that proves it |
|---|---|---|---|
| **R-005** | Med | D7: on a `Throwable` inside `withoutTenantScope()`, write the failed-bypass trace to a dedicated redacted Monolog channel before rethrowing. No second connection | A bypass that throws leaves **0** `audit_log` rows (already true) **and** one line in the captured channel naming the reason, with no SQL, bindings or GUC name |
| **R-007** (T2 half) | Low | `DatabaseErrorMapper` keyed on `getPrevious()->errorInfo[0]`: `42501`→403, `23505`→409, `23514`→422, `23503`→409, `08006`→503, each a `problem+json` document whose `type` is a `App\Domain\Http` enum case | A real `23514` (an un-lower-cased email) returns a body carrying no constraint name, no SQL and no bindings |
| **R-009** | Med | `SET log_statement = 'none'` on the bootstrap session, before the advisory lock | The test sets `log_statement = 'all'` **cluster-wide as root first**, then asserts the bootstrap session reports `none` — otherwise it passes for the wrong reason in CI, whose Postgres service defaults to `none`. Measured end to end locally: **6 verifier occurrences → 0** |
| **R-010** | Med | A source-scanning guard: no `set_config(…, false)`, no raw `SET app.*`, no `app.*` name outside C1. Shipped as a `composer guard` script **and** a Pest test, so CI fails independently of the suite | Deliberately-bad fixtures in `tests/Fixtures/guard/` (which the repo-wide scan skips and the test feeds in explicitly) make the guard report each violation; the guard exits non-zero |
| **R-012** (T2 half) | Low | B6: readiness opens no connection; it reads the key `readiness:refresh` writes from the **worker** connection (§6.3) | 40 app connections held, then `/readyz` **answers** and `/healthz` returns 200. Reverting to a readiness query gives `08006` and a 500 |
| **R-013** | Low | Distinguish "the statement failed" from "the statement was never built", and read the real SQLSTATE rather than `getCode()` | A connectivity failure names `08006` (today it would print "SQLSTATE 7" — 0.2 viii); a `format()` failure says the statement was never built; neither message contains a verifier substring |
| **R-014** | Low | A ceiling assertion on `config('tenancy.timeouts')`, so a far-too-high configured value fails fast instead of hanging the suite | Setting `app.statement_timeout` to `600s` in config makes the ceiling test fail. The firing proofs keep their lower bound only (F10) |
| **R-015** | Low | Closed by D9's boot assertion, which is where `§5` already puts the real control | With `APP_ENV=production` and `DB_OWNER_PASSWORD` resolvable, boot refuses: exit 1, reason logged |
| **R-016** | Low | A per-run marker: `TestCase::runMarker()` into `audit_log.context` for every row the suite writes, and every assertion filters on it | A source scan fails on any `audit_log` assertion with no filter. `ConnectionReuseTest`'s `rollback.probe` and `WorkerScopeTest`'s unfiltered count are the two sites to convert |
| **R-017** | Med | Depth counter: **already shipped** (verified in the code). Lint half: R-010. F4: Task 4 per B3 | R-010's fixture test. §5's row text is corrected to say the counter landed |
| **R-018** | Low | One shared fix: `TestCase::withThrowawayRole()` creates `qrpager_probe_<rand>`, grants the attribute, connects on a temporary connection, drops it in `finally`. The bootstrap convergence test points `config('tenancy.roles.app')` at the throwaway role | The three sites keep their refusal assertions; a new assertion holds `pg_roles.rolbypassrls = false` for `qrpager_app` throughout; and the `kill -9` demonstration R-018 asks for is scripted into the progress report |
| **R-019** | Low | The guard's single named exception, **by path** — `tests/Support/Composition::setSessionGuc()` — never "tests are exempt" | Moving the helper, or adding a sixth call site elsewhere, fails the guard |
| **R-020** | Low | A boot check that refuses to serve on a connection carrying a session-scoped tenant setting, plus the operator remedy documented in `§2.10` | A session-scoped GUC on the app connection makes the boot check refuse |
| **R-021** | Low | A generic `problem+json` 500 for `UnsafeDatabaseRoleException`; role and reasons in the log, absent from the body, at any `APP_DEBUG` | Measured today: the JSON body carries `qrpager_app` and the reason. After the fix the body carries neither and the log carries both |
| **R-022** | Med | B8 as corrected: rollback-and-purge registered on **both** `OperationTerminated` and `WorkerErrorOccurred`. No precondition, so `§2.10`'s transaction level 1 stays normal. Stores no tenant id | The two-operation test on one connection, plus the failing-request variant. Measured without the fix: the next request enters at level 1 carrying the previous request's uuid. Reverting either half fails its own test |
| **R-023** | Low | Both messages state what was **attempted**; the one swallowing `catch` gets a test | The commit-then-reopen path asserts the row **survives** and the message claims no rollback; a forced `rollBack()` throw reaches the `catch` |
| **R-024** | Low | Test the invalid-UTF-8 fallback in `TenantContextException::printable()`; the docblock enumerates **five** messages, or counts none | An invalid-UTF-8 byte sequence through a real refusal path fails when the fallback body is replaced by a constant |

Also in scope because measurement found them (0.2 viii), both contained: the five
`TenantContextException::statementFailed()` call sites read `getCode()` and must read
`errorInfo[0]`; and the Monolog redaction has to scrub `context['exception']`, not only the message,
because the rendered trace carries the SQL on its own.

## 5. Out of scope — the owner is named, nothing here gets built

| Excluded | Owner |
|---|---|
| Any route that creates, reads or mutates a pager session | Task 3 |
| QR token generation, hashing, lookup | Task 3 |
| The outbox worker, Web Push, Redis status projection, the polling endpoint | Task 4 |
| Real authentication, users, passkeys, 2FA, RBAC | Task 5 |
| Any Filament panel or page | Tasks 6–7 |
| Any frontend workspace, token package, TypeScript (`packages/contracts` does not exist yet) | Task 8 |
| CSP, CORS, SAST/DAST, ASVS L2 table | Task 12 |
| Terraform, deployment, `sslmode=verify-full`, connection-limit sizing | Task 13 |

## 6. The seven `§6` pre-coding answers

**1. Tables / columns / roles / GUCs touched.** **No schema change.** No migration in this task touches
rows or structure. Both GUCs are read and written only through `App\Platform\Database\TenantContext`
(`app.tenant_id` via `set_config(…, true)`; `app.bypass_tenant` only inside `withoutTenantScope()`), and
no new `app.*` name is introduced — C1 unchanged. All four roles are read-only: `RuntimeRoleGuard` asks
one connection for `current_user` and its `pg_roles` attributes, and D9's new boot assertion reads the
**environment** (whether `DB_OWNER_*`/`DB_ROOT_*` resolve), not the database. `config/database.php` keeps
`pgsql_root` and `pgsql_bootstrap` defined unconditionally, deliberately: making them conditional would
change what migrations and `db:bootstrap` see and could break Task 1's suite silently (D9). New tables
read by the guard: none — the tenant-table list the `beforeExecuting` guard matches on comes from one
registry shared with `RlsCoverageTest`'s allowlist, so a table cannot be in one and not the other.

**2. Shared contracts used or added.** Three, each with one source and a CI staleness check:

| Contract | Single source | What makes CI fail when it is stale |
|---|---|---|
| Error envelope `type` values | `App\Domain\Http\ProblemType` (PHP enum, string-backed, one case per mapped condition) | It is the only producer of `type`; the mapper takes the enum, never a string. A new condition without a case does not compile |
| The OpenAPI document | the routes and their return types, read by `dedoc/scramble` | CI runs `php artisan scramble:export --path=$(mktemp)` and `diff`s it against the committed `docs/openapi.json`. Verified: the export command exists and writes OpenAPI 3.1.2, and the enum above lands as `#/components/schemas/ProblemType` with every case |
| Health/readiness response shape | the controller's declared return type | Same `diff`. Scramble infers the schema from the code, so a shape change that is not re-exported fails CI |

`packages/contracts` is **not** created — that is Task 8 (ADR-003). The existing `App\Domain\DatabaseEnums`
registry and `EnumContractTest` are untouched.

**3. The exact path by which tenant context reaches every query.**

```
HTTP request
  → /v1/* route, middleware group 'api.v1'
  → RequireTenantScope middleware: resolves the tenant id and binds it, nothing more — it opens no scope
  → controller → service method = ONE unit of work (B4)
      withTenant($tenantId, fn () => …)                     app/Platform/Support/helpers.php
        → TenantContext::run()                               app/Platform/Database/TenantContext.php
            readState()                      1 round trip, BEFORE the transaction
            assertRowLevelSecurityApplies()  refuses a superuser/BYPASSRLS connection first (H-C)
            $db->transaction(fn () => …)     BEGIN
              set_config('app.tenant_id', ?, true)           C2: third argument always literal true
              …the unit of work…
              assertScopeSurvived()          transaction depth postcondition
              clearScope()                   MATERIALIZED read-back proves the scope was still ours
            COMMIT
```

*Where a scope opens:* in the service layer, per unit of work, never per request. A request-scoped
transaction would hold one of the forty `qrpager_app` connections for the whole request and collide with
both the connection cap and the 503 clause (B4).

*What enforces that a tenant-table query outside a scope cannot happen:*
`App\Platform\Database\TenantScopeGuard`, registered once on `ConnectionEstablished` and attached with
`Connection::beforeExecuting()`. It throws `TenantScopeMissingException` when the statement references a
tenant table and `TenantContext` holds no open scope on that connection. Measured properties (§7): it
fires **before** the statement reaches Postgres, it covers every query method including `unprepared` and
the builder, and it reattaches after `DB::purge()`. RLS is still the backstop — the guard turns a silent
zero-row answer into a loud refusal, it does not replace the policies.

*Which routes legitimately have no tenant, and how they are exempted:* `/healthz` and `/readyz` (B6 —
they open no connection at all on the request path, so there is nothing for the guard to exempt); and
queries against the four global tables (`migrations`, `users`, `password_reset_tokens`, `failed_jobs`),
which the guard does not match because they carry no tenant column — the same list as `§2.4`, read from
the shared registry so the two cannot drift. Nothing else is exempt: there is no allowlist of routes.

### What replaces the tick — the readiness carrier

The prompt's research list asked which source readiness reads and whether it reports what I think it
reports. Measurement (0.2 iii) removed the obvious answer: `Worker::handleTick()` is called from exactly
one place in Octane, `bin/swoole-server`, so **ticks do not exist under FrankenPHP** and there is no
in-process timer to hang a probe on. What replaces it, with every part verified:

**`readiness:refresh` — a sub-minute scheduled command, on the worker connection, writing one Redis key.**

| Part | Why that part, and what proves it |
|---|---|
| **Cadence: `->everyFiveSeconds()`** | Verified present in Laravel 13 (`ManagesFrequencies.php:109`), and verified to actually fire sub-minute: `ScheduleRunCommand::repeatEvents()` loops until the end of the minute re-running repeatable events, while `schedule:work` starts one `schedule:run` per minute. So a 5-second cadence is real, from one extra process, with no tick |
| **Carrier process: `php artisan schedule:work`** | Locally a second terminal; on Fargate a second container **in the same task definition**, so it shares the task's lifecycle. That co-location is what makes staleness meaningful (below). It needs `pcntl` in the image — measured absent from the base image |
| **Probe connection: `pgsql_worker`, never `pgsql`** | **This is the load-bearing choice.** A probe that measures app-role saturation must not be subject to it. Measured: with `qrpager_app` at **40/40** and a new app connection refused `08006`, the worker-role probe still returned `{"app_backends":40,"app_limit":40,"saturation":1}`. A non-superuser can read `usename` from `pg_stat_activity`, so no `pg_read_all_stats` grant and no `§2` change is needed. It costs one long-lived connection out of the worker's separate 20-slot budget |
| **What it writes** | One Redis key: `{saturation, app_backends, app_limit, postgres_ok, redis_ok, measured_at}` |
| **What `/readyz` does** | Reads that key. **Nothing else.** Zero Postgres round trips on the request path, which is B6 literally, and the reason R-012's coupling cannot recur |
| **What the shedding middleware does** | Reads the same key, so the fast 503 for a saturated pool is also DB-free on the request path |
| **Staleness** | The value carries `measured_at`; older than a configured budget (proposed: 3× the cadence, 15 s) ⇒ **not ready**. Because the carrier is a container in the same task, a dead carrier is a task that should be replaced, so "stale ⇒ not ready" is the correct reading rather than a dangerous one |
| **Redis down** | No value to read ⇒ not ready, which is the gate's "health reflects real Postgres + Redis state". `/healthz` reads **nothing at all** and still returns 200, so neither a saturated pool nor a dead Redis can make the load balancer kill a task that is merely busy (`§4 › Edge & ops`) |

Rejected, each for a measured reason rather than a preference:

- **Octane ticks** — Swoole-only, measured.
- **A sampled refresh on the `/v1` request path** (refresh at most once per *N* seconds from requests
  that already hold a connection). Needs no second process, and fails under zero traffic: the value goes
  stale and both readings are wrong. "Stale ⇒ not ready" kills an idle task; "stale ⇒ ready" means
  readiness has stopped reflecting real state, which is the clause it exists for.
- **Readiness queries Postgres itself** — fails exactly when it matters. Measured: at 40/40 a new app
  connection is refused `08006`, so readiness would return 500 under the one condition it exists to
  report. That is R-012's coupling restated.
- **A dedicated second connection for readiness** — a new `§2.1` surface and a new failure mode, which
  is the same shape D7 rejected for R-005.

**4. Security.**

- *authn:* none exists before Task 5. Every `/v1` route is deny-by-default behind
  `RequireTenantScope` plus a guard middleware; the pre-Task-5 stub
  (`App\Platform\Auth\HarnessTenantResolver`) resolves its tenant id from a **container binding set only
  by the test harness** — never a header, never a route parameter, so no HTTP input reaches it (B5).
  deptrac's third guard is pointed at its namespace, so the stub surviving Task 5 is a CI failure rather
  than a reviewer's job. The customer token path is Task 3's and is not built here.
- *authz:* route default is deny; the two probes are the only explicitly public routes and are listed in
  D6's policy text with their justification.
- *input validation per entry point:* `/healthz` and `/readyz` take no input. Everything else in this
  task is middleware and error handling, which take no user-supplied fields; the first validated bodies
  arrive in Task 3. Mass assignment: no new model.
- *rate limit:* per IP **and** per tenant (`§4 › Realtime`), keyed by tenant id from the stub (D5), in
  Redis. It covers unknown routes (404s) by being registered on the fallback route as well, which is the
  gate's own clause. Posture: **fail-open** on Redis unavailability, which needs explicit handling of
  `RedisException` because the limiter throws (0.2 v). The residual — an attacker who downs Redis removes
  rate limiting — goes to `§5` with owner Task 12 (B7).
- *log redaction:* a Monolog processor on the stack channel, scrubbing the message **and**
  `context['exception']`. Required: by default the log carries the SQL and the bindings (0.2 viii).
  Verified API: `ProcessorInterface::__invoke(LogRecord): LogRecord` with
  `LogRecord::with(message:, context:)`, because `message` and `context` are readonly. Wired through the
  channel's `tap`, which is what the `single`/`daily` drivers support (`processors` is `monolog`-driver
  only, read from `LogManager`).
- *secrets:* none added. `.env.example` gains nothing. R-009's one-line `SET` keeps the role verifier out
  of the Postgres log. gitleaks already runs pre-commit and over full history in CI.
- *config validation at boot:* one ordered check that exits 1 with the reason logged — the runtime role
  is safe (`assertSafeRuntimeRole()`, Task 1's guard, wired here and widened per D9); owner/root
  credentials are not resolvable outside `local`/`testing` (D9); `sslmode` is not in
  `{disable, allow, prefer}` outside `local`/`testing` (D8); the connection carries no session-scoped
  tenant setting (R-020); `APP_DEBUG` is false outside `local`.

**5. Scale.**

- *Per request:* the two probes cost **0 Postgres and 0 Redis round trips** on the request path —
  readiness reads a value a scheduled command wrote (B6, carrier per 0.2 iii). A `/v1` request costs
  one rate-limiter Redis round trip plus, per unit of work, **3 statements of tenant-scope overhead and
  2 transaction control round trips** on top of its own queries — measured from the Postgres log, not
  asserted (0.2 vii and §7). D11's "four statements per scope" is the count `DB::listen` reports for a
  one-query scope; the wire figure is 6 round trips. Both numbers go into `§2.11`.
- *Per active session:* unchanged by this task — 1 `pager_sessions` row + 0-N `push_subscriptions` rows
  (`§2.11`). This task adds no per-session state.
- *What is bounded:* concurrent app connections, hard, at **40** by `ALTER ROLE … CONNECTION LIMIT`
  (measured: the 41st connection is refused). Rate-limiter keys are bounded by TTL. The OpenAPI document
  is a committed file. What is **not** bounded and is not this task's: `outbox` and `audit_log` growth
  (`§6`, Task 13/14).
- *Accepted deliberately (D11):* the 3-statement scope overhead. Optimising it before any load number
  exists is what `§7` forbids; Task 14 re-measures.

**6. Failure.**

| Mode | Behaviour, and what proves it |
|---|---|
| timeout | `statement_timeout` 5s for `app` fires `57014` (Task 1's `RoleTimeoutTest`, lower bound only per F10); the mapper turns it into a 503 `problem+json` |
| retry | The probes are idempotent. Nothing else in this task mutates. `markReady`'s idempotency is Task 3's |
| duplicate | No write path added |
| crash mid-way | The scope's transaction rolls back; `assertScopeSurvived()` and `clearScope()` refuse a derailed block loudly. The new request-boundary cleanup rolls back whatever the crash left (R-022) |
| SIGTERM | FrankenPHP stops accepting, `frankenphp_handle_request()` returns false, the worker loop exits, `finally` calls `Worker::terminate()` → `WorkerStopping` → the drain listener closes DB connections → exit 0. Read from `bin/frankenphp-worker.php`; the compiled-app proof needs **Q7** |
| pool saturation | New connections fail with **`08006`**, not `53300` (measured). The mapper returns a fast 503; the probes keep answering because they open nothing |
| Redis down | Everything Redis throws `RedisException` (measured). Rate limiter: fail-open, logged. Anything carrying tenant data: fail-closed. Readiness reports not-ready; health still 200 |
| a connection returning to the pool dirty | **Measured as broken today**: the next request on the same worker enters at `transactionLevel 1` with the previous request's `app.tenant_id` still set, and after a *failed* request even the `RequestTerminated` hook never fires. Fixed by the two-hook cleanup; clause (a) is the gate half |

**7. Research.** §7 below. Every claim in this ticket that names a number or an API was produced by
running code in this session. One thing is still unmeasured and says so: the CI coverage figure, which
`§4a` forbids measuring locally. The FrankenPHP static binary stayed unrunnable here, so the runtime is
the container instead and its PHP version **is** verified (§0.3).

## 7. Research — what was run, and what it returned

Versions resolved against this repo's lock, in a scratchpad copy so `apps/api` is untouched:
`laravel/framework 13.38.0` · `php 8.4.27` · `laravel/octane v2.22.0` (requires
`laravel/framework ^11|^12|^13`, `php ^8.2`) · `deptrac/deptrac 4.7.2` · `dedoc/scramble 0.13.50` ·
`monolog/monolog 3.12.1` · phpredis 6.3.0. `composer audit`: **no security vulnerability advisories
found**, before and after adding all three packages.

1. **Octane container-reset semantics (D10).** Real `Laravel\Octane\Worker` + `FakeClient`, three
   requests: `TenantContext` object ids **675, 580, 566**; `db` manager **283** on all three; PDO **676**
   on all three. A `singleton()` not in `octane.warm` is per-request because each request resolves it in
   `clone $this->app` and `$sandbox->flush()` discards it.
2. **Request-termination hooks and a dirty connection (R-022, B8).** Request 1 leaks a transaction →
   `RequestTerminated` fires at `transactionLevel=1`, cleanup rolls back, request 2 enters at level 0
   with an empty GUC. Request 3 leaks **and throws** → only `WorkerErrorOccurred` fires → request 4
   enters at `transactionLevel=1`, `inTransaction=true`,
   `app.tenant_id = 11111111-1111-1111-1111-111111111111`. `FrankenPhpClient` is not `StoppableClient`.
3. **`beforeExecuting` vs `listen` (B4, the invariant).** `listen()` + throw → `rows written = 1`.
   `beforeExecuting()` + throw → `rows written = 0`. The `ConnectionEstablished`-registered guard refused
   `select`, `insert`, `update`, `delete`, `unprepared` and the builder, refused again after
   `DB::purge()`, and let `select 1 as ok` through.
4. **Monolog (redaction).** `ProcessorInterface::__invoke(LogRecord): LogRecord`;
   `LogRecord::with(message:, context:)` replaced the readonly message and left the original untouched;
   the captured line read `probe.ERROR: [redacted message] {"kept":2}`.
5. **deptrac (D1, three architecture guards).** Clean repo: `Violations 0, Allowed 24`, exit 0. A
   `App\Domain\Bad` class importing `Illuminate\Support\Facades\DB`: `Violations 1`, **exit 1**,
   `DependsOnDisallowedLayer`. An `App\Platform\Bad` class importing `Tests\Support\Composition`:
   `Violations 1`, **exit 1**. Removing both files: back to 0 and exit 0.
6. **R-009's measurement and its fix.** One `db:bootstrap` with `log_statement=all`: **116** new
   container-log lines, **0** plaintext passwords, **6** `SCRAM-SHA-256` occurrences — twice per role,
   once as `format()`'s bound parameter and once in the executed `ALTER ROLE` text. (R-009 records 110
   lines; this run had the database and roles already present, which is the difference. The figure that
   matters, 6, reproduced exactly.) With `SET log_statement = 'none'` on the bootstrap session: **39**
   lines, **0** `SCRAM-SHA-256`, **0** `ALTER ROLE`. Verified session-scoped, not cluster-wide: the
   separate grant connection kept logging its `GRANT`/`REVOKE` statements, so R-004's
   `log_statement=all` survives for Task 4's proof.
7. **Pool saturation (D4, B6, R-012).** 40 `qrpager_app` connections opened, the 41st refused with
   `errorInfo[0] = 08006` and `FATAL: too many connections for role "qrpager_app"`. From an open
   connection: `{"app_backends":40,"max_connections":"100","reserved":"3","role_limit":40}`. Ticks are
   Swoole-only (`handleTick` called from `bin/swoole-server` alone). cgroup v2 readable;
   `sys_getloadavg()` returns host figures; `memory.max` reads `max` with no limit set.
   **The carrier (§6.3), measured separately:** with `qrpager_app` held at 40/40 and a new app
   connection refused `08006`, the same probe statement run as **`qrpager_worker`** still returned
   `{"app_backends":40,"app_limit":40,"saturation":1}` — a non-superuser reads `usename` from
   `pg_stat_activity` with no `pg_read_all_stats` grant and no `§2` change. Sub-minute scheduling
   verified in the framework source: `ManagesFrequencies::everyFiveSeconds()` exists, and
   `ScheduleRunCommand::repeatEvents()` re-runs repeatable events until the end of the minute, so
   the 5-second cadence is real without a tick.
8. **Redis down (B7).** Dead port set before boot: `ping`, `Cache::get`, `RateLimiter::attempt` and
   `tooManyAttempts` all threw `RedisException: Connection refused`. (A first run reported `ping: OK`;
   that was a stale facade root resolved before the config change, not a property of Redis — re-run
   cleanly and corrected, because a figure I cannot explain is not a figure.)
9. **SQLSTATE (trap 7, R-013).** `42P01`, `22P02`, `22012`: `getCode()` and `errorInfo[0]` agree.
   Connection refused **and** wrong password: `getCode()` = **`7`**, `errorInfo[0]` = **`08006`**.
   `tests/TestCase.php::sqlState()` is the shape to reuse.
10. **What leaks today.** `QueryException` through `Log::error(..., ['exception' => $e])`: 22 lines
    carrying the SQL and the bound uuid. JSON 500 body for the same: carries both. JSON 500 body for
    `UnsafeDatabaseRoleException`: carries `qrpager_app` and the reason (R-021). HTML debug page:
    862,104 bytes, carries everything.
11. **Scope cost (D11).** `DB::listen` on one scope with one query: 4 statements (`readState`,
    `set_config`, the query, the read-back-and-clear). With three queries: 6 — so 3 of overhead,
    independent of the work. From the Postgres log, one scope is 6 round trips:
    `readState` → `BEGIN` → `set_config` → the query → the read-back → `COMMIT`, with `readState`
    outside the transaction.
12. **OpenAPI (D2, D3).** `scramble:export --path=` wrote OpenAPI **3.1.2** from the real routes with no
    annotations; a `/v1` prefix was picked up via `api_path`; a controller returning a DTO typed with a
    string-backed PHP enum produced `#/components/schemas/ProblemType` with all four cases as `enum`.
    Caveat for phase 2: Scramble infers `const` from literal constructor arguments, so a controller must
    return a declared shape rather than one branch's literals or the spec over-constrains.
13. **FrankenPHP (Q7, now verified).** Octane requires ≥ **1.13.0**; release **v1.13.3** (2026-10-23),
    whose notes record worker mode broken for symlinked document roots in 1.13.0–1.13.2, so ≥ 1.13.3 is
    the floor. The **static binary** could not be downloaded or executed here (sandbox policy), so the
    runtime is the **container**: `dunglas/frankenphp:1.13.3-php8.4-bookworm` reports
    `PHP 8.4.27 (cli) … (ZTS)` and `FrankenPHP v1.13.3 PHP 8.4.27 Caddy v2.11.7` — output in §0.3.
    The unqualified `1.13.3` tag is ambiguous (`1.13.3-php8.5` ships in the same release), so the tag
    names the PHP version. The image ships `PDO`, `mbstring`, `posix` and **not** `pdo_pgsql`, `redis`,
    `pcntl`, `intl` or `bcmath`; `install-php-extensions` is present to add them.
14. **Q4, before removing anything.** `/up` is referenced in exactly one place outside `vendor/`:
    `apps/api/bootstrap/app.php:13`. `compose.yaml`'s two healthchecks are `pg_isready` and
    `redis-cli ping`, each against its own service; CI's `--health-*` flags belong to the same two
    service containers and nothing curls the app. No test, no doc, no workflow calls it.

**New `§5` row to write in phase 2 (Q5).** *`dedoc/scramble` is pinned at a pre-1.0 version (`0.13.50`).*
Severity Low. Why accepted: it is the only candidate that generates the spec from the routes rather
than from hand-written attributes, which is what `§6.2` and the gate's own wording require; the
alternative's second source is the larger risk. Compensating control: the version is pinned exactly
with no caret, the generated spec is committed, and CI `diff`s a fresh export against it — so a
generator behaviour change appears as a spec diff on a deliberate upgrade and cannot arrive
silently. Owner **Task 12** (`QRPAGER-19`), which owns the SAST/DAST and dependency pass. Review by:
at Task 12's acceptance gate, or when Scramble reaches 1.0, whichever is first.

Repeatable probes, if you want to re-run any of it:
`/tmp/claude-1000/-home-lenovo-code-qr-pager/072d249a-9782-4a5c-9240-efaab327a002/scratchpad/` —
`octane-worker-probe.php`, `octane-lifecycle-probe.php`, `listener-probe.php`, `guard-probe.php`,
`monolog-probe.php`, `queryexception-probe.php`, `sqlstate-probe.php`, `body-probe.php`, `pool-probe.php`,
`redis-probe2.php`, `scope-cost-probe.php`, `scope-wire-probe.php`, `readiness-carrier-probe.php`,
against the sandbox copy in `probe/`.
Nothing in `apps/api` was modified in phase 1.

## 8. Phase 2 — what gets built, once the questions are answered

Commands, in order:

```
composer require laravel/octane:^2.22 "dedoc/scramble:0.13.50"   # exact pin, no caret (Q5)
composer require --dev deptrac/deptrac:^4.7
# No octane:install -- the runtime is the pinned container (Q7):
#   dunglas/frankenphp:1.13.3-php8.4-bookworm, + pdo_pgsql redis pcntl intl bcmath
```

Files, by layer — `App\Domain` (no framework, no DB) · `App\Platform` (framework + DB, imports no
feature module) · `App\Features\*` created empty (D1):

- `app/Domain/Http/ProblemType.php` — the one source for `type` (D3)
- `app/Domain/Database/TenantTables.php` — the registry the guard and `RlsCoverageTest` share
- `app/Platform/Database/TenantScopeGuard.php` + `TenantScopeMissingException.php` — `beforeExecuting`
- `app/Platform/Database/DatabaseErrorMapper.php` — real driver codes via `errorInfo[0]`
- `app/Platform/Http/Middleware/RequireTenantScope.php`, `RateLimit.php`
- `app/Platform/Http/Controllers/HealthController.php`, `ReadinessController.php`
- `app/Platform/Logging/RedactingProcessor.php` — message **and** `context['exception']`
- `app/Platform/Boot/BootChecks.php` — role, credentials, sslmode, session GUC, debug (D8, D9, R-020)
- `app/Platform/Octane/RequestBoundaryCleanup.php` — on `OperationTerminated` **and**
  `WorkerErrorOccurred` (R-022)
- `app/Platform/Auth/HarnessTenantResolver.php` — the stub deptrac is pointed at (B5)
- `app/Platform/Console/RefreshReadinessCommand.php` (`readiness:refresh`) — the carrier, on the
  **worker** connection, `->everyFiveSeconds()` (§6.3)
- `Dockerfile` + a `frankenphp` service in `compose.yaml` — pinned image, extensions installed
- `guard/` + `composer guard` — the C1/C2 source scan (R-010, R-019), with its bad fixtures
- `deptrac.yaml`, `config/octane.php`, `config/scramble.php`, `config/shedding.php`, `routes/v1.php`
- `docs/openapi.json` — committed, diffed in CI

CI gains: `composer guard`, `vendor/bin/deptrac analyse`, the OpenAPI staleness `diff`, a Redis-down
job, and **the SIGTERM drain proof — in CI, not only locally (Q7)**: start the pinned FrankenPHP
container, hold one slow in-flight request, send `SIGTERM`, assert the in-flight request completes and
the process exits 0. Then the three gates green from a fresh clone, the coverage number from the CI run that
produced it, `PLAN.md` updated (`§0`, `§4a`, `§5` dispositions **plus the new pre-1.0 Scramble row owned by
Task 12**, `§2.11` for D11, `§6` for D6), the two `§4` rank-2 edits made **and reported to the user so
they can paste them into the Project instructions (Q6)**, and the PR.
No merge and no `task-2` tag until both `§11` reviews have run.
