<?php

declare(strict_types=1);

namespace App\Features\Landing\Panel;

use App\Features\Landing\MediaAssetRow;
use Filament\Forms\Components\Field;
use Filament\Support\Components\Contracts\HasEmbeddedView;

/**
 * The logo slot — `QRPAGER-47` Part B item 2.
 *
 * ================================================================================
 * ⚠⚠ IT IS A DROP ZONE OVER THE MEDIA LIBRARY'S OWN UPLOAD, AND BOTH HALVES WERE RULED
 * ================================================================================
 *
 * Muhammad's ticket: *"Do NOT use Filament's FileUpload — it writes bytes to the object store
 * without passing InspectedImage, which removes a Task 7 security control. Use the path the
 * media library already uses."* His ruling of 2026-11-12, after the artboard arrived:
 * *"There was no contradiction: the artboard specifies the affordance, the brief specifies
 * the pipe. The asset lands in the tenant's library, so one upload serves both."*
 *
 * So the markup here is the affordance — a `<label>`-based drop zone, exactly the one
 * `studio/media.blade.php` already serves and the `§11` rounds already reviewed — and the
 * bytes travel `ThemePage::updatedLogoUpload()` → `MediaLibrary::store()` →
 * `InspectedImage`. There is no second upload path in this product and this field does not
 * add one.
 *
 * ⚠ **A CHOOSER WAS REJECTED ON `§12` GROUNDS, WHICH IS WORTH KEEPING:** *"an owner setting
 * up their page has the logo on their desktop, not in a library they have never opened."* A
 * chooser over existing assets is a small addition on this same wiring if anyone asks.
 *
 * ================================================================================
 * ⚠ REMOVING THE LOGO REMOVES THE REFERENCE, NOT THE IMAGE
 * ================================================================================
 *
 * The ruling again: *"deleting the logo removes the REFERENCE, not the asset underneath."*
 * So the control clears this document member and the upload stays in the media library, where
 * the owner can delete it deliberately with everything else. The help line says so, because a
 * destructive-sounding word on a screen must say what it actually destroys (`§12`).
 *
 * And the card shows **the file's own name and its real size** — the ruling's other half:
 * *"someone who uploaded the wrong file can see that they did."* A thumbnail alone cannot
 * tell `logo-final.png` from `logo-final-2.png`.
 */
class LogoField extends Field implements HasEmbeddedView
{
    /** @var array<string, MediaAssetRow> */
    protected array $assets = [];

    /** The tenant's library, loaded once per form build by `MediaOptions`. */
    public function library(MediaOptions $media): static
    {
        $this->assets = $media->rows();

        return $this;
    }

    /**
     * ⚠ NO DERIVED LABEL, AND THE EMPTY STRING IS THE WHOLE POINT.
     *
     * `Filament\Forms\Components\Field::getLabel()` falls back to `getDefaultLabel()`
     * whenever the label is not `filled()`, and that default is the FIELD NAME turned into
     * words — so `theme_color_background` became the sr-only, screen-reader-only English
     * sentence *"Theme color background"* inside an Arabic panel. `->label('')` does not stop
     * it, because `''` is not `filled()` either.
     *
     * Returning `''` here makes the wrapper render no label element at all
     * (`@if (filled($label) && $labelSrOnly)`), which is correct for a field that renders its
     * own: a second `<label for>` on the same control would give it two accessible names.
     *
     * Found by `StudioLocalizationTest`, which renders every tab under `ar` and reads the
     * text nodes — the only way a hidden English label is visible at all.
     */
    public function getDefaultLabel(): string
    {
        return '';
    }

