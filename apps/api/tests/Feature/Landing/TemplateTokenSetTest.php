<?php

declare(strict_types=1);

use App\Domain\Landing\LandingDocument;
use App\Domain\Landing\LandingTemplate;
use App\Domain\Landing\ThemeTokens;
use App\Domain\Tenancy\TenantLanguage;
use App\Features\Landing\LandingRenderer;
use App\Features\Landing\ThemeDefaults;
use Tests\Support\LandingFixtures;
use Tests\Support\StubMediaReferences;

/*
 * ================================================================================
 * `QRPAGER-46`'s STATED INVARIANT, CLAUSE 1 — THE TWO SETS ARE EQUAL
 * ================================================================================
 *
 * Muhammad's words: *"For each of rail, ember and tile: the set of tokens the template
 * references and the set the resolver emits are EQUAL — no reference with nothing behind it,
 * no emitted property nothing reads."*
 *
 * ⚠ BOTH SETS ARE READ OUT OF THE RENDERED PAGE, which is the only subject that cannot
 * disagree with what a visitor receives. The references come from the `var()` calls in the
 * served `<style>` block; the emitted properties come from the `style` attribute on `<html>`,
 * which `ThemeTokenTest` separately proves is the ONLY place a theme reaches the document.
 * Neither side is a list in this file, and that is the point: a list here would be a third
 * source, and the two it was meant to check would be free to drift from it together.
 *
 * ⚠ WHY THE CLAUSE IS WORTH A TEST AT ALL. The three new arrangements carry **no `var()`
 * fallback literal** — `R-268` is what a fallback costs — so a reference the resolver does
 * not emit paints NOTHING. Not a wrong colour: no colour. That failure is invisible to every
 * test that reads markup, and it is one typo away at any time.
 *
 * ⚠ AND THE CLAUSE IS SCOPED TO THE THREE, WHICH THE LAST CASE PROVES RATHER THAN ASSERTS.
 * `classic` must reproduce `pre-template-page.html` byte for byte, so it emits only what the
 * tenant set and relies on the fallbacks `tools/design-tokens/landing-baseline.json` freezes.
 * A test that demanded equality of all five would be demanding the opposite of
 * `QRPAGER-33`'s invariant clause 2.
 */

/** The page a document renders to, with every other argument fixed. */
function renderTemplate(string $template, array $theme = []): string
{
    $document = LandingDocument::fromInput(
        array_replace(LandingFixtures::fullPage(), ['template' => $template, 'theme' => $theme]),
        new StubMediaReferences,
    );

    return app(LandingRenderer::class)->render(
        $document,
        'olive.pages.qrpager.test',
        'Olive',
        [],
        TenantLanguage::English,
    );
}

/**
 * Every custom property the page's own rules READ, as a sorted list.
 *
 * ⚠ THE NAME IS WHAT IS COLLECTED, NOT THE WHOLE CALL, so `var(--color-surface,#f6f6f6)` and
 * `var(--color-surface)` are the same reference — which they are: a fallback changes what
 * renders when the token is absent, never which token is read. The fallback itself is the
 * subject of `LandingFallbackBaselineTest`, not of this file.
 *
 * @return list<string>
 */
function referencedTokens(string $html): array
{
    $style = [];
    preg_match('/<style>(.*?)<\/style>/s', $html, $style);

    expect($style[1] ?? '')->not->toBe('', 'the rendered page serves no <style> block at all');

    $found = [];
    preg_match_all('/var\(\s*(--[a-z0-9-]+)/i', (string) $style[1], $found);

    /** @var list<string> $names */
    $names = array_values(array_unique($found[1]));
    sort($names);

    return $names;
}

/**
 * Every custom property the page's `style` attribute SETS, as a sorted list.
 *
 * @return list<string>
 */
function emittedProperties(string $html): array
{
    $root = [];
    preg_match('/<html[^>]*\sstyle="([^"]*)"/', $html, $root);

    $names = [];

    foreach (explode(';', $root[1] ?? '') as $declaration) {
        $name = trim(explode(':', $declaration, 2)[0]);

        if (str_starts_with($name, '--')) {
            $names[] = $name;
        }
    }

    $names = array_values(array_unique($names));
    sort($names);

    return $names;
}

