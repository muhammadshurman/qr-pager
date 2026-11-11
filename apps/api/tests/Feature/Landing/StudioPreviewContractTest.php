<?php

declare(strict_types=1);

use App\Domain\Landing\BlockSchema;
use App\Domain\Landing\ThemeTokenKind;
use App\Domain\Landing\ThemeTokens;
use App\Domain\Tenancy\TenantRole;
use App\Features\Landing\LandingPages;
use App\Features\Landing\MediaLibrary;
use App\Features\Landing\Panel\KeepsPreviewInStep;
use App\Features\Landing\Panel\Pages\LandingBuilderPage;
use App\Features\Landing\Panel\Pages\ThemePage;
use App\Features\Landing\Panel\StudioForm;
use App\Features\Landing\Panel\StudioPanelProvider;
use App\Features\Landing\UnsavedPreview;
use Filament\Panel;
use Livewire\Livewire;
use Tests\Support\ImageFixtures;
use Tests\Support\LandingFixtures;

/*
 * ================================================================================
 * EVERY STUDIO PAGE THAT CAN SAVE KEEPS THE PREVIEW IN STEP
 * ================================================================================
 *
 * `QRPAGER-45` was REJECTED on delivery: a colour changed on `الثيم` and SAVED did not reach
 * the preview, because `previewChanged()` and the `forget()` calls lived on
 * `LandingBuilderPage` alone — the ticket's invariant named only builder-page controls and
 * the studio's second document-writing page was in neither it nor a test.
 *
 * THE AMENDED INVARIANT, which replaces the one the ticket shipped against:
 *
 *   On every studio page, a settled change is reflected in the preview with no Save click
 *   and no page refresh; and after a save on any studio page, the preview and the stored
 *   draft agree.
 *
 * ⚠ THIS FILE IS THE GUARD, AND ITS FIRST VERSION COULD BE EVADED. It globbed
 * `Panel/Pages/*.php` while claiming to derive from the panel, and it decided "writes the
 * draft" with `str_contains($source, 'saveDraft(')`. Measured by the §11 round: a `Page`
 * subclass registered from `Panel/Screens/`, calling `saveDraft()` with no trait and no
 * partial, left all four cases GREEN — and moving a save body into a trait, or a forget
 * into an unreachable method, evaded it in the other direction.
 *
 * So: the page set comes from the PANEL (`StudioLocalizationTest` already does this), and
 * the contract is DRIVEN rather than grepped.
 *
 * ⚠ AND NO MESSAGE IS PASSED TO `expect()`. Pest's `expect(mixed $value = null)` takes ONE
 * argument (`vendor/pestphp/pest/src/Functions.php:35`), so a second one is silently
 * discarded and the failure reads only *"Failed asserting that two arrays are identical"* —
 * the `§11` QA round's L-8. Every explanation here is on the MATCHER, where Pest prints it.
 */

/**
 * Every page the studio panel registers, from the panel itself.
 *
 * @return list<class-string>
 */
function previewContractPages(): array
{
    /** @var list<class-string> $pages */
    $pages = array_values((new StudioPanelProvider(app()))->panel(Panel::make())->getPages());

    return $pages;
}

/** The pages that expose a save, which is what the invariant's second clause is about. */
function previewContractSavingPages(): array
{
    return array_values(array_filter(
        previewContractPages(),
        static fn (string $page): bool => method_exists($page, 'save')
    ));
}

it('derives its page set from the panel, so a page registered anywhere is covered', function (): void {
    $pages = previewContractPages();

    expect(count($pages))->toBeGreaterThanOrEqual(4)
        ->and($pages)->toContain(LandingBuilderPage::class)
        ->and($pages)->toContain(ThemePage::class);

    // ⚠ AND IT IS NOT THE FILESYSTEM. The first version globbed a directory while its own
    // docblock said "derived from the panel, never listed"; a page registered from another
    // directory evaded every case in this file.
    expect(previewContractSavingPages())->not->toBeEmpty();
});

it('gives every page that can save the preview contract', function (): void {
    $offenders = [];

    foreach (previewContractSavingPages() as $page) {
        if (! in_array(KeepsPreviewInStep::class, class_uses_recursive($page), true)) {
            $offenders[] = class_basename($page).' exposes save() without KeepsPreviewInStep';
        }
    }

    expect(
        $offenders,
        'a studio page can save without keeping the preview in step, so after a save there the '
        .'pane can show an unsaved key that contradicts the database — the bug QRPAGER-45 was '
        .'rejected for: '.implode(' | ', $offenders)
    )->toBe([]);
});