    public function toEmbeddedHtml(): string
    {
        $state = is_string($this->getState()) ? $this->getState() : '';
        $asset = $this->assets[strtolower($state)] ?? null;

        $card = $asset === null
            ? '<p class="studio-logo__empty">'.e(__('studio.logo.none')).'</p>'
            : $this->card($asset);

        /*
         * ⚠ THE INPUT IS A PLAIN `<input type="file">` BOUND TO A PAGE PROPERTY, and the
         * reasoning is `MediaLibraryPage`'s rather than this file's: Filament's `FileUpload`
         * would write bytes to the object store itself, with nothing passing
         * `InspectedImage`. `sr-only` rather than `hidden`, so it keeps focus and the ring is
         * drawn on the zone through `:has(:focus-visible)` — `display:none` would take the
         * control out of the tab order, which is `§12`'s keyboard clause.
         *
         * The drop handler is the media page's, verbatim in behaviour: it refuses when
         * disabled, puts the dropped file on the input and fires `change` so Livewire's own
         * upload begins. One affordance, one pipe.
         */
        $zone = <<<'HTML'
            <div class="studio-logo__drop" x-data="{ over: false }"
                 x-on:dragover.prevent="over = true"
                 x-on:dragleave.prevent="over = false"
                 x-on:drop.prevent="
                     over = false;
                     if (!$refs.input.disabled && $event.dataTransfer.files.length > 0) {
                         $refs.input.files = $event.dataTransfer.files;
                         $refs.input.dispatchEvent(new Event('change', { bubbles: true }));
                     }
                 ">
                <input x-ref="input" id="studio-logo-upload" type="file" wire:model="logoUpload"
                       accept="image/png,image/jpeg,image/webp,image/avif,image/svg+xml"
                       aria-describedby="studio-logo-help" class="sr-only" />

                <label for="studio-logo-upload" class="studio-logo__zone"
                       x-bind:data-over="over ? 'true' : 'false'">
                    <span class="studio-logo__zone-title">__ZONE_TITLE__</span>
                    <span class="studio-logo__zone-help">__ZONE_HELP__</span>
                    <span class="studio-logo__zone-formats">__ZONE_FORMATS__</span>
                </label>

                <p id="studio-logo-help" class="studio-logo__help">__HELP__</p>
            </div>
            HTML;

        $zone = str_replace(
            ['__ZONE_TITLE__', '__ZONE_HELP__', '__ZONE_FORMATS__', '__HELP__'],
            [
                e(__('studio.logo.upload')),
                e(__('studio.logo.drag')),
                /*
                 * ⚠ THE MEDIA LIBRARY'S OWN LINE, NOT A SECOND COPY OF IT. The accepted
                 * formats and the size bound are `MediaLibrary`'s, this upload goes through
                 * `MediaLibrary::store()`, and `studio.media.formats` already states them in
                 * both languages — including the real 5 MB bound, where the artboard's
                 * indicative `2 MB` would have been wrong.
                 */
                e(__('studio.media.formats')),
                e(__('studio.logo.reference_only')),
            ],
            $zone,
        );

        return $this->wrapEmbeddedHtml(<<<HTML
            <div class="studio-logo">
                <div class="studio-logo__current">{$card}</div>
                {$zone}
            </div>
            HTML);
    }

    /** The current logo: a preview, the file's own name, its real size, and one way out. */
    private function card(MediaAssetRow $asset): string
    {
        $name = e($asset->originalName);
        $meta = e(__('studio.logo.file_meta', [
            'type' => $this->shortType($asset->contentType),
            'size' => $this->kilobytes($asset->byteSize),
        ]));
        $remove = e(__('studio.logo.remove'));

        return <<<HTML
            <div class="studio-logo__card">
                <span class="studio-logo__mark" aria-hidden="true"></span>
                <span class="studio-logo__meta">
                    <span class="studio-logo__name">{$name}</span>
                    <span class="studio-logo__file">{$meta}</span>
                    <button type="button" class="studio-logo__remove" wire:click="removeLogo">{$remove}</button>
                </span>
            </div>
            HTML;
    }

    /**
     * `image/svg+xml` → `SVG`. The owner's word for their file, not the wire's.
     *
     * No map and no match: the subtype after the slash, cut at the first `+`, upper-cased.
     * `MediaLibrary` already refuses every type that is not in its own allow-list, so this
     * cannot be handed something arbitrary — and a list here would be a second copy of that
     * allow-list, which `composer guard` rule 11c exists to stop.
     */
    private function shortType(string $contentType): string
    {
        $subtype = explode('/', $contentType)[1] ?? $contentType;

        return strtoupper(explode('+', $subtype)[0]);
    }

    /**
     * Bytes as whole kilobytes, never zero.
     *
     * A 400-byte SVG logo is legitimate — it is the shape a hand-written mark takes — and
     * `0 KB` beside a file that exists reads as a broken row rather than as a small file.
     */
    private function kilobytes(int $bytes): string
    {
        return (string) max(1, (int) round($bytes / 1024));
    }
}
