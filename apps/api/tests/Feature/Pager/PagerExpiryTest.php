<?php

declare(strict_types=1);

use App\Domain\Pager\PagerAuditAction;
use App\Domain\Pager\PagerStatus;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * §3 gate, clause 5: "expiry job uses `SELECT id` -> `UPDATE ... WHERE id = ? AND
 * status = ?` with no `RETURNING`".
 *
 * And R-003, which this task owns: the worker's two cross-tenant pager_sessions policies
 * were bounded by TIME ALONE, so the worker held a permanent context-free cross-tenant
 * read of every historical session and could mark another tenant's `completed` order
 * `expired` with no audit row. The row's acceptance condition is "the two policies are
 * narrowed and the expiry job re-proven in the same change", which is why both halves are
 * in one file.
 */

/** A session past its TTL in $status, inserted directly so a terminal one can be set up. */
function expiredSessionIn(string $tenantId, PagerStatus $status): string
{
    $id = (string) Str::uuid7();

    withTenant($tenantId, fn () => DB::connection('pgsql_owner')->insert(
        'insert into pager_sessions (id, tenant_id, token_hash, order_reference, status, expires_at)
         values (?, ?, decode(?, \'hex\'), ?, ?::pager_status, now() - interval \'5 minutes\')',
        [$id, $tenantId, bin2hex(random_bytes(32)), 'A-'.random_int(100, 999), $status->value],
    ), 'pgsql_owner');

    return $id;
}

it('expires both live statuses and leaves everything else alone', function (): void {
    $tenant = $this->makeTenant();

    $waiting = expiredSessionIn($tenant, PagerStatus::Waiting);
    $ready = expiredSessionIn($tenant, PagerStatus::Ready);
    $completed = expiredSessionIn($tenant, PagerStatus::Completed);
    $cancelled = expiredSessionIn($tenant, PagerStatus::Cancelled);
    $live = $this->makeSession($tenant); // inside its TTL

    Artisan::call('pager:expire');

    $statuses = withTenant($tenant, fn (): array => DB::table('pager_sessions')
        ->orderBy('id')->pluck('status', 'id')->all());

    expect($statuses[$waiting])->toBe(PagerStatus::Expired->value)
        ->and($statuses[$ready])->toBe(PagerStatus::Expired->value)
        // Terminal statuses are not re-written, and a session inside its TTL is untouched.
        ->and($statuses[$completed])->toBe(PagerStatus::Completed->value)
        ->and($statuses[$cancelled])->toBe(PagerStatus::Cancelled->value)
        ->and($statuses[$live])->toBe(PagerStatus::Waiting->value);
});

it('writes an audit row per expiry and no outbox row at all', function (): void {
    $tenant = $this->makeTenant();
    $session = expiredSessionIn($tenant, PagerStatus::Waiting);

    Artisan::call('pager:expire');

    [$audit, $outbox, $actor] = withTenant($tenant, fn (): array => [
        DB::table('audit_log')->where('subject_id', $session)
            ->where('action', PagerAuditAction::Expired->value)->count(),
        DB::table('outbox')->where('pager_session_id', $session)->count(),
        DB::table('audit_log')->where('subject_id', $session)
            ->where('action', PagerAuditAction::Expired->value)->value('actor_type'),
    ]);

    expect($audit)->toBe(1, 'the expiry wrote no audit row -- which is what R-003 objected to')
        // Settled by the schema rather than by taste: §2.7 gives qrpager_worker no INSERT
        // grant on outbox at all, so an expiry notification is not something this job
        // could write even if somebody decided it should.
        ->and($outbox)->toBe(0)
        // `system` is the honest actor here and the only place in this feature where it is:
        // no user asked for it. Staff transitions write `user` with a null actor_user_id
        // until Task 5 fills it.
        ->and($actor)->toBe('system');
});

it('is re-runnable: a second pass expires nothing and audits nothing', function (): void {
    $tenant = $this->makeTenant();
    expiredSessionIn($tenant, PagerStatus::Waiting);

    Artisan::call('pager:expire');
    $afterFirst = withTenant($tenant, fn (): int => DB::table('audit_log')
        ->where('action', PagerAuditAction::Expired->value)->count());

    Artisan::call('pager:expire');
    $afterSecond = withTenant($tenant, fn (): int => DB::table('audit_log')
        ->where('action', PagerAuditAction::Expired->value)->count());

    // The affected-row count is the whole crash story: `status = ?` in the WHERE makes a
    // second attempt a 0, so nothing is double-expired and nothing is double-audited.
    expect($afterFirst)->toBe(1)->and($afterSecond)->toBe(1);
});

it('issues the prescribed statement shape, with no RETURNING anywhere', function (): void {
    $tenant = $this->makeTenant();
    expiredSessionIn($tenant, PagerStatus::Waiting);

    $statements = [];
    DB::listen(function (QueryExecuted $event) use (&$statements): void {
        $statements[] = strtolower($event->sql);
    });

    Artisan::call('pager:expire');

    $selects = array_values(array_filter(
        $statements,
        static fn (string $sql): bool => str_contains($sql, 'from pager_sessions'),
    ));
    $updates = array_values(array_filter(
        $statements,
        static fn (string $sql): bool => str_starts_with(trim($sql), 'update pager_sessions'),
    ));

    expect($selects)->not->toBeEmpty()->and($updates)->not->toBeEmpty();

    // The UPDATE names the KEY and the expected STATUS. Never a bulk statement: §4 ›
    // Postgres traps records that a bulk UPDATE referencing no column of the table
    // escapes RLS row filtering entirely and writes rows the role cannot read.
    foreach ($updates as $sql) {
        expect($sql)->toContain('where id = ?')
            ->and($sql)->toContain('status = ?')
            // Clause 5, verbatim. Kept as a convention rather than as the mechanism
            // (§2.12 corrected that), because it costs nothing, keeps the worker's read
            // surface narrow, and preserves the affected-row count as the signal.
            ->and($sql)->not->toContain('returning');
    }

    foreach ($statements as $sql) {
        if (str_contains($sql, 'pager_sessions')) {
            expect($sql)->not->toContain('returning');
        }
    }
});

it('bounds one pass, so a pass cannot hold a transaction over an unbounded row count', function (): void {
    $tenant = $this->makeTenant();

    foreach (range(1, 5) as $ignored) {
        expiredSessionIn($tenant, PagerStatus::Waiting);
    }

    Artisan::call('pager:expire', ['--limit' => 2]);

    $expired = withTenant($tenant, fn (): int => DB::table('pager_sessions')
        ->where('status', PagerStatus::Expired->value)->count());

    expect($expired)->toBe(2);

    // And the rest are picked up by later passes: nothing is lost by stopping early.
    Artisan::call('pager:expire', ['--limit' => 2]);
    Artisan::call('pager:expire');

    $all = withTenant($tenant, fn (): int => DB::table('pager_sessions')
        ->where('status', PagerStatus::Expired->value)->count());

    expect($all)->toBe(5);
});

/*
 * ---- R-003: the policies, narrowed ------------------------------------------------
 */

it('narrows the worker’s cross-tenant SELECT to live and expired sessions past their TTL — R-003', function (): void {
    $tenant = $this->makeTenant();

    $waiting = expiredSessionIn($tenant, PagerStatus::Waiting);
    $ready = expiredSessionIn($tenant, PagerStatus::Ready);
    $completed = expiredSessionIn($tenant, PagerStatus::Completed);
    $cancelled = expiredSessionIn($tenant, PagerStatus::Cancelled);

    // The worker, with NO tenant context: this is the cross-tenant read R-003 is about.
    $visible = array_map(
        static fn (object $row): string => (string) $row->id,
        $this->asWorker()->select('select id from pager_sessions order by id'),
    );

    sort($visible);
    $expected = [$waiting, $ready];
    sort($expected);

    // Before the narrowing this returned every historical session -- "a permanent,
    // context-free, cross-tenant read of every historical session (everything but
    // token_hash)". Now a terminal session that is not expiring is invisible.
    expect($visible)->toBe($expected)
        ->and($visible)->not->toContain($completed)
        ->and($visible)->not->toContain($cancelled);
});

it('stops the worker expiring another tenant’s completed or cancelled order — R-003’s actual hole', function (): void {
    $tenant = $this->makeTenant();
    $completed = expiredSessionIn($tenant, PagerStatus::Completed);
    $cancelled = expiredSessionIn($tenant, PagerStatus::Cancelled);

    foreach ([$completed => PagerStatus::Completed, $cancelled => PagerStatus::Cancelled] as $id => $status) {
        // Cross-tenant, no context, the prescribed statement shape. UPDATE 0 rather than
        // 42501: the old row fails the UPDATE policy's USING, so it is silently SKIPPED
        // -- which is §2.12's documented old-row behaviour and is the right outcome here.
        $affected = $this->asWorker()->update(
            'update pager_sessions set status = ?::pager_status, expired_at = now(), updated_at = now()
              where id = ? and status = ?::pager_status',
            [PagerStatus::Expired->value, (string) $id, $status->value],
        );

        expect($affected)->toBe(0, "the worker expired another tenant's {$status->value} order");
    }

    // And nothing moved.
    $statuses = withTenant($tenant, fn (): array => DB::table('pager_sessions')->pluck('status', 'id')->all());

    expect($statuses[$completed])->toBe(PagerStatus::Completed->value)
        ->and($statuses[$cancelled])->toBe(PagerStatus::Cancelled->value);
});

it('still refuses any transition other than expiring, which WITH CHECK pins — R-003', function (): void {
    $tenant = $this->makeTenant();
    $session = expiredSessionIn($tenant, PagerStatus::Waiting);

    // A row the worker CAN see and CAN update, moved to a status it may not write. The old
    // row passes USING, so WITH CHECK is what refuses -- and it refuses loudly.
    expect($this->sqlStateOf(fn () => $this->asWorker()->update(
        'update pager_sessions set status = ?::pager_status where id = ? and status = ?::pager_status',
        [PagerStatus::Ready->value, $session, PagerStatus::Waiting->value],
    )))->toBe('42501');
});

it('carries the status whitelist in the policies themselves, read from pg_catalog — R-003', function (): void {
    $policies = $this->asRoot()->select(
        "select p.polname, p.polcmd, pg_get_expr(p.polqual, p.polrelid) as using_expr,
                coalesce(pg_get_expr(p.polwithcheck, p.polrelid), '') as check_expr
           from pg_policy p join pg_class c on c.oid = p.polrelid
          where c.relname = 'pager_sessions' and p.polname like 'worker_%'
          order by p.polname",
    );

    $byName = [];

    foreach ($policies as $policy) {
        $byName[(string) $policy->polname] = $policy;
    }

    expect(array_keys($byName))->toBe(['worker_expires_sessions', 'worker_reads_expired_sessions']);

    // The SELECT policy must contain 'expired' -- and that is §2.12's mechanism rather
    // than a widening. An UPDATE that references a column re-checks the NEW row against
    // the SELECT policies, so the whitelist has to contain the status the update writes;
    // measured with 'expired' removed, the prescribed statement raises 42501 and every
    // expiry fails. §4 › Postgres traps prescribes exactly this shape.
    $read = (string) $byName['worker_reads_expired_sessions']->using_expr;

    expect($read)->toContain('expires_at <= now()')
        ->and($read)->toContain("'waiting'")
        ->and($read)->toContain("'ready'")
        ->and($read)->toContain("'expired'");

    // The UPDATE policy's USING bounds the OLD row and must NOT contain 'expired',
    // 'completed' or 'cancelled': only a live session past its TTL may be touched.
    $update = (string) $byName['worker_expires_sessions']->using_expr;

    expect($update)->toContain('expires_at <= now()')
        ->and($update)->toContain("'waiting'")
        ->and($update)->toContain("'ready'")
        ->and($update)->not->toContain("'completed'")
        ->and($update)->not->toContain("'cancelled'");

    // And WITH CHECK still pins the only status the worker may write without context.
    expect((string) $byName['worker_expires_sessions']->check_expr)->toContain("'expired'");
});
