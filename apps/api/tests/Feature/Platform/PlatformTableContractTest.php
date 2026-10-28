<?php

declare(strict_types=1);

use App\Domain\Database\TenantTables;
use App\Domain\Pager\PagerStatus;
use App\Domain\Provisioning\ProvisioningStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * THE REPLACEMENT FOR WHAT C4's CLASSIFIER CANNOT SEE.
 *
 * RlsCoverageTest decides whether a table is tenant-scoped by looking for a column NAMED
 * tenant_id (or the name `tenants`). Task 6's three platform tables carry no such column,
 * on the argument that each is one row per tenant so the tenant's id is the row's identity
 * rather than a scope column over many rows. That argument is correct and it is also
 * exactly the argument someone would make while quietly moving a tenant table out of
 * isolation -- so a design that steps around a mechanical control owes that control a
 * replacement, and this file is it.
 *
 * Everything here reads information_schema and pg_catalog, never the migration, so the
 * verdict is independent of the code that produced the schema.
 */

/** @return list<string> */
function platformColumns(string $table): array
{
    return array_map(
        static fn (object $r): string => (string) $r->column_name,
        DB::connection('pgsql_owner')->select(<<<'SQL'
            select column_name
            from information_schema.columns
            where table_schema = 'public' and table_name = ?
            order by column_name
        SQL, [$table]),
    );
}

it('pins every platform table column set exactly, so a tenant_id cannot join one quietly', function (
    string $table,
    array $columns,
): void {
    // An exact set, not a subset. A subset assertion would pass while a tenant_id column
    // was added beside the ones it names, which is the single thing this file exists to
    // prevent.
    expect(platformColumns($table))->toBe($columns);
})->with([
    'tenant_provisionings' => ['tenant_provisionings', [
        'attempts', 'completed_at', 'created_at', 'failure_reason', 'id', 'name',
        'owner_email', 'requested_by_user_id', 'slug', 'status', 'updated_at',
    ]],
    'tenant_statistics' => ['tenant_statistics', [
        'computed_at', 'created_at', 'id', 'median_wait_seconds', 'p95_wait_seconds',
        'sessions_cancelled', 'sessions_completed', 'sessions_expired', 'sessions_ready',
        'sessions_total', 'sessions_waiting', 'staff_count', 'updated_at', 'window_end',
        'window_start',
    ]],
    'platform_audit_log' => ['platform_audit_log', [
        'action', 'actor_user_id', 'context', 'created_at', 'id', 'ip_address',
        'subject_id', 'subject_type',
    ]],
]);

it('carries no column named tenant_id on any platform table, which is the premise of the design', function (): void {
    // Stated as its own case rather than left implicit in the pins above, because this is
    // the sentence the whole platform/tenant split rests on. If it ever becomes false, the
    // table belongs under RLS and the pin above is the wrong place to find that out.
    foreach (['tenant_provisionings', 'tenant_statistics', 'platform_audit_log'] as $table) {
        // str_contains-style check and NOT `->not->toContain($needle, $message)`: Pest's
        // toContain() is VARIADIC, so a message in the second argument is read as a second
        // needle and the negated assertion becomes "contains neither" -- which a message
        // that never appears makes trivially true. Four assertions in this repository were
        // that shape and three guarded named gate invariants (§4a); SourceGuard rule 7
        // caught this very line while this test was being written.
        expect(in_array('tenant_id', platformColumns($table), true))
            ->toBeFalse("{$table} grew a tenant_id: it is a tenant table now, and C4 applies");
    }
});

it('keeps row-level detail out of the aggregate table, which is the ruling as a property', function (): void {
    // "An aggregate carries no row-level detail: no order reference, no token, no session
    // identifier" (QRPAGER-13 §0). A forbidden-name list rather than a count, so the
    // failure message names the column that broke it.
    $forbidden = [
        'order_reference', 'token', 'token_hash', 'pager_session_id', 'session_id',
        'endpoint', 'p256dh', 'auth', 'email', 'owner_email', 'ip_address',
        'created_by_user_id', 'actor_user_id', 'subject_id',
    ];

    $present = array_values(array_intersect(platformColumns('tenant_statistics'), $forbidden));

    expect($present)->toBe([], 'tenant_statistics carries row-level detail: '.implode(', ', $present));
});

