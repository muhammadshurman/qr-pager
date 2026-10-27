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
     * The media type EVERY error response carries, and the media type the spec publishes
     * them under (R-033's "one producer, two readers" applied to the envelope rather than
     * the body). The committed spec published all four error codes under
     * `application/json` while this header has always said `application/problem+json`, so
     * a client generated from the document would have been told the wrong media type for
     * every error it will actually receive. Both halves now read this constant.
     */
    public const string CONTENT_TYPE = 'application/problem+json';

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
     * The document this problem renders as -- the shape docs/openapi.json publishes (R-033).
     *
     * ONE PRODUCER, TWO READERS: toArray() below serialises what this returns, and
     * Scramble documents these same classes' properties. So the published schema and the
     * emitted body come from one place and cannot drift -- which they already had, before
     * this method existed. Pointing Scramble at THIS class published `retryAfter` (a
     * HEADER, never a body member) and omitted `title` and `status`, which are in every
     * body, because Scramble reads constructor-promoted properties and this class's are
     * the renderer's inputs rather than the wire's output. Shipping that would have been
     * R-025's category inside a generated artefact.
     *
     * TWO CLASSES AND NOT ONE OPTIONAL MEMBER, which is forced rather than chosen:
     * Scramble marks every property of a documented class `required`, measured both as a
     * promoted constructor parameter with a default and as a plain property with an
     * initialiser. So a single class carrying `errors` would publish it as required while
     * a 404 or a 409 body omits it. The honest split is the real one -- a 422 body ALWAYS
     * has per-field messages and no other status ever does -- so each schema is exactly
     * right and the wire format is unchanged from Task 2. The alternative, emitting
     * `errors: null` on every error response, would have made one schema work by changing
     * every body in the API, which is a wire change nothing asked for.
     *
     * OpenApiSpecTest pins the published property names against the keys a real rendered
     * body has, so neither class can drift from toArray() in either direction.
     */
    public function document(): ProblemDocument
    {
        if ($this->errors !== []) {
            return new ValidationProblemDocument(
                $this->type,
                $this->type->title(),
                $this->type->status(),
                $this->instance,
                $this->errors,
            );
        }

        return new ProblemDocument(
            $this->type,
            $this->type->title(),
            $this->type->status(),
            $this->instance,
        );
    }

    /**
     * @return array{type: string, title: string, status: int, instance: string|null, errors?: array<string, list<string>>}
     */
    public function toArray(): array
    {
        $document = $this->document();

        $body = [
            'type' => $document->type->value,
            'title' => $document->title,
            'status' => $document->status,
            'instance' => $document->instance,
        ];

        // Absent rather than present-and-empty, and the type carrying it is the type the
        // 422 response is documented with.
        if ($document instanceof ValidationProblemDocument) {
            $body['errors'] = $document->errors;
        }

        return $body;
    }

    public function toResponse(): JsonResponse
    {
        $headers = [
            'Content-Type' => self::CONTENT_TYPE,
            'Cache-Control' => 'no-store',
        ];

        if ($this->retryAfter !== null) {
            $headers['Retry-After'] = (string) $this->retryAfter;
        }

        return new JsonResponse($this->toArray(), $this->type->status(), $headers);
    }
}
