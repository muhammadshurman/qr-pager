<?php

declare(strict_types=1);

use App\Domain\Database\TenantTables;
use App\Domain\Pager\PagerStatus;
use App\Domain\Provisioning\ProvisioningStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * THE RULING'S SECOND CLAUSE: "per-tenant statistics are aggregates written to a platform
 * table, never a read of a tenant table. A counter or a timestamp is maintained by a job or
 * the outbox worker, and the panel reads only that platform row. An aggregate carries no
 * row-level detail: no order reference, no token, no session identifier."
 *
 * §0's *Blocking anything?* row had said since 2026-10-26 that Task 6 would need a FIFTH
 * named cross-tenant path -- a rank-2 edit to §4 -- or a different dashboard design. This
 * file is the evidence that it needed neither: the job reads each tenant INSIDE that
 * tenant's own scope, one at a time, and enumerates tenants from a platform table.
 */

/** A provisioned tenant, with its registry row, as the job expects to find it. */
function provisionedTenant(?string $slug = null): string
{
    $id = (string) Str::uuid7();
    $slug ??= 'stats-'.Str::lower(Str::random(10));

    // The registry row first, then the tenant. The job enumerates from the registry, so a
    // tenant with no registry row is deliberately invisible to it -- which one case below
    // asserts directly.
    DB::insert(
        'insert into tenant_provisionings (id, name, slug, owner_email, status, created_at, updated_at)
         values (?, ?, ?, ?, ?::provisioning_status, now(), now())',
        [$id, 'Stats '.$slug, $slug, $slug.'@example.test', ProvisioningStatus::Pending->value],
    );
    DB::update(
        'update tenant_provisionings set status = ?::provisioning_status, completed_at = now(), updated_at = now()
          where id = ?',
        [ProvisioningStatus::Completed->value, $id],
    );

    test()->makeTenant(slug: $slug, id: $id);

    return $id;
}

function statsRow(string $tenantId): ?object
{
    return DB::selectOne('select * from tenant_statistics where id = ?', [$tenantId]);
}

it('aggregates a tenant’s sessions into the platform row, with no row-level detail', function (): void {
    $tenant = provisionedTenant();

    $this->makeSession($tenant, ['status' => PagerStatus::Waiting->value, 'order_reference' => 'AAA-111']);
    $this->makeSession($tenant, ['status' => PagerStatus::Ready->value, 'order_reference' => 'BBB-222']);
    $this->makeSession($tenant, ['status' => PagerStatus::Ready->value, 'order_reference' => 'CCC-333']);
    $this->makeSession($tenant, ['status' => PagerStatus::Completed->value, 'order_reference' => 'DDD-444']);

    $this->artisan('platform:aggregate-stats')->assertSuccessful();

    $row = statsRow($tenant);

    expect($row)->not->toBeNull()
        ->and((int) $row->sessions_total)->toBe(4)
        ->and((int) $row->sessions_waiting)->toBe(1)
        ->and((int) $row->sessions_ready)->toBe(2)
        ->and((int) $row->sessions_completed)->toBe(1)
        ->and((int) $row->sessions_cancelled)->toBe(0)
        ->and((int) $row->sessions_expired)->toBe(0)
        ->and($row->computed_at)->not->toBeNull();

    // AND NOT ONE ORDER REFERENCE CROSSED THE BOUNDARY. The four above are distinctive
    // strings; the whole platform row is searched for any of them, so this is a property of
    // the row rather than of the columns the test happened to read.
    $serialised = json_encode((array) $row, JSON_THROW_ON_ERROR);

    foreach (['AAA-111', 'BBB-222', 'CCC-333', 'DDD-444'] as $reference) {
        expect(str_contains($serialised, $reference))
            ->toBeFalse("the aggregate row carries the order reference {$reference}");
    }
});

