# 10a — the `§6` pre-coding gate, the `§12` screen specs, and TWO STOP-AND-REPORTS

**Posted before the first implementation commit.** Branch `task-10a-staff-shell`, cut at
`09479e9` (`PLAN §0.0c` (b) states why that commit and not `369fed6`; the brief's branch-point
sha is `443fb951fa92b52ea936353c278e5dc6f3e11454`, verified an ancestor).

---

# ⛔ STOP-AND-REPORT 1 — "shift settings" IS NOT SPECIFIED ANYWHERE, AND IT IS A PRODUCT QUESTION

**This is the brief's fourth stop condition — *any product question: what a screen should DO,
not how*.** Raised before any code, not after a review.

**Measured, not assumed.** The string `shift` appears in the entire repository in exactly two
places, and both are inventory lines rather than specifications:

```
$ grep -rin "shift" docs/*.md docs/adr/*.md | grep -vi 'shifted\|shifting'
docs/PLAN.md:1537  §3 row 10  "... history, shift view."
docs/PLAN.md:2391  §4.2       "... Shift/device settings ..."
```

`docs/BLUEPRINT.md`: **zero hits.** No table, no column, no enum, no endpoint, no `§2` data
contract entry, no gate clause anywhere defines what a shift **is** or what it **does**.

**Why this is a stop and not a decide-and-record.** `PROJECT_INSTRUCTIONS.md §0` on the
Blueprint: *"If it is silent on something, that is a gap to **ask the client about**, never to
fill in."* `§8`: *"If something is unspecified, ask one concise question. Do not write
speculative code."* Every reading I can construct — a shift is a named working period that
scopes the board; a shift is a handover record; a shift is just "this device's current
settings" — **changes what the screen does**, and two of the three need a new tenant table,
which `§6` Q1 makes a `PLAN §2` change *first*.

**The one concise question.** *What is a shift in this product — is it (a) a persisted
per-tenant entity that scopes what the board and history show and needs a table, (b) a
handover record of who was on duty, auditable, or (c) nothing persisted, i.e. "the settings
that apply to this screen right now", in which case the word should leave `§3` row 10 and
`§4.2`?*

**What I am doing about it tonight, and why it is not a halt.** The **device** half of
"shift and device settings" is fully specified — `devices.store` and `devices.destroy` exist,
`DeviceTokens::forTenant()` exists, and `§3` row 5 ships on device-token scope — so it is
built. The **shift** half is **not built, not stubbed, and not guessed**: no route, no
component, no column, nothing half-made to mistake for a decision. It is a declared
non-delivery of 10a, it goes into the `task-10a` tag message and into `§4a`'s *what this does
NOT deliver*, and it carries a `§5` row.

**The precedent for continuing rather than halting is this project's own**, and it is the
Task 9 record in `§0`: *"FIVE STOP-AND-REPORTS, all raised before the first implementation
commit"* — and Task 9 was built, gated, reviewed and tagged with all five outstanding. `§0`'s
*stop and report* is **never silently resolve**; it is not **stop working**. A shift screen
nobody has defined is also not something 10b, 10c or 10d build on, so nothing downstream
inherits the unknown. If Muhammad reads this differently, the correction costs one screen.

---

# ⛔ STOP-AND-REPORT 2 — `R-115`'s "staff-management screens" IS OWNED BY TASK 10 AND IS IN NONE OF THE FOUR SCOPES

