# QRPAGER-28 — Task 10d · History with filters, and issuing a pager sheet

*Committed mirror of the Jira description. The board is authoritative;
`muhdshurman.atlassian.net/browse/QRPAGER-28`.*

> **Read first:** `docs/PLAN.md §0` (current state), then `docs/PROJECT_INSTRUCTIONS.md` (§0, §4, §6, §11, **§12**), the rest of `docs/PLAN.md`, `docs/adr/`.
> This ticket never restates a rule from those files. On disagreement: **stop and report (§0).**

**This is ticket 4 of 4 in the Task 10 split, ruled by Muhammad on 2026-11-02.** The ruling is on `QRPAGER-17` and is recorded in `PLAN.md §1`. `§3` row 10 is **not** reworded.

**Why this ticket exists:** 10c as originally proposed carried four surfaces. **Two read/CRUD surfaces with no shared invariant do not belong behind the resync proof.**

**Blocked by:** 10c. **Blocks:** Task 11 (`QRPAGER-18`).

---

## 0. The named falsifiable invariant — written before the work

> **Every history filter combination reachable from the UI returns only rows of the caller's own tenant, proven by issuing each combination cross-tenant over HTTP and asserting the refusal or the empty set — not by reading the query builder.**

**Filters are where a tenant scope gets dropped**, so the assertion **enumerates** them rather than sampling them.

## 1. Scope — nothing else

History with filters, and issuing a pager sheet.

## 2. THE REPRINT QUESTION IS RULED — by Muhammad, 2026-11-02

`PLAN.md §0` records it as owed before this screen is coded. **The ruling: a sheet is RE-ISSUED, never reprinted.**

The QR token is **128-bit CSPRNG stored as a hash only** (`§4 › Data`), so the raw value exists **once, at generation**, and **can never be re-rendered from the database**.

**Reprint is not a feature being declined — it is physically unavailable, and `§4` forbids making it available.**

* [ ] The screen says this **to the staff member in plain words, not system vocabulary**: *printing a new sheet makes the old code stop working.*
* [ ] The action is **destructive in effect**, so `§12` requires it **confirmed** and **clearly marked as not reversible**.
* [ ] **Record the ruling and its reason in `PLAN.md §1`'s decision log as part of this ticket.**

## 3. `§12`'s zero-technical-background bar is the hard half here, not the accessibility half

**A restaurant owner reads no documentation.**

* **No unexplained identifier** anywhere on either screen.
* **No empty state without a next action.**
* **Every error says what to do** rather than what failed.

## 4. Out of scope

| Not here | Owned by |
| --- | --- |
| Login, shell, session lifetime and lock, shift/device settings | **10a** |
| The board, pager detail, mark ready, renotify, cancel | **10b** |
| KDS view and the offline resync | **10c** |
| The transitions themselves | Task 3 |
| Notification delivery, outbox, dispatcher | Task 4 |
| Platform admin and tenant provisioning | Task 6 |
| Public landing pages | Task 11 |

## 5. Pre-coding gate — answer all seven in a comment on this ticket, before the first implementation commit (§6)

1. Which tables / columns / roles / GUCs does this touch? Any change to the data contract → `PLAN.md` **first**.
2. Which shared contracts (statuses, enums, DTOs)? One source only, never a duplicated enum.
3. The exact path by which tenant context reaches **every** query — **including every filter combination.**
4. Security: authn, authz, input validation, rate limit, log redaction, secrets. **The raw token exists once, at generation** — state where it goes and where it must not.
5. Scale: cost per request; what bounds a history query. What is bounded.
6. Failure: timeout, retry, duplicate, crash mid-way — **and a sheet issued but never printed.**
7. Research done for anything version-specific, **verified by running code in-session**. Paste the output.

## 6. Acceptance gate

* [ ] **The invariant in §0** — **every** filter combination reachable from the UI, enumerated and driven cross-tenant over HTTP, each asserting the refusal or the empty set. The combination list is **derived**, not hand-written.
* [ ] The re-issue action is confirmed, marked not reversible, and **the old code is proven to stop working** by driving it after a re-issue.
* [ ] **The raw token never reaches a log, a URL path or query string, or an error body** — asserted against captured output (`§3` row 3).
* [ ] `§12`'s zero-technical-background bar, by the three mechanical clauses in §3 — asserted against the rendered screens, not against intent.
* [ ] `§6` in full: `composer gate`, `pnpm gate`, `pnpm run gate:e2e`, fresh-clone proof, ≥ 90% coverage on added code, OpenAPI current, rollback clean.
* [ ] `§12` in full — RTL, AA contrast, 44 px targets, `prefers-reduced-motion`, visible focus, no library default look.

## 7. Known traps

* `R-115` (QR batch generation + printable sheets, never delivered at Task 6) is **owned by Task 10** — `PLAN.md §4.3`. This ticket is where it lands or is re-owned with a reason.
* A history filter is the classic place a tenant scope is dropped — the gate enumerates rather than samples for that reason.
* `php artisan test` cannot run from a git worktree. The two heavy gates must not run at the same time (`R-200`). `PAO_DISABLE=1` for a by-hand test run.
* An unproven guard is not a guard · a vacuous assertion reads as coverage · assert exact values, not ranges · cross HTTP · `${PIPESTATUS[0]}` · generated tables · a reasoned skip is not a measurement · figures name the commit **and** the toolchain · no narrowing of an assertion without saying so.

## 8. Working rules

Own branch (from 10c's tag — `git rev-parse` it first), own full `§6` gate, own full `§11` review in its own pinned worktrees with verbatim briefs, **both reports relayed here IN FULL with counts BEFORE any disposition**, final evidence once at the end, own tag with `--cleanup=verbatim` diffed byte-for-byte against its file, CI discharged by `gh`. Push at round boundaries.
