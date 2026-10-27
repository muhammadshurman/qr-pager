<?php

declare(strict_types=1);

use App\Domain\Pager\PagerAuditAction;
use App\Domain\Pager\PagerStatus;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * §3 gate, clause 5, AS AMENDED 2026-10-27 by Muhammad (QA L-2 / R-065): "expiry job uses
 * a policy-limited `SELECT` of `id`, `tenant_id` and `status` -> `UPDATE ... WHERE id = ?
 * AND status = ?` with no `RETURNING`, and never a bulk statement". It read "uses
 * `SELECT id`", which taken literally forbade the two columns the pattern itself needs.
 * No code changed -- the clause did, and `PROJECT_INSTRUCTIONS.md §4 › Postgres traps`
 * with it, as a rank-2 edit marked in that file.
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

/*
 * QA L-4. The §3 gate's FIRST clause is stated generally — "the status change, the outbox
 * row and the audit row commit in one transaction, or none of them does" — and
 * PagerTransactionTest proves it for the three writers in PagerSessions. This job is the
 * FOURTH writer of a status change plus an audit row in one withTenant() transaction, and
 * it had no injected-failure case: the invariant was proven for the service and assumed
 * for the job, on the grounds that both use the same helper.
 *
 * Same helper is a reason to expect it to hold, not a reason not to test it — and the one
 * thing that differs here is the connection. The job runs on pgsql_worker, whose scope is
 * opened by withTenant(..., 'pgsql_worker'), and nothing had ever injected a failure
 * inside a worker-connection scope.
 */
it('commits the expiry and its audit row together or not at all — QA L-4', function (): void {
    $tenant = $this->makeTenant();
    $session = expiredSessionIn($tenant, PagerStatus::Waiting);

    // beforeExecuting fires BEFORE the statement runs, so no test-only seam exists in
    // production code -- the same instrument PagerTransactionTest uses, pointed at the
    // worker connection, which is the half that had never been exercised.
    DB::connection('pgsql_worker')->beforeExecuting(function (string $query): void {
        if (str_contains($query, 'insert into audit_log')) {
            throw new RuntimeException('deliberate, after the expiry UPDATE');
        }
    });

    expect(fn () => Artisan::call('pager:expire'))
        ->toThrow(RuntimeException::class, 'deliberate, after the expiry UPDATE');

    $status = withTenant($tenant, fn () => DB::table('pager_sessions')->where('id', $session)->value('status'));
    $audit = withTenant($tenant, fn (): int => DB::table('audit_log')
        ->where('subject_id', $session)
        ->where('action', PagerAuditAction::Expired->value)
        ->count());

    // The UPDATE had already succeeded when the audit insert threw, so this asserts the
    // rollback and not merely that nothing happened.
    expect($status)->toBe(
        PagerStatus::Waiting->value,
        'the expiry survived a transaction whose audit row was rolled back'
    )->and($audit)->toBe(0);
});

