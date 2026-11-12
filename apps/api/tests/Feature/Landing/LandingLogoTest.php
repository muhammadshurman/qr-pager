<?php

declare(strict_types=1);

use App\Domain\Landing\LandingDocument;
use App\Domain\Landing\LandingDocumentInvalid;
use App\Domain\Tenancy\TenantRole;
use App\Features\Landing\LandingPages;
use App\Features\Landing\MediaLibrary;
use App\Features\Landing\MediaRejected;
use App\Features\Landing\Panel\Pages\ThemePage;
use App\Features\Landing\PublishedSite;
use App\Features\Landing\UnsavedPreview;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\ImageFixtures;
use Tests\Support\LandingFixtures;
use Tests\Support\StubMediaReferences;

/**
 * ================================================================================
 * THE LOGO SLOT — `QRPAGER-47` PART B ITEM 2, AND THE FOUR THINGS IT HAD TO NOT BE
 * ================================================================================
 *
 * Muhammad's brief: *"The logo slot. Do NOT use Filament's FileUpload — it writes bytes to
 * the object store without passing InspectedImage, which removes a Task 7 security control.
 * Use the path the media library already uses."* His ruling of 2026-11-12 settled the
 * affordance: *"the artboard specifies the affordance, the brief specifies the pipe. The
 * asset lands in the tenant's library, so one upload serves both"* — and
 * *"deleting the logo removes the REFERENCE, not the asset underneath."*
 *
 * So four properties, each with its own case below:
 *
 *   1. a logo is a media id this tenant owns, refused by NAME otherwise;
 *   2. it counts as a media reference, so the publisher copies it and a delete is refused;
 *   3. the upload goes through `MediaLibrary::store()` — the `InspectedImage` boundary;
 *   4. removing it clears the reference and leaves the image in the library.
 */
it('refuses a logo that is not this tenant’s own asset, by name', function (): void {
    // The IDOR a media reference would otherwise be: the panel sends an id, and an id from
    // another restaurant's library is a cross-tenant read with a picture at the end of it.
    // `StubMediaReferences` answers for a library that holds nothing.
    expect(fn () => LandingDocument::fromInput(
        array_replace(LandingFixtures::fullPage(), ['logo' => '3f1a9c62-7b1e-4a2d-9c84-5f0e1d2b3a44']),
        new StubMediaReferences,
    ))->toThrow(LandingDocumentInvalid::class, 'not in your library');

    // A value that is not even a reference is refused before the library is consulted.
    foreach (['https://example.com/logo.png', 'logo.png', '../../etc/passwd', 42] as $bad) {
        try {
            LandingDocument::fromInput(
                array_replace(LandingFixtures::fullPage(), ['logo' => $bad]),
                new StubMediaReferences,
            );

            throw new RuntimeException('a non-reference was accepted as a logo: '.var_export($bad, true));
        } catch (LandingDocumentInvalid $e) {
            expect($e->field)->toBe('logo')
                ->and($e->reason)->toContain('image reference');
        }
    }
});

it('treats absent and empty as NO logo, which is how one is removed', function (): void {
    /*
     * ⚠ BOTH, AND FOR TWO DIFFERENT REASONS. **Absent** is every document stored before
     * 2026-11-12, which `fromStored()` re-validates on every read — a required logo would
     * have invalidated all of them at once. **Empty** is what Livewire sends for a control the
     * owner has just cleared, so refusing it would turn *"remove my logo"* into an error.
     */
    $absent = LandingDocument::fromInput(LandingFixtures::fullPage(), new StubMediaReferences);
    $empty = LandingDocument::fromInput(
        array_replace(LandingFixtures::fullPage(), ['logo' => '']),
        new StubMediaReferences,
    );

    expect($absent->logo)->toBeNull()
        ->and($empty->logo)->toBeNull()
        // And the member is always PRESENT in the stored shape, as null — so a document
        // written today and read back tomorrow round-trips without the key appearing or
        // disappearing under the validator.
        ->and($absent->toArray())->toHaveKey('logo')
        ->and($absent->toArray()['logo'])->toBeNull();
});

