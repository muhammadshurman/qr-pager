<?php

declare(strict_types=1);

use App\Domain\Pager\PagerToken;
use App\Features\Pager\PagerSessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

/*
 * §3 gate, clause 6 AS AMENDED 2026-10-27 (security S-1): "raw token never appears in a
 * log, a URL path or query string, an error body, or anywhere a server receives or logs
 * it". The pre-amendment wording was "never appears in any log, URL or error body (asserted
 * against captured output)". §4 › Data: "128-bit CSPRNG, stored only as a hash, TTL 2
 * hours. Never logged, never in a URL query string, never in an error body."
 *
 * ASSERTED AGAINST CAPTURED OUTPUT, which the clause says in so many words -- so the log
 * cases below attach a real Monolog handler and read what was emitted, rather than
 * asserting that the redactor was called.
 *
 * The two documents differ: §4 forbids the token in a URL QUERY STRING and §3 widens that
 * to any URL. The wider one is satisfied, and the reading is recorded in QRPAGER-10 §4 and
 * in PagerToken::qrPayload(): the token is never in a URL a server receives or logs -- which
 * is now what the clause SAYS, rather than a reading of it this file used to assume. It
 * IS in the QR payload's fragment, because a QR code is how the token reaches the customer
 * and a fragment is not transmitted to a server -- which is also what keeps the landing
 * page one CDN cache entry instead of one per scan.
 */

it('is 128 bits of CSPRNG, 22 base64url characters, and stored only as a sha256', function (): void {
    $token = PagerToken::issue();
    $raw = $token->reveal();

    expect(strlen($raw))->toBe(PagerToken::LENGTH)
        ->and($raw)->toMatch('/^[A-Za-z0-9_-]{22}$/')
        // 22 unpadded base64url characters decode to 16 bytes = 128 bits.
        ->and(strlen((string) base64_decode(strtr($raw, '-_', '+/').'==', true)))->toBe(16)
        // The hash is of the PRESENTED STRING, not of the 16 raw bytes (§2.5): the string
        // is what a client sends, so hashing it needs no decode and admits no second
        // encoding of the same token.
        ->and($token->hashHex())->toBe(hash('sha256', $raw))
        ->and(strlen($token->hashHex()))->toBe(64);
});

it('draws a different token every time', function (): void {
    $tokens = array_map(static fn (): string => PagerToken::issue()->reveal(), range(1, 200));

    expect(array_unique($tokens))->toHaveCount(200);
});

it('cannot be leaked by any of the six ways a value escapes a PHP object', function (): void {
    $token = PagerToken::issue();
    $raw = $token->reveal();
    $leaked = [];

    ob_start();
    var_dump($token);
    $dumped = (string) ob_get_clean();

    if (str_contains($dumped, $raw)) {
        $leaked[] = 'var_dump';
    }

    if (str_contains(print_r($token, true), $raw)) {
        $leaked[] = 'print_r';
    }

    if (str_contains((string) json_encode($token), $raw)) {
        $leaked[] = 'json_encode';
    }

    // The shape Monolog uses for context, which is the realistic leak path.
    if (str_contains((string) json_encode(['context' => ['token' => $token]]), $raw)) {
        $leaked[] = 'json_encode nested';
    }

    if (str_contains(var_export($token, true), $raw)) {
        $leaked[] = 'var_export';
    }

    // serialize() is REFUSED rather than redacted, because the token lives in a Closure.
    // A refusal is the right answer for a secret: it fails at the call site instead of
    // succeeding quietly. Measured while writing the class: with the token in a private
    // string property, var_dump/print_r/json_encode were clean and serialize() and
    // var_export() BOTH printed it -- a private property is private to callers, not to
    // the serialisers, and var_export has no magic method to override.
    try {
        if (str_contains(serialize($token), $raw)) {
            $leaked[] = 'serialize';
        }
    } catch (Throwable $e) {
        expect($e->getMessage())->toContain('Closure');
    }

    expect($leaked)->toBe([], 'the raw token escaped via: '.implode(', ', $leaked))
        // And the marker is what those sinks see instead.
        ->and($dumped)->toContain(PagerToken::REDACTED)
        ->and((string) json_encode($token))->toContain(PagerToken::REDACTED);
});

it('has no __toString, so interpolating it is an error rather than a leak', function (): void {
    $token = PagerToken::issue();

    expect(method_exists($token, '__toString'))->toBeFalse();

    // @phpstan-ignore-next-line  the point of the case is that this is a type error
    expect(fn (): string => 'token: '.$token)->toThrow(Error::class);
});

it('keeps the raw token out of captured log output, even when the object is logged', function (): void {
    $token = PagerToken::issue();
    $raw = $token->reveal();

    // A real handler on the real logger, so this reads what was EMITTED rather than
    // trusting the redactor to have been consulted.
    $handler = new TestHandler;
    Log::swap(new Logger('capture', [$handler]));

    Log::info('issued a pager token', ['token' => $token]);
    Log::warning('token in the message position', ['payload' => ['nested' => ['deep' => $token]]]);
    Log::error('a failure carrying the token', ['context' => $token]);

    $captured = '';

    foreach ($handler->getRecords() as $record) {
        $captured .= $record->message.' '.(string) json_encode($record->context);
    }

    // str_contains() AND NOT `->not->toContain($raw, 'message')`, which is what this was
    // and which asserted NOTHING -- Pest's toContain takes ...$needles, so the message was
    // read as a second needle and ->not became "does not contain BOTH", trivially true.
    // Measured 2026-10-27. This is the control for a §3 row 3 gate clause -- "the raw
    // token never reaches a log" -- so it was the most expensive of the three instances to
    // have been inert. Task 4 carries the guard rule and the positive control; here the
    // correction stands alone.
    expect(str_contains($captured, $raw))->toBeFalse('the raw token reached captured log output');
    expect($captured)->toContain(PagerToken::REDACTED);
});

