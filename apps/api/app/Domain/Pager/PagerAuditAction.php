<?php

declare(strict_types=1);

namespace App\Domain\Pager;

/**
 * The `audit_log.action` vocabulary this feature writes.
 *
 * One source, for the same reason as PagerEvent: `action` is varchar(64) rather than a
 * Postgres enum (§2.5), so nothing in the database constrains it and an action name
 * invented at a call site would be invisible until somebody tried to query the log.
 *
 * `subject_type` is the same for all of them, so it lives here too -- §2.5 gives
 * audit_log `subject_type varchar(64) NULL` and `subject_id uuid NULL` and no foreign
 * key, deliberately: an ON DELETE SET NULL would be an UPDATE and the append-only
 * trigger blocks it, so the log keeps ids rather than references.
 *
 * Every transition writes one of these, including the two that write no outbox row. The
 * audit log is the record that the action happened; the outbox is the record that
 * somebody has to be told. Conflating them is how a complete or an expire ends up
 * unaudited.
 */
enum PagerAuditAction: string
{
    case Created = 'pager.session.created';
    case Ready = 'pager.session.ready';
    case Renotified = 'pager.session.renotified';
    case Completed = 'pager.session.completed';
    case Cancelled = 'pager.session.cancelled';
    case Expired = 'pager.session.expired';

    /** What `subject_type` carries for every row this feature writes. */
    public const SUBJECT = 'pager_session';
}
