# QRPAGER-13 — Task 6, Filament admin: tenant provisioning (S1) — the handover

> Branch `task-6-provisioning`, cut from the `task-5` tag (`5a227f8`). Epic **E4** (`QRPAGER-5`).
> Built by an unattended builder session on 2026-10-28.
>
> **No PR and no merge**, by instruction — `main` stays untouched until client handover
> (`§10`), and the tag is the delivery record.
>
> Read with `docs/tickets/QRPAGER-13.md`, which carries the ticket, the seven `§6` answers
> and the five stop-and-reports. **This file is the builder's reasoning written down and the
> `§11` reviewers were not given it.**

---

## 1. WHAT I WOULD DO FIRST IF I WERE YOU

1. **Read `§2` — the two rank-2 edits that are yours and nobody else's.** The first is why
   this task's whole shape rests on a clause the repository does not contain.
2. **Then `§3`, the four gate clauses, quoted and answered one at a time.** Two are not met
   and one is met by substitution; each has a `§5` row with an owner. `§3` row 6 itself is
   deliberately **not** reworded to match what I built.
3. **Then `§6` — the two reviews, and the one finding that was a real isolation defect.**
   Security H-1 was a genuine cross-tenant read, and the trap it fell into is documented in
   `PLAN.md §2.6`, which I read during Phase 0 and did not connect to the design I was
   writing. If you read one finding, read that one.
4. **Then run the gate yourself**, from `apps/api`: `composer gate`. The transcript at head
   is `docs/tickets/QRPAGER-13-gate.txt`.
5. **Then open the panel.** `php artisan serve`, sign in through `/v1/auth/login` as a
   platform admin, then visit `/platform`. There is **no** login page at `/platform/login`
   and that is deliberate — `§5.6`.
6. **Then `§5`, the assumptions.** The one most worth your disagreement is the first: none
   of the three new tables carries a column named `tenant_id`. Both reviewers pushed on it
   and both accepted it; you may not.

## 2. THE TWO RANK-2 EDITS THAT ARE YOURS

Both are in `QRPAGER-13 §9` ready to paste. Neither blocks anything I built; both mean the
repository does not yet say what two rank-1 sources say it says.

### (a) `§4 › Tenancy` does not carry the two clauses dated 2026-10-28

The prompt and `QRPAGER-13 §0` both state that it does. Measured: **zero matches for
`2026-10-28` in `docs/PROJECT_INSTRUCTIONS.md` on every ref** — `origin/main`,
`origin/task-4-notifications`, `origin/task-5-auth-rbac` and `task-5^{}`.

The ruling is in force regardless, because it is rank 1 and the file is rank 2. What is
missing is its record, and writing it is a rank-2 edit the prompt reserves to you. **Until it
lands, `§3.1` row 6's resolution rests on a prompt rather than on the repository** — which is
the shape security S-1 was about at Task 3, one level up.

### (b) `§4 › Security` has no role-seniority clause, which is what `R-106` needs

`R-106` was Task 6's by `§5`, and **it moves to Task 10 rather than closing here** — on
**one** reason, not the two I first gave:

- Its own *review by* cell prescribes "write both into `§4 › Security` as a **rank-2 edit**".
  A builder session cannot take that.

⚠ **The second reason I first gave is withdrawn as unsound, and the security review was right
to push on it.** I said the trigger had not fired because this task ships no staff-management
screen. But this task shipped a live writer for `R-106`'s **consent** half in
`TenantProvisioning::resolveOwner()`: it made any existing account the owner of a new tenant
with no consent, which re-opened the login-strand denial of service `R-106` itself records —
through the provisioning form instead of through a staff screen. That half is now **refused**
(an account holding any membership, or a platform admin, cannot be named as first owner), and
the residual policy question — what a restaurant group's owner does when they legitimately
want a second restaurant — is **`R-119`**.

The **seniority** half is genuinely untouched by this task: `RoleMatrix` is unchanged but for
a docblock, nothing here touches `members:write`, and the only membership this task creates is
the first `owner` of a brand-new tenant, which has no senior role to escalate past.

Recommended clause text is in `QRPAGER-13 §9.3`. **New owner: Task 10 (`QRPAGER-17`)**, which
is where a tenant's own owner or manager administers staff and which `R-106`'s own cell
already names for the two-membership login edge. **Re-owned 2026-10-28**, and the `§5` row now
says so — it did not until the review caught it.

