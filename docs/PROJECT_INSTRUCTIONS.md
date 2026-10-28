# PROJECT INSTRUCTIONS — QR Pager SaaS

## 0. PRECEDENCE — read before anything else, every chat
On conflict, the higher number wins. **Never silently resolve a conflict. Stop and report it.**

1. The user's message in the current chat
2. This file
3. `docs/PLAN.md` in the repo (living decision log + task table)
4. `docs/BLUEPRINT.md` — **an archive**, not the original. The original Blueprint v2.0 no longer exists; only a conversation transcript quoting parts of it survives. The archive contains exactly what that transcript preserves, and lists in its §9 what is known to be missing.

The Blueprint is a **binding baseline**, not a suggestion. Only the overrides in §2 cancel parts of it.
Anything not covered by 1–3 follows the Blueprint. Do not invent alternatives.

Everything in the archive that is still binding has already been absorbed into this file and `PLAN.md` — its §8 maps each part to where it now lives. Read it for **provenance**, to check what the original actually said. If it is silent on something, that is a gap to **ask the client about**, never to fill in. Do not reconstruct a missing section from memory (§8).

---

## 1. LOCKED STACK — no substitution without a written ADR

| Layer | Locked |
|---|---|
| Backend | Laravel 13, PHP 8.4 |
| Admin panel | Filament 5 |
| Frontend | Next.js 15 — customer PWA + staff dashboard/KDS |
| Database | PostgreSQL 16, RLS forced |
| Cache / queue | Redis 7 |
| Auth | Fortify + Sanctum + WebAuthn passkeys + 2FA |
| Runtime | Octane (FrankenPHP) |
| Push | `minishlink/web-push` (VAPID) |
| Object storage | S3 (`league/flysystem-aws-s3-v3`); MinIO locally — see `docs/adr/002-storage-and-mail.md` |
| Transactional mail | Amazon SES; Mailpit locally — see `docs/adr/002-storage-and-mail.md` |
| Monorepo | pnpm workspaces — `apps/api` (Laravel), `apps/web` (PWA + staff), `apps/site` (public renderer), `packages/{tokens,ui,contracts}` — see `docs/adr/003-monorepo-and-frontend-tooling.md` |
| Tests | PHP: Pest 5 + PHPUnit 13 (real Postgres + Redis) — see `docs/adr/001-pest-5.md`. TypeScript: **Vitest**. E2E: Playwright. Load: k6 |
| IaC | Terraform — VPC (3 AZ), ECS Fargate, Aurora PG (PITR), ElastiCache, S3, SES, ALB, CloudFront, WAF, Shield, Secrets Manager, CloudWatch + X-Ray |
| CI/CD | GitHub Actions + OIDC to AWS (no stored keys) |

Verify every library API by running code in-session. Never from memory. If a version has moved, read the primary changelog before choosing.

---

## 2. OVERRIDES TO THE BLUEPRINT — only these two

| # | Blueprint said | Now |
|---|---|---|
| **O1** | NestJS + Drizzle + tRPC + Better Auth | **Laravel 13 owns the entire backend.** Those four are removed. |
| **O2** | SSE hub as the live channel | **No persistent connection.** Web Push is the primary channel; short polling is the live-status fallback. Reverb may be added later as an enhancement only — never as a dependency. |

Everything else in the Blueprint stands: RLS model, DB roles, outbox pattern, QR token design, task gates §5, universal gate §6, department checklists §7, security posture, scale targets.

---

## 3. BLUEPRINT CONTRADICTIONS — RESOLVED. DO NOT RE-LITIGATE.

| ID | Conflict | Resolution |
|---|---|---|
| C1 | `app.current_tenant_id` vs `app.tenant_id` | **`app.tenant_id`** is the only tenant GUC. Bypass GUC is **`app.bypass_tenant`**. No other `app.*` setting may be introduced without editing this file. |
| C2 | `SET LOCAL app.x = $1` | **Forbidden** — Postgres `SET` takes no bind parameters. Always `SELECT set_config('app.tenant_id', ?, true)`. Third arg is always `true` (transaction-scoped). A session-scoped tenant setting is a hard failure. |
| C3 | Blueprint §3.1 schema vs the schema actually built | §3.1 is **superseded**. Source of truth = the Laravel migrations in the repo. The real table list must be transcribed into `PLAN.md §2` before Task 2 starts. |
| C4 | RLS policy covers `orders` only | RLS `ENABLE` **and** `FORCE` on **every** tenant table, no exception. A meta-test fails the build if any table lacks either. |
| C5 | Who sends Web Push | The **outbox queue worker**, never the HTTP request. The request only commits status + outbox row inside one transaction. |
| C6 | 100K vs 300K | Design ceiling **300K concurrent active sessions**. The launch tier is set by **Task 14** load results, never by assumption. (The Blueprint said "Task 9" under its own 10-task numbering; `PLAN.md §3` has 15 tasks and load testing is Task 14.) |
| C7 | §5 task table duplicated verbatim | One task table only, in `PLAN.md`. This file never duplicates it. |

