<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `qrpager_app` MAY ROTATE A PAGER SESSION's `token_hash` — TASK 10d, AND IT IS A CHANGE TO A
 * DELIBERATE GRANT POSTURE RATHER THAN A FILLED GAP.
 *
 * ================================================================================
 * WHAT THE POSTURE WAS, AND IT WAS NOT AN OVERSIGHT
 * ================================================================================
 *
 * `2026_10_25_150300_grant_runtime_role_privileges.php` gives `qrpager_app`, on
 * `pager_sessions`:
 *
 *   `SELECT`  every column **except `token_hash`** — the app role cannot read it at all;
 *   `INSERT`  including `token_hash` — it may write one, once, at creation;
 *   `UPDATE`  `status, ready_at, completed_at, cancelled_at, last_notified_at,
 *             renotify_count, updated_at` — and **not `token_hash`**.
 *
 * That is a **write-once credential**: created once, never read, never changed. The same shape
 * `DeviceToken`'s docblock describes for `personal_access_tokens` and gives the reason for —
 * *"withholding the grant makes that structural: a bug in this file cannot widen a token,
 * because the role cannot write the columns."*
 *
 * ================================================================================
 * WHY IT MOVES, AND THE RULING THAT MOVES IT
 * ================================================================================
 *
 * Muhammad's ruling, `QRPAGER-28 §2`: *"a sheet is RE-ISSUED, never reprinted"* — because the
 * raw token exists once, at generation, and **cannot be re-rendered from the database**, so a
 * staff member holding a lost or ruined sheet can only be given a NEW code for the same order.
 * `PagerSessions::reissue()` implements that as a token rotation on the existing row, and the
 * three alternatives are rejected in its docblock with reasons.
 *
 * ⚠ **THE COLLISION WAS FOUND BY THE DATABASE REFUSING THE WRITE, NOT BY READING THE GRANT** —
 * `SQLSTATE[42501]: permission denied for table pager_sessions` on the `update … set
 * token_hash`, surfaced through `ProblemRenderer` as a **403**, which is how six cases of
 * `PagerReissueTest` first failed. The grant posture is doing exactly what it was built to do;
 * the ruling asks for the one capability it withheld.
 *
 * ================================================================================
 * WHY THIS IS THE LEAST-PRIVILEGE WAY TO SATISFY THE RULING — THREE ALTERNATIVES, MEASURED
 * ================================================================================
 *
 * **(1) Terminate the old session and create a new one.** Needs no grant change, and is
 * SEMANTICALLY FALSE in both available forms. `cancelled` tells the customer the restaurant
 * cancelled their order, which is not true — the food is still coming. `expired` claims the
 * two-hour life ran out, which is also untrue, and `qrpager_app` holds no `UPDATE (expired_at)`
 * either, so the row would carry `status = expired` with a NULL `expired_at` — an inconsistent
 * row to avoid a grant. It also puts TWO orders in the history where the restaurant had one.
 *
 * **(2) Write as `pgsql_owner` from the request path.** Strictly worse. No production request
 * path in this application uses that connection — measured: the only `pgsql_owner` references
 * in `app/` are a console command and the boot checks — and introducing one would put a role
 * with RLS BYPASS available into an HTTP handler, to avoid granting one column.
 *
 * **(3) This grant.** One column, named, on one table.
 *
 * ================================================================================
 * WHAT IT COSTS, STATED PLAINLY RATHER THAN ARGUED AWAY
 * ================================================================================
 *
 * `qrpager_app` can now replace the credential of any session **inside a tenant it is scoped
 * to** (RLS is unchanged and still bounds it to that tenant). So a bug or an injection on the
 * app role could void live pagers.
 *
 * **And that is a STRICTLY LESSER capability than one the role already holds**, which is the
 * argument that makes this proportionate: it already has `UPDATE (status)`, so it can already
 * set every live session in its tenant to `cancelled` — which voids the same pagers **and
 * notifies every one of those customers that their order is cancelled.** Rotating a hash is
 * the quieter half of a harm already available.
 *
 * **WHAT IT DOES NOT GRANT, and these are the checks a reviewer should make:**
 *
 *   - **No `SELECT (token_hash)`.** The app role still cannot READ the column, so this does
 *     not make any credential readable — only replaceable. That is the difference between a
 *     disclosure and a denial of service, and the disclosure half is untouched.
 *   - **Nothing table-wide.** One column, named, exactly as
 *     `2026_11_01_120000_grant_passkey_credential_update.php` does for `passkeys.credential`
 *     and for the same stated reason.
 *   - **No new ability to bind a CHOSEN token to a session.** The role could already do that
 *     with its `INSERT (token_hash)` grant by creating a session; being able to do it to an
 *     *existing* row adds that row's history and audit trail and nothing else.
 *
 * `PagerGrantNarrowingTest` pins the resulting UPDATE set EXACTLY, so a later widening fails
 * there rather than passing quietly — `AuthGrantNarrowingTest`'s own mechanism, applied to the
 * table this migration touches.
 *
 * ⚠ **RAISED WITH MUHAMMAD RATHER THAN DECIDED QUIETLY.** `PLAN.md §0` records it and `§5`
 * carries it as a row: a grant posture with written reasoning behind it moved, and the person
 * who ruled the feature in should see that the ruling had a database cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('pgsql_owner')->unprepared(<<<'SQL'
            GRANT UPDATE (token_hash) ON pager_sessions TO qrpager_app;
        SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->unprepared(<<<'SQL'
            REVOKE UPDATE (token_hash) ON pager_sessions FROM qrpager_app;
        SQL);
    }
};
