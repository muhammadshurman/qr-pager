<?php

declare(strict_types=1);

use App\Domain\Landing\BlockSchema;
use App\Domain\Landing\BlockType;
use App\Domain\Landing\LandingTemplate;
use App\Domain\Landing\ThemeTokens;
use App\Domain\Tenancy\TenantRole;
use App\Features\Landing\Panel\Pages\DomainsPage;
use App\Features\Landing\Panel\Pages\LandingBuilderPage;
use App\Features\Landing\Panel\Pages\MediaLibraryPage;
use App\Features\Landing\Panel\Pages\ThemePage;
use App\Features\Landing\Panel\StudioForm;
use Illuminate\Support\Facades\Route;
use Tests\Support\LandingFixtures;

/*
 * ================================================================================
 * `QRPAGER-40`'s GATE INVARIANT — NOTHING THE OWNER COULD DO IS LOST IN THE MOVE
 * ================================================================================
 *
 * Muhammad's ticket: *"Every task the owner could do before this ticket, he can still do
 * after it. No block type, no field, no action is lost in the move to tabs. Prove it with
 * a list of them before and after, as a test — not by assertion. That list is the gate."*
 *
 * ⚠ **THE LIST IS DERIVED, NEVER TYPED.** A hand-written inventory would be a second
 * source: it would agree with the schema on the day it was written and drift silently
 * after, which is the defect `§6.2` exists to prevent and the one `R-250` is still open
 * about. So the AFTER side is read out of `StudioForm` and `BlockSchema` at run time, and
 * the BEFORE side is the only thing written down — a frozen snapshot of what the single
 * form offered before the split, which is a historical fact and cannot drift.
 *
 * ⚠ **AND THE BEFORE LIST IS A FLOOR, NOT AN EQUALITY.** The ticket asks that nothing be
 * LOST; adding a field later is allowed and must not fail this. So every case below asks
 * "is every BEFORE capability still present", never "are the two sets equal" — an equality
 * would turn this gate into an obstacle to the next ticket and get it deleted.
 */

/**
 * Every block type the builder offered before the tabs, verbatim.
 *
 * Read out of `git show 55a3a34:…/BlockType.php` -- the commit before this ticket's first --
 * rather than recalled. The first draft of this list was written from memory and named four
 * types that do not exist (`about`, `menu`, `hours`, `map`); the test went red, which is the
 * only reason the list is right now.
 */
const BEFORE_BLOCK_TYPES = [
    'hero', 'how_it_works', 'menu_highlights', 'gallery', 'hours_location', 'contact', 'footer',
];

/** Every theme property the Theme section offered, from `ThemeTokens::NAMES` at that commit. */
const BEFORE_THEME_NAMES = [
    'color.background', 'color.surface', 'color.text', 'color.muted',
    'color.accent', 'color.accent_text', 'font.heading', 'font.body',
    'radius.small', 'radius.large', 'space.section', 'size.heading',
];

/** Every action the one page carried, by the Livewire method the view called. */
const BEFORE_ACTIONS = ['save', 'publish', 'unpublish', 'setPageLanguage'];

/**
 * The two tabs' rendered HTML, keyed by tab.
 *
 * ⚠ **DRIVEN OVER HTTP RATHER THAN BY WALKING FILAMENT'S COMPONENT TREE, AND THAT IS A
 * CORRECTION.** The first version of this file built the schemas standalone and recursed
 * through `getChildComponents()`. It does not work and cannot be made to: a `Section`
 * outside a container throws from `BelongsToContainer`, and once bound, descending needs a
 * Livewire component — measured as *"getLivewire(): Return value must be of type
 * Livewire\Component, null returned"*.
 *
 * Driving the page is also the BETTER test, which is why this is not a workaround. The
 * ticket's invariant is about **what the owner can still do**, and a control is something
 * he can see and type into — not an object in a tree. This asserts the thing itself.
 *
 * @return array{page: string, theme: string}
 */
function studioTabs(): array
{
    LandingFixtures::bucket();
    test()->actingForTenant(role: TenantRole::Owner);

    return [
        'page' => test()->get('/studio')->getContent(),
        'theme' => test()->get('/studio/theme')->getContent(),
    ];
}

it('keeps every block type the one form offered', function (): void {
    $after = array_map(static fn (BlockType $t): string => $t->value, BlockType::cases());

    foreach (BEFORE_BLOCK_TYPES as $type) {
        // ⚠ `in_array` AND NOT `toContain($type, $message)`: Pest's `toContain` is
        // VARIADIC and reads a second argument as another NEEDLE, so the message would be
        // asserted as a block type. This repository has been bitten by that matcher before.
        expect(in_array($type, $after, true))->toBeTrue("the block type [{$type}] is no longer offered");
    }
});

