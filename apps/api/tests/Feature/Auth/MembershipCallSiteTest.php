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

it('is called from exactly the five places that pass a server-derived user id', function (): void {
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
    // NONE takes a tenant from request input. `PagerIdorTest` drives five caller-supplied
    // tenant shapes at the staff surface and requires 404 from each, which is the runtime
    // half of the same statement.
    expect(membershipCallSites())->toBe([
        'app/Platform/Auth/ActiveTenant.php:111',
        'app/Platform/Auth/AuthenticatedTenantResolver.php:150',
        'app/Platform/Auth/AuthenticatedTenantResolver.php:162',
        'app/Platform/Auth/AuthenticatedTenantResolver.php:167',
        'app/Platform/Auth/Http/AuthController.php:102',
        'app/Platform/Auth/Http/AuthController.php:189',
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