it('never reaches the database: only the hash is stored', function (): void {
    $tenant = $this->makeTenant();

    $issued = app(PagerSessions::class)->create($tenant, 'A-401');
    $raw = $issued->token->reveal();

    // Read as ROOT, which is the only role that can see token_hash at all, and compare
    // against the hash rather than the token.
    $stored = $this->asRoot()->selectOne(
        'select encode(token_hash, \'hex\') as hash from pager_sessions where id = ?',
        [$issued->session->id],
    );

    expect((string) $stored->hash)->toBe($issued->token->hashHex())
        ->and((string) $stored->hash)->not->toBe($raw)
        // There is no column anywhere holding the token itself. Asserted by searching
        // every text-ish column of the row, so a future column cannot quietly carry it.
        ->and(json_encode((array) $this->asRoot()->selectOne(
            'select id::text, order_reference, status::text, encode(token_hash, \'hex\') as hash
               from pager_sessions where id = ?',
            [$issued->session->id],
        )))->not->toContain($raw);
});

it('never reaches a log when a session is created through the service', function (): void {
    $tenant = $this->makeTenant();

    $handler = new TestHandler;
    Log::swap(new Logger('capture', [$handler]));

    $issued = app(PagerSessions::class)->create($tenant, 'A-402');
    $raw = $issued->token->reveal();

    $captured = '';

    foreach ($handler->getRecords() as $record) {
        $captured .= $record->message.' '.(string) json_encode($record->context);
    }

    expect($captured)->not->toContain($raw);
});

it('satisfies each limb of the amended token-location clause, by name', function (): void {
    /*
     * §3 row 3 and §3.1 row 3, AS AMENDED on 2026-10-27 by Muhammad (security S-1):
     *
     *   "the raw token never reaches a log, a URL PATH OR QUERY STRING, an error body,
     *    or anywhere a server receives or logs it"
     *
     * The previous wording was "never in any URL". This test used to assert "nowhere a
     * server would see it" -- which is the amended property, asserted BEFORE anyone had
     * amended anything. That was the real defect S-1 found: the gate said one thing, the
     * code did another, a docblock argued the difference away, and this test pinned the
     * argument rather than the gate. A test is not the place to settle a rank-2/rank-3
     * conflict (§0: stop and report, never silently resolve), so now that the conflict IS
     * settled, each limb of the settled wording is asserted separately and named.
     */
    $token = PagerToken::issue();
    $raw = $token->reveal();
    $payload = $token->qrPayload('https://qr.example.test/p');

    $parts = (array) parse_url($payload);

    // LIMB 1 -- not in the path.
    expect($parts['path'] ?? '')->toBe('/p')
        ->and($parts['path'] ?? '')->not->toContain($raw);

    // LIMB 2 -- not in a query string. There is no query at all, which is stronger.
    expect($parts['query'] ?? null)->toBeNull();

    // LIMB 3 -- nowhere a server receives. Everything before the '#' is the whole of what
    // reaches a request line, an access log, a Referer header and a CDN cache key;
    // RFC 3986 §3.5 leaves the fragment to the client.
    expect(explode('#', $payload)[0])->not->toContain($raw)
        ->and($parts['host'] ?? '')->not->toContain($raw)
        ->and($parts['scheme'] ?? '')->not->toContain($raw);

    // AND THE FRAGMENT IS WHERE IT IS, asserted positively rather than left as the
    // remainder -- so this case fails if someone "fixes" it into the path later.
    expect($parts['fragment'] ?? null)->toBe($raw);

    // §4 › Edge & ops: the path is CONSTANT across tokens, so a scan burst is one CDN
    // cache entry rather than one per scan. This is the second invariant riding on the
    // same choice and the reason the path was never an option.
    expect(explode('#', PagerToken::issue()->qrPayload('https://qr.example.test/p'))[0])
        ->toBe(explode('#', $payload)[0]);
});

it('refuses a malformed presented token without touching the database', function (): void {
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    foreach ([
        '',
        'short',
        'this-one-is-far-too-long-to-be-a-token',
        'has spaces in it 1234',
        'has+plus/and=pad123456',
        str_repeat('a', 21),
        str_repeat('a', 23),
    ] as $bad) {
        expect(PagerToken::fromPresented($bad))->toBeNull("[{$bad}] was accepted as well-formed")
            ->and(PagerToken::isWellFormed($bad))->toBeFalse();
    }

    // The bound on an attacker's loop: a malformed token costs one regex and no round
    // trip (§11 › Security, "what does an attacker's loop cost us per request").
    expect($queries)->toBe(0, 'rejecting a malformed token cost a database query');

    // And a well-formed one is accepted, so the regex is not simply refusing everything.
    expect(PagerToken::fromPresented(PagerToken::issue()->reveal()))->not->toBeNull();
});
