<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\FontProviders\BunnyFontProvider;
use Filament\FontProviders\LocalFontProvider;
use Filament\Pages\BasePage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\LandingFixtures;

/*
 * ================================================================================
 * THE RESTYLE IS REGISTERED, AND IT FETCHES NOTHING FROM ANYBODY ELSE
 * ================================================================================
 *
 * Task 8b turns both Filament panels from "the library default look" -- `§12`'s Tasks 6-7
 * exemption, which expires at Task 8 -- into the approved token set. Three registrations do
 * it, and this file asserts each one from a RENDERED RESPONSE rather than from the source:
 *
 *   1. `->viteTheme(...)` -- the compiled panel theme is linked, and each panel links ITS
 *      OWN, so the panels serve our stylesheet instead of Filament's precompiled vendor CSS.
 *   2. `->font(...)` -- `--font-family` is the approved Latin family, exactly.
 *   3. `provider: LocalFontProvider::class` -- and nothing is fetched from a third party.
 *
 * ================================================================================
 * (3) IS THE ONE THAT NEARLY WENT THE OTHER WAY, SO IT GETS THE LONGEST NOTE
 * ================================================================================
 *
 * `§9.1` ruled the `bunny('Instrument Sans')` helper in `vite.config.js` out: it sourced a
 * face from **fonts.bunny.net**, which is a third-party request on an authenticated surface,
 * off the approved direction, and the Laravel starter-kit face -- so it also failed `§12`'s
 * "a reviewer cannot name the UI framework from a screenshot".
 *
 * ⚠ AND THE OBVIOUS FIX RE-ADDS THE DEFECT. `HasFont::getFontProvider()` reads:
 *
 *     $this->evaluate($this->fontProvider) ?? ($this->hasCustomFontFamily()
 *         ? BunnyFontProvider::class
 *         : LocalFontProvider::class)
 *
 * so `->font('Archivo')` with no `provider:` argument defaults to
 * `BunnyFontProvider`, which emits `<link rel="preconnect" href="https://fonts.bunny.net">`
 * and a stylesheet from that host. Measured with the argument dropped: the rendered
 * `/platform` body carried both. That is removing a CDN font from one file and adding one in
 * another, in the same commit, under a message that says the CDN font is gone.
 *
 * So the assertion is on the RENDERED HTML of every panel route, it names each host, and
 * `it refuses to pass against the Bunny provider` drives the wrong configuration through the
 * same code path and watches it fail. An assertion that cannot fail is worse than an absent
 * one -- `§4a` records four instances.
 */

/**
 * Every concrete GET route either panel serves, DERIVED from the router.
 *
 * `SR-3` measured seven and corrected `§4.3`'s inventory, which had five lines wrong. The
 * enumeration case below holds that number, so a panel that gains or loses a route reddens
 * here rather than quietly going unstyled.
 *
 * @return list<string>
 */
function panelGetRoutes(): array
{
    $uris = [];

    foreach (Route::getRoutes() as $route) {
        $uri = '/'.ltrim($route->uri(), '/');

        if (! str_starts_with($uri, '/platform') && ! str_starts_with($uri, '/studio')) {
            continue;
        }

        if (! in_array('GET', $route->methods(), true) || str_contains($uri, '{')) {
            continue;
        }

        $uris[] = $uri;
    }

    $uris = array_values(array_unique($uris));
    sort($uris);

    return $uris;
}

/**
 * The panel routes that render FILAMENT CHROME, and which panel each belongs to.
 *
 * ⚠ DERIVED STRUCTURALLY, from the route's action class, and not from the rendered body --
 * which would make every assertion below circular. A Filament page route resolves to a
 * Livewire component extending `Filament\Pages\BasePage`; `/studio/preview` resolves to
 * `PreviewController`, which renders the TENANT LANDING PAGE rather than the panel layout.
 * Measured: six chrome routes at 38-160 kB each carrying `<html class="... fi ...">`, and
 * `/studio/preview` at 2177 bytes carrying none of it.
 *
 * That distinction matters because the font and theme assertions are claims about the panel
 * LAYOUT. Asserting them over the preview would be asserting them of a different document.
 *
 * @return array<string, string> uri -> panel id
 */
function panelChromeRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes() as $route) {
        $uri = '/'.ltrim($route->uri(), '/');

        if (! in_array($uri, panelGetRoutes(), true)) {
            continue;
        }

        $action = $route->getAction('controller');

        if (! is_string($action) || ! is_subclass_of($action, BasePage::class)) {
            continue;
        }

        // The panel id is the second segment of Filament's own route name --
        // `filament.<panel>.pages.media`, `filament.<panel>.resources.audit-log.index`.
        $name = (string) $route->getName();
        $segments = explode('.', $name);

        if (($segments[0] ?? '') !== 'filament' || ! isset($segments[1])) {
            continue;
        }

        $routes[$uri] = $segments[1];
    }

    ksort($routes);

    return $routes;
}

/** The platform panel's principal: a platform admin, inserted as the OWNER role (R-006). */
function themeAdmin(): User
{
    $id = (string) Str::uuid7();

    // The app role holds no INSERT on users.is_platform_admin -- security L-1, recorded at
    // length in PlatformPanelInvariantTest's fixture. So the owner connection, as there.
    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Theme Admin',
        'email' => 'theme-'.Str::lower(Str::random(10)).'@example.test',
        'is_platform_admin' => true,
    ]);

    $user = User::query()->findOrFail($id);
    assert($user instanceof User);

    return $user;
}

/** Every host a font could be fetched from by the providers Filament ships. */
function thirdPartyFontHosts(): array
{
    return ['fonts.bunny.net', 'fonts.googleapis.com', 'fonts.gstatic.com'];
}

/** The manifest entry for one panel's compiled theme, read rather than guessed. */
function panelThemeAsset(string $panel): string
{
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

    expect($manifest)->toBeArray();

    $key = "resources/css/filament/{$panel}/theme.css";
    $file = is_array($manifest) ? ($manifest[$key]['file'] ?? null) : null;

    expect($file)->toBeString("{$key} is not in the manifest -- run `composer assets`");

    return (string) $file;
}

/** Authenticate as whichever principal the panel owning $uri serves. */
function actAsPrincipalFor(string $uri): void
{
    // ⚠ IMMEDIATELY BEFORE EACH REQUEST, never once ahead of a loop over both panels. One
    // `actingAs($admin)` ahead of such a loop leaves a PLATFORM ADMIN in the session for the
    // studio routes, and the studio panel REFUSES a platform admin (StudioPanelInvariantTest
    // SR-1). Measured: all four studio routes then answered 403 with a ~125-byte
    // problem+json body carrying no font markup, so a "contains no third-party host"
    // assertion was true of an error page rather than of a styled panel.
    if (str_starts_with($uri, '/studio')) {
        test()->actingForTenant(role: TenantRole::Owner);

        return;
    }

    test()->actingAs(themeAdmin());
}

it('enumerates the panel routes, so every case below has a subject', function (): void {
    // THE POSITIVE CONTROL FOR THE DERIVATION ITSELF. Every case in this file is driven over
    // these two functions; a derivation that silently returned [] would make all of them
    // pass vacuously, which is the shape §4a records four instances of.
    //
    // ⚠ AND THE LIST IS SEVEN, NOT EIGHT. This expectation was first written with
    // `/platform/login` in it, from memory of what a Filament panel serves, and the
    // derivation reddened. There is no login route on either panel, deliberately:
    // `PlatformPanelProvider`'s docblock explains that `->login()` would be a THIRD
    // authentication surface reaching the same `users` table Task 5 moved behind a TOTP
    // challenge. So this case asserts that absence by name too.
    expect(panelGetRoutes())->toBe([
        '/platform',
        '/platform/audit-log',
        '/platform/restaurants',
        '/studio',
        '/studio/domains',
        '/studio/media',
        '/studio/preview',
    ])
        ->and(panelGetRoutes())->not->toContain('/platform/login')
        ->and(panelGetRoutes())->not->toContain('/studio/login');

    // Six of the seven render the panel layout. /studio/preview renders the tenant landing
    // page through a controller, which is why it is not a subject for the chrome cases.
    expect(panelChromeRoutes())->toBe([
        '/platform' => 'platform',
        '/platform/audit-log' => 'platform',
        '/platform/restaurants' => 'platform',
        '/studio' => 'studio',
        '/studio/domains' => 'studio',
        '/studio/media' => 'studio',
    ]);
});