it('keeps the window bounds free of other tenants’ sessions, once they are written', function (): void {
    // The contamination's second symptom, with enough sessions that the window is not
    // suppressed: before the H-1 fix, tenant A's window_start was the oldest session on the
    // whole platform -- a timestamp that could predate A's own existence.
    $a = provisionedTenant();
    $b = provisionedTenant();

    // B's sessions are OLD and past their TTL, so the worker policy would have exposed them.
    foreach (range(1, 3) as $i) {
        $id = $this->makeSession($b, ['status' => PagerStatus::Ready->value]);
        withTenant($b, fn () => DB::connection('pgsql_owner')->update(
            "update pager_sessions
                set created_at = now() - interval '30 days', ready_at = now() - interval '30 days',
                    expires_at = now() - interval '29 days'
              where id = ?",
            [$id],
        ), 'pgsql_owner');
    }

    // A's are recent, and there are five of them so the window is written.
    foreach (range(1, 5) as $i) {
        $id = $this->makeSession($a, ['status' => PagerStatus::Ready->value]);
        withTenant($a, fn () => DB::connection('pgsql_owner')->update(
            "update pager_sessions set created_at = now() - interval '1 hour', ready_at = now() where id = ?",
            [$id],
        ), 'pgsql_owner');
    }

    $this->artisan('platform:aggregate-stats')->assertSuccessful();

    $rowA = statsRow($a);
    $aFirst = withTenant($a, fn () => DB::selectOne('select min(created_at) as m from pager_sessions')->m);

    expect((int) $rowA->sessions_total)->toBe(5)
        ->and($rowA->window_start)->toBe($aFirst, "A's window reaches outside A's own sessions");
});

it('counts the tenant’s own staff and nobody else’s', function (): void {
    $tenant = provisionedTenant();
    $other = provisionedTenant();

    // makeTenant creates no membership, so both counts start at zero and the rows added
    // here are the whole population.
    foreach ([$tenant, $tenant, $other] as $target) {
        $userId = (string) Str::uuid7();
        DB::insert(
            'insert into users (id, name, email, created_at, updated_at) values (?, ?, ?, now(), now())',
            [$userId, 'Staff', 'staff-'.Str::lower(Str::random(10)).'@example.test'],
        );
        withTenant($target, fn () => DB::insert(
            'insert into tenant_users (id, tenant_id, user_id, role, created_at, updated_at)
             values (?, ?, ?, ?::tenant_role, now(), now())',
            [(string) Str::uuid7(), $target, $userId, 'staff'],
        ));
    }

    $this->artisan('platform:aggregate-stats')->assertSuccessful();

    expect((int) statsRow($tenant)->staff_count)->toBe(2)
        ->and((int) statsRow($other)->staff_count)->toBe(1);
});

it('reads each tenant inside its own scope, so no statement can see two tenants at once', function (): void {
    // THE PROPERTY THAT REPLACED A FIFTH NAMED CROSS-TENANT PATH, and it is asserted at the
    // statement level rather than from the figures. Two tenants, each with a different
    // number of sessions: if any aggregate statement were unscoped, both rows would carry
    // the combined total.
    $a = provisionedTenant();
    $b = provisionedTenant();

    $this->makeSession($a);
    $this->makeSession($b);
    $this->makeSession($b);

    $statements = [];
    $scopesOpened = 0;
    DB::listen(function ($query) use (&$statements, &$scopesOpened): void {
        $statements[] = $query->sql;

        // THE GUC NAME IS A BINDING, NOT SQL TEXT, and that is C2 rather than an accident:
        // "always `select set_config(?, ?, true)`", because Postgres `SET` takes no bind
        // parameters and a session-scoped setting is a hard failure. So counting scopes by
        // searching the SQL for `app.tenant_id` finds ZERO however many are opened -- which
        // is how the first version of this case asserted nothing and said so loudly.
        if (str_contains($query->sql, 'set_config') && in_array('app.tenant_id', $query->bindings, true)) {
            $scopesOpened++;
        }
    });

    $this->artisan('platform:aggregate-stats')->assertSuccessful();

    expect((int) statsRow($a)->sessions_total)->toBe(1)
        ->and((int) statsRow($b)->sessions_total)->toBe(2);

    // Every statement that touched a tenant table must have been accompanied by a
    // set_config of app.tenant_id -- which is what withTenant() does. Measured as: the
    // scope-setting statement appears at least once per tenant, and the tenant tables
    // touched are only the two the aggregates read.
    $touched = [];
    foreach ($statements as $sql) {
        $table = TenantTables::referencedBy((string) $sql);
        if ($table !== null) {
            $touched[$table] = true;
        }
    }

    $names = array_keys($touched);
    sort($names);

    expect($names)->toBe(['pager_sessions', 'tenant_users'])
        // And a scope was opened at least twice: once per tenant.
        ->and($scopesOpened)->toBeGreaterThanOrEqual(2);

    // No bypass was ever turned on. A bypass is the thing that would have made one
    // unscoped statement serve both tenants.
    // Neither in the SQL nor in a binding, for the same reason as above.
    expect(DB::connection('pgsql_worker')
        ->selectOne("select nullif(current_setting('app.bypass_tenant', true), '') as b")->b)
        ->toBeNull();
});

