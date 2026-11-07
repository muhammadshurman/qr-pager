# BLUEPRINT — archive of surviving source material

> ## Read this header before using this file
>
> **This is not the original Blueprint v2.0.** The original document no longer
> exists in any form we hold. What survives is a PDF transcript of the
> conversation in which it was discussed, and that transcript quotes only parts
> of it.
>
> This file contains **exactly what that transcript preserves and nothing else.**
> Nothing here has been reconstructed, inferred, completed or improved. Where a
> section of the original is known to be missing, it is listed as missing in §9
> rather than guessed at — per `PROJECT_INSTRUCTIONS.md §8`.
>
> **Precedence:** this file is rank 4 in `PROJECT_INSTRUCTIONS.md §0`. It is the
> lowest rank for a reason: everything in it that is still binding has already
> been absorbed into ranks 2 and 3. §8 of this file maps each part to where it
> now lives. Use this file for **provenance** — to check what the original
> actually said — not as a working instruction.
>
> If this file and `PROJECT_INSTRUCTIONS.md` or `PLAN.md` disagree, those win,
> and §0 applies: **stop and report.**

---

## 1. Row-Level Security — the exact policy (original §3.2)

Quoted verbatim from the transcript.

```sql
-- Enable & Force RLS on every tenant table
ALTER TABLE pager_sessions ENABLE ROW LEVEL SECURITY;
ALTER TABLE pager_sessions FORCE ROW LEVEL SECURITY;

-- The exact policy (handles empty string from pooled connections)
CREATE POLICY tenant_isolation_pager_sessions ON pager_sessions
USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
```

The `NULLIF(..., '')` is the part that matters and the part people drop: a
pooled connection that has been reset leaves an **empty string**, not NULL, and
`''::uuid` raises instead of matching nothing.

**Now binding via:** `PROJECT_INSTRUCTIONS.md` C1, C2, C4 and §4 › Tenancy.

---

## 2. Findings carried forward from the lost Task 1 (original §3.3)

Quoted verbatim. These were paid for once, on a build that no longer exists.

- **No `RETURNING` on worker expiry updates.** `RETURNING` makes Postgres apply
  SELECT policies to the NEW row; EXPIRED rows are deliberately not selectable
  by the worker. Expiry job: `SELECT id` (policy-limited), then
  `UPDATE ... WHERE id = $1 AND status = <expected>` and check row count.
- **The ORM wraps driver errors.** The real Postgres error was in `error.cause`.
  Error mapping must read `cause.code` — `42501` RLS, `23505` unique, `23514`
  check, `23503` FK.
- **Audit log immutability holds even for superuser** (trigger on
  UPDATE / DELETE / TRUNCATE).

> The first two were observed against Drizzle on NestJS, which **O1** removed.
> The `RETURNING` finding is a **Postgres** behaviour and carries over unchanged.
> The `error.cause` finding is **ORM-specific** — Laravel's PDO layer exposes the
> driver code differently, so Task 2 must find where it actually lives rather
> than assume this shape. The *rule* carries over: read the real driver code.

**Now binding via:** `PROJECT_INSTRUCTIONS.md §4 › Postgres traps`.

---

## 3. Pre-delivery department checklists (original §7)

Quoted verbatim. `PROJECT_INSTRUCTIONS.md §2` keeps this section binding. Every
line below was audited against the task table on 2026-10-25 and mapped onto an
acceptance gate — see `PLAN.md §1`, decision log entry for that date.

| Department | Must complete before delivery |
|---|---|
| **Database** | RLS policies on every tenant table; FORCE RLS enabled; migration rollback tested; PITR backups configured; role-level timeouts set via `ALTER ROLE`. |
| **API** | All procedures have schema validation; per-tenant rate limiting; OpenAPI spec generated for REST endpoints; `assertSafeRuntimeRole` active. |
| **Frontend** | Lighthouse Performance ≥ 95; PWA installable; `pushsubscriptionchange` handler tested; fallback channel tested. |
| **Security** | OWASP ASVS L2 self-assessment passed; SAST/DAST zero high/critical; secrets in Secrets Manager; IAM least-privilege. |
| **QA** | 90%+ unit coverage; Playwright suite green on all browsers; load test passed at the design ceiling. |
| **DevOps** | Terraform modules for all AWS resources; GitHub Actions CI/CD with OIDC; zero-downtime deploy tested. |
| **Observability** | CloudWatch dashboards for ALB 5xx, ECS CPU, Aurora ACU, Redis latency; alarms to a real destination. |
| **Docs** | ADRs for all stack choices; incident-response runbook; API documentation published. |

> Two rows are reworded from the original because **O1** and **O2** removed the
> named technology, not the requirement: "All tRPC procedures have Zod
> validation" → "All procedures have schema validation"; "SSE fallback tested" →
> "fallback channel tested"; "Snyk/ZAP" → "SAST/DAST"; "k6 300K SSE load test" →
> "load test passed at the design ceiling"; "alarms to PagerDuty/Slack" →
> "alarms to a real destination". The obligation is unchanged.

---

## 4. Security posture (from the transcript)

