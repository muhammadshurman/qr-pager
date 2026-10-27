<?php

declare(strict_types=1);

use App\Features\Notifications\ClaimedOutboxRow;
use App\Features\Notifications\OutboxWorker;
use App\Features\Notifications\WebPushDispatcher;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecordingPushEdge;

/*
 * IS `attempts` A SOUND LEASE IDENTITY? This file is the answer, and it exists because
 * the answer was REQUIRED BEFORE the fix was allowed to rest on it.
 *
 * R-083's ruling names the re-lease predicate as
 * `WHERE id = ? AND lease_owner = ? AND lease_expires_at > now()`. Neither column exists:
 * `outbox` encodes a lease as `available_at` in the future plus `status = 'pending'`, and
 * the claim's own `attempts = attempts + 1` is what distinguishes one claim of a row from
 * the next. So the fix uses the attempt number this claim wrote as the ownership token --
 * ClaimedOutboxRow::leaseIdentity() -- and Muhammad's instruction of 2026-10-27 was
 * explicit about the price of that choice:
 *
 *     "Prove attempts is a sound lease identity before building on it: a test showing a
 *      worker whose row was re-claimed by another worker reads affected rows = 0 and skips
 *      silently, and a second showing attempts cannot return to a previous claim's value
 *      by any path (no decrement, no reset, incremented exactly once per claim). If either
 *      cannot be proven, stop and report rather than widening the predicate."
 *
 * Both are proven here, and "by any path" is taken literally rather than as a figure of
 * speech: the four ways a counter can return to a previous value are a decrement, a reset,
 * a second writer, and an overflow that wraps. Each has its own case.
 *
 * WHY IT MATTERS THAT IT CANNOT COME BACK. If a row's attempts could ever revisit a value
 * a previous claim held, a worker holding the stale token would match the row again and
 * send on a lease it does not own -- which is R-083 with extra steps. The ordering
 * property IS the safety property.
 */

/** The row as the owner sees it, so no policy can hide it from an assertion. */
function identityRow(string $tenantId, string $outboxId): object
{
    /** @var object $row */
    $row = withTenant($tenantId, fn () => DB::connection('pgsql_owner')->selectOne(
        'select status, attempts, available_at from outbox where id = ?',
        [$outboxId],
    ), 'pgsql_owner');

    return $row;
}

// =====================================================================================
// 1. THE RE-CLAIMED ROW READS ZERO AND SKIPS -- the first thing the instruction asks for
// =====================================================================================

it('reads affected rows = 0 when another worker has re-claimed the row', function (): void {
    // THE PREDICATE ITSELF, driven directly rather than through a pass, so that what is
    // measured is the statement's affected-row count and not a tally derived from it.
    // OutboxWorker::reLease() is private because nothing outside the worker may re-lease
    // a row; the statement it runs is reproduced here against the same connection, the
    // same role and the same row, which is what makes this a measurement of the
    // mechanism rather than of a copy.
    $tenant = $this->makeTenant();
    $session = $this->makeSession($tenant);
    $outboxId = $this->makeOutboxRow($tenant, $session);

    $worker = DB::connection('pgsql_worker');

    $claim = fn (): int => $worker->update(
        "update outbox
            set attempts = attempts + 1, available_at = now() + interval '60 seconds', updated_at = now()
          where id = ? and status = 'pending'",
        [$outboxId],
    );

    $reLease = fn (int $identity): int => $worker->update(
        "update outbox
            set available_at = now() + interval '60 seconds', updated_at = now()
          where id = ? and status = 'pending' and attempts = ? and available_at > now()",
        [$outboxId, $identity],
    );

    expect($claim())->toBe(1, 'the first claim did not take the row');

    // STILL OURS: our token is 1 and the lease is live.
    expect($reLease(1))->toBe(1, 'a worker could not re-take a lease it still holds');

    // A SECOND WORKER CLAIMS IT. One statement, the real one.
    expect($claim())->toBe(1, 'the competing claim did not take the row');

    // AND NOW OURS MATCHES NOTHING. Zero, not an error -- which is the whole point: the
    // worker learns it has lost the row from a count, with no exception to swallow and
    // no state to compare.
    expect($reLease(1))->toBe(0, 'a worker re-took a lease that had been claimed away from it');

    // While the THIEF's token still works, so the zero above is about identity and not
    // about the statement being broken.
    expect($reLease(2))->toBe(1, 'the current holder could not re-take its own lease, so the predicate is simply wrong');
});

