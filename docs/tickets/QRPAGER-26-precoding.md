# QRPAGER-26 — Task 10b · the `§6` pre-coding gate, answered before the first implementation commit

*Committed copy of the Jira comment. The board is authoritative.*

**Branch `task-10b-board`, cut at `958854d`** — the docs-only descendant of the `task-10a`
tag, for the mechanical reason `PLAN §0.0c` **(c)** states and measures. `git rev-parse
task-10a` was checked first: `8a923c1` (tag object) → `8d40191` (commit), an ancestor.

**`§3.1` row 10's two recorded gaps are WRITTEN** — in `PLAN.md §3.1` row 10, in the commit
that carries this document, which is what `§1` of this ticket requires *before* the first
implementation commit.

---

## 1. Which tables / columns / roles / GUCs does this touch?

**Read, no schema change:** `pager_sessions` — `id`, `order_reference`, `status`,
`created_at`, `ready_at`. Already Task 3's `PagerSessions::board()`.
**Written, no schema change:** `pager_sessions.status` / `ready_at` / `renotify_count`,
`outbox`, `audit_log` — all through Task 3's existing transitions, inside their one
transaction. **This ticket adds no transition and no column to any of them.**

**ONE DATA-CONTRACT CHANGE, AND IT IS `PLAN.md §2.8` FIRST PER `§6` Q1 — `R-222`'s ruling.**
`public.user_memberships(uuid)` gains a fifth returned column, **`tenant_name`**:

```
RETURNS TABLE (tenant_id uuid, role public.tenant_role,
               tenant_status public.tenant_status, tenant_slug varchar,
               tenant_name varchar)            -- added at 10b
```

**Why widen the function rather than read the names another way, and why this is not a
reversal of 10a's decision.** 10a recorded a decide-and-record that `GET /v1/tenant` exists
*rather than* widening this function — and its reason does not apply here. That reason was
that the name is readable **inside the resolved tenant's scope**, where RLS leaves exactly
one row, so no cross-tenant read was needed. **The chooser runs BEFORE a tenant is
resolved**: there is no scope to read inside, and the only other mechanism would be opening
one tenant scope per membership in a single request — which breaks *one tenant scope per
request* for a display string and is a far larger change to the tenancy posture than one
more named column.

**And it adds no cross-tenant PATH.** `PROJECT_INSTRUCTIONS.md §4 › Tenancy` names *"a user's
own memberships"* as one of exactly four, and this function **is** that path. A restaurant's
name, for a restaurant the caller holds a membership in, is squarely inside it. `§2.8`'s
named list stays at **four**; rule 1's bound is unchanged — `RETURNS TABLE` with named
columns, **one** exact-match uuid argument compared with `=`, `EXECUTE` to `qrpager_app`
alone after `REVOKE ALL … FROM PUBLIC`. **No rank-2 edit, and none is taken.**

⚠ **The migration is `DROP FUNCTION` then `CREATE FUNCTION`, not `CREATE OR REPLACE`** —
Postgres refuses to change a function's return type in place, and the argument list is
unchanged so the drop is unambiguous. The grants are re-made in the same statement block,
because a grant does not survive its object.

**Roles:** `qrpager_app` only. No DDL at runtime, no `BYPASSRLS`.
**GUCs:** `app.tenant_id`, transaction-scoped, set by `withTenant()` via
`select set_config('app.tenant_id', ?, true)` — C1 and C2 unchanged. `app.bypass_tenant` is
set **inside the function body only** and restored on the error path too, which is the
pattern that function already has and which this migration preserves verbatim. **No third
GUC. No session-scoped setting.**

## 2. Which shared contracts does it use or add?

**Uses, adds none.** `PagerStatus` is the PHP enum `App\Domain\Pager\PagerStatus`, mirrored
into `@qrpager/contracts` by the generator; the board's badge is typed
`Record<PagerStatus, string>`, so **a status added in PHP fails the TypeScript build** until
its copy exists. That is the one source `§6.2` requires and it is already mechanised —
`StatusBadge` was built on it at Task 8a.

**`throttled` is NOT a shared contract and is deliberately not added to one.** It is a
mapping from an HTTP status to a client result kind, which lives in `src/staff/api.ts`
beside the other five. A domain enum for it would be a second source for something the
server expresses as `429`.

## 3. The exact path by which tenant context reaches every query

**The board and the four transitions — unchanged from Tasks 3 and 5, and the point is that
this ticket adds no new path:**