`R-115` (Medium, accepted 2026-10-28 from Task 6's `§11` reviews) names **three** items,
owner **Task 10**, acceptance *"at Task 10's acceptance gate. Its gate must name all three"*:

| `R-115` item | Where it lands in the split |
| --- | --- |
| a scannable QR in one wizard pass | **10d** — issuing a pager sheet |
| QR batch generation + printable sheets | **10d** — same screen |
| **staff-management screens** | **NOWHERE.** |
| the reprint question | **RULED** 2026-11-02, 10d records it |

The four scopes in the brief are explicit and each ends *"Nothing else"*: 10a is login, shell,
session lock, shift/device settings and the UI primitives; 10b the board; 10c the KDS; 10d
history and sheets. **Member management is in none of them**, and `§4.2`'s own inventory does
not list it either — so `R-115`'s third item is not an omission in the split, it is an item
`§4.2` never carried.

**Not decided here, because `R-115`'s acceptance clause is a gate clause and re-owning it is a
scope decision.** The API is built and tested (`members.index/store/update/destroy`,
`MemberDirectory`, `MembersTest`, `MemberConcurrencyTest`) — this is an unbuilt UI over proven
behaviour. **The question:** does member management join 10a's scope (it is the same
*authentication and membership* concern), become a fifth ticket, or get re-owned past Task 10
with a new date? **I have added nothing for it.**

---

# The seven `§6` pre-coding answers

## 1. Tables, columns, roles, GUCs

**No new table, no new column, no new GUC, no migration.** 10a is a client surface over
endpoints that exist, plus two small server additions that read existing columns:

| Touch | Table / column | Role | How the tenant reaches it |
| --- | --- | --- | --- |
| `GET /v1/tenant` (**new**) | `tenants.name`, `.slug`, `.timezone`, `.status` | `app` | `withTenant()` → `set_config('app.tenant_id', ?, true)`; RLS does the rest |
| `GET /v1/devices` (**new**) | `personal_access_tokens.id/name/expires_at/last_used_at/abilities` | `app` | **not** RLS — the table is global and RLS-free (`§2.4`); the tenant pin in `abilities` is the filter, and `DeviceTokens::forTenant()` already applies it |
| `GET /v1/auth/me` | unchanged | `app` | unchanged |
| the four passkey routes | `passkeys`, `users` | `app` | unchanged |

**GUCs: `app.tenant_id` only, transaction-scoped, via `withTenant()`.** No `app.bypass_tenant`
anywhere in this diff. **No change to `public.user_memberships()`** — see the §1 note below on
why the restaurant's name comes from a new RLS-scoped route rather than from widening the
named cross-tenant path.

**Why `GET /v1/tenant` exists at all, rather than adding `tenant_name` to `/v1/auth/me`.**
`§12`'s zero-technical-background bar forbids an unexplained identifier on a screen, and
`/v1/auth/me` carries `slug` and no `name` — a slug is a DNS label (`e2e-0199…`), which is an
identifier and not a restaurant. The cheap-looking fix is to add `tenant_name` to
`Memberships::for()`, and that is the wrong one: its SQL is
`select … from public.user_memberships(?)`, which **is** `§4 › Tenancy`'s named cross-tenant
read path *"a user's own memberships"*. Widening a cross-tenant function to carry display text
is a migration, a `§2.8` change and a grant change, for data the caller can read **inside their
own tenant's scope with ordinary RLS**. So the name comes from a staff route that reads
`tenants` inside `withTenant()` — no cross-tenant read added, `§2.8`'s list unchanged at four,
no migration.

## 2. Shared contracts

**Consumed, never redefined.** `@qrpager/contracts` is the generated TS mirror of the PHP
`PagerStatus` enum and `CLAUDE.md` forbids hand-editing it; 10a adds **no** status and no
enum. The two new routes return shapes built from existing value objects.

**One new PHP enum case: `Ability::TenantRead = 'tenant:read'`**, and it is a single source
read by both the policy layer and the test — `RoleMatrix`'s one array, exactly as `§3` row 5
requires. It is held by `owner`, `manager`, `staff` **and `device`** (a KDS has to be able to
say which restaurant it is showing). `devices.index` maps to the **existing**
`Ability::DevicesWrite` rather than a new `devices:read`: listing credentials is itself a
credential-management act, the holder set would be identical, and a second ability with the
same holders is a distinction with no difference. Both decisions are in the code's docblock
rather than only here.