## 3. THE GATE, CLAUSE BY CLAUSE — all four, quoted

`PLAN.md §3` row 6, verbatim and unchanged by this branch:

> "A new restaurant goes from zero to a scannable QR in one wizard pass, with no SQL run by
> hand; the wizard is transactional — a failure halfway leaves no partial tenant; platform
> admin actions all land in `audit_log`; a staff invite is actually delivered, asserted
> against the local mail catcher (ADR-002)."

**⚠ My first accounting of this was incomplete and QA was right to say so (M-7).** It listed
two clauses, dropped two, and — in `§3.1`, not `§3` — wrote "an audit log" where the gate says
"`audit_log`", which is the self-amendment my own SR-2 had promised not to make. Both are
corrected, and `§3.1` row 6 now quotes all four.

| # | Clause | Met? | Evidence, or the row that owns it |
| --- | --- | --- | --- |
| 1 | "zero to a **scannable QR** in one wizard pass, with no SQL run by hand" | **NO** (second half yes) | A scannable QR is a `pager_sessions` row. The *no-hand-SQL* half IS met: `RestaurantCreateActionTest`'s round trip runs panel action → `platform:provision-tenants` → `platform:aggregate-stats` and asserts the tenant, its first owner and its aggregate row, with no SQL by hand anywhere. **`R-115`, Task 10.** |
| 2 | "the wizard is **transactional** — a failure halfway leaves no partial tenant" | **YES** | An injected trigger failure at step 4 of 5 leaves **0** `tenants`, **0** `tenant_users`, **0** `audit_log` rows, plus one `failed` registry row carrying a mapped reason with no SQL, no SQLSTATE and no trigger name in it. Revert-proved by revert A — **by two genuine assertion reds**, see `§6.4`. |
| 3 | "platform admin actions all land in **`audit_log`**" | **NO as written; met against `platform_audit_log`** | Measured impossible for a serving process: 42501 on insert for `qrpager_app` with *and* without a context and for `qrpager_owner` without the bypass; 0 rows on read for the app role either way; owner-with-bypass alone can do either. A **false claim corrected in place**, not a `§5` row. Now pinned by a test (QA M-5 — it was asserted in six documents and tested nowhere). |
| 4 | "**a staff invite is actually delivered**, asserted against the local mail catcher" | **NO** | **`R-124`, Task 10** — and recorded separately from `R-115` on purpose. **The ruling is no obstacle to this one at all**: delivering mail to Mailpit touches no tenant table. The honest reason is that no invite is minted by this task, so there is no credential to deliver. Folding it in with clause 1 under "the panel cannot reach a tenant table" was itself the finding. |

### The named invariant — MET, and revert-proved in BOTH halves

> No panel query reaches a tenant table, and a platform admin with no membership can read no
> tenant row by any route the panel exposes.

- `PlatformPanelInvariantTest` — 16 cases. The route set is **enumerated from the router**:
  the four `/platform` paths pinned exactly, **and Livewire's `update` endpoint**, matched by
  pattern because its prefix is derived from `APP_KEY`. QA M-2 found that endpoint missing
  from the enumeration, and it carries every table search, sort and modal submit in the panel.
- Each route driven with a `DB::listen()` listener; every statement classified by
  `TenantTables::referencedBy()`, the registry the runtime guard itself uses.
- Driven as a platform admin, as an anonymous caller, and as all three tenant roles.
- The three platform models are reflected over: **a relation method fails the test**, because
  one `->with('tenant')` is how this breaks later.
- **Revert-proved**: revert B (unscoped read) reds the status case, revert C (a read *inside*
  a scope) reds the invariant case itself. The second is the one that matters — see `§6.4`.

### `§6`'s universal gate

| Clause | Figure, at the commit it was measured at |
| --- | --- |
| `composer gate`, all six steps | **green at `FINAL_SHA`** |
| Suite | **SUITE_FIGURE** |
| Per-task coverage, `bin/coverage --task=task-5 --min=90` | **COVERAGE_FIGURE**, threshold passing |
| Three consecutive green full-suite runs | **THREE_RUNS** |
| Fresh **clone** | **FRESH_CLONE** |
| Migrations twice + rollback clean | `bin/migration-rollback-proof`, all five checks green at `FINAL_SHA` |
| Every new route in the generated OpenAPI spec, `/v1`-versioned | `composer openapi:check` matches; `docs/openapi.json` is **byte-identical to `task-5`**, because the panel mounts no `/v1` route. `RouteSurfaceTest`'s allowlist carries the argument and names the four panel paths one by one. |
| Shared contracts from one source, CI fails when stale | `provisioning_status` in `DatabaseEnums::map()`; `EnumContractTest` compares `pg_enum` against the PHP cases in both directions |

