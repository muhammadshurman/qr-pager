# QRPAGER-11 — Task 4, Notification pipeline — the ticket

> Branch `task-4-notifications`. Epic **E2**. Built by an unattended builder session on
> 2026-10-27.
>
> Read with `docs/tickets/QRPAGER-11-handover.md`, which carries what is done, what is
> not, every assumption, the gate figures and the commits they were measured at.

---

## 0. The rank-1 amendment, and where the branch was cut

### 0.1 The amendment

A **rank-1 amendment to the task prompt was received at `2026-10-27T09:41Z`**, before any
code was written and before any `§6` answer was written. Per its own instruction for that
case, it is **folded in and the seven questions are answered once, with it included.**
There is therefore no `AMENDED <timestamp>` heading in `§6` below and no superseded
answer underneath one: nothing was answered before it arrived. What it changed relative to
the original prompt is recorded in the handover under *THE AMENDMENT*.

### 0.2 Where the branch was cut — the one deviation from the instruction

The instruction was "branch `task-4-notifications` off `main` at the task-3 merge commit".

**There is no task-3 merge commit.** Measured at `1695f0b`:

```
git merge-base --is-ancestor task-3-pager-domain main   -> false (not merged)
git tag -l                                              -> task-0 task-1 task-2   (no task-3)
git rev-list --count main..task-3-pager-domain          -> 17
```

`PLAN.md §0` says the same in words: Task 3 is "BUILT and PUSHED … It is **NOT** tagged:
what remains is `W-10` (the PR) and `W-11` (CI green on the head commit, then the tag)",
and both are assigned to Muhammad, not to an agent.

**Cut from `task-3-pager-domain`'s tip, `1695f0b`.** Branching from `main`'s tip
(`7f1c8f6`) would have produced a branch with **none** of Task 3 in it — no
`PagerTokenLookup`, no `PagerStatus`, no `PagerEvent`, no outbox writer, no
`pager_sessions` policies — and Task 4 consumes all five. Task 4 could not be built there
at all, so this is the only reading under which the instruction has a result.

**It is reversible, and the exact command is in the handover:** when Task 3 squash-merges
to `main` as commit `X`, `git rebase --onto X task-3-pager-domain task-4-notifications`
replays only Task 4's own commits onto it.

This is the same deviation Task 3 recorded in its own `§0`, for the same reason, and the
precedent is `QRPAGER-10 §0` / handover `§5.1`.

---

## 1. The `§3` acceptance gate — verbatim from `PLAN.md §3`, row 4

> Kill the worker mid-batch → nothing lost, nothing duplicated; polling endpoint does
> **zero Postgres queries** (proven by query log) and p99 < 5 ms; dead subscriptions
> pruned on 404/410; push sent to a mocked edge only **in the automated suite**; **one
> manual validation before this task is tagged: a real iOS device, installed to Home
> Screen, receives one real push from this dispatcher — iOS version and result recorded
> in §4a**; client backoff + jitter verified; **Redis is taken down and the polling
> endpoint's behaviour is defined and tested — the customer must not see a dead end**;
> **every Redis key holding tenant data is namespaced by tenant id, and a test proves one
> tenant's projection cannot be read through another tenant's context**. · **THE CUSTOMER
> AUTHENTICATION SURFACE — two clauses added 2026-10-27 by Muhammad, from Task 3's `§11`
> reviews, before this task starts rather than after a review of it.** … **(a) A presented
> token that resolves to a session past its TTL is refused, and distinguishably** … **(b)
> The token endpoint carries its own rate-limit bucket, tighter than `api` and keyed per
> IP** — … **failed resolutions are counted so an enumeration is observable, and the
> bucket is proven to refuse by driving it rather than by reading the config** (`R-063`).

**Two clauses in that gate are changed by this task, both recorded in `PLAN.md` in place:**

1. **The manual iOS validation moves to Task 9** — `§0.3` below, and the amendment's
   SCOPE BOUNDARY section is the authority. `PLAN.md §3` rows 4 and 9 carry the move,
   dated and attributed.
2. **"Client backoff + jitter verified" moves with it**, for the same reason: the client
   is Task 9's. What stays here is the *server* half — the values the server publishes for
   a client to back off by, and a test that they are published. Stated in `§6.6`.

### 0.3 SCOPE BOUNDARY — is Task 4 backend-only? **Yes.**

**`PLAN.md §3` row 4's task column, verbatim and in full:**

> **Notification pipeline.** Outbox worker (`FOR UPDATE SKIP LOCKED`, backoff,
> dead-letter), Web Push dispatcher (VAPID, 404/410 subscription pruning), Redis status
> projection, polling endpoint, subscription endpoints.

Five deliverables, **all five server-side**. There is no client artifact in the row, and
three independent statements of the repo's own structure agree:

| Statement | Where |
| --- | --- |
| "The frontend half of the repo is created at **Task 8** — the first task that needs it. Until then only `apps/api` exists, and that is correct." | `CLAUDE.md › Layout` |
| The customer PWA's six screens — scan landing, **enable alerts incl. the iOS Home-Screen install path**, waiting, order ready, session ended, offline — are **Task 9's** | `PLAN.md §4.1` |
| "**Customer PWA.** The scan-to-ready journey (see §4). Service worker including a **`pushsubscriptionchange` handler**" with "**iOS Home-Screen install path works and is measured**" in its own gate | `PLAN.md §3` row 9 |

So **the iOS manual gate does not belong to this task**, and per the amendment it is
**dropped from Task 4's gate and moved to Task 9 (`QRPAGER-16`)**, which owns the client.
Task 9's gate *already* names "iOS Home-Screen install path works and is measured", so
what moves is the specific clause — *a real iOS device, installed to Home Screen, receives
one real push from this dispatcher; iOS version and result recorded in `§4a`*.

**No client harness is built**, not even an unstyled one. `PROJECT_INSTRUCTIONS.md §12`
forbids writing any customer-facing component before the Task 8 visual approval, and the
amendment's own test — *is Task 4 scoped backend-only* — answers yes, which takes the
second branch of its instruction off the table. `apps/web` is not created by this task.

**The cost of the move is real and is not hidden.** `PLAN.md §3` row 4's own parenthesis
argued the opposite: *"this is the project's largest product risk … and Task 9 is too
late to discover it"*, and the Jira comment of 2026-10-25 that created the clause spells
out why — by Task 9, Tasks 5-8 have been built on the premise that a customer actually
receives the notification. **That argument is now live again**, so it is written into
`PLAN.md §5` as **`R-073`** with owner Task 9 and a date, swept into `§3.2` the same day,
rather than disappearing with the clause. It is also raised to Muhammad in the handover
under *DECISIONS THAT ARE YOURS*: the cheapest way to buy the risk back is a minimal
Task 8 install harness, and that is his call, not this session's.

**The alternative, rejected:** keep the clause in Task 4 and build the minimum unstyled
harness. Rejected because Task 4 is backend-only on the row's own wording, so the
amendment's first branch applies and its second does not — and because a harness would be
a customer-facing component written before the Task 8 approval, which `§12` forbids and
which this session has no authority to override.

---

## 2. The invariant this task ships on — verbatim from `PLAN.md §3.1`, row 4

