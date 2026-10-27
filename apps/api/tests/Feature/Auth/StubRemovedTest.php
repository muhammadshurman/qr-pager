<?php

declare(strict_types=1);

use App\Platform\Auth\AuthenticatedTenantResolver;
use App\Platform\Http\TenantResolver;

/*
 * ================================================================================
 * R-035 / PLAN.md §3 row 5 -- THE PRE-TASK-5 AUTH STUB IS GONE
 * ================================================================================
 *
 * PROJECT_INSTRUCTIONS.md §4 › Tenancy: "A stub that survives Task 5 is a Critical
 * finding." R-035 is the row recording that NOTHING MECHANICAL would have caught it --
 * QRPAGER-9 §6.4 claimed deptrac would, and deptrac's rules govern who may IMPORT a class,
 * not whether it still exists. The only thing that would have noticed was
 * TenantResolutionTest's glob() of one directory, which fires only if Task 5's own resolver
 * happens to land in that same directory. It does land there -- and that is exactly why
 * that check was worth nothing.
 *
 * So the gate names three assertions, and this file is them. Each fails WHEREVER the stub
 * lands, which is the property the glob did not have.
 *
 * THE CLASS NAME IS SPELLED ONCE IN THIS REPOSITORY, in the constant below, and the
 * assertions compare against it. It is deliberately absent from every file under app/:
 * assertion 1 greps that tree, so a comment naming the deleted class in production code
 * would fail this file. That is the assertion working.
 */

/** The deleted class, by name. The one place this string exists outside §5 and the ticket. */
const DELETED_STUB = 'HarnessTenantResolver';

it('has no file for the deleted stub, wherever it might have been put', function (): void {
    // Not a check for one path: a search of the whole tree, so moving the class rather
    // than deleting it fails too. The gate's literal clause is that
    // app/Platform/Auth/HarnessTenantResolver.php does not exist; this is strictly
    // stronger and covers the literal case.
    $base = dirname(__DIR__, 3);

    $found = [];

    foreach (['app', 'database', 'routes', 'config', 'bootstrap'] as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base.'/'.$dir));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php'
                && str_contains($file->getFilename(), DELETED_STUB)) {
                $found[] = str_replace($base.'/', '', $file->getPathname());
            }
        }
    }

    expect($found)->toBe([], 'the pre-Task-5 auth stub still has a file: '.implode(', ', $found));
});

it('mentions the deleted stub nowhere in production code, not even in a comment', function (): void {
    // The ticket's §6 clause: "A grep over app/ for the stub's class name returns ZERO
    // hits, asserted by a test, not by reading." Widened to the other three production
    // trees, because a binding in a provider or a route file would be just as alive.
    //
    // A comment hit is a real failure and not pedantry: the only reason to name the class
    // in production code is that something still refers to it, and a stale reference in a
    // comment is how a reader concludes the stub is still the mechanism.
    $base = dirname(__DIR__, 3);
    $hits = [];

    foreach (['app', 'database', 'routes', 'config', 'bootstrap'] as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base.'/'.$dir));

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if ($contents !== false && str_contains($contents, DELETED_STUB)) {
                $hits[] = str_replace($base.'/', '', $file->getPathname());
            }
        }
    }

    expect($hits)->toBe([], 'production code still names the deleted stub: '.implode(', ', $hits));
});

it('resolves the TenantResolver binding to the real implementation', function (): void {
    // The gate's second clause. Resolved through the container rather than read out of
    // AppServiceProvider, because what matters is what a request gets -- a binding
    // overridden in a test, a service provider ordering problem or a stale cached config
    // would all show up here and in none of the other two assertions.
    $resolved = app(TenantResolver::class);

    expect($resolved)->toBeInstanceOf(AuthenticatedTenantResolver::class)
        ->and($resolved::class)->not->toContain(DELETED_STUB);
});

it('references the stub binding constant in no test either', function (): void {
    // The gate's third clause: "no test references HarnessTenantResolver::BINDING".
    //
    // Its point is that the stub's own ENTRY POINT is gone, not just the class: a test
    // still naming the constant means a test still expects to steer tenancy by binding a
    // container value, which is the mechanism this task removed. Searched over tests/
    // excluding this file, which is the one place the name is allowed to appear.
    $base = dirname(__DIR__, 2);
    $hits = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        if ($file->getRealPath() === realpath(__FILE__)) {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if ($contents !== false && str_contains($contents, DELETED_STUB)) {
            $hits[] = str_replace($base.'/', '', $file->getPathname());
        }
    }

    expect($hits)->toBe([], 'a test still references the deleted stub: '.implode(', ', $hits));
});
