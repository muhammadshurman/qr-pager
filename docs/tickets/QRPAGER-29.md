# QRPAGER-29 — Task 10e · Staff-management screens

*Committed mirror of the Jira description. The board is authoritative;
`muhdshurman.atlassian.net/browse/QRPAGER-29`.*

> **Read first:** `docs/PLAN.md §0` (current state), then `docs/PROJECT_INSTRUCTIONS.md` (§0, §4, §6, §11, **§12**), the rest of `docs/PLAN.md`, `docs/adr/`.
> This ticket never restates a rule from those files. On disagreement: **stop and report (§0).**

**This is ticket 5 of 5 in the Task 10 split, and the LAST ticket of Task 10.** It was created on 2026-11-02 by Muhammad's ruling `R-218`, which closed the stop-and-report 10a raised: `R-115`'s third item — *staff-management screens* — was owned by **Task 10** with the clause *"its gate must name all three"* and was in **none** of the four tickets' scopes.

**It is NOT folded into 10d**, and the reason is 10d's own: *"two CRUD surfaces with no shared invariant do not belong behind the resync proof."* Member management has an invariant of its own, below, and it is a different one from history-with-filters.

**Blocked by:** 10d (`QRPAGER-28`). **Blocks:** Task 11 (`QRPAGER-18`), because `§6`'s order rule is *no task starts before the previous one is green and tagged* and this is now the last ticket of Task 10. ⚠ **10d's own `Blocks` link to `QRPAGER-18` is LEFT IN PLACE rather than deleted** — `Blocks` does not mean *the only blocker*, 10d does block Task 11 transitively, and removing a link somebody else created to make a diagram tidier is a mutation nobody asked for. `QRPAGER-18` is now blocked by both, which is accurate.

**Inside Task 10, this satisfies `R-115` with no deferral.**

---

## 0. The named falsifiable invariant — written before the work

> **No member-management action can raise a caller's own privilege or strand a tenant: a manager cannot grant an ability they do not hold, cannot change their own role, and the last owner cannot be removed or demoted — proven by driving each attempt over HTTP and asserting the refusal.**

**Proposed by Muhammad in the `R-218` ruling and CONFIRMED against `RoleMatrix` before this ticket was written, so the gate is not vacuous.** What Task 5 already proves and what it does not, measured:

| Clause | State before 10e |
| --- | --- |
| a manager cannot **grant** a role senior to its own | **NOT proven, and not enforced.** `UpdateMemberRoleRequest` and `StoreMemberRequest` validate `role` against the whole of `TenantRole::cases()` and nothing compares it to the actor's. `R-106` measured it at Task 5's `§11` security review by driving the route. |
| a manager cannot **change its own role** | **NOT proven, and not enforced.** `MemberDirectory::changeRole()` takes the actor id only to write the audit row; the target membership id is never compared with the actor's own. `R-106`: a manager `PATCH /v1/members/{own-id}` with `role: owner` → **200**. |
| the **last owner** cannot be removed or demoted | **PROVEN** — `MemberDirectory`'s last-owner rule, `FOR UPDATE`-locked, driven by `RoleMatrixTest` and by `MemberConcurrencyTest` in two processes. 10e re-drives it **through the screen's own route set** and does not re-prove the lock. |

So two of the three clauses are new enforcement and `§4 › Security` already carries the policy verbatim — *"No actor may grant a role senior to its own, assume one, or change its own role at all"* — which means **no rank-2 edit is needed and none is taken.** This ticket implements a rank-2 clause that has been in force since 2026-10-28 and was never enforced in code.

## 1. Scope — nothing else

The staff-management screens on the staff surface: **list the restaurant's staff, add an existing account, change a member's role, remove a member.** Over `members.index` / `members.store` / `members.update` / `members.destroy`, which exist and are tested at the API layer (Task 5), plus the seniority and self-role enforcement the invariant above names.

**`R-106`'s seniority half is settled here.** Its consent half is already enforced more strictly than the clause (Task 6 security M-2) with the residual as `R-119`; **`R-119` is NOT this ticket's** — an invite flow is a product decision about what an invitation says and how long it lives, and `R-124`'s mail clause rides on it.

## 2. Out of scope

