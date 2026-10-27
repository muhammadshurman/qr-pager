<?php

declare(strict_types=1);

use App\Domain\Pager\PagerAuditAction;
use App\Domain\Pager\PagerEvent;
use App\Domain\Pager\PagerStatus;
use App\Domain\Pager\PagerTransitionDenied;
use App\Features\Pager\PagerSessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * §3 gate, clause 2: "markReady is idempotent under concurrent calls -- one outbox row,
 * one notification". Also §4 › Realtime: "markReady is idempotent. A retry never
 * double-notifies."
 *
 * Two mechanisms are being tested, and they are deliberately separable:
 *
 *   1. the row lock, which is where idempotency actually comes from -- the second caller
 *      blocks, sees a status that is no longer `waiting`, and writes nothing;
 *   2. UNIQUE (tenant_id, dedupe_key) on outbox, the database-level backstop §2.5 says
 *      that index exists for.
 *
 * The last case in this file takes the lock out of the picture entirely and shows the
 * index still refuses the second row, so neither mechanism is being credited for the
 * other's work.
 */

/** @return array{outbox: int, audit: int} */
function readyRowCounts(string $tenantId, string $sessionId): array
{
    return withTenant($tenantId, fn (): array => [
        'outbox' => DB::table('outbox')
            ->where('pager_session_id', $sessionId)
            ->where('type', PagerEvent::Ready->value)
            ->count(),
        'audit' => DB::table('audit_log')
            ->where('tenant_id', $tenantId)
            ->where('subject_id', $sessionId)
            ->where('action', PagerAuditAction::Ready->value)
            ->count(),
    ]);
}

it('is idempotent on a repeat call: one outbox row, one audit row, and changed is false', function (): void {
    $tenant = $this->makeTenant();
    $session = $this->makeSession($tenant);
    $sessions = app(PagerSessions::class);

    $first = $sessions->markReady($tenant, $session);
    $second = $sessions->markReady($tenant, $session);
    $third = $sessions->markReady($tenant, $session);

    expect($first->changed)->toBeTrue()
        ->and($second->changed)->toBeFalse('a repeat markReady reported a change')
        ->and($third->changed)->toBeFalse()
        // The state is the same answer every time, which is what makes a dropped
        // response safe to retry.
        ->and($second->status)->toBe(PagerStatus::Ready)
        ->and($third->status)->toBe(PagerStatus::Ready);

    $counts = readyRowCounts($tenant, $session);

    expect($counts['outbox'])->toBe(1, 'a repeat markReady wrote a second outbox row -- a second notification')
        ->and($counts['audit'])->toBe(1, 'a repeat markReady wrote a second audit row for work it did not do');
});

it('writes one outbox row when two processes mark the same session ready at once', function (): void {
    $tenant = $this->makeTenant();
    $session = $this->makeSession($tenant);

    // TRUE CONCURRENCY, which is what the gate clause says ("under concurrent calls").
    // Two connections in ONE php process cannot produce it: `select ... for update` would
    // block the only thread there is and the test would deadlock rather than demonstrate
    // ordering. So two processes race for the same row, and the row lock is the only
    // thing that can order them.
    //
    // Both are launched before either is read, so they overlap: Laravel's boot is ~100 ms
    // and the transition is sub-millisecond, so the two transactions really are open
    // together. If the lock were not there the outcomes would be two `applied` and two
    // outbox rows, or a 23505 from the unique index -- all three fail the assertions below.
    $script = base_path('tests/Support/mark-ready-in-another-process.php');
    $database = (string) config('database.connections.pgsql.database');

    // Merged, not replaced. proc_open's $env REPLACES the environment, so a bare array
    // loses PATH (and `php` is then not found) and loses everything .env resolution needs
    // -- measured: the child produced no output at all and the failure looked like a
    // concurrency result.
    $env = array_merge(getenv(), [
        'APP_ENV' => 'testing',
        'DB_DATABASE' => $database,
        'PAO_DISABLE' => '1',
    ]);

    $children = [];

    foreach ([0, 1] as $i) {
        $children[$i] = ['handle' => proc_open(
            ['php', $script, $tenant, $session],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $children[$i]['pipes'],
            base_path(),
            $env,
        ), 'pipes' => $children[$i]['pipes'] ?? []];

        expect($children[$i]['handle'])->not->toBeFalse("could not start process {$i}");
    }

    $outcomes = [];

    foreach ($children as $child) {
        $outcomes[] = trim((string) stream_get_contents($child['pipes'][1]));
        $stderr = trim((string) stream_get_contents($child['pipes'][2]));
        fclose($child['pipes'][1]);
        fclose($child['pipes'][2]);
        proc_close($child['handle']);

        // Surfaced rather than swallowed: a child that died on a boot error would
        // otherwise read as a concurrency outcome, which is exactly how this test failed
        // the first time it was written.
        expect($stderr)->toBe('', "a child process wrote to stderr: {$stderr}");
    }

    sort($outcomes);

    // EXACTLY ONE of them performed the transition. The other found it already ready and
    // wrote nothing -- not an error, which is the point of idempotence.
    expect($outcomes)->toBe(['applied', 'idempotent'], 'two concurrent markReady calls did not resolve to one applied and one no-op');

    $counts = readyRowCounts($tenant, $session);

    expect($counts['outbox'])->toBe(1, 'two concurrent markReady calls produced two notifications')
        ->and($counts['audit'])->toBe(1, 'two concurrent markReady calls produced two audit rows');
});