> Kill the worker mid-batch → nothing lost, nothing duplicated · every Redis key holding
> tenant data is tenant-namespaced and one tenant's projection cannot be read through
> another's context · **a presented token resolving to a past-TTL session is refused, and
> distinguishably from an invalid one** · **the token endpoint has its own per-IP bucket,
> failed resolutions are counted, and the bucket is proven to refuse by driving it** (both
> added 2026-10-27, Muhammad — from Task 3's `§11` reviews, `R-058` and `R-063`)

Under `§11 › Round limit`, **that is the bar this branch ships on**, and it is quoted here
and at the top of the handover so the reviewers have it in writing before they read the
diff.

### 2.1 What `§3.1` says this gate still needs — and what this task does about it

> `p99 < 5 ms` on the polling endpoint needs **a stated measurement method**. It is
> `F10`'s class: on this branch a loaded host turned a passing timeout proof into a red at
> **27.6 s**, and only the *lower* bound survived as sound. Under the round limit a
> misleading red costs a round.

**The method, stated before the number was measured** — and it is `§6.5`'s answer as well:

1. **What is measured.** Wall-clock around the whole kernel call for one real request to
   the polling route — middleware, controller, render. That is a **superset** of the
   endpoint's own work, so a figure under budget is conservative in the right direction.
2. **Sample.** 20 warm-up iterations discarded, then 200 measured, in one process,
   against the real composed Redis, with the projection pre-warmed.
3. **Statistic.** p99 = the 198th value of the 200 sorted ascending (0-based index 197).
   p50 and p95 are reported beside it.
4. **The `F10` guard: best of three batches.** The whole batch is run three times and the
   **lowest p99 of the three** is the figure. A scheduling spike on a loaded runner lands
   in one batch; an endpoint that is actually slow — one that opens a Postgres
   connection, say — fails all three. This measures the endpoint's capability rather than
   the runner's contention, which is what the clause is about, and it is falsifiable in
   both directions.
5. **Two hard assertions that cannot flake at all, and carry the weight:** the endpoint
   issues **zero Postgres queries** (a query listener over a real request, the instrument
   `ReadinessCarrierTest` already uses for the same claim) and **exactly two Redis
   commands**. Those are the properties that make 5 ms reachable; the timing assertion is
   the confirmation, not the proof.
6. **Reported with its commit, its machine and its method**, in the handover and in
   `§4a`, never as a bare number.

The harness is **`apps/api/bin/poll-latency-proof`**, in the repo (`R-043`), and the
command is in the handover.

---

## 3. The `§5` debt this task owns — four rows

| Row | What it is | Severity | What closing it requires, from its own *Review by* cell |
| --- | --- | --- | --- |
| **R-063** | Nothing bounds an enumeration loop against the QR token lookup, and the only stated bound was wrong | Medium | Its own per-IP bucket tighter than `api`; failed resolutions counted so an enumeration is observable; the bucket proven to refuse **by driving it**; and an explicit decision on whether a failed resolution writes an `audit_log` row |
| **R-068** | `PagerTtlTest`'s revert-proof claim is wrong in both halves | Low | The header says **six** and names case **2** as the renotify one, or it drops the per-case claim and keeps only the aggregate. Re-running both reverts is two `sed`s and two test runs, recorded in `§4a` |
| **R-069** | Two present-tense sites still describe the expiry read as `SELECT id` | Low | Both sentences name the three columns, or drop the column count and say "a policy-limited keyed read". `grep -rn "SELECT id" apps/api` returns nothing outside the historical narrative |
| **R-070** | A mapped database 503 carries no `Retry-After` | Low | Either the mapped 503s carry a `Retry-After` chosen **per SQLSTATE** with a test asserting the header, or the omission is stated as deliberate with the client-side backoff named as the control |

All four are addressed in this task. `R-063` is a gate clause rather than a note, which is
`§3.2`'s seventh-delta observation: it is the first row in this project to land on a task
whose gate was amended *for it, before the task began*.

---

## 4. `§8` STOP-AND-REPORT — recorded, not silently resolved

### 4.1 Task 3's token contract does not reach Task 4's polling gate

`PROJECT_INSTRUCTIONS.md §0` forbids silently resolving a conflict, and the amendment
pre-authorises continuing once it is recorded. **Both of these are true and they cannot
both be satisfied by Task 3's contract as delivered:**

| Clause | Where | What it requires |
| --- | --- | --- |
| "A customer has no account and authenticates **only** by presenting the QR token … A customer-facing route that accepts anything other than that token is a rejected design." | `PROJECT_INSTRUCTIONS.md §4 › Tenancy` (rank 2) | The polling endpoint must accept the token |
| "Polling endpoint: **Redis-only read, zero Postgres queries** … A polling endpoint that touches Postgres is a rejected design." | `PROJECT_INSTRUCTIONS.md §4 › Realtime` (rank 2) | The polling endpoint must not reach Postgres |

The only thing that resolves a token is `App\Features\Pager\PagerTokenLookup`, and it is
**`DB::selectOne` against `resolve_pager_token(...)`** — Postgres, on every call. So the
one artifact Task 3 hands Task 4 for authentication cannot be used on the one path the
gate forbids Postgres on. **Task 3's handover `§7` does not mention this**; it says the
lookup resolves "with no tenant context" and that Task 4's routes are not on
`RequireTenantScope`, both of which are true and neither of which is this.

**Recorded here as `§8` stop-and-report, carried as `R-074` in `PLAN.md §5`, and resolved
by the most reasonable reading**, which is set out in full in `§6.3` below. In one line:
**the Redis entry is a cache of `PagerTokenLookup`'s own result and never a second way of
computing it** — it is written only from a `ResolvedPagerSession` that `PagerTokenLookup`
returned, and the key is `PagerToken::hashHex()`, the same single derivation
`pager_sessions.token_hash` uses. **No second lookup path is invented**: there is one
resolver, and this is a memo of it.

### 4.2 The ticket's own `§6` trap list is stale in one clause

`QRPAGER-11 §6` on the board says:

> The compose file already runs Postgres with `log_statement=all` — use that log as the
> proof, not an assertion.

**That is now false.** `R-004` was closed at Task 3 **by removing the cluster-wide
`log_statement`**, not by scoping it: `PLAN.md §0` records "`R-004`'s Task 4 half is
**dissolved rather than deferred** — the cluster-wide `log_statement` is gone, so there is
no setting left to scope per session". The instrument the same row prescribes instead is
**a query listener over a real request**, which is what `§6.5` and `bin/poll-latency-proof`
use. Disclosed here rather than silently worked around; the board ticket is not edited by
this session, and `PLAN.md §3` row 4 is what this task ships against.

### 4.3 The board and the repo disagree about Task 3

Jira shows `QRPAGER-10` as **Done**. The repo shows Task 3 unmerged and untagged
(`§0.2`). Recorded per `§8`; it blocks nothing here and the repo is the authority.

### 4.4 The board names a different branch

`QRPAGER-11 §7` on the board says branch `task-4-notification-pipeline`. The rank-1
instruction says `task-4-notifications`. **Rank 1 wins**; the board is not edited.

---

## 5. Out of scope — the owner is named, nothing here gets built

| Not here | Owned by | Why not here |
| --- | --- | --- |
| Creating sessions, state transitions, writing outbox rows | **Task 3** (built) | `PLAN.md §3` row 3. This task reads what that one writes |
| Any customer-facing component, including an unstyled push harness | **Task 8** (tokens + approval), **Task 9** (the PWA) | `§12`: no component before the Task 8 visual approval. `§0.3` above |
| The manual iOS push validation | **Task 9** (`QRPAGER-16`) | `§0.3`. Moved by the rank-1 amendment; `R-073` carries the risk |
| Client-side jittered interval and exponential backoff | **Task 9** | The client is Task 9's. The *server* publishes the values and tests that it does — `§6.6` |
| The `pushsubscriptionchange` handler and subscription rotation | **Task 9** | `PLAN.md §3` row 9 names it in that task's gate |
| Scheduling `outbox:work` and `pager:expire` against real infrastructure | **Task 13** | `R-047`, `R-058`. A command scheduled against infrastructure that does not exist is `R-047`'s shape; this task registers the command and says so |
| Staff-facing routes of any kind | **Task 3** (built), **Task 10** (the dashboard) | This task adds no staff route. `§6.4` |
| Real authentication for staff | **Task 5** | The test-only stub stays; every use is listed in the handover under *DELETE IN TASK 5* |
| Per-tenant edge limits, CSP, CORS, SAST/DAST | **Task 12** | `PLAN.md §3` row 12 |
| Reverb or any persistent connection | **Nobody. Forbidden (O2).** | `PROJECT_INSTRUCTIONS.md §2` |
| A TypeScript mirror of any enum added here | **Task 8** | `packages/contracts` does not exist until Task 8. Nothing is hand-copied in either direction, because there is no other direction yet |

---

## 6. The seven `§6` pre-coding answers

### 6.1 Which tables / columns / roles / GUCs does this touch? Any change to the data contract → update `PLAN.md` **first**

**No new table, no new column, no new enum type, no new role, no new GUC. No migration.**

That is a finding rather than a convenience, and it was checked against the schema rather
than assumed. Everything this task needs was built at Task 1 and granted at Task 1:

| Need | What already exists | Where |
| --- | --- | --- |
| A claimable queue with backoff and dead-letter | `outbox` — `status outbox_status ('pending','sent','dead')`, `attempts smallint`, `available_at timestamptz`, `sent_at`, `dead_at`, `last_error text` | `2026_10_25_150100_create_tenant_tables.php` |
| An index for the claim | `outbox_claim_index ON outbox (available_at, id) WHERE status = 'pending'` — exactly this task's claim predicate and order | same |
| Idempotent enqueue | `UNIQUE (tenant_id, dedupe_key)` | same |
| Push subscriptions | `push_subscriptions` with `UNIQUE (pager_session_id, endpoint)`, `CHECK (endpoint LIKE 'https://%' AND length(endpoint) <= 2048)`, composite FK `(tenant_id, pager_session_id)` → `pager_sessions (tenant_id, id)` `ON DELETE CASCADE` | same |
| The worker's grants | `GRANT SELECT ON outbox`, `GRANT UPDATE (status, attempts, available_at, sent_at, dead_at, last_error, updated_at) ON outbox`, `GRANT SELECT, DELETE ON push_subscriptions` — the DELETE is commented "the worker prunes on 404/410" | `2026_10_25_150300_grant_runtime_role_privileges.php` |
| The app's grants | `GRANT SELECT, INSERT, DELETE ON push_subscriptions`, `GRANT UPDATE (p256dh, auth, updated_at) ON push_subscriptions` — enough for an `ON CONFLICT … DO UPDATE` upsert and no more | same |
| The worker's cross-tenant outbox policies | `worker_drains_outbox FOR SELECT USING (true)`, `worker_updates_outbox FOR UPDATE USING (true) WITH CHECK (true)` | `2026_10_25_150200_enable_row_level_security.php` |

**A dead-letter is a status, not a table.** `outbox_status` already has `dead`, and
`dead_at`/`last_error` are already columns, so the amendment's "dead-letter … updates
`PLAN.md §2` in the same commit as the migration" has no migration to attach to. Stated
rather than left looking like an omission.

**`PLAN.md §2` IS updated by this task, in two places, and both land before the code that
uses them:**

1. **A new `§2.13` — the Redis keyspace.** `§2` is the data contract and Redis holds
   tenant data, but no subsection of `§2` has ever described a Redis key. The ticket's own
   2026-10-25 comment is the argument for fixing that: *"Everything Task 1 built … stops
   at the Postgres connection. Redis enforces nothing. A key is a key."* `§2.13` lists
   every key this project writes, its namespace, its TTL, what writes it, what reads it,
   and whether it holds tenant data — so the `§4 › Realtime` invariant has somewhere to be
   checked against, which is what Task 12's security review is told to do.
2. **`§2.11` — what is bounded, what is not** gains the three figures `§6.5` produces.

**GUCs:** `app.tenant_id` and `app.bypass_tenant` only (C1). No new `app.*` name; the repo
guard would reject one and is run by `composer gate`.

**Roles:** `qrpager_app` serves the three customer routes; `qrpager_worker` runs the
outbox worker. Neither gains a privilege.

### 6.2 Which shared contracts (statuses, enums, DTOs) does it use or add? One source only. Never a duplicated enum

**Used, not re-declared:**

- `App\Domain\Pager\PagerStatus` — the projection stores `$status->value` and reads it
  back with `PagerStatus::from()`. **The projection has no status vocabulary of its own**,
  which is the ticket's `§4.2` clause ("no second copy for the projection"). A value Redis
  cannot map back to a case is treated as a corrupt projection and answered as a miss,
  never as a new status.
- `App\Domain\Pager\PagerEvent` — the worker switches on `outbox.type` through
  `PagerEvent::tryFrom()`. The enum already owns the type *and* the dedupe-key shape, and
  Task 3's docblock says why they are one decision; the worker adds a third reader, not a
  second source.
- `App\Domain\Http\ProblemType` — every refusal on the three new routes is one of the
  existing cases. **One case is added**, below.
- `App\Domain\Outbox\OutboxStatus` — `pending` / `sent` / `dead`, already mirrored against
  the Postgres enum by `EnumContractTest`.

**Added:**

- **`App\Domain\Pager\PagerDelivery`** — the disposition of one send attempt: `Delivered`,
  `Gone` (prune), `Retry`, `Permanent` (dead-letter). It is the *dispatcher's* vocabulary,
  not the database's, so it is deliberately **not** a Postgres enum and is deliberately
  **not** in `DatabaseEnums::map()` — the same call `PagerEvent` made, for the same reason,
  and `EnumContractTest` correctly does not police it.
- **`ProblemType::Stale`** — the polling endpoint's cache-miss answer, `404`. It is a new
  case in a *committed, CI-checked* contract (`R-033`), so it lands as a visible diff in
  `docs/openapi.json`, which is the order `R-042` asked for.

**TypeScript:** `packages/contracts` does not exist until Task 8 (`CLAUDE.md › Layout`),
so there is nothing to generate and nothing to go stale. No enum is hand-copied in either
direction because there is no other direction yet. Task 8's gate already owns "the TS
status mirror is generated and CI fails when it is stale" (`§3.1` row 8).

### 6.3 Show the exact path by which tenant context reaches **every** query

There are **four** query paths in this task. Three open a tenant scope; one is a named
cross-tenant path and does not.

---

**(1) The polling endpoint — `POST /v1/pager/status`. ZERO Postgres queries.**

This is `§4.1`'s stop-and-report, resolved. The exact path, step by step:

```
client POSTs { "token": "<22 base64url chars>" }      token in the BODY, never the URL
   |
   v
PagerToken::fromPresented($raw)       shape check, no I/O; null => 404 NotFound
   |
   v
$key1 = 'pager:tok:' . $token->hashHex()           sha256 of the token, hex
   |
   v
Redis GET $key1   -->  miss  ==>  404 ProblemType::Stale   (NO POSTGRES. See below.)
   |
   v  hit: a server-written JSON pair { tenant_id, session_id }
   |
$key2 = 't:' . $tenant_id . ':pager:' . $session_id        tenant_id from the VALUE
   |
   v
Redis GET $key2   -->  miss  ==>  404 ProblemType::Stale
   |
   v  hit: the projection
200  { status, order_reference, ready_at, expires_at, poll_after_seconds, ... }
```

**Two Redis commands, zero Postgres statements, and both are asserted.**

- **What resolves token → session → tenant.** `PagerTokenLookup`, and nothing else.
  `key1` is **written only from a `ResolvedPagerSession` that `PagerTokenLookup` returned**
  — `PagerProjection::remember()` takes the resolved object, never a token and a tenant id
  separately, so there is no call shape in which a caller could put a tenant under a token
  it does not belong to. The key is `PagerToken::hashHex()`, the **same single derivation**
  that produces `pager_sessions.token_hash`. This is a **memo of the one resolver, not a
  second resolver** — the amendment's "do not invent a second lookup path" is satisfied
  because there is exactly one piece of code that can answer "which session is this token",
  and it is in `app/Features/Pager/PagerTokenLookup.php`.
- **What populates it.** `POST /v1/pager/session` (the resolve endpoint, `(2)` below) on
  every successful resolution, and the outbox worker whenever it refreshes a projection.
  Nothing else writes either key.
- **Its TTL.** `min(remaining session TTL, pager.projection_ttl)`, floored at 1 second, on
  **both** keys, written with `SETEX`. The session's own `expires_at` is therefore the
  hard ceiling: **a projection cannot outlive the session it describes**, so a token whose
  session has expired stops resolving in Redis at the same moment it stops being valid,
  and the client is pushed back to the Postgres path which can tell it *expired* from
  *invalid* (gate clause (a)).
- **Its tenant namespace.** `key2` is `t:{tenant_id}:pager:{session_id}` — the shape the
  ticket's 2026-10-25 comment prescribes verbatim — and **the tenant id comes from `key1`'s
  value, which the server wrote, never from the request.** There is no request field in
  this task from which a tenant id is read, on any route.
- **`key1`'s own namespace, stated plainly because it is the one key that is not tenant-prefixed.**
  `pager:tok:{sha256}` cannot be prefixed by a tenant, because resolving the tenant is what
  it is for. What stands behind it instead is **the key name itself: to read it you must
  present the preimage of a sha256 — the 128-bit CSPRNG token.** That is the same property
  that makes `resolve_pager_token` a legitimate `SECURITY DEFINER` bypass in Task 3, and
  `§4 › Tenancy` already names "QR token lookup" as one of exactly four permitted
  cross-tenant paths. The invariant's subject — **the projection** — is properly
  tenant-namespaced, and the value under `key1` is a pointer, not tenant content. **This is
  recorded as an assumption in the handover with the alternative that was rejected** (a
  signed poll ticket, rejected because `§4 › Tenancy` forbids a customer route that accepts
  anything other than the token).
- **Behaviour on a miss: `404 ProblemType::Stale`, and NO fallback to Postgres.** This is
  the design decision the amendment asks to be stated and justified, and it is stated as a
  refusal rather than as a fallback: *the hot path never reaches Postgres, at any cache
  state.* The justification is the failure mode the fallback creates — a cold Redis is
  exactly the moment every waiting device in every restaurant polls at once, and a
  fallback would convert a cache outage into a Postgres stampede, which is the same
  reasoning the ticket's own 2026-10-25 comment uses to reject "fall back to Postgres"
  outright. The client's defined reaction is one call to `(2)`, which is rate-limited,
  Postgres-backed and authoritative. **The customer sees no dead end**: `Stale` means
  *re-resolve*, which the PWA does without the customer doing anything.

**Redis unreachable** is a different answer from a miss: `503 ProblemType::Unavailable`
with `Retry-After`, which is the option the ticket's 2026-10-25 comment picked in terms
("503 + `Retry-After` — the client backs off and keeps waiting — honest"). A miss means
*we know nothing about this token*; an outage means *ask again shortly*. The two are
distinguishable by the client, and both are tested with the container actually stopped.

---

**(2) The resolve endpoint — `POST /v1/pager/session`. Postgres, no tenant context, by design.**

`PagerTokenLookup::resolve()` → `resolve_pager_token(...)`, the `SECURITY DEFINER`
function. **No tenant context, and that is `§4 › Tenancy`'s named cross-tenant path** — the
token is what decides the tenant. The route is **not** on `RequireTenantScope`, per Task 3's
handover `§7` and `PLAN.md §0`'s *Blocking anything?* row. Nothing downstream of it queries
anything: it reads the resolved object, warms both Redis keys, and renders.

- Unknown or malformed token → **404 `NotFound`**.
- Resolves, `hasExpired(now)` → **409 `Expired`**. Gate clause (a): *distinguishable from
  invalid*, by `type` as well as by status code, and a test asserts both answers differ.
  `hasExpired()` computes from `expires_at` against the clock, never from `status`, which
  matters because `pager:expire` is scheduled nowhere (`R-058`) so `status` lags — Task 3's
  handover `§7` says so in terms.
- Otherwise → **200**, and **both Redis keys are written** before the response.

---

**(3) The subscription endpoints — `POST` / `DELETE /v1/pager/subscription`. Postgres, scoped.**

```
token -> PagerTokenLookup::resolve()        no context (the named path)
      -> refuse if null (404) or hasExpired (409)
      -> withTenant($resolved->tenantId, fn () => ... )     <-- the scope, from the RESOLVED
                                                                session, never from input
         insert into push_subscriptions (...)
           on conflict (pager_session_id, endpoint) do update set p256dh = ..., auth = ..., updated_at = now()
```

The tenant comes from `ResolvedPagerSession::tenantId`, which came from the token. Inside
the scope, another tenant's `pager_sessions` row is invisible, so the composite FK
`(tenant_id, pager_session_id)` cannot be satisfied for a session the token does not own —
**structurally, not by a check.** `withTenant()` opens one transaction, so the upsert is
atomic with nothing else needed.

---

**(4) The outbox worker — `outbox:work`. Cross-tenant to claim, scoped to do anything else.**

This is the one path that runs without tenant context, and it is `§4 › Tenancy`'s named
"outbox queue". **The limit of that reach was measured, not assumed** — `§6.7` has the
output:

```
as qrpager_worker, NO tenant context:
  select ... from outbox ... for update skip locked   -> rows from EVERY tenant   (named path)
  update outbox set attempts = ..., available_at = ... -> UPDATE 1 cross-tenant   (named path)
  select count(*) from push_subscriptions              -> 0 rows                  <-- NOT reachable
  inside withTenant(A): push_subscriptions             -> 1 row
  inside withTenant(B): the same query                 -> 0 rows
```

So the worker **cannot** read a subscription, a session or an audit row cross-tenant — the
only cross-tenant policies it holds are the two on `outbox` and the two narrowed ones on
`pager_sessions` that `R-003` bounded to live-past-TTL rows for the expiry job. Everything
the worker does after claiming a row happens inside `withTenant($row->tenant_id, …)`, with
the tenant id taken **from the claimed row**, which the worker read through its own policy
and which no external input reaches.

```
claim (no context)  ->  for each row:  withTenant(row.tenant_id):
                                          read the session's order_reference
                                          read push_subscriptions for that session
                                          [send to the edge — OUTSIDE any transaction]
                                          mark sent / back off / dead-letter
                                          prune on 404/410
                                          refresh the Redis projection
```

---

### 6.4 Security: authn, authz, input validation, rate limit, log redaction, secrets

**Authentication.** Three customer routes, one credential: the QR token, in the **request
body** of a `POST`. Never a path segment, never a query string, never a header — `§3`
row 3 as amended says the raw token reaches no URL and nowhere a server logs it, and a
body is the only place on an HTTP request that is in neither an access log nor a referrer
nor a CDN cache key. **That is also why the polling endpoint is a `POST`**, which is
otherwise unusual for a read; the trade is recorded in the handover.

**The staff half, before Task 5 exists.** This task adds **no staff route**. `markReady`
and the other four transitions are Task 3's, already on the `api` group behind
`ShedLoad → RateLimit → RequireTenantScope`, which is deny-by-default: no resolvable
tenant, no request, `401` with no detail. The resolver behind it is the **test-only stub**
`App\Platform\Auth\HarnessTenantResolver`, which reads a container binding and never a
header, a route parameter, a query string or a cookie — so no HTTP input can choose a
tenant — and which refuses to run outside `local`/`testing`. deptrac holds it in its own
`AuthStub` layer that `Features` may not import, so nothing built here can be founded on
it. **Every use of the stub is listed in the handover under `DELETE IN TASK 5`**, which is
`R-035`'s obligation and a Critical finding if one survives.

**Authorisation.** A token authorises exactly one session in exactly one tenant. There is
no scope, role or claim to escalate, and no route takes an id: the session is whatever the
token resolves to, so **there is no IDOR surface on the customer routes at all** — the
parameter an attacker would tamper with does not exist. The staff routes' IDOR posture is
Task 3's and is unchanged; `PagerIdorTest` still runs.

**Input validation.** `token` — a required string, shape-checked by `PagerToken` before
anything is hashed or any statement runs. `endpoint` — required, `https://`, ≤ 2048, which
is the same bound the database `CHECK` enforces, so the refusal is a `422` with a field
message rather than a `23514`. `p256dh`, `auth` — required, base64url, ≤ 255. Mass
assignment is not reachable: every write in this task is an explicit column list in a
hand-written statement, and there is no Eloquent model on the path.

**Rate limiting — `R-063`, the gate clause.** Three new buckets in `config/ratelimit.php`:

| Bucket | Per IP | Per tenant | Why |
| --- | --- | --- | --- |
| `pager-token` | **30 / 60 s** | — | The resolve endpoint. Tighter than `api`'s 120, which is the clause's "tighter than `api` and keyed per IP". **No per-tenant half is possible**: a token carries no tenant until it resolves, so there is nothing to key on before the lookup — which is the clause's own parenthesis |
| `pager-poll` | 240 / 60 s | — | A waiting device polls every ~7 s, so ~9/minute; 240 leaves room for several devices behind one NAT and still bounds a loop. Same reason there is no per-tenant half |
| `pager-subscribe` | 20 / 60 s | — | Registering a subscription is a write. Same reason again |

**Failed resolutions are counted**, so an enumeration is observable: `PagerTokenLookup`'s
caller increments a Redis counter per UTC hour on every null resolution, and logs at
warning **only when a threshold is crossed** — not per request, because `R-046` is exactly
the finding that unthrottled per-request warning logs on a high-volume failure path are
themselves a defect. The counter key holds **no tenant data** — a failed resolution has no
tenant — so it is correctly not tenant-namespaced, and that is stated in `§2.13` rather
than left to look like an oversight.

**The explicit decision `R-063` asks for: a failed resolution writes NO `audit_log` row.**
Three reasons, the first of them structural:

1. **It is not possible without a bypass.** `audit_log` carries
   `tenant_isolation_audit_log` with `WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)`.
   With no context that is `NULL = NULL` → `NULL` → not true, so the insert is refused
   `42501`. A failed resolution has no tenant **by definition**, so there is no scope to
   open. Measured, not reasoned.
2. **It would be a denial-of-service amplifier.** `§2.11` already calls `audit_log`
   "unbounded, and the one to watch", no grant in `§2.7` deletes from it, and the
   append-only trigger refuses `DELETE` and `TRUNCATE` even to a superuser. An
   unauthenticated loop that writes a permanent row per attempt is a worse outcome than
   the enumeration it records.
3. `§2.8` makes the function body the audit boundary and **does not require one**, so this
   is a choice made explicitly rather than inherited — which is what the row asked for.

**Load shedding order.** `§4 › Edge & ops` requires nice-to-haves to be dropped **before**
alerts, so the three routes are not treated alike: **only the polling endpoint carries
`ShedLoad`.** Polling is the live timer — the nice-to-have. Resolve and subscribe are the
path by which a customer comes to receive an alert at all, so shedding them would drop the
alert first and the nice-to-have never, which is the stated order exactly inverted. This
is a deliberate asymmetry and a test pins it.

**Secrets — VAPID.** **No key, not even a test one, reaches the repository.**
`.env.example` gains `VAPID_SUBJECT`, `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` as **names
with empty values**. `config/webpush.php` reads them through `env()` and nothing else.
**Every test generates its own pair at runtime** with `VAPID::createVapidKeys()` — which
is why a fixture key is not needed. The private key never enters a log, a response body or
an exception message: the dispatcher catches `Throwable` from the library and re-raises
the class only, the way `RequestLimiter` does, because a VAPID validation failure's message
can carry key material. `gitleaks` runs in pre-commit and in CI — and **`.githooks` is
wired in this clone and the hook is proven to bite**, with the 82-character
`github_pat_` probe `CLAUDE.md` prescribes, because `core.hooksPath` is per clone
(`R-028`).

**Log redaction.** Nothing new is logged that was not already redacted. The token is
`#[SensitiveParameter]` on `PagerTokenLookup::resolve()`; the worker logs an outbox row id,
a tenant id and an attempt count, never a payload, never an endpoint URL — **a push
endpoint URL is a bearer capability for that device**, so it is treated as a credential and
the dispatcher logs a sha256 prefix of it instead. `LogRedactionTest` is extended rather
than trusted.

**What an attacker learns from a polling response.** With a valid token: the status, the
order reference and the timestamps of **their own** session — the holder of a 128-bit token
is the customer, which is Task 3's `§5.4` decision. Without one: `404` and nothing else,
in constant work (one Redis `GET` for a well-formed token, zero for a malformed one). The
response carries `Cache-Control: no-store` and no tenant id, no session id and no name.

### 6.5 Scale: cost per request and per active session. What is bounded?

**The number this is designed for, stated as the ticket demands.** The `C6` design ceiling
is **300 000 concurrent active sessions**. At a **7-second** client interval that is
**300 000 / 7 ≈ 42 900 polls per second** at steady state. The launch tier is **not** set
here — `C6` says Task 14's load results set it, never an assumption — but the *design* has
to survive the ceiling, so 42 900 rps is the figure every bound below is measured against.

| Thing | Cost | Bounded by |
| --- | --- | --- |
| One poll | **7 Redis commands, 0 Postgres statements, 0 Postgres writes.** The *handler* is 2 (`GET` the pointer, `GET` the projection); the request is 7, because the middleware in front of it is on Redis too — see the correction below | `pager-poll` (240/IP/min), `ShedLoad`, and the absence of any Postgres |
| One poll, token malformed | **5 Redis, 0 Postgres** — the handler spends one regex and no I/O; the five are the shedder and the limiter, which run in front of every request by design | the same bucket |
| One resolve | 1 Postgres round trip (one `plpgsql` call, two `set_config`s, one unique-index probe) + 2 Redis `SETEX` | `pager-token` (30/IP/min). This is the expensive path and it is the tightest bucket |
| One subscribe | 1 resolve + 1 upsert inside one transaction | `pager-subscribe` (20/IP/min) |
| One active session in Redis | **2 keys**, both `SETEX` with a TTL ≤ the session's remaining life. At 300 K sessions and ~300 B a pair, **≈ 90 MB** — and it **cannot grow past the WAITING population**, because `refresh()` is `XX` so a session nobody scanned gets no key at all, and nothing writes a key with a TTL longer than the session that owns it | the TTL, which is derived rather than configured; and `XX`, which ties the keyspace to scans rather than to orders |
| One notification | 1 outbox row (already written by Task 3), 1 claim share, 1 tenant scope, ≤ `max_attempts` HTTP sends | `max_attempts` then dead-letter |
| One expired subscription | **Deleted on the first 404/410**, so it is paid for at most once more | the pruning clause |

**What is bounded, and what is not.** Bounded: every Redis key by a TTL; every claim by
`batch_size`; every retry by `max_attempts` then `dead`; every customer route by its own
per-IP bucket; the subscription table by the composite FK's `ON DELETE CASCADE` plus
pruning. **Not bounded, and recorded rather than waved at:** `outbox` keeps `sent` rows
forever — no grant in `§2.7` deletes one — which is already `§2.11`'s "unbounded, and the
one to watch", and a retention job is Task 13's with `R-047`'s family. This task adds rows
to that table at the same rate Task 3 already does and does not make it worse; it is named
here because `§6.5` asks what is bounded and the honest answer includes what is not.

**⚠ CORRECTED AFTER MEASUREMENT, 2026-10-27.** The two rows above first read "2 Redis
`GET`s" and "0 Redis", counting only the controller. `PagerPollingTest` then measured the
real request and found **seven**, in this order:

```
get            ShedLoad, reading the readiness snapshot
get            RateLimiter::tooManyAttempts, reading the attempt count
eval, eval     RateLimiter::hit, adding the timer key and the counter key
incrby         RateLimiter::hit, incrementing it
get, get       THE HANDLER: the pointer, then the projection
```

So **four of the seven are rate limiting** and one is load shedding. At the C6 ceiling of
300 000 concurrent sessions on a 7-second interval that is ~42 900 polls per second and
therefore **~300 000 Redis operations per second**, of which ~171 600 are the limiter.
That is the figure Task 14 has to size ElastiCache against, and it is 3.5x what the
uncorrected note implied. Both numbers are asserted by `PagerPollingTest` — the handler's
two, so the design claim is pinned by something that does not move when the limiter's
internals do, and the request's seven, so the real cost is pinned at all.

Recorded here as a correction rather than quietly edited, because a cost nobody measured
is `§4a`'s most frequent defect and this ticket asserted one before the code existed.

**The `p99 < 5 ms` measurement method is `§2.1` above**, stated before the number was
taken, with `bin/poll-latency-proof` in the repo.

### 6.6 Failure: timeout, retry, duplicate, crash mid-way

**The worker killed mid-batch — the task's first invariant.** The shape is
**claim-then-lease, and the edge is never touched inside a transaction**:

```
TX 1 (short, no context):  select ... for update skip locked limit N
                           update outbox set attempts = attempts + 1,
                                             available_at = now() + lease
                             where id = ?            -- one keyed UPDATE per row
                           COMMIT                    -- the lease is now durable
--- no transaction is open while the HTTP call happens ---
per row:  withTenant(row.tenant_id): read session + subscriptions
          send to the edge
          TX 2: mark sent | back off | dead-letter | prune
```

- **Nothing lost.** A kill at any point leaves the row `pending` with `available_at` in the
  future; when the lease expires the next pass claims it. Proven by
  `bin/outbox-kill-proof`, which **actually `kill -9`s the worker mid-batch** and asserts
  the outcome — not by a mocked interruption.
- **Nothing duplicated, at the level the database can hold it.** `FOR UPDATE SKIP LOCKED`
  plus the lease means **no two workers ever hold the same row** (measured in `§6.7`: the
  second worker sees 0 rows while the first holds them, and a plain `FOR UPDATE` blocks to
  `55P03`). The claim predicate is `status = 'pending'`, so a `sent` row is never claimed
  again, and `UNIQUE (tenant_id, dedupe_key)` is the backstop Task 3 built behind it.
- **The residual, stated rather than claimed away.** A crash in the window *after* the edge
  accepted the push and *before* `TX 2` commits re-sends that one notification on the next
  pass. That window is one HTTP call wide and **no outbox design closes it**, because the
  Web Push edge has no idempotency key. What bounds it is a real mechanism rather than a
  hope: the dispatcher sets the **`Topic` header** from the row's `dedupe_key`, and a push
  service **collapses an undelivered message with the same topic**, so a duplicate that has
  not yet reached the device replaces it instead of joining it. The remainder — a duplicate
  after delivery — is closed on the client by the service worker's notification `tag`,
  which is **Task 9's**, and it is written into `PLAN.md §5` as `R-075` with that owner
  rather than left for Task 9 to discover.
- **Timeout.** The PSR-18 client carries an explicit connect and request timeout from
  `config/webpush.php`; a timeout is a `Retry`, not a `Permanent`, and costs one attempt.
- **Push endpoint returns 5xx then succeeds.** `Retry` with **exponential backoff plus
  jitter** — `available_at = now() + min(base * 2^attempts, cap) ± jitter`. Jitter is on
  the server side here because the retry schedule is the server's; without it, every row
  that failed during one edge outage retries on the same tick.
- **A subscription silently dead.** 404 or 410 → `Gone` → **the row is deleted** inside the
  tenant scope, on the first occurrence. `MessageSentReport::isSubscriptionExpired()` is
  true for exactly 404 and 410 and false for 500, 429 and 413 — measured in `§6.7`.
- **No subscription at all.** The dispatcher does not assume one exists: a row whose
  session has zero subscriptions is marked `sent` with no edge call, because there is
  nobody to tell and leaving it `pending` would retry forever. That is the ticket's "the
  dispatcher must not assume a subscription exists".
- **Redis down.** The polling endpoint answers `503 + Retry-After` (above). The worker's
  projection refresh is best-effort and logged — a failed refresh must not fail a
  notification that was delivered. The rate limiter's posture is unchanged (`B7`, fail
  open, `R-027`).
