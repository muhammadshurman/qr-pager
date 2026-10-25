<?php

declare(strict_types=1);

use App\Domain\DatabaseEnums;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * §6.2: a shared contract has one source, and CI fails when the mirror is stale.
 *
 * The PHP enum is the source; the Postgres type is the mirror; Task 8 generates
 * the TypeScript mirror from the same registry (ADR-003).
 */

it('mirrors every PHP enum exactly, in order', function (string $type, string $class): void {
    $labels = array_map(
        static fn (object $row): string => (string) $row->enumlabel,
        DB::connection('pgsql_owner')->select(<<<'SQL'
            select e.enumlabel
            from pg_enum e
            join pg_type t on t.oid = e.enumtypid
            where t.typname = ?
            order by e.enumsortorder
        SQL, [$type]),
    );

    expect($labels)->toBe($class::values(), "the Postgres type [{$type}] has drifted from {$class}");
})->with(array_map(
    static fn (string $type, string $class): array => [$type, $class],
    array_keys(DatabaseEnums::map()),
    array_values(DatabaseEnums::map()),
));

it('registers every enum type that exists in the database', function (): void {
    $types = array_map(
        static fn (object $row): string => (string) $row->typname,
        DB::connection('pgsql_owner')->select(<<<'SQL'
            select t.typname
            from pg_type t join pg_namespace n on n.oid = t.typnamespace
            where n.nspname = 'public' and t.typtype = 'e'
            order by t.typname
        SQL),
    );

    $registered = array_keys(DatabaseEnums::map());
    sort($registered);

    // An enum type created without being registered has no drift test behind it,
    // and Task 8 would not generate a TypeScript mirror for it.
    expect($types)->toBe($registered);
});

it('refuses a value outside the enum at the database level', function (): void {
    $tenant = $this->makeTenant();

    expect($this->sqlStateOf(fn () => withTenant($tenant, fn () => DB::table('outbox')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant,
        'type' => 'pager.ready',
        'dedupe_key' => 'bad-status',
        'status' => 'not-a-status',
    ]))))->toBe('22P02');
});
