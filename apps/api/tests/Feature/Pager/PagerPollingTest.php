<?php

declare(strict_types=1);

use App\Domain\Http\ProblemType;
use App\Domain\Pager\PagerStatus;
use App\Domain\Pager\PagerToken;
use App\Features\Pager\PagerProjection;
use App\Features\Pager\PagerSessions;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * THE POLLING ENDPOINT, which is the clause with the sharpest wording in this task's gate:
 * "polling endpoint does ZERO POSTGRES QUERIES (proven by query log) and p99 < 5 ms", and
 * `§4 › Realtime`'s "A polling endpoint that touches Postgres is a rejected design."
 *
 * THE INSTRUMENT IS A QUERY LISTENER, NOT THE POSTGRES LOG, and that is a correction
 * rather than a shortcut. QRPAGER-11 §6 says "the compose file already runs Postgres with
 * log_statement=all -- use that log as the proof, not an assertion". That became FALSE at
 * Task 3: R-004 was closed by REMOVING the cluster-wide setting, so there is no log to
 * read. PLAN.md §0 names the replacement in terms -- "the instrument Task 2 already used
 * for the same claim (a query listener over a real request, ReadinessCarrierTest)" -- and
 * that is what these cases use.
 *
 * A LISTENER IS ALSO THE STRONGER INSTRUMENT HERE. The Postgres log records statements
 * that reached the server; DB::listen() fires for every statement this process issues on
 * any connection, including ones that fail before they are sent and ones on the owner or
 * worker connection. For "zero queries" the listener's superset is what you want.
 */

/** A live session, scanned, with both Redis keys warm. Returns the raw token. */
function scannedSession(string $tenantId, ?string &$sessionId = null): string
{
    $issued = app(PagerSessions::class)->create($tenantId, 'A-'.random_int(100, 999));
    $sessionId = $issued->session->id;

    // Through the REAL resolve endpoint, because that is the only thing that writes the
    // pointer key -- a test that reached into Redis directly would be proving its own
    // fixture rather than the path a customer takes.
    test()->postJson('/v1/pager/session', ['token' => $issued->token->reveal()])->assertOk();

    return $issued->token->reveal();
}

it('issues ZERO Postgres statements on a poll, proven by a query listener', function (): void {
    $tenant = $this->makeTenant();
    $token = scannedSession($tenant);

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $response = $this->postJson('/v1/pager/status', ['token' => $token]);

    $response->assertOk();

    // The whole clause, in one assertion, with the offending SQL in the failure message
    // rather than a bare count -- because a failure here is "something reached Postgres"
    // and the only useful next question is "what".
    expect($statements)->toBe([], 'the polling endpoint reached Postgres: '.implode(' | ', $statements));
});

it('issues exactly two Redis commands in the handler, and seven across the request', function (): void {
    // THE NON-FLAKY HALF OF THE p99 CLAUSE. A timing assertion can be defeated by a loaded
    // host (F10/R-014, which cost this project a round); a command count cannot.
    //
    // THE FIRST VERSION OF THIS CASE ASSERTED TWO AND MEASURED SEVEN, and the ticket said
    // two as well -- so this is the measurement correcting the design note rather than the
    // other way round, which is the order §4a says this project most often gets wrong. The
    // HANDLER issues two; the REQUEST issues seven, because the middleware in front of it
    // is on Redis too:
    //
    //   get              ShedLoad, reading the readiness snapshot
    //   get              RateLimiter::tooManyAttempts, reading the attempt count
    //   eval, eval       RateLimiter::hit, adding the timer key and the counter key
    //   incrby           RateLimiter::hit, incrementing it
    //   get, get         THE HANDLER: the pointer, then the projection
    //
    // Both numbers are asserted, because they answer different questions. Two is the
    // design claim -- a pointer and a projection, no more -- and seven is what one poll
    // actually costs Redis, which is the figure §6.5's budget has to be computed from: at
    // the C6 ceiling that is ~42 900 polls per second and therefore ~300 000 Redis
    // operations per second, four sevenths of them rate limiting.
    $tenant = $this->makeTenant();
    $token = scannedSession($tenant);

    $commands = pollWhileCountingRedis($token, $response);

    $response->assertOk();

    expect($commands)->toBe(['get', 'get', 'eval', 'eval', 'incrby', 'get', 'get'],
        'the poll request issued: '.implode(',', $commands));

    // And the handler's own two, in isolation from the middleware, so the design claim is
    // pinned by something that does not move when the limiter's internals do.
    $handler = redisCommandsDuring(fn () => app(PagerProjection::class)->read(
        PagerToken::fromPresented($token),
        new DateTimeImmutable,
    ));

    expect($handler)->toBe(['get', 'get']);
});

