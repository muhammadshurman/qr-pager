# QRPAGER-10 — Task 3, Pager domain — the ticket

> Branch `task-3-pager-domain`. Epic **E2**. Built by an unattended builder session on
> 2026-10-27.
>
> **Both `§11` reviews have since run (2026-10-27), each in its own session with the diff
> and this ticket only: 18 findings — 0 Critical, 2 High, 8 Medium, 8 Low.** The fix round
> is applied and `PLAN.md §4a` carries the dispositions. Two claims in `§6.4` below were
> measured false by those reviews and are **corrected in place**, marked where they appear.
> **7 of the QA review's 12 findings were never relayed to the fix session and are
> therefore undispositioned** — see `PLAN.md §0`, `W-12`. This ticket is not the record of
> the reviews; `§4a` is.

Read with `docs/tickets/QRPAGER-10-handover.md`, which carries what is done, what is not,
the gate figures and the commits they were measured at.

---

## 0. Where the branch was cut, and the one deviation from the instruction

The instruction was "branch `task-3-pager-domain` off `main` at `2e42267`". The branch was
cut off **`main`'s tip, `3fcabb5`**, which *contains* `2e42267`. The three commits between
them are **docs-only** — `git diff --stat 2e42267 main` is `docs/PLAN.md | 108 +++---`, one
file — and they are the commits that record Task 2 as merged and tagged and close `W-2`,
`W-3`, `W-5` and `W-7`.

Branching at `2e42267` exactly would have meant editing a `PLAN.md` whose `§0` had since
been corrected three times, and every `§0`/`§4a` edit this task makes would have reverted
those corrections on merge. That is `R-025`'s defect — this file asserting something
measurement contradicts — manufactured deliberately. The alternative is recorded here
rather than taken.

---

## 1. The `§3` acceptance gate — verbatim from `PLAN.md §3`, row 3

> **The status change, the outbox row and the audit row commit in one transaction, or none
> of them does — proven by injecting a failure after the status write and asserting all
> three are absent**; `markReady` is idempotent under concurrent calls — one outbox row,
> one notification; invalid state transitions rejected; cross-tenant IDOR blocked through
> HTTP, not just at the DB; expiry job uses `SELECT id` → `UPDATE ... WHERE id = ? AND
> status = ?` with **no `RETURNING`**; raw token never appears in any log, URL or error
> body (asserted against captured output).

Task column, for what has to exist: create session (128-bit CSPRNG token, hash-only
storage, 2 h TTL, QR payload), `markReady`, `renotify`, `complete`, `cancel`, expiry job.

## 2. The invariant this task ships on — verbatim from `PLAN.md §3.1`, row 3

> - The status change, outbox row and audit row commit together or not at all
> - `markReady` is idempotent under concurrent calls
> - cross-tenant IDOR blocked through HTTP, not just at the DB
> - the raw token never reaches a log, URL or error body
>
> **`§3.1` row 3 was amended on 2026-10-27 by Muhammad** (security S-1), after this ticket
> was written. That fourth clause now reads: *the raw token never reaches a log, a URL path
> or query string, an error body, or anywhere a server receives or logs it* — and a fifth
> was added: *a session past its TTL accepts no staff transition*. The quotation above is
> the wording this task was built against; `PLAN.md §3.1` is the wording it ships against.

`§3.1` row 3 records **"Done 2026-10-26 — the one-transaction clause moved into the
gate"**, so this gate needed nothing at the start of this task. Under `§11 › Round limit`,
after the second review of a fix diff only a failure of those four blocks `task-3`.

## 3. The `§5` debt this task owns — six rows

Every row's acceptance condition is its own *Review by* cell in `§5`. `§0` required
**R-049** and **R-054** to be settled "in one edit, before its own work starts"; they were
done first, before any feature code, and the gate transcript proving it is the first one
on the branch.