## 3. The exact path by which tenant context reaches every query

Unchanged from Task 5, and this ticket adds no new mechanism — it adds two routes **inside**
the existing one:

```
request → [global] ArmTenantScopeGuard
        → api group: ShedLoad → RateLimit
                   → EncryptCookies → AddQueuedCookies → StartSession → ValidateCsrfToken
                   → ResolvePrincipal      (who is asking; refuses nothing)
                   → RequireTenantScope    (deny-by-default; 401, bodiless, no detail)
                        └─ publishes RequireTenantScope::BINDING = the tenant id
        → routes/v1.php group: RequireAbility  (ability by ROUTE NAME from RouteAbilities;
                                                an unmapped route in this group is 403)
        → controller: app(RequireTenantScope::BINDING) → withTenant($id, fn () => …)
                        └─ select set_config('app.tenant_id', ?, true)   [C1, C2]
```

**No HTTP input chooses a tenant.** Not a header, not a path parameter, not a body field, not
a query string. The tenant is the container binding `RequireTenantScope` published from the
caller's own verified membership. **In the UI it is enforced nowhere** — the shell displays
the active tenant and never sends it.

**The one place that is NOT RLS, said out loud:** `GET /v1/devices` reads
`personal_access_tokens`, which `§2.4` makes global and RLS-free. Its tenant filter is the
`tenant:<uuid>` pin inside `abilities`, compared against the **resolved** tenant — never
against input. `DeviceTokens::forTenant()` is the existing implementation and
`AuthUnitGapsTest` already drives its negative case (another tenant's id → `[]`). The new
controller adds no query of its own.

## 4. Security — and the kitchen screen

* **authn** — passkey-first, through Task 5's four `auth.passkeys.*` routes. No stub exists and
  none is added; `StubRemovedTest`'s three assertions stay green.
* **authz** — `RequireAbility` by route name, two layers (runtime fail-closed in the group,
  build-time totality in `RoleMatrixTest`).
* **input validation** — the two new routes take **no input at all**: no body, no query, no
  path parameter. That is the strongest available answer and it is why they are GETs.
* **rate limit** — both new routes are on the `api` group's bucket. `GET /v1/tenant` is called
  once per shell load and `GET /v1/devices` once per settings visit; neither is a polling
  carrier, so neither gets its own tighter bucket. `RateLimitTest` drives the group bucket to a
  real 429 already.
* **log redaction / secrets** — neither new route touches a token, a hash or a credential.
  `GET /v1/devices` returns `id`, `name`, `expires_at`, `last_used_at` and the capability —
  **never the token plaintext**, which `DeviceTokens::issue()` returns once and nothing can
  read back.
* **CSRF, verified by running code rather than from memory** — `laravel/framework v13.38.0`,
  `Illuminate\Foundation\Http\Middleware\PreventRequestForgery`:
  `getTokenFromRequest()` reads `_token`, then `X-CSRF-TOKEN`, then **decrypts**
  `X-XSRF-TOKEN` (line 183-196); `newCookie()` sets `XSRF-TOKEN` with
  **`httpOnly: false`** (line 239-253, seventh argument) so a first-party script can read it;
  `EncryptCookies::$except` is `[]`, so the cookie is encrypted and the header is the
  encrypted value — which is why the middleware decrypts it. ⚠ **And `handle()` passes on
  `Sec-Fetch-Site: same-origin` ALONE** (`hasValidOrigin()`, line 143-160), with
  `static::$allowSameSite = false` and `$originOnly` unset in this application. So a
  same-origin fetch satisfies CSRF with no token. **The client sends the token anyway**: the
  origin check is a property of the browser's own header and the token is the mechanism, and
  relying on the one the server would accept by accident is the shape `§8` calls assuming.
* **THE KITCHEN SCREEN — session lifetime and lock.** The answer is in §12's spec below. In
  one line: **the lock DESTROYS the server session** rather than hiding the data over a live
  one, because a hidden-only lock leaves a valid cookie in a browser a passer-by can open
  devtools on — and then *"a locked shell reveals no tenant datum"* is a claim about CSS.

## 5. Scale — what is bounded

| Call | When | Cost | Bound |
| --- | --- | --- | --- |
| `GET /v1/auth/me` | shell load, unlock | 1 membership function call | memberships per user |
| `GET /v1/tenant` | shell load | 1 indexed single-row read in a tenant scope | 1 row, by construction |
| `GET /v1/devices` | settings visit only | 1 read of a global table filtered in PHP | **bounded in SQL by a `limit`**, and the ceiling is stated in the code rather than left to the number of tokens a tenant has minted |
| `auth.passkeys.*` | sign-in, unlock | the ceremony, on `auth-passkey`'s own bucket | the bucket |

**Nothing in 10a polls.** The board's polling cost is 10b's question and this ticket adds no
timer that talks to the server. The idle-lock timer is a client-side `setTimeout` reset by
input events and costs no request until it fires, at which point it costs exactly one
`POST /v1/auth/logout`.

## 6. Failure — timeout, retry, duplicate, crash mid-way

* **timeout / unreachable** — every call goes through one client module whose result type
  separates *unreachable* from *server error* from *unauthenticated*, as Task 9's
  `src/pager/api.ts` already does for the customer. The shell renders `offline` for the first
  and `error` for the second; the copy differs because the honest message differs.
* **retry** — the sign-in ceremony is **not** retried automatically: a WebAuthn assertion is a
  user gesture, and silently re-running one is a prompt the person did not ask for. The shell's
  data reads retry on the person's own action, through the primitive's required retry affordance.
* **duplicate** — `POST /v1/auth/logout` is idempotent by construction and already answers the
  same way whether a session existed (`routes/auth.php` makes that an anti-oracle property). A
  double-tap on sign-out therefore cannot produce a wrong answer. Issuing a device token is
  **not** idempotent and must not be made to look like it is: ~~the screen's confirm step is what
  stands between a double-tap and two credentials~~, and the list shows what exists.
  > ⚠ **CORRECTED 2026-11-02 — `§11` SECURITY L-2 / QA M-8, AND THE STRUCK CLAUSE WAS FALSE WHEN
  > IT WAS WRITTEN.** There is no confirm step on the issue path. Removal is confirmed
  > (`StaffDevices.tsx` renders *"Remove this screen?"* in place of the button); **issuing is a
  > single submit**, `<Button type="submit" data-issue-device="true">` carried no `loading` and
  > `onIssue` was fire-and-forget, so two taps on a kitchen tablet minted two 30-day
  > `pager:read` credentials and `setIssued()` overwrote the first plaintext — an unintended
  > live row the person was never told about. Nothing leaked (the discarded plaintext reaches
  > nothing and `DeviceTokens::issue()` cannot read it back) and the row is visible and
  > revocable, which is why the reviewer graded it Low.
  >
  > **This answer described a control that did not exist, which is worse than describing none:**
  > it is the reasoning that stopped the guard being written. The control now is an in-flight
  > flag on the form — the submit is `disabled` with `aria-busy` while a request is outstanding
  > and the handler refuses a second call — and the test is the reviewer's own criterion: two
  > submits, exactly one `POST /v1/devices`.
* **crash mid-way** — a passkey ceremony abandoned between *options* and *credential* leaves a
  single-use server-side challenge that the next ceremony replaces. Nothing is committed by a
  half-finished sign-in, because the session is written only by `auth.passkeys.login`.

## 7. Version-specific research, verified by running code in-session

```
$ composer show laravel/framework | head -4      → v13.38.0
$ grep -n 'getTokenFromRequest' -A 14 vendor/laravel/framework/src/Illuminate/Foundation/
  Http/Middleware/PreventRequestForgery.php      → _token | X-CSRF-TOKEN | decrypt(X-XSRF-TOKEN)
$ ... newCookie()                                 → Cookie('XSRF-TOKEN', session token, …, false, false, …)
$ ... hasValidOrigin()                            → Sec-Fetch-Site: same-origin ⇒ true
$ grep -n 'protected $except' .../EncryptCookies.php → []
```

Node 22 / pnpm 12.16.1 / Next 15.5.28 / React 19.3.1 / Playwright 1.64.1, all already pinned
by `package.json` and unchanged by this ticket — **no dependency is added in either half.**

**Also verified by reading the harness rather than assuming it**, because 10a's gate needs a
real browser on a secure origin at the same origin as `/v1`: `tools/browsers/bin/e2e` already
builds and starts `apps/web` with `QRPAGER_HARNESS_API_ORIGIN="${API_ORIGIN}"` and
`NEXT_PUBLIC_QRPAGER_API_BASE=""`, serves it over TLS at `QRPAGER_E2E_BASE`, and checks the
`/v1/*` rewrite is live before running anything. So the staff shell is same-origin with the API
and in a secure context with **no change to the harness's topology** — only a staff fixture user
with a membership, which `bin/issue-harness-user` does not currently create (it makes a user with
**no** membership, which is correct for `passkey.spec.ts` and insufficient here).

---

# `§12` — the screens, specified before they are coded

**`§12` applies in full. No exemption.** These are staff surfaces and the tokens exist as of
Task 8. Copy is given in **English and Arabic**, because Arabic is a launch language and `§12`
requires RTL-safe layout — the copy is part of the spec, not a later pass.

## S1 · Staff sign-in — `/staff/sign-in`

**Purpose.** Let a member of this restaurant's staff prove who they are, with a passkey, on a
device that may be shared. Nothing else is on this screen.

**Why passkey-first and not password-first.** `§4.2` says *"Login (passkey-first)"*. The
password form is present but secondary and below the passkey button, because a shared kitchen
device is the worst place to type a password and the best place to tap a key.

| State | What is shown |
| --- | --- |
| `idle` | the passkey button, primary; the password form, secondary, collapsed behind a link |
| `authenticating` | the button becomes busy; nothing else moves (`prefers-reduced-motion` safe by having no motion at all) |
| `failed` | one message, the same for every cause — no oracle about whether an address has an account |
| `offline` | the honest message and a retry; the button is disabled because a ceremony cannot complete |
| `needs-2fa` | the code field, when the account has two-factor armed |
| `choose-restaurant` | only when the person holds more than one membership |

**Exact copy.** EN: heading *"Sign in"* · *"Use your passkey"* · *"Use a password instead"* ·
*"Signing you in…"* · failure *"That did not work. Try your passkey again, or use your
password."* · offline *"No connection. Check the network and try again."* · 2FA *"Enter the
6-digit code from your authenticator app."* · choose *"Which restaurant?"*
AR: *"تسجيل الدخول"* · *"استخدم مفتاح المرور"* · *"استخدم كلمة المرور"* · *"جارٍ تسجيل
الدخول…"* · *"لم ينجح ذلك. جرّب مفتاح المرور مرة أخرى، أو استخدم كلمة المرور."* ·
*"لا يوجد اتصال. تحقّق من الشبكة وحاول مرة أخرى."* ·
*"أدخل الرمز المكوّن من ٦ أرقام من تطبيق المصادقة."* · *"أي مطعم؟"*

**Content inventory.** The product mark; the restaurant's name is **not** here and cannot be —
no tenant is resolved before a credential is. No version string, no build id, no "powered by".

## S2 · The shell — `/staff`

**Purpose.** Say where you are, what you can do, and how to leave. It is chrome, not a screen:
10b's board and 10c's KDS mount inside it.

**Content inventory.** The **restaurant's name** (from `GET /v1/tenant` — never the slug, never
the uuid); the signed-in person's name; a role word in plain language, not the enum
(*"Owner" / "Manager" / "Staff"*, and `§12` forbids showing `tenant_role` values); navigation;
sign-out. **No tenant id and no user id is rendered anywhere** — asserted, not intended.