```
cookie session → AuthenticatedTenantResolver (membership the USER holds, from the session's
  own selection; never a header, never a body field, never a query string)
→ RequireTenantScope publishes app(RequireTenantScope::BINDING)
→ RequireAbility checks the route's ability by route NAME (RouteAbilities)
→ PagerSessionController::tenant() reads the binding
→ PagerSessions::board($tenant) / markReady($tenant, $id) / …
→ withTenant() → BEGIN; select set_config('app.tenant_id', ?, true); <query>; COMMIT
→ RLS: tenant_isolation_pager_sessions USING (tenant_id = current_setting(...)::uuid)
```

The session id is a **path parameter**; the tenant is **not**, and cannot be. A session id
belonging to another restaurant is invisible to the statement under RLS, so it answers
**404** and not 403 — a 403 would confirm the id exists.

**`GET /v1/auth/me` has no tenant scope, by design**, and that is the one query here that is
cross-tenant: `select * from public.user_memberships(?)` with the **guard's** user id.
`MembershipCallSiteTest` pins the set of call sites, because *"the user id comes from the
guard"* is a property of the call site rather than of the function. The widening in §1 does
not change that set.

## 4. Security — authn, authz, input validation, rate limit, log redaction, secrets

**authn.** The first-party session cookie Task 5 establishes. `Secure`, `HttpOnly`,
`SameSite=Lax`, pinned as exact values by `StaffSessionPolicyTest`. CSRF: the token is sent
even though a same-origin fetch would pass on `Sec-Fetch-Site` alone — 10a's reasoning, kept.

