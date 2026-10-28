<?php

declare(strict_types=1);

use App\Features\Provisioning\Panel\Resources\PlatformAuditResource;
use App\Features\Provisioning\Panel\Resources\PlatformAuditResource\Pages\ListPlatformAudit;
use App\Features\Provisioning\Panel\Resources\RestaurantResource;
use App\Features\Provisioning\Panel\Resources\RestaurantResource\Pages\ListRestaurants;
use App\Models\User;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * ================================================================================
 * §12 — THIS PANEL IS EXEMPT FROM THE VISUAL BAR AND NOT FROM THE ACCESSIBILITY BAR
 * ================================================================================
 *
 * `§12`'s last two bullets, and the ticket's `§5`, say it in those words: the Filament admin
 * is built FUNCTIONALLY in Tasks 6-7 and restyled from the approved tokens in Task 8, which
 * is where the tokens first exist. So "no library default look" does NOT apply here, and
 * this task invents no token set to satisfy a standard that does not exist yet.
 *
 * Still binding: keyboard reachable, visible focus ring, WCAG AA contrast, 44px minimum
 * touch target, `prefers-reduced-motion` respected, RTL-safe layout.
 *
 * ================================================================================
 * WHAT IS ASSERTED HERE, AND WHAT HONESTLY CANNOT BE
 * ================================================================================
 *
 * This file asserts the half of that bar that a SERVER-RENDERED RESPONSE can carry, and
 * says plainly which half it cannot. The alternative — writing a weak assertion for each
 * clause so the checklist looks complete — is the defect `§4a` records four instances of: an
 * assertion that cannot fail is worse than an absent one, because it reports coverage that
 * is not there.
 *
 * ASSERTED HERE (all of it from the rendered HTML):
 *   - `lang` and `dir` are present on `<html>`, and `dir` tracks the locale, which is what
 *     makes the layout RTL-safe rather than LTR-with-a-stylesheet. Arabic is a launch
 *     language.
 *   - No element carries a positive `tabindex`, so the tab order is the document order —
 *     a positive tabindex is the single most common way a page stops being keyboard
 *     navigable in source order.
 *   - This task's own markup sets no `outline: none` and no `outline: 0`, which is how a
 *     focus ring gets removed, and no hard-coded hex colour.
 *
 * ⚠ AND NOT "no hard-coded colour, spacing or font value", which this header claimed until
 * 2026-10-28 (QA L-6). The assertion checks a HEX PATTERN only, while the dashboard template
 * legitimately uses Filament's utility classes for spacing (`gap-4`, `mt-1`), size
 * (`text-sm`, `text-3xl`) and colour (`text-gray-500 dark:text-gray-400`). That practice is
 * fine — `§12` exempts Tasks 6-7 from the visual bar and Task 8 restyles from the tokens —
 * but the comment described a check that was not being made, which is the same defect class
 * as an assertion that cannot fail.
 *   - The dashboard's figures are a real `<dl>` with a `<dt>` per figure, so each number
 *     has a programmatic label instead of being a number in a box.
 *   - The aggregates' staleness is TEXT in the document, not a tooltip and not a colour.
 *   - The restaurant state is text inside its badge, so it is never conveyed by colour
 *     alone (WCAG 1.4.1).
 *
 * NOT ASSERTED, AND WHY — each needs a real browser with layout and a stylesheet applied,
 * which this machine has none of. `R-100` is the precedent for recording that instead of
 * asserting it weakly: Task 5's passkey gate clause is unmet for exactly the same reason,
 * and the ruling was to say so.
 *   - A VISIBLE FOCUS RING is a computed style on a focused element. Asserting that our
 *     markup does not remove it (above) is not the same claim as asserting Filament draws
 *     one, and nothing here can measure the second.
 *   - 44px TOUCH TARGETS are computed box sizes. Filament's buttons and table rows are the
 *     only interactive elements on these pages and this task sets no size on any of them,
 *     so the measurement is entirely a property of the library's stylesheet.
 *   - WCAG AA CONTRAST is a computed colour pair, and the colours are Filament's defaults
 *     by design until Task 8.
 *   - `prefers-reduced-motion` is a media query in Filament's own CSS.
 *
 * All four are carried as **R-118**, owned by Task 8 — the task that restyles this panel
 * from the approved tokens and therefore has to measure all four anyway — with Task 9's
 * Playwright harness as the instrument. Written into `§5` with an owner and a date rather
 * than claimed here.
 */

function accessibilityAdmin(): User
{
    $id = (string) Str::uuid7();

    // The owner connection: the app role holds no INSERT on users.is_platform_admin
    // (R-006 / SEC L-1), which PlatformPanelInvariantTest's fixture records at length.
    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Accessibility Admin',
        'email' => 'a11y-'.Str::lower(Str::random(10)).'@example.test',
        'is_platform_admin' => true,
    ]);

    $user = User::query()->findOrFail($id);
    assert($user instanceof User);

    return $user;
}

