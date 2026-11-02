# QRPAGER-26 — Task 10b · The board, and the act of marking ready

*Committed mirror of the Jira description. The board is authoritative;
`muhdshurman.atlassian.net/browse/QRPAGER-26`.*

> **Read first:** `docs/PLAN.md §0` (current state), then `docs/PROJECT_INSTRUCTIONS.md` (§0, §4, §6, §11, **§12**), the rest of `docs/PLAN.md`, `docs/adr/`.
> This ticket never restates a rule from those files. On disagreement: **stop and report (§0).**

**This is ticket 2 of 4 in the Task 10 split, ruled by Muhammad on 2026-11-02.** The ruling is on `QRPAGER-17` and is recorded in `PLAN.md §1`. `§3` row 10 is **not** reworded.

**Blocked by:** 10a. **Blocks:** 10c.

---

## 0. The named falsifiable invariant — written before the work

> **Two staff marking the same session ready at the same instant produce exactly ONE customer notification and ONE terminal state, and both boards converge on it within one poll interval. Asserted by driving two concurrent clients against the real API.**

Never by trusting Task 3's idempotency from the client side. That is `§3` row 10's *"board stays correct with two staff acting at once"* **with _correct_ defined**, which is the gap `§3.1` row 10 records.

## 1. THIS TICKET WRITES `§3.1` ROW 10's TWO GAPS — before its first implementation commit

`§3.1` row 10 records: *"Under 2 s to the customer device" is a wall-clock threshold with no stated method (`F10`'s class)* and *"The board stays correct" needs **correct** defined — which rows, in which order, after which interleaving.*

### (a) The "under 2 s" method — **approved as proposed, 2026-11-02 by Muhammad**

* Measured from the **staff click** to the **customer's RENDERED ready state**.
* On the **real stack**.
* Over the **POLLING path**, because push is Apple's and Google's and is not drivable by a runner (`R-211`).
* **Five runs**; the **median** is the figure; **all five reported**.
* The **load-invariant half stated beside it**, because a wall-clock number moves with the runner — the same reasoning that moved `R-205`.

### (b) "The board stays correct"

**"Correct" is defined by the invariant in §0.** That definition — not a paraphrase of it — is written into `§3.1` row 10.

## 2. Scope — nothing else

The active board (**polled, per `O2` — never a persistent connection**), pager detail, `markReady`, `renotify`, `cancel`.

## 3. Standing invariants this ticket is most likely to break — say in a comment here how each is satisfied

* **C5** — the outbox worker sends Web Push, **never** the HTTP request. The request commits status + outbox row in **ONE** transaction and nothing else.
* **`markReady` is idempotent; a retry never double-notifies.** That is half the §0 invariant.
* **The polling endpoint is a Redis-only read:** zero Postgres queries, p99 under 5 ms, client-side jittered interval with exponential backoff, short-TTL cache headers. **One Postgres query there is a rejected design.**
* **Every Redis key holding tenant data is namespaced by tenant id, derived from the caller's own context, never from client input.** Redis carries no RLS.

## 4. Out of scope

| Not here | Owned by |
| --- | --- |
| Login, shell, session lifetime and lock, shift/device settings | **10a** (settled there — consume it, do not re-decide it) |
| KDS view and the offline resync | **10c** |
| History with filters | **10d** |
| Issuing a pager sheet, and the reprint ruling | **10d** |
| The transitions themselves | Task 3 |
| Notification delivery, the outbox, the dispatcher | Task 4 |

## 5. Pre-coding gate — answer all seven in a comment on this ticket, before the first implementation commit (§6)

1. Which tables / columns / roles / GUCs does this touch? Any change to the data contract → `PLAN.md` **first**.
2. Which shared contracts (statuses, enums, DTOs)? One source only, never a duplicated enum.
3. The exact path by which tenant context reaches **every** query.
4. Security: authn, authz, input validation, rate limit, log redaction, secrets.
5. Scale: **how many pagers on a board before it degrades. What is bounded.**
6. Failure: timeout, retry, duplicate, crash mid-way — **and two staff marking the same order ready simultaneously.**
7. Research done for anything version-specific, **verified by running code in-session**. Paste the output.

## 6. Acceptance gate

* [ ] **The invariant in §0, by two concurrent clients against the real API.**
* [ ] **`§3.1` row 10's two clauses written** (§1 above) before the first implementation commit.
* [ ] The "under 2 s" figure, by the method in §1(a): five runs, median reported **with all five**, load-invariant half beside it.
* [ ] Each of §3's four standing invariants answered **and asserted**, not stated.
* [ ] `§6` in full: `composer gate`, `pnpm gate`, `pnpm run gate:e2e`, fresh-clone proof, ≥ 90% coverage on added code, OpenAPI current, rollback clean.
* [ ] `§12` in full — see §8.

## 7. Known traps

* **ALREADY SOLVED:** the correct second push on an existing session is **`renotify`**, NOT `markReady` on a fresh session, because `push_subscriptions.pager_session_id` binds the subscription to the session.
* Idempotency is enforced in Task 3, but the UI must not **assume** it. A double-tap, a slow network retry and two devices are all the same event — the board must **converge**, not double-count.
* `php artisan test` cannot run from a git worktree. The two heavy gates must not run at the same time (`R-200`). `PAO_DISABLE=1` for a by-hand test run.
* An unproven guard is not a guard · a vacuous assertion reads as coverage · assert exact values, not ranges · cross HTTP · `${PIPESTATUS[0]}` · generated tables · a reasoned skip is not a measurement · figures name the commit **and** the toolchain · no narrowing of an assertion without saying so.

## 8. `§12` applies in full — no exemption

Staff surfaces, tokens exist as of Task 8: **RTL, AA contrast, 44 px targets, `prefers-reduced-motion`, visible focus, no library default look, zero-technical-background bar.** A kitchen screen is greasy, bright and viewed from two metres — contrast and target size are **functional** requirements here. Every screen's purpose, all states, content inventory and exact copy written into this ticket before it is coded.

## 9. Working rules

Own branch (from 10a's tag — `git rev-parse` it first), own full `§6` gate, own full `§11` review in its own pinned worktrees with verbatim briefs, **both reports relayed here IN FULL with counts BEFORE any disposition**, final evidence once at the end, own tag with `--cleanup=verbatim` diffed byte-for-byte against its file, CI discharged by `gh`. Push at round boundaries.