**authz.** `RequireAbility`, by route name. The board is `pager:read` (held by every
principal including a KDS device token — a kitchen screen's whole job); `ready` and
`renotify` are `pager:notify`; `complete` and `cancel` are `pager:resolve`. **Neither is
held by `Device`** — `Ability::issuableToDevice()` is a closed list of one — so a kitchen
screen can watch the board and cannot act on it, which is the design 10c inherits.

**input validation.** The four transitions take **no body at all**, and their one input is a
`whereUuid`-constrained path parameter, so a non-uuid is a routing miss behind the
unknown-path limiter rather than a cast error inside a query. The board takes no input of any
kind — no body, no query, no path parameter — which is the strongest validation answer
available: there is nothing to validate.

**rate limit.** The `api` bucket, per IP **and** per tenant. **`R-224`'s whole subject:** a
429 on this surface is now **ordinary**, because the board polls. The refusal carries
`Retry-After`, which the client honours as its backoff hint; `poll_after_seconds` is **not**
carried, because `PacedRoute` reads `config('pager.poll_interval')` — the **customer's**
7-second cadence — and making one config value govern two unrelated cadences is the second
source `§6.2` exists to prevent. Stated rather than left as an apparent omission.

**log redaction.** **The board carries no token and cannot.** `token_hash` is not in
`board()`'s select list and the raw token exists only in `store()`'s response (10d's screen).
So there is nothing on this surface to redact, which is why this answer is short rather than
thorough.

**secrets.** None added. No new environment variable.

**`R-222`'s disclosure, analysed rather than asserted.** `/v1/auth/me` returns **only the
caller's own memberships** and already drops suspended tenants, so publishing `name` tells a
staff member what the restaurants they work in are called. It enumerates nothing: there is no
input shape that returns a second user's set, and the set is the one the caller already holds
`slug` for. Muhammad's ruling and reasoning are in `PLAN §1`.

## 5. Scale — how many pagers on a board before it degrades, and what is bounded

**MEASURED IN-SESSION, on this project's composed Postgres 16, as `qrpager_app` inside a real
tenant scope, three runs per size, `ANALYZE`d between sizes. Not estimated.**

| live sessions in ONE tenant | `board()` execution time, three runs |
| --- | --- |
| 100 | 0.341 · 0.332 · 0.373 ms |
| 1 000 | 0.883 · 0.839 · 0.843 ms |
| 5 000 | 3.062 · 2.999 · 3.133 ms |
| 20 000 | 11.197 · 11.276 · 11.349 ms |

**The cost is LINEAR IN THE TENANT'S LIVE POPULATION, NOT IN THE 100 ROWS RETURNED, and the
plan says why.** At 18 441 live rows:

```
Limit → Sort (top-N heapsort, 32kB)
  → Index Scan using pager_sessions_expiry_index  (rows=18441, Rows Removed by Filter: 164)
      Filter: (tenant_id = (NULLIF(current_setting('app.tenant_id', true), ''))::uuid)
```

**`pager_sessions_board_index` on `(tenant_id, status, created_at DESC)` is NOT chosen**, and
that is a property of the query rather than of the statistics: `status IN ('waiting','ready')`
splits into two index ranges, each ordered by `created_at` within its own range and not
across them, so the ordering cannot be served by one scan and the planner reads the partial
expiry index and top-N sorts instead. Verified by re-running with an explicit `tenant_id`
literal (same plan, 4.56 ms) and with `enable_sort = off` (same plan, the sort unavoidable).

**So `LIMIT 100` bounds the RESPONSE, not the WORK** — stated plainly because the opposite is
the natural reading of that clause.

**What that means for a real restaurant, and why nothing is optimised here.** A pager session
reaches a terminal status within two hours of creation, so the live population is the orders
of one service, not the order history: at 100 live pagers the board costs **0.34 ms**, and a
restaurant with 20 000 simultaneously-live pagers is not a restaurant. `PROJECT_INSTRUCTIONS
.md §7` forbids optimising a layer before a load number exists and `§6` Q5 asks the cost to
be justified with a number; the number says **not yet**. Carried as a `§5` row with the
measurement, owned by the task that owns load — the same disposition and the same reasoning
as `R-223`.

**What this ticket ADDS to the load, which is the part Q5 is really asking:** the board is
polled, so the figure above is multiplied by `clients × (1 / interval)`. At the interval in
§8 below, five staff clients in one restaurant cost **~1 request/second/tenant**, i.e. ~0.34 ms
of Postgres time per second per tenant at 100 live pagers. The `api` bucket (per IP **and**
per tenant) is the ceiling, and the client's jittered backoff is what keeps a refusal from
becoming a retry storm.

**Bounded:** the response at 100 rows · one Postgres statement per poll · the renotify count
at `config('pager.renotify_limit')` = 5, which bounds `outbox` growth per session · the
client's backoff at `BACKOFF_CAP_SECONDS` = 120.
**Not bounded here:** `pager_sessions` row growth and `outbox` retention — `§2.11`'s existing
rows, Task 13/14's.

## 6. Failure — timeout, retry, duplicate, crash mid-way, and two staff at once

**Timeout / unreachable.** `fetch` rejects → `{kind:'unreachable'}`. **The board keeps showing
the rows it already has** and marks when they were last confirmed; it does not blank itself,
because the data is not wrong, it is merely not fresh. With no rows yet, the offline state.

**Retry.** The jittered interval with exponential backoff on consecutive failures, reusing
Task 9's `nextDelayMs` rather than a second implementation of the same arithmetic — `§4 ›
Realtime`'s client half. **A 429 honours `Retry-After` when the server sends one**, because an
advisory from a server that is already refusing beats a client's doubling.

**Duplicate.** Two kinds, and they are different. A **double-tap** is one client sending the
same transition twice: Task 3's `markReady` is idempotent and the second call sends no second
notification. **Two staff on two devices** is the `§0` invariant and is the hard one — see
below.

**Crash mid-way.** Task 3's property, unchanged and not re-proved here: the status change, the
`outbox` row and the `audit_log` row commit together or not at all. If this client dies
between the request and the response, the transition has either happened or not, and the next
poll shows which — which is why **the board is the source of truth and the button's own
optimistic state is never kept**.

**TWO STAFF MARKING THE SAME ORDER READY AT THE SAME INSTANT — the named invariant.** Proven
by driving **two concurrent clients against the real API**, in **two processes** rather than
two connections in one, for the reason `MemberConcurrencyTest` and `PagerIdempotencyTest` both
state: a blocking statement with both sides in one PHP thread deadlocks the only thread there
is instead of demonstrating order. The assertions are **exact counts on the server**: one
`ready` terminal state, **exactly one** `outbox` row for that session, and both clients'
subsequent board reads agreeing. **Never by trusting Task 3's idempotency from the client
side** — that is the mechanism under test, not the oracle.

## 7. Version-specific research, verified by running code in-session

```
$ php -r 'require "vendor/autoload.php"; echo \Composer\InstalledVersions::getPrettyVersion("laravel/framework");'
v13.38.0
$ node -e "const p=require('./apps/web/package.json'); console.log('next',p.dependencies.next,'| react',p.dependencies.react)"
next 15.5.28 | react 19.3.1
```

**Postgres, measured rather than recalled** — the plan and the four timings in §5, plus the
two control runs that establish *why* the board index is not used. Run as `qrpager_app`
inside a real tenant scope on the composed Postgres 16, because the owner's bypass policy
carries no `tenant_id` predicate and therefore plans a different query.

**`DROP` + `CREATE` for the function signature change, verified against the server rather
than assumed:** `CREATE OR REPLACE FUNCTION` raises `42P13 cannot change return type of
existing function` when the `RETURNS TABLE` column list grows. The migration drops first.

---

# `§12` — the screens, specified before they are coded

`§12` applies **in full, no exemption**. Copy in **English and Arabic**, because Arabic is a
launch language and the copy is part of the spec rather than a later pass.

## S6 · The board — at `/staff`, replacing 10a's placeholder body

**Purpose.** Show every pager that is still waiting or ready, newest first, readable from two
metres, and let a staff member act on one. This is the screen a service runs on.

⚠ **IT IS NOT A NEW ROUTE, AND THAT IS A DECIDE-AND-RECORD.** `apps/web/test/staff-build-
surface.test.ts`'s `declaredStaffRoutes()` pins the staff route set at **exactly two** and
asserts both are prerendered with no authenticated chrome. The board replaces `StaffHome`'s
body at `/staff`; **pager detail is a panel in the same screen, not a third route.** Three
reasons, in order of weight: a wall-mounted kitchen tablet in kiosk mode has no URL bar and no
back button, so a detail ROUTE is a place a person can get stranded; the board and the action
are one task in use, and a round trip through a router between them costs a poll interval of
freshness; and a third prerendered document is a third surface that must be proven to carry no
tenant data. Recorded here rather than discovered in the history.

| State | What is shown |
| --- | --- |
| `loading` | the staff loading primitive, announced. Only before the FIRST answer |
| `ready` | the rows, newest first, each with its order reference, status word and elapsed time |
| `empty` | *no orders are waiting* — with an action, because `§12` requires one |
| `error` | the error primitive with a retry. **Only when there is nothing to show**; with rows in hand the rows stay and the staleness marker appears instead |
| `offline` | the offline primitive with a retry, on the same condition as `error` |
| `throttled` | ⚠ **`R-224`. NO MESSAGE ON THE FIRST 429** — the backoff is working and nothing is required of the person. Copy appears **only once the backoff window is exhausted**, and it carries **no action**, because there is no button that would make it faster |
| `stale` | not a state but a marker on `ready`: *last updated {time}*, shown whenever the newest answer is older than one interval |

**Why `throttled` takes no action, in the type and not only in the copy.** `StaffState` gains
a `throttled` case shaped like `permission-denied` — title and description **required**,
action **absent** — so an actionless throttle does not merely read correctly, it is the only
thing that compiles. That is the same mechanism `§12`'s *no empty state without a next
action* already uses, pointed the other way.

**Exact copy.** EN: *"Orders"* · *"Order {reference}"* · *"Waiting {duration}"* ·
*"Preparing"* / *"Ready"* / *"Collected"* / *"Cancelled"* / *"Expired"* ·
empty *"No orders are waiting."* / *"An order appears here as soon as a pager is started for
it."* / action *"Check for new orders"* · error *"We could not load the orders."* /
*"Nothing has changed. Try again."* · offline *"No connection."* / *"These are the orders from
the last time we reached the network."* · throttled *"The board is catching up."* /
*"It will refresh itself in a moment. You do not need to do anything."* ·
stale *"Last updated {time}"*
AR: *"الطلبات"* · *"طلب {reference}"* · *"منذ {duration}"* ·
*"قيد التحضير"* / *"جاهز"* / *"تم الاستلام"* / *"ملغى"* / *"منتهي"* ·
*"لا توجد طلبات قيد الانتظار."* / *"سيظهر الطلب هنا بمجرد إنشاء جهاز نداء له."* /
*"تحقّق من الطلبات الجديدة"* · *"لم نتمكّن من تحميل الطلبات."* /
*"لم يتغيّر شيء. حاول مرة أخرى."* · *"لا يوجد اتصال."* /
*"هذه الطلبات من آخر مرة وصلنا فيها إلى الشبكة."* · *"اللوحة تتابع التحديث."* /
*"ستُحدّث نفسها بعد لحظات. لا حاجة لأي إجراء منك."* · *"آخر تحديث {time}"*

**Content inventory.** The order reference, the status as a WORD (never the `pager_status`
enum value), and the elapsed time. **No session id, no tenant id, no token, no customer
anything** — there is no customer identity in this product to show. Asserted, not intended.

## S7 · Pager detail — a panel over the board, not a route

**Purpose.** Do the one thing this order needs: tell them it is ready, buzz them again, say
they collected it, or cancel.

| State | What is shown |
| --- | --- |
| `idle` | the order, its status, and the actions legal **for that status** |
| `working` | the pressed action becomes busy; nothing else moves |
| `done` | the panel closes and the board refreshes immediately rather than at the next tick |
| `confirm-cancel` | cancel is destructive in effect, so `§12` requires it confirmed and marked not reversible |
| `limit` | the renotify cap is reached — a real 409, with the next action in words |
| `expired` | the session passed its two-hour TTL; the action is a new code, not a retry |
| `gone` | a 404: somebody else finished it. Not an error — the board was simply out of date |
| `failed` | anything else. *Nothing has changed* is the load-bearing half |

**Which actions are legal is read from the status, not from the button's own memory**, so two
staff cannot both be offered *"Tell them it is ready"* for a session one of them has already
readied: the second one's next poll removes it, and the server refuses it anyway with a 409.

**Exact copy.** EN: *"Tell them it is ready"* · *"Buzz them again"* · *"They collected it"* ·
*"Cancel this order"* · *"Close"* · *"Sending…"* · cancel confirm *"Cancel this order?"* /
*"The customer is told it is cancelled. You cannot undo this."* / *"Cancel the order"* /
*"Keep it"* · limit *"You have buzzed them as many times as we allow. Go and find them
instead."* · expired *"This pager has run out. Give the customer a new code."* ·
gone *"That order is not on the board any more. Somebody else may have finished it."* ·
failed *"That did not go through. Nothing has changed — try again."*
AR: *"أبلغه أن الطلب جاهز"* · *"نبّهه مرة أخرى"* · *"تم استلام الطلب"* · *"إلغاء الطلب"* ·
*"إغلاق"* · *"جارٍ الإرسال…"* · *"إلغاء هذا الطلب؟"* /
*"سيتم إبلاغ العميل بالإلغاء. لا يمكن التراجع عن ذلك."* / *"إلغاء الطلب"* /
*"الاحتفاظ به"* · *"لقد نبّهته العدد المسموح من المرات. ابحث عنه بنفسك."* ·
*"انتهت صلاحية جهاز النداء. أعطِ العميل رمزًا جديدًا."* ·
*"هذا الطلب لم يعد على اللوحة. ربما أكمله شخص آخر."* ·
*"لم تنجح العملية. لم يتغيّر شيء — حاول مرة أخرى."*

## S1 · Sign-in — ONE state added, and its copy DIFFERS from the board's

⚠ **`R-224`, and this is the half the row itself had folded into one.** Muhammad's ruling:
*"on the board nothing is required of the person and it resumes itself; on sign-in they must
wait before trying again."* So `SignInStage` gains `throttled`, the form stays usable, and the
sentence tells them to wait — where the board's tells them to do nothing.

**Exact copy.** EN: *"Too many tries. Wait a minute, then sign in again."*
AR: *"محاولات كثيرة. انتظر دقيقة ثم سجّل الدخول مرة أخرى."*

## S2 · The shell — the chooser labels by NAME

⚠ **`R-222`'s ruling.** The restaurant chooser renders the restaurant's **name**. The slug
stops being the label and stops being carried in client state for that purpose; the route
still returns it, because a published response shape is not changed in place.

**The two-same-role case stays asserted**, because it is the case that produced `R-222`: two
owner memberships must render two distinguishable buttons. They now differ by name, which is
what a person recognises, rather than by a DNS label.

## The polling cadence

**Five seconds**, jittered ±20 %, doubling on consecutive failure to the existing 120-second
cap, honouring `Retry-After` when the server sends one.

**Why five and not the customer's seven.** They are different products of the same mechanism:
a customer is waiting for one event on one phone and seven seconds was sized against the `C6`
ceiling of 300 000 concurrent sessions; a kitchen board is a handful of screens per restaurant
watching a list that changes constantly, so the cost is bounded by **staff headcount** rather
than by order volume. The two values are therefore two constants with two reasons, and
**not** one config key serving both — which is the drift `§6.2` mechanises against elsewhere.

⚠ **A FIGURE THIS DOCUMENT CANNOT PROMISE, AND IT IS FLAGGED HERE RATHER THAN DISCOVERED AT
THE GATE.** `§3` row 10's *"mark-ready round trip under 2 s to the customer device"*, measured
by the approved method — over the **polling** path, because push is not drivable by a runner —
is bounded below by the **customer's** poll interval, which is `config('pager.poll_interval')`
= **7 seconds**. A uniform wait over one interval has a median of ~3.5 s, so **the median
cannot come in under 2 s over polling at the current interval, whatever this ticket builds.**
The five runs will be reported as the method requires, with the arithmetic beside them.
Shortening the customer interval is a scale decision — `§2.11` sizes ElastiCache at ~300 000
Redis operations/second from that very number — so it is **not** a builder's call. It is
raised in the handover with the measurement.
