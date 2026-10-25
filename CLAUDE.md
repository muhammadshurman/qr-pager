# QR Pager SaaS

Read before any work, in this order:

1. `docs/PROJECT_INSTRUCTIONS.md` — precedence, locked stack, invariants, gates, agent workflow
2. `docs/PLAN.md` — decision log, data contract, task table, accepted risks
3. `docs/adr/` — accepted deviations from the locked stack

This file duplicates nothing. On conflict between files, follow precedence in
`PROJECT_INSTRUCTIONS.md §0`: stop and report, never silently resolve.

## Layout

- `apps/api` — Laravel 13 backend (PHP 8.4). All backend work happens here.
- `docs/` — the two documents above plus ADRs.
- `compose.yaml` — Postgres 16 + Redis 7. Tests run against these, never SQLite.

## Gates (`PROJECT_INSTRUCTIONS.md §6`)

From `apps/api`, all three must be green before any commit:

```
composer pint
composer stan
php artisan test
```

No task is done until its own acceptance gate in `docs/PLAN.md §3` is demonstrated,
QA and Security reviews have run (§11), and `task-N` is tagged.