| State | What is shown |
| --- | --- |
| `loading` | the staff loading primitive, with an announced label |
| `ready` | the chrome and the child screen |
| `error` | the staff error primitive **with a retry** |
| `offline` | the offline primitive, **without the chrome** — see the correction below |
| `unauthenticated` | a redirect to S1, not an error — an expired session is normal, not a fault |
| `locked` | S3, full-screen, over everything |

> ⚠ **CORRECTED 2026-11-02 — `§11` QA M-9. THE `error` AND `offline` ROWS SAID THE CHROME STAYS,
> AND THE IMPLEMENTATION DELIBERATELY DOES THE OPPOSITE: `StaffApp.tsx` renders the shell only
> in `ready`, and `apps/web/test/staff-build-surface.test.ts` ASSERTS the absence. The reviewer
> is right that the spec was not amended, and amending it is this note rather than a quiet edit
> to the row above.**
>
> **Why the implementation is what ships.** The chrome is the restaurant's name, the person's
> name, their role and a *"Sign out"* button — it exists only once `GET /v1/tenant` has
> answered. The first version did render it in every phase but `locked`, and the defect was
> found in the BUILD OUTPUT rather than by a test: `/staff` and `/staff/devices` are statically
> prerendered, so both shipped documents carried `qr-staff__restaurant`, a `Sign out` button and
> *"Signed in as "* with an empty name — to a caller who had presented no credential at all. A
> phase-gated chrome is what removes that, and "no chrome until the restaurant is known" is one
> rule covering the prerender, the transient `offline` and the `error` phase alike.
>
> **What the struck clause was protecting, and how it is still met.** It was about not throwing
> a person out of the app over one dropped packet — and that property is kept by the STATE, not
> by the chrome: `offline` renders the offline primitive with a retry affordance that calls
> `load()` on the person's own action, the session is untouched, and a successful retry returns
> to `ready` with the chrome back. Nobody is signed out and nothing is lost. What the person
> does not see for those seconds is the restaurant's name — which the application does not
> reliably know at that moment, and inventing a header for it is the half-truth `§12` refuses.
>
> Recorded as a **decide-and-record**, not a stop: this is my own pre-coding specification
> rather than an approved design in the ticket, and the reason it changed was measured after it
> was written.

