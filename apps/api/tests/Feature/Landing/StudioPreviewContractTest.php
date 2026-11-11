<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use App\Features\Landing\Panel\KeepsPreviewInStep;
use App\Features\Landing\Panel\Pages\LandingBuilderPage;
use App\Features\Landing\Panel\Pages\ThemePage;
use Filament\Pages\Page;

/*
 * ================================================================================
 * EVERY STUDIO PAGE THAT WRITES THE DRAFT KEEPS THE PREVIEW IN STEP
 * ================================================================================
 *
 * `QRPAGER-45` was REJECTED on delivery, and this file is the shape of the fix rather than
 * the fix itself.
 *
 * The symptom the owner reported: a colour changed on `الثيم` and SAVED did not reach the
 * preview, and appeared only when he clicked the arrangement control on the builder page.
 * The cause was not subtle — `previewChanged()` and the `forget()` calls lived on
 * `LandingBuilderPage` alone, because the invariant the ticket was built against named only
 * builder-page controls: *"changing the arrangement, or editing a block field"*. The studio
 * has a SECOND page that writes the same document, and it was in neither the invariant nor
 * a single test.
 *
 * ⚠ SO A PER-PAGE FIX IS THE SHAPE THAT JUST FAILED. Muhammad, 2026-11-11: *"fix at the
 * SHARED level… and a test asserts that EVERY studio page does."* The contract is
 * `KeepsPreviewInStep`; this file is the thing that notices when a page does not have it.
 *
 * THE AMENDED INVARIANT, which replaces the one the ticket shipped against:
 *
 *   On every studio page, a settled change is reflected in the preview with no Save click
 *   and no page refresh; and after a save on any studio page, the preview and the stored
 *   draft agree.
 *
 * The second clause is the one the bug violated and the one nothing tested.
 */

/**
 * Every page class the studio panel registers.
 *
 * ⚠ DERIVED FROM THE PANEL, NEVER LISTED. A hand-written list is a snapshot, and a snapshot
 * is exactly how the theme page came to be outside this contract — `PanelRoutes::chrome()`
 * already derives the route set structurally and `PanelTargetTest` learned the same lesson
 * the same way (`§11` QA M-4, "a seventh chrome route would have been invisible").
 *
 * @return list<class-string>
 */
function studioPageClasses(): array
{
    $pages = [];

    foreach (glob(app_path('Features/Landing/Panel/Pages/*.php')) ?: [] as $file) {
        $class = 'App\\Features\\Landing\\Panel\\Pages\\'.basename($file, '.php');

        if (! class_exists($class) || ! is_subclass_of($class, Page::class)) {
            continue;
        }

        $pages[] = $class;
    }

    return $pages;
}

it('derives a real set of studio pages, so the cases below have subjects', function (): void {
    $pages = studioPageClasses();

    // A bound AND two names: a derivation that silently returned nothing, or only the page
    // this contract was first written for, must not pass.
    expect(count($pages))->toBeGreaterThanOrEqual(4)
        ->and($pages)->toContain(LandingBuilderPage::class)
        ->and($pages)->toContain(ThemePage::class);
});

it('gives every page that writes the draft the preview contract', function (): void {
    $offenders = [];
    $writers = 0;

    foreach (studioPageClasses() as $class) {
        $file = (new ReflectionClass($class))->getFileName();
        $source = $file === false ? '' : (string) file_get_contents($file);

        // "Writes the draft" is a property of the SOURCE, not a list: a page that calls
        // saveDraft() can leave the stored draft disagreeing with the unsaved key.
        if (! str_contains($source, 'saveDraft(')) {
            continue;
        }

        $writers++;

        if (! in_array(KeepsPreviewInStep::class, class_uses_recursive($class), true)) {
            $offenders[] = class_basename($class).' writes the draft without KeepsPreviewInStep';

            continue;
        }

        // Having the trait is not using it: the forget is what makes the saved state win.
        if (! str_contains($source, 'forgetUnsavedPreview(')) {
            $offenders[] = class_basename($class).' never forgets the unsaved preview on save';
        }
    }

    expect($writers)->toBeGreaterThanOrEqual(2, 'fewer than two pages write the draft, so this proves nothing');

    expect($offenders, 'a studio page writes the landing draft without keeping the preview in '
        .'step: after a save there, the pane can show an unsaved key that contradicts the '
        .'database, which is the bug QRPAGER-45 was rejected for')->toBe([]);
});

it('gives every page that writes the draft the debounce that drives it', function (): void {
    /*
     * The server half is useless without the client half: `previewChanged()` is called by
     * the view's 400ms debounce, and the theme tab had no wrapper at all. One partial, so a
     * page cannot have the contract in PHP and not in the DOM.
     */
    $offenders = [];
    $checked = 0;

    foreach (studioPageClasses() as $class) {
        $file = (new ReflectionClass($class))->getFileName();
        $source = $file === false ? '' : (string) file_get_contents($file);

        if (! str_contains($source, 'saveDraft(')) {
            continue;
        }

        if (preg_match("/protected string \\\$view = '([^']+)'/", $source, $m) !== 1) {
            $offenders[] = class_basename($class).' declares no view';

            continue;
        }

        $checked++;
        $view = resource_path('views/'.str_replace('.', '/', $m[1]).'.blade.php');

        if (! is_file($view)) {
            $offenders[] = class_basename($class).": view {$m[1]} does not exist";

            continue;
        }

        if (! str_contains((string) file_get_contents($view), 'studio.partials.preview-sync')) {
            $offenders[] = class_basename($class).": {$m[1]} does not include the preview sync partial";
        }
    }

    expect($checked)->toBeGreaterThanOrEqual(2);

    expect($offenders, 'a studio page writes the draft but its view carries no debounce, so '
        .'nothing calls previewChanged() and the preview only moves on a save')->toBe([]);
});

it('renders the debounce on every studio page that writes the draft', function (): void {
    test()->actingForTenant(role: TenantRole::Owner);

    /*
     * ⚠ THE RENDERED PAGE AND NOT THE TEMPLATE. The case above reads the Blade source, which
     * is where the include is written; this one asks the panel what it actually served. The
     * two have disagreed in this project before — `QRPAGER-41` part B named three classes
     * Filament does not render, changed nothing, and was green the whole time.
     */
    foreach (['/studio', '/studio/theme'] as $uri) {
        $html = (string) test()->get($uri)->getContent();

        expect($html)->toContain('studio-preview-updated')
            ->and($html)->toContain('previewChanged');
    }
});
