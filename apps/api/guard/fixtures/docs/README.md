# Rule 8's deliberately bad documents

`PROJECT_INSTRUCTIONS.md §4 › Postgres traps` requires the repo guard to be **proven to
fail on a deliberately bad file**, and `§7` requires a fix that recurs to have had a
missing regression test. Rule 8 replaces the regression test a deletion cannot have.

| fixture | clause it violates |
| --- | --- |
| `stale-plan.md.fixture` | (a) a second document carrying a task-table heading — C7 |
| `duplicate-blueprint.md.fixture` | (b) a byte-identical copy of `docs/BLUEPRINT.md` |

Both carry the `.fixture` suffix so the guard's own scan — which lists **tracked `.md`
paths** — cannot see them. `RepoGuardTest` copies each into the tree under a real `.md`
name, runs the rule, asserts the violation, and removes it in a `finally`. If one of those
copies ever survives a crash, the guard itself reports it on the next run, which is the
self-correcting property a fixture directory should have.
