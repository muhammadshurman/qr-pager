# QRPAGER-13 — Task 6, Filament admin: tenant provisioning (S1)

> Branch `task-6-provisioning`, cut from the `task-5` tag (`5a227f8`). Epic **E4** (`QRPAGER-5`).
> Ticket `QRPAGER-13`. Built by an unattended builder session on 2026-10-28.
>
> **Phase 0 — this file's `§6` answers are committed before any implementation commit**, which
> is what `PROJECT_INSTRUCTIONS.md §6` requires and what the ticket's `§3` repeats.
>
> Everything here is measured in-session. Where a number or a behaviour is quoted, the command
> that produced it is beside it. `§8` forbids the other kind.

---

## 0. STOP-AND-REPORTS — recorded first, because two of them bear on the design

`§0` says *never silently resolve a conflict; stop and report it*. The rank-1 prompt says to
record a `§0`/`§8` stop-and-report **and then continue with everything it does not block**.
That is what is done here. None of the four blocks the build.

### SR-1 — `PROJECT_INSTRUCTIONS.md §4 › Tenancy` does **not** carry the two clauses dated 2026-10-28

Both the rank-1 prompt and `QRPAGER-13 §0` state that `§4 › Tenancy` "now carries two new clauses
dated 2026-10-28". **It does not, on any ref.** Measured:

```
$ for ref in origin/main origin/task-4-notifications origin/task-5-auth-rbac task-5^{}; do
>   echo -n "$ref: "; git show $ref:docs/PROJECT_INSTRUCTIONS.md | grep -c '2026-10-28'; done
origin/main: 0
origin/task-4-notifications: 0
origin/task-5-auth-rbac: 0
task-5^{}: 0
```

**Why it blocks nothing.** The clauses' full text is in the rank-1 prompt and in `QRPAGER-13 §0`,
and the prompt is rank 1 — above the file that is missing them. So the *ruling* is in force and
is not in doubt; what is missing is its record in the rank-2 file.

**Why it is not fixed here.** Writing them into `§4` is a **rank-2 edit**, which the rank-1 prompt
lists under *STOP AND BRING TO ME*. The clause text is in `§9` below, ready to paste, and it is
the first item in the handover.

### SR-2 — the ruling and `PLAN.md §3` row 6 / `QRPAGER-13 §1` + `§4` disagree about this task's scope

The ruling says **"A panel query that reaches a tenant table is a rejected design."** Four scope
and gate items cannot hold at the same time as that sentence, because each names a **tenant**
table (`§2.5`):

| Item | Source | Tenant table it needs | Verdict |
| --- | --- | --- | --- |
| "a **scannable QR** in one wizard pass" | `§3` row 6 · ticket `§4` | `pager_sessions` | unreachable from the panel |
| "**staff** management + invites" (screens) | `§3` row 6 · ticket `§1` | `tenant_users` | unreachable from the panel |
| "**QR batch** generation + printable sheets" | `§3` row 6 · ticket `§1` | `pager_sessions` | unreachable from the panel |
| "platform admin actions all land in **`audit_log`**" | `§3` row 6 · ticket `§4` · `§3.1` row 6 | `audit_log` | unreachable **twice over** — see SR-3 |