- **Postgres contention — `R-070`.** The mapped 503s gain a **`Retry-After` chosen per
  SQLSTATE**: a lock timeout (`55P03`) and a statement cancellation (`57014`) are short,
  a saturated pool (`53300`) and a lost connection (`08006`) are longer. A test asserts the
  header per code, which is the first of that row's two options.

**Client backoff and jitter** are Task 9's (`§5` out of scope). What this task ships is the
**server half**: every polling response carries `poll_after_seconds` and every `503` and
`429` carries `Retry-After`, and a test asserts that they are present and sane. The client
that obeys them is Task 9's, whose gate already owns the four failure paths.

### 6.7 Research done for anything version-specific, verified by running code

Every figure below is from a command run in this session on this machine. Nothing is from
memory. Full output is in the handover.

**(a) `minishlink/web-push` v11.0.0** — chosen over v10.1.0 because v11 takes an injected
**PSR-18 `ClientInterface`**, which is what makes "push sent to a **mocked edge** only in
the automated suite" a real injection rather than a network stub. v10 reaches for Guzzle
directly. Released 2026-07-23; `php >=8.2`; licence **MIT**; it pulls `web-token/jwt-library`
4.2.3, `spomky-labs/pki-framework`, `php-http/discovery`, `php-http/httplug`,
`spomky-labs/base64url`, `symfony/polyfill-php83`. `composer audit` reports **no advisory
against it or any of its dependencies**.

