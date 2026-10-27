# QRPAGER-12 — Task 5, Auth & RBAC — the handover

> Branch `task-5-auth-rbac`, pushed. Epic **E3** (`QRPAGER-4`). Built by an unattended
> builder session on 2026-10-27/28.
>
> **No PR, no merge, no tag** — the rank-1 prompt's pre-authorised deviation, which overrides
> `§6`'s "commit + tag `task-N`" and `§11`'s PR step for this task. The two `§11` reviews are
> the user's next prompt and were **not** run here.
>
> Read with `docs/tickets/QRPAGER-12.md`, which carries the ticket, the seven `§6` answers
> and the three stop-and-reports.

---

## 1. WHAT I WOULD DO FIRST IF I WERE YOU

1. **Read `§6` below — the one gate clause this task does not meet.** Passkey registration
   and login are not proven through a browser, because there is no browser on this machine.
   Everything else in the gate is met and measured.
2. **Then `§7`, the assumptions.** Five, each with the alternative I rejected. The one most
   worth your disagreement is the device token's tenant travelling in `abilities` rather than
   in a column.
3. **Then run the gate yourself**, from `apps/api`: `composer gate`. The last run at head is
   in `docs/tickets/QRPAGER-12-gate-final.txt`.
4. **Then the two `§11` reviews**, each in its own session, given
   `git diff task-4..task-5-auth-rbac` and `docs/tickets/QRPAGER-12.md` only — not this file,
   which is the builder's reasoning written down.

---

## 2. WHERE THE BRANCH WAS CUT — the one deviation from the instruction

The instruction was "off `task-4-notifications` at the task-4 tag (`55a4f98`)". **Cut at
`f44085b`** — the branch tip, one commit later.

```
$ git cat-file -e 55a4f98:apps/api/bin/_revert-proof.sh
fatal: path 'apps/api/bin/_revert-proof.sh' exists on disk, but not in '55a4f98'
```

The same prompt requires every revert-proof script to source that helper, and `f44085b` is
the commit that adds it. Branching at the tag would have made two of its own instructions
unsatisfiable at once. `f44085b` **contains** `55a4f98`, so nothing tagged is lost.

---

## 3. WHAT IS DONE

### 3.1 Phase 0 — the ledger sweep (`d691892`)

`§5` read against every owner, in one pass. **Before: 99 rows, 26 closed, 69 open. After the
sweep: 30 closed, 69 open. After Task 5's own closures: 32 closed, 67 open.**

- **Six rows were owned by a task that is already tagged and still open.** Four were already
  done and the ledger had never been told — `R-063`, `R-068`, `R-069`, `R-070` — all closed
  with the commit that closed them. Two are genuinely open and **re-owned with a new date**:
  `R-082` (Muhammad for the policy, Task 12 for the edit) and `R-088` (Task 13, beside
  `R-087`).
- **`R-088` is part-closed and the measurement is `bin/coverage`'s, not the row's.** Three of
  its four gaps moved; the command's loop, sleep, `shouldStop` exit and both `pcntl` handlers
  are still wholly uncovered at **64.3 %**.
- **Two rows' own *why accepted* cells were FALSE.** `R-068` and `R-069` both said the defect
  was *"carried rather than corrected in place by the user's decision"* while both had in
  fact been corrected at `b50cf39`. Corrected in place.
- **`R-091` … `R-099` each gained an owner AND an accepted-on date.** They had owners and a
  trigger; `§11` asks for a date and none of the nine carried one.
- **`R-092`'s trigger fired on this branch** — *"before Task 5 branches"* — and is answered
  in its own row rather than passing unnoticed the way `R-082`'s did.
- **⚠ And the sweep's own first arithmetic was wrong**, which is written into `§5.1` rather
  than quietly fixed: it published "31 → 35 closed, 68 → 64 open" from a grep for `CLOSED`,
  and `R-047` contains the phrase *"/readyz fails CLOSED"* — a dependency posture, not a
  closure. Re-measured with the rule now stated in `§5.1`.

### 3.2 Phase 1 — the ticket and the seven answers (`76ba810`)

`docs/tickets/QRPAGER-12.md` did not exist; the board was rewritten on 2026-10-27 and nothing
had transcribed it. It now carries the ticket, the seven `§6` answers, and three
stop-and-reports. **The role matrix is named in `PLAN.md §3` row 5**, which is what `§3.1`
row 5 said this gate still needed and what it says to do at the task's start.

