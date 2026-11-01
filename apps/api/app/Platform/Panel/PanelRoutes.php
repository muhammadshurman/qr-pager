<?php

declare(strict_types=1);

namespace App\Platform\Panel;

use Filament\Pages\BasePage;
use Illuminate\Support\Facades\Route;

/**
 * The panel routes, DERIVED from the router — `SR-3`'s ruling, in one place.
 *
 * `PLAN §4.3` as corrected by Task 8b says it in those words: *"§12's walk subject list is
 * DERIVED from that table, never hand-listed — a walk measured against a typed inventory is
 * the same defect one layer up."* Three tests then carried the same typed six-element array,
 * which is that defect two layers up.
 *
 * ⚠ A SEVENTH CHROME ROUTE WOULD HAVE BEEN INVISIBLE to the 44px floor check and to the
 * fixture-drift check — the two controls `R-174` and `R-118` clause 2 rest on. That is the
 * §11 QA round's M-4, and this class is its fix: one derivation, read by every caller.
 *
 * In `App\Platform` because `deptrac` rule 2 forbids Platform from importing a feature, and
 * a helper inside one feature imported by the other would be a feature-to-feature edge. Both
 * may read Platform, which is the argument `DatabaseRoles` already rests on.
 */
final class PanelRoutes
{
    /**
     * Every concrete GET route either panel serves. `SR-3` measured seven.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $uris = [];

        // `->getRoutes()` on the collection, which returns a plain array: the collection
        // itself is typed as RouteCollectionInterface and PHPStan does not accept that as
        // iterable. `LivewireRequest` and `PanelRateLimitTest` reach for the same accessor
        // for the same reason.
        foreach (Route::getRoutes()->getRoutes() as $route) {
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
     * The routes that render FILAMENT CHROME, and which panel each belongs to.
     *
     * Structural, never a prefix match: a Filament page route resolves to a Livewire
     * component extending `BasePage`, while `/studio/preview` resolves to a controller that
     * renders the TENANT'S OWN landing page. The panel id comes from Filament's own route
     * name — `filament.<panel>.pages.media`.
     *
     * @return array<string, string> uri => panel id
     */
    public static function chrome(): array
    {
        $all = self::all();
        $chrome = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = '/'.ltrim($route->uri(), '/');

            if (! in_array($uri, $all, true)) {
                continue;
            }

            $action = $route->getAction('controller');

            if (! is_string($action) || ! is_subclass_of($action, BasePage::class)) {
                continue;
            }

            $segments = explode('.', (string) $route->getName());

            // explode() always returns at least one element, so index 0 needs no coalesce --
            // PHPStan says so and it is right.
            if ($segments[0] !== 'filament' || ! isset($segments[1])) {
                continue;
            }

            $chrome[$uri] = $segments[1];
        }

        ksort($chrome);

        return $chrome;
    }
}
