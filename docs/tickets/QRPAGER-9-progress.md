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

### 7 · `34abd69` — **the first invariant**: a request with no tenant scope cannot reach the DB

| | |
|---|---|
| What | `App\Platform\Database\TenantScopeGuard` on `Connection::beforeExecuting()`, registered from a `ConnectionEstablished` listener so it reattaches after an Octane purge, and armed by `ArmTenantScopeGuard` on the **global** middleware stack. `App\Domain\Database\TenantTables` is one registry for both table lists, which `RlsCoverageTest` now reads instead of declaring `GLOBAL_TABLES` itself |
| §5 rows | none by name — this is `§3.1` row 2's **first** invariant, recorded in `§4a` as *unenforced* at `7f1c8f6` |
| Test | `tests/Feature/Database/TenantScopeGuardTest.php`, 10 cases. The headline case does **not** rely on RLS to produce the difference: an unscoped insert into a tenant table is refused `42501` with or without a guard, so the row count would prove nothing. It deletes from `password_reset_tokens` — a global table the app role may delete from, which the guard does not match — with a subquery against `tenants`, which it does |
| Revert | `beforeExecuting`→`DB::listen`: **2 failed, 8 passed** · global arming removed: **3 failed, 7 passed** · attached once per connection NAME: **1 failed, 9 passed** · registry matches nothing: **5 failed, 18 passed** (+RlsCoverage, 23) · `hasOpenScope()` returns false: **1 error, 9 passed** · guard every connection: **1 error, 9 passed** · registry drifts by one table: **1 failed, 12 passed** (RlsCoverage, 13) · restored: **10 passed** |
| Counts explained | The `listen()` revert fails 2, and the second is the one I did not predict: with a post-execution guard, `update outbox …` is refused by **Postgres first** (the app role has no UPDATE grant), so the caller gets a `QueryException` instead of the guard's refusal — a second way the mechanism is wrong, from the same revert. Removing the arming fails exactly the three request-path cases and none of the seven that drive `armedFor()` directly. It is also worth reading **why** they fail: the request returns **200**, because an unscoped count on `pager_sessions` succeeds with zero rows. That 200 is the weaker property this commit replaces. Guarding every connection fails only the owner-bypass case, with *"Tenant context could not be audited"* — guarding the owner connection refuses the audited bypass's own `audit_log` write, which is why the guarded set is the runtime connections and why R-015's real control is D9's boot assertion rather than this layer |
| Found on the way | **`DB::listen()` fires after execution**, so B4's specified mechanism would have made this task's own invariant test green while being false. Reproduced inside the suite rather than left in a commit message. And the guard holds the **container**, not a `TenantContext`: it is resolved at boot, so it lives in Octane's root application, and an injected instance would be the root one — the guard would ask an object that never has a scope open whether a scope is open, refusing every scoped query in production while passing every test that called `inspect()` directly |
| The boundary, not overclaimed | Armed per **request**, which is exactly what the invariant claims. Console, scheduled and queue work is left to RLS — which is what it already was — because `TenantIsolationTest`, `ConnectionReuseTest` and `WorkerScopeTest` prove the backstop by making this very query with no scope and expecting zero rows. An always-armed guard would have needed a suspension helper for them, and an escape hatch in the one class that exists to have none is a worse trade than a named boundary |

### 8 · `e5b7868` — **closes R-022**: a connection goes back to the pool clean, both ways a request ends

| | |
|---|---|
| What | `RequestBoundaryCleanup` on **both** `OperationTerminated` and `WorkerErrorOccurred`, first on each key. `TenantContext` becomes `scoped()` (D10) and the guard resolves it from `Container::getInstance()` |
| §5 rows | **R-022** closed. Gate clause (a), measured **false** at `7f1c8f6` |
| Test | `tests/Feature/Octane/RequestBoundaryTest.php`, 6 cases, through the **real** `Laravel\Octane\Worker` with `FakeClient` — the row exists because the hooks do not fire in the shape that was assumed, so a double would have reproduced the assumption. The asymmetry is reached through public API: `onRequestHandled()` callbacks run **before** `$gateway->terminate()`, and terminate() is the only dispatcher of `RequestTerminated` |
| Revert | `WorkerErrorOccurred` registration removed: **2 failed, 4 passed** · `OperationTerminated` removed: **2 failed, 4 passed** · purge unconditionally: **1 failed, 5 passed** · `TenantContext` back to `singleton()`: **1 failed, 5 passed** · restored: **6 passed** |
| Counts explained | Each half fails its own case plus the registration case, which is R-022's acceptance condition stated exactly. The first revert's message is the measurement: *"Failed asserting that 1 is identical to 0"* — transaction level **1** after the throwing request, which is the state phase 1 recorded. The unconditional-purge revert is worth noting: it passes the clause and fails the case that exists to stop the clause being satisfied by reconnecting on every request |
| Found on the way | Octane's own `GiveNewApplicationInstanceToDatabaseManager` points the **shared** db manager at the current sandbox on every `RequestReceived`, and `handle()` flushes that sandbox at the end — so between requests the manager holds an application with no config bound and asking it for a new connection fails *"Target class [config] does not exist"*. The server loop re-points it next request; a test inspecting the connection between requests has to do the same. It is also why the cleanup can purge at all: both events fire **before** the finally that flushes the sandbox |

