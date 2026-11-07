# QRPAGER-24 — the operator script

---

## ✅ PERFORMED 2026-11-02. `R-073` IS CLOSED. Read this result before the steps.

**iPhone 16 Pro Max · iOS 27.0.1 · display mode `standalone` · TWO real pushes from Task 4's
dispatcher, BOTH RECEIVED.**

| | |
| --- | --- |
| Foreground | **received** — `outbox_id 01a1994f-…`, `delivered: 1, pruned: 0`, `2026-11-01T22:51:41Z` |
| **Phone LOCKED, app backgrounded** | **received** — `outbox_id 01a19957-…`, `delivered: 1, pruned: 0`, `2026-11-01T23:00:27Z` |

Both outbox rows `sent` on **one attempt, no error**; `pruned: 0` both times, so Apple returned no
404/410 and the endpoint survived backgrounding. Full record in `PLAN §4a`.

### ⚠ THE SECOND PUSH USED `renotify`, NOT `markReady` ON A FRESH SESSION — and the reasoning matters more than the outcome

The instruction was *"re-run `markReady` on a fresh session"*. That would have failed, and in the most
misleading direction available:

1. **`push_subscriptions.pager_session_id` binds a subscription to ONE session.** A fresh session
   carries no subscription, so the dispatcher reports **`delivered: 0`** — and on a locked phone that
   reads as *iOS did not deliver*, blaming the platform for a harness mistake and recording the run as
   a failure of the very thing it proves.
2. **`markReady` on the SAME session would correctly have sent NOTHING.** `§4 › Realtime`:
   *"`markReady` is idempotent. A retry never double-notifies."* The session was already `ready`.
3. **`renotify` is the staff action for exactly this**, and `§3` row 10 names it.

**So one run confirmed three `§4` clauses** — the session-bound subscription, `markReady`'s idempotency,
and `renotify`'s distinctness — from a correction to the method rather than from a test.

### ⛔ AND THE FINDING THIS TICKET EXISTED TO PRODUCE: the install path took THREE attempts

Worth more than the push itself, because **Task 9's install guidance has to survive all three**
(`R-214`):

| attempt | why it failed on the device |
| --- | --- |
| **1** — `#t=<token>` + `localStorage` | **(i)** an installed web app launches at the manifest's `start_url`, never the bookmarked URL, so the fragment cannot survive the install; **(ii)** a Home-Screen app's storage is **partitioned** from Safari's, so the pre-install write is invisible to it |
| **2** — per-session manifest, link repointed client-side | **iOS did not honour it.** Safari reads `<link rel="manifest">` from the **HTML as served**, and it **caches a manifest per origin**, so attempt 1's token-less manifest was very likely still in use. It passed a headless-Chromium probe, which proved nothing about iOS |
| **3** — ✅ **a text field in the installed app** | worked first time. Paste the token, shape checked with the GENERATED `isWellFormedPagerToken`, stored in that context's own storage |

**The ruling:** *the install path's token transport is NOT the thing under test.* The token merely has to
BE in the standalone context. **Production's QR payload is untouched** — `PagerToken::qrPayload()` still
emits `{landing_url}#{token}` and `PLAN §3` row 3's fragment rule is unchanged.

**Install-link fidelity, if it is ever wanted, is server-rendered and path-based** —
`/harness/push/<token>` emitting `<link rel="manifest" href="/harness/manifest/<token>">` in the HTML as
served, never patched client-side. Ruled explicitly as not part of this run.

### ⚠ FOUR DEFECTS IN THE SCRIPT BELOW, every one found by RUNNING it rather than reading it

1. `$issued->state->id` — no such property; it is `$session`. Printed an **empty** `SESSION:` after two
   warnings, which the operator then pastes into `markReady`. **Corrected below.**
2. `run start -- --port 3000` — the `--` makes `next start` read `--` as the project directory
   (*"Invalid project directory provided"*). **Corrected below.**
3. `NEXT_PUBLIC_VAPID_PUBLIC_KEY` must be exported **before the build**, and verified baked in — not
   assumed.
4. ⛔ **The install path had never been run end to end on a device at all**, which is the one thing this
   harness exists to test. Attempts 1 and 2 are what that cost: three device sessions instead of one,
   and the finding above arrived by accident rather than by design.

---


**For Muhammad, on the machine that holds the repo, with the iPhone in hand.**

The harness is built and green. What is left is the half an agent cannot do:
`QRPAGER-24 §4(a)` — *"A real iOS device, held by Muhammad. The device run is his; an agent
can only build the harness."*