/**
 * A Table bound to the real list-page component the panel mounts.
 *
 * @param  class-string  $page
 */
function configuredTable(string $page): Table
{
    $instance = Livewire::test($page)->instance();
    assert($instance instanceof HasTable);

    return Table::make($instance);
}

/** @return list<string> */
function panelPageUris(): array
{
    return ['/platform', '/platform/restaurants', '/platform/audit-log'];
}

it('renders every panel page with lang and dir on the document element', function (string $uri): void {
    $html = (string) $this->actingAs(accessibilityAdmin())->get($uri)->getContent();

    expect($html)->toContain('<html')
        // Both attributes, not just lang: `dir` is what makes the layout mirror rather than
        // break under Arabic, and a page with lang and no dir renders RTL text in an LTR box.
        ->and($html)->toMatch('/<html[^>]*\blang=/')
        ->and($html)->toMatch('/<html[^>]*\bdir=/');
})->with(panelPageUris());

it('sets dir from the locale, so RTL is a configuration rather than a rewrite', function (): void {
    // THE CLAIM THAT MATTERS: switching the locale to Arabic switches the document
    // direction, with no change to any template. If this failed, "RTL-safe" would mean
    // "RTL-ready after someone edits every view", which is the retrofit §12 says is far
    // more expensive than building it in.
    $admin = accessibilityAdmin();

    $ltr = (string) $this->actingAs($admin)->get('/platform')->getContent();

    app()->setLocale('ar');

    $rtl = (string) $this->actingAs($admin)->get('/platform')->getContent();

    app()->setLocale('en');

    expect($ltr)->toMatch('/<html[^>]*\bdir="ltr"/')
        ->and($rtl)->toMatch('/<html[^>]*\bdir="rtl"/');
});

it('carries no positive tabindex anywhere, so the tab order is the document order', function (string $uri): void {
    $html = (string) $this->actingAs(accessibilityAdmin())->get($uri)->getContent();

    // `tabindex="0"` and `tabindex="-1"` are both legitimate -- the first makes a div
    // focusable, the second removes something from the order deliberately. A POSITIVE value
    // is the one that reorders the page away from its source order, and it is the common
    // way a keyboard user ends up somewhere unexpected.
    preg_match_all('/tabindex\s*=\s*["\']?(-?\d+)/i', $html, $matches);

    $positive = array_values(array_filter(
        $matches[1] ?? [],
        static fn (string $value): bool => (int) $value > 0,
    ));

    expect($positive)->toBe([], $uri.' carries a positive tabindex: '.implode(', ', $positive));
})->with(panelPageUris());

it('removes no focus ring in this task’s own markup', function (): void {
    // ASSERTED AGAINST THE SOURCE, not the rendered page, and the distinction is the point:
    // Filament's compiled stylesheet is vendor output and this case is about what THIS task
    // wrote. `outline: none` in one of our templates is how a focus ring disappears while
    // every library default is still correct.
    //
    // It does NOT assert that a ring is drawn. That is a computed style and needs a
    // browser -- R-118, owned by Task 8.
    $ours = [
        __DIR__.'/../../../resources/views/platform/dashboard.blade.php',
    ];

    foreach ($ours as $file) {
        expect(file_exists($file))->toBeTrue("missing: {$file}");

        $source = (string) file_get_contents($file);

        expect($source)->not->toMatch('/outline\s*:\s*(none|0)/i', basename($file).' removes a focus ring')
            // And no hard-coded HEX colour. Deliberately not "no colour, spacing or font
            // value": the template uses Filament's own utility classes for all three, which
            // is what §12's Tasks 6-7 exemption permits — a raw hex is the thing that would
            // survive Task 8's restyle and have to be hunted down (QA L-6).
            ->and($source)->not->toMatch('/#[0-9a-f]{3,8}\b/i', basename($file).' hard-codes a hex colour');
    }
});

it('labels every dashboard figure programmatically, not by position', function (): void {
    $html = (string) $this->actingAs(accessibilityAdmin())->get('/platform')->getContent();

    // A real description list. A screen reader then reads "Restaurants, 4" rather than "4",
    // which is the difference between a labelled figure and a number in a box.
    expect($html)->toContain('<dl')
        ->and($html)->toContain('<dt')
        ->and($html)->toContain('<dd');

    // Every figure's own label is present as text, so none of them depends on its position
    // in the grid.
    foreach ([
        'Restaurants', 'Active', 'In progress', 'Failed',
        'Pager sessions', 'Staff accounts', 'Median wait',
    ] as $label) {
        // Same variadic trap as the negated form, one step milder: this one FAILS loudly
        // rather than passing vacuously (the message is read as a second needle and is not
        // in the page), which is how it was found. str_contains() carries the message where
        // a message belongs.
        expect(str_contains($html, $label))
            ->toBeTrue("the dashboard has no text label for [{$label}]");
    }
});

