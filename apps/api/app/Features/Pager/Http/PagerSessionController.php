<?php

declare(strict_types=1);

namespace App\Features\Pager\Http;

use App\Features\Pager\PagerSessions;
use App\Features\Pager\PagerSessionState;
use App\Platform\Http\Middleware\RequireTenantScope;
use App\Platform\Http\ProblemDocument;
use App\Platform\Http\ValidationProblemDocument;
use Dedoc\Scramble\Attributes\Response as DocResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The staff-facing pager surface. Five routes, all under /v1, all deny-by-default.
 *
 * WHERE THE TENANT COMES FROM, and it is the whole of this class's security posture: the
 * container binding RequireTenantScope published for this request. Never a header, never a
 * route parameter, never a body field, never a query string -- so no HTTP input can choose
 * a tenant. The session id in the path is looked up INSIDE that tenant's scope, where
 * another tenant's row is invisible to row level security, so a cross-tenant id answers
 * 404 and not 403: a 403 would confirm the id exists. That is the gate's "cross-tenant
 * IDOR blocked through HTTP, not just at the DB", and it is asserted through real requests
 * rather than read off this comment.
 *
 * The customer-facing half of this feature has NO route here on purpose. A customer
 * authenticates only by presenting the QR token (§4 › Tenancy, "two authentication
 * surfaces, never confused"), the lookup that resolves it is PagerTokenLookup, and the
 * endpoints that consume it -- polling, subscriptions -- are Task 4's. This task builds
 * the resolver; Task 4 puts a route in front of it.
 *
 * DOCBLOCKS HERE ARE PUBLISHED. Scramble ships a controller method's docblock as the
 * endpoint's `description` in docs/openapi.json, which is R-052 and R-056 -- a capacity
 * figure reached the committed spec that way. So every method comment below is written as
 * public API text: what the endpoint does, and nothing about thresholds, internals or
 * infrastructure.
 */
final class PagerSessionController
{
    public function __construct(private readonly PagerSessions $sessions) {}

    /**
     * Create a pager session.
     *
     * Issues a single-use token for one order and returns the payload to encode in a QR
     * code. The token is returned by this call only and cannot be retrieved afterwards.
     */
    #[DocResponse(status: 201, description: 'The session, and the QR payload to encode.')]
    #[DocResponse(status: 422, description: 'The order reference did not pass validation.', type: ValidationProblemDocument::class)]
    public function store(CreatePagerSessionRequest $request): JsonResponse
    {
        $issued = $this->sessions->create(
            $this->tenant(),
            $request->orderReference(),
            actorIp: $request->ip(),
        );

        // reveal() is called HERE and nowhere else in the application. This is the only
        // moment the raw token can reach the QR code, and PagerToken makes every other
        // way of getting at it either a redacted marker or an error.
        return new JsonResponse([
            'session' => $issued->session->toArray(),
            'qr_payload' => $issued->token->qrPayload((string) config('pager.landing_url')),
        ], 201, ['Cache-Control' => 'no-store']);
    }

    /**
     * Mark an order ready and notify the customer.
     *
     * Safe to repeat: calling this again on a session that is already ready changes
     * nothing and sends no second notification.
     */
    #[DocResponse(status: 200, description: 'The session state after the call.')]
    #[DocResponse(status: 404, description: 'No such session in this tenant.', type: ProblemDocument::class)]
    #[DocResponse(status: 409, description: 'The session cannot move to that state.', type: ProblemDocument::class)]
    public function ready(Request $request, string $session): JsonResponse
    {
        return $this->state($this->sessions->markReady($this->tenant(), $session, actorIp: $request->ip()));
    }

    /**
     * Send the ready notification again.
     *
     * Requires a session that has already been marked ready, and is limited to a fixed
     * number of repeats per session.
     */
    #[DocResponse(status: 200, description: 'The session state after the call.')]
    #[DocResponse(status: 404, description: 'No such session in this tenant.', type: ProblemDocument::class)]
    #[DocResponse(status: 409, description: 'The session is not ready, or has reached its repeat limit.', type: ProblemDocument::class)]
    public function renotify(Request $request, string $session): JsonResponse
    {
        return $this->state($this->sessions->renotify($this->tenant(), $session, actorIp: $request->ip()));
    }

    /**
     * Mark an order collected.
     *
     * Sends no notification: the customer is at the counter. Safe to repeat.
     */
    #[DocResponse(status: 200, description: 'The session state after the call.')]
    #[DocResponse(status: 404, description: 'No such session in this tenant.', type: ProblemDocument::class)]
    #[DocResponse(status: 409, description: 'The session cannot move to that state.', type: ProblemDocument::class)]
    public function complete(Request $request, string $session): JsonResponse
    {
        return $this->state($this->sessions->complete($this->tenant(), $session, actorIp: $request->ip()));
    }

    /**
     * Cancel an order and tell the customer.
     *
     * Notifies, so that a customer waiting on a pager that will never go off is told.
     * Safe to repeat.
     */
    #[DocResponse(status: 200, description: 'The session state after the call.')]
    #[DocResponse(status: 404, description: 'No such session in this tenant.', type: ProblemDocument::class)]
    #[DocResponse(status: 409, description: 'The session cannot move to that state.', type: ProblemDocument::class)]
    public function cancel(Request $request, string $session): JsonResponse
    {
        return $this->state($this->sessions->cancel($this->tenant(), $session, actorIp: $request->ip()));
    }

    private function state(PagerSessionState $state): JsonResponse
    {
        return new JsonResponse(['session' => $state->toArray()], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * The tenant RequireTenantScope resolved for this request.
     *
     * It cannot be absent: the middleware is on the `api` group and answers 401 before any
     * route runs when no tenant resolves. The refusal below is therefore unreachable
     * through routing -- it exists so that a future route mounted without that middleware
     * fails loudly rather than running unscoped, which is the direction §4 asks for. A
     * server fault, not a client one: nothing the caller sent caused it.
     */
    private function tenant(): string
    {
        if (! app()->bound(RequireTenantScope::BINDING)) {
            throw new RuntimeException(
                'No tenant is bound for this request, so a pager route ran outside '
                .RequireTenantScope::class.'. Every /v1 route must be on the api middleware group.'
            );
        }

        /** @var string $tenant */
        $tenant = app()->make(RequireTenantScope::BINDING);

        return $tenant;
    }
}
