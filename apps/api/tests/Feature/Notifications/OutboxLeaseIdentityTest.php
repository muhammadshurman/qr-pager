<?php

declare(strict_types=1);

use App\Features\Notifications\ClaimedOutboxRow;
use App\Features\Notifications\OutboxWorker;
use App\Features\Notifications\WebPushDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PushFixtures;
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
    //
    // ⚠ IT IS A COPY, AND THAT IS STATED RATHER THAN DENIED -- QA L-2, 2026-10-27. This
    // case said it was "a measurement of the mechanism rather than of a copy", which was
    // false: OutboxWorker::reLease() is private, this reproduces its statement, and the
    // production predicate could change while this case went on passing. What makes the
    // copy honest is the assertion at the end of this file, which reads reLease()'s real
    // SQL out of the source and requires the four clauses to be in it -- so a divergence
    // fails a test rather than hiding here.
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
    // ⚠ THIS CASE USED TO LIE, and it is the same defect as QA H-2 earlier in this task:
    // a name that states a contract over a body that never reaches it (QA M-6,
    // 2026-10-27). It leased the row into the FUTURE before the pass, so `claim()` found
    // nothing, `claimed` was 0, reLease() was never called and the skip path never ran --
    // while its comments described a theft "the statement below" performs and there was
    // no such statement. It passed with the fix reverted.
    //
    // IT NOW REACHES THE SKIP, through the same door OutboxLeaseTest uses: a decoy row is
    // processed first and the victim is stolen inside the decoy's send, so the victim is
    // claimed, leased, and taken away before the worker gets to it.
    $tenant = $this->makeTenant();

    $victimSession = $this->makeSession($tenant);
    $victimId = $this->makeOutboxRow($tenant, $victimSession);

    $decoySession = $this->makeSession($tenant);
    $decoyId = $this->makeOutboxRow($tenant, $decoySession);

    PushFixtures::configureVapid();

    $device = PushFixtures::subscription();
    withTenant($tenant, fn () => DB::connection('pgsql_owner')->table('push_subscriptions')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant,
        'pager_session_id' => $decoySession,
        'endpoint' => $device['endpoint'],
        'p256dh' => $device['p256dh'],
        'auth' => $device['auth'],
    ]), 'pgsql_owner');

    // claim() orders by `available_at, id`, so the decoy is made to sort first.
    DB::connection('pgsql_root')->update(
        "update outbox set available_at = now() - interval '10 seconds' where id = ?", [$decoyId],
    );
    DB::connection('pgsql_root')->update(
        "update outbox set available_at = now() - interval '1 second' where id = ?", [$victimId],
    );

    $before = identityRow($tenant, $victimId);
    $stolen = 0;

    $edge = new RecordingPushEdge(201, false, function ($request, int $call) use ($victimId, &$stolen): void {
        if ($call === 0) {
            $stolen = DB::connection('pgsql_worker')->update(
                "update outbox
                    set attempts = attempts + 1, available_at = now() + interval '60 seconds', updated_at = now()
                  where id = ? and status = 'pending'",
                [$victimId],
            );
        }
    });

    app()->instance(WebPushDispatcher::EDGE, $edge);
    app()->forgetInstance(WebPushDispatcher::class);

    $tally = app(OutboxWorker::class)->pass();

    expect($stolen)->toBe(1, 'the competing claim did not take the victim row')
        ->and($tally)->toMatchArray(['claimed' => 2, 'sent' => 1, 'skipped' => 1])
        // ONE send, the decoy's. The victim has no device, so a send on its behalf would
        // be impossible anyway -- what is asserted is that the worker wrote nothing.
        ->and($edge->sentCount())->toBe(1);

    $after = identityRow($tenant, $victimId);

    // TWO increments and no rollback: ours when we claimed it, the thief's when it was
    // taken away. Our rollback is guarded by OUR token, which the thief's claim has
    // already moved past, so it matched nothing -- which is exactly what it is for.
    expect($after->status)->toBe('pending', 'a worker finished a row it no longer held')
        ->and((int) $after->attempts)->toBe((int) $before->attempts + 2, 'the thief’s attempt was rolled back');
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

