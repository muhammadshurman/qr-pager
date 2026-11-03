<?php

declare(strict_types=1);

namespace App\Features\Landing;

use App\Domain\Tenancy\TenantLanguage;
use Illuminate\Support\Facades\DB;

/**
 * A restaurant's own language: read it, set it. `QRPAGER-30`.
 *
 * ================================================================================
 * WHY A CLASS OF ITS OWN AND NOT A FIELD ON THE BUILDER'S FORM
 * ================================================================================
 *
 * The builder's Filament schema maps to the landing **document** -- `StudioForm::toDocument()`
 * turns its whole state into `blocks`, `theme` and `seo`, which is the contract
 * `docs/contracts/landing-page.schema.json` validates. `language` is a column on `tenants`,
 * not a member of that document, and the ruling is explicit that it is one stored value in
 * one place. A field inside that schema would either have to be stripped before
 * `toDocument()` or would ride into the document as a tenth member nothing validates -- the
 * second-source defect, arriving through a form.
 *
 * ================================================================================
 * BOTH METHODS RUN INSIDE THE TENANT'S OWN SCOPE
 * ================================================================================
 *
 * `tenants` carries `ENABLE` + `FORCE` ROW LEVEL SECURITY (`§2.5`), so `withTenant()` is not
 * a convenience here: without a bound scope the app role sees **zero rows** and `set()` would
 * silently update nothing while reporting success. That is the shape
 * `DataMigrationPatternTest` exists for, and it is why `set()` checks the affected row count
 * rather than trusting the statement.
 *
 * `§2.8`'s named list of cross-tenant reads is untouched: one tenant's own row, inside its
 * own scope, on both paths.
 */
final class TenantPageLanguage
{
    public function current(string $tenantId): TenantLanguage
    {
        /** @var string|null $value */
        $value = withTenant($tenantId, static fn (): ?string => DB::table('tenants')->value('language'));

        /*
         * `DEFAULT` and not an exception for a missing row: `TenantPanelAccess` has already
         * resolved this tenant, so a null here means the app role could not see the row --
         * which is a scope failure, not a data one, and the page it feeds must still render.
         * The column is `NOT NULL`, so a row that IS visible always has a value.
         */
        return $value === null ? TenantLanguage::DEFAULT : TenantLanguage::from($value);
    }

    /**
     * @throws LanguageNotChanged when no row was updated
     */
    public function set(string $tenantId, TenantLanguage $language): void
    {
        /** @var int $affected */
        $affected = withTenant($tenantId, static fn (): int => DB::table('tenants')->update([
            'language' => $language->value,
            'updated_at' => now(),
        ]));

        /*
         * ⚠ THE ROW COUNT IS CHECKED, which is `§4 › Postgres traps` applied to the one write
         * this class makes. Under FORCE RLS an out-of-scope UPDATE affects zero rows and
         * raises nothing at all, so an unchecked `update()` would report success to the
         * restaurant and publish the old language on the next publish.
         */
        if ($affected !== 1) {
            throw new LanguageNotChanged;
        }
    }
}
