# QRPAGER-16 — Task 9, Customer PWA: scan → wait → ready

> Working file for the task. `PROJECT_INSTRUCTIONS.md §12` requires every screen to be
> specified — purpose, all states, content inventory, exact copy — **before it is coded**,
> and `§6` requires the seven pre-coding questions answered in writing first. Both are
> below, committed before the first implementation commit.
>
> This file restates no rule from `PROJECT_INSTRUCTIONS.md` or `PLAN.md`. On disagreement
> with either: **stop and report (`§0`)** — which is what `§0` of this file is.

**Branch** `task-9-customer-pwa` · **base** `58ca1ea` (see `SR-2`) · **ticket** `QRPAGER-16`
· **epic** E5 · named invariant `§4.0` of the ticket, confirmed 2026-11-01.

---

## §0. STOP-AND-REPORTS — five, all raised before the first implementation commit

`§0` of `PROJECT_INSTRUCTIONS.md`: *never silently resolve a conflict, stop and report it.*
Each item below names the conflict, what was measured, which reading this task built
against, and what changes if Muhammad rules the other way. None was resolved in a docblock
— which is the defect Task 3's security `S-1` was actually about.

### `SR-1` ⛔ THE SCAN LANDING'S URL SHAPE. `§4.1` says `/s/{token}`; `§3` row 3 as amended says the path is forbidden.

**The conflict, quoted from both sides, same file.**

* `§4.1` (the page inventory): screen **`Scan landing /s/{token}`**. `§3.1` row 9 and the
  ticket's `§4.1` Lighthouse method of 2026-11-01 both carry that spelling forward —
  *"the scan landing route `/s/{token}` at a real issued token"*.
* `§3` row 3, amended 2026-10-27 by Muhammad from security `S-1`: *"The token goes in the
  **fragment** … **The path and the query string stay forbidden**, which is what the
  original clause was protecting"* — and the reason given is `§4 › Edge & ops`:
  *"the path stays constant so a scan burst is one cache entry rather than one per scan."*

**And the rank-1 brief states the path too**, in trap 1: *"It arrives in the path and the
harness uses a fragment."* That makes this a rank-1-versus-rank-3 conflict and not only an
intra-`PLAN` one, which is why it is the first item here.

**Measured, not argued.** `App\Domain\Pager\PagerToken::qrPayload()` is the only producer of
a scannable payload in the repository:

```php
return rtrim($landingUrl, '#').'#'.$this->reveal();
```

So the artefact a camera actually scans is `{pager.landing_url}#{token}` — **a fragment, with
a constant path** — and `config('pager.landing_url')` is `https://…/p`, which carries no token
segment. `BootChecks` refuses to boot if that URL *"already carries a fragment"*. There is no
code path anywhere in `apps/api` that puts a token in a URL path.

**Built against: the fragment, on a constant path `/s`.** Three reasons, in order of weight.

1. It is the **later and explicit** ruling, it names the other reading and strikes it
   (*"the path and the query string stay forbidden"*), and it is attributed to Muhammad with
   a date and a finding id. `§4.1`'s `/s/{token}` predates it by two days.
2. It is the only reading compatible with `§4 › Edge & ops`'s *"The QR landing page is
   static and CloudFront-cached. A scan burst must never reach origin"* — a token in the
   path is one cache key per scan, i.e. one origin miss per scan, which is that clause
   inverted. `§3` row 3's amendment says so in its own words.
3. It is the shape the **producer already emits**. Building `/s/{token}` would mean the page
   could never be reached by scanning the QR code this product generates.

**What changes if Muhammad rules the other way:** one file, `apps/web/app/s/page.tsx`, plus
the route folder name. The journey, the screens, the copy, the poller and the service worker
read the token from one function (`readPresentedToken()`), so the switch is that function and
nothing else. **But the ruling would also have to re-open `§4 › Edge`'s cache clause and
`§3` row 3's amendment**, which is why this is reported rather than chosen.

**What this task did NOT do:** accept both shapes. A route that also honours
`/s/{token}` would *create* the exposure the amendment removed — the token in the access
log, the `Referer` and the CDN cache key — for every client that ever used it. One shape,
reported.

### `SR-2` ⛔ THE BASE. Branching from the `task-8b` tag would have deleted four things the brief tells this task to use.

**The instruction:** *"cut `task-9-customer-pwa` FROM THE `task-8b` TAG. Do not branch from
a loose sha."*

**Measured.** `git rev-parse task-8b` resolves (tag object `a075381`, commit `5ab40fc`). The
branch `task-8b-panel-restyle` is **two commits ahead** of it, and
`git diff --name-only task-8b..58ca1ea` is 22 paths. Four of them are things the same brief
and the same ticket require this task to consume:

| absent at the `task-8b` tag | who requires it |
| --- | --- |
| `apps/api/bin/classify-reds`, `apps/api/bin/known-flakes-summary` | the brief: *"`bin/classify-reds` exists … Use it, and never explain a red by hand"* |
| `apps/api/tests/known-flakes.json` | ticket `§6`: discharge *"all previous tasks' tests still pass"* against it |
| `apps/web/app/harness/push/*`, `apps/web/public/{sw.js,manifest.webmanifest,harness-icon.svg}` | ticket `§2`: *"this task **extends** that harness, it does not rebuild it"*; the brief: *"its harness is built … you inherit the pattern"* |
| `apps/api/bin/gate`'s classifier wiring, `docs/PLAN.md`'s post-8b `§0` | the brief: order of work, and `CLAUDE.md`'s *read `§0` first* |

So the two instructions cannot both be obeyed. **There is also no tag that carries the
harness, by instruction:** `PLAN §0` says *"`R-073` is not closed and `QRPAGER-24` is not
tagged until that result is in `§4a`"*, and the device run has not happened.

**Built against: base `58ca1ea`**, which is **the tip of the pushed, named branch
`origin/task-8b-panel-restyle`** — not a loose sha — and is a strict descendant of the tag:

```
git merge-base task-8b^{commit} 58ca1ea   ->  5ab40fcddab6fe3c04a2b69c784cad991c4290c6
git merge-base --is-ancestor task-8b^{commit} 58ca1ea   ->  exit 0
```

