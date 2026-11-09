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
                ->label('Page arrangement')
                ->helperText('How your blocks are laid out. Publish again to apply a change to your live page.')
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
                ->label('Blocks')
                ->blocks(self::blocks($media))
                ->maxItems(BlockSchema::MAX_BLOCKS)
                // §12.1: "block list with reorder". Buttons AND drag, because a
                // drag-only reorder is not keyboard reachable and §12's accessibility
                // bar is not waived for this panel.
                ->reorderable()
                ->reorderableWithButtons()
                ->collapsible()
                ->addActionLabel('Add a block'),

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
            Section::make('Theme')
                ->description('A theme changes colours, fonts and spacing. It cannot change the layout.')
                ->schema(self::theme()),

            Section::make('Search and sharing')
                ->description('What search engines and social networks show. Structured data is derived from these and is not editable.')
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
                ->label($type->label())
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

    private static function field(string $name, Field $field, MediaOptions $media): Component
    {
        $label = self::label($name);

        return match ($field->kind) {
            FieldKind::Text => TextInput::make($name)
                ->label($label)
                ->required($field->required)
                ->maxLength($field->maxLength)
                ->helperText('Plain text, up to '.$field->maxLength.' characters. One line.'),

            FieldKind::Url => TextInput::make($name)
                ->label($label)
                ->required($field->required)
                ->url()
                ->maxLength($field->maxLength)
                ->helperText('A full https:// address.'),

            FieldKind::Email => TextInput::make($name)
                ->label($label)
                ->required($field->required)
                ->email()
                ->maxLength($field->maxLength),

            FieldKind::Telephone => TextInput::make($name)
                ->label($label)
                ->required($field->required)
                ->tel()
                ->helperText('Digits, spaces and hyphens, with an optional leading +.'),

            FieldKind::Media => Select::make($name)
                ->label($label)
                ->required($field->required)
                // The tenant's OWN library, and nothing else can be chosen. The server
                // re-checks the id against media_assets inside this tenant's scope
                // anyway -- a select is a convenience, not the boundary.
                ->options($media->options())
                ->searchable()
                ->native(false)
                ->helperText($media->options() === [] ? 'Upload an image in the media library first.' : null),

            FieldKind::Number => TextInput::make($name)
                ->label($label)
                ->required($field->required)
                ->numeric()
                ->minValue($field->min)
                ->maxValue($field->max)
                ->helperText('Between '.$field->min.' and '.$field->max.'.'),

            FieldKind::ItemList => Repeater::make($name)
                ->label($label)
                ->required($field->required)
                ->schema(self::fields($field->item ?? [], $media))
                ->maxItems($field->maxItems)
                ->reorderable()
                ->reorderableWithButtons()
                ->collapsible()
                ->addActionLabel('Add'),
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
                ThemeTokenKind::Colour => TextInput::make($key)
                    ->label(self::label($name))
                    ->placeholder('#1a1a1a')
                    ->helperText('A hex colour, for example #1a1a1a.'),
                ThemeTokenKind::Length => TextInput::make($key)
                    ->label(self::label($name))
                    ->placeholder('1rem')
                    ->helperText('A number and a unit: px, rem, em or %.'),
                ThemeTokenKind::FontChoice => Select::make($key)
                    ->label(self::label($name))
                    ->options(array_combine(
                        array_keys(ThemeTokens::STACKS),
                        array_map(self::label(...), array_keys(ThemeTokens::STACKS)),
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
                ->label('Page title')
                ->maxLength(BlockSchema::MAX_SEO_TITLE)
                ->helperText('Up to '.BlockSchema::MAX_SEO_TITLE.' characters.'),
            TextInput::make('seo_description')
                ->label('Description')
                ->maxLength(BlockSchema::MAX_SEO_DESCRIPTION)
                ->helperText('Up to '.BlockSchema::MAX_SEO_DESCRIPTION.' characters.'),
            Select::make('seo_og_image')
                ->label('Sharing image')
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
    private static function label(string $name): string
    {
        return ucfirst(str_replace(['_', '.'], ' ', $name));
    }

    private static function blankToNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
