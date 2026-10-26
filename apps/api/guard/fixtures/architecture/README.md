# Deliberately bad files

One per named architecture guard (`PLAN.md §3`, row 2). They are `.php.fixture`, not `.php`, so
nothing autoloads, formats or analyses them, and they live outside `./app` so `deptrac.yaml` never
sees them in an ordinary run.

`tests/Feature/Architecture/ArchitectureGuardTest.php` copies one into the layer it violates, runs
**the real `deptrac.yaml`** — not a second copy of the ruleset, which would be a second source that
could drift from it — asserts deptrac exits non-zero and names the dependency, then deletes it in a
`finally`. A crash therefore leaves a file that makes every later `deptrac analyse` fail loudly,
which is the opposite of `R-018`'s shape: nothing shared is mutated and nothing self-repairs
silently.
