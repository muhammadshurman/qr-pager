<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * WCAG 2.1 contrast, for the pairs the generated table does not carry.
 *
 * ================================================================================
 * ⚠ WHY A SECOND IMPLEMENTATION EXISTS AT ALL, AND WHAT KEEPS IT HONEST
 * ================================================================================
 *
 * `packages/tokens` owns this formula and publishes its results: `generated/contrast.json`
 * lists 42 pairs with their ratios, computed by `packages/tokens/src/color.ts` under a stated
 * convention — *"WCAG 2.1 relative luminance"*, *"TRUNCATED at two decimals (floored), never
 * rounded up"*. A second implementation of a formula is normally the second source this
 * project refuses.
 *
 * The pair that needs judging here is not in that table and cannot be: the `§11` security
 * round's M-1 is about the FOCUS OUTLINE drawn over `ember`'s inverted band — `--color-accent`
 * over `--color-text` — and those are LANDING CONTRACT tokens whose values a tenant chooses.
 * The generated table is a table of PRODUCT pairs. So the ratio has to be computed from two
 * resolved values at test time.
 *
 * ⚠ WHAT KEEPS IT HONEST IS THAT IT MUST REPRODUCE THE PUBLISHED FIGURES FIRST.
 * `TemplateContrastTest` reads `contrast.json` and requires this class to agree with every
 * pair in it, to two decimals, before it judges a pair the table does not carry. An
 * arithmetic error here cannot therefore pass as a verdict about the page — it fails as a
 * disagreement with the token layer, naming the pair.
 */
final class Contrast
{
    /**
     * The contrast ratio of two sRGB hex colours, floored to two decimals.
     *
     * Floored rather than rounded, because every threshold in this project is a MINIMUM: the
     * convention block in `contrast.json` states it, and rounding up can only overstate
     * compliance.
     */
    public static function ratio(string $foreground, string $background): float
    {
        $first = self::luminance($foreground);
        $second = self::luminance($background);

        $lighter = max($first, $second);
        $darker = min($first, $second);

        return floor((($lighter + 0.05) / ($darker + 0.05)) * 100) / 100;
    }

    /** WCAG 2.1 relative luminance of a `#rgb` or `#rrggbb` colour. */
    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::channels($hex);

        return 0.2126 * self::linear($r) + 0.7152 * self::linear($g) + 0.0722 * self::linear($b);
    }

    /**
     * @return array{float, float, float} each channel as 0..1
     */
    private static function channels(string $hex): array
    {
        $value = ltrim(trim($hex), '#');

        if (strlen($value) === 3) {
            $value = $value[0].$value[0].$value[1].$value[1].$value[2].$value[2];
        }

        if (preg_match('/^[0-9a-f]{6}$/i', $value) !== 1) {
            throw new \InvalidArgumentException("not a hex colour: {$hex}");
        }

        return [
            (int) hexdec(substr($value, 0, 2)) / 255,
            (int) hexdec(substr($value, 2, 2)) / 255,
            (int) hexdec(substr($value, 4, 2)) / 255,
        ];
    }

    /** The sRGB transfer function, as WCAG 2.1 states it. */
    private static function linear(float $channel): float
    {
        return $channel <= 0.04045
            ? $channel / 12.92
            : (($channel + 0.055) / 1.055) ** 2.4;
    }
}
