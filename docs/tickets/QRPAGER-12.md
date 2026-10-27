# QRPAGER-12 — Task 5, Auth & RBAC — the ticket

> Branch `task-5-auth-rbac`. Epic **E3** (`QRPAGER-4`). Built by an unattended builder
> session on 2026-10-27/28.
>
> Read with `docs/tickets/QRPAGER-12-handover.md`, which carries what is done, what is
> not, every assumption, the gate figures and the commits they were measured at.
>
> **The board is the source for `§1`–`§9` below**, transcribed from `QRPAGER-12` as it read
> on 2026-10-27T22:10Z (the rewrite the rank-1 prompt refers to), plus its one comment.
> `§10` is this session's work: the seven `§6` pre-coding answers.

---

## 0. Where the branch was cut — the one deviation from the instruction

The instruction was: **"Branch `task-5-auth-rbac` off `task-4-notifications` at the task-4
tag (`55a4f98`)."**

**Cut at `f44085b` instead — the tip of `task-4-notifications`, one commit after the tag.**

```
git rev-parse task-4^{commit}   -> 55a4f9850c17a49252a31f9cfc2fe74ac60cdbbc
git rev-parse task-4-notifications -> f44085b0267ae1447d0f5223953eb603bf7a9f5c
git log --oneline task-4^{commit}..task-4-notifications
  f44085b fix(proofs): a red is only evidence if it is the assertion failing
```

**Why, and it is mechanical rather than a preference.** The same prompt's invariant list
says *"Every revert-proof script you write uses `bin/_revert-proof.sh`. A red counts as
evidence only when it is the assertion failing."* That file is added **by `f44085b`** and
does not exist at `55a4f98`:

```
$ git cat-file -e 55a4f98:apps/api/bin/_revert-proof.sh
fatal: path 'apps/api/bin/_revert-proof.sh' exists on disk, but not in '55a4f98'

$ git cat-file -e f44085b:apps/api/bin/_revert-proof.sh   # exit 0
```

Branching at the tag would have made two instructions in one prompt unsatisfiable at the
same time. `f44085b` **contains** `55a4f98`, so nothing in the tagged tree is lost — the
branch is one docs-and-proofs commit **ahead** of the tag, not beside it or behind it. The
tag message's figures still describe this tree for everything but that one commit, and the
baseline measured below confirms it: identical counts.

**Baseline, measured before any Task 5 work so that a later red can be attributed.**

```
composer gate   all six steps green, exit 0 — 654 passed / 4132 assertions   at f44085b
```

Identical to the `task-4` tag's own figures. The transcript is
`docs/tickets/QRPAGER-12-baseline-gate.txt`.

**`§6`'s order is satisfied and the "merged" reading is not what it requires.** `§6` says
*no task starts before the previous one is green and tagged*. `task-3` (`f30bd24`) and
`task-4` (`55a4f98`) are both green and tagged; neither is merged, and no PR or merge was
ever authorised for either branch. `PLAN.md §0` carried *"Task 5 is blocked on Tasks 3 and
4 being reviewed, merged and tagged"* — corrected in place in Phase 0, because "merged"
appears in no rank-2 clause.

---

## 1. Scope

* Fortify + Sanctum
* WebAuthn passkeys
* 2FA
* Roles: platform admin / owner / manager / staff
* KDS device tokens
* Session policy
* **Deletion of the pre-Task-5 authentication stub** — see `§6`
* **R-081** — suspended-tenant enforcement on the staff surface — see `§7`

Plus, from the board's comment of 2026-10-25: **a minimal unstyled harness page**, built
here purely to exercise the WebAuthn browser API. No design, no tokens, no layout — a
button and a result.

## 2. Out of scope

| Not here | Owned by |
| --- | --- |
| **Customer accounts** | **Nobody. Forbidden —** `§4 › Data`: no customer accounts, no phone numbers stored. |
| The Filament panel itself | Task 6 |
| Login screens and passkey UI | Task 10 |
| CSP, CORS, fuzzing, ASVS mapping | Task 12 |
| The surface that **sets** `tenants.status = 'suspended'` | Task 6 — a platform-admin action, which `R-081`'s own cell assigns there |
| Platform-admin **cross-tenant reads** | **Nobody yet.** Not on `§4 › Tenancy`'s named list; `PLAN.md §3.1` row 6 already records that Task 6 needs it settled before it builds the dashboard. See `§11.1`. |

## 3. Pre-coding gate — answer all seven before coding (`§6`)

* \[x] **1.** Tables / columns / roles / GUCs touched. Data-contract change → `PLAN.md §2` first. Note which new tables need RLS ENABLE + FORCE (C4) — **every tenant table, no exception**.
* \[x] **2.** The role enum has one source, shared with policies. No duplicated role list.
* \[x] **3.** Tenant context path. "A user's own memberships" is a **named** cross-tenant path in `§4 › Tenancy` — show it is scoped to the authenticated user and cannot be widened.
* \[x] **4.** Security: route default is **deny**; every public route explicitly marked and justified; session fixation; CSRF; token scope.
* \[x] **5.** Scale: cost of an auth check per request. What is cached and for how long — remembering Octane keeps workers alive between requests.
* \[x] **6.** Failure: passkey registration abandoned halfway; 2FA device lost; device token leaked.
* \[x] **7.** Research: Fortify + Sanctum + WebAuthn APIs on **Laravel 13**, verified by running code in-session. Paste output. Do not assume a package version — check it.

Answered in `§10`.

## 4. Acceptance gate

### 4.1 What the board carries

* \[ ] Full role-matrix test
* \[ ] Session fixation and CSRF tests
* \[ ] A tenant-provisioning actor **cannot read another tenant's data**
* \[ ] Passkey registration + login E2E
* \[ ] Device token scope **cannot escalate**

### 4.2 ⚠ The board calls that "verbatim from `PLAN.md §3`, row 5" and it is not