## 4. WHAT IS BUILT, AND THE SHAPE OF IT

### Three PLATFORM tables, no RLS, no new tenant table

`tenant_provisionings` (the provisioning record and the registry the panel lists),
`tenant_statistics` (aggregates, one row per tenant), `platform_audit_log` (append-only).
Data contract in `PLAN.md §2.4`, which was updated **in the same commit as the migration**.

**No new GUC. No new cross-tenant policy — still exactly the four named ones. No grant
widened on any tenant table.**

### `§3.1` row 6's blocker is resolved by design, not by a fifth named path

That row has said since 2026-10-26 that Task 6 would need either a fifth named cross-tenant
path — a rank-2 edit to `§4` — or a different dashboard. **It needed neither.** The aggregate
job enumerates tenants from a *platform* table, then reads each tenant **inside that tenant's
own context, one at a time**, on a connection whose role holds **no cross-tenant policy on any
table**, with an explicit `where tenant_id = ?` as a second bound.
`RlsCoverageTest::it('has exactly the four named cross-tenant policies and no others')` is
untouched by this task, which is the mechanical statement of the same thing.

⚠ **Every clause of that paragraph is now true and one of them was not when I first wrote
it.** "An ordinary scoped read" was the sentence security H-1 falsified: the reads ran on the
**worker** connection, whose permissive `worker_reads_expired_sessions` policy is OR-ed into
every scoped read it makes, so "inside that tenant's own context" bounded nothing. The two
bounds above are the fix, and `§6.1` is the measurement. The conclusion survived; the
reasoning behind it did not, which is the distinction worth carrying forward.

### The two halves of S1, and why they are two

`TenantProvisioning::request()` runs on the panel's request and writes two platform rows in
one transaction. `TenantProvisioning::provision()` runs out of band and does the tenant-side
work inside the new tenant's own context — set to the id it is about to insert, which
`tenants`' `id = ctx` policy satisfies exactly. A single synchronous method would have put
`insert into tenants` on the panel's request, which is the one thing the invariant forbids.

**The out-of-band half is a scheduled command, not a queued job**, and that is measured
rather than preferred: there is no queued job anywhere in this application, `§2.4` records
that `jobs` and `job_batches` were dropped from the skeleton at Task 1, and `outbox` cannot
carry it because `outbox.tenant_id` is `NOT NULL REFERENCES tenants (id)` — the tenant does
not exist yet. **Both commands are scheduled in the same commit that created them**, which is
`R-058`'s ruling applied before it could be repeated.

### The panel

Filament **v5.11.0** on Laravel **13.38.0**, both verified by running code. It lives in
`app/Features/Provisioning/Panel` because deptrac rule 2 forbids `Platform → Features`, and
the provider is registered in `bootstrap/providers.php` beside Task 4's, which set that
precedent.

**No `->login()`.** `§4 › Tenancy` says there are two authentication surfaces, never
confused; Filament's own credential form would be a **third**, with its own throttling and no
second factor, against the `users` table Task 5 moved behind `authenticate_user()` and a TOTP
challenge. The panel reuses the `web` session Task 5's login establishes, named explicitly
with `authGuard('web')` so a change to `config/auth.php`'s default cannot move it silently.
An anonymous caller gets the ordinary **401 problem+json**, not a redirect.

**No widgets.** The generator adds `AccountWidget` and `FilamentInfoWidget`; the second
renders the installed Filament version to anyone who can see the dashboard.

---

## 5. ASSUMPTIONS, EACH WITH THE ALTERNATIVE I REJECTED

### 5.1 None of the three platform tables carries a column named `tenant_id` — **read this one hardest**

C4's meta-test classifies a table as tenant-scoped by exactly two rules read from
`pg_catalog`: `relname = 'tenants'`, or **a column named `tenant_id`**. A platform table
carrying that name would be classified tenant-scoped and `RlsCoverageTest` would demand RLS on
it, which the ruling forbids.

