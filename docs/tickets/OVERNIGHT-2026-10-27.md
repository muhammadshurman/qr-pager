STATUS (SUPERSEDED 2026-10-27, LATER THE SAME DAY): R-083 IS FIXED AND CLOSED, and so are QA M-6 and security L-1. Muhammad ruled all three; the branch is at 056d7a1, gate green at 642 passed / 3768 assertions, and what remains before the tag is the §11 review of the fix diff. Read docs/PLAN.md §0 for current state — this file is now a record of the overnight run and no longer states where the project is.

> **The original STATUS line, kept because the rest of this file was written under it:**
> TASK-3 TAGGED, TASK-4 BLOCKED — R-083: the outbox lease covers the whole batch but rows are sent one at a time, so a second worker re-sends; it falsifies Task 4's own §3.1 invariant and every fix is a design choice reserved to you.
>
> **What changed.** The design choice was taken: RE-LEASE PER ROW plus a device safety cap, chosen over the four alternatives because it makes correctness independent of batch size, device count and network latency. The two-process proof this file records as deliberately NOT written (assumption 12) is now `bin/outbox-lease-proof`, and it was RED before the fix at 12 requests for 8 rows. `R-074` closed too — Muhammad wrote the rank-2 sentence himself and it is mirrored into the repo's `PROJECT_INSTRUCTIONS.md §4 › Realtime`.

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

## Phase C — the Task 4 `§11` reviews

Both ran as separate agent sessions that did not write the code, each receiving **the diff
(`git diff task-3..task-4-notifications`) and `docs/tickets/QRPAGER-11.md` only**, plus
`PROJECT_INSTRUCTIONS.md` and `PLAN.md` as their own role definitions require. Neither was
given the handover, this file, or the other's output; both state in their reports that they
opened none of them. Neither ran the suite — a second concurrent runner corrupts the shared
test database (`R-049`) — and each named what it wanted run instead. Both requests were run;
results below.

### The counts

**24 findings: 0 Critical, 3 High, 9 Medium, 12 Low.** QA reported **13** (0 / 2 / 4 / 7);
Security reported **11** (0 / 1 / 5 / 5). Security labels its Mediums `M-2`…`M-6` with no
`M-1`; the count and the list agree, so the labels are relayed exactly as given rather than
renumbered.

**The two reviews found the same High independently, from two directions** — QA from the
batch-wide lease, Security from unbounded devices per session. One row, `R-083`.

### QA — 13 findings: 0 Critical, 2 High, 4 Medium, 7 Low

| # | Severity | Finding | Disposition |
| --- | --- | --- | --- |
| **H-1** | High | The lease is taken for the whole batch at claim time but rows are processed sequentially, so a batch that outlives its lease lets a second worker re-claim and re-send — "nothing duplicated" fails for the multi-worker configuration the design claims to support | **BLOCKS THE TAG**, `R-083`. Verified myself: `claim()` leases every row in one transaction and commits, `pass()` then walks them one at a time. No attacker needed at `batch_size: 100`, `lease_seconds: 60` |
| **H-2** | High | `PagerDelivery::Permanent` is documented in three places to dead-letter the row; the worker marks it `sent` with `last_error = null`, and the test named for the behaviour asserts the opposite without ever reading the row | **FIXED** with a regression test, proven red without the fix. All-permanent now dead-letters with a recorded reason; a second case pins the other half (one device delivered, another refused → still `sent`) |
| **M-3** | Medium | Two worker state-machine branches have no test, including the only database failure/timeout path inside the worker | **§5 `R-088`**, owner **Muhammad**, before the `task-4` tag — Task 4 has no successor to inherit its own gaps |
| **M-4** | Medium | The command's continuous mode and SIGTERM handling are untested while the test file asserts in writing that every line is driven | **§5 `R-088`** for the proof; the false sentence in the test header is **corrected in place** |
| **M-5** | Medium | Refusal branches on the customer read path have no test: wrong-alphabet token on any route, unknown token on either subscription verb, every refusal through `DELETE`, and a pointer hit with the projection gone | **§5 `R-088`**, same owner and round |
| **M-6** | Medium | The gate clause "every polling response carries `poll_after_seconds`" is not literally met: the 404 `Stale` answer carries no pacing value | **YOURS — left open.** Either `stale()` carries the value or `§3` row 4 is narrowed, and amending your own gate to match your own code is exactly Task 3's security `S-1` defect. Not a `§5` row, because a gate amendment is rank-2 |
| **L-1** | Low | "Floored at 1 s" is stated as present-tense fact in `§2.13` and in `config/pager.php`; the code removed the floor | **CORRECTED IN PLACE**, both sites |
| **L-2** | Low | `§0` claims all four dead assertions are revert-proved; the proof covers three | **CORRECTED IN PLACE** — `§0` now says three revert-proved and the fourth re-run |
| **L-3** | Low | `§4a` and `§3.2` still attribute `OpenApiSpecTest`'s sweep to Task 2, which `§0` corrected the same day and which `git log -S` contradicts | **CORRECTED IN PLACE**, both sites, with the commit named |
| **L-4** | Low | `§0`'s two cells disagree about which `§3.2` delta carries Task 4's rows | **CORRECTED IN PLACE** — both say eleventh |
| **L-5** | Low | The ticket's published-contract diagram lists `ready_at` on the poll response; neither the code nor the committed spec has it | **CORRECTED IN PLACE** in the ticket, with a note that the resolve response does carry it |
| **L-6** | Low | The only thing bounding a hung push edge is config no test reads, and one test's comment claims to pin `allow_redirects` when it pins only the destination | **§5 `R-090`** for the assertions; the comment's claim **corrected in place** |
| **L-7** | Low | "Loud rather than silent" describes code that is silent — a bare `continue` with no log and no counter | **CORRECTED IN PLACE** — the comment now says the arm is defensive and silent, and why skipping is right either way |

