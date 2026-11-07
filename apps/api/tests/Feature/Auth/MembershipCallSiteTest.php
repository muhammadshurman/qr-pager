<?php

declare(strict_types=1);

use App\Platform\Auth\Memberships;

/** The one pattern, defined once so the guard and its own control cannot diverge. */
// ⚠ `holdsAnyMembership` JOINED THE ALTERNATION AT TASK 12 (`R-244`), AND IT HAD TO.
// This pattern ENUMERATES the method names, so a new public read is invisible to the
// call-site case until it is added here -- and invisible is the one thing this guard must
// not be. Measured: with the new reader in place and this list unchanged, the call-site
// case reported `UserAttachment.php:131` MISSING from the live set while the call sat on
// exactly that line. A name list that silently stops matching is `§4a`'s guard-goes-quiet
// shape, and the surface case above is what forces this edit: it fails first.
const MEMBERSHIP_CALL = '/\bmemberships\s*->\s*(for|holdsAnyMembership|in|sole)\s*\(/';

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

it('exposes exactly the four public reads its docblock describes', function (): void {
    /*
     * ⚠⚠ A FOURTH READER ARRIVED AT TASK 12 — `holdsAnyMembership()`, `R-244` — AND THIS
     * GUARD'S QUESTION IS ANSWERED FOR IT HERE, which is what its failure message demands.
     *
     * **WHERE ITS USER ID COMES FROM.** The same place `UserAttachment`'s other call took it:
     * `users.id`, from an EXACT-MATCH lookup on a lower-cased email two statements earlier in
     * the same method. Never a request parameter, never a path segment, never a tenant-scoped
     * row. `users` is global and RLS-free (`§2.4`).
     *
     * **WHY IT EXISTS WHEN `for()` ANSWERS THE SAME QUESTION.** Because `for()` MEMOISES, and
     * the consent refusal must not. Measured 2026-11-07 across two real `POST /v1/members` in
     * one test, traced inside `refusalFor()`: on the second request
     * `public.user_memberships()` returned **1 row** on that very connection while `for()`
     * returned **none** — the first request's own consent check had memoised `[]` for that id,
     * and the attach happened after it. So one question had two answers depending on container
     * lifetime: refused in production, `201` in the suite. Muhammad ruled the DIVERGENCE the
     * defect rather than the status.
     *
     * **WHAT IT READS, AND IT IS LESS THAN `for()` DOES.** `select 1 … limit 1`. The function
     * returns another user's tenant ids, slugs and names; this reader takes none of them, so
     * the caller cannot disclose another tenant's identity even by accident —
     * `MemberConsentTest` asserts that property from the outside.
     *
     * **IT ADDS NO CROSS-TENANT PATH.** Same function, same connection, same named path
     * (`§4 › Tenancy`, *"a user's own memberships"*). The list stays at FOUR, and the call-site
     * count below is unchanged at ten: one call became another on the same line.
     */
    $public = array_values(array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        array_filter(
            (new ReflectionClass(Memberships::class))->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === Memberships::class
                && $method->getName() !== '__construct',
        ),
    ));

    sort($public);

    expect($public)->toBe(['for', 'holdsAnyMembership', 'in', 'sole'],
        "Memberships' public surface changed. Every reader here is backed by the named "
        .'cross-tenant path user_memberships(uuid), so a new one needs its own argument '
        .'about where its user id comes from -- and this class\'s docblock, QRPAGER-12 '
        .'§10.3(c) and PLAN.md §2.8 all describe this set.',
    );

    // AND EVERY ONE OF THEM TAKES A USER ID FIRST. That is the shape the bound depends on:
    // a reader whose first argument were a tenant, a filter or a list would be a different
    // kind of question being asked of a bypassing function.
    foreach (['for', 'holdsAnyMembership', 'in', 'sole'] as $name) {
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
    //
    // ⚠⚠ **A GENUINELY NEW CALLER AT `R-233`, 2026-11-04 — THE FIRST SINCE THIS LIST WAS
    // WRITTEN, AND THIS GUARD'S QUESTION ANSWERED FOR IT.**
    // `app/Platform/Tenancy/UserAttachment.php:106`.
    //
    // **WHERE ITS USER ID COMES FROM**, which is what the failure message demands before the
    // list is touched: from `users.id`, read by an EXACT-MATCH lookup on a lower-cased email
    // address two statements earlier in the same method —
    // `select id, is_platform_admin from users where email = ?`. It is never a request
    // parameter, never a path segment and never a tenant-scoped row: `users` is global and
    // RLS-free (`§2.4`), and the address arrives validated by `StoreMemberRequest` (members
    // surface) or by the provisioning form.
    //
    // **WHY IT CALLS THE PATH AT ALL.** `§4 › Security` requires that an address already
    // holding ANY membership be refused rather than attached, and that question cannot be
    // answered inside one tenant's scope. The path `§4 › Tenancy` names — *"a user's own
    // memberships"* — is the one that answers it, and `TenantProvisioning` had already been
    // calling it for the same rule since Task 6 with the same reasoning recorded in its
    // docblock. `R-233` moved that rule into one class with two callers, so this list GAINS
    // one entry and `TenantProvisioning`'s own call disappears from it: the count of
    // cross-tenant call sites is unchanged at nine, and what changed is which file holds it.
    //
    // **WHAT IT DOES WITH THE RESULT.** `=== []` and nothing else. The function returns
    // another user's tenant ids, slugs and names; this caller reads only whether the list is
    // empty, returns an enum case, and `MemberConsentTest` asserts the refusal body names no
    // tenant.
    //
    // ⚠ **BOTH `AuthController` LINES MOVED A THIRD TIME AT TASK 10e AND NO CALLER CHANGED
    // AGAIN: `:102` -> `:103` and `:241` -> `:268`.** READ BEFORE UPDATING, which is what this
    // guard's failure message asks for, and the answers are unchanged:
    //
    //   `:103`  `login()`'s `for($userId)`, with `$userId` the id of the user Fortify just
    //           authenticated. Moved by ONE line, by a `use App\Domain\Tenancy\TenantRole;`
    //           import at the top of the file.
    //   `:268`  `identity()`'s `for($userId)`, with `$userId` the value the guard supplied to
    //           `identityResponse()`. Moved by 27 lines, by a 26-line INTERNAL NOTES comment
    //           above the method body explaining `memberships[].grantable_roles` — 10e's new
    //           field — and the same +1 import.
    //
    // Neither takes a tenant or a user id from request input, which is the question this test
    // exists to ask. `git diff --unified=0` on that file reports hunks at `+9`, `+213` and
    // `+283` — all three ABOVE or AFTER the two calls and none of them at a call — which is the
    // mechanical form of *no caller changed*.
    //
    // That is three rounds of this guard firing on comments and imports, which is the friction
    // the note above predicted after the third time. It stays as it is for that note's reason.
    expect(membershipCallSites())->toBe([
        'app/Platform/Auth/ActiveTenant.php:111',
        'app/Platform/Auth/AuthenticatedMembership.php:53',
        'app/Platform/Auth/AuthenticatedMembership.php:54',
        'app/Platform/Auth/AuthenticatedTenantResolver.php:150',
        'app/Platform/Auth/AuthenticatedTenantResolver.php:162',
        'app/Platform/Auth/AuthenticatedTenantResolver.php:167',
        'app/Platform/Auth/Http/AuthController.php:103',
        'app/Platform/Auth/Http/AuthController.php:268',
        'app/Platform/Auth/RequestPrincipal.php:85',
        'app/Platform/Tenancy/UserAttachment.php:131',
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
