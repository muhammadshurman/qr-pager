<?php

declare(strict_types=1);

namespace App\Features\Landing\Panel;

use App\Domain\Landing\LandingTemplate;
use Filament\Forms\Components\Field;
use Filament\Support\Components\Contracts\HasEmbeddedView;

/**
 * The three themes as CARDS — `QRPAGER-47` Part B item 1.
 *
 * ================================================================================
 * WHY A FIELD OF ITS OWN AND NOT A `Radio` WITH LABELS
 * ================================================================================
 *
 * The control it replaces was `Radio::make('template')` on the **page** tab, whose options
 * were one sentence each. Muhammad's ticket moves the choice here and changes what it has to
 * show: *"Each thumbnail differs STRUCTURALLY, not by colour — a ruled column, a dark centred
 * hero, a photo grid. The current one is marked in words."*
 *
 * A radio with a sentence beside it cannot do that, and a radio with an IMAGE beside it would
 * need three images — three files to keep in step with three arrangements, which is the
 * duplicated-list shape this repository has paid for twice. So each thumbnail is **markup
 * plus CSS**: a few `<i>` elements this file names structurally, laid out by
 * `resources/css/filament/studio/theme.css`, carrying no colour and no size of their own.
 *
 * ⚠ **THE THUMBNAIL IS A PICTURE OF THE ARRANGEMENT'S STRUCTURE, AND THE CARDS ARE WHY
 * `QRPAGER-46`'s WORK WAS WORTH DOING.** Rail is a ruled stack; ember is a dark band with
 * centred type over a narrow row; tile is a grid of equal cells. The same three facts
 * `TemplateStructureTest` asserts about the rendered page, drawn at 104 pixels.
 *
 * ================================================================================
 * ⚠ THE BADGE MARKS WHAT IS **STORED**, NOT WHAT IS SELECTED
 * ================================================================================
 *
 * *"The current one is marked in words"* — and the honest reading of *current* is the
 * arrangement the saved draft names, not the card the owner has just clicked. The preview
 * follows a click immediately (`KeepsPreviewInStep`); the stored document does not, until
 * Save. So after clicking `جَمر` the owner sees ember in the preview and `مستخدم الآن` still
 * on `السِجِل` — which is the truth, and the only thing on screen that distinguishes *looking*
 * from *having changed*.
 *
 * ================================================================================
 * ⚠ ACCESSIBILITY, AND WHAT THE `§12` BAR ACTUALLY REQUIRES HERE
 * ================================================================================
 *
 * The radio is a REAL radio and is never `display:none` — it is clipped to a pixel by
 * `.sr`-style positioning so it keeps focus, keyboard semantics and the grouping a screen
 * reader announces. The visible card is the `<label>`, so the touch target is the whole card,
 * far above `§12`'s 44px floor, and the focus ring is drawn on the card through
 * `:has(:focus-visible)` rather than on the one-pixel input nobody can see.
 *
 * The thumbnail is `aria-hidden` and the name and sentence carry the meaning, because a
 * screen-reader user choosing a theme needs the words rather than a description of six bars.
 */
class ArrangementField extends Field implements HasEmbeddedView
{
    /**
     * The arrangement the STORED draft names, for the badge. Null means *do not mark one* —
     * which is what a caller that has not read the draft must pass rather than guessing.
     */
    protected ?string $stored = null;

    public function stored(?string $value): static
    {
        $this->stored = $value;

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
        $statePath = $this->getStatePath();
        $locale = app()->getLocale();
        $name = e($statePath);
        $cards = '';

        foreach (LandingTemplate::cases() as $case) {
            $cards .= $this->card($case, $locale, $name);
        }

        /*
         * ⚠ THE NOTE IS PART OF THE CONTROL, NOT HELPER TEXT ABOVE IT. The ticket asks for
         * *"switching a theme never touches the owner's text or images, and the screen says
         * so"* — a sentence an owner reads at the moment of hesitating, which is after the
         * cards and not before them.
         */
        $note = e(__('studio.arrangements.note'));

        /*
         * ⚠ A `<fieldset>` WITH A SCREEN-READER-ONLY `<legend>`, which is what a radio group
         * IS — and the reason this field renders its own rather than taking Filament's
         * wrapper label: that label is a `<label for="{id}">`, and no single element here
         * carries the field's id, so it would point at nothing. A legend names the group for
         * the one reader who cannot see that the cards are a choice.
         */
        $legend = e(__('studio.arrangements.section'));

        return $this->wrapEmbeddedHtml(<<<HTML
            <fieldset class="studio-arrangements" x-data="{ chosen: \$wire.\$entangle('{$name}') }">
                <legend class="sr-only">{$legend}</legend>
                <div class="studio-arrangements__grid">{$cards}</div>
                <p class="studio-arrangements__note">{$note}</p>
            </fieldset>
            HTML);
    }

    /** One card: the radio, the structural thumbnail, the name, the badge, the sentence. */
    private function card(LandingTemplate $case, string $locale, string $name): string
    {
        $value = e($case->value);
        $title = e($case->name($locale));
        $summary = e($case->description($locale));

        $badge = $this->stored === $case->value
            ? '<span class="studio-arrangement__badge">'.e(__('studio.arrangements.in_use')).'</span>'
            : '';

        $disabled = $this->isDisabled() ? ' disabled' : '';

        return <<<HTML
            <label class="studio-arrangement" :class="chosen === '{$value}' && 'studio-arrangement--chosen'">
                <input type="radio" class="studio-arrangement__input" name="{$name}"
                       value="{$value}" x-model="chosen"{$disabled} />
                <span class="studio-arrangement__thumb" aria-hidden="true">{$this->thumb($case)}</span>
                <span class="studio-arrangement__body">
                    <span class="studio-arrangement__head">
                        <span class="studio-arrangement__name">{$title}</span>
                        {$badge}
                    </span>
                    <span class="studio-arrangement__summary">{$summary}</span>
                </span>
            </label>
            HTML;
    }

    /**
     * The structure, as elements. No colour, no size, no literal of any kind.
     *
     * ⚠ `match` WITH NO `default`, so a fourth arrangement does not compile until somebody
     * decides what it looks like. That is the same mechanism `label()` uses on the enum and
     * the reason a new case cannot ship with an empty card.
     */
    private function thumb(LandingTemplate $case): string
    {
        return match ($case) {
            // A ruled stack: title, strapline, one hairline, two priced rows, a dark call to
            // action at the foot — the ledger, which is what `rail.blade.php` renders.
            LandingTemplate::Rail => '<i class="studio-thumb__bar studio-thumb__bar--title"></i>'
                .'<i class="studio-thumb__bar studio-thumb__bar--sub"></i>'
                .'<i class="studio-thumb__rule"></i>'
                .'<span class="studio-thumb__row"><i></i><i></i></span>'
                .'<span class="studio-thumb__row"><i></i><i></i></span>'
                .'<i class="studio-thumb__cta"></i>',

            // A dark band with centred type, then a narrow row under it.
            LandingTemplate::Ember => '<span class="studio-thumb__hero">'
                .'<i class="studio-thumb__bar studio-thumb__bar--title"></i>'
                .'<i class="studio-thumb__bar studio-thumb__bar--sub"></i>'
                .'</span>'
                .'<span class="studio-thumb__strip"><i></i><i></i><i></i></span>',

            // A heading and a grid of equal cells.
            LandingTemplate::Tile => '<i class="studio-thumb__bar studio-thumb__bar--title"></i>'
                .'<span class="studio-thumb__grid"><i></i><i></i><i></i><i></i></span>',
        };
    }
}
