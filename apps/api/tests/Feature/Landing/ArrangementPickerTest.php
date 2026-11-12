<?php

declare(strict_types=1);

use App\Domain\Landing\LandingTemplate;
use App\Domain\Tenancy\TenantRole;
use App\Features\Landing\LandingPages;
use App\Features\Landing\MediaLibrary;
use App\Features\Landing\Panel\Pages\ThemePage;
use App\Features\Landing\UnsavedPreview;
use Livewire\Livewire;
use Tests\Support\ImageFixtures;
use Tests\Support\LandingFixtures;

/**
 * ================================================================================
 * `QRPAGER-47`'s STATED INVARIANT, SECOND CLAUSE — THE SERVER HALF
 * ================================================================================
 *
 * Muhammad's bar: *"choosing a theme in the picker is reflected in the preview with no Save
 * click and no page refresh."*
 *
 * ⚠ THE CLAUSE HAS TWO HALVES AND THEY NEED DIFFERENT SUBJECTS. *"No page refresh"* is a
 * browser fact and is driven by `browser/studio.spec.ts`, which clicks a card and watches the
 * pane's own markup change. *"Reflected in the preview, with no Save"* is a SERVER fact —
 * what `previewChanged()` writes and what the stored draft still says — and that is this
 * file. An e2e case alone would leave the mechanism untested at the one place a defect hides:
 * `QRPAGER-45` was rejected for a preview that showed something contradicting the database.
 *
 * ⚠ AND THE THIRD PROPERTY HERE IS THE TICKET'S OWN PROMISE TO THE OWNER: *"switching a
 * theme never touches the owner's text or images, and the screen says so."* `StudioForm::
 * merged()` is what makes that true, and the case below reads the blocks and the logo out of
 * the previewed document rather than trusting it.
 */
it('reflects a new arrangement in the preview with no save, leaving the draft alone', function (): void {
    $tenant = $this->actingForTenant(role: TenantRole::Owner);
    $sid = session()->getId();

    $pages = app(LandingPages::class);
    $pages->saveDraft($tenant, LandingFixtures::fullPage());

    // The stored arrangement is the default, which is `rail`.
    expect($pages->draft($tenant)->template)->toBe(LandingTemplate::Rail);

    Livewire::test(ThemePage::class)
        ->set('data.template', 'tile')
        ->call('previewChanged');

    $previewed = app(UnsavedPreview::class)->get($tenant, $sid);

    expect($previewed)->not->toBeNull('no preview was written, so the pane would still show the stored draft')
        ->and($previewed['template'] ?? null)->toBe('tile');

    // ⚠ AND THE DATABASE HAS NOT MOVED. Without this half the case would pass just as well
    // against a control that SAVED on every click — which is a different product: the owner
    // would be publishing experiments to their draft while looking at them.
    expect($pages->draft($tenant)->template)->toBe(
        LandingTemplate::Rail,
        'choosing an arrangement wrote the stored draft, so it was saved rather than previewed'
    );
});

it('carries the owner’s blocks and logo into the previewed document, which is the promise on screen', function (): void {
    /*
     * The sentence under the cards says *"switching a theme never removes your text or your
     * images"*. This is that sentence as an assertion — and it is not free: the theme tab's
     * form carries no `blocks` member at all, so a preview built from this page's own state
     * would render a page with NOTHING on it. `StudioForm::merged()` lays the state over the
     * stored document, which is the only reason the promise holds.
     */
    $tenant = $this->actingForTenant(role: TenantRole::Owner);
    $sid = session()->getId();

    $assetId = (new MediaLibrary)->store($tenant, 'sarw-mark.png', ImageFixtures::png());
    $pages = app(LandingPages::class);

    $pages->saveDraft($tenant, array_replace(LandingFixtures::fullPage(), ['logo' => $assetId]));

    $before = $pages->draft($tenant)->toArray();

    Livewire::test(ThemePage::class)
        ->set('data.template', 'ember')
        ->call('previewChanged');

    $previewed = app(UnsavedPreview::class)->get($tenant, $sid);

    expect($previewed['template'] ?? null)->toBe('ember')
        ->and($previewed['blocks'] ?? null)->toBe($before['blocks'], 'the owner’s blocks changed with the theme')
        ->and($previewed['logo'] ?? null)->toBe($before['logo'], 'the owner’s logo changed with the theme')
        ->and($previewed['seo'] ?? null)->toBe($before['seo']);
});

