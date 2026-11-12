<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * `classic → rail`, `editorial → ember` — `QRPAGER-47` PART A, and **the project's first data
 * migration**.
 *
 * ================================================================================
 * WHY A DATA MIGRATION AT ALL, AND WHY IT CANNOT BE SKIPPED
 * ================================================================================
 *
 * `LandingTemplate` loses `Classic` and `Editorial` in the same commit as this file. A stored
 * document that still names one is then refused by `LandingDocument::fromInput()` — which is
 * the validator every read goes through: the studio's `mount()`, the media library's
 * reference sweep, a republish. So the choice is not *"migrate or leave it"*; it is *"migrate
 * or break the page of every tenant who was on one of the two retired arrangements"*.
 *
 * Muhammad's ticket is explicit about the mapping and about this being the first one:
 * *"stored documents are migrated: classic -> rail, editorial -> ember … This is the
 * project's FIRST data migration, and §4 already paid for the trap — read it before writing a
 * line."*
 *
 * ================================================================================
 * ⚠⚠ THE TRAP, AND THE PATTERN THAT ANSWERS IT — `PLAN.md §2.10`, FOLLOWED NOT RE-DERIVED
 * ================================================================================
 *
 * `owner` is bound by FORCE ROW LEVEL SECURITY like every other role, so a migration that
 * touches rows sees **none** of them — *including the `tenants` table it wants to loop over*.
 * `§2.10` records that as a corrected defect rather than as advice: pattern 1 used to open
 * with a plain `DB::connection('pgsql_owner')->table('tenants')->pluck('id')`, which returns
 * **zero rows** inside a migration, *"so the loop body never ran. A backfill that silently
 * does nothing is worse than one that fails loudly."*
 *
 * So, in order:
 *
 *   1. ONE `withoutTenantScope()` to enumerate tenants. That is the only part the owner
 *      genuinely cannot do under FORCE RLS. It refuses on any role but `qrpager_owner`,
 *      refuses to open inside a tenant scope, and writes its own `audit_log` row
 *      (`actor_type = system`, `action = tenancy.bypass`, this reason in `context`) inside
 *      the same transaction — so this migration is auditable after the fact by the same
 *      mechanism every other cross-tenant act in this product is.
 *   2. `withTenant()` per tenant for the work itself, on the owner connection.
 *   3. A **policy-limited SELECT that reads the key**, then an **UPDATE keyed by that id**,
 *      with the affected-row count checked.
 *
 * ⚠ STEP 3 IS THE ONE THAT BITES, AND IT IS NOT A STYLE PREFERENCE. `PROJECT_INSTRUCTIONS.md
 * §4 › Postgres traps`, measured on 16.14: *"A bulk `UPDATE` or `DELETE` that references no
 * column of the table escapes RLS row filtering entirely and writes rows the role cannot
 * read … a data migration running as `owner` under FORCE RLS is the most likely place this
 * bites."* `UPDATE landing_pages SET draft_document = …` with no `WHERE` would have been the
 * shortest way to write this file and would have written **every tenant's row from inside one
 * tenant's scope**. Every statement below names `id`, and every one has its count read back.
 *
 * ================================================================================
 * ⚠ `jsonb_set(…, create_missing => false)`, SO MOST DOCUMENTS ARE NEVER WRITTEN
 * ================================================================================
 *
 * A document that carries **no** `template` member — every page stored before `QRPAGER-33`,
 * and there are live ones — needs no write at all: it takes `LandingTemplate::DEFAULT`, and
 * that constant moved from `Classic` to `Rail` in the same commit. `create_missing = false`
 * makes that explicit rather than incidental: the statement cannot invent the member, so a
 * document without one is left byte-for-byte as it was stored.
 *
 * And the member is replaced rather than the document re-encoded. A round trip through
 * `json_decode`/`json_encode` would rewrite key order and whitespace in every tenant's page,
 * which makes the diff of a one-word change unbounded and puts the 256 KB bound
 * (`landing_pages_draft_is_bounded`) in play for a change that adds nothing.
 *
 * ================================================================================
 * ⚠⚠ `down()` IS A DELIBERATE NO-OP. THE REASON IS WRITTEN OUT, NOT ASSUMED.
 * ================================================================================
 *
 * `up()` writes only `rail` and `ember`, and **both of those cases exist in the rolled-back
 * code as well** — reverting this ticket restores `classic` and `editorial` beside them, it
 * does not remove the two the data now names. So every document this migration touched is
 * still valid against the restored enum, and there is nothing a rollback must repair.
 *
 * The alternative — mapping `rail → classic` and `ember → editorial` — is **worse than doing
 * nothing**, and that is the whole argument: after Part B ships, a tenant can CHOOSE the
 * ledger, and nothing in the row distinguishes a tenant who chose it from one this migration
 * moved. A `down()` that mapped back would silently rewrite the first tenant's page to an
 * arrangement they never picked. An irreversible-by-nature mapping is reported as such rather
 * than faked, and `§6`'s *"a rollback of this task's migrations runs clean"* is proven by
 * running `migrate:rollback` and then `migrate` again (`RetiredTemplateTest`).
 */
