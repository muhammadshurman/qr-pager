# A directory that resolves and contains no PHP file

This exists so that `SourceGuard`'s rule 6 — *"a directory that contains no `.php` file
counts as unscannable too: 'I walked it and read nothing' is the same vacuous pass as
'I could not find it'"* — has a case it can be tested against.

**It must stay free of `.php` files.** That is the whole of its contract. `README.md` is
here because git does not track an empty directory, and a Markdown file is not something
the guard scans.

## Why it is a fixture and not a real directory

`RepoGuardTest` used to point this case at `app/Features`, with the comment *"app/Features
exists and holds only `.gitkeep` until Task 3 puts a module in it, so it is the real
'walked it and read nothing' case rather than a fabricated one."*

Task 3 put a module in it, and the test went red — not because the guard regressed, but
because the fixture it borrowed had been repurposed by ordinary work. The comment had
predicted its own expiry and nothing acted on the prediction, which is the shape
`PLAN.md §3.2` keeps recording.

A real directory that happens to be empty is a fixture with a deadline. This one cannot be
invalidated by somebody writing a feature.
