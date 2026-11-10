<?php

declare(strict_types=1);

namespace App\Features\Landing\Panel;

use App\Domain\Landing\BlockSchema;
use App\Domain\Landing\BlockType;
use App\Domain\Landing\Field;
use App\Domain\Landing\FieldKind;
use App\Domain\Landing\LandingTemplate;
use App\Domain\Landing\ThemeTokenKind;
use App\Domain\Landing\ThemeTokens;
use App\Features\Landing\LandingPages;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The builder's form, GENERATED FROM BlockSchema (§6.2, decision D4).
 *
 * ================================================================================
 * NOTHING HERE DECIDES WHAT A BLOCK CONTAINS
 * ================================================================================
 *
 * Every block, every field, every bound and every label comes out of `BlockSchema` and
 * `ThemeTokens`. This class only decides which Filament COMPONENT renders each field
 * kind. So a field added to the schema appears in the panel, in the generated JSON Schema
 * and in the validator at once, and there is no second list anywhere to forget.
 *
 * ⚠ THE FORM IS NOT THE VALIDATOR, AND MUST NOT BE READ AS ONE. Filament's `required()`
 * and `maxLength()` here are affordances -- they make the panel usable and they tell the
 * tenant what the bound is before they hit save. The authority is
 * `LandingDocument::fromInput()`, server-side, which runs on every save and again on
 * every publish, and which is what the fuzz suite drives. A control the client can skip
 * is not a control (§11.4), and this file is the client.
 *
 * ================================================================================
 * `data` AND `fields`, AND WHY THE TRANSLATION IS HERE
 * ================================================================================
 *
 * Filament's Builder stores each block as `['type' => …, 'data' => [...]]`; the document
 * contract says `fields`. Rather than bend either -- renaming the contract's key to suit
 * a form library would put a panel concern into a document Task 11 consumes -- the
 * translation lives in this class, in two methods, next to each other.
 */
