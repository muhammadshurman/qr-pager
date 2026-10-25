# PLAN.md — QR Pager SaaS

> Living decision log + the **only** task table. Precedence and invariants live in `PROJECT_INSTRUCTIONS.md`; this file never duplicates them.

**Version:** 2.9 · **Backend:** Laravel 13 (O1) · **Realtime:** Web Push + polling (O2)

---

## 0. WHERE WE ARE RIGHT NOW

> **Read this section first in every new chat. It is the only place that states current state. Update it at the end of every task.**

| | |
|---|---|
| **Last completed** | **Task 1 is not tagged.** Branch `task-1-db-foundation`, ticket `QRPAGER-1`, PR #1, head `7bcfdb3`, both CI checks green, **170 passed / 480 assertions**, coverage **98.7 %**. Five rounds have run. The fifth stopped patching and changed the state model: **`app.tenant_id` is now the only source of truth** and the PHP mirror is gone, which is what H-A was. All eight fourth-review findings are closed, each proven by revert where there is code to revert. See §4a. |
| **Next up** | **Task 2** — API core. Ticket `QRPAGER-9`. Does not start until `task-1` is tagged (§3 order is strict). |
| **Blocking anything?** | Task 2 is blocked on the `task-1` tag, which is blocked on **the fifth review** of the fifth round's diff. Nothing else is outstanding on this branch. The standing reason for that review: each of the last two rounds produced a High inside the previous round's fix, so a fix diff nobody has read is the one thing this branch has reliably got wrong. |
| **Open debt that must die in Task 2** | (1) `assertSafeRuntimeRole()` is tested but **nothing calls it yet**, and it must also refuse a runtime task that can resolve owner or root credentials — §2.1 previously claimed it already did (F6). (2) **R-005**, a bypass that throws leaves no audit trace. (3) **R-007**, error mapping: `23514` must not return a constraint name or any SQL. (4) **R-009**, the role verifier reaches the Postgres log 6× per bootstrap. (5) **R-010**, the §4 repo guard for C1/C2 **does not exist** although §4 states it does — the wording is a rank-2 edit and should be corrected in the same change. (6) **R-013** and **R-014**, bootstrap diagnostics and the timeout proofs' missing upper bound. |
| **Needs the user's decision** | Nothing open. The fifth-round question — patch again or redesign — was answered by doing the structural part: one source of truth for tenant scope, with the PHP mirror removed rather than guarded. R-003's owner was decided on 2026-10-25: **Task 3** (`QRPAGER-10`), because `§3` row 3 states the expiry job in Task 3's scope and its gate clause verbatim, so the plan won over the disposition (rank 3, `§0`). |

### Where everything lives

| What | Where |
|---|---|
| Rules, invariants, precedence | `docs/PROJECT_INSTRUCTIONS.md` — also pasted into the claude.ai Project instructions |
| This file — decisions, data contract, tasks, risks | `docs/PLAN.md` in the repo is the **source**; the copy in Project knowledge is a mirror, refreshed after each `task-N` |
| Accepted stack deviations | `docs/adr/` |
| Surviving source material | `docs/BLUEPRINT.md` — an **archive**, not the original. Read its header before using it. |
| Agent entry point | `CLAUDE.md` at repo root |
| Reviewer definitions | `.claude/agents/qa-reviewer.md`, `.claude/agents/security-reviewer.md` |
| Task intake template | `.claude/commands/task.md` |
| Repo | `github.com/muhammadshurman/qr-pager` (private, SSH) |
| Board | `muhdshurman.atlassian.net` → project `QRPAGER` |

### Jira ↔ task map

Board columns: `Backlog → Selected for development → In Progress → QA Review → Security Review → Done`. The last two exist because `PROJECT_INSTRUCTIONS.md §11` requires both reviews; a task that skips them is not done.

| Task | Ticket | Epic | Task | Ticket | Epic |
|---|---|---|---|---|---|
| 0 ✅ | `QRPAGER-8` | E1 | 8 | `QRPAGER-15` | E5 |
| 1 | `QRPAGER-1` | E1 | 9 | `QRPAGER-16` | E5 |
| 2 | `QRPAGER-9` | E1 | 10 | `QRPAGER-17` | E5 |
| 3 | `QRPAGER-10` | E2 | 11 | `QRPAGER-18` | E5 |
| 4 | `QRPAGER-11` | E2 | 12 | `QRPAGER-19` | E6 |
| 5 | `QRPAGER-12` | E3 | 13 | `QRPAGER-20` | E6 |
| 6 | `QRPAGER-13` | E4 | 14 | `QRPAGER-21` | E6 |
| 7 | `QRPAGER-14` | E4 | | | |

Epics: E1 `QRPAGER-2` foundation · E2 `QRPAGER-3` domain + notifications · E3 `QRPAGER-4` auth · E4 `QRPAGER-5` admin (S1/S2) · E5 `QRPAGER-6` design + frontend · E6 `QRPAGER-7` hardening + launch.

Each ticket carries its own acceptance gate **copied verbatim from §3 below**, the §6 pre-coding questions as a checklist, an explicit *out of scope* table naming which task owns each excluded item, and the known traps for that task. Tickets are linked `blocks` in order.

### How a task runs

1. Open the ticket. Read `PROJECT_INSTRUCTIONS.md`, this file, `docs/adr/`.
2. Answer the seven §6 pre-coding questions **as a comment on the ticket**. Then move to In Progress.
3. Branch `task-N-<slug>`. Build. Three gates green against real Postgres + Redis.
4. PR. Both required checks green.
5. **QA Review** — separate agent session, diff + ticket only.
6. **Security Review** — separate agent session, diff + ticket only.
7. Every Critical and High fixed with a regression test. Medium / Low → §5 with owner and date.
8. Squash merge. Tag `task-N`. Push the tag.
9. **Update §0 of this file, and §4a with the gate evidence.**

---

## 1. Decision log

| Date | Decision | Why |
|---|---|---|
| 2026-10-25 | Laravel 13 owns the entire backend. NestJS / Drizzle / tRPC / Better Auth removed. | Client decision (O1). Single language, single team. |
| 2026-10-25 | No persistent connection at launch. Web Push primary, short polling for live status. | Customer waits ~15 min with a locked screen; Push delivers with the page closed. A Reverb cluster for 300K would be ~25 nodes before the first paying tenant. |
| 2026-10-25 | Reverb is an optional later enhancement, never a dependency. | Laravel Echo on the client makes the swap a config change. |
| 2026-10-25 | Prior Task 1 / Task 2 artifacts are **lost** — no repo, empty Project knowledge. | Schema and SQL are re-derived from the Blueprint at Task 1 and re-tested from scratch. |
| 2026-10-25 | Every task passes Builder → QA reviewer → Security reviewer. | `PROJECT_INSTRUCTIONS.md §11`. |
| 2026-10-25 | **ADR-001:** Pest 5.3.3 + PHPUnit 13.4.3 replace the locked "Pest 4". | Laravel 13 skeleton locks PHPUnit 12.5.40; Pest 4.7.x conflicts with PHPUnit >12.5.33. Verified in-session. `docs/adr/001-pest-5.md`. |
| 2026-10-25 | Local toolchain: Windows + WSL2 (Ubuntu 24.04), PHP 8.4.27 NTS, Composer 2.10.3, Docker Desktop 29.6.1 via WSL integration. | Production is Linux on ECS Fargate; FrankenPHP and SIGTERM draining (Task 2 gate) do not exist on native Windows. |
| 2026-10-25 | `laravel/pao` v1.1.5 kept (ships with the Laravel 13 skeleton). | First-party, MIT, strips ANSI/noise from test and Artisan output when an AI agent is detected — supports the §11 agent workflow. Logged for the Task 0 security review (§11.7). |
| 2026-10-25 | **ADR-002:** S3 + MinIO for object storage, SES + Mailpit for transactional mail. | Task 7's media library and Task 6's staff invites had no component in §1 and were blocked. Both were in the Blueprint's own local stack (§8) and were lost when §1 was written. `docs/adr/002-storage-and-mail.md`. |
| 2026-10-25 | Blueprint §7 department checklists audited against the task table; 14 gaps closed. | §7 is binding per `PROJECT_INSTRUCTIONS.md §2` but had never been mapped onto an acceptance gate. Added: OpenAPI generation and publication, 90% coverage bar, migration rollback test, cross-browser Playwright, `pushsubscriptionchange`, PITR, named CloudWatch dashboards, X-Ray, Shield, 3 AZs, RDS Proxy pinning measurement, S3, SES. |
| 2026-10-25 | `docs/BLUEPRINT.md` created as an **archive** of the surviving source material. | Precedence rank 4 in `PROJECT_INSTRUCTIONS.md §0` pointed at a file that never existed — the original Blueprint v2.0 is gone and only a conversation transcript quoting parts of it survives. The archive holds exactly what the transcript preserves, maps each part to where it is now binding, and lists what is **known to be missing** rather than reconstructing it (§8). |
| 2026-10-25 | Jira tickets re-reviewed against the source PDF; 8 further gaps closed, 3 deliberate differences recorded. | Six of the eight were **lessons already paid for on the lost build** and would have been rediscovered: Redis projection not tenant-namespaced (Blueprint bug 4, which O2 moved rather than removed), the lint guard enforcing C1/C2, the push-subscription cross-tenant FK test, role-timeout verification, data migrations under FORCE RLS, and the rate limiter missing 404s. Plus auto-scaling and the queue-transport decision. |
| 2026-10-25 | Fifth review pass: 2 contradictions, 1 unassigned mechanism, 1 risk resequenced. | The axis this time was **ordering and dependencies**. (1) Task 5's gate required a passkey login E2E while its own out-of-scope table assigned login UI to Task 10 — now an unstyled harness page in Task 5, the designed screen in Task 10. (2) Tasks 6-7 were held to §12's "no library default look" before Task 8 created any tokens — §12 now states where the visual bar applies and when, and Task 8 restyles the panel. (3) Nothing said how routes are guarded before Task 5 exists, or which task owns customer token auth — §4 › Tenancy now names both surfaces. (4) **The iOS Home-Screen push risk — `§4` calls it the largest customer-experience risk — was unvalidated until Task 9 of 15.** One manual validation on a real device now gates `task-4`. |
| 2026-10-25 | **ADR-003:** pnpm workspaces, `apps/web` + `apps/site` + `packages/{tokens,ui,contracts}`, Vitest for TypeScript. | Fourth review pass. The frontend had **no directory in the repo and no test runner**, while Task 8 required a token package shared by four consumers and §6 required 90% coverage on TypeScript. Task 8 was unbuildable as written. Same failure mode as ADR-002: the Blueprint's layout and Vitest were lost when §1 was written. `docs/adr/003-monorepo-and-frontend-tooling.md`. |
| 2026-10-25 | Fourth review pass: 4 structural gaps closed. | The first three passes checked **requirements**; this one checked **structure** — "where does this live", not "what must it do". Found: no frontend directory, no TS test runner, no API versioning policy behind a published spec, and "architecture guards" as an empty phrase where the lost build had three named rules. |
| 2026-10-25 | Third review pass: 4 implementation gaps closed. | The first pass found scope (§7 checklists), the second found paid-for lessons, the third found **mechanisms sitting behind policies we already had**: load shedding was stated as policy but never specified as behaviour (fast 503 on pool saturation, 503 under pressure, **health keeps answering**); no dependency had a declared fail-open/fail-closed posture; §6 said "fresh DB" where the Blueprint said **fresh clone** — the exact wording that would have caught Task 0's empty-directory bug; WAF was listed without the OWASP managed rules that are the point of it. |
| 2026-10-25 | **The bootstrap superuser is renamed `qrpager_root`; `qrpager_owner` is created `NOSUPERUSER NOBYPASSRLS`.** | Verified in-session: a Postgres **superuser ignores `ENABLE` and `FORCE` RLS entirely**. Task 0's container bootstrapped its superuser *as* `qrpager_owner`, so the Task 1 gate clause "`owner` is also bound by FORCE RLS" was unsatisfiable by construction. Not a fourth application role — the cluster superuser already existed; it is renamed so it stops colliding with role #1, which is also how Aurora works (master user bootstraps, migrations run as owner). Costs one `docker compose down -v`. |
| 2026-10-25 | **The no-`RETURNING` trap's stated mechanism is wrong, and a policy built from it would break the expiry job.** The rule is kept; `PLAN.md §2.12` records the measurement. | Measured on 16.14: **any `UPDATE` that reads a column re-checks the new row against the SELECT policies** — `RETURNING` is not required. A worker SELECT policy that hid expired rows would therefore make the prescribed `SELECT id → UPDATE … WHERE id = ? AND status = ?` fail with `42501` every time, not just forbid `RETURNING`. Hence `worker_reads_expired_sessions` is bounded by **time**, not status. The `§4` wording needs a rank-2 edit and is raised, not made. |
| 2026-10-25 | Five Laravel skeleton tables dropped: `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`. `users.id` becomes a uuid. | Redis is the locked store for session, cache and queue (§1), so these were unused tables — and four of them would have needed a tenant-isolation justification they cannot have. A uuid `users.id` matches every other id in the contract; changing a primary key later is a migration nobody wants to write. |
| 2026-10-25 | **C6 corrected:** the launch tier is set by **Task 14**, not Task 9. | C6 carried the Blueprint's own 10-task numbering, where load testing was Task 9. `PLAN.md §3` has 15 tasks and Task 9 is the customer PWA. A direct contradiction between ranks 2 and 3. |