**My claim** is that it is not a rename to escape the classifier: each table is **one row per
tenant**, so the tenant's id is the row's *identity* rather than a scope over many rows —
`tenant_provisionings.id` **is** the id the tenant will carry. That is the mirror of the
property `TenantTables` already documents for `tenants` itself, with the sides reversed:
`tenants.id` **scopes** tenant data; these ids **reference** a tenant from a record holding
none.

**Rejected (a):** name the column `tenant_id` and give the tables RLS. The platform admin
holds no tenant context, so they would read nothing — which is exactly the state Task 5
shipped and this task exists to fix without widening it.
**Rejected (b):** loosen the classifier to a hand-maintained list. That trades the one
mechanical control stopping a *new* tenant table escaping isolation for a label on three
tables.

**What I added so the classifier is not merely sidestepped**: `PlatformTableContractTest`
pins all three column sets **exactly** against `information_schema`; asserts no `tenant_id`
and none of a forbidden row-level-detail list; asserts the three carry no RLS **and** that the
app role reads them with `app.tenant_id` unset (the absence of a policy alone would pass on a
table the role had no grant for); and pins the scope guard's matcher against all three names
with `audit_log`, `tenants` and `tenant_users` as controls — `platform_audit_log` **embeds**
`audit_log` and survives only on the matcher's identifier-boundary lookbehind, which is a
property of a regex rather than of a schema.

### 5.2 No subscription table, although the ruling names "subscription records"

`QRPAGER-13 §2` says billing/subscriptions is **out of scope, owned by nobody until the
client states it** (`PLAN.md §6`). The more specific statement wins, and inventing plan names,
seat semantics and a billing period nobody has specified would be the speculative code `§8`
forbids. The category is real; the table waits for a requirement.

### 5.3 The answer to "what is in the database after a failure at step 4 of 5" is not quite "nothing"

The ticket says it must be "nothing". I built **"nothing of the tenant, and one platform row
that says the attempt failed"**, because an attempt that leaves no trace at all is one nobody
can see failed. The tenant half is absolute and tested; the platform row is deliberate.

### 5.4 The panel reports "Requested", not "Created"

The restaurant does not exist when the action returns and cannot — creating it writes
`tenants`. A screen saying "created" over a row still reading `pending` is `R-058`'s failure
mode with a cheerful face on it.

### 5.5 The aggregate job READS as the app role and WRITES as the worker

⚠ **This section said "the aggregate job runs as the worker" and that was the H-1 defect
written down as an assumption.** It is corrected rather than deleted, because the reasoning
that produced it is the thing worth reading: I chose the worker because `§2.7` points
background work at it and because the panel deliberately holds no write on
`tenant_statistics`. Both halves of that are still true. What I missed is that the worker
carries a **permissive cross-tenant SELECT policy** on `pager_sessions`, so "inside a tenant's
scope" does not bound it — `§6.1`.

So the split is now: **reads on `pgsql` (app)**, which holds no cross-tenant policy on any
table, with an explicit `where tenant_id = ?` as a second bound; **write on `pgsql_worker`**,
which is what keeps "the job is the only writer" a GRANT rather than a convention — the app
role holds no `INSERT` and no `UPDATE` on `tenant_statistics` at all.

The provisioning job runs as the **app** role, unchanged: `§2.7` gives `qrpager_app` the
`tenants` and `tenant_users` inserts and gives the worker neither, so provisioning must be the
app role or a grant has to widen. Neither job widens anything.

### 5.6 The panel mounts no login page, and reuses Task 5's session

`§4 › Tenancy` says there are **two authentication surfaces, never confused**. Filament's
`->login()` would be a **third**: its own credential form, with its own throttling and no
second factor, against the `users` table Task 5 moved behind `authenticate_user()` and a TOTP
challenge. So it is absent, and the panel trusts the `web` session
`AuthController::login()` establishes — named explicitly with `authGuard('web')` so a change
to `config/auth.php`'s default cannot move it silently.

**Rejected:** mounting the login page "because the generator does". An unauthenticated visitor
gets the ordinary **401 problem+json** instead of a second password box, which is also what
makes `route default is deny` true on all four panel routes rather than approximately true.

⚠ One consequence worth knowing: the panel has no session invalidation on password change,
because `AuthenticateSession` **cannot work here** — `R-006` revoked the app role's `SELECT`
on `users.password`, so `getAuthPassword()` returns null and the comparison can never differ.
Measured, and the middleware is removed rather than left reading as protection. `R-120`.

## 6. THE TWO `§11` REVIEWS — 32 findings, dispositioned in one pass