- **Tenant isolation:** RLS enforced twice — application and database — plus CI
  tests that actively try to read another tenant's rows.
- **Customers:** no accounts, no phone numbers stored. A random 128-bit QR token
  that expires in 2 hours.
- **Staff and admins:** passkeys and 2FA, short-lived sessions, a full audit log.
- **Edge:** WAF with OWASP rules, rate limits per IP **and** per tenant, Shield.
- **Data:** TLS 1.3, KMS encryption at rest, secrets in Secrets Manager, private
  subnets.
- **Pipeline:** dependency and DAST scans on every PR, GitHub-to-AWS OIDC, **no
  stored keys**.
- **Before launch:** an independent penetration test against OWASP ASVS Level 2.

**Now binding via:** `PROJECT_INSTRUCTIONS.md §4` and `PLAN.md §3` rows 12-14.

---

## 5. Stability posture (from the transcript)

- Three availability zones, auto-scaling, zero-downtime deploys.
- Health checks, graceful shutdown, connection draining.
- **Load shedding:** when overloaded, drop the nice-to-have things — live timer,
  analytics — **first**, and keep alerts alive.
- Idempotent "ready" calls, so retries never double-notify.
- Alarms and dashboards in CloudWatch and X-Ray.

**Now binding via:** `PROJECT_INSTRUCTIONS.md §4 › Edge & ops` and `PLAN.md §3`
rows 13-14.

---

## 6. Local environment (original §8)

The original local stack was:

```
docker compose up -d   # Postgres, Redis, MinIO, MailHog
```

**MinIO and a mail catcher were in the original stack from the start.** They were
lost when `PROJECT_INSTRUCTIONS.md §1` was written, and their absence blocked
Tasks 6 and 7 until **ADR-002** restored them (as MinIO and Mailpit).

This is the clearest evidence for why this archive exists: a requirement that is
only in the source material, and not in the working documents, will be
rediscovered late and at cost.

---

## 7. Bugs identified in the original blueprint's own code

Found during the original review, listed here because each one is a mistake
somebody will otherwise make again.

1. `SET LOCAL app.current_tenant_id = ${tenantId}` **fails** — Postgres `SET`
   takes no bind parameters. Use `SELECT set_config(..., $1, true)`.
2. The RLS policy covered `orders` only, while the schema also had
   `pager_sessions` and `push_subscriptions`. Those need RLS too.
3. The customer-facing read and the token lookup have **no tenant context**, so
   they need either a narrow, named bypass or a token-scoped policy.
4. The Redis `order:ready` message carried **no tenant id**.
5. Connect as a **non-owner** role; run migrations under a separate owner
   connection.
6. Sticky sessions on the ALB are unnecessary if every instance subscribes to
   the shared channel.
7. **The iOS limitation — Web Push works only from a Home Screen install — is the
   single biggest customer-experience risk, and the original blueprint
   underplayed it.**

**Now binding via:** C1, C2, C4 (bugs 1-2), §4 › Tenancy named paths (bug 3),
§4 › Tenancy roles (bug 5), §4 › Realtime (bug 7). Bugs 4 and 6 concern the SSE
and Redis pub/sub design that **O2** cancelled.

---

## 8. Where each part of the original now lives

| Original section | Status | Now in |
|---|---|---|
| §1-§2 stack and architecture | **Superseded** by O1 and O2 | `PROJECT_INSTRUCTIONS.md §1, §2` |
| §3.1 schema | **Superseded** (C3) | The Laravel migrations; transcribed into `PLAN.md §2` at Task 1 |
| §3.2 RLS policy | **Binding** | §1 of this file; `PROJECT_INSTRUCTIONS.md §4`, C1, C2, C4 |
| §3.3 Task 1 findings | **Binding** | §2 of this file; `PROJECT_INSTRUCTIONS.md §4 › Postgres traps` |
| §4 architecture diagrams | **Lost** — only the headings survive | Nothing. See §9. |
| §5 task list (10 tasks) | **Superseded** (C7) | `PLAN.md §3` — 15 tasks. **One task table only.** This file deliberately does not reproduce the old one. |
| §6 universal gate | **Superseded, expanded** | `PROJECT_INSTRUCTIONS.md §6` |
| §7 department checklists | **Binding** | §3 of this file; mapped onto gates 2026-10-25 |
| §8 local environment | **Binding** | §6 of this file; `compose.yaml`, ADR-002 |

---

## 9. Known to be missing — do not reconstruct from memory

The transcript does not preserve these. If a future chat needs them, the answer
is **ask the client**, not infer:

- **§1 and §2** in full — the original stack rationale and architecture narrative.
- **§3.1** — the original schema. Superseded by C3, so this is a provenance gap,
  not a working one.
- **§4.1 High-Level Architecture**, **§4.2 Real-Time Notification Flow**,
  **§4.3 AWS Deployment Topology** — only the headings survive. The diagrams
  themselves are gone.
- Any section numbered above §8.

Per `PROJECT_INSTRUCTIONS.md §8`: *do not invent table, column, route, or env
names.* The same applies to this document. **An absence recorded honestly is
worth more than a plausible reconstruction.**
