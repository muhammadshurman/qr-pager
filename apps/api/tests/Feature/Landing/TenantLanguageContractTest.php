<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantLanguage;
use Illuminate\Support\Facades\DB;

/*
 * ================================================================================
 * `dir` IS DERIVED AND HAS NOWHERE TO BE STORED. `QRPAGER-30`, invariant clause 3.
 * ================================================================================
 *
 * *"`dir` is never read from storage: there is no column, no field in
 * `landing-page.schema.json`, and no path by which a tenant can set it independently of
 * `language`."*
 *
 * ⚠ **THIS IS A TEST ABOUT AN ABSENCE, WHICH IS THE WEAKEST KIND, SO IT IS DRIVEN FROM THE
 * SERVER AND FROM THE COMMITTED SCHEMA RATHER THAN FROM A LIST HERE.** The column sweep reads
 * `information_schema`, so a `direction` column added by any later migration fails here; the
 * schema sweep reads `docs/contracts/landing-page.schema.json`, so a member added to the
 * document contract fails here too. Neither can be satisfied by editing this file.
 */

it('has no stored direction anywhere on tenants, and exactly one language column', function (): void {
    $columns = array_column(DB::connection('pgsql_root')->select(
        "select column_name from information_schema.columns where table_schema = 'public' and table_name = 'tenants'"
    ), 'column_name');

    expect($columns)->toContain('language')
        ->and($columns)->not->toContain('dir')
        ->and($columns)->not->toContain('direction')
        ->and($columns)->not->toContain('rtl')
        ->and($columns)->not->toContain('text_direction');
});

it('names no direction and no language in the landing document contract', function (): void {
    // The document is the tenant-AUTHORED contract, and the ruling puts the language on the
    // tenant rather than in it. A `dir` member here would be a second source a tenant could
    // set independently of its own language -- the exact disagreement the ruling forbids,
    // and the one that does not fail loudly.
    $schema = (string) file_get_contents(base_path('../../docs/contracts/landing-page.schema.json'));

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($schema, true, 512, JSON_THROW_ON_ERROR);

    // ⚠ AN EXACT LIST, AND IT HAS MOVED TWICE ON PURPOSE. It read `['blocks', 'theme', 'seo']`
    // until `QRPAGER-33` added `template` on 2026-11-03, and `QRPAGER-47` added `logo` on
    // 2026-11-12; this case going red each time is the list doing its job: a member added to
    // the tenant-authored contract has to be acknowledged here, because the thing being ruled
    // out is a `dir` arriving among them. Keeping it exact is what makes the three
    // `not->toContain` lines below more than decoration.
    expect(array_keys($decoded['properties'] ?? []))->toBe(['blocks', 'theme', 'seo', 'template', 'logo'])
        ->and($schema)->not->toContain('"dir"')
        ->and($schema)->not->toContain('"direction"')
        ->and($schema)->not->toContain('"language"');
});

it('pins the column default against the enum constant, so the two cannot drift', function (): void {
    // `TenantLanguage::DEFAULT` exists for a reader with no row. The AUTHORITY is the column,
    // because a row inserted by anything other than this application must still carry a
    // language -- so the constant is held to `pg_attrdef` rather than the other way round.
    $default = DB::connection('pgsql_root')->select(
        "select pg_get_expr(d.adbin, d.adrelid) as def
           from pg_attrdef d
           join pg_attribute a on a.attrelid = d.adrelid and a.attnum = d.adnum
          where a.attrelid = 'tenants'::regclass and a.attname = 'language'"
    );

    expect($default)->toHaveCount(1, 'the language column has no DEFAULT, so a row can arrive without one')
        ->and($default[0]->def)->toBe("'".TenantLanguage::DEFAULT->value."'::tenant_language");
});

it('derives a direction for every case, and only the two the kit knows', function (): void {
    // ⚠ OVER `cases()`, NOT OVER TWO LITERALS. A third case added to the enum without a
    // direction fails `match` at runtime, and this is the case that runs it -- so the
    // exhaustiveness is proven rather than left to PHPStan.
    $directions = [];

    foreach (TenantLanguage::cases() as $case) {
        $directions[$case->value] = $case->direction();
    }

    expect($directions)->toBe(['ar' => 'rtl', 'en' => 'ltr'])
        // The TS mirror's own list, so the kit and the column cannot name different
        // languages. `composer contracts:check` holds the generated file to the PHP enum;
        // this holds the PHP enum to the shape the mirror is generated into.
        ->and(TenantLanguage::values())->toBe(['ar', 'en']);
});
