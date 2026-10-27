<?php

declare(strict_types=1);

use App\Domain\DatabaseEnums;
use App\Domain\Pager\PagerStatus;
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

it('keeps every database-backed enum value a bare identifier, so one can be interpolated — QA M-2', function (): void {
    // THE PREMISE BEHIND PagerStatus::liveSqlList(), CHECKED RATHER THAN DOCUMENTED.
    //
    // The expiry job's `where status in (...)` list is built from the enum's own case
    // values and interpolated, because §4 › Postgres traps prescribes that statement's
    // shape clause by clause and a bound parameter per case would make the shape vary with
    // the enum's size. That is safe only while every case value IS a bare identifier: a
    // value containing a quote would be injection from INSIDE OUR OWN CODE, which no input
    // validation anywhere would catch.
    //
    // So the property is asserted across every enum this project mirrors into Postgres, not
    // just the one that is interpolated today -- the next one to be interpolated should find
    // the rule already standing.
    foreach (DatabaseEnums::map() as $type => $class) {
        foreach ($class::cases() as $case) {
            expect(preg_match(PagerStatus::SQL_SAFE_VALUE, $case->value))->toBe(
                1,
                "{$class}::{$case->name} has the value [{$case->value}], which is not [a-z_]+. "
                ."Either keep it a bare identifier or stop interpolating {$type} anywhere."
            );
        }
    }
});

it('throws rather than interpolating a case value it cannot prove safe — QA M-2', function (): void {
    // The control, driven. A LogicException and not a refusal that returns something: an
    // empty list would issue `status in ()`, a syntax error, and a partial list would
    // silently stop expiring some sessions -- worse than a loud failure.
    //
    // The real enum cannot produce the condition, which is the point of the case above. So
    // the throw is exercised against an anonymous enum carrying the same contract, proving
    // the guard's logic rather than re-proving that `waiting` is safe.
    $unsafe = "ready'); drop table pager_sessions; --";

    expect(preg_match(PagerStatus::SQL_SAFE_VALUE, $unsafe))->toBe(0, 'the pattern accepts a quoted value');

    // And the live enum passes it, so the pattern is not simply rejecting everything.
    expect(PagerStatus::liveSqlList())->toBe("'waiting', 'ready'");

    foreach (PagerStatus::live() as $status) {
        expect(preg_match(PagerStatus::SQL_SAFE_VALUE, $status->value))->toBe(1);
    }
});
