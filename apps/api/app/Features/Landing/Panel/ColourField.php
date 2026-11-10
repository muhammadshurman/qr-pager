<?php

declare(strict_types=1);

namespace App\Features\Landing\Panel;

use Filament\Forms\Components\Field;
use Filament\Support\Components\Contracts\HasEmbeddedView;
use Filament\Support\View\ComponentAttributeBag as FilamentComponentAttributeBag;

/**
 * A colour the restaurant owner picks, not a hex code he types. `QRPAGER-41` part B item 5.
 *
 * ================================================================================
 * THE ACCEPTANCE TEST IS A SENTENCE, AND IT IS MUHAMMAD'S
 * ================================================================================
 *
 * *"A restaurant owner does not know what a hex code is — that sentence is the acceptance
 * test."* Six `TextInput`s, each with a six-digit hex as its placeholder and a helper line
 * offering the same hex as an example, asked him to know one six times over -- on the tab
 * whose entire purpose is choosing colours. `<input type="color">` opens the operating system's
 * own picker, which needs no vocabulary at all.
 *
 * ================================================================================
 * ⚠ WHY NOT FILAMENT'S `ColorPicker`
 * ================================================================================
 *
 * It exists, and it is a JavaScript widget with a text field in it -- a different control
 * that still shows the owner a hex. The ticket names the element: *"`<input type="color">`
 * with the hex shown as a read-only caption beside it"*. The native input is also the one
 * that needs no asset, no Alpine component and no focus management of its own, which is
 * worth something in a panel whose accessibility bar is not waived.
 *
 * ================================================================================
 * ⚠ EMPTY IS A VALUE HERE, AND A NATIVE COLOUR INPUT CANNOT HOLD IT
 * ================================================================================
 *
 * A theme token is an OVERRIDE: `StudioForm::toDocument()` keeps a value only when it is a
 * non-empty string, so "unset" means *use the arrangement's own colour*. `<input
 * type="color">` has no empty state -- with nothing in it the element reports pure black --
 * which would silently turn every untouched field into an override of black the first time
 * the owner pressed Save.
 *
 * So the input is NOT bound directly to the state. Alpine holds the picker's own value,
 * seeded from the state when there is one and from the fallback when there is not, and the
 * state is written only by an actual `input` event -- i.e. only when the owner picks. The
 * CLEAR action writes the empty string back, which is how an override is removed, and it is
 * rendered only when there is something to clear.
 *
 * The caption is `<output>`: read-only by element rather than by attribute, associated with
 * the input it reports on, and announced as a live region by a screen reader when it changes.
 */
class ColourField extends Field implements HasEmbeddedView
{
    /**
     * ⚠ THE FALLBACK IS A TOKEN NAME, NOT A HEX, AND THAT IS NOT PEDANTRY.
     *
     * `<input type="color">` has no empty state -- it reports pure black when it holds
     * nothing -- so the picker needs SOMETHING to open on while the token is unset. The first
     * version of this file carried a mid-grey literal, and `scan-panel.mjs` REFUSED IT: five
     * findings, every one of them in prose rather than in a value, which is `R-260` happening
     * a second time to the same author in the same week. A default colour written into panel
     * PHP is also a design decision made outside the token layer, which is the thing `§12`'s
     * single-source rule exists to stop -- so the scanner was right twice over.
     *
     * So the NAME travels and the browser resolves it. `border-strong` is the right role: it
     * is the neutral that already has a value in both modes, so the picker opens on
     * something that reads as "nothing chosen" in light and in dark without this field
     * knowing which mode it is in.
     */
    protected string $fallbackToken = '--color-border-strong';

    public function toEmbeddedHtml(): string
    {
        $statePath = $this->getStatePath();
        $hasError = $this->hasErrorForPath($statePath);
        $id = $this->getId();

        /** @var string $state */
        $state = is_string($this->getState()) ? $this->getState() : '';

        $attributes = (new FilamentComponentAttributeBag)
            ->merge([
                'disabled' => $this->isDisabled(),
                'id' => $id,
                'x-model' => 'picked',
                'x-on:input' => 'chosen = picked',
                /*
                 * ⚠ SERVER-RENDERED, SO THE CONTROL IS RIGHT BEFORE ALPINE RUNS. Without it
                 * the swatch paints black for one frame on every field -- a colour input
                 * with no value has no other choice -- and an owner whose background is
                 * already set would watch six black squares become his colours.
                 */
                'value' => $state === '' ? null : $state,
                /*
                 * ⚠ `aria-invalid` AND NOT `fi-valid`/`fi-invalid`. The first version copied
                 * those two classes from Filament's `Checkbox`, and `PanelTargetTest` refused
                 * the result: it derives its control set from `fi-`-prefixed classes, so the
                 * element arrived as the bare class `fi-valid`, which nothing in
                 * `targets.css` covers and nothing SHOULD -- a validity state is not a
                 * control, and covering it would put a 44px floor under everything that
                 * carries one.
                 *
                 * So the state is expressed where it belongs. `aria-invalid` is what a
                 * screen reader reads, this field defines no styling for either class, and
                 * the 44px floor comes from `.studio-colour-input` in `targets.css` -- the
                 * same arrangement `[data-media-delete='true']` already uses for a control
                 * that is this panel's rather than Filament's.
                 */
                'aria-invalid' => $hasError ? 'true' : null,
            ], escape: false)
            ->merge($this->getExtraAttributes(), escape: false)
            ->merge($this->getAccessibilityAttributes(), escape: false)
            ->class(['studio-colour-input']);

        /*
         * `chosen` is the Livewire state and `picked` is what the input displays. They are
         * separate for the reason the docblock gives: seeding `chosen` from `picked` on load
         * would write an override nobody asked for.
         */
        $alpine = e(json_encode([
            'statePath' => $statePath,
            'fallbackToken' => $this->fallbackToken,
        ], JSON_THROW_ON_ERROR));

        $clearLabel = e(__('studio.theme.clear_colour'));
        $defaultLabel = e(__('studio.theme.using_default'));

        /*
         * The caption's own first paint. Alpine replaces it with `x-text` the moment it
         * initialises; until then -- and in any server-rendered reading of this page -- the
         * element already says what the picker is set to.
         */
        $caption = $state === '' ? $defaultLabel : e($state);

        return $this->wrapEmbeddedHtml(<<<HTML
            <div class="studio-colour" x-data="studioColour({$alpine})">
                <input type="color" {$attributes->toHtml()} />

                <output for="{$id}" class="studio-colour__value" x-text="chosen || '{$defaultLabel}'">{$caption}</output>

                <button type="button" class="studio-colour__clear" x-show="chosen" x-cloak
                        x-on:click="clear()">{$clearLabel}</button>
            </div>
            HTML);
    }
}
