<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use App\Features\Landing\LandingPages;
use App\Features\Landing\MediaLibrary;
use App\Features\Landing\Panel\StudioForm;
use Tests\Support\ImageFixtures;
use Tests\Support\LandingFixtures;

/*
 * ================================================================================
 * `QRPAGER-40` — SAVING FROM ONE TAB MUST NOT DROP WHAT ANOTHER TAB OWNS
 * ================================================================================
 *
 * Muhammad's ruling of 2026-11-09: the Theme and Search-and-sharing move does not land
 * without this test in the same commit, **both directions, per tab**.
 *
 * ⚠ **THE DEFECT IT EXISTS FOR WAS FOUND BY READING, NOT BY A RED TEST, AND IT WOULD NOT
 * HAVE SHOWN IN A SCREENSHOT.** `StudioForm::toDocument()` builds the WHOLE document —
 * blocks, template, theme, seo — out of ONE flat state array, by key. That was correct
 * while one form carried all of it. The moment the schema is split across two tabs, a page
 * that hands `toDocument()` only its OWN state writes a document whose other members are
 * simply absent:
 *
 *   save from `الصفحة` -> `theme` empty  -> the tenant's colours, fonts and spacing gone
 *   save from `الثيم`  -> `blocks` empty -> the tenant's whole page gone
 *
 * Silent in both directions, and discovered only when the owner next looked at their own
 * site. `StudioForm::merged()` is the fix — each page lays its state over the stored draft
 * — and these cases are what keep it true when a third tab arrives.
 */

function mergeTenant(): string
{
    LandingFixtures::bucket();

    return test()->actingForTenant(role: TenantRole::Owner);
}

/** The stored draft, as the flat form state both tabs speak. */
function storedState(string $tenantId): array
{
    return StudioForm::fromDocument(app(LandingPages::class)->draft($tenantId)->toArray());
}

it('keeps the theme when the page tab saves — the الصفحة direction', function (): void {
    $tenant = mergeTenant();
    $asset = (new MediaLibrary)->store($tenant, 'logo.png', ImageFixtures::png());

    app(LandingPages::class)->saveDraft($tenant, array_replace(
        LandingFixtures::fullPage($asset),
        ['logo' => $asset],
    ));

    $before = storedState($tenant);
    $themeKey = StudioForm::themeKey('color.background');

    // The premise: the stored draft really does carry a theme. Without this the case
    // would pass on a document that never had one, which is the vacuity this file is
    // most at risk of.
    expect($before[$themeKey] ?? null)->toBeString()->not->toBe('');

    /*
     * What the page tab's form actually holds — and `QRPAGER-47` CHANGED IT: the arrangement
     * moved to the theme tab (Part B item 1), so `template` is no longer in this state. The
     * `§11` QA round caught this shape still carrying it, which would have made the case
     * describe a form that does not exist.
     *
     * ⚠ AND THAT MAKES THE DIRECTION BELOW STRONGER RATHER THAN WEAKER: the page tab now has
     * to leave the arrangement, the theme AND the logo alone, none of which it carries, and
     * all three survive for one reason — `merged()` lays this state over the stored document.
     */
    $pageTabState = ['blocks' => $before['blocks']];

    app(LandingPages::class)->saveDraft($tenant, StudioForm::merged($tenant, $pageTabState));

    $after = storedState($tenant);

    expect($after[$themeKey])->toBe($before[$themeKey], 'saving a block deleted the theme');
    expect($after['blocks'])->toEqual($before['blocks']);

    // ⚠ AND THE TWO MEMBERS `QRPAGER-47` ADDED OR MOVED, which is the `§11` QA round's Medium
    // 2: this file is the project's only guard against one tab's save deleting another tab's
    // members, and `logo` is the member this ticket added.
    expect($after['template'])->toBe($before['template'], 'saving a block reset the arrangement')
        ->and($after['logo'])->toBe($before['logo'], 'saving a block deleted the logo');
});

it('keeps the blocks when the theme tab saves — the الثيم direction', function (): void {
    $tenant = mergeTenant();
    $asset = (new MediaLibrary)->store($tenant, 'logo.png', ImageFixtures::png());

    // ⚠ THE LOGO IS SET DELIBERATELY, because `LandingFixtures::fullPage()` carries none and
    // the premise assertion below refuses to run this direction without one. The same asset
    // serves as a block image and as the mark, which is legitimate — `mediaReferences()`
    // de-duplicates.
    app(LandingPages::class)->saveDraft($tenant, array_replace(
        LandingFixtures::fullPage($asset),
        ['logo' => $asset],
    ));

    $before = storedState($tenant);

    expect($before['blocks'])->not->toBe([], 'the fixture stored no blocks, so this proves nothing');

    /*
     * What the theme tab's form holds: every theme key, the seo fields, and — since
     * `QRPAGER-47` — the arrangement and the logo. No blocks.
     */
    $themeTabState = [
        'template' => $before['template'],
        'logo' => $before['logo'],
    ];

    foreach ($before as $key => $value) {
        if (str_starts_with((string) $key, 'theme_') || str_starts_with((string) $key, 'seo_')) {
            $themeTabState[$key] = $value;
        }
    }

    expect($before['logo'])->toBeString()->not->toBe(
        '',
        'the fixture stored no logo, so the logo half of this direction proves nothing'
    );

    expect($themeTabState)->not->toBe([], 'no theme or seo keys were found, so the shape changed');

    app(LandingPages::class)->saveDraft($tenant, StudioForm::merged($tenant, $themeTabState));

    $after = storedState($tenant);

    expect($after['blocks'])->toEqual($before['blocks'], 'saving the theme deleted the page');
    expect($after['template'])->toBe($before['template']);
});

it('still lets a tab CHANGE what it owns, which is the half a merge could break', function (): void {
    /*
     * ⚠ THE CONTROL ON THE CONTROL. A `merged()` that returned the stored draft and ignored
     * the incoming state would pass both cases above perfectly — nothing would ever be
     * dropped, because nothing would ever change. So one case drives a real edit through
     * each tab and requires it to land.
     */
    $tenant = mergeTenant();
    $asset = (new MediaLibrary)->store($tenant, 'logo.png', ImageFixtures::png());

    app(LandingPages::class)->saveDraft($tenant, LandingFixtures::fullPage($asset));

    $themeKey = StudioForm::themeKey('color.background');

    app(LandingPages::class)->saveDraft($tenant, StudioForm::merged($tenant, [$themeKey => '#123456']));

    expect(storedState($tenant)[$themeKey])->toBe('#123456', 'the theme tab could not change the theme');

    $fewer = array_slice(storedState($tenant)['blocks'], 0, 1, preserve_keys: true);

    app(LandingPages::class)->saveDraft($tenant, StudioForm::merged($tenant, ['blocks' => $fewer]));

    $after = storedState($tenant);

    expect($after['blocks'])->toHaveCount(1, 'the page tab could not change the blocks')
        ->and($after[$themeKey])->toBe('#123456', 'and the edit from the other tab survived it');
});
