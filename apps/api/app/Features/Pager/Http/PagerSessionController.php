<?php

declare(strict_types=1);

namespace App\Features\Pager\Http;

use App\Features\Pager\PagerSessions;
use App\Features\Pager\PagerSessionState;
use App\Features\Pager\PagerSheet;
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
 * EVERY METHOD PUBLISHES A 401, and the class publishes a security requirement (QA L-5 and
 * security M-1). The committed spec had neither: no `components.securitySchemes`, no
 * `security` member on any operation, and no 401 anywhere -- so the five state-changing
 * staff endpoints were published as requiring no authentication, while 401 is in fact the
 * most common answer this surface gives. RequireTenantScope answers it before any method
 * here runs, which is why no method mentions it in its own body and every one of them
 * documents it.
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
     * Issues a single-use token for one order and returns both the payload to encode in a QR
     * code and a ready-made SVG of that code, sized by the page that renders it. The token is
     * returned by this call only and cannot be retrieved afterwards.
     */
    /**
     * List the restaurant's active pagers.
     *
     * Everything still waiting or ready, newest first. This is what a kitchen display reads.
     */
    #[DocResponse(status: 401, description: 'No authenticated staff user. Every endpoint here requires one; the body carries no detail about why.', type: ProblemDocument::class)]
    #[DocResponse(status: 200, description: 'The active pagers for this restaurant.')]
    #[DocResponse(status: 403, description: 'That is not permitted.', type: ProblemDocument::class)]
    public function index(): JsonResponse
    {
        /*
         * ⚠ `private, no-store`, ADDED AT 10b's FIX ROUND — `§11` SECURITY M-2, and it is the
         * clause `§4 › Realtime` asks of a polling endpoint: *short-TTL cache headers*.
         *
         * This route had NO headers at all and relied on the framework default, which the
         * reviewer measured rather than assumed: Symfony computes `no-cache, private` for a
         * bare `JsonResponse`. So there was never a shared-cache or CDN cross-tenant leak —
         * `private` forbids that — and the residual is the one every sibling on this surface
         * already guards against by hand: `no-cache` permits the DEVICE's own HTTP cache to
         * STORE the body, so a shared kitchen tablet would keep one restaurant's order board
         * on disk across the lock, the sign-out and the next person's sign-in.
         *
         * Every neighbour sets it deliberately — `store()` and `state()` below,
         * `TenantController`, `DeviceController`, `CustomerPagerController` — and
         * `StaffShellEndpointsTest` asserts it for `/v1/tenant` with the words *"Nothing
         * shared may cache a restaurant's data off a shared kitchen device."* The board is
         * the one route polled every five seconds by every screen in the kitchen, so it is
         * the last one that should have been relying on a default.
         */
        return new JsonResponse(
            ['sessions' => $this->sessions->board($this->tenant())],
            200,
            ['Cache-Control' => 'private, no-store'],
        );
    }

    #[DocResponse(status: 401, description: 'No authenticated staff user. Every endpoint here requires one; the body carries no detail about why.', type: ProblemDocument::class)]
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
        $payload = $issued->token->qrPayload((string) config('pager.landing_url'));

        return new JsonResponse([
            'session' => $issued->session->toArray(),
            'qr_payload' => $payload,
            'qr_svg' => PagerSheet::svg($payload),
        ], 201, ['Cache-Control' => 'no-store']);
    }

    /**
     * Mark an order ready and notify the customer.
     *
     * Safe to repeat: calling this again on a session that is already ready changes
     * nothing and sends no second notification. A session that has passed its two-hour
     * expiry is refused; issue a new session instead.
     */
    #[DocResponse(status: 401, description: 'No authenticated staff user. Every endpoint here requires one; the body carries no detail about why.', type: ProblemDocument::class)]
    #[DocResponse(status: 200, description: 'The session state after the call.')]
    #[DocResponse(status: 404, description: 'No such session in this tenant.', type: ProblemDocument::class)]
    #[DocResponse(status: 409, description: 'The session cannot move to that state, or has passed its expiry. The `type` member distinguishes the two.', type: ProblemDocument::class)]
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
    #[DocResponse(status: 401, description: 'No authenticated staff user. Every endpoint here requires one; the body carries no detail about why.', type: ProblemDocument::class)]
    #[DocResponse(status: 200, description: 'The session state after the call.')]
    #[DocResponse(status: 404, description: 'No such session in this tenant.', type: ProblemDocument::class)]
    #[DocResponse(status: 409, description: 'The session is not ready, has reached its repeat limit, or has passed its expiry. The `type` member distinguishes them.', type: ProblemDocument::class)]
    public function renotify(Request $request, string $session): JsonResponse
    {
        return $this->state($this->sessions->renotify($this->tenant(), $session, actorIp: $request->ip()));
    }

    /**
     * Mark an order collected.
     *
     * Sends no notification: the customer is at the counter. Safe to repeat.
     */
    #[DocResponse(status: 401, description: 'No authenticated staff user. Every endpoint here requires one; the body carries no detail about why.', type: ProblemDocument::class)]
    #[DocResponse(status: 200, description: 'The session state after the call.')]
    #[DocResponse(status: 404, description: 'No such session in this tenant.', type: ProblemDocument::class)]
    #[DocResponse(status: 409, description: 'The session cannot move to that state, or has passed its expiry. The `type` member distinguishes the two.', type: ProblemDocument::class)]
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
    #[DocResponse(status: 401, description: 'No authenticated staff user. Every endpoint here requires one; the body carries no detail about why.', type: ProblemDocument::class)]
    #[DocResponse(status: 200, description: 'The session state after the call.')]
    #[DocResponse(status: 404, description: 'No such session in this tenant.', type: ProblemDocument::class)]
    #[DocResponse(status: 409, description: 'The session cannot move to that state, or has passed its expiry. The `type` member distinguishes the two.', type: ProblemDocument::class)]
    public function cancel(Request $request, string $session): JsonResponse
    {
        return $this->state($this->sessions->cancel($this->tenant(), $session, actorIp: $request->ip()));
    }

    /**
     * List the restaurant's past orders.
     *
     * Filter by any combination of order state, a date range of up to 92 days, and part of an
     * order reference. Newest first. Returns at most 100 orders.
     */
    #[DocResponse(status: 401, description: 'No authenticated staff user. Every endpoint here requires one; the body carries no detail about why.', type: ProblemDocument::class)]
    #[DocResponse(status: 200, description: 'The matching orders for this restaurant.')]
    #[DocResponse(status: 403, description: 'That is not permitted.', type: ProblemDocument::class)]
    #[DocResponse(status: 422, description: 'A filter did not pass validation.', type: ValidationProblemDocument::class)]
    public function history(PagerHistoryRequest $request): JsonResponse
    {
        // `private, no-store` for `§11` SECURITY M-2's reason, which is stronger here than on
        // the board: this response is a restaurant's ORDER RECORD, and a shared kitchen tablet
        // keeping it in its own HTTP cache would survive the lock, the sign-out and the next
        // person's sign-in.
        return new JsonResponse(
            ['sessions' => $this->sessions->history($this->tenant(), $request->filters())],
            200,
            ['Cache-Control' => 'private, no-store'],
        );
    }

    /**
     * Issue a new code for an existing order.
     *
     * The previous code stops working immediately and cannot be recovered. Use this when a
     * printed sheet is lost or unreadable; the order itself is unchanged and the customer is
     * not notified. Returns the new payload and a ready-made SVG of the new code. An order
     * that is already finished, cancelled or expired is refused.
     */
    #[DocResponse(status: 401, description: 'No authenticated staff user. Every endpoint here requires one; the body carries no detail about why.', type: ProblemDocument::class)]
    #[DocResponse(status: 200, description: 'The session, and the new QR payload to encode.')]
    #[DocResponse(status: 404, description: 'No such session in this tenant.', type: ProblemDocument::class)]
    #[DocResponse(status: 409, description: 'The order is already finished, or has passed its expiry. The `type` member distinguishes the two.', type: ProblemDocument::class)]
    public function reissue(Request $request, string $session): JsonResponse
    {
        $issued = $this->sessions->reissue($this->tenant(), $session, actorIp: $request->ip());

        // reveal() is called HERE and in `store()` and nowhere else in the application. This is
        // the only other moment a raw token can reach a QR code, and it exists because the raw
        // value of the PREVIOUS one never can -- Muhammad's ruling: a sheet is re-issued, never
        // reprinted.
        $payload = $issued->token->qrPayload((string) config('pager.landing_url'));

        return new JsonResponse([
            'session' => $issued->session->toArray(),
            'qr_payload' => $payload,
            'qr_svg' => PagerSheet::svg($payload),
        ], 200, ['Cache-Control' => 'no-store']);
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