| Row | Sev | Its *Review by*, in one line | What was done |
|---|---|---|---|
| **R-049** | Med | Hold a `pg_advisory_lock` for the life of a suite process, or give each runner its own database. Proven by two concurrent invocations on two files, both green | A process-lifetime advisory lock on a raw PDO handle `DB::purge()` cannot reach. Proven the way the row was found |
| **R-054** | Med | Either make the step emit frames and keep the claim, or narrow the claim in all three places | **Frames, claim kept** — and the row's own premise was measured wrong. See `§4` |
| **R-003** | Med | The two policies are narrowed and the expiry job re-proven in the same change | Narrowed to time **+ a status whitelist**; both proven, with the counterfactual |
| **R-004** | Low | Before Task 3 writes its first token; Task 4 scopes the setting per-session | `log_statement=all` removed from `compose.yaml`. Task 4's half is **dissolved, not deferred** |
| **R-033** | Med | The first route that can return a problem document declares it, and the spec is asserted to carry `#/components/schemas/ProblemType` with every case | Done, and the `Problem` schema was corrected on the way — see `§4` |
| **R-042** | Med | A `BadRequest` case mapping 400, 402/406/410/415 mapped or collapsed, `fromStatus()`'s default changed to `ServerError` | Done, in that order, after R-033 |

## 4. What measurement changed — every item below was run in this session

**(i) `R-054`'s premise is environment-specific, and the row states it as a property of
the project.** The row says "this machine's Pest prints one JSON line — identical piped and
on a PTY, so the `tee` is not the cause". The `tee` is indeed not the cause. The cause is
**`laravel/pao`**, which replaces the console output style when
`Laravel\AgentDetector\AgentDetector::detect()->isAgent` — a detector over ~40 vendor
environment variables, `AI_AGENT` and `CLAUDECODE` among them. Both are set inside an agent
session and neither is set in an ordinary terminal, so **the gate log's content depended on
who ran the gate**. Measured: unsetting `AI_AGENT` *or* `CLAUDECODE` alone still gave JSON;
unsetting both gave Pest's normal output.

`laravel/pao` has a supported off switch, checked before anything else —
`PAO_DISABLE` (`vendor/laravel/pao/src/Laravel/ServiceProvider.php:22`). `bin/gate` now
exports it, so the kept log is the same artifact for every runner. With it set, inside this
agent session, a deliberate red run recorded the test name, the exception class, the
`SQLSTATE[...]`, the source excerpt **and the frame list** (`+6 vendor frames`, then
`tests/.../RedProbeTest.php:9`). So the claim in `bin/gate`, `CLAUDE.md` and `R-049`'s
compensating control is **kept, and is now true for every runner** rather than narrowed to
a subset of what the file holds.

**And the row's "no stack" is not quite right either.** Measured on the JSON path:
`failures[]` (an assertion failure) carries `test`, `file`, `line`, `message` and **no**
`trace`; `error_details[]` for a `RuntimeException` thrown in the test body likewise. But
`error_details[]` for a **`QueryException`** — the shape `R-049` actually was — carried
`trace` with two frames, one of them the test file and line. So the artifact was richer for
the one incident the row is about than the row says. Recorded because the row is the
compensating control for `R-049` and it understated itself in the direction nobody checks.

**(ii) `qrpager_app` cannot reference `token_hash` at all, not merely read it.** `§2.7`
says the column is "write-only for `app`", and the consequence is stronger than the phrase
suggests: measured through the real stack, `select token_hash …` **and** `where token_hash
= decode(?, 'hex')` both raise `42501`. A column-level `SELECT` grant is required to name a
column in a predicate, not only in a select list. So `§2.8`'s `SECURITY DEFINER` function
is the **only** way the application can resolve a token — it is mechanically forced, not a
design preference. This is the single most useful thing measured for this task and it is
why the lookup is a database function rather than a query.

**(iii) `R-003`'s narrowing holds, with the counterfactual that pins the wording.** Run
against the cluster on a throwaway database, as `qrpager_worker` with no tenant context:

| Statement | Before (time-only) | After (time + status whitelist) |
|---|---|---|
| `select id, status` cross-tenant | every historical session | **only `waiting`/`ready` past TTL** |
| expire a past-TTL `waiting` row | `UPDATE 1` | `UPDATE 1` |
| expire a past-TTL `ready` row | `UPDATE 1` | `UPDATE 1` |
| expire another tenant's **`completed`** row | `UPDATE 1` — the hole `R-003` names | **`UPDATE 0`** |
| expire another tenant's **`cancelled`** row | `UPDATE 1` | **`UPDATE 0`** |
| expire a row still inside its TTL | `UPDATE 0` | `UPDATE 0` |
| `waiting → ready` on a past-TTL row | `42501` | `42501` |