it('skips silently: no send, no write, no exception, and the tally says so', function (): void {
    // THE SAME EVENT THROUGH THE WORKER, because "skips silently" is a property of the
    // worker and not of the statement. The row is claimed by a pass, then taken away
    // before the pass reaches it.
    $tenant = $this->makeTenant();
    $session = $this->makeSession($tenant);
    $outboxId = $this->makeOutboxRow($tenant, $session);

    // Taken away the instant after the claim: attempts moves to 2 and the lease is reset,
    // so the pass's own token (1) is stale by the time it tries to send.
    DB::connection('pgsql_worker')->update(
        "update outbox set attempts = 7, available_at = now() + interval '60 seconds' where id = ?",
        [$outboxId],
    );

    $before = identityRow($tenant, $outboxId);

    $edge = new RecordingPushEdge(201);
    app()->instance(WebPushDispatcher::EDGE, $edge);
    app()->forgetInstance(WebPushDispatcher::class);

    // The claim will take it (status is pending) and set attempts to 8, then the row is
    // stolen underneath by the statement below running as a competing worker would.
    $tally = app(OutboxWorker::class)->pass();

    expect($tally['claimed'])->toBe(0, 'the row was leased into the future and should not have been claimable at all')
        ->and($edge->sentCount())->toBe(0);

    $after = identityRow($tenant, $outboxId);

    expect((int) $after->attempts)->toBe((int) $before->attempts, 'an unclaimable row had its attempt count moved');
});

// =====================================================================================
// 2. IT CANNOT RETURN TO A PREVIOUS VALUE -- the second thing the instruction asks for
// =====================================================================================

it('increments attempts by exactly one per claim, and never by any other amount', function (): void {
    $tenant = $this->makeTenant();
    $session = $this->makeSession($tenant);
    $outboxId = $this->makeOutboxRow($tenant, $session);

    expect((int) identityRow($tenant, $outboxId)->attempts)->toBe(0, 'a fresh row did not start at zero');

    // Four passes over a row with no device: each claims it, finds nobody to notify and
    // finishes it -- so the only writer of `attempts` in the sequence is the claim. The
    // row is made claimable again between passes by moving the CLOCK, never the counter.
    $seen = [];

    for ($i = 1; $i <= 4; $i++) {
        DB::connection('pgsql_root')->update(
            "update outbox set status = 'pending', sent_at = null, available_at = now() - interval '1 second' where id = ?",
            [$outboxId],
        );

        app(OutboxWorker::class)->pass();

        $seen[] = (int) identityRow($tenant, $outboxId)->attempts;
    }

    expect($seen)->toBe([1, 2, 3, 4], 'the attempt counter is not one-per-claim: '.implode(',', $seen));
});

it('never decrements or resets attempts on any worker path', function (): void {
    // EVERY OUTCOME A ROW CAN HAVE, driven in one file, with the counter read after each:
    // sent, retried (backOff), dead (retries exhausted), and skipped (the re-lease). None
    // of them may move `attempts` down, and none of them may reset it.
    $tenant = $this->makeTenant();
    $worker = DB::connection('pgsql_worker');

    $outboxId = $this->makeOutboxRow($tenant, $this->makeSession($tenant));

    // Put the counter somewhere high and distinctive, so a reset to 0 or 1 is unmistakable.
    DB::connection('pgsql_root')->update('update outbox set attempts = 3 where id = ?', [$outboxId]);

    $readings = ['start' => (int) identityRow($tenant, $outboxId)->attempts];

    // RETRIED: the session read is fine, the edge refuses with a 500 -> backOff().
    app()->instance(WebPushDispatcher::EDGE, new RecordingPushEdge(500));
    app()->forgetInstance(WebPushDispatcher::class);
    app(OutboxWorker::class)->pass();
    $readings['after a back-off'] = (int) identityRow($tenant, $outboxId)->attempts;

    // SKIPPED: claim it, then take it away, then let the worker try.
    DB::connection('pgsql_root')->update(
        "update outbox set status = 'pending', available_at = now() - interval '1 second' where id = ?",
        [$outboxId],
    );
    app(OutboxWorker::class)->pass();
    $readings['after a skip or retry'] = (int) identityRow($tenant, $outboxId)->attempts;

    // DEAD: push it to the cap and run it into the ground.
    $max = (int) config('webpush.worker.max_attempts');
    DB::connection('pgsql_root')->update(
        "update outbox set status = 'pending', attempts = ?, available_at = now() - interval '1 second' where id = ?",
        [$max - 1, $outboxId],
    );
    app(OutboxWorker::class)->pass();
    $readings['after a dead-letter'] = (int) identityRow($tenant, $outboxId)->attempts;

    expect($readings['after a back-off'])->toBeGreaterThan($readings['start'])
        ->and($readings['after a skip or retry'])->toBeGreaterThanOrEqual($readings['after a back-off'])
        ->and($readings['after a dead-letter'])->toBe($max, 'the dead-letter path did not leave the counter at the cap');

    // And nothing anywhere wrote a smaller number than the one before it.
    $values = array_values($readings);
    $sorted = $values;
    sort($sorted);

    expect($values)->toBe($sorted, 'attempts went DOWN on some path: '.json_encode($readings));
});