it('marks the STORED arrangement in words, not the one just clicked', function (): void {
    /*
     * ⚠ THE BADGE IS THE ONLY THING ON THE TAB THAT DISTINGUISHES *LOOKING* FROM *HAVING
     * CHANGED*, which is why it marks the saved arrangement rather than the selected card.
     * The brief asks for *"the current one is marked in words"*, and after a click the current
     * one is still the one in the database.
     *
     * Driven through the rendered component, because the claim is about what the owner SEES.
     */
    $tenant = $this->actingForTenant(role: TenantRole::Owner);

    app(LandingPages::class)->saveDraft(
        $tenant,
        array_replace(LandingFixtures::fullPage(), ['template' => 'ember']),
    );

    /*
     * ⚠ PINNED TO `ar`, AND THE REASON IS THE SAME ONE `StudioCapabilityTest` RECORDS: the
     * tenant this test acts for has no language set, so it is `TenantLanguage::DEFAULT` =
     * `ar` and the PANEL renders Arabic — while `app()->getLocale()` outside the component's
     * own lifecycle is still `en`. Reading the key in the wrong locale made this case fail on
     * a page that was correct, with `strpos()` returning false and nothing saying why.
     */
    $badge = (string) __('studio.arrangements.in_use', [], 'ar');

    $component = Livewire::test(ThemePage::class);
    $html = $component->html();

    // Exactly one badge, and it is in `ember`'s card: the name and the badge are adjacent in
    // the markup, so the slice between them cannot contain another card's name.
    expect(substr_count($html, $badge))->toBe(1);

    $emberName = e(LandingTemplate::Ember->name('ar'));
    $emberAt = strpos($html, $emberName);
    $badgeAt = strpos($html, $badge);

    expect($emberAt)->toBeInt()
        ->and($badgeAt)->toBeInt()
        ->and($badgeAt - $emberAt)->toBeLessThan(400)
        ->and($badgeAt)->toBeGreaterThan($emberAt);

    // Clicking another card moves the SELECTION and not the badge, because nothing has been
    // saved. The selection is Alpine's and lives in the state; the badge is the server's.
    $after = $component->set('data.template', 'tile')->html();

    expect(substr_count($after, $badge))->toBe(1)
        ->and(strpos($after, $badge))->toBeGreaterThan((int) strpos($after, $emberName));
});

it('moves the badge once the arrangement is actually saved', function (): void {
    // The other direction, so the case above cannot pass against a badge that is simply
    // nailed to one card.
    $tenant = $this->actingForTenant(role: TenantRole::Owner);

    app(LandingPages::class)->saveDraft($tenant, LandingFixtures::fullPage());

    $badge = (string) __('studio.arrangements.in_use', [], 'ar');
    $tileName = e(LandingTemplate::Tile->name('ar'));
    $railName = e(LandingTemplate::Rail->name('ar'));

    $before = Livewire::test(ThemePage::class)->html();

    expect(strpos($before, $badge))->toBeGreaterThan((int) strpos($before, $railName))
        ->and(strpos($before, $badge))->toBeLessThan((int) strpos($before, $tileName));

    Livewire::test(ThemePage::class)
        ->set('data.template', 'tile')
        ->call('save');

    expect(app(LandingPages::class)->draft($tenant)->template)->toBe(LandingTemplate::Tile);

    $after = Livewire::test(ThemePage::class)->html();

    expect(strpos($after, $badge))->toBeGreaterThan((int) strpos($after, $tileName));
});