it('counts the logo as a media reference, so a delete is refused and a publish copies it', function (): void {
    $tenant = $this->actingForTenant(role: TenantRole::Owner);
    $library = new MediaLibrary;
    $assetId = $library->store($tenant, 'sarw-mark.png', ImageFixtures::png());

    $pages = new LandingPages;
    $pages->saveDraft($tenant, array_replace(LandingFixtures::fullPage(), ['logo' => $assetId]));

    $document = $pages->draft($tenant);

    expect($document->logo)->toBe(strtolower($assetId))
        ->and($document->mediaReferences())->toContain(strtolower($assetId));

    /*
     * ⚠ THE DELETE IS REFUSED BY NAME, AND THIS IS THE CASE THAT WOULD HAVE CAUGHT THE
     * DEFECT. `blockUsing()` answers *"which block"*, and the logo is in no block — so
     * without its own check the panel would have let an owner delete the image their
     * published page serves as its mark, leaving a live page pointing at an object the
     * library no longer lists AND a draft that can never publish again.
     */
    expect(fn () => $library->delete($tenant, $assetId))
        ->toThrow(MediaRejected::class, (string) __('landing.media.in_use_as_logo'));

    // Still there, because the refusal happens before the row is touched.
    expect(collect($library->all($tenant))->pluck('id'))->toContain($assetId);

    // And the publisher copies it, because `mediaReferences()` is what it reads.
    $published = $pages->publish($tenant);
    $html = (string) MediaLibrary::disk()->get(PublishedSite::pointer($published->hostname));

    expect($html)->toContain('class="mark"');
});

it('renders the logo above the page’s own title, and nothing at all without one', function (): void {
    $tenant = $this->actingForTenant(role: TenantRole::Owner);
    $library = new MediaLibrary;
    $pages = new LandingPages;

    // No logo: no element. An empty `src` would make every visitor's browser re-request the
    // page itself, which is why the renderer resolves to null rather than to ''.
    $pages->saveDraft($tenant, LandingFixtures::fullPage());
    $without = (string) MediaLibrary::disk()->get(PublishedSite::pointer($pages->publish($tenant)->hostname));

    expect($without)->not->toContain('class="mark"')
        ->and($without)->not->toContain('src=""');

    $assetId = $library->store($tenant, 'sarw-mark.png', ImageFixtures::png());
    $pages->saveDraft($tenant, array_replace(LandingFixtures::fullPage(), ['logo' => $assetId]));
    $with = (string) MediaLibrary::disk()->get(PublishedSite::pointer($pages->publish($tenant)->hostname));

    /*
     * ⚠ ABOVE `<main>` AND ABOVE THE `h1`, which is where the artboard puts it and what keeps
     * it out of every arrangement's business: `TemplateStructureTest` fingerprints `main`'s
     * own regions, and a mark inside one of them would have had to be placed by all three
     * Blades and could have been dropped by any of them.
     */
    $mark = strpos($with, 'class="mark"');
    $main = strpos($with, '<main>');
    $h1 = strpos($with, '<h1');

    expect($mark)->toBeInt()
        ->and($main)->toBeInt()
        ->and($h1)->toBeInt()
        ->and($mark)->toBeLessThan($h1)
        ->and($mark)->toBeLessThan($main);

    // ⚠ THE `alt` IS THE RESTAURANT'S NAME AND NOT THE WORD "logo". A screen-reader user at
    // the top of the page needs to know WHOSE page it is; the category of the image tells
    // them nothing (`§12`).
    //
    // The name is read from the row the PUBLISHER reads it from, so this case cannot pass
    // against a name only the test knows.
    $name = withTenant($tenant, static fn (): string => (string) DB::table('tenants')->value('name'));

    expect($name)->not->toBe('')
        ->and($with)->toContain('alt="'.e($name).'"')
        ->and($with)->not->toContain('alt="logo"');
});