And the counterfactual, which is why `'expired'` is in the **SELECT** whitelist and not
only in the UPDATE one: with `'expired'` removed from `worker_reads_expired_sessions`, the
prescribed expiry statement raises **`42501` — new row violates row-level security
policy**. That is `§2.12`'s mechanism arriving exactly as documented: an `UPDATE` that
references a column re-checks the **new** row against the SELECT policies, so the whitelist
must contain the status the update writes. `PROJECT_INSTRUCTIONS.md §4 › Postgres traps`
prescribes precisely this and the measurement confirms it.

**(iv) `R-033` is fixable as prescribed, and fixing it exposed a second defect.**
`#[Response(status: ..., type: Problem::class)]` on a documented route makes Scramble emit
**both** `#/components/schemas/Problem` and `#/components/schemas/ProblemType`, the latter
with every case as an OpenAPI `enum`. Measured: `components.schemas` went from `{}` to two
entries and `ProblemType` from **0 occurrences to 4**.

But the `Problem` schema Scramble inferred was **wrong about the wire shape**. It read the
constructor's promoted properties — `type`, `instance`, `errors`, `retryAfter` — while the
body `toArray()` actually emits is `type`, `title`, `status`, `instance` and optionally
`errors`. So publishing it as-is would have put a schema in the committed contract that
advertises a field no body carries (`retryAfter`) and omits two that every body carries
(`title`, `status`). Shipping that would have been `R-025`'s category in a generated
artefact. Closed by giving the envelope one wire-shape class that `toArray()` **builds**,
so the documented shape and the emitted body cannot drift, with a test that compares the
published property names against the keys a real body has.

**(v) `ProblemType`'s whole docblock is published as the schema's `description`.** This is
`R-052`/`R-056`'s shape — a docblock shipping as spec text — arriving in an enum rather
than a controller. The published description also carried the docblock's own `*` as the
literal text `"one  * source"`, and the sentence "verified in the phase-1 research", which
`R-033` measured false. The docblock is rewritten to what is true and short, because it is
published.

**(vi) `R-004`'s cluster-wide setting has no test depending on it.**
`BootstrapLoggingTest` sets `log_statement = 'all'` itself with `ALTER DATABASE` and resets
it in a `finally`, and CI sets it with `ALTER SYSTEM` in its own step before the suite
("Log every statement, so the scan below cannot pass vacuously"). So removing it from
`compose.yaml` weakens neither the in-suite assertion nor CI's whole-run scan. Two comments
in that file claimed the cluster-wide value was a live control that Task 4 depends on;
both are corrected, because after this change they are false.

---

## 5. Out of scope — the owner is named, nothing here gets built

| Excluded | Owner |
|---|---|
| Any **customer-facing** HTTP endpoint — polling, subscriptions, the landing page | Task 4 |
| The outbox **worker**, Web Push dispatch, VAPID, 404/410 pruning, the Redis status projection | Task 4 |
| Scoping `log_statement` per session for Task 4's own proof | Task 4 — and see `§4 (vi)`: this task removed the cluster-wide value, so the half that remains is Task 4 choosing its own instrument |
| Real authentication, users, passkeys, 2FA, RBAC, filling `audit_log.actor_user_id` | Task 5 |
| Any Filament panel, QR **image** rendering, printable sheets | Tasks 6–7 |
| Any frontend, the TS status mirror, reading the QR fragment in a browser | Tasks 8–9 |
| `outbox`/`audit_log` retention and pruning | Tasks 13–14 |
| The renotify cap as a **database** `CHECK` rather than an application rule | unassigned — see `§6.5` |

## 6. The seven `§6` pre-coding answers

**1. Which tables / columns / roles / GUCs does this touch?**

**No table, column, enum value or role attribute changes.** Every column this task writes
already exists in `§2.5` and is already granted in `§2.7`. What changes in the data
contract, and was written into `PLAN.md §2` **before** the migrations:

