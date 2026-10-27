<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * R-003: bound the worker's two cross-tenant pager_sessions policies by TIME AND STATUS.
 *
 * They were bounded by time alone -- `expires_at <= now()` -- and `expires_at` only
 * recedes into the past. So the worker held a permanent, context-free, cross-tenant READ
 * of every historical session (everything but token_hash) and a cross-tenant WRITE over
 * every terminal row: it could mark another tenant's `completed` order `expired`, with no
 * audit row. F3, security review of task-1; owner Task 3, because §3 row 3 puts the
 * expiry job and its gate clause here and the task that owns the job should narrow the
 * policy and re-prove the job in the same change.
 *
 * A NEW MIGRATION AND NOT AN EDIT to 2026_10_25_150200. A migration is a snapshot:
 * editing the shipped one would change what a fresh database gets and leave every
 * already-migrated database -- including a reviewer's -- on the old policies with no
 * signal. task-1 is tagged.
 *
 * WHY 'expired' IS IN THE SELECT WHITELIST, which reads like a widening and is the exact
 * opposite. Verified on 16.14 as part of this change, and it is §2.12's mechanism: an
 * UPDATE that references any column of the table re-checks the NEW row against the SELECT
 * policies. The prescribed pattern references columns, so the new row -- status
 * 'expired' -- has to satisfy them. Measured with 'expired' removed from the whitelist:
 * the prescribed statement raises 42501 and EVERY expiry fails.
 * PROJECT_INSTRUCTIONS.md §4 › Postgres traps prescribes precisely this shape -- "a
 * status whitelist that contains the status the update writes" -- and that is what this
 * is.
 *
 * Measured after this change, as qrpager_worker with no tenant context:
 *
 *   select id, status cross-tenant         -> only waiting/ready past TTL (was: everything)
 *   expire a past-TTL waiting row          -> UPDATE 1
 *   expire a past-TTL ready row            -> UPDATE 1
 *   expire another tenant's completed row  -> UPDATE 0   (was: UPDATE 1 -- the hole)
 *   expire another tenant's cancelled row  -> UPDATE 0   (was: UPDATE 1)
 *   expire a row inside its TTL            -> UPDATE 0
 *   waiting -> ready on a visible row      -> 42501      (WITH CHECK, unchanged)
 *
 * The policy NAMES are unchanged on purpose, so RlsCoverageTest's "exactly the four named
 * cross-tenant policies and no others" still holds without an edit -- the set of
 * cross-tenant grants is the same set, each one narrower.
 *
 * CREATE OR REPLACE POLICY does not exist in Postgres 16, so each one is dropped and
 * recreated. Both statements are in one DB::unprepared, and Migrator wraps the migration
 * in a transaction (PostgresGrammar reports supportsSchemaTransactions), so there is no
 * window in which pager_sessions has no worker policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DROP POLICY worker_reads_expired_sessions ON pager_sessions;
            DROP POLICY worker_expires_sessions ON pager_sessions;

            -- The expiry job's read. 'expired' is here because the UPDATE below writes it
            -- and the new row is re-checked against this policy (§2.12). 'completed' and
            -- 'cancelled' are NOT: a terminal session that is not expiring is none of the
            -- worker's business, and that is the cross-tenant read R-003 is about.
            CREATE POLICY worker_reads_expired_sessions ON pager_sessions
                FOR SELECT TO qrpager_worker
                USING (expires_at <= now() AND status IN ('waiting', 'ready', 'expired'));

            -- USING bounds the OLD row: only a live session past its TTL may be touched,
            -- so another tenant's completed or cancelled row is skipped rather than
            -- rewritten. WITH CHECK still pins the only status the worker may write
            -- without context.
            CREATE POLICY worker_expires_sessions ON pager_sessions
                FOR UPDATE TO qrpager_worker
                USING (expires_at <= now() AND status IN ('waiting', 'ready'))
                WITH CHECK (status = 'expired');
        SQL);
    }

    /**
     * Back to the time-only form, which is what this migration reverses.
     *
     * Deliberately the WIDER policies and not "the narrow ones minus nothing": a down()
     * that left the new behaviour in place would make `migrate:rollback` followed by
     * `migrate` a no-op in one direction only, and the §6 clause is that a rollback runs
     * clean and a re-apply returns the IDENTICAL schema. The schema fingerprint reads
     * policy expressions (database/schema-fingerprint.sql), so an asymmetric down() shows
     * up there as a diff rather than as an opinion.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP POLICY worker_reads_expired_sessions ON pager_sessions;
            DROP POLICY worker_expires_sessions ON pager_sessions;

            CREATE POLICY worker_reads_expired_sessions ON pager_sessions
                FOR SELECT TO qrpager_worker USING (expires_at <= now());

            CREATE POLICY worker_expires_sessions ON pager_sessions
                FOR UPDATE TO qrpager_worker
                USING (expires_at <= now())
                WITH CHECK (status = 'expired');
        SQL);
    }
};
