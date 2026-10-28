<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use App\Domain\Http\ProblemType;
use App\Platform\Auth\DeviceToken;
use App\Platform\Http\Problem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The auth surface is the staff SESSION surface. A request that authenticated by bearer
 * token is refused on all of it.
 *
 * ================================================================================
 * WHY THIS EXISTS: THE GAP BETWEEN TWO CORRECT DECISIONS
 * ================================================================================
 *
 * Two things were true and the space between them was open.
 *
 * `routes/auth.php` is mounted on the `auth-surface` group, which deliberately carries
 * neither `RequireTenantScope` (circular — the tenant comes from the credential a login is
 * about to establish) nor `RequireAbility` (there is no tenant yet to hold an ability in).
 * Both of those reasons still hold and neither is changed here.
 *
 * `ResolvePrincipal` tries the `web` guard and then `sanctum`, so that a KDS device
 * presenting a bearer token is authenticated rather than answering 401 on the one route its
 * ability permits. That is also right.
 *
 * Together they left each auth route's only gate as the controller's own
 * `$request->user() instanceof User` — **and a bearer token satisfies that test.**
 *
 * ================================================================================
 * WHAT A BEARER-ONLY CALLER ACTUALLY REACHED, corrected to the production stack
 * ================================================================================
 *
 * ⚠ THE FIRST VERSION OF THIS DOCBLOCK OVER-STATED IT, and the `§11` review of the fix diff
 * measured the difference. `ValidateCsrfToken` runs BEFORE this middleware in the group, so
 * a bearer-only client — no session cookie, no CSRF token — was already refused **419** on
 * every state-changing route. The earlier figures (200 from `two-factor/enable` with a fresh
 * secret and eight recovery codes, 204 from `two-factor/disable`, the whole registration
 * ceremony, 204 from `auth/tenant`) were taken through the TEST harness, where
 * `ValidateCsrfToken::runningUnitTests()` turns CSRF off. They are real as test-harness
 * measurements and they are not the production blast radius.
 *
 * **What was genuinely reachable with a bearer token alone, measured against the production
 * CSRF semantics, is the two GETs:**
 *
 *   GET /v1/auth/me               -> 200, the issuer's WHOLE membership map, including
 *                                   tenants the token is not pinned to
 *   GET /v1/auth/passkeys/options -> 200, a live challenge plus the victim's WebAuthn user
 *                                   handle and display name
 *
 * That is a cross-tenant disclosure from a credential whose entire scope is one tenant and
 * one read-only capability, which is `PLAN.md §3.1` row 5's second named invariant failing —
 * so the fix stands on its own without the larger claim.
 *
 * **And the POSTs were reachable in one shape that is not hypothetical:** a stolen kitchen
 * screen that still holds a live session cookie *and* its matching CSRF token — which is
 * exactly what a browser-based KDS on a shared screen has. In that shape the full list
 * applies: a fresh TOTP secret with all eight recovery codes, a *confirmed* factor silently
 * re-keyed (a lockout, not a disclosure), the second factor removed, and the registration
 * ceremony that mints a credential the attacker holds.
 *
 * That is `PLAN.md §3.1` row 5's second named invariant, "device token scope cannot
 * escalate", failing. `RequestPrincipal` makes it structural on the staff surface by
 * deciding on credential SHAPE before any membership role is read; this is the same rule
 * on the surface `RequestPrincipal` is never consulted on.
 *
 * ================================================================================
 * ON THE GROUP, AND COVERING ROUTES THAT LEAKED NOTHING
 * ================================================================================
 *
 * It refuses the whole group rather than the five routes that gave something away, and both
 * halves of that are deliberate.
 *
 * On the group, so that a route added to `routes/auth.php` INHERITS the refusal instead of
 * having to remember it — the same fail-closed reasoning as `RequireAbility`'s unmapped
 * route. `DeviceTokenOnAuthSurfaceTest`'s last case enumerates the group from the live
 * router and fails the suite if any route on it is not driven, so the coverage cannot drift
 * either.
 *
 * Including `auth.login`, which leaked nothing: it reads the posted credentials and never
 * consults `$request->user()`, so it answered 401 on bad credentials with or without this.
 * It is still refused, because the rule worth having is "this surface does not take bearer
 * tokens" and not "these are the routes where it mattered" — a list of the latter is a list
 * someone has to maintain correctly forever.
 *
 * ================================================================================
 * WHAT IT DOES NOT TOUCH
 * ================================================================================
 *
 * An ANONYMOUS request is untouched: there is no access token on it, so it passes through
 * and gets the controller's own 401. That keeps login, the 2FA challenge and the passkey
 * login ceremony reachable without a credential, which is what they are for.
 *
 * A staff browser carrying a session cookie AND a stray `Authorization` header is also
 * untouched, because `ResolvePrincipal` tries `web` first and a session request has no
 * `currentAccessToken()`. That ordering was already a decision with a reason; this
 * middleware depends on it, which is why it reads the resolved token rather than sniffing
 * the header.
 *
 * ================================================================================
 * 403 AND NOT 401
 * ================================================================================
 *
 * The credential is valid; it is the surface that is wrong for it. 401 would invite a
 * client to retry with a fresh token, which would fail identically, and it would make this
 * refusal indistinguishable from the unauthenticated one the controllers return — two
 * different conditions deserve two answers in the code even when both are refusals.
 *
 * The body says nothing about the cause. A caller gets the same problem document as any
 * other 403, so a stolen screen learns nothing about which routes exist or which credential
 * would have worked; an operator reads the log line below, which names the token id and the
 * route and carries no secret.
 */
final class RefuseDeviceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! DeviceToken::isTokenRequest($request)) {
            return $next($request);
        }

        // The token's own id, null-safely: `isTokenRequest()` has already established that
        // there is a user and an access token, but it says so at runtime and not in a way
        // the analyser can carry here.
        $tokenId = $request->user()?->currentAccessToken()?->getKey();

        // LOGGED AT WARNING, NOT REPORTED AS AN EXCEPTION, and the difference is a
        // measurement rather than taste. This was `report(new RuntimeException(...))`: the
        // §11 review of the fix diff drove 100 refusals and measured **32,200 bytes of
        // local.ERROR** -- 322 per request -- with no bound, from one stolen screen. In any
        // deployment with error alerting that is unbounded alerting too.
        //
        // The signal is still worth keeping: no legitimate client presents a device token
        // here, so a single line says which screen to revoke. What it is not is an
        // application FAULT, and ERROR is the level that pages somebody. The volume is
        // separately bounded now by the `auth-surface` bucket, which runs before this
        // middleware -- that is the actual fix for the flood, and this is the right level
        // for what is being said.
        //
        // The token ID identifies the screen to revoke; the plaintext is never in this
        // process's reach and the hash is not published here either.
        Log::warning('RefuseDeviceToken refused a bearer-token request on the auth surface.', [
            'route' => $request->route()?->getName() ?? '(unnamed)',
            'token_id' => is_scalar($tokenId) ? (string) $tokenId : '(unknown)',
        ]);

        return (new Problem(ProblemType::Forbidden, $request->getPathInfo()))->toResponse();
    }
}
