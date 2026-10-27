STATUS: TASK-3 TAGGED, TASK-4 BLOCKED — rebased and green at bef66a3; the §11 reviews are the remaining work. This line is rewritten at each phase boundary.

# Overnight run, 2026-10-27 — close Task 3, rebase and close Task 4

> Written and committed at each phase boundary, not once at the end, so that whatever
> state the session ends in, this file is true when you open it. **The STATUS line above
> is the only summary that matters; everything below it is detail.**
>
> Task 5 was not started.
>
> **This file is maintained on `task-4-notifications`** from Phase B onward, because that is
> the branch the run continues on. `task-3-pager-domain` carries the Phase A version of it at
> `d7e55fc`; the two agree about Phase A and only this one has Phase B and C.

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

## Phase B — Task 4 rebased onto the task-3 tag

**Rebased head `bef66a3`** (12 commits on top of `task-3`), force-pushed — pre-authorised for
this branch only. The pre-rebase head is parked and pushed at
**`parked/task-4-pre-rebase` = `80769d9`**, so nothing in this run can lose it.

### B1 — the rebase, and the five conflicts it produced

`git rebase --onto task-3 1695f0b task-4-notifications`. Eleven commits replayed; five
conflicts, every one resolved by **which sentence is true on the rebased branch**, which is
the only question a conflict between two comments can be resolved by:

| Where | Resolution |
| --- | --- |
| `CreatePagerSessionRequest`, `ReadinessController` | **Task 3's** wording. The sentence is about *where the docblock move happened*, and after the rebase it happened in Task 3's history — so "MOVED OUT OF THE DOCBLOCK, FOUND AT TASK 4" is the true one |
| `GrantMatrixTest`, `PagerTokenTest` | **Task 4's** wording. Those sentences are about whether rule 7 and `ExpectationApiTest` EXIST. On Task 3 alone they did not, which is what Task 3's text said; on the rebased branch they do, so Task 4's text is true and Task 3's would be the false claim |
| `guard/src/SourceGuard.php`, `RepoGuardTest.php` | **both**, rule 7 then rule 8 — two additive blocks |
| `docs/PLAN.md` §0 | **Task 4's** (the later state), then corrected in its own commit: §0 is "the only place that states current state", and after the rebase neither side was it |
| `docs/PLAN.md` §3.2, §4a, §5 | **both**. Task 4's delta renumbered to the **eleventh** and its running count re-chained — 73 → 76 → 83, not 73 → 80 |

Two merges needed more than "keep both", and both were caught by `php -l` before anything was
staged: in `SourceGuard.php` the shared `/**` above the conflict belonged to whichever side
came first, so rule 8's docblock lost its opener; and in `RepoGuardTest.php` **both** sides
ended mid-function, each expecting the single `});` that followed the marker, so the first
side needed its own closer.

**`composer install` is required after this rebase** and is easy to miss: Task 4 adds
`minishlink/web-push`, so the lock file changed while `vendor/` was still Task 3's. The first
gate failed at `composer stan` with "class not found" for exactly that reason.

### B2 / B3 — the duplicates, and the two rules meeting

- **`docs/claude/PLAN.md` and `docs/claude/BLUEPRINT.md` are gone, removed by the rebase
  itself** rather than by a new commit: Task 3 deleted them (QA `H-1`) and Task 4 never
  touched them, so the deletion is simply in the base. `git ls-tree` over `docs/claude/`
  returns **0 entries**.
- **Rules 7 and 8 both exist and both pass, with NO collision** — the number reservation
  held. Rule 7's constants are `VACUOUS_NEGATED_CONTAINS` and `VARIADIC_MATCHERS`; rule 8's
  are `DUPLICATE_CANONICAL_DOC`, `CANONICAL_DOCS` and `TASK_TABLE_HEADING`. **Nine** cases
  exercise the two rules and all nine are green; `composer guard` is clean across **190**
  files. The comment that explained the reservation now records that it worked.

### B4 — every figure re-measured at `bef66a3`