it('counts NO other tenant’s sessions, even past-TTL ones the worker policy can see', function (): void {
    // ================================================================================
    // SECURITY H-1's REGRESSION TEST (§11 review, 2026-10-28). It is red without the fix.
    // ================================================================================
    //
    // This command used to read `pager_sessions` on the WORKER connection inside each
    // tenant's scope, and its docblock called that "an ordinary scoped read". It was not.
    // `qrpager_worker` carries `worker_reads_expired_sessions`, a PERMISSIVE cross-tenant
    // SELECT policy `USING (expires_at <= now() AND status IN ('waiting','ready','expired'))`,
    // and permissive policies are **OR-ed** -- so inside tenant A's scope the worker also
    // sees every other tenant's past-TTL sessions. `§2.6` records exactly this asymmetry in
    // the other direction and it was not connected to this design.
    //
    // THE CASE THAT EXISTED BELOW COULD NOT CATCH IT: it seeds only fresh sessions, whose
    // `expires_at` is in the future, so the policy's predicate is false and nothing crosses.
    // The sessions here are deliberately PAST their TTL and in the policy's status
    // whitelist, which is the production steady state.
    $a = provisionedTenant();
    $b = provisionedTenant();

    // A owns exactly one fresh session.
    $this->makeSession($a, ['status' => PagerStatus::Waiting->value]);

    // B owns three past-TTL sessions, one per status in the worker's whitelist.
    foreach ([PagerStatus::Waiting, PagerStatus::Ready, PagerStatus::Expired] as $status) {
        $id = $this->makeSession($b, ['status' => $status->value]);

        // The owner connection: the app role holds no UPDATE on `expires_at` (§2.7).
        withTenant($b, fn () => DB::connection('pgsql_owner')->update(
            "update pager_sessions set expires_at = now() - interval '1 hour' where id = ?",
            [$id],
        ), 'pgsql_owner');
    }

    // THE MEASUREMENT THAT NAMES THE MECHANISM, so a future reader sees why the roles differ
    // rather than only that they do. Without the fix the first of these is 4 rows over 2
    // tenants; the app role sees 1 over 1.
    $asWorker = withTenant($a, fn () => DB::connection('pgsql_worker')->selectOne(
        'select count(*) as c, count(distinct tenant_id) as t from pager_sessions'
    ), 'pgsql_worker');
    $asApp = withTenant($a, fn () => DB::selectOne(
        'select count(*) as c, count(distinct tenant_id) as t from pager_sessions'
    ));

    expect((int) $asWorker->t)->toBe(2, 'the worker policy no longer crosses tenants; this case has lost its subject')
        ->and((int) $asApp->t)->toBe(1, 'the APP role crossed tenants, which would be a far worse finding');

    $this->artisan('platform:aggregate-stats')->assertSuccessful();

    // A's row counts A's one session and nothing of B's.
    $rowA = statsRow($a);

    expect((int) $rowA->sessions_total)->toBe(1)
        ->and((int) $rowA->sessions_waiting)->toBe(1)
        ->and((int) $rowA->sessions_ready)->toBe(0)
        ->and((int) $rowA->sessions_expired)->toBe(0);

    // And B's row counts B's three and nothing of A's -- both directions, because a
    // one-directional assertion would pass on a job that simply summed everything into the
    // first tenant it saw.
    $rowB = statsRow($b);

    expect((int) $rowB->sessions_total)->toBe(3)
        ->and((int) $rowB->sessions_waiting)->toBe(1)
        ->and((int) $rowB->sessions_ready)->toBe(1)
        ->and((int) $rowB->sessions_expired)->toBe(1);

    // The window bounds are SUPPRESSED here, because A has one session and the threshold is
    // five (security L-4) -- so the contamination's other symptom, a window_start predating
    // the tenant's own existence, is asserted as `null` rather than as A's first session.
    // The case below drives the window with enough sessions for it to be written.
    expect($rowA->window_start)->toBeNull();
});

