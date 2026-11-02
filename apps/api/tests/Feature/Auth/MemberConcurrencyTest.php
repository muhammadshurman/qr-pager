<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * THE LAST-OWNER RULE UNDER TRUE CONCURRENCY
 * ================================================================================
 *
 * `MemberDirectory` refuses to demote or remove a tenant's LAST owner, and its own comment
 * calls that "the one bound in this class with no RLS or middleware layer underneath it".
 * It was checked with an unlocked `select count(*)` inside a READ COMMITTED transaction, so
 * two concurrent demotions of two DIFFERENT owners each saw two owners, each believed it
 * was leaving one behind, and both committed:
 *
 *     A sees owners: 2     B sees owners: 2
 *     FINAL owner count for the tenant: 0
 *
 * A tenant with no owners has NO IN-PRODUCT RECOVERY at this task: `RoleMatrix` grants
 * `PlatformAdmin` nothing and the admin surface is Task 6, so nobody can repair it through
 * the API. `MembersTest` drives the rule sequentially and passes either way, which is why
 * this file exists rather than a case being added there.
 *
 * TWO PROCESSES AND NOT TWO CONNECTIONS, for the reason `PagerIdempotencyTest` states and
 * this fix makes load-bearing: the rule now takes `select ... for update`, and a second
 * connection in this one PHP process would block the only thread there is -- the test would
 * deadlock instead of demonstrating order.
 */

/** A second (or third) owner in an existing tenant. Returns the membership id. */
function extraOwner(string $tenantId, TenantRole $role = TenantRole::Owner): string
{
    $userId = (string) Str::uuid7();
    $membershipId = (string) Str::uuid7();

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $userId,
        'name' => 'Second Owner',
        'email' => 'owner-'.str_replace('-', '', $userId).'@example.test',
    ]);

    withTenant($tenantId, fn () => DB::connection('pgsql_owner')->table('tenant_users')->insert([
        'id' => $membershipId,
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'role' => $role->value,
    ]), 'pgsql_owner');

    return $membershipId;
}

/** Owners currently in the tenant, read as the owner role so RLS is not the thing under test. */
function ownerCountOf(string $tenantId): int
{
    return withTenant($tenantId, fn (): int => (int) DB::connection('pgsql_owner')
        ->table('tenant_users')
        ->where('tenant_id', $tenantId)
        ->where('role', TenantRole::Owner->value)
        ->count(), 'pgsql_owner');
}

