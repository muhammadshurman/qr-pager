# Deliberately bad files — the C1/C2 repo guard

One per rule the guard enforces (`PROJECT_INSTRUCTIONS.md §3 C1/C2`, `§4 › Postgres traps`). They are
`.php.fixture`, so nothing autoloads, formats or analyses them, and `SourceGuard::files()` only picks
up `.php` when it walks a directory — these are handed to it as explicit file paths by
`tests/Feature/Architecture/RepoGuardTest.php`.

Every one of them is a shape that would have been accepted silently before this guard existed
(`R-010`), including `app.current_tenant_id`, which is the name `C1` rejected.
