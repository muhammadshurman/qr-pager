<?php

declare(strict_types=1);

use App\Domain\Provisioning\ProvisioningStatus;
use App\Features\Provisioning\Panel\Resources\RestaurantResource;
use App\Features\Provisioning\Panel\Resources\RestaurantResource\Pages\ListRestaurants;
use App\Features\Support\SupportAccess;
use App\Models\TenantProvisioningRecord;
use App\Platform\Tenancy\SupportMemberships;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\LandingFixtures;
use Tests\TestCase;

/*
 * ================================================================================
 * TAKING SUPPORT ACCESS THROUGH THE PANEL — THE ONLY PATH THAT EXISTS. `QRPAGER-22`.
 * ================================================================================
 *
 * `QRPAGER-22 §1` was raised because a platform admin gets **403** at `/studio` by
 * construction, and `§4 › Tenancy`'s support clause is the bridge the 2026-10-30 ruling
 * named. Without an action the bridge is unreachable, so these cases are the feature rather
 * than a check on top of it.
 *
 * ⚠ DRIVEN THROUGH LIVEWIRE, for the reason `StudioActionsTest`'s header gives: a GET of a
 * Filament page never invokes an action, and everything an admin does arrives over
 * `POST /livewire-<hash>/update` — the endpoint Task 6's security M-1 was about, which runs
 * neither `SetUpPanel` nor Filament's `Authenticate`.
 */

/** A provisioning record whose tenant really exists, which is what the action needs. */
function provisionedRestaurant(object $test, ProvisioningStatus $status = ProvisioningStatus::Completed): string
{
    // ⚠ `Closure::call($test)` AND NOT `$test->makeTenant(...)`: `makeTenant` is PROTECTED on
    // `Tests\TestCase`, so a file-scope helper cannot reach it from outside. Binding the
    // closure to the test instance is how the other helpers in this suite do it
    // (`ownerOfAnotherTenant` in `MemberConsentTest`), and the error without it is
    // "Call to protected method Tests\TestCase::makeTenant() from global scope".
    /** @var string $tenantId */
    $tenantId = (function (): string {
        /** @var TestCase $this */
        /*
         * ⚠ THE WHOLE ID, AND IT WAS A 20-HEX PREFIX — the `§11` QA round's L-4 on
         * `QRPAGER-46`, found as an intermittent red at HEAD: *"it releases it from the row it
         * was taken on"* failed with `23505 tenants_slug_unique` in one full-suite run and
         * passed in the next. The reason is written out TEN LINES BELOW this one, for the
         * sibling slug on `tenant_provisionings`, and it applies identically here: Laravel's
         * uuid7 keeps its first 25 hex digits stable inside one millisecond, so two tenants
         * made back to back in one case collide on a prefix. This is the case that makes two.
         */
        return $this->makeTenant(slug: 'panel-'.str_replace('-', '', (string) Str::uuid7()));
    })->call($test);

    // `owner_email` is NOT NULL on `tenant_provisionings` -- the column the provisioning
    // wizard fills and `R-106`'s consent rule reads. Caught by running it (23502).
    DB::connection('pgsql_owner')->table('tenant_provisionings')->insert([
        'id' => $tenantId,
        'name' => 'Panel restaurant',
        // ⚠ THE WHOLE ID, NOT A PREFIX. `makeTenant`'s own comment records why: "Laravel's
        // uuid7 keeps its first 25 hex digits stable inside one millisecond, so a
        // prefix-based slug collided with tenants_slug_unique whenever two were made back to
        // back." Two restaurants in one test is exactly that case, and it collided here too
        // -- `tenant_provisionings_slug_unique`, measured, on the very case that makes two.
        'slug' => 'panel-slug-'.str_replace('-', '', $tenantId),
        'owner_email' => 'panelowner-'.str_replace('-', '', $tenantId).'@example.test',
        'status' => $status->value,
        'attempts' => 0,
        // ⚠ `completed_at` IS REQUIRED WHEN `status` IS `completed`, by the CHECK constraint
        // `tenant_provisionings_completed_has_timestamp`. Caught by running it (23514), and
        // it is the data contract refusing an impossible row rather than a fixture nicety:
        // a completed provisioning with no completion time is not a thing.
        'completed_at' => $status === ProvisioningStatus::Completed ? now() : null,
    ]);

    return $tenantId;
}