---

## 4. NON-NEGOTIABLE INVARIANTS
Break any of these and the task is not done.

### Tenancy
- Three DB roles: `owner` (migrations only), `app` (web), `worker` (queue). `app` and `worker` cannot run DDL, cannot `BYPASSRLS`, cannot become owner.
- The app **refuses to boot** on an unsafe role: exit 1, reason logged.
- Tenant context is set per transaction and must never leak across a pooled connection. A test proves this.
- Cross-tenant reads exist **only** on these named paths: QR token lookup, a user's own memberships, the outbox queue, the expiry job. Adding one requires editing this file first.
- **Two authentication surfaces, never confused.** Staff and admins authenticate as **users** (Task 5). A customer has no account and authenticates **only** by presenting the QR token, which resolves to exactly one session in exactly one tenant — built in Task 3, consumed by Task 4. A customer-facing route that accepts anything other than that token is a rejected design.
- **Before Task 5 exists**, every route built in Tasks 2-4 is either on the customer token path above, or guarded by Task 2's deny-by-default guard with a **test-only** authentication stub that is deleted in Task 5. A stub that survives Task 5 is a Critical finding.
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

### Data
- QR token: 128-bit CSPRNG, **stored only as a hash**, TTL 2 hours. Never logged, never in a URL query string, never in an error body.
- No customer accounts. No phone numbers stored.
- `audit_log` is append-only, enforced by trigger on UPDATE / DELETE / TRUNCATE — superuser included.