it('lets renotify through repeatedly, with a distinct outbox row each time', function (): void {
    $tenant = $this->makeTenant();
    $session = $this->makeSession($tenant);
    $sessions = app(PagerSessions::class);

    $sessions->markReady($tenant, $session);

    $first = $sessions->renotify($tenant, $session);
    $second = $sessions->renotify($tenant, $session);

    // A renotify IS a new notification, so unlike markReady it must NOT collapse. The
    // attempt number in the dedupe key is what keeps the rows distinct.
    expect($first->renotifyCount)->toBe(1)
        ->and($second->renotifyCount)->toBe(2)
        ->and($first->changed)->toBeTrue()
        ->and($second->changed)->toBeTrue();

    $keys = withTenant($tenant, fn (): array => DB::table('outbox')
        ->where('pager_session_id', $session)
        ->where('type', PagerEvent::Renotify->value)
        ->orderBy('dedupe_key')
        ->pluck('dedupe_key')
        ->all());

    expect($keys)->toBe([
        PagerEvent::Renotify->dedupeKey($session, 1),
        PagerEvent::Renotify->dedupeKey($session, 2),
    ]);
});

it('refuses a renotify past the configured cap, as a conflict rather than silently', function (): void {
    config(['pager.renotify_limit' => 2]);

    $tenant = $this->makeTenant();
    $session = $this->makeSession($tenant);
    $sessions = app(PagerSessions::class);

    $sessions->markReady($tenant, $session);
    $sessions->renotify($tenant, $session);
    $sessions->renotify($tenant, $session);

    expect(fn () => $sessions->renotify($tenant, $session))
        ->toThrow(PagerTransitionDenied::class);

    // The cap is a bound on the outbox, so the thing to assert is the row count and not
    // only the refusal: §2.11 calls outbox "unbounded, and the one to watch".
    $rows = withTenant($tenant, fn (): int => DB::table('outbox')
        ->where('pager_session_id', $session)
        ->where('type', PagerEvent::Renotify->value)
        ->count());

    expect($rows)->toBe(2);
});

it('refuses a renotify on a session that was never marked ready', function (): void {
    $tenant = $this->makeTenant();
    $session = $this->makeSession($tenant);

    expect(fn () => app(PagerSessions::class)->renotify($tenant, $session))
        ->toThrow(PagerTransitionDenied::class);

    $rows = withTenant($tenant, fn (): int => DB::table('outbox')->where('pager_session_id', $session)->count());

    expect($rows)->toBe(0);
});

it('has the unique index as the backstop, independent of the row lock', function (): void {
    $tenant = $this->makeTenant();
    $session = $this->makeSession($tenant);

    app(PagerSessions::class)->markReady($tenant, $session);

    // The lock is out of the picture here: this writes the SAME dedupe key directly, the
    // way a lost lock or a future rewrite would. §2.5 calls UNIQUE (tenant_id,
    // dedupe_key) "the mechanism for Task 3's idempotent markReady ... enforced by the
    // database under true concurrency, not by a read-then-write check", so the index is
    // asserted on its own terms rather than credited for the lock's work.
    $state = $this->sqlStateOf(fn () => withTenant($tenant, fn () => DB::table('outbox')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant,
        'pager_session_id' => $session,
        'type' => PagerEvent::Ready->value,
        'dedupe_key' => PagerEvent::Ready->dedupeKey($session),
    ])));

    expect($state)->toBe('23505');
});
