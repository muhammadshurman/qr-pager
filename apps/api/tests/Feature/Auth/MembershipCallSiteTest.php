<?php

declare(strict_types=1);

use App\Platform\Auth\Memberships;

/** The one pattern, defined once so the guard and its own control cannot diverge. */
const MEMBERSHIP_CALL = '/\bmemberships\s*->\s*(for|in|sole)\s*\(/';

/*
 * ================================================================================
 * "A USER'S OWN MEMBERSHIPS" — THE NAMED CROSS-TENANT PATH, BOUNDED BY ITS CALL SITES
 * ================================================================================
 *
 * `PROJECT_INSTRUCTIONS.md §4 › Tenancy` names four cross-tenant reads, and this is one of
 * them: `public.user_memberships(uuid)` runs `SECURITY DEFINER` with the tenant bypass on,
 * so it is the one seam in the application that sees `tenant_users` across tenants. Adding a
 * fifth named path needs a rank-2 edit first; keeping this one narrow is the whole of why
 * that has not been needed.
 *
 * THE SECURITY PROPERTY IS NOT A COUNT. `Memberships`' docblock, `QRPAGER-12 §10.3(c)` and
 * `PLAN.md §2.8` all claimed the class had "one public read" and "one call site", asserted
 * by a `MembershipScopeTest`. Task 5's QA review measured three public reads, five call
 * sites, and no such test anywhere in the tree. The property that actually matters held
 * every time -- every caller passes a server-derived id -- but nothing was holding it, and
 * three documents agreed on a number that was wrong, which is the more interesting failure:
 * a claim everybody had read and nobody had run.
 *
 * So this file pins the two things that can drift, and deliberately not the number:
 *
 *   1. the PUBLIC SURFACE of the class, so a fourth reader cannot appear unnamed;
 *   2. the SET OF CALL SITES, so a sixth caller fails the suite and has to be read.
 *
 * Neither is a security control by itself. Both are the mechanism that makes somebody look
 * at the next caller, which is the only thing that can check "the id came from the guard".
 */

/** Every `app/` line that calls a public read on this class, as `file:line`. */
function membershipCallSites(): array
{
    $root = base_path('app');
    $found = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = 'app'.substr($file->getPathname(), strlen($root));

        // The class's own file is not a call site.
        if ($relative === 'app/Platform/Auth/Memberships.php') {
            continue;
        }

        foreach (explode("\n", (string) file_get_contents($file->getPathname())) as $i => $line) {
            // Matches a CALL and not a mention. LOWERCASE `memberships` followed by `->`,
            // which is a property or a variable; the class name `Memberships` is capitalised
            // and is always reached with `::` or `new`, so the two comments in `app/` that
            // name `Memberships::for()` and `Memberships::sole()` are not call sites and the
            // container binding `new Memberships(` is not one either. That near-miss is
            // exactly what a grep-based guard has to get right or it gets switched off, so
            // the last case in this file drives both halves of it.
            if (preg_match(MEMBERSHIP_CALL, $line) === 1) {
                $found[] = $relative.':'.($i + 1);
            }
        }
    }

    sort($found);

    return $found;
}

it('exposes exactly the three public reads its docblock describes', function (): void {
    $public = array_values(array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        array_filter(
            (new ReflectionClass(Memberships::class))->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === Memberships::class
                && $method->getName() !== '__construct',
        ),
    ));

    sort($public);

    expect($public)->toBe(['for', 'in', 'sole'],
        "Memberships' public surface changed. Every reader here is backed by the named "
        .'cross-tenant path user_memberships(uuid), so a new one needs its own argument '
        .'about where its user id comes from -- and this class\'s docblock, QRPAGER-12 '
        .'§10.3(c) and PLAN.md §2.8 all describe this set.',
    );

    // AND EVERY ONE OF THEM TAKES A USER ID FIRST. That is the shape the bound depends on:
    // a reader whose first argument were a tenant, a filter or a list would be a different
    // kind of question being asked of a bypassing function.
    foreach (['for', 'in', 'sole'] as $name) {
        $parameters = (new ReflectionMethod(Memberships::class, $name))->getParameters();

        expect($parameters)->not->toBeEmpty($name);
        expect($parameters[0]->getName())->toBe('userId',
            "Memberships::{$name}()'s first parameter is not a user id",
        );
        expect((string) $parameters[0]->getType())->toBe('string', $name);
    }
});