Driving the real library against a recording PSR-18 client:

```
p256dh len=87 auth len=22
createVapidKeys: publicKey len=87 privateKey len=43
VAPID::validate keys: subject,publicKey,privateKey
Subscription contentEncoding=aes128gcm (create() DEFAULT is aesgcm)
201 created success=true  expired=false | TTL=1800 Topic=rdy-abc Urgency=high Content-Encoding=aes128gcm
404 gone    success=false expired=true  | ...
410 gone    success=false expired=true  | ...
500 server  success=false expired=false | ...
429 limited success=false expired=false | ...
413 too big success=false expired=false | ...
cleartext NEEDLE present in ciphertext body? no
method=POST uri=https://push.example.test/abc123
```

Four things this settles, each of which would otherwise have been an assumption:

1. **`isSubscriptionExpired()` is true for exactly 404 and 410** and false for 500, 429 and
   413. That is the pruning signal the gate names, read from the library rather than
   re-derived from status codes.
2. **`Subscription::create()` defaults to `aesgcm`** — the library's own docblock calls it
   "Not recommended. Outdated historic encoding." RFC 8291's encoding is `aes128gcm` and
   **it has to be passed explicitly**. A default taken on trust here would have shipped the
   legacy encoding.
3. **A PSR-18 client can be injected**, so the suite's edge is a recorded object, never a
   socket.