**Exact copy.** EN: *"Signed in as {name}"* · *"Sign out"* · *"Something went wrong on our
side. Nothing you did caused it."* / *"Try again"* · *"No connection. We will keep trying."*
AR: *"مسجّل الدخول بصفة {name}"* · *"تسجيل الخروج"* ·
*"حدث خطأ من جهتنا. لم يكن ذلك بسببك."* / *"حاول مرة أخرى"* ·
*"لا يوجد اتصال. سنواصل المحاولة."*

## S3 · Locked — an overlay, not a route

**Purpose.** A screen left alone in a kitchen must stop being a signed-in screen.

**THE DESIGN, AND IT IS THE SECURITY ANSWER RATHER THAN A UI STATE.** After
**15 minutes** with no pointer, key or touch event, the shell (a) calls
`POST /v1/auth/logout`, destroying the **server** session, (b) drops every byte of tenant data
it is holding in memory, and (c) covers itself with this overlay. So a locked screen holds **no
valid credential at all** — devtools, a copied cookie and a direct `curl` from that browser all
get the same bodiless 401 as a stranger. Recovery is a full passkey ceremony, which is
*"recovery needs the credential again"* satisfied by the server and not by CSS.

**Polling is not activity.** A board that refreshes itself every few seconds must still lock,
or an unattended screen never locks — which is the whole requirement. Only **human** input
events reset the timer, and that is asserted.

