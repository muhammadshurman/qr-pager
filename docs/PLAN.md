# PLAN.md — QR Pager SaaS

> Living decision log + the **only** task table. Precedence and invariants live in `PROJECT_INSTRUCTIONS.md`; this file never duplicates them.

**Version:** 2.6 · **Backend:** Laravel 13 (O1) · **Realtime:** Web Push + polling (O2)

---

## 0. WHERE WE ARE RIGHT NOW

> **Read this section first in every new chat. It is the only place that states current state. Update it at the end of every task.**

| | |
|---|---|
| **Last completed** | **Task 0** — repo, local environment, quality gates. Tagged `task-0`, CI green. |
| **Next up** | **Task 1** — DB foundation. Ticket `QRPAGER-1`. Nothing else starts first (§3 order is strict). |
| **Blocking anything?** | No. Task 1 is unblocked and ready. |
| **Open debt that must die in Task 1** | The app connects as `qrpager_owner`. See §6. |

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
| 2026-10-25 | Third review pass: 4 implementation gaps closed. | The first pass found scope (§7 checklists), the second found paid-for lessons, the third found **mechanisms sitting behind policies we already had**: load shedding was stated as policy but never specified as behaviour (fast 503 on pool saturation, 503 under pressure, **health keeps answering**); no dependency had a declared fail-open/fail-closed posture; §6 said "fresh DB" where the Blueprint said **fresh clone** — the exact wording that would have caught Task 0's empty-directory bug; WAF was listed without the OWASP managed rules that are the point of it. |
| 2026-10-25 | **C6 corrected:** the launch tier is set by **Task 14**, not Task 9. | C6 carried the Blueprint's own 10-task numbering, where load testing was Task 9. `PLAN.md §3` has 15 tasks and Task 9 is the customer PWA. A direct contradiction between ranks 2 and 3. |

---

## 2. Data contract — **TO BE FILLED AT TASK 1**

Blueprint §3.1 is superseded (C3). The real table list, columns, enums and roles are transcribed here from the Laravel migrations **before Task 2 starts**. Until then this section is empty on purpose — no task may invent a table or column name.

- Tables: _TBD at Task 1_
- Status enum (single source, PHP enum + mirrored TS type): _TBD at Task 1_
- GUCs: `app.tenant_id`, `app.bypass_tenant` (locked, C1)
- DB roles: `owner`, `app`, `worker` (locked, §4)

---

## 3. Task table

Each task also passes the Universal Gate (`PROJECT_INSTRUCTIONS.md §6`) and the three-role review (§11). Order is strict.

