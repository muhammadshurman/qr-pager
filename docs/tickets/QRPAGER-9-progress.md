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

**Q1–Q7 answered by the user the same day.** Scope is all 17 rows; the three gate clauses, R-017's
disposition, the unversioned probes and the two `§4` rank-2 edits are approved; Scramble is pinned to
exactly `0.13.50` with the pre-1.0 risk going to `§5` owner Task 12; the runtime is the pinned
FrankenPHP container and **the SIGTERM drain proof must run in CI, not only locally**.

Three things the user asked to be verified before approving, all run:

- **The bundled PHP version.** `dunglas/frankenphp:1.13.3-php8.4-bookworm` →
  `PHP 8.4.27 (cli) … (ZTS)`, `FrankenPHP v1.13.3 PHP 8.4.27 Caddy v2.11.7`. Output in the ticket's
  §0.3. Two findings came out of it: the unqualified `1.13.3` tag is ambiguous because
  `1.13.3-php8.5` ships in the same release, and the image has **no** `pdo_pgsql`, `redis`, `pcntl`,
  `intl` or `bcmath` — `pcntl` is not optional, because Laravel's `trap()` is how the readiness
  carrier drains on `SIGTERM`.
- **That nothing calls `/up`.** One reference outside `vendor/`: `apps/api/bootstrap/app.php:13`.
  `compose.yaml`'s healthchecks are `pg_isready` and `redis-cli ping`, each against its own service;
  CI's `--health-*` flags belong to the same two service containers; nothing curls the app. Safe to
  remove.
- **What replaces the Swoole-only tick for readiness.** `readiness:refresh`, a sub-minute scheduled
  command on the **worker** connection writing one Redis key, readiness reading only that key —
  specified in the ticket's §6.3. The choice that decides it is the connection: measured, with
  `qrpager_app` at 40/40 and a new app connection refused `08006`, the worker-role probe still
  returned `saturation: 1`. A probe on the app connection would fail exactly when the number matters.
  Sub-minute cadence verified in the framework source rather than assumed.

**Owed to the user:** when the two `§4` rank-2 edits land, say so, so they can paste the same text into
the claude.ai Project instructions (Q6).

**Open:** the user's approval of phase 1 as a whole. No application code until then.

Housekeeping, so it is recorded rather than noticed later: four untracked `*:Zone.Identifier` files
(Windows download markers beside `docs/PLAN.md`, `docs/claude/*` and the prompt) were deleted. No tracked
file and no document content was touched.

---

## Phase 2 — the build

Gates are run before every commit: `composer pint`, `composer stan`, `composer deptrac`,
`composer guard`, `php artisan test`. No commit was made on a red suite. Every commit is pushed.

### ⚠ Owed to the user — **both `§4` rank-2 edits have landed** (Q6)

Commit `0b59659`. Paste these into the claude.ai Project instructions or the two copies disagree —
`§0`'s "Where everything lives" already records the `§11` copy as out of step, and this adds `§4`.

1. `§4 › Postgres traps`: "A **repo guard in lint** rejects…" → "A **repo guard in CI** rejects…",
   with the reason appended: nothing in lint can see `false` inside the SQL string
   `'select set_config(?, ?, false)'` — Pint formats and PHPStan analyses the AST.
2. `§4 › Edge & ops`: "No secrets in logs. Redaction tested against captured log output." →
   "No secrets in logs, **or in an error response body, at any `APP_DEBUG` setting**. Redaction
   tested against captured log output **and against captured response bodies**." From `§3.2`'s
   finding 2, with the `7f1c8f6` measurement beside it.

### 1 · `86ec04a` — the three named architecture guards (D1)