`QRPAGER-13` contradicts itself here: its `§0` is the ruling, and its `§1` and `§4` predate it.
Rank 1 wins (`§0` precedence), and the rank-1 prompt's own Phase 1 list (1a–1e) is the narrower,
ruling-compliant scope — so that is what is built. **The four items above are NOT delivered, and
each is named with an owner and a date in `§8` and in the `task-6` tag message**, which is the
form the prompt asks for ("the tag message states every gate clause NOT met, with its row and
owner named"). They are not quietly dropped and `§3` row 6 is **not** reworded to match the code —
amending your own gate to match your own work is Task 3's security S-1 defect.

### SR-3 — a platform-scope `audit_log` row is **unwritable and unreadable** by `qrpager_app`

This is measured, not reasoned, and it is why this task adds a platform audit table.
`audit_log` is a tenant table with a nullable `tenant_id` that `§2.5` documents as "platform
scope", and `tenant_isolation_audit_log` compares `tenant_id = NULLIF(current_setting(...))::uuid`.
`NULL = anything` is `NULL`, never true, so the row satisfies no policy:

```
app   | tenant_id NULL | no context       => REFUSED sqlstate=42501
app   | tenant_id NULL | context set      => REFUSED sqlstate=42501
owner | tenant_id NULL | no context       => REFUSED sqlstate=42501
owner | tenant_id NULL | bypass on        => INSERT SUCCEEDED
```

and on the read side, against a row committed by owner+bypass:

```
app role SELECT of platform-scope row, no context   => rows=0
app role SELECT of platform-scope row, in context   => rows=0
owner+bypass SELECT                                 => rows=1
```

So "platform admin actions all land in `audit_log`" was never reachable by a serving process at
any point in this project's history — only by `qrpager_owner` with `app.bypass_tenant = 'on'`,
which is a migration-time posture and not a request-time one.

**Two documents assert the unmeasured opposite and are corrected in place** (`§0`'s rule: a false
claim is corrected, never carried as a `§5` row):

- `app/Models/User.php:209` — "platform-scoped things — provisioning a tenant, reading `audit_log`
  rows whose `tenant_id` is NULL"
- `app/Domain/Auth/RoleMatrix.php:61` — the same sentence

### SR-4 — the ticket's proof mechanism for the named invariant names an instrument that was removed

`QRPAGER-13 §4.1` says "The Postgres query log is already this repository's proof mechanism".
It is not: `R-004` was closed at Task 3 by **removing** `log_statement=all` from the cluster, and
`PagerPollingTest`'s own header records the replacement — a `DB::listen()` query listener, which
is the **stronger** instrument here because it sees every statement this process issues on any
connection, including the owner and worker connections and statements that fail before they are
sent. The named invariant is proven with a listener. Nothing is lost; the ticket's sentence is
simply stale, and this is the correction rather than a substitution made quietly.

### SR-5 — branch name

Ticket `§6` says branch `task-6-tenant-provisioning`; the rank-1 prompt says `task-6-provisioning`.
Rank 1 wins. The branch is **`task-6-provisioning`**.

---

## 1. Q1 — tables, columns, roles, GUCs. **Which are platform tables and which are tenant tables.**

> The ticket calls this "the whole task". It is answered before the migration and `PLAN.md §2` is
> updated in the same commit as the migration, ahead of any code that reads these tables.

### 1.1 New tables — all three are PLATFORM tables, none carries RLS

| Table | Kind | Why it belongs to no tenant |
| --- | --- | --- |
| `tenant_provisionings` | **platform** | The platform's own record of a restaurant it was asked to create: what was requested, by which platform admin, and how it went. It is the **registry** the panel lists — the ruling's "can see that a restaurant exists" — and it holds no tenant content. |
| `tenant_statistics` | **platform** | One row of **aggregates** per tenant, written by a job from inside that tenant. Counters and two percentiles. No row-level detail. |
| `platform_audit_log` | **platform** | What a platform admin did to a platform record. Append-only by trigger, like `audit_log`. Exists because SR-3 proves `audit_log` cannot serve this. |

**No new tenant tables.** Nothing in this task's scope needs one: the tenant-side rows it causes
to exist (`tenants`, `tenant_users`) already exist in the contract and are written by the
provisioning job inside the new tenant's own context, not by the panel.

### 1.2 The one thing a reviewer should push on: **none of the three has a column named `tenant_id`**

That is deliberate, it is the single highest-risk decision in this task, and it is stated here
rather than left to be discovered.

**The mechanism it interacts with.** C4's meta-test classifies a table as tenant-scoped by exactly
two rules, read from `pg_catalog` (`RlsCoverageTest`, and `TenantTables`' own docblock):

```sql
where c.relname = 'tenants' or exists (select 1 from pg_attribute a
      where a.attrelid = c.oid and a.attname = 'tenant_id' ...)
```

So a platform table carrying a column named `tenant_id` would be classified tenant-scoped, and
`it('has every tenant-scoped table under ENABLE and FORCE')` plus
`it('keeps the global allowlist honest: nothing on it carries a tenant_id')` would **both fail**
unless it were given RLS — which the ruling forbids.

**Why the column name is not a dodge.** Each of these tables is **one row per tenant**, so the
tenant's id is the row's *identity*, not a scope column over many rows. `tenant_provisionings.id`
**is** the id the tenant will carry; `tenant_statistics.id` **is** the id of the tenant it
describes. That is ordinary 1:1 modelling, and it is the exact mirror of the property
`TenantTables` already documents for `tenants` itself — *"`tenants` is here although it has no
tenant_id column: its own id IS the tenant scope"*. The difference, and the whole of the ruling,
is **which side owns the row**: `tenants.id` scopes tenant data, and these three ids reference a
tenant from a platform record that contains none.

**What was rejected.** (a) Naming the column `tenant_id` and giving the tables RLS — then the
platform admin, who holds no tenant context, reads nothing, which is the state Task 5 shipped and
this task exists to fix without widening. (b) Naming it `tenant_id` and loosening the meta-test's
classifier to a hand-maintained list — that weakens the one mechanical control that stops a *new*
tenant table escaping isolation, in order to describe three tables correctly. Trading a strong
general guard for a local label is the wrong direction.

**What is added so the guard is not merely sidestepped.** Three new mechanical checks, because a
design that steps around a test owes the test a replacement:

1. `it('pins the global allowlist as an exact set')` grows from six names to nine, each with its
   argument in the diff — that test exists precisely so this cannot happen quietly.
2. **New:** every platform table's **column set is pinned exactly** against `information_schema`,
   so neither a `tenant_id` column nor a row-level-detail column can be added to one without the
   diff showing it.
3. **New:** `tenant_statistics` is asserted to carry **no** column from a forbidden list —
   `order_reference`, `token`, `token_hash`, `pager_session_id`, `session_id`, `endpoint`,
   `p256dh`, `auth`, `email` — which is the ruling's "an aggregate carries no row-level detail"
   as a property rather than as a promise.

And the scope guard's statement matcher was checked against all three names before they were
chosen, including the one that embeds another table's name:

```
tenant_provisionings  select * from tenant_provisionings  => NULL
tenant_statistics     select * from tenant_statistics     => NULL
platform_audit_log    select * from platform_audit_log    => NULL
tenants               select * from tenants               => 'tenants'
audit_log             select * from audit_log             => 'audit_log'
```

`platform_audit_log` does not false-match `audit_log` because the matcher's negative lookbehind
rejects a preceding identifier character, and `_` is one. That is pinned by a test too, since it
is a property of a regex and not of a schema.

### 1.3 Columns

**`tenant_provisionings`** — platform, no RLS.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | uuid PK | **is the tenant id this row provisions**, allocated by the panel before the tenant exists |
| `name` | varchar(120) NOT NULL | |
| `slug` | varchar(63) NOT NULL **UNIQUE** | same DNS-label CHECK as `tenants.slug`, so a duplicate is refused on the platform side before the job runs |
| `owner_email` | varchar(255) NOT NULL | stored lower-cased, as `users.email` is |
| `status` | `provisioning_status` NOT NULL DEFAULT `pending` | `pending` → `running` → `completed` \| `failed` |
| `attempts` | smallint NOT NULL DEFAULT 0 CHECK ≥ 0 | |
| `failure_reason` | text NULL | a mapped, redacted reason — never a driver message, `§4 › Edge & ops` |
| `requested_by_user_id` | uuid NULL → `users.id` ON DELETE SET NULL | |
| `completed_at` | timestamptz NULL | |
| `created_at`, `updated_at` | timestamptz NOT NULL | |
| | | INDEX `(status, created_at DESC)` |

No FK from `id` to `tenants.id`: **the row is written before the tenant exists**, which is the
point of it. An FK would invert the order and make the failure path — a provisioning record for a
tenant that was never created — unrepresentable, and that record is the only evidence a failed
attempt leaves.

**`tenant_statistics`** — platform, no RLS. Aggregates only.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | uuid PK | **is the tenant id** these aggregates describe |
| `sessions_total` | integer NOT NULL DEFAULT 0 CHECK ≥ 0 | |
| `sessions_waiting` · `sessions_ready` · `sessions_completed` · `sessions_cancelled` · `sessions_expired` | integer NOT NULL DEFAULT 0 CHECK ≥ 0 | one per `pager_status` value. ⚠ **Written out LITERALLY in the migration, not generated from the enum** — corrected 2026-10-28: a migration that reads today's enum is not a snapshot, which is the tenant-tables migration's own stated rule, and the first wording of this cell contradicted it. The CONTRACT is enforced from the other side instead: `PlatformTableContractTest` asserts one counter column per `PagerStatus` case, and the aggregate command derives its `count(*) filter (...)` list from the enum — so a sixth status fails the build rather than leaving a counter at zero. |
| `staff_count` | integer NOT NULL DEFAULT 0 CHECK ≥ 0 | |
| `median_wait_seconds` · `p95_wait_seconds` | integer NULL CHECK ≥ 0 | `ready_at - created_at`, whole seconds |
| `window_start` · `window_end` | timestamptz NULL | the window the aggregates cover |
| `computed_at` | timestamptz NOT NULL | |
| `created_at`, `updated_at` | timestamptz NOT NULL | |

**`platform_audit_log`** — platform, no RLS, append-only.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint `GENERATED ALWAYS AS IDENTITY` PK | the only non-uuid id in the schema besides `audit_log.id` |
| `actor_user_id` | uuid NULL | **no FK**, for `audit_log`'s own reason: `ON DELETE SET NULL` is an `UPDATE`, which the append-only trigger blocks, so deleting a user would become impossible. The log keeps ids, not references. |
| `action` | varchar(64) NOT NULL | |
| `subject_type` | varchar(64) NULL | |
| `subject_id` | uuid NULL | a tenant id for provisioning actions |
| `context` | jsonb NOT NULL DEFAULT `{}` | |
| `ip_address` | inet NULL | |
| `created_at` | timestamptz NOT NULL DEFAULT `now()` | INDEX `(created_at DESC)`, INDEX `(action, created_at DESC)` |

Immutability is the same construction as `§2.9`: a `BEFORE UPDATE OR DELETE` statement-level
trigger and a `BEFORE TRUNCATE` statement-level trigger, both `ENABLE ALWAYS`, both raising
`42501`. `ENABLE ALWAYS` is what makes it hold for the superuser too.

### 1.4 What is NOT added, and why

**No subscription table.** The ruling lists "subscription records" as an example of the platform
category, and `QRPAGER-13 §2` says billing/subscriptions is **out of scope, owned by nobody until
the client states it** (`PLAN.md §6`). The more specific and more recent statement wins, and
inventing plan names, seat semantics and a billing period that nobody has specified would be
speculative code — `§8` forbids it. The category is real; the table waits for a requirement.

### 1.5 Roles, grants, GUCs

**No new GUC.** C1's two — `app.tenant_id`, `app.bypass_tenant` — are untouched, and
`it('reads no app.* setting other than the two locked by C1')` keeps that honest.

**No new cross-tenant path, so no rank-2 edit to `§4 › Tenancy`'s named list.** This is worth
stating plainly because it is the thing `§0`'s *Blocking anything?* row said Task 6 would need:
the aggregate job reads each tenant **inside that tenant's own context**, one tenant at a time,
which is an ordinary scoped read and not a cross-tenant one. It enumerates tenants from
`tenant_provisionings` — a platform table — so it never needs an unscoped read of `tenants`.

| Table | `qrpager_app` | `qrpager_worker` |
| --- | --- | --- |
| `tenant_provisionings` | SELECT · INSERT `(id, name, slug, owner_email, status, requested_by_user_id, created_at, updated_at)` · UPDATE `(status, attempts, failure_reason, completed_at, updated_at)` | SELECT `(id, slug, status)` — enough to enumerate completed tenants, and no more |
| `tenant_statistics` | **SELECT only** — the panel reads, never writes | SELECT · INSERT · UPDATE |
| `platform_audit_log` | SELECT · INSERT `(actor_user_id, action, subject_type, subject_id, context, ip_address)` | INSERT `(actor_user_id, action, subject_type, subject_id, context, ip_address)` |

No role is granted UPDATE or DELETE on `platform_audit_log`: the trigger makes it impossible and
the absent grant makes it unreachable, which is `§2.9`'s belt and braces.

---

## 2. Q2 — shared contracts

One new Postgres enum type, mirrored by one PHP enum, generated from the single source the repo
already uses for `pager_status`, `outbox_status`, `tenant_role`, `tenant_status` and
`audit_actor_type` (`§2.3`):

- **`provisioning_status`** — `pending`, `running`, `completed`, `failed`
  → `App\Domain\Provisioning\ProvisioningStatus`, implementing `DatabaseEnum` like its five siblings,
  so the existing enum meta-test compares the PHP cases against `pg_enum` and fails on drift.

`tenant_statistics`' per-status counter columns are **generated from `PagerStatus`** in the
migration rather than typed out, so a sixth pager status cannot leave a counter missing.

No TypeScript mirror: `packages/contracts` is created at Task 8 (ADR-003) and nothing in this task
is consumed by a frontend.

---

## 3. Q3 — the tenant-context path, in both directions

> The ticket asks for three things here: how the wizard writes before the tenant's context exists,
> why that is not a general bypass, and that the panel's read path never leaves platform tables.

### 3.1 The panel's own request — platform tables only, no tenant context, no bypass

The Filament action validates input and then, in **one transaction on the `pgsql` (app)
connection**, writes two rows: a `tenant_provisionings` row at `pending`, and a
`platform_audit_log` row. Both are platform tables, so the statements need no tenant context and
the scope guard does not refuse them. Then it dispatches `ProvisionTenant`.

**The panel issues no statement against any tenant table.** That is the named invariant, and it is
proven by a listener over every panel resource rather than by this paragraph.

### 3.2 The provisioning job — inside the new tenant's own context, and why that is not a bypass

`ProvisionTenant` runs out of band, on the **app** connection, and does its tenant-side work
inside `withTenant($tenantId, ...)` — context set to **the id it is about to insert**. That is the
path `tests/TestCase.php::makeTenant()` already documents: *"context is set to the id about to be
inserted, so even this needs no bypass"*. `tenants`' isolation policy is `id = ctx`, so the
`INSERT ... (id) VALUES (ctx)` satisfies `WITH CHECK` exactly, and `tenant_users`' is
`tenant_id = ctx`.

**Why this is not a general bypass**, which is the half of Q3 the ticket asks for by name:

- `app.bypass_tenant` is never set. The only bypass policies in the schema are
  `owner_bypass_*`, targeted at `qrpager_owner` alone, and
  `it('targets every bypass policy at the owner alone')` pins that.
- The context is **one** tenant id, transaction-scoped (`set_config(..., true)`, C2), and it is the
  id of the tenant being created — so the job's reach is exactly one tenant, the new one. It can
  read and write **no other tenant's rows at any point**, which is the property a bypass would
  destroy and which a test drives directly.
- The job needs no grant that `qrpager_app` did not already hold: `§2.7` already gives the app role
  `INSERT (id, name, slug, status, timezone, created_at, updated_at)` on `tenants` and
  `SELECT, INSERT, DELETE` on `tenant_users`. **No grant is widened by this task** on any tenant
  table, and `GrantMatrixTest` pins the whole matrix as an exact set.

The role is `app` rather than `worker` because the app role is the only one the contract gives
INSERT on `tenants` and `tenant_users` (`§2.7`); granting the worker those would widen a role whose
whole value is that it cannot do them. It is `app` rather than `owner` because `§4 › Tenancy` makes
owner migrations-only.

### 3.3 The aggregate write path — a job, inside the tenant

`AggregateTenantStatistics` runs on the **worker** connection and, per tenant:

1. reads the tenant list from `tenant_provisionings` where `status = 'completed'` — a **platform**
   table, unscoped, no cross-tenant read of `tenants` needed;
2. for each id, `withTenant($id, ...)` and runs the aggregate `SELECT`s over `pager_sessions` and
   `tenant_users` **inside that tenant's own scope** — an ordinary scoped read;
3. writes one `tenant_statistics` row by `INSERT ... ON CONFLICT (id) DO UPDATE`, outside the
   tenant context, on a platform table.

The worker already holds `SELECT` on exactly the `pager_sessions` columns this needs —
`status`, `created_at`, `ready_at` are all in its column grant (`§2.7`) — and `SELECT` on
`tenant_users`. **No grant is widened on a tenant table.**

**Idempotent**, which the prompt requires: the upsert is keyed on `id`, so running it twice leaves
one row with the same values; a `pg_advisory_xact_lock` on the tenant id serializes two concurrent
runners, which is the same mechanism `§4 › Postgres traps` prescribes for the role bootstrap race.

### 3.4 The panel's read path

The dashboard and both resources read `tenant_provisionings`, `tenant_statistics` and
`platform_audit_log`. **Nothing else.** Eloquent models for the three carry no relation to any
tenant-table model, so there is no `with()` or lazy load that could reach one — a relation would
be the realistic way this invariant would break later, so the absence is pinned by a test that
reflects over the models' methods.

---

## 4. Q4 — security

**Who may run the wizard.** `is_platform_admin` is required by the panel's own authorisation,
and the Filament panel is mounted behind the platform guard. A tenant staff member, manager or
owner — anyone with `is_platform_admin = false` — cannot reach the panel at all, and a platform
admin still holds **no tenant ability**, which Task 5's `PlatformAdminIsNotABypassTest` already
proves through HTTP and which this task does not touch.

**Route default is deny** (`§4`). The panel's routes are registered by Filament; the
`RouteSurfaceTest` that rejected Fortify's 27 unversioned endpoints at Task 5 is extended to
account for the panel's paths explicitly rather than let them arrive unnoticed.

**Input validation, every field.** `name` ≤ 120; `slug` ≤ 63 and matched against the same
DNS-label expression as the database CHECK, so the application and the schema agree rather than
one trusting the other; `owner_email` a valid address ≤ 255, lower-cased before it is stored or
compared. The duplicate paths are both real and both tested: a duplicate `slug` is refused by
`tenant_provisionings_slug_unique` (23505) and mapped to a field error, not a 500.

**No file upload.** Branding assets are in the ticket's `§1` and in `§4.3`'s wizard as the
*branding* step; under the ruling this task ships no branding step, so the upload surface the
ticket's `§5` warns about is **not opened here**. It moves with the rest of the wizard — `§8`.

**What lands in the audit log.** Every platform-admin action writes a `platform_audit_log` row in
the **same transaction** as the change it records: the provisioning request, and the job's
completion or failure. Not `audit_log`, for SR-3's measured reason.

**Invite tokens are credentials.** No invite is issued here — invites move with staff management
(`§8`) — so no credential is minted by this task and the posture has nothing to bind yet. Stated
rather than silently skipped, because the ticket raises it.

**No secret, token, SQL or bound parameter in any log or error body, at any `APP_DEBUG`.**
`failure_reason` carries a mapped reason from `DatabaseErrorMapper`'s SQLSTATE vocabulary, never a
driver message — a driver message is where the SQL and the bound parameters live, and that is the
exact shape `R-007`/`R-021` were about. Asserted against captured output and captured response
bodies, both settings, which is what `§4` requires.

---

## 5. Q5 — scale, and what is bounded

**The QR batch's cost is not incurred here** — no batch is generated (SR-2). The ticket's question
"how many codes can one request generate?" has no answer in this task's code because this task's
code generates none; it is carried to the owner named in `§8`.

What this task does add, with its bound:

| Path | Cost | Bounded by |
| --- | --- | --- |
| Create a restaurant (panel request) | 2 `INSERT`s, one transaction, no tenant table | one row per request; the unique `slug` makes a replay a 23505 rather than a second tenant |
| `ProvisionTenant` (per job) | 1 `SELECT` + up to 3 `INSERT`s in one transaction | one tenant; retries bounded by `attempts` |
| `AggregateTenantStatistics` | **O(tenants)** transactions, 2 aggregate `SELECT`s each | the number of provisioned tenants. **This is the one unbounded-in-principle cost added**, and it is bounded by an explicit per-run cap. ⚠ **This cell said "a chunked iteration and an explicit per-run cap" and there was no chunked iteration** — corrected 2026-10-28 from QA M-4, which found the worse half of that: `order by id limit <n>` with no offset or cursor recomputed the same first N every five minutes, so above the cap every remaining tenant NEVER got a `tenant_statistics` row and the dashboard read "No data yet" for it indefinitely. The ordering is now `computed_at asc nulls first`, which makes the cap a **rate limit rather than a cliff**: a tenant never aggregated goes first, then the stalest. Still not chunked, and now it does not need to be. At 300 K sessions (C6) the aggregates are index-only over `(tenant_id, status, created_at DESC)`, which already exists. |
| Dashboard / list / detail (panel read) | 1 `SELECT` each, platform tables | pagination; no N+1, since there is no relation to load |

---

## 6. Q6 — failure: **the wizard fails at step 4 of 5. What is in the database afterwards?**

The ticket says the answer must be "nothing". It is **"nothing of the tenant, and one platform row
that says the attempt failed"** — which is the honest answer and a better one, because an attempt
that leaves no trace at all is an attempt nobody can see failed.

| Failure | What exists afterwards |
| --- | --- |
| The panel's transaction fails | Nothing. Both rows are in one transaction. |
| `ProvisionTenant` fails **anywhere** — duplicate slug, duplicate email, a crash between the `tenants` insert and the `tenant_users` insert | **No `tenants` row and no `tenant_users` row.** The job's tenant-side work is one transaction inside one `withTenant` scope, so a half-created tenant is not representable. The platform row reads `failed` with a mapped reason. |
| The job is killed mid-transaction | Postgres rolls the tenant-side work back, so there is still no partial tenant. ⚠ **The rest of this cell was FALSE as written** — corrected 2026-10-28 from QA H-2. The row stayed `running` and **nothing ever picked it up**: the drain selects `pending` only, `running -> running` is forbidden by the transition table, and `attempts` was incremented, selected and read by **nothing**, so "`attempts` bounds that" had no implementation at all — `R-058`'s shape (a column selected and never read) in new code. A row left at `running` was shown as "In progress" for ever and recoverable only by hand SQL, which `§4`'s own "no SQL run by hand" forbids. **Both halves now exist:** a real `TenantProvisioning::MAX_ATTEMPTS` cap that `provision()` reads, and `platform:provision-tenants --retry-stalled <id>` — a per-row operator act that resets the counter deliberately, because a cap a loop resets is not a cap. |
| The job runs twice | One tenant. The `tenants` insert is keyed on the id already allocated, so the second attempt raises 23505 and is mapped to "already provisioned" rather than a failure. |
| Duplicate request, same slug | 23505 on `tenant_provisionings_slug_unique`, refused at the panel, no job dispatched. |
| Rollback of this task's migrations | Clean, proven twice-over by `bin/migration-rollback-proof`. |

Each of these is a test, and **the half-created case is revert-proved** — the proof that it is the
assertion failing and not the harness is `bin/_revert-proof.sh`, which every revert-proof script in
this repo sources (`R-043`).

---

## 7. Q7 — Filament 5 on Laravel 13, verified by running code

**Versions in this session, not from memory:**

```
$ php -v | head -1
PHP 8.4.27 (cli) (built: Oct 21 2026 14:35:21) (NTS)

$ php artisan --version
Laravel Framework 13.38.0
```

**Filament 5 exists and its latest release is `v5.11.0`:**

```
$ composer show --available filament/filament | head -4
name     : filament/filament
descrip. : A collection of full-stack components for accelerated Laravel app development.
versions : 5.x-dev, v5.11.0, v5.10.3, v5.10.2, v5.10.1, v5.10.0, v5.9.0, ...
```

**It supports Laravel 13.** The root package requires only `php` and its own sub-packages at
`self.version`; the framework constraint lives in `filament/support`, read from the registry
rather than assumed:

```
$ # packagist p2 is minified -- absent keys inherit from the previous entry
$ ... filament/support v5.11.0 require:
  illuminate/contracts => ^11.28|^12.0|^13.0
  livewire/livewire    => ^4.4.8
  php                  => ^8.2
  symfony/console      => ^7.0|^8.0
```

`^13.0` covers the installed `13.38.0`. The resolved install and the wizard/transaction behaviour
the ticket asks about are recorded in `§7.1` of the handover once the package is in, with the
`composer require` output and the version actually locked.

**One thing found while checking the versions, and it is why `php artisan --version` is quoted
above rather than taken for granted.** On a fresh checkout of this branch every artisan command
died with `A facade root has not been set` from `ReportRedactedException`, because `vendor/` was
still the **Task 4** tree — `git checkout` does not touch a gitignored directory, and Task 5 added
`laravel/fortify`, `laravel/sanctum` and `laravel/passkeys`:

```
$ for p in laravel/fortify laravel/sanctum laravel/passkeys; do [ -d "vendor/$p" ] && echo "OK $p" || echo "MISS $p"; done
MISS laravel/fortify
MISS laravel/sanctum
MISS laravel/passkeys
```

`composer install` fixed it. **The defect worth recording is not the stale vendor — it is that the
original exception was destroyed.** The custom reportable used a facade before the container was
bound, so the only thing printed was the reporter's own crash, and the real cause was invisible.
That is a `§5` row, `R-114`, with the mechanism in its own cell: a reportable that can throw must
not be the only path to the message it is reporting.

---

## 8. What this task does NOT deliver — each with its owner and date

Written here, in the handover, in `PLAN.md §3.1` row 6 and in the `task-6` tag message.

⚠ **And the REASON column was too broad in its first version** (QA M-7). "Needs a tenant table; the ruling rejects a panel query that reaches one" does **not** establish unreachability for this task: this task's own architecture writes two tenant tables **out of band**, from a scheduled command, inside the tenant's own context. So a QR batch or a membership row is reachable by exactly that pattern. What the ruling forbids is doing it **on the panel's request** — which is a narrower and more useful statement, and the one the rows now carry. For the invite clause the ruling is no obstacle whatsoever.

| Not delivered | Gate row it belongs to | Why | Owner · date |
| --- | --- | --- | --- |
| "a **scannable QR** in one wizard pass" | `§3` row 6 · ticket `§4` | needs `pager_sessions`, a tenant table; the ruling rejects a panel query that reaches one | **R-115** — owner **Task 10** (staff dashboard, the first surface a tenant's own staff authenticate into) · accepted 2026-10-28 |
| **staff management** screens | `§3` row 6 · ticket `§1` | needs `tenant_users`; same reason. The `/v1/members` API from Task 5 already serves the capability | **R-115** — same row |
| "a staff **invite** is actually delivered, asserted against the local mail catcher" | `§3` row 6 · ticket `§4` | ⚠ **NOT for the reason above, and conflating them was itself a finding** (QA M-7). Delivering mail to Mailpit touches **no tenant table**, so the ruling is no obstacle at all. The honest reason: no invite is minted by this task, so there is no credential to deliver | **R-124**, owner **Task 10**, with `R-119` · accepted 2026-10-28 |
| **QR batch** generation + printable sheets | `§3` row 6 · ticket `§1` | needs `pager_sessions`; same reason. The reprint constraint the ticket's `§5` raises is unanswered because nothing is printed | **R-115** — same row |
| "platform admin actions land in **`audit_log`**" | `§3` row 6 · `§3.1` row 6 · ticket `§4` | **measured impossible** for a serving process, SR-3. Delivered against `platform_audit_log` instead | **corrected in place**, not a `§5` row — `§0`'s rule for a false claim |
| the **branding** step and its upload surface | `§4.3` wizard | part of the wizard the ruling removes from the panel; Task 7 owns tenant-facing content and Task 8 owns tokens | **R-116** — owner **Task 7** · accepted 2026-10-28 |
| **R-106**'s fix | ticket `§1` | its own `§5` cell prescribes a **rank-2 edit to `§4 › Security`**, which the rank-1 prompt reserves for Muhammad; and its trigger is "before the staff-management screens ship", which this task does not ship | **moves** — see `§9` |

---

## 9. R-106, and the two rank-2 edits that are Muhammad's

### 9.1 R-106 moves, with a reason — ⚠ and one of the two reasons was WITHDRAWN

**Corrected 2026-10-28, from this task's own `§11` security review (M-3), which was right.**
This section gave two reasons; the second does not hold:

> ~~Its trigger has not fired. The trigger is "At Task 6's acceptance gate, **before the
> staff-management screens ship**." Under the ruling this task ships no staff-management
> screen, so the condition the row waits on is still ahead.~~

This task **did** ship a live writer for `R-106`'s **consent** half:
`TenantProvisioning::resolveOwner()` made any existing account the owner of a new tenant with
no consent, which re-opens the login-strand denial of service `R-106` itself records — through
the provisioning form rather than through a staff screen. So the trigger had partly fired.
That half is now **refused** (security M-2) and the residual policy question is `R-119`.

**The move stands on the first reason alone**, which is sufficient on its own.


The rank-1 prompt says *"Close it here or state why it moves, with a new owner and date."* It moves,
for two independent reasons, either of which is sufficient:

1. **Its prescribed fix is a rank-2 edit.** R-106's *Review by* cell says: "Decide the seniority
   rule … and the consent rule …, **write both into `§4 › Security` as a rank-2 edit**, and drive a
   matrix case per (actor role × target role)." The rank-1 prompt lists "anything needing a rank-2
   edit to `PROJECT_INSTRUCTIONS.md`" under *STOP AND BRING TO ME*. A builder session cannot take it.
2. **Its trigger has not fired.** The trigger is "At Task 6's acceptance gate, **before the
   staff-management screens ship**." Under the ruling this task ships no staff-management screen,
   so the condition the row waits on is still ahead.

**New owner: Task 10** (`QRPAGER-17`), the first task that ships a surface where a tenant's own
owner or manager administers staff — and the task R-106's own cell already names for the
two-membership login edge ("which is Task 10"). **New date: accepted 2026-10-28, re-owned
2026-10-28.** Its `§5` row is updated in place with both, and the recommended rule text is below so
the decision is one paste rather than one design session.

### 9.2 Ready to paste — the two `§4 › Tenancy` clauses (SR-1)

```markdown
- **Platform tables are not tenant tables.** The platform admin panel operates only on tables
  belonging to no tenant — the tenant registry, provisioning and subscription records — which
  carry no RLS because they carry no tenant data. A platform admin holds no tenant ability and no
  cross-tenant read: they can see that a restaurant exists and administer it from outside, and
  cannot see inside it. Support access to a tenant's own data, if ever needed, is a temporary
  audited membership. Added 2026-10-28.
- **Per-tenant statistics are aggregates written to a platform table, never a read of a tenant
  table.** A counter or a timestamp is maintained by a job or the outbox worker — which already
  holds a named cross-tenant path — and the panel reads only that platform row. An aggregate
  carries no row-level detail: no order reference, no token, no session identifier. A panel query
  that reaches a tenant table is a rejected design. Added 2026-10-28.
```

### 9.3 Ready to paste — the `§4 › Security` clause R-106 asks for

```markdown
- **Role seniority, and consent.** `owner` is senior to `manager`, which is senior to `staff`. No
  actor may grant a role senior to its own, assume one, or change its own role at all — a manager
  promoting itself to owner is refused, not audited after the fact. Attaching an **existing** user
  to a tenant requires that user's acceptance of an invite; a membership is never created for a
  user by someone else's request alone. Added 2026-10-__ (R-106).
```

---

## 10. The named invariant, and how it is proven

> **No panel query reaches a tenant table, and a platform admin with no membership can read no
> tenant row by any route the panel exposes.**

Already in `QRPAGER-13 §4.1` before this session started; added to `PLAN.md §3.1` row 6 in this
same Phase 0 commit, which is where it was missing.

**Proven mechanically, in two halves, neither by reading:**

1. **Every panel resource is driven** — dashboard, list, detail, the create action, and the
   platform audit viewer — with a `DB::listen()` listener capturing every statement the request
   issues on **any** connection. Each captured statement is classified by
   `TenantTables::referencedBy()`, the registry the runtime guard itself uses, and the assertion is
   that the set of tenant tables touched is **empty**, with the offending SQL in the failure message
   rather than a bare count. A route table drives it, so **a panel route with no case fails the
   suite** — the shape Task 5's `§3.1` row 5 settled on, because a matrix that does not iterate its
   own subject is unfalsifiable by anyone but its author.
2. **A platform admin with no membership is driven against every tenant-row route the panel
   exposes** and reads nothing — which it cannot, since the panel exposes none. The assertion that
   makes this non-vacuous is (1)'s route table: the two together say *the panel exposes no such
   route, and here is the enumeration behind that claim*. `bin/vacuous-assertion-proof` is the
   mechanism this repo already uses to show an assertion is not green for the wrong reason, and it
   is run against this one.

The invariant is **revert-proved**: the listener assertion is shown red by a deliberate panel query
against `tenants`, which is the only way to know the test would notice.
