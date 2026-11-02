<?php

declare(strict_types=1);

namespace App\Platform\Auth;

use App\Domain\Auth\Ability;

/**
 * Which `Ability` each named `/v1` staff route requires. ONE SOURCE, read by the
 * middleware that enforces it and by the test that proves the matrix is complete.
 *
 * ================================================================================
 * WHY A MAP AND NOT A MIDDLEWARE PARAMETER
 * ================================================================================
 *
 * The obvious shape is `->middleware('ability:pager:notify')` on each route. That spreads
 * the authorisation contract across the route file as string literals, and — the part that
 * matters — **it makes "every route is covered" unobservable**: a route with no `ability:`
 * parameter is simply a route with less middleware, which no test can distinguish from a
 * route that was meant to be open.
 *
 * With the requirement in a map keyed by route name, both halves become mechanical:
 *
 *   - `RequireAbility` looks the current route's name up here and **fails closed** when it
 *     finds nothing. A new staff route is refused until somebody adds it, rather than
 *     served without a check.
 *   - `RoleMatrixTest` iterates the router and fails if any `/v1` staff route is missing
 *     from this map. That is what makes `PLAN.md §3` row 5's amended "full role-matrix
 *     test" mean *every route* rather than *the routes its author remembered* — the
 *     unfalsifiability `§3.1` row 5 rejected.
 *
 * ================================================================================
 * THE THREE SURFACES, AND WHY TWO OF THEM ARE ABSENT FROM THIS MAP
 * ================================================================================
 *
 * `§4 › Tenancy` has two authentication surfaces that must never be confused, and the
 * probes are a third thing again. Only the staff surface appears here:
 *
 *   - **Staff** — behind `RequireTenantScope`, a user with a membership. Mapped below.
 *   - **Customer** — the QR token path. A customer has no account, no membership and no
 *     role, so an `Ability` is meaningless for them; their authorisation IS the token, and
 *     `CustomerPagerController` checks it. Listing them here would imply a principal they
 *     do not have.
 *   - **The auth surface itself** — login, the 2FA challenge, the passkey ceremony. These
 *     run BEFORE a principal exists, so requiring an ability of them is circular.
 *
 * `RoleMatrixTest` enumerates all three sets and requires their union to be the whole `/v1`
 * route table, so a route that is in none of them fails the suite. That is the same
 * closed-world shape `RouteSurfaceTest` already uses for the unversioned probes.
 */
final class RouteAbilities
{
    /**
     * Route name → the ability a caller must hold in the resolved tenant.
     *
     * @var array<string, Ability>
     */
    private const MAP = [
        // ---------------------------------------------------------------- Task 3 and 4's
        //
        // Mapped at Task 5 rather than at Task 3, because there was no principal to
        // authorise until now: these five routes have been behind the deny-by-default
        // tenant guard since they were written, which answered "is there a tenant" and
        // never "may THIS actor do this".
        //
        // `store` is PagerCreate and the four transitions split two ways: marking ready and
        // re-notifying are PagerNotify, completing and cancelling are PagerResolve. The
        // split is not decoration -- it is what lets a KDS device hold neither while still
        // reading the board, and what Task 10 will need when the kitchen screen and the
        // front counter stop being the same person.
        'pager.sessions.store' => Ability::PagerCreate,
        'pager.sessions.ready' => Ability::PagerNotify,
        'pager.sessions.renotify' => Ability::PagerNotify,
        'pager.sessions.complete' => Ability::PagerResolve,
        'pager.sessions.cancel' => Ability::PagerResolve,

        // ---------------------------------------------------------------- Task 5's own
        'members.index' => Ability::MembersRead,
        'members.store' => Ability::MembersWrite,
        'members.update' => Ability::MembersWrite,
        'members.destroy' => Ability::MembersWrite,

        'devices.store' => Ability::DevicesWrite,
        'devices.destroy' => Ability::DevicesWrite,

        // ---------------------------------------------------------------- Task 10a's
        //
        // Listing the screens is on `devices:write` and NOT on a new `devices:read`: the
        // holder set would be identical, and a second ability with the same holders is a
        // distinction with no difference. `DeviceController::index()` carries the argument.
        'devices.index' => Ability::DevicesWrite,

        // The restaurant the caller is signed in to. `tenant:read` is the FLOOR for a
        // person -- staff, manager and owner all hold it -- because a shell that cannot
        // say which restaurant you are in fails `§12`'s zero-technical-background bar.
        // `Principal::Device` does NOT hold it; `Ability::TenantRead`'s docblock has the
        // reason and it is not an oversight.
        'tenant.show' => Ability::TenantRead,

        // The board a KDS screen reads. The ONE ability a device token may carry, which is
        // why this route exists at Task 5 rather than at Task 10: without it, "device token
        // scope cannot escalate" would be a claim about a token that can reach nothing, and
        // a token that can reach nothing cannot be shown not to escalate.
        'pager.sessions.index' => Ability::PagerRead,

        // ---------------------------------------------------------------- Task 10d's
        //
        // ⚠ `pager:history` AND NOT `pager:read`, AND THE DIFFERENCE IS A KITCHEN SCREEN.
        // `pager:read` is the only ability `Ability::issuableToDevice()` will issue to a device
        // token, and `RequestPrincipal::may()` requires BOTH the matrix and the token's own
        // abilities -- so this route on `pager:read` would be reachable by every wall-mounted
        // screen in every kitchen, reading the restaurant's whole order record on a thirty-day
        // credential. The live board and the historical record are two reads with two
        // audiences; `Ability::PagerHistory`'s docblock has the full argument.
        'pager.sessions.history' => Ability::PagerHistory,

        // Re-issuing a sheet is `pager:create`'s act: a CSPRNG draw that mints a credential.
        // Not `pager:notify` -- it notifies nobody -- and not a new ability, because the holder
        // set would be identical to `pager:create`'s and a second ability with the same holders
        // is a distinction with no difference (`devices.index`'s own reasoning, one route over).
        'pager.sessions.reissue' => Ability::PagerCreate,
    ];

    /** The ability this route name requires, or null when the route is not mapped. */
    public static function for(string $routeName): ?Ability
    {
        return self::MAP[$routeName] ?? null;
    }

    /**
     * Every mapped route name.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * The whole map, as the test reads it.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return array_map(static fn (Ability $a): string => $a->value, self::MAP);
    }
}
