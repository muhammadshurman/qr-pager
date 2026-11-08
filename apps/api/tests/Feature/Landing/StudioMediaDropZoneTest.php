<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use App\Features\Landing\Panel\Pages\MediaLibraryPage;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Support\ImageFixtures;

/*
 * ================================================================================
 * §12.2 — THE MEDIA LIBRARY IS A DROP ZONE AND A GRID, NOT A FILE INPUT AND A TABLE
 * ================================================================================
 * QRPAGER-37, 2026-11-08. The owner's complaint was that he could not find where to
 * upload: the page offered a bare `Choose File / No file chosen` and a text table.
 *
 * ⚠ THIS CASE IS THE TICKET'S INVARIANT, NOT A STYLING CHECK. The bar is that every
 * task the owner could do before, he can still do after -- so it asserts the grid
 * carries EVERY column the table carried (name, type, size, dimensions, delete), by
 * value and not by assertion. A redesign that drops the file size is a redesign that
 * lost something, and nothing else in the suite would have said so.
 */

it('offers a drop zone and a visible button, and keeps the plain file input', function (): void {
    $this->actingForTenant(role: TenantRole::Owner);

    $html = Livewire::test(MediaLibraryPage::class)->html();

    /*
     * ⚠ `expect(str_contains(...))->toBeTrue($message)` AND NOT `toContain($needle, $message)`.
     * Pest's `toContain` is VARIADIC: a second argument is ANOTHER NEEDLE, not a message, so
     * the message itself would be asserted as a substring and the case would fail for a
     * reason that has nothing to do with its subject. `§1`, 2026-10-27, and it caught this
     * file on its first run.
     */
    foreach ([
        'data-drop-zone="true"' => 'there is no drop zone',
        'data-choose="true"' => 'there is no visible choose button',
        'Drag an image here' => 'the drop zone does not say what to drag',
        // The input stays a PLAIN file input: Filament's FileUpload would write bytes to the
        // object store without passing InspectedImage. MediaLibraryPage's docblock is the
        // argument; this is the assertion that keeps it true.
        'type="file"' => 'the plain file input is gone',
        'id="media-upload"' => 'the input the drop zone labels is gone',
    ] as $needle => $why) {
        expect(str_contains($html, $needle))->toBeTrue($why);
    }
});

it('keeps the input in the tab order, which `hidden` would not', function (): void {
    $this->actingForTenant(role: TenantRole::Owner);

    $html = Livewire::test(MediaLibraryPage::class)->html();

    /*
     * `sr-only`, never `hidden` or `display:none`: those remove the control from the tab
     * order and the drop zone would become mouse-only. §12 requires keyboard reachable AND
     * a visible focus ring, which is why the ring is bound to the label through `peer-`.
     */
    expect(str_contains($html, 'sr-only peer'))->toBeTrue('the input is not sr-only peer');
    expect(str_contains($html, 'type="file" hidden'))->toBeFalse('the input is hidden, so it left the tab order');
    expect(str_contains($html, 'peer-focus-visible:ring-2'))->toBeTrue('focusing the input shows no ring on the zone');
});

it('carries every column the table carried, so the move to a grid loses nothing', function (): void {
    $this->actingForTenant(role: TenantRole::Owner);

    // The same in-process driver StudioActionsTest uses for store()'s branches: this case is
    // about what the PAGE RENDERS for an asset, not about the signed upload path, which
    // StudioMediaUploadTest already drives over real HTTP.
    $page = Livewire::test(MediaLibraryPage::class)
        ->set('upload', UploadedFile::fake()->createWithContent('invariant-fixture.png', ImageFixtures::png()))
        ->call('store');

    $assets = $page->instance()->assets();

    expect($assets)->toHaveCount(1);

    $asset = $assets[0];
    $html = $page->html();

    foreach ([
        'invariant-fixture.png' => 'the NAME column is gone',
        $asset->contentType => 'the TYPE column is gone',
        number_format($asset->byteSize / 1024, 1).' KB' => 'the SIZE column is gone',
        $asset->width.'x'.$asset->height => 'the DIMENSIONS column is gone',
        "delete('{$asset->id}')" => 'the DELETE action is gone',
        // And the thumbnail itself, which is what the table could not show.
        'preview/asset/'.$asset->id => 'the grid shows no thumbnail',
    ] as $needle => $why) {
        expect(str_contains($html, (string) $needle))->toBeTrue($why);
    }
});