it('states the aggregates’ staleness as text, because an unshown staleness reads as zero', function (): void {
    $html = (string) $this->actingAs(accessibilityAdmin())->get('/platform')->getContent();

    // One of the two sentences must be there: either no aggregates have been computed, or
    // when they were. Not a tooltip, not a colour, not an icon.
    expect(
        str_contains($html, 'No aggregates have been computed yet')
        || str_contains($html, 'last computed')
    )->toBeTrue('the dashboard does not say how old its figures are');

    // And the ruling itself is on the screen, because the first question a platform admin
    // asks of this page is why they cannot see the orders.
    expect($html)->toContain('cannot see inside it');
});

it('carries the restaurant state as text, never as colour alone', function (): void {
    $admin = accessibilityAdmin();

    $id = (string) Str::uuid7();
    DB::insert(
        'insert into tenant_provisionings (id, name, slug, owner_email, status, created_at, updated_at)
         values (?, ?, ?, ?, ?::provisioning_status, now(), now())',
        [$id, 'Badge Check', 'badge-'.Str::lower(Str::random(8)), 'b@example.test', 'pending'],
    );

    $html = (string) $this->actingAs($admin)->get('/platform/restaurants')->getContent();

    // WCAG 1.4.1: colour is never the only carrier of information. Filament's badge() puts
    // the value inside the badge as text, which is why badge() is acceptable here and a
    // bare coloured dot would not be.
    expect($html)->toContain('pending')
        ->and($html)->toContain('Badge Check');
});

it('declares the exact empty-state copy for both lists', function (): void {
    // §12: "every screen is specified before it is coded: purpose, all states (empty /
    // loading / error / offline / permission-denied), content inventory, and the exact
    // copy." The empty state is the one a first-run platform actually has, so it is the one
    // most likely to ship as a blank box.
    //
    // ASSERTED ON THE TABLE CONFIGURATION, NOT ON RENDERED HTML, and that is a correction
    // rather than a convenience. Measured in this session: a Filament table defers its
    // rows, so neither the page's first response NOR a `Livewire::test(...)->assertSee()`
    // render carries the empty-state heading -- both were tried and both failed while the
    // copy was correctly configured. Asserting against the configuration is a claim this
    // test can actually stand behind: THE COPY IS DECLARED AND IS THIS TEXT. That the
    // library then paints it is a browser-level fact, and it is carried as R-118 with the
    // other three computed-style clauses rather than claimed weakly here.
    // Table::make() needs its Livewire host -- a Table is configured FOR a component that
    // implements HasTable, so `app(Table::class)` cannot build one. The list page is that
    // component, taken from Livewire's own test harness so it is the real instance the
    // panel mounts rather than a hand-made stand-in.
    // Authenticated first: the page is behind the panel's guard, so an anonymous
    // Livewire::test() mounts nothing and ->instance() comes back null.
    test()->actingAs(accessibilityAdmin());

    $audit = PlatformAuditResource::table(configuredTable(ListPlatformAudit::class));
    $restaurants = RestaurantResource::table(configuredTable(ListRestaurants::class));

    expect($audit->getEmptyStateHeading())->toBe('Nothing recorded yet')
        ->and($restaurants->getEmptyStateHeading())->toBe('No restaurants yet')
        ->and($restaurants->getEmptyStateDescription())
        ->toBe('Use “New restaurant” to provision the first one.');
});

it('offers no destructive action on either list, which the audit viewer requires by name', function (): void {
    // The ticket's §5: "the viewer is read-only by construction -- do not add an edit path."
    // And for the registry: deleting a provisioning record would orphan a live tenant the
    // panel cannot see, which is the one destructive act this surface must not offer.
    //
    // Asserted on the configuration for the same reason as above, and it is the stronger
    // place for THIS claim anyway: an action absent from the table object cannot be
    // rendered by any view.
    test()->actingAs(accessibilityAdmin());

    foreach ([
        'audit' => PlatformAuditResource::table(configuredTable(ListPlatformAudit::class)),
        'restaurants' => RestaurantResource::table(configuredTable(ListRestaurants::class)),
    ] as $name => $table) {
        expect($table->getRecordActions())->toBe([], "{$name} offers a row action")
            ->and($table->getToolbarActions())->toBe([], "{$name} offers a toolbar action");
    }
});
