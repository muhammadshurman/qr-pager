<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * A REAL Livewire update request, built the way the browser builds one.
 *
 * ================================================================================
 * WHY THIS EXISTS: A BARE POST CANNOT TELL SERVED FROM REFUSED
 * ================================================================================
 *
 * `POST /livewire-<hash>/update` carries every write both panels make. Until 2026-10-31
 * no test issued one: every action test drives its component with `Livewire::test()`,
 * which calls it in process and never crosses HTTP. That is the shape that hid `R-116`
 * for the whole of Task 7 -- a guard refusing an endpoint the product needs, with a green
 * suite -- and this class exists so the same thing cannot happen one surface over.
 *
 * ⚠ AND THE OBVIOUS PROBE DOES NOT WORK, which is why this is a class rather than a line.
 * Measured 2026-10-31:
 *
 *   - a bare `POST <prefix>/update` answers **404**, from Livewire itself:
 *     `RequireLivewireHeaders` aborts 404 without an `X-Livewire` header ("to avoid
 *     confirming the endpoint exists to scanners", in the vendor's words) and
 *     `HandleRequests::handleUpdate()` aborts 404 again on an empty payload. So the
 *     endpoint's own refusal and a global guard's refusal are THE SAME STATUS.
 *   - 419 is not available either: `VerifyCsrfToken` skips itself when
 *     `runningUnitTests()`, so no test can observe the CSRF refusal a browser would get.
 *
 * What is left is to send a real one: take the snapshot the server embedded in the page's
 * own HTML, and post it back with a call on it. A 200 whose `effects.html` carries the
 * page's content is then proof the endpoint WORKS, not merely proof that something
 * answered.
 *
 * The payload shape is Livewire's wire format and this class is coupled to it on purpose.
 * If Livewire changes it, these cases fail loudly at the seam rather than going quiet,
 * which is the direction every guard in this repository is pointed.
 */
final class LivewireRequest
{
    /** The header Livewire's own client always sends. Without it the endpoint answers 404. */
    public const HEADERS = ['X-Livewire' => 'true'];

    /**
     * The update endpoint's path, read from the router.
     *
     * NEVER a literal: Livewire 4 derives the prefix from `APP_KEY`
     * (`substr(hash('sha256', $key.'livewire-endpoint'), 0, 8)`), so it differs on this
     * machine and on CI, and a literal here would be the "pass that depends on something
     * only present on the builder's machine" §6 forbids. `RouteSurfaceTest` owns the
     * other half of this argument.
     */
    public static function updatePath(): string
    {
        // `->getRoutes()` on the collection, which returns a plain array: the collection
        // itself is typed as RouteCollectionInterface, and PHPStan does not accept that as
        // iterable. PanelRateLimitTest reaches for the same accessor for the same reason.
        /** @var RoutingRoute $route */
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = '/'.ltrim($route->uri(), '/');

            if (preg_match('#^/livewire-[0-9a-f]{8}/update$#', $uri) === 1) {
                return $uri;
            }
        }

        return '';
    }

    /**
     * The snapshot one named component embedded in a rendered page.
     *
     * ⚠ BY NAME, AND THAT IS LOAD-BEARING. A Filament page renders FOUR Livewire
     * components -- `Topbar`, `Sidebar`, the page itself and `Notifications` -- so
     * "the first `wire:snapshot` in the HTML" is the topbar. The first version of this
     * probe took it, and reached a conclusion about the wrong component: the topbar
     * re-renders happily for an anonymous caller, because it holds no tenant content.
     * Asking for the page by class name is what makes the answer mean anything.
     */
    public static function snapshotFor(string $html, string $component): string
    {
        preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

        foreach ($matches[1] as $raw) {
            $json = html_entity_decode($raw, ENT_QUOTES);

            if (($decoded = json_decode($json, true)) !== null
                && ($decoded['memo']['name'] ?? null) === $component) {
                return $json;
            }
        }

        return '';
    }

    /**
     * The body the browser posts: one component, one call on it.
     *
     * `$refresh` by default -- a re-render, which changes nothing and is therefore the
     * safe way to ask "does this endpoint work".
     *
     * @param  list<mixed>  $params
     * @return array<string,mixed>
     */
    public static function payload(string $snapshot, string $method = '$refresh', array $params = []): array
    {
        return [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [['path' => '', 'method' => $method, 'params' => $params]],
            ]],
        ];
    }
}