it('stores an uploaded logo through the library’s own boundary, and never past it', function (): void {
    /*
     * ⚠ THE WHOLE POINT OF THE TICKET'S INSTRUCTION, DRIVEN. `Filament\Forms\Components\
     * FileUpload` writes to a disk itself; this page does not have one. The upload is a
     * Livewire temporary file and `MediaLibrary::store()` — the only writer in this product —
     * is what receives it, so content sniffing, the size bound and the SVG policy all apply.
     *
     * Asserted through the panel rather than against `MediaLibrary` directly, because the
     * claim is about the PANEL's path: a page that wrote bytes any other way would pass a
     * library test and fail this one.
     */
    $tenant = $this->actingForTenant(role: TenantRole::Owner);

    (new LandingPages)->saveDraft($tenant, LandingFixtures::fullPage());

    $before = (new MediaLibrary)->count($tenant);

    Livewire::test(ThemePage::class)
        ->set('logoUpload', UploadedFile::fake()->createWithContent('sarw-mark.png', ImageFixtures::png()))
        ->assertHasNoErrors();

    $assets = (new MediaLibrary)->all($tenant);

    expect($assets)->toHaveCount($before + 1);

    $stored = end($assets);

    expect($stored)->not->toBeFalse()
        // The asset is in the LIBRARY, which is the ruling's own words: "one upload serves
        // both". It is an ordinary asset, deletable from the media tab like any other.
        ->and($stored->originalName)->toBe('sarw-mark.png');

    // And a file the boundary refuses never becomes a logo: the state is untouched and the
    // library is unchanged, which is the fail-closed half.
    $count = (new MediaLibrary)->count($tenant);

    Livewire::test(ThemePage::class)
        ->set('logoUpload', UploadedFile::fake()->createWithContent('not-an-image.png', 'MZ this is a dos executable'))
        ->assertSet('data.logo', null);

    expect((new MediaLibrary)->count($tenant))->toBe($count);
});

it('removes the REFERENCE and leaves the image in the library', function (): void {
    // Muhammad's ruling, as an assertion: the control clears one document member, and the
    // upload stays where the owner can delete it deliberately.
    $tenant = $this->actingForTenant(role: TenantRole::Owner);
    $library = new MediaLibrary;
    $assetId = $library->store($tenant, 'sarw-mark.png', ImageFixtures::png());

    (new LandingPages)->saveDraft($tenant, array_replace(LandingFixtures::fullPage(), ['logo' => $assetId]));

    Livewire::test(ThemePage::class)
        ->assertSet('data.logo', strtolower($assetId))
        ->call('removeLogo')
        ->assertSet('data.logo', null);

    expect(collect($library->all($tenant))->pluck('id'))->toContain($assetId);

    // The stored draft still names it until Save, which is what makes this a form change
    // rather than a silent write — the same contract every other control on the tab follows.
    expect((new LandingPages)->draft($tenant)->logo)->toBe(strtolower($assetId));

    Livewire::test(ThemePage::class)
        ->call('removeLogo')
        ->call('save');

    expect((new LandingPages)->draft($tenant)->logo)->toBeNull()
        ->and(collect($library->all($tenant))->pluck('id'))->toContain($assetId);
});

it('refuses another tenant’s asset as a logo through the PANEL, and tells it from a nonexistent one', function (): void {
    /*
     * ================================================================================
     * ⚠⚠ `§11` SECURITY ROUND, MEDIUM 1 — THE COMPOSITION, NOT THE PORT
     * ================================================================================
     *
     * The first case in this file proves `LandingDocument::media()` refuses an id the
     * reference port does not know, with `StubMediaReferences` standing in for a library that
     * holds nothing. The round's point is that `§11.2` asks for *"proven inaccessible across
     * tenants by a test, not by reading the code"* — and the composition that exists in
     * production is `ThemePage::save()` → `StudioForm::merged()` → `LandingPages::saveDraft()`
     * → a REAL `TenantMediaReferences` built from the authenticated membership.
     *
     * So this drives the panel with a SECOND TENANT'S REAL ASSET under real RLS. The attack is
     * one POST: an owner sets `data.logo` to a uuid they saw somewhere and saves.
     */
    $beta = $this->makeTenant(slug: 'logo-beta');
    $betaAsset = (new MediaLibrary)->store($beta, 'beta-mark.png', ImageFixtures::png());

    $alpha = $this->actingForTenant(role: TenantRole::Owner);
    $pages = new LandingPages;
    $pages->saveDraft($alpha, LandingFixtures::fullPage());

    Livewire::test(ThemePage::class)
        ->set('data.logo', $betaAsset)
        ->call('save');

    expect($pages->draft($alpha)->logo)->toBeNull(
        'another restaurant’s image became this one’s logo'
    );

    // ⚠ AND THE PREVIEW PATH TOO, because it validates and writes a key of its own — a
    // refusal on `save()` alone would leave the foreign reference renderable for 10 minutes.
    Livewire::test(ThemePage::class)
        ->set('data.logo', $betaAsset)
        ->call('previewChanged');

    $previewed = app(UnsavedPreview::class)->get($alpha, session()->getId());

    expect($previewed['logo'] ?? null)->not->toBe(
        strtolower($betaAsset),
        'the preview rendered another restaurant’s image as this one’s logo'
    );

    /*
     * ⚠ AND THE REFUSAL IS NOT AN EXISTENCE ORACLE. A message that distinguished *"that asset
     * belongs to someone else"* from *"no such asset"* would let an owner enumerate other
     * restaurants' media ids one uuid at a time. Measured at the validator, where the two
     * paths meet: the reason must be byte-identical.
     */
    $reasons = [];

    foreach ([$betaAsset, '3f1a9c62-7b1e-4a2d-9c84-5f0e1d2b3a44'] as $id) {
        try {
            $pages->saveDraft($alpha, array_replace(LandingFixtures::fullPage(), ['logo' => $id]));

            throw new RuntimeException('a foreign or absent logo reference was accepted: '.$id);
        } catch (LandingDocumentInvalid $e) {
            $reasons[] = $e->reason;
        }
    }

    expect($reasons[0])->toBe(
        $reasons[1],
        'the refusal distinguishes another tenant’s asset from a nonexistent one, which is an existence oracle'
    );

    // And beta's library is untouched by any of it.
    expect(collect((new MediaLibrary)->all($beta))->pluck('id'))->toContain($betaAsset);
});