it('links its OWN compiled theme on every chrome route', function (): void {
    // DISCRIMINATING, which is the point: the platform routes must link the platform theme
    // and NOT the studio one. Both files are ~692 kB of near-identical CSS differing only in
    // their @source globs, so "a theme is linked" would pass with the panels crossed over.
    LandingFixtures::bucket();

    $assets = [
        'platform' => panelThemeAsset('platform'),
        'studio' => panelThemeAsset('studio'),
    ];

    expect($assets['platform'])->not->toBe($assets['studio']);

    foreach (panelChromeRoutes() as $uri => $panel) {
        actAsPrincipalFor($uri);

        $response = $this->get($uri);
        $html = (string) $response->getContent();

        $other = $panel === 'platform' ? 'studio' : 'platform';

        expect($response->status())->toBe(200, "{$uri} did not render")
            ->and($html)->toContain('/build/'.$assets[$panel])
            ->and(str_contains($html, '/build/'.$assets[$other]))
            ->toBeFalse("{$uri} links the {$other} panel's theme");
    }
});

it('declares the approved Latin family on every chrome route', function (): void {
    // Filament's own `@theme inline` sets `--font-sans: var(--font-family), …` and the base
    // layout writes --font-family from getFontFamily() -- so this ONE property is what makes
    // every Filament component use the approved face.
    $family = (string) config('design-tokens.fonts.latin.family');

    // The family is read from the GENERATED token config, so this is the one place the
    // approved value is stated, and `pnpm tokens:check` holds the config to the source.
    expect($family)->toBe('Archivo');

    LandingFixtures::bucket();

    foreach (array_keys(panelChromeRoutes()) as $uri) {
        actAsPrincipalFor($uri);

        $html = (string) $this->get($uri)->getContent();

        // EXACT, including the quotes and the semicolon: a `(string)` cast over a missing
        // config key yields '' and `--font-family: '';` would satisfy any looser check.
        expect($html)->toContain("--font-family: '{$family}';");
    }
});

it('fetches no font from a third party on any panel route', function (): void {
    // ALL SEVEN, the preview included. The preview renders a TENANT-AUTHORED landing page,
    // which is the one panel surface where a font URL could arrive from data rather than
    // from our own configuration, so excluding it would leave the most interesting case
    // unmeasured.
    LandingFixtures::bucket();

    foreach (panelGetRoutes() as $uri) {
        actAsPrincipalFor($uri);

        $response = $this->get($uri);
        $html = (string) $response->getContent();

        // The response must BE the document before a negative claim about it means
        // anything. The chrome routes additionally have to be the panel layout.
        expect($response->status())->toBe(200, "{$uri} did not render")
            ->and(strlen($html))->toBeGreaterThan(1000, "{$uri} returned a stub body");

        if (array_key_exists($uri, panelChromeRoutes())) {
            expect($html)->toContain('--font-family: ');
        }

        foreach (thirdPartyFontHosts() as $host) {
            // ⚠ str_contains() AND NOT `->not->toContain($host, $message)`. Pest's
            // toContain() is VARIADIC over needles and takes no message argument, so the
            // negated two-argument form asserts "contains NEITHER" -- which a message that
            // never appears in the subject makes trivially true. `composer guard`'s
            // vacuous-negated-contains rule refuses that form, and it caught this very line
            // after a falsification run had already passed straight over it.
            expect(str_contains($html, $host))->toBeFalse("{$uri} reaches {$host}");
        }
    }
});

it('refuses to pass against the Bunny provider, which is what makes it an assertion', function (): void {
    // THE FALSIFICATION. The same panel, the same route, the same rendered response -- with
    // only the provider swapped to the one Filament would have chosen BY DEFAULT had
    // `provider:` been omitted. If this case ever stops failing, the case above has stopped
    // measuring anything.
    Filament::getPanel('platform')->font(
        (string) config('design-tokens.fonts.latin.family'),
        provider: BunnyFontProvider::class,
    );

    $html = (string) $this->actingAs(themeAdmin())->get('/platform')->getContent();

    expect($html)->toContain('fonts.bunny.net')
        ->and($html)->toContain('rel="preconnect"');
});

it('resolves the local provider from the registration rather than from the default', function (): void {
    // The configuration the rendered-HTML cases above are a consequence of. Naming it here
    // is what tells a reviewer WHICH line to change in order to break them.
    foreach (['platform', 'studio'] as $id) {
        $panel = Filament::getPanel($id);

        expect($panel->getFontProvider())->toBe(LocalFontProvider::class, "{$id} panel")
            ->and($panel->getFontFamily())->toBe('Archivo', "{$id} panel")
            // LocalFontProvider with no URL emits the empty string, which is correct here:
            // the @font-face rules arrive inside the compiled theme, served same-origin.
            ->and((string) $panel->getFontHtml())->toBe('')
            ->and($panel->getViteTheme())->toBe("resources/css/filament/{$id}/theme.css");
    }
});