it('drops the unsaved state when any such page actually saves', function (): void {
    $tenant = test()->actingForTenant(role: TenantRole::Owner);
    $sid = session()->getId();

    $pages = app(LandingPages::class);
    $pages->saveDraft($tenant, LandingFixtures::fullPage());

    $state = StudioForm::fromDocument($pages->draft($tenant)->toArray());
    $driven = 0;
    $published = 0;

    foreach (previewContractSavingPages() as $page) {
        if (! in_array(KeepsPreviewInStep::class, class_uses_recursive($page), true)) {
            continue; // the case above already names it
        }

        // A key exists, as it would after any editing on any tab.
        app(UnsavedPreview::class)->put($tenant, $sid, $pages->draft($tenant)->toArray());

        /*
         * ⚠ DRIVEN, NOT GREPPED. The first version asserted `str_contains($source,
         * 'forgetUnsavedPreview(')`, which stayed green when that call was moved into an
         * unreachable private method — the reported bug restored, the guard satisfied.
         */
        Livewire::test($page)->fillForm($state)->call('save');

        expect(
            app(UnsavedPreview::class)->get($tenant, $sid),
            class_basename($page).' saved and left an unsaved key behind to contradict the draft'
        )->toBeNull();

        $driven++;

        /*
         * ⚠ AND PUBLISH, WHICH IS A SECOND WRITE OF THE DOCUMENT AND WAS THE `§11` QA
         * ROUND'S M-4. `UnsavedPreviewTest` drives publish on `LandingBuilderPage` by name;
         * derived here, a page that gains a publish tomorrow is covered the day it is
         * registered rather than the day somebody remembers to add a case for it.
         */
        if (! method_exists($page, 'publish')) {
            continue;
        }

        app(UnsavedPreview::class)->put($tenant, $sid, $pages->draft($tenant)->toArray());

        Livewire::test($page)->fillForm($state)->call('publish');

        expect(
            app(UnsavedPreview::class)->get($tenant, $sid),
            class_basename($page).' published and left an unsaved key behind, so the pane can '
            .'contradict the page that is now live'
        )->toBeNull();

        $published++;
    }

    expect($driven)->toBeGreaterThanOrEqual(2, 'fewer than two pages were driven, so this proves nothing')
        ->and($published)->toBeGreaterThanOrEqual(1, 'no publish was driven, so the publish half is vacuous');
});

/**
 * ONE STATE PER PAGE THAT MUST NOT REACH THE KEY, FOR EACH WAY OF BEING REFUSED.
 *
 * ⚠ DECLARED PER PAGE, AND CHECKED AGAINST THE DERIVED SET BELOW, BECAUSE "INVALID" IS NOT
 * A PROPERTY OF THE CONTRACT — it is a property of a page's own fields. The `§11` QA round's
 * L-9 measured the consequence of driving the failure branches through one page: every one
 * of them (the 403 re-ask, both invalid shapes, the neighbour's media) ran on
 * `LandingBuilderPage` only, so on `الثيم` — THE PAGE THE BUG WAS REPORTED ON — no refusal
 * was exercised at all. Emptying a block heading is not invalid on a form that has no
 * blocks: a shared mutation would have passed there while testing nothing.
 *
 * So each page declares its own, and a page that declares none FAILS this file rather than
 * being skipped by it. That is the same bargain as `previewContractPages()`: the set is
 * derived, and the thing that cannot be derived is forced to be written down.
 *
 * @param  string  $stolen  a media id belonging to ANOTHER tenant
 * @return array<class-string, array<string, callable(array<string, mixed>): array<string, mixed>>>
 */
function previewContractInvalidStates(string $stolen): array
{
    /** @var string $colour the first token the theme holds as a colour, derived, not named */
    $colour = (string) array_key_first(array_filter(
        ThemeTokens::NAMES,
        static fn (ThemeTokenKind $kind): bool => $kind === ThemeTokenKind::Colour,
    ));

    return [
        LandingBuilderPage::class => [
            'a value FILAMENT itself refuses' => static function (array $state): array {
                /** @var array<array-key, array{data: array<string, mixed>}> $blocks */
                $blocks = $state['blocks'];
                $first = array_key_first($blocks);

                // A required field emptied, which is what mid-edit actually looks like:
                // `getState()` raises Filament's `ValidationException` before the document
                // gate ever sees the state. That was one of this ticket's own three defects.
                $state['blocks'][$first]['data']['heading'] = '';

                return $state;
            },
            'a value the document contract refuses' => static function (array $state): array {
                /** @var array<array-key, array{data: array<string, mixed>}> $blocks */
                $blocks = $state['blocks'];
                $first = array_key_first($blocks);

                // A line break: Filament has no rule against one, so this reaches the
                // document gate rather than stopping at `getState()`.
                $state['blocks'][$first]['data']['heading'] = "Welcome\nto Olive";

                return $state;
            },
            'another tenant’s media id' => static fn (array $state): array => StudioForm::fromDocument(
                LandingFixtures::fullPage($stolen),
            ),
        ],
        ThemePage::class => [
            'a value FILAMENT itself refuses' => static fn (array $state): array => array_replace($state, [
                // ⚠ NOT A REQUIRED FIELD, BECAUSE THIS FORM HAS NONE — which is exactly why
                // L-9 could not be closed by sharing the builder's mutation. The same GATE is
                // reached by its own `maxLength`, so `ValidationException` is exercised on
                // THIS page too, and that is the branch the reported bug's page never had.
                'seo_title' => str_repeat('a', BlockSchema::MAX_SEO_TITLE + 10),
            ]),
            'a value the document contract refuses' => static fn (array $state): array => array_replace($state, [
                StudioForm::themeKey($colour) => 'not-a-colour',
            ]),
            'another tenant’s media id' => static fn (array $state): array => array_replace($state, [
                'seo_og_image' => $stolen,
            ]),
        ],
    ];
}

