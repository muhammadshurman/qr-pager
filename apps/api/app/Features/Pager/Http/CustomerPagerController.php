<?php

declare(strict_types=1);

namespace App\Features\Pager\Http;

use App\Domain\Http\ProblemType;
use App\Features\Pager\PagerProjection;
use App\Features\Pager\PagerTokenLookup;
use App\Features\Pager\PushSubscriptions;
use App\Features\Pager\ResolvedPagerSession;
use App\Features\Pager\TokenResolutionFailures;
use App\Platform\Http\Problem;
use App\Platform\Http\ProblemDocument;
use App\Platform\Http\ValidationProblemDocument;
use DateTimeImmutable;
use Dedoc\Scramble\Attributes\Response as DocResponse;
use Illuminate\Http\JsonResponse;

/**
 * The customer surface. Four routes, no account, no tenant context, one credential.
 *
 * ===================================================================================
 * THE SECOND AUTHENTICATION SURFACE, AND IT IS NOT THE STAFF ONE
 * ===================================================================================
 *
 * `§4 › Tenancy`: two authentication surfaces, never confused. Staff authenticate as
 * users and every one of their routes is behind RequireTenantScope. A CUSTOMER HAS NO
 * ACCOUNT: they authenticate by presenting the QR token, which resolves to exactly one
 * session in exactly one tenant. So these routes are deliberately NOT on
 * RequireTenantScope -- there is no tenant to require before the token is resolved, and
 * putting them on it would answer 401 to every real customer.
 *
 * WHERE THE TENANT COMES FROM INSTEAD: ResolvedPagerSession::tenantId, which
 * PagerTokenLookup produced from the token. Never a header, a path segment, a query
 * string or a body field -- there is no tenant field in any request this class accepts,
 * so there is nothing for an attacker to vary.
 *
 * AND THERE IS NO ID ON ANY ROUTE, which is this surface's whole IDOR answer. The session
 * is whatever the token resolves to; the parameter somebody would tamper with does not
 * exist.
 *
 * ===================================================================================
 * THREE ANSWERS, AND TELLING THEM APART IS A GATE CLAUSE
 * ===================================================================================
 *
 * Gate clause (a): "a presented token that resolves to a session past its TTL is refused,
 * and DISTINGUISHABLY". So:
 *
 *   unknown or malformed token  -> 404 ProblemType::NotFound   "no such thing"
 *   resolves, past its TTL      -> 409 ProblemType::Expired    "it was yours, it is over"
 *   resolves, live              -> 200
 *
 * The verdict is ResolvedPagerSession::hasExpired(), which computes from expires_at
 * against the clock and NEVER from status -- because `pager:expire` is scheduled nowhere
 * (R-058) so status lags, sometimes indefinitely. Task 3's handover says so in terms and
 * this is the production caller that clause was written for: until this class existed,
 * hasExpired() had none.
 *
 * ===================================================================================
 * DOCBLOCKS HERE ARE PUBLISHED
 * ===================================================================================
 *
 * Scramble ships a controller method's docblock as the endpoint's `description` in
 * docs/openapi.json -- R-052 and R-056, where a capacity figure reached the committed
 * spec that way. Every method comment below is written as public API text: what the
 * endpoint does, and nothing about thresholds, keys, internals or infrastructure.
 */
final class CustomerPagerController
{
    public function __construct(
        private readonly PagerTokenLookup $lookup,
        private readonly PagerProjection $projection,
        private readonly PushSubscriptions $subscriptions,
        private readonly TokenResolutionFailures $failures,
    ) {}

