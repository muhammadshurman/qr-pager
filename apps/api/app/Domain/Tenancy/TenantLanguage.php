<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Concerns\HasDatabaseValues;
use App\Domain\DatabaseEnum;

/**
 * A restaurant's own language, and the direction DERIVED from it.
 *
 * Mirrored by the Postgres enum type [tenant_language] (PLAN.md §2.3, §2.5). Ruled by
 * Muhammad on 2026-11-03 (PLAN.md §1); built by QRPAGER-30, which owns R-132 and R-221.
 *
 * ================================================================================
 * `dir` IS A METHOD AND NEVER A COLUMN, WHICH IS THE WHOLE OF THE RULING
 * ================================================================================
 *
 * *"Two columns that must agree are two sources of truth, and the disagreement here does
 * not fail loudly, it renders a restaurant's page backwards."* So there is one stored
 * value and one derivation, and the derivation lives here because this is the only place
 * that knows the set of cases.
 *
 * ================================================================================
 * ⚠ A NATIVE ENUM AND NOT `varchar(2)` + CHECK, WHICH THE RULING'S TEXT SAID
 * ================================================================================
 *
 * `QRPAGER-30 §5` made that a §6 Q1 question with an explicit tiebreaker -- *"`varchar(2)`
 * with a CHECK, not an enum type, UNLESS §2.3's enum convention is read first and says
 * otherwise"* -- and §2.3 says otherwise: *"The PHP enum is the source. The Postgres type is
 * a native ENUM created by migration, and EnumContractTest compares pg_enum labels against
 * the PHP cases for every pair in DatabaseEnums::map()."* Nine stored columns in this
 * schema, nine native enums.
 *
 * The deciding argument is a second source `varchar` cannot close. The launch-language list
 * would otherwise sit in four places with nothing tying them: the migration's CHECK,
 * `@qrpager/ui`'s `LOCALES`, the studio's field, and PHP validation. Registered here it is
 * one source with two mechanical checks that already exist -- `EnumContractTest` for
 * PHP <-> Postgres and `composer contracts:check` for the generated TypeScript mirror.
 *
 * ⚠ AND THE CONSEQUENCE, STATED RATHER THAN DISCOVERED: the invariant's fourth clause asks
 * for a third value to be refused `23514` (check_violation). A native enum refuses it
 * `22P02` (invalid_text_representation). The property -- the DATABASE refuses it, not the
 * application -- is identical and is driven; only the code differs. Recorded on QRPAGER-30
 * comment 10108 and in PLAN.md §1, §2.5 and §2.7, which carried the varchar shape.
 */
enum TenantLanguage: string implements DatabaseEnum
{
    use HasDatabaseValues;

    case Arabic = 'ar';
    case English = 'en';

    /**
     * The default a tenant who never touches the setting gets.
     *
     * ⚠ IT IS NOT THE AUTHORITY, AND THAT IS THE POINT. The authority is the COLUMN's
     * `DEFAULT 'ar'`, because a row inserted by anything other than this application's own
     * path must still carry a language -- `QRPAGER-30 §5`: *"a code default would make a row
     * inserted by anything other than the app's own path carry nothing, and NOT NULL would
     * then fail at a distance from the cause."* This constant exists so a READER with no row
     * (a preview of an unsaved document, a test fixture) resolves the same way the database
     * would, and `TenantLanguageContractTest` pins it against the column's own default read
     * back from `pg_attrdef` -- so the two cannot drift.
     */
    public const DEFAULT = self::Arabic;

    /** `ar` -> `rtl`, `en` -> `ltr`. The one place this mapping exists on the PHP side. */
    public function direction(): string
    {
        return match ($this) {
            self::Arabic => 'rtl',
            self::English => 'ltr',
        };
    }
}