---

## 2. Data contract — filled at Task 1

> Blueprint §3.1 is superseded (C3). **The Laravel migrations in `apps/api/database/migrations` are the
> source of truth; this section transcribes them.** Any task that needs a new table, column, enum value,
> role, GUC or grant updates this section **first** (`PROJECT_INSTRUCTIONS.md §6.1`) and then writes the
> migration. Nothing here may be invented by a later task.

### 2.1 Roles and connections

Four roles exist in the cluster, but only **three are application roles** — the fourth is the cluster's
pre-existing bootstrap superuser, which exists whether or not we name it.

| Role | Attributes | Used by | Laravel connection |
|---|---|---|---|
| `qrpager_root` | SUPERUSER (local) / `rds_superuser` (Aurora) | **Role and database bootstrap only**, never by the application | `pgsql_root` |
| `qrpager_owner` | NOSUPERUSER **NOBYPASSRLS** NOCREATEDB NOCREATEROLE, owns every table, `CREATE` on `schema public` | Migrations only | `pgsql_owner` |
| `qrpager_app` | NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE, **no `CREATE` on any schema** | Web requests | `pgsql` ← **the default** |
| `qrpager_worker` | same attributes as `qrpager_app` | Queue worker | `pgsql_worker` |

**Why `root` is separate from `owner`.** Verified in-session: a Postgres **superuser ignores `ENABLE` and
`FORCE` row level security entirely** — it read both tenants' rows with no tenant context set. Task 0's
container bootstrapped its superuser *as* `qrpager_owner`, so the §3 gate clause "`owner` is also bound by
FORCE RLS" was unsatisfiable by construction. The bootstrap superuser is therefore renamed `qrpager_root`
and `qrpager_owner` is created as a **non-superuser** table owner. This maps to production exactly: the
Aurora master user bootstraps the roles once, migrations run as `owner`, and neither is the runtime role.

`DB_OWNER_*` and `DB_ROOT_*` credentials belong **only** to the migration/CI environment, and a runtime task
should carry `DB_USERNAME=qrpager_app` (or `qrpager_worker`) and nothing else.

**Corrected 2026-10-25 (F6, security review of task-1).** This paragraph used to claim that
`assertSafeRuntimeRole()` "fails the boot if it ever carries more". It does not, and never did. What exists
is narrower: the guard asks **one connection** for its `current_user` and refuses a role that can see past
row level security — owner, superuser, `BYPASSRLS`, or a member of the owner. It does **not** inspect the
environment, so a runtime task that also carries `DB_OWNER_PASSWORD` passes it; and `withoutTenantScope()`
is a global function defaulting to the owner connection, so request-handling code is one call from a
cross-tenant bypass that is audited but not prevented. Nothing calls the guard yet either. Both halves —
wiring it into boot, and widening it to refuse a resolvable non-runtime credential — are **Task 2's**, whose
gate carries "boot refuses a runtime task that can resolve owner or root credentials".

### 2.2 GUCs — the only two that exist (C1)

| GUC | Scope | Set by | Read by |
|---|---|---|---|
| `app.tenant_id` | **transaction-local always** — `SELECT set_config('app.tenant_id', ?, true)` (C2) | `withTenant()` | every tenant policy |
| `app.bypass_tenant` | transaction-local | `withoutTenantScope()` — **owner connection only, writes an `audit_log` row** | the `owner_bypass_*` policies only |

**Verified, and the reason the `NULLIF` guard is not optional:** a placeholder GUC that has *never* been set
returns `NULL`, but once a transaction has set it, **every later transaction on that connection reads an
empty string `''`** — and `''::uuid` raises `22P02`, it does not match nothing. Every policy therefore uses
`NULLIF(current_setting('app.tenant_id', true), '')::uuid`, which yields `NULL`, which makes the policy
false, which is "**0 rows, no error**".

**`app.bypass_tenant` is not a general escape hatch.** Every `owner_bypass_*` policy is `TO qrpager_owner`.
Verified: `qrpager_app` setting `app.bypass_tenant = 'on'` itself still reads **0 rows**. SQL injection in
the web tier therefore cannot reach another tenant's data — the GUC is inert for the runtime roles.

### 2.3 Enum types — one source, mechanically checked

The **PHP enum is the source**. The Postgres type is a native `ENUM` created by migration, and
`tests/Feature/Database/EnumContractTest.php` compares `pg_enum` labels against the PHP cases for every
pair in `App\Domain\DatabaseEnums::map()`, so adding a case to either side fails the build until both
agree. Task 8 generates `packages/contracts` from the same registry (ADR-003) — never a hand-written copy.

| Postgres type | PHP enum | Values (ordered) |
|---|---|---|
| `pager_status` | `App\Domain\Pager\PagerStatus` | `waiting` · `ready` · `completed` · `cancelled` · `expired` |
| `outbox_status` | `App\Domain\Outbox\OutboxStatus` | `pending` · `sent` · `dead` |
| `tenant_status` | `App\Domain\Tenancy\TenantStatus` | `active` · `suspended` |
| `tenant_role` | `App\Domain\Tenancy\TenantRole` | `owner` · `manager` · `staff` |
| `audit_actor_type` | `App\Domain\Audit\AuditActorType` | `user` · `system` · `platform` |

`tenant_role` holds **tenant-scoped** roles only. The platform-admin flag is Task 5's and is not in this
contract yet. Transitions of `pager_status` are defined in the PHP enum by Task 3, not here.

### 2.4 Global tables — no `tenant_id`, no RLS

A table with no tenant scope column must appear in this list **and** in the allowlist in
`tests/Feature/Database/RlsCoverageTest.php`, or the meta-test fails the build. That is what stops a new
table from quietly escaping tenant isolation.

| Table | Columns | Grants |
|---|---|---|
| `migrations` | framework | `owner` only |
| `users` | `id` uuid PK · `name` varchar(120) · `email` varchar(255) **unique**, stored lower-cased · `email_verified_at` timestamptz NULL · `password` varchar(255) NULL *(passkey-only users have none)* · `remember_token` varchar(100) NULL · `created_at` · `updated_at` | app: SELECT/INSERT/UPDATE · worker: SELECT |
| `password_reset_tokens` | `email` varchar(255) PK · `token` varchar(255) · `created_at` | app: SELECT/INSERT/DELETE |
| `failed_jobs` | Laravel standard (`id` bigint identity · `uuid` unique · `connection` · `queue` · `payload` · `exception` · `failed_at`) | **worker only**: INSERT/SELECT/DELETE |

`users` is global because a user may hold memberships in several tenants and platform staff belong to none.
`failed_jobs` is granted to the worker **only**: a serialized job payload can contain tenant data, and a
global table has no RLS behind it.

**Dropped from the Laravel skeleton:** `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`. Redis is
the locked store for session, cache and queue (`PROJECT_INSTRUCTIONS.md §1`), so these were five unused
tables, four of which would have needed a tenant-isolation justification they cannot have.

### 2.5 Tenant tables — `ENABLE` + `FORCE` RLS on every one (C4)

All timestamps are `timestamptz`. All ids are `uuid` except `audit_log.id`.
"Scope column" is the column every policy compares against `app.tenant_id`.

| Table | Scope column | Columns |
|---|---|---|
| `tenants` | **`id`** | `id` PK · `name` varchar(120) · `slug` varchar(63) **unique**, CHECK DNS-label · `status` `tenant_status` default `active` · `timezone` varchar(64) default `UTC` · `created_at` · `updated_at` |
| `tenant_users` | `tenant_id` | `id` PK · `tenant_id` → `tenants.id` RESTRICT · `user_id` → `users.id` CASCADE · `role` `tenant_role` · `created_at` · `updated_at` · UNIQUE `(tenant_id, user_id)` · INDEX `(user_id)` |
| `pager_sessions` | `tenant_id` | `id` PK · `tenant_id` → `tenants.id` RESTRICT · `token_hash` bytea **unique** · `order_reference` varchar(32) · `status` `pager_status` · `expires_at` · `ready_at` NULL · `completed_at` NULL · `cancelled_at` NULL · `expired_at` NULL · `last_notified_at` NULL · `renotify_count` smallint default 0 CHECK ≥ 0 · `created_by_user_id` → `users.id` SET NULL · `created_at` · `updated_at` · UNIQUE `(tenant_id, id)` · INDEX `(tenant_id, status, created_at DESC)` · partial INDEX `(expires_at)` WHERE `status IN ('waiting','ready')` |
| `push_subscriptions` | `tenant_id` | `id` PK · `tenant_id` · `pager_session_id` · **FK `(tenant_id, pager_session_id)` → `pager_sessions (tenant_id, id)` CASCADE** · `endpoint` text CHECK `https://` + ≤ 2048 · `p256dh` varchar(255) · `auth` varchar(255) · `created_at` · `updated_at` · UNIQUE `(pager_session_id, endpoint)` · INDEX `(tenant_id)` |
| `outbox` | `tenant_id` | `id` PK · `tenant_id` → `tenants.id` RESTRICT · `pager_session_id` NULL · **FK `(tenant_id, pager_session_id)` → `pager_sessions (tenant_id, id)` CASCADE** · `type` varchar(64) · `dedupe_key` varchar(160) · `payload` jsonb default `{}` · `status` `outbox_status` default `pending` · `attempts` smallint default 0 CHECK ≥ 0 · `available_at` default `now()` · `sent_at` NULL · `dead_at` NULL · `last_error` text NULL · `created_at` · `updated_at` · **UNIQUE `(tenant_id, dedupe_key)`** · partial INDEX `(available_at, id)` WHERE `status = 'pending'` |
| `audit_log` | `tenant_id` **nullable** | `id` bigint `GENERATED ALWAYS AS IDENTITY` PK · `tenant_id` NULL = platform scope, **no FK** · `actor_type` `audit_actor_type` · `actor_user_id` uuid NULL, **no FK** · `action` varchar(64) · `subject_type` varchar(64) NULL · `subject_id` uuid NULL · `context` jsonb default `{}` · `ip_address` inet NULL · `created_at` default `now()` · INDEX `(tenant_id, created_at DESC)` · INDEX `(action, created_at DESC)` |

Three column choices that are load-bearing, not incidental:

- **`UNIQUE (tenant_id, id)` on `pager_sessions`** exists so `push_subscriptions` and `outbox` can carry a
  **composite** foreign key. That is what makes "a push subscription cannot point at another tenant's
  session" structurally impossible rather than policy-dependent: referential integrity checks bypass RLS,
  so the composite key is checked against the real table and a cross-tenant reference raises `23503`.
  Verified in-session.
