# Task 2 — API core (`QRPAGER-9`, epic E1)

Closes all **seventeen** `§5` rows Task 2 owns, plus **H-C's two remaining boot clauses**, and builds
every clause of the `§3` row-2 gate. 29 commits, one logically complete unit each, every `§5` row proven
by revert with its failure count explained.

**Not ready to merge:** the `§11` QA review and the `§11` Security review have not run, and `§6` makes
both a precondition for the `task-2` tag. This PR is for those reviews.

## The gate, clause by clause

| Clause | Where it is proven |
|---|---|
| Boot refuses an unsafe DB role (exit 1, reason logged) | `BootChecks` + `RefuseUnsafeBoot` on `WorkerStarting`, and `php artisan boot:check` as a real process in CI, both directions |
| A request without tenant context never reaches the DB | `TenantScopeGuard` on `Connection::beforeExecuting()`, armed by a **global** middleware |
| Health reflects real Postgres + Redis state | `readiness:refresh` on the **worker** connection → one Redis key; `/readyz` reads only that |
| `SIGTERM` drains in-flight requests and exits 0, against the compiled app | `bin/drain-proof` against `dunglas/frankenphp:1.13.3-php8.4-bookworm`, in CI (Q7) |
| Captured logs carry no SQL, bound parameters or secrets | `RedactingProcessor` on every channel + `ReportRedactedException` at the producer |
| The repo guard and each named architecture guard fail on a deliberately bad file | `composer guard` + deptrac, four rules, each with a fixture |
| Every public route under `/v1`, with the versioning policy in `§6` | `RouteSurfaceTest`'s allowlist; policy written at `c8a7fac` |
| The rate limiter covers unknown routes (404s) | a rate-limited fallback route on its own tighter bucket |
| A saturated DB pool returns a fast 503, memory/CPU pressure returns 503, probes keep answering | `ShedLoad` + `PressureGauge` (cgroup v2), with an end-to-end case at 41 held connections |
| Redis taken down and the API keeps serving, posture stated and tested | `bin/redis-down-proof` in CI, plus both postures unit-tested against real Redis |
| The OpenAPI spec generated from the routes, committed, CI fails when stale | `composer openapi:check`, proven by modifying one byte |
| **(a)** A pooled connection carries no open transaction and no tenant setting | `RequestBoundaryCleanup` on **both** Octane keys, driven through the real `Worker` |
| **(b)** No error body carries a role name, a constraint name, SQL or a binding | `ProblemRenderer`, registered for every request at **any** `APP_DEBUG` |
| **(c)** No test mutates a permanent database role's attributes | `TestCase::withThrowawayRole()`, with the `kill -9` demonstration in the progress log |

## What this branch adds, in full

**120 files changed, +12,797 / −561**, measured at `eaea965` — this document's own inventory was added after it and is not in that figure. Every class below is new unless marked *(modified)*.

### `App\Domain` — no framework, no DB (deptrac enforces it)

| File | What it is |
|---|---|
| `Domain/Http/ProblemType.php` | The single source for the error envelope's `type` (D3). A string enum carrying each case's status and a **fixed** title, so the mapper takes the enum and never a string and a new outcome without a case does not compile |
| `Domain/Database/TenantTables.php` | One registry of tenant and global tables, shared by the runtime guard and by `RlsCoverageTest`, which now reads it instead of declaring `GLOBAL_TABLES` itself — a table in one copy and not the other is the §2.4 gap that file exists to close |

### `App\Platform\Database` — the tenancy and driver layer

| File | What it is |
|---|---|
| `TenantScopeGuard.php` | The first invariant. `Connection::beforeExecuting()`, attached from a `ConnectionEstablished` listener so it survives an Octane purge |
| `TenantScopeMissingException.php` | Its refusal: names the table and the connection, carries no values |
| `DriverError.php` | The one SQLSTATE reader — `getPrevious()->errorInfo[0]`, never `getCode()` |
| `DatabaseErrorMapper.php` | SQLSTATE → `ProblemType`. `42501`→403, `23505`/`23503`→409, `23514`/`22P02`→422, `08006`/`53300`/`57014`→503 |
| `TenantContext.php` *(modified)* | `hasOpenScope()` for the guard; all five `statementFailed()` sites read the real SQLSTATE; the failed-bypass trace (R-005) wraps the whole transaction |
| `TenantContextException.php` *(modified)* | Both over-claiming messages now state what was **attempted**; the docblock enumerates the five value-carrying messages by name |
| `RoleBootstrapper.php` *(modified)* | `log_statement`/`log_min_error_statement` suppressed on the bootstrap session (R-009); `withoutLeakingCredentials()` split into two phases and two messages (R-013) |
| `DatabaseRoles.php` *(modified)* | `timeoutsPastCeiling()` (R-014), which treats `0` as past every ceiling because it disables the timeout entirely |