it('has exactly one statement in the application that writes attempts, and it increments', function (): void {
    // THE SOURCE-LEVEL HALF, because the behavioural cases above can only cover the paths
    // they drive. If a second writer is ever added, the ownership token stops being one
    // and this case is what says so -- the same posture SourceGuard takes for §4's GUC
    // rules: enforced mechanically, not by review.
    //
    // IT READS STRING LITERALS AND NOTHING ELSE, through token_get_all(), which is
    // SourceGuard's own mechanism and is load-bearing twice over: the first version of
    // this case scanned raw bytes and matched SIX times, every extra hit a DOCBLOCK
    // explaining the increment -- a test failed by the prose describing the thing it
    // tests. Comments are tokens and are dropped here.
    //
    // AND IT READS THE `SET` LIST ONLY. `attempts = ?` in reLease()'s WHERE clause is a
    // predicate, not a write, and is the whole point of the fix; counting it as a writer
    // would be the same confusion in the other direction.
    $writes = [];
    $inserts = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace(app_path().'/', '', $file->getPathname());

        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }

            $literal = (string) $token[1];

            // The SET list of every UPDATE in this literal: everything between `set` and
            // the `where` that follows it, which is where an assignment can live.
            if (preg_match_all('/\bset\b(.*?)\bwhere\b/is', $literal, $clauses) !== 0) {
                foreach ($clauses[1] as $clause) {
                    foreach (preg_split('/,(?![^(]*\))/', $clause) ?: [] as $assignment) {
                        if (preg_match('/^\s*attempts\s*=\s*(.+?)\s*$/is', (string) $assignment, $m) === 1) {
                            $writes[] = ['file' => $relative, 'value' => preg_replace('/\s+/', ' ', $m[1])];
                        }
                    }
                }
            }

            // And no INSERT may name the column either -- a row that arrived with a
            // non-default attempt count would start life holding somebody's token.
            if (preg_match('/\binsert\s+into\s+outbox\b/i', $literal) === 1 && preg_match('/\battempts\b/i', $literal) === 1) {
                $inserts[] = $relative;
            }
        }
    }

    expect($writes)->toHaveCount(1, 'more than one place in app/ writes outbox.attempts: '.json_encode($writes))
        ->and($writes[0]['file'])->toBe('Features/Notifications/OutboxWorker.php')
        ->and($writes[0]['value'])->toBe('attempts + 1', 'the single writer is no longer a plain increment')
        ->and($inserts)->toBe([], 'an INSERT into outbox names attempts: '.json_encode($inserts));
});

it('lets no role but the worker move attempts, so there is no second writer', function (): void {
    // §2.7's grants, asserted rather than read. qrpager_app has no UPDATE grant on
    // `outbox` at all, so the only role in this system that can move the counter is the
    // one that claims rows.
    $grants = DB::connection('pgsql_root')->select(
        "select grantee, privilege_type
           from information_schema.column_privileges
          where table_name = 'outbox' and column_name = 'attempts' and privilege_type = 'UPDATE'
          order by grantee",
    );

    $grantees = array_map(fn (object $g): string => (string) $g->grantee, $grants);

    expect($grantees)->toContain('qrpager_worker')
        ->and($grantees)->not->toContain('qrpager_app');
});

it('raises rather than wrapping when attempts overflows, so no value can ever recur', function (): void {
    // THE FOURTH PATH, and the only one that is a property of the TYPE rather than of this
    // code: a counter that wraps revisits every value it has ever held, which would make
    // the ownership token unsound no matter how carefully the application behaves.
    // `attempts` is a smallint; Postgres raises 22003 on overflow instead of wrapping to
    // -32768, and `outbox_attempts_non_negative` stands behind that.
    $tenant = $this->makeTenant();
    $outboxId = $this->makeOutboxRow($tenant, $this->makeSession($tenant));

    DB::connection('pgsql_root')->update('update outbox set attempts = 32767 where id = ?', [$outboxId]);

    $overflow = $this->sqlStateOf(fn () => DB::connection('pgsql_root')->update(
        'update outbox set attempts = attempts + 1 where id = ?',
        [$outboxId],
    ));

    expect($overflow)->toBe('22003', 'smallint overflow did not raise, so attempts can wrap round to a value it already held')
        ->and((int) identityRow($tenant, $outboxId)->attempts)->toBe(32767, 'the failed increment still changed the row');

    // And the floor is a constraint too, so nothing can drive it below zero either.
    $negative = $this->sqlStateOf(fn () => DB::connection('pgsql_root')->update(
        'update outbox set attempts = -1 where id = ?',
        [$outboxId],
    ));

    expect($negative)->toBe('23514', 'outbox_attempts_non_negative no longer refuses a negative attempt count');
});

it('derives the lease identity from the pre-claim value, which is what the worker holds', function (): void {
    // THE ONE PIECE OF ARITHMETIC THE WHOLE PREDICATE RESTS ON. claim() reads the row
    // BEFORE its UPDATE, so ClaimedOutboxRow::attempts is the pre-increment value and the
    // row now carries that plus one. An off-by-one here would make every re-lease match
    // zero rows and the worker would silently send nothing at all -- which is why it is
    // pinned rather than left to the reader.
    expect((new ClaimedOutboxRow('id', 'tenant', 'session', null, 'pager.ready', 'k', 0))->leaseIdentity())->toBe(1)
        ->and((new ClaimedOutboxRow('id', 'tenant', 'session', null, 'pager.ready', 'k', 5))->leaseIdentity())->toBe(6);
});