final class StudioForm
{
    /**
     * The whole builder: the arrangement, blocks, theme and SEO.
     *
     * @return list<Component>
     */
    public static function schema(MediaOptions $media): array
    {
        return [
            /*
             * ================================================================================
             * THE ARRANGEMENT, FIRST, AND IT IS A DOCUMENT MEMBER — QRPAGER-33
             * ================================================================================
             *
             * ⚠ IN THIS FORM AND NOT BESIDE IT, WHICH IS THE OPPOSITE OF `tenants.language`
             * (`QRPAGER-30`), AND THE TWO ARE NOT INCONSISTENT. That one is a COLUMN, so a
             * field inside this schema would have ridden into `toDocument()` as a member
             * nothing validates. `template` IS a member of the landing document, so this form
             * is exactly where it belongs: `toDocument()` carries it, `BlockSchema` validates
             * it, and the publisher reads it off the document with no extra database read.
             *
             * ⚠ FIRST, because it changes what every block below it does. A control that
             * reorders the whole page, placed under the blocks it reorders, reads as a detail.
             *
             * `Radio` and not `Select`: there are two options with sentence-long labels, and
             * `§12`'s zero-technical-background bar is about the LABELS rather than the widget
             * -- a reader has to see what the choice means without opening a menu. The options
             * come from the enum's own `cases()`, and the label from `LandingTemplate::label()`
             * in the panel's locale, so a third arrangement adds a button here with no edit.
             */
            Radio::make('template')
                ->label(__('studio.form.template_label'))
                ->helperText(__('studio.form.template_help'))
                ->options(collect(LandingTemplate::cases())
                    ->mapWithKeys(static fn (LandingTemplate $t): array => [
                        $t->value => $t->label(app()->getLocale()),
                    ])
                    ->all())
                // ⚠ NOT `->required()`. A document stored before this feature has no
                // `template`, and `fromDocument()` fills the DEFAULT for it rather than
                // leaving the control empty -- so `required` would be unreachable, and an
                // unreachable validation rule reads as protection that is not there.
                ->default(LandingTemplate::DEFAULT->value),

            Builder::make('blocks')
                ->label(__('studio.form.blocks_label'))
                /*
                 * ⚠ A CLASS SO THE LABEL CAN BE PAINTED AS A SECTION HEADING — `QRPAGER-44`
                 * item A, and it is deliberately NOT a change of element.
                 *
                 * `الكتل` heads the main content of this tab, and `الحالة` above it — a small
                 * status panel — is an `<h2>`. So two things that read as peers are different
                 * elements: one a real heading, one a Filament field label. Muhammad's ruling
                 * of 2026-11-10 is to make them LOOK like the peers they are and to leave the
                 * structure alone: *"the shrink papers over it; the structural fix belongs with
                 * the preset ticket, which rebuilds that region anyway."* `PLAN.md §5` carries
                 * that as a row rather than this comment carrying it alone.
                 *
                 * `extraFieldWrapperAttributes` merges onto the `fi-fo-field` wrapper
                 * (`vendor/filament/forms/resources/views/components/field-wrapper.blade.php`),
                 * which is why the studio theme can reach the label from one class on the
                 * field. A `:has()` on the builder inside would have worked and would have
                 * tied the panel's type scale to Filament's internal DOM; this names the
                 * intent instead.
                 *
                 * ⚠ THE VALUE IS A LITERAL AND IT HAS TO STAY ONE. `field-wrapper.blade.php`
                 * merges this with `escape: false`, and `ComponentAttributeBag::__toString()`
                 * escapes only `"` -- so ANY non-constant passed here is HTML-attribute
                 * injection into the admin panel. The `§11` security round of 2026-11-10 (L-3)
                 * confirmed nothing is exploitable today for exactly that reason, and named
                 * the preset ticket -- which rebuilds this region -- as where a tenant-derived
                 * class would plausibly arrive. It must not arrive through this argument.
                 */
                ->extraFieldWrapperAttributes(['class' => 'studio-section-field'])
                ->blocks(self::blocks($media))
                ->maxItems(BlockSchema::MAX_BLOCKS)
                // §12.1: "block list with reorder". Buttons AND drag, because a
                // drag-only reorder is not keyboard reachable and §12's accessibility
                // bar is not waived for this panel.
                ->reorderable()
                ->reorderableWithButtons()
                ->collapsible()
                // ⚠ COLLAPSED BY DEFAULT SINCE `QRPAGER-40`. A running order is a list of
                // rows the owner reads to find the one he wants; expanded, seven blocks are
                // seven forms and the ORDER -- the thing the numbers are for -- is off the
                // bottom of the screen. Every field is one click away and nothing is lost,
                // which `StudioCapabilityTest` is what proves rather than this comment.
                ->collapsed()
                // ⚠ FILAMENT'S OWN NUMBER IS OFF, OR THE ROW CARRIES TWO. It appends the
                // index to each item's label by default, so with the running order's CSS
                // counter in front the row read *"١  Hero — … 1"*. The counter is the one
                // that stays correct when a row is dragged, so it is the one that stays.
                ->blockNumbers(false)
                ->addActionLabel(__('studio.form.add_block')),

        ];
    }

    /**
     * The `الثيم` tab's schema — theme and search-and-sharing, in that order.
     *
     * ================================================================================
     * ⚠ THESE TWO MOVED OUT OF `schema()` AT `QRPAGER-40`, AND THE MOVE IS DELIBERATE
     * ================================================================================
     *
     * They were two collapsed `Section`s at the bottom of the landing-page form. Muhammad's
     * ticket moves them to their own tab, in his words *"a deliberate move, not a side
     * effect — the Landing page form is for the page's CONTENT, and the theme is not
     * content"*.
     *
     * ⚠⚠ **AND THE MOVE IS WHY `LandingBuilderPage::save()` AND `ThemePage::save()` BOTH
     * MERGE OVER THE STORED DRAFT.** `toDocument()` reads the theme values out of the SAME
     * FLAT STATE as the blocks, by `themeKey()`. Split the schema across two pages without
     * changing how they save, and every save from the page tab writes a document whose
     * `theme` is EMPTY — **a tenant's theme deleted by saving a block, invisible in any
     * screenshot, and silent until they looked at their own site.** Found by reading
     * `toDocument()` before any of this was written, not by a test going red.
     *
     * `StudioDraftMergeTest` is the control, both directions, per tab. Muhammad's ruling:
     * this does not land without that test in the same commit.
     *
     * @return list<Component>
     */
    public static function themeSchema(MediaOptions $media): array
    {
        return [
            Section::make(__('studio.form.theme_section'))
                ->description(__('studio.form.theme_section_help'))
                ->schema(self::theme()),

            Section::make(__('studio.form.seo_section'))
                ->description(__('studio.form.seo_section_help'))
                ->schema(self::seo($media)),
        ];
    }