it('refuses the logo actions to a principal the panel refuses, and writes nothing', function (): void {
    /*
     * ⚠ `§11` SECURITY ROUND, MEDIUM 2. Deny-by-default holds three ways over — Filament's
     * `hydrateCanAuthorizeAccess()` aborts on every hydration, `ThemePage::canAccess()` is
     * `TenantPanelAccess::allows()`, and `updatedLogoUpload()`'s first statement is
     * `tenantId()`, which aborts itself. None of that was EVIDENCED for these two actions:
     * every case that drove them acted as an owner.
     *
     * The shape is `StudioPreviewContractTest`'s, which is the real attack: a snapshot
     * captured from an authorised page and replayed by somebody the panel refuses. `staff` is
     * refused by `TenantPanelAccess::ROLES` in the owner's OWN tenant, so this is a refusal of
     * the ROLE rather than an accident of which tenant was resolved.
     *
     * ⚠ `assertForbidden()` AND NOT `try`/`catch`: the harness catches the abort and records a
     * status, so a catch never fires — the trap that made an earlier version of a case like
     * this report a correct refusal as a failure to refuse.
     */
    $tenant = $this->actingForTenant(role: TenantRole::Owner);
    $library = new MediaLibrary;
    $assetId = $library->store($tenant, 'sarw-mark.png', ImageFixtures::png());

    (new LandingPages)->saveDraft($tenant, array_replace(LandingFixtures::fullPage(), ['logo' => $assetId]));

    $before = $library->count($tenant);

    /*
     * ⚠ THE UPLOAD IS DRIVEN BY `set()` AND NOT BY `call()`, WHICH LIVEWIRE ENFORCES:
     * `updatedLogoUpload` is an `updated*` LIFECYCLE HOOK, and calling one as an action
     * raises `DirectlyCallingLifecycleHooksNotAllowedException` — measured while writing this
     * case. Setting the property is also the real path: it is what the file input does.
     */
    $drive = [
        'removeLogo' => static fn ($component) => $component->call('removeLogo'),
        /*
         * ⚠ A NON-FILE VALUE, AND A REAL `UploadedFile` HERE WAS A DEAD END: setting one puts
         * Livewire's upload TRANSPORT in play (`_startUpload`/`_finishUpload` are their own
         * requests) and the harness answered *"Invalid Livewire snapshot structure"* rather
         * than a status — a failure about the test harness, not about the panel. Setting any
         * value fires the same hook, whose first statement is the guard this case is about.
         *
         * The transport's own requests are covered by a layer below this one:
         * `hydrateCanAuthorizeAccess()` aborts `canAccess()` on EVERY hydration of a Filament
         * page, which is what `StudioPanelInvariantTest`'s replayed-snapshot case measures.
         */
        'updatedLogoUpload' => static fn ($component) => $component->set('logoUpload', 'intruder'),
    ];

    foreach ($drive as $action => $run) {
        $component = Livewire::test(ThemePage::class);

        $this->actingForTenant($tenant, role: TenantRole::Staff);

        $run($component)->assertForbidden();

        $this->actingForTenant($tenant, role: TenantRole::Owner);

        // Nothing stored, nothing uploaded, and the logo still the owner's own.
        expect($library->count($tenant))->toBe($before, $action.' wrote an asset for a refused principal')
            ->and((new LandingPages)->draft($tenant)->logo)->toBe(
                strtolower($assetId),
                $action.' changed the stored logo for a refused principal'
            );
    }
});