### 3.3 Phase 2a — the stub is gone (`b6f7e9d`)

`app/Platform/Auth/HarnessTenantResolver.php` is **deleted**, with its binding and its
`actingForTenant()` call site. `tests/Feature/Auth/StubRemovedTest.php` is `R-035`'s three
assertions, made stronger than the gate's wording: no file for the class anywhere under
`app`, `database`, `routes`, `config` or `bootstrap`; no **mention** of the name in any of
those trees, not even in a comment; the binding resolves to the real implementation; and no
test references it either. The name is spelled once in this repository, in that file's own
constant — and writing the real resolver's docblock broke the second assertion immediately,
which is the assertion working.

**The deptrac rule is repurposed, deliberately.** The `AuthStub` layer is renamed `Auth` and
keeps the rule that `Features` may not import it: the old rule was "nothing may be built on a
class about to vanish", the new one is "the auth layer has one entry point". The
deliberately-bad fixture now imports the real resolver and the guard still exits non-zero.

**The suite could not have run with the stub gone and no real auth**, which the ticket asked
me to say either way. The shortest path was to land the resolver in the same commit, and
`actingForTenant()` now creates a user, gives them one membership and authenticates as them —
so a feature test exercises the guard, the `§2.8` membership function and the
suspended-tenant check instead of a harness that short-circuited all three.

### 3.4 Phases 2b, 2d, 2e — the matrix, device tokens, R-081 (`7d16ace`)

- **The role matrix**: one PHP array in `App\Domain\Auth\RoleMatrix`, read by
  `RequireAbility` to decide and by `RoleMatrixTest` to assert. **5 principals × every `/v1`
  staff route × both tenants.** Totality is mechanical: `RequireAbility` fails **closed** on
  an unmapped route, and the test iterates the router and requires every `/v1` route to be in
  exactly one of three named sets.
- **Device tokens**: one tenant, one capability, explicit expiry — seven cases, including a
  token whose stored abilities are rewritten **directly in the database as the owner role**,
  which still reaches only what `Principal::Device` holds.
- **`R-081` closed in both halves**: enforcement at the resolver in one place, a test over
  **all twelve** staff routes rather than one, and the customer surface's behaviour decided
  and written down.
- **`R-007`'s Task 5 half closed**, by the matrix rather than by a grant change.

### 3.5 Phase 2c — passkeys, 2FA, the harness page

- **2FA**: enrol → confirm → challenge, with recovery codes. Six cases, including the
  `§6` failure question *"2FA device lost"* (a recovery code works exactly once and does
  **not** disable two-factor) and **OTP replay protection**, which I found by writing a test
  that reused a code and was correctly refused.
- **Passkeys**: the four ceremony endpoints over `laravel/passkeys`, and every server-side
  half tested. The browser half is `§6` below.
- **The harness page**: `resources/views/passkey-harness.blade.php`, mounted outside
  `production` only, deliberately unstyled. `§3` row 5 asks for exactly that.

---

## 4. THE DATA CONTRACT THIS TASK ADDED

`PLAN.md §2` was updated **in the same commit as the migration and before the code that uses
it**, which is the rank-1 invariant.

| What | Where | Note |
| --- | --- | --- |
| `users` + 4 columns | `§2.4` | `is_platform_admin`, and Fortify's three 2FA columns |
| `passkeys` | `§2.4` | **global, no RLS** — a passkey belongs to a user |
| `personal_access_tokens` | `§2.4` | **global, no RLS** — resolvable from a bearer token alone |
| `qrpager_app` loses `SELECT` on `users.password`, `remember_token` | `§2.7` | **`R-006`** |
| …and never gains it on either 2FA secret | `§2.7` | my choice, not `R-006`'s letter — see `§7` |
| `public.authenticate_user(varchar)` | `§2.8` | crosses a **column grant**, not a tenant boundary |
| `public.user_memberships(uuid)` | `§2.8` | **`§4`'s second named path**, already on the list |
| `public.user_two_factor(uuid)` | `§2.8` | the consequence of withholding the 2FA secrets |

