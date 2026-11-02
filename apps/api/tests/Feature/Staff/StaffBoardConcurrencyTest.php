<?php

declare(strict_types=1);

use App\Domain\Pager\PagerAuditAction;
use App\Domain\Pager\PagerEvent;
use App\Domain\Pager\PagerStatus;
use App\Domain\Tenancy\TenantRole;
use App\Models\User;
use App\Platform\Auth\ActiveTenant;
use App\Platform\Auth\Memberships;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * 10b's NAMED FALSIFIABLE INVARIANT, AND IT IS THE ONLY THING THIS FILE IS ABOUT
 * ================================================================================
 *
 * `QRPAGER-26 §0`, written before the work:
 *
 *   "Two staff marking the same session ready at the same instant produce exactly ONE
 *    customer notification and ONE terminal state, and both boards converge on it within
 *    one poll interval. Asserted by driving two concurrent clients against the real API."
 *
 * ⚠ **WHY THIS IS NOT `PagerIdempotencyTest` AGAIN, because the two look alike and the
 * difference is the whole point.** That file drives `PagerSessions::markReady()` — the
 * SERVICE — in two processes, and proves Task 3's gate clause. This ticket's clause
 * explicitly refuses to stand on that: *"Never by trusting Task 3's idempotency from the
 * client side."* So what is driven here is the whole request path two real clients take,
 * with their own sessions, through every middleware on the `/v1` group:
 *
 *     ShedLoad → RateLimit → EncryptCookies → StartSession → ValidateCsrfToken
 *              → ResolvePrincipal → RequireTenantScope → RequireAbility
 *              → PagerSessionController::ready → PagerSessions::markReady
 *
 * Each of those is somewhere two concurrent callers could be handled differently from two
 * sequential ones — a limiter keyed per tenant, a resolver memoised per request, an
 * ability check that reads a membership — and a service-level proof says nothing about any
 * of them.
 *
 * **AND THEY ARE TWO DIFFERENT PEOPLE**, not one person's client sending twice. Two staff
 * members with two memberships in the same restaurant and two browser sessions, which is
 * the situation the gate clause describes: a kitchen and a counter both reaching for the
 * same order.
 *
 * ================================================================================
 * `SESSION_DRIVER=redis` FOR THE CHILDREN, AND WHY THE SUITE'S OWN DEFAULT CANNOT DO
 * ================================================================================
 *
 * `phpunit.xml` sets `SESSION_DRIVER=array`, which is right for the suite and useless
 * here: an array session lives in one process's memory, so a child could not present a
 * session this test could see, and two children could not present their own. The children
 * therefore run with the production driver (`§1` locks Redis as the session store), each
 * writing its own session and computing its own cookie — see
 * `tests/Support/mark-ready-over-http-in-another-process.php`, which has the cookie
 * mechanics and why the `serialize: false` argument is not optional.
 */

/** A second staff member in an existing tenant. Returns their user id. */
function staffMemberIn(string $tenantId, TenantRole $role = TenantRole::Staff): string
{
    $userId = (string) Str::uuid7();

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $userId,
        'name' => 'Board Staff',
        // The WHOLE uuid, never a prefix: a v7 uuid's first 48 bits are a millisecond
        // timestamp, so two made in one millisecond share their first twelve characters
        // exactly and collide on `users_email_unique`. `TestCase::actingForTenant` records
        // the measurement -- 2 distinct emails out of 200 -- and this helper obeys it.
        'email' => 'board-'.str_replace('-', '', $userId).'@example.test',
    ]);

    withTenant($tenantId, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'role' => $role->value,
    ]), 'pgsql_owner');

    return $userId;
}

/** @return array{outbox: int, audit: int, status: string} */
function boardRaceCounts(string $tenantId, string $sessionId): array
{
    return withTenant($tenantId, fn (): array => [
        'outbox' => DB::table('outbox')
            ->where('pager_session_id', $sessionId)
            ->where('type', PagerEvent::Ready->value)
            ->count(),
        'audit' => DB::table('audit_log')
            ->where('tenant_id', $tenantId)
            ->where('subject_id', $sessionId)
            // The enum, never the literal: `PagerAuditAction::Ready->value` is
            // `pager.session.ready`, and a hand-written `'pager.ready'` matched nothing
            // and reported 0 audit rows against a correct transition -- which is a
            // vacuous assertion wearing a failure, caught on this file's first run.
            ->where('action', PagerAuditAction::Ready->value)
            ->count(),
        'status' => (string) DB::table('pager_sessions')->where('id', $sessionId)->value('status'),
    ]);
}