    /**
     * This page's form state, merged over the STORED draft, as a document.
     *
     * ================================================================================
     * ⚠ THE ONE FUNCTION THAT STOPS A TAB DELETING WHAT ANOTHER TAB OWNS
     * ================================================================================
     *
     * `toDocument()` builds the WHOLE document — blocks, template, theme and seo — from one
     * flat state array. That was correct while one form carried all of it. `QRPAGER-40`
     * split it across two tabs, and a page that passed only its OWN state would write a
     * document with the other tab's members missing: saving a block would delete the theme.
     *
     * So each page reads the stored draft, turns it back into the same flat shape with
     * `fromDocument()`, and lays its own state over the top. The members a page does not
     * carry survive **because they are still in the array**, not because anything special
     * is done for them — which is what makes this robust to a THIRD tab being added later.
     *
     * ⚠ `array_replace` AND NOT `array_merge`: the state is a string-keyed map and
     * `array_merge` would renumber any integer-like key. `blocks` is keyed by ORDER INDEX
     * (`fromDocument()` says why), so `array_merge` would silently reindex a tenant's page.
     *
     * @param  array<string, mixed>  $state  the saving page's own form state
     * @return array<string, mixed>
     */
    public static function merged(string $tenantId, array $state): array
    {
        $stored = self::fromDocument(app(LandingPages::class)->draft($tenantId)->toArray());

        return self::toDocument(array_replace($stored, $state));
    }

    /** @return list<Block> */
    public static function blocks(MediaOptions $media): array
    {
        $blocks = [];

        foreach (BlockType::cases() as $type) {
            $blocks[] = Block::make($type->value)
                /*
                 * ⚠ THE LABEL IS THE BLOCK'S NAME PLUS A ONE-LINE SUMMARY — `QRPAGER-40`
                 * item 4, which asks each row of the running order to carry both.
                 *
                 * The summary is the block's own HEADING when it has one, because that is
                 * what the owner typed and what the row IS: a list of "Hero" seven times
                 * tells him nothing, and he is looking for the row that says *أشهر أطباقنا*.
                 * A block with no heading keeps its type name alone rather than inventing
                 * a summary out of some other field.
                 *
                 * `getStateUsing`-style closures run per item, so this is evaluated against
                 * each row's own state rather than once for the schema.
                 */
                /*
                 * ⚠ TWO SPANS, AND THAT IS ITEM 3's *"truncate the summary, never the number
                 * or the name"* — `QRPAGER-41` part B. One text node cannot be half
                 * truncated: `text-overflow: ellipsis` cuts the END of a box, so a single
                 * string would lose the owner's heading AND, on a narrow row, the block's own
                 * name with it. The name is `flex: 0 0 auto` and keeps its width whatever
                 * happens; only `.studio-block-summary` shrinks. The NUMBER is untouchable by
                 * construction -- it is a CSS counter on `::before`, not text in this string.
                 *
                 * ⚠ `HtmlString`, SO THE TENANT'S HEADING IS ESCAPED HERE AND NOWHERE ELSE.
                 * Returning markup from a label means Blade will not escape it, so `e()` is
                 * applied to the one value that is tenant input and to nothing else. The
                 * block's own name comes from `lang/`, and `Str::limit` still bounds the
                 * heading before it is escaped -- a long heading is a layout problem, not a
                 * safety one, and both are handled rather than one standing in for the other.
                 */
                ->label(static function (?array $state) use ($type): HtmlString {
                    $heading = $state['heading'] ?? null;
                    $name = __('studio.blocks.'.$type->value);

                    if (! is_string($heading) || trim($heading) === '') {
                        return new HtmlString('<span class="studio-block-name">'.e($name).'</span>');
                    }

                    return new HtmlString(
                        '<span class="studio-block-name">'.e($name).'</span>'
                        .'<span class="studio-block-summary">'.e(Str::limit(trim($heading), 60)).'</span>'
                    );
                })
                ->schema(self::fields(BlockSchema::fields($type), $media));
        }

        return $blocks;
    }