it('writes nothing, raises nothing and clears nothing when any page stops validating', function (): void {
    $neighbour = test()->actingForTenant(role: TenantRole::Owner);
    app(MediaLibrary::class)->store($neighbour, 'neighbour-asset.png', ImageFixtures::png());
    $stolen = app(MediaLibrary::class)->all($neighbour)[0]->id;

    $tenant = test()->actingForTenant(role: TenantRole::Owner);
    $sid = session()->getId();

    $pages = app(LandingPages::class);
    $pages->saveDraft($tenant, LandingFixtures::fullPage());

    $state = StudioForm::fromDocument($pages->draft($tenant)->toArray());
    $declared = previewContractInvalidStates($stolen);
    $driven = 0;

    foreach (previewContractPages() as $page) {
        if (! in_array(KeepsPreviewInStep::class, class_uses_recursive($page), true)) {
            continue;
        }

        expect(
            array_key_exists($page, $declared),
            class_basename($page).' has the preview contract and declares no invalid state, so '
            .'its refusals are untested — add one to previewContractInvalidStates()'
        )->toBeTrue();

        foreach ($declared[$page] as $way => $mutate) {
            $where = class_basename($page).' with '.$way;

            /*
             * The last state that DID validate, planted first, because the specification is
             * about three things at once and the third is the one a reader forgets:
             * nothing written, nothing raised, and nothing CLEARED. Clearing would drop the
             * pane back to the stored draft mid-edit — the preview jumping backwards while
             * the owner types, which reads as data loss.
             */
            $valid = LandingFixtures::fullPage();
            $valid['blocks'][0]['fields']['heading'] = 'LAST VALID PREVIEW';
            app(UnsavedPreview::class)->put($tenant, $sid, $pages->validated($tenant, $valid)->toArray());

            Livewire::test($page)
                ->fillForm($mutate($state))
                ->call('previewChanged')
                ->assertHasNoErrors();

            $held = app(UnsavedPreview::class)->get($tenant, $sid);

            expect(
                $held,
                $where.': the key was CLEARED, so the pane falls back to the stored draft and the '
                .'preview jumps backwards in the middle of an edit'
            )->not->toBeNull();

            expect(
                (string) json_encode($held, JSON_UNESCAPED_UNICODE),
                $where.': the key no longer holds the last state that validated, so this refusal '
                .'overwrote the preview instead of leaving it standing'
            )->toContain('LAST VALID PREVIEW');

            $driven++;
        }
    }

    expect($driven)->toBeGreaterThanOrEqual(6, 'fewer refusals were driven than the pages declare');
});

/**
 * How many unsaved-preview keys this tenant has, under ANY session.
 *
 * ⚠ THE SCAN RATHER THAN `get($tenant, $sid)`, AND THE REASON IS THE REFUSAL CASE BELOW.
 * Signing in as a different principal may regenerate the session id, so a key read back
 * under the id captured beforehand would be null because the NAME moved, not because
 * nothing was written — a control that passes for the wrong reason, on the exact branch it
 * exists to cover. Counting the tenant's whole namespace cannot be fooled that way, and the
 * case proves the counter itself is live before it relies on a zero.
 */
function previewKeyCount(string $tenantId): int
{
    /** @var array<array-key, string> $keys */
    $keys = app('redis')->connection()->keys('qrpager:studio:unsaved-preview:'.$tenantId.':*');

    return count($keys);
}

