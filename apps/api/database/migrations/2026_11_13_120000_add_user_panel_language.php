<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `users.panel_language` — the language a PERSON reads the PANEL in. `QRPAGER-48`.
 *
 * ================================================================================
 * ⚠⚠ THIS IS NOT A SECOND COPY OF `tenants.language`, AND THE DISTINCTION IS THE POINT
 * ================================================================================
 *
 * Muhammad's ruling of 2026-11-13, recorded in `PLAN.md §2.4` so it is not re-derived:
 *
 *   "The PANEL's language is a property of the PERSON signed in. Two staff at the same
 *    restaurant can want different ones. It belongs on the user. `tenants.language` keeps
 *    exactly its current meaning: the language of the PUBLISHED page. That is a property of
 *    the restaurant, not of whoever is looking at the panel."
 *
 * **The defect this closes was measured before the column was designed.** `StudioLocale`
 * READ `tenants.language` to set the panel's locale, while `LandingBuilderPage::
 * setPageLanguage()` WRITES that same column under copy calling it *"the language and the
 * reading direction of your published page"*. One column, two meanings — so a topbar AR/EN
 * toggle over it would have let an owner change what their CUSTOMERS read by choosing what
 * THEY read.
 *
 * ================================================================================
 * NULLABLE, NO DEFAULT, NO BACKFILL — AND THAT IS THE WHOLE OF THE SAFETY
 * ================================================================================
 *
 * Resolution is `users.panel_language ?? tenants.language`, so every person who has not
 * chosen keeps **exactly today's behaviour** and this migration rewrites no row. `NULL`
 * means *"not chosen"*, which is a different fact from either language and is why the
 * column is not `NOT NULL DEFAULT 'ar'`.
 *
 * ⚠ **AND `§4`'s FORCE-RLS MIGRATION TRAP DOES NOT APPLY HERE**, which his ruling says in as
 * many words: *"It is a schema addition, not a data rewrite. Do not over-engineer it into
 * one."* `users` is a GLOBAL table (`§2.4`) with no `tenant_id` and no RLS at all, so
 * `§2.10`'s keyed-per-tenant pattern — which `QRPAGER-47`'s document migration had to
 * follow — has nothing to bite on. There is no `withTenant`, no audited bypass and no
 * per-tenant loop here, deliberately.
 *
 * ================================================================================
 * THE EXISTING ENUM, NOT A NEW ONE — `§2.3`'s SINGLE SOURCE
 * ================================================================================
 *
 * `tenant_language` already enumerates `('ar','en')`. A second type would let the two
 * columns drift, and a person could then choose a panel language the product cannot render.
 *
 * The grants are column-scoped like every other grant on this table, and deliberately
 * narrower than the column list around them: the app SELECTs it to resolve the locale and
 * UPDATEs it when the person chooses, and that is all. **No INSERT** — a new user has no
 * preference, which is what `NULL` already says — and **nothing for the worker**, which
 * sends notifications and has no business in a panel preference. `GrantMatrixTest` states
 * these as EXACT column sets, so this migration cannot widen them quietly.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE users ADD COLUMN panel_language tenant_language NULL;

            COMMENT ON COLUMN users.panel_language IS
                'The language this person reads the PANEL in. NULL means not chosen, and resolution falls back to tenants.language. NOT the published page language.';

            GRANT SELECT (panel_language) ON users TO qrpager_app;
            GRANT UPDATE (panel_language) ON users TO qrpager_app;
        SQL);
    }

    /**
     * Dropping the column drops its grants with it, so there is nothing to revoke: a
     * column-level privilege has no existence apart from the column.
     */
    public function down(): void
    {
        DB::unprepared('ALTER TABLE users DROP COLUMN panel_language;');
    }
};
