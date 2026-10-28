# Rule 9 fixtures — workflow triggers that can exclude a pushed branch

`SourceGuard::scanWorkflowSource()` reads one workflow's text; these are the shapes it has to
get right. Driven by `tests/Feature/Architecture/RepoGuardTest.php`.

| Fixture | What it proves |
| --- | --- |
| `excludes-pushed-branch.yml.fixture` | the real trigger until 2026-10-28 — `[main, 'task-*']` — is a violation (`R-126`) |
| `branches-ignore.yml.fixture` | `branches-ignore` excludes a pushed branch just as surely |
| `every-branch-block-form.yml.fixture` | the passing shape in block-sequence form, so the parser is not only right about the flow form |
| `no-push-trigger.yml.fixture` | a schedule-only workflow is NOT a violation per file |
| `unreadable-trigger.yml.fixture` | a trigger the parser cannot read fails CLOSED |

These files end in `.fixture`, and rule 9 globs `*.yml` and `*.yaml`, so none of them is ever
read as a real workflow — the same reason the repo fixtures are not `.php`.
