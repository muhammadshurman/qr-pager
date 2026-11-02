<?php

declare(strict_types=1);

namespace App\Platform\Http;

use App\Domain\Http\ProblemType;
use App\Domain\Pager\PagerSessionExpired;
use App\Domain\Pager\PagerSessionNotFound;
use App\Domain\Pager\PagerTransitionDenied;
use App\Domain\Tenancy\MemberActionDenied;
use App\Platform\Database\DatabaseErrorMapper;
use App\Platform\Database\TenantContextException;
use App\Platform\Database\UnsafeDatabaseRoleException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Every uncaught throwable becomes one RFC 7807 document (D3).
 *
 * Registered for ALL requests, not only for JSON ones, and that is deliberate: the
 * clause it exists for is "no error response body carries a database role name, a
 * constraint name, SQL or a bound parameter, **at any APP_DEBUG setting**" (`§4 › Edge
 * & ops` as amended; gate clause (b)). A renderer that steps aside when APP_DEBUG is
 * true satisfies the words and not the property -- and `.env.example` ships
 * APP_DEBUG=true, so that is the configuration a developer actually runs.
 *
 * What was measured at `7f1c8f6`, before this existed:
 *   - QueryException           -> JSON body with the full SQL and the bound uuid
 *   - UnsafeDatabaseRoleException -> JSON body naming qrpager_app and the attribute
 *   - either, as HTML          -> 862,104 bytes carrying all of it
 *
 * The cost is Ignition's page for local debugging. The detail is not lost: Laravel
 * still reports the exception, so the class, the SQLSTATE and the file and line are in
 * the log, where the redaction processor strips the statement and keeps the rest.
 */
final class ProblemRenderer
{
    public function __construct(private readonly DatabaseErrorMapper $databaseErrors) {}

    public function render(Throwable $error, Request $request): Problem
    {
        $instance = '/'.ltrim($request->path(), '/');

        if ($error instanceof ValidationException) {
            /** @var array<string, list<string>> $errors */
            $errors = $error->errors();

            return new Problem(ProblemType::Validation, $instance, $errors);
        }

        // Before the generic HttpException branch: these two ARE HttpExceptions, and
        // matching them by class rather than by status keeps the mapping readable.
        if ($error instanceof NotFoundHttpException || $error instanceof ModelNotFoundException) {
            return new Problem(ProblemType::NotFound, $instance);
        }

        if ($error instanceof MethodNotAllowedHttpException) {
            return new Problem(ProblemType::MethodNotAllowed, $instance);
        }

        if ($error instanceof AuthenticationException) {
            return new Problem(ProblemType::Unauthenticated, $instance);
        }

        if ($error instanceof AuthorizationException) {
            return new Problem(ProblemType::Forbidden, $instance);
        }

        // The two pager-domain refusals. Both live in App\Domain and not in App\Features
        // for exactly this reason: deptrac forbids Platform from importing Features, so a
        // feature-layer exception could not be mapped here at all, and an unmapped domain
        // refusal would render as a 500.
        //
        // NOT FOUND AND NEVER FORBIDDEN for a session that belongs to another tenant. It
        // is invisible inside the caller's tenant scope rather than refused, and a 403
        // would confirm the id exists -- which is the leak the gate clause "cross-tenant
        // IDOR blocked through HTTP, not just at the DB" is about. So the two cases
        // "no such session" and "not your session" are deliberately indistinguishable
        // from outside, and the exception carries nothing that could tell them apart.
        if ($error instanceof PagerSessionNotFound) {
            return new Problem(ProblemType::NotFound, $instance);
        }

        // An illegal transition, a renotify on a session that is not ready, and a session
        // at its renotify cap are all 409: the request is well-formed and the current
        // STATE is what makes it impossible. A 422 would say the body was wrong, and for
        // the four transition endpoints there is no body at all.
        //
        // The exception's message names only our own status values, so it is safe for the
        // log -- and it still never reaches the body: Conflict's title is a fixed phrase,
        // which is the whole point of the envelope.
        if ($error instanceof PagerTransitionDenied) {
            return new Problem(ProblemType::Conflict, $instance);
        }

        /*
         * TASK 10e's TWO MEMBER-MANAGEMENT REFUSALS — `§4 › Security`'s *no actor may grant a
         * role senior to its own, assume one, or change its own role at all*.
         *
         * **403 AND NOT 404, WHICH IS THE OPPOSITE OF THE RULE TWO CLAUSES UP, AND THE
         * DIFFERENCE IS WHETHER THE ANSWER DISCLOSES ANYTHING.**
         *
         * `PagerSessionNotFound` is a 404 because a 403 there would confirm that a session id
         * exists in somebody else's restaurant. These two refusals depend on NO fact the
         * caller does not already hold: seniority compares the role they just requested with
         * the role they have been acting under, and the self-role clause compares the target
         * id with their own membership id. There is nothing for a 403 to leak.
         *
         * And a 404 here would be actively worse for `§12`: it would tell a manager who tried
         * to appoint an owner that the person they were looking at does not exist, which is
         * false, and the screen's sentence would be about the wrong thing.
         *
         * `MemberActionDenied`'s docblock carries the ordering that makes this safe on
         * `store()` — the seniority check runs BEFORE the email lookup, so an unknown address
         * and a known one answer identically.
         */
        if ($error instanceof MemberActionDenied) {
            return new Problem(ProblemType::Forbidden, $instance);
        }

        // A session past its TTL. 409 like the clause above -- the state is what makes
        // the request impossible -- but a DIFFERENT `type`, because the two are different
        // states and a client has to act on them differently: an illegal transition is a
        // mistake to correct, an expired session is one to re-issue. `type` is the
        // envelope's contractual discriminator, so the distinction is published rather
        // than carried in a message no body ever renders.
        if ($error instanceof PagerSessionExpired) {
            return new Problem(ProblemType::Expired, $instance);
        }

        // R-021: the message names the database role and the attribute that made it
        // unsafe, and with APP_DEBUG=true that reached the rendered body. The type
        // stays UnsafeDatabaseRoleException -- deliberately not a
        // TenantContextException, so the composition sweep cannot swallow an
        // RLS-exempt connection -- and the disclosure is closed here instead, where it
        // belongs. A server fault, not a client one: nothing the caller sent caused it.
        if ($error instanceof UnsafeDatabaseRoleException || $error instanceof TenantContextException) {
            return new Problem(ProblemType::ServerError, $instance);
        }

        $database = $this->databaseErrors->map($error);

        if ($database !== null) {
            // R-070: the retry hint, chosen per SQLSTATE rather than per status code. A
            // lock timeout and a saturated connection pool are both 503 and deserve
            // different numbers, so the mapper decides -- it is the only thing here that
            // has read the driver code. Null for the mapped 4xx and 5xx, so a 409 or a
            // 422 still carries no Retry-After.
            return new Problem($database, $instance, [], $this->databaseErrors->retryAfter($error));
        }

        if ($error instanceof HttpExceptionInterface) {
            return new Problem($this->fromStatus($error->getStatusCode()), $instance, [], $this->retryAfter($error));
        }

        return new Problem(ProblemType::ServerError, $instance);
    }

    /**
     * An HttpException's status to a type in the published vocabulary (R-042).
     *
     * THE DEFAULT IS ServerError AND NOT Forbidden, which is the whole of R-042. It used
     * to be `$status >= 500 ? ServerError : Forbidden`, so every unmapped 4xx rendered
     * 403 -- measured through this renderer: a thrown 400, 402, 406, 410, 415 and 418 all
     * came out as 403 with the title "That is not permitted." Laravel throws 400 for a
     * malformed JSON body, so the first endpoint in this project that accepts one would
     * have answered an AUTHORIZATION VERDICT to a caller who sent broken JSON -- and the
     * status the client saw was not the status that was thrown.
     *
     * Defaulting to ServerError is the honest answer for a status this renderer does not
     * model: it says "we could not complete this", which is true, rather than asserting
     * something about authorization that the renderer cannot know. An unmapped 4xx is a
     * gap on our side.
     *
     * The collapses, each deliberate (R-042 allows "mapped or deliberately collapsed"):
     *
     *   400, 406, 415 -> BadRequest   the request itself is unusable: malformed body, a
     *                                 media type we cannot produce, one we cannot accept
     *   410           -> NotFound     Gone is a 404 with history, and "No such resource."
     *                                 is the right fixed phrase for it
     *   402           -> the default  Payment Required is not an outcome this API has, so
     *                                 it is not modelled rather than mapped somewhere
     *                                 convenient
     *   419           -> CsrfMismatch Laravel's own status for a missing or stale CSRF
     *                                 token. ⚠ IT WAS ABSENT AND THEREFORE FELL TO THE
     *                                 DEFAULT, so every CSRF rejection rendered as a 500
     *                                 `server-error` -- measured end to end at Task 5's
     *                                 security review: `POST /v1/auth/login` with no token
     *                                 answered 500. Worse than the wrong number: Laravel
     *                                 has `TokenMismatchException` on its internal
     *                                 don't-report list, so the refusal was invisible in
     *                                 the log AND misreported to the client, and a client
     *                                 reading 5xx retries rather than refreshing its token.
     *                                 The request was always correctly REFUSED; it is the
     *                                 answer that was wrong. `QRPAGER-12 §10.4(c)` claimed
     *                                 "a test drives a state-changing route without a token
     *                                 and asserts 419" -- the test asserted the exception
     *                                 class from an instantiated middleware, so nothing
     *                                 ever saw the status. `SessionPolicyTest` drives it
     *                                 through the kernel now.
     *
     * Asserted by throwing each status through this renderer and reading the rendered
     * `status`, which is how QA found the defect (ProblemEnvelopeTest).
     */
    private function fromStatus(int $status): ProblemType
    {
        return match ($status) {
            400, 406, 415 => ProblemType::BadRequest,
            401 => ProblemType::Unauthenticated,
            403 => ProblemType::Forbidden,
            404, 410 => ProblemType::NotFound,
            405 => ProblemType::MethodNotAllowed,
            409 => ProblemType::Conflict,
            419 => ProblemType::CsrfMismatch,
            422 => ProblemType::Validation,
            429 => ProblemType::RateLimited,
            503 => ProblemType::Unavailable,
            default => ProblemType::ServerError,
        };
    }

    private function retryAfter(HttpExceptionInterface $error): ?int
    {
        $headers = $error->getHeaders();
        $value = $headers['Retry-After'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