it('has one counter column per pager status, so a sixth status cannot leave one missing', function (): void {
    // The migration writes these out literally, because a migration that reads today's
    // enum is not a snapshot (the tenant-tables migration's own rule). This is the other
    // half of that split: the snapshot is pinned, and the contract is enforced here.
    $expected = array_map(static fn (PagerStatus $s): string => 'sessions_'.$s->value, PagerStatus::cases());
    sort($expected);

    $actual = array_values(array_filter(
        platformColumns('tenant_statistics'),
        static fn (string $c): bool => str_starts_with($c, 'sessions_') && $c !== 'sessions_total',
    ));

    expect($actual)->toBe($expected);
});

it('leaves all three platform tables without row level security, which is the ruling', function (): void {
    $rows = DB::connection('pgsql_owner')->select(<<<'SQL'
        select c.relname, c.relrowsecurity as enabled, c.relforcerowsecurity as forced
        from pg_class c join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public'
          and c.relname in ('tenant_provisionings', 'tenant_statistics', 'platform_audit_log')
        order by c.relname
    SQL);

    expect($rows)->toHaveCount(3);

    foreach ($rows as $row) {
        expect($row->enabled)->toBeFalse("{$row->relname} has RLS enabled: it is being treated as a tenant table")
            ->and($row->forced)->toBeFalse();
    }
});

it('reaches every platform table with NO tenant context at all, which is what the panel does', function (): void {
    // The positive half. The three cases above say the tables are not under RLS; this one
    // says the app role can actually read them with app.tenant_id unset, which is the
    // state every panel request is in. A test that only asserted the absence of a policy
    // would pass on a table the app role had no grant for.
    expect(DB::connection('pgsql')->table('tenant_provisionings')->count())->toBeInt()
        ->and(DB::connection('pgsql')->table('tenant_statistics')->count())->toBeInt()
        ->and(DB::connection('pgsql')->table('platform_audit_log')->count())->toBeInt();

    // And the context really is unset, so the line above is not passing for the wrong
    // reason -- which is the shape bin/vacuous-assertion-proof exists to catch.
    expect(DB::connection('pgsql')->select("select nullif(current_setting('app.tenant_id', true), '') as ctx")[0]->ctx)
        ->toBeNull();
});

it('is not matched by the scope guard, so an unscoped platform statement is not refused', function (string $table): void {
    // A property of a regex, not of a schema, so it gets its own case. The matcher uses an
    // identifier boundary either side, which is why platform_audit_log does not false-match
    // audit_log: the character before it is `_`, and that is an identifier character.
    foreach ([
        "select * from {$table}",
        "insert into {$table} (id) values (?)",
        "update \"{$table}\" set status = ?",
        "select count(*) from public.{$table} where id = ?",
    ] as $sql) {
        expect(TenantTables::referencedBy($sql))->toBeNull("the scope guard would refuse: {$sql}");
    }
})->with(['tenant_provisionings', 'tenant_statistics', 'platform_audit_log']);

it('still matches the tenant tables whose names these embed, so the check above is worth something', function (): void {
    // The control. If referencedBy() returned null for everything the case above would be
    // vacuous, and `audit_log` is the one that matters because platform_audit_log contains
    // it as a substring.
    expect(TenantTables::referencedBy('select * from audit_log'))->toBe('audit_log')
        ->and(TenantTables::referencedBy('select * from tenants'))->toBe('tenants')
        ->and(TenantTables::referencedBy('select * from tenant_users'))->toBe('tenant_users');
});

it('mirrors provisioning_status between PHP and Postgres, in order', function (): void {
    // EnumContractTest already does this for every registered enum; this is here so the
    // new type's own file fails if someone registers it and forgets, and because the
    // ordering is part of the contract rather than incidental.
    $labels = array_map(
        static fn (object $r): string => (string) $r->enumlabel,
        DB::connection('pgsql_owner')->select(<<<'SQL'
            select e.enumlabel from pg_enum e join pg_type t on t.oid = e.enumtypid
            where t.typname = 'provisioning_status' order by e.enumsortorder
        SQL),
    );

    expect($labels)->toBe(ProvisioningStatus::values())
        ->and($labels)->toBe(['pending', 'running', 'completed', 'failed']);
});

