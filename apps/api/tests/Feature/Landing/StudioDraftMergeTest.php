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

    app(LandingPages::class)->saveDraft($tenant, LandingFixtures::fullPage($asset));

    $before = storedState($tenant);
    $themeKey = StudioForm::themeKey('color.background');

    // The premise: the stored draft really does carry a theme. Without this the case
    // would pass on a document that never had one, which is the vacuity this file is
    // most at risk of.
    expect($before[$themeKey] ?? null)->toBeString()->not->toBe('');

    // What the page tab's form actually holds: blocks and the arrangement, no theme keys.
    $pageTabState = [
        'blocks' => $before['blocks'],
        'template' => $before['template'],
    ];

    app(LandingPages::class)->saveDraft($tenant, StudioForm::merged($tenant, $pageTabState));

    $after = storedState($tenant);

    expect($after[$themeKey])->toBe($before[$themeKey], 'saving a block deleted the theme');
    expect($after['blocks'])->toEqual($before['blocks']);
});

it('keeps the blocks when the theme tab saves — the الثيم direction', function (): void {
    $tenant = mergeTenant();
    $asset = (new MediaLibrary)->store($tenant, 'logo.png', ImageFixtures::png());

    app(LandingPages::class)->saveDraft($tenant, LandingFixtures::fullPage($asset));

    $before = storedState($tenant);

    expect($before['blocks'])->not->toBe([], 'the fixture stored no blocks, so this proves nothing');

    // What the theme tab's form holds: every theme key and the seo fields, no blocks.
    $themeTabState = [];

    foreach ($before as $key => $value) {
        if (str_starts_with((string) $key, 'theme_') || str_starts_with((string) $key, 'seo_')) {
            $themeTabState[$key] = $value;
        }
    }

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