- **`§2.6`** — the two worker cross-tenant policies on `pager_sessions` are narrowed from
  time-only to **time + a status whitelist** (`R-003`). `worker_reads_expired_sessions`
  becomes `expires_at <= now() AND status IN ('waiting','ready','expired')`;
  `worker_expires_sessions` becomes `USING (expires_at <= now() AND status IN
  ('waiting','ready')) WITH CHECK (status = 'expired')`. The policy **names** are unchanged,
  so `RlsCoverageTest`'s "exactly the four named cross-tenant policies" still holds.
- **`§2.8`** — the named cross-tenant read for the QR token lookup now exists as
  `public.resolve_pager_token(bytea)`: `SECURITY DEFINER`, owned by `qrpager_owner`,
  `SET search_path = pg_catalog, pg_temp`, a narrow `RETURNS TABLE` naming seven columns and
  **not** `token_hash`, `EXECUTE` revoked from `PUBLIC` and granted to `qrpager_app` alone.
- **`§2.2`** — `app.bypass_tenant` has a **second setter**. It was `withoutTenantScope()`
  alone; the resolver function's body now also sets it, for the duration of its own body,
  restoring the previous value before returning. That is what `§2.8` prescribes, and the
  "Set by" cell said otherwise, so it is corrected rather than left to a reviewer to notice.
- **`§2.7`** — gains the one `EXECUTE` grant above. No column grant changes.
- **`§2.3`** — "Transitions of `pager_status` are defined in the PHP enum by Task 3" is
  now true and points at the enum.
- **`§2.12`** — its closing paragraph carried `R-003` as live. Corrected in place.

Roles used: `qrpager_app` for the five endpoints, `qrpager_worker` for the expiry job.
Neither gains an attribute. **No new `app.*` GUC name**, so C1 is unchanged and
`composer guard`'s rule 5 still sees exactly two.

**2. Which shared contracts does it use or add? One source only.**

- `App\Domain\Pager\PagerStatus` — **gains the transition table**, which `§2.3` already
  assigned here. One source for "which transition is legal", read by the service, the
  expiry job and the tests. The Postgres `pager_status` type is unchanged, so
  `EnumContractTest` keeps passing without an edit.
- `App\Domain\Http\ProblemType` — **gains `BadRequest`** (`R-042`). Still the only producer
  of a `type` value, and now **mechanically published**: `docs/openapi.json` carries
  `#/components/schemas/ProblemType` with every case, asserted case-by-case from the enum
  rather than against a hand-written list, so adding a case without regenerating the spec
  fails `composer openapi:check` **and** the assertion.
- `App\Domain\Pager\PagerEvent` — new. One source for the outbox `type` string **and** the
  `dedupe_key` shape that makes `markReady` idempotent, because those two are one decision:
  the key is what the unique index enforces.
- `App\Domain\Pager\PagerAuditAction` — new. One source for the `audit_log.action`
  vocabulary this feature writes.
- `App\Platform\Http\ProblemDocument` — new, and it is a **wire shape, not a second
  envelope**: `Problem::toArray()` builds one and serialises it, so there is still exactly
  one producer of a problem body and the published schema is read off the same class.

None of the three new enums is a Postgres enum, so none joins `DatabaseEnums::map()` —
`§2.3`'s registry stays the list of PHP↔Postgres pairs, which is what `EnumContractTest`
checks.

**3. Show the exact path by which tenant context reaches every query.**

*Staff path (the five endpoints).* Request → `ArmTenantScopeGuard` on the **global** stack →
the `api` group: `ShedLoad`, `RateLimit`, `RequireTenantScope` → `RequireTenantScope` asks
`TenantResolver` and, on `null`, answers 401 with no detail → it publishes the id at
`RequireTenantScope::BINDING` → the controller reads that binding and **never** a header, a
route parameter or a body field → `PagerSessions::<operation>()` calls `withTenant($tenant,
…)` **once** → `TenantContext::run()` opens one transaction and issues `select
set_config('app.tenant_id', ?, true)` (C2, third argument always `true`) → every statement
in the callback is narrowed by `tenant_isolation_*`. Nothing in this task opens a scope from
a route parameter or from anything the caller sent, which is the property that makes the
IDOR answer a 404 rather than a leak.