4. **The payload is encrypted before it leaves**: a marker string in the cleartext does not
   appear in the request body.

Key sizes confirm the schema fits without a migration: `p256dh` 87 chars and `auth` 22
against `varchar(255)`.

**(b) `FOR UPDATE SKIP LOCKED` and the worker's real reach**, run as `qrpager_worker`
against the composed Postgres 16, two concurrent sessions:

```
worker, no context, FOR UPDATE SKIP LOCKED           -> 2 rows, from 2 tenants
second worker, same statement, first still holding   -> 0 rows          <-- SKIP LOCKED
second worker, plain FOR UPDATE, lock_timeout 250ms  -> SQLSTATE 55P03  <-- it would block
worker, no context, lease UPDATE on another tenant   -> UPDATE 1
worker, no context, select from push_subscriptions   -> 0 rows visible  <-- NOT reachable
worker inside tenant A scope                          -> 1 push_subscription
worker inside tenant B scope                          -> 0
```

This is `§6.3`'s claim measured rather than argued: the worker's context-free reach is
`outbox` and nothing else, so every other read it makes must open a tenant scope — which
is what fixes the worker's shape before a line of it was written.

**(c) `phpunit/phpunit` 13.4.3 carries an advisory, and it CANNOT be fixed here.**
`PKSA-qhjm-rpqc-qkn6`, reported **2026-10-27** — the day this task started — affecting
`>=13.4.0,<13.4.4`: local code execution by another local user through a shared temporary
directory in PHPT coverage handling. A dev dependency, unrelated to this task, found by the
`composer audit` this task ran for its own new package.

