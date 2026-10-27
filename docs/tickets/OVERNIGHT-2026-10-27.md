STATUS: TASK-3 TAGGED, TASK-4 BLOCKED — Phase B/C still running in the overnight session; this line is rewritten at each phase boundary

# Overnight run, 2026-10-27 — close Task 3, rebase and close Task 4

> Written and committed at each phase boundary, not once at the end, so that whatever
> state the session ends in, this file is true when you open it. **The STATUS line above
> is the only summary that matters; everything below it is detail.**
>
> Task 5 was not started.

## What to do first when you open the repo

1. **Read the STATUS line.** If it says `TASK-4 BLOCKED`, the one-line reason is on it and
   the detail is in *Where it stopped*, below.
2. `git fetch --all --tags`, then `git log --oneline --decorate -5 task-3-pager-domain` and
   `git tag -l`. You should see `task-3` at **`f30bd24`**.
3. **Nothing is merged and no PR exists**, by instruction. `main` is untouched at
   `3fcabb5`; both task branches are pushed.
4. The pre-rebase head of `task-4-notifications` is parked — see *Parked refs*. Nothing in
   this run can lose it.
5. **One thing is still yours and still owed**: the manual iOS step, restated at the end of
   this file.

## Phase A — Task 3 closed

**A1 was already done before this run started**, and is recorded rather than re-done: QA
`L-4`'s disposition, the held `L-5`'s disposition and the `bootstrap/app.php` comment that
said the command is "registered and not scheduled" were committed at `fc77889`, with the
figure-naming correction at `4f27a9d`. The working tree was clean when the run began.

### A2 — the figures, each with the commit it was measured at

| `§6` clause | Result | Commit | How |
| --- | --- | --- | --- |
| the six gate steps | **all six green, exit 0 — 513 passed / 2383 assertions** | `4f27a9d`, re-measured identically at `f30bd24` | `composer gate`; transcript `docs/tickets/QRPAGER-10-gate.txt` |
| a fresh **clone**, not just a fresh database | **green in the clone — 513 / 2383, identical to the host** | `4f27a9d` | `bin/fresh-clone-proof`; transcript `QRPAGER-10-fresh-clone.txt` |
| coverage ≥ 90 % on this task's code | **97.47 % (733/752) over 27 files**, passing | `4f27a9d` | `bin/coverage --task=task-2 --min=90`; transcript `QRPAGER-10-coverage.txt` |
| migrations apply from empty · second apply a no-op · clean rollback | **all five checks passed** | `4f27a9d` + this round's `.env` read | `bin/migration-rollback-proof`; transcript `QRPAGER-10-rollback.txt` |

**The fresh-clone script is new, and `R-071` is why.** That row says `§6`'s fresh-clone
clause had been claimed at a head where nothing had measured it. `bin/fresh-clone-proof`
clones through `file://` so no object is shared with the working tree, and **refuses to
continue** unless the clone arrived with no `vendor/`, no `.env` and no `node_modules`. It
runs the whole gate inside the clone and prints the counts so the two runs can be
**compared**: they are identical. The `§5` row itself stays open for its owner — a
mechanism is not a closure.

**The rollback proof's first unattended run failed**, and the reason is kept because it is
the more useful half: security `L-5` had just removed its fallback superuser password,
correctly, so the script required `DB_ROOT_PASSWORD` and a background shell carried none. It
now reads those four names from `.env` when they are not exported (an exported value still
wins), because *"load `.env` first"* is how a script stops being run — `R-043`'s clause.

### A3 / A4 — the record and the tag

- `f30bd24` carries the four transcripts, `bin/fresh-clone-proof`, the `.env` read, `PLAN.md
  §4a`'s evidence table and `QRPAGER-10 §11`.
- **`task-3` is an annotated tag at `f30bd24`**, pushed. Its message carries the four
  figures, the review history and the three things it deliberately does not close.
- No PR, no merge — `§6`'s "commit + tag task-N" and `§11`'s PR step are both overridden by
  tonight's rank-1 instruction, which authorises the tag alone.

## Phase B — rebase Task 4

*(in progress — this section is rewritten when the phase ends)*

## Phase C — the Task 4 reviews

*(not started — this section is rewritten when the phase ends)*

## Assumptions made, and the alternative rejected

1. **A1 was treated as already satisfied rather than re-committed.** The three items it
   names were committed earlier today at `fc77889`. *Rejected:* committing them again as
   empty or duplicated changes, which would put a false entry in the history to match the
   instruction's wording.
2. **The `task-3` tag is annotated, carrying the figures.** `task-0` is annotated and
   `task-1`/`task-2` are lightweight, so the convention was mixed and had to be chosen.
   *Rejected:* a lightweight tag, which would make "tagging is the delivery record" depend
   entirely on a commit message that says nothing about the fresh clone or the coverage.
3. **The figures name the commit they were measured at, not "the head".** Twice today a
   sentence saying "the pushed head" was made stale by the commit that followed it.
   *Rejected:* re-running every measurement after every documentation commit, which
   recurses without converging.
4. **`bin/migration-rollback-proof` now reads `.env` itself.** *Rejected:* exporting the
   variable in the session that ran it, which would have left the proof unrunnable for you
   in the morning — the exact defect `R-043` exists for.

## Parked refs

| Ref | Points at | Why |
| --- | --- | --- |
| `task-3` | `f30bd24` | Task 3's delivery record |
| *(Phase B adds `parked/task-4-pre-rebase` here)* | | |

## Still yours, still owed — the manual iOS step

**iOS Safari push is verified by hand, on a real device, by Muhammad.** It cannot be done
from a session: it needs an iPhone, the PWA added to the home screen, and a notification
observed arriving. `R-073` records it and **Task 9 owns it** — moved there from Task 4
because Task 4 builds the server half and Task 9 builds the client that asks for
permission.

Your ruling on the server half stands and is not implemented by this run unless a review
finding forces it: **payload-shape tests belong to the server half** — encrypted payload
≤ 4 KB, the TTL and urgency headers, the `aps` structure — while **iOS itself stays in Task
9**. If Phase C produced a finding that touches it, it is listed in Phase C with that
ruling quoted beside it.