### Security — 11 findings: 0 Critical, 1 High, 5 Medium, 5 Low

| # | Severity | Finding | Disposition |
| --- | --- | --- | --- |
| **H-1** | High | Nothing caps push endpoints per session and the worker sends to all of them sequentially, so one token holder can make a row's processing exceed its own lease — the same root as QA `H-1`, reached from the device side | **BLOCKS THE TAG**, `R-083`. Verified: `forSession()` has no `LIMIT`, the per-send worst case is 3 s connect + 8 s request, the lease is 60 s, and the code states it has no per-device cursor |
| **M-2** | Medium | The raw QR token is accepted from the URL **query string**, so "never in a URL query string" is enforced by client convention only | **FIXED** with a regression test over all four routes, proven red without the fix. Measured independently before fixing: `?token=` was honoured on the JSON POST **and** the DELETE |
| **M-3** | Medium | Arbitrary `https://` push endpoints make the worker an SSRF primitive with a 1-bit existence oracle (201 vs 200 on re-registration) | **§5 `R-084`**, owner Task 12 with Task 13 for egress |
| **M-4** | Medium | No per-tenant rate limit anywhere on the customer surface, and the failure counter is global so nothing names the tenant under attack | **§5 `R-085`**, owner Task 12, whose gate says "per-tenant + per-IP limits in Redis" verbatim |
| **M-5** | Medium | Tenant data enters Redis for the first time with no boot-time requirement that Redis is authenticated or encrypted; `BootChecks` has a clause for everything else and none for Redis | **§5 `R-086`**, owner Task 13 with `R-008` |
| **M-6** | Medium | The VAPID fail-closed boot check is wired only to the HTTP process, which never sends a push; `outbox:work` evaluates no check | **§5 `R-087`**, owner Task 13 with `R-077`; the docblock's posture claim is **corrected in place** |
| **L-1** | Low | `PROJECT_INSTRUCTIONS.md` (rank 2) was not amended for the non-tenant-namespaced pointer key; the permitting reading lives only in rank-3 `PLAN.md`. **This is the reviewer's ruling on `R-074` / ticket `§8.1`, which the ticket assigned to this review** — on the merits it judged the key acceptable, and found the *record* wanting | **YOURS — left open, and it is the one thing the ticket explicitly asked a reviewer to rule on.** One dated sentence in `PROJECT_INSTRUCTIONS.md §4 › Realtime` permitting exactly one key whose name is the secret the caller must present. A rank-3 file cannot qualify a rank-2 clause, and a session must not edit rank 2 |
| **L-2** | Low | The `customer` middleware group is empty, so a fifth route added to that file would default to unauthenticated, unlimited and unshed — and the compensating test iterates a hardcoded list of four names | **§5 `R-089`(a)**, owner Task 12 |
| **L-3** | Low | An enumeration's magnitude is not observable: one log line per hour at exactly the threshold, so 50 attempts and 5,000,000 read alike | **§5 `R-089`(b)**, owner Task 13's metrics path |
| **L-4** | Low | `GuzzleHttp\Client` is used by production code but is only a transitive dependency of `apps/api` | **§5 `R-089`(c)**, owner Task 12. Not fixed tonight: a dependency constraint added unattended is not worth the risk for a latent fault |
| **L-5** | Low | A Redis outage removes the only bound on the Postgres-backed customer route and silences its failure counter at the same moment, widening `R-027` | **§5 `R-089`** context; `R-027` already carries the class with owner Task 12 |

### What both reviewers asked to be run, and what it showed

- **Security:** the six customer/worker test files on this commit — `69 passed / 700 assertions`.
  Every one green while `H-1` is live, which is the reviewer's own point: no existing case
  covers the property.
- **QA, for `H-2`:** assert the row's status in `it dead-letters a 400 immediately` and watch
  it fail on the current head. Done, as the fix's regression proof: **red before, green
  after**.
- **QA, for `H-1`:** a two-process proof showing duplicate sends today. **Not written** — it
  is the acceptance evidence for a fix whose design is yours, so building the harness before
  the design is chosen would prejudge it. `R-083`'s closing gate names the proof precisely.
- **QA, for `§11` item 7:** `bin/vacuous-assertion-proof` plus the two guard invocations.
  The proof ran green in Phase B (all three invariants red without their fix); `composer
  guard` is clean over 190 files and its fixture case is one of the nine green rule cases.

