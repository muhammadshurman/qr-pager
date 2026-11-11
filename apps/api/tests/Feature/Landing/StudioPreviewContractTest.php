<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use App\Features\Landing\LandingPages;
use App\Features\Landing\Panel\KeepsPreviewInStep;
use App\Features\Landing\Panel\Pages\LandingBuilderPage;
use App\Features\Landing\Panel\Pages\ThemePage;
use App\Features\Landing\Panel\StudioForm;
use App\Features\Landing\Panel\StudioPanelProvider;
use App\Features\Landing\UnsavedPreview;
use Filament\Panel;
use Livewire\Livewire;
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

        expect($html, class_basename($page).' serves no debounce, so nothing calls previewChanged()')
            ->toContain('previewChanged');

        expect($html, class_basename($page).' serves no reload listener')
            ->toContain('studio-preview-updated');

        $checked++;
    }

    expect($checked)->toBeGreaterThanOrEqual(2);
});