it('emits exactly the tokens it references, for each of the three new arrangements', function (): void {
    $driven = 0;

    foreach (LandingTemplate::cases() as $template) {
        if (! $template->resolvesEveryToken()) {
            continue;
        }

        $html = renderTemplate($template->value);

        $referenced = referencedTokens($html);
        $emitted = emittedProperties($html);

        // Not vacuous: a page that referenced nothing would pass an equality of two empty
        // lists, and twelve is what the contract carries.
        expect($referenced)->toHaveCount(
            count(ThemeTokens::NAMES),
            $template->value.' reads a different number of tokens than the contract has, so one '
            .'of the two sets below is not the set this invariant is about'
        );

        expect($emitted)->toBe(
            $referenced,
            $template->value.': the tokens its rules READ and the properties the resolver SETS '
            .'are not the same set. A reference with nothing behind it paints nothing at all, '
            .'because these arrangements carry no var() fallback — and an emitted property '
            .'nothing reads is a value the owner can change with no effect. Referenced: '
            .implode(' ', $referenced).' · emitted: '.implode(' ', $emitted)
        );

        $driven++;
    }

    expect($driven)->toBe(3, 'fewer than three arrangements resolve every token, so this proves nothing');
});

it('lets the tenant win every token they set, and fills only the rest', function (): void {
    /*
     * ⚠ THE HALF AN EQUALITY CANNOT SEE. Filling every absent token would satisfy the case
     * above even if it OVERWROTE the tenant's own values — the set would still be equal and
     * the restaurant's colours would be gone. Measured on the value, not on the set.
     */
    $html = renderTemplate('rail', ['color.accent' => '#7d2b8f']);

    $root = [];
    preg_match('/<html[^>]*\sstyle="([^"]*)"/', $html, $root);

    expect($root[1] ?? '')->toContain('--color-accent:#7d2b8f')
        ->and($root[1])->toContain('--color-background:'.ThemeDefaults::values()['color.background'])
        ->and(emittedProperties($html))->toHaveCount(count(ThemeTokens::NAMES));
});

it('leaves classic and editorial emitting only what the tenant set, which is the clause’s scope', function (): void {
    /*
     * ⚠ THE CONTROL FOR THE SCOPE, AND IT IS A REAL ASSERTION RATHER THAN A COMMENT. If the
     * resolver filled defaults for every arrangement, `classic` would carry twelve properties
     * where `tests/Fixtures/landing/pre-template-page.html` carries none — so this case fails
     * the moment `resolvesEveryToken()` stops being the thing that decides, and it fails
     * BEFORE the byte-for-byte fixture does, with a message that says why.
     */
    foreach ([LandingTemplate::Classic, LandingTemplate::Editorial] as $template) {
        expect($template->resolvesEveryToken())->toBeFalse();

        expect(emittedProperties(renderTemplate($template->value)))->toBe(
            [],
            $template->value.' now emits theme properties for a document with no theme, which '
            .'changes every page published before QRPAGER-33 — the invariant the pre-feature '
            .'capture holds to the byte'
        );

        // And it still READS the twelve, through the fallbacks the baseline freezes: the
        // inequality is the point, not a gap.
        expect(referencedTokens(renderTemplate($template->value)))
            ->toHaveCount(count(ThemeTokens::NAMES));
    }
});

it('gives every default a value the contract itself accepts, from the generated token layer', function (): void {
    $defaults = ThemeDefaults::values();

    // The key sets are equal, so no token is left without a default and no default names a
    // token the contract does not have.
    expect(array_keys($defaults))->toBe(array_keys(ThemeTokens::NAMES));

    foreach ($defaults as $name => $value) {
        /*
         * ⚠ THROUGH `accepts()`, THE SAME GATE A TENANT'S VALUE PASSES. A default that was
         * emitted without being validated could carry an upper-case hex, a font stack where
         * a stack KEY belongs, or a length with no unit — and the only thing that would say
         * so is a visitor looking at a broken page. `accepts()` takes lower-case hex only,
         * which is why `ThemeDefaults` lower-cases what the token layer writes.
         */
        expect(ThemeTokens::accepts($name, $value))->toBeTrue(
            "the default for {$name} is {$value}, which the contract refuses"
        );
    }

    // And the values are the token layer's, not this file's: two spot checks that would fail
    // if `config/design-tokens.php` were regenerated with different roles.
    expect($defaults['color.background'])->toBe(strtolower((string) config('design-tokens.colours.light.paper')))
        ->and($defaults['radius.small'])->toBe((string) config('design-tokens.radius.control'));
});