it('keeps platform_audit_log append-only, for the owner and for the app role alike', function (): void {
    DB::connection('pgsql')->table('platform_audit_log')->insert([
        'action' => 'probe.append_only',
        'context' => json_encode(['probe' => true], JSON_THROW_ON_ERROR),
    ]);

    // The grant is absent AND the trigger refuses. Both are asserted, because either one
    // alone would make this pass while the other had been dropped.
    expect($this->sqlStateOf(fn () => DB::connection('pgsql')
        ->table('platform_audit_log')->where('action', 'probe.append_only')->update(['action' => 'tampered'])))
        ->toBe('42501')
        ->and($this->sqlStateOf(fn () => DB::connection('pgsql')
            ->table('platform_audit_log')->where('action', 'probe.append_only')->delete()))
        ->toBe('42501');

    // And the owner, who holds every grant, is refused by the trigger alone -- which is
    // what ENABLE ALWAYS is for.
    expect($this->sqlStateOf(fn () => DB::connection('pgsql_owner')
        ->table('platform_audit_log')->where('action', 'probe.append_only')->delete()))
        ->toBe('42501')
        ->and($this->sqlStateOf(fn () => DB::connection('pgsql_owner')
            ->unprepared('truncate platform_audit_log')))
        ->toBe('42501');
});

it('refuses a DELETE that would match zero rows, which is why the trigger is per statement', function (): void {
    // A row trigger would not fire here at all and the tamper attempt would report success.
    expect($this->sqlStateOf(fn () => DB::connection('pgsql_owner')
        ->table('platform_audit_log')->whereRaw('false')->delete()))
        ->toBe('42501');
});

it('refuses a terminal provisioning row with no reason, at the database level', function (): void {
    // The CHECK, not the application. A failed provisioning with no reason is the state
    // this table exists to make impossible, so it is pinned where it cannot be bypassed.
    expect($this->sqlStateOf(fn () => DB::connection('pgsql')->table('tenant_provisionings')->insert([
        'id' => (string) Str::uuid7(),
        'name' => 'No Reason',
        'slug' => 'no-reason-'.random_int(1000, 9999),
        'owner_email' => 'owner@example.test',
        'status' => ProvisioningStatus::Failed->value,
    ])))->toBe('23514');

    expect($this->sqlStateOf(fn () => DB::connection('pgsql')->table('tenant_provisionings')->insert([
        'id' => (string) Str::uuid7(),
        'name' => 'No Timestamp',
        'slug' => 'no-timestamp-'.random_int(1000, 9999),
        'owner_email' => 'owner@example.test',
        'status' => ProvisioningStatus::Completed->value,
    ])))->toBe('23514');
});

it('refuses a non-DNS-label slug and a non-lower-cased email, at the database level', function (): void {
    $insert = function (string $slug, string $email): ?string {
        return $this->sqlStateOf(fn () => DB::connection('pgsql')->table('tenant_provisionings')->insert([
            'id' => (string) Str::uuid7(),
            'name' => 'Shape',
            'slug' => $slug,
            'owner_email' => $email,
            'status' => ProvisioningStatus::Pending->value,
        ]));
    };

    // The same expression as tenants_slug_is_dns_label, so a slug the tenant table would
    // refuse is refused here first, before any job runs.
    expect($insert('Has-Capitals', 'owner@example.test'))->toBe('23514')
        ->and($insert('-leading-dash', 'owner@example.test'))->toBe('23514')
        ->and($insert('trailing-dash-', 'owner@example.test'))->toBe('23514')
        ->and($insert('under_score', 'owner@example.test'))->toBe('23514')
        ->and($insert('fine-slug-'.random_int(1000, 9999), 'Owner@Example.test'))->toBe('23514');
});
