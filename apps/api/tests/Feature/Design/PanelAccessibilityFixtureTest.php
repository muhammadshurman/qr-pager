<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use App\Models\User;
use App\Platform\Panel\PanelRoutes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LandingFixtures;

/*
 * ================================================================================
 * THE BROWSER FIXTURE DESCRIBES MARKUP FILAMENT ACTUALLY EMITS — R-118, 8b's share
 * ================================================================================
 *
 * `browser/panel.spec.ts` measures R-118's four computed-style clauses against
 * `browser/fixtures/panel.html`, whose class strings were copied from a real render of
 * `/platform/restaurants`. A copied string is a second source, and the failure mode is the
 * worst kind: if Filament renames a class, the fixture keeps measuring an element nothing
 * styles, every clause goes green, and the panel is unmeasured.
 *
 * So this holds the fixture to the render. Every `data-fixture` element's class list must
 * appear in a document the panel actually serves — asserted here, where there is a session
 * and a database, because the browser harness has neither.
 */

/** A platform admin, inserted as the owner because the app role holds no INSERT on the flag. */
function fixtureAdmin(): User
{
    $id = (string) Str::uuid7();

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Fixture Admin',
        'email' => 'fixture-'.Str::lower(Str::random(10)).'@example.test',
        'is_platform_admin' => true,
    ]);

    $user = User::query()->findOrFail($id);
    assert($user instanceof User);

    return $user;
}

/** The repository root, from the Laravel app two levels under it. */
function fixtureRepoRoot(): string
{
    return dirname(base_path(), 2);
}

/**
 * Every `data-fixture` element in the browser fixture, as name => class list.
 *
 * @return array<string, string>
 */
function browserFixtureElements(): array
{
    $html = (string) file_get_contents(fixtureRepoRoot().'/browser/fixtures/panel.html');

    preg_match_all(
        '/data-fixture="([^"]+)"(?:[^>]*?)class="([^"]*)"/s',
        $html,
        $matches,
        PREG_SET_ORDER
    );

    $elements = [];

    foreach ($matches as $match) {
        $elements[$match[1]] = $match[2];
    }

    return $elements;
}

it('finds five marked elements in the fixture, so the sweep below has subjects', function (): void {
    $elements = browserFixtureElements();

    // Named exactly: an element dropped from the fixture stops being measured in the
    // browser, and a sweep over whatever remains would not notice. `narrow-button` is the
    // fifth and it exists because `.fi-btn`'s `min-width` had no test — the only button here
    // carried "New restaurant" and measured 112px wide, so nothing was ever under the bar on
    // that axis (§11 round two, QA L1). With it, removing that line measures 43x44.
    expect(array_keys($elements))
        ->toBe(['primary-button', 'narrow-button', 'icon-button', 'input', 'header-cell']);

    foreach ($elements as $name => $classes) {
        // str_contains() and not `toContain($needle, $message)`: that matcher is variadic
        // over needles and takes no message in EITHER form, so the sentence becomes a second
        // needle. FIFTH time in this task; it is an author habit rather than a gap in
        // `composer guard`, whose documented decision to flag only the silent negated form
        // has held every time.
        expect(str_contains($classes, 'fi-'))
            ->toBeTrue("the {$name} element carries no Filament class");
    }
});

it('carries only class lists the panel really renders', function (): void {
    // The six chrome routes, so an element that moved from one screen to another is still
    // found. Both panels, because the fixture's body class names the platform one and the
    // controls are shared.
    LandingFixtures::bucket();

    $admin = fixtureAdmin();
    $tenant = $this->actingForTenant(role: TenantRole::Owner);

    $rendered = '';

    // ⚠ DERIVED, NOT LISTED -- the §11 QA round's M-4, same reason as PanelTargetTest.
    $chrome = PanelRoutes::chrome();

    // ⚠ SEVEN SINCE `QRPAGER-40` ADDED `/studio/theme` — it was six, and the count is a
    // FLOOR on the derivation rather than a description of the panel, so it moves with the
    // panel and a route that stopped rendering still fails here.
    expect($chrome)->toHaveCount(7);

    foreach (array_keys($chrome) as $uri) {
        if (str_starts_with($uri, '/studio')) {
            $this->actingForTenant(tenantId: $tenant, role: TenantRole::Owner);
        } else {
            $this->actingAs($admin);
        }

        $response = $this->get($uri);

        expect($response->status())->toBe(200, "{$uri} did not render");

        $rendered .= (string) $response->getContent();
    }

    // Not vacuous: six panel pages are tens of kilobytes each.
    expect(strlen($rendered))->toBeGreaterThan(100_000);

    $missing = [];

    foreach (browserFixtureElements() as $name => $classes) {
        foreach (preg_split('/\s+/', trim($classes)) as $class) {
            if (! is_string($class) || $class === '' || ! str_starts_with($class, 'fi-')) {
                continue;
            }

            if (! str_contains($rendered, $class)) {
                $missing[] = "{$name}: {$class}";
            }
        }
    }

    expect($missing, 'these classes are in browser/fixtures/panel.html and in no rendered '.
        'panel page, so the browser checks are measuring elements Filament no longer emits')
        ->toBe([]);
});

it('keeps the document and body attributes the panel sets', function (): void {
    // The fixture's `<html class="fi">` and `fi-body` are what Filament's own selectors hang
    // off -- `html.fi` scopes the dark variant, and the body classes carry the panel id. A
    // fixture missing them would resolve different styles from the real page while every
    // element class matched.
    $html = (string) file_get_contents(fixtureRepoRoot().'/browser/fixtures/panel.html');

    // ⚠ /platform/restaurants, which is the page the fixture was copied FROM. `/platform`
    // is the dashboard and renders a different body class list, so comparing against it
    // failed on a difference that is not a drift -- the first version of this case matched
    // nothing there and reported `Expecting null not to be null`.
    $page = (string) $this->actingAs(fixtureAdmin())->get('/platform/restaurants')->getContent();

    expect($html)->toContain('class="fi"')
        ->and($page)->toContain('class="fi"');

    // Tolerant of attribute ORDER, exact about the VALUE: the class list is what Filament's
    // selectors hang off and is the thing that must not drift.
    preg_match('/<body[^>]*\sclass="([^"]*)"/', $page, $real);
    preg_match('/<body[^>]*\sclass="([^"]*)"/', $html, $fixture);

    expect($real[1] ?? null)->not->toBeNull('no body class was found in the rendered page');
    expect($fixture[1] ?? null)->toBe($real[1]);
});
