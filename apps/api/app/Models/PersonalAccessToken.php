<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Sanctum\PersonalAccessToken as BaseToken;

/**
 * Sanctum's token model, with a uuid key.
 *
 * THE SECOND INSTANCE OF ONE MISMATCH, and worth naming as a pattern rather than fixing
 * twice in silence: both `laravel/passkeys` and `laravel/sanctum` ship a migration using
 * `$table->id()`, so both of their models assume an auto-incrementing integer key. This
 * project's ids are uuids (`PLAN.md §2.5`) and its migrations are its own, so the column has
 * no default and an insert through the vendor model sends no id.
 *
 * Measured rather than predicted -- the first device-token issue answered 500 with:
 *
 *     SQLSTATE[23502]: null value in column "id" of relation "personal_access_tokens"
 *     violates not-null constraint
 *
 * which is the loud failure direction, and is why this is a test catching a wiring gap
 * rather than a column quietly filling with something unintended.
 *
 * `HasUuids` is the whole of the difference. Registered through
 * `Sanctum::usePersonalAccessTokenModel()` in AppServiceProvider, the package's own seam.
 *
 * Nothing else changes. The parent's `$hidden` keeps `token` out of serialisation, its
 * `abilities` cast stays json, and `can()`/`cant()` -- which is what bounds a device
 * token's capability -- are inherited untouched.
 */
class PersonalAccessToken extends BaseToken
{
    use HasUuids;
}