Backstop, not the mechanism: `TenantScopeGuard` runs `beforeExecuting` on the app
connection and refuses any statement naming `pager_sessions`, `outbox` or `audit_log` with
no scope open — so a future endpoint that forgets `withTenant()` is a loud refusal before
the statement reaches Postgres, not a zero-row answer.

*Token path (one of `§4`'s four named cross-tenant reads).* **No tenant context, by
design** — that is the whole point: the token is what decides the tenant. The raw token
never reaches Postgres; its `sha256` does, as the single exact-match argument of
`resolve_pager_token(bytea)`. The function turns on `app.bypass_tenant` for its own body
and restores the previous value before returning. Measured: the GUC reads empty in the
caller afterwards, and the caller's own direct `pager_sessions` query still returns **0
rows** — so the bypass is the function's, not the connection's.

The scope guard permits that statement because it names no tenant table. That is correct —
it is a named context-free path — but it was **incidental**, so it is pinned by a test: in
one armed request, the function call is permitted and a bare `pager_sessions` statement on
the same connection is refused.

*Worker path (the expiry job).* No tenant context, the fourth named path. `SELECT id` bounded
by `worker_reads_expired_sessions` → `UPDATE … WHERE id = ? AND status = ?` bounded by
`worker_expires_sessions`, **no `RETURNING`**, and the affected-row count is the idempotency
signal. Never a bulk statement: every write names the key, per `§4 › Postgres traps`.

**4. Security: authn, authz, input validation, rate limit, log redaction, secrets.**

- **authn.** Two surfaces, never confused (`§4 › Tenancy`). Staff authenticate as users —
  which does not exist yet, so the five endpoints sit behind Task 2's deny-by-default guard
  with the **test-only** stub that Task 5 deletes, and `§3` row 5's three assertions already
  exist to prove it is gone. The customer has no account and authenticates **only** by
  presenting the token. No endpoint in this task accepts a tenant id, a session id or a
  token from the caller as a way of *choosing* a tenant.
- **authz.** Deny-by-default. A session id that belongs to another tenant is invisible under
  RLS inside the scope, so it answers **404 `NotFound`, never 403** — 403 would confirm the
  id exists. That is the IDOR answer and it is asserted through HTTP, which is the gate's
  own wording ("not just at the DB").
- **input validation.** `order_reference` is validated against `§2.5`'s own column —
  `varchar(32)`, `NOT NULL`, printable — so a value the database would refuse is refused with
  a 422 instead of a `22001`. **Corrected 2026-10-27:** this said "non-empty", and the column
  carries no non-empty `CHECK` (measured: `pager_sessions`'s only check constraint is
  `renotify_count >= 0`). A whitespace-only reference is still a 422, but the rule that
  produces it is Laravel's global `TrimStrings` + `ConvertEmptyStringsToNull`, not
  `required` — measured, because `required` passes `'   '` on its own. The outcome was
  right and the stated reason was not. The four transition endpoints take **no body at all**. The
  token, when it is presented (Task 4's surface, this task's resolver), is shape-checked
  before it is hashed: exactly 22 base64url characters, so a malformed token is rejected
  without a database round trip. **Corrected 2026-10-27 (security S-4):** this used to end
  "so an attacker's loop costs one regex", which is the cost of a *malformed* token only.
  A real enumeration sends well-formed ones — 22 random base64url characters are free to
  generate — and each then costs a sha256, a plpgsql call with two `set_config`s, an index
  probe and a round trip. The honest answer to `§11`'s question for this path is **a
  database round trip per attempt, bounded by nothing in this task**: no limiter (the
  route is Task 4's), no counter, no audit row per resolution, so a sustained enumeration
  leaves no trace in `audit_log` and no metric. The 128 bits mean guessing cannot
  *succeed*; they do not bound the loop. Carried as **R-063**, owner Task 4.
- **rate limit.** The five endpoints are on the `api` bucket (per IP **and** per tenant).
  Creating a session is the expensive one and gets its own tighter bucket.
- **log redaction.** The raw token is **unloggable by construction**, which is stronger than
  redacting it: it lives in a private property of a value object with no `__toString()`, a
  `__debugInfo()` that returns a redacted marker, and a `JsonSerializable` that returns the
  same — so `var_dump`, `print_r`, `json_encode` and Monolog's context serialisation all get
  the marker and not the token. It is never a route parameter, never a query string, and
  never in an error body. The QR payload carries it in the **URL fragment**, which is not
  transmitted to a server.
- **secrets.** None added. No new environment variable carries one.

**5. Scale: cost per request and per active session. What is bounded?**

Per `§2.11`, one tenant scope costs 3 statements of overhead and 2 transaction round trips.
On top of that:

| Operation | Postgres work inside the one scope |
|---|---|
| create | 1 `INSERT` session + 1 `INSERT` audit |
| `markReady` | 1 `SELECT … FOR UPDATE` + 1 `UPDATE` + 1 `INSERT` outbox + 1 `INSERT` audit |
| `renotify` | same as `markReady` |
| `complete` | 1 `SELECT … FOR UPDATE` + 1 `UPDATE` + 1 `INSERT` audit (no outbox row) |
| `cancel` | 1 `SELECT … FOR UPDATE` + 1 `UPDATE` + 1 `INSERT` outbox + 1 `INSERT` audit |
| token resolve | 1 function call, 1 index probe on `token_hash`'s unique index |
| expiry job, per pass | 1 bounded `SELECT id` + 1 `UPDATE` per row |

**Bounded:** outbox rows per session, because `renotify` is capped — without a cap a staff
member holding the button is an unbounded writer into the one table `§2.11` already calls
"the one to watch", and each row is a push notification to a customer's phone. The cap is
`config('pager.renotify_limit')`, refused as a `409 Conflict`. The expiry job's pass is
bounded by an explicit `LIMIT`, so one pass cannot hold a long transaction over an
unbounded row count.

**Not bounded, and already recorded:** `pager_sessions`, `outbox` and `audit_log` growth
(`§2.11`, retention is Tasks 13–14). This task adds no new unbounded dimension.

**Chosen against:** a `CHECK (renotify_count <= n)` on the column. It is stronger, and it
is a data-contract change to a shipped table for a number Task 14 will re-measure; an
application rule with a test is the reversible form. Recorded in `§5` out-of-scope.

**6. Failure: timeout, retry, duplicate, crash mid-way.**

- **timeout.** The app role carries `statement_timeout = 5s` and
  `idle_in_transaction_session_timeout = 15s` (`§2.1`), both already proven to fire. The
  `SELECT … FOR UPDATE` is the only statement that waits on another transaction, and
  `lock_timeout = 2s` bounds that wait.
- **retry.** `markReady` is idempotent, by two mechanisms rather than a read-then-write
  check: the row lock serialises concurrent calls, and the second caller sees a status that
  is no longer `waiting` and writes **nothing** — no second outbox row, no second audit row.
- **duplicate.** `UNIQUE (tenant_id, dedupe_key)` on `outbox` is the database-level
  backstop behind that, which is exactly what `§2.5` says it exists for. A duplicate key is
  mapped to `409 Conflict` and never to a 500.
- **crash mid-way.** The gate's first clause. All three writes are inside the **one**
  transaction `withTenant()` opens, so a failure after the status write rolls back the
  status change, the outbox row and the audit row together. Proven by injecting a throw
  after the status write and asserting all three are absent — not by reading the code.
- **expiry job killed mid-pass.** Each row is its own keyed `UPDATE` with its own
  affected-row count; a killed pass leaves the rows it had already committed expired and the
  rest untouched, and the next pass picks them up. Nothing is lost and nothing is
  double-expired, because `status = ?` in the `WHERE` makes a second attempt a `UPDATE 0`.

**7. Research done for anything version-specific, verified by running code.**

Everything in `§4` above was run in this session against Postgres 16 and the installed
packages — the policy matrix and its counterfactual, the `token_hash` predicate refusal,
`SELECT … FOR UPDATE` under a column-level `UPDATE` grant, the resolver function end to end
including that the bypass GUC does not leak, Scramble's component emission, and
`laravel/pao`'s detector and its `PAO_DISABLE` switch read from the vendor source. Nothing
in this ticket is quoted from memory. Where a measurement contradicted a document, the
document is corrected in place and the correction is named in `§4`.