it('expires nothing and audits nothing when a staff member wins the race — QA M-1', function (): void {
    // THE BRANCH THE RE-RUNNABILITY CLAIM RESTS ON, and the one the suite never reached.
    // Measured by QA M-1 at `1695f0b`: `if ($affected !== 1) { return 0; }` in
    // ExpirePagerSessionsCommand::expire() was **0 times covered**, while the ticket's §6.6
    // and the command's own docblock both lean on it -- "a second attempt on an
    // already-expired row is a 0, not an error and not a duplicate".
    //
    // The existing "is re-runnable" case cannot reach it: the SELECT filters on
    // `status in ('waiting','ready')`, so a second pass finds NO ROWS and never issues a
    // second UPDATE. The branch only executes when the row changes BETWEEN the SELECT and
    // the keyed UPDATE -- which is a staff member completing or cancelling a session while
    // a pass is in flight, the exact interleaving §4 › Postgres traps makes the keyed,
    // status-guarded UPDATE the answer to.
    //
    // So the race is forced rather than waited for: beforeExecuting fires BEFORE the
    // statement, so the row is moved on ANOTHER connection in the instant between the
    // worker's SELECT and its UPDATE. No production seam exists for this; the instrument is
    // the same one PagerTransactionTest and the QA L-4 case above already use.
    //
    // ============================================================================
    // THE RACER IS THE SUPERUSER, AND THAT IS A LIMIT OF THIS PROOF -- STATED, NOT HIDDEN
    // ============================================================================
    //
    // In production the actor that wins this race is a STAFF MEMBER on `qrpager_app`,
    // inside a `withTenant()` scope, going through PagerSessions::complete(). This test
    // cannot use that role, and the reason is a control working correctly rather than a
    // shortcut: the racing write happens INSIDE the worker's own scope (beforeExecuting
    // fires within it), and TenantContext refuses to open a scope on a second connection
    // while one is live -- R-015's guard, which exists so that a callback cannot quietly
    // run unscoped on a different connection. Attempting it raises
    // "Tenant context is already open on connection [pgsql_worker]; it cannot be reused on
    // [pgsql_owner]", which is the right answer to the wrong question.
    //
    // WHAT IS THEREFORE NOT PROVEN HERE: that `qrpager_app`'s own policies and column grants
    // permit the racing write. They do -- PagerTransitionTest and PagerIdorTest drive
    // complete() as the app role through HTTP -- but not in this case, and not in this
    // interleaving.
    //
    // WHAT IS PROVEN, and why the role does not weaken it: the mechanism under test belongs
    // entirely to the WORKER's statement. `update ... where id = ? and status = ?` matches
    // zero rows because the row's status changed, and the affected-row count is the
    // idempotency signal. Which role committed that change, and under which policy, is
    // invisible to the worker -- it sees a row whose status is no longer the one it read.
    // The superuser is the only role that can make the change from inside the worker's
    // scope, so it is the only instrument available, and it produces exactly the state a
    // staff member would.
    //
    // Recorded so a reviewer rules on it rather than discovering it (QA M-1, Muhammad's
    // clarification of 2026-10-27).
    $tenant = $this->makeTenant();
    $session = expiredSessionIn($tenant, PagerStatus::Waiting);

    $raced = false;

    DB::connection('pgsql_worker')->beforeExecuting(function (string $query) use (&$raced, $session): void {
        if ($raced || ! str_contains($query, 'update pager_sessions')) {
            return;
        }

        $raced = true;

        // The staff member wins. A DIFFERENT CONNECTION, committing in its own transaction
        // while the worker's is open -- which is what makes this a race rather than the
        // worker's own transaction moving its own row, a thing that would prove nothing.
        //
        // AS ROOT, NOT AS qrpager_app -- see the limit stated in this case's header. The
        // superuser bypasses row level security and needs no scope, so the racing write is a
        // precondition placed from outside rather than a second tenant context.
        //
        // READ COMMITTED is what makes the worker see it: its UPDATE re-reads the row at
        // statement start, and the worker holds no lock on it yet -- the pass's SELECT runs
        // outside the per-session transaction and takes none.
        DB::connection('pgsql_root')->update(
            'update pager_sessions set status = ?::pager_status, completed_at = now() where id = ?',
            [PagerStatus::Completed->value, $session],
        );
    });

    Artisan::call('pager:expire');

    expect($raced)->toBeTrue('the race never fired, so this case proved nothing');

    $status = withTenant($tenant, fn () => DB::table('pager_sessions')->where('id', $session)->value('status'));

    $audit = withTenant($tenant, fn (): int => DB::table('audit_log')
        ->where('subject_id', $session)
        ->where('action', PagerAuditAction::Expired->value)
        ->count());

    $expiredAt = withTenant($tenant, fn () => DB::table('pager_sessions')->where('id', $session)->value('expired_at'));

    // Three assertions, because the branch owes three things.
    expect($status)->toBe(
        PagerStatus::Completed->value,
        'the expiry overwrote a status a staff member had just written'
    )
        // NOTHING TO AUDIT, BECAUSE NOTHING HAPPENED. An audit row here would be a record
        // of work that did not occur, which the command's docblock calls out as worse than
        // a failed pass.
        ->and($audit)->toBe(0, 'an audit row was written for an expiry that did not happen')
        // And the worker did not half-write: expired_at stays null on a row it did not move.
        ->and($expiredAt)->toBeNull('expired_at was set on a row the UPDATE did not match');

    // The pass itself reports success and expires zero: it is a no-op, not a failure.
    expect(Artisan::output())->toContain('0 of 1 due session(s) expired');
});