This is the same form `§0.0` used for 8b (*"whose base `git merge-base` confirms **is**
`d8c9652`"*), and the base is **recorded here before the first implementation commit**
rather than left to be discovered in the history — which is the actual defect behind the
*do not branch from a loose sha* rule (`PLAN §0`'s *Last completed*: Task 8a's base was an
untagged descendant and *"read by tags alone the chain is `task-7` → `task-8a` with
`049934b` in between and unnamed"*).

### `SR-3` ⛔ `.claude/commands/task.md` FORBIDS WHAT THE BRIEF AND `R-189` REQUIRE.

`.claude/commands/task.md`: *"**Never spawn, invoke or simulate the qa-reviewer or
security-reviewer agents.** … The human starts both reviews, in their own sessions, after
the PR is open."*

The rank-1 brief: *"THEN RUN THE REVIEWS YOURSELF, without waiting on Muhammad: two
subagents, each in ITS OWN git worktree pinned at your head."* And `PLAN §5 R-189`, filed
2026-11-01, makes it the **builder's** procedure: *"**The builder, every round from now
on** — it is a procedure, not code. ⚠ THE RULE: each reviewer gets its OWN `git worktree`
pinned at the sha under review, created before launch and named in its brief"*, recording
that *"8b's round two and 8a's fix-diff review were both run that way"*.

**Built against: the brief.** Rank 1 wins on `§0`'s precedence, and `R-189` is the project's
own later ruling with two rounds of precedent behind it. **The file is NOT edited by this
task** — `§11` names it as repo wiring and `§0` forbids a session resolving a conflict by
rewriting the lower-ranked document. It is reported so somebody with the authority can
reconcile the two.

### `SR-4` ⛔ `§4.1` REQUIRES RESTAURANT BRANDING ON THREE SCREENS AND NO ENDPOINT SUPPLIES IT.

`§4.1` requires *"Restaurant mark"* on the scan landing, *"restaurant branding"* on waiting,
*"a path back to the restaurant page"* on session-ended, and `@qrpager/ui`'s `OrderReady`
already takes a `logo` slot.

**Measured against the two endpoints this task is allowed to call.**
`CustomerPagerController::ok()` returns `status`, `order_reference`, `expires_at`,
`ready_at`, `as_of`, `poll_after_seconds`. `PagerStatusView::toArray()` returns `status`,
`order_reference`, `expires_at`, `as_of`, `poll_after_seconds`. **Neither carries a tenant
name, a logo URL, a brand colour or a landing-page URL**, and `PagerStatusView`'s docblock
says that is deliberate:

> *WHAT IT DELIBERATELY DOES NOT CARRY: no tenant id, no session id, no restaurant name, no
> token and no hash. … anything in here is something an attacker who has stolen a token can
> read.*

So supplying branding is a **widening of what a stolen token discloses**, on **Task 3's and
Task 4's response shapes**, both of which ticket `§2` puts out of scope (*"Session creation,
token issuing, state transitions → Task 3"*, *"polling endpoint → Task 4"*).

**Built against: the slots exist and render nothing tenant-specific.** Every screen that
`§4.1` asks to brand takes an optional `brand` prop (`name`, `logo`, `homeHref`); the
journey passes `undefined`; the product mark is rendered instead, and the session-ended
screen's *path back* is a **re-scan instruction** rather than a dead link. When the field
arrives the change is a value at one call site, not a mechanism. **Filed as a `§5` row with
an owner and a date** rather than built around — see `§7` of this file.

### `SR-5` ⛔ THE NAMED INVARIANT'S MECHANISM DOES NOT EXIST ON THE PLATFORM THE PRODUCT IS MOST AT RISK ON.

The task's named invariant (`§3.1` row 9, ticket `§4.0`) is *"a rotated subscription is
re-registered by the `pushsubscriptionchange` handler and the next notification still
arrives."*

**`pushsubscriptionchange` is not implemented on iOS Safari at all.** Primary source, MDN
`browser-compat-data` `api/ServiceWorkerGlobalScope.json`, read this session:

| key | `pushsubscriptionchange_event` |
| --- | --- |
| `safari_ios` | **`false`** — no `version_added` |
| `safari` | `16` (note: *"Notifications are supported on macOS Ventura and later"*) |
| `chrome` | `138` |
| `edge` | `17`, **`version_removed: 79`** |
| `firefox` / `firefox_android` | `44` / `48`, **`partial_implementation: true`** — note: *"The event does not have the `oldSubscription` and `newSubscription` properties"* |

Corroborated by **WebKit Bugzilla 273063** (PWA push subscriptions revoked outside user
interaction; the event is unsupported on mobile, unassigned) and by WebKit **249766**, closed
**WONTFIX**, which records that a push to an **expired Safari endpoint still answers 200**
rather than an error — so Task 4's dispatcher's *"404 / 410 means a dead subscription"*
pruning never fires for a Safari endpoint either.

**Why this is a stop-and-report and not just a note.** `§4 › Realtime` says *"iOS: Web Push
only works from a Home Screen install"* and `R-073` calls iOS *"the project's largest product
risk"*. The invariant's stated mechanism is therefore absent on exactly the platform the
invariant exists to protect, and a handler-only implementation would be **green in Chromium
and dead on an iPhone** — the `R-126` shape, one layer up.

**Built against: the invariant's OUTCOME, with the handler as one of two mechanisms.**

1. The `pushsubscriptionchange` handler is implemented and **driven in a real engine**, which
   is what the clause literally names.
2. **`reconcileSubscription()` runs on every launch and every `visibilitychange` to
   visible**, compares `pushManager.getSubscription()` against what was last registered, and
   re-registers through the same real endpoint when they differ or when it is `null`. This is
   engine-independent and is the only mechanism that works on iOS. `POST
   /v1/pager/subscription` is already idempotent by Task 4's own contract — *"Calling this
   again with the same endpoint updates the stored keys rather than adding a second device,
   so it is safe to repeat on every page load"* — so this needs no new endpoint and no
   change to Task 4.

Both are asserted, separately, and the second is asserted **with the handler removed**, so
the iOS path is proven to hold without it. **The wording of the invariant is Muhammad's to
amend, not this task's**, which is why the handler is built rather than replaced.

---

## §1. RESEARCH — ticket `§3` question 7, verified against primary sources in this session

> *"Current Web Push and Wake Lock support per browser, and the iOS Home-Screen install
> requirement, verified against primary sources in this session. Paste the findings."*

**Two kinds of evidence, kept apart.** A compatibility table is a *claim about engines in
the world*; a probe is a *measurement of the engines this gate runs*. Neither substitutes
for the other, and the second is what `§8` means by verifying in-session.

### 1.1 Primary-source compatibility — MDN `browser-compat-data`, `main`, read 2026-11-01

`api/PushManager.json`, top-level support block and `.subscribe` (identical):

| chrome | edge | firefox | firefox_android | safari | safari_ios | webview_android |
| --- | --- | --- | --- | --- | --- | --- |
| 42 | 17 | 44 | 48 | 16 ¹ | **16.4** ² | **false** |

¹ *"Notifications are supported on macOS Ventura and later."*
² *"Notifications are supported in web apps saved to the home screen."* — the clause
`§4 › Realtime` already states, in Apple's own compat note.

`api/ServiceWorkerGlobalScope.json` → `push_event`: safari_ios **16.4**, same note.
→ `pushsubscriptionchange_event`: **safari_ios `false`**; chrome **138**; edge 17 **removed
in 79**; firefox 44 / firefox_android 48 **partial** (*"The event does not have the
`oldSubscription` and `newSubscription` properties"*). This is `SR-5`.

`api/WakeLock.json` (`WakeLock` and `WakeLock.request`, identical):

| chrome | firefox | safari | safari_ios |
| --- | --- | --- | --- |
| 84 | 126 | 16.4 | **18.4** full; **16.4–18.4 partial** |

The partial note, verbatim: ***"Does not work in standalone Home Screen Web Apps. See bug
254545."***

⚠ **That is the exact configuration this product requires.** `§4.1` asks the waiting screen
for a *"keep-screen-awake toggle"*; on iOS 16.4 through 18.4, installed to the Home Screen —
which is the only configuration in which the product's notifications work at all — the API
is **present and non-functional**. So the toggle may not be rendered from a feature
detection: `'wakeLock' in navigator` is `true` there and lies. It is rendered from the
**outcome of a real `request()`**, and its three outcomes are three content states
(`§3.3`).

### 1.2 Apple's own statement on the install requirement — WebKit blog, read 2026-11-01

<https://webkit.org/blog/13878/web-push-for-web-apps-on-ios-and-ipados/>:

* *"Now with iOS and iPadOS 16.4, we are adding support for Web Push to Home Screen web
  apps."*
* *"A web app that has been added to the Home Screen can request permission to receive push
  notifications"*.
* The permission request must be *"in response to direct user interaction"* — so
  `Notification.requestPermission()` is called from a click handler and never from an
  effect. QRPAGER-24's harness already does this and says why; this task inherits it.

### 1.3 Measured in the three engines this gate runs — `storage/probe/engines.mjs`

Toolchain: container `qrpager-node22-php-pw:local`, Node **v22.23.4**, Playwright **1.64.1**.

| capability | chromium **156.0.8078.4** | firefox **157.0** | webkit **27.2** |
| --- | --- | --- | --- |
| `navigator.serviceWorker` | yes | yes | yes |
| `PushManager`, `PushSubscription` | function | function | function |
| `ServiceWorkerRegistration.prototype.pushManager` / `.showNotification` | yes / yes | yes / yes | yes / yes |
| `Notification`, `Notification.permission` | function, string | function, string | function, string |
| `navigator.wakeLock` | **yes** | **yes** | **yes** |
| `navigator.vibrate` | **yes** | **no** | **no** |
| `navigator.standalone` | absent | absent | absent |
| `matchMedia('(display-mode: standalone)')` | boolean | boolean | boolean |
| `caches`, `AudioContext`, `history.replaceState` | yes | yes | yes |

**Two findings that change the design.**

* **`navigator.vibrate` is absent in two of the three engines.** `§4.1` requires the
  order-ready screen to carry *"sound + vibration"*. Vibration is therefore an
  **enhancement, attempted and allowed to be absent**, never a precondition of the alert —
  and the alert itself is the notification plus the full-bleed screen plus the assertive
  live region, each of which works in all three.
* **`navigator.wakeLock` is `true` in all three**, while `§1.1` records it as non-functional
  in the one configuration that matters. Presence is not capability. This is why `§3.3`'s
  toggle is driven by a real `request()` and not by a detection.

### 1.4 `R-194`'s own question, measured — which engines send `Sec-Fetch-Dest`?

`R-194`: *"`PanelProblemPage::wants()` requires `Sec-Fetch-Dest: document` … Engines that do
not send `Sec-Fetch-*` get `application/problem+json` on a genuine navigation."* Its owner
cell says **Task 9** owns it because *"it is the task that first drives real engines and can
therefore measure which of them send the header."* Measured, on a genuine top-level
navigation to a local HTTP server:

| engine | `sec-fetch-dest` | `sec-fetch-mode` | `sec-fetch-site` | `sec-fetch-user` |
| --- | --- | --- | --- | --- |
| chromium 156.0.8078.4 | `document` | `navigate` | `none` | `?1` |
| firefox 157.0 | `document` | `navigate` | `none` | `?1` |
| webkit 27.2 | `document` | `navigate` | `none` | **absent** |

**All three send `Sec-Fetch-Dest: document`.** So `wants()` is satisfied by every engine this
project's gate can drive, and the narrowing `R-194` names is real only for engines none of
these three is — Safari before 16.4, older embedded webviews, anything scripted. **The row
is therefore narrowed, not closed**, and what closes it is a sentence being qualified rather
than code: see `§7`.

---

## §2. `§6` PRE-CODING GATE — all seven, answered before any implementation commit

### 1. Which tables / columns / roles / GUCs does this touch?

**None. Not one.** This task adds no migration, no model, no query, no connection and no
GUC. `apps/web` is a statically prerendered Next application; its only reach into the
backend is four HTTP calls to routes that already exist and are already tested
(`routes/customer.php`, Task 4):

```
POST   /v1/pager/session        resolve a scanned token
POST   /v1/pager/status         the live status (Redis-only, zero Postgres)
POST   /v1/pager/subscription   register a device
DELETE /v1/pager/subscription   forget a device
```

The data contract (`PLAN §2`) is therefore **unchanged**, which is why it is not updated
first — `§6` question 1's *"any change to the data contract → update `PLAN.md` first"* has no
antecedent here. `apps/api` is touched in exactly **two** places and neither is data: a
`const` extracted in `PagerToken` so the token's shape has one source (question 2), and the
generator that mirrors it into TypeScript.

### 2. Which shared contracts does it use or add? One source only.

**Used:** `@qrpager/contracts` → `PAGER_STATUS`, `PagerStatus`, `PAGER_STATUS_TRANSITIONS`.
Generated from `App\Domain\DatabaseEnums::map()` by `php artisan contracts:export`;
`composer contracts:check` byte-diffs it and fails the gate when stale. The journey's
status handling switches on that type, so a status added in PHP is a **type error** in this
app rather than a screen that silently renders nothing.

**Added, and it is an addition to the GENERATOR rather than a second copy.** The client has
to know the token's shape — it validates `location.hash` before it sends anything — and a
literal `22` in TypeScript would be exactly the second source `§6.2` forbids: it agrees on
the day it is written and drifts silently after. So:

* `PagerToken::LENGTH` already exists; `PagerToken::ALPHABET` is **extracted from the regex
  that was already there**, and `isWellFormed()` now builds its pattern from both constants,
  so PHP still has one source and it is the same one.
* `StatusMirror` emits `PAGER_TOKEN_LENGTH` and `PAGER_TOKEN_PATTERN` into
  `packages/contracts/src/generated/status.ts` from those two constants.
* `contracts:check` and `StatusMirrorTest` make it stale-checked like every other mirrored
  artefact, and a Vitest case asserts the TypeScript validator accepts and refuses exactly
  what the PHP one does, **at the generated values**, not at typed ones.

**Not added:** no second status enum, no second problem-type vocabulary (the client switches
on the `type` URI string the API already publishes, read from the response, never
re-declared as a list it could drift from — `ProblemType` is PHP's and the three URIs this
client distinguishes are asserted against `docs/openapi.json`).

### 3. The exact path by which tenant context reaches every query.

**There is no query, and there is no tenant context on this surface — by design, and that
is `§4 › Tenancy`'s second authentication surface rather than an omission.**

The customer has no account. The token *is* the authentication, and it resolves server-side
to exactly one session in exactly one tenant. The client therefore:

* holds the token and **nothing else** — no tenant id, no session id, no restaurant
  identifier. It could not name a tenant if it wanted to, because no response it receives
  carries one (`SR-4` is the cost of that, recorded rather than worked around);
* sends the token in the **request body** on all four calls, which is why all four are
  `POST`/`DELETE` and the read is not a `GET` (`PresentedTokenRequest`'s docblock, and
  `BODY_ONLY` refuses a token in the query string with a 422);
* reaches a surface whose own routes are deliberately **not** on `RequireTenantScope`,
  because there is no tenant to require before the token is resolved.

So the chain is: QR fragment → `readPresentedToken()` → request body → `PagerTokenLookup`
→ `resolve_pager_token()` (the `SECURITY DEFINER` function, `§4 › Tenancy`'s named
cross-tenant read) → a tenant id the server opens its own scope with. **The client is on no
part of that chain after the body.**

### 4. Security: what an attacker learns from a scan page; the token; what the service worker caches.

**What an attacker with the page but no token learns: the product exists.** The scan landing
is one static document, identical for every tenant and every session, containing no tenant
name, no order data and no configuration beyond the API origin and the VAPID **public** key.
That is a consequence of `SR-4`, not a mitigation anyone designed, and it is worth stating
plainly.

**What an attacker with a stolen token learns:** `status`, `order_reference`, `expires_at`,
`as_of` — and can register or remove a push device for that session, bounded by
`webpush.worker.max_devices_per_session` with newest-wins eviction (`R-091`). That is the
server's existing surface and this task widens it by nothing.

**The token is treated as a secret on the client, which is where this task's own clauses
are.** `§3` row 9's three mechanical clauses, each falsifiable:

| clause | how it is met | how it is proven |
| --- | --- | --- |
| (a) read `location.hash` once, strip immediately with `history.replaceState` | `readPresentedToken()` is called once, from the first effect, and replaces the URL with `location.pathname` before it returns | a Playwright case asserts `location.hash === ''` and `location.href` has no `#` **after first paint**, and that `history.length` grew by **0** and no entry's URL contains the token |
| (b) the landing origin loads **no** third-party script | asserted **against the built output** — every `<script src>`, `<link href>`, `@font-face url()` and `fetch()` literal in `apps/web/.next` is resolved and required to be same-origin or relative | `apps/web/test/build-surface.test.ts`, red on a planted `https://cdn.example/x.js` |
| (c) a CSP whose `script-src` makes (b) **impossible** rather than merely untrue | ⛔ **NOT MET IN THIS TASK, and it is `R-171`** — see below |

⚠ **Clause (c) is the one clause of `§3` row 9 this task does not meet, and it is stated
here rather than discovered in a review.** `R-171` records that both frontend origins have
no `headers()` and that the headers *"do not belong in `next.config.mjs` on this
architecture"* — both apps are statically prerendered, so in production the bytes come from
CloudFront in front of S3 and `next.config.mjs`'s `headers()` applies only to what `next
start` serves. The row is owned by **Task 13** for the mechanism and **Task 12** for the
CSP's content, review-by **2026-12-06**, and its acceptance clause is *"a **captured
response** from a deployed environment carries every one of the five"*. There is no deployed
environment, so there is nothing to capture, and a `headers()` block committed here would be
a control that is green locally and absent where it matters.

**What this task DOES add to that row, because it is the task that made it bite:** the CSP
Task 12 has to write is now **derivable rather than invented**. `build-surface.test.ts`
emits `docs/contracts/web-csp-surface.json` — the complete set of origins, the inline-script
COUNT and connect targets the built app actually needs — and asserts it is unchanged.

⚠ **THIS SENTENCE SAID *"inline-script hashes"* AND THE ARTEFACT CARRIED NONE** (`§11` round
one, SEC L-4) — and the first fix, which emitted them, was **wrong**: measured across two
builds of one commit, four of the six hashes are identical and **exactly two differ**, because
Next's RSC payload pushes carry build-specific content. `fresh-clone-proof`, now a CI step,
caught it on the clone. So a committed hash list would be wrong on every machine but the one
that wrote it, and the artefact records that measurement instead. **The consequence is the part
Task 12 needs: a `script-src` policy for this page CANNOT be static** — it must be emitted by
the build from that build's own output, or by the edge as a per-response nonce. The hashes are
computed on every run and asserted for count, shape and distinctness, so the capability is
proven without pinning a build artefact.

The policy is `default-src 'self'` plus that file, and a future diff that needs a wider policy
fails a test rather than quietly widening one.

**What the service worker caches, which ticket `§3` asks explicitly.**

| cached | strategy | why |
| --- | --- | --- |
| the app shell: the `/s` document, its JS/CSS chunks, the two self-hosted font files, the product mark | **cache-first, versioned by build id**, precached on `install` | `§4 › Edge`: *"the QR landing page is static and CloudFront-cached. A scan burst must never reach origin."* A cold-3G second visit must not refetch the shell, and the offline screen is unreachable without it |
| **nothing else. No API response is ever cached, and no token is ever cached by the shell cache.** | — | a poll response is per-session data behind a per-session secret; a cached copy is that answer left on a device the customer may hand to someone else |
| the token, in **one** `Cache` entry under its own cache name, written by the page | read **only** by `pushsubscriptionchange` | a service worker cannot read `localStorage`, and the rotation handler must be able to authenticate. QRPAGER-24's harness established this and named it as the thing Task 9 must harden; `§4` of this file says how |

The token cache is **deleted** when the session reaches a terminal status, on `DELETE
/v1/pager/subscription`, and — **as of `§11` round one, SEC M-2** — by `sw.js`'s own expiry check, which
drops the entry on READ as well as on `activate` once its `expiresAt` has passed, treating an ABSENT
expiry as expired. ⚠ **THIS SENTENCE WAS FALSE WHEN WRITTEN:** `CREDENTIAL_CACHE` was in the worker's
`OWNED` list, so `activate` deleted only caches NOT in it, and no expiry was checked or even stored —
so a customer who collected their order and closed the app left a live 128-bit bearer token on the
device for the whole 2-hour TTL. Asserted in `browser/sw.spec.ts` (expired entry, and no-expiry entry)
and in `browser/pager.spec.ts` (both homes empty after a terminal status, in a real engine) —
so a device that is handed on does not carry a live credential. Asserted.

### 5. Scale: cost per request and per active session. What is bounded?

**Per waiting customer, per minute:** one poll every `poll_after_seconds` ± jitter.
`config('pager.poll_interval')` is **7**, and the client's jitter is **±20 %**, so the
interval is uniform on **[5.6 s, 8.4 s]** and the long-run rate is **8.57 requests/minute**
per device. `ratelimit.pager-poll` allows **240/60 s per IP**, i.e. 28× headroom for one
device and a real bound at ~28 devices behind one NAT.

**Jitter is the point, not politeness.** `§4 › Realtime` requires a *client-side jittered
interval*: without it every phone that scanned during one lunch rush polls on the same tick
forever, because they all started from the same server-advertised interval. The jitter is
drawn **per request**, not once per session, so two devices that happen to align separate
again.

**Bounded:**

| quantity | bound | by what |
| --- | --- | --- |
| polls per device per minute | 8.57 mean, 10.7 worst case | `poll_after_seconds` + jitter floor |
| consecutive 503s before the client stops polling | **6** → 5.6 s, 11.2 s, 22.4 s, 44.8 s, 89.6 s, 120 s (capped), then the degraded state and a manual retry | exponential backoff, base 2, cap 120 s |
| requests on the scan path | **1** per scan; the document itself is static and CDN-served | `§4 › Edge` |
| `POST /v1/pager/subscription` calls | **1** per launch at most, and only when `reconcileSubscription()` finds a difference | the reconcile compares before it posts — against `localStorage` first and, when that is unavailable, against the credential `Cache` entry's own `endpoint`. ⚠ **THE BOUND WAS FALSE UNTIL `§11` ROUND ONE, SEC M-4:** `remembered` was read through `env.storage?.`, so with storage null (Safari private browsing, blocked site storage) it was ALWAYS null, the `unchanged` branch was unreachable and the reconcile posted on EVERY return to visibility — one POST and one Postgres upsert per tab focus. Measured by the reviewer at 5 calls → 5 POSTs. `notify.test.ts` now drives five launches with `storage: null` and an unchanged endpoint and asserts exactly one POST |
| notification count for one order | **1** visible notification, however many pushes arrive | the `tag` from the payload (`R-075`) |
| token copies on the device | **2** — one `localStorage` entry, one `Cache` entry — both deleted on a terminal status | asserted |

**Not bounded, and said rather than left:** the number of *scans* of one printed QR code is
the restaurant's physical control, not this client's. A client-side timer is wall-clock and
a backgrounded tab is throttled by the engine; the elapsed timer therefore recomputes from
`as_of` on every poll rather than counting its own ticks, so a phone that slept for twenty
minutes shows the truth on its next successful poll instead of being twenty minutes behind.

### 6. Failure: permission denied; offline mid-wait; expired token; invalid token; phone sleeps; app backgrounded.

Each is a **named state with its own copy**, not an error path. The six the ticket lists,
with the state they land in and the thing that proves it:

| failure | state | the answer the client acts on | proven by |
| --- | --- | --- | --- |
| permission denied | `alerts: denied` | `Notification.requestPermission() → 'denied'` | a Playwright case that denies the permission in a real context and asserts the copy, the absent retry, and that polling **continues** |
| offline mid-wait | `waiting: offline` | a thrown `fetch` **or** `navigator.onLine === false` | a case that routes every request to `abort` mid-wait and asserts the offline state, then restores and asserts recovery without a reload |
| expired token | `ended: expired` | **409** `…/problems/expired` | a case driven against the real API with a session whose `expires_at` is in the past |
| invalid token | `landing: invalid` | **404** `…/problems/not-found`, or a fragment that fails the generated shape check with **no request at all** | two cases: a well-formed unknown token (404) and a malformed one (zero network requests — asserted on the request log, not inferred) |
| phone sleeps | the timer recomputes from `as_of`; the poller skips a missed tick rather than firing a burst | `visibilitychange` → one immediate poll, then the normal cadence | a case that hides the document for 60 s of fake time and asserts **one** catch-up request, not nine |
| app backgrounded | as above, plus the notification is the channel | the push arrives at the service worker, which the page is not required to be alive for | the rotation and delivery cases in `§6` of this file |

**And the two answers the polling endpoint gives that must be handled differently** — `§3`
row 9 clause (c): **404 `…/problems/stale`** means *this server no longer holds a live
status, open the session again*, and **503** means *come back shortly, nothing is wrong*. A
client that treats them alike either abandons a live session or hammers a degraded one. They
are two distinct transitions in the reducer, each with its own case, and the 503 path is the
only one that touches the backoff.

### 7. Research for anything version-specific, verified by running code.

`§1` of this file: primary-source compatibility data, Apple's own statement, and a probe of
the three engines the gate drives. Every version number beside every figure in this file and
in the final report names the engine that produced it.

---

## §3. THE SCREENS — `§12`'s specification, written before any screen was coded

`§4.1` is the inventory and **every state in it is a deliverable**. Each screen below has
its purpose, every state, its content inventory and its **exact copy in both launch
languages**. `§12` forbids a component inventing copy, so every string a customer can see
is in `apps/web/src/copy/{en,ar}.ts`, keyed by the ids below, and
`apps/web/test/copy.test.ts` asserts the two records have **identical key sets** — a missing
Arabic string is a failing test, not a screen that falls back to English.

### 3.0 One route, six screens, and why that is the right shape

All six are **states of one route, `/s`**, not six documents. Three reasons, each a clause
rather than a preference:

1. **`§4 › Edge`** — *"The QR landing page is static and CloudFront-cached. A scan burst must
   never reach origin."* One document is one cache entry. Six routes is six, and five of
   them would be fetched **during** a wait, on 3G, which is the moment a customer is least
   able to wait for a network round trip.
2. **`§3` row 3 as amended** — a navigation re-attaches the fragment to the new URL
   (`§3` row 9's own clause list says so). Not navigating is the only way clause (a) stays
   true for the whole journey rather than only until the first link.
3. **`§4.1`** — *"zero chrome"*. There is no header, no nav and no back button to build,
   because there is nowhere to go.

**It is still six screens for every other purpose:** six specifications below, six entries in
the one-handed measurement, six `data-screen` values a Playwright locator reads, and six
`StateView` compositions. The reducer in `§4` is the only thing that moves between them.

### 3.1 Scan landing — `data-screen="landing"`

**Purpose.** Turn a camera scan into one decision. The customer has just sat down, is
holding the phone one-handed, and has not read anything. `§4.1`: *"Restaurant mark, order
reference, a single primary action, zero chrome. Static shell from CDN."*

**Content inventory.** Product mark (`SR-4`: the restaurant's mark when a field exists to
carry it) · order reference, large, `dir="ltr"` · one sentence of what happens next · **one**
primary action · nothing else. No timer — the wait has not started. No status badge — the
status *is* the screen.

**States — all four of `§4.1`'s.**

| state | reached by | primary action | polling |
| --- | --- | --- | --- |
| `valid` | **200** from `POST /v1/pager/session` with `status: waiting` | *Enable alerts* → `alerts` | not yet |
| `expired` | **409** `…/problems/expired` | *(none)* → renders `ended: expired` instead | no |
| `invalid` | **404** `…/problems/not-found`, **or** a fragment that fails the generated shape check — in which case **no request is made at all** | *(none)* | no |
| `already-ready` | **200** with `status: ready` | *Got it* → `ready: fresh`, acknowledged immediately | no — the wait is over |

⚠ **`already-ready` is the state that is easiest to get wrong and is the commonest in
practice**: the customer's order was marked ready while they were walking to the table, so
the first thing the scan returns is `ready`. It must not show *"we'll let you know"*. It goes
straight to the order-ready screen, and the only difference from arriving there by push is
that nothing is announced assertively — the customer is already looking at the phone.

`status: completed` or `cancelled` on the first resolve lands in `ended`, which is why the
landing screen has four states and the status enum has five values: two of them are not
landing states at all.

**Exact copy.**

| id | English | العربية |
| --- | --- | --- |
| `landing.valid.eyebrow` | Your order | طلبك |
| `landing.valid.lede` | We'll buzz your phone the moment it's ready. You can put it away. | سننبّه هاتفك في اللحظة التي يصبح فيها طلبك جاهزًا. يمكنك إبعاده الآن. |
| `landing.valid.cta` | Enable alerts | تشغيل التنبيهات |
| `landing.valid.skip` | Just show me the status | اكتفِ بعرض الحالة |
| `landing.invalid.title` | That isn't a valid pager link | هذا ليس رابط تنبيه صالحًا |
| `landing.invalid.body` | Scan the code on your receipt again, or ask the counter for a new one. | أعد مسح الرمز الموجود على فاتورتك، أو اطلب رمزًا جديدًا من الكاشير. |
| `landing.ref.label` | Order | رقم الطلب |

`landing.valid.skip` exists because `§4.1` asks for *a single primary action* and **enabling
alerts is not something a customer may be forced through**: a customer who declines must
still reach the waiting screen. It is rendered as a quiet secondary, below the fold of the
thumb zone, so there is still exactly one *primary* action.

### 3.2 Enable alerts — `data-screen="alerts"`

**Purpose.** Get notification permission, from a user gesture, having first told the customer
why in one sentence. `§4.1`: *"One-sentence value statement, permission button, 'why we ask',
**iOS Home-Screen install path as a first-class flow**"*.

**Content inventory.** One-sentence value statement · permission button (the one gesture
`§1.2` requires) · *"why we ask"*, collapsed by default and reachable by keyboard · the iOS
install path **as its own flow, not a hint** · a way on without granting.

**States — all four of `§4.1`'s, plus the install path which is orthogonal to them.**

| state | reached by | what is shown |
| --- | --- | --- |
| `not-asked` | `Notification.permission === 'default'` | value statement, *Allow notifications*, *why we ask*, *Not now* |
| `granted` | `'granted'`, and `reconcileSubscription()` has registered an endpoint | one line of confirmation, then straight to `waiting` |
| `denied` | `'denied'` | honest copy, **no retry** — the browser will not ask again and offering a button that cannot work is a lie (`StateView`'s `permission-denied` makes this structural) · what still works · *Continue* |
| `unsupported` | `typeof Notification === 'undefined'` **or** `!('serviceWorker' in navigator)` **or** `!('PushManager' in window)` | what the browser can't do, what still works, *Continue* |

**The iOS install path.** Shown when the engine reports iOS-family **and** the display mode
is not standalone — never from a user-agent string alone, because the display mode is the
thing that actually decides whether a push can arrive. It is a **first-class flow**: its own
heading, the three steps with the share-sheet affordance named, and the explicit statement
that notifications cannot work until it is done. `§1.1` and `§1.2` are the evidence that it
is a requirement and not advice.

⚠ **It also self-labels, which is QRPAGER-24's inherited pattern.** A run in a tab is
allowed — it is the only way to check the rest of the journey — and the screen says, in
copy the customer can read, that alerts will not arrive until the app is installed. The
harness put the display mode on every log line for the same reason; here it is on the screen.

**Exact copy.**

| id | English | العربية |
| --- | --- | --- |
| `alerts.lede` | One buzz when your order is ready. Nothing else, ever. | تنبيه واحد عندما يصبح طلبك جاهزًا. ولا شيء غير ذلك، أبدًا. |
| `alerts.cta` | Allow notifications | السماح بالتنبيهات |
| `alerts.why.summary` | Why we ask | لماذا نطلب ذلك |
| `alerts.why.body` | So you can put your phone away and still know. We send one notification for this order and we keep no account, no phone number and no name. | لكي تتمكّن من إبعاد هاتفك وتبقى على علم. نرسل تنبيهًا واحدًا لهذا الطلب، ولا نحفظ لك حسابًا ولا رقم هاتف ولا اسمًا. |
| `alerts.notNow` | Not now | ليس الآن |
| `alerts.granted` | Alerts are on. You can put your phone away. | تم تشغيل التنبيهات. يمكنك إبعاد هاتفك. |
| `alerts.denied.title` | Notifications are blocked | التنبيهات محجوبة |
| `alerts.denied.body` | Your browser won't ask again, so we can't turn them on from here. Keep this page open and the status below stays live. | لن يسألك متصفحك مرة أخرى، لذا لا يمكننا تشغيلها من هنا. أبقِ هذه الصفحة مفتوحة وستبقى الحالة أدناه محدَّثة. |
| `alerts.unsupported.title` | This browser can't send alerts | هذا المتصفح لا يدعم التنبيهات |
| `alerts.unsupported.body` | Keep this page open instead — the status below stays live while you wait. | أبقِ هذه الصفحة مفتوحة بدلًا من ذلك؛ ستبقى الحالة أدناه محدَّثة أثناء انتظارك. |
| `alerts.continue` | Continue | متابعة |
| `alerts.install.title` | Add this to your Home Screen first | أضِف هذه الصفحة إلى الشاشة الرئيسية أولًا |
| `alerts.install.body` | On iPhone and iPad, notifications only work from a Home Screen app. It takes three taps. | على iPhone و iPad، لا تعمل التنبيهات إلا من تطبيق على الشاشة الرئيسية. يستغرق ذلك ثلاث نقرات. |
| `alerts.install.step1` | Tap the Share button in the browser bar. | اضغط زر المشاركة في شريط المتصفح. |
| `alerts.install.step2` | Choose "Add to Home Screen". | اختر «أضِف إلى الشاشة الرئيسية». |
| `alerts.install.step3` | Open it from the new icon, then allow notifications. | افتحها من الأيقونة الجديدة، ثم اسمح بالتنبيهات. |
| `alerts.install.tabNotice` | You're in a browser tab, so alerts can't reach you yet. | أنت في علامة تبويب في المتصفح، لذا لا يمكن للتنبيهات أن تصلك بعد. |

### 3.3 Waiting — `data-screen="waiting"`

**Purpose.** Make a wait bearable and honest. `§4.1`: *"Live elapsed timer, status,
restaurant branding, keep-screen-awake toggle, quiet 'what happens next'."*

**Content inventory.** Order reference · elapsed timer (`@qrpager/ui`'s `ElapsedTimer`,
recomputed from `as_of`, `dir="ltr"`, `aria-live="off"`) · the status in words, never colour
alone · restaurant branding slot (`SR-4`) · keep-screen-awake toggle **with three outcomes**
· a quiet *what happens next* · nothing that competes with the alert.

**States — all three of `§4.1`'s.**

| state | reached by | what changes |
| --- | --- | --- |
| `healthy` | the last poll answered **200** | the timer runs; the status line is quiet; no banner |
| `degraded` | ≥1 consecutive **503**, or the backoff is above the base interval | a non-alarming banner naming *the live status*, not the order; the timer keeps running from the last `as_of` and is marked as of that time; **the alert channel is explicitly stated to be unaffected** |
| `offline` | a thrown `fetch`, or `navigator.onLine === false` | the offline banner and a manual *Try again*; the timer freezes at the last known `as_of` rather than inventing seconds |

⚠ **The degradation order is a `§4 › Edge` clause, and it is visible here rather than
implied.** *"Load shedding drops nice-to-haves (live timer, analytics) before alerts."* The
`503` the client gets **is** the server shedding the live timer, and `ShedLoad` is on the
polling route and on neither of the other two for exactly that reason. So the degraded
state's copy says the timer is behind **and that the buzz is not** — which is the only way a
customer can tell a shed timer from a broken product. Asserted: the degraded state's DOM
contains the alert-unaffected line, and the subscription call is **never** retried or
cancelled by the backoff.

**The keep-screen-awake toggle, and its three outcomes.** `§1.1` measured that
`navigator.wakeLock` is present and non-functional in standalone iOS web apps from 16.4 to
18.4 — the exact configuration the product needs. So the toggle is not rendered from a
detection:

| outcome | rendered |
| --- | --- |
| `request('screen')` resolves | toggle, on, with the "on" label |
| `request('screen')` rejects, or the API is absent | **the toggle is not rendered at all**, and one quiet line says the screen may dim — rather than a control that does nothing |
| the lock is released by the engine (tab hidden) | re-requested on `visibilitychange` to visible; a release we did not ask for never silently flips the label |

**Exact copy.**

| id | English | العربية |
| --- | --- | --- |
| `waiting.title` | We're on it | جارٍ تحضير طلبك |
| `waiting.status.waiting` | Being prepared | قيد التحضير |
| `waiting.elapsed.label` | Time since you scanned | الوقت المنقضي منذ المسح |
| `waiting.next.summary` | What happens next | ما سيحدث بعد ذلك |
| `waiting.next.body` | When the kitchen marks your order ready, your phone buzzes and this screen turns green. If you miss it, the screen still shows it. | عندما يضع المطبخ طلبك جاهزًا، سينبّه هاتفك وستتحول هذه الشاشة إلى اللون الأخضر. وإن لم تنتبه للتنبيه، ستظل الشاشة تعرضه. |
| `waiting.awake.label` | Keep the screen on | إبقاء الشاشة مضاءة |
| `waiting.awake.unavailable` | Your screen may dim while you wait. The alert still arrives. | قد تخفت إضاءة شاشتك أثناء الانتظار، لكن التنبيه سيصل على أي حال. |
| `waiting.degraded.title` | Live updates are running behind | تحديثات الحالة متأخرة |
| `waiting.degraded.body` | The counter below may be a little old. Your alert is not affected and will still arrive. | قد يكون العدّاد أدناه متأخرًا قليلًا. هذا لا يؤثر على تنبيهك، وسيصل كما هو مقرر. |
| `waiting.degraded.asOf` | Last confirmed {time} | آخر تأكيد {time} |
| `waiting.offline.title` | You're offline | أنت غير متصل بالإنترنت |
| `waiting.offline.body` | We can't check the status without a connection. Your order is still being prepared. | لا يمكننا التحقق من الحالة دون اتصال. لا يزال طلبك قيد التحضير. |
| `waiting.retry` | Try again | أعد المحاولة |

### 3.4 Order ready — `data-screen="ready"`

**Purpose.** Be unmistakable from across a room. `§4.1`: *"Full-bleed unmistakable state,
sound + vibration, order reference, AAA contrast, readable across a room."*

⚠ **This screen is OUTSIDE theming (D4) and this task adds nothing to it.** It is
`@qrpager/ui`'s `OrderReady`, whose three colours are `--ready-field`, `--ready-ink` and
`--ready-signal` — declared once, outside both theme blocks, in a namespace no tenant theme
token can produce. **There is no clamp and no contrast-adjustment code in this task**, and
the ratios are arithmetic over the token source rather than a browser measurement:
`--ready-ink` on `--ready-field` **15.55:1**, `--ready-signal` on `--ready-field` **9.73:1**,
`--ready-field` on `--ready-signal` **9.73:1** — all three AAA. The only tenant-supplied thing
is the logo slot (`SR-4`).

**Content inventory.** Full-bleed field · heading · the order reference at the largest step
in the scale, `dir="ltr"`, tabular numerals · one instruction · one acknowledge button ·
an **assertive** live region — the one place in the product that earns it, because the
customer may not be looking.

**States — both of `§4.1`'s.**

| state | reached by | difference |
| --- | --- | --- |
| `fresh` | a push, or a poll returning `ready`, or a resolve returning `ready` | sound + vibration attempted once; the acknowledge button is the primary; the live region announces |
| `acknowledged` | the customer taps acknowledge | the button becomes a quiet outline, nothing is re-announced, nothing re-sounds, and the screen **stays** — `§4.1` does not send the customer anywhere, because the order reference is still the thing they are holding up at the counter |

**Sound and vibration, and why they are attempted rather than required.** `§1.3` measured
`navigator.vibrate` **absent in firefox 157.0 and webkit 27.2**. So:

* the **notification** is the alert that works everywhere, and the OS owns its sound;
* in-page sound is a short `AudioContext` tone, played **only** from the gesture-adjacent
  path or after a prior gesture, because every engine blocks autoplay otherwise — a failure
  to play is caught and is not an error state;
* `navigator.vibrate?.(…)` is attempted once and its absence changes nothing;
* **under `prefers-reduced-motion: reduce`, vibration is not attempted at all.** Reduced
  motion means no motion, and a buzz is motion. The notification and the colour change carry
  the alert instead. This is a judgement and it is stated rather than buried.

**Exact copy.**

| id | English | العربية |
| --- | --- | --- |
| `ready.heading` | Your order is ready | طلبك جاهز |
| `ready.instruction` | Show this number at the counter. | أظهِر هذا الرقم عند الكاشير. |
| `ready.ack` | Got it | تم |
| `ready.acked` | On your way | في الطريق |
| `ready.announce` | Your order is ready. Order {reference}. | طلبك جاهز. رقم الطلب {reference}. |

### 3.5 Session ended — `data-screen="ended"`

**Purpose.** End honestly, and never leave a dead end. `§4.1`: *"Expired or completed, with
a path back to the restaurant page."*

**Content inventory.** What happened, in one line · the order reference, so the customer can
still refer to it · one way forward · **no timer, no poll, no retry** — all three are over.
Polling **stops**, the token is **cleared from both of its homes**, and the push subscription
is removed with `DELETE /v1/pager/subscription` on `completed` and `cancelled`.

**States — all three of `§4.1`'s.**

| state | reached by | tone |
| --- | --- | --- |
| `completed` | `status: completed` from a poll or a resolve | finished, pleasant, no action needed |
| `cancelled` | `status: cancelled` | neutral and non-accusatory — a cancellation is usually the restaurant's, and the copy must not imply the customer did something |
| `expired` | `status: expired`, or **409** `…/problems/expired` on any call | the two-hour TTL ran out; the way forward is a new code |

⚠ **`§4.1`'s *"path back to the restaurant page"* is `SR-4`.** No response carries a tenant
landing URL, and Task 11 owns that page. The way forward is therefore a re-scan instruction
and an explicit `homeHref` slot that renders a link **only** when one is supplied — never a
link to a guessed URL, which would be a dead end wearing the costume of a path.

**Exact copy.**

| id | English | العربية |
| --- | --- | --- |
| `ended.completed.title` | Enjoy your order | بالهناء والشفاء |
| `ended.completed.body` | This pager is finished. Thanks for waiting. | انتهى عمل هذا التنبيه. شكرًا لانتظارك. |
| `ended.cancelled.title` | This order was cancelled | تم إلغاء هذا الطلب |
| `ended.cancelled.body` | The restaurant cancelled it. Please ask at the counter if you weren't expecting this. | قام المطعم بإلغائه. يُرجى السؤال عند الكاشير إن لم تكن تتوقّع ذلك. |
| `ended.expired.title` | This code has expired | انتهت صلاحية هذا الرمز |
| `ended.expired.body` | Pager codes last two hours. Ask the counter for a new one. | تبقى رموز التنبيه صالحة لمدة ساعتين. اطلب رمزًا جديدًا من الكاشير. |
| `ended.rescan` | Scan a new code | امسح رمزًا جديدًا |
| `ended.home` | Back to {restaurant} | العودة إلى {restaurant} |

### 3.6 Offline / error — `data-screen="error"`

**Purpose.** `§4.1`: *"Honest message, retry, no dead end."* This is the screen for the
failures that are **not** a state of the journey: the first resolve could not be made at all,
or the server answered something the client cannot interpret.

**States.** `§4.1` lists none, which is not the same as one. There are **three**, and they
differ in what a retry can do — which is the only distinction that matters to a customer:

| state | reached by | retry? |
| --- | --- | --- |
| `offline` | the **first** call threw, or `navigator.onLine === false` before any answer | yes — and automatically, on `online` |
| `server` | **5xx**, or a 503 on the resolve path (not the poll path, which degrades instead) | yes, manual, with the same backoff |
| `unknown` | a 2xx whose body does not parse, or a status this build does not have (`PagerStatus` is a generated union, so this is reachable only across a deploy) | yes, manual — and the copy does not pretend to know what happened |

**No dead end, mechanically.** Every one of the three renders a `StateView` with
`onRetry` bound, and `apps/web/test/screens.test.tsx` asserts **for every state of every
screen** that the rendered output contains at least one enabled interactive element **or** an
instruction naming a physical action the customer can take (re-scan, ask the counter). The
only states that legitimately offer no button are `alerts: denied` and `alerts:
unsupported`, where a retry would be a lie, and both carry a *Continue*.

**Exact copy.**

| id | English | العربية |
| --- | --- | --- |
| `error.offline.title` | You're offline | أنت غير متصل بالإنترنت |
| `error.offline.body` | We'll try again as soon as you're back on a network. | سنحاول من جديد بمجرد عودة الاتصال. |
| `error.server.title` | We can't reach the kitchen right now | لا يمكننا الوصول إلى المطبخ في هذه اللحظة |
| `error.server.body` | This is on us, not on you. Your order is unaffected. | هذا خلل من جانبنا لا من جانبك، ولا يؤثر على طلبك. |
| `error.unknown.title` | Something went wrong | حدث خطأ ما |
| `error.unknown.body` | We're not sure what. Try again, and ask at the counter if it keeps happening. | لسنا متأكدين من السبب. أعد المحاولة، واسأل عند الكاشير إن تكرّر ذلك. |
| `error.retry` | Try again | أعد المحاولة |

### 3.7 The accessibility bar, per screen — `§12`, measured not declared

Every clause below is asserted in a **real engine** across all six screens and all of their
states, in **both** directions. The figures and the method are `§5`.

| clause | where it is measured |
| --- | --- |
| keyboard reachable, visible focus ring | Playwright: tab through each state, assert the active element at each stop and a **non-zero computed outline width** on it |
| WCAG **AA** everywhere, **AAA on order-ready** | AA: resolved pairs in a real engine against `generated/contrast.json`. AAA: arithmetic over the token source (D4), already `packages/tokens`' assertion, re-asserted against the **rendered** pair here |
| 44×44 targets, ≥8 px separation | bounding boxes, **without** `R-174`'s WCAG 2.5.8 exception, per that row's instruction |
| `prefers-reduced-motion` | the `chromium-reduced-motion` project plus the same projects for firefox and webkit: **every** animation and transition duration resolves to `0s`, and vibration is not attempted |
| RTL-safe | every state rendered under `lang="ar" dir="rtl"`; no horizontal scroll; the timer and the order reference stay `ltr`; no physical `left`/`right` in this task's CSS (scanned) |

---

## §4. THE JOURNEY AS ONE REDUCER — so "every branch has a test" is countable

`§11` role 2 check 2: *"Every branch of any state machine has a test."* A journey spread
across six components has no state machine to enumerate, so the branches live in one pure
function, `apps/web/src/pager/journey.ts`:

```
reduce(state: JourneyState, event: JourneyEvent): JourneyState
```

**17 screen-states**, from `§3`: `landing`×2 (valid, invalid — the other two of `§4.1`'s four scan
outcomes REDIRECT to `ended.expired` and `ready.fresh` rather than being states of their own, which is
what `SCAN_OUTCOMES` maps; `§11` QA L-4 corrected the ×3) ·
`alerts`×4 · `waiting`×3 · `ready`×2 · `ended`×3 · `error`×3. `JOURNEY_STATES` exports all of
them and `journey.test.ts` iterates that export, so a state added without a case is a
failing test rather than an untested branch.

**The events, and every one of them is something that actually happens:**

| event | source |
| --- | --- |
| `token/present`, `token/malformed` | `readPresentedToken()` over `location.hash` |
| `resolve/ok`, `resolve/expired`, `resolve/unknown`, `resolve/unreachable`, `resolve/server`, `resolve/unreadable` | `POST /v1/pager/session`. `unreadable` is a 200 whose body this build cannot interpret — added during the build, because `error.unknown` was otherwise an unreachable state with copy written for it |
| `poll/ok`, `poll/stale`, `poll/unavailable`, `poll/unreachable` | `POST /v1/pager/status` — the four answers Task 4 defines, `stale` and `unavailable` deliberately distinct (`§3` row 9 clause (c)) |
| `permission/probed`, `permission/granted`, `permission/denied`, `permission/unsupported` | `Notification.requestPermission()`, and the capability probe. `probed` carries what the browser already thinks before anything is asked; `unsupported` is also dispatched when `serviceWorker.register()` REJECTS, which `§11` round one found uncaught |
| `push/ready` | the service worker's message to the page |
| `alerts/open` | the scan landing's primary action — the only path to the alerts screen |
| `ack` | the customer |
| `net/online`, `net/offline` | `window` events |
| `skip` | `landing.valid.skip`, `alerts.notNow`, `alerts.continue` |
| `retry` | the retry control on the error and degraded screens |

**⚠ TWENTY-THREE EVENTS, AND THIS TABLE SAID NINETEEN** — `§11` round one, QA L-4. The export is
the authority: `journey.ts` declares 23 and `journey.test.ts` asserts `ALL_EVENTS.length === 23`
so the 17 × 23 matrix cannot go stale. The four this table had dropped are `resolve/unreadable`,
`permission/probed`, `retry` and `alerts/open` — the last of which is the ONLY path to the alerts
screen, so a table missing it described a journey with an unreachable screen. All four are rows
now, and the reconciliation was done by parsing the declared union rather than by recounting by
hand: the first correction to this table still left `alerts/open` out.
`§4` exists so *"every branch has a test"* is countable FROM THE TICKET, which a table four
events short is not.

**Three properties asserted over the whole table rather than case by case**, because a
per-case assertion cannot see a hole:

1. **Terminality.** From any `ended` state, every event returns the identical state. A
   completed session cannot be re-opened by a late poll — which is reachable, because a poll
   in flight when `completed` arrives answers afterwards.
2. **No silent self-transition on an unknown event.** The reducer is exhaustive over the
   generated `PagerStatus` union *and* over its own event union, with no `default` arm: a new
   status is a **type error**, which is the whole point of consuming the generated contract
   rather than a hand-written list.
3. **Monotone status.** The journey never moves backwards through
   `PAGER_STATUS_TRANSITIONS` — also generated. A poll that answers `waiting` after `ready`
   (stale read from a replica, or a projection written out of order) is **ignored**, not
   rendered, and `journey.test.ts` drives exactly that sequence. Without this the order-ready
   screen can flip back to waiting while the customer is walking to the counter.

---

## §5. HOW THE GATE'S TWO MEASURED CLAUSES ARE MEASURED

The method is `§3.1` row 9 and ticket `§4.1`, written 2026-11-01 before this task started.
Nothing below restates it; this is only where each part is implemented and what the figure
came out as.

| part of the method | implemented in |
| --- | --- |
| Lighthouse CLI, pinned exact, over the Chrome build Playwright pins, both versions printed | `tools/gate/bin/lighthouse-proof` |
| the page: `/s` at a real issued token, `next build` + `next start`, never the dev server | same; the token is issued through the **real** API |
| cold: fresh profile, empty cache, **no service worker**, no prior `localStorage` | same — a fresh `--user-data-dir` per run, and the run asserts `getRegistrations()` is empty before it measures |
| throttling as figures: RTT 300 ms, 700/700 Kbps, CPU ×4, mobile, 390×844 DPR 3 | same, as explicit flags, never a preset name |
| five runs, **median**, all five reported, a single run under 95 reports and does not fail | same |
| the three **load-invariant** assertions that carry the weight | `apps/web/test/build-surface.test.ts` — critical-path transferred bytes under a stated budget, render-blocking request count, **zero third-party origins in the built output** |
| one-handed: four properties, 390×844 DPR 3, every screen, every state, **LTR and RTL** | `browser/one-handed.spec.ts` |
| (c) measured **without** `R-174`'s WCAG 2.5.8 exception | same — that row's own instruction |

**The one-handed measurement is a generated table, not a typed one.** `§6`'s discipline
(*every table is generated or gate-checked*) applies to the thing this task is most likely to
get wrong: the subject list. `browser/one-handed.spec.ts` derives its subjects from
`JOURNEY_STATES` — the same export the reducer's test iterates — so a screen state that
exists and is not measured is impossible rather than merely unlikely. The emitted table is
`docs/tickets/QRPAGER-16-one-handed-{chromium,firefox,webkit}-{ltr,rtl}.md` — SIX files, one per
project — and the spec fails if any committed copy disagrees. (`§11` round one, QA L-4: this named a
single `QRPAGER-16-one-handed.md` that was never written, which matters because `§5` exists so a
reviewer can count branches from the ticket.)

---

## §6. WHAT THIS TASK DELIVERS, FILE BY FILE

**`apps/web/src/` — the customer journey.** One route, six screens, seventeen states.

| file | what it is |
| --- | --- |
| `copy/en.ts`, `copy/ar.ts`, `copy/index.ts` | the exact copy from `§3`, in both launch languages. `ar.ts` is `Record<CopyKey, string>`, so a missing translation does not compile |
| `pager/token.ts` | `§3` row 9 clause (a): read `location.hash` once, strip with `replaceState`, validate against the GENERATED shape |
| `pager/api.ts` | the four customer calls, token in the body, the four poll answers kept distinct |
| `pager/poll.ts` | the jittered interval and the exponential backoff, as arithmetic |
| `pager/journey.ts` | the whole journey as one pure reducer, exhaustive over the generated status union |
| `pager/notify.ts` | permission, subscription, and `reconcileSubscription()` — `SR-5`'s engine-independent half |
| `pager/install.ts` | the iOS Home-Screen path, gated on the display mode and on `maxTouchPoints` |
| `pager/wake.ts` | the keep-screen-awake toggle, driven by a real `request()` and not by a detection |
| `pager/alert.ts` | sound and vibration, both attempted, vibration suppressed under reduced motion |
| `pager/storage.ts` | the token between launches, deleted on every terminal status and on read past expiry |
| `pager/locale.ts` | the launch language, matched on the primary subtag |
| `screens/*.tsx` | the six screens, seven files, no literal copy in any of them |
| `PagerJourney.tsx` | the orchestrator: the I/O the reducer cannot do, and no decisions |
| `styles/pager.css` | layout only; every value a token reference; `margin-block-start: auto` is the thumb-zone clause |

**`apps/web/app/s/`** — the route. Statically prerendered, token in the fragment, its own manifest
and its own iOS install meta (both `apple-` and standard names, measured on the built page).

**`apps/web/public/`** — `sw.js` **extended** (offline shell, the `R-075` tag, the message to the
page, the hardened rotation handler), `pager.webmanifest`, and `pager-icon.svg` **generated from
the token source**.

**`apps/api/`** — two places, neither of them data:

* `PagerToken::ALPHABET` extracted from the regex that was already there, and `pattern()` built
  from both constants, so PHP still has one source;
* `StatusMirror::pagerToken()` emits `PAGER_TOKEN_LENGTH`, `PAGER_TOKEN_ALPHABET` and
  `isWellFormedPagerToken` into `packages/contracts`, stale-checked by `composer contracts:check`;
* `bin/issue-pager-session` and `bin/issue-harness-user` — E2E fixtures, not seeders;
* four cases in `PanelProblemPageTest` that close **`R-195`**.

**`tools/browsers/`** — the toolchain, committed. A pinned `Dockerfile` (the Playwright version
read from the lockfile, never typed), a runner, a TLS proxy, and the engine probe. This host
cannot launch a browser (`playwright install-deps` needs sudo it does not have), so the engines
run in that image and **every figure in the report names it**.

**`browser/`** — five spec files: the kit's two (8a and 8b's, untouched), plus `engines.spec.ts`
(`R-194`), `pager.spec.ts` (the journey, three engines), `sw.spec.ts` (the worker), 
`one-handed.spec.ts` (`§3.1` row 9 (2)) and `passkey.spec.ts` (**`R-100`**).

---

## §7. THE `§5` LEDGER — what this task closes, narrows, carries and adds

> Written here so the report and `PLAN §5` cannot disagree. Every row below is one this task
> was handed or produced; nothing is claimed closed that is not asserted somewhere.

### Closed

| row | what closes it |
| --- | --- |
| **`R-075`** | the notification `tag` is derived from the payload (`event` + `order_reference`), so two identical pushes collapse and a renotify does not. Driven on the shipped `sw.js` in all three engines |
| **`R-195`** | four cases in `PanelProblemPageTest`: `Sec-Fetch-Mode` alone, `Sec-Fetch-Dest` alone, `! acceptsHtml()`, and a 4xx page carrying no `Retry-After` |
| **`R-100`** | a passkey registered against a CDP virtual authenticator and then used to sign in, through the real harness page over https — 201 then 200, with a control case that refuses when no credential exists |

### Narrowed by measurement, not closed

| row | the measurement |
| --- | --- |
| **`R-194`** | chromium 156.0.8078.4, firefox 157.0 and webkit 27.2 **all** send `Sec-Fetch-Dest: document` on a genuine navigation, asserted in `browser/engines.spec.ts`. So the narrowing the row describes is real only for engines none of these is. **The sentence still needs qualifying, and that is a wording question** — the row's own instruction |
| **`R-171`** | not met and not closable here: both apps are statically prerendered, so the headers belong at the edge (Task 13) and the policy's content to Task 12. What this task adds is `docs/contracts/web-csp-surface.json`, **derived from the built output and byte-compared**, so the policy is written from a measurement — including the finding that the page carries **6 inline scripts**, so `script-src 'self'` alone would break it and a nonce is unavailable on a static document |
| **`R-174`** | inherited rather than re-decided, as that row instructs. Clause (c) is measured **without** the WCAG 2.5.8 exception on all 17 states in both directions, and this task's own screens render no text-inside-a-sentence control, so none needed it |

### Carried, with the deadline named rather than quietly passed

| row | state |
| --- | --- |
| **`R-073`** | **OPEN, and it is what blocks `task-9`.** The device result is `QRPAGER-24`'s and has not happened. Every half this project owns is asserted; what is untested is whether a push arrives on a real iPhone installed to the Home Screen |
| **`R-091` (a)** | **the first branch is taken and recorded.** The row offered *"either the cap stays a silent server-side bound and Task 9 says nothing, or it becomes a product rule and the PWA has copy for it."* Task 9 says nothing: 5 devices is above any plausible session, a capped customer still sees the status by polling, and there is no action a customer could take on being told. The figure remains Task 14's |
| **`R-093`** | ⚠ **ITS DEADLINE WAS *"before Task 9 builds the client"* AND THE CLIENT IS BUILT.** Neither branch was taken: the rank-2 sentence is Muhammad's and `§0` forbids a session editing it, and a `SourceGuard` key-inventory rule is an `apps/api` control this task has no other reason to touch. **Reported rather than silently carried.** Nothing in this task adds a Redis key or reads one |

### Added

Four rows, each with an owner and a date. They are `SR-4`, `SR-5`, and two smaller things this
task measured.

---

## §8. DECLARED NON-DELIVERIES AND DELIBERATE CHOICES A REVIEWER MAY DISAGREE WITH

Stated here so none of them has to be discovered. Each is a decision rather than an oversight,
and each names what would change it.

| choice | why, and what would change it |
| --- | --- |
| **The PWA renders the approved LIGHT theme only.** `ThemeProvider` is given no `theme`, so `data-theme="light"` is always set and the attribute always decides — `prefers-color-scheme` never reaches it. | The approved direction makes the order-ready screen *"the only dark surface in the product"*, and that screen is outside theming entirely (D4), so a dark customer journey is not something the approval covers. A customer with dark mode on their phone therefore gets a bright page at night. Offering them the dark theme is a product decision nobody has taken; the mechanism already exists (`AVAILABLE_THEMES`), so it is one prop when somebody does. |
| **No language switcher.** The locale comes from `navigator.languages`, matched on the primary subtag. | `§4.1` asks the scan landing for *"zero chrome"*, and a switcher is a control on every screen that advances nothing. A customer scanning a code in a restaurant has their phone's language set already. |
| **The prerendered shell carries no copy at all** — a spinner, and the locale resolves at hydration. | The document is static, so it is built with no `navigator`. Rendering the English shell and switching would be a hydration mismatch for every Arabic customer AND a visible flash of the wrong language and direction. It costs nothing because the first paint has no order data either way. |
| **The `<details>` disclosure keeps the engine's own marker triangle.** | Everything else about it is ours — colour, size, the 44px block floor, the focus ring. `§12`'s *"no library default look"* is about naming a FRAMEWORK from a screenshot, and a disclosure triangle names none. The alternative is re-implementing a native, keyboard-reachable, screen-reader-announced control in JavaScript. |
| **One rewritten response in one browser case.** `pager.spec.ts`'s poll-driven transition rewrites the FIRST resolve's `status` to `waiting` and nothing else; every later request, including every poll, reaches the real server. | There is no customer route that can mark a live session ready — `markReady` is staff-authenticated — and adding a control route to `apps/api` is out of scope (`§2`). The choice was one rewritten field or no poll-driven transition at all, and it is named in the test rather than hidden in a helper. |
| **The one-handed measurement controls the API answers.** | It is a GEOMETRY measurement over 17 states × 3 engines × 2 directions; reaching each state deterministically matters more than the transport, and the transport is what `pager.spec.ts` crosses for real. |
| **The offline-shell cases run in Chromium only.** | A real service-worker registration needs a trusted certificate, and a worker script is fetched by the browser's own network stack — so `ignoreHTTPSErrors` does not reach it and only Chromium has a flag. Named in the spec with what is therefore not proven. |
| **`R-100` is closed in Chromium only.** | `WebAuthn.addVirtualAuthenticator` is a CDP command; no runner exposes an equivalent for Firefox or WebKit, so there is no way to present a valid attestation to them. |