---

## ⚠ Read this before you start anything

**EVERY STEP HAPPENS IN ONE SESSION, AND THAT IS NOT A STYLE PREFERENCE.** `QRPAGER-24 §4(b)`:
a `cloudflared` quick tunnel's hostname is **random per run**, and a Home-Screen install
**pins its origin**. So:

- The notification permission, the service-worker registration, the push subscription and
  the Cache entry all belong to `https://<random>.trycloudflare.com`.
- Kill the tunnel and that origin is gone. The Home Screen icon still exists and still
  points at a hostname that now resolves to nothing — and **everything the install knew
  dies with it**.
- `§4`: *"A tunnel restarted between steps invalidates the run, and a run that was
  invalidated is not evidence."*

**Budget 20 uninterrupted minutes. Do not close the tunnel terminal.**

**AND THE INVARIANT IS ABOUT THE HOME SCREEN, NOT ABOUT SAFARI.** `QRPAGER-24 §3`: *"A push
received in Safari WITHOUT a Home Screen install does not satisfy it."* The harness stamps
every transcript line with the display mode it was produced in, so a tab run cannot be
pasted into `PLAN §4a` as if it were an install run. Read the stamp before you believe the
result.

---

## 0. One-time setup, before the tunnel

**(a) VAPID keys. `apps/api/.env` has none — measured, `VAPID_PUBLIC_KEY` is empty.** Without
them the dispatcher cannot sign a push and the subscribe button cannot build an
`applicationServerKey`.

```bash
cd ~/code/qr-pager/apps/api
php -r 'require "vendor/autoload.php"; print_r(Minishlink\WebPush\VAPID::createVapidKeys());'
```

Put the pair into `apps/api/.env` and set a subject (a `mailto:` you own):

```
VAPID_SUBJECT=mailto:muhd.shurman@gmail.com
VAPID_PUBLIC_KEY=<the publicKey from above>
VAPID_PRIVATE_KEY=<the privateKey from above>
```

**(b) Services and the API.**

```bash
cd ~/code/qr-pager/apps/api
docker compose -f ../../compose.yaml up -d
php artisan db:bootstrap
php artisan migrate --database=pgsql_owner
php artisan serve --port=8000            # leave running, terminal 1
```

**(c) Seed a tenant and a pager session, and print the token.** ⚠ **Corrected 2026-11-02: this read `$issued->state->id` and `IssuedPagerSession` has no `state` property** — it is `$session` (`app/Features/Pager/IssuedPagerSession.php:22`). Run as written it printed an empty `SESSION:` after two `Undefined property` warnings, which is a value you would then paste into `markReady` and get nothing from. Found by running the script rather than reading it. No new code: this is Task 3's
own `PagerSessions::create`, which is what the staff endpoint calls.

```bash
cd ~/code/qr-pager/apps/api
php artisan tinker --execute='
  $tenant = (string) Illuminate\Support\Str::uuid7();
  withTenant($tenant, fn () => Illuminate\Support\Facades\DB::connection("pgsql_owner")
      ->table("tenants")->insert([
          "id" => $tenant,
          "name" => "Push harness",
          "slug" => "push-harness-" . substr(str_replace("-", "", $tenant), 0, 12),
      ]), "pgsql_owner");
  $issued = app(App\Features\Pager\PagerSessions::class)->create($tenant, "H-001");
  echo "TENANT:  {$tenant}\n";
  echo "SESSION: {$issued->session->id}\n";
  echo "TOKEN:   {$issued->token->reveal()}\n";
'
```

**Keep all three.** The token is the customer's only credential; it is a 128-bit secret and
it goes in a URL **fragment** only, never a query string (`PLAN §3` row 3).

**(d) Build and serve the harness.** The rewrite makes the page and `/v1` the **same origin**,
so one tunnel covers both and iOS never sees mixed content.

```bash
cd ~/code/qr-pager
export QRPAGER_HARNESS_API_ORIGIN=http://127.0.0.1:8000
export NEXT_PUBLIC_VAPID_PUBLIC_KEY=<the VAPID_PUBLIC_KEY from step (a)>
pnpm --filter @qrpager/web run build
pnpm --filter @qrpager/web run start -- --port 3000   # leave running, terminal 2
```

> `NEXT_PUBLIC_*` is baked in at **build** time, so it must be exported **before** the build.
> If you set it after, the page will say `NEXT_PUBLIC_VAPID_PUBLIC_KEY is empty` in its own
> transcript — the harness checks on load rather than failing at the button.