it('is called from exactly the nine lines, in five files, that pass a server-derived id', function (): void {
    // Each of these was read at Task 5's QA review and again here. What makes each one
    // safe is named, because the list is worthless if the next person only counts it:
    //
    //   AuthController:102   $userId from the credential verification that just succeeded
    //   AuthController:189   $userId from $request->user()->getAuthIdentifier()
    //   ActiveTenant:111     $userId from the guard, $tenantId from the SERVER-SIDE session
    //   AuthenticatedTenantResolver:150  the token's own pin, re-verified against this id
    //   AuthenticatedTenantResolver:162  the session selection, re-verified against it
    //   AuthenticatedTenantResolver:167  sole(), no tenant input at all
    //   RequestPrincipal:85  the tenant RequireTenantScope already resolved and verified
    //
    // TWO JOINED AT TASK 7, and both were read before this list was updated, which is
    // what the failure message below asks of whoever changes it:
    //
    //   AuthenticatedMembership:53  in(), with $userId from the guard's own
    //                               getAuthIdentifier() and $selected from the
    //                               SERVER-SIDE session -- the identical pair
    //                               AuthenticatedTenantResolver:162 passes, and it is
    //                               re-verified against the live membership set on every
    //                               call rather than believed
    //   AuthenticatedMembership:54  sole(), no tenant input at all, the same shape as
    //                               AuthenticatedTenantResolver:167
    //
    // The new caller exists because Task 7's tenant-scoped panel needs the ROLE as well
    // as the tenant (D1 restricts editing to `owner` and `manager`), and widening
    // TenantResolver's shape would have touched four surfaces built on it at Tasks 2-5.
    // It adds NO new way to name a tenant: both arguments come from the same two places
    // the resolver's already do.
    //
    // NONE takes a tenant from request input. `PagerIdorTest` drives five caller-supplied
    // tenant shapes at the staff surface and requires 404 from each, which is the runtime
    // half of the same statement.
    //
    // ⚠ ONE LINE HAS NOW MOVED TWICE AT TASK 10b AND NO CALLER CHANGED EITHER TIME:
    // `AuthController:189` -> `:216` (the `R-222` internal-notes comment) -> `:241` (the
    // `identityResponse()` helper `§11` SECURITY M-2's cache header needed). Both shifts are
    // edits ABOVE the call, and in both the answer to this test's own question is unchanged.
    // READ BEFORE UPDATING, which is what the failure message below asks for, and the answer
    // is the one this list already records for that site: it is `identity()`'s `for($userId)`
    // with `$userId` the value `me()` took from the guard, unchanged in shape and in
    // provenance. What moved it is a 28-line INTERNAL NOTES comment added above it, because
    // `R-222`'s reasoning could not stay where it was -- a comment above a key in that method's
    // array is PUBLISHED as the property's description in `docs/openapi.json` (`R-052`), and
    // `OpenApiSpecTest` caught the whole paragraph in the spec.
    //
    // ⚠ AND THE SHAPE OF THIS GUARD IS WORTH A SENTENCE, since it fired for a comment: a
    // `file:line` pin moves on any edit above a call site, so most of its reds will be shifts
    // rather than new callers. That is friction by design -- the message demands a human read
    // the moved line -- but it is also how a pin gets updated mechanically after the third
    // time. Left as it is, because the mechanism belongs to whoever owns this guard and 10b
    // came to build a board; recorded in `§4a`'s 10b notes rather than changed in passing.
    expect(membershipCallSites())->toBe([
        'app/Platform/Auth/ActiveTenant.php:111',
        'app/Platform/Auth/AuthenticatedMembership.php:53',
        'app/Platform/Auth/AuthenticatedMembership.php:54',
        'app/Platform/Auth/AuthenticatedTenantResolver.php:150',
        'app/Platform/Auth/AuthenticatedTenantResolver.php:162',
        'app/Platform/Auth/AuthenticatedTenantResolver.php:167',
        'app/Platform/Auth/Http/AuthController.php:102',
        'app/Platform/Auth/Http/AuthController.php:241',
        'app/Platform/Auth/RequestPrincipal.php:85',
    ], 'the set of callers of the named cross-tenant membership path changed -- read the '
       .'new one and say where its user id comes from before updating this list');
});

it('finds a planted call site, so the grep cannot pass by matching nothing', function (): void {
    // THE CONTROL THE LIST ABOVE NEEDS. A regex that silently stopped matching would make
    // the previous case pass with an empty set after the expectation was updated once, and
    // the guard would be inert exactly when a caller was added. §4a records four defects of
    // that class on this branch, so the pattern is driven against both shapes here.
    // THE SAME CONSTANT THE GUARD USES. A second copy of the pattern here would let the
    // guard rot while its control stayed green, which is the failure this case exists to
    // make impossible.
    $pattern = MEMBERSHIP_CALL;

    foreach ([
        '        $membership = $this->memberships->in($userId, $tenantId);',
        '        $all = $this->memberships->for($userId);',
        '        return $this->memberships->sole($userId);',
        '    $rows = $memberships->for($id);',
    ] as $shouldMatch) {
        expect(preg_match($pattern, $shouldMatch))->toBe(1, "missed a real call: {$shouldMatch}");
    }

    // And it does NOT match the two mentions in comments that are not calls, which is why
    // the list above is seven lines and not nine.
    foreach ([
        '            // property error at runtime in a loop. Same shape as Memberships::for().',
        '        // Never "the first of several" -- Memberships::sole() refuses that and says why.',
        '        $this->app->scoped(Memberships::class, static fn ($app): Memberships => new Memberships(',
    ] as $shouldNotMatch) {
        expect(preg_match($pattern, $shouldNotMatch))->toBe(0, "false positive on: {$shouldNotMatch}");
    }
});