Measured against `PLAN.md §3` row 5 on 2026-10-27. The board's list **omits three things**
and **softens a fourth**, and all four are in the rank-3 file, which wins on content
(`§0` precedence: the repo's `PLAN.md` outranks a ticket). The authoritative gate, verbatim:

> Full role-matrix test; session fixation and CSRF tests; a tenant-provisioning actor
> cannot read another tenant's data; **passkey registration + login proven end to end
> through the browser against the harness page** (the styled screen is Task 10, and must
> not be required here); device token scope cannot escalate; **the pre-Task-5 auth stub is
> gone, proven by three assertions rather than by a reviewer remembering:
> `app/Platform/Auth/HarnessTenantResolver.php` does not exist, the `TenantResolver`
> binding resolves to the real implementation, and no test references
> `HarnessTenantResolver::BINDING`**

What the board's `§4` leaves out:

1. **The three stub assertions** (`R-035`), added to `§3` row 5 on 2026-10-27 *before this
   task started*. The board's `§6` covers the deletion in prose but its gate does not name
   the three mechanical checks, and the three are the point — `§4` calls a surviving stub a
   **Critical** finding and the only thing that would otherwise have noticed is
   `TenantResolutionTest`'s `glob()` of one directory, which fires only if Task 5's own
   resolver happens to land there.
2. **"proven end to end *through the browser against the harness page*"**, with the
   parenthetical that the styled screen is Task 10 and *must not be required here*. The
   board says only "Passkey registration + login E2E", which is what its own comment of
   2026-10-25 was written to fix.
3. **The harness page itself** is in `§3` row 5's task column and in the board's comment,
   but not in the board's `§1` scope list.

Recorded rather than silently reconciled (`§0`: stop and report, never silently resolve) —
but this is a ticket-versus-`PLAN.md` difference where `PLAN.md` is the higher rank and says
*more*, not something different, so it is **not** a blocking conflict. **This task ships
against `PLAN.md §3` row 5 as quoted above.**

### 4.3 The named invariant for this task (`§11`)

**A tenant-provisioning actor cannot read another tenant's data.**

This is the clause a review can falsify, and it is the only one that blocks `task-5` once
the `§11` round limit is reached. Written on the board **before** the work started, per
`§11`: *a task whose gate names no invariant a review could falsify is not ready to begin.*

`PLAN.md §3.1` row 5 names three, and all three are treated as the bar:

> A tenant-provisioning actor cannot read another tenant's data · device token scope cannot
> escalate · the pre-Task-5 auth stub is gone, by three assertions (added 2026-10-27,
> `R-035`)

### 4.4 What `§3.1` says this gate still needs — and what this task does about it

`PLAN.md §3.1` row 5, *what this gate still needs, at the start of that task*:

> "Full role-matrix test" does not say what full means. Name the matrix — roles × actions ×
> own-tenant/other-tenant — or it is unfalsifiable by anyone but its author

**Done here, at the start of the task, before any implementation commit** — which is what
`§3.1` prescribes ("Each is fixed at the start of its own task, from this list, by the
person who is about to do that work"). The matrix is named in `§10.2` as a single source in
PHP consumed by both the policies and the test, and the `§3` row 5 gate is amended to name
it. The amendment is a rank-3 edit to the file's own task table, attributed and dated in
place, exactly as Task 3's and Task 4's gate amendments were.

## 5. Known traps

* \[ ] A platform admin is **not** a tenant bypass. If platform admin needs cross-tenant reads, that is a new named path and `PROJECT_INSTRUCTIONS.md §4` must be **edited first** — not assumed.
* \[ ] Octane: anything the auth layer caches in a container or static property leaks across requests and therefore across tenants. Prove isolation.
* \[ ] A KDS device token lives on a shared screen in a kitchen. Scope it to exactly one tenant and one capability, and make expiry explicit.
* \[ ] Route default is deny. A new route with no middleware must fail closed, and there must be a test that fails if someone adds an unguarded route.

## 6. Carry-over that must NOT survive this task — the authentication stub

`PROJECT_INSTRUCTIONS.md §4 › Tenancy`: every route built in Tasks 2-4 is either on the
customer token path or guarded by Task 2's deny-by-default guard with a **test-only**
authentication stub **that is deleted in Task 5**. _A stub that survives Task 5 is a
Critical finding._

* \[ ] The stub resolver and **every** call site are deleted. The full list is in `docs/tickets/QRPAGER-11-handover.md` under the heading **DELETE IN TASK 5** — read it, do not rediscover it.
* \[ ] A grep over `app/` for the stub's class name returns **zero** hits, asserted by a test, not by reading.
* \[ ] Any deptrac or architecture rule that existed only to contain the stub is either removed or repurposed deliberately — not left pointing at a class that no longer exists.
* \[ ] A design disagreement is recorded in `PLAN.md` about where that resolver should live (a production class that throws outside `local`/`testing`, versus a `Tests\Support` class kept out of production by the "production code imports no test helpers" rule). **Task 5 settles it by deleting the stub entirely**; if anything is kept, say what and why.

### 6.1 The DELETE IN TASK 5 list, transcribed from the handover

Re-verified by grep at `f44085b` rather than copied on trust — the counts below are this
session's measurement.

| File | What it is |
| --- | --- |
| `app/Platform/Auth/HarnessTenantResolver.php` | **The stub itself.** Reads a container binding, never an HTTP input; refuses to run outside `local`/`testing`; held in deptrac's own `AuthStub` layer that `Features` may not import |
| `app/Providers/AppServiceProvider.php` | the one production reference: `TenantResolver::class` → `HarnessTenantResolver::class` |
| `tests/TestCase.php` → `actingForTenant()` | the only way a test gives a request a tenant |
| `tests/Feature/Http/TenantResolutionTest.php` | the stub's own behaviour, including its refusal outside local/testing |
| Nine further test files calling `actingForTenant()` | `tests/Feature/Database/TenantScopeGuardTest.php` · `tests/Feature/Http/ProblemEnvelopeTest.php` · `tests/Feature/Http/RateLimitTest.php` · `tests/Feature/Http/ShedLoadTest.php` · `tests/Feature/Http/TenantResolutionTest.php` · `tests/Feature/Logging/LogRedactionTest.php` · `tests/Feature/Pager/PagerIdorTest.php` · `tests/Feature/Pager/PagerRouteSurfaceTest.php` · `tests/Feature/Pager/PagerTtlTest.php` |
| **Task 4's own files: none** | `PagerCustomerSurfaceTest` mentions `actingForTenant()` twice, both in comments, because the customer surface authenticates by token |

`R-035` is the row: nothing mechanical fails if the stub survives Task 5.

## 7. R-081 — suspended tenants, owned by this task

`tenants.status = 'suspended'` is enforced at no point on the staff surface: a suspended
tenant's staff can create, ready, renotify, complete and cancel exactly as an active one.
Found by the Task 3 security review, accepted into `PLAN.md §5` as **R-081** with this task
as owner, and named in the `task-3` tag message.

* \[ ] Enforcement lands on the staff surface as middleware, one place, not per route
* \[ ] A test proves every staff route refuses a suspended tenant
* \[ ] The customer surface's behaviour for a suspended tenant is decided deliberately and written down — an existing session is not silently broken without a stated answer
* \[ ] `R-081` is closed in `PLAN.md §5` with the commit that closed it

**The row rejects two alternatives in advance**, and the ticket's "as middleware, one place"
has to be read with them: a **per-query filter** is rejected because it puts the check in
every call site; a **database-side control** is rejected by measurement, because
`§2.7` grants `qrpager_app` `UPDATE (name, status, timezone, updated_at)` on `tenants` and an
in-scope write of `status` reports `UPDATE 1` — the role whose requests would be refused can
rewrite the column. So the check belongs **where the credential is exchanged**. See `§10.4`.

## 8. Definition of Done — `§6` and `§11`

* \[ ] `composer pint`, `composer stan`, `php artisan test` green against **real** Postgres + Redis
* \[ ] Fresh **clone**, not just a fresh DB: migrations apply from empty; second apply is a no-op; **rollback of this task's migrations runs clean**
* \[ ] Unit coverage **≥ 90%** on the code this task adds, measured with `bin/coverage`, number reported
* \[ ] Every REST endpoint added is in the **generated OpenAPI spec**, committed, versioned `/v1/`
* \[ ] Every shared contract (role enum, DTO) generated from its single source; **CI fails when it is stale**
* \[ ] Every bug found has a regression test that **fails without the fix** — proven by reverting it
* \[ ] All previous tasks' suites still pass
* \[ ] `PLAN.md` updated
* \[ ] Branch `task-5-auth-rbac`, pushed, CI green
* \[ ] **QA review** in a separate agent session — findings posted here **in full**, with the count (`§11` relay rule)
* \[ ] **Security review** in a separate agent session — findings posted here **in full**, with the count
* \[ ] Every Critical and High fixed with a regression test; Medium / Low fixed or written into `PLAN.md §5` with an owner and a date
* \[ ] ~~**Tag** `task-5` **created and pushed.**~~ **No PR, no merge and NO TAG** — the rank-1 prompt of 2026-10-27 overrides this clause and `§11`'s PR step for this task. The reviews are the user's next prompt, not this session's.

## 9. Working rules

One task = one branch = one ticket (`§11`). Never commit to `main`. Commit subject ≤ 72
chars. Reviewers get the diff and this ticket only (`§11`). Never claim a test passed
without pasting output (`§8`).

---

# 10. The seven `§6` pre-coding answers

Written before any implementation commit. Phase 0's ledger sweep (`d691892`) precedes
them and touches no code.

## 10.1 Which tables / columns / roles / GUCs does this touch?

**`PLAN.md §2` is updated in the same commit as the migration and before the code that uses
it**, which is the rank-2 invariant from the prompt. What follows is the contract this task
adds, so that `§2` and the migration are written from one list.

### (a) GUCs — none added. C1 holds unchanged

`app.tenant_id` and `app.bypass_tenant` remain the only two `app.*` settings. **This matters
because the obvious design wants a third.** `R-006`'s *why accepted* cell names it: scoping
`users` rows to "your own user" at the database layer "would be a **third `app.*` GUC** —
forbidden by C1 without editing `PROJECT_INSTRUCTIONS.md` first". So the actor identity
lives in the request/auth layer and never becomes a GUC, and `composer guard`'s C1 rule stays
able to reject any `app.*` name it does not know.

### (b) Roles — no new database role

Three roles remain: `owner` (migrations), `app` (web), `worker` (queue). Task 5 narrows and
widens `qrpager_app`'s **grants**, below.

### (c) `users` — a global table (`§2.4`), gains four columns

| Column | Type | Why |
| --- | --- | --- |
| `is_platform_admin` | `boolean NOT NULL DEFAULT false` | `§2.3` already says "The platform-admin flag is Task 5's and is not in this contract yet". A **flag, not a `tenant_role` value** — see `§10.2` |
| `two_factor_secret` | `text NULL` | Fortify's 2FA. Encrypted at rest by Fortify's own cast |
| `two_factor_recovery_codes` | `text NULL` | Fortify's 2FA, same |
| `two_factor_confirmed_at` | `timestamptz NULL` | Fortify's 2FA; `timestamptz`, not Fortify's `timestamp` — every timestamp in `§2.5` is `timestamptz` and the vendor migration is not used (see (f)) |

**Grant change on `users`, and it is a narrowing — this is `R-006`.** The row's Review-by
cell dictates the clause:

> `qrpager_app` holds no `SELECT` on `users.password` or `users.remember_token`;
> authentication reads the hash only through a named `SECURITY DEFINER` function granted to
> `qrpager_app` alone, proven by a test that the role gets `42501` on
> `select password from users`

So: `qrpager_app` **loses** `SELECT` on `password` and `remember_token`, and **never gains**
`SELECT` on `two_factor_secret` or `two_factor_recovery_codes` — the same class of secret,
added in the same change, so granting them would re-open the row the moment it closes. It
keeps `SELECT` on the non-secret columns, `INSERT`, and `UPDATE` on the credential columns
(a password change and a 2FA enrolment are writes, and a column can be written without being
readable — `§2.7` already proves that shape with `pager_sessions.token_hash`).

### (d) `passkeys` — a NEW global table (`§2.4`)

A passkey belongs to a **user**, and `users` is global because "a user may hold memberships
in several tenants and platform staff belong to none" (`§2.4`). A passkey therefore has no
tenant scope column and is **global, with no RLS** — which is a statement that must appear in
`§2.4` *and* in `tests/Feature/Database/RlsCoverageTest.php`'s allowlist, or the C4 meta-test
fails the build. That meta-test is the thing that stops a new table quietly escaping tenant
isolation, so the row is written first and deliberately.

| Column | Type |
| --- | --- |
| `id` | `uuid` PK — project convention; the vendor migration's `$table->id()` is bigint |
| `user_id` | `uuid` → `users.id` **CASCADE** |
| `name` | `varchar(120)` |
| `credential_id` | `text` **unique** |
| `credential` | `jsonb` — the stored public key and metadata |
| `last_used_at` | `timestamptz NULL` |
| `created_at`, `updated_at` | `timestamptz` |
| index | `(user_id)` |

**`credential` holds a public key, not a secret**, which is the property that makes WebAuthn
worth having here: a database disclosure does not yield anything an attacker can authenticate
with. It is still credential *material*, so it is `#[Hidden]` on the model and never
serialised.

### (e) `personal_access_tokens` — a NEW global table (`§2.4`), Sanctum's

Sanctum's shape, with this project's types. **Global and RLS-free, and the reason is the same
one that makes `users` global**: the token must be resolvable *before* any tenant context
exists, which is exactly the property a tenant-scoped table cannot have.

| Column | Type |
| --- | --- |
| `id` | `uuid` PK |
| `tokenable_type` | `varchar(255)` |
| `tokenable_id` | `uuid` |
| `name` | `text` |
| `token` | `varchar(64)` **unique** — the **sha256 of** the plaintext, never the plaintext |
| `abilities` | `text NULL` |
| `last_used_at` | `timestamptz NULL` |
| `expires_at` | `timestamptz NULL`, indexed |
| `created_at`, `updated_at` | `timestamptz` |
| index | `(tokenable_type, tokenable_id)` |

**There is deliberately NO `tenant_id` column, and that is a decision with a rejected
alternative.** Adding one would make this a tenant table, and C4 then requires RLS `ENABLE` +
`FORCE` on it — at which point the row is invisible to the very lookup that has to happen
before a tenant is known, and the only way back is a **fifth** `SECURITY DEFINER` cross-tenant
path, which `§4 › Tenancy` forbids without editing `PROJECT_INSTRUCTIONS.md` first. So the
tenant a device token is good for is carried **in `abilities`**, and it is *verified* against
the holder's own memberships on every request rather than trusted — see `§10.3`. The cost is
stated rather than hidden: one tenant id sits in a text column in a table with no RLS behind
it, in a row whose own `tokenable_id` already identifies a user.

### (f) The three vendor migrations are NOT used

`laravel/fortify`, `laravel/sanctum` and `laravel/passkeys` each ship one:

```
vendor/laravel/fortify/database/migrations/2014_10_12_200000_add_two_factor_columns_to_users_table.php
vendor/laravel/sanctum/database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php
vendor/laravel/passkeys/database/migrations/2024_01_01_000000_create_passkeys_table.php
```

None is published, and none can be. They use `$table->id()` (bigint, where this project is
uuid), `$table->timestamp()` (where this project is `timestamptz`), `$table->morphs()` (which
would make `tokenable_id` bigint against a uuid `users.id`), and none of them grants a column
to a role, adds a table to the C4 allowlist, or runs as `pgsql_owner`. This project's
migrations run as the owner and its tables carry explicit per-column grants. So Task 5 writes
its own, from the vendor schemas read in-session, and the vendor files stay unpublished.

### (g) New `SECURITY DEFINER` functions (`§2.8`), and only one is a cross-tenant path

| Function | Cross-tenant? | `§4` named path |
| --- | --- | --- |
| `public.user_memberships(user_id uuid)` | **Yes** — `tenant_users` is a tenant table | **"a user's own memberships"**, already named. No `§4` edit |
| `public.authenticate_user(email varchar)` | **No** — `users` is global and carries no RLS at all | Not a tenant path. It is `R-006`'s privilege boundary over a **column grant**, not over a tenant scope |

Both follow `§2.8`'s two rules learned by running it: **never `RETURNS SETOF <table>`**
(column grants are not applied to a function's result set, so a `SETOF users` would hand
`password` straight back and undo the narrowing in (c)), and **one exact-match argument**, so
each answers one question and cannot enumerate.

### (h) `tenant_users` — no column change, and `R-007`'s half is application-level

`R-007`: `qrpager_app` holds table-wide `INSERT`/`DELETE` and `UPDATE (role, updated_at)` on
`tenant_users`, so within its own tenant a broken authorisation check can promote a staff row
to `owner` with nothing underneath it. Task 5 owns "the role matrix and the lower-casing"
half. The matrix is `§10.2`; the grant is **not** narrowed, because a tenant's owner
legitimately manages their own staff and the only available database-level discriminator
would be a per-row actor identity, i.e. the third GUC C1 forbids.

### (i) What needs RLS `ENABLE` + `FORCE` (C4)

**Nothing this task adds.** All three new tables (`passkeys`, `personal_access_tokens`, and
the four columns on `users`) are **global**, for the reasons in (d) and (e). Each therefore
has to be added to `§2.4` *and* to `RlsCoverageTest`'s allowlist, and the C4 meta-test is what
makes that a build failure rather than an oversight. **A reviewer should push hardest here**:
three new RLS-free tables in the authentication layer is the largest single widening of the
RLS-free surface since Task 1, and the justification for each is "it must be readable before a
tenant is known", which is true of all three and is exactly the sentence an attacker would
also like to be true.

## 10.2 Which shared contracts does it use or add? One source only

### (a) `TenantRole` — exists, and is reused rather than redefined

`§2.3` already pins it: Postgres type `tenant_role` ↔ PHP `App\Domain\Tenancy\TenantRole`,
values `owner · manager · staff`, in that order. Task 5 adds **no** role enum. The policies
consume that enum; a second list of role names anywhere is the defect `§6.2` forbids.

### (b) Platform admin is a **flag**, not a fourth `tenant_role` value

Stated because it is the one place a duplicated or wrongly-shaped role list would be easy to
introduce. `tenant_role` holds **tenant-scoped** roles; `§2.4` says platform staff "belong to
none". A `platform_admin` member of `tenant_role` would have to be stored in
`tenant_users.role`, which requires a `tenant_id` — so the value could only ever exist
attached to a tenant, which is precisely what a platform admin is not. It is
`users.is_platform_admin`, read through one accessor.

### (c) The role matrix — the single source `§3.1` row 5 asks to be named

`§3.1` row 5: *"Full role-matrix test" does not say what full means. Name the matrix — roles ×
actions × own-tenant/other-tenant — or it is unfalsifiable by anyone but its author.*

**Named here, before the work.** The matrix is **one PHP array, in one class, consumed by
both the policy layer and the test** — not a table in a test file, because a matrix that
lives only in its own assertion proves nothing about the code.

* **Principals (5):** `platform_admin` · `owner` · `manager` · `staff` · `device` (a KDS
  device token — a principal, not a role, which is why it is in the matrix and not in
  `tenant_role`).
* **Actions:** every `/v1` staff action that exists at this task — the five pager
  transitions (`create`, `markReady`, `renotify`, `complete`, `cancel`), plus the membership
  actions Task 5 itself adds (`members.list`, `members.invite`, `members.updateRole`,
  `members.remove`) and `devices.issue` / `devices.revoke`.
* **Dimensions:** for each (principal × action), the expected answer in **own tenant** and in
  **another tenant**. Other-tenant is always refusal; it is in the matrix because the
  invariant this task ships on is exactly that cell.
* **Totality is mechanical:** the test iterates the route table and fails if any `/v1` route
  is absent from the matrix, so "full" means *every route, every principal, both tenants*
  and a new route added without a matrix row fails the suite. That is the same shape as the
  deny-by-default route test, and it is what makes the clause falsifiable by someone other
  than its author.

### (d) Sanctum abilities — one source

Device and session capabilities are an enum (`App\Domain\Auth\Ability`), not string literals
at call sites. Sanctum compares abilities as strings, so the enum's `->value` is what reaches
the token; the enum is what the code and the matrix name.

### (e) The TypeScript mirror is Task 8's, not this task's

`§6` requires every shared contract to be generated from one source with CI failing when
stale. `packages/contracts` **does not exist until Task 8** (`CLAUDE.md`, ADR-003), which is
also where the status-enum mirror is generated. Task 5 adds no TypeScript, so there is nothing
to generate and nothing to go stale; what it must do is leave `TenantRole` and `Ability` in
the same generated-from-PHP shape the Task 8 generator will read. Stated so that a reviewer
checking the `§6` clause finds the reason rather than an omission.

## 10.3 The exact path by which tenant context reaches every query

### (a) What exists, and what Task 5 replaces

The seam is already built and Task 5 changes **one implementation**:

```
Request
  → RequireTenantScope (middleware, every /v1 route)
      → TenantResolver::resolve(Request): ?string      <-- interface, Platform
      → null  => 401 Problem, bodiless, no route body runs
      → id    => published at RequireTenantScope::BINDING
  → service layer: withTenant($id, fn () => ...)
      → SELECT set_config('app.tenant_id', ?, true)    <-- C2, transaction-scoped
      → TenantScopeGuard refuses any query not inside a scope
```

`TenantResolver`'s own docblock says it: *"Task 5 replaces the implementation and nothing
else."* The resolver is the **only** place a tenant id is decided, and `RequireTenantScope`
opens no scope — a scope opens per unit of work in the service layer, because a
request-scoped transaction would hold one of the forty `qrpager_app` connections for the
whole request.

### (b) The real resolver, and why it needs a tenant *selection*

A staff user may hold memberships in several tenants, so the request must say which one. That
is the one place client input touches tenancy, and it is a **selection that is verified**,
never a value that is trusted:

1. The authenticated principal comes from Fortify's session guard (staff browser) or
   Sanctum's bearer guard (KDS device / API). No principal → `null` → 401.
2. The request names a tenant (one header, `X-Tenant`, validated as a uuid by shape before
   anything else touches it). If it names none and the user holds **exactly one**
   membership, that one is used; if it names none and the user holds several, the answer is
   `null` → 401, never "pick the first".
3. The named tenant is looked up **in the authenticated user's own memberships** (c). Not a
   member → `null` → 401, with the same bodiless answer, because "that tenant does not
   exist" and "that tenant is not yours" must be the same answer from outside —
   `RequireTenantScope`'s docblock already fixes that, and it is what stops the header being
   a tenant-existence oracle.
4. The tenant's `status` must be `active`, or `null` → 401. That is `R-081`, and `§10.4`
   has the detail.

**So the header can only ever *select among* what the user already has.** A forged or
swapped value cannot widen anything: it is an exact-match lookup against a membership set
derived from the session, and the failure mode is refusal.

### (c) "A user's own memberships" — scoped to the authenticated user, and why it cannot be widened

This is the `§4 › Tenancy` named cross-tenant path, and the prompt asks for proof that it is
scoped and unwidenable. The mechanism is `§2.8`'s, which exists precisely so that no policy
has to be widened:

```sql
public.user_memberships(p_user_id uuid)
  RETURNS TABLE (tenant_id uuid, role tenant_role, tenant_status tenant_status)
  SECURITY DEFINER, owned by qrpager_owner, SET search_path = pg_catalog, pg_temp
```

Five properties, each load-bearing, and each a thing a reviewer can falsify:

1. **One exact-match argument, and it is the *authenticated* user's id** — taken from the
   guard, never from the request. There is no code path that passes a user id from input;
   the function is called from one place, with `$request->user()->getKey()`.
2. **`RETURNS TABLE`, never `RETURNS SETOF tenant_users`** — `§2.8` rule 1, because column
   grants are not applied to a function's result set. Three columns, named.
3. **No pattern, range or `IN` argument.** The signature takes a uuid and compares it with
   `=`. There is no shape of input that returns another user's memberships, which is what
   "cannot be widened" means mechanically rather than by intention.
4. **`EXECUTE` to `qrpager_app` alone, after `REVOKE ALL … FROM PUBLIC`** — `§2.7`'s pattern
   for `resolve_pager_token`, and the `REVOKE` is load-bearing: a `SECURITY DEFINER`
   function is executable by `PUBLIC` by default. The ACL is read back from `pg_proc.proacl`
   as an exact set, so a third grantee fails the suite.
5. **It restores the bypass GUC before returning**, verified end to end — the caller's own
   direct queries stay tenant-bound, which is the property `§2.8` says was learned by
   running it.

**And a platform admin is NOT a tenant bypass.** The ticket's `§5` trap and the rank-1
prompt both say so, and the prompt adds: *if one is needed, that is a new named path and
`PROJECT_INSTRUCTIONS.md §4` must be edited first — stop and report, do not assume.* **This
task adds no platform-admin cross-tenant read.** `is_platform_admin` gates
platform-scoped things only (creating a tenant, reading `audit_log` rows whose `tenant_id`
is `NULL`). A platform admin with no membership in tenant X resolves **no** tenant for X and
is refused by the same 401 as anyone else — and that is a *test*, not a comment. See
`§11.1` for the stop-and-report, which is Task 6's blocker and is already recorded in
`PLAN.md §3.1` row 6.

### (d) Every query, including the new ones

| Query | How context reaches it |
| --- | --- |
| The five pager transitions (Tasks 3–4) | unchanged — `withTenant()` in the service layer |
| `user_memberships()` | **no tenant context by design** — it is the named cross-tenant path, bounded by (c) |
| `authenticate_user()` | **no tenant context needed** — `users` is global and RLS-free; the function exists for the column grant, not for a scope |
| Sanctum's token lookup | `personal_access_tokens` is global and RLS-free (`§10.1(e)`) |
| Passkey read/write | `passkeys` is global and RLS-free, keyed by `user_id` from the guard |
| Membership management (`members.*`) | `withTenant($resolved, …)` — a write to `tenant_users` inside the resolved tenant's scope, so RLS bounds it even if the policy check is wrong. That is `R-007`'s only remaining backstop and it is why the matrix test iterates both tenants |
| `devices.issue` / `devices.revoke` | `withTenant($resolved, …)` for the membership check; the token row itself is global |

## 10.4 Security: authn, authz, input validation, rate limit, log redaction, secrets

### (a) Route default is deny, and a test fails if an unguarded route is added

Already true for `/v1` via `RequireTenantScope`, and already tested by
`PagerRouteSurfaceTest`. Task 5 **extends** that test rather than adding a parallel one: the
route table is iterated, and every route must be in exactly one of four named sets —
`PUBLIC_PATHS` (`/healthz`, `/readyz`), the customer token surface, the staff surface behind
the guard, or the new **auth surface** (login, passkey ceremony, 2FA challenge), which is
reachable without a tenant by necessity and is enumerated explicitly. A route in none of them
fails the suite. That is the "fail closed" trap in `§5`, made mechanical.

### (b) The auth surface is public-but-not-unguarded, and it is the one new widening

Login cannot require a tenant, because the tenant is derived from the credential. These routes
sit **outside** `RequireTenantScope` and are therefore the only new routes in the project that
reach application code without a tenant. What bounds them: each is in the named auth set
(a), each carries its own rate-limit bucket (d), none reads a tenant from input, and none
returns anything that distinguishes "no such user" from "wrong password".

### (c) Session fixation and CSRF

* **Fixation:** the session id is regenerated on every privilege change — login, passkey
  login, 2FA confirmation — and the test asserts the pre-login id is **not** usable
  afterwards. Laravel's `SessionGuard::login()` migrates the session; the test exists because
  "the framework does it" is not evidence, and because the passkey and 2FA paths are the ones
  where a custom responder could skip it.
* **CSRF:** the staff surface is session-authenticated, so `VerifyCsrfToken` applies and a
  test drives a state-changing route **without** a token and asserts 419. The Sanctum bearer
  surface is token-authenticated and stateless, so CSRF does not apply to it — stated rather
  than assumed, because the two surfaces must not be confused.

### (d) Rate limits

Per-IP **and** per-tenant is the `§4` rule, and the auth surface can only do per-IP before a
credential resolves — the same argument `config/ratelimit.php` already records for the
customer token route (`R-063`). New buckets: `auth-login`, `auth-2fa`, `auth-passkey`,
`devices-issue`. Each is proven to refuse **by being driven**, not by reading the config —
`R-063`'s clause, applied to this surface because it is the same shape.

### (e) Log redaction and secrets

No password, no `two_factor_secret`, no recovery code, no Sanctum plaintext token and no
passkey challenge reaches a log line or an error body **at any `APP_DEBUG` setting**, asserted
against captured output *and* captured response bodies — the rank-2 clause, both halves. The
`Redactor` already exists; Task 5 adds its key names and a test per key. `#[Hidden]` covers
serialisation for `password`, `remember_token`, `two_factor_secret`,
`two_factor_recovery_codes` and `passkeys.credential`.

### (f) Token scope cannot escalate — the second named invariant

A KDS device token carries exactly one tenant and one capability, with a non-null
`expires_at`. The test drives, rather than reads: a device token is refused on every route
outside its capability; it cannot act in another tenant; it cannot be used to mint another
token; and an expired one is refused. `Sanctum::$accessTokenRetrievalCallback` is not
overridden to widen anything.

## 10.5 Scale: cost of an auth check per request, and what is cached — under Octane

**The prompt's wording is the bar: "Octane keeps workers alive between requests. Anything the
auth layer caches in a container or a static property leaks across requests and therefore
across tenants. State what is cached and for how long, and prove isolation with a test."**

### (a) What is cached, stated exactly

| Thing | Cached? | Lifetime |
| --- | --- | --- |
| The authenticated user | **Per request only** — Laravel's guard resolves once per request | dies with the request |
| The user's memberships | **Per request only**, in a request-scoped object, so `user_memberships()` is called **once** per request rather than per authorisation check | dies with the request |
| The resolved tenant id | **Per request only**, at `RequireTenantScope::BINDING` | dies with the request |
| Anything in a static property | **Nothing. Zero.** | — |
| Anything in Redis or the cache store | **Nothing.** No membership cache, no permission cache | — |

**Nothing is cached across requests, deliberately, and the cost of that is one indexed query
per request.** `tenant_users` carries `INDEX (user_id)` (`§2.5`) and the function takes one
exact-match uuid, so a membership lookup is one index probe returning at most a handful of
rows. That is cheap enough that a cross-request cache would be buying microseconds with a
cross-tenant leak as the downside — which is this question's whole point.

### (b) Why `scoped()` and not `singleton()`, which is already measured in this repo

`AppServiceProvider` records the measurement: a class bound `singleton()` resolved as three
different objects across three real Octane requests, because nothing warmed it, so each
request resolved it inside `clone $this->app` and the sandbox flush discarded it. **A binding
that happens to be discarded is not isolation** — it is isolation by accident, and the next
warm-up changes it. So the membership holder is `scoped()`, which Octane flushes at the
request boundary by contract, and `RequestBoundaryCleanup` (which already runs on both
`OperationTerminated` and the request-boundary event, from `R-022`) is where any new
per-request state is cleared.

### (c) The isolation proof, which is a test and not an argument

`R-022`'s shape, applied to auth: **two consecutive requests on one Octane worker, as two
different users in two different tenants**, asserting that the second request sees its own
user, its own memberships and its own tenant — and, the half that actually catches a leak,
that the **second request entered with no tenant setting and no open transaction** from the
first. Task 2's equivalent test exists because the measured failure was exactly this: the
next request on an Octane worker entered at transaction level 1 carrying the previous
request's tenant uuid. The auth version is the same proof over the new state.

## 10.6 Failure: passkey registration abandoned halfway, 2FA device lost, device token leaked

### (a) Passkey registration abandoned halfway

The WebAuthn ceremony is two steps: the server issues options with a challenge, the browser
returns an attestation. Abandoned between them, **nothing is persisted** — the challenge
lives in the session and expires with it, and `passkeys` gains no row. The failure that
matters is the opposite one: a user who abandons registration and has **no other
credential** must not be locked out, so a passkey is additive and never replaces the
password until the user has at least one working passkey *and* confirms. A test drives
abandonment and asserts zero `passkeys` rows and that the password still works.

### (b) 2FA device lost

Recovery codes, which is why `two_factor_recovery_codes` is in `§10.1(c)`. Each is
single-use; using one does not disable 2FA. If recovery codes are also lost, the path is a
platform-admin reset — which is **Task 6's surface**, so Task 5 ships the capability and the
audit row, and the admin screen is out of scope here. A test asserts a recovery code
authenticates once and then does not.

### (c) Device token leaked

This is the one with a real blast radius, because the token lives on a shared kitchen screen.
Three bounds, all of them mechanical rather than operational:

1. **One tenant, one capability** — a leaked device token is useless outside the one tenant
   and the one thing it may do (`§10.4(f)`).
2. **Explicit, non-null expiry** — the ticket's `§5` trap. A device token without
   `expires_at` is refused at issue time, so "forever" is not expressible.
3. **Revocation is one row delete**, and it is audited. A test drives a revoked token and
   asserts refusal.

What is *not* bounded, stated rather than implied: a leaked device token is valid until it
expires or is revoked, and nothing detects its use from a second location. That is an
observability question the project has no answer for at this task, and if it survives review
it becomes a `§5` row with Task 12 or 13 as owner rather than a sentence here.

## 10.7 Research: Fortify + Sanctum + WebAuthn on Laravel 13, verified by running code

**Everything below is output from this session, not memory. The prompt's instruction was
"verified by running code in-session, never from memory. Paste the output. Check the package
versions; do not assume them."**

### (a) ⚠ The first answer I got was wrong, and the correction is the point

`composer show -a laravel/fortify` with **no version argument** reports **`dev-master`**'s
constraints, and `dev-master` reads:

```
illuminate/support ^10.0|^11.0|^12.0
```

which says Fortify does **not** support Laravel 13 — and would have been a locked-stack
blocker and a `§8` stop-and-report. Asking for the **released** version instead:

```
$ composer show -a laravel/fortify v1.41.0 | sed -n '/^requires/,/^$/p'
requires
php ^8.2
ext-json *
bacon/bacon-qr-code ^3.0
illuminate/console ^11.0|^12.0|^13.0
illuminate/support ^11.0|^12.0|^13.0
laravel/passkeys ^0.2.0
pragmarx/google2fa ^9.0
```

**`^13.0` is there, and `dev-master` is simply behind the release branch.** Recorded because
the wrong reading was one command away from being written down as a stack conflict, and
because `§8` is a rule about exactly this: the first measurement is not automatically the
answer.

### (b) Installed versions, from `composer.lock` after the install

```
laravel/framework          v13.38.0
laravel/fortify            v1.41.0
laravel/sanctum            v4.3.3
laravel/passkeys           v0.2.1
web-auth/webauthn-lib      5.3.9
pragmarx/google2fa         v9.1.0
bacon/bacon-qr-code        v3.1.1
PHP 8.4.27
```

**Fortify v1.41.0 ships passkeys as a first-class feature** via `laravel/passkeys`, which is
a dependency rather than something this project wires itself. That is the single most
load-bearing fact in this answer: the locked stack's "Fortify + Sanctum + WebAuthn passkeys +
2FA" is **one coherent supported set** on Laravel 13, not three integrations.

### (c) Fortify's feature surface, by reflection rather than by documentation

```
$ php -r "…" # ReflectionClass('Laravel\Fortify\Features')->getMethods(IS_STATIC|IS_PUBLIC)
enabled()                        registration()
optionEnabled()                  resetPasswords()
hasProfileFeatures()             emailVerification()
canUpdateProfileInformation()    updateProfileInformation()
hasSecurityFeatures()            updatePasswords()
canUpdatePasswords()             twoFactorAuthentication()
canManageTwoFactorAuthentication()  passkeys()
canManagePasskeys()
```

`Features::passkeys()` and `Features::twoFactorAuthentication()` both exist. Classes
confirmed present: `FortifyServiceProvider`, `Features`, `Fortify`, `Contracts\LoginResponse`,
`Actions\ConfirmTwoFactorAuthentication`.

### (d) Sanctum's surface

```
Laravel\Sanctum\Sanctum                   yes
Laravel\Sanctum\HasApiTokens              yes
Laravel\Sanctum\PersonalAccessToken       yes
Laravel\Sanctum\Contracts\HasAbilities    yes
Laravel\Sanctum\Guard                     yes

PersonalAccessToken own methods: tokenable, findToken, can, cant
NewAccessToken properties:       accessToken, plainTextToken
```

`can`/`cant` on the token is what `§10.4(f)`'s one-capability rule is enforced with, and
`NewAccessToken::$plainTextToken` is the only place the plaintext exists — it is returned once
and never stored, which is why `personal_access_tokens.token` is a sha256 (`§10.1(e)`).

### (e) `laravel/passkeys` — the actions and contracts this task builds against

```
src/Actions/GenerateRegistrationOptions.php   src/Actions/StorePasskey.php
src/Actions/GenerateVerificationOptions.php   src/Actions/VerifyPasskey.php
src/Actions/DeletePasskey.php
src/Contracts/PasskeyUser.php                 src/PasskeyAuthenticatable.php
src/Http/Controllers/PasskeyRegistrationController.php
src/Http/Controllers/PasskeyLoginController.php
src/Http/Controllers/PasskeyConfirmationController.php
src/Passkey.php   src/Passkeys.php   src/Support/WebAuthn.php
```

`PasskeyUser` is the interface `App\Models\User` must implement, and its methods are
`passkeys()`, `hasPasskeysEnabled()`, `getKey()`, `getPasskeyUserHandle()`,
`getPasskeyDisplayName()`, `getPasskeyUsername()` — read off the file, not guessed.

### (f) Dependency posture — `§11.3` item 7

`composer audit` over the new set:

```
Found 1 security vulnerability advisory affecting 1 package:
  phpunit/phpunit  PKSA-qhjm-rpqc-qkn6  >=13.4.0,<13.4.4
```

**That is `R-076`, pre-existing, and none of the packages this task adds carries an
advisory.** `R-076` records that it cannot be fixed: `vendor/pestphp/pest/composer.json`
declares `"conflict": {"phpunit/phpunit": ">13.4.3"}`, v5.3.3 is the newest Pest, and
ADR-001 locks Pest 5 — so every PHPUnit without the advisory is excluded by a locked package.
Owner Task 12. **Not re-attempted here**, because `R-076`'s own cell asks the next person not
to re-run it and conclude it was never tried.

Licences of everything added: **MIT** (`laravel/fortify`, `laravel/sanctum`,
`laravel/passkeys`, `bacon/bacon-qr-code`, `web-auth/webauthn-lib`, `pragmarx/google2fa`),
all first-party Laravel or long-maintained community packages.

**⚠ One maintenance flag, raised rather than absorbed: `laravel/passkeys` is `v0.2.1` —
pre-1.0**, and it is a *required* dependency of Fortify v1.41.0 rather than a choice. That is
`R-026`'s shape (Scramble pinned pre-1.0) in the authentication layer, which is a worse place
for it. `composer.lock` pins it exactly, so it cannot drift without a visible diff. If this
survives review it belongs in `§5` with an owner.

### (g) The app still boots with all three providers registered

```
$ php artisan test tests/Feature/Http/ProblemEnvelopeTest.php
Tests:    28 passed (82 assertions)
```

Run after the install and before any configuration, because three auto-discovered service
providers that read config that does not exist yet is the cheapest possible early failure.

---

# 11. `§8` STOP-AND-REPORT — recorded, not silently resolved

## 11.1 Platform-admin cross-tenant reads — not opened, and not this task's to open

`§4 › Tenancy` names four cross-tenant paths and says adding one requires editing
`PROJECT_INSTRUCTIONS.md` **first**. A platform admin reading tenant data would be a fifth.

**This task does not add it, does not assume it, and does not edit `§4`.** `PLAN.md §3.1` row
6 already records the same thing against Task 6: *"platform-admin cross-tenant reads are
**not** on `§4`'s named list, and Task 6 needs that settled before it builds the dashboard"*.
So the decision already has a home and a trigger, and Task 5's job is to not pre-empt it. What
Task 5 ships instead is a platform admin who is refused by the ordinary 401 in any tenant
where they hold no membership, **with a test asserting it** — which is the behaviour the
invariant requires and also the behaviour that makes the Task 6 question unavoidable rather
than quietly answered.

## 11.2 The board's `§4` is not verbatim

`§4.2` above. `PLAN.md §3` row 5 is the gate this task ships against.

## 11.3 The board still requires a tag; the rank-1 prompt forbids one

The board's `§8` says *"Tag `task-5` created and pushed"*. The rank-1 prompt says no PR, no
merge, no tag, and pre-authorises the deviation in those words. **Rank 1 wins** (`§0`), the
clause is struck through in `§8` above rather than deleted, and the absence of a `task-5` tag
when this work stops is an instruction being followed rather than a gate being missed.
