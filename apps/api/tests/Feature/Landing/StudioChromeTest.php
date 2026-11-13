<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use App\Features\Landing\LandingPages;
use App\Features\Landing\Panel\Pages\ThemePage;
use Illuminate\Support\Facades\DB;

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

/**
 * ⚠⚠ THE PUBLISH ACTION SAVES THIS TAB FIRST, AND THAT ORDER IS THE WHOLE CASE.
 *
 * `LandingBuilderPage::publish()` already records why: *"a publish that used the stored draft
 * while the form held newer values would publish something the tenant is not looking at."*
 * The theme tab holds exactly such values, so publishing from here without saving would put
 * the OLD theme on the live page while the screen showed the new one — a defect an owner
 * would only discover by looking at their own published page.
 */
it('publishes from the theme tab, and publishes what the FORM holds', function (): void {
    $tenantId = $this->actingForTenant(role: TenantRole::Owner);
    $pages = app(LandingPages::class);

    // A publishable page to start from, with a known arrangement.
    $pages->saveDraft($tenantId, [
        'template' => 'rail',
        'blocks' => [['type' => 'hero', 'fields' => ['heading' => 'Before']]],
    ]);
    $pages->publish($tenantId);

    expect($pages->state($tenantId)['has_unpublished_changes'])->toBeFalse();

    // Now change the arrangement IN THE FORM and publish without saving first. If the action
    // published the stored draft, the live page would keep `rail`.
    Livewire::test(ThemePage::class)
        ->set('data.template', 'ember')
        ->callAction('publish');

    // The PUBLISHED document's own bytes, read back rather than inferred from the draft:
    // the whole claim is that what reached the live page is what the form held.
    $stored = (string) withTenant(
        $tenantId,
        static fn (): string => (string) DB::connection('pgsql_owner')
            ->table('landing_pages')->where('tenant_id', $tenantId)->value('published_document'),
        'pgsql_owner',
    );

    /** @var array{template?: string} $published */
    $published = json_decode($stored, true);

    expect($published['template'] ?? null)->toBe('ember')
        ->and($pages->state($tenantId)['has_unpublished_changes'])->toBeFalse();
});