### `App\Platform\Http` — the request surface

| File | What it is |
|---|---|
| `Problem.php` · `ProblemRenderer.php` | One RFC 7807 document for every uncaught throwable, registered for **every** request at **any** `APP_DEBUG` |
| `TenantResolver.php` | The interface Task 5 replaces the implementation of, and nothing else |
| `RequestLimiter.php` | The limiter, on the redis store explicitly, with the fail-open posture Redis actually requires |
| `Middleware/ArmTenantScopeGuard.php` | **Global** stack: arms the guard for every request, including an unmatched path |
| `Middleware/ShedLoad.php` | Fast 503 for a saturated pool and for real memory/CPU pressure. Fails **open** where readiness fails closed |
| `Middleware/RateLimit.php` | Per IP (sha1'd) **and** per tenant (from the resolver, never from the request) |
| `Middleware/RequireTenantScope.php` | Deny-by-default for `/v1`. Resolves the tenant and **opens no scope** (B4) |
| `Controllers/HealthController.php` | Liveness. Reads nothing, so nothing can block it |
| `Controllers/ReadinessController.php` | Readiness. Reads one Redis key and nothing else |

### `App\Platform` — boot, logging, readiness, Octane, auth

| File | What it is |
|---|---|
| `Boot/BootChecks.php` | Five ordered clauses: safe runtime role (H-C) · owner/root credentials absent (D9, R-015) · sslmode not downgradable (D8) · no session-scoped tenant setting (R-020) · `APP_DEBUG` off outside `local` |
| `Boot/RefuseUnsafeBoot.php` | On `WorkerStarting`, first: logs every reason at critical, then exits 1 |
| `Console/CheckBootCommand.php` | `php artisan boot:check` — the same checks as a process with an exit code |
| `Console/RefreshReadinessCommand.php` | `readiness:refresh`, the carrier that replaces Octane's Swoole-only tick |
| `Readiness/SaturationProbe.php` | Measures on the **worker** connection, which the app pool cannot starve |
| `Readiness/ReadinessStore.php` · `ReadinessSnapshot.php` | The one Redis key, and what it carries |
| `Readiness/PressureGauge.php` | cgroup v2 memory and CPU **throttling** — never `sys_getloadavg()`, which reports the host |
| `Logging/Redactor.php` · `RedactingProcessor.php` · `RedactLogs.php` | The sink: every channel, scrubbing the message **and** `context['exception']` |
| `Logging/ReportRedactedException.php` | The producer, for the emergency logger no tap can reach |
| `Octane/RequestBoundaryCleanup.php` | R-022, on **both** `OperationTerminated` and `WorkerErrorOccurred` |
| `Auth/HarnessTenantResolver.php` | The pre-Task-5 stub: a container binding, no HTTP input, and refuses outside `local`/`testing` |

### Routes and wiring

`routes/v1.php` (empty by design — every endpoint in it belongs to a later task) · `routes/probes.php`
(the two unversioned probes on their own **empty** middleware group) · `bootstrap/app.php` *(modified)* —
`apiPrefix: 'v1'`, the global arming middleware, the `api` group's three middleware in order, the
rate-limited fallback, the problem renderer and the redacted reporter · `AppServiceProvider` *(modified)*
— `TenantContext` becomes `scoped()` (D10), the guard and gauge are singletons, `TenantResolver` is bound.

**The served surface is three routes**, which `RouteSurfaceTest` pins as an allowlist:

```
GET /healthz                 probe group (empty)
GET /readyz                  probe group (empty)
GET {fallbackPlaceholder}    RateLimit:unknown
```

### The guards

`guard/src/SourceGuard.php` + `guard/bin/guard` — five C1/C2 rules, scanning **string literals** through
`token_get_all` so the guard does not flag the documentation of its own rule, with one exception by
**path and method**. `deptrac.yaml` — four rules: Domain imports nothing · Platform imports no Feature ·
nothing imports `Tests\*` · **Features may not import the auth stub** (B5). Twelve fixtures
under `guard/fixtures/` — the bad shapes each guard must reject, plus the clean ones that prove it does
not over-reach (`comment-only`, which spells a forbidden `set_config` out inside a comment and must pass).

## Configuration, packages, runtime and CI

| | |
|---|---|
| Packages | `laravel/octane:^2.22` · `dedoc/scramble:0.13.50` (**exact**, no caret — R-026) · `deptrac/deptrac:^4.7` (dev) |
| Config added | `config/octane.php` (the two-key listener registration is the point of the file) · `config/shedding.php` · `config/ratelimit.php` · `config/scramble.php` |
| Config modified | `config/tenancy.php` (timeout ceilings + exemptions) · `config/logging.php` (the `RedactLogs` tap on every channel, plus the `tenancy` channel for R-005) · `config/filesystems.php` (`serve => false`) · `phpstan.neon` |
| Composer scripts | `composer deptrac` · `composer guard` · `composer openapi` · `composer openapi:check` |
| Runtime | `Dockerfile` (`dunglas/frankenphp:1.13.3-php8.4-bookworm`, extensions installed and asserted) · `docker/Caddyfile` · `.dockerignore` · a `frankenphp` compose service behind a profile |
| Proof scripts | `bin/drain-proof` · `bin/redis-down-proof` · `bin/openapi-check` |
| CI | `composer openapi:check` · `boot:check` in both directions · `ALTER SYSTEM log_statement=all` + a Postgres-log scan for the verifier across the whole run · a new **`runtime`** job running both proofs against the built image |
| Committed artefact | `docs/openapi.json`, generated from the routes, byte-identical across three consecutive exports |

## The test inventory — 341 cases, 1,149 assertions

Eighteen new files, eight existing test files changed, plus `TestCase`:

| New file | Cases | Proves |
|---|---|---|
| `Feature/Database/TenantScopeGuardTest.php` | 10 | The first invariant, with the case that tells `beforeExecuting` from `DB::listen` |
| `Feature/Boot/BootChecksTest.php` | 13 | All five boot clauses, plus the exit code as a real process |
| `Feature/Http/ShedLoadTest.php` | 13 | The fast 503, the cgroup gauge, and the open/closed asymmetry |
| `Feature/Architecture/RepoGuardTest.php` | 10 | C1/C2 against the bad fixtures |
| `Feature/Database/RefusalDiagnosticsTest.php` | 8 | R-013, R-023, R-024 |
| `Feature/Http/ProblemEnvelopeTest.php` | 8 | Gate clause (b), against captured **bodies** |
| `Feature/Http/RateLimitTest.php` | 8 | Both keys, both postures, the 404 bucket |
| `Feature/Http/RouteSurfaceTest.php` | 9 | The `/v1` clause and the route allowlist |
| `Feature/Logging/LogRedactionTest.php` | 9 | The second invariant, against the file the logger wrote |
| `Feature/Readiness/ReadinessCarrierTest.php` | 7 | R-012's measured proof at 40/40 |
| `Feature/Http/TenantResolutionTest.php` | 7 | B5: no HTTP input reaches the resolver |
| `Feature/Database/FailedBypassTraceTest.php` | 6 | R-005 |
| `Feature/Octane/RequestBoundaryTest.php` | 6 | R-022, through the real `Worker` |
| `Feature/Database/DriverErrorTest.php` | 6 | The SQLSTATE reading, where `getCode()` agrees and where it does not |
| `Feature/Architecture/ArchitectureGuardTest.php` | 6 | The three named guards + B5's fourth |
| `Feature/Architecture/AuditLogAssertionTest.php` | 5 | R-016's scan, block-aware and comment-blind |
| `Feature/Http/OpenApiSpecTest.php` | 5 | The spec, and the staleness check failing on one changed byte |
| `Feature/Database/BootstrapLoggingTest.php` | 4 | R-009, against the real container log |

Support: `tests/Support/OctaneWorker.php` (the real worker, with the container bookkeeping),
`GuardFixtures.php`, `DeliberateWorkerFailure.php`, and `TestCase` gains `actingForTenant()`,
`withThrowawayRole()`, `runMarker()`/`auditAction()`/`auditContext()`, `suiteStartedAt()` and a stale
probe-role sweep.

## What was removed, and why

| Removed | Why |
|---|---|
| `GET /up` | Duplicated liveness and ran the framework's dependency-touching checks — the opposite of what §4 › Edge & ops asks of a health check. Verified beforehand that nothing called it |
| `GET /` + `resources/views/welcome.blade.php` | The skeleton's welcome page: a public route outside `/v1`, so the gate and the placeholder test contradicted each other |
| `GET`/`PUT storage/{path}` | From the local disk's `serve => true`. The `PUT` writes request content into `storage/app/private` behind a signed relative URL; S3 is the locked storage (ADR-002) and uploads are Task 7's |
| `tests/Feature/ExampleTest.php` | It asserted `GET /` returns 200 |
| Scramble's `docs/api`, `docs/api.json` and dev-tools asset route | The spec is a committed file diffed in CI; an endpoint listing every route is not a surface to leave open and then gate |

## The commits

Three of them are the phase-1 ticket and the baseline; the rest are one logically complete unit each.

| | | |
|---|---|---|
| 1 | `0dac0cf` | the phase-1 ticket, with the research that changed four decisions |
| 2 | `d38a576` | the seven questions settled, and the B6 carrier answered |
| 3 | `439eaba` | §4a's baseline — two of the three invariants **false** at `7f1c8f6` |
| 4 | `86ec04a` | the three named architecture guards |
| 5 | `fb35ee9` | deptrac's cache out of the repo — Task 0's bug 1, repeated by me |
| 6 | `0b59659` | **R-010, R-019** — the C1/C2 repo guard |
| 7 | `c8a7fac` | the `/v1` surface, the two probes, D6's policy |
| 8 | `081ad9d` | progress log |
| 9 | `2655bdd` | **R-007 (T2 half), R-021** — one RFC 7807 envelope |
| 10 | `8075aec` | the second invariant: Monolog redaction |
| 11 | `c95c87e` | progress log |
| 12 | `34abd69` | **the first invariant** — the `beforeExecuting` guard |
| 13 | `e5b7868` | **R-022** — the request boundary, both ways a request ends |
| 14 | `0a5feb3` | **H-C ×2, R-015, R-020** + D8, D9 — boot refuses to serve |
| 15 | `ccca185` | **R-013, R-023, R-024** — a refusal says what it knows |
| 16 | `453f05d` | **R-014** — the timeout ceiling |
| 17 | `34f7204` | **R-018** — the throwaway role |
| 18 | `447002d` | **R-016** — the run marker and the scan |
| 19 | `c426f45` | **R-009** — the verifier reaches the log nowhere |
| 20 | `8a79554` | **R-005** — the failed-bypass trace |
| 21 | `0a1cc35` | **R-012 (T2 half)** — the readiness carrier, measured |
| 22 | `d4edbd2` | deny-by-default, both rate-limit keys, the 404 bucket |
| 23 | `8a366a1` | load shedding |
| 24 | `ed1e4fc` | the OpenAPI spec, generated and diffed |
| 25 | `73a5d78` | the pinned runtime, with both proofs in CI |
| 26 | `6b99a48` | `PLAN.md` — §0, the gate clauses, §2.11, §4a, every §5 disposition |
| 27 | `202b146` | progress log — all seventeen rows, with every revert proof |
| 28 | `5f081b7` | the Redis proof waited on a signal that is always success |
| 29 | `2e26d71` | the fresh-clone run, and the one flake it found |
| 30 | `b35511a` | this document, in the repo |
| 31 | `eaea965` | ignore `report.md` |

## Four measurements that changed a design, not just an implementation

1. **`DB::listen()` fires AFTER execution.** The planning chat specified a query listener (B4) for this
   task's headline invariant. A listener that throws raises the exception and the row is **already
   written**; `beforeExecuting` leaves `rows written = 0`. B4's mechanism would have made this task's own
   invariant test **green while being false**. Reproduced inside the suite so the choice cannot be
   quietly undone.
2. **One Octane hook is not enough.** A request that throws out of the gateway never fires
   `RequestTerminated`, and the next request on that worker entered at transaction level 1 carrying the
   previous request's tenant uuid. `FrankenPhpClient` is not a `StoppableClient`, so nothing stopped the
   worker either.
3. **`log_statement = 'none'` does not cover a statement that FAILS.** `log_min_error_statement`
   defaults to `error` and logs the text in full, so the path a human is most likely to read was the one
   still publishing the role verifier.
4. **`statement_timeout = 0` disables the timeout entirely while being the smallest interval,** so a
   ceiling that compared magnitudes would have waved through exactly the value it exists to stop.

## Four controls that were not controls, each found by reverting

- R-016's block-aware scan changes nothing when reverted **alone** — the proof is reverting it together
  with the unfiltered count, which puts the suite back where it started.
- R-023's swallow case asserted its message inside `if ($caught instanceof TenantContextException)`, and
  that path answers with a `QueryException`, so the assertion never ran.
- R-018's first revert passed the bootstrap-convergence site, because `db:bootstrap` converges
  `qrpager_app`'s attributes just as happily as a throwaway's.
- R-005's key-hashing case built its own key and asserted the hash it had just computed.

## Three tests that passed for the wrong reason

- "Take Redis away" by changing `database.redis.default.port` does nothing to the rate limiter: the cache
  store uses the **`cache`** connection and the cache manager keeps the resolved store.
- A rate-limit bucket configured at **0** attempts lets the first request through.
- R-009's container-log window, at second resolution, caught or missed a neighbouring test's leak
  depending on how fast the suite ran.

## Two deliberate trades, stated rather than buried

- **The tenant-scope guard is armed per request, not always.** The invariant is about a request, and the
  suite proves the RLS backstop by querying a tenant table with no scope and expecting zero rows. An
  always-armed guard would have needed a suspension helper for those tests — an escape hatch in the one
  class that exists to have none. Console, scheduled and queue work is left to RLS, which is what it
  already was.
- **`routes/drain.php` is loaded when it exists and the environment is not production.** A drain is only
  observable with a request that outlives the signal. The file is mounted into the drain container and
  never committed, with tests asserting its absence from the repository and from the served surface. The
  alternative — a sleep in a middleware behind a header — is a production code path a request can trigger.

## Two new `§5` rows, named rather than hidden

- **R-026** — `dedoc/scramble` pinned pre-1.0 (`0.13.50`). Pinned exactly with no caret, spec committed,
  CI diffs a fresh export. Owner **Task 12**.
- **R-027** — an attacker who downs Redis removes rate limiting, because the limiter fails open (B7).
  Every fail-open event is logged; per-IP and per-tenant are separate keys; Task 13's WAF rules do not
  depend on Redis. Owner **Task 12**, with Task 13.

## Evidence

From a **fresh clone** (`§6`: a pass that depends on the builder's machine is not a pass):

```
pint      passed            stan      0 errors
deptrac   0 violations      guard     C1 and C2 hold across 6 scanned path(s)
openapi   in sync           boot:check passed, exit 0
test      341 passed, 1149 assertions   (198 at 7f1c8f6)

drain  FrankenPHP v1.13.3 PHP 8.4.27 (ZTS) · in-flight 200 {"completed":true,"held_seconds":3.004} · exit 0
redis  /healthz 200 · /readyz 503 · unknown path 404 · recovered to 200
pool   40 held, the 41st refused 08006 · worker probe still returned saturation 1 · both probes answered
```

The 240 figure was re-measured on a second machine and was identical. **Coverage is deliberately not
quoted** — `§4a` forbids measuring it locally, so the figure is the CI run's.

The `404` in the Redis line is the one to read: that request goes through the rate limiter, which cannot
reach Redis, so 404 means it failed open. **500** would mean a `RedisException` reached the renderer,
which is the default behaviour this code replaces.

## Reviewers

`docs/tickets/QRPAGER-9-progress.md` has one entry per commit: what, the `§5` rows, the test, the revert
with every failure count explained, and what was found on the way. `PLAN.md §4a` has the invariants
re-measured against the `7f1c8f6` baseline table, and every `§5` row's "Review by" cell now names the
commit that closed it and how.

## Owed to the user (Q6)

Both `§4` rank-2 edits landed at `0b59659` and must be pasted into the claude.ai Project instructions, or
the two copies disagree:

1. `§4 › Postgres traps`: "A **repo guard in lint** rejects…" → "A **repo guard in CI** rejects…"
2. `§4 › Edge & ops`: "No secrets in logs. Redaction tested against captured log output." → "No secrets
   in logs, **or in an error response body, at any `APP_DEBUG` setting**. Redaction tested against
   captured log output **and against captured response bodies**."