Both ran **concurrently in two separate sessions**, each given only
`git diff task-5...task-6-provisioning` and `docs/tickets/QRPAGER-13.md`. Neither received
the other's output, this file, or any of my reasoning.

**QA 19 — 0 Critical, 2 High, 8 Medium, 9 Low.**
**Security 13 — 0 Critical, 2 High, 3 Medium, 8 Low.**
**Combined 32 — 0 Critical, 4 High, 11 Medium, 17 Low.** All 32 dispositioned.

**The named invariant held in both reviews, both reviewers said so explicitly, and neither
found a Critical.** The full table is in the relay and in `PLAN.md §4a`.

### 6.1 Security H-1 — the one finding that was a real isolation defect

Read this one if you read nothing else.

The aggregate job read `pager_sessions` on the **worker** connection inside each tenant's
scope, and I wrote a docblock calling that "an ordinary scoped read". It was not.
`qrpager_worker` carries `worker_reads_expired_sessions`, a **permissive** cross-tenant
SELECT policy, and permissive policies are **OR-ed** — so inside tenant A's scope the job
also saw every other tenant's past-TTL sessions, which in the production steady state is most
of them. Every tenant's counters, percentiles and window bounds were contaminated; one
reviewer measured a `window_start` that predated the tenant's own existence.

I reproduced it before fixing it: **worker 4 rows over 2 tenants, where the app role sees
1 over 1.**

**`PLAN.md §2.6` already documents this exact asymmetry** — *"⚠ AND IT BOUNDS THE
CONTEXT-FREE PATH ONLY"*, from Task 3's security L-2 — and I read that paragraph during this
task's Phase 0 and did not connect it to the design I was writing. That is the part worth
keeping: the trap was written down, in the file I had just read, and the documentation did not
fire. A reader of `§2.6` sees it stated about the expiry job and has to notice that it is a
property of the ROLE, not of that job.

Fixed with **two independent bounds**, only one of which lives in that file: the reads moved
to the **app** connection, which carries no cross-tenant policy on any table, **and** each
statement now carries `where tenant_id = ?` explicitly. Revert-proved — the faithful revert
fails with `4 is identical to 1`.

### 6.2 Security H-2 — the panel had no rate limiter

Every `/v1` route, the auth group and even the 404 fallback carried a bucket. The four panel
routes and Livewire's update endpoint carried none, and each anonymous 401 or 419 minted
**one Redis session key with a 7 200 s TTL** — because `StartSession` persists a session to
carry a CSRF token *before* `Authenticate` refuses, on a path needing no credential, in the
store `§1` locks for session **and** cache **and** queue. I had found this myself before the
review returned; the reviewer measured the key growth, which is the part that makes it a High
rather than a note.

### 6.3 The other two Highs were record defects, and both were mine

- **QA H-1 / security M-3.** `R-114`…`R-118` were cited in the ticket, in a test's header and
  in a code comment while appearing **zero** times in `PLAN.md`, and `R-106` still named Task
  6 although the ticket said it had been re-owned. I drafted those rows in a scratchpad and
  never applied them. This is the "claimed before written" pattern `§0`'s debt cell already
  records for `R-003`, `R-004`, `R-006` and `R-035`, and I repeated it.
- **QA H-2 / security L-2.** `attempts` was incremented, selected, and read by **nothing**
  while three documents claimed "retries bounded by `attempts`", and a docblock named a
  `--retry-stalled` option that did not exist — so a row left at `running` by a killed
  process was stuck for ever, shown as "In progress" on the dashboard, recoverable only by
  the hand SQL `§3` row 6 forbids. That is `R-058`'s shape in new code. Both now exist: a
  real `MAX_ATTEMPTS` cap, and a per-row `--retry-stalled` that resets the counter
  deliberately.

### 6.4 ⚠ A FIGURE I PUBLISHED WAS WRONG, and the reviewers caught my own instruments

**"Revert A turns 8 cases red" was published in a commit message and in a status report, and
it is wrong.** Six of those eight reds were `ErrorException`s: my revert split the transaction
with a pattern and left `$ownerUserId` undefined, so what went red was the harness breaking,
not the guard being removed — which is the *precise* thing `bin/_revert-proof.sh` exists to
reject, and the defect its own header records about the lease proof. **Two** genuine assertion
reds carry the transactional clause. `ErrorException` and its family are in the not-evidence
list now, and revert A is written out by hand in `bin/support/` instead of being assembled by
a regex.