| Not here | Owned by |
| --- | --- |
| Login, shell, session lifetime and lock, device settings | **10a** |
| The board, pager detail, mark ready, renotify, cancel | **10b** |
| KDS view and the offline resync | **10c** |
| History with filters, issuing a pager sheet | **10d** |
| **Invites** — minting a credential, delivering mail, the consent rule | **`R-119` + `R-124`**, and they are a product decision first |
| Platform admin and tenant provisioning | Task 6 |
| The transitions themselves | Task 3 |

## 3. Pre-coding gate — answer all seven in a comment on this ticket, before the first implementation commit (§6)

1. Which tables / columns / roles / GUCs does this touch? Any change to the data contract → `PLAN.md` **first**.
2. Which shared contracts (statuses, enums, DTOs)? One source only, never a duplicated enum — **`TenantRole` has a TS mirror; a hand-written role list on the client is the defect `§6.2` forbids.**
3. The exact path by which tenant context reaches **every** query.
4. Security: authn, authz, input validation, rate limit, log redaction, secrets. **State where the seniority comparison lives and why it is not in the FormRequest alone.**
5. Scale: cost per request; what bounds the member list. What is bounded.
6. Failure: timeout, retry, duplicate, crash mid-way — **and two managers demoting two different owners at the same instant.**
7. Research done for anything version-specific, **verified by running code in-session**. Paste the output.

## 4. Acceptance gate

* [ ] **The invariant in §0**, every clause driven over HTTP and the refusal asserted — **(a)** a manager granting `owner`, on both `members.store` and `members.update`; **(b)** a manager and an owner each targeting their OWN membership id; **(c)** the last owner removed and demoted, through the screen's own route set.
* [ ] **`R-115`'s third item is DELIVERED**, not re-owned: the screens exist, and `R-115`'s owner cell for that item names this ticket.
* [ ] **`R-106`'s seniority half is CLOSED** with the enforcement and its tests, and the row says so.
* [ ] Every refusal is a **regression test that fails without the fix** — each proven red.
* [ ] `§6` in full: `composer gate`, `pnpm gate`, `pnpm run gate:e2e`, fresh-clone proof, ≥ 90% coverage on added code, OpenAPI current, rollback clean.
* [ ] `§12` in full — see §6.

## 5. Known traps

* **A refusal must not be an oracle.** `members.store` already answers 404 for an unknown address *and* for one this caller may not see, deliberately, so the platform's `users` table is not an account-existence oracle. A new seniority refusal must not undo that by answering distinguishably.
* **The enforcement cannot live in validation alone.** A FormRequest sees the requested role and not the target's current one, and the self-role clause is about the *target id*, not the body — so a rule in `rules()` would be a check that looks complete and is not.
* **`RoleMatrix` is `(principal × ability)` and has no seniority dimension** (`R-106`'s own reasoning). Adding one to that array would change the matrix every other test reads; the seniority rule is about *which role may be granted*, which is a different question from *which ability is held*.
* `php artisan test` cannot run from a git worktree. The two heavy gates must not run at the same time (`R-200`). `PAO_DISABLE=1` for a by-hand test run.
* An unproven guard is not a guard · a vacuous assertion reads as coverage · assert exact values, not ranges · cross HTTP · `${PIPESTATUS[0]}` · generated tables · a reasoned skip is not a measurement · figures name the commit **and** the toolchain · no narrowing of an assertion without saying so.

## 6. `§12` applies in full — no exemption

Staff surfaces, tokens exist as of Task 8: **RTL, AA contrast, 44 px targets, `prefers-reduced-motion`, visible focus, no library default look, zero-technical-background bar.** The hard half here is the zero-technical-background bar on a **refusal**: *"you cannot give someone a role above your own"* has to read as a sentence a restaurant manager understands, in both launch languages, and a removal is destructive in effect so `§12` requires it confirmed. Every screen's purpose, all states, content inventory and exact copy written into this ticket before it is coded.

## 7. Working rules

Own branch (from 10d's tag — `git rev-parse` it first), own full `§6` gate, own full `§11` review in its own pinned worktrees with verbatim briefs, **both reports relayed here IN FULL with counts BEFORE any disposition**, final evidence once at the end, own tag with `--cleanup=verbatim` diffed byte-for-byte against its file, CI discharged by `gh`. Push at round boundaries.