it('says what to do when no file arrived, and stores nothing', function (): void {
    // `§11` QA round, Medium 4: the "no file" branch of `updatedLogoUpload()` had no case, so
    // the only thing standing between it and a 500 was reading it. §12.1's rule is that a
    // refusal names the next action, which is why the branch sends a notification at all.
    $tenant = $this->actingForTenant(role: TenantRole::Owner);
    $library = new MediaLibrary;

    (new LandingPages)->saveDraft($tenant, LandingFixtures::fullPage());

    $before = $library->count($tenant);

    /*
     * ⚠ A VALUE THAT IS NOT A TEMPORARY UPLOAD, set rather than called: `updatedLogoUpload`
     * is a lifecycle hook and Livewire refuses `call()` on one. This is also how the branch is
     * reached in life — a cleared input, or a payload that never carried a file.
     */
    Livewire::test(ThemePage::class)
        ->set('logoUpload', 'not-a-file')
        ->assertHasNoErrors();

    expect($library->count($tenant))->toBe($before)
        ->and((new LandingPages)->draft($tenant)->logo)->toBeNull();
});

it('escapes a hostile colour value into the swatch’s attribute, breaking out of nothing', function (): void {
    /*
     * ⚠ `§11` SECURITY ROUND, LOW 1, AND THE ROUND MEASURED IT RATHER THAN READ IT.
     * `ColourField` merges its attributes with `escape: false`, and Laravel's
     * `ComponentAttributeBag::__toString()` only turns `"` into `\"` — which an HTML parser
     * does not treat as an escape inside a quoted attribute. So a form state of
     * `" x-on:focus=alert(1) z="` closed `value="…"` and opened an Alpine event attribute, in
     * a panel whose CSP carries `unsafe-inline` (`R-242`).
     *
     * Self-XSS only — the value is the authenticated owner's own form state, there is no way
     * to share it, and `ThemeTokens::accepts()` means it can never be stored — and that is
     * why it is Low. It is fixed because `QRPAGER-47` took the number of rows carrying it from
     * one to six.
     */
    $tenant = $this->actingForTenant(role: TenantRole::Owner);

    app(LandingPages::class)->saveDraft($tenant, LandingFixtures::fullPage());

    $html = Livewire::test(ThemePage::class)
        ->set('data.theme_color_background', '" x-on:focus=alert(document.domain) z="')
        ->html();

    /*
     * ⚠⚠ A PARSER AND NOT `toContain`, AND THE FIRST VERSION OF THIS CASE PROVED WHY. With the
     * value escaped, the string `x-on:focus` is STILL in the HTML — inside the attribute's own
     * value, where it is inert text — so `not->toContain('x-on:focus')` failed on a page that
     * is correct. The question is whether the breakout created an ATTRIBUTE, and only an HTML
     * parser answers that. It is the same instrument `StudioAccessibilityTest` reads the panel
     * with.
     */
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $swatch = (new DOMXPath($document))
        ->query('//input[@data-token="color.background"]')
        ?->item(0);

    expect($swatch)->toBeInstanceOf(DOMElement::class, 'the colour swatch is not in the page at all');

    $attributes = [];

    foreach ($swatch->attributes ?? [] as $attribute) {
        $attributes[] = $attribute->nodeName;
    }

    expect($attributes)->not->toContain('x-on:focus')
        // Nothing Alpine-shaped at all beyond the two this field declares, so a breakout into
        // `x-bind`, `@click` or any other binding fails here too.
        ->and(array_values(array_filter(
            $attributes,
            static fn (string $name): bool => str_starts_with($name, 'x-') || str_starts_with($name, '@'),
        )))->toBe(['x-model', 'x-on:input'])
        /*
         * ⚠ AND THE VALUE IS STILL THERE, so this case cannot pass against a field that dropped
         * the attribute altogether or a page that failed to render the row.
         */
        ->and($swatch->getAttribute('value'))->toBe('" x-on:focus=alert(document.domain) z="');
});