---

## 1. The tunnel — terminal 3, and it stays open

```bash
cloudflared tunnel --url http://127.0.0.1:3000
```

It prints a line like:

```
https://random-words-1234.trycloudflare.com
```

**That hostname is the origin for the whole run.** Copy it. If this terminal dies, go back to
step 1 and start the device steps over — a new hostname is a new origin.

---

## 2. On the iPhone — the ordered steps

**Open this URL in Safari — deliberately with NO token in it:**

```
https://<random>.trycloudflare.com/harness/push
```

⚠ **Do NOT put the token in the URL.** A Home-Screen launch starts at the manifest's `start_url` and a
Home-Screen app's storage is partitioned from Safari's, so a token delivered in Safari reaches the
installed app by neither route — that is attempts 1 and 2 above. **Install first, then paste the token
into the app itself** at step 3a.

Then, in this order:

1. **Install to the Home Screen.** Share → **Add to Home Screen** → Add.
2. **Close Safari, and open the app from the Home Screen icon.**
   **Check the page says `Display mode: standalone`.** If it says `browser tab`, stop — a
   push received now does not satisfy `§3`, and the transcript will say so on every line.
3. ⚠ **PASTE THE TOKEN INTO THE APP.** The *Pager token* field is rendered only when this
   context has no token — which is every fresh install, for the two iOS reasons above. Paste the
   `TOKEN:` from step 0(c) and tap **Use this token**. Expect
   `token accepted from the paste field and stored in THIS context`, and the Token row to flip to
   `present`.
   **The shape is checked with the GENERATED `isWellFormedPagerToken`** (length 22), so a
   truncated paste is refused here with a reason rather than failing later at the API with a 404
   that names nothing. Measured: a 10-character paste is refused.
4. **Tap "Grant notification permission".** iOS requires this from a user gesture, which is
   why it is a button. Expect `granted`.
5. **Tap "Subscribe".** Expect two lines: `subscribed at https://web.push.apple.com` and
   `POST /v1/pager/subscription -> 201 {"registered":true}`.
6. **Trigger the dispatcher** — back on the machine, terminal 4. This is Task 4's real path:
   `markReady` writes the outbox row, `outbox:work` dispatches it.

   ```bash
   cd ~/code/qr-pager/apps/api
   php artisan tinker --execute='
     app(App\Features\Pager\PagerSessions::class)->markReady("<TENANT>", "<SESSION>");
     echo "marked ready\n";
   '
   php artisan outbox:work --limit=5
   ```

7. **Receive.** The notification arrives on the phone. Tap it; it focuses the installed app.
   The in-page transcript is the evidence.

**Leave the phone unlocked and the app open for steps 6-7 on the first run.** If that works,
repeat with the app **backgrounded and the phone locked** — ⚠ **and use `renotify`, NOT `markReady`
on a fresh session**, for the three reasons at the top of this file — that is the case the product
actually depends on, and it is worth the extra 60 seconds while the tunnel is still up.

---

## 3. What to record in `PLAN §4a`

`QRPAGER-24 §3` asks for four things, and `§5` says the ticket is **not tagged** until they
are in `PLAN §4a`:

| Field | Value |
| --- | --- |
| iOS version | Settings → General → About → Software Version |
| Device | e.g. iPhone 14 Pro |
| Timestamp | from the transcript line, which is ISO-8601 UTC |
| Result | received / not received, **and the display mode the transcript stamped** |

Paste the transcript verbatim. Also record, because they are the things that would make a
later reader doubt the run: whether the app was foregrounded, backgrounded or the phone
locked; and whether step 5 was repeated.

**If it does not arrive**, that is a result and not a failure of the exercise — it is exactly
what `R-073` was raised to find out, and `QRPAGER-24 §4` already says the honest outcome gets
recorded rather than faked. Capture the subscribe response, the endpoint origin, and
`storage/logs/laravel.log` around the `outbox:work` run before the tunnel dies.

---

## 4. Teardown

Terminals 1-3: Ctrl-C. Delete the Home Screen icon — its origin is dead and leaving it
invites a second run against a hostname that no longer exists.

⚠ **Then remove the VAPID keys from `apps/api/.env` or keep them deliberately.** They are a
real signing key pair. `.env` is git-ignored, so this is not a leak risk in the repo — it is
a decision about whether this machine holds a key that can sign pushes for this origin.