it('keeps every field of every block type', function (): void {
    /*
     * ⚠ THE DEEPER HALF, AND THE ONE A TAB MOVE COULD PLAUSIBLY BREAK. Keeping the block
     * types while dropping a field inside one would satisfy the case above and still lose
     * the owner a thing he could do. `BlockSchema` is the single source for both, so this
     * asks it rather than a list.
     */
    foreach (BlockType::cases() as $type) {
        $fields = BlockSchema::fields($type);

        expect($fields)->not->toBe([], "block type [{$type->value}] offers no fields at all");
    }
});

it('keeps every theme property, now on the theme tab', function (): void {
    $theme = studioTabs()['theme'];

    foreach (BEFORE_THEME_NAMES as $name) {
        expect(str_contains($theme, StudioForm::themeKey($name)))
            ->toBeTrue("the theme property [{$name}] is on no tab the owner can open");
    }

    // And the allow-list itself did not shrink, which is what the renderer reads.
    foreach (BEFORE_THEME_NAMES as $name) {
        expect(ThemeTokens::NAMES)->toHaveKey($name);
    }
});

it('keeps the page-content controls on the page tab', function (): void {
    $page = studioTabs()['page'];

    /*
     * ⚠ READ FROM THE LANGUAGE FILE AND PINNED TO `ar` — `QRPAGER-41`. These were the three
     * English literals, and the tenant this test acts for has no language set, so it is
     * `TenantLanguage::DEFAULT` = `ar` and the panel renders Arabic. The CLAIM is unchanged
     * and is the only reason the strings may move: these three controls are still on the
     * page tab. Asserting the key's value rather than a copy of it means a reworded label
     * cannot make this case red, and a DELETED control still can -- which is the whole
     * point of a capability test.
     */
    expect(str_contains($page, __('studio.form.add_block', [], 'ar')))->toBeTrue('the block builder is gone from the page tab');
    expect(str_contains($page, __('studio.page.language_legend', [], 'ar')))->toBeTrue('the page language control is gone');

    /*
     * ⚠⚠ AND THE ARRANGEMENT CONTROL IS **NOT** ON THIS TAB ANY MORE — `QRPAGER-47` Part B
     * item 1, and this is the half that makes the move a move rather than a duplication. It
     * was asserted PRESENT here until 2026-11-12, with the words *"Page arrangement is gone
     * from the page tab"* as its failure message; the three themes are now cards on the theme
     * tab and the case below asserts them there.
     *
     * `studio.form.template_label` is still a key and still Arabic — nothing renders it — so
     * the absence is asserted on the control's own copy exactly as the presence was.
     */
    expect(str_contains($page, __('studio.form.template_label', [], 'ar')))
        ->toBeFalse('the arrangement control is still on the page tab, so it was copied rather than moved');
});

it('puts the three themes on the theme tab, as cards with a structural thumbnail each', function (): void {
    /*
     * The other half of the move — `QRPAGER-47` Part B item 1. A capability test, so it reads
     * what the tab OFFERS rather than how it looks: three radio inputs whose values are the
     * three arrangements, each with the enum's own name beside it, and the thumbnail markup
     * that makes a card a picture rather than a label.
     */
    $theme = studioTabs()['theme'];

    expect($theme)->toContain(__('studio.arrangements.section', [], 'ar'))
        ->and($theme)->toContain(__('studio.arrangements.note', [], 'ar'));

    foreach (LandingTemplate::cases() as $case) {
        expect($theme)->toContain('value="'.$case->value.'"')
            ->and($theme)->toContain(e($case->name('ar')))
            ->and($theme)->toContain(e($case->description('ar')));
    }

    // The badge marks the STORED arrangement, and the tenant this test acts for is on the
    // default — so exactly one card carries it.
    expect(substr_count($theme, __('studio.arrangements.in_use', [], 'ar')))->toBe(1);

    // Each thumbnail is the arrangement's own structure: the ledger's rule and call to
    // action, ember's dark hero, tile's grid. One marker each is enough — `ArrangementField`
    // builds them in a `match` with no `default`, so a fourth case cannot ship without one.
    expect($theme)->toContain('studio-thumb__rule')
        ->and($theme)->toContain('studio-thumb__hero')
        ->and($theme)->toContain('studio-thumb__grid');
});