The obvious fix was attempted and **refused by the resolver**, twice:

```
composer require --dev "phpunit/phpunit:^13.4.4"
  - pestphp/pest v5.3.3 conflicts with phpunit/phpunit 13.4.5.
composer require --dev "phpunit/phpunit:13.4.4"
  - pestphp/pest v5.3.3 conflicts with phpunit/phpunit 13.4.4.
```

`vendor/pestphp/pest/composer.json` declares `"conflict": { "phpunit/phpunit": ">13.4.3" }`,
and **v5.3.3 is the newest Pest there is** — so every version of PHPUnit without this
advisory is excluded by Pest, and Pest 5 is locked by **ADR-001**. The fix is therefore not
a lockfile line; it is either leaving Pest 5 (an ADR change) or waiting for Pest to lift its
conflict.

**Carried as `PLAN.md §5` `R-076`**, owner Task 12 — which owns the dependency and CI pass
and already owns `R-026` and `R-039` — reviewed when Pest lifts the `>13.4.3` conflict.
`composer.json` is left unchanged; the attempt is recorded rather than the failure hidden,
because `§11`'s dependency check is a gate and "no advisory" would have been false.

---

## 7. What this task will have changed in `PLAN.md`

Recorded here before the work, so the end state can be checked against it:

- **`§0`** — `Last completed` / `Next up` / `Blocking anything?` / `Open debt` rewritten
  for Task 4, with figures measured at the commit they are quoted from.