    /**
     * @param  array<string, Field>  $fields
     * @return list<Component>
     */
    private static function fields(array $fields, MediaOptions $media): array
    {
        $components = [];

        foreach ($fields as $name => $field) {
            $components[] = self::field($name, $field, $media);
        }

        return $components;
    }

    /**
     * Does a field of this kind hold a LITERAL, or the owner's own prose?
     *
     * ================================================================================
     * ⚠ DIRECTION FOLLOWS THE KIND THE CONTRACT DECLARES, NEVER A CSS SELECTOR
     * ================================================================================
     *
     * Muhammad's ruling of 2026-11-10, generalised past the bug that produced it: *"A
     * selector-shaped rule cannot distinguish a hostname from a sentence; the contract
     * already can."* The first version of `QRPAGER-42` A3 was a stylesheet rule over
     * `.studio-layout__form .fi-input` -- every text field in the form, including `heading`,
     * `seo_title` and the menu item names, which hold ARABIC PROSE. It would have rendered
     * the restaurant's own sentences backwards in the name of fixing a hex code.
     *
     * ⚠ NO DEFAULT ARM, AND THAT IS THE WHOLE POINT OF THE FUNCTION. A lookup with a silent
     * fallback would give a kind added later no mark at all, and the bug would return with
     * nothing failing -- the preset ticket is expected to add exactly that, a field whose
     * value IS a literal. `match` without a default throws `UnhandledMatchError` on an
     * unhandled case and PHPStan reports the match as non-exhaustive, so the next kind
     * cannot ship undecided. `StudioPreviewIsolationTest` iterates every case of both
     * enums and asserts a decision exists for each.
     */
    public static function holdsLiteralValue(FieldKind $kind): bool
    {
        return match ($kind) {
            // Typed or copied character by character; reversed by the bidi algorithm in an
            // RTL document for the same reason a colour value is.
            FieldKind::Url, FieldKind::Email, FieldKind::Telephone, FieldKind::Number => true,
            // A media field's value is an asset chosen from the tenant's own library and
            // shown by its filename, which is a literal on the same grounds.
            FieldKind::Media => true,
            // The owner's own writing. `ItemList` is a container whose CHILDREN are each
            // decided by this same function.
            FieldKind::Text, FieldKind::ItemList => false,
        };
    }

    /** The same decision for a theme token. */
    public static function tokenHoldsLiteralValue(ThemeTokenKind $kind): bool
    {
        return match ($kind) {
            // `1rem`, `12px` -- a magnitude and a unit, read left to right.
            ThemeTokenKind::Length => true,
            // A colour is a literal too, but `ColourField` renders its own markup and marks
            // its caption there; nothing in this function reaches it.
            ThemeTokenKind::Colour => false,
            // A named stack, shown to the owner in his own language.
            ThemeTokenKind::FontChoice => false,
        };
    }

