<?php

declare(strict_types=1);

namespace App\Features\Landing\Panel;

use App\Domain\Landing\BlockSchema;
use App\Domain\Landing\LandingDocumentInvalid;
use Illuminate\Support\Facades\Lang;

/**
 * ================================================================================
 * WHAT THE OWNER IS TOLD WHEN A FIELD WILL NOT PUBLISH — `R-262`
 * ================================================================================
 *
 * `LandingDocumentInvalid` carries two values built for a PROGRAMMER: a dotted path like
 * `seo.og_image` or `blocks.3.fields.items.0.image`, and an English sentence like *"names an
 * image that is not in your library"*. Three places put that pair in front of the owner
 * verbatim — the publish refusal, the preview refusal and the theme tab's save — so an
 * Arabic-speaking restaurant owner read:
 *
 *     «seo.og_image لا يمكن نشره: names an image that is not in your library»
 *
 * Muhammad's walkthrough of PR #22 found it in use rather than in the file, which is how
 * `R-262` went from recorded to visible: *"that message renders a raw English reason and a
 * raw field path to the owner."*
 *
 * ================================================================================
 * ⚠⚠ WHAT THIS DOES AND DOES NOT TRANSLATE — STATED, BECAUSE IT IS NOT UNIFORM
 * ================================================================================
 *
 * **The FIELD is fully resolved.** The path space is structured and closed — `logo`,
 * `seo.*`, `theme.*`, `blocks.{n}[.fields[.{name}…]]` — so every path this can receive maps
 * to a TAB and a human field name, and that is the half `§12` is most specific about: *"say
 * WHICH tab and WHICH field."*
 *
 * **The REASON is NOT fully translated, and this is deliberate rather than unfinished.**
 * The domain emits 37 distinct reasons and 10 of them interpolate a value at the throw site
 * (*"is {$length} characters; the limit is {$maxLength}"*), so an exact-prose lookup cannot
 * cover them and a table keyed on English prose would rot the first time a sentence is
 * reworded. What this guarantees instead is the property the owner cares about: **they never
 * see the English.** A named set of reasons gets a specific Arabic sentence; everything else
 * gets a correct general one plus the next action. The raw reason stays on the exception for
 * logs and tests.
 *
 * Fixing that properly means stable codes from `app/Domain/Landing`, which is a refactor
 * across 37 throw sites in the layer that may not import a translator — `R-262`'s own note
 * now says so.
 */
final class InvalidField
{
    /**
     * The whole owner-facing sentence: which tab, which field, what is wrong, what to do.
     */
    public static function sentence(LandingDocumentInvalid $invalid): string
    {
        return __('studio.invalid.sentence', [
            'field' => self::label($invalid->field),
            'tab' => self::tab($invalid->field),
            'why' => self::why($invalid->reason),
        ]);
    }

    /**
     * The tab an owner has to open to fix this field, in their own language.
     *
     * ⚠ THE TAB AND NOT THE PATH. An owner has four tabs and no idea what `seo.og_image` is;
     * naming the tab is the difference between a message and an instruction.
     */
    public static function tab(string $field): string
    {
        $head = explode('.', $field)[0];

        return match ($head) {
            'logo', 'theme' => __('studio.tabs.theme'),
            default => __('studio.tabs.page'),
        };
    }

    /** The field, named the way the panel names it. */
    public static function label(string $field): string
    {
        $parts = explode('.', $field);
        $head = $parts[0];

        if ($head === 'logo') {
            return __('studio.invalid.field.logo');
        }

        if ($head === 'seo') {
            $leaf = $parts[1] ?? '';

            return Lang::has('studio.invalid.field.seo.'.$leaf)
                ? __('studio.invalid.field.seo.'.$leaf)
                : __('studio.invalid.field.seo.self');
        }

        if ($head === 'theme') {
            return __('studio.invalid.field.theme');
        }

        if ($head !== 'blocks') {
            return __('studio.invalid.field.page');
        }

        // `blocks`, `blocks.3`, `blocks.3.fields`, `blocks.3.fields.heading`, and the item
        // forms below those. An owner counts blocks from one, not from zero.
        if (! isset($parts[1]) || ! ctype_digit($parts[1])) {
            return __('studio.invalid.field.blocks');
        }

        $position = ((int) $parts[1]) + 1;
        $name = $parts[3] ?? null;

        if (! is_string($name) || $name === '') {
            return __('studio.invalid.field.block', ['position' => $position]);
        }

        return __('studio.invalid.field.block_field', [
            'position' => $position,
            'name' => self::fieldName($name),
        ]);
    }

    /**
     * A field's own name, from the schema's label when it has one.
     *
     * Falls back to the raw key rather than to nothing: a key is a poor label and a better
     * one than silence, and `BlockSchema` is where the panel's own labels come from, so the
     * two cannot drift.
     */
    private static function fieldName(string $name): string
    {
        return Lang::has('studio.fields.'.$name) ? __('studio.fields.'.$name) : $name;
    }

    /**
     * What is wrong, in the owner's language.
     *
     * ⚠ MATCHED ON A NAMED SET, AND THE REST FALL BACK. The reasons are English prose built
     * for a programmer and ten of them interpolate numbers, so this recognises the ones an
     * owner actually meets and answers everything else with a true general sentence. It
     * never returns the English.
     */
    private static function why(string $reason): string
    {
        return match (true) {
            str_contains($reason, 'not in your library') => __('studio.invalid.why.missing_image'),
            str_contains($reason, 'is required') => __('studio.invalid.why.required'),
            str_contains($reason, 'the limit is'),
            str_contains($reason, 'is longer than'),
            str_contains($reason, 'at most') => __('studio.invalid.why.too_long'),
            str_contains($reason, 'web address'),
            str_contains($reason, 'https://'),
            str_contains($reason, 'valid host') => __('studio.invalid.why.bad_address'),
            str_contains($reason, 'email address') => __('studio.invalid.why.bad_email'),
            str_contains($reason, 'telephone') => __('studio.invalid.why.bad_phone'),
            str_contains($reason, 'at least one item') => __('studio.invalid.why.needs_item'),
            default => __('studio.invalid.why.unusable'),
        };
    }
}
