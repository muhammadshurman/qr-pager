<?php

declare(strict_types=1);

namespace App\Domain\Http;

/**
 * The single source for the error envelope's `type` (D3, §6.2).
 *
 * RFC 7807 `application/problem+json`. One case per outcome the API can report, and
 * the only producer of a `type` value: the mapper takes this enum, never a string, so
 * a new outcome without a case here does not compile.
 *
 * In App\Domain on purpose -- no framework, no DB, which deptrac enforces. The HTTP
 * status lives beside the type because they are one decision, and the title is a fixed
 * phrase rather than a message: nothing here is ever built from a driver error, a
 * constraint name or a role name. That is the whole point of the envelope.
 *
 * Scramble generates `#/components/schemas/ProblemType` from this enum with every case
 * as an OpenAPI `enum`, verified in the phase-1 research, which is what makes "one
 * source" mechanical rather than a convention.
 */
enum ProblemType: string
{
    case Validation = 'https://qrpager.dev/problems/validation';
    case NotFound = 'https://qrpager.dev/problems/not-found';
    case MethodNotAllowed = 'https://qrpager.dev/problems/method-not-allowed';
    case Unauthenticated = 'https://qrpager.dev/problems/unauthenticated';
    case Forbidden = 'https://qrpager.dev/problems/forbidden';
    case Conflict = 'https://qrpager.dev/problems/conflict';
    case RateLimited = 'https://qrpager.dev/problems/rate-limited';
    case Unavailable = 'https://qrpager.dev/problems/unavailable';
    case ServerError = 'https://qrpager.dev/problems/server-error';

    public function status(): int
    {
        return match ($this) {
            self::Validation => 422,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::Unauthenticated => 401,
            self::Forbidden => 403,
            self::Conflict => 409,
            self::RateLimited => 429,
            self::Unavailable => 503,
            self::ServerError => 500,
        };
    }

    /**
     * A fixed phrase. Never a driver message, a constraint name, a role name or a
     * statement -- §4 › Edge & ops, as amended on 2026-10-26 to cover response bodies
     * at any APP_DEBUG setting.
     */
    public function title(): string
    {
        return match ($this) {
            self::Validation => 'The request did not pass validation.',
            self::NotFound => 'No such resource.',
            self::MethodNotAllowed => 'That method is not allowed on this resource.',
            self::Unauthenticated => 'Authentication is required.',
            self::Forbidden => 'That is not permitted.',
            self::Conflict => 'The request conflicts with the current state.',
            self::RateLimited => 'Too many requests.',
            self::Unavailable => 'The service is temporarily unable to handle the request.',
            self::ServerError => 'The request could not be completed.',
        };
    }
}