    private static function field(string $name, Field $field, MediaOptions $media): Component
    {
        $label = __('studio.fields.'.$name);
        $literal = self::holdsLiteralValue($field->kind) ? ['class' => 'studio-ltr'] : [];

        /*
         * ⚠ A SECOND MARK, BECAUSE A NON-NATIVE SELECT THROWS THE FIRST ONE AWAY. Filament's
         * `Select` spreads `extraInputAttributes` only in its `$isNative` branch
         * (`Select.php:1925`); the branch this form uses keeps six aria keys and nothing else
         * (`:1979`). The `§11` QA round of 2026-11-10 measured the consequence on the rendered
         * page -- zero `studio-ltr` on any media chooser while the enum test asserted the
         * decision was `true` -- so the mark goes on the WRAPPER, as a marker class only, and
         * `theme.css` isolates the two descendants that hold the filename. The wrapper's own
         * text is the LABEL, which is Arabic prose and must not be touched.
         */
        $literalSelect = self::holdsLiteralValue($field->kind) ? ['class' => 'studio-ltr-select'] : [];

        return match ($field->kind) {
            FieldKind::Text => TextInput::make($name)
                ->label($label)
                ->required($field->required)
                ->maxLength($field->maxLength)
                ->helperText(__('studio.help.text', ['max' => $field->maxLength])),

            /*
              * ⚠ `studio-ltr` MARKS A LITERAL — `QRPAGER-42` A3. A URL, an address, a phone
              * number and a coordinate are read and typed character by character, so each
              * renders LTR and isolated however the document around it runs. The kinds NOT
              * marked are `Text` and `ItemList`, which hold the owner's own Arabic prose:
              * forcing direction there would render his sentences backwards, which is what
              * the first version of the stylesheet rule would have done.
              */
            FieldKind::Url => TextInput::make($name)
                ->label($label)
                ->required($field->required)
                ->extraInputAttributes($literal)
                ->url()
                ->maxLength($field->maxLength)
                ->helperText(__('studio.help.url')),

            FieldKind::Email => TextInput::make($name)
                ->label($label)
                ->required($field->required)
                ->extraInputAttributes($literal)
                ->email()
                ->maxLength($field->maxLength),

            FieldKind::Telephone => TextInput::make($name)
                ->label($label)
                ->required($field->required)
                ->extraInputAttributes($literal)
                ->tel()
                ->helperText(__('studio.help.telephone')),

            FieldKind::Media => Select::make($name)
                ->label($label)
                ->required($field->required)
                // ⚠ `extraAttributes` AND NOT `extraInputAttributes`: see `$literalSelect`.
                ->extraAttributes($literalSelect)
                // The tenant's OWN library, and nothing else can be chosen. The server
                // re-checks the id against media_assets inside this tenant's scope
                // anyway -- a select is a convenience, not the boundary.
                ->options($media->options())
                ->searchable()
                ->native(false)
                ->helperText($media->options() === [] ? self::line('studio.help.media_empty') : null),

            FieldKind::Number => TextInput::make($name)
                ->label($label)
                ->required($field->required)
                ->extraInputAttributes($literal)
                ->numeric()
                ->minValue($field->min)
                ->maxValue($field->max)
                ->helperText(__('studio.help.number', ['min' => $field->min, 'max' => $field->max])),

            FieldKind::ItemList => Repeater::make($name)
                ->label($label)
                ->required($field->required)
                /*
                 * ⚠ ZERO, AND FILAMENT'S DEFAULT IS ONE — `QRPAGER-45`, `§11` QA round H-1.
                 * A seeded item is not an empty list: its own fields are REQUIRED at item
                 * level (`BlockSchema`: *"what is NOT optional is each ITEM being
                 * complete"*), so a block added from the picker was INVALID the moment it
                 * appeared — before the owner had typed anything. `getState()` threw
                 * `ValidationException`, Save was refused, and after `QRPAGER-45` the live
                 * preview silently stopped following them with nothing on screen to say why.
                 *
                 * The list itself is optional by schema (*"a block whose list is empty is a
                 * section the tenant added and has not filled in yet, which is a draft state
                 * and not an error"*), so zero is what that sentence already describes. An
                 * owner adds a step when they want one.
                 *
                 * ⚠ IT CHANGES NO STORED DOCUMENT. Hydration from a document wins over this,
                 * so every existing page keeps exactly the items it has; this is only what a
                 * NEWLY ADDED block starts with.
                 */
                ->defaultItems(0)
                ->schema(self::fields($field->item ?? [], $media))
                ->maxItems($field->maxItems)
                ->reorderable()
                ->reorderableWithButtons()
                ->collapsible()
                // ⚠ COLLAPSED BY DEFAULT SINCE `QRPAGER-40`. A running order is a list of
                // rows the owner reads to find the one he wants; expanded, seven blocks are
                // seven forms and the ORDER -- the thing the numbers are for -- is off the
                // bottom of the screen. Every field is one click away and nothing is lost,
                // which `StudioCapabilityTest` is what proves rather than this comment.
                ->collapsed()
                ->addActionLabel(__('studio.form.add_item')),
        };
    }