it('answers 404 Stale when Redis holds no pointer, and never falls back to Postgres', function (): void {
    // THE CACHE-MISS DESIGN DECISION, asserted rather than described. A miss does not reach
    // Postgres at any cache state: a cold Redis is exactly the moment every waiting device
    // in every restaurant polls at once, so a fallback would turn a cache outage into a
    // Postgres stampede. The client's defined reaction is one call to the resolve endpoint,
    // which is rate-limited and authoritative.
    $tenant = $this->makeTenant();
    $issued = app(PagerSessions::class)->create($tenant, 'A-700');

    // Deliberately NOT scanned: a real, live, valid token with no projection behind it.
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $response = $this->postJson('/v1/pager/status', ['token' => $issued->token->reveal()]);

    $response->assertStatus(404)
        ->assertJsonPath('type', ProblemType::Stale->value);

    expect($statements)->toBe([], 'a cache miss reached Postgres: '.implode(' | ', $statements));
});

it('tells Stale apart from NotFound, so a client knows whether to re-open or give up', function (): void {
    // Both are 404 and they mean different things: Stale means "this server no longer holds
    // a live status, resolve again", NotFound means "no such thing". A client that cannot
    // tell them apart either abandons a live session or retries a dead one forever.
    $tenant = $this->makeTenant();
    $live = app(PagerSessions::class)->create($tenant, 'A-701');

    $stale = $this->postJson('/v1/pager/status', ['token' => $live->token->reveal()]);
    $unknown = $this->postJson('/v1/pager/session', ['token' => str_repeat('A', 22)]);

    expect($stale->json('type'))->toBe(ProblemType::Stale->value)
        ->and($unknown->json('type'))->toBe(ProblemType::NotFound->value)
        ->and($stale->json('type'))->not->toBe($unknown->json('type'));
});

it('spends nothing on a malformed token beyond the middleware that guards the door', function (): void {
    // What bounds a garbage-traffic loop on the highest-volume route in the product: the
    // shape check runs before anything is hashed, so the HANDLER costs one regex and no
    // I/O at all. The five commands that remain are the shedder and the limiter, which run
    // in front of every request by design -- refusing junk cheaply is what they are for.
    // Asserted as the exact list rather than "fewer than a real poll", so that a handler
    // that started touching Redis on a malformed token would be visible.
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $commands = pollWhileCountingRedis('!!!! not a token !!!!', $response);

    $response->assertStatus(422);

    expect($statements)->toBe([])
        ->and($commands)->toBe(['get', 'get', 'eval', 'eval', 'incrby'],
            'a malformed token reached the handler: '.implode(',', $commands));
});