return new class extends Migration
{
    /**
     * The retired case and the arrangement that replaces it.
     *
     * Deliberately a literal map and not `LandingTemplate::tryFrom()`: the keys name cases
     * that **no longer exist in the enum** by the time this file runs, so reading them from
     * the enum is impossible by construction. The values are the strings `Rail` and `Ember`
     * carry, and `RetiredTemplateTest` asserts both are still cases — which is what keeps
     * this map honest if a later ticket renames one.
     */
    private const MAPPING = [
        'classic' => 'rail',
        'editorial' => 'ember',
    ];

    /** The two jsonb columns a landing document is stored in (`PLAN.md §4b` B2). */
    private const COLUMNS = ['draft_document', 'published_document'];

    public function up(): void
    {
        $connection = 'pgsql_owner';

        /** @var Collection<int, mixed> $tenants */
        $tenants = withoutTenantScope(
            '2026_11_12 QRPAGER-47 retire classic and editorial: list tenants',
            static fn () => DB::connection($connection)->table('tenants')->pluck('id'),
            $connection,
        );

        foreach ($tenants as $tenantId) {
            withTenant((string) $tenantId, function () use ($connection): void {
                /*
                 * The policy-limited read that `§4 › Postgres traps` prescribes: inside this
                 * tenant's scope RLS leaves at most one row — `landing_pages_tenant_unique`
                 * makes "the tenant's page" a well-defined object — and this is where the key
                 * comes from. A tenant with no page at all returns null and is skipped.
                 */
                $page = DB::connection($connection)
                    ->table('landing_pages')
                    ->select(['id', ...self::COLUMNS])
                    ->first();

                if ($page === null) {
                    return;
                }

                foreach (self::COLUMNS as $column) {
                    $this->migrateColumn($connection, (string) $page->id, $column, $page->{$column});
                }
            }, $connection);
        }
    }

    /**
     * `down()` restores nothing, and the docblock above says why at length.
     *
     * It is written out rather than omitted so that `migrate:rollback` runs this file and
     * succeeds — a missing `down()` on a non-anonymous migration is a fatal, and a `down()`
     * that threw would make the rollback of any LATER migration impossible to run too.
     */
    public function down(): void
    {
        // Intentionally empty. `rail` and `ember` are valid cases on both sides of this
        // ticket, so a rolled-back schema reads every row this migration wrote.
    }

    /**
     * One column of one tenant's page, keyed and counted.
     *
     * @param  mixed  $stored  the column as it was read inside this tenant's scope
     */
    private function migrateColumn(string $connection, string $pageId, string $column, mixed $stored): void
    {
        if (! is_string($stored)) {
            return;
        }

        /** @var array<string, mixed> $document */
        $document = json_decode($stored, true, 32, JSON_THROW_ON_ERROR) ?: [];
        $current = $document['template'] ?? null;

        if (! is_string($current) || ! array_key_exists($current, self::MAPPING)) {
            return;
        }

        $replacement = self::MAPPING[$current];

        /*
         * ⚠ KEYED BY `id`, AND GUARDED BY THE VALUE IT EXPECTS TO FIND. Both halves are the
         * pattern `§4 › Postgres traps` ends on: the key is what keeps RLS row filtering in
         * force, and the value guard is the idempotency signal — a second run matches nothing
         * and reports 0, which is how this migration is safe to re-apply.
         *
         * The column name is interpolated and the two values are bound. The name comes from
         * `self::COLUMNS`, a private literal list in this file: there is no path from any
         * input to it, and `MediaLibrary` already iterates the same pair by name.
         */
        $affected = DB::connection($connection)->update(
            <<<SQL
                UPDATE landing_pages
                   SET {$column} = jsonb_set({$column}, '{template}', ?::jsonb, false)
                 WHERE id = ?
                   AND {$column}->>'template' = ?
                SQL,
            [json_encode($replacement, JSON_THROW_ON_ERROR), $pageId, $current],
        );

        /*
         * ⚠ THE COUNT IS READ BACK, WHICH IS THE OTHER HALF OF `§2.10`'s CORRECTION. A
         * backfill that silently does nothing is worse than one that fails loudly: the row
         * was just read inside this scope and named by its primary key, so 0 here means
         * either the row moved under us or the scope is not what this code believes it is,
         * and both are reasons to stop the migration rather than to carry on quietly.
         */
        if ($affected !== 1) {
            throw new RuntimeException(
                "retiring {$current} wrote {$affected} rows for landing_pages.{$column} keyed by its own id",
            );
        }
    }
};