    /** @return list<Component> */
    private static function theme(): array
    {
        $components = [];

        foreach (ThemeTokens::NAMES as $name => $kind) {
            // The dots are the contract's; Filament reads a dot as a nested path, so the
            // form holds the token under a flat key and toDocument() puts the dots back.
            $key = self::themeKey($name);

            $components[] = match ($kind) {
                /*
                 * ⚠ A PICKER, NOT A HEX FIELD — `QRPAGER-41` part B item 5, and the helper
                 * line went WITH the field rather than being reworded. It read *"A hex
                 * colour, for example …"*, which only ever existed to teach the owner the
                 * format the control demanded; a control that demands no format needs no
                 * line explaining one. `ColourField` carries the rest of the reasoning.
                 */
                ThemeTokenKind::Colour => ColourField::make($key)
                    ->label(__('studio.tokens.'.$name)),
                ThemeTokenKind::Length => TextInput::make($key)
                    ->label(__('studio.tokens.'.$name))
                    ->extraInputAttributes(self::tokenHoldsLiteralValue($kind) ? ['class' => 'studio-ltr'] : [])
                    ->placeholder('1rem')
                    ->helperText(__('studio.help.length')),
                ThemeTokenKind::FontChoice => Select::make($key)
                    ->label(__('studio.tokens.'.$name))
                    ->options(array_combine(
                        array_keys(ThemeTokens::STACKS),
                        array_map(
                            static fn (string $stack): string => __('studio.fonts.'.$stack),
                            array_keys(ThemeTokens::STACKS),
                        ),
                    ))
                    ->native(false),
            };
        }

        return $components;
    }

    /** @return list<Component> */
    private static function seo(MediaOptions $media): array
    {
        return [
            TextInput::make('seo_title')
                ->label(__('studio.form.seo_title'))
                ->maxLength(BlockSchema::MAX_SEO_TITLE)
                ->helperText(__('studio.help.max_characters', ['max' => BlockSchema::MAX_SEO_TITLE])),
            TextInput::make('seo_description')
                ->label(__('studio.form.seo_description'))
                ->maxLength(BlockSchema::MAX_SEO_DESCRIPTION)
                ->helperText(__('studio.help.max_characters', ['max' => BlockSchema::MAX_SEO_DESCRIPTION])),
            Select::make('seo_og_image')
                ->label(__('studio.form.seo_og_image'))
                ->options($media->options())
                ->searchable()
                ->native(false),
        ];
    }