- **`UNIQUE (tenant_id, dedupe_key)` on `outbox`** is the mechanism for Task 3's idempotent `markReady`:
  one row per logical event, enforced by the database under true concurrency, not by a read-then-write check.
- **`token_hash` is `bytea`, and PDO will not bind raw binary to it.** Bind hex and decode in SQL:
  `insert into pager_sessions (…, token_hash, …) values (…, decode(?, 'hex'), …)`, and the same for the
  lookup's `where token_hash = decode(?, 'hex')`. Task 3 inherits this; `tests/TestCase.php` has the
  working shape.
- **`audit_log` carries no foreign keys.** An `ON DELETE SET NULL` would be an `UPDATE`, which the
  append-only trigger blocks, so deleting a user would become impossible. The log keeps ids, not references.

### 2.6 Policy matrix

Every tenant table gets exactly these, where `ctx` is `NULLIF(current_setting('app.tenant_id', true), '')::uuid`:

| Policy | Command | Roles | `USING` | `WITH CHECK` |
|---|---|---|---|---|
| `tenant_isolation_<table>` | ALL | app, worker, owner | `scope = ctx` | `scope = ctx` |
| `owner_bypass_<table>` | ALL | **owner only** | `bypass = 'on'` | `bypass = 'on'` |

Plus exactly these named cross-tenant policies, and no others:

| Policy | Table | Command | Role | `USING` | `WITH CHECK` | §4 named path |
|---|---|---|---|---|---|---|
| `worker_drains_outbox` | `outbox` | SELECT | worker | `true` | — | the outbox queue |
| `worker_updates_outbox` | `outbox` | UPDATE | worker | `true` | `true` | the outbox queue |
| `worker_reads_expired_sessions` | `pager_sessions` | SELECT | worker | `expires_at <= now()` | — | the expiry job |
| `worker_expires_sessions` | `pager_sessions` | UPDATE | worker | `expires_at <= now()` | **`status = 'expired'`** | the expiry job |

Permissive policies are OR-ed, so the worker's cross-tenant reach is *exactly* these four grants and is
further narrowed by column grants: it can never `INSERT` into `outbox`, never change `tenant_id` anywhere,
and cross-tenant it can move a session to **no status other than `expired`**, and only after its TTL has
passed. `app` has no cross-tenant policy on any table.

The remaining two named paths in `§4 › Tenancy` — **the QR token lookup** and **a user's own memberships** —
have no policy here on purpose. They are implemented by the task that needs them (3 and 5) with the recipe
in §2.8, because a policy wide enough to serve them would be a standing bypass for `qrpager_app`.

### 2.7 Column grants — what each role may touch

The point of column grants is that over-broad SQL cannot leak what the role was never given.

| Table | `qrpager_app` | `qrpager_worker` |
|---|---|---|
| `tenants` | SELECT all · INSERT `(id, name, slug, status, timezone, created_at, updated_at)` · UPDATE `(name, status, timezone, updated_at)` | SELECT all |
| `tenant_users` | SELECT · INSERT · UPDATE `(role, updated_at)` · DELETE | SELECT |
| `pager_sessions` | SELECT **all except `token_hash`** · INSERT `(id, tenant_id, token_hash, order_reference, status, expires_at, created_by_user_id, created_at, updated_at)` · UPDATE `(status, ready_at, completed_at, cancelled_at, last_notified_at, renotify_count, updated_at)` | SELECT **all except `token_hash`** · UPDATE `(status, expired_at, updated_at)` |
| `push_subscriptions` | SELECT · INSERT · UPDATE `(p256dh, auth, updated_at)` · DELETE | SELECT · DELETE *(404/410 pruning)* |
| `outbox` | SELECT · INSERT `(id, tenant_id, pager_session_id, type, dedupe_key, payload, available_at, created_at, updated_at)` | SELECT · UPDATE `(status, attempts, available_at, sent_at, dead_at, last_error, updated_at)` |
| `audit_log` | INSERT `(tenant_id, actor_type, actor_user_id, action, subject_type, subject_id, context, ip_address)` · SELECT | INSERT same · SELECT |

- **`token_hash` is write-only for `app`.** Verified: a role can `INSERT` a column it cannot `SELECT`, so the
  hash can be stored and never read back — the raw token is already forbidden anywhere (`§4 › Data`), and
  the hash cannot leak through a careless `SELECT *` either. Task 3's lookup goes through §2.8.
- **No role has `UPDATE` on any `tenant_id`**, so a row cannot be moved between tenants even with context set.
- **No role has `UPDATE`, `DELETE` or `TRUNCATE` on `audit_log`**; the trigger in §2.9 is the backstop behind that.
- `app` has no `DELETE` on `pager_sessions` or `outbox`: sessions are cancelled or expire, they are not deleted.

### 2.8 Cross-tenant reads — the only mechanism

A named path in `§4 › Tenancy` that genuinely has no tenant context is implemented as a
**`SECURITY DEFINER` function owned by `qrpager_owner`** with `SET search_path`, which turns on
`app.bypass_tenant` for the duration of its own body, restores the previous value before returning, and is
granted `EXECUTE` to `qrpager_app` alone. Verified end to end, including that the GUC does **not** leak back
to the caller and that the caller's own direct queries stay tenant-bound.

**Two rules, both learned by running it:**

1. **Never `RETURNS SETOF <table>`.** A function returns rows as its owner, and **column grants are not
   applied to a function's result set** — a `SETOF pager_sessions` resolver hands `token_hash` straight back
   to `qrpager_app` and quietly undoes §2.7. Declare a narrow `RETURNS TABLE (...)` naming only the columns
   the caller needs.
2. The function body is the audit boundary. It takes one exact-match argument (a token hash, a user id), so
   it answers one question and cannot be used to enumerate.

### 2.9 `audit_log` immutability

A `BEFORE UPDATE OR DELETE` statement-level trigger and a `BEFORE TRUNCATE` statement-level trigger both
raise `42501`, and both are **`ENABLE ALWAYS`**.

`ENABLE ALWAYS` is the whole point, and it is not decoration: verified in-session, a **superuser** that sets
`session_replication_role = replica` deletes rows straight through an ordinary trigger, and is refused by an
`ENABLE ALWAYS` one. Statement-level is deliberate — it fires even when the statement would match zero rows.

Immutability holds against every role including the superuser. It does **not** hold against a superuser
willing to run DDL (`DROP TRIGGER`), which nothing in Postgres can prevent; in production no application
role is a superuser and `owner` holds no `SUPERUSER` or `BYPASSRLS` attribute.

### 2.10 Data migrations under FORCE RLS — the settled pattern

`PLAN.md §6` recorded this as the trap that blocks the first data migration anyone writes. `owner` is bound
by FORCE RLS, so a migration that touches rows sees **none** of them — including the `tenants` table it wants
to loop over. **Corrected 2026-10-25 (H-1, QA review of task-1):** pattern 1 below used to open with a plain
`DB::connection('pgsql_owner')->table('tenants')->pluck('id')`. That returns **zero rows** inside a migration,
so the loop body never ran. A backfill that silently does nothing is worse than one that fails loudly.

Two patterns, in order of preference:

```php
// 1. Preferred — the per-tenant work needs no bypass. Enumerating the tenants does:
//    that one cross-tenant read is the only part the owner cannot do under FORCE RLS.
$ids = withoutTenantScope(
    '2026_11_02 backfill pager_sessions.order_reference: list tenants',
    fn () => DB::connection('pgsql_owner')->table('tenants')->pluck('id'),
);

foreach ($ids as $id) {
    // Keyed, never bulk: an UPDATE or DELETE that references no column of the table
    // escapes RLS row filtering entirely (PROJECT_INSTRUCTIONS.md §4 › Postgres traps).
    withTenant($id, fn () => /* ... */, 'pgsql_owner');
}

// 2. Only when the work itself genuinely spans tenants — named, audited, owner-only:
withoutTenantScope('2026_11_02 backfill pager_sessions.order_reference', fn () => /* ... */);
```

Both helpers always run **nested** here: `Migrator` wraps every migration in a transaction because
`PostgresGrammar` reports `supportsSchemaTransactions`, so a migration body is already at transaction level 1.
`set_config(name, value, true)` binds to the **outermost** transaction and not to the savepoint Laravel opens
for the inner block, so both helpers capture the previous value and restore it on the way out (F1). Proven by
`DataMigrationPatternTest`.

`withoutTenantScope()` refuses to run on any connection whose role is not `qrpager_owner`, and writes an
`audit_log` row (`actor_type = system`, `action = tenancy.bypass`, the reason in `context`) inside the same
transaction, so a bypass that **commits** always carries its own audit row. A bypass that **throws** rolls
that row back with the work and leaves no trace: carried as F12 in `§5`, owned by Task 2. A **schema**
migration needs neither pattern; only a migration that reads or writes rows does.

A third pattern needs no bypass and is the one to reach for first: seed rows **before** the table's RLS is
enabled, within the same migration.

### 2.11 What is bounded, what is not

| Growth | Bound |
|---|---|
| `pager_sessions` | Unbounded by design (one row per order, forever). Rows reach a terminal status within 2 h of creation; the hot working set is the partial index on live sessions only. Retention is a Task 13/14 decision and is **not** solved here. |
| `push_subscriptions` | Bounded by live sessions — `ON DELETE CASCADE` from the session, plus 404/410 pruning in Task 4. |
| `outbox` | **Unbounded, and the one to watch.** `sent` and `dead` rows are never deleted by any grant in §2.7. The worker's claim index is partial on `status = 'pending'`, so throughput does not degrade, but disk does. Pruning is unassigned — recorded in §6. |
| `audit_log` | Unbounded by design and append-only, so pruning can only ever be a partition drop, never a `DELETE`. Recorded in §6. |
| Per active session | 1 `pager_sessions` row + 0-N `push_subscriptions` rows. No per-session Postgres read on the hot path: Task 4's polling endpoint is Redis-only (`§4 › Realtime`). |

### 2.12 A verified Postgres behaviour that changes a documented trap

`PROJECT_INSTRUCTIONS.md §4 › Postgres traps` and the Task 3 ticket both explain the no-`RETURNING` rule as
"`RETURNING` makes Postgres apply SELECT policies to the NEW row, and expired rows are deliberately not
selectable by the worker". Run against Postgres 16.14, the first half is **narrower than reality** and the
second half would **break the expiry job outright**:

| Statement | Result |
|---|---|
| `UPDATE … SET status='expired' WHERE id = ?` — new row hidden by the SELECT policy | **`ERROR 42501` — no `RETURNING` involved** |
| same, with `RETURNING id` | `ERROR 42501` |
| `UPDATE … SET status='expired'` with **no `WHERE`** | succeeds |
| `UPDATE … SET status='ready'` — new row still visible | succeeds |
| `UPDATE … WHERE id = ?` — **old** row hidden by the SELECT policy | `UPDATE 0` — silent, no error |
| `UPDATE … SET status='touched'` with **no `WHERE`** — old row hidden | succeeds — **writes a row the role cannot read** |

The actual rule: **when an `UPDATE` reads any column of the table, the SELECT policies are applied to the
new row as well.** The prescribed pattern `SELECT id → UPDATE … WHERE id = ? AND status = ?` reads columns,
so a worker SELECT policy that hid expired rows would make the expiry job impossible — `42501` every time,
or a silent `UPDATE 0` if no cross-tenant SELECT policy existed at all.

The same trigger has an **old-row half**, and it is the more dangerous one: when a column is referenced a row
invisible to the SELECT policy is silently skipped (`UPDATE 0`, no error), but when **no** column is referenced
the filter does not apply at all and the statement writes rows the role cannot read — measured as `UPDATE 2`
where the same statement with a `WHERE` reported `UPDATE 0`.

This is why `worker_reads_expired_sessions` is bounded by **time** (`expires_at <= now()`) and not by status:
the new row still satisfies it. The no-`RETURNING` rule is kept — it costs nothing and still protects any
future policy that hides a new row — but it is now a defensive convention, not the mechanism. **The §4
wording was corrected on 2026-10-25 with the user's approval (rank 2, `§0`): the trigger is restated as any
column reference by the statement, the old-row asymmetry is recorded as its own trap, and the claim that
expired rows are not selectable by the worker is dropped.** The time-only bound on
`worker_reads_expired_sessions` is wider than the path it implements and is carried as an accepted risk
(`§5` R-003, owner Task 3, which also owns the expiry job).

