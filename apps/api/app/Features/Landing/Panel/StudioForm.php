<?php

declare(strict_types=1);

namespace App\Features\Landing\Panel;

use App\Domain\Landing\BlockSchema;
use App\Domain\Landing\BlockType;
use App\Domain\Landing\Field;
use App\Domain\Landing\FieldKind;
use App\Domain\Landing\ThemeTokenKind;
use App\Domain\Landing\ThemeTokens;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
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
     * The whole builder: blocks, theme and SEO.
     *
     * @return list<Component>
     */
    public static function schema(MediaOptions $media): array
    {
        return [
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

            Section::make('Theme')
                ->description('A theme changes colours, fonts and spacing. It cannot change the layout.')
                ->schema(self::theme())
                ->collapsed(),

            Section::make('Search and sharing')
                ->description('What search engines and social networks show. Structured data is derived from these and is not editable.')
                ->schema(self::seo($media))
                ->collapsed(),
        ];
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
        $state = ['blocks' => []];

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