it('answers 503 with a Retry-After when Redis is unreachable, not 404 and not 500', function (): void {
    // "Redis is taken down and the polling endpoint's behaviour is defined and tested --
    // the customer must not see a dead end." A MISS and an OUTAGE are different answers:
    // a miss means "we know nothing about this token" (re-open), an outage means "we could
    // not ask" (come back). The customer still gets their notification either way -- Web
    // Push is the primary channel and does not pass through here -- so only the live timer
    // degrades, which is the load-shedding order §4 › Edge & ops requires.
    $tenant = $this->makeTenant();
    $token = scannedSession($tenant);

    // A dead socket rather than a mocked client: the behaviour under test belongs to
    // Redis, and §6's "no DB mocks for DB behaviour" is the same rule one dependency over.
    //
    // forgetInstance('redis'), NOT purge(). RedisManager takes its config as a constructor
    // argument, so a connection purged and rebuilt reads the SAME config the manager was
    // built with and reconnects to the live server -- measured here: the first version of
    // this case purged the connection and the endpoint answered 200 from a Redis that was
    // never down. The manager itself has to go. Same mechanism ReadinessCarrierTest uses.
    config(['database.redis.default.port' => 1]);
    app()->forgetInstance('redis');

    $response = $this->postJson('/v1/pager/status', ['token' => $token]);

    $response->assertStatus(503)
        ->assertJsonPath('type', ProblemType::Unavailable->value)
        ->assertHeader('Retry-After');

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('serves the live status with the pacing a client needs, and no-store', function (): void {
    $tenant = $this->makeTenant();
    $token = scannedSession($tenant, $sessionId);

    $response = $this->postJson('/v1/pager/status', ['token' => $token]);

    $response->assertOk()
        ->assertJsonPath('status', PagerStatus::Waiting->value)
        ->assertJsonPath('poll_after_seconds', (int) config('pager.poll_interval'))
        // `no-store, private`: the controller sets no-store and the framework appends
        // private. Asserted as a substring rather than an exact string, because the
        // property is "nothing may store this", and `private` only narrows it further.
        ->assertHeaderMissing('ETag');

    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    expect($response->json())->toHaveKeys(['status', 'order_reference', 'expires_at', 'as_of', 'poll_after_seconds']);
});

it('tells the customer nothing about the tenant or the session', function (): void {
    // The poll body is what an attacker holding a stolen token reads. The holder of a
    // 128-bit token IS the customer, so the bar is "what the customer needs" -- and the
    // tenant id in particular stays server-side, because it is in the KEY.
    $tenant = $this->makeTenant();
    $token = scannedSession($tenant, $sessionId);

    $body = $this->postJson('/v1/pager/status', ['token' => $token])->getContent();

    expect($body)->not->toContain($tenant)
        ->and($body)->not->toContain((string) $sessionId)
        ->and($body)->not->toContain($token);
});

it('follows a transition within a poll, without the worker having run', function (): void {
    // The live-status fallback has to be live. PagerSessions refreshes the projection after
    // the transaction commits, so a customer polling sees `ready` immediately rather than
    // after the next outbox pass -- which matters most when the push never arrives, which
    // is the case polling exists for.
    $tenant = $this->makeTenant();
    $token = scannedSession($tenant, $sessionId);

    app(PagerSessions::class)->markReady($tenant, (string) $sessionId);

    $this->postJson('/v1/pager/status', ['token' => $token])
        ->assertOk()
        ->assertJsonPath('status', PagerStatus::Ready->value);
});

it('follows a completion too, which writes no outbox row and so no worker ever sees it', function (): void {
    // `complete` deliberately enqueues nothing (the customer is at the counter). If the
    // projection were refreshed only by the outbox worker, a completed order would poll as
    // `waiting` until its TTL ran out. This is the case that makes the service-side refresh
    // necessary rather than belt-and-braces.
    $tenant = $this->makeTenant();
    $token = scannedSession($tenant, $sessionId);

    app(PagerSessions::class)->complete($tenant, (string) $sessionId);

    $this->postJson('/v1/pager/status', ['token' => $token])
        ->assertOk()
        ->assertJsonPath('status', PagerStatus::Completed->value);
});

it('creates no projection for a session nobody scanned', function (): void {
    // PagerProjection::refresh() is XX -- update-only -- so the projection's lifetime belongs
    // to the SCAN and not to the order. That is what keeps the keyspace bounded by the
    // waiting population rather than by the order volume, which is §6.5's figure.
    $tenant = $this->makeTenant();
    $issued = app(PagerSessions::class)->create($tenant, 'A-702');

    app(PagerSessions::class)->markReady($tenant, $issued->session->id);

    $key = PagerProjection::projectionKey($tenant, $issued->session->id);

    expect(app('redis')->connection()->exists($key))->toBe(0);
});

it('treats a corrupt projection as a miss rather than a 500', function (): void {
    // The one thing a Redis-only endpoint must not do is turn a cache problem into an error
    // on a screen a customer is staring at while they wait. A payload this build cannot
    // decode -- a future version, truncated JSON, a status that is not in the enum -- is a
    // miss, and the client re-opens against Postgres.
    $tenant = $this->makeTenant();
    $token = scannedSession($tenant, $sessionId);
    $key = PagerProjection::projectionKey($tenant, (string) $sessionId);

    foreach (['not json at all', '{"v":99,"s":"ready"}', '{"v":1,"s":"invented-status","o":"A","x":"x","a":"a"}'] as $corrupt) {
        app('redis')->connection()->set($key, $corrupt);

        $this->postJson('/v1/pager/status', ['token' => $token])
            ->assertStatus(404)
            ->assertJsonPath('type', ProblemType::Stale->value);
    }
});

/**
 * Drive one poll while counting the Redis commands it issues.
 *
 * enableEvents() has to run BEFORE the connection is resolved -- RedisManager sets a flag
 * that only connections built afterwards read, so enabling it on an already-resolved
 * manager counts nothing and the test passes vacuously. Measured 2026-10-27: enabling
 * after a connection existed saw zero commands for a request that issued four.
 *
 * @param  mixed  $response  filled with the TestResponse, by reference
 * @return list<string>
 */
function pollWhileCountingRedis(string $token, &$response): array
{
    $commands = [];

    app('redis')->enableEvents();
    foreach (array_keys(app('redis')->connections()) as $name) {
        app('redis')->purge((string) $name);
    }

    Event::listen(CommandExecuted::class, function (CommandExecuted $event) use (&$commands): void {
        $commands[] = $event->command;
    });

    $response = test()->postJson('/v1/pager/status', ['token' => $token]);

    return $commands;
}

/**
 * Count the Redis commands one closure issues, with nothing else in the picture.
 *
 * The same enableEvents()-before-resolve constraint as pollWhileCountingRedis(), for the
 * same measured reason.
 *
 * @return list<string>
 */
function redisCommandsDuring(Closure $work): array
{
    $commands = [];

    app('redis')->enableEvents();
    foreach (array_keys(app('redis')->connections()) as $name) {
        app('redis')->purge((string) $name);
    }

    Event::listen(CommandExecuted::class, function (CommandExecuted $event) use (&$commands): void {
        $commands[] = $event->command;
    });

    $work();

    return $commands;
}
