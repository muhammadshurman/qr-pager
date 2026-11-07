<?php

declare(strict_types=1);

namespace App\Domain\Landing;

/**
 * The theme: an allow-list of token NAMES and a typed validator per token (decision D6).
 *
 * ================================================================================
 * "THEME OVERRIDES TOKENS ONLY, NEVER COMPONENTS" -- WHAT THAT MEANS MECHANICALLY
 * ================================================================================
 *
 * PLAN.md §3.1 row 7 says that clause "names no mechanism" and is therefore not checkable.
 * This task gives it three, and each one can be made to fail:
 *
 *   1. NAMES below is an allow-list. A token the tenant invents is refused, not ignored --
 *      so a theme cannot introduce a property the renderer did not plan for.
 *   2. Every value is validated against a kind (ThemeTokenKind) whose patterns admit no
 *      `;`, no `}`, no `)`, no quote and no whitespace-separated second value. A CSS
 *      declaration cannot be ended early and a second one cannot be appended, so a token
 *      value cannot become a RULE -- which is what "never components" means in CSS terms.
 *   3. `composer guard` rule 11 keeps the list from being copied and keeps CSS out of
 *      every file in app/ but the one emitter, proven on a fixture.
 *
 * ================================================================================
 * THIS FILE IS THE ONLY PLACE A TOKEN NAME MAY APPEAR, AND THE GUARD ENFORCES IT
 * ================================================================================
 *
 * Rule 11 reads NAMES out of this file by parsing it -- never by holding its own copy,
 * which would be the duplicated-list shape QA H-1 cost this project once -- and then
 * refuses any other file under app/ that contains one of these literals.
 *
 * A FONT IS A CHOICE FROM A LIST, NOT A STACK THE TENANT WRITES. STACKS below maps four
 * names to four real font stacks. The tenant's value is one of the four keys, so no
 * tenant-authored string ever reaches a font-family declaration, and no stack here needs
 * a quoted family -- which is what keeps the emitted value free of quotes entirely.
 *
 * In App\Domain, so no framework and no database -- deptrac enforces it.
 */
final class ThemeTokens
{
    /**
     * Every token a tenant may set, and the kind of value each takes.
     *
     * @var array<string, ThemeTokenKind>
     */
    public const NAMES = [
        'color.background' => ThemeTokenKind::Colour,
        'color.surface' => ThemeTokenKind::Colour,
        'color.text' => ThemeTokenKind::Colour,
        'color.muted' => ThemeTokenKind::Colour,
        'color.accent' => ThemeTokenKind::Colour,
        'color.accent_text' => ThemeTokenKind::Colour,
        'font.heading' => ThemeTokenKind::FontChoice,
        'font.body' => ThemeTokenKind::FontChoice,
        'radius.small' => ThemeTokenKind::Length,
        'radius.large' => ThemeTokenKind::Length,
        'space.section' => ThemeTokenKind::Length,
        'size.heading' => ThemeTokenKind::Length,
    ];

    /**
     * The four font choices, and the only stacks that can ever be emitted.
     *
     * No family here needs quoting, deliberately: a quoted family would put a `"` into a
     * CSS declaration that then has to survive HTML attribute escaping, and the cheapest
     * way to be sure of that is to have no quotes at all.
     *
     * @var array<string, string>
     */
    public const STACKS = [
        'system' => 'system-ui, sans-serif',
        'sans' => 'ui-sans-serif, Helvetica, Arial, sans-serif',
        'serif' => 'ui-serif, Georgia, serif',
        'mono' => 'ui-monospace, SFMono-Regular, Menlo, monospace',
    ];

    /** The CSS custom property a token name becomes: `color.accent` -> `--color-accent`. */
    public static function property(string $name): string
    {
        return '--'.str_replace(['.', '_'], '-', $name);
    }

    /** Whether $name is a token at all. */
    public static function knows(string $name): bool
    {
        return array_key_exists($name, self::NAMES);
    }

    /**
     * Is $value a legal value for the token $name?
     *
     * Returns false for an unknown name, so a caller cannot accidentally validate a token
     * that does not exist into existence.
     */
    public static function accepts(string $name, mixed $value): bool
    {
        if (! self::knows($name) || ! is_string($value)) {
            return false;
        }

        return match (self::NAMES[$name]) {
            // Lower-case hex only. NOT rgb(), hsl(), color-mix(), var() or a named colour:
            // every one of those carries a parenthesis or a keyword, and the whole point of
            // the pattern is that a value cannot contain a function call.
            ThemeTokenKind::Colour => preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $value) === 1,
            // A number and a unit, nothing else. The bound is on the TEXT, so an absurd
            // value cannot be written at all rather than being clamped later.
            ThemeTokenKind::Length => preg_match('/^(?:0|[1-9][0-9]{0,3})(?:\.[0-9]{1,2})?(?:px|rem|em|%)$/', $value) === 1,
            ThemeTokenKind::FontChoice => array_key_exists($value, self::STACKS),
        };
    }

    /**
     * The CSS value a validated token emits.
     *
     * A font choice becomes its stack; everything else is already its own CSS value.
     */
    public static function cssValue(string $name, string $value): string
    {
        return self::NAMES[$name] === ThemeTokenKind::FontChoice
            ? self::STACKS[$value]
            : $value;
    }
}
