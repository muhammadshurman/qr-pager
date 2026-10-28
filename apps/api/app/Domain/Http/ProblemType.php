<?php

declare(strict_types=1);

namespace App\Domain\Http;

// INTERNAL NOTES -- LINE COMMENTS ON PURPOSE, SO THEY ARE NOT PUBLISHED.
//
// Scramble ships this enum's DOCBLOCK verbatim as the `description` of
// `#/components/schemas/ProblemType` in docs/openapi.json. Line comments are invisible to
// it; a /** */ block is not. That distinction is the whole of why this text is up here and
// the docblock below says only what a client developer needs.
//
// Measured 2026-10-27 while closing R-033, and it is worth recording because the obvious
// fix does not work: separating the published half from the internal half INSIDE one
// docblock with a horizontal rule published both halves, rule included. The generated
// description carried "THIS DOCBLOCK IS PUBLISHED ... R-052 and R-056 are both this
// shape", which is R-052's defect committed by the comment warning about R-052 -- and it
// reproduced the asterisk artefact it was describing, shipping the literal text
// `one  * source` a second time.
//
// What belongs in the docblock: the contract. What belongs here: why the file looks like
// this. The task rationale -- the single-source argument, the deptrac placement, the
// BadRequest case's history -- is in QRPAGER-10 §4 and in ProblemRenderer::fromStatus(),
// which is a private method of a class Scramble does not document.
//
// BadRequest (R-042) was added at Task 3, after R-033 made this vocabulary a committed and
// asserted artefact, which is the order R-042 asks for: the new case lands as a visible
// diff in something CI checks rather than as a silent change to a contract nothing reads.

/**
 * The vocabulary of `type` values this API can return in an RFC 7807 problem document.
 *
 * Each case is a stable URI, the HTTP status beside it, and a fixed human-readable title.
 * A client may switch on `type`; it is the part of an error response that is contractual.
 */
enum ProblemType: string
{
    case BadRequest = 'https://qrpager.dev/problems/bad-request';
    case Validation = 'https://qrpager.dev/problems/validation';
    case NotFound = 'https://qrpager.dev/problems/not-found';
    case MethodNotAllowed = 'https://qrpager.dev/problems/method-not-allowed';
    case Unauthenticated = 'https://qrpager.dev/problems/unauthenticated';
    case Forbidden = 'https://qrpager.dev/problems/forbidden';
    case Conflict = 'https://qrpager.dev/problems/conflict';
    case CsrfMismatch = 'https://qrpager.dev/problems/csrf-mismatch';
    case Expired = 'https://qrpager.dev/problems/expired';
    case Stale = 'https://qrpager.dev/problems/stale';
    case RateLimited = 'https://qrpager.dev/problems/rate-limited';
    case Unavailable = 'https://qrpager.dev/problems/unavailable';
    case ServerError = 'https://qrpager.dev/problems/server-error';

    public function status(): int
    {
        return match ($this) {
            self::BadRequest => 400,
            self::Validation => 422,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::Unauthenticated => 401,
            self::Forbidden => 403,
            self::Conflict => 409,
            // 419 is not an IANA status; it is Laravel's own for a CSRF token that is
            // missing or stale, and it is what a client keys on to fetch a fresh token
            // and retry. Mapping this to 409 or 400 instead would be tidier and would
            // break that convention for the one case it exists for.
            self::CsrfMismatch => 419,
            self::Expired => 409,
            self::Stale => 404,
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
            self::BadRequest => 'The request could not be understood.',
            self::Validation => 'The request did not pass validation.',
            self::NotFound => 'No such resource.',
            self::MethodNotAllowed => 'That method is not allowed on this resource.',
            self::Unauthenticated => 'Authentication is required.',
            self::Forbidden => 'That is not permitted.',
            self::Conflict => 'The request conflicts with the current state.',
            self::CsrfMismatch => 'That form token was missing or stale. Reload and try again.',
            self::Expired => 'That resource has expired.',
            self::Stale => 'That live status is no longer held. Resolve the session again.',
            self::RateLimited => 'Too many requests.',
            self::Unavailable => 'The service is temporarily unable to handle the request.',
            self::ServerError => 'The request could not be completed.',
        };
    }
}