Three more things my own instruments got wrong:

- **The revert proof took its restore copy from the working tree** (`cp` to a temp file)
  rather than from git. With a concurrent reviewer session in the same checkout, that restored
  the *other* session's revert and reported it byte-identical — truthfully, to the wrong
  baseline — and one reviewer's run consequently measured the invariant case **green**. It
  now refuses a dirty tree and restores with `git checkout --`.
- **The listener half of the invariant's proof had never been seen red** (QA M-1). The runtime
  scope guard refuses an unscoped statement before it is sent, so `DB::listen` never fired and
  the red came from a status assertion sharing the case. The status check is its own case now,
  and **revert C** reads a tenant table *inside* a scope — the case the guard allows and only
  the listener catches.
- **`revert_proof_run` never required a revert to produce any red**, so an edit that matched
  nothing would have passed while the verdict printed "every revert kept its controls green
  and every red was an assertion" — true, and vacuous. Each revert declares a required red
  now.

And **three of my own assertions were the vacuous `not->toContain($needle, $message)` shape**,
caught by `SourceGuard` rule 7 — the control working on the very assertions that carry this
task's named invariant.

### 6.5 Where I disagreed with a reviewer, and lost

Security M-3 judged my stated reason for moving `R-106` **partly unsound**, and it was right.
I gave two reasons: that the fix needs a rank-2 edit, and that the trigger had not fired
because this task ships no staff-management screen. The second is wrong — this task shipped a
live writer for `R-106`'s **consent** half in `resolveOwner()`, which made any existing
account owner of a new tenant with no consent and re-opened its named login-strand denial of
service through the provisioning form instead of a staff screen. **The move stands on the
rank-2 reason alone**, the consent half is now refused (security M-2), and the residual policy
question is `R-119`. The seniority half is genuinely untouched: `RoleMatrix` is unchanged but
for a docblock, nothing here touches `members:write`, and the only membership this task
creates is the first `owner` of a brand-new tenant, which has no senior role to escalate past.

## 7. SIX DEFECTS FOUND WHILE BUILDING, AND ONE SCANNER THAT WAS WRONG

Each was measured, not reasoned, and each is fixed in this branch unless a row says otherwise.

1. **Every `php artisan` command died on a fresh checkout**, printing only the exception
   reporter's own crash (`A facade root has not been set`). Cause: `vendor/` was still the
   Task 4 tree, because `git checkout` does not touch a gitignored directory and Task 5 added
   three packages. `composer install` fixed the tree; **the defect worth recording is that
   the original exception was destroyed** — `R-114`, Task 12.
2. **Installing Filament added two routes that answered 500 to an anonymous GET** —
   `filament/exports/{export}/download` and the imports equivalent, reading tables this
   application never created — plus two Livewire file-upload endpoints for a panel with no
   upload. `RefuseUnservedVendorRoutes` answers the ordinary 404 for all four. Global and
   prepended, because a competing route would depend on service-provider order.
3. **Three of my own assertions were the vacuous `not->toContain($needle, $message)` shape**,
   which passes trivially. `SourceGuard` rule 7 refused them — the control working on the very
   assertions carrying this task's named invariant.
4. **`tenant_provisionings` and `tenant_statistics` were not truncated between tests**, so one
   case read ten aggregate rows where it expected one. Both join `TestCase::RESETTABLE`;
   `platform_audit_log` stays out, because its `ENABLE ALWAYS` trigger refuses `TRUNCATE`.
5. **`phpunit` 13.4.3 carried a published advisory** (PKSA-qhjm-rpqc-qkn6, reported
   2026-10-27). Updated with pest to 13.4.5 / v5.3.4; `composer audit` is clean.
6. **The duplicate-address error would not have appeared beside its field.** A
   `ValidationException` thrown from a Filament action's closure lands under the bare key
   `slug`, while Filament attaches a field's errors at `mountedActions.0.data.slug` — both
   keys read back in this session. The uniqueness is now a rule **on the field**; the caught
   exception is reduced to the race it can honestly cover.

**And `OutboxLeaseIdentityTest` was looser than its own name.** It read every
`set … attempts = …` literal in `app/` whatever table it targeted, while its INSERT half had
always required `insert into outbox` — two halves of one control disagreeing about their
subject. `tenant_provisionings.attempts` exposed it. The UPDATE half now requires
`update outbox`, which **narrows** the matcher and keeps every statement it was written for.