it('produces one notification and one terminal state when two staff clients mark the same order ready at the same instant', function (): void {
    /*
     * ⚠ THREE ATTEMPTS, FOR `MemberConcurrencyTest`'s MEASURED REASON RATHER THAN FOR
     * BELT AND BRACES. That test's `§11` review reverted its lock and ran the file five
     * times: red, GREEN, red, red, red. A guard on a state with no in-product repair that
     * passes one run in five against broken code is not a guard. The start barrier takes
     * each child's ~150 ms boot OUT of the race window, and three attempts put a
     * per-attempt miss rate of even one in five under 1%. With the row lock in place every
     * attempt is deterministic, so this costs nothing in the green direction.
     */
    foreach (range(1, 3) as $attempt) {
        $tenant = $this->makeTenant();
        $session = $this->makeSession($tenant);

        $kitchen = staffMemberIn($tenant);
        $counter = staffMemberIn($tenant);

        $script = base_path('tests/Support/mark-ready-over-http-in-another-process.php');
        $database = (string) config('database.connections.pgsql.database');
        $barrier = sys_get_temp_dir().'/qrpager-board-race-'.Str::random(12);

        // Merged, not replaced: `proc_open`'s $env REPLACES the environment, so a bare
        // array loses PATH and everything .env resolution needs -- and a child that
        // produces no output at all reads as a concurrency result rather than as a broken
        // launch. The lesson is `PagerIdempotencyTest`'s, paid for once already.
        //
        // SESSION_DRIVER and the two Redis databases are passed explicitly so a slotted
        // reviewer worktree (`§0.2` (b)) keeps its own keyspace: two slots sharing Redis
        // db 0 would be two suites writing each other's sessions.
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_DATABASE' => $database,
            'SESSION_DRIVER' => 'redis',
            'PAO_DISABLE' => '1',
        ]);

        $children = [];

        foreach ([$kitchen, $counter] as $i => $userId) {
            $children[$i] = ['handle' => proc_open(
                ['php', $script, $tenant, $session, $userId, $barrier],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $children[$i]['pipes'],
                base_path(),
                $env,
            ), 'pipes' => $children[$i]['pipes'] ?? []];

            expect($children[$i]['handle'])->not->toBeFalse("could not start process {$i}");
        }

        // BOTH BOOTED, both sessions written, both cookies computed, and only then
        // released -- so the window contains two HTTP requests and nothing else.
        //
        // ⚠ THROWS RATHER THAN ASSERTS INSIDE THE SPIN. An `expect()` in a spin loop runs
        // an unpredictable number of times and makes this file's assertion count vary run
        // to run; `§4a` publishes "N passed / M assertions" at a commit, and a
        // non-reproducible M makes that figure meaningless. Measured on
        // `MemberConcurrencyTest` at 248/253/259/264 over four green runs before its own
        // fix.
        $deadline = microtime(true) + 30.0;

        while (count(glob($barrier.'.ready.*') ?: []) < 2) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('the two clients never both reached the barrier');
            }

            usleep(1000);
        }

        touch($barrier.'.go');

        $outcomes = [];

        foreach ($children as $child) {
            $outcomes[] = trim((string) stream_get_contents($child['pipes'][1]));
            $stderr = trim((string) stream_get_contents($child['pipes'][2]));
            fclose($child['pipes'][1]);
            fclose($child['pipes'][2]);
            proc_close($child['handle']);

            expect($stderr)->toBe('', "a client process wrote to stderr: {$stderr}");
        }

        foreach (glob($barrier.'*') ?: [] as $leftover) {
            @unlink($leftover);
        }

        sort($outcomes);

        /*
         * ⚠ BOTH CLIENTS GOT A 200, AND THAT IS ASSERTED BEFORE THE COUNTS. A 401 would
         * mean the cookie mechanics in the child are wrong, a 403 that the ability check
         * is, a 429 that the two clients collided in the limiter -- and every one of those
         * would ALSO leave exactly one outbox row, so the counts below would pass against
         * a test that proved nothing. This assertion is what makes the rest of the file
         * mean what it says.
         */
        expect($outcomes)->toBe(['http:200:applied', 'http:200:idempotent'],
            "attempt {$attempt}: expected one applied and one idempotent 200, got: ".implode(', ', $outcomes),
        );

        $counts = boardRaceCounts($tenant, $session);

        // EXACTLY ONE NOTIFICATION. `outbox` is the only place a dispatch is visible,
        // because the worker is the only sender (C5), so this is the clause "exactly ONE
        // customer notification" measured where it is true rather than where it is
        // convenient.
        expect($counts['outbox'])->toBe(1,
            "attempt {$attempt}: two concurrent staff clients produced {$counts['outbox']} notifications",
        );

        // ONE TERMINAL STATE, and one audit row for it.
        expect($counts['status'])->toBe(PagerStatus::Ready->value,
            "attempt {$attempt}: the session did not end in one ready state",
        )->and($counts['audit'])->toBe(1,
            "attempt {$attempt}: two concurrent staff clients produced {$counts['audit']} audit rows",
        );

        /*
         * AND BOTH BOARDS CONVERGE. One poll interval later is, for a board that holds no
         * state between polls, simply the next read -- so the convergence claim is checked
         * by reading the board as EACH of the two staff members and requiring the same
         * answer: the session present exactly once, and ready.
         *
         * Read sequentially and deliberately: convergence is not a concurrency property,
         * and racing the reads would test the children's harness again rather than the
         * boards.
         */
        foreach ([$kitchen, $counter] as $userId) {
            $this->actingAs(User::query()->findOrFail($userId));
            // The request-scoped membership reader memoises per user id, and this test has
            // authenticated more than once -- `TestCase::actingForTenant` forgets it for
            // the same reason.
            $this->app->forgetInstance(Memberships::class);
            session()->put(ActiveTenant::SESSION_KEY, $tenant);

            $rows = $this->getJson('/v1/pager-sessions')->assertOk()->json('sessions');

            $mine = array_values(array_filter(
                is_array($rows) ? $rows : [],
                static fn (mixed $row): bool => is_array($row) && ($row['id'] ?? null) === $session,
            ));

            expect($mine)->toHaveCount(1,
                "attempt {$attempt}: the board showed the session ".count($mine).' times for one staff member',
            )->and($mine[0]['status'])->toBe(PagerStatus::Ready->value,
                "attempt {$attempt}: a staff member's board did not converge on ready",
            );
        }
    }
});