it('reads the tenant’s sessions as the APP role, which holds no cross-tenant policy', function (): void {
    // The mechanism of H-1's fix, asserted rather than left to the docblock: the aggregate
    // statements must not be issued on the worker connection, because that role's permissive
    // expiry policy is OR-ed into every scoped read it makes. Captured per connection.
    $tenant = provisionedTenant();
    $this->makeSession($tenant);

    $byConnection = [];
    DB::listen(function ($query) use (&$byConnection): void {
        if (TenantTables::referencedBy($query->sql) !== null) {
            $byConnection[$query->connectionName][] = $query->sql;
        }
    });

    $this->artisan('platform:aggregate-stats')->assertSuccessful();

    expect(array_keys($byConnection))->toBe(['pgsql'],
        'a tenant table was read on a connection other than the app role: '.json_encode(array_keys($byConnection)));
});

it('is idempotent: a second pass leaves one row with the same counters', function (): void {
    $tenant = provisionedTenant();
    $this->makeSession($tenant);
    $this->makeSession($tenant);

    $this->artisan('platform:aggregate-stats')->assertSuccessful();
    $first = statsRow($tenant);

    $this->artisan('platform:aggregate-stats')->assertSuccessful();
    $second = statsRow($tenant);

    expect(DB::selectOne('select count(*) as c from tenant_statistics where id = ?', [$tenant])->c)->toBe(1)
        ->and((int) $second->sessions_total)->toBe((int) $first->sessions_total)
        ->and((int) $second->sessions_total)->toBe(2)
        // created_at is NOT rewritten by the upsert -- the row's identity is stable even
        // though its values are refreshed.
        ->and($second->created_at)->toBe($first->created_at);
});

it('computes the wait percentiles in whole seconds, once there are enough of them', function (): void {
    $tenant = provisionedTenant();

    // FIVE ready sessions, because the percentiles are SUPPRESSED below
    // AggregateTenantStatisticsCommand::WAIT_SUPPRESSION_THRESHOLD (security L-4): a median
    // over one session IS that customer's wait, which is row-level detail wearing an
    // aggregate's name. Each wait is set explicitly, so the figure is arithmetic rather
    // than a race with the clock -- R-111 is an open row about a case that races a wall
    // clock, and this one deliberately does not.
    foreach ([60, 90, 90, 120, 150] as $seconds) {
        $session = $this->makeSession($tenant, ['status' => PagerStatus::Ready->value]);

        // The owner connection: the app role holds no UPDATE on `created_at` at all
        // (§2.7 -- "a creation timestamp is never rewritten by the application").
        withTenant($tenant, fn () => DB::connection('pgsql_owner')->update(
            "update pager_sessions
                set created_at = now() - interval '1 second' * ?, ready_at = now()
              where id = ?",
            [$seconds, $session],
        ), 'pgsql_owner');
    }

    $this->artisan('platform:aggregate-stats')->assertSuccessful();

    $row = statsRow($tenant);

    // The median of 60, 90, 90, 120, 150 is 90; the 95th percentile interpolates to 144.
    expect((int) $row->median_wait_seconds)->toBe(90)
        ->and((int) $row->p95_wait_seconds)->toBe(144)
        ->and((int) $row->sessions_ready)->toBe(5)
        // The window bounds exist once the figures are not suppressed.
        ->and($row->window_start)->not->toBeNull()
        ->and($row->window_end)->not->toBeNull();
});