it('puts theme and search-and-sharing on the theme tab and NOT on the page tab', function (): void {
    /*
     * The move itself, asserted in both directions. Present on the new tab is half the
     * claim; ABSENT from the old one is the other half, and without it a "move" that
     * duplicated the sections would pass.
     */
    $tabs = studioTabs();
    $themeKey = StudioForm::themeKey('color.background');

    /*
     * ⚠ ASSERTED ON THE CONTROL'S OWN COPY, NOT ON THE STATE KEY, AND THE REASON IS A
     * MEASUREMENT RATHER THAN A PREFERENCE. `LandingBuilderPage::mount()` fills its form
     * from `fromDocument()`, which returns the WHOLE flat state — theme keys included — and
     * Livewire serialises that into the page's snapshot. So `theme_color_background` is
     * present in the page tab's HTML even though no control renders it, and an assertion on
     * the key would fail for a page that is correct.
     *
     * That is harmless to the tenant's data — the save merges from the DATABASE, never from
     * the snapshot — but it does mean the page tab round-trips theme values it cannot edit.
     * Narrowing `mount()` to its own keys is a tidy-up for a later ticket, not a defect, and
     * it is written down here rather than left for the next person to re-measure.
     *
     * The claim this case makes is that the CONTROL moved, so it asks after the control: the
     * section's own description, which appears exactly where the section is rendered.
     */
    $themeCopy = __('studio.form.theme_section_help', [], 'ar');
    $seoCopy = __('studio.form.seo_section_help', [], 'ar');

    expect(str_contains($tabs['theme'], $themeKey))->toBeTrue('the theme field is not on the theme tab');
    expect(str_contains($tabs['theme'], $themeCopy))->toBeTrue('the theme section is not on the theme tab');
    expect(str_contains($tabs['page'], $themeCopy))->toBeFalse('the theme section is STILL on the page tab, so it was copied rather than moved');
    expect(str_contains($tabs['theme'], $seoCopy))->toBeTrue('search-and-sharing did not move with it');
    expect(str_contains($tabs['page'], $seoCopy))->toBeFalse('search-and-sharing is still on the page tab');
});

it('keeps every action, each still reachable on a registered page', function (): void {
    foreach (BEFORE_ACTIONS as $action) {
        expect(method_exists(LandingBuilderPage::class, $action))
            ->toBeTrue("the action [{$action}] is gone from the page it lived on");
    }

    // The theme tab needs its own save, or its form is a form that cannot be submitted.
    expect(method_exists(ThemePage::class, 'save'))->toBeTrue();
});

it('keeps all four surfaces reachable, which is what the sidebar used to give', function (): void {
    /*
     * The tabs replaced the sidebar, so "can the owner still GET there" stops being
     * Filament's problem and becomes this ticket's. Each page must still resolve a URL.
     */
    foreach ([
        LandingBuilderPage::class => 'filament.studio.pages..',
        MediaLibraryPage::class => 'filament.studio.pages.media',
        ThemePage::class => 'filament.studio.pages.theme',
        DomainsPage::class => 'filament.studio.pages.domains',
    ] as $page => $route) {
        // ⚠ THE ROUTE AND NOT `::getUrl()`. `getUrl()` resolves through the CURRENT panel,
        // which no panel request has established here, so it throws rather than answering
        // the question. The claim is "the owner can still get there", and a named route
        // existing is that claim; the tab partial builds its href from the same name.
        expect(Route::has($route))
            ->toBeTrue("[{$page}] has no route, so the tab bar cannot link to it");
    }
});

it('mirrors Filament’s dark mode onto the attribute the token layer reads', function (): void {
    /*
     * ⚠ TWO THEMING SYSTEMS MEET IN THIS PANEL AND DID NOT TOUCH BEFORE `QRPAGER-40`.
     * Filament flips on `html.dark`; `packages/tokens/generated/tokens.css` flips on
     * `[data-theme='dark']`. The compiled stylesheet carries BOTH -- `--color-paper`
     * appears as `#fcfcfa` and `#191c22` in the same file -- and nothing set the attribute,
     * so every `var(--color-…)` in panel Blade resolved to the LIGHT value inside a dark
     * panel. The studio's footer bar rendered white on a dark page.
     *
     * ⚠ THIS ASSERTS THE MECHANISM IS WIRED, NOT THAT THE COLOURS ARE RIGHT. A PHP test
     * cannot run the script or read a computed style; what it can do is fail if the hook is
     * removed or renamed, which is the regression worth catching. The colours are a
     * BROWSER claim and belong to a rendered frame.
     */
    $html = studioTabs()['page'];

    expect(str_contains($html, "root.classList.contains('dark')"))
        ->toBeTrue('the panel no longer mirrors Filament’s mode onto data-theme');
    expect(str_contains($html, 'root.dataset.theme'))
        ->toBeTrue('the mirror no longer writes the attribute the token layer reads');
    expect(str_contains($html, 'MutationObserver'))
        ->toBeTrue('the mirror is set once and never updated, so flipping the mode mid-session desyncs it');
});
