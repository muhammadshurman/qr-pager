<?php

declare(strict_types=1);

namespace App\Platform\Http;

use App\Domain\Http\ProblemType;
use Illuminate\Http\JsonResponse;

/**
 * An RFC 7807 problem document (D3).
 *
 * What it carries is fixed: the `type` from the enum, that type's fixed `title` and
 * `status`, and the request path as `instance`. What it never carries is the point --
 * no database role name, no constraint name, no SQL, no bound parameter, at any
 * APP_DEBUG setting (`§4 › Edge & ops`, amended 2026-10-26; gate clause (b)).
 *
 * Measured at `7f1c8f6`, before this class existed: a QueryException rendered a JSON
 * 500 carrying the full statement and the interpolated bound uuid, and
 * UnsafeDatabaseRoleException rendered one naming `qrpager_app` and the attribute that
 * made it unsafe (R-021). The detail still reaches the operator -- it goes to the log,
 * where the redaction processor strips the statement and keeps the SQLSTATE.
 *
 * `errors` is the single exception to "nothing from the request comes back", and it is
 * ours rather than the driver's: validation messages are generated from our own rules.
 */
final class Problem
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        public readonly ProblemType $type,
        public readonly ?string $instance = null,
        public readonly array $errors = [],
        public readonly ?int $retryAfter = null,
    ) {}

    /**
     * @return array{type: string, title: string, status: int, instance: string|null, errors?: array<string, list<string>>}
     */
    public function toArray(): array
    {
        $body = [
            'type' => $this->type->value,
            'title' => $this->type->title(),
            'status' => $this->type->status(),
            'instance' => $this->instance,
        ];

        if ($this->errors !== []) {
            $body['errors'] = $this->errors;
        }

        return $body;
    }

    public function toResponse(): JsonResponse
    {
        $headers = [
            'Content-Type' => 'application/problem+json',
            'Cache-Control' => 'no-store',
        ];

        if ($this->retryAfter !== null) {
            $headers['Retry-After'] = (string) $this->retryAfter;
        }

        return new JsonResponse($this->toArray(), $this->type->status(), $headers);
    }
}
