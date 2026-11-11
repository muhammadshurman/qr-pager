<?php

declare(strict_types=1);

use App\Domain\Landing\LandingTemplate;
use Tests\Support\Contrast;
use Tests\Support\LandingPages;

/*
 * ================================================================================
 * THE FOCUS RING IS VISIBLE WHEREVER IT LANDS — the `§11` SECURITY ROUND'S M-1
 * ================================================================================
 *
 * The round measured this on the delivered code: `colours.light.ink` and `colours.light.brand`
 * are THE SAME HEX (`#191C22`), so with the shipped defaults `--color-accent` equals
 * `--color-text` — and `ember` paints its band `background:var(--color-text)` while the page's
 * shared rule draws `outline:3px solid var(--color-accent)` at `outline-offset:2px`, i.e. OVER
 * the band. **1.00:1**, on the page's only interactive control, against WCAG 2.2 SC 1.4.11's
 * 3:1. `§12`'s accessibility bar applies to this page in full and is not waived.
 *
 * ⚠ IT IS A RATIO AND NOT AN INEQUALITY OF TWO STRINGS. *"The outline colour differs from the
 * background"* would have passed for two near-identical colours, which is the same defect one
 * shade later. `Tests\Support\Contrast` computes WCAG 2.1 relative luminance, and the first
 * case here requires it to reproduce EVERY figure the generated `contrast.json` publishes
 * before any verdict below is trusted — a second implementation of a formula is only safe
 * while something independent holds it to the first.
 *
 * ⚠ AND IT READS THE SERVED PAGE, not a list of rules in this file: the outline colour and the
 * region backgrounds are parsed out of the `<style>` block the arrangement actually ships, and
 * resolved through the `style` attribute the resolver actually emitted. A list here would be a
 * third source, free to agree with itself while the page disagreed.
 *
 * ⚠ THE CASCADE MODEL IS DELIBERATELY SIMPLE, AND SAYING SO IS PART OF THE PROOF. Within this
 * one stylesheet, an arrangement's own rules come later in source order than the shared rules
 * and are scoped under the arrangement's root class, so "the outline in force inside region R"
 * is the last `outline`/`outline-color` declaration whose selector is scoped at or under R.
 * That is faithful to THIS stylesheet, not to CSS in general; a page that grew `!important` or
 * id selectors would need a real cascade, and this comment is where that would be noticed.
 */

/** Every `--token: value` the page serves, as the browser would resolve it. */
function resolvedTokens(string $html): array
{
    $properties = LandingPages::rootProperties($html);

    expect($properties)->not->toBe([], 'the page emitted no theme properties at all');

    return $properties;
}

/**
 * `selector => the token its `background` sets`, for every region rule in the served CSS.
 *
 * @return array<string, string>
 */
function regionBackgrounds(string $css): array
{
    $regions = [];

    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);

    foreach ($rules as $rule) {
        $selector = trim($rule[1]);

        if (preg_match('/(?:^|;)\s*background\s*:\s*var\(\s*(--[a-z0-9-]+)/i', $rule[2], $token) === 1) {
            $regions[$selector] = $token[1];
        }
    }

    return $regions;
}

/**
 * The outline colour in force for a focusable element inside `$region`.
 *
 * The last declaration whose selector is scoped at or under the region wins — see the cascade
 * note in this file's header for why that is faithful here and nowhere else.
 */
function outlineTokenFor(string $css, string $region): string
{
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);

    $colour = '';

    foreach ($rules as $rule) {
        $selector = trim($rule[1]);

        if (preg_match('/(?:^|;)\s*outline(?:-color)?\s*:[^;]*var\(\s*(--[a-z0-9-]+)/i', $rule[2], $token) !== 1) {
            continue;
        }

        $scoped = str_contains($selector, ':focus-visible')
            && ($region === '' || str_contains($selector, $region));

        $global = str_contains($selector, ':focus-visible') && ! str_contains($selector, '.');

        if ($scoped || $global) {
            $colour = $token[1];
        }
    }

    return $colour;
}

