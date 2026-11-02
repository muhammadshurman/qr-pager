<?php

declare(strict_types=1);

namespace App\Features\Tenants\Http;

use App\Domain\Http\ProblemType;
use App\Features\Tenants\TenantProfile;
use App\Platform\Http\Middleware\RequireTenantScope;
use App\Platform\Http\Problem;
use App\Platform\Http\ProblemDocument;
use Dedoc\Scramble\Attributes\Response as DocResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The restaurant the caller is signed in to.
 *
 * DOCBLOCKS HERE ARE PUBLISHED. Scramble ships a controller method's docblock as the
 * endpoint's `description` in `docs/openapi.json` (`R-052`, `R-056`), so this comment and
 * the one below are written as public API text. Anything about internals lives in
 * `App\Features\Tenants\TenantProfile` instead.
 */
final class TenantController
{
    public function __construct(private readonly TenantProfile $profile) {}

    /**
     * The current restaurant.
     *
     * Its name, as staff should see it, with the timezone its day is measured in. Takes no
     * parameters: the restaurant is the one the caller's own credential resolves to.
     */
    #[DocResponse(status: 200, description: 'The restaurant this session is acting in.')]
    #[DocResponse(status: 401, description: 'No authenticated staff user. The body carries no detail about why.', type: ProblemDocument::class)]
    #[DocResponse(status: 403, description: 'That is not permitted.', type: ProblemDocument::class)]
    #[DocResponse(status: 404, description: 'No such restaurant.', type: ProblemDocument::class)]
    public function show(Request $request): Response
    {
        $tenant = $this->profile->forTenant($this->tenant());

        if ($tenant === null) {
            /*
             * UNREACHABLE THROUGH THE MIDDLEWARE STACK, and asserted rather than assumed.
             * `RequireTenantScope` publishes a tenant only after verifying the caller's own
             * membership in it, so the row exists and RLS makes it visible. Reaching here
             * would mean the row was deleted between the two, which answers 404 because
             * that is what "it is not there" means -- not a 500, and not a body that
             * explains the race.
             */
            return (new Problem(ProblemType::NotFound, $request->getPathInfo()))->toResponse();
        }

        return new JsonResponse(['tenant' => $tenant], 200, [
            // The restaurant's own name is not a secret from its own staff, but it is
            // tenant data on a shared device, and a shared device is what `§12`'s lock
            // clause is about. No shared cache may hold it.
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** The tenant this request resolved, from the container and never from input. */
    private function tenant(): string
    {
        $tenantId = app(RequireTenantScope::BINDING);

        if (! is_string($tenantId) || $tenantId === '') {
            throw new RuntimeException('no tenant was published for this request');
        }

        return $tenantId;
    }
}