### 9 · `0a5feb3` — **closes H-C's two boot clauses, R-015 and R-020** (with D8 and D9)

| | |
|---|---|
| What | `App\Platform\Boot\BootChecks` — five clauses in `§6.4`'s order, returning **reasons** rather than throwing, so the caller decides what refusing means. `RefuseUnsafeBoot` on Octane's `WorkerStarting` (first on the key) exits 1 with every reason logged at critical; `php artisan boot:check` runs the same checks, because an exit code is only provable by running a process |
| §5 rows | **R-015** closed · **R-020** closed · **H-C's two remaining Task 2 clauses** closed (`assertSafeRuntimeRole()` at boot, and widening it per D9) |
| Test | `tests/Feature/Boot/BootChecksTest.php`, 13 cases. Each clause is driven on its own, and one case runs the real command in a subprocess and reads `$?` |
| Revert | `assertSafe()` not called at boot: **2 failed, 11 passed** · D9 reads only the environment: **1 failed, 12 passed** · `prefer` accepted: **1 failed, 12 passed** · R-020's clause removed: **2 failed, 11 passed** · an unverifiable role passes: **1 failed, 12 passed** · logs but does not stop: **1 failed, 12 passed** · `WorkerStarting` registration removed: **1 failed, 12 passed** · restored: **13 passed** |
| Counts explained | The `prefer` revert is the one to read: `prefer` is libpq's **own default** and the only one of the three weak modes that looks safe, so a guard that dropped it would pass every obvious test. Removing the owner's timeout exemption (R-014's commit) is the loudest result on this branch and is correct — `db:bootstrap` refuses the real configuration, and it runs in `TestCase::setUp`, so every test in that file **errors** before its body runs. That is what "fails fast instead of hanging" looks like |
| Found on the way | **D9 has to read the resolved config as well as the environment.** `config:cache` evaluates `config/database.php` while `.env` is loaded and bakes the password into the cache, after which `env()` returns null at runtime — so an environment-only check passes while the credentials are still resolvable. That is an unproven control, which is the one shape `§4a` keeps recording, and it has its own test |
| Found on the way | **Setting an environment variable before booting a second application in the same process does nothing.** phpdotenv's immutable writer refuses to overwrite a variable already in the environment *unless dotenv loaded it itself*, and `Env::getRepository()` is a static singleton — so the writer that loaded `.env` for the test's own application still remembers `APP_DEBUG` as its own and puts `.env`'s value straight back. `Env::enablePutenv()` nulls the repository. How it surfaced is the useful part: the suite died with *"Premature end of PHP process"*, because a worker **is** a serving process, the boot check runs on its `WorkerStarting`, and `§4` forbids `APP_DEBUG` outside `local` while the suite runs as `testing` with `.env` shipping it true. The check was right and the helper was wrong |
| Also found | A production boot check against local Postgres can never be reduced to one reason: local Postgres has no TLS, so `DB_SSLMODE=require` cannot connect at all. The subprocess case reports credentials **and** sslmode, which is correct rather than a test that needs fixing. The first version of the sslmode case also moved only the `pgsql` connection — `.env` ships one `DB_SSLMODE` shared by all five pgsql connections, so the check names all five in one reason and the test now asserts that |

### 10 · `ccca185` — **closes R-013, R-023 and R-024**: a refusal says what it knows

| | |
|---|---|
| What | All six `getCode()` readers now read the SQLSTATE through `DriverError`. `withoutLeakingCredentials()` gains two phases and two messages. Both over-claiming refusal messages state what was **attempted**. `printable()`'s invalid-UTF-8 fallback gets a test and the docblock enumerates five messages by name |
| §5 rows | **R-013** closed · **R-023** closed · **R-024** closed |
| Test | `tests/Feature/Database/RefusalDiagnosticsTest.php`, 8 cases. Reaching the bootstrapper's **build** phase took a lever: pointing the bootstrap connection at a dead port fails on `select pg_advisory_lock(...)` instead — outside the redaction entirely, and correctly so, because everything before the role statement carries no credential. The case uses `beforeExecuting()` on the bootstrap connection, the same hook `TenantScopeGuard` uses and for the same reason |
| Revert | `statementFailed` back to `getCode()`: **1 failed, 58 passed** · bootstrapper back to one phase: **1 failed, 58 passed** · `tenantSettingChanged` claims the rollback: **1 failed, 44 passed** · `transactionLeftOpen` claims the unwind: **2 failed, 44 passed** · invalid-UTF-8 fallback → a constant: **1 failed, 58 passed** · restored: **46 passed** |
| Counts explained | The fourth takes 2 because both the swallow case and the plain depth case assert that wording — which is the point of the second case existing. The first two each fail only their own case, because every other message in the suite goes through a statement error where `getCode()` happens to be right |
| Two mistakes the reverts caught | `pg_terminate_backend` needs privileges of the target role or `pg_signal_backend`, and `qrpager_owner` has neither — it answers `42501` *"permission denied to terminate process"*. The kill goes through the root connection. **And the one that matters:** the swallow test asserted the message wording inside `if ($caught instanceof TenantContextException)`, and on the killed-connection path Laravel's own wrapper answers with a `QueryException` — so the assertion never ran, and reverting that message left the suite **green**. An assertion that can skip itself is not an assertion. The conditional is gone and a second case drives the ordinary deeper-depth path, where the unwind actually succeeds and the message still refuses to say so |

### 11 · `453f05d` — **closes R-014**: a timeout configured far too high is refused, not waited out

| | |
|---|---|
| What | A ceiling per setting in `config/tenancy.php`, asserted where the value is **written**: `db:bootstrap` refuses one past it. Since `TestCase::setUp` runs `db:bootstrap`, a bad value is one clear failure in the first test instead of a hang in the twelfth |
| §5 rows | **R-014** closed |
| Test | three cases in `RoleTimeoutTest`, including one that reads the role's live setting afterwards to prove nothing was written |
| Revert | ceiling check removed: **2 failed, 10 passed** · `0` no longer unlimited: **2 failed, 10 passed** · the owner's exemption removed: **12 ERRORED, 0 passed** · restored: **12 passed** |
| Counts explained | The third is the loudest result on this branch and it is correct: without the exemption, `db:bootstrap` refuses the real configuration, and it runs in `TestCase::setUp`, so every test in the file errors before its body runs |
| Found on the way | **The trap inside the trap.** `statement_timeout = 0` means **no** timeout, so it is the most dangerous value the app role could carry — and as an interval it is the **smallest**, so a ceiling that only compared magnitudes would wave through exactly the value it exists to stop. `0` is now past every ceiling unless the role/setting pair is exempt by name, which is what turns the owner's deliberate `statement_timeout = 0` into an exemption rather than an accident |

### 12 · `34f7204` — **closes R-018**: no test alters a permanent database role

| | |
|---|---|
| What | `TestCase::withThrowawayRole()` creates `qrpager_probe_<hex>`, grants the attribute, connects as it on a temporary connection and drops it in a `finally`, so `qrpager_app` is never altered. All three sites keep their refusal assertions and hold `pg_roles.rolbypassrls = false` for `qrpager_app` throughout |
| §5 rows | **R-018** closed |
| Revert | the helper alters the live role: **3 failed, 61 passed** · `DROP OWNED BY` removed: **1 failed, 63 passed** · the stale-probe sweep removed: **1 failed, 63 passed** · restored: **64 passed** |
| Counts explained | The first revert took **2**, not 3, until one line was added: with the helper reverted to altering the live role, the bootstrap-convergence site still passed, because `db:bootstrap` converges `qrpager_app`'s attributes just as happily. The line that can tell the two apart is asserting the role under test is not `qrpager_app`, and it is now there. The other two reverts each fail `RoleNamingTest` from opposite directions — without `DROP OWNED BY` the drop throws and the leftover appears mid-run; without the sweep, a leftover from any earlier interrupted run is still there at the start |
| Three things this cost | `CONNECT` has to be granted explicitly, because the bootstrap revokes it from `PUBLIC` (`§2.1`) — a new role cannot open a session and the connection fails `08006` *"permission denied for database"*, with the refusal under test replaced by a connection error. `DROP OWNED BY` must precede `DROP ROLE`: Postgres refuses with `2BP01` while any privilege is granted **to** the role, and the convergence site points `tenancy.roles.app` at the throwaway and runs `db:bootstrap`, so it ends up holding grants. And a sweep, because `RoleNamingTest` pins the cluster's login roles as an **exact** set — found by that test failing with **eight** leftovers from the runs it took to discover the CONNECT grant |

#### R-018's `kill -9` demonstration, run on this machine

R-018 asks for *"a `kill -9` during the test leaving `pg_roles.rolbypassrls = false` for `qrpager_app`"*.
Both halves were run, because the contrast is the finding. The script is
`bin/`-adjacent only in spirit — it lives in the session scratchpad, reproduced here in full:

```bash
# (a) the OLD approach: grant, then SIGKILL before the revert
$root->unprepared('alter role qrpager_app bypassrls');
try { posix_kill(posix_getpid(), SIGKILL); }
finally { $root->unprepared('alter role qrpager_app nobypassrls'); }   # never runs

# (b) withThrowawayRole's statements, SIGKILL in the same window
$root->statement("create role {$role} with login noinherit password 'x'");
$root->statement("grant connect on database \"{$db}\" to {$role}");
$root->statement("alter role {$role} bypassrls");
try { posix_kill(posix_getpid(), SIGKILL); }
finally { $root->statement("drop owned by {$role}"); $root->statement("drop role if exists {$role}"); }
```

```
== baseline
qrpager_app.rolbypassrls = f

== (a) the OLD approach, killed mid-window
exit=137
qrpager_app.rolbypassrls = t   <-- tenant isolation is OFF for every process on this cluster

== repairing the cluster before continuing
qrpager_app.rolbypassrls = f

== (b) the NEW approach -- withThrowawayRole's statements -- killed in the same window
exit=137
qrpager_app.rolbypassrls = f   <-- untouched
leftover role           = qrpager_probe_967961f1f852 (bypassrls=t, has no privilege on any table)
login roles now         = 5

== (c) the next suite run sweeps it
{"tool":"pest","result":"passed","tests":5,"passed":5,"assertions":9,"duration_ms":487}
leftover roles after    = 0
```

(a) is the window R-018 is about, reproduced exactly. (b) leaves a role with no privilege on any table
that nothing connects as — a mess, not a hole — and (c) is the sweep removing it.

### 13 · `447002d` — **closes R-016**: no `audit_log` assertion reads rows unfiltered

| | |
|---|---|
| What | `TestCase::runMarker()` — static so every row and assertion in one run agree, random so two runs never do — with `auditAction()` and `auditContext()` putting it in the action and the `context` column of every row the suite writes. `AuditLogAssertionTest` scans `tests/` for a read of the table with no filter. The two sites `§5` names are converted |
| §5 rows | **R-016** closed |
| Revert | the scan treats every region as filtered: **1 failed, 16 passed** · WorkerScope's count unfiltered: **1 failed, 17 passed** · `runMarker()` not stable: **2 failed, 15 passed** · the marker not in context: **1 failed, 16 passed** · comments visible to the scan: **1 failed, 17 passed** · the scan reads statements, not blocks: **0 failed, 18 passed** · *…paired with the unfiltered count:* **0 failed, 15 passed** · restored: **18 passed** |
| Counts explained | The last pair is the proof and the reason the brace rule exists. Reverting the brace rule **alone changes nothing**, because nothing in a correct suite needs it. Reverting it *together with* WorkerScope's filter puts the suite back exactly where it was: an unfiltered count on an append-only table, and a scan that cannot see it. Reporting the first revert alone would have been reporting a control I had not proven |
| Three things the scan got wrong first | It **missed the site `§5` names by name**: `WorkerScopeTest` reads the table through a loop variable, so splitting the source on `;` puts the name in one piece and the read in the next. The region now runs to the matching `}` when a brace opens first. It **flagged the documentation of its own rule** — `0b59659`'s lesson arriving again at the same place — so the source is read through `token_get_all` with comments replaced by their own newlines, keeping line numbers honest. And its deliberately-bad statement was written inline where the scan flagged it; the bad and good shapes live in `tests/Fixtures/auditlog` and are fed in explicitly, **not** the test exempted by path |
| Stronger, not just safer | Filtering `WorkerScopeTest` by the tenant it seeded makes the assertion say *the row this test just wrote is invisible to the worker*, rather than *the table looks empty*. The unfiltered version was bounded by row level security, which is why it passed and why it was still the exact shape R-016 is about |

### 14 · `c426f45` — **closes R-009**: the role verifier reaches the Postgres log nowhere

| | |
|---|---|
| What | `set log_statement = 'none'` **and** `set log_min_error_statement = 'panic'` on the bootstrap session, before the advisory lock. Session-scoped, so R-004's cluster-wide `log_statement=all` survives — asserted: the bootstrap session reports `none` while the grant connection in the same run still reports `all` |
| §5 rows | **R-009** closed |
| Test | `tests/Feature/Database/BootstrapLoggingTest.php`, 4 cases, one of them reading the **real container log**. The test sets `log_statement = 'all'` on the database as root first: without it the case passes for the wrong reason in CI, whose Postgres service defaults to `none` |
| Revert | the SET removed entirely: **4 failed, 0 passed** · moved after the advisory lock: **1 failed, 3 passed** · suppressed cluster-wide: **2 failed, 2 passed** · `log_min_error_statement` not suppressed: **2 failed, 288 passed** (full suite) · the verifier bound into a comparison: **1 failed, 289 passed** (full suite) · restored: **290 passed** |
| Counts explained | Removing the SET fails all four, and the fourth reports the measurement: **18** occurrences of `SCRAM-SHA-256` in the window, three bootstraps at R-009's own figure of **6** each, reproduced exactly. Suppressing it cluster-wide fails the case that exists to protect R-004 — the grant connection reports `none` where it must report `all` |
| Two leaks R-009 does not name | **`log_statement = 'none'` does not cover a statement that FAILS.** Its text is logged in full by `log_min_error_statement`, whose default is `error`, so the one path where a human is most likely to be reading the log was the one path still publishing the credential: `STATEMENT: ALTER ROLE qrpager_app … PASSWORD 'SCRAM-SHA-256$4096:…'`. `panic` suppresses the STATEMENT line only; the ERROR is governed by `log_min_messages` and is still logged. **And one of this project's own tests published three verifiers per run** — `select rolpassword = ? …` on the root connection logs bound parameters. It now reads the value and compares in PHP with `hash_equals` |
| The window, which took two attempts | A per-test window reads a second-resolution timestamp, so it either missed a neighbouring test's leak or caught one at random depending on how fast the suite ran — measured both ways, which is how the second leak was found and then nearly lost again. It now spans the whole run. Its remaining limitation is stated rather than discovered: it sees only tests that ran **before** it, and the CI step that runs the same scan after the whole suite is what removes the ordering dependency |

### 15 · `8a79554` — **closes R-005**: a bypass that fails leaves a trace a rollback cannot take

| | |
|---|---|
| What | D7's shape: a dedicated `tenancy` Monolog channel, carrying the `RedactLogs` tap like every other channel. The `try` wraps the **whole transaction**, not just the callback |
| §5 rows | **R-005** closed |
| Test | `tests/Feature/Database/FailedBypassTraceTest.php`, 7 cases, reading the real file the logger wrote. The line is four fields — reason, connection, throwable class, SQLSTATE — and the throwable's message and the exception object are deliberately absent |
| Revert | the trace not written: **4 failed, 11 passed** · the catch wraps the callback only: **1 failed, 14 passed** · the reason left out: **4 failed, 11 passed** · the throwable's message put in context: **1 failed, 14 passed** · the channel loses its tap: **2 failed, 13 passed** · `sqlstate` back in the secret sweep: **1 failed, 14 passed** · restored: **15 passed** |
| Counts explained | Removing the trace and removing the reason fail the same four, which is right: every case asserts on the reason, because a line that cannot be attributed to a caller is not a trace. Narrowing the catch fails only the commit-failure case, which is the case that exists for it. Losing the tap fails 2, the second being `LogRedactionTest`'s own sweep over every configured channel — so a channel added without the tap is caught by the control that already existed as well as by this row's test |
| Found on the way | **The Redactor was redacting `sqlstate`.** `SECRET_KEYS` is matched by substring and `sqlstate` contains `sql`. The substring rule is **not** loosened — it is what makes `DB_OWNER_PASSWORD` and `passwordConfirmation` both covered — and there is now a one-entry `NOT_SECRET_KEYS` allowlist that a key has to be argued onto, with the value still scrubbed as a string |
| And a testing trap | `expect(...)->toThrow(Throwable::class)` **does not assert the type.** Pest treats a `toThrow()` argument that is not a class as a MESSAGE to match, and `Throwable` is an interface — so the assertion silently became *"the exception message contains the word Throwable"*, and it reported the whole driver message as the actual value, which is a very confusing way to find out |

### 16 · `0a1cc35` — **the readiness carrier**: R-012's measured proof

| | |
|---|---|
| What | `SaturationProbe` on the **worker** connection, `readiness:refresh` on `->everyFiveSeconds()` with `withoutOverlapping()`, writing one Redis key |
| §5 rows | **R-012 (Task 2 half)** — the measured proof `c8a7fac` left owed |
| Test | `tests/Feature/Readiness/ReadinessCarrierTest.php`, 7 cases. 40 app connections held with raw PDO handles against the live cluster; the 41st refused with **`08006`**, asserted rather than assumed, because `53300` is the SQLSTATE anyone would guess and never fires for a per-role `CONNECTION LIMIT` |
| Revert | the probe measures on the **app** connection: **2 failed, 10 passed** · readiness queries Postgres per request: **3 failed, 9 passed** · cadence drops to once a minute: **1 failed, 11 passed** · `withoutOverlapping` removed: **1 failed, 11 passed** · a stale snapshot reads as ready: **1 failed, 11 passed** · restored: **12 passed** |
| Counts explained | The first revert reproduces R-012's original bug exactly and fails 2 — the second failure is the informative one: with the probe on the app connection, *"Postgres is down"* becomes true whenever the app pool is full, which is the two answers collapsing into one. The second fails 3, including `c8a7fac`'s own route-surface case, so the no-database-on-the-request-path clause is held by two independent tests from two commits. The cadence revert fails on the **repeat interval** and not on the cron expression, because `->everyFiveSeconds()` and `->everyMinute()` both produce `* * * * *` — an assertion on the expression alone would have passed |
| Found on the way | The command called `measure()` and then a `refresh()` that measured **again**, so it published the second reading while printing the first — a log line that disagrees with the key it describes. `publish()` now takes the snapshot |

### 17 · `d4edbd2` — deny-by-default, per-IP and per-tenant limits, and the 404 bucket

| | |
|---|---|
| What | `HarnessTenantResolver` (B5) reading a container binding and nothing else, refusing outside `local`/`testing`; `RequireTenantScope` (deny-by-default, opening no scope); `RateLimit` per IP **and** per tenant; a rate-limited fallback route for 404s; a deptrac `AuthStub` layer Features may not depend on |
| §5 rows | none — three gate clauses, plus B5, B7 and D5 |
| Test | `RateLimitTest` (8), `TenantResolutionTest` (7), and a fourth deptrac fixture in `ArchitectureGuardTest`. Against **real Redis**, because the suite runs `CACHE_STORE=array` and the one behaviour that matters here — what happens when the store is unreachable — belongs to Redis |
| Revert | the per-tenant key removed: **2 failed, 23 passed** · the limiter rethrows instead of failing open: **2 failed, 23 passed** · the fallback loses its limiter: **2 failed, 23 passed** · the resolver reads a header: **1 failed, 24 passed** · the IP keyed in the clear: **1 failed, 7 passed** (RateLimitTest, 8) · restored: **25 passed**. Plus deptrac, driven directly: a Feature importing the stub gives **Violations 1**; widening Features' rule takes it to 0; removing the file takes it to 0 with the real rule |
| Three tests that passed for the wrong reason | **"Take Redis away" by changing `database.redis.default.port` does nothing to the limiter.** The cache manager keeps the resolved `redis` store and that store keeps its connection, and `config/cache.php`'s redis store uses the **`cache`** connection, not `default` — so the fail-open case passed with Redis perfectly healthy, caught only because it also asserts the warning that fail-open logs. **A bucket configured at 0 attempts does not refuse:** Laravel's `tooManyAttempts()` resets the counter and returns false when the count has reached the maximum and no timer key exists yet, so a maximum of 0 lets the first request through. **And the key-hashing case built its own key** and asserted the hash it had just computed, so reverting the middleware's `sha1()` left it green; it now makes a real request and scans the `cache` connection |
| Nine existing tests started returning 401 | Not loosened: `TestCase::actingForTenant()` binds the harness value, which is the only way a request gets a tenant before Task 5, and each file says so. A test that does not call it gets a 401 — which is the clause working |

### 18 · `8a366a1` — load shedding: a fast 503 for a saturated pool and for real pressure

| | |
|---|---|
| What | `ShedLoad` on the `/v1` group (first, before the limiter), reading the readiness key for pool saturation and `PressureGauge` for cgroup v2 memory and CPU throttling |
| §5 rows | none — the gate's shedding clause, and D4 |
| Test | `tests/Feature/Http/ShedLoadTest.php`, 13 cases. **Fast means no Postgres**, asserted with a query listener over a real request; one case holds 41 PDO handles against the live cluster, lets the carrier measure it, and sheds end to end. The gauge is driven against real files in a temporary cgroup tree rather than a mock |
| Revert | the pool clause removed: **5 failed, 22 passed** · shedding fails CLOSED on no snapshot: **1 failed, 26 passed** · the memory clause removed: **1 failed, 26 passed** · the CPU signal becomes utilisation: **2 failed, 25 passed** · the memory fallback reads the machine: **4 failed, 23 passed** · `ShedLoad` added to the probe group: **3 failed, 24 passed** · restored: **27 passed** |
| Counts explained | The memory-fallback revert fails four, and three of them are the interesting ones: reading the machine's 6 GB against a 512 MB ceiling sheds **every** `/v1` request, so cases that assert a 200 below the threshold fail — which is precisely the failure mode the fallback exists to avoid. The probe-group revert fails the "both probes keep answering" case and the ordering case, which is the clause's second half made mechanical |
| The asymmetry | Shedding fails **open** where readiness fails **closed**, on the same key. Readiness answers *should traffic come here*, so not knowing must mean no; shedding answers *should I refuse this request*, so not knowing must mean carry on — otherwise losing Redis turns into an outage, and the gate's own clause is that the API keeps serving with Redis down |
| Found on the way | The readiness key is cross-test shared state in a real store with a 60-second TTL, so a saturated snapshot one test publishes sheds every `/v1` request after it — three `TenantResolutionTest` cases and two `LogRedactionTest` cases started answering 503 the moment this middleware existed. `TestCase::setUp` now clears it for the same reason it truncates the tables |

### 19 · `ed1e4fc` — the OpenAPI spec, generated and diffed

| | |
|---|---|
| What | `composer openapi` writes `docs/openapi.json`; `composer openapi:check` re-exports to a temporary file and diffs, and is a CI step |
| §5 rows | none closed; **R-026** is raised for the pre-1.0 pin (Q5) |
| Test | `tests/Feature/Http/OpenApiSpecTest.php`, 5 cases, including one that changes a byte of the committed spec, asserts the check exits non-zero and says `STALE`, restores it, and asserts it passes again |
| Two wrong-while-looking-fine specs | The vendor default `api_path => 'api'` matches nothing here — every public route is under `/v1` and the probes are unversioned — so the first export was an **empty** document. And `§7`'s own caveat came true: with the readiness body built in a private helper and the status in a `$ready ? 200 : 503` expression, Scramble described the whole endpoint as `{"type":"string"}`. Two returns with literal status codes give both outcomes with the real object shape |
| Not in the spec yet, correctly | `ProblemType` lands as `#/components/schemas/ProblemType` when a documented route returns a `Problem` — verified in phase 1 — and `routes/v1.php` is empty at Task 2 by design. Documenting a route just to get the schema into today's spec would be worse than waiting for Task 3's first endpoint |

### 20 · `73a5d78` — the pinned FrankenPHP runtime, with both proofs in CI

| | |
|---|---|
| What | `Dockerfile` on `dunglas/frankenphp:1.13.3-php8.4-bookworm`, a `frankenphp` compose service behind a profile, `bin/drain-proof` and `bin/redis-down-proof`, and a `runtime` CI job that runs both |
| §5 rows | none — the SIGTERM drain clause and the Redis-outage clause, neither reachable from `php artisan test` |
| Measured | `FrankenPHP v1.13.3 PHP 8.4.27 Caddy v2.11.7` · `PHP 8.4.27 (cli) (ZTS)` · in-flight response `200 {"completed":true,"held_seconds":3.005,"pid":1}` · exit code `0` · with Redis up `/readyz = 200`; with Redis down `/healthz = 200  /readyz = 503  unknown path = 404`; with Redis up again `/readyz = 200` |
| Why 404 is the line to read | That request goes through the rate limiter, which cannot reach Redis. 404 means it failed open; **500** would mean a `RedisException` reached the renderer, which is the default behaviour the code replaces |
| Four things the build taught me | `composer install` failed with *"The zip extension and unzip/7z commands are both missing"*. `composer dump-autoload` failed looking for `Laravel\Pail\PailServiceProvider` — `bootstrap/cache/packages.php` had been **copied from the host**, built with dev packages, while the image installs `--no-dev`; a `.dockerignore` now keeps the build to source. The cache directories have to be created in the build, because `package:discover` needs `bootstrap/cache` to exist. And copying `frankenphp-worker.php` alone is not enough: it does `require __DIR__.'/bootstrap.php'`, so every thread died with *"Failed opening required '/app/public/bootstrap.php'"* and FrankenPHP exited on *"worker has not reached frankenphp_handle_request()"* |
| The trade, stated | `routes/drain.php` is loaded when it exists and the environment is not production. A drain is only observable with a request that outlives the signal, and no such route belongs in the application. The file is mounted into the drain container and never committed, with two tests holding the other half: it is absent from the repository, and `/drain/slow` is not in the served surface. The alternative — a sleep in a middleware behind a header — is a production code path a request can trigger, which is strictly worse |

### 21 · `6b99a48` — `PLAN.md`: §0, the three gate clauses, §2.11, §4a and every §5 disposition

| | |
|---|---|
| What | The rank-3 gate clauses (Q2) into `§3` row 2; the per-scope and per-request costs (D11) into `§2.11`; Task 2's evidence into `§4a`, measured against the `7f1c8f6` baseline table; all seventeen `§5` dispositions, each naming the commit that closed it and how; `§0` saying Task 2 is code-complete and **not** tagged; `§6`'s driver-code item turned from a warning into a measurement |
| New `§5` rows | **R-026** (Scramble pinned pre-1.0, owner Task 12) · **R-027** (an attacker who downs Redis removes rate limiting, owner Task 12 with Task 13) |
| R-017 | Its cell now records that the depth counter was already in the code at `7f1c8f6` and that the row's own text describes the boolean it replaced — the text is stale, not the work owed (B3) |

---

## Where the ledger stands at `6b99a48`

**All seventeen `§5` rows Task 2 owns are closed, each with a revert proof:**
R-005 · R-007 (T2 half) · R-009 · R-010 · R-012 (T2 half) · R-013 · R-014 · R-015 · R-016 · R-017 ·
R-018 · R-019 · R-020 · R-021 · R-022 · R-023 · R-024.

**H-C's two remaining Task 2 clauses are closed:** `assertSafeRuntimeRole()` at boot (exit 1, reason
logged, proven as a real process) and D9's widening, which reads the resolved config as well as the
environment.