---

## 8. ALSO WORTH KNOWING

- **`deptrac` reported 39 uncovered and zero violations** when the panel arrived. The
  architecture rules held the moment it was written — it lives in `app/Features` precisely so
  rule 2 is not broken — and what was missing was the Framework collector's knowledge of two
  vendor roots. `Filament` and `Livewire` added; `Uncovered: 0`, which is the figure `§4a`
  records.
- **Livewire 4 derives its route prefix from `APP_KEY`** —
  `sha256(key.'livewire-endpoint')[:8]` — deliberately, so a scanner cannot guess it. **It
  differs on every installation, including CI.** Every place that names it uses the pattern
  `livewire-<8 hex>`, and `RouteSurfaceTest` asserts the pattern still matches the live routes
  — otherwise the identical matcher inside `RefuseUnservedVendorRoutes` would stop refusing
  the upload endpoints silently, which is a guard going quiet rather than failing.
- **`php artisan filament:install --panels` rewrote `bootstrap/providers.php`**, dropping both
  of its comments and reordering the list, and generated a provider under `app/Providers`.
  Both undone by hand. Worth knowing before anyone runs that installer again.
- **The panel's four routes are not under `/v1`, and should not be.** `/v1` versions the REST
  contract — a promise to API clients about request and response shapes. A server-rendered
  admin UI has no API consumers. `RouteSurfaceTest`'s allowlist carries the argument and lists
  the four one by one, so a fifth arrives in a diff.

---

## 9. WHAT I STOPPED ON

**Nothing blocked, and I made no rank-2 edit.** Five `§0`/`§8` stop-and-reports were
**recorded and continued past**, which is what the prompt directs: `QRPAGER-13 §0`
SR-1 … SR-5.

Two of them matter and only one is still open:

- **SR-1 is still yours** — `§4 › Tenancy` does not carry the two clauses dated 2026-10-28
  that both the rank-1 prompt and `QRPAGER-13 §0` state it carries. Zero matches on every
  ref. The ruling is in force because it is rank 1 and the file is rank 2; what is missing is
  its record, and writing it is the rank-2 edit in `§2(a)` above.
- **SR-2 is answered** — the rank-1 ruling against `§3` row 6's own scope. `§3` row 6 is
  unchanged; `§3.1` row 6 now quotes all four clauses and answers each; the unmet ones have
  `§5` rows with owners; and the tag message names them. ⚠ My first wording of *why* three of
  them are unreachable was too broad and QA M-7 corrected it: they are unreachable **from the
  panel's request**, not from this task's architecture, which writes two tenant tables out of
  band from a scheduled command inside the tenant's own context.

**Nothing was brought to you mid-session**, because nothing met the prompt's bar for it: no
finding changed Task 6's design rather than fixing a defect, no `§5` row needed an owner that
does not exist, and the two rank-2 edits are recorded rather than made.

## 10. ANY SCRIPT THAT PROVES SOMETHING LIVES IN THE REPO (R-043)

- **`bin/provisioning-revert-proof`** — revert-proves the transactional clause and both halves
  of the named invariant, through `bin/_revert-proof.sh`. It now **refuses a dirty tree**,
  restores with `git checkout --`, and each of its three reverts declares a **required red**.
  Its two reverts are written out by hand in `bin/support/revert-split-transaction.py` and
  `bin/support/revert-panel-reads-tenants.py`, so the edit a reader has to trust is a file
  with a name rather than a regex inside a heredoc.
- **`bin/_revert-proof.sh`** — gained `ErrorException`, `TypeError`, `Undefined variable` and
  their family in the not-evidence list; `revert_proof_require_clean_tree`,
  `revert_proof_restore` and `revert_proof_require_red`. Every proof in this repository that
  sources it is stricter as a result.
- **`bin/migration-rollback-proof`** — extended with Task 6's own positive and absence checks:
  the three platform tables present and `rls=f`, **zero policies** on them, **zero `tenant_id`
  columns** on them, both append-only triggers `ENABLE ALWAYS`, and no `UPDATE` or `DELETE` on
  `platform_audit_log` for either runtime role.
- **`bin/coverage --task=task-5 --min=90`** — the per-task figure.
- **`bin/three-green-runs`, `bin/fresh-clone-proof`** — unchanged, re-run at head.

Nothing that proves something about this task lives only in a session scratchpad.
