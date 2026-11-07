# QRPAGER-27 — Task 10c · The KDS, and the offline resync

*Committed mirror of the Jira description. The board is authoritative;
`muhdshurman.atlassian.net/browse/QRPAGER-27`.*

> **Read first:** `docs/PLAN.md §0` (current state), then `docs/PROJECT_INSTRUCTIONS.md` (§0, §4, §6, §11, **§12**), the rest of `docs/PLAN.md`, `docs/adr/`.
> This ticket never restates a rule from those files. On disagreement: **stop and report (§0).**

**This is ticket 3 of 4 in the Task 10 split, ruled by Muhammad on 2026-11-02.** The ruling is on `QRPAGER-17` and is recorded in `PLAN.md §1`. `§3` row 10 is **not** reworded.

**Blocked by:** 10b. **Blocks:** 10d.

---

## 0. The named falsifiable invariant — written before the work

> **A KDS that loses the network mid-shift and reconnects re-sends NO notification and shows NO duplicate row. Asserted by dropping the network in a real browser, counting dispatches on the server before and after the reconnect, and asserting the two counts are equal.**

That is `§3` row 10's third clause verbatim, and it is the hard one.

### The assertion compares a server-side count to a server-side count

**A UI that looks right after reconnect proves nothing** — the duplicate we care about is a **dispatch**, and the **outbox** is the only place it is visible. Since the worker is the only sender (**C5**), a reconnect that re-queues is a duplicate **AT THE OUTBOX**. Prove it there.

## 1. Scope — nothing else

The KDS view and the resync.

* **History, filters and QR issuing are 10d** — out of scope, and **do not build "just the data layer" for them here**.
* **Session lifetime and lock were settled in 10a** — consume that, do not re-decide it.

## 2. Out of scope

| Not here | Owned by |
| --- | --- |
| Login, shell, session lifetime and lock, shift/device settings | **10a** |
| The board, pager detail, mark ready, renotify, cancel | **10b** |
| The "under 2 s" method and the "board stays correct" definition | **10b** |
| History with filters | **10d** |
| Issuing a pager sheet, and the reprint ruling | **10d** |
| Outbox, dead-letter, dispatcher | Task 4 |
| Public landing pages | Task 11 |

## 3. Pre-coding gate — answer all seven in a comment on this ticket, before the first implementation commit (§6)

1. Which tables / columns / roles / GUCs does this touch? Any change to the data contract → `PLAN.md` **first**.
2. Which shared contracts (statuses, enums, DTOs)? One source only, never a duplicated enum.
3. The exact path by which tenant context reaches **every** query.
4. Security: authn, authz, input validation, rate limit, log redaction, secrets. **A KDS screen is unattended in a kitchen** — and 10a settled lifetime and lock, so state **how this consumes it**.
5. Scale: cost per request and per active session. What is bounded.
6. Failure: **network drops mid-shift; the KDS resyncs after a drop**; timeout, retry, duplicate, crash mid-way.
7. Research done for anything version-specific, **verified by running code in-session**. Paste the output.

## 4. Acceptance gate

* [ ] **The invariant in §0** — network dropped in a **real browser**, dispatches counted **on the server** before and after the reconnect, the two counts asserted **equal**.
* [ ] No duplicate row in the KDS view after reconnect, and the row identity is asserted, not eyeballed.
* [ ] `§6` in full: `composer gate`, `pnpm gate`, `pnpm run gate:e2e`, fresh-clone proof, ≥ 90% coverage on added code, OpenAPI current, rollback clean.
* [ ] `§12` in full — see §6.

## 5. Known traps

* **"Resyncs without duplicate notifications" is the hard half.** A naive refetch after reconnect can re-trigger sends.
* The count must be a **server-side** count on both sides of the reconnect. A client-side tally is the thing under test, not the oracle.
* `php artisan test` cannot run from a git worktree. The two heavy gates must not run at the same time (`R-200`). `PAO_DISABLE=1` for a by-hand test run.
* An unproven guard is not a guard · a vacuous assertion reads as coverage · assert exact values, not ranges · cross HTTP · `${PIPESTATUS[0]}` · generated tables · a reasoned skip is not a measurement · figures name the commit **and** the toolchain · no narrowing of an assertion without saying so.

## 6. `§12` applies in full — no exemption

Staff surfaces, tokens exist as of Task 8: **RTL, AA contrast, 44 px targets, `prefers-reduced-motion`, visible focus, no library default look, zero-technical-background bar.** A kitchen screen is greasy, bright and viewed from two metres — contrast and target size are **functional** requirements here. Every screen's purpose, all states, content inventory and exact copy written into this ticket before it is coded.

## 7. Working rules

Own branch (from 10b's tag — `git rev-parse` it first), own full `§6` gate, own full `§11` review in its own pinned worktrees with verbatim briefs, **both reports relayed here IN FULL with counts BEFORE any disposition**, final evidence once at the end, own tag with `--cleanup=verbatim` diffed byte-for-byte against its file, CI discharged by `gh`. Push at round boundaries.
