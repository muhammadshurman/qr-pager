<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
 * ================================================================================
 * EVERY PATH A `§12` SCREEN SPEC NAMES MUST RESOLVE — `QRPAGER-32`, the panel half
 * ================================================================================
 *
 * `QRPAGER-32` measured the drift this exists to catch: `QRPAGER-25-precoding.md §12` headed
 * screen S1 *"Staff sign-in — `/staff/sign-in`"* and no such route has ever existed, from
 * 10a's first commit until 2026-11-03. Nothing noticed, because nothing read the spec.
 *
 * That ticket closed the Next.js half: `apps/web/test/staff-build-surface.test.ts` parses the
 * two staff tickets' `## S<n>` headings and requires every `/staff` path they name to have a
 * prerendered document. **This is the half it did not cover**, found when Muhammad asked for
 * the check again on 2026-11-04 and the question was widened past the staff tickets:
 * `PROJECT_INSTRUCTIONS.md §12` heads three screens with panel paths —
 * `12.1 /studio`, `12.2 /studio/media`, `12.3 /studio/domains` — and those are Laravel routes,
 * so a Next build artefact cannot speak for them.
 *
 * ⚠ **IT PARSES THE SPEC RATHER THAN RESTATING IT**, for the reason the TS half gives: a list
 * of paths copied into a test is a third source, and the failure mode here is exactly two
 * sources left to agree by hand. The heading shape is the contract.
 *
 * ⚠ **AND IT IS GREEN TODAY, WHICH IS WHAT A GUARD LOOKS LIKE BEFORE IT IS NEEDED.** All three
 * resolve. The value is that renaming `/studio/media` without touching `§12`, or heading a
 * `§12` screen with a path nobody mounted, now fails here instead of in a client's browser.
 */

/** Every `§12` heading's backticked panel path, read out of the committed instructions. */
function specPanelPaths(): array
{
    /*
     * ⚠ THE SPECS ARE IN THE TICKETS, NOT IN `PROJECT_INSTRUCTIONS.md`, AND THIS FILE LOOKED
     * IN THE WRONG PLACE TWICE BEFORE MEASURING IT. `§12` in the instructions states the BAR;
     * each ticket's own `§12` states its SCREENS, which is where a path appears. The panel
     * screens are headed in `QRPAGER-14.md` — `### 12.1 \`/studio\``, `12.2`, `12.3` — and
     * `PROJECT_INSTRUCTIONS.md` contains no `12.1` at all.
     *
     * Both wrong turns were caught by the non-vacuity case below rather than by reading:
     * first a `strstr` slice that could return `false`, then a heading sweep over a file with
     * no screen headings in it. A sweep over an empty list passes, which is why that case
     * exists and why it is first.
     */
    $specs = glob(base_path('../../docs/tickets/*.md')) ?: [];

    expect($specs)->not->toBe([], 'no ticket documents were found, so there is no spec to read');

    $instructions = '';

    foreach ($specs as $file) {
        $instructions .= (string) file_get_contents($file)."\n";
    }

    /*
     * Every heading of every ticket, so a screen spec cannot dodge this by living in a
     * ticket nobody listed here.
     */
    $paths = [];

    foreach (explode("\n", $instructions) as $line) {
        if (preg_match('/^#{2,4}\s/', $line) !== 1) {
            continue;
        }

        // A heading that says a screen is not a route may name none; the TS half enforces
        // that for the staff tickets and it has no instance here.
        preg_match_all('/`(\/(?:studio|platform)[a-z0-9\/-]*)`/i', $line, $found);

        foreach ($found[1] as $path) {
            $paths[] = rtrim($path, '/');
        }
    }

    return array_values(array_unique($paths));
}

/** Every GET path the live router serves, normalised the way a spec writes one. */
function routerGetPaths(): array
{
    $uris = [];

    /** @var RoutingRoute $route */
    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $uris[] = '/'.ltrim($route->uri(), '/');
    }

    return array_values(array_unique($uris));
}

it('finds panel paths in the ticket specs at all, so the sweep below has a subject', function (): void {
    // The non-vacuity guard, and it is the whole reason this file is not silently green: a
    // regex that stopped matching, or a `§12` that was renumbered, would otherwise make the
    // case below a loop over nothing.
    expect(specPanelPaths())->toContain('/studio')
        ->and(count(specPanelPaths()))->toBeGreaterThanOrEqual(3);
});

it('serves every panel path a §12 screen spec names, so a rename cannot leave the spec behind', function (): void {
    $served = routerGetPaths();
    $missing = [];

    foreach (specPanelPaths() as $path) {
        if (! in_array($path, $served, true)) {
            $missing[] = $path;
        }
    }

    expect($missing)->toBe(
        [],
        'a ticket\'s §12 heads a screen with a path the router does not serve — the '
        .'QRPAGER-32 drift, on the panel side',
    );
});

it('does not count a path that is only a PREFIX of a real route as served', function (): void {
    /*
     * The control on the matcher, because an `in_array` over whole paths is doing real work
     * only if a near-miss fails. `/studio/med` is a prefix of `/studio/media` and is not a
     * route: a sweep built on `str_starts_with` would call it served, and would then accept
     * `/staff/sign-in` the moment any `/staff/sign-in-something` existed.
     */
    expect(routerGetPaths())->toContain('/studio/media')
        ->and(in_array('/studio/med', routerGetPaths(), true))->toBeFalse();
});