it('never leaves a tenant with zero owners when two owners are demoted at the same instant', function (): void {
    /*
     * ⚠ THE RACE IS RUN THREE TIMES, AND THAT IS NOT BELT AND BRACES.
     *
     * The first version launched the two children and let them run. The `§11` review of the
     * fix diff reverted `lockOwnerCount()` to the unlocked count and ran this file five
     * times: red, GREEN, red, red, red. So the only guard on a state with no in-product
     * repair -- a tenant with zero owners, and the admin surface is Task 6 -- would have
     * let the defect through one run in five.
     *
     * Two things fix that, and neither touches production code. The children now align on a
     * START BARRIER (their docblock has it), so Laravel's ~150 ms boot is outside the window
     * rather than inside it; and the race is run three times, so a per-attempt miss rate of
     * even one in five leaves under a 1% chance of a green run against broken code. With
     * the lock in place every attempt is deterministic, so this costs nothing in the green
     * direction -- measured, 6/6 green before the barrier and after it.
     */
    foreach (range(1, 3) as $attempt) {
        $tenant = $this->makeTenant();

        /*
         * ⚠ **THE ACTOR IS A MANAGER AND BOTH RACE TARGETS ARE OTHER PEOPLE'S MEMBERSHIPS —
         * CHANGED AT TASK 10e, AND IT IS THE SCENARIO `QRPAGER-29 §3` Q6 ALREADY NAMED:**
         * *"two managers demoting two different owners at the same instant."*
         *
         * This used to make the acting OWNER one of the two targets. Since 10e,
         * `§4 › Security`'s third limb — *no actor may … change its own role at all* — refuses
         * that child before it reaches the owner-count lock, so the race had one real
         * contender and one `MemberActionDenied`: it would have been asserting 10e's guard
         * while claiming to assert the lock, and the `['demoted', 'refused']` shape below could
         * never be produced again.
         *
         * Two owners who are not the actor keeps the race exactly as it was. The acting user
         * holds `manager`, which `requireSeniority()` permits to grant `staff` — the role the
         * child demotes to — so the only thing that can refuse either child is the last-owner
         * rule, which is what this file is about.
         */
        // The MANAGER who drives both demotions, created first so that the tenant's only
        // owners are the two race targets. `actingForTenant` makes a new user with a
        // membership in this tenant and authenticates as them.
        $this->actingForTenant($tenant, TenantRole::Manager);

        $actorUserId = (string) auth()->id();

        $first = extraOwner($tenant);
        $second = extraOwner($tenant);

        /*
         * ⚠ **EXACTLY TWO OWNERS, AND NEITHER IS THE ACTOR. BOTH HALVES MATTER.**
         *
         * TWO, because that is what makes the race meaningful: demoting both would leave ZERO,
         * so the rule must refuse the second — and against an unlocked count both callers see
         * two owners, each believes it is leaving one behind, and the tenant ends with none.
         * With three owners both demotions are legal and the lock is never engaged, so the
         * race would prove nothing.
         *
         * NEITHER IS THE ACTOR, because 10e's self-role guard would otherwise refuse one child
         * before it reached the lock.
         */
        expect(ownerCountOf($tenant))->toBe(2);

        $script = base_path('tests/Support/demote-owner-in-another-process.php');
        $database = (string) config('database.connections.pgsql.database');
        $barrier = sys_get_temp_dir().'/qrpager-owner-race-'.Str::random(12);

        // Merged, not replaced: proc_open's $env REPLACES the environment, so a bare array
        // loses PATH and everything .env resolution needs -- the lesson
        // PagerIdempotencyTest's comment records, and a child that produces no output at
        // all reads as a concurrency result rather than as a broken launch.
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_DATABASE' => $database,
            'PAO_DISABLE' => '1',
        ]);

        $children = [];

        foreach ([$first, $second] as $i => $membershipId) {
            $children[$i] = ['handle' => proc_open(
                ['php', $script, $tenant, (string) $membershipId, $actorUserId, $barrier],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $children[$i]['pipes'],
                base_path(),
                $env,
            ), 'pipes' => $children[$i]['pipes'] ?? []];

            expect($children[$i]['handle'])->not->toBeFalse("could not start process {$i}");
        }

        // BOTH BOOTED AND WAITING before either is released, which is what makes the two
        // transactions start together instead of whenever each finished booting.
        $deadline = microtime(true) + 30.0;

        // ⚠ THROWS RATHER THAN ASSERTS, and the reason is this project's own evidence
        // practice. An `expect()` inside a spin loop runs an unpredictable number of times,
        // so the file's assertion count varied run to run -- measured at 248, 253, 259 and
        // 264 over four green runs. `§4a` publishes "N passed / M assertions" at a commit for
        // every figure, and a non-reproducible M makes that figure meaningless.
        while (count(glob($barrier.'.ready.*') ?: []) < 2) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('the two children never both reached the barrier');
            }

            usleep(1000);
        }

        touch($barrier.'.go');

        $outcomes = [];

        foreach ($children as $child) {
            $outcomes[] = trim((string) stream_get_contents($child['pipes'][1]));
            $stderr = trim((string) stream_get_contents($child['pipes'][2]));
            fclose($child['pipes'][1]);
            fclose($child['pipes'][2]);
            proc_close($child['handle']);

            expect($stderr)->toBe('', "a child process wrote to stderr: {$stderr}");
        }

        foreach (glob($barrier.'*') ?: [] as $leftover) {
            @unlink($leftover);
        }

        sort($outcomes);

        // THE INVARIANT, asserted first because it is the one that matters: whatever the
        // interleaving, the tenant still has an owner. Against the unlocked count this read
        // 0.
        expect(ownerCountOf($tenant))->toBe(1,
            "attempt {$attempt}: two concurrent demotions left the tenant with the wrong number of owners",
        );

        // And the shape of the outcome: exactly one caller won, and the loser was REFUSED
        // rather than erroring. A deadlock, a serialisation failure or an exception would
        // all leave the invariant intact and are all still wrong -- a 40P01 surfacing to a
        // staff user as a 500 is not "the rule held".
        expect($outcomes)->toBe(['demoted', 'refused'],
            "attempt {$attempt}: expected one demotion and one refusal, got: ".implode(', ', $outcomes),
        );
    }
});

it('still refuses the last owner when nothing is racing it', function (): void {
    /*
     * The sequential half, kept here beside the concurrent one so the lock cannot be "fixed"
     * by removing the rule: one owner, demotion refused, removal refused, and the row
     * unchanged after both.
     *
     * ⚠ DRIVEN BY A MANAGER SINCE TASK 10e, for the concurrent case's reason: the actor used to
     * target their OWN membership, which `§4 › Security`'s self-role limb now refuses earlier
     * and with a 403 — so the request no longer reached the last-owner rule this case is about.
     * The self-role refusal has its own cases in `MemberSeniorityTest`.
     */
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant, TenantRole::Manager);

    $membershipId = extraOwner($tenant);

    expect(ownerCountOf($tenant))->toBe(1);

    $this->patchJson("/v1/members/{$membershipId}", ['role' => 'staff'])->assertStatus(404);
    $this->deleteJson("/v1/members/{$membershipId}")->assertStatus(404);

    expect(ownerCountOf($tenant))->toBe(1);
});