### Postgres traps — already paid for, do not rediscover
- **The worker expiry UPDATE must leave the new row visible to the worker's own SELECT policy.** Measured on 16.14: what pulls the SELECT policies in is **any column of the table referenced by the statement** — in `WHERE`, in a `SET` expression, or in the `RETURNING` list — because that is what makes the statement require `SELECT` privilege. Postgres then applies them twice: to the rows the statement may find (a row invisible to `SELECT` is **silently skipped** — `UPDATE 0`, never an error) and to the **new** row (`42501` if the update would hide it). `RETURNING` is neither necessary nor sufficient: a bare `WHERE id = ?` triggers both passes with no `RETURNING` at all, `RETURNING 1` triggers no new-row check, `RETURNING id` does. So the worker's cross-tenant SELECT policy is bounded by **time plus a status whitelist that contains the status the update writes** — never by a predicate the update falsifies, which would fail every expiry. Pattern: a **policy-limited `SELECT` that reads the keys** → `UPDATE ... WHERE id = ? AND status = ?` → check affected rows. Keep **no `RETURNING`**: it is a convention, not the mechanism — it preserves the affected-row count as the idempotency signal and keeps the worker's read surface narrow. (Reworded from "`SELECT id` (policy-limited)" on 2026-10-27 with the user's approval — rank 2, `§0` — from Task 3's `§11` QA review, finding L-2 / `PLAN.md R-065`. **The trap's point was never the column count; it is `SELECT`-then-keyed-`UPDATE` instead of a bulk statement**, which is the very next bullet. Read literally, "`SELECT id`" forbade the two columns the pattern itself needs: the keyed `UPDATE` needs **the status it expects to find**, because that guard IS the idempotency signal this bullet ends on, and a worker that must write an audit row needs **the tenant id**, because `audit_log` carries the same isolation policy and the insert is refused with `42501` outside a scope. So the real expiry job reads `id, tenant_id, status`; both extra columns are already in the worker's `SELECT` grant and inside its policy whitelist, so the wider select list costs no reach — and guessing either instead would mean a `WHERE` that cannot be written.)
- **A bulk `UPDATE` or `DELETE` that references no column of the table escapes RLS row filtering entirely** and writes rows the role cannot read. With no `WHERE` and only constants in `SET`, the statement needs no `SELECT` privilege, so neither pass above applies and the only limit left is the UPDATE policy's own `USING`. Measured: `UPDATE t SET status = 'expired'` wrote a row the role's SELECT policy hid, while the same statement with `WHERE id = ?` reported `UPDATE 0`. From any role, owner included, never write a bulk statement against a tenant table — a data migration running as `owner` under FORCE RLS is the most likely place this bites. Reference the key and check the affected-row count.
- Error mapping must read the **real driver code**: `42501` RLS denied, `23505` unique, `23514` check, `23503` FK.
- Role bootstrap races when suites run in parallel. Serialize with `pg_advisory_lock`.
- A **repo guard in CI** rejects session-scoped tenant settings, a raw `SET app.*`, and any `app.*` name not in C1. C1 and C2 are enforced mechanically, not by review. The guard must be proven to fail on a deliberately bad file. (Reworded from "in lint" on 2026-10-26 with the user's approval — rank 2, `§0` — when the guard was built at Task 2 and `R-010` closed. Nothing in lint can see it: Pint formats and PHPStan analyses the AST, while the value this rule is about is `false` inside the SQL string `'select set_config(?, ?, false)'`. The guard is a source scan over string literals, run by `composer guard` and as its own CI step.)
- **Data migrations run as `owner` under FORCE RLS**, so the owner sees no rows either. Any migration that touches data must set tenant context per tenant, or use a named, audited bypass. Discovered and documented on the lost build; it blocks the first data migration anyone writes.

### Realtime (post-O2)
- `markReady` is **idempotent**. A retry never double-notifies.
- Polling endpoint: **Redis-only read, zero Postgres queries**, p99 under 5 ms, client-side jittered interval + exponential backoff, short-TTL cache headers. A polling endpoint that touches Postgres is a rejected design.
- **Redis carries no RLS.** Every key that holds tenant data is namespaced by tenant id, and every read derives that id from the caller's own context — never from client input. A projection key that is not tenant-namespaced is a cross-tenant read with none of Postgres's protection behind it.
- Exactly one Redis key may be keyed by a secret the caller must present rather than by tenant id: the QR token pointer, whose key name is the stored token hash and whose value carries the tenant. Added 2026-10-27 after R-074 and the Task 4 Security review. No second such key may be introduced without editing this line.
- Web Push dispatcher prunes subscriptions on 404 / 410.
- Outbox worker: `FOR UPDATE SKIP LOCKED`, backoff, dead-letter. Killing it mid-batch loses nothing and duplicates nothing.
- iOS: Web Push only works from a Home Screen install. The install guidance is a required product path, not a nice-to-have.

### Edge & ops
- The QR landing page is static and CloudFront-cached. A scan burst must never reach origin.
- Rate limits per IP **and** per tenant. Load shedding drops nice-to-haves (live timer, analytics) before alerts.
- Load shedding is **implemented, not just stated**: a fast 503 when the DB connection pool is saturated, and a 503 under memory or CPU pressure — while **health and readiness endpoints keep answering**. A health check that sheds with everything else makes the load balancer kill a task that is merely busy, and the pressure moves to its neighbours. That is a cascade, not shedding.
- Every dependency has a declared **fail-open or fail-closed** posture, chosen deliberately and tested by taking the dependency down. "It stayed up in testing" is not a posture.
- No secrets in logs, **or in an error response body, at any `APP_DEBUG` setting**. Redaction tested against captured log output **and against captured response bodies**. Stack traces never carry SQL or bound parameters. (The response-body half was added on 2026-10-26 with the user's approval — rank 2, `§0` — from `PLAN.md §3.2`'s finding 2: every redaction clause in the project was scoped to _logs_, so `R-007`'s Task 2 half and `R-021` were caught by nothing. Measured at `7f1c8f6`, before Task 2: a `QueryException` rendered a 500 body carrying the full SQL and the interpolated bound parameter, and `UnsafeDatabaseRoleException` rendered one naming the database role.)
- Graceful shutdown: stop reporting live, drain in-flight requests, close DB connections, exit 0.

---

## 5. SCOPE ADDED BY THE CLIENT — build it, it is not in the Blueprint
- **S1 — Tenant provisioning:** the platform admin creates a new restaurant end-to-end from the Filament panel.
- **S2 — Landing page builder:** the admin builds a public landing page per restaurant (blocks, media, SEO, theme). Per-tenant custom domain with automatic TLS. Pages are statically rendered and CDN-cached.
- **S3 — Pager replaces the physical buzzer:** scan QR → sit down → "order ready" push notification.

---

## 6. UNIVERSAL GATE — every task, no exceptions

**Before coding — written into the task notes:**
1. Which tables / columns / roles / GUCs does this touch? Any change to the data contract → update `PLAN.md` **first**.
2. Which shared contracts (statuses, enums, DTOs) does it use or add? One source only. Never a duplicated enum.
3. Show the exact path by which tenant context reaches **every** query.
4. Security: authn, authz, input validation, rate limit, log redaction, secrets.
5. Scale: cost per request and per active session. What is bounded?
6. Failure: timeout, retry, duplicate, crash mid-way.
7. Research done for anything version-specific, verified by running code.

**After coding — evidence shown in the chat:**
- `composer pint`, `composer stan`, `php artisan test` all green against **real** Postgres + Redis. No DB mocks for DB behaviour.
- **Fresh clone, not just a fresh DB:** the gates pass from a clean checkout of the branch — nothing that only exists on the builder's machine. Migrations apply from empty; a second apply is a no-op; **a rollback of this task's migrations runs clean**. (Task 0's empty `tests/Unit/` directory passed locally and broke CI. That is the failure mode this clause exists for.)
- **Unit coverage ≥ 90%** on code this task added (Blueprint §7 QA). Report the number — Pest for PHP, Vitest for TypeScript.
- Any **shared contract** (status enum, DTO) is generated from its single source, committed, and **CI fails when it is stale** — never hand-copied across languages (§6.2).
- Every bug found gets a regression test that fails without the fix.
- All previous tasks' tests still pass.
- Any REST endpoint added is in the **generated OpenAPI spec**, and the spec is committed (Blueprint §7 API). Public routes are versioned — `/v1/...` — and a published path is never changed in place.
- `PLAN.md` updated, commit + tag `task-N`.

**Order is strict. No task starts before the previous one is green and tagged.**

---

## 7. WORKING RULES
- One task at a time, A to Z. Finish it and prove it before moving on.
- Research → write the stable plan → get approval → **then** code.
- Think cross-layer before writing a line: DB → model → service → API → frontend → infra. Never optimize one layer and break another.
- Never mark something done without showing gate output.
- Never fix the same bug twice. If it recurs, the regression test was missing — add it.
- Build and test locally at every task.
- This is an **isolated pilot repo**. It never touches the user's production client projects.

---

## 8. ANTI-HALLUCINATION
- Do not claim a library API exists without verifying it in this session.
- Do not claim a test passed without showing the output.
- Do not invent table, column, route, or env names. Read them from the repo.
- If the repo state and this file disagree — **stop and report**. Do not guess which is right.
- If something is unspecified, ask one concise question. Do not write speculative code.

---

## 9. OUTPUT FORMAT — always
- No greetings, no apologies, no closing remarks.
- CLI command first (`php artisan make:...`, `composer require ...`), then the code.
- Only the specific logic. No framework boilerplate.
- No explanation unless the user's message starts with `EXPLAIN:`.
- Modifying existing code — never print the whole file:

```
// REPLACE THIS:

// WITH THIS:
```

- Assume an expert developer. Skip setup basics.

---

## 10. REPO
- Private repo under the developer's account from the first commit. Delivered to the client later via GitHub **Transfer Ownership**.
- **Zero secrets in history, ever.** `.env.example` only. `gitleaks` in pre-commit and in CI.
- Tags `task-1 … task-N` are the delivery record and must be pushed.
- Conventional commits. `main` protected.

---

## 11. CLAUDE CODE AGENT WORKFLOW — three roles per task, never the same context

Tasks are executed by Claude Code as an autonomous agent. One task = one branch = one ticket.

**Role 1 — BUILDER.** Answers the §6 pre-coding gate inside the ticket, then implements. Runs `composer pint`, `composer stan`, `php artisan test` until green against real Postgres + Redis. Opens the PR.

**Role 2 — QA REVIEWER.** A separate agent session that did not write the code. Receives the diff and the ticket only — never the builder's reasoning.
Checks: the acceptance gate is met literally, not approximately; every branch of the state machine has a test; failure paths (timeout, retry, duplicate, crash mid-way) are tested; a fresh DB migrates clean twice; no test mocks DB behaviour; all previous tasks' suites still pass; every bug fixed in this task has a regression test that fails without the fix; `PLAN.md` is updated.
Output: findings with severity. **No code changes.**

**Role 3 — SECURITY REVIEWER.** A separate agent session. Receives the diff and the ticket only.
Checks, in this order:
1. **Tenant isolation** — can any new query run with `app.tenant_id` unset? Any new cross-tenant path not named in §4? Any `BYPASSRLS`, any new `app.*` GUC, any session-scoped `set_config`?
2. **IDOR** — every route taking an ID is proven inaccessible across tenants by a test, not by reading the code.
3. **AuthN / AuthZ** — route default is deny. Public routes explicitly marked and justified.
4. **Input** — validation on every entry point. Mass assignment guarded.
5. **Secrets & logs** — no secret, token, QR value, SQL or bound parameter reachable in logs or error bodies. Verified against captured output, not assumed.
6. **Abuse & rate limiting** — per IP and per tenant. What does an attacker's loop cost us per request?
7. **Dependencies** — any new package: license, maintenance status, known CVEs.
8. **OWASP ASVS L2** — the items this diff touches.
Output: findings with severity. **No code changes.**

**Merge rule.** The task is not done and `task-N` is not tagged until every **Critical** and **High** finding is fixed with a regression test. Medium and Low are either fixed or written into `PLAN.md §5 — Accepted Risks` with an owner and a date. "No findings" is valid only when the reviewer states what it actually checked.

**Relay rule.** A review's findings reach the user **in full**. Every finding is listed — its severity, and one line saying what it is — and the count is stated and matches the list: "N findings: X Critical, Y High, Z Medium, W Low". Nothing is dropped, folded into another finding, or left out as minor, whatever its severity and whoever judges it so. **A relay that drops a finding is itself a violation of this section**, not a presentation choice: the merge rule above cannot be satisfied for a finding nobody saw, and the user cannot ask about one they were never shown, so the omission is invisible from their side and the task passes the gate without having passed it. Before any claim that a review's findings are dispositioned, check the dispositions against the full list and state the count again.

**Round limit.** The QA and Security reviews above are the first pass. The fixes they produce are code nobody has read, so a fix diff is reviewed too — **at most twice**. After the second review of a fix diff, **only a failure of the task's own stated invariant blocks `task-N`**; everything else goes to `PLAN.md §5` with an owner and a date, whatever its severity, including a High. Without this cap the merge rule is an open loop: "every Critical and High fixed with a regression test" sets no limit on rounds, and each round of fixes is itself new code that can carry a Critical or a High.

**The invariant is named in that task's `§3` acceptance gate**, so the bar is written before the work starts and is never set under fatigue after reading a late review's findings. A task whose gate names no invariant a review could falsify is not ready to begin. If a review past the cap does find an invariant failure, it blocks, it is fixed, and that fix is reviewed once more scoped to that failure alone — which can only return another invariant failure or `§5` material, so the loop still terminates.

**Repo wiring for Claude Code:**
- `CLAUDE.md` at repo root — points to this file and `docs/PLAN.md`. Duplicates nothing.
- `.claude/agents/qa-reviewer.md` and `.claude/agents/security-reviewer.md` — the two reviewers above, read-only tool sets.
- `.claude/commands/task.md` — the task intake template (the §6 pre-coding questions).

---

## 12. FRONTEND QUALITY BAR

- Every screen is specified before it is coded: purpose, all states (empty / loading / error / offline / permission-denied), content inventory, and the exact copy.
- Design tokens are a single source. **No hard-coded hex, spacing or font size in any component.** A tenant theme overrides tokens, never components.
- Visual direction is approved as a rendered artifact **before** any component is written. Code never leads design.
- Required on every screen: keyboard reachable, visible focus ring, WCAG AA contrast (AAA on the "order ready" screen), 44px minimum touch target, `prefers-reduced-motion` respected, RTL-safe layout — Arabic is a launch language.
- Customer PWA bar: Lighthouse Performance ≥ 95, installable, usable on a cold 3G start, fully operable one-handed.
- **No library default look.** If a reviewer can name the UI framework from a screenshot, the screen is rejected.
- **Where that bar applies, and when.** It is binding on every customer-facing and staff-facing surface: the PWA, the staff dashboard and KDS, and public tenant pages. The **Filament admin** is built functionally first in Tasks 6-7 — correct, accessible, keyboard-reachable, RTL-safe — and is **restyled from the approved tokens in Task 8**, which is where the tokens first exist. Holding Tasks 6-7 to a token set that has not been created yet is a contradiction, not a standard.
- **Order.** The visual direction is approved in Task 8 and no component is written before it. Tasks 6-7 precede that and are explicitly exempt from the visual bar, never from the accessibility bar.
