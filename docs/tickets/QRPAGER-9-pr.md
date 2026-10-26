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
