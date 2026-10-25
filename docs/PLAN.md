# PLAN.md — QR Pager SaaS

> Living decision log + the **only** task table. Precedence and invariants live in `PROJECT_INSTRUCTIONS.md`; this file never duplicates them.

**Version:** 2.1 · **Status:** `task-0` green and tagged · **Backend:** Laravel 13 (O1) · **Realtime:** Web Push + polling (O2)
**Repo:** `github.com/muhammadshurman/qr-pager` (private) · `main` protected, PR + 2 required checks

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
| **1** | **DB foundation.** Migrations for the full schema, 3 DB roles, RLS `ENABLE` + `FORCE` on every tenant table, per-role policies, column grants, append-only `audit_log` trigger, `ALTER ROLE` timeouts, `withTenant()` transaction helper, `assertSafeRuntimeRole()`. | No tenant context → every table returns **0 rows, no error**; cross-tenant read/update/delete/insert all blocked; `owner` is also bound by FORCE RLS; context does **not** leak across a single-connection pool; `worker` sees the outbox across tenants, `app` does not; `audit_log` immutable even for superuser; **meta-test fails the build if any table lacks ENABLE or FORCE**; migrations apply clean from empty twice. §2 of this file is filled. |
| **2** | **API core.** Octane/FrankenPHP boot, config validation, tenant-context middleware (`set_config(..., true)` per transaction), safe-role boot refusal, error mapping to real driver codes, Monolog redaction, rate limiting, health + readiness, graceful shutdown, architecture guards in CI. | Boot refuses an unsafe DB role (exit 1, reason logged); a request without tenant context never reaches the DB; health reflects real Postgres + Redis state; `SIGTERM` drains in-flight requests and exits 0 (proven against the compiled app); captured logs contain no SQL, no bound parameters, no secrets. |
| **3** | **Pager domain.** Create session (128-bit CSPRNG token, hash-only storage, 2h TTL, QR payload), `markReady`, `renotify`, `complete`, `cancel`, expiry job. Status + outbox row + audit row committed in **one** transaction. | `markReady` is idempotent under concurrent calls — one outbox row, one notification; invalid state transitions rejected; cross-tenant IDOR blocked through HTTP, not just at the DB; expiry job uses `SELECT id` → `UPDATE ... WHERE id = ? AND status = ?` with **no `RETURNING`**; raw token never appears in any log, URL or error body (asserted against captured output). |
| **4** | **Notification pipeline.** Outbox worker (`FOR UPDATE SKIP LOCKED`, backoff, dead-letter), Web Push dispatcher (VAPID, 404/410 subscription pruning), Redis status projection, polling endpoint, subscription endpoints. | Kill the worker mid-batch → nothing lost, nothing duplicated; polling endpoint does **zero Postgres queries** (proven by query log) and p99 < 5 ms; dead subscriptions pruned on 404/410; push sent to a mocked edge only; client backoff + jitter verified. |
| **5** | **Auth & RBAC.** Fortify + Sanctum, WebAuthn passkeys, 2FA, roles (platform admin / owner / manager / staff), KDS device tokens, session policy. | Full role-matrix test; session fixation and CSRF tests; a tenant-provisioning actor cannot read another tenant's data; passkey registration + login E2E; device token scope cannot escalate. |
| **6** | **Filament admin — tenant provisioning (S1).** Platform dashboard, restaurant create wizard end-to-end, staff management, QR batch generation + printable sheets, audit log viewer. | A new restaurant goes from zero to a scannable QR in one wizard pass, with no SQL run by hand; the wizard is transactional — a failure halfway leaves no partial tenant; platform admin actions all land in `audit_log`. |
| **7** | **Landing page builder (S2).** Block-based builder in Filament, media library, SEO fields, theme tokens per tenant, publish → static render, custom domain + automatic TLS. | Publish produces a statically rendered, CDN-cached page; an unpublished draft is not reachable; a tenant cannot inject script through any block (XSS fuzz suite); custom domain issues TLS automatically and the old domain redirects; theme overrides **tokens only**, never components. |
| **8** | **Design system + UI kit.** Token package (color, type scale, spacing, radius, elevation, motion), base components, RTL + dark mode, accessibility primitives. **Two or three visual directions delivered as rendered artifacts for approval before any component is written.** | A direction is approved in writing; zero hard-coded hex/spacing/font-size anywhere (lint rule enforces it); WCAG AA across the kit; RTL mirrors correctly; `prefers-reduced-motion` honoured; a reviewer cannot name the UI framework from a screenshot. |
| **9** | **Customer PWA.** The scan-to-ready journey (see §4). | Playwright E2E: scan → wait → ready → alert; permission-denied, offline, expired-token and invalid-token paths all handled; iOS Home-Screen install path works and is measured; Lighthouse Performance ≥ 95; one-handed operation verified at 390px. |
| **10** | **Staff dashboard + KDS.** Active pager board, mark-ready, renotify, history, shift view. | Mark-ready round trip under 2 s to the customer device; board stays correct with two staff acting at once; KDS survives a network drop and resyncs without duplicate notifications. |
| **11** | **Public landing renderer.** Next.js static/ISR rendering of S2 pages with per-tenant theme and domain. | Cold page served from CDN with origin untouched; per-tenant theme applied from tokens; SEO/OG tags correct; Lighthouse ≥ 95 on a real tenant page. |
| **12** | **Security hardening.** CSP, CORS, per-tenant + per-IP limits in Redis, input fuzzing, SAST/DAST in CI, OWASP ASVS L2 mapping. | Zero high/critical from the SAST and DAST runs; ASVS L2 table complete with evidence per item; CSP has no `unsafe-inline`; fuzz suite finds no 5xx. |
| **13** | **Infrastructure (Terraform).** VPC, ECS Fargate, Aurora PG, ElastiCache, ALB, CloudFront, WAF, Secrets Manager, observability, GitHub OIDC CI/CD. | `terraform validate` + `plan` clean; zero-downtime deploy rehearsed; no stored AWS keys anywhere; secrets only in Secrets Manager; alarms wired. |
| **14** | **Load, chaos, launch.** k6 ramps, ready-storm, task kill, Redis failover, DB failover. Runbooks, backup/restore drill, pen-test prep, launch checklist. | `docs/load-results.md` with p95/p99, error rate and cost per tier; **the launch tier is set here from measured numbers, never assumed (C6)**; restore drill executed end-to-end; runbooks reviewed. |

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

_None yet. Medium/Low review findings that are not fixed land here with an owner and a date._

---

## 6. Open items

- **Task 0 connects as `qrpager_owner`.** The `app` and `worker` roles do not exist until Task 1, so the Task 0 skeleton necessarily uses the owner connection. Task 1 must switch `.env` to the `app` role and add `assertSafeRuntimeRole()`; until then §4 "refuses to boot on an unsafe role" is **not yet satisfied**. This must not survive Task 1.
- Billing / subscriptions: not in the Blueprint and not requested by the client. **Out of scope until stated.**
- Launch tier: unset by design until Task 14.
