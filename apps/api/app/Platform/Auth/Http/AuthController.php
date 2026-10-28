<?php

declare(strict_types=1);

namespace App\Platform\Auth\Http;

use App\Domain\Auth\Principal;
use App\Domain\Http\ProblemType;
use App\Models\User;
use App\Platform\Auth\ActiveTenant;
use App\Platform\Auth\Memberships;
use App\Platform\Auth\UserCredentials;
use App\Platform\Http\Problem;
use App\Platform\Http\ProblemDocument;
use App\Platform\Http\ValidationProblemDocument;
use Dedoc\Scramble\Attributes\Response as DocResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signing in, signing out, and choosing which restaurant a session acts in.
 *
 * DOCBLOCKS HERE ARE PUBLISHED as the endpoint descriptions in the OpenAPI spec, so they
 * are written as public API text (`R-052`). The reasoning behind each decision is in
 * `App\Platform\Auth\UserCredentials` and `App\Platform\Auth\ActiveTenant`.
 */
final class AuthController
{
    public function __construct(
        private readonly UserCredentials $credentials,
        private readonly Memberships $memberships,
        private readonly ActiveTenant $activeTenant,
    ) {}

    /**
     * Sign in with an email address and password.
     *
     * Answers the same way for an unknown address and a wrong password.
     */
    #[DocResponse(200, 'Signed in. The body names the restaurants this account may act in.')]
    #[DocResponse(401, 'Those credentials were not accepted.', type: ProblemDocument::class)]
    #[DocResponse(422, 'The request was malformed.', type: ValidationProblemDocument::class)]
    #[DocResponse(429, 'Too many attempts.', type: ProblemDocument::class)]
    public function login(LoginRequest $request): Response
    {
        $userId = $this->credentials->verify(
            (string) $request->string('email'),
            (string) $request->string('password'),
        );

        if ($userId === null) {
            // ONE ANSWER FOR BOTH FAILURES. "No such account" and "wrong password" must be
            // indistinguishable, or this endpoint enumerates the platform's accounts --
            // and `users` is global, so that is every tenant's staff at once.
            return (new Problem(ProblemType::Unauthenticated, $request->getPathInfo()))->toResponse();
        }

        $user = User::query()->find($userId);

        if (! $user instanceof User) {
            return (new Problem(ProblemType::Unauthenticated, $request->getPathInfo()))->toResponse();
        }

        // A CONFIRMED SECOND FACTOR STOPS HERE, and the credentials being correct is not
        // enough to establish a session.
        //
        // `two_factor_confirmed_at` and not `two_factor_secret`: config/fortify.php sets
        // `confirm: true`, so a secret exists from the moment enrolment begins and is NOT
        // in force until the user has proven they can read a code from it. Gating on the
        // secret would lock somebody out of their own account between those two steps,
        // which is QRPAGER-12 §10.6(b)'s failure case.
        if ($user->getAttribute('two_factor_confirmed_at') !== null) {
            // The pending user id goes in the SERVER-SIDE session, so the challenge cannot
            // be completed for somebody else: TwoFactorController reads the id from here
            // and never from the request.
            $request->session()->put(TwoFactorController::PENDING_KEY, $userId);

            // Regenerated even though no session has been established yet: an attacker who
            // fixed the id before the password step must not hold the id that is now
            // one code away from being authenticated.
            $request->session()->regenerate();

            return response()->json(['two_factor_required' => true], 200);
        }

        // SESSION FIXATION. Auth::login() migrates the session, and the explicit
        // regenerate() here is belt and braces on the one event that matters most: a
        // pre-login id must not carry post-login authority. SessionPolicyTest asserts the
        // id moves and that the old one no longer authenticates.
        Auth::guard('web')->login($user);

        $request->session()->regenerate();

        // A STALE SELECTION IS FORGOTTEN AT LOGIN. Memberships change between sessions, and
        // a selection pointing at a tenant this account has left would be re-verified and
        // refused on every subsequent request -- which looks to the user like a broken
        // account rather than a tenant they no longer belong to.
        $this->activeTenant->forget($request);

        $memberships = $this->memberships->for($userId);

        // A single membership is selected for the caller, because there is no choice to
        // offer. Several, and the client has to choose: the resolver refuses to pick one.
        if (count($memberships) === 1) {
            $this->activeTenant->select($request, $userId, $memberships[0]->tenantId);
        }

        return response()->json($this->identity($user, $userId));
    }

