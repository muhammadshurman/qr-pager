<?php

declare(strict_types=1);

use App\Platform\Database\DatabaseErrorMapper;
use App\Platform\Database\DriverError;
use App\Platform\Http\Problem;
use App\Platform\Http\ProblemRenderer;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

/*
 * R-070: "A mapped database 503 carries no `Retry-After`, while both of the project's
 * other 'come back later' answers do."
 *
 * The row's own remedy, first option: "either the mapped 503s carry a `Retry-After` chosen
 * PER SQLSTATE -- a lock timeout and a saturated pool deserve different numbers -- and a
 * test asserts the header, or the omission is stated as deliberate".
 *
 * Per SQLSTATE is the half that matters, so the per-code numbers are asserted rather than
 * merely the presence of the header: a single constant would satisfy "carries a
 * Retry-After" and would be the thing the row objected to, one layer along.
 */

function problemFor(string $sqlstate): Problem
{
    // A real QueryException wrapping a real PDOException, because DriverError reads the
    // SQLSTATE from errorInfo[0] on the exception UNDERNEATH -- getCode() is the integer 7
    // for a connection failure, which is not a SQLSTATE at all. A hand-rolled exception
    // with a code would test a path production never takes.
    $pdo = new PDOException('refused');
    $pdo->errorInfo = [$sqlstate, 7, 'the driver said so'];

    $query = new QueryException('pgsql', 'select 1', [], $pdo);

    return app(ProblemRenderer::class)->render($query, Request::create('/v1/pager-sessions', 'POST'));
}

it('gives every mapped 503 a Retry-After, chosen per SQLSTATE', function (): void {
    $expected = [
        // A lock timeout: the app role waits 2s (§2.1) and whoever held the row is
        // finishing one keyed UPDATE, so the contention is over in well under a second.
        DriverError::LOCK_NOT_AVAILABLE => 1,
        // statement_timeout, 5s. Slow rather than contended, so longer than a lock.
        DriverError::QUERY_CANCELED => 2,
        // Measured at Task 2: 08006 is ALSO how a saturated role CONNECTION LIMIT arrives
        // at the client. An immediate retry adds to the saturation it is reporting.
        DriverError::CONNECTION_FAILURE => 10,
        DriverError::TOO_MANY_CONNECTIONS => 10,
    ];

    foreach ($expected as $sqlstate => $seconds) {
        $problem = problemFor((string) $sqlstate);
        $response = $problem->toResponse();

        expect($response->getStatusCode())->toBe(503, "{$sqlstate} should be a 503")
            ->and($response->headers->get('Retry-After'))->toBe((string) $seconds, "{$sqlstate} should advise {$seconds}s");
    }

    // THE POINT OF THE ROW: the numbers DIFFER. A single constant would satisfy "carries a
    // Retry-After" and be the same defect one layer along.
    expect(array_unique(array_values($expected)))->toHaveCount(3);
});

it('advises no retry on an answer that repeating cannot fix', function (): void {
    // A 409, a 422 and a 500 have no retry interval to advertise, and inventing one would
    // tell a caller that repeating an invalid request might work.
    // The status is EXACT per sqlstate rather than `< 500`, which is the same form the
    // case above uses for the three that DO advise a retry. A range here would have been
    // satisfied by any 4xx, including one that mapped the wrong way.
    // PAIRS AND NOT A MAP: these constants are numeric strings ('23505', '42501'), and
    // PHP coerces a numeric-string array KEY to int -- so a map hands `problemFor(string)`
    // an int and the case dies in a TypeError rather than asserting. Met while writing it.
    $statuses = [
        [DriverError::UNIQUE_VIOLATION, 409],
        [DriverError::CHECK_VIOLATION, 422],
        [DriverError::RLS_DENIED, 403],
    ];

    foreach ($statuses as [$sqlstate, $status]) {
        $response = problemFor($sqlstate)->toResponse();

        expect($response->headers->has('Retry-After'))->toBeFalse("{$sqlstate} advised a retry");
        expect($response->getStatusCode())->toBe($status, "{$sqlstate} should be a {$status}");
    }
});

it('maps a throwable with no driver error to nothing, so the mapper invents no interval', function (): void {
    expect(app(DatabaseErrorMapper::class)->retryAfter(new RuntimeException('not a database error')))->toBeNull();
});

it('still carries no SQLSTATE, SQL, role or table name in the body', function (): void {
    // The header is new; the envelope's silence is not up for renegotiation. §4 › Edge &
    // ops, as amended on 2026-10-26 to cover response bodies at any APP_DEBUG setting.
    config(['app.debug' => true]);

    $body = problemFor(DriverError::LOCK_NOT_AVAILABLE)->toResponse()->getContent();

    // The forbidden strings are named precisely, because two near-misses are legitimate
    // and a loose assertion would have caught them: `qrpager.dev` is in every `type` URI
    // by design, and `/v1/pager-sessions` is the request path in `instance`. What must
    // never appear is a database ROLE name and a TABLE name -- which differ from those two
    // only by a dot and a hyphen.
    expect((string) $body)->not->toContain('55P03')
        ->and((string) $body)->not->toContain('select 1')
        ->and((string) $body)->not->toContain('qrpager_app')
        ->and((string) $body)->not->toContain('qrpager_owner')
        ->and((string) $body)->not->toContain('qrpager_worker')
        ->and((string) $body)->not->toContain('the driver said so')
        ->and((string) $body)->not->toContain('pager_sessions');
});