**No new GUC. C1 holds.** `R-006`'s own cell names a third `app.*` GUC as the forbidden route,
so the actor identity stays in the auth layer.

**Nothing this task adds needs RLS**, because all three tables are global — and that is the
largest single widening of the RLS-free surface since Task 1. The C4 meta-test makes each
addition a build failure rather than an oversight, and `RlsCoverageTest`'s pinned allowlist
carries the argument for each. **A reviewer should push hardest here.**

---

## 5. GATE FIGURES — each at the commit it was measured at

| Clause | Result | Commit |
| --- | --- | --- |
| `composer gate`, all six steps | *(filled in below)* | |
| Baseline, before any Task 5 work | **654 passed / 4132 assertions**, six steps green | `f44085b` |
| Coverage ≥ 90 % on this task's code | *(filled in below)* | |
| Fresh clone | *(filled in below)* | |
| Migrations apply twice, rollback clean | *(filled in below)* | |
| Three consecutive green full-suite runs | *(filled in below)* | |
| Every new route in the generated OpenAPI spec, `/v1`, committed | **28 routes, 64 problem responses**, `openapi:check` green | at head |

Transcripts: `QRPAGER-12-baseline-gate.txt`, `QRPAGER-12-gate-2a.txt`,
`QRPAGER-12-gate-2b.txt`, `QRPAGER-12-gate-final.txt`, `QRPAGER-12-coverage.txt`,
`QRPAGER-12-fresh-clone.txt`, `QRPAGER-12-rollback.txt`, `QRPAGER-12-three-runs.txt`.

---

## 6. STOPPED ON — the one gate clause this task does not meet

### 6.1 Passkey registration and login are NOT proven through a browser

`PLAN.md §3` row 5 requires:

> **passkey registration + login proven end to end through the browser against the harness
> page** (the styled screen is Task 10, and must not be required here)

**It cannot be demonstrated on this machine.** The credential ceremony lives in
`navigator.credentials`, not in HTTP: a valid attestation needs a real authenticator, or a
virtual one driven over CDP. Measured:

```
$ which chromium chromium-browser google-chrome firefox node npx
/mnt/c/Program Files/nodejs//npx          # a Windows shim; no node binary
$ ls ~/.cache/ms-playwright
no such directory
```

**What is built and what is proven instead:**

- the harness page exists (`resources/views/passkey-harness.blade.php`), mounted outside
  production, and does the base64url ↔ `ArrayBuffer` conversion a real ceremony needs;
- the four endpoints exist and are in the committed spec;
- **every server-side half is tested** — options are issued and the challenge kept
  server-side, each options call replaces the pending challenge, a credential with no stored
  challenge is refused, a malformed one is refused, the user handle is an HMAC and not the
  user id, and the stored row has a uuid key.

**What I did NOT do, and would not:** fabricate an attestation and call it end-to-end. That is
the vacuous-assertion defect `§4a` records four of, committed on purpose.

**What closes it** — about twenty minutes on a machine with a browser:

```
pnpm add -D @playwright/test && npx playwright install chromium
# then drive the harness page with a CDP virtual authenticator:
#   client.send('WebAuthn.enable')
#   client.send('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2',
#     transport: 'internal', hasResidentKey: true, hasUserVerification: true,
#     isUserVerified: true } })
# register, then sign out, then sign in with the passkey, asserting 201 and 200.
```

The suite has no Playwright wiring at all yet — `§1` locks it for E2E and Task 9 is the task
that creates it. So this clause may be cheaper to close **with** Task 9's harness than on its
own.

### 6.2 Two smaller things recorded rather than claimed

- **The passkey registration POST's refusal order is unverifiable here.** laravel/passkeys'
  FormRequest deserialises the credential in `passedValidation()`, so a 422 is reached before
  the controller's session check — and every credential a test can fabricate without an
  authenticator fails that deserialisation. What IS established either way: no passkey is
  stored and no account is named. If you want the 401 first, the fix is `auth` middleware on
  the two registration routes.
- **"A challenge is spent even when the ceremony fails" is unproven**, for the same reason:
  spending happens inside `registrationOptions()`, which only a deserialisable credential
  reaches. The adjacent property that *is* proven is that each options call replaces the
  pending challenge.

---

## 7. ASSUMPTIONS, each with the alternative I rejected