- **`§2.11`** — the three cost figures from `§6.5`.
- **`§2.13`** — new: the Redis keyspace, every key, namespace, TTL, writer, reader.
- **`§3` row 4** — the iOS manual clause and the client backoff/jitter clause removed,
  dated and attributed to the rank-1 amendment.
- **`§3` row 9** — the same two clauses added, dated and attributed.
- **`§3.1` row 4** — the stated measurement method for `p99 < 5 ms`, which is what that
  row says this gate still needs.
- **`§4a`** — Task 4's gate evidence: the figures, their commit, the machine and the
  method.
- **`§5`** — `R-063`, `R-068`, `R-069`, `R-070` closed with a named test; `R-073` (the iOS
  gate's deferred discovery, owner Task 9), `R-074` (Task 3's token contract does not reach
  this gate), `R-075` (the duplicate-after-delivery residual, owner Task 9) added.
- **`§3.2`** — a tenth delta sweeping every new row the same day it is written, which is
  that subsection's standing rule.

---

## 7. `R-074` — the pointer key, answered (added 2026-10-27, from Muhammad's review)

The review asked one question first and three to be stated either way. All four are below,
each against a measurement rather than against the code.

### 7.1 Is the key the raw token, or the hash Postgres stores? **The hash.**

```
pager:tok:{sha256_hex_of_the_presented_token}
```

`PagerProjection::pointerKey()` is `'pager:tok:'.$token->hashHex()`, and `hashHex()` is
`hash('sha256', $this->reveal())` — the same derivation that produces
`pager_sessions.token_hash`, which the insert stores as `decode(?, 'hex')`.

**Proven against Postgres, not against `hashHex()`.** Comparing the key to the function
that built it proves only that the function is deterministic.
`PagerProjectionTest` → *"it keys the pointer on the SAME hash Postgres stores, not on the
raw token"* reads `encode(token_hash, 'hex')` back from `pager_sessions` **as the cluster
superuser** — no runtime role may read that column at all — and asserts the key is
`pager:tok:` + exactly that value, 64 hex characters, matching `/^pager:tok:[0-9a-f]{64}$/`,
with the raw 22-character token absent from it.

So the Critical the review described does not apply: there is no raw token in Redis, and
therefore none recoverable from the slowlog, from `MONITOR`, from a connection error, or
from any RDB or AOF artifact. `§4 › Data`'s "stored only as a hash" holds on both sides.

### 7.2 The key's TTL, and that it cannot exceed the token's 2-hour TTL

`min(session's remaining life, pager.projection_ttl)`, written with `SETEX` on **both** keys
in the same call from the same clock reading, so they expire together.

- The session's own `expires_at` is the hard ceiling, and `expires_at` is set to
  `now() + interval '2 hours'` at creation (`PagerSessions::TTL_HOURS`, a constant and not
  config, so a deployment cannot lengthen it).
- `pager.projection_ttl` is 7200 — equal to the session TTL, so in practice it binds only if
  the session TTL is ever raised, which is exactly when a forgotten cache TTL starts serving
  stale state.
- **There is no floor.** It was `max(1, ...)` until this round, because `setex` refuses a
  non-positive TTL — which meant a terminal transition on a long-expired session published a
  live status for one second. Both writers now refuse outright
  (`hasNothingLeftToProject()`), and a test asserts no key is written for an expired session.

Pinned by *"it never writes a projection that outlives the session it describes"*, which
drives four remaining-life values and asserts both keys' TTLs are `<= remaining` and
`<= projection_ttl`.

### 7.3 What invalidates it, and the test that a session expired in Postgres stops resolving

**Two mechanisms, and the second was added in this round because the first is not sufficient
on its own.**

1. **The TTL**, above: both keys lapse no later than `expires_at`.
2. **A read-time check**: `PagerProjection::read()` now takes a clock and treats a view whose
   own `expiresAt` has passed as a **miss**, even while the key is physically present. A TTL
   is arithmetic done at write time against PHP's clock; the authority is Postgres. If the
   two ever disagree — PHP behind Postgres, a drifting container, a rounded `SETEX` — the
   first mechanism alone would serve a live status for a session the expiry job is about to
   move to `expired`.

**And one thing that cannot happen, which the first version of this test assumed could:**
`expires_at` is in **no UPDATE grant** for either runtime role — the app role may write
`(status, ready_at, completed_at, cancelled_at, last_notified_at, renotify_count, updated_at)`
and the worker `(status, expired_at, updated_at)`. So a session's expiry is **immutable after
creation** through every application path, and "`expires_at` moved in Postgres" is a state no
code can produce. The projection's copy can therefore never disagree with Postgres about
*when*; only about whether that moment has passed, which is what (2) decides.

