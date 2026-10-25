# ADR 003 — Monorepo layout and frontend tooling

- Status: accepted
- Date: 2026-10-25
- Extends: `PROJECT_INSTRUCTIONS.md §1` (two new rows) and the repo layout in `CLAUDE.md`

## Context

Two structural requirements were never decided, and each blocks a task outright.

### 1. The frontend has nowhere to live

The Blueprint's folder layout was:

```
apps/api · apps/web · packages/db · packages/types
```

Task 0 created `apps/api` only. `CLAUDE.md` says *"`apps/api` — Laravel 13 backend. All backend work happens here"* and does not mention a frontend at all.

Tasks 8-11 build Next.js 15 — the design system, the customer PWA, the staff dashboard and the public renderer. None of them has a directory.

Worse, Task 8's own ticket requires:

> *The token set is **one source**, consumed by the PWA, the staff app, the Filament panel and the public renderer.*

A package shared by four consumers, in a repo with no workspace, no package manager decision and no `packages/`. **Task 8 is unbuildable as written.**

### 2. No frontend test runner

`PROJECT_INSTRUCTIONS.md §1` locks Pest 5 + PHPUnit 13 (PHP), Playwright (E2E) and k6 (load). Tasks 8-11 produce TypeScript and React, and §6 requires **≥ 90% unit coverage on code the task added**.

There is no runner that can produce that number. The Blueprint used Vitest; it was lost when §1 was written — the same way MinIO and Mailpit were (ADR-002).

## Decision

### Layout

```
qr-pager/
├── apps/
│   ├── api/          Laravel 13 — backend, Filament admin
│   ├── web/          Next.js 15 — customer PWA + staff dashboard/KDS
│   └── site/         Next.js 15 — public tenant landing renderer (Task 11)
├── packages/
│   ├── tokens/       design tokens, single source (Task 8)
│   ├── ui/           component kit (Task 8)
│   └── contracts/    status enum + DTO types, mirrored from the PHP enum
└── docs/
```

**Package manager: pnpm workspaces.** No Turborepo at this size — two Next.js apps and three packages do not need a build orchestrator, and an unused one is a dependency plus a config file plus a cache that will eventually be committed by accident (see Task 0's PHPStan cache). Revisit if build times become a real complaint.

`apps/site` is separate from `apps/web` because Task 11 is statically rendered and CDN-served with **origin untouched**, while `apps/web` is an authenticated application. One Next.js app serving both makes that gate hard to prove and couples a public page's deploy to the staff dashboard's.

### Frontend tooling

| Need | Locked |
|---|---|
| Unit tests + coverage | **Vitest** |
| E2E | **Playwright** (already locked) |
| Lint + format | **ESLint + Prettier** |
| Types | **TypeScript strict** |

### The shared status enum

`PROJECT_INSTRUCTIONS.md §6.2` requires one source for every shared contract. The PHP enum in `apps/api` is that source. `packages/contracts` carries a **generated** TypeScript mirror, and **CI fails when it is stale** — the same mechanism as the OpenAPI spec.

A hand-maintained copy is a second source wearing a disguise, and the drift shows up as a customer seeing the wrong status.

## Consequences

- Task 0 is complete and tagged; this layout is created **at Task 8**, the first task that needs it. `apps/api` does not move.
- `CLAUDE.md` gains the full layout.
- `PLAN.md §3` row 8 gains the workspace setup and the generated mirror.
- The universal gate's **≥ 90% coverage** clause is satisfied by Pest for PHP and Vitest for TypeScript. Both numbers get reported.
- CI gains a second job for the frontend workspace.
- Task 13 provisions hosting for two Next.js apps, not one.

## Rejected

- **Turborepo.** Right for a large monorepo; here it is machinery for its own sake.
- **One Next.js app for everything.** Couples a public, statically rendered, CDN-cached page to an authenticated dashboard and makes Task 11's "origin untouched" gate hard to prove.
- **Jest.** Vitest is what the Blueprint chose and is the better fit for a Vite/Next toolchain.
- **A hand-written TypeScript enum.** Two sources of truth for the status machine, which is the exact failure this project is structured to prevent (C7, §6.2).
