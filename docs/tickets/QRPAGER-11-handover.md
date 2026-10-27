# QRPAGER-11 — Task 4, Notification pipeline — handover

> Branch `task-4-notifications`, head **`a27e98b`** (before this document's own commit).
> Built unattended on 2026-10-27. Read with `docs/tickets/QRPAGER-11.md`, which has the
> seven `§6` pre-coding answers.

---

## 0. THE ONE THING THAT IS YOURS AND IS NOT A FOOTNOTE

**The manual iOS validation is no longer Task 4's, and you moved it.**

Your amendment's SCOPE BOUNDARY told me to read `PLAN.md §3`'s Task 4 row and state whether
Task 4 is scoped backend-only. **It is** — five deliverables, all server-side — so by the
amendment's own first branch the iOS manual gate does not belong here. It is **dropped from
Task 4's gate and moved to Task 9 (`QRPAGER-16`)**, which owns the client, and **no client
harness was built**, not even unstyled: `§12` forbids a customer-facing component before the
Task 8 visual approval, and `apps/web` does not exist until Task 8.

So there is no command for you to run and no blank table to fill in here. What there is
instead is **a decision, and it wants taking before Task 8 starts rather than after**:

> `PLAN.md §3` row 4 used to argue *"this is the project's largest product risk … and Task 9
> is too late to discover it"*, and your own Jira comment of 2026-10-25 spelled out why — by
> Task 9, Tasks 5-8 have been built on the premise that a customer actually receives the
> notification. **That argument is live again.** It is written into `§5` as **`R-073`** with
> owner Task 9 rather than disappearing with the clause.
>
> **The cheapest way to buy it back is a minimal install harness in Task 8**, which is the
> first moment `apps/web` exists. That is a scope call and it is yours, not this session's.

Everything a device would receive **is** specified and tested without one:
`WebPushDispatcherTest` drives the real library against a recorded PSR-18 edge and pins the
encoding (`aes128gcm`, RFC 8291 — **not** the library's default, which is the legacy
`aesgcm`), that exactly 404 and 410 mean a dead subscription, that the payload is encrypted,
and that `Topic`, `TTL` and `Urgency` are set. What is untested is whether it **arrives**.

---

## 1. Do this first, in this order

1. **`PLAN.md §0`** — rewritten. Two branches are outstanding and they stack.
2. **`PLAN.md §0.1` `W-15` and `W-16`** — Task 4's two `§11` reviews, then its PR/CI/tag.
   **Task 3's `W-10` and `W-11` come first**, because Task 4 is branched off Task 3.
3. **`R-074`** — **your blocking question is answered: the key is the HASH Postgres stores,
   not the raw token**, proven against `encode(token_hash, 'hex')` read back as the cluster
   superuser rather than against the function that builds the key. The three clauses you asked
   to be stated either way — TTL, invalidation with its test, and log/body posture at any
   `APP_DEBUG` — are in **`QRPAGER-11 §7`**, and two hardening changes came out of writing
   them. The row keeps your wording and the Security reviewer rules on it. `§6.2` below.
4. **`§4a` → "FOUR DEAD ASSERTIONS"** — the finding that is not about Task 4's code and
   matters most. `§7` below.
5. `docs/tickets/QRPAGER-11.md` — the `§6` answers, including the four board-level items.

---

## 2. THE INVARIANT THIS BRANCH SHIPS ON — verbatim, so the reviewers have the bar in writing

From `PLAN.md §3.1`, row 4, unchanged by this task:

> Kill the worker mid-batch → nothing lost, nothing duplicated · every Redis key holding
> tenant data is tenant-namespaced and one tenant's projection cannot be read through
> another's context · **a presented token resolving to a past-TTL session is refused, and
> distinguishably from an invalid one** · **the token endpoint has its own per-IP bucket,
> failed resolutions are counted, and the bucket is proven to refuse by driving it** (both
> added 2026-10-27, Muhammad — from Task 3's `§11` reviews, `R-058` and `R-063`)

Under `§11 › Round limit` that is what blocks the tag. Everything else goes to `§5`.

`§3.1` also said this gate **owed a stated measurement method** for `p99 < 5 ms`. It is now
written into `§3.1` row 4 — and it was written **before the first number was taken**, which
is the whole point of that clause.

---

## 3. THE AMENDMENT

**Received `2026-10-27T09:41:05Z`**, before any code and before any `§6` answer was written.
Per its own first case I folded it in and answered the seven questions once, with it
included — so there is no `AMENDED <timestamp>` heading in the ticket and no superseded
answer beneath one, because nothing had been answered yet.

**There is no `REWORK CAUSED BY THE AMENDMENT` section, and that is why.** No code existed
to invalidate. What it changed, relative to the original prompt:

| It changed | To |
| --- | --- |
| Scope of the iOS manual gate | Out of Task 4 → Task 9, with `R-073` carrying the cost (`§0` above) |
| Whether a client harness gets built | **No.** Task 4 is backend-only, so the amendment's second branch never applies |
| The polling endpoint's auth path | Had to be written out exactly, including cache-miss behaviour — `§5` below |
| Data-contract discipline | Forced the check that found there is **no migration to write** — `§8` |
| Proof artifacts | `bin/outbox-kill-proof` and `bin/poll-latency-proof` exist because of it |
| VAPID secrets | Names only in `.env.example`; every test generates its own pair at runtime |

---

## 4. OVERRIDES YOU PRE-AUTHORISED — recorded, not stopped on

- **No PR, no merge, no tag.** Your rank-1 instruction, repeated in the amendment, overriding
  `§6`'s "commit + tag `task-N`" and `§11`'s PR step. The branch is pushed and green.
- **Branched off `task-3-pager-domain`'s tip, not off `main`** — see `§6.1`.

---

## 5. THE POLLING ENDPOINT'S AUTH PATH, exactly as the amendment asked

```
POST /v1/pager/status   { "token": "<22 base64url chars>" }     token in the BODY, never a URL
   |
   v  PagerToken::fromPresented()      shape check, NO I/O; null => 404 (zero round trips)
   |
   v  GET  pager:tok:{sha256(token)}   the POINTER        <-- Redis command 1
   |        miss -> 404 ProblemType::Stale   NO POSTGRES
   |        hit  -> a server-written { tenant_id, session_id }
   v
   v  GET  t:{tenant_id}:pager:{session_id}   the PROJECTION   <-- Redis command 2
   |        miss -> 404 Stale
   v  200  { status, order_reference, expires_at, as_of, poll_after_seconds }
```

- **What resolves token → session → tenant:** `PagerTokenLookup`, and nothing else. The
  pointer is written **only** from a `ResolvedPagerSession` that resolver returned —
  `PagerProjection::remember()` takes the token and the resolved session *together*, so there
  is no call shape in which a session is filed under a token it does not belong to. The key
  is `PagerToken::hashHex()`, the same single derivation behind `pager_sessions.token_hash`.
  **No second lookup path was invented**: this is a memo of the one resolver.
- **What populates it:** `POST /v1/pager/session` on every successful resolve, and the outbox
  worker's refresh. Nothing else writes either key.
- **TTL:** `min(session's remaining life, pager.projection_ttl)`, floored at 1 s, on **both**
  keys in the same call. **A projection cannot outlive the session it describes** — which is
  what makes a miss honest.
- **Tenant namespace:** the projection is `t:{tenant}:pager:{session}` and the tenant id comes
  from the pointer's **value**. There is no tenant field in any customer request.
- **Cache miss: `404` with type `.../problems/stale`, and NO fallback to Postgres at any cache
  state.** Stated as the design decision the amendment asked for. The justification is the
  failure it would create: a cold Redis is exactly the moment every waiting device in every
  restaurant polls at once, so a fallback converts a cache outage into a Postgres stampede —
  which your own 2026-10-25 comment rejected outright. The client's defined reaction is one
  call to the resolve endpoint, which is rate-limited and authoritative.
- **Redis unreachable is a DIFFERENT answer:** `503` + `Retry-After`, the option your comment
  picked in terms. A miss means *we know nothing about this token*; an outage means *ask again
  shortly*. Both tested with the container's socket actually dead.

**Measured cost per poll: 7 Redis commands, 0 Postgres.** The handler is 2; the other 5 are
`ShedLoad` (1) and `RateLimit` (4). The ticket first said 2, counting only the controller, and
is corrected in place — at the `C6` ceiling that is ~300 000 Redis ops/second, not ~86 000.

---

## 6. EVERY ASSUMPTION, WHAT I CHOSE, AND WHAT I REJECTED

Ordered by how much it would cost to reverse.

### 6.1 Branch point — `task-3-pager-domain`'s tip, because there is no task-3 merge commit

**Chosen:** branch off `1695f0b`.
**Alternative:** branch off `main` at "the task-3 merge commit", as instructed.
**Why:** that commit does not exist. `git merge-base --is-ancestor task-3-pager-domain main`
is false; `git tag -l` is `task-0 task-1 task-2`; `main..task-3-pager-domain` is 17 commits.
`PLAN.md §0` says the same in words — `W-10` and `W-11` are open and both are yours. A branch
off `main` would contain **none** of Task 3, and Task 4 consumes `PagerTokenLookup`,
`PagerStatus`, `PagerEvent`, the outbox writer and the narrowed `pager_sessions` policies.
**If you disagree:** `git rebase --onto <task-3 squash commit> task-3-pager-domain
task-4-notifications` replays only Task 4's four commits. Conflicts will be `docs/PLAN.md`
only.

### 6.2 The pointer key is not tenant-namespaced — `R-074`, and it needs your ruling

**Chosen:** `pager:tok:{sha256(token)}` → `{tenant_id, session_id}`, with the **projection**
properly namespaced `t:{tenant}:pager:{session}`.
**Alternative rejected:** a signed, server-issued poll ticket carrying the tenant, which would
have made every key tenant-namespaced. **Rejected because `§4 › Tenancy` forbids it**: "a
customer-facing route that accepts anything other than that token is a rejected design".
**Why the chosen shape:** the pointer cannot be prefixed by a tenant, because resolving the
tenant is its purpose. What bounds it is the key's **name** — reading it requires the preimage
of a sha256, i.e. the 128-bit token — which is the same property that makes
`resolve_pager_token` a legitimate `SECURITY DEFINER` bypass in Task 3, and `§4 › Tenancy`
already names "QR token lookup" as a permitted cross-tenant path.
**This is a reading of two rank-2 clauses, so it is recorded and not resolved in a docblock** —
that is security `S-1`'s defect from Task 3 and I was not going to repeat it the week after.
`R-074` is the row; it keeps your wording and the Security reviewer rules on it.

**ANSWERED IN YOUR REVIEW ROUND, with two hardening changes.** The key is the **hash Postgres
stores** — `pager:tok:` + `encode(token_hash, 'hex')`, proven against the column as the cluster
superuser, 64 hex characters, raw token absent. Then: expiry is now checked at **read** time as
well as by the TTL, because a TTL is arithmetic done at write time against PHP's clock while the
authority is `expires_at` in Postgres; and neither writer floors the TTL at one second any more,
so a terminal transition on a long-expired session writes nothing instead of publishing a live
status for a second. Full answer in `QRPAGER-11 §7`.

### 6.3 All four customer routes are POST, including the one that only reads

**Chosen:** token in the request **body**.
**Alternatives:** `GET` with the token in a header, or in the path/query.
**Why:** `§3` row 3 as amended says the raw token reaches "no URL path or query string …
[and] nowhere a server receives or logs it". A body is the only place on an HTTP request that
is in neither an access log, a `Referer`, a proxy's URL trace, nor a CDN cache key. A header is
better than a URL and still worse than a body.
**The trade, stated:** the poll response is uncacheable by any shared cache — which it already
was, being per-session data behind a per-session secret. `§4 › Realtime`'s "short-TTL cache
headers" is answered by `poll_after_seconds` in the body plus `Cache-Control: no-store`.
**If you disagree** it is a route definition and a form request.

### 6.4 Only the polling route carries `ShedLoad`

**Chosen:** poll sheds; resolve and subscribe do not.
**Alternative:** the whole customer group sheds, like `api`.
**Why:** `§4 › Edge & ops` requires nice-to-haves to be dropped **before** alerts. Polling *is*
the live timer, by that clause's own words. Shedding resolve or subscribe stops a customer ever
receiving a push, which is dropping the alert first and the nice-to-have never. Pinned by a
test, because an asymmetry nobody asserts is a line somebody tidies.

### 6.5 A failed token resolution writes a Redis counter and **no** `audit_log` row

**Chosen:** `pager:tok:fail:{YYYYMMDDHH}`, plus a warning on the threshold-crossing attempt only.
**Alternative:** an audit row per failed resolution, which `R-063` asked me to decide explicitly.
**Why, and the first reason is structural:** with no tenant context `audit_log`'s `WITH CHECK`
is `NULL`, so the insert is refused `42501` — a failed resolution has no tenant *by definition*.
Measured, not reasoned. Second, `audit_log` is append-only and `§2.11` already calls it
"unbounded, and the one to watch", so an unauthenticated loop writing a permanent row per
attempt is worse than the enumeration it records. Third, `§2.8` does not require one.
The log is thresholded because of **`R-046`** — a warning per request on a high-volume failure
path is the same amplification one layer up.

### 6.6 The row, not the device, is the unit of retry — `R-078`

**Chosen:** if **any** device returns a retryable error, the whole outbox row backs off.
**Alternative:** finish the row when the first device succeeds.
**Why:** that alternative silently drops the notification for a customer's second device, and a
customer with a phone and a tablet is not exotic. A lost buzz is the failure this product
exists to prevent; a duplicate buzz is an annoyance, and the `Topic` header collapses it while
undelivered. **The cost is real and is written down** as `R-078`, owner Task 14.

### 6.7 Attempts are charged at claim time, not after a failure

**Chosen:** `attempts = attempts + 1` in the claim transaction.
**Alternative:** increment on failure.
**Why:** a worker killed mid-send has already paid for the attempt. Incrementing on failure
means a row that *kills the worker* every time is retried forever, because the crash is exactly
the path that never records the attempt. A poison row must cost attempts.

### 6.8 The projection is refreshed after commit, best-effort — `R-079`

**Chosen:** `PagerSessions::project()` runs **after** `withTenant()` returns, and swallows failure.
**Alternative:** write it inside the transaction.
**Why:** Redis has no part in Postgres's commit, so a projection written inside would survive a
rollback and publish a state that never happened. And the transition is already committed when
the refresh runs, so a Redis failure must not fail the staff request. The staleness is bounded
and self-healing (`R-079`).

### 6.9 `refresh()` is `XX` — update-only

**Chosen:** a staff transition on a session nobody scanned creates no Redis key.
**Why:** it ties the keyspace to the **waiting** population rather than to order volume, which
is what makes `§6.5`'s ~90 MB figure hold. The projection's lifetime belongs to the scan.

### 6.10 `minishlink/web-push` v11, not v10.1

**Why:** v11 takes an injected PSR-18 `ClientInterface`, which makes "push sent to a mocked
edge only in the automated suite" a real **injection** — no socket, no DNS — rather than a
hostname nobody resolves. v10.1 reaches for Guzzle directly. MIT; no advisory against it or any
of its dependencies.

---

## 7. THE FINDING THAT IS NOT TASK 4'S, AND MATTERS MOST — corrected after your review

**Four assertions in this suite asserted nothing, and three guarded named invariants. Two
tagged trees carry one.**

Pest's `toContain()` and `toContainEqual()` are its **only two variadic matchers** — measured
by reflection: 73 others on `Pest\Mixins\Expectation` take a real `$message`, and
`Pest\Expectation`'s six variadic methods are all debug helpers. So a message in the second
argument is read as a **second needle**, and under `->not->` the assertion becomes *"does not
contain BOTH"*, which a message that never appears makes trivially true.

### ⚠ MY FIRST ATTRIBUTION WAS WRONG. Here is the measured one.

I told you Tasks 1, 2 and 3, and named the OpenAPI sweep as Task 2's. Re-measured with today's
`SourceGuard` rule 7 against **each ref's own bytes**:

| ref | vacuous | which |
| --- | --- | --- |
| `task-0` `3057fe7` | 0 | — |
| **`task-1` `3fe10c2`** | **1** | `GrantMatrixTest:29` — *"grants UPDATE on no tenant_id column anywhere"*, the control behind a row being unable to move between tenants |
| **`task-2` `2e42267`** | **1** | the same one, **carried**. Task 2 introduced none of its own |
| `task-3-pager-domain` `1695f0b` | 3 | + `PagerTokenTest:131` (*"the raw token never reaches a log"* — a **`§3` row 3 gate clause**) and `OpenApiSpecTest:283` (the `R-052`/`R-056` sweep) |
| Task 4 | 1, mine | `PagerCustomerSurfaceTest` — introduced and fixed on this branch |

Introduced by `122d203` (**Task 1**) and `224dde9` (**Task 3**). So the two files I attributed
to Task 2 are Task 3's, and Task 2's only exposure is inheriting Task 1's.

**Two measurement mistakes produced the wrong answer and both are worth naming**, because each
is the same species as the defect being investigated:

1. `git log -L` reports the commit that last touched a line **region**, not the one that
   introduced a defect. It named `122d203` and `224dde9` correctly but told me nothing about
   which *tags* carried them.
2. My first sweep copied the guard into a worktree per ref and ran it there — which returned
   **0 for `task-1`**, because the guard did not exist at `task-1` (`R-010`: built at Task 2),
   so the copy failed silently and the grep counted nothing. **A vacuous pass, produced while
   investigating vacuous passes.** The sound shape is today's class against historical bytes.

### What is now in place

- **The guard (your 1b).** `SourceGuard` rule 7, `VACUOUS_NEGATED_CONTAINS`. Runs under
  `composer guard` and as its **own CI step**, which `ci.yml` already had. Proven to fail on
  `guard/fixtures/repo/vacuous-not-contains.php.fixture` — four bad shapes (both matchers, one
  split across lines with a comment inside, one with a nested call as its first argument) and
  three correct ones that must **not** be flagged. Token-based, so the comments documenting the
  rule are invisible to it. One exception, rule 7's own positive control, scoped **by file and
  by function name** like `R-019`'s — `RepoGuardTest` proves a second function in the same file
  is still caught, and that the same function name in a different file is not excepted.
- **The assertions (your 1c).** All four rewritten as
  `expect(str_contains($h, $n))->toBeFalse($message)`, and **each proven to fail without its
  fix** by `apps/api/bin/vacuous-assertion-proof`: a real `GRANT UPDATE (tenant_id)` on the
  test database, `PagerToken::jsonSerialize()` returning the raw token, and the capacity figure
  put back into the committed spec. All three go red; all three return green on restore.
  **That script found two bugs in itself first** — it granted on the *development* database
  while the suite reads `qrpager_test`, and its `perl` replacement interpolated `$this` away,
  a fix which then reproduced the same interpolation in PHP. It now verifies each revert
  **landed** before trusting what the assertion says, because a revert proof whose revert did
  nothing reports a working control as broken.
- **The published artifact (your 1d): BOTH corrected and regenerated, not the test alone.**
  The source fix is `ReadinessController`'s rationale and five `FormRequest` docblocks moved
  from `/** */` to `//`, where Scramble cannot see them; `composer openapi` regenerated the
  spec. `grep` over the committed `docs/openapi.json` returns **0** for `0.9 x app_limit`,
  `app_limit`, `app_backends`, `saturation`, `R-031`, `ShedLoadTest`, the `     * ` artefact and
  `§`. And `composer openapi:check` — a gate step and a CI step — reports the file **matches
  the routes**, so the artifact is generated from source and a hand-edit would fail.
- **The record (your 1e).** `PLAN.md §1` has the dated decision-log entry; `§0`'s cell is
  corrected in place with the wrong attribution shown rather than silently replaced; `§4a` has
  the full round.

`§3.2`'s tenth delta asked *has this row's compensating control ever been seen to FAIL?* The
answer for three of them was **no, for as long as they existed**. `R-052`'s cell has named that
sweep as its compensating control since Task 2, and the sweep had never asserted anything.

---

## 8. WHAT IS DONE

- **Outbox worker** (`outbox:work`): `FOR UPDATE SKIP LOCKED` claim with no tenant context (the
  named cross-tenant path), a durable lease, **no transaction open across the edge**, per-row
  keyed status-guarded `UPDATE`s with no `RETURNING`, exponential backoff with jitter, and
  dead-lettering. SIGTERM drains between passes.
- **Web Push dispatcher**: VAPID, `aes128gcm`, `Topic`/`TTL`/`Urgency`, injected PSR-18 client,
  **prunes a subscription on its first 404/410**, timeouts from config, no redirect following.
- **Redis status projection**: two keys, both TTL-bounded by the session, tenant-namespaced
  where it counts, written by the resolve endpoint and refreshed by the service and the worker.
- **Polling endpoint**: zero Postgres, two Redis commands in the handler, `404 Stale` on a miss,
  `503 + Retry-After` on an outage, `Cache-Control: no-store`.
- **Subscription endpoints**: register (201/200, idempotent by the unique index) and remove
  (idempotent), both refusing an expired session with `409`.
- **The four `§5` rows Task 4 owned, closed**: `R-063` (its own per-IP bucket, failed
  resolutions counted, bucket proven by driving it, audit decision made explicitly), `R-068`
  (re-measured by `bin/ttl-revert-proof`: **six** cases for `apply()`, **one** — case 2 — for
  `renotify()`), `R-069` (both stale `SELECT id` sites), `R-070` (`Retry-After` per SQLSTATE).
- **A VAPID boot check**, found by the kill proof rather than by review.
- `PLAN.md` `§0`, `§0.1`, `§2.11`, new **`§2.13`** (the Redis keyspace), `§3` rows 4 and 9,
  `§3.1` row 4, `§3.2` tenth delta, `§4a`, `§5` (`R-073` … `R-079`, and `R-052` corrected).

**No migration, and that is a finding rather than a convenience** (`§6.1` of the ticket):
`outbox` already had `status`/`attempts`/`available_at`/`sent_at`/`dead_at`/`last_error`;
`outbox_claim_index` is exactly this task's claim predicate and order; `push_subscriptions`
already had its composite FK and `https://` CHECK; both roles already had every grant,
including the worker's `DELETE` whose Task 1 comment reads *"the worker prunes on 404/410"*.
**A dead-letter is a status, not a table.** So the amendment's "update `PLAN.md §2` in the same
commit as the migration" had no migration to attach to — `§2` is still updated, with `§2.13`.

---

## 9. WHAT IS NOT DONE

- **The two `§11` reviews** (`W-15`). Not startable from here: `§11` requires a context without
  the builder's reasoning, and this session is the builder.
- **PR, merge, tag** (`W-16`) — your instruction, and `gh`/a token do not exist here anyway.
- **The manual iOS push** — moved to Task 9 (`§0`), with `R-073` carrying the risk.
- **Client backoff and jitter** — moved to Task 9. The **server** half is built and tested.
- **Anything that runs `outbox:work` in a deployed environment** — `R-077`, Task 13, with
  `R-047` and `R-058`. Third instance of one structural gap.
- **`phpunit` 13.4.3's advisory** — `R-076`, blocked by Pest's own `>13.4.3` conflict.

---

## 10. STOPPED ON

Nothing was left unbuilt. Three things met `§8`'s *stop and report* bar and are recorded
rather than silently resolved, as the amendment pre-authorised:

1. **Task 3's token contract cannot satisfy Task 4's polling gate** — `R-074`, `§6.2` above.
   Resolved by a reading; wants your confirmation before the tag.
2. **`QRPAGER-11 §6`'s trap list is stale in one clause.** *"The compose file already runs
   Postgres with `log_statement=all` — use that log as the proof"* is **false** since Task 3
   closed `R-004` by **removing** the cluster-wide setting. The instrument is a query listener.
   Disclosed in the ticket; the board ticket is not edited by this session.
3. **The board and the repo disagree about Task 3.** Jira shows `QRPAGER-10` as **Done**; the
   repo shows it unmerged and untagged. Blocks nothing; the repo is the authority.

Two smaller discrepancies, neither blocking: the board names branch
`task-4-notification-pipeline` (your rank-1 instruction said `task-4-notifications`; rank 1
wins), and the board's `§5` acceptance gate predates the four clauses added to `PLAN.md §3`
row 4 on 2026-10-25 and 2026-10-27 — `PLAN.md` is what this task shipped against.

---

## 10a. THE p99 OF RECORD IS THE FULL HARNESS. THE SUITE RUNS A SMOKE CHECK.

Stated because the review asked, and because the distinction is easy to lose:

| | asserts | reports | run by |
| --- | --- | --- | --- |
| `bin/poll-latency-proof` | zero Postgres, two Redis commands, **and p99 < 5 ms** | p50/p95/p99 of 3 batches, with commit, machine and method | a person, on a quiet machine. **This is the figure of record** and it goes into `§4a` |
| `bin/poll-latency-proof --quick` | zero Postgres, two Redis commands | p50/p95/p99 of 1 short batch | `PagerPollLatencyTest`, inside the gate |

`PagerPollLatencyTest` is **a smoke check on the harness** — that it still runs, and still
measures the two load-invariant properties. It asserts nothing about the clock. Its header says
so, its name says so, and it now asserts the script printed `timing REPORTED ONLY`, so dropping
`--quick` becomes a deliberate change rather than an accident.

**A budget asserted by a cheaper path than the one measured is not a budget**, which is exactly
what the first version did: it asserted the script's exit code, the exit code asserted the
timing, and the gate went red at 7.640 ms where the full harness read 3.337 ms on the same
commit. `§3.1` row 4 calls that `F10`'s class.

---

## 11. THE GATE FIGURES, AND THE COMMIT EACH WAS MEASURED AT

| What | Figure | Commit | How |
| --- | --- | --- | --- |
| `composer gate`, working tree | **all six green, exit 0 — 622 passed, 3641 assertions** at **`bef66a3`** · *superseded: 601 passed / 3428 assertions at `5f1bdbf`, measured before the rebase* | **`bef66a3`** (rebased), was `5f1bdbf` | `composer gate`; output at `docs/tickets/QRPAGER-11-gate.txt`. Measured there rather than quoted from the code commit, because `R-071` is the row for "`§6`'s fresh-clone clause has no evidence at the head it is being tagged on" |
| `composer gate`, **fresh clone** | **all six green — 622 passed, 3641 assertions**, the same counts as the working tree, now via `bin/fresh-clone-proof` · *superseded: 601 / 3428 at `5f1bdbf`* | **`bef66a3`** (rebased), was `5f1bdbf` | clone → `cp .env.example .env` → `composer install` → `key:generate` → `composer gate`; output at `docs/tickets/QRPAGER-11-fresh-clone-gate.txt` |
| Baseline, branch point | **513 passed, 2383 assertions** — the `task-3` TAG, which is the base after the rebase · *superseded: 492 / 2182 at `1695f0b`, the branch point before Task 3 was finished* | `task-3` = `f30bd24`, was `1695f0b` | so Task 4 adds **109 tests, 1258 assertions** (was 100 / 1198 against the older base) |
| **Coverage, this task's code** | **96.66 % — 1013/1048 over 24 `app/` files** at `d7b004a`, `--min=90` passing · *superseded: 96.60 % (994/1029) at `bef66a3` before the `§11` fixes, and 96.50 % (964/999) over 25 files at `a27e98b` before the rebase*. The base ref changed from the BRANCH `task-3-pager-domain`, which moved under it, to the TAG `task-3`, which cannot — so the file set is smaller and strictly more honest | **`bef66a3`** (rebased), was `a27e98b` | `bin/coverage --task=task-3-pager-domain --min=90`; output at `docs/tickets/QRPAGER-11-coverage.txt` |
| **`p99` of record, polling endpoint** | **2.931 ms** vs a 5 ms budget; batches 6.479 / **2.931** / 3.395, best taken · *superseded: 2.730 ms, batches 2.730 / 3.137 / 3.499, at `5d89234`*. **Batch 1 came in ABOVE budget at 6.479 ms**, which is exactly why the method takes the best of three on a machine that is not quiet — load was 1.12 — and why a single batch would have reported a failure the other two contradict | **`bef66a3`** (rebased), was `5d89234` | **the FULL harness** — `bin/poll-latency-proof` with no arguments: 20 warm-up discarded, 200 measured, **best p99 of 3 batches**, Linux 6.18.54 WSL2 x86_64, 8 CPUs, load 1.08, PHP 8.4.27. **Not `--quick`.** Full transcript committed at `docs/tickets/QRPAGER-11-p99.txt`; the earlier 3.337 ms was also the full harness, at `5dd7828`, before the read-time expiry check |
| Postgres statements per poll | **0** | `a27e98b` | query listener over a real request |
| Redis commands in the handler | **2** (`get`, `get`); **7** across the whole request | `a27e98b` | `CommandExecuted` listener |
| Kill mid-batch | **8 sent, 0 pending, 0 dead**, max attempts 2 of 6, no duplicate `(tenant_id, dedupe_key)` — re-measured unchanged on the rebased tree | **`bef66a3`** (rebased), was `a27e98b` | `bin/outbox-kill-proof 8` — a real `kill -9` |
| Migrations | apply from empty, second apply a no-op, rollback → 1 table / 0 functions, re-apply byte-identical | `a27e98b` | `bin/migration-rollback-proof`, all five checks |
| gitleaks pre-commit | `leaks found: 1`, exit 1, nothing committed | — | the 82-char `github_pat_` probe from `CLAUDE.md`; `git config core.hooksPath .githooks` was already set in this clone |

**⚠ EVERY FIGURE IN THIS TABLE WAS RE-MEASURED AFTER THE REBASE ONTO `task-3`
(2026-10-27), and the superseded value is kept beside the new one rather than swapped out.**
Before the rebase they were taken at `5f1bdbf`, `a27e98b` and `5d89234` against a base of
`1695f0b` — a branch point that predates Task 3 being finished — so every one of them
described a tree that no longer exists. They are now all at **`bef66a3`**, whose base is the
`task-3` tag.

**What moved, and what that says.** The suite gained 21 cases and 213 assertions, which is
Task 3's own additions arriving (its security-contract cases, the in-process generator case,
and the three on `RequireTenantScope`). Coverage moved by +0.10 pp on a denominator 30
statements larger. `p99` moved by +0.201 ms, within the spread of its own batches. Nothing
moved in a direction that needs explaining, and the one figure that could have — a first
batch at 6.479 ms, above budget — is the reason the method was written to take the best of
three rather than the first.

**One superseded figure is NOT in this table**: the pre-rebase gate was measured with
`docs/openapi.json` carrying Scramble's shared `components.responses/ValidationException`
reference on the customer routes. After the rebase, Task 3's extension replaces it with the
project's own problem document, so the published document changed and the spec case count
with it. See `§11a`.

**⚠ The gate row read 577 / 3319 for one draft of this document**, which was a run at
`47db64e` quoted against `a27e98b` — the commit after it added fifteen cases. It was caught
by the fresh clone reporting 592 / 3380 and by my writing a sentence to explain a gap that
did not exist. Re-measured at `a27e98b`: **592 / 3380 in both**, which is exactly what a
fresh-clone check is for. Left visible rather than silently fixed, because `R-025` is this
project's most frequent defect and a handover that hid an instance of it would be worse than
one that shows it.

**COVERAGE WAS 92.73 % FIRST.** `WorkOutboxCommand` read **0/27** — the entry point of this
task's first gate clause — because `bin/outbox-kill-proof` drives it in a subprocess, which is
the only way to kill it and is invisible to the coverage driver. Closed to 17/27 with
`WorkOutboxCommandTest`; the remaining ten are the infinite loop body and the `pcntl` handler,
which cannot run in process without hanging the suite.

---

## 11a. THE ORDERING, AND WHY TASK 4'S FIGURES ARE CONDITIONAL

`§6`'s order is strict — *no task starts before the previous one is green and tagged* — and
Task 4 started before Task 3 was either, on your rank-1 instruction. So, written into
`PLAN.md §0`'s *Blocking anything?* row rather than left to be discovered at the tag:

1. **Task 3's `§11` reviews run first.**
2. **Any Critical or High there changes Task 3's code, which this branch is built on.** So
   `task-4-notifications` is rebased onto the new Task 3 commit:
   `git rebase --onto <new task-3 commit> task-3-pager-domain task-4-notifications`.
3. **The FULL gate is re-run and every figure in `§4a`'s Task 4 section is RE-REPORTED at that
   commit** — gate, fresh clone, coverage, p99, the kill proof.
4. **Neither tag is created until then.**

A gate figure measured against a parent that has since changed is not a gate figure, which is
the same property `R-071` names one level up.

---

## 11b. THE §6 ITEMS YOU ASKED ME TO CONFIRM, WITH OUTPUT

All six run at this head. Transcripts are committed beside this file.

| Item | Result |
| --- | --- |
| **Rollback of this task's migrations runs clean** | `bin/migration-rollback-proof`, **all five checks**: apply from empty in one batch · second apply `Nothing to migrate` · this task's objects present · rollback leaves **1 table (the migrations table) and 0 functions** · re-apply returns a **byte-identical** schema fingerprint. This task adds no migration, so the proof is that the existing set still behaves — re-run, not assumed |
| **Every new route in the generated spec, committed, `/v1`** | `composer openapi:check` → *"docs/openapi.json matches the routes"*. The committed spec carries `/v1/pager/session` POST, `/v1/pager/status` POST, `/v1/pager/subscription` POST+DELETE. Everything outside `/v1` is one of the two probes, asserted |
| **TS enums/DTOs generated, CI fails when stale** | **Nothing to generate and nothing that can be stale.** `git ls-files '*.ts' '*.tsx'` → **0**; `packages/` does not exist (created at Task 8, `CLAUDE.md › Layout`). Task 4 adds no TypeScript and hand-copies nothing, because there is no other direction yet. Task 8's `§3.1` invariant already owns "the TS status mirror is generated and CI fails when it is stale" |
| **`DELETE IN TASK 5` list** | **Task 4 added none.** Re-derived from a grep: the stub is `app/Platform/Auth/HarnessTenantResolver.php`; its one production reference is `AppServiceProvider:48`; nine test files call `actingForTenant()` plus `TestCase.php` which defines it. **Task 4's nine new test files make ZERO calls** — `PagerCustomerSurfaceTest` appears in a filename grep only because of two comments saying "deliberately NO `actingForTenant()` in this test". Full list in `§13` |
| **gitleaks green** | `gitleaks detect` over **121 commits** → *no leaks found*. `gitleaks protect` over the working tree → *no leaks found*. The `.githooks` pre-commit hook was exercised earlier with the 82-character `github_pat_` probe: `leaks found: 1`, exit 1, nothing committed |
| **No VAPID key in the tree or the history** | `.env.example` lines 83-85 are `VAPID_SUBJECT=`, `VAPID_PUBLIC_KEY=`, `VAPID_PRIVATE_KEY=` — **names only, no values**. `git grep -E "VAPID_(SUBJECT\|PUBLIC_KEY\|PRIVATE_KEY)=.+"` → nothing. No committed P-256-shaped literal anywhere. Every test generates its own pair at runtime (`PushFixtures::vapid()`, `VAPID::createVapidKeys()`), which is why the suite needs none set — and why the new boot check exempts local/testing |

---

## 12. THE PROOF SCRIPTS — all in the repo, none in a scratchpad (R-043)

| Script | Proves | Run it |
| --- | --- | --- |
| `apps/api/bin/outbox-kill-proof` | nothing lost, nothing duplicated, by `kill -9`ing a real worker blocked inside a send | `bin/outbox-kill-proof 8` |
| `apps/api/bin/poll-latency-proof` | zero Postgres, two Redis commands, and the p99 figure | `bin/poll-latency-proof` (add `--quick` to report the clock instead of asserting it) |
| `apps/api/bin/ttl-revert-proof` | `R-068`'s per-case revert claim, both halves separately | `bin/ttl-revert-proof` |
| `apps/api/bin/vacuous-assertion-proof` | the three invariants that were unproven, each now **proven to fail without its fix** | `bin/vacuous-assertion-proof` |

**`composer gate` and `bin/coverage` both already existed at the task-3 commit**, so neither
had to be built — the amendment asked me to say so either way.

Each has its own database (`qrpager_outbox_proof`, `qrpager_poll_proof`) and bootstraps it, so
none touches your development data or the suite's. The first version of the latency proof did
not, and failed with `resolve_pager_token(bytea) does not exist` because `.env` points at
`qrpager` and only `qrpager_test` had ever been migrated — which is the bug
`bin/migration-rollback-proof`'s own header already records.

---

## 13. DELETE IN TASK 5 — every use of the pre-Task-5 auth stub

The amendment asked for this list. **Task 4 adds no staff route and no new use of the stub**;
what follows is every place it is reachable from, so Task 5 has one list rather than a grep.

| File | What it is |
| --- | --- |
| `apps/api/app/Platform/Auth/HarnessTenantResolver.php` | **The stub itself.** Reads a container binding, never an HTTP input; refuses to run outside `local`/`testing`; held in deptrac's own `AuthStub` layer that `Features` may not import |
| `apps/api/app/Providers/AppServiceProvider.php` | the one binding: `TenantResolver::class` → `HarnessTenantResolver::class` |
| `apps/api/tests/TestCase.php` → `actingForTenant()` | the only way a test gives a request a tenant |
| `apps/api/tests/Feature/Http/TenantResolutionTest.php` | the stub's own behaviour, including its refusal outside local/testing |
| Every test file that calls `actingForTenant()` — **nine, besides `TestCase.php` above, listed from a grep rather than from memory** (the grep returns ten lines; the tenth is `TestCase.php`, which defines it) | `tests/Feature/Database/TenantScopeGuardTest.php` · `tests/Feature/Http/ProblemEnvelopeTest.php` · `tests/Feature/Http/RateLimitTest.php` · `tests/Feature/Http/ShedLoadTest.php` · `tests/Feature/Http/TenantResolutionTest.php` · `tests/Feature/Logging/LogRedactionTest.php` · `tests/Feature/Pager/PagerIdorTest.php` · `tests/Feature/Pager/PagerRouteSurfaceTest.php` · `tests/Feature/Pager/PagerTtlTest.php` — reproduce with `grep -rln actingForTenant tests/` |
| **Task 4's own files: none** | `PagerCustomerSurfaceTest` mentions `actingForTenant()` **twice, both in comments** — "Deliberately NO `actingForTenant()` anywhere in this test" — because the customer surface authenticates by token and a tenant binding would prove nothing. Verified by grep rather than claimed: Task 4 adds **no new use of the stub at all**, which is the honest answer and is better than the list I first wrote from memory, which named `PagerCustomerSurfaceTest` as a user and omitted `TenantScopeGuardTest` |

`R-035` is the row: nothing mechanical fails if the stub survives Task 5, and a stub that
survives Task 5 is a **Critical** finding. Task 5's `§3.1` invariant already names "the
pre-Task-5 auth stub is gone, by three assertions".

---

## 14. WHAT I WOULD LOOK AT FIRST IF I WERE REVIEWING THIS

Not a defence — the three places I think a review should push hardest.

1. **`R-074`'s reading.** It is the one load-bearing judgement in the task. A reviewer who
   thinks `pager:tok:{sha256}` violates `§4 › Realtime` as written is making a legitimate
   finding, not rediscovering something I resolved.
2. **`R-078`'s trade.** One failing device delaying a session's whole notification is a real
   behaviour, argued for rather than overlooked. The argument may be wrong.
3. **The worker's lease window.** `lease_seconds` (60) must exceed the worst case of one row's
   processing — a tenant scope, two reads, and up to a few sends at 8 s each. With many devices
   on one session that margin narrows, and nothing currently measures it. Task 14 sets the
   number; I have not written a row for it because `R-078` is the same shape, but a reviewer
   might reasonably want one.


---

## 11a. What the rebase changed, beyond the numbers

**The rebase found a defect, which is what a rebase is for.** Task 3's security
post-processing step was written when the API had two kinds of route — two probes and five
staff endpoints — so it said *"a probe, or else staff"*. The moment Task 4's **customer**
routes landed on the same document, all four of them were published as requiring a **staff
bearer token**: a contract telling a customer to present a staff credential, on the one
surface `§4 › Tenancy` insists must never be confused with it.

Nothing about the application was wrong; the generated document was, and Tasks 8–9 generate
clients from it. Fixed at `bef66a3`: the generator now distinguishes **three** surfaces, and
the customer set is read **from the router** (membership of the `customer` middleware group)
rather than from a second hand-maintained constant, because the failure mode of forgetting to
edit a list is a route published as needing a credential it does not use.

**The spec's problem-response count went from 14 to 26** — the staff routes' 14 plus the
customer surface's 12 — because Task 3's `ValidationProblemResponseExtension` and its
media-type step now apply to Task 4's routes too. Both the committed-file case and the
in-process generator case assert the new number.

**The vacuous-assertion sweep over the rebased tree: 72 test files, ZERO real instances.**
The one hit is `ExpectationApiTest:133`, rule 7's own positive control, excepted by file and
function. `composer guard` runs rule 7 across 190 files with no violation.

**`SourceGuard` rules 7 and 8 met with no collision**, which is what the number reservation
was for: rule 7's constants are `VACUOUS_NEGATED_CONTAINS` and `VARIADIC_MATCHERS`, rule 8's
are `DUPLICATE_CANONICAL_DOC`, `CANONICAL_DOCS` and `TASK_TABLE_HEADING`. The merge was two
additive blocks. Nine cases exercise the two rules and all nine are green.

**Two things found and deliberately NOT changed tonight**, both written down instead:

1. **`arityFrom()` counts a trailing comma as an argument.** `f($x,)` reads as two. It is
   latent — no call to either variadic matcher in this repository is written that way — and
   it was found because the ad-hoc inventory had the identical bug and reported two false
   positives in `TenantScopeGuardTest` before the number was recorded.
2. **`R-082`'s closing gate names this moment** ("before `task-4` is tagged") and it is not
   closed. Extending rule 7 to the three slot-2 matchers means flagging
   `->not->toThrow($class, 'message')`, which is Pest's **documented** idiom for asserting an
   exception message — a policy choice binding every future test in the repository, and
   therefore Muhammad's rather than a 2 a.m. guard edit. The measurement is in hand: zero
   instances today, so nothing is inert while the row stays open.