---

## 3. Task table

Each task also passes the Universal Gate (`PROJECT_INSTRUCTIONS.md §6`) and the three-role review (§11). Order is strict.

| # | Task | Own acceptance gate — must be demonstrated |
|---|---|---|
| **0** | **Repo + local environment.** Private repo, `.gitignore`, `.env.example`, gitleaks pre-commit + CI, conventional-commit hook, `CLAUDE.md`, `.claude/agents/*`, `.claude/commands/task.md`, Docker Compose (Postgres 16, Redis 7), PHP 8.4 toolchain, Laravel 13 skeleton, Pint + PHPStan + Pest wired, GitHub Actions skeleton. | `php artisan serve` responds; `php artisan test` runs one green test against the **real** composed Postgres; `gitleaks detect` clean; CI green on first push; tag `task-0`. |
| **1** | **DB foundation.** Migrations for the full schema, 3 DB roles, RLS `ENABLE` + `FORCE` on every tenant table, per-role policies, column grants, append-only `audit_log` trigger, `ALTER ROLE` timeouts, `withTenant()` transaction helper, `assertSafeRuntimeRole()`. | No tenant context → every table returns **0 rows, no error**; cross-tenant read/update/delete/insert all blocked; `owner` is also bound by FORCE RLS; context does **not** leak across a single-connection pool; `worker` sees the outbox across tenants, `app` does not; `audit_log` immutable even for superuser; **meta-test fails the build if any table lacks ENABLE or FORCE**; **a push subscription cannot point at another tenant's session**; **the `ALTER ROLE` timeouts are verified to fire, per role**; migrations apply clean from empty twice; **`migrate:rollback` runs clean and a re-apply returns the identical schema** (Blueprint §7 Database). §2 of this file is filled. |
| **2** | **API core.** Octane/FrankenPHP boot, config validation, tenant-context middleware (`set_config(..., true)` per transaction), safe-role boot refusal, error mapping to real driver codes, Monolog redaction, rate limiting, health + readiness, graceful shutdown, **named architecture guards in CI** (domain logic imports no framework or DB code · platform layer imports no feature modules · production code imports no test helpers), **a repo guard that rejects session-scoped tenant settings and unknown `app.*` names**, **OpenAPI spec generation wired**, **`/v1` route versioning**. | Boot refuses an unsafe DB role (exit 1, reason logged); a request without tenant context never reaches the DB; health reflects real Postgres + Redis state; `SIGTERM` drains in-flight requests and exits 0 (proven against the compiled app); captured logs contain no SQL, no bound parameters, no secrets; **the repo guard and each named architecture guard fail on a deliberately bad file** (C1/C2 enforced mechanically); **every public route is under `/v1`, and the versioning policy for a breaking change is written into §6**; **the rate limiter covers unknown routes (404s), or the gap is written into §5 with the control that covers it**; **a saturated DB pool returns a fast 503, and memory/CPU pressure returns 503 — while health and readiness keep answering**; **Redis is taken down and the API keeps serving, with the rate limiter's fail-open/fail-closed posture stated and tested**; **the OpenAPI spec is generated from the routes, committed, and CI fails when it is stale** (Blueprint §7 API). |
| **3** | **Pager domain.** Create session (128-bit CSPRNG token, hash-only storage, 2h TTL, QR payload), `markReady`, `renotify`, `complete`, `cancel`, expiry job. Status + outbox row + audit row committed in **one** transaction. | `markReady` is idempotent under concurrent calls — one outbox row, one notification; invalid state transitions rejected; cross-tenant IDOR blocked through HTTP, not just at the DB; expiry job uses `SELECT id` → `UPDATE ... WHERE id = ? AND status = ?` with **no `RETURNING`**; raw token never appears in any log, URL or error body (asserted against captured output). |
| **4** | **Notification pipeline.** Outbox worker (`FOR UPDATE SKIP LOCKED`, backoff, dead-letter), Web Push dispatcher (VAPID, 404/410 subscription pruning), Redis status projection, polling endpoint, subscription endpoints. | Kill the worker mid-batch → nothing lost, nothing duplicated; polling endpoint does **zero Postgres queries** (proven by query log) and p99 < 5 ms; dead subscriptions pruned on 404/410; push sent to a mocked edge only **in the automated suite**; **one manual validation before this task is tagged: a real iOS device, installed to Home Screen, receives one real push from this dispatcher — iOS version and result recorded in §4a** (this is the project's largest product risk per `PROJECT_INSTRUCTIONS.md §4 › Realtime`, and Task 9 is too late to discover it); client backoff + jitter verified; **Redis is taken down and the polling endpoint's behaviour is defined and tested — the customer must not see a dead end**; **every Redis key holding tenant data is namespaced by tenant id, and a test proves one tenant's projection cannot be read through another tenant's context** — Redis has no RLS (Blueprint bug 4, which O2 moved rather than removed). |
| **5** | **Auth & RBAC.** Fortify + Sanctum, WebAuthn passkeys, 2FA, roles (platform admin / owner / manager / staff), KDS device tokens, session policy. **A minimal unstyled harness page is built here purely to exercise the WebAuthn browser API** — the designed login screen is Task 10. | Full role-matrix test; session fixation and CSRF tests; a tenant-provisioning actor cannot read another tenant's data; **passkey registration + login proven end to end through the browser against the harness page** (the styled screen is Task 10, and must not be required here); device token scope cannot escalate. |
| **6** | **Filament admin — tenant provisioning (S1).** Platform dashboard, restaurant create wizard end-to-end, staff management + **invites**, QR batch generation + printable sheets, audit log viewer. | A new restaurant goes from zero to a scannable QR in one wizard pass, with no SQL run by hand; the wizard is transactional — a failure halfway leaves no partial tenant; platform admin actions all land in `audit_log`; **a staff invite is actually delivered, asserted against the local mail catcher** (ADR-002). |
| **7** | **Landing page builder (S2).** Block-based builder in Filament, **media library on S3**, SEO fields, theme tokens per tenant, publish → static render, custom domain + automatic TLS. | Publish produces a statically rendered, CDN-cached page; an unpublished draft is not reachable; a tenant cannot inject script through any block (XSS fuzz suite); custom domain issues TLS automatically and the old domain redirects; theme overrides **tokens only**, never components; **an upload is validated by real content type and size, not by extension, and one tenant's asset path cannot be guessed or reached from another** (ADR-002). |
| **8** | **Design system + UI kit.** **Create the pnpm workspace and the frontend half of the repo (ADR-003): `apps/web`, `apps/site`, `packages/{tokens,ui,contracts}`, Vitest, ESLint, TS strict, and the generated TS mirror of the PHP status enum.** Then the token package (color, type scale, spacing, radius, elevation, motion), base components, RTL + dark mode, accessibility primitives, **and the Filament panel restyled from those tokens** (Tasks 6-7 build it functionally first — see §12). **Two or three visual directions delivered as rendered artifacts for approval before any component is written.** | **The workspace builds, lints and tests from a fresh clone, and CI runs it as a second job**; **the TS status mirror is generated and CI fails when it is stale** — no hand-written copy; a direction is approved in writing; zero hard-coded hex/spacing/font-size anywhere (lint rule enforces it, **proven to fail on a bad file**); WCAG AA across the kit; RTL mirrors correctly; `prefers-reduced-motion` honoured; a reviewer cannot name the UI framework from a screenshot. |
| **9** | **Customer PWA.** The scan-to-ready journey (see §4). Service worker including a **`pushsubscriptionchange` handler**. | Playwright E2E **green on Chromium, Firefox and WebKit** (Blueprint §7 QA): scan → wait → ready → alert; permission-denied, offline, expired-token and invalid-token paths all handled; **a rotated subscription is re-registered by the `pushsubscriptionchange` handler and the next notification still arrives** — tested, not assumed; iOS Home-Screen install path works and is measured; Lighthouse Performance ≥ 95; one-handed operation verified at 390px. |
| **10** | **Staff dashboard + KDS.** Active pager board, mark-ready, renotify, history, shift view. | Mark-ready round trip under 2 s to the customer device; board stays correct with two staff acting at once; KDS survives a network drop and resyncs without duplicate notifications. |
| **11** | **Public landing renderer.** Next.js static/ISR rendering of S2 pages with per-tenant theme and domain. | Cold page served from CDN with origin untouched; per-tenant theme applied from tokens; SEO/OG tags correct; Lighthouse ≥ 95 on a real tenant page. |
| **12** | **Security hardening.** CSP, CORS, per-tenant + per-IP limits in Redis, input fuzzing, SAST/DAST in CI, OWASP ASVS L2 mapping. | Zero high/critical from the SAST and DAST runs; ASVS L2 table complete with evidence per item — **including file-upload handling and mail header injection** (ADR-002); CSP has no `unsafe-inline`; fuzz suite finds no 5xx; **the OpenAPI spec is checked against the live routes and exposes no endpoint that should be private**. |
| **13** | **Infrastructure (Terraform).** VPC across **3 AZs**, ECS Fargate, Aurora PG with **PITR**, ElastiCache, **S3**, **SES**, ALB, CloudFront, **WAF with the OWASP managed rule set**, **Shield**, Secrets Manager, observability (**CloudWatch dashboards + X-Ray**), GitHub OIDC CI/CD. | `terraform validate` + `plan` clean; zero-downtime deploy rehearsed; **auto-scaling configured, with the scale-out trigger and ceiling stated**; no stored AWS keys anywhere; secrets only in Secrets Manager; **PITR enabled and its retention window stated**; **dashboards exist for ALB 5xx, ECS CPU, Aurora ACU and Redis latency, with alarms routed to a real destination**; **if RDS Proxy is adopted, session pinning is measured first and the number recorded** (Blueprint §7 Observability + Database). |
| **14** | **Load, chaos, launch.** k6 ramps, ready-storm, task kill, Redis failover, DB failover. Runbooks, backup/restore drill, pen-test prep, **published API documentation**, launch checklist. | `docs/load-results.md` with p95/p99, error rate and cost per tier; **the launch tier is set here from measured numbers, never assumed (C6)**; restore drill executed end-to-end **from PITR, with the measured RTO written down**; **API documentation published from the OpenAPI spec** (Blueprint §7 Docs); runbooks reviewed. |

---

## 4. Frontend scope — page inventory

Every page below needs purpose, all states, content inventory and final copy written into its ticket **before** it is coded (`PROJECT_INSTRUCTIONS.md §12`).

### 4.1 Customer PWA (Task 9) — no account, phone-first, Arabic + English
| Screen | Must contain | States |
|---|---|---|
| Scan landing `/s/{token}` | Restaurant mark, order reference, a single primary action, zero chrome. Static shell from CDN. | valid · expired · invalid · already-ready |
| Enable alerts | One-sentence value statement, permission button, "why we ask", **iOS Home-Screen install path as a first-class flow** | not-asked · granted · denied · unsupported browser |
| Waiting | Live elapsed timer, status, restaurant branding, keep-screen-awake toggle, quiet "what happens next" | polling healthy · polling degraded · offline |
| **Order ready** | Full-bleed unmistakable state, sound + vibration, order reference, AAA contrast, readable across a room | fresh · acknowledged |
| Session ended | Expired or completed, with a path back to the restaurant page | expired · completed · cancelled |
| Offline / error | Honest message, retry, no dead end | — |

### 4.2 Staff dashboard + KDS (Task 10)
Login (passkey-first) · Active pager board · Pager detail (mark ready, renotify, cancel) · Create pager + print QR · History with filters · Shift/device settings · Error and empty states for each.

### 4.3 Filament admin (Tasks 6–7)
Platform dashboard · Restaurants list · **Create-restaurant wizard** (identity → branding → staff → QR batch → publish) · Restaurant detail (branding, domains, staff, QR batches, usage) · **Landing page builder** (block canvas, media library, SEO panel, theme tokens, preview, publish) · Audit log viewer · Platform settings.

### 4.4 Public tenant landing page (Task 11)
Hero · How the pager works · Menu highlights · Gallery · Hours + location + map · Contact / social · Footer · SEO + OG + structured data. All blocks optional and reorderable, all styled from tenant tokens.

---

## 4a. Gate evidence

### Task 0 — complete, tagged `task-0` (2026-10-25)

| Gate clause | Evidence |
|---|---|
| `php artisan serve` responds | `curl` → `200` |
| `php artisan test` green against the **real** composed Postgres | 4 tests / 12 assertions. `RealDatabaseTest` asserts driver `pgsql`, database `qrpager_test`, `PostgreSQL 16`, and **fails** when forced to SQLite (verified) |
| `gitleaks detect` clean | clean locally and in CI over full history (`fetch-depth: 0`) |
| CI green on first push | run #2, both jobs green, 37s |
| tag `task-0` | pushed |

Built: Laravel 13.38.0 in `apps/api`, Docker Compose (Postgres 16.14 + Redis 7, bound to `127.0.0.1` only, `log_statement=all` for the Task 4 proof), Pint + PHPStan level 8 (larastan) + Pest 5, gitleaks pre-commit + conventional-commit hook (both proven to block), `CLAUDE.md`, `.claude/agents/{qa,security}-reviewer.md`, `.claude/commands/task.md`, GitHub Actions.

**Bugs found and fixed in Task 0 — do not repeat:**
1. `.gitignore` had `/build/`, which anchors to the repo root, so `apps/api/build/` (the PHPStan `tmpDir`) was committed — 678 cache files. Fixed to `build/`. Any new tool cache must be verified with `git status` after running the tool, not assumed ignored.
2. Deleting the last file in `tests/Unit/` left an empty directory that git does not track, so `phpunit.xml`'s `Unit` testsuite broke on a fresh CI clone while passing locally. Fixed with a real unit test asserting PHP 8.4 and the required extensions — never a `.gitkeep`.
3. `apps/api/CLAUDE.md` shipped by the Laravel skeleton (laravel-boost) instructed agents to install PHP 8.5, contradicting §1. Deleted; one `CLAUDE.md` at the repo root only.

---

### Task 1 — **not tagged**. On `task-1-db-foundation` (built 2026-10-25), five rounds run, fifth review pending

Gates, re-run after the §11 review fixes: `composer pint` **passed** · `composer stan` (level 8)
**0 errors** · `php artisan test` **170 passed, 480 assertions** against the composed Postgres 16.14 and
Redis 7, from a **dropped and rebuilt** test database · `gitleaks protect --staged` passed on every commit
locally (8.30.1, the CI pin) and `gitleaks detect` passed over full history in CI.

Coverage **98.7 %** against the 90 % gate (`--min=90`), measured by PCOV on the CI run for PR #1 at
`7bcfdb3` — run `40235159657`, step "Unit coverage on the code this branch adds". Two files sit below
100 %: `Platform/Database/TenantContext` at **97.4 %**, uncovered only at `248-249`, the
`insertBypassAudit()` catch named above; and `Platform/Database/RoleBootstrapper` at **98.3 %**, uncovered
only at `263` and `275`, two pre-existing unreachable guard throws this task did not add. **No coverage
figure in this file is ever a local measurement:** the build machine has neither PCOV nor Xdebug, and CI
gets PCOV from `shivammathur/setup-php`. A figure recorded here always names the run and the commit that
produced it.

| Gate clause | Evidence |
|---|---|
| No tenant context → every table returns **0 rows, no error** | `TenantIsolationTest` walks all six tenant tables twice: on a connection that has never set the GUC (reads `NULL`) and on one that has (reads `''`). Both give 0 rows and no error |
| Cross-tenant read / update / delete / insert all blocked | read → 0 rows even when the other tenant's id is named · update → `UPDATE 0` · delete → 0 rows (tested on `push_subscriptions`, where `app` *does* hold `DELETE`, so it measures the policy and not a missing grant) · insert → `42501` · moving a row between tenants → `42501` |
| `owner` is also bound by FORCE RLS | `OwnerAndBypassTest`: 0 rows on all six tables with no context, and the role reports `rolsuper=f`, `rolbypassrls=f` |
| Context does **not** leak across a single-connection pool | `ConnectionReuseTest` drives tenant A → no context → tenant B down one backend, asserting `pg_backend_pid()` never changed |
| `worker` sees the outbox across tenants, `app` does not | `WorkerScopeTest`: worker reads 3 rows across 2 tenants with no context and claims them `FOR UPDATE SKIP LOCKED`; `app` reads 0 and sees only its own tenant's row. Worker `INSERT`/`DELETE` on the outbox → `42501` |
| `audit_log` immutable even for superuser | UPDATE / DELETE / TRUNCATE → `42501` for `app`, `worker`, `owner` **and `qrpager_root`, a real superuser**, including with `session_replication_role = replica`; zero-row statements refused too; both triggers assert `tgenabled = 'A'` |
| **Meta-test fails the build if any table lacks ENABLE or FORCE** | `RlsCoverageTest` reads `pg_catalog`, not the migrations. It also **creates a deliberately bad table and proves itself failing**, then checks every table is either tenant-scoped or on the named global allowlist |
| **A push subscription cannot point at another tenant's session** | `23503` from a **composite** FK `(tenant_id, pager_session_id)` → `pager_sessions (tenant_id, id)`. Structural: FK checks bypass RLS, so this does not depend on a policy. Same for `outbox` |
| **The `ALTER ROLE` timeouts are verified to fire, per role** | `SHOW` on a fresh connection per role, compared **as intervals** (Postgres reports `60s` as `1min`) · `lock_timeout` **fires** for `app`, `worker` and `owner` (`55P03`, after the configured wait, proven with an advisory lock) · `app` `statement_timeout` **fires** (`57014`, asserted with a lower wall-clock bound only — see F10) · `pg_roles.rolconfig` confirms the settings are on the role, not one database |
| Migrations apply clean from empty **twice** | Database dropped, `db:bootstrap`, apply → 5 migrations, apply again → `Nothing to migrate` |
| **`migrate:rollback` clean and a re-apply returns the identical schema** | Rollback leaves exactly one table (`migrations`). Re-apply then matches **byte for byte** on both a 1219-line `pg_dump --schema-only` diff and a 421-fact catalog fingerprint (`database/schema-fingerprint.sql`) covering RLS flags, policy expressions, policy roles, column grants, triggers, enums, constraints and indexes. Re-proven in CI, which is where the ticket asked for it |
| `PLAN.md §2` is filled | §2.1-§2.12 |

Carry-over from Task 0, now closed: `.env` and `.env.example` connect as `qrpager_app`; migrations run on
a separate `pgsql_owner` connection; `assertSafeRuntimeRole()` exists with tests proving it rejects the
owner **and** a superuser, and — proven against the live cluster rather than asserted — a role granted
`BYPASSRLS` or membership of the owner.

**§11 review findings — what the two reviews produced.** QA raised H-1; Security raised F1-F12.
Two High, five Medium, five Low, one informational. Fixed here, each with a regression test proven to
fail when the fix is reverted:

| # | Sev | Finding | Fix | Proof |
|---|---|---|---|---|
| **F1** | High | `withTenant()` leaked tenant context out of any transaction it did not start. Laravel opens a `SAVEPOINT` rather than a `BEGIN` when nested, and `set_config(…, true)` binds to the **outermost** transaction, so the GUC outlived the block and the next query read the last tenant used — *another tenant's rows*, not the designed "0 rows, no error". The PHP-side switch guard stopped firing too. | Both helpers capture the previous GUC value and restore it on the success path. Restoring in a bare `finally` would raise `25P02` on a failed callback and mask the original exception; a rollback — of the transaction or to the savepoint — reverts `set_config` by itself, measured on 16.14. The **refusal** alternative was rejected: `PostgresGrammar::supportsSchemaTransactions()` is `true`, so `Migrator` wraps every migration body in a transaction, and refusing to nest would have broken every data migration using the `§2.10` per-tenant loop. | `TenantContextTest`: 3 tests. Reverted, they fail with `app.tenant_id` reading the leaked uuid and `app.bypass_tenant` reading `'on'` |
| **F4** | High | Every role password reached the Postgres server log **and** Laravel exception messages. `ALTER ROLE … PASSWORD '<plaintext>'` puts it in the statement text that `log_statement` captures, and `format()` sent it as a bound parameter, which Postgres logs on error and Laravel interpolates into `QueryException::getMessage()`. Two breaches of `§4` at once. | The SCRAM-SHA-256 verifier is now computed in PHP — PBKDF2-HMAC-SHA256, 4096 iterations, 16-byte salt — so the plaintext never crosses the wire. Non-ASCII passwords are refused rather than silently producing a verifier that authenticates nobody, since SASLprep is the identity only on printable ASCII. Role statements are additionally wrapped so no exception can carry the statement text; the SQLSTATE survives, the original is deliberately not chained. | `BootstrapDatabaseCommandTest`: the bootstrap's own query log contains the verifier and **not** the password. Measured end to end: 0 occurrences of the dev password in 110 new lines of container log, where the same bootstrap previously logged it twice. **This is not "no credential in the log":** the same 110 lines carry the *verifier* 6 times, twice per role. That is a reduction, not a closure, and it is carried as **R-009** |
| **H-1** | High | `§2.10`'s data-migration loop opened with a plain owner read of `tenants`. The owner is bound by FORCE RLS, so that list is **empty** inside a migration and the loop body never ran — a backfill that silently does nothing. | The tenant list now comes through one audited `withoutTenantScope()` read; the per-tenant work still needs no bypass. `§2.10` and the `§6` bullet both corrected. | `DataMigrationPatternTest`: 2 tests, including the whole pattern at transaction level 1. **H-1 has no discriminating regression test, and §11 coverage is not claimed for it:** the fix was a prose change to `§2.10`, so there is no code to revert and the first test passes against `122d203` too. It is named a characterisation test in the file, with the reason beside it. The second test *is* discriminating, but for F1, not for H-1 |
| **F2** | Med | `users` and `password_reset_tokens` were the only tables granted **table-wide**, and the only ones with no assertion in `GrantMatrixTest` — so the widest grants in the schema were the ones nothing would catch widening. | Stated per column in the migration. The worker is narrowed to `SELECT (id, name, email)`: it authenticates nobody and has no business holding credentials. The app's set is unchanged in effect but now explicit, minus `id` and `created_at` on `UPDATE`. Residual carried as **R-006**, owner Task 5. | `GrantMatrixTest`: 3 tests pinning exact column sets **and** asserting the grants are column grants rather than table-wide. All three fail when reverted |
| **F5** | Med | No `CONNECTION LIMIT` on any role: `rolconnlimit = -1` everywhere, and Postgres reserves slots for superusers only, so `qrpager_app` exhausting the pool starved the worker (the only sender of notifications, C5) and the owner (migrations, incident response). This task's own bug 2 was that exhaustion. | Per-role limits in `config/tenancy.php` — owner 10, app 40, worker 20 — applied by `ALTER ROLE` and read back from `pg_roles`. A missing limit is a hard failure, not an unlimited default. | `BootstrapDatabaseCommandTest`: limits match config, their sum leaves the cluster headroom above `superuser_reserved_connections`, and a missing limit throws |
| **F6** | Med | `§2.1` claimed `assertSafeRuntimeRole()` "fails the boot if it ever carries more" than the runtime credential. It never did: the guard inspects one connection's `current_user` and never looks at the environment. | `§2.1` corrected to describe what exists. Widening the guard stays Task 2's gate clause, where it already was. | §2.1 and §0 |
| **F7** | Low | `sslmode` defaulted to `prefer`, which silently downgrades to an unencrypted session — including on the connections that carry the role credentials. | Defaults to `require`, so it **fails closed**. Local and CI opt out with one explicit `DB_SSLMODE=disable`. `verify-full` carried as **R-008**, owner Task 13. | `ConnectionHygieneTest` asserts the unset default across all five connections |
| **F9** | Low | `RlsCoverageTest`'s global allowlist was only checked against the same `tenant_id`-name heuristic used to classify tenant tables, so a future tenant table scoped by a differently named column could join the allowlist undetected. | The allowlist is pinned as an exact set. | `RlsCoverageTest` |
| **F10** | Low | The `statement_timeout` proof asserted wall-clock time **below** 7 s. It measures host scheduling, not Postgres, and it **failed on the reviewer's run at 27.6 s** while `57014` passed — gate evidence that gets re-run until green rather than believed. | `57014` is the proof; the only sound wall-clock assertion is the **lower** bound, since Postgres cannot cancel before the timeout but a loaded host can report it arbitrarily late. The identical upper bound on the `lock_timeout` proof is removed for the same reason. | Both proofs re-run green with eight cores saturated |
| **F3, F8, F11, F12** | Med/Low | Accepted, not fixed. | — | **R-003** (owner Task 3), **R-004**, **R-007**, **R-005** |

The reviews also confirmed, against `pg_catalog` and the live cluster rather than the migrations: FORCE RLS
on all six tenant tables, exactly two `app.*` GUCs, `set_config` called in exactly two places and always
with third argument `true`, no `BYPASSRLS` on any application role, exactly the four named cross-tenant
policies, no cross-tenant policy for `qrpager_app`, `audit_log` immutable for a real superuser including
under `session_replication_role = replica`, and `composer.lock` unchanged by this task.

**A third review, scoped to the fix commits.** The reviews above ran against `122d203`, so the fixes for
their own findings had been read by nobody. A scoped security review of `122d203..c757a99` found **1 High,
4 Medium, 5 Low, 1 informational** — and the High was in the F1 fix itself:

| # | Sev | Finding | Disposition |
|---|---|---|---|
| **H-1** | **High** | **`withTenant()` inside `withoutTenantScope()` had no isolation at all.** The F1 fix guarded one direction only: the bypass cleared `app.tenant_id`, but `withTenant()` did nothing about an active `app.bypass_tenant`, and every `owner_bypass_*` policy is PERMISSIVE — so it is OR'd with `tenant_isolation_*` and the tenant predicate narrowed nothing. Measured: a scoped block inside a bypass saw **every** tenant and a keyed cross-tenant `UPDATE` **committed**. The vulnerable shape was the natural reading of the `§2.10` pattern that had just been corrected, since that is where the tenant list is produced. | **NOT CLOSED.** Attempted: `withTenant()` refuses when the bypass is on. The refusal was placed *after* the re-entry short-circuit, so a second composition order reaches a fully unscoped block — see **H-A** in the fourth review below. The 2 regression tests cover only the shape that does refuse. |
| **M-3** | High in effect | The success-path GUC restore could itself raise `25P02`: a callback that *handled* a `QueryException` returns normally while the transaction is aborted, so the restore failed, discarded the callback's result, and put `SQL: select set_config(app.tenant_id, <previous tenant id>, true)` into the exception message — breaking `§4 › Edge & ops` "stack traces never carry SQL or bound parameters". Treated as blocking regardless of label, since `§4` opens "break any of these and the task is not done". | **NOT FIXED — replaced by a worse failure.** `restoreGuc()` cannot throw, which removed the only signal: the aborted transaction now commits as a silent rollback and the helper reports success. See **H-B** below. The regression test is discriminating for the leak, and pins the new defect in place by asserting only the return value. |
| **M-2** | Medium | Nothing checked that the locally built verifier was accepted *as a verifier*. Postgres silently re-salts anything it cannot parse, so a malformed verifier would make the role's live password the verifier string — which this code writes to the log. | Fixed, and the fourth review confirmed it discriminates by injecting both defects. An equality assertion on `pg_authid.rolpassword`, plus the comment that had argued the next reader out of exactly that check. Its recorded limits hold; one of its own hedges was pessimistic. |
| **M-1, M-4, L-1, L-2, L-3, L-5** | Med/Low | Accepted with owners and dates | **R-009** … **R-014** |

Also corrected from that review: the `25P02` justification in `TenantContext` was over-broad — a plain PHP
exception does **not** abort the Postgres transaction, measured; the rollback is what makes the throw path
safe. And the credential-redaction test reaches its path only through F5's `CONNECTION LIMIT`, which is now
noted in the test as a deliberate coupling so a future change to the limit does not silently disarm it.

What that review confirmed independently, having been given the diff and the earlier findings list only:
the SCRAM construction is **byte-identical** to Postgres 16.14 for the same salt and iteration count, field
by field, with `random_bytes(16)` matching `SCRAM_DEFAULT_SALT_LEN` and 4096 matching
`SCRAM_DEFAULT_ITERATIONS`; the SASLprep restriction is correct and only marginally too narrow; the `users`
and `password_reset_tokens` grants match the migration exactly with `relacl` NULL and nothing widened;
`REVOKE ALL … ON ALL TABLES` does clear `attacl`, so `down()` is still correct with column grants; the
save-and-restore leaves **no residue on any of eleven constructed paths**; and **every** regression test
added by the fix commits is discriminating, verified by ten reverts rather than by trusting the claim.

**A fourth review (2026-10-26), of the fix commit (`c757a99..46e2e3e`).** It found **2 High, 1 Medium, 5 Low — every one
of them in the fix commits themselves**. Both Highs were independently reproduced before being recorded
here. The pattern has now held four times: each review reads the previous review's fixes for the first time
and finds a High in them.

| # | Sev | Finding | State |
|---|---|---|---|
| **H-A** | **High** | **H-1 was never closed, only moved behind a second door.** The refusal added for H-1 sits *after* the same-tenant re-entry short-circuit, so `withTenant` → `withoutTenantScope` → `withTenant` on one connection returns `$callback()` without ever reaching it. Measured: 2 of 2 tenants read inside the "scoped" block, and a keyed cross-tenant `UPDATE` **committed**. Contributing cause: `withoutTenantScope()` clears `app.tenant_id` in Postgres but never touches `$this->tenantId`, so `current()` reports a tenant that is not in force and the short-circuit trusts that stale PHP state. Both H-1 regression tests exercise only the bypass-outermost shape, so they pass. | **CLOSED** — fifth round, structurally |
| **H-B** | **High** | **The M-3 fix traded a §4 prose breach for silent data loss.** `restoreGuc()` swallows, so a callback that catches a `QueryException` and returns leaves `$completed` true: Laravel issues `COMMIT`, Postgres silently converts it to `ROLLBACK`, and the helper returns the callback's value with **no exception anywhere**. Measured at transaction level 0: a write made before the swallowed error was gone afterwards. Concrete case — Task 3's canonical idempotency pattern, catch `23505` and treat as already-done (C5, "`markReady` is idempotent"), would return success while the status change, the outbox row **and** the audit row all roll back. On the bypass path the `audit_log` row the block writes is lost too, which **R-005 does not cover** (that row is scoped to a bypass that *throws*). Before the fix this failure was loud. | **CLOSED** — fifth round |
| **M-A** | Medium | The pre-check added for H-1 is itself an unwrapped statement, so on an already-aborted transaction it propagates `SQL: select coalesce(current_setting(app.bypass_tenant, true), ) as value` — a fresh breach of "stack traces never carry SQL or bound parameters", at the same call site M-3 was raised for. | **CLOSED** — fifth round |
| **L-A** | Low | The comment on the two restores in `withoutTenantScope()` asserts the opposite of what the code does: it claims the bypass is restored last "so nothing between these two statements sees a narrowed tenant with the bypass still open", and restoring tenant-first/bypass-last produces exactly that state. Restoring the bypass first is what fails safe. | **CLOSED** — fifth round, structurally |
| **L-B** | Low | `withoutTenantScope()` has no reciprocal guard against being opened inside a tenant scope, and leaves `current()` disagreeing with the database. Loaded gun for §4 › Realtime, where "every read derives that id from the caller's own context" — a Redis key namespaced from `current()` inside a bypass would be built from a tenant id that is not in force. | **CLOSED** — fifth round |
| **L-C** | Low | The refusal compares against `config('tenancy.bypass_value')` while the policies hard-code `'on'`. Diverge them and the PHP check stops firing while Postgres still grants the bypass: measured, 2 tenants visible inside a "scoped" block. Nothing pins the config value against the policy literal. | **CLOSED** — fifth round, structurally |
| **L-D** | Low | `withTenant()` now costs 5 statements per scope where it cost 4; both GUCs could be read in the one round trip that already exists. §6.5 cost per request. | **CLOSED** — fifth round |
| **L-E** | Low | Two claims written into *this file* by the previous round that measurement does not support. **R-014's mitigation text is wrong:** `RoleTimeoutTest`'s first test compares `current_setting` against *the same config value*, so it catches role-vs-config drift only — a far-too-high value **in config** passes it and is then waited out, not caught. And §4a's note that the M-3 test leaks `<previous tenant id>` overstates it: on that path the previous value is empty, so no tenant uuid leaks in the test cited. | **CLOSED** — fifth round |

**All eight were closed in the fifth round** (below) and none was moved into §5. They were never accepted
risks: they were unfixed findings on an untagged branch.

Also still unaddressed: three comments in `TenantContext.php` have now each asserted something false — the
§4 trap mechanism (corrected), the `25P02` justification (corrected), and the restore order (L-A, still
wrong). What survived the fourth review intact: the **M-2** fix, verified discriminating by defect injection,
with its recorded limits confirmed and one of its own hedges found pessimistic; the corrected `25P02`
reasoning on both halves; the §4a note that QA's H-1 has no discriminating regression test; and all six tests
added or renamed by the fix commit, revert-verified.

**The fifth round (2026-10-26) — the last patch round, and a structural change rather than a sixth guard.**
Head `7bcfdb3`. Four rounds had each added one more order-dependent check to `run()` and the next
composition walked around it, so the first change was not a fix for any listed finding:

**`app.tenant_id` on the target connection is now the only source of truth.** The PHP mirror is gone and
`current()` reads the GUC. That was chosen over making the mirror follow the GUC, because a mirror that
agrees today can diverge tomorrow and that divergence *was* H-A: `withoutTenantScope()` cleared the GUC in
Postgres, left `$this->tenantId` set, and the re-entry short-circuit trusted the stale field. There is no
field left to go stale. `$openScopes` is not a mirror — it records which connections the object opened a
scope on, is read only to refuse cross-connection reuse, cannot affect which tenant is in force, and a stale
entry can only make the class stricter.

| # | Closed how | Proven by revert |
|---|---|---|
| **H-A** | Structural, three ways: the mirror is gone; the bypass check runs **first**, above every short-circuit; and `withoutTenantScope()` refuses while a tenant context is open — both the GUC on its own connection and `$openScopes` on any connection, both failing closed. | Bypass check moved below re-entry → the order test fails. The two bypass-inside-scope guards removed → four tests fail. |
| **H-B** | The clear is loud again, and sanitised: a `TenantContextException` with no statement text, no bindings, no chained previous. | `setGuc` swallowing → the durability test fails with *"returned 'callback-finished' and the write is gone"*. |
| **M-A** | All four statement sites — `setGuc`, `readContext`, `currentRole`, `insertBypassAudit` — convert a driver error into a sanitised exception. | Both catches rethrowing raw → the two sanitisation tests error with the unsanitised message. |
| **L-A** | Structurally gone: there is no restore order left. `app.tenant_id` is `''` when a bypass opens (the bypass refuses inside a scope) and stays `''` (`run()` refuses inside a bypass), so only the bypass is restored and the "narrowed tenant with the bypass still open" state is unreachable rather than ordered against. | n/a — the second statement no longer exists. |
| **L-B** | `withoutTenantScope()` has the reciprocal guard, and `current()` reads the GUC so it can no longer disagree with the database. | Guards removed → four tests fail. |
| **L-C** | Structural: the refusal fires on **any** non-empty bypass GUC, so no configuration value can disarm it. A separate test pins the value used to *set* the bypass against the literal every `owner_bypass_*` policy compares against, read from `pg_policies`. | Config diverged from the policy literal → the pin fails. |
| **L-D** | `readContext()` reads both GUCs in one round trip. | n/a — a cost change, not a behaviour change. |
| **L-E** | Doc corrections, including **R-014's mitigation text, now reworded** to what measurement supports rather than flagged as disputed. | n/a |

**The composition matrix.** `TenantContextCompositionTest` walks every composition of the two helpers —
both nesting directions, same connection and cross connection, with and without an outer transaction —
because every previous round guarded the one shape its author imagined. Shapes covered: re-entry with the
same tenant; a different tenant; cross-connection reuse; bypass inside a scope, same and cross connection;
scope inside a bypass, same and cross connection; bypass inside a bypass; the `scope → bypass → scope`
shape H-A used; both refusals inside an outer transaction with a residue check; correct scoping inside an
outer transaction; and a fix-agnostic sweep asserting that **no** nesting of the two yields a block that can
read or write another tenant. The matrix found one of its own shape definitions wrong on the first run.

**Postgres behaviour asserted in the new comments was measured in this session, none carried over:** a full
`ROLLBACK` leaves a transaction-local setting empty; `ROLLBACK TO SAVEPOINT` restores the value held when
the savepoint was taken; `owner_bypass_*` and `tenant_isolation_*` are both `PERMISSIVE` and `FOR ALL`, so
Postgres ORs them; a plain PHP exception does not abort the transaction while a caught `QueryException`
does, with `25P02`.

**Not claimed:** `insertBypassAudit()`'s catch (`TenantContext.php:248-249`) is the one uncovered line pair
in the class. Forcing the audit insert specifically to fail needs the earlier statements on the same
connection to succeed first, which could not be arranged without reaching around the helper. It is the same
shape as the two sanitisation paths that *are* covered.

**Also fixed, found by the new matrix:** `TestCase::makeTenant()` built its slug from the first 16 hex
digits of a `uuid7`, and Laravel's `uuid7` keeps its first **25** stable inside one millisecond, so two
tenants made back to back collided on `tenants_slug_unique`. A live flake in the gate — the same class as
F10 — now using the full id.

**Bugs found and fixed in Task 1 — each with a regression test that fails without the fix:**

1. **`assertSafeRuntimeRole()` missed a role that could `SET ROLE` to the owner.** It asked
   `pg_has_role(…, 'USAGE')`, but the runtime roles are `NOINHERIT`, so a role granted membership of
   `qrpager_owner` reported no inherited privilege at all while still being able to become it. Now
   `'MEMBER'`. The test grants the membership for real and revokes it in a `finally`.
2. **The suite exhausted Postgres connection slots at around test 100** (`08006`, "remaining connection
   slots are reserved for roles with the SUPERUSER attribute"). Each test boots its own application and
   opens up to five connections; nothing closed them. `TestCase::tearDown()` now purges, and
   `ConnectionHygieneTest` covers both halves.
3. **`db:bootstrap` granted schema privileges on the root connection's own database**, not on the one it
   had just created. Harmless while both read `DB_DATABASE`, and silently wrong the moment they differ —
   it would report success and leave the application unable to connect. It now connects as root *to the
   target database*; the test bootstraps a scratch database and checks the grants landed there.
4. **`format()` with untyped placeholders → `42P18`** ("could not determine data type of parameter"):
   `format()` is variadic `"any"`, so every binding needs `?::text`. Covered by the bootstrap tests.

## 5. Accepted risks

| # | Risk | Severity | Why accepted | Compensating control | Owner | Review by |
|---|---|---|---|---|---|---|
| **R-001** | **`main` is not actually protected.** GitHub rulesets are not enforced on a private repository under an individual plan ("won't be enforced on this private repository until you move to GitHub Team"). Branch protection is configured but inert, so a direct push to `main` succeeds and can bypass PR review. | Medium | Single developer. The core of `PROJECT_INSTRUCTIONS.md §11` is that QA and Security reviewers run in **separate agent sessions without the builder's reasoning** — that is enforced by process, not by a GitHub button. CI runs on every push to `main` (proven), so a broken merge is caught within a minute. Paying for GitHub Team before the first paying tenant is not justified. | **Working rule, binding:** one task = one branch = one PR. Never commit to `main` directly. Never merge until both required checks are green and the §11 QA + Security reviews have run. Squash merge only. | Muhammad | At client handover (Transfer Ownership to an Organization, where rulesets are enforced) **or** when a second developer joins — whichever is first. |

| **R-002** | **PHPStan no longer analyses Pest test bodies.** Pest binds `$this` inside a test closure at runtime, so PHPStan types it as `Pest\PendingCalls\TestCall` and every `$this->makeTenant()` in a test body is an "undefined method" — 182 such errors once this task's suite existed. `phpstan.neon` excludes `tests/**/*Test.php`. | Low | The test *infrastructure* — `tests/TestCase.php` and `tests/Support` — is still analysed at level 8, and that is where the logic lives. A typo in a closure fails the moment `php artisan test` runs, which is every gate, so the feedback loop is seconds either way. The alternative was a stub that lies about Pest's own types, which would mask real errors. | The exclusion is three explicit globs in `phpstan.neon`, visible in any diff that widens them, with the reason written beside them. | Muhammad | When a Pest/PHPStan integration for closure binding exists, or at Task 12 (SAST), whichever is first. |

| **R-003** | **The worker's two cross-tenant `pager_sessions` policies are bounded by time alone.** `worker_reads_expired_sessions USING (expires_at <= now())` and `worker_expires_sessions USING (expires_at <= now()) WITH CHECK (status = 'expired')`. `expires_at` only recedes into the past, so the worker holds a permanent, context-free, cross-tenant **read** of every historical session (everything but `token_hash`) and a cross-tenant **write** over every terminal row — it can mark another tenant's `completed` order `expired`, with no audit row. F3, security review of task-1. | Medium | Not reachable without a leaked `qrpager_worker` credential or a bug in the worker Task 4 builds, and no worker exists yet. Narrowing it is **not blocked by Postgres**: measured on 16.14, a status **whitelist that includes the value the update writes** — `status IN ('waiting','ready','expired')` on SELECT, `status IN ('waiting','ready')` on UPDATE — keeps the prescribed `SELECT id → UPDATE … WHERE id = ? AND status = ?` pattern working and still refuses any other transition with `42501`. It is left alone here only because the task that owns the expiry job should narrow the policy and re-prove the job in the same change. | `worker_expires_sessions`'s `WITH CHECK (status = 'expired')` still pins the only transition the worker may make without context, and `WorkerScopeTest` asserts every other cross-tenant transition raises `42501`. The worker holds no `INSERT` or `DELETE` on `pager_sessions` at all. | Task 3 (`QRPAGER-10`), which owns the expiry job. Decided 2026-10-25: the finding was first dispositioned to Task 4, which contradicted `§3` row 3 — that row carries the expiry job and its gate clause verbatim — so the plan won (rank 3, `§0`). Narrowing the policies in Task 4 would have meant Task 3 building the expiry job against the un-narrowed ones and Task 4 changing them underneath it. | **At Task 3's acceptance gate**: the two policies are narrowed and the expiry job re-proven in the same change, not in a later task |

| **R-004** | **`log_statement=all` is set cluster-wide in `compose.yaml`, and from Task 3 it will log every QR token-hash binding.** It already logged the role passwords twice per bootstrap until F4 moved them off the wire. F8, security review of task-1. | Low | Local only: `compose.yaml` binds Postgres to `127.0.0.1` and the setting exists for Task 4's "polling endpoint touches zero Postgres queries" proof, which is a real gate clause. The hash is not the token, so logging it is not logging a credential — but it is tenant data in a log nobody redacts. | Written down here rather than remembered, which is the control that matters: the next person to widen the deployment has a line to find. `§4 › Data` already forbids the raw token from any log. | Task 3 (`QRPAGER-10`), which writes the first token | **Before Task 3 writes its first token**, and Task 4 scopes the setting per-session rather than cluster-wide |

| **R-005** | **A bypass that fails leaves no trace.** `withoutTenantScope()` writes its `audit_log` row inside the same transaction as the work, so a committed bypass always carries its audit row — and a bypass that **throws** rolls that row back with the work. An attacker-triggered or buggy bypass that errors is therefore invisible. F12, raised by the task-1 security review. | Medium | The detection gap only matters once something can trigger a bypass from outside, and nothing can: `withoutTenantScope()` is called by no production code path in Task 1, only by tests and the `§2.10` migration pattern. Fixing it properly means a second connection or a log sink, which is logging infrastructure Task 2 owns — doing it here would mean inventing that infrastructure in the database task. | The misleading comment that claimed a bypass "cannot happen silently" is corrected in both `TenantContext.php` and `§2.10`, so nobody relies on a guarantee that does not hold. The bypass still refuses any role but the owner. | Task 2 (`QRPAGER-9`), which owns logging and error mapping | **At Task 2's acceptance gate** |

| **R-006** | **`qrpager_app` can still read and write `users.password` and `users.remember_token`, with no tenant context required.** `users` is global, so no RLS stands behind it: the role can read every platform admin's and every tenant's staff credentials in one statement, and `UPDATE users SET password = …` is a direct authentication bypass. Residual half of F2, security review of task-1. | Medium | Task 1 did what it can at this layer: the grants are now stated per column and pinned as exact sets, and the worker lost credential access entirely. The real fix needs an actor identity to scope rows to "your own user", which would be a **third `app.*` GUC** — forbidden by C1 without editing `PROJECT_INSTRUCTIONS.md` first — or the `§2.8` `SECURITY DEFINER` login function, which belongs with the authentication code. Not reachable today: no HTTP surface, no query layer, no `User` read path exists. | `GrantMatrixTest` now pins the `users` and `password_reset_tokens` grants as exact column sets **and** asserts they are column grants rather than table-wide, so any widening appears in a diff. `#[Hidden(['password','remember_token'])]` keeps both out of serialisation. | Task 5 (`QRPAGER-12`) | **At Task 5's acceptance gate**, which must carry: "`qrpager_app` holds no `SELECT` on `users.password` or `users.remember_token`; authentication reads the hash only through a named `SECURITY DEFINER` function granted to `qrpager_app` alone, proven by a test that the role gets `42501` on `select password from users`" |

| **R-007** | **The tenant role matrix is entirely application-enforced, and `users_email_is_lowercase` surfaces as a raw `23514`.** `qrpager_app` holds table-wide `INSERT`/`DELETE` and `UPDATE (role, updated_at)` on `tenant_users`, so within its own tenant a broken authorisation check can promote a `staff` row to `owner` with nothing underneath it. Separately, the `CHECK (email = lower(email))` constraint turns any un-normalised email into a driver error. F11, security review of task-1. | Low | Correct for this layer: the database cannot know who the actor is, so it cannot distinguish a legitimate promotion from an illegitimate one. RLS still confines the damage to one tenant — this is not a cross-tenant path. | `GrantMatrixTest` asserts no role holds `UPDATE` on any `tenant_id` or on `tenants.id`, so a row cannot be moved between tenants whatever the role column says. | Task 5 (`QRPAGER-12`) for the role matrix and the lower-casing; Task 2 (`QRPAGER-9`) for mapping `23514` without returning the constraint name or the SQL | **At each of those tasks' acceptance gates** |

| **R-008** | **`sslmode` is `require`, not `verify-full`: the transport is encrypted but the server is not authenticated.** `require` accepts any certificate, so it stops passive interception and not an active man-in-the-middle. Residual half of F7, security review of task-1. | Low | `require` closes the actual finding, which was `prefer` silently downgrading to plaintext. `verify-full` additionally needs an RDS CA bundle shipped into the image and a hostname that matches the certificate — deployment work with no deployment to do it against, since nothing is hosted yet. | The default now **fails closed**: a deployment that sets nothing gets `require` and a server without TLS is refused rather than silently accepted. `ConnectionHygieneTest` asserts the unset default across all five connections, and the local opt-out is one explicit `DB_SSLMODE=disable` in `.env.example` and in CI. | Task 13 (`QRPAGER-20`), which owns deployment sizing and configuration | **Before the first deployed environment exists** |

| **R-009** | **The role password verifier is written to the Postgres log on every bootstrap.** F4 moved the *plaintext* off the wire; it did not stop a credential reaching the log. Measured across one `db:bootstrap`: 110 new container-log lines, **0** occurrences of the plaintext and **6** of `SCRAM-SHA-256$4096:…` — twice per role, once as the bound parameter `format()` sends and once in the `ALTER ROLE` statement text. The verifier is the complete `pg_authid` secret: `ServerKey` lets an attacker complete a SCRAM handshake **as the server** (and `sslmode=require` does not authenticate the server — R-008), and `StoredKey` enables offline password recovery at 4096 PBKDF2-SHA256 rounds. M-1, scoped security review of the task-1 fixes. | Medium | `log_statement=all` is local-only (R-004) and Postgres is bound to `127.0.0.1`; the exposure in a deployed environment depends on a log configuration that does not exist yet. The verifier is strictly less dangerous than the plaintext it replaced — it is not password-equivalent for client authentication — so this is a reduction, not a regression. The fix is a one-line `SET log_statement = 'none'` on the bootstrap session, which was measured to suppress it completely, but it belongs with the task that decides what the application logs at all. | `§4a` and the `TenantContext`/`RoleBootstrapper` comments no longer claim the credential is out of the log; they say what reaches it and how often. `db:bootstrap` is not a request path and runs once per deploy. | Task 2 (`QRPAGER-9`), which owns logging and redaction | **At Task 2's acceptance gate**, together with the §4 "no secrets in logs" clause it already carries |
| **R-010** | **The §4 repo guard that enforces C1 and C2 mechanically does not exist.** `PROJECT_INSTRUCTIONS.md §4 › Postgres traps` states it as a fact — *"A **repo guard in lint** rejects session-scoped tenant settings, a raw `SET app.*`, and any `app.*` name not in C1. C1 and C2 are enforced mechanically, not by review. The guard must be proven to fail on a deliberately bad file."* There is no such step: `composer.json` has `pint`, `stan`, `test`; CI adds gitleaks, migrate-twice, rollback-fingerprint and coverage; no test reads source files. M-4, scoped security review of the task-1 fixes. | Medium | The repo is compliant today and that was verified by hand, twice: exactly two `app.*` names exist (`config/tenancy.php`), `set_config` is called from exactly one file, and every call passes `true`. So the invariant holds — it is the *enforcement* that is missing, and §4 says enforcement must not be left to a reviewer. Pre-existing: not introduced by the task-1 fixes, though those fixes added two more `set_config` call sites under no guard. **Note the gap is in the wording as much as the work** — §4 describes a control that has never existed, so a reader trusts a guard that is not there. | Three reviews have now checked C1/C2 by hand and found no violation. The surface is one file. | Task 2 (`QRPAGER-9`), whose gate already names the repo guard | **At Task 2's acceptance gate.** §4's wording is a rank-2 edit and should be corrected in the same change, so it stops asserting a control that does not exist |
| **R-011** | **`.env.example` ships `DB_SSLMODE=disable`, and `cp .env.example .env` is the documented bootstrap.** The code default is now `require` and genuinely fails closed — verified that `require` and `verify-full` are refused by the TLS-less compose Postgres while `disable` and `prefer` connect — but any environment started from the template inherits plaintext transport. That is F7 with one extra step, and not "visible in a diff" as the template comment claims. L-1, scoped security review of the task-1 fixes. | Low | The template is for local development against a Postgres bound to `127.0.0.1`, which has no TLS to negotiate; without the line a fresh clone cannot run the gates at all. Nothing is deployed, and no deployment inherits `.env.example` — deployments get their environment from Secrets Manager (`§2.1`). | The default is fail-closed, so an environment that sets *nothing* is refused rather than silently downgraded. | Task 13 (`QRPAGER-20`), with R-008 | **Before the first deployed environment exists.** The companion control is a boot refusal of `sslmode` in `{disable, allow, prefer}` outside `local`/`testing`, which pairs with Task 2 wiring `assertSafeRuntimeRole()` |
| **R-012** | **The per-role connection limits are sized against a local `max_connections` with no deploy-time check, and the app's limit can starve a health check.** 10 + 40 + 20 = 70 against a local `max_connections` of 100 with 3 reserved. Aurora derives `max_connections` from instance class, so on a small instance the sum can exceed capacity and the limits become decorative with nothing noticing. Separately, capping `qrpager_app` at 40 sharpens the §4 load-shedding clause rather than softening it: once 40 app connections are held, a readiness endpoint that queries Postgres **as `qrpager_app`** also fails — the cascade §4 forbids. L-2, scoped security review of the task-1 fixes. | Low | The limits are applied, match config and fit the local cluster, which is what Task 1 can demonstrate. Instance sizing is Task 13's by definition and the endpoints are Task 2's; neither exists. | `BootstrapDatabaseCommandTest` asserts the limits match config and that their sum leaves headroom above `superuser_reserved_connections` on the cluster being bootstrapped. | Task 13 (`QRPAGER-20`) for the sizing check; Task 2 (`QRPAGER-9`) for the readiness coupling | **At each task's gate.** Task 13: `db:bootstrap` refuses when `sum(limits) >= max_connections - superuser_reserved_connections`. Task 2: readiness answers with the app role's pool exhausted |
| **R-013** | **`withoutLeakingCredentials()` discards every diagnostic, including for failures that carry no credential.** It catches `Throwable`, keeps only `getCode()` and deliberately does not chain. For a `QueryException` that leaves the SQLSTATE, but `format()`'s own `RuntimeException` has code `0`, which the formatter suppresses — so a connectivity or configuration failure reports only *"Could not create or alter role [qrpager_app]. The statement text is withheld…"* and nothing else. L-3, scoped security review of the task-1 fixes. | Low | Operability, not security: the redaction is correct and deliberately total. `db:bootstrap` is run by a human or a deploy step with the Postgres log beside it, not by a request. | The message always names the role, so the failure is localised even without the cause. | Task 2 (`QRPAGER-9`), which owns error mapping to real driver codes | **At Task 2's acceptance gate**, where the message should distinguish "the statement failed" from "the statement was never built" |
| **R-014** | **Removing the wall-clock upper bounds from the timeout proofs trades a flaky assertion for an unbounded hang.** F10's fix is right in principle and the remaining assertions are discriminating — disabling `statement_timeout` in config makes the `57014` assertion fail. But the removed upper bound was the only thing that would catch a timeout configured far too high; such a value now hangs the suite until the harness kills it. L-5, scoped security review of the task-1 fixes. | Low | Residual risk is small: the `SHOW`-comparison test in the same file pins the configured value against config, so a wrong value fails there first and fast. A hang is also unmistakable, where the old upper bound produced a *misleading* red on a loaded host — which is what made `§4a` record "135 passed" for a suite that was 134 passed and 1 failed. | **Reworded 2026-10-26 (L-E).** The earlier text here claimed `RoleTimeoutTest`'s first test catches a far-too-high value by equality. It does not: that test compares `current_setting` against the **same** config value, so it catches role-vs-config drift only. A far-too-high value *in config* passes it and is then waited out by the firing proof — which is the hang this row is about. There is no compensating control today beyond the configured values being short, visible in `config/tenancy.php`, and in the diff of any change to them. | Task 2 (`QRPAGER-9`), the next task to touch this suite and the timeouts' load-shedding clause | **At Task 2's acceptance gate** |
_Medium/Low review findings that are not fixed land here with an owner and a date._

---

## 6. Open items

- Billing / subscriptions: not in the Blueprint and not requested by the client. **Out of scope until stated.**
- Launch tier: unset by design until Task 14.
- **Queue transport for push dispatch.** The Blueprint specified SQS. The design is an outbox table plus a worker, which satisfies the guarantee — "ready" commits instantly, the send happens behind it with retries — independently of transport. Laravel's Redis queue is the default. **Task 13 decides whether the launch tier needs SQS instead, and Task 14 measures it.** Do not assume either.
- **Data migrations under FORCE RLS — settled, and corrected.** The pattern is `PLAN.md §2.10`. It was wrong until 2026-10-25 (H-1, QA review of task-1): it opened by enumerating tenants with a plain owner read, which returns **zero rows** under FORCE RLS, so the loop body never ran and a backfill would have silently done nothing. The list now comes through one audited `withoutTenantScope()` read; the per-tenant work still needs no bypass. `DataMigrationPatternTest` proves both halves, including at transaction level 1, which is where every migration body runs.
- **`outbox` and `audit_log` retention is unassigned.** Both grow without bound (§2.11): no grant deletes a `sent` or `dead` outbox row, and `audit_log` is append-only, so pruning it can only ever be a partition drop, never a `DELETE`. Nothing breaks before launch; it is a disk bill that grows. **Decide in Task 13 (storage sizing) and measure in Task 14.** Do not let the first person who notices invent a `DELETE`, because the trigger will refuse it.
- **Platform-admin cross-tenant reads are not on the §4 named list.** Task 6 builds a platform dashboard, a restaurants list and an audit-log viewer, all of which read across tenants. `§4 › Tenancy` names exactly four cross-tenant paths and says "adding one requires editing this file first". Task 1 deliberately built **no** policy for these: `qrpager_app` has no cross-tenant policy anywhere. Task 6 must either get §4 amended (a rank-2 edit, so the user's call) or go through §2.8's `SECURITY DEFINER` recipe with its own authorisation. **Raised now so Task 6 does not discover it mid-wizard.**
- **Platform-scope `audit_log` rows (`tenant_id` NULL) can currently be written only through the owner bypass.** `app` holds `INSERT` but the isolation policy's `WITH CHECK` compares `tenant_id` to the context, and `NULL = x` is never true. Task 6's "platform admin actions all land in `audit_log`" therefore needs a decision: an `INSERT`-only policy allowing `tenant_id IS NULL` (write-only for the app, readable only through §2.8), or every platform action naming a tenant. Not invented here.
- **Laravel wraps the driver error.** `QueryException::getCode()` is not reliably the SQLSTATE; the real code is on the PDO exception underneath, at `getPrevious()->errorInfo[0]`. `tests/TestCase.php::sqlState()` has the working shape. Task 2 owns error mapping and should start there rather than re-finding it.

### Deliberately different from the Blueprint — recorded so nobody "fixes" it

| The Blueprint said | We do not | Why |
|---|---|---|
| Split the notification service from the API | One Laravel application | That split existed to stop SSE connection load from taking down order handling. **O2 removed SSE.** Web Push needs no open connection, so the load it protected against does not exist. Revisit only if Task 14 measures API contention. |
| Alarms and dashboards in CloudWatch, X-Ray **and Grafana** | CloudWatch + X-Ray only | Three observability surfaces for one product is three places to look during an incident. Add Grafana only if a named need appears. |
| Redis in cluster mode, with a channel per instance | Single ElastiCache, no pub/sub fan-out | That design existed to fan SSE events out to every instance holding connections. **O2 removed it.** Cluster mode for the status projection is a **Task 13 sizing decision measured in Task 14**, not an architectural one. |

### Deliberately excluded — do not re-add without editing this file

Recorded because each appears in the source material and will otherwise be
"rediscovered" as a missing feature by a future chat.

| Excluded | Where it came from | Why it is out |
|---|---|---|
| SSE hub, `Last-Event-ID`, heartbeat, connection caps, 503 shedding on a stream, Redis pub/sub with a channel per instance | Blueprint realtime design | Cancelled by **O2**. No persistent connection at launch. |
| Wait-time prediction per tenant | "Ideas that would make it unique" | An idea, never agreed scope |
| Multi-channel fallback: push → SMS → WhatsApp | Same | Same. Would also reintroduce storing phone numbers, which `§4 › Data` forbids. |
| POS webhooks / `/orders/:ref/ready` integration | Same, and a reference repo's feature | An idea, never agreed scope |
| Group pagers and table pagers | Same | An idea, never agreed scope |
| Testcontainers | Blueprint testing section | Superseded: tests run against the real composed Postgres and Redis, which is the same guarantee with less machinery |