    /**
     * Open a pager session from a scanned code.
     *
     * Exchanges the code for the order's current state. Call this once after a scan, and
     * again whenever the live status endpoint reports that it no longer holds a status.
     */
    #[DocResponse(status: 200, description: 'The session state, and how long to wait between live-status calls.')]
    #[DocResponse(status: 404, description: 'No such pager session.', type: ProblemDocument::class)]
    #[DocResponse(status: 409, description: 'The session has passed its expiry. Ask the restaurant for a new code.', type: ProblemDocument::class)]
    public function resolve(ResolvePagerSessionRequest $request): JsonResponse
    {
        $now = new DateTimeImmutable;
        $token = $request->token();

        if ($token === null) {
            return $this->unknown($request->getPathInfo(), $now);
        }

        $session = $this->lookup->resolveToken($token);

        if ($session === null) {
            return $this->unknown($request->getPathInfo(), $now);
        }

        if ($session->hasExpired($now)) {
            // Deliberately BEFORE the projection is written. An expired session gets no
            // live status: there is nothing to poll for, and a projection would keep
            // answering 200 to a poll for as long as its TTL ran.
            return (new Problem(ProblemType::Expired, $request->getPathInfo()))->toResponse();
        }

        // The one place either Redis key can be created. It takes the token AND the
        // resolved session together, so a session cannot be filed under a token it does
        // not belong to -- there is no call shape in which the two are supplied
        // separately. Failure here is not failure of the request: the customer has their
        // answer, and their next poll will report no live status and bring them back
        // here, which is the defined path rather than an error.
        $this->projection->remember($token, $session, $now);

        return $this->ok($session, $now);
    }

    /**
     * Read the live status of a pager session.
     *
     * Designed to be called repeatedly while an order is being prepared. Wait
     * `poll_after_seconds` between calls, apply your own random jitter to it, and back
     * off when this endpoint reports it is temporarily unavailable. A `404` means this
     * server no longer holds a live status for the code: open the session again, which
     * will say whether it is still valid.
     */
    #[DocResponse(status: 200, description: 'The live state of the session.')]
    #[DocResponse(status: 404, description: 'No live status is held for that code. Open the session again.', type: ProblemDocument::class)]
    #[DocResponse(status: 503, description: 'The live status is temporarily unavailable. Retry after the interval in the Retry-After header; notifications are unaffected.', type: ProblemDocument::class)]
    public function poll(PollPagerStatusRequest $request): JsonResponse
    {
        $now = new DateTimeImmutable;
        $token = $request->token();

        // A malformed token costs ZERO round trips -- no Redis, no Postgres -- because
        // the shape check runs before anything is hashed. That is what bounds a
        // garbage-traffic loop on the highest-volume route in the product.
        if ($token === null) {
            return $this->stale($request->getPathInfo());
        }

        $view = $this->projection->read($token, $now);

        // FALSE AND NULL ARE DIFFERENT ANSWERS and the customer feels the difference.
        // false: Redis could not be reached, so we do not know -- 503 and come back,
        //        which is the option QRPAGER-11's own 2026-10-25 comment chose in terms.
        //        The customer still gets their notification: Web Push is the primary
        //        channel and does not pass through here. Only the live timer degrades,
        //        which is the load-shedding order §4 › Edge & ops requires.
        // null:  Redis answered and holds nothing -- 404, open the session again.
        //
        // NEITHER FALLS BACK TO POSTGRES. A cold cache is precisely the moment every
        // waiting device in every restaurant polls at once, so a fallback would turn a
        // Redis outage into a Postgres stampede. §4 › Realtime calls a polling endpoint
        // that touches Postgres a rejected design, and this is the case that makes it a
        // rule rather than a preference.
        if ($view === false) {
            return (new Problem(
                ProblemType::Unavailable,
                $request->getPathInfo(),
                retryAfter: $this->pollInterval(),
            ))->toResponse();
        }

        if ($view === null) {
            return $this->stale($request->getPathInfo());
        }

        return $this->json($view->toArray($this->pollInterval()));
    }

    /**
     * Register a device to be notified when the order is ready.
     *
     * Send the endpoint and keys from the browser's push subscription. Calling this again
     * with the same endpoint updates the stored keys rather than adding a second device,
     * so it is safe to repeat on every page load.
     */
    #[DocResponse(status: 201, description: 'The device was registered.')]
    #[DocResponse(status: 200, description: 'The device was already registered; its keys were updated.')]
    #[DocResponse(status: 404, description: 'No such pager session.', type: ProblemDocument::class)]
    #[DocResponse(status: 409, description: 'The session has passed its expiry.', type: ProblemDocument::class)]
    #[DocResponse(status: 422, description: 'The endpoint or keys did not pass validation.', type: ValidationProblemDocument::class)]
    public function subscribe(StorePushSubscriptionRequest $request): JsonResponse
    {
        $now = new DateTimeImmutable;
        $session = $this->live($request, $now);

        if ($session instanceof JsonResponse) {
            return $session;
        }

        $created = $this->subscriptions->register(
            $session,
            $request->endpoint(),
            $request->p256dh(),
            $request->authSecret(),
        );

        return $this->json(['registered' => true], $created ? 201 : 200);
    }

