# QRPAGER-9 — Task 2 progress

One entry per logically complete unit: what was done, which `PLAN.md §5` row it closes, the test that
proves it, and the revert result **with its failure count explained**. A revert whose failure count I
cannot account for stops the run (`§6` of the ticket prompt).

---

## Phase 1 — the ticket (2026-10-26)

| | |
|---|---|
| What was done | Read `PROJECT_INSTRUCTIONS.md`, `PLAN.md §0 §1 §2 §3 §3.1 §3.2 §5 §6`, the three ADRs, and the Task 1 code named in the prompt. Ran every item of the prompt's §5 research list that could be run in this session. Wrote `docs/tickets/QRPAGER-9.md` |
| §5 rows closed | none — phase 1 writes no application code |
| Tests | none added. Baseline re-measured at `7f1c8f6`: `composer pint` passed · `composer stan` 0 errors · `php artisan test` **198 passed, 580 assertions**, 36.44s, against the composed Postgres 16.14 + Redis 7. Matches `§0`'s figure for `8dcc2ba`, so that record is accurate |
| Revert proof | n/a |

**Research done by running code** — full output in the ticket's §7. Seven results changed or refined a
planning decision, and two are defects no `§5` row covers:

1. `DB::listen()` fires **after** execution, so a listener that throws does not stop the query (row
   written). `Connection::beforeExecuting()` does (no row written). **B4's stated mechanism would have
   made the task's own invariant test green while false.**
2. A request that throws out of Octane's gateway never fires `RequestTerminated`; the next request on
   that worker enters at `transactionLevel 1` carrying the previous request's `app.tenant_id`.
   `FrankenPhpClient` is not `StoppableClient`, so nothing stops the worker either. **B8 needs a second
   hook on `WorkerErrorOccurred`.**
3. Ticks are Swoole-only, so **B6 has no "off the request path" carrier** under FrankenPHP.
4. Role connection-limit exhaustion surfaces as **`08006`**, not `53300`. `sys_getloadavg()` reports the
   host inside a container. **D4 refined.**
5. Redis down makes the cache **and** the rate limiter throw `RedisException`. **B7 is code, not a
   property.**
6. `TenantContext` is already resolved fresh per request under Octane, but accidentally — it is a
   `singleton()` that nothing warms. **D10: use `scoped()`.**
7. One tenant scope costs **3 statements of overhead + 2 transaction round trips** (6 on the wire for a
   one-query scope), measured from the Postgres log. **D11's "four" is the `DB::listen` count.**
8. New: `QueryException::getCode()` returns the integer **`7`** for connection-level failures where the
   SQLSTATE is `08006`, so the five `TenantContextException::statementFailed()` sites and
   `RoleBootstrapper::withoutLeakingCredentials()` print "SQLSTATE 7". R-013 names only the latter.
9. New: by default the log **and** the 500 body carry the SQL and the bound parameters; the
   `UnsafeDatabaseRoleException` body carries the role name (R-021 reproduced).

R-009's own measurement reproduced (6 verifier occurrences in the Postgres log per bootstrap) and its
one-line fix verified to take it to 0, session-scoped, leaving R-004's `log_statement=all` intact.

**Blocked, and named in the ticket as Q7:** the FrankenPHP binary could not be downloaded or executed in
this session (sandbox policy), so its bundled PHP version is unverified and the gate's
"`SIGTERM` … proven against the compiled app" clause has no route to a proof yet.

**Open:** seven questions (Q1–Q7) in the ticket's §0.3. Phase 2 does not begin until they are answered.

Housekeeping, so it is recorded rather than noticed later: four untracked `*:Zone.Identifier` files
(Windows download markers beside `docs/PLAN.md`, `docs/claude/*` and the prompt) were deleted. No tracked
file and no document content was touched.