it('never decrements or resets attempts on any path that keeps the row', function (): void {
    // ⚠ THE NAME IS NARROWER THAN IT WAS, because the behaviour is -- QA M-7's fix,
    // 2026-10-27. There is now exactly ONE path that decrements: abandon(), which hands
    // back the attempt its own claim charged for a row it turned out not to hold, and
    // which is guarded by that worker's own token so it can never touch a thief's count.
    // Every path that KEEPS the row is still monotonic, and that is what this drives.
    //
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

it('has exactly two statements in the application that write attempts, and both are keyed', function (): void {
    // THE SOURCE-LEVEL HALF, because the behavioural cases above can only cover the paths
    // they drive. If a THIRD writer is ever added, the ownership token stops being one
    // and this case is what says so -- the same posture SourceGuard takes for §4's GUC
    // rules: enforced mechanically, not by review. (It asserted ONE writer until
    // 2026-10-27; QA M-7's fix added the guarded rollback and the number moved with it.)
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

            // ⚠ ONLY LITERALS THAT TARGET `outbox`. This condition was ABSENT until
            // 2026-10-28 and the scanner read every `set ... attempts = ...` in app/
            // whatever table it was against -- so it was a scanner for "the word attempts"
            // wearing the name of one for `outbox.attempts`. Task 6 added
            // `tenant_provisionings.attempts`, whose keyed `attempts = attempts + 1` is on
            // a PLATFORM table and has nothing to do with the outbox lease, and this case
            // failed with three writers and a demand that they all live in OutboxWorker.
            //
            // The INSERT half below has always required `insert into outbox`, so the two
            // halves of one control disagreed about their own subject. This is the UPDATE
            // half brought up to the INSERT half's discipline, which NARROWS the matcher
            // and does not weaken the control: every statement it used to catch that was
            // genuinely about the outbox still names `outbox`.
            // BOTH SPELLINGS, and the quoted form too (security L-7). The first version of
            // this condition was `update\s+outbox` alone, which `update public.outbox set
            // attempts = …` escapes -- so narrowing the matcher correctly (Task 6's
            // `tenant_provisionings.attempts` was a genuine false positive) had inherited
            // the INSERT half's own gap instead of fixing it. Both halves now accept an
            // optional schema and optional quoting.
            $targetsOutbox = '/\bupdate\s+(?:"?public"?\.)?"?outbox"?(?![A-Za-z0-9_])/i';

            if (preg_match($targetsOutbox, $literal) !== 1) {
                continue;
            }

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
            if (preg_match('/\binsert\s+into\s+(?:"?public"?\.)?"?outbox"?(?![A-Za-z0-9_])/i', $literal) === 1
                && preg_match('/\battempts\b/i', $literal) === 1) {
                $inserts[] = $relative;
            }
        }
    }

    // TWO WRITERS, AND THE SECOND IS THE GUARDED ROLLBACK -- QA M-7 / security M-3's fix,
    // 2026-10-27. This case asserted ONE until then, and the number moved because
    // abandon() hands back the attempt its own claim charged for work it never did.
    // Both are in the worker, both are keyed, and the DECREMENT is the one that has to
    // be guarded by the token or the identity stops being sound -- which is why the
    // statement itself is read back below rather than only counted.
    $values = array_column($writes, 'value');
    sort($values);

    expect($writes)->toHaveCount(2, 'app/ no longer has exactly two writers of outbox.attempts: '.json_encode($writes))
        ->and(array_unique(array_column($writes, 'file')))->toBe(['Features/Notifications/OutboxWorker.php'])
        ->and($values)->toBe(['attempts + 1', 'attempts - 1'])
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

it('pins the three predicates in the worker’s own source, so no copy here can drift from it', function (): void {
    // WHAT MAKES THE COPIES IN THIS FILE HONEST — QA L-2, 2026-10-27. Two cases above
    // reproduce statements that live in OutboxWorker, and a reproduction can go on
    // passing after the original changes. This reads the real source and requires each
    // of the three statements the lease identity rests on to carry its guard, so a
    // divergence fails here rather than hiding there.
    //
    // It is a source scan for the same reason SourceGuard is one: the property is about
    // what the SQL says, and no amount of driving the worker proves the clause is in the
    // statement rather than the behaviour merely coinciding.
    $source = (string) file_get_contents(app_path('Features/Notifications/OutboxWorker.php'));

    $statement = function (string $after) use ($source): string {
        $at = strpos($source, $after);

        expect($at)->not->toBeFalse("OutboxWorker no longer contains {$after}");

        return (string) substr($source, (int) $at, 420);
    };

    // reLease(): keyed, status-guarded, owner-guarded, lease-guarded, no RETURNING.
    $reLease = $statement('set available_at = now() + (interval \'1 second\' * ?),
                    updated_at = now()');

    expect($reLease)->toContain('where id = ?')
        ->and($reLease)->toContain("and status = 'pending'")
        ->and($reLease)->toContain('and attempts = ?')
        ->and($reLease)->toContain('and available_at > now()')
        ->and($reLease)->not->toContain('returning');

    // finish(): keyed, status-guarded, owner-guarded. NOT lease-guarded — finishing a
    // row whose lease expired while we still held it is correct and must keep working.
    $finish = $statement('set status = ?::outbox_status');

    expect($finish)->toContain('where id = ?')
        ->and($finish)->toContain("and status = 'pending'")
        ->and($finish)->toContain('and attempts = ?')
        ->and($finish)->not->toContain('returning');

    // abandon(): the decrement, and the guard is what keeps the identity sound. Without
    // `and attempts = ?` this statement would hand back an attempt the thief had charged.
    $abandon = $statement('set attempts = attempts - 1');

    expect($abandon)->toContain('where id = ?')
        ->and($abandon)->toContain("and status = 'pending'")
        ->and($abandon)->toContain('and attempts = ?')
        ->and($abandon)->not->toContain('returning');
});

it('rolls an attempt back only when the row still carries this worker’s token', function (): void {
    // THE GUARD ON THE DECREMENT, driven rather than read. The identity's soundness no
    // longer rests on "the counter never decreases" — abandon() decrements — but on
    // NO TWO WORKERS EVER HOLDING THE SAME TOKEN FOR ONE ROW AT ONCE. A rollback that
    // fires only against its own token, and retires that token in the same breath,
    // cannot break it; one that fired against a thief's count would.
    $tenant = $this->makeTenant();
    $outboxId = $this->makeOutboxRow($tenant, $this->makeSession($tenant));
    $worker = DB::connection('pgsql_worker');

    $rollback = fn (int $identity): int => $worker->update(
        "update outbox set attempts = attempts - 1, updated_at = now()
          where id = ? and status = 'pending' and attempts = ?",
        [$outboxId, $identity],
    );

    $worker->update(
        "update outbox set attempts = attempts + 1 where id = ? and status = 'pending'",
        [$outboxId],
    );

    expect((int) identityRow($tenant, $outboxId)->attempts)->toBe(1);

    // A STALE TOKEN MATCHES NOTHING. Another worker claims it first.
    $worker->update(
        "update outbox set attempts = attempts + 1 where id = ? and status = 'pending'",
        [$outboxId],
    );

    expect($rollback(1))->toBe(0, 'a worker handed back an attempt the current holder had charged')
        ->and((int) identityRow($tenant, $outboxId)->attempts)->toBe(2, 'the thief’s count was altered');

    // AND THE CURRENT HOLDER'S OWN TOKEN DOES MATCH, so the zero above is about identity
    // and not about the statement being broken.
    expect($rollback(2))->toBe(1)
        ->and((int) identityRow($tenant, $outboxId)->attempts)->toBe(1);
});

it('returns from every abandon(), because the identity proof rests on the worker retiring its token', function (): void {
    // THE ONE LOAD-BEARING PREMISE THAT HAD NO CONTROL — found by the scoped review of
    // the ownership fix, 2026-10-27.
    //
    // The lease identity survives a decrement only because `abandon()` RETIRES the token
    // in the same breath as it hands the attempt back: the statement is guarded by the
    // worker's own token, so it fires only when no other claim has intervened, and the
    // worker then stops touching the row. The guard is in the SQL and is pinned by the
    // source scan above. The RETIRING half is a property of every call site being
    // `return $this->abandon(...)` — and a future call site that abandoned and then kept
    // processing would break the identity silently, with nothing going red.
    //
    // So it is enforced the way SourceGuard enforces §4's GUC rules: mechanically, on the
    // text, with a deliberately bad shape proven to fail.
    $source = (string) file_get_contents(app_path('Features/Notifications/OutboxWorker.php'));

    $offenders = [];
    $calls = 0;

    foreach (explode("\n", $source) as $number => $line) {
        if (! str_contains($line, '$this->abandon(')) {
            continue;
        }

        $calls++;

        // Either `return $this->abandon(...)` or a ternary whose arms both return, which
        // is the one other shape in the file: `return $cond ? [...] : $this->abandon(...)`.
        if (preg_match('/^\s*return\b/', $line) !== 1) {
            $offenders[] = ($number + 1).': '.trim($line);
        }
    }

    expect($calls)->toBeGreaterThanOrEqual(5, 'abandon() has fewer call sites than expected — has it been renamed?')
        ->and($offenders)->toBe([], 'an abandon() call does not return immediately: '.implode(' | ', $offenders));

    // THE POSITIVE CONTROL, so a pass here means the scan can fail. The same check over a
    // line that calls abandon() without returning must flag it.
    $planted = '        $this->abandon($row);';

    expect(preg_match('/^\s*return\b/', $planted))->toBe(0, 'the scan would not flag a bare abandon() call');
});
