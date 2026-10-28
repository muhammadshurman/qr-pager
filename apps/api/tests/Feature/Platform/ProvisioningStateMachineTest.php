<?php

declare(strict_types=1);

use App\Domain\Provisioning\ProvisioningStatus;
use App\Features\Provisioning\PlatformAudit;
use App\Features\Provisioning\ProvisioningExhausted;
use App\Features\Provisioning\ProvisioningTransitionDenied;
use App\Features\Provisioning\TenantProvisioning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * QA M-3 and H-2: the provisioning state machine, per edge.
 *
 * `ProvisioningStatus::allows()` had no test at all -- unlike `PagerStatus`, whose
 * transition table `PagerTransitionTest` drives -- so the enum carried a table nothing
 * compared against behaviour, `ProvisioningTransitionDenied` was never constructed (0/1
 * covered), and two edges had neither caller nor test.
 */

function stateMachineRequest(string $slug): string
{
    return app(TenantProvisioning::class)->request(
        name: 'State '.$slug,
        slug: $slug,
        ownerEmail: $slug.'@example.test',
        requestedByUserId: null,
    );
}

/** Force a registry row to a status, through the granted UPDATE columns. */
function forceStatus(string $id, ProvisioningStatus $status, ?int $attempts = null): void
{
    DB::update(
        'update tenant_provisionings
            set status = ?::provisioning_status,
                attempts = coalesce(?, attempts),
                failure_reason = case when ? = \'failed\' then \'forced\' else failure_reason end,
                completed_at = case when ? = \'completed\' then now() else completed_at end,
                updated_at = now()
          where id = ?',
        [$status->value, $attempts, $status->value, $status->value, $id],
    );
}

it('pins the transition table against the enum, edge by edge', function (): void {
    // The table itself, as a value. A caller that read `allows()` and got something else
    // would be reading a different machine from the one documented.
    expect(ProvisioningStatus::allows())->toBe([
        'pending' => [ProvisioningStatus::Running, ProvisioningStatus::Failed],
        'running' => [ProvisioningStatus::Completed, ProvisioningStatus::Failed, ProvisioningStatus::Pending],
        'completed' => [],
        'failed' => [ProvisioningStatus::Running, ProvisioningStatus::Pending],
    ]);
});

it('answers allowsMoveTo for every ordered pair, so no edge is untested', function (): void {
    // All 16 pairs, derived rather than listed: a fifth status would widen this
    // automatically instead of leaving a quiet gap.
    $expected = ProvisioningStatus::allows();

    foreach (ProvisioningStatus::cases() as $from) {
        foreach (ProvisioningStatus::cases() as $to) {
            expect($from->allowsMoveTo($to))
                ->toBe(in_array($to, $expected[$from->value], true), "{$from->value} -> {$to->value}");
        }
    }
});

it('treats completed as terminal and nothing else', function (): void {
    foreach (ProvisioningStatus::cases() as $status) {
        expect($status->isTerminal())->toBe($status === ProvisioningStatus::Completed, $status->value);
    }

    // `completed` has NO outgoing edge at all, which is the property that makes a
    // provisioned tenant un-reprovisionable: its id is taken and the insert raises 23505.
    expect(ProvisioningStatus::allows()['completed'])->toBe([]);
});

it('refuses to provision a row that is already running, which is the denial branch', function (): void {
    // `ProvisioningTransitionDenied` was never constructed by any test (0/1 covered), so
    // the only production enforcement of the transition table was unexercised.
    $id = stateMachineRequest('denied-'.Str::lower(Str::random(8)));

    forceStatus($id, ProvisioningStatus::Running);

    expect(fn () => app(TenantProvisioning::class)->provision($id))
        ->toThrow(ProvisioningTransitionDenied::class);

    // And nothing of the tenant exists: the refusal happens before any tenant-side work.
    expect(withTenant($id, fn () => DB::selectOne('select count(*) as c from tenants')->c))->toBe(0);
});

it('stops retrying at the attempts cap rather than looping for ever', function (): void {
    // QA H-2: `attempts` was incremented, selected, and read by NOTHING, while three
    // documents claimed "retries bounded by `attempts`" -- R-058's shape (a column selected
    // and never read) in new code. This is the bound existing.
    $id = stateMachineRequest('capped-'.Str::lower(Str::random(8)));

    forceStatus($id, ProvisioningStatus::Failed, TenantProvisioning::MAX_ATTEMPTS);

    expect(fn () => app(TenantProvisioning::class)->provision($id))
        ->toThrow(ProvisioningExhausted::class);

    // The attempt counter was NOT incremented by the refusal: a row at the cap stays at the
    // cap, so the number keeps meaning "how many times the work was attempted".
    expect((int) DB::selectOne('select attempts from tenant_provisionings where id = ?', [$id])->attempts)
        ->toBe(TenantProvisioning::MAX_ATTEMPTS);
});