it('suppresses the wait figures and the window below the threshold, because one row IS a row', function (): void {
    // ⚠ SECURITY L-4, AND IT IS THE RULING RATHER THAN A PREFERENCE. "An aggregate carries
    // no row-level detail" -- and `percentile_cont(0.5)` over ONE ready session is that
    // customer's exact wait, while `min`/`max(created_at)` are single-row timestamps by
    // definition. The ruling's own enumeration names identifiers (order reference, token,
    // session id) and `PlatformTableContractTest` enforces exactly that list, so nothing
    // caught a figure that IS a row without being called one.
    $tenant = provisionedTenant();

    $session = $this->makeSession($tenant, ['status' => PagerStatus::Ready->value]);
    withTenant($tenant, fn () => DB::connection('pgsql_owner')->update(
        "update pager_sessions set created_at = now() - interval '90 seconds', ready_at = now() where id = ?",
        [$session],
    ), 'pgsql_owner');

    $this->artisan('platform:aggregate-stats')->assertSuccessful();

    $row = statsRow($tenant);

    // THE COUNTS ARE STILL WRITTEN -- a count is an aggregate at any size. Only the figures
    // that would be one customer's are withheld.
    expect((int) $row->sessions_total)->toBe(1)
        ->and((int) $row->sessions_ready)->toBe(1)
        ->and($row->median_wait_seconds)->toBeNull()
        ->and($row->p95_wait_seconds)->toBeNull()
        ->and($row->window_start)->toBeNull()
        ->and($row->window_end)->toBeNull();

    // A tenant with sessions but NONE ready has no median either, which is a different fact
    // from a median of zero and is why the column is nullable.
    $noReady = provisionedTenant();
    $this->makeSession($noReady, ['status' => PagerStatus::Waiting->value]);

    $this->artisan('platform:aggregate-stats')->assertSuccessful();

    expect(statsRow($noReady)->median_wait_seconds)->toBeNull()
        ->and((int) statsRow($noReady)->sessions_total)->toBe(1);
});

it('aggregates a tenant with no sessions to zeroes rather than skipping it', function (): void {
    // A provisioned restaurant that has taken no orders yet must still have a row: the
    // dashboard sums over this table, and a missing row and a row of zeroes are the same
    // total but a different answer to "does this restaurant exist".
    $tenant = provisionedTenant();

    $this->artisan('platform:aggregate-stats')->assertSuccessful();

    $row = statsRow($tenant);

    expect($row)->not->toBeNull()
        ->and((int) $row->sessions_total)->toBe(0)
        ->and((int) $row->staff_count)->toBe(0)
        ->and($row->median_wait_seconds)->toBeNull()
        ->and($row->window_start)->toBeNull();
});

it('aggregates only COMPLETED registry rows, so a pending restaurant is not counted', function (): void {
    // The registry is the job's enumeration source, and a `pending` row has no tenant
    // behind it yet -- entering a context for a tenant that does not exist would read
    // nothing and write a row of zeroes for a restaurant that may never be created.
    $pending = (string) Str::uuid7();
    DB::insert(
        'insert into tenant_provisionings (id, name, slug, owner_email, status, created_at, updated_at)
         values (?, ?, ?, ?, ?::provisioning_status, now(), now())',
        [$pending, 'Not Yet', 'not-yet-'.Str::lower(Str::random(8)), 'n@example.test', ProvisioningStatus::Pending->value],
    );

    $this->artisan('platform:aggregate-stats')->assertSuccessful();

    expect(statsRow($pending))->toBeNull();
});