    /**
     * Sign out.
     *
     * Answers the same way whether or not there was a session.
     */
    #[DocResponse(204, 'Signed out.')]
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            // invalidate() regenerates the id AND empties the store, so the selected
            // tenant goes with it rather than waiting to be re-verified against a user who
            // is no longer there.
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(null, 204);
    }

    /**
     * Choose which restaurant this session acts in.
     *
     * Reported as not found when the account holds no membership there, or the restaurant
     * is not active.
     */
    #[DocResponse(204, 'Selected.')]
    #[DocResponse(404, 'No such restaurant for this account.', type: ProblemDocument::class)]
    #[DocResponse(401, 'No session.', type: ProblemDocument::class)]
    #[DocResponse(422, 'The request was malformed.', type: ValidationProblemDocument::class)]
    public function selectTenant(SelectTenantRequest $request): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return (new Problem(ProblemType::Unauthenticated, $request->getPathInfo()))->toResponse();
        }

        $selected = $this->activeTenant->select(
            $request,
            (string) $user->getKey(),
            (string) $request->string('tenant_id'),
        );

        if (! $selected) {
            // 404 for "not yours" AND for "suspended", which is the same reasoning as the
            // resolver's single null: a distinguishable answer would tell a caller which
            // tenant ids exist and which are suspended.
            return (new Problem(ProblemType::NotFound, $request->getPathInfo()))->toResponse();
        }

        return response()->json(null, 204);
    }

    /**
     * The signed-in account, and the restaurants it may act in.
     */
    #[DocResponse(200, 'The account and its restaurants.')]
    #[DocResponse(401, 'No session.', type: ProblemDocument::class)]
    public function me(Request $request): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return (new Problem(ProblemType::Unauthenticated, $request->getPathInfo()))->toResponse();
        }

        return response()->json($this->identity($user, (string) $user->getKey()));
    }

    /** @return array<string, mixed> */
    private function identity(User $user, string $userId): array
    {
        $memberships = [];

        foreach ($this->memberships->for($userId) as $membership) {
            // Only active tenants are offered. A suspended one cannot be selected, so
            // listing it would be offering a choice that is refused -- and naming it would
            // disclose that it exists and is suspended.
            if (! $membership->tenantIsActive()) {
                continue;
            }

            $memberships[] = [
                'tenant_id' => $membership->tenantId,
                'slug' => $membership->tenantSlug,
                'role' => $membership->role->value,
                'principal' => Principal::fromTenantRole($membership->role)->value,
            ];
        }

        /*
         * `active_tenant` IS ONE OF THE OFFERED MEMBERSHIPS, OR IT IS NULL.
         *
         * It used to be whatever the session held, which could name a tenant that is NOT in
         * the list above -- measured at Task 5's security review with a suspended tenant:
         * `{"memberships":[],"active_tenant":"<suspended id>"}`. Not a cross-tenant leak,
         * because the caller chose that tenant themselves and the resolver refuses it on
         * every other route; but a client reading `active_tenant` would offer a tenant that
         * answers 401 everywhere, with nothing in the payload explaining why.
         *
         * Filtering it against the SAME list makes the two fields consistent by
         * construction rather than by a reader noticing they should be: whatever reason
         * removes a membership from `memberships` -- suspension today, anything §2.4 adds
         * later -- removes it from here too.
         */
        $selected = $this->activeTenant->selected(request());

        $offered = array_column($memberships, 'tenant_id');

        return [
            'user' => [
                'id' => $userId,
                'name' => (string) $user->getAttribute('name'),
                'email' => (string) $user->getAttribute('email'),
                'is_platform_admin' => $user->isPlatformAdmin(),
                'two_factor_enabled' => $user->getAttribute('two_factor_confirmed_at') !== null,
                'passkeys_enabled' => $user->hasPasskeysEnabled(),
            ],
            'memberships' => $memberships,
            'active_tenant' => in_array($selected, $offered, true) ? $selected : null,
        ];
    }
}