| | |
|---|---|
| What | deptrac 4.7.2, layers `App\Domain` / `App\Platform` / `App\Features` / `Bootstrapping`, with `Tests\*` as a layer nothing may depend on. `composer deptrac` + a CI step. Real tree: 0 violations, 37 allowed |
| §5 rows | none — this is a gate clause, not a ledger row |
| Test | `tests/Feature/Architecture/ArchitectureGuardTest.php` copies a `.php.fixture` into the layer it violates and runs **the real `deptrac.yaml`**, not a second copy of the ruleset |
| Revert | `Domain: ~`→`[Framework]`: **1 failed, 4 passed**. `Platform: +Features`: **1 failed, 4 passed**. `Platform: +TestHelpers`: **1 failed, 4 passed**. Restored: 5 passed. Each revert fails exactly the guard whose rule was widened |
| Found on the way | The first version of the test leaked three empty `Deliberately/` directories — it unlinked files and not directories. Its own fifth test caught it, which is why that test exists |

### 2 · `fb35ee9` — Task 0's bug 1, repeated by me

`86ec04a` committed `apps/api/.deptrac.cache`. `§4a` records this exact failure for Task 0 ("any new
tool cache must be verified with `git status` after") and I added a tool without doing that check.
Fixed twice over so neither route can bring it back: `cache_file: build/deptrac/` (already ignored for
PHPStan's `tmpDir`) and `.deptrac.cache` in `apps/api/.gitignore`.

### 3 · `0b59659` — **closes R-010 and R-019**: the C1/C2 repo guard

| | |
|---|---|
| What | `Guard\SourceGuard` scans **string literals** via `token_get_all`, never raw text — a dozen comments in this repo spell the forbidden shape out while explaining it, and a text-matching guard would flag the documentation of its own rule. Five rules: the literal `true` third argument (bound and absent both fail — Postgres defaults it to `false`); no raw `SET`/`SET LOCAL`/`SET SESSION` of an `app.*` setting; GUC names inside `current_setting`/`set_config`; whole literals of the shape `app.<name>`, which is how every name here really travels; and `config/tenancy.php`'s configured set, the only place a third name would actually be introduced |
| §5 rows | **R-010** closed · **R-019** closed |
| Test | `tests/Feature/Architecture/RepoGuardTest.php`, 10 cases against `guard/fixtures/repo` |
| Revert | rule 1 never fires: **4 failed, 6 passed** · rule 2 never matches: **1 failed, 9 passed** · rules 3+4 allow anything: **1 failed, 9 passed** · rule 5 always passes: **1 failed, 9 passed** · `EXCEPTIONS` emptied: **3 failed, 7 passed** · restored: 10 passed |
| Counts explained | Rule 1 takes **4** because the three shape tests plus the method-scoping test all depend on it firing at all. Emptying the exception takes **3**: the real tree stops being clean, `composer guard` stops exiting 0, and the method-scoping test asserts the real helper scans clean as its first assertion. Every other revert touches exactly its own test |
| R-019's own condition | Met as stated — "by path, never by *tests are exempt*". The exception is path **and method**: moving the statement to a second method of the same file is still a violation, proven through `scanSource()` with the real path and altered source, so nothing on disk is written |
| Found on the way | The guard flagged its own violation **message** (token scanning hides comments, not message strings), then its own **test file**'s 13 assertion literals. The second was fixed by moving every forbidden literal into `guard/fixtures/repo` and asserting against the fixture's own text — **not** by exempting the guard's test by path, which would have hidden the one thing worth knowing. `tests/Support/GuardFixtures.php` exists because the first version declared `fixture()` at file scope and Pest already ships one, taking the whole suite down: the same collision `Composition.php`'s docblock was written about |

### 4 · `c8a7fac` — the `/v1` surface, the two probes, D6's policy

| | |
|---|---|
| What | `apiPrefix: 'v1'` for `routes/v1.php` (empty at Task 2 by design — every endpoint in it belongs to a later task), `routes/probes.php` unversioned on its own **empty** middleware group, which is the mechanism behind "while health and readiness keep answering". `/healthz` reads nothing; `/readyz` reads one Redis key and is fail-closed on every unknown. `§6` gains the versioning policy |
| §5 rows | **R-012 (Task 2 half)** — structurally closed here; the measured proof lands with the carrier |
| Test | `tests/Feature/Http/RouteSurfaceTest.php`, 5 cases: the allowlist, the three removals, liveness with both dependencies pointed at dead ports, readiness 503 with Postgres pointed at a dead port, readiness 503 with Redis down while liveness stays 200 |
| Three public routes removed | `/up` (duplicated liveness and runs the framework's dependency-touching checks; verified beforehand that nothing called it) · `/` (the skeleton's welcome page, which `ExampleTest` asserted returned 200 — the placeholder test and the gate clause contradicted each other) · **`GET` and `PUT storage/{path}`**, from `config/filesystems.php`'s local disk `serve => true`. The `PUT` writes request content into `storage/app/private` behind a signed relative URL. Not an open upload, but the signature is the app key, nothing uses the route, S3 is the locked storage (ADR-002) and uploads are Task 7's. Found with `php artisan route:list` — it shows nowhere else |

### 5 · `2655bdd` — **closes R-007 (Task 2 half) and R-021**: one RFC 7807 envelope

| | |
|---|---|
| What | `App\Domain\Http\ProblemType` is the single source for `type` (D3) — a string enum carrying the status and a fixed title beside each case, so the mapper takes the enum and never a string and a new outcome without a case does not compile. `App\Platform\Database\DriverError` reads the SQLSTATE from `getPrevious()->errorInfo[0]`; `DatabaseErrorMapper` keys on it; `ProblemRenderer` is registered for **every** request at **any** `APP_DEBUG` — `.env.example` ships `APP_DEBUG=true`, so a renderer that steps aside in debug satisfies clause (b)'s words and not its property. The cost is Ignition's page; nothing is lost, because Laravel still reports the exception and the log keeps the class, the SQLSTATE, the file and the line |
| §5 rows | **R-007 (Task 2 half)** closed · **R-021** closed. Gate clause (b) is the half of §3's gate they sit under, and both were measured **false** at `7f1c8f6` (§4a baseline) |
| Test | `tests/Feature/Http/ProblemEnvelopeTest.php` (8 cases) asserts against **captured response bodies**, never the mapper's return value — what broke at `7f1c8f6` was the rendering, and a mapper-only test passes straight over the leak. The `23514` case drives the real `users_email_is_lowercase` constraint and asserts its name is absent; the `42501` case asserts the other tenant's uuid is absent; the HTML case asserts the body is under 500 bytes where the debug page measured 862,104. `tests/Feature/Database/DriverErrorTest.php` (6 cases) pins the SQLSTATE reading itself |
| Revert | renderer unregistered: **8 failed, 6 passed** · `23514` not mapped: **1 failed, 13 passed** · `42501` not mapped: **1 failed, 13 passed** · `sqlState()` reads `getCode()`: **3 failed, 11 passed** · restored: **14 passed** |
| Counts explained | Unregistering the renderer fails all **8** envelope tests and none of the 6 `DriverError` tests, which never go through HTTP — that revert is what closes R-007's leak half and R-021. Each mapping revert fails only its own case, by status. Reverting `sqlState()` fails **3**: the connection-failure case (`7` is not `08006`), the "reports nothing rather than a wrong code" case, and the wrapped-exception case. The three "agrees with `getCode()`" datasets still pass, correctly — and that **is** the finding: a `getCode()` reader is indistinguishable from a right one until a connection fails |
| One honest note on R-021 | Its test passes with the `UnsafeDatabaseRoleException` branch removed, because the fall-through also yields a generic 500. What closes R-021 is the renderer existing at all, which revert A proves. The branch stays as a **classification pin** — it fixes that class as a server fault, so a later mapper change cannot reclassify an RLS-exempt-connection refusal into a 403 "that is not permitted", which would be a misleading answer to the caller |
| Found on the way | `getCode()` and `errorInfo[0]` **agree** for statement errors (`42P01`, `22P02`, `22012`) and disagree only for connection-level failure, where `getCode()` is the integer **`7`** and the SQLSTATE is `08006` — which is also how a saturated role `CONNECTION LIMIT` arrives at the client, never `53300`, measured at 40/40. `§6 Open items`' "not reliably the SQLSTATE" is now precise rather than a warning. `DriverError` is the mechanism **R-013** needs; its five `TenantContext::statementFailed()` call sites, `RoleBootstrapper.php:147` and `TestCase::sqlState()` still read `getCode()` and are that row's own work |

### 6 · `8075aec` — **the second invariant**: Monolog redaction

| | |
|---|---|
| What | Redaction at **both** ends. *Sink:* `RedactingProcessor`, pushed by the `RedactLogs` tap on every channel in `config/logging.php` — `tap` and not the `processors` key, because `processors` is read only by the `monolog` driver while `tap` applies to every channel the manager resolves, and a `stack` channel inherits its children's processors. *Producer:* `ReportRedactedException` as the reportable callback, returning `false` to stop Laravel's own reporting. The producer half covers the one path no tap can reach: `LogManager::get()` falls back to `createEmergencyLogger()` when a channel cannot be resolved, and that logger is built directly with `new Monolog(...)` |
| §5 rows | none closed by name — this is `§3.1` row 2's **second invariant**, "logs carry no SQL, bound parameters or secrets", recorded **false** in the `§4a` baseline: one `Log::error($e->getMessage(), ['exception' => $e])` for a real `QueryException` wrote **22 lines** carrying the statement and the bound uuid interpolated into it. Laravel builds that message itself in `Connection::run()`, so this was never about careless logging calls — there is no way to log a query failure without redaction in between. It also carries R-021's other half: the role and the refusal reasons **in** the log and **absent** from the body, which is that row's acceptance condition stated exactly |
| Test | `tests/Feature/Logging/LogRedactionTest.php`, 17 cases. The contract is stated once, in the test file, so no case re-argues it: **values, statements and traces out; names and locations in.** The relation name, the constraint name and the throw site's file and line are **kept** — `§2` publishes the schema and the repo publishes the code, so neither is a secret, and a log with a SQLSTATE and no idea which object or line it concerns is most of the way to useless |
| Revert | `message()` returns its input: **5 failed, 11 passed** · DETAIL/HINT/CONTEXT not stripped: **1 failed, 15 passed** · a throwable in context keeps its trace: **1 failed, 15 passed** · producer-side report callback removed: **1 failed, 16 passed** · the channel taps removed: **1 failed, 15 passed** · restored: **17 passed** |
| Counts explained | The fourth revert is the one worth reading. On its first run it failed **nothing** — all 16 cases passed with the producer-side redaction gone, because the sink-side processor covered every one of them. An unproven control is what `§4a` keeps recording, so the emergency-logger case was written to distinguish it: a default channel the manager cannot build, a real query failure, and an assertion against the emergency log file. With that case the same revert fails exactly **1**, and the suite is 17. The other four each fail only their own case except `message()`, which takes **5** because every message-level case depends on it doing anything at all |
| Found on the way | **The leak I had not planned for.** Stripping Laravel's `" (Connection: ..., SQL: ...)"` clause is not enough: Postgres puts **DETAIL ahead of it**, and for a constraint violation DETAIL is `Failing row contains (...)` — *every column value of the row*. Measured against the real `users_email_is_lowercase` constraint: the uuid, the name and the email all came back in the driver's own description, before the clause I was stripping. DETAIL, HINT and CONTEXT are now stripped as a group and that case has its own test. Separately, three of my own assertions were stricter than the stated contract and were **corrected to it**, rather than the implementation being bent to them |

**Suite: 198 at `7f1c8f6` → 217 (`c8a7fac`) → 231 (`2655bdd`) → 240 passed, 736 assertions
(`8075aec`).** `pint` passed · `stan` 0 errors · `deptrac` 0 violations · `guard` clean, at every
commit. Re-measured on a second machine at `8075aec`, from the same composed Postgres 16 + Redis 7:
**240 passed, 736 assertions**, all five gates green — so the figure is the code's and not the
builder's machine's.

### Where the ledger stands at `8075aec`

**Closed:** R-007 (Task 2 half), R-010, R-012 (structurally — the measured proof is owed with the
carrier), R-019, R-021.
**Open, and the rest of this branch:** R-005, R-009, R-013, R-014, R-015, R-016, R-017 (its lint half
rides R-010; the row text still needs correcting), R-018, R-020, R-022, R-023, R-024, and H-C's two
boot clauses.
