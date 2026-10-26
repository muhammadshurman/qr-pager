<?php

declare(strict_types=1);

namespace App\Platform\Http;

use App\Domain\Http\ProblemType;
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
            return new Problem($database, $instance);
        }

        if ($error instanceof HttpExceptionInterface) {
            return new Problem($this->fromStatus($error->getStatusCode()), $instance, [], $this->retryAfter($error));
        }

        return new Problem(ProblemType::ServerError, $instance);
    }

    private function fromStatus(int $status): ProblemType
    {
        return match ($status) {
            401 => ProblemType::Unauthenticated,
            403 => ProblemType::Forbidden,
            404 => ProblemType::NotFound,
            405 => ProblemType::MethodNotAllowed,
            409 => ProblemType::Conflict,
            422 => ProblemType::Validation,
            429 => ProblemType::RateLimited,
            503 => ProblemType::Unavailable,
            default => $status >= 500 ? ProblemType::ServerError : ProblemType::Forbidden,
        };
    }

    private function retryAfter(HttpExceptionInterface $error): ?int
    {
        $headers = $error->getHeaders();
        $value = $headers['Retry-After'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
