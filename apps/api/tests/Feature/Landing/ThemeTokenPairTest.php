<?php

declare(strict_types=1);

use App\Domain\Landing\ThemeTokenKind;
use App\Domain\Landing\ThemeTokens;
use App\Features\Landing\ThemeDefaults;

/**
 * ================================================================================
 * THE CONTRAST PAIRS, AND THE PROPERTY THAT MAKES THE ADVICE POSSIBLE — `QRPAGER-47`
 * ================================================================================
 *
 * Part B item 3 asks for *"live contrast feedback: when a chosen colour is hard to read, the
 * message says what to do, not what failed."* A row can only say that if it knows which other
 * colour it is read against, and `ThemeTokens::CONTRAST_PAIRS` is that answer.
 *
 * ⚠ THE FAILURE THIS FILE EXISTS FOR IS SILENT: a colour with no pair gets a row with no
 * check, and the row looks exactly like the five that have one. Nothing on the screen, in a
 * browser test or in a review diff distinguishes them.
 */
it('pairs every colour token, and nothing else', function (): void {
    $colours = array_keys(array_filter(
        ThemeTokens::NAMES,
        static fn (ThemeTokenKind $kind): bool => $kind === ThemeTokenKind::Colour,
    ));

    // Exactly the colours, in the contract's own order: a pair for a length would be
    // meaningless, and a colour without one is a row whose contrast nothing judges.
    expect(array_keys(ThemeTokens::CONTRAST_PAIRS))->toBe($colours)
        ->and($colours)->toHaveCount(6);

    foreach (ThemeTokens::CONTRAST_PAIRS as $name => $pair) {
        expect(ThemeTokens::NAMES[$pair] ?? null)->toBe(
            ThemeTokenKind::Colour,
            "{$name} is paired with {$pair}, which is not a colour token"
        );

        // And never with itself: the ratio of a colour against itself is 1:1, so a row
        // paired with itself would advise the owner on every colour they ever picked.
        expect($pair)->not->toBe($name);
    }
});

it('judges a button’s label on the button, which is the pairing Muhammad ruled', function (): void {
    /*
     * The ruling of 2026-11-12, in his words: *"accent / accent_text are a PAIR: the contrast
     * check on those two runs between them, not against the page background."*
     *
     * It is asserted rather than left to the map's reading, because getting it wrong is
     * invisible in the same way: judging `accent_text` against `color.background` would pass
     * a combination no visitor sees — white-on-paper — and would stay quiet about the one
     * they do.
     */
    expect(ThemeTokens::CONTRAST_PAIRS['color.accent'])->toBe('color.accent_text')
        ->and(ThemeTokens::CONTRAST_PAIRS['color.accent_text'])->toBe('color.accent');

    // Text and secondary text are read on the page's own ground; the ground and a card's
    // ground are read UNDER text. Four rows, two pairs, each stated from both ends.
    expect(ThemeTokens::CONTRAST_PAIRS['color.text'])->toBe('color.background')
        ->and(ThemeTokens::CONTRAST_PAIRS['color.muted'])->toBe('color.background')
        ->and(ThemeTokens::CONTRAST_PAIRS['color.background'])->toBe('color.text')
        ->and(ThemeTokens::CONTRAST_PAIRS['color.surface'])->toBe('color.text');
});

it('names a resolvable custom property for every colour’s default, and refuses the rest', function (): void {
    /*
     * `ColourField` hands this property NAME to the browser so the swatch opens on the colour
     * the page is actually using. ⚠ IF THE NAME DOES NOT RESOLVE, THE PICKER OPENS ON BLACK
     * AND NOTHING SAYS WHY — `getComputedStyle` returns an empty string for an unknown
     * property and the field falls back to the element's own default. So the generated token
     * layer is read here and every name is required to be in it.
     */
    $generated = (string) file_get_contents(base_path('../../packages/tokens/generated/tokens.css'));

    expect($generated)->not->toBe('', 'the generated token layer is missing, so this case proves nothing');

    foreach (array_keys(ThemeTokens::CONTRAST_PAIRS) as $name) {
        $property = ThemeTokens::defaultProperty($name);

        expect($property)->toStartWith('--color-')
            ->and(str_contains($generated, $property.':'))->toBeTrue(
                "{$name}'s default property {$property} is not in packages/tokens/generated/tokens.css, "
                .'so the picker would open on black'
            );
    }

    // A token whose default is not a colour has no picker and says so, rather than returning
    // something a caller would paint.
    expect(fn () => ThemeTokens::defaultProperty('space.section'))
        ->toThrow(InvalidArgumentException::class, 'no colour default');
    expect(fn () => ThemeTokens::defaultProperty('font.body'))
        ->toThrow(InvalidArgumentException::class, 'no colour default');
});

it('opens each picker on the value the renderer would emit, not on a neighbour', function (): void {
    /*
     * ⚠ THE HALF A NAME CANNOT CARRY: `defaultProperty()` returning a resolvable name is not
     * the same claim as that name holding the RIGHT colour. `ThemeDefaults` resolves the same
     * token through `config('design-tokens')` for the published page, so the two must agree —
     * and this is the only case that compares them.
     *
     * Measured both ways: the property name comes from `DEFAULT_SOURCES` and the value comes
     * from the generated config, so a wrong derivation here would show up as a picker opening
     * on the wrong colour while every other test passed.
     */
    $defaults = ThemeDefaults::values();
    $tokens = require base_path('config/design-tokens.php');

    foreach (array_keys(ThemeTokens::CONTRAST_PAIRS) as $name) {
        $property = ThemeTokens::defaultProperty($name);
        $role = substr($property, strlen('--color-'));

        expect(strtolower((string) ($tokens['colours']['light'][$role] ?? '')))->toBe(
            $defaults[$name],
            "the picker for {$name} opens on {$property} while the page is painted "
            .$defaults[$name]
        );
    }
});