| What | Before the rebase | After | What moved |
| --- | --- | --- | --- |
| `composer gate` | 601 / 3428 at `5f1bdbf` | **622 passed / 3641 assertions**, six steps, exit 0 | +21 cases, +213 assertions — Task 3's own additions arriving |
| fresh clone | identical, proven by hand | **identical, 622 / 3641**, via `bin/fresh-clone-proof` | the script arrives from Task 3 |
| coverage, this task's code | 96.50 % (964/999), 25 files | **96.60 % (994/1029), 24 files**, `--min=90` passing | the base ref moved from the BRANCH `task-3-pager-domain`, which moved under it, to the TAG `task-3`, which cannot |
| `p99` of record | 2.730 ms at `5d89234` | **2.931 ms**, batches 6.479 / **2.931** / 3.395 | +0.201 ms, inside its own spread |
| kill mid-batch | 8 sent, 0 pending, 0 dead, attempts 2 of 6 | **unchanged**, re-measured | nothing |
| migrations | five checks pass | **unchanged** — Task 4 adds no migration | nothing |

**Batch 1 of the p99 run came in ABOVE budget at 6.479 ms**, on a machine at load 1.12. That
is why the method takes the best of three rather than the first, and it is stated here
because a reader who sees only "2.931 ms" would not know the run contained a 6.479.

Transcripts, each naming `bef66a3`:
`docs/tickets/QRPAGER-11-{gate,coverage,fresh-clone-gate,p99,outbox-kill-proof,vacuous-assertion-proof}.txt`.
`QRPAGER-11-handover.md §11` keeps each superseded figure beside its replacement.

### B5 — the sweep: 72 test files, ZERO real instances

The one hit is `ExpectationApiTest:133` — rule 7's own positive control, excepted by file
**and** function name. The first inventory reported **three**; the two extra were
single-argument calls with a **trailing comma** in `TenantScopeGuardTest`, which the counter
read as a second argument. Corrected before the number was written anywhere.

### ⚠ The rebase found a defect, which is what a rebase is for

Task 3's security post-processing step was written when the API had two kinds of route — two
probes and five staff endpoints — so it said *"a probe, or else staff"*. The moment Task 4's
**customer** routes landed on the same document, **all four were published as requiring a
staff bearer token**: a contract telling a customer to present a staff credential, on the one
surface `§4 › Tenancy` insists must never be confused with it. A customer authenticates by
presenting the QR token in the request **body**, has no account and resolves no tenant.

Nothing about the application was wrong. The generated document was, and Tasks 8–9 generate
clients from it. Fixed at `bef66a3`: **three** surfaces, with the customer set read **from the
router** — membership of the `customer` middleware group — rather than a second
hand-maintained constant, because the failure mode of forgetting to edit a list is a route
published as needing a credential it does not use. The spec's problem-response count moved
from 14 to **26** as Task 3's validation extension and media-type step reached Task 4's
routes; the committed-file case and the in-process generator case both assert the new number.

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

5. **The five rebase conflicts were resolved by which sentence is TRUE on the rebased
   branch**, not by a blanket "take theirs". Two went to Task 3's wording and two to Task
   4's, for opposite reasons, both recorded in Phase B. *Rejected:* `-X ours`/`-X theirs`,
   which would have silently dropped one side of every conflict — including, in the
   `SourceGuard` case, an entire guard rule.
6. **`R-082` was left open and rule 7 was not extended.** Its closing gate names this moment
   ("before `task-4` is tagged"), so leaving it is a deviation from the row and is recorded
   as one. *Why:* extending rule 7 to the three slot-2 matchers means flagging
   `->not->toThrow($class, 'message')`, which is Pest's **documented** idiom for asserting an
   exception message — a policy binding every future test in this repository, and therefore
   yours rather than a 2 a.m. guard edit. The measurement is in hand: **zero** instances
   today, so nothing is inert while the row stays open. *Rejected:* extending it anyway,
   which would have put an unreviewed policy change into the diff the `§11` reviewers read.
7. **The three-surface spec fix was treated as a defect, not a design change.** The
   application's behaviour is untouched; only the generated document was wrong. *Rejected:*
   stopping and leaving it for you, which would have meant tagging Task 4 — or asking you to
   — on a published contract that tells customers to present a staff credential.

## Parked refs

| Ref | Points at | Why |
| --- | --- | --- |
| `task-3` | `f30bd24` | Task 3's delivery record |
| `parked/task-4-pre-rebase` | `80769d9` | the pre-rebase head of `task-4-notifications`, pushed **before** the rebase began |
| `task-4-notifications` | `bef66a3` and later | force-pushed by the rebase, which was pre-authorised for this branch only. `main`, `task-3-pager-domain` and every tag were never force-pushed |

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