**15 minutes is a decide-and-record, not a ruling** — it is a parameter and `§3` row 10 names
none. It is one exported constant read by the client and by its test, so 10b and 10c consume
it rather than choosing again. A kitchen display that must stay readable without a person is a
**device-token** surface (`Principal::Device`), which holds no human session and is 10c's
concern — that is why a human session may safely expire this aggressively.

**Exact copy.** EN: *"Screen locked"* · *"Nobody is signed in on this screen. Sign in with
your passkey to carry on."* · *"Sign in"*
AR: *"الشاشة مقفلة"* · *"لا يوجد أحد مسجّل الدخول على هذه الشاشة. سجّل الدخول بمفتاح المرور
للمتابعة."* · *"تسجيل الدخول"*

**No countdown is shown.** A visible timer tells a passer-by how long they have.

## S4 · Devices — `/staff/devices`

**Purpose.** A kitchen screen needs a credential of its own. This is where someone who runs the
restaurant creates one, sees the ones that exist, and takes one away.

| State | What is shown |
| --- | --- |
| `loading` | the loading primitive |
| `empty` | **with a next action** — `§12`'s zero-technical-background bar forbids an empty state that only says "nothing here" |
| `ready` | the list: the name the person gave it, when it stops working, when it was last used |
| `issued` | the token, **once**, with the plainest possible statement that it will not be shown again |
| `error` / `offline` | the primitives, with a retry |
| `permission-denied` | staff hold no `devices:write`; no retry, because retrying changes nothing |

