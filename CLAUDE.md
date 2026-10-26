# QR Pager SaaS

## Start here, every time

**`docs/PLAN.md §0` tells you where the project is right now** — what was finished
last, what is next, which ticket, and what debt is outstanding. Read it first.
Nothing else in this repo states current state.

Then, in this order:

1. `docs/PROJECT_INSTRUCTIONS.md` — precedence, locked stack, invariants, gates, agent workflow
2. `docs/PLAN.md` — the rest of it: decisions, data contract, task table, risks
3. `docs/adr/` — accepted deviations from the locked stack

This file duplicates nothing. On conflict between files, follow precedence in
`PROJECT_INSTRUCTIONS.md §0`: **stop and report, never silently resolve.**

## Tickets

Board: `muhdshurman.atlassian.net`, project `QRPAGER`. The task ↔ ticket map is in
`docs/PLAN.md §0`. Each ticket carries that task's acceptance gate, the §6
pre-coding questions, and an explicit *out of scope* table. Work from the ticket,
not from memory.

## Layout

pnpm workspaces (ADR-003). The frontend half is created at **Task 8** — the first
task that needs it. Until then only `apps/api` exists, and that is correct.

```
apps/api      Laravel 13 (PHP 8.4) — backend + Filament admin
apps/web      Next.js 15 — customer PWA + staff dashboard/KDS   (Task 8+)
apps/site     Next.js 15 — public tenant landing renderer        (Task 8+)
packages/
  tokens      design tokens, single source                       (Task 8)
  ui          component kit                                      (Task 8)
  contracts   TS mirror of the PHP status enum — GENERATED       (Task 8)
docs/         PROJECT_INSTRUCTIONS · PLAN · BLUEPRINT · adr/
compose.yaml  Postgres 16 + Redis 7. Tests run against these, never SQLite.
```

`packages/contracts` is generated from the PHP enum in `apps/api`. **Never hand-edit
it** — one source, and CI fails when the mirror is stale.

## Gates (`PROJECT_INSTRUCTIONS.md §6`)

From `apps/api`, all three must be green before any commit:

```
composer pint
composer stan
php artisan test
```

First run on a machine, or after `docker compose down -v`: the cluster's superuser is
`qrpager_root` and the three application roles are created by

```
php artisan db:bootstrap      # roles, the database, and the per-role ALTER ROLE timeouts
php artisan migrate --database=pgsql_owner    # migrations run as the owner, never as the app role
```

`php artisan test` runs both of those itself, so a fresh clone needs only
`cp .env.example .env`, `php artisan key:generate` and `docker compose up -d`.
The role model and why `root` is separate from `owner` are in `docs/PLAN.md §2.1`.

From the repo root, once the workspace exists (Task 8+):

```
pnpm lint
pnpm typecheck
pnpm test
```

Gates run from a **fresh clone**, not just a fresh database — a pass that depends
on something only present on the builder's machine is not a pass.

No task is done until its own acceptance gate in `docs/PLAN.md §3` is demonstrated,
QA and Security reviews have run (§11), and `task-N` is tagged.
