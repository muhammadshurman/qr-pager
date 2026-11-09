<?php

declare(strict_types=1);

namespace App\Features\Landing;

use RuntimeException;

/**
 * No row was updated when a restaurant changed its page language. `QRPAGER-30`.
 *
 * It names no tenant, no row count and no SQL: `§4.2`'s rule is that a refusal may say what
 * to do, never what exists, and `ProblemRenderer`'s clause is that no error body carries a
 * role name, a constraint name, SQL or a bound parameter at any `APP_DEBUG` setting.
 */
final class LanguageNotChanged extends RuntimeException
{
    public function __construct()
    {
        /*
         * ⚠ TRANSLATED HERE AND NOT AT THE PANEL — `QRPAGER-41`. This exception takes no
         * arguments and carries exactly one sentence, so the message IS the copy and there
         * is nothing for a key to add. `App\Features` may call `__()`; `App\Domain` may
         * not, which is the line this file sits on the right side of.
         */
        parent::__construct(__('landing.language.not_changed'));
    }
}