**Exact copy.** EN: heading *"Kitchen screens"* · empty *"No kitchen screen is set up yet."* /
*"Set up a kitchen screen"* · *"Stops working on {date}"* · *"Last used {when}"* /
*"Not used yet"* · issued *"Copy this now. It is shown once and cannot be shown again. If you
lose it, remove this screen and set up a new one."* · remove *"Remove this screen?"* /
*"The screen will stop working straight away. You can set up a new one afterwards."* /
*"Remove"* / *"Keep it"* · denied *"Only the owner or a manager can set up kitchen screens.
Ask one of them."*
AR: *"شاشات المطبخ"* · *"لا توجد شاشة مطبخ بعد."* / *"إعداد شاشة مطبخ"* ·
*"تتوقف عن العمل في {date}"* · *"آخر استخدام {when}"* / *"لم تُستخدم بعد"* ·
*"انسخه الآن. يُعرض مرة واحدة ولا يمكن عرضه مرة أخرى. إذا فقدته، أزل هذه الشاشة وأعدّ واحدة
جديدة."* · *"إزالة هذه الشاشة؟"* / *"ستتوقف الشاشة عن العمل فورًا. يمكنك إعداد واحدة جديدة
بعد ذلك."* / *"إزالة"* / *"الإبقاء عليها"* ·
*"يمكن للمالك أو المدير فقط إعداد شاشات المطبخ. اطلب ذلك من أحدهما."*

**Not one identifier on this screen.** No token id, no uuid, no ability string: *"Kitchen
screen"* rather than `pager:read`, a name the person typed rather than a key.

## S5 · The staff primitives in `@qrpager/ui`

`StateView` already implements the five states and is the single implementation — this adds the
**staff** layer rather than a second one: staff-scale type and target sizes for a screen read
from two metres, and **one mechanical strengthening** that comes straight from `§12`'s
zero-technical-background clause — **`empty`, `error` and `offline` REQUIRE an action.** The
type makes an actionless empty state fail to compile rather than fail a review.

---

# What 10a does NOT deliver, declared now rather than at the tag

1. **The shift screen** — STOP-AND-REPORT 1. Not built, not stubbed, not guessed.
2. **Staff-management screens** — STOP-AND-REPORT 2. `R-115`'s third item, in no scope.
3. The board, pager detail, mark-ready, the KDS, history, sheets — 10b, 10c, 10d by the split.
