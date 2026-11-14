<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Landing\ThemeTokenKind;
use App\Domain\Landing\ThemeTokens;

/**
 * The six sentinel colours — `QRPAGER-49A`. The ONE place they are written.
 *
 * ================================================================================
 * WHAT THEY ARE FOR
 * ================================================================================
 *
 * `tools/gate/bin/theme-paint-proof` publishes the demo restaurant's document under a theme whose
 * six colour roles hold six values that appear nowhere else in the product, then asks a real
 * browser what every colour-bearing computed style resolved to. A value found on the page that is
 * one of these six can only have come from the theme; anything else is a literal. That is the
 * claim *"the theme reaches the pixel"*, and it needs the sentinels to be (1) distinct, (2) real
 * values the contract accepts — they travel the production path and a validator refuses
 * anything else — (3) present for EVERY colour role, and (4) unique in the product.
 *
 * ⚠ **ALL FOUR ARE ASSERTED, NOT ASSUMED**, by `SentinelThemeTest`. Phase 1 stated (4) as a
 * one-off `grep` result in `PLAN.md`, which holds on the day it is run and says nothing about the
 * day a designer adds `#ff00e4` to a token file. A sentinel that collides with a product colour
 * makes a finding unattributable, and nothing would have said so.
 *
 * ⚠ AND (3) IS WHAT STOPS A SEVENTH ROLE FROM BEING SILENTLY UNMEASURED. The roles are read from
 * `ThemeTokens::NAMES` and compared to this table, so a new colour token added to the contract
 * without a sentinel fails a test here rather than being a picker the harness never exercised —
 * which is the same defect as the dead pickers, one layer up.
 *
 * ⚠ THEY ARE FAR APART IN RGB ON PURPOSE. Six near-neighbours would let a misattributed role —
 * the accent painted where the muted was meant — read as a pass. MEASURED, not asserted by
 * adjective: the closest pair is `color.text` / `color.accent_text` at 105.0 Euclidean RGB
 * distance, then `color.surface` / `color.accent` at 109.1, against 1.0 for a one-digit near
 * miss. (An earlier draft of this note said the six *"share no channel pair"*, which is false —
 * `text` and `accent_text` both have green 0 and blue 255 — and was caught by computing the
 * distances for the test below rather than by anybody reading the sentence.)
 *
 * It lives under `tests/Support` because it is development infrastructure, as
 * `LandingFixtures` and `Contrast` are: `bin/publish-sentinel-theme` and `render-arrangements`
 * both refuse to run outside local/testing and both load it from here.
 */
final class SentinelTheme
{
    /**
     * Role -> hex, in the order the studio's theme tab shows the rows.
     *
     * Lower-case hex, because `ThemeTokens::accepts()` takes lower-case only.
     *
     * @return array<string, string>
     */
    public static function values(): array
    {
        return [
            'color.background' => '#ff00e4',
            'color.surface' => '#00ff95',
            'color.text' => '#1400ff',
            'color.muted' => '#ff7a00',
            'color.accent' => '#00e5ff',
            'color.accent_text' => '#7d00ff',
        ];
    }

    /**
     * The roles in `$contractRoles` that this table has no sentinel for.
     *
     * A pure function of its argument so a test can hand it a contract that has grown — which is
     * the only way to prove the detection works: comparing `colourRoles()` against a list built by
     * the same code (`§11` QA L3) can fail only if that code is rewritten.
     *
     * @param  list<string>  $contractRoles
     * @return list<string>
     */
    public static function uncovered(array $contractRoles): array
    {
        return array_values(array_diff($contractRoles, array_keys(self::values())));
    }

    /**
     * Every colour role the contract defines, read from the contract rather than from here.
     *
     * @return list<string>
     */
    public static function colourRoles(): array
    {
        return array_keys(array_filter(
            ThemeTokens::NAMES,
            static fn (ThemeTokenKind $kind): bool => $kind === ThemeTokenKind::Colour,
        ));
    }
}