it('reclaims a stalled row, which is the operator path that did not exist', function (): void {
    // QA H-2's other half: a row left at `running` by a killed process was picked up by
    // nothing, shown as "In progress" for ever, and recoverable only by hand SQL -- which
    // is what §3 row 6's "no SQL run by hand" exists to forbid. The command's own docblock
    // named `--retry-stalled` as the recovery path and the option did not exist.
    $id = stateMachineRequest('stalled-'.Str::lower(Str::random(8)));

    forceStatus($id, ProvisioningStatus::Running, 2);

    $this->artisan('platform:provision-tenants', ['--retry-stalled' => $id])
        ->expectsOutputToContain('reclaimed')
        ->assertSuccessful();

    $row = DB::selectOne('select status, attempts, failure_reason from tenant_provisionings where id = ?', [$id]);

    expect($row->status)->toBe(ProvisioningStatus::Pending->value)
        // RESET, which is what makes it safe for the cap to exist: a reclaim is a
        // deliberate per-row act, so it may clear the counter; a loop may not.
        ->and((int) $row->attempts)->toBe(0)
        ->and($row->failure_reason)->toBeNull();

    // And the ordinary drain now completes it, with no hand SQL anywhere.
    $this->artisan('platform:provision-tenants')->assertSuccessful();

    expect(DB::selectOne('select status from tenant_provisionings where id = ?', [$id])->status)
        ->toBe(ProvisioningStatus::Completed->value);
});

it('reclaims an exhausted row too, and refuses an id that does not exist', function (): void {
    $id = stateMachineRequest('exhausted-'.Str::lower(Str::random(8)));

    forceStatus($id, ProvisioningStatus::Failed, TenantProvisioning::MAX_ATTEMPTS);

    $this->artisan('platform:provision-tenants', ['--retry-stalled' => $id])->assertSuccessful();
    $this->artisan('platform:provision-tenants')->assertSuccessful();

    expect(DB::selectOne('select status from tenant_provisionings where id = ?', [$id])->status)
        ->toBe(ProvisioningStatus::Completed->value);

    // A reclaim of a completed row is a no-op rather than a reset, so the option cannot be
    // used to re-provision a live tenant.
    $this->artisan('platform:provision-tenants', ['--retry-stalled' => $id])
        ->expectsOutputToContain('already provisioned')
        ->assertSuccessful();

    // And an unknown id fails loudly rather than silently doing nothing.
    $this->artisan('platform:provision-tenants', ['--retry-stalled' => (string) Str::uuid7()])
        ->assertFailed();
});

it('writes exactly ONE provisioned audit row even when two runners race', function (): void {
    // QA L-9. `markCompleted()` was `where id = ?` alone, so the loser of a race -- which
    // takes the `tenants_pkey` path and calls it too -- wrote a SECOND
    // `platform.tenant.provisioned` row for one tenant, in an append-only table nothing can
    // prune. The race is simulated by provisioning, then driving the losing path directly.
    $id = stateMachineRequest('race-'.Str::lower(Str::random(8)));

    expect(app(TenantProvisioning::class)->provision($id))->toBeTrue();

    // The loser: the row is already `completed`, so this is the redelivery path.
    expect(app(TenantProvisioning::class)->provision($id))->toBeFalse();

    // AND THE HARDER LOSER, which is the path QA L-9 is actually about: the row is back at
    // `pending` (as a reclaim or a second runner's claim would leave it) while the TENANT
    // already exists, so the tenant insert raises 23505 on `tenants_pkey` and
    // markCompleted() is called a second time. `running` cannot be forced here, because
    // `running -> running` is correctly refused by the transition table -- measured, and
    // the reason this case reads the way it does.
    forceStatus($id, ProvisioningStatus::Pending, 1);

    expect(app(TenantProvisioning::class)->provision($id))->toBeFalse();

    expect((int) DB::selectOne(
        'select count(*) as c from platform_audit_log where subject_id = ? and action = ?',
        [$id, PlatformAudit::TENANT_PROVISIONED],
    )->c)->toBe(1, 'a second provisioned audit row was written for one tenant');

    // One tenant, one owner.
    expect(withTenant($id, fn () => DB::selectOne('select count(*) as c from tenants')->c))->toBe(1)
        ->and(withTenant($id, fn () => DB::selectOne('select count(*) as c from tenant_users')->c))->toBe(1);
});