    /**
     * Filament's form state -> the document contract's shape.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function toDocument(array $state): array
    {
        $blocks = [];

        foreach ((array) ($state['blocks'] ?? []) as $block) {
            if (! is_array($block) || ! is_string($block['type'] ?? null)) {
                continue;
            }

            $blocks[] = [
                'type' => $block['type'],
                'fields' => (array) ($block['data'] ?? []),
            ];
        }

        $theme = [];

        foreach (array_keys(ThemeTokens::NAMES) as $name) {
            $value = $state[self::themeKey($name)] ?? null;

            // An empty field is "not overridden", not "set to empty" -- the page falls
            // back to the template's own default, which is what makes a theme an
            // override rather than a replacement.
            if (is_string($value) && $value !== '') {
                $theme[$name] = $value;
            }
        }

        return [
            'blocks' => $blocks,
            'theme' => $theme,
            // QRPAGER-33. A string, not the enum: `toDocument()` returns the shape
            // `LandingDocument::fromInput()` validates, and validating it is that class's
            // job rather than this form's. An unknown value from a tampered Livewire
            // payload is refused there, by name, before any write.
            'template' => is_string($state['template'] ?? null)
                ? $state['template']
                : LandingTemplate::DEFAULT->value,
            'seo' => [
                'title' => self::blankToNull($state['seo_title'] ?? null),
                'description' => self::blankToNull($state['seo_description'] ?? null),
                'og_image' => self::blankToNull($state['seo_og_image'] ?? null),
            ],
        ];
    }

    /**
     * The document contract's shape -> Filament's form state.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function fromDocument(array $document): array
    {
        $state = [
            'blocks' => [],
            // ⚠ THE DEFAULT FOR A DOCUMENT THAT NAMES NONE, which is every document stored
            // before QRPAGER-33. Without this the control would open empty for an existing
            // tenant, and their first save would then have to choose -- so opening the
            // builder would silently become a decision point about their live page.
            'template' => is_string($document['template'] ?? null)
                ? $document['template']
                : LandingTemplate::DEFAULT->value,
        ];

        foreach ((array) ($document['blocks'] ?? []) as $index => $block) {
            if (! is_array($block)) {
                continue;
            }

            // ⚠ KEYED, NOT A LIST. Filament's Builder stores its items in a MAP whose
            // keys are the item identities Livewire diffs against; a plain list fills as
            // no items at all. The panel's own `mount()` happened to look right because
            // an empty builder renders the same as one that failed to fill, and it was
            // `fillForm()` in StudioActionsTest that made the difference visible --
            // "actual size 0 matches expected size 7".
            //
            // The key is the ORDER INDEX rather than a fresh uuid, so filling the same
            // document twice produces the same state and Livewire re-renders nothing it
            // does not have to. Order is the document's own, which is what the builder's
            // reorder control edits.
            $state['blocks']['block-'.$index] = [
                'type' => $block['type'] ?? null,
                'data' => (array) ($block['fields'] ?? []),
            ];
        }

        foreach (array_keys(ThemeTokens::NAMES) as $name) {
            $state[self::themeKey($name)] = ((array) ($document['theme'] ?? []))[$name] ?? null;
        }

        $seo = (array) ($document['seo'] ?? []);
        $state['seo_title'] = $seo['title'] ?? null;
        $state['seo_description'] = $seo['description'] ?? null;
        $state['seo_og_image'] = $seo['og_image'] ?? null;

        return $state;
    }

    /** `color.accent` -> `theme_color_accent`. Filament reads a dot as a nested path. */
    public static function themeKey(string $name): string
    {
        return 'theme_'.str_replace('.', '_', $name);
    }

    /** `cta_label` -> `Cta label`; `color.accent` -> `Color accent`. */
    /**
     * ⚠ DELETED AT `QRPAGER-41`, AND THE DELETION IS THE POINT.
     *
     * This method was `ucfirst(str_replace(['_', '.'], ' ', $name))` -- every field label in
     * the studio DERIVED from the contract's own field name. That is why the theme tab said
     * *"Color background"*: nobody wrote those words, a `str_replace` did.
     *
     * **A derived label cannot be translated.** There is no string to put in a language file,
     * and a second derivation per language is not a translation -- Arabic does not reach
     * `heading` from `heading` by any transformation. So the names became KEYS, and the
     * lookups are `studio.fields.*` and `studio.tokens.*`.
     *
     * ⚠ AND THE REPLACEMENT HAS A TOTALITY TEST, which the derivation did not need and could
     * not have: `StudioLocalizationTest` requires one key per field in `BlockSchema` and per
     * token in `ThemeTokens::NAMES`, in BOTH language files. A field added later fails there
     * rather than rendering as `studio.fields.whatever` in the panel -- the one failure mode
     * a lookup has that a derivation does not.
     *
     * The method is gone rather than kept as a fallback: a fallback would turn that failure
     * back into an English label appearing silently, which is the defect this ticket exists
     * to remove.
     */
    /**
     * A translated line, as the string it is.
     *
     * ⚠ NOT A CAST TO SILENCE A CHECKER. `__()` is typed `array|string|null` because a key
     * MAY address a nested array of lines -- `studio.tokens` is exactly such a key -- and
     * PHPStan is right to say so. Most Filament setters accept the whole union, so the
     * ordinary `->label(__(…))` calls in this file need nothing; `helperText()` does not,
     * and it is the one place the union has to be resolved.
     *
     * The resolution is a real runtime check with a defined outcome, not an assertion: a key
     * that addresses an array returns the KEY, which renders as `studio.help.media_empty` in
     * the panel and is visible immediately. `StudioLocalizationTest`'s totality case is what
     * makes that outcome unreachable for every key this form actually uses.
     *
     * @param  array<string, mixed>  $replace
     */
    private static function line(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }

    private static function blankToNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
