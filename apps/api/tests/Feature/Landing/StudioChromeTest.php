<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;

/**
 * The studio's chrome, one case per region. `QRPAGER-48` item 2.
 *
 * ⚠⚠ THE INVARIANT NAMES THE REGIONS AND SAYS **NONE MAY BE MISSING**, so these are written
 * one-per-region rather than as a single sweep: a sweep that lost a region would still pass
 * with the others, and *"no region missing"* is precisely the claim being made.
 *
 * Muhammad reversed his own earlier ruling to get here — `QRPAGER-47` called the chrome
 * CONTEXT, and 2026-11-13: *"I previously called it context; I was wrong, and it is now part
 * of the deliverable."*
 */
beforeEach(function (): void {
    $this->actingForTenant(role: TenantRole::Owner);
});

it('names the RESTAURANT in the topbar, not just the product', function (): void {
    // §12 forbids an unexplained identifier. Before this the topbar carried the product's
    // name and nothing about whose page was being edited -- on a shared device the
    // restaurant is the one fact a person glances up to confirm.
    $this->get('/studio/theme')
        ->assertOk()
        ->assertSee('data-topbar-restaurant="true"', false);
});

it('says which screen this is, so the restaurant is not read as the product', function (): void {
    $this->get('/studio/theme')
        ->assertOk()
        ->assertSee('data-topbar-chip="true"', false)
        ->assertSee(__('studio.chrome.studio'), false);
});

it('shows the signed-in person by NAME, readable without a click', function (): void {
    // The artboard drew a bare initial, and Muhammad ruled the artboard itself the defect:
    // "§12 forbids an unexplained identifier, and a single letter is exactly that."
    $this->get('/studio/theme')
        ->assertOk()
        ->assertSee('data-topbar-person="true"', false)
        // The FIRST name, which is what the topbar has room for.
        ->assertSee('Acting', false);
});

it('carries the whole name and the address in the menu itself', function (): void {
    // The address is what distinguishes two people with one first name -- the case the
    // abbreviation in the topbar cannot serve.
    $this->get('/studio/theme')
        ->assertOk()
        ->assertSee('data-usermenu-identity="true"', false)
        ->assertSee('Acting Staff', false);
});

it('offers a way out, which is the only other thing the menu carries', function (): void {
    // Filament's own sign-out lives in that menu; this asserts the menu exists to hold it
    // rather than re-testing Filament.
    $this->get('/studio/theme')
        ->assertOk()
        ->assertSee('data-back-to-staff="true"', false);
});

it('renders every region on EVERY tab, not only the theme one', function (string $path): void {
    // The chrome is the frame around the tabs. A region that appeared on one page and not
    // another would be the artboard's own complaint -- it carries the whole tab "so the
    // theme tab is not designed in isolation from the page tab around it".
    $response = $this->get($path)->assertOk();

    foreach ([
        'data-topbar-restaurant="true"',
        'data-topbar-chip="true"',
        'data-topbar-person="true"',
        'data-usermenu-identity="true"',
    ] as $marker) {
        $response->assertSee($marker, false);
    }
})->with(['/studio', '/studio/theme', '/studio/media', '/studio/domains']);