it('derives its live-status list from the enum; the SELECT policy whitelist carries the written status and the UPDATE policy USING does not — QA M-2', function (): void {
    // ONE SOURCE, ASSERTED ACROSS ALL THREE PLACES THE SET LIVES. Until 2026-10-27 the job
    // carried `where status in ('waiting','ready')` as a SQL literal while PagerStatus's own
    // docblock claimed "the service, the expiry job and the tests all read allows()". §6.2
    // is "One source only. Never a duplicated enum", and a string literal is a duplicated
    // enum static analysis cannot see.
    //
    // THE CONSTRAINT THIS MUST NOT BREAK, which is why the test is a set comparison and not
    // a string check: §4 › Postgres traps requires the worker's cross-tenant SELECT policy
    // to be bounded by TIME PLUS A STATUS WHITELIST THAT CONTAINS THE STATUS THE UPDATE
    // WRITES. An UPDATE that references a column re-checks the NEW row against the SELECT
    // policies (§2.12), so `expired` has to be in the read whitelist or every expiry raises
    // 42501 -- and it must NOT be in the UPDATE policy's USING, or an already-expired row
    // could be rewritten cross-tenant.
    $live = array_map(fn (PagerStatus $s): string => $s->value, PagerStatus::live());
    sort($live);

    // (1) the enum's own derivation
    expect($live)->toBe(['ready', 'waiting'], 'the live set changed; every assertion below depends on it');

    // (2) the SQL list the job interpolates, parsed back out of the string
    preg_match_all("/'([a-z_]+)'/", PagerStatus::liveSqlList(), $matches);
    $fromSql = $matches[1];
    sort($fromSql);

    expect($fromSql)->toBe($live, 'liveSqlList() and live() disagree');

    // (3) the statement the job actually issues, captured off a real pass
    $tenant = $this->makeTenant();
    expiredSessionIn($tenant, PagerStatus::Waiting);

    $selects = [];
    DB::listen(function ($query) use (&$selects): void {
        if (str_contains($query->sql, 'from pager_sessions') && str_starts_with(trim($query->sql), 'select')) {
            $selects[] = $query->sql;
        }
    });

    Artisan::call('pager:expire');

    $claim = collect($selects)->first(fn (string $sql): bool => str_contains($sql, 'expires_at <= now()'));

    expect($claim)->not->toBeNull('the expiry pass issued no claiming SELECT');

    preg_match_all("/'([a-z_]+)'/", (string) $claim, $matches);
    $fromStatement = $matches[1];
    sort($fromStatement);

    expect($fromStatement)->toBe($live, 'the issued statement does not use the enum-derived list');

    // (4) THE SELECT POLICY, by name AND by polcmd, against the same set.
    //
    // §4 › Postgres traps' mechanism is about the SELECT POLICIES, applied TWICE: once to
    // the rows the statement may find, and once to the NEW row -- 42501 if the update would
    // hide it. So the whitelist that must contain the written status is the SELECT policy's,
    // and the policy that must NOT contain it is the UPDATE policy's USING. Getting those
    // two the wrong way round would leave every expiry failing 42501, or would leave an
    // already-expired row rewritable cross-tenant (R-003).
    //
    // polcmd IS ASSERTED, not just the name. A policy name is a convention -- somebody could
    // rename or recreate `worker_reads_expired_sessions` as an UPDATE policy and every
    // string assertion below would still pass while the clause it stands for was inverted.
    // `pg_policy.polcmd` is the fact: 'r' is SELECT, 'w' is UPDATE.
    $select = $this->asRoot()->selectOne(
        "select p.polcmd, pg_get_expr(p.polqual, p.polrelid) as using_expr
           from pg_policy p join pg_class c on c.oid = p.polrelid
          where c.relname = 'pager_sessions' and p.polname = 'worker_reads_expired_sessions'",
    );

    expect($select)->not->toBeNull('worker_reads_expired_sessions does not exist')
        ->and((string) $select->polcmd)->toBe('r',
            'worker_reads_expired_sessions is not a SELECT policy, so §4\'s two-pass mechanism does not apply to it');

    $read = (string) $select->using_expr;

    preg_match_all("/'([a-z_]+)'/", $read, $matches);
    $policyWhitelist = array_values(array_unique($matches[1]));
    sort($policyWhitelist);

    // The policy's whitelist is the live set PLUS the status the update writes. That is the
    // §4 clause, stated as an equation rather than as prose: a reader can see at a glance
    // that the extra member is exactly `expired` and nothing else.
    $expected = $live;
    $expected[] = PagerStatus::Expired->value;
    sort($expected);

    expect($policyWhitelist)->toBe($expected,
        'worker_reads_expired_sessions (SELECT) is not the live set plus exactly the written status');

    // (5) THE WRITTEN STATUS IS IN THE SELECT POLICY. This is the half that makes the expiry
    // possible at all: the UPDATE references columns, so the NEW row is re-checked against
    // the SELECT policies, and measured with 'expired' removed the prescribed statement
    // raises 42501 and EVERY expiry fails (§2.12).
    //
    // in_array() AND NOT `toContain($needle, $message)`: toContain is VARIADIC, so a message
    // is read as a second needle. The positive form fails loudly when misused that way --
    // it did, right here, while this case was being written -- and the NEGATED form below
    // would have passed vacuously instead. That is the defect `SourceGuard` rule 7 exists
    // for on `task-4-notifications`; this branch does not carry rule 7 yet, so these two
    // were caught by the positive form's own failure rather than by the guard.
    expect(in_array(PagerStatus::Expired->value, $policyWhitelist, true))->toBeTrue(
        'worker_reads_expired_sessions (SELECT) omits the status the UPDATE writes, so every expiry would fail 42501');

    // (6) THE UPDATE POLICY, again by name and by polcmd: its USING is the live set and NOT
    // the written status -- otherwise an already-expired row could be rewritten
    // cross-tenant, which is R-003.
    $updatePolicy = $this->asRoot()->selectOne(
        "select p.polcmd, pg_get_expr(p.polqual, p.polrelid) as using_expr,
                coalesce(pg_get_expr(p.polwithcheck, p.polrelid), '') as check_expr
           from pg_policy p join pg_class c on c.oid = p.polrelid
          where c.relname = 'pager_sessions' and p.polname = 'worker_expires_sessions'",
    );

    expect($updatePolicy)->not->toBeNull('worker_expires_sessions does not exist')
        ->and((string) $updatePolicy->polcmd)->toBe('w',
            'worker_expires_sessions is not an UPDATE policy');

    $update = (string) $updatePolicy->using_expr;

    preg_match_all("/'([a-z_]+)'/", $update, $matches);
    $updateUsing = array_values(array_unique($matches[1]));
    sort($updateUsing);

    expect($updateUsing)->toBe($live,
        'worker_expires_sessions (UPDATE) USING is not exactly the live set');

    expect(in_array(PagerStatus::Expired->value, $updateUsing, true))->toBeFalse(
        'worker_expires_sessions (UPDATE) USING admits an already-expired row, which is R-003');

    // And its WITH CHECK pins the only status the worker may write without context, which is
    // the third limb and the one that keeps the SELECT whitelist from being a widening.
    expect((string) $updatePolicy->check_expr)->toContain("'".PagerStatus::Expired->value."'");
});