| # | Task | Own acceptance gate — must be demonstrated |
|---|---|---|
| **0** | **Repo + local environment.** Private repo, `.gitignore`, `.env.example`, gitleaks pre-commit + CI, conventional-commit hook, `CLAUDE.md`, `.claude/agents/*`, `.claude/commands/task.md`, Docker Compose (Postgres 16, Redis 7), PHP 8.4 toolchain, Laravel 13 skeleton, Pint + PHPStan + Pest wired, GitHub Actions skeleton. | `php artisan serve` responds; `php artisan test` runs one green test against the **real** composed Postgres; `gitleaks detect` clean; CI green on first push; tag `task-0`. |
| **1** | **DB foundation.** Migrations for the full schema, 3 DB roles, RLS `ENABLE` + `FORCE` on every tenant table, per-role policies, column grants, append-only `audit_log` trigger, `ALTER ROLE` timeouts, `withTenant()` transaction helper, `assertSafeRuntimeRole()`. | No tenant context → every table returns **0 rows, no error**; cross-tenant read/update/delete/insert all blocked; `owner` is also bound by FORCE RLS; context does **not** leak across a single-connection pool; `worker` sees the outbox across tenants, `app` does not; `audit_log` immutable even for superuser; **meta-test fails the build if any table lacks ENABLE or FORCE**; **a push subscription cannot point at another tenant's session**; **the `ALTER ROLE` timeouts are verified to fire, per role**; migrations apply clean from empty twice; **`migrate:rollback` runs clean and a re-apply returns the identical schema** (Blueprint §7 Database). §2 of this file is filled. |
| **2** | **API core.** Octane/FrankenPHP boot, config validation, tenant-context middleware (`set_config(..., true)` per transaction), safe-role boot refusal, error mapping to real driver codes, Monolog redaction, rate limiting, health + readiness, graceful shutdown, architecture guards in CI, **a repo guard that rejects session-scoped tenant settings and unknown `app.*` names**, **OpenAPI spec generation wired**. | Boot refuses an unsafe DB role (exit 1, reason logged); a request without tenant context never reaches the DB; health reflects real Postgres + Redis state; `SIGTERM` drains in-flight requests and exits 0 (proven against the compiled app); captured logs contain no SQL, no bound parameters, no secrets; **the repo guard fails on a deliberately bad file** (C1/C2 enforced mechanically); **the rate limiter covers unknown routes (404s), or the gap is written into §5 with the control that covers it**; **a saturated DB pool returns a fast 503, and memory/CPU pressure returns 503 — while health and readiness keep answering**; **Redis is taken down and the API keeps serving, with the rate limiter's fail-open/fail-closed posture stated and tested**; **the OpenAPI spec is generated from the routes, committed, and CI fails when it is stale** (Blueprint §7 API). |
| **3** | **Pager domain.** Create session (128-bit CSPRNG token, hash-only storage, 2h TTL, QR payload), `markReady`, `renotify`, `complete`, `cancel`, expiry job. Status + outbox row + audit row committed in **one** transaction. | `markReady` is idempotent under concurrent calls — one outbox row, one notification; invalid state transitions rejected; cross-tenant IDOR blocked through HTTP, not just at the DB; expiry job uses `SELECT id` → `UPDATE ... WHERE id = ? AND status = ?` with **no `RETURNING`**; raw token never appears in any log, URL or error body (asserted against captured output). |
| **4** | **Notification pipeline.** Outbox worker (`FOR UPDATE SKIP LOCKED`, backoff, dead-letter), Web Push dispatcher (VAPID, 404/410 subscription pruning), Redis status projection, polling endpoint, subscription endpoints. | Kill the worker mid-batch → nothing lost, nothing duplicated; polling endpoint does **zero Postgres queries** (proven by query log) and p99 < 5 ms; dead subscriptions pruned on 404/410; push sent to a mocked edge only; client backoff + jitter verified; **Redis is taken down and the polling endpoint's behaviour is defined and tested — the customer must not see a dead end**; **every Redis key holding tenant data is namespaced by tenant id, and a test proves one tenant's projection cannot be read through another tenant's context** — Redis has no RLS (Blueprint bug 4, which O2 moved rather than removed). |
| **5** | **Auth & RBAC.** Fortify + Sanctum, WebAuthn passkeys, 2FA, roles (platform admin / owner / manager / staff), KDS device tokens, session policy. | Full role-matrix test; session fixation and CSRF tests; a tenant-provisioning actor cannot read another tenant's data; passkey registration + login E2E; device token scope cannot escalate. |
| **6** | **Filament admin — tenant provisioning (S1).** Platform dashboard, restaurant create wizard end-to-end, staff management + **invites**, QR batch generation + printable sheets, audit log viewer. | A new restaurant goes from zero to a scannable QR in one wizard pass, with no SQL run by hand; the wizard is transactional — a failure halfway leaves no partial tenant; platform admin actions all land in `audit_log`; **a staff invite is actually delivered, asserted against the local mail catcher** (ADR-002). |
| **7** | **Landing page builder (S2).** Block-based builder in Filament, **media library on S3**, SEO fields, theme tokens per tenant, publish → static render, custom domain + automatic TLS. | Publish produces a statically rendered, CDN-cached page; an unpublished draft is not reachable; a tenant cannot inject script through any block (XSS fuzz suite); custom domain issues TLS automatically and the old domain redirects; theme overrides **tokens only**, never components; **an upload is validated by real content type and size, not by extension, and one tenant's asset path cannot be guessed or reached from another** (ADR-002). |
| **8** | **Design system + UI kit.** Token package (color, type scale, spacing, radius, elevation, motion), base components, RTL + dark mode, accessibility primitives. **Two or three visual directions delivered as rendered artifacts for approval before any component is written.** | A direction is approved in writing; zero hard-coded hex/spacing/font-size anywhere (lint rule enforces it); WCAG AA across the kit; RTL mirrors correctly; `prefers-reduced-motion` honoured; a reviewer cannot name the UI framework from a screenshot. |
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

## 5. Accepted risks

| # | Risk | Severity | Why accepted | Compensating control | Owner | Review by |
|---|---|---|---|---|---|---|
| **R-001** | **`main` is not actually protected.** GitHub rulesets are not enforced on a private repository under an individual plan ("won't be enforced on this private repository until you move to GitHub Team"). Branch protection is configured but inert, so a direct push to `main` succeeds and can bypass PR review. | Medium | Single developer. The core of `PROJECT_INSTRUCTIONS.md §11` is that QA and Security reviewers run in **separate agent sessions without the builder's reasoning** — that is enforced by process, not by a GitHub button. CI runs on every push to `main` (proven), so a broken merge is caught within a minute. Paying for GitHub Team before the first paying tenant is not justified. | **Working rule, binding:** one task = one branch = one PR. Never commit to `main` directly. Never merge until both required checks are green and the §11 QA + Security reviews have run. Squash merge only. | Muhammad | At client handover (Transfer Ownership to an Organization, where rulesets are enforced) **or** when a second developer joins — whichever is first. |

_Medium/Low review findings that are not fixed land here with an owner and a date._

---

## 6. Open items

- **Task 0 connects as `qrpager_owner`.** The `app` and `worker` roles do not exist until Task 1, so the Task 0 skeleton necessarily uses the owner connection. Task 1 must switch `.env` to the `app` role and add `assertSafeRuntimeRole()`; until then §4 "refuses to boot on an unsafe role" is **not yet satisfied**. This must not survive Task 1.
- Billing / subscriptions: not in the Blueprint and not requested by the client. **Out of scope until stated.**
- Launch tier: unset by design until Task 14.
- **Queue transport for push dispatch.** The Blueprint specified SQS. The design is an outbox table plus a worker, which satisfies the guarantee — "ready" commits instantly, the send happens behind it with retries — independently of transport. Laravel's Redis queue is the default. **Task 13 decides whether the launch tier needs SQS instead, and Task 14 measures it.** Do not assume either.
- **Data migrations under FORCE RLS.** `owner` sees no rows either, so any migration that touches data needs tenant context set per tenant or a named, audited bypass. The pattern is settled in Task 1 and written here; the first data migration must not rediscover it.

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