**The tests:**

- *"it stops resolving from Redis once the clock passes the session expiry, end to end"* —
  a session given two seconds of life, scanned through the real endpoint, asserted to have a
  TTL of at most two seconds, then a real wait, then `404 Stale` on the poll and `409 Expired`
  on the re-open. Nothing is planted and nothing is mocked.
- *"it refuses a projection whose expiry has passed even while its key is still present"* —
  isolates mechanism (2), because after the wait above mechanism (1) has fired too. A view
  with a past `expiresAt` is planted under a key with **600 seconds** of TTL left, so only the
  read-time check can refuse it. Without it, that is a 200 carrying a live status for a
  session that is over.

**The expiry job and a status change** need no Redis write at all, and that is the point of
deriving the TTL: by the time `pager:expire` moves a row to `expired`, the keys are already
gone or already refused. A status change refreshes the projection with `XX` after its
transaction commits, so a live projection follows the session and a dead one is not revived.

### 7.4 Can the key, or its input, reach a log line or an error body?

**No, verified against captured output at `APP_DEBUG=true`, not assumed.**

Two cases, both driving every customer path including the ones that fail — a resolve, a poll,
an unknown token, a malformed token and a rejected subscription:

- *"it puts neither the key nor the token in any log line, at APP_DEBUG=true"* swaps in a real
  Monolog `TestHandler` and asserts the captured records contain neither the raw token, nor
  the full key, nor the 64-character hash.
- *"it puts neither the key nor the token in any response body, at APP_DEBUG=true"* asserts
  the same three absences across five real response bodies, plus the absence of the literal
  key prefix `pager:tok:` so the key's **shape** is not disclosed either.

Behind them: the token is `#[SensitiveParameter]` on `PagerTokenLookup::resolve()`,
`PagerToken` has no `__toString` and returns a redaction marker from `jsonSerialize()` and
`__debugInfo()`, and `TokenResolutionFailures` deliberately stores **no token and no hash** —
only a count per UTC hour.

### 7.5 The framing stays, and the Security reviewer rules on it

`R-074` keeps its wording: **a named cross-tenant path mirrored into a store with no RLS.**
The pointer cannot be tenant-namespaced because resolving the tenant is its purpose; what
bounds it is that reading it requires the preimage of a sha256 — the 128-bit CSPRNG token —
which is the same property that makes `public.resolve_pager_token` a legitimate
`SECURITY DEFINER` bypass in Task 3. The invariant's actual subject, the projection, is
namespaced `t:{tenant}:pager:{session}` with the tenant read from the pointer's value.

---

## 8. For the reviewers — three things that are ticket content, not the builder's commentary

Added 2026-10-27 on Muhammad's instruction, so that both `§11` reviewers receive them with the
ticket rather than from the builder. Each is stated as a thing to **check**, not as a
conclusion to accept.

### 8.1 `R-074` — a named cross-tenant path mirrored into a store with no RLS

**The Security reviewer rules on this. It is settled by neither the builder's reading nor
Muhammad's.**

`PROJECT_INSTRUCTIONS.md §4 › Tenancy` names exactly four cross-tenant reads, one of them the
QR token lookup, and requires a customer-facing route to accept nothing but that token.
`§4 › Realtime` requires the polling endpoint to be a Redis-only read with zero Postgres
queries, and says: *"Every key that holds tenant data is namespaced by tenant id, and every
read derives that id from the caller's own context — never from client input. A projection key
that is not tenant-namespaced is a cross-tenant read with none of Postgres's protection behind
it."*

This task resolves that by **mirroring the token lookup into Redis**:

| key | namespaced | holds |
| --- | --- | --- |
| `pager:tok:{sha256_hex}` | **no** | `{tenant_id, session_id}` |
| `t:{tenant_id}:pager:{session_id}` | yes | the status projection |

What is **established by measurement** and can be checked rather than argued:

- the pointer key is the **hash Postgres stores**, not the raw token — `PagerProjectionTest`
  asserts it equals `pager:tok:` + `encode(token_hash, 'hex')` read back from
  `pager_sessions` as the cluster superuser;
- the projection's tenant id comes from the pointer's **value**, never from a request field;
- both keys' TTLs are `<= ` the session's remaining life, and a past-expiry view is refused at
  read time even while its key is present;
- neither the key, its hash, nor the raw token reaches a log line or a response body at
  `APP_DEBUG=true`, asserted against captured output.

What is **a reading, and is what the review is for**: whether a key whose NAME is a 128-bit
secret the caller must present satisfies `§4 › Realtime`, given that the clause says
*namespaced by tenant id* and this key is not. The builder's argument is that the key is
bounded by the same secret that makes `public.resolve_pager_token` a legitimate
`SECURITY DEFINER` bypass in Task 3. **A reviewer who disagrees is making a finding, not
rediscovering something already settled.**

### 8.2 The vacuous-assertion defect, and `SourceGuard` rule 7

**QA should check that the guard fails on the fixture rather than take this claim.**

Pest's `toContain()` and `toContainEqual()` are its only two variadic *matchers*; 77 others
take a real `$message`. So `expect($x)->not->toContain($needle, "message")` reads the message
as a **second needle**, and `->not` makes the assertion *"does not contain BOTH"* — which a
message that never appears makes trivially true.

Four assertions in this repository were that shape, three of them guarding named invariants
(`PLAN.md §1`, dated 2026-10-27, has the inventory with the commit that corrected each and the
commit that proved each). The control is **`SourceGuard` rule 7**, run by `composer guard` and
as its own CI step.

**What to verify, rather than accept:**

```
php guard/bin/guard guard/fixtures/repo/vacuous-not-contains.php.fixture   # must exit 1, 4 violations
php guard/bin/guard                                                        # must exit 0 on the real tree
bin/vacuous-assertion-proof                                                # each invariant must go RED
```

The fixture carries **four bad shapes** (both matchers; one split across lines with a comment
inside; one whose first argument is a nested call) and **three correct ones that must not be
flagged** (a single needle; a needle containing a comma; the positive `toContain($a, $b)`,
which legitimately means "contains both"). Rule 7 has one exception — its own positive control
— scoped **by file and by function name**, and `RepoGuardTest` proves a second function in that
same file is still caught and that the same function name elsewhere is not excepted.

`bin/vacuous-assertion-proof` breaks the world for real: a `GRANT UPDATE (tenant_id)` on the
test database, `PagerToken::jsonSerialize()` returning the raw token, and the capacity figure
put back into the committed spec. Each assertion must go red and return green on restore.

### 8.3 `R-073` — the iOS gate is out of Task 4 and sits in Task 9

`PLAN.md §3` row 4's task column lists five deliverables, **all server-side**. `CLAUDE.md`
says the frontend half of the repo is not created until Task 8, and
`PROJECT_INSTRUCTIONS.md §12` forbids a customer-facing component before the Task 8 visual
approval. On that basis the manual iOS push validation and the client backoff/jitter clause
were **moved to `§3` row 9**, struck through in row 4 so the move is visible in that row's own
history, and **no client harness was built**.

**So a reviewer should not report the absence of an iOS validation or of client-side jitter as
a Task 4 gap** — they are not in this task's gate. What is in scope is the **server half**:
every polling response carries `poll_after_seconds`, every `503` and `429` carries
`Retry-After`, and both are tested.

The cost of the move is carried as **`R-073`** with owner Task 9, because `§3` row 4's own
parenthesis argued *"Task 9 is too late to discover it"*. **`R-073`'s payload question is
Muhammad's and is still open**; it is not a review item.