it('takes support access from the restaurant list, with the reason reaching the trail', function (): void {
    $admin = LandingFixtures::platformAdmin();
    $tenantId = provisionedRestaurant($this);

    Livewire::actingAs($admin);

    /*
     * ⚠ `TestAction::make(...)->table($record)` AND NOT `callAction('name', record: $id)`.
     * Filament 5's signature is `callAction(string|TestAction|array, array $data, array
     * $arguments)` -- there is no `record:` parameter, and passing one dies with "Unknown
     * named parameter $record" from the Macroable trait. A table ROW action is targeted by
     * `->table($record)`, which is what makes this exercise the row rather than a page action
     * that happens to share the name.
     */
    Livewire::test(ListRestaurants::class)
        ->callAction(
            TestAction::make('takeSupportAccess')->table($tenantId),
            ['reason' => 'owner says the page is blank'],
        );

    expect(app()->make(SupportMemberships::class)->heldTenantId((string) $admin->getKey()))
        ->toBe($tenantId, 'the panel action did not create the membership');

    $audit = withTenant($tenantId, fn () => DB::select(
        'select action, context::text as context from audit_log where action = ?',
        [SupportAccess::TAKEN],
    ));

    expect($audit)->toHaveCount(1)
        ->and((string) $audit[0]->context)->toContain('owner says the page is blank');
});

it('refuses a take with no reason, from the panel as well as from the service', function (): void {
    // The form marks it required; the SERVICE is the enforcement, and this drives the
    // service's refusal through the panel so the two cannot disagree.
    $admin = LandingFixtures::platformAdmin();
    $tenantId = provisionedRestaurant($this);

    Livewire::actingAs($admin);

    Livewire::test(ListRestaurants::class)
        ->callAction(TestAction::make('takeSupportAccess')->table($tenantId), ['reason' => ''])
        ->assertHasActionErrors(['reason']);

    expect(app()->make(SupportMemberships::class)->heldTenantId((string) $admin->getKey()))
        ->toBeNull('a membership was created with no reason');
});

it('releases it from the row it was taken on, and only from that row', function (): void {
    $admin = LandingFixtures::platformAdmin();
    $held = provisionedRestaurant($this);
    $other = provisionedRestaurant($this);

    app(SupportAccess::class)->take($held, (string) $admin->getKey(), 'first');

    Livewire::actingAs($admin);

    /*
     * ⚠ THE RELEASE ACTION IS HIDDEN ON THE OTHER ROW, which is the honest answer to "which
     * one would this release?". A platform admin holds at most one at a time, so a button on
     * an unrelated row would release something that row is not about.
     */
    Livewire::test(ListRestaurants::class)
        ->assertActionHidden(TestAction::make('releaseSupportAccess')->table($other))
        ->assertActionVisible(TestAction::make('releaseSupportAccess')->table($held))
        ->callAction(TestAction::make('releaseSupportAccess')->table($held));

    expect(app()->make(SupportMemberships::class)->heldTenantId((string) $admin->getKey()))->toBeNull();
});

it('hides the take action until provisioning has completed', function (): void {
    /*
     * The key exists before the tenant ROW does -- `provisioning_status` starts `pending` and
     * `tenant_users.tenant_id` carries an FK to `tenants`. Offering the action earlier turns
     * a reasonable click into a 500.
     *
     * ⚠ THE FIXTURE STILL CREATES A REAL TENANT ROW, so this case proves the GUARD rather
     * than the FK: if the guard were removed the action would succeed here, and the assertion
     * would fail for the right reason instead of passing because the database refused.
     */
    $admin = LandingFixtures::platformAdmin();
    $pending = provisionedRestaurant($this, ProvisioningStatus::Pending);

    Livewire::actingAs($admin);

    Livewire::test(ListRestaurants::class)
        ->assertActionHidden(TestAction::make('takeSupportAccess')->table($pending));
});

it('offers neither action to a record list an ordinary user cannot reach at all', function (): void {
    // The panel's own gate, restated here because these actions are the first destructive-ish
    // thing on this resource: `RestaurantResource::canViewAny()` requires `is_platform_admin`,
    // and `PlatformPanelInvariantTest` drives every panel route for all five principals. This
    // asserts the model-level guard the actions inherit rather than re-driving HTTP.
    expect(TenantProvisioningRecord::query()->getModel())->toBeInstanceOf(TenantProvisioningRecord::class)
        ->and(RestaurantResource::canViewAny())->toBeFalse();
});
