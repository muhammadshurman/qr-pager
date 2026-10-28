<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use App\Domain\Http\ProblemType;
use App\Platform\Http\Problem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse the vendor routes this application mounts a panel for but does not serve.
 *
 * ================================================================================
 * WHY THIS EXISTS — two UNAUTHENTICATED 500s, measured, not supposed
 * ================================================================================
 *
 * Installing `filament/filament` at Task 6 added eleven routes outside `/v1` that nobody
 * asked for. Nine are load-bearing for the panel (Livewire's update endpoint and its
 * script and stylesheet routes). **Two are not, and both answered 500 to an anonymous
 * GET**, measured in this session at the commit that installed the package:
 *
 *     GET /filament/exports/1/download                 => 500
 *     GET /filament/imports/1/failed-rows/download     => 500
 *
 * They are registered unconditionally by `filament/actions` and they read the `exports`
 * and `imports` tables, which this application has never created: Filament's own
 * migrations were not published, because the panel uses no export action and no import
 * action. So the 500 is `relation "exports" does not exist`, reachable by anyone, on a path
 * this product has no feature behind.
 *
 * That is wrong on three counts at once, which is why it is fixed rather than accepted:
 * `§4`'s **route default is deny** (these deny nothing), the error budget (an anonymous
 * caller can generate unlimited 500s, and `§4 › Edge & ops` wants load shed before that
 * matters), and the simple fact that an endpoint with no feature behind it is surface.
 *
 * TWO MORE ARE REFUSED FOR THE SAME REASON, though neither was a 500: Livewire's
 * `upload-file` and `preview-file`. **This panel has no file upload at all** — branding
 * assets are the part of the wizard the ruling removes (`QRPAGER-13 §0` SR-2, `R-116`), and
 * the ticket's `§5` calls uploads "an attack surface: type, size, content sniffing, and
 * where they are stored". A temporary-file-upload endpoint with no form behind it is that
 * surface with none of the validation. `upload-file` answered 419 behind CSRF, which is a
 * control and not a reason to keep an endpoint nobody uses.
 *
 * ================================================================================
 * WHY A GLOBAL MIDDLEWARE AND NOT A ROUTE
 * ================================================================================
 *
 * Registering competing routes for these paths would work only while this application's
 * routes are registered before the package's, and that order is a function of service
 * provider order — which nothing in the repository pins and a `composer update` can move.
 * A **prepended global middleware runs before routing**, so the refusal does not depend on
 * registration order at all. That is the same reason `ArmTenantScopeGuard` is global rather
 * than a route group, and its docblock makes the argument at length.
 *
 * ================================================================================
 * THE LIVEWIRE PREFIX IS A PATTERN, NOT A LITERAL, AND THAT IS LOAD-BEARING
 * ================================================================================
 *
 * Livewire 4 derives its route prefix from the application key —
 * `substr(hash('sha256', config('app.key').'livewire-endpoint'), 0, 8)`, read from
 * `EndpointResolver` in this session — deliberately, so a universal scanner cannot guess
 * it. **It therefore differs on every installation**, including between this machine and
 * CI. A literal `livewire-89488b6d` here would be the shape `§6` forbids in so many words:
 * a pass that depends on something only present on the builder's machine.
 *
 * So the prefix is matched as `livewire-<8 hex>` and NOT read from `EndpointResolver`,
 * which is an internal class this application should not couple to. The risk that leaves is
 * that Livewire changes its scheme and this pattern silently stops matching — a guard going
 * quiet rather than failing, which is the defect class `§4a` records four of. That is
 * closed from the other side: `RouteSurfaceTest` asserts the live Livewire routes still
 * match this pattern, so a change in the scheme fails the suite instead of opening the
 * endpoints back up.
 *
 * NOTHING ELSE IS REFUSED HERE. `livewire-<hash>/update` is how every Filament table,
 * modal and form talks to the server; refusing it would leave a panel that renders and does
 * nothing. The script and stylesheet routes are how it loads at all.
 */
final class RefuseUnservedVendorRoutes
{
    /**
     * Paths this application mounts and does not serve, as anchored patterns.
     *
     * Anchored at both ends, and `#` as the delimiter so the slashes read plainly. Each
     * entry carries the reason it is here, because a path list with no reasons is a list
     * nobody dares delete from.
     *
     * @var list<string>
     */
    private const REFUSED = [
        // No export action exists in this panel, and the `exports` table was never created.
        '#^filament/exports/[^/]+/download$#',
        // No import action either, same table story.
        '#^filament/imports/[^/]+/failed-rows/download$#',
        // No file upload in this panel at all: branding is R-116's, in another task.
        '#^livewire-[0-9a-f]{8}/upload-file$#',
        '#^livewire-[0-9a-f]{8}/preview-file/.*$#',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $path = ltrim($request->decodedPath(), '/');

        foreach (self::REFUSED as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                // THE SAME DOCUMENT AN UNKNOWN PATH GETS, deliberately. A distinct status
                // or a distinct body would tell a scanner that this path is special —
                // "refused" and "does not exist" must be indistinguishable from outside, or
                // the refusal becomes the disclosure. `ProblemType::NotFound` is what
                // bootstrap/app.php's fallback answers with.
                return (new Problem(ProblemType::NotFound, $request->getPathInfo()))->toResponse();
            }
        }

        return $next($request);
    }

    /**
     * The patterns, for the test that keeps them honest.
     *
     * Exposed rather than duplicated in the test: a guard whose test carries its own copy
     * of the list is a guard that can disagree with its test, which is QA H-1's defect at
     * Task 4 (a duplicated path list) and is why `TenantTables` is one registry.
     *
     * @return list<string>
     */
    public static function patterns(): array
    {
        return self::REFUSED;
    }
}
