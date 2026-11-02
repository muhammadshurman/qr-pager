# QRPAGER-25 — Task 10a · Staff shell and authentication

*Committed mirror of the Jira description. The board is authoritative;
`muhdshurman.atlassian.net/browse/QRPAGER-25`. This file exists because `§11` reviewers receive
**the diff and this ticket only**, and a reviewer in a pinned worktree reads the repo.*

> **Read first:** `docs/PLAN.md §0` (current state), then `docs/PROJECT_INSTRUCTIONS.md` (§0, §4, §6, §11, **§12**), the rest of `docs/PLAN.md`, `docs/adr/`.
> This ticket never restates a rule from those files. On disagreement: **stop and report (§0).**

**This is ticket 1 of 4 in the Task 10 split, ruled by Muhammad on 2026-11-02.** The ruling is on `QRPAGER-17` and is recorded in `PLAN.md §1`. The split is **by invariant, not by screen count**, because `§11 › Round limit` makes a named falsifiable invariant the precondition for starting. `§3` row 10 is **not** reworded; `§3.1` row 10's two recorded gaps are written by **10b**.

**Blocked by:** Task 9 (`QRPAGER-16`, Done, tagged `task-9`). **Blocks:** 10b.

---

## 0. The named falsifiable invariant — written before the work

> **A staff member authenticated in tenant A can reach no route and no datum of tenant B. Proven by driving EVERY mounted staff route cross-tenant over HTTP and asserting the refusal — not by reading the code.**

The route list is **derived from the router**, not hand-written, so a route added later cannot slip the proof. `RouteSurfaceTest` and `RoleMatrixTest` are the precedent: totality is mechanical, and a route with no cross-tenant case fails the suite rather than passing unnoticed.

This is `§11` check 2 (IDOR) made into this ticket's bar. It is the right one for 10a because this is the first surface on which a **human reads tenant data through a browser**.

## 1. Scope — nothing else

* Passkey-first login.
* The authenticated shell: navigation, tenant identity, sign-out.
* Session lifetime and lock behaviour **for a screen left unattended in a kitchen**.
* Shift and device settings (a device registering itself is the same concern as a person authenticating).
* The staff **error / empty / loading primitives** in `@qrpager/ui`.

### `§4`'s two authentication surfaces are never confused

Staff authenticate as **users** — Task 5, which **exists**. So there is **no stub, and no stub may appear**; `§4 › Tenancy` calls a stub that survives Task 5 a **Critical** finding. A customer has no account and authenticates **only** by presenting the QR token.

## 2. Out of scope

| Not here | Owned by |
| --- | --- |
| Active pager board, pager detail | **10b** |
| Mark ready, renotify, cancel | **10b** |
| The "under 2 s" method and the "board stays correct" definition | **10b** |
| KDS view and the offline resync | **10c** |
| History with filters | **10d** |
| Issuing a pager sheet (and the reprint ruling) | **10d** |
| The `markReady` / `cancel` transitions themselves | Task 3 |
| Notification delivery, outbox, dispatcher | Task 4 |
| Passkey / Fortify / device-token **backend** | Task 5 |
| Platform admin and tenant provisioning | Task 6 |
| Customer-facing screens | Task 9 |

## 3. Pre-coding gate — answer all seven in a comment on this ticket, before the first implementation commit (§6)

1. Which tables / columns / roles / GUCs does this touch? Any change to the data contract → `PLAN.md` **first**.
2. Which shared contracts (statuses, enums, DTOs) does it use or add? One source only, never a duplicated enum.
3. The exact path by which tenant context reaches **every** query — server-side, not in the UI.
4. Security: authn, authz, input validation, rate limit, log redaction, secrets. **A KDS screen is unattended in a kitchen:** session lifetime, lock behaviour, what is visible to anyone walking past.
5. Scale: cost per request and per active session. What is bounded.
6. Failure: timeout, retry, duplicate, crash mid-way.
7. Research done for anything version-specific, **verified by running code in-session**. Paste the output.

## 4. Acceptance gate

* [ ] **The invariant in §0, by the mechanical route-derived proof.**
* [ ] Passkey registration and login work **through a real browser** against this ticket's own screen — not against Task 5's harness page.
* [ ] Session lifetime and lock behaviour are **asserted**, not configured: an idle shell locks, a locked shell reveals no tenant datum, and recovery needs the credential again.
* [ ] The error / empty / loading primitives exist in `@qrpager/ui` and every state of every screen in this ticket is rendered by them.
* [ ] `§6` in full: `composer gate`, `pnpm gate`, `pnpm run gate:e2e`, fresh-clone proof, ≥ 90% coverage on added code, OpenAPI current, rollback clean.
* [ ] `§12` in full — see §6 below.

## 5. Known traps

* `php artisan test` **cannot run from a git worktree** (Pest derives its root from the autoloader). Reviewers: run the PHP half in a clean checkout at the same sha.
* **The two heavy gates must not run at the same time** — they share Redis, and `TestCase::setUp`'s `clearPagerProjection()` deletes the E2E's live sessions mid-journey (`R-200`).
* `PAO_DISABLE=1` when running `php artisan test` by hand from an agent session, or the failure you read has had its frames removed.
* **ALREADY SOLVED, do not re-diagnose:** `R-100`'s passkey case depended on a migration the E2E harness never applied — `GRANT UPDATE (credential) ON passkeys`, absent → `42501` → a 403 naming nothing. Fixed 2026-11-01 in `tools/browsers/bin/e2e`, which now bootstraps and migrates before serving.
* An unproven guard is not a guard · a vacuous assertion reads as coverage · assert exact values, not ranges · cross HTTP · `${PIPESTATUS[0]}` · generated tables · a reasoned skip is not a measurement · figures name the commit **and** the toolchain · no narrowing of an assertion without saying so.

## 6. `§12` applies in full — no exemption

These are staff surfaces and the tokens exist as of Task 8, so `§12`'s Task 6-7 exemption does not reach here: **RTL, AA contrast, 44 px targets, `prefers-reduced-motion`, visible focus, no library default look, and the zero-technical-background bar.** Every screen's purpose, all states, content inventory and **exact copy** are written into this ticket before it is coded.

## 7. Working rules

Own branch, own full `§6` gate, own full `§11` review, own tag. Branches from the previous tag; `git rev-parse` it before cutting. `§11`'s QA and Security run as **subagents in their own pinned worktrees** (`tools/gate/bin/reviewer-slot`), briefs verbatim, neither seeing the builder's reasoning nor each other's output. **Both reports relayed to this ticket IN FULL with their counts BEFORE any disposition.** Final evidence once, at the end (`tools/gate/bin/final-evidence`). Tag with `--cleanup=verbatim` and diff the tag message byte-for-byte against its file. CI discharged by `gh`. Push at round boundaries; never pause with work unpushed, a dirty tree, or review worktrees left behind.