it('refuses previewChanged on every page to a principal the panel refuses', function (): void {
    $tenant = test()->actingForTenant(role: TenantRole::Owner);

    app(LandingPages::class)->saveDraft($tenant, LandingFixtures::fullPage());

    $driven = 0;

    foreach (previewContractPages() as $page) {
        if (! in_array(KeepsPreviewInStep::class, class_uses_recursive($page), true)) {
            continue;
        }

        $where = class_basename($page);

        // The counter is LIVE: planted, seen, dropped, seen to be gone. Without this a zero
        // below would be indistinguishable from a scan that matches nothing it should.
        app(UnsavedPreview::class)->put($tenant, session()->getId(), app(LandingPages::class)->draft($tenant)->toArray());

        expect(previewKeyCount($tenant))->toBe(1, $where.': the keyspace scan sees no key that was just written');

        app(UnsavedPreview::class)->forget($tenant, session()->getId());

        expect(previewKeyCount($tenant))->toBe(0, $where.': the keyspace scan still sees a key that was dropped');

        /*
         * ⚠ THE PRINCIPAL CHANGES AFTER THE MOUNT, which is the shape of the real attack: a
         * snapshot captured from an authorised page and replayed by somebody else. `staff`
         * is refused by `TenantPanelAccess::ROLES` in the owner's OWN tenant, so this is a
         * refusal of the role and not an accident of which tenant was resolved.
         *
         * ⚠ AND `assertForbidden()` RATHER THAN A `try`/`catch`: the harness catches the
         * abort and records a status, so a catch never fires and the first version of the
         * case in `UnsavedPreviewTest` reported a correct refusal as a failure to refuse.
         * This is that control over the DERIVED page set — the `§11` QA round's L-9, which
         * measured every failure branch running on `LandingBuilderPage` alone.
         */
        $component = Livewire::test($page);

        test()->actingForTenant($tenant, role: TenantRole::Staff);

        $component->call('previewChanged')->assertForbidden();

        expect(previewKeyCount($tenant))->toBe(
            0,
            $where.' refused the principal and wrote a key anyway, so a 403 arriving after the '
            .'write leaks the owner’s in-flight edits into a store the refused caller can key to'
        );

        test()->actingForTenant($tenant, role: TenantRole::Owner);

        $driven++;
    }

    expect($driven)->toBeGreaterThanOrEqual(2, 'fewer than two pages were driven, so this proves nothing');
});

it('serves the debounce on every page that has the contract', function (): void {
    test()->actingForTenant(role: TenantRole::Owner);

    /*
     * ⚠ THE RENDERED PAGE, AND THE URL COMES FROM THE PANEL. The first version iterated a
     * two-element literal while being named "every studio page", so the rendered half was
     * unchecked for anything added later. A template and a served page have disagreed in
     * this project before — `QRPAGER-41` part B named three classes Filament does not
     * render, changed nothing, and was green the whole time.
     */
    $checked = 0;

    foreach (previewContractPages() as $page) {
        if (! in_array(KeepsPreviewInStep::class, class_uses_recursive($page), true)) {
            continue;
        }

        $html = (string) test()->get($page::getUrl(panel: 'studio'))->getContent();

        expect($html)->toContain(
            'previewChanged',
        );

        expect($html)->toContain('studio-preview-updated');

        $checked++;
    }

    expect($checked)->toBeGreaterThanOrEqual(2);
});

it('opens the studio layout with no stray text before the form column', function (): void {
    test()->actingForTenant(role: TenantRole::Owner);

    /*
     * ⚠ A RENDERING DEFECT THE PARTIAL INTRODUCED, FOUND BY THE `§11` QA ROUND OF 2026-11-11.
     * `preview-sync.blade.php` used to END with `reload()">` — closing the tag itself — while
     * both call sites add their own `>`. So every studio page served a stray `>` as the first
     * child of `.studio-layout`, which is `display: flex`: an anonymous flex item, i.e. a
     * visible glyph plus one extra `gap` before the form column, on a builder page that was
     * correct before the partial existed.
     *
     * The assertion is on the RENDERED page and on the gap between two known landmarks,
     * because that is where the defect lives — a template assertion would have passed on it.
     */
    foreach (previewContractPages() as $page) {
        if (! in_array(KeepsPreviewInStep::class, class_uses_recursive($page), true)) {
            continue;
        }

        $html = (string) test()->get($page::getUrl(panel: 'studio'))->getContent();

        /*
         * ⚠ PARSED, NOT SCANNED. The first version looked for the next `>` after
         * `class="studio-layout"` and found one INSIDE the `x-data` attribute — the arrow
         * functions are full of them — so it reported the debounce's own source as stray
         * text. A string landmark cannot find the end of a tag whose attributes contain the
         * delimiter.
         */
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $layout = (new DOMXPath($dom))
            ->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' studio-layout ')]")
            ?->item(0);

        expect($layout)->not->toBeNull();

        $stray = [];

        foreach ($layout->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE && trim((string) $child->nodeValue) !== '') {
                $stray[] = trim((string) $child->nodeValue);
            }
        }

        expect($stray)->toBe(
            [],
            class_basename($page).' renders a text node directly inside .studio-layout, which is '
            .'display:flex — so it becomes a visible anonymous flex item: '.implode(' | ', $stray)
        );
    }
});