**Open, named rather than hidden:** **R-026** and **R-027**, both raised by this task's own work and both
owned by Task 12.

**Still owed before the tag:** the `§11` QA review and the `§11` Security review of this diff. `§6` makes
both a precondition for `task-2`, and neither has run.

**Suite:** 198 at `7f1c8f6` → 217 (`c8a7fac`) → 231 (`2655bdd`) → 240 (`8075aec`) → 251 (`34abd69`) →
257 (`e5b7868`) → 270 (`0a5feb3`) → 278 (`ccca185`) → 281 (`453f05d`, `34f7204`) → 286 (`447002d`) →
290 (`c426f45`) → 296 (`8a79554`) → 303 (`0a1cc35`) → 321 (`d4edbd2`) → 334 (`8a366a1`) →
**341 passed, 1149 assertions** (`ed1e4fc` onward), against the composed Postgres 16 and Redis 7.
`pint` passed · `stan` 0 errors · `deptrac` 0 violations · `guard` clean · `openapi:check` in sync, at
every commit. The 240 figure was re-measured on a second machine at `8075aec` and was identical, so the
number is the code's and not one builder's machine's.

**Coverage** is deliberately not quoted here: `§4a` forbids measuring it locally, so the figure is the
one the CI run produces.

### Environment note

`.env`'s `APP_KEY` was empty on this machine, which `CLAUDE.md`'s documented setup (`php artisan
key:generate`) fills and which nothing had run here. It surfaced as *"No application encryption key has
been specified"* the first time a test booted a second application through Octane's `ApplicationFactory`
— the ordinary suite never resolves the encrypter, so an empty key had been invisible. `.env` is
gitignored; no tracked file changed.