### Figures at the final head `d7b004a`

| What | Figure | Commit |
| --- | --- | --- |
| `composer gate` | **all six steps green, exit 0 — 624 passed / 3668 assertions** (622 / 3641 before the `§11` fixes; the +2 are H-2's two cases and M-2's) | `d7b004a` |
| coverage, this task's code | **96.66 % (1013/1048) over 24 files**, `--min=90` passing (96.60 %, 994/1029 at `bef66a3`) | `d7b004a` |
| fresh clone | **622 / 3641, identical to the host** | `bef66a3` — not re-run after the two fixes, which add two in-process cases and touch no file a clone resolves differently |
| `p99` of record | **2.931 ms** vs a 5 ms budget | `bef66a3` |
| kill mid-batch | 8 sent, 0 pending, 0 dead, attempts 2 of 6 | `bef66a3` |
| migrations | five checks pass; Task 4 adds no migration | `4f27a9d` (Task 3's run) |
| vacuous sweep | 72 files, **zero** real instances | `bef66a3` |

Transcripts for the gate and coverage name `d7b004a`; the other four name `bef66a3` and say
so, because re-running a kill proof and a latency harness after two test-only commits would
produce a new number without new information.

### Why Task 4 is not tagged

`§11`'s merge rule: a High blocks the tag until it is fixed with a regression test. `R-083`'s
fix is a choice between re-leasing per row, renewing the lease, bounding `lease_seconds` by
`batch_size × worst case × devices` (which needs a device cap — a product decision), adding a
per-device cursor (which `R-078` already circles), or sending concurrently under one budget.
Each is a different design for the same invariant, and **"any finding that would change Task
4's design rather than fix a defect" is on your list, not mine.** So the branch is left
pushed, green, and untagged, with the finding recorded as `R-083` and its closing gate
written.

**Nothing is at risk while it waits:** `R-077` records that no deployed environment runs
`outbox:work` at all, so the duplicate-send window has no production exposure today.

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

8. **`H-2` was fixed by changing the CODE, not the three documents.** `PagerDelivery`,
   `WebPushDispatcher` and ticket `§6.2` all said `Permanent` dead-letters; only the worker
   disagreed, so the majority was not the tie-breaker — the behaviour was. A notification no
   retry can deliver recorded as `sent` is a false record in the one table an operator reads.
   *Rejected:* correcting the three documents to say "sent", which would have made the
   repository consistent and the record wrong.
9. **`H-2`'s condition is "nothing reached anybody", not "a permanent failure happened".**
   *Rejected:* dead-lettering any row with a permanent refusal, which would turn a
   notification that one of a customer's two devices received into a lost one — the exact
   failure this product exists to prevent. A second case pins that half.
10. **`M-2` refuses the request rather than ignoring the query parameter.** *Rejected:*
   silently reading the body only, which would answer 404 or 422 for a token the caller
   believes is valid and teach the client developer nothing.
11. **Mediums went to `§5` and only two findings were fixed.** Your `C3` says Medium and Low
   go to `§5` with an owner; `H-2` is a High, and `M-2` would have meant accepting a
   documented `§4 › Data` invariant breach into the ledger, which `§11` does not sanction.
   *Rejected:* fixing more of them — `M-5`'s Redis boot clause, `M-6`'s worker boot check,
   `L-4`'s dependency constraint — each of which is defensible and none of which was asked
   for tonight. They are rows with owners and closing gates instead.
12. **The two-process duplicate-send proof QA asked for was NOT written.** *Rejected:*
   writing it anyway. It is the acceptance evidence for a fix whose design is yours, and a
   harness built around one of the five candidate designs would prejudge the choice.
   `R-083`'s closing gate specifies it precisely so it can be built in one sitting.

## Where it stopped

**`R-083` is why `task-4` has no tag.** `§11`'s merge rule makes a High block the tag until it
is fixed with a regression test; this one falsifies Task 4's own `§3.1` row 4 invariant
("nothing lost, nothing duplicated"), and every fix available is a different design:

| Candidate fix | What it costs |
| --- | --- |
| Re-lease each row immediately before its send | smallest change; one extra statement per row |
| Renew the lease during a long row | needs a heartbeat inside the send loop |
| Bound `lease_seconds ≥ batch_size × worst case × devices` | needs a **device cap**, which is a product rule about how many devices one order may notify |
| A per-device cursor | the largest change, and `R-078` already circles this ground |
| Send concurrently under one total budget | changes the worker's shape and its failure modes |

**Nothing is at risk while it waits.** `R-077` records that no deployed environment runs
`outbox:work`, so the duplicate-send window has no production exposure today.

**Two more things are yours and are not `§5` rows:** QA `M-6` (the `Stale` 404 carries no
`poll_after_seconds`, so `§3` row 4's clause is not literally met — carry the value, or narrow
the clause, which is a rank-2 amendment) and Security `L-1` (`PROJECT_INSTRUCTIONS.md §4 ›
Realtime` needs one dated sentence permitting the pointer key; the reviewer judged the key
acceptable on the merits and the record wanting, and that is the ruling ticket `§8.1` asked a
reviewer for).

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