    /**
     * Stop notifying a device.
     *
     * Safe to repeat: removing a device that is not registered succeeds.
     */
    #[DocResponse(status: 200, description: 'The device is no longer registered.')]
    #[DocResponse(status: 404, description: 'No such pager session.', type: ProblemDocument::class)]
    #[DocResponse(status: 409, description: 'The session has passed its expiry.', type: ProblemDocument::class)]
    public function unsubscribe(ForgetPushSubscriptionRequest $request): JsonResponse
    {
        $now = new DateTimeImmutable;
        $session = $this->live($request, $now);

        if ($session instanceof JsonResponse) {
            return $session;
        }

        $this->subscriptions->forget($session, $request->endpoint());

        return $this->json(['registered' => false]);
    }

    /**
     * A live session, or the response that refuses it. The shared half of the two
     * subscription routes.
     *
     * An EXPIRED session may not register a device, which is not pedantry: the outbox
     * worker would then hold a subscription for a session nothing will ever notify, and
     * the row would survive until the session's cascade removed it.
     */
    private function live(PresentedTokenRequest $request, DateTimeImmutable $now): ResolvedPagerSession|JsonResponse
    {
        $token = $request->token();

        if ($token === null) {
            return $this->unknown($request->getPathInfo(), $now);
        }

        $session = $this->lookup->resolveToken($token);

        if ($session === null) {
            return $this->unknown($request->getPathInfo(), $now);
        }

        if ($session->hasExpired($now)) {
            return (new Problem(ProblemType::Expired, $request->getPathInfo()))->toResponse();
        }

        return $session;
    }

    /**
     * A token that resolved to nothing: counted, then refused.
     *
     * R-063's "failed resolutions are counted so an enumeration is observable". The
     * counter is unconditional and the log inside it is thresholded, which is R-046 --
     * a warning per request on a high-volume failure path is the same amplification the
     * attacker is paying for. The REFUSAL is the rate limiter's; this is the TRACE.
     *
     * A malformed token counts too. It costs us less, but it is the same attacker and an
     * observable that only sees well-formed attempts would report a quiet hour during a
     * noisy one.
     */
    private function unknown(string $path, DateTimeImmutable $now): JsonResponse
    {
        $this->failures->record($now);

        return (new Problem(ProblemType::NotFound, $path))->toResponse();
    }

    /** 404, with the type that means "re-open the session" rather than "no such thing". */
    private function stale(string $path): JsonResponse
    {
        return (new Problem(ProblemType::Stale, $path))->toResponse();
    }

    private function ok(ResolvedPagerSession $session, DateTimeImmutable $now): JsonResponse
    {
        return $this->json([
            'status' => $session->status->value,
            'order_reference' => $session->orderReference,
            'expires_at' => $session->expiresAt->format(DATE_RFC3339),
            'ready_at' => $session->readyAt?->format(DATE_RFC3339),
            'as_of' => $now->format(DATE_RFC3339),
            'poll_after_seconds' => $this->pollInterval(),
        ]);
    }

    /**
     * Every response from this surface, with the same two headers.
     *
     * `no-store` on all of them, including the 200s: the body is per-session data behind
     * a per-session secret, so no shared cache could ever serve a second customer from
     * it, and a browser cache holding it is a copy of the answer on a device the customer
     * may hand to someone else. The pacing a client needs is `poll_after_seconds` in the
     * body, not a max-age.
     *
     * @param  array<string, mixed>  $body
     */
    private function json(array $body, int $status = 200): JsonResponse
    {
        return new JsonResponse($body, $status, ['Cache-Control' => 'no-store']);
    }

    private function pollInterval(): int
    {
        return max(1, (int) config('pager.poll_interval'));
    }
}
