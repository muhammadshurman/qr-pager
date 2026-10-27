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

**Run them through `composer gate`, which also runs `guard`, `deptrac` and
`openapi:check` and tees every line to `storage/logs/gate-<utc>.log`.** The three above
stay correct by hand; the wrapper is the form that leaves evidence. A full suite run on
`task-2-api-core` went red once at 363 passed and the failing test's identity was never
captured, because what was kept was a one-line summary rather than the output — so the
honest state of the suite was "one unexplained" until a run whose output went to a file
identified it in one sitting (two concurrent runners and an unserialized `TRUNCATE`,
`PLAN.md` R-049). A gate you cannot read afterwards is a gate you have to re-run to
learn anything from.

**And `composer gate` is the form that leaves a *readable* one, which running the three by
hand is not guaranteed to be.** `laravel/pao` swaps the console output style whenever
`Laravel\AgentDetector\AgentDetector::detect()->isAgent` — a detector over ~40 vendor
environment variables, `AI_AGENT` and `CLAUDECODE` among them — so inside **any** agent
session `php artisan test` prints a single JSON line, and on an assertion failure that line
carries `failures[].test`, `.file`, `.line` and `.message` with **no frame list at all**.
In an ordinary terminal the same command prints Pest's normal output, frames included. So
the three commands produce different evidence for a human and for a tool, which is how
`R-054` came to be measured as a defect in `bin/gate` when it was never a property of the
script. `bin/gate` exports **`PAO_DISABLE=1`** (that package's own off switch, checked
before every other condition), so its log is the same artifact for every runner. **Running
`php artisan test` by hand from a session: set `PAO_DISABLE=1` too, or the failure you are
reading has had its frames removed before you saw it.** Measured 2026-10-27: unsetting
`AI_AGENT` alone still gave JSON, `CLAUDECODE` alone still gave JSON, both gave frames.

First run on a machine, or after `docker compose down -v`: the cluster's superuser is
`qrpager_root` and the three application roles are created by

```
php artisan db:bootstrap      # roles, the database, and the per-role ALTER ROLE timeouts
php artisan migrate --database=pgsql_owner    # migrations run as the owner, never as the app role
```

`php artisan test` runs both of those itself, so a fresh clone needs only
`cp .env.example .env`, `php artisan key:generate` and `docker compose up -d`.

**And one more, which this file used to omit:** from the repo root,

```
git config core.hooksPath .githooks
```

`core.hooksPath` is **per clone** and git does not read `.githooks/` without it, so **both**
hooks there are **inert in every fresh clone until this is run** — the pre-commit
`gitleaks protect` scan and the `commit-msg` conventional-commit check (which is why 8 of
`task-2-api-core`'s 36 subjects exceed its 72-character limit) —
`PROJECT_INSTRUCTIONS.md §10 › REPO` states "gitleaks in pre-commit and in CI" as a fact, and only
the CI half was ever true by default. Found on 2026-10-26 with 36 commits on
`task-2-api-core` none of which had been scanned pre-commit (history is clean —
`gitleaks detect` over all 83 commits finds nothing — but that is luck, not the control).
**Verify it bites, and the exact string matters.** gitleaks' `github-fine-grained-pat` rule is
length-exact — `github_pat_` followed by **exactly 82** word characters — so a plausible-looking
shorter token reports `no leaks found`, exits 0 and the commit LANDS, which reads as "the hook is
still inert": the very condition this step exists to rule out. Measured on 2026-10-27 with 65 and
71 characters after the prefix (both reported no leaks, both committed) and with 82 (`leaks found:
1`, exit 1, nothing committed). So:

```
python3 -c "import random,string; print('github_pat_'+''.join(random.choice(string.ascii_letters+string.digits) for _ in range(82)))" > leak.txt
git add leak.txt && .githooks/pre-commit   # must print 'leaks found: 1' and exit 1
git reset -q HEAD leak.txt && rm leak.txt
```

Run the hook directly rather than committing, so a pass does not leave a commit to undo. The hook
fails **closed** if gitleaks is not installed — `set -e` plus a 127 exit — which was checked rather
than assumed.

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
