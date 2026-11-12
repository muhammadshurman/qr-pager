<?php

declare(strict_types=1);

namespace App\Features\Landing\Panel;

use App\Domain\Landing\ThemeTokens;
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
     * So the NAME travels and the browser resolves it. `border-strong` is the DEFAULT for a
     * field nobody has given a token to; a row built with `token()` replaces it with that
     * token's own default property, so the swatch shows the colour the page is using rather
     * than a neutral standing in for one.
     */
    protected string $fallbackToken = '--color-border-strong';

    /**
     * The contract token this row edits — `color.text`, and so on.
     *
     * ⚠ IT IS ALSO THE ROW'S OWN ADDRESS IN THE DOM, as `data-token`, which is how the
     * CONTRAST check finds the other colour of its pair without either field knowing the
     * other's Livewire path. Filament derives an element id from the state path and this file
     * must not re-derive it: a second copy of that rule would break silently the day Filament
     * changes it.
     */
    protected string $token = '';

    /**
     * The token this one is READ AGAINST — `QRPAGER-47` Part B item 3.
     *
     * Muhammad's ruling of 2026-11-12: *"accent / accent_text are a PAIR: the contrast check
     * on those two runs between them, not against the page background."* So the pairing is
     * declared per row rather than assumed, and the row says which colour it is judged with.
     */
    protected string $pairedWith = '';

    public function token(string $name): static
    {
        $this->token = $name;

        /*
         * ⚠ AND THE PICKER NOW OPENS ON **THIS TOKEN'S OWN DEFAULT** rather than on a neutral.
         * `QRPAGER-41` opened every swatch on `--color-border-strong` because the field knew
         * no better: the point was *"something that reads as nothing chosen"*. The artboard
         * answers the question properly — each row's swatch shows the colour the page is
         * ACTUALLY using, which for an unset token is its default — and `ThemeTokens` can now
         * say which property holds it. Still a NAME resolved in the browser, so no hex enters
         * panel PHP.
         */
        $this->fallbackToken = ThemeTokens::defaultProperty($name);

        return $this;
    }

    public function pairedWith(string $name): static
    {
        $this->pairedWith = $name;

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
                 *
                 * ⚠⚠ AND `e()`d, WHICH THE `§11` SECURITY ROUND MEASURED RATHER THAN ARGUED.
                 * This bag is merged with `escape: false`, and Laravel's
                 * `ComponentAttributeBag::__toString()` does only
                 * `str_replace('"', '\\"', ...)` — a backslash does NOT neutralise a quote
                 * inside a quoted attribute, so a state of `" x-on:focus=alert(1) z="` closed
                 * this attribute and opened an Alpine one, in a panel whose CSP already
                 * carries `unsafe-inline` (`R-242`). Self-XSS only — the value is the
                 * authenticated owner's own form state and `ThemeTokens::accepts()` stops it
                 * ever being stored — and `QRPAGER-47` multiplied the rows carrying it from
                 * one to six, which is why it is fixed here rather than carried.
                 */
                'value' => $state === '' ? null : e($state),
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
                // The row's address for the contrast check — see `$token`.
                'data-token' => $this->token === '' ? null : $this->token,
            ], escape: false)
            ->merge($this->getExtraAttributes(), escape: false)
            ->merge($this->getAccessibilityAttributes(), escape: false)
            ->class(['studio-colour-input']);

        /*
         * `chosen` is the Livewire state and `picked` is what the input displays. They are
         * separate for the reason the docblock gives: seeding `chosen` from `picked` on load
         * would write an override nobody asked for.
         */
        /*
         * `chosen` is the Livewire state and `picked` is what the input displays. They are
         * separate for the reason the docblock gives: seeding `chosen` from `picked` on load
         * would write an override nobody asked for.
         *
         * ⚠ `advice` IS A TRANSLATED SENTENCE HANDED IN, NOT BUILT IN JAVASCRIPT. The
         * component decides WHETHER to say something; what it says comes from `lang/`, because
         * a string assembled in a render hook is a string no translator can reach.
         */
        $alpine = e(json_encode([
            'statePath' => $statePath,
            'fallbackToken' => $this->fallbackToken,
            'token' => $this->token,
            'pairedWith' => $this->pairedWith,
            // ⚠ 4.5, AND IT IS `§12`'s NUMBER RATHER THAN THIS FILE'S OPINION: *"WCAG AA
            // contrast (AAA on the order ready screen)"*. The ready screen is not settable
            // here at all — Muhammad closed that row on 2026-11-12 — so AA is the whole of
            // what this control has to judge.
            'floor' => 4.5,
            /*
             * ⚠⚠ TWO SENTENCES, ONE PER DIRECTION — `§11` QA ROUND, MEDIUM 3, AND IT WAS A
             * MEASUREMENT: one fixed sentence saying *"darken it a little"* was handed to every
             * row, and for a mid-grey page background against the default ink text the round
             * measured **1.75:1** — where darkening the background LOWERS the ratio further.
             * The ticket asks for a message that *"says what to do"*, so the component picks by
             * comparing the two luminances: a colour becomes more readable by moving AWAY from
             * the one it is read against, which means lighter when it is already the lighter of
             * the pair.
             *
             * ⚠ AND THE TWO VALUES THE ROUND MEASURED ARE DESCRIBED RATHER THAN QUOTED, because
             * `scan-panel.mjs` refused this comment with two `no-raw-color` findings when they
             * were written as hexes — `R-260` happening to a COMMENT for the third time in this
             * repository, and the scanner being right each time: a hex in panel PHP is a design
             * decision made outside the token layer, whether or not anything paints with it.
             */
            'adviceLighten' => __('studio.colours.hard_to_read_lighten'),
            'adviceDarken' => __('studio.colours.hard_to_read_darken'),
        ], JSON_THROW_ON_ERROR));

        $clearLabel = e(__('studio.colours.reset'));
        $defaultLabel = e(__('studio.colours.at_default'));
        /*
         * ⚠ THE ROW'S NAME COMES FROM THE TOKEN AND FROM NOTHING ELSE. An earlier version
         * fell back to `getLabel()`, which PHPStan refused outright — that method returns
         * `Htmlable|string|null` — and the refusal was right for a second reason: this field
         * now reports NO default label (see `getDefaultLabel()`), so the fallback could only
         * ever have produced an empty name while looking like it had one. A `ColourField`
         * built without `->token()` renders no name, which is visible immediately.
         */
        $name = $this->token === '' ? '' : e(__('studio.tokens.'.$this->token));
        $hint = $this->token === '' ? '' : e(__('studio.tokens_help.'.$this->token));

        /*
         * The caption's own first paint. Alpine replaces it with `x-text` the moment it
         * initialises; until then -- and in any server-rendered reading of this page -- the
         * element already says what the picker is set to.
         */
        $caption = $state === '' ? $defaultLabel : e($state);

        /*
         * ⚠⚠ THE WHOLE ROW IS RENDERED HERE, AND THAT IS THE ARTBOARD'S SHAPE RATHER THAN
         * FILAMENT'S. `docs/design/studio/theme-tab.html` draws each colour as one line —
         * swatch, then the name with a sentence under it, then the code, then the way back to
         * the default — and Filament's field wrapper puts a label ABOVE its control. So the
         * name is a `<label for>` inside this markup (which is also what makes the swatch
         * announce its own name), every field is built with `hiddenLabel()`, and the wrapper
         * is left to do what only it can: show a validation error against this path.
         *
         * ⚠⚠ AND THE TICKET'S *"it currently reads reversed"* DOES NOT REPRODUCE ON THIS
         * ELEMENT — MEASURED, NOT ASSUMED, AND THE `§11` QA ROUND IS RIGHT THAT IT HAD TO BE.
         * Item 3 ends *"the code renders LTR-isolated — direction: ltr AND unicode-bidi:
         * isolate, both halves, per QRPAGER-42. It currently reads reversed."* Both halves are
         * already on `.studio-colour__value` (`theme.css`, untouched by this ticket), and
         * `browser/studio.spec.ts`'s *"renders a colour value with the hash first — A3"* holds
         * them there by PER-CHARACTER GEOMETRY in a real RTL engine — `#` must sit left of the
         * first digit, which on the build that was broken sat 42px to its right. That case
         * passes at this head.
         *
         * The first attempt here added a redundant `studio-ltr` class to this element, which
         * changed nothing and claimed to change something; it is removed. If the reversal is
         * real on another screen, the element has to be named before it can be fixed —
         * `§0`'s stop-and-report rather than a second no-op.
         *
         * ⚠ AND THE PICKER IS STILL `<input type="color">`. The artboard draws a picker panel
         * OPEN — a saturation field, a hue rail, a code box, five presets — because a static
         * HTML mock cannot show the operating system's own picker. `QRPAGER-41` chose the
         * native element deliberately (*"a JavaScript widget with a text field in it is a
         * different control that still shows the owner a hex"*) and this ticket's brief
         * reaffirms it: *"a swatch that opens a real picker"*. What the mock puts inside that
         * panel — the code, the way back, the contrast reading — is on the row instead, where
         * it is visible without opening anything.
         */
        return $this->wrapEmbeddedHtml(<<<HTML
            <div class="studio-colour" x-data="studioColour({$alpine})" x-on:input.window="judge()">
                <input type="color" {$attributes->toHtml()} />

                <span class="studio-colour__text">
                    <label class="studio-colour__name" for="{$id}">{$name}</label>
                    <span class="studio-colour__hint">{$hint}</span>
                    <span class="studio-colour__advice" role="status" x-show="advice" x-cloak
                          x-text="advice"></span>
                </span>

                <output for="{$id}" class="studio-colour__value" x-text="chosen || '{$defaultLabel}'">{$caption}</output>

                <button type="button" class="studio-colour__clear" x-show="chosen" x-cloak
                        x-on:click="clear()">{$clearLabel}</button>

                <span class="studio-colour__default" x-show="! chosen">{$defaultLabel}</span>
            </div>
            HTML);
    }
}
