<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * How a bearer token says which tenant it is good for.
 *
 * ================================================================================
 * WHY THIS CLASS EXISTS — the repurposed deptrac rule caught its own author
 * ================================================================================
 *
 * The prefix started life as a constant on `App\Platform\Auth\DeviceToken`, and
 * `App\Features\Devices\DeviceTokens` read it from there to build and match the ability
 * string. deptrac refused that, four times, with the rule Task 5 had just repurposed:
 * *Features must not depend on `App\Platform\Auth`.* The guard was right and the import was
 * the thing to change, not the rule — a feature module reaching into the auth layer for a
 * string constant is exactly the coupling that rule exists to prevent, and the next thing it
 * reaches for would not be a constant.
 *
 * So the ENCODING lives here, in `App\Domain`, with no framework and no database, beside the
 * `Ability` vocabulary it shares a string space with. Both sides read it: the auth layer to
 * extract a pin from a presented token, the devices feature to write one into a new token.
 * One source, which is `§6.2`'s rule applied to a format rather than to an enum.
 *
 * ================================================================================
 * WHAT A PIN IS, AND WHAT IT IS NOT
 * ================================================================================
 *
 * `personal_access_tokens` has no `tenant_id` column and `PLAN.md §2.4` records why one is
 * not available: a `tenant_id` makes it a tenant table, C4 then forces RLS on it, and the
 * row becomes invisible to the lookup that has to happen before any tenant is known.
 *
 * **So a pin is a CLAIM and never an authority.** It is used only to narrow: the resolver
 * resolves the tenant from the holder's own memberships first and then requires the pin to
 * equal it. A pin naming a tenant its holder is not a member of resolves nothing, and a pin
 * naming a tenant the holder has SINCE LEFT stops working the moment the membership goes,
 * because the membership set is re-read every request.
 */
final class TenantPin
{
    /**
     * The ability prefix. `tenant:` rather than something unlikely to collide, because it
     * shares a string space with `Ability`'s values and every one of those is
     * `noun:verb` — so a pin is visibly not a capability, and a capability called
     * `tenant:something` would be the collision to avoid when adding one.
     */
    public const PREFIX = 'tenant:';

    /** The ability string that pins a token to this tenant. */
    public static function for(string $tenantId): string
    {
        return self::PREFIX.$tenantId;
    }

    /** Whether this ability string is a pin rather than a capability. */
    public static function isPin(string $ability): bool
    {
        return str_starts_with($ability, self::PREFIX);
    }

    /**
     * The tenant id a pin names, or null when the string is not a pin at all.
     *
     * Note what this does NOT do: it does not validate the id's shape. A caller that is
     * about to compare the result against a real tenant must decide what a malformed pin
     * means, and the two possible answers — "unpinned, so narrow nothing" and "pinned to
     * something impossible, so match nothing" — are very different. Returning the raw text
     * keeps that decision at the call site, where `App\Platform\Auth\DeviceToken` makes it
     * explicitly and chooses the second.
     */
    public static function tenantFrom(string $ability): ?string
    {
        return self::isPin($ability) ? substr($ability, strlen(self::PREFIX)) : null;
    }
}
