<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `tenants.language` — a restaurant's own language, and the `dir` derived from it.
 *
 * Muhammad's ruling of 2026-11-03, rank 1, recorded in `PLAN.md §1` and written into the data
 * contract at `§2.5` and `§2.7` BEFORE this migration existed, per `§6` Q1. Built by
 * `QRPAGER-30`, which owns `R-132` and `R-221`.
 *
 * ================================================================================
 * THE DEFAULT IS `ar`, IT IS A PRODUCT DECISION, AND IT IS IN THE DATABASE
 * ================================================================================
 *
 * `§12` names Arabic a launch language and the market is Arabic-first, so a default of `en`
 * would make every restaurant who never touches the setting ship an English page. The old
 * behaviour was a hard-coded `lang="en" dir="ltr"` on every published page (`R-132`), so this
 * default is ALSO the fix for every tenant who does nothing.
 *
 * ⚠ **AND IT IS THE COLUMN'S DEFAULT, NOT A CODE DEFAULT.** `QRPAGER-30 §5`: *"a code default
 * would make a row inserted by anything other than the app's own path carry nothing, and
 * NOT NULL would then fail at a distance from the cause."* `TenantLanguage::DEFAULT` exists
 * for readers with no row and is pinned against `pg_attrdef` by
 * `TenantLanguageContractTest`, so the two cannot drift.
 *
 * ⚠ **EVERY EXISTING ROW BECOMES `ar`, WHICH IS THE RULING AND NOT AN ACCIDENT.** `ADD COLUMN
 * … NOT NULL DEFAULT` fills existing rows; on PostgreSQL 11+ it does so without a table
 * rewrite, because the default is stored in `pg_attrdef` and applied on read. Nothing on a
 * live page changes until that tenant publishes again — `§6.6`'s published bytes are
 * immutable and the pointer only moves on a republish — so this is a change to what the NEXT
 * publish produces, not a silent edit of what is being served.
 *
 * ================================================================================
 * A NATIVE ENUM TYPE AND NOT `varchar(2)` + CHECK
 * ================================================================================
 *
 * `§1`'s ruling spells the shape as `varchar(2)` with a CHECK, and `QRPAGER-30 §5` made that a
 * `§6` Q1 question with its own tiebreaker: *"not an enum type — UNLESS `§2.3`'s enum
 * convention is read first and says otherwise."* It does. `TenantLanguage`'s docblock carries
 * the full argument and the one consequence (a third value is refused `22P02` rather than
 * `23514`); the ruling's substance — one column, two values, default `ar`, `dir` derived — is
 * untouched.
 *
 * ================================================================================
 * THE TWO GRANT LISTS, AND WHY THIS IS THE OPPOSITE OF `slug`
 * ================================================================================
 *
 * `§2.7`: `language` joins BOTH the INSERT and the UPDATE list. A tenant is created with one
 * and a restaurant may change it later. `slug` is deliberately absent from the UPDATE list and
 * `domain-move-proof` clause A asserts that absence, because `{slug}.{platform_domain}` is a
 * published prefix and a rewritable slug would be a prefix that changes hands. `language`
 * carries no such weight: it changes what a page SAYS, never where it LIVES.
 *
 * `qrpager_worker` keeps `SELECT` on the whole table and gains nothing here — it renders no
 * page.
 *
 * ================================================================================
 * `down()` IS REAL, AND THE ORDER IS THE REVERSE OF `up()`
 * ================================================================================
 *
 * A type cannot be dropped while a column still uses it (`2BP01`), so the column goes first.
 * The column grants go with the column — a grant does not survive its object, the lesson
 * `2026_11_02_120000`'s docblock records for functions — so no `REVOKE` is needed or issued;
 * issuing one against a dropped column would raise `42703`.
 *
 * ⚠ **THIS SENTENCE NAMED A TEST THAT DOES NOT EXIST** when it was first written -- it read
 * *"`MigrationRollbackTest` drives this down and back up"*, and there is no such class;
 * `tests/Feature/Database/` holds no rollback case at all. `§8`'s rule is not to claim a test
 * passed, and naming an imaginary one is the same defect with more confidence. Caught by
 * looking for the file rather than by trusting the comment.
 *
 * The real mechanism is **`apps/api/bin/migration-rollback-proof`**, a script, and it is what
 * `§3`'s clause *"rollback of this task's migrations runs clean"* is proven by: a throwaway
 * database, because `migrate:rollback` takes the last BATCH and a fresh database puts this
 * migration in a batch of its own. It was run for this migration and its transcript is
 * `docs/tickets/QRPAGER-30-rollback-proof.txt`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('pgsql_owner')->unprepared(<<<'SQL'
            CREATE TYPE tenant_language AS ENUM ('ar', 'en');

            ALTER TABLE tenants
                ADD COLUMN language tenant_language NOT NULL DEFAULT 'ar';

            GRANT INSERT (language), UPDATE (language) ON tenants TO qrpager_app;
        SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->unprepared(<<<'SQL'
            ALTER TABLE tenants DROP COLUMN language;

            DROP TYPE tenant_language;
        SQL);
    }
};