it('aggregates one tenant only when given --tenant, and refuses an id that is not provisioned', function (): void {
    $a = provisionedTenant();
    $b = provisionedTenant();

    $this->makeSession($a);
    $this->makeSession($b);

    $this->artisan('platform:aggregate-stats', ['--tenant' => $a])->assertSuccessful();

    expect(statsRow($a))->not->toBeNull()
        ->and(statsRow($b))->toBeNull();

    // An id with no completed registry row aggregates nothing, rather than creating a row
    // for a tenant nobody provisioned.
    $this->artisan('platform:aggregate-stats', ['--tenant' => (string) Str::uuid7()])->assertSuccessful();

    // Scoped to this test's two ids rather than a bare count(*). tenant_statistics IS
    // truncated between tests now, so a global count would be correct -- but a count
    // scoped to the subject stays correct if that ever changes, and an assertion that
    // depends on another test's cleanup is the shape that made this case read 10 for 1.
    expect(DB::selectOne(
        'select count(*) as c from tenant_statistics where id in (?, ?)',
        [$a, $b],
    )->c)->toBe(1);
});

it('leaves no tenant context behind on the worker connection', function (): void {
    $tenant = provisionedTenant();
    $this->makeSession($tenant);

    $this->artisan('platform:aggregate-stats')->assertSuccessful();

    // C2: the scope is transaction-scoped, so it cannot survive on a pooled connection into
    // whatever runs next. Asserted on the WORKER connection, which is the one the job used.
    expect(DB::connection('pgsql_worker')
        ->selectOne("select nullif(current_setting('app.tenant_id', true), '') as t")->t)
        ->toBeNull();
});

it('writes the aggregate row with the worker role, which the panel cannot do', function (): void {
    // THE RULING AS A GRANT. "Aggregates written by a job, and the panel reads only that
    // platform row" is only true if the panel cannot write one -- so the app role holds no
    // INSERT and no UPDATE on tenant_statistics, and the attempt is refused by Postgres
    // rather than by a code path.
    $tenant = provisionedTenant();

    expect($this->sqlStateOf(fn () => DB::insert(
        'insert into tenant_statistics (id, sessions_total, computed_at, created_at, updated_at)
         values (?, 99, now(), now(), now())',
        [$tenant],
    )))->toBe('42501');

    // And the worker can, which is what makes the line above a narrowing rather than a
    // table nobody can write at all.
    DB::connection('pgsql_worker')->insert(
        'insert into tenant_statistics (id, sessions_total, computed_at, created_at, updated_at)
         values (?, 99, now(), now(), now())',
        [$tenant],
    );

    expect((int) statsRow($tenant)->sessions_total)->toBe(99);
});

it('has one counter column per pager status, driven from the enum', function (): void {
    // The command builds its `count(*) filter (...)` list from PagerStatus::cases(), so a
    // sixth status would add a counter the table has no column for and this would fail --
    // which is the direction that matters. PlatformTableContractTest pins the column set
    // from the schema side; this pins that the JOB writes all of them.
    $tenant = provisionedTenant();

    foreach (PagerStatus::cases() as $status) {
        $this->makeSession($tenant, ['status' => $status->value]);
    }

    $this->artisan('platform:aggregate-stats')->assertSuccessful();

    $row = (array) statsRow($tenant);

    foreach (PagerStatus::cases() as $status) {
        expect((int) $row['sessions_'.$status->value])->toBe(1, "sessions_{$status->value} was not written");
    }

    expect((int) $row['sessions_total'])->toBe(count(PagerStatus::cases()));
});