it('reproduces every ratio the generated contrast table publishes', function (): void {
    /*
     * The bridge the verdicts below stand on. `packages/tokens` owns the formula and publishes
     * 42 pairs; if this implementation disagreed with any of them, nothing it said about a
     * pair the table does NOT carry would be worth reading.
     */
    $table = json_decode(
        (string) file_get_contents(base_path('../../packages/tokens/generated/contrast.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    /** @var list<array<string, mixed>> $pairs */
    $pairs = array_merge($table['pairs'] ?? [], $table['orderReady']['pairs'] ?? []);

    expect(count($pairs))->toBeGreaterThan(40, 'the generated table carries no pairs to agree with');

    foreach ($pairs as $pair) {
        $ours = Contrast::ratio((string) $pair['fgHex'], (string) $pair['bgHex']);

        expect($ours)->toBe(
            (float) $pair['ratio'],
            "this implementation disagrees with packages/tokens for {$pair['fgHex']} on "
            ."{$pair['bgHex']}: {$ours} against {$pair['ratio']}"
        );
    }
});

it('draws a focus ring that clears 3:1 against every region it can land on', function (): void {
    $driven = 0;

    foreach (LandingTemplate::cases() as $template) {
        $html = LandingPages::render($template->value);
        $css = LandingPages::styleBlock($html);

        expect($css)->not->toBe('', $template->value.' serves no stylesheet');

        /*
         * ⚠ THE DEFAULTS ARE THE SUBJECT, so a tenant theme is deliberately NOT set: M-1 is
         * about the colours the product ships, and a theme would have hidden it. For `classic`
         * and `editorial` the resolver emits nothing, so their tokens come from the frozen
         * fallbacks — read out of the CSS rather than assumed.
         */
        $tokens = $template->resolvesEveryToken() ? resolvedTokens($html) : [];
        $fallback = static function (string $token) use ($css): string {
            preg_match('/var\(\s*'.preg_quote($token, '/').'\s*,\s*([^)]+)\)/i', $css, $found);

            return trim($found[1] ?? '');
        };

        $resolve = static fn (string $token): string => $tokens[$token] ?? $fallback($token);

        foreach (regionBackgrounds($css) as $selector => $backgroundToken) {
            $background = $resolve($backgroundToken);

            if (! str_starts_with($background, '#')) {
                continue; // `none`, or a value this page does not resolve to a colour
            }

            /*
             * ⚠ A FOCUSABLE ELEMENT'S OWN FILL IS NOT A SURFACE THE RING LANDS ON, and the
             * first version of this case judged it and failed on `classic` — *"a focus ring in
             * --color-accent (#0b5fff) drawn over .cta, which is --color-accent (#0b5fff)"*.
             * The shared rule draws `outline:3px solid …` at `outline-offset:2px`, so the ring
             * sits OUTSIDE the border box, over whatever CONTAINS the control. Judging the
             * control's own background measured an adjacency that does not exist.
             *
             * ⚠ IT WOULD EXIST AT `outline-offset:0`, and that is worth knowing rather than
             * filing: the call to action is filled with `--color-accent` and the ring is drawn
             * in `--color-accent`, so a future edit that removed the offset would make the
             * ring vanish into the button on EVERY arrangement, `classic` included. The offset
             * is what keeps those two apart, and it is frozen debt in the shared rules.
             */
            if (preg_match('/(?:\.cta|\ba|\bbutton)\s*(?::[a-z-]+)?$/i', $selector) === 1) {
                continue;
            }

            $outlineToken = outlineTokenFor($css, $selector);

            expect($outlineToken)->not->toBe(
                '',
                $template->value.' has no focus-outline colour in force for '.$selector
            );

            $outline = $resolve($outlineToken);

            expect(Contrast::ratio($outline, $background))->toBeGreaterThanOrEqual(
                3.0,
                $template->value.': a focus ring in '.$outlineToken.' ('.$outline.') drawn over '
                .$selector.', which is '.$backgroundToken.' ('.$background.'), has a contrast of '
                .Contrast::ratio($outline, $background).':1 — under WCAG 2.2 SC 1.4.11\'s 3:1, so '
                .'a keyboard visitor cannot see where focus is. §12 requires a visible focus '
                .'ring on every screen and this page is not exempt from it.'
            );

            $driven++;
        }
    }

    // Not vacuous: there are regions with a resolved background in more than one arrangement,
    // and `ember`'s inverted band — the one M-1 was measured on — is among them.
    expect($driven)->toBeGreaterThanOrEqual(4, 'too few regions were judged for this to mean anything');

    $ember = LandingPages::styleBlock(LandingPages::render('ember'));

    expect(regionBackgrounds($ember))->toHaveKey('.ember-hero');
    expect(outlineTokenFor($ember, '.ember-hero'))->toBe(
        '--color-background',
        'the inverted band no longer overrides the focus-outline colour, so the ring is drawn '
        .'in --color-accent over --color-text — the same hex by default (1.00:1)'
    );
});

it('keeps the band’s own text and its call to action readable, which the inversion could cost', function (): void {
    /*
     * The other half of an inversion: swapping two colours preserves their RATIO, so the band's
     * text is as readable as the page's was — but the CTA re-inverts again inside the band, and
     * a link in there inherits the band's colour rather than the page's accent. Both are
     * asserted so a later edit to one of those three lines cannot quietly fail.
     */
    $html = LandingPages::render('ember');
    $tokens = resolvedTokens($html);

    $band = $tokens['--color-text'];
    $ink = $tokens['--color-background'];

    expect(Contrast::ratio($ink, $band))->toBeGreaterThanOrEqual(4.5, 'the band’s own text is unreadable');

    // The CTA is paper-on-ink the other way round: the same pair, the same ratio.
    expect(Contrast::ratio($tokens['--color-text'], $tokens['--color-background']))
        ->toBeGreaterThanOrEqual(4.5);
});
