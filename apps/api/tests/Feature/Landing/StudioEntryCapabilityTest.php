<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use App\Platform\Http\TenantPanelAccess;

/**
 * The staff app may only offer a door the panel will actually open. `QRPAGER-48` item 3.
 *
 * ================================================================================
 * ⚠⚠ WHY THE CAPABILITY IS PUBLISHED RATHER THAN DERIVED IN TYPESCRIPT
 * ================================================================================
 *
 * Muhammad, 2026-11-13:
 *
 *   "The person signed into the staff app may not have access to the studio at all. Per
 *    User::canAccessPanel(), /studio requires TenantPanelAccess::allows() — Owner or Manager
 *    — so a staff member who is neither would click a button and get a 401. So the button is
 *    shown only to someone who can actually reach the studio, and the check is the same one
 *    the panel itself uses, not a second implementation that can drift from it."
 *
 * The staff app is a separate Next.js process and cannot call `allows()`, so the API
 * publishes the answer on `/v1/auth/me`. `TenantPanelAccess::permits()` is the single
 * predicate: `membership()` asks it per request — which is what `canAccessPanel()` and
 * Filament's gate rest on — and the payload asks it per membership.
 *
 * ⚠ THESE CASES ASSERT THE TWO HALVES AGAINST EACH OTHER, not each against a constant. A
 * test that said "Owner is true" would pass against a hard-coded `true` in the controller;
 * what makes drift impossible is that the published value and the PANEL'S OWN ANSWER are
 * compared for the same person.
 */
it('publishes a studio capability that matches the panel gate, for every role', function (TenantRole $role): void {
    $this->actingForTenant(role: $role);

    $published = $this->getJson('/v1/auth/me')
        ->assertOk()
        ->json('memberships.0.can_access_studio');

    // The panel's own gate, asked directly rather than reimplemented here.
    $gate = TenantPanelAccess::allows();

    expect($published)->toBeBool()
        ->and($published)->toBe($gate);
})->with([
    'owner' => TenantRole::Owner,
    'manager' => TenantRole::Manager,
    'staff' => TenantRole::Staff,
]);

it('opens the studio for exactly the roles it advertises', function (TenantRole $role): void {
    $this->actingForTenant(role: $role);

    $published = (bool) $this->getJson('/v1/auth/me')->assertOk()->json('memberships.0.can_access_studio');

    $response = $this->get('/studio');

    if ($published) {
        // Advertised: the panel must actually answer. Anything but a refusal is a door that
        // opened -- Filament may redirect to its own first page.
        expect($response->status())->not->toBe(401)
            ->and($response->status())->not->toBe(403);

        return;
    }

    // Not advertised: the panel must refuse. This is the case that would have caught a button
    // shown to a staff member -- a click that lands on 401.
    expect($response->status())->toBeIn([401, 403]);
})->with([
    'owner' => TenantRole::Owner,
    'manager' => TenantRole::Manager,
    'staff' => TenantRole::Staff,
]);
