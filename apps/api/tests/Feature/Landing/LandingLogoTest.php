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