1. **The branch point is `f44085b`, not `55a4f98`.** Alternative rejected: branch at the tag
   and leave `bin/_revert-proof.sh` absent, which the same prompt forbids. `§2` above.
2. **The tenant a staff request acts in comes from the server-side session, never a header.**
   Alternative rejected: an `X-Tenant` header, which would have meant **weakening
   `PagerIdorTest`** — it drives five caller-supplied tenant shapes, `X-Tenant` among them,
   and requires 404 from each. The design changed instead and that test passes unchanged.
3. **`personal_access_tokens` carries no `tenant_id`; the tenant travels in `abilities`.**
   Alternative rejected: a `tenant_id` column — which makes it a tenant table, forces RLS by
   C4, and makes the row invisible to the lookup that must happen before any tenant is known.
   The only escape is a **fifth** named cross-tenant path, which `§4` forbids without a
   rank-2 edit. **This is the assumption most worth your disagreement.**
4. **`qrpager_app` is never granted `SELECT` on the two 2FA secrets.** `R-006` names only
   `password` and `remember_token`, so this was my choice. Alternative rejected: grant them,
   which re-opens `R-006`'s shape one task after closing it. The cost is real and is paid in
   `public.user_two_factor(uuid)` plus not using two of Fortify's helpers.
5. **A suspended tenant's customer sessions keep working.** Alternative rejected: refusing the
   customer surface too, for symmetry — rejected because symmetry is not a reason and the cost
   falls on the diner. Bounded at two hours by the TTL and by staff being unable to open a new
   session.

---

## 8. WHAT THE SUITE FOUND THAT I HAD WRONG

Eight, listed because each is a defect a reviewer would otherwise have to find:

1. **deptrac refused `App\Features\Devices` → `App\Platform\Auth`** — the rule Task 5 had just
   repurposed, biting its own author. The tenant-pin encoding moved to
   `App\Domain\Auth\TenantPin`.
2. **`R-052`'s control caught internal rationale in published descriptions, twice** — a
   form-request rule comment, then a class docblock. Scramble ships both.
3. **Sanctum's token model assumes a bigint key** — `23502` null violation on `id`. Second
   instance of the mismatch the passkey model already had.
4. **The `api` group had no session middleware**, so the tenant selection silently never
   applied — and every single-membership test still passed, because the resolver falls back
   to `sole()`. Only a two-membership user exposed it.
5. **`User::refresh()` bypasses global scopes**, so it emitted `select *` and hit the `42501`
   the scope exists to prevent. Fixed in the model, not just the caller.
6. **`encrypted` casts on the 2FA columns double-encrypted them** — Fortify owns that
   encryption end to end. `DecryptException: The payload is invalid.`
7. **Withholding the 2FA secrets broke Fortify's own helpers**, which read them off the model.
   That is what `public.user_two_factor(uuid)` is for.
8. **A test of mine asserted a tri-state the code did not have** — a session selection that is
   *present but unusable* must refuse, not fall back. The test was right; the code changed.

---

## 9. THE PROOF SCRIPTS, all in the repository (`R-043`)

| Script | Proves |
| --- | --- |
| `bin/gate` | the six steps, with the output kept |
| `bin/coverage` | coverage on this task's own files |
| `bin/fresh-clone-proof` | the gate from a clean checkout |
| `bin/migration-rollback-proof` | apply twice, roll back clean, re-apply identical |

Task 5 adds **no new proof script**, and that is worth stating: every clause it has to
demonstrate is demonstrated by a test in the suite or by one of the four above. The one
clause that would need a new harness is the browser proof in `§6`, and that harness is
Playwright, which Task 9 owns.

---

## 10. WHAT IS NOT DONE

- **The browser passkey proof** — `§6.1`.
- **No PR, no merge, no tag** — instructed.
- **The two `§11` reviews** — the user's next prompt.
- **Password reset, email verification and self-service registration are deliberately
  absent.** `config/fortify.php` records each with its owner: reset and verification need a
  delivered email, whose first gate clause is Task 6's; `/register` does not exist at any
  tier.
- **Staff invites** — Task 6's wizard. `members.store` adds an **existing** account.
- **`R-006` is closed; `R-007`, `R-035` and `R-081` are closed.** Task 5 owned exactly those
  four.
