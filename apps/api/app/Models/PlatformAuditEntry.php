<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row of `platform_audit_log` — a PLATFORM table, append-only (PLAN.md §2.4, §2.9).
 *
 * READ-ONLY BY CONSTRUCTION, which the ticket's §5 asks for by name: "the viewer is
 * read-only by construction -- do not add an edit path." Three independent things make it
 * so, and they are deliberately not interchangeable:
 *
 *   1. The `ENABLE ALWAYS` statement triggers on the table refuse UPDATE, DELETE and
 *      TRUNCATE for every role, the superuser included.
 *   2. No role holds UPDATE or DELETE on the table, so a statement is refused before a
 *      trigger has to fire.
 *   3. `$fillable` is empty and `$timestamps` is off, so there is no Eloquent path that
 *      would even assemble such a statement.
 *
 * A reviewer should read 1 as the real control; 3 is convenience and 2 is the belt.
 *
 * `$timestamps = false` because `created_at` is the database's (`DEFAULT now()`) and there
 * is no `updated_at` column at all — a log entry is never updated, so the column would be
 * a promise the table does not keep.
 */
class PlatformAuditEntry extends Model
{
    protected $table = 'platform_audit_log';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
