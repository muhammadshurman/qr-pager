<?php

declare(strict_types=1);

use App\Domain\Landing\LandingDocument;
use App\Domain\Landing\LandingTemplate;
use App\Domain\Tenancy\TenantLanguage;
use App\Features\Landing\LandingRenderer;
use Tests\Support\LandingFixtures;
use Tests\Support\StubMediaReferences;

/*
 * ================================================================================
 * THE ROOT ELEMENT TAKES THE TENANT'S TEXT COLOUR — `QRPAGER-49A`, ruling 1
 * ================================================================================
 *
 * Phase 1 measured 7,944 colour-bearing computed values over six renders and found 66 that were
 * not a sentinel and not a declared member. All 66 were one defect seen eleven times: the root
 * `html` element resolved `#000000` for `color` and for the ten properties whose initial value is
 * `currentcolor`, because `page.blade.php` set `color` on `body` and nothing set it on `:root`.
 * It painted nothing — the root carries no text and no border, and the canvas takes `body`'s
 * background — but a theme that did not reach the root was a theme with a hole in it, and the
 * fix is one declaration: `:root{color:var(--color-text)}`.
 *
 * ⚠ THE BROWSER HARNESS IS THE REAL PROOF AND THIS IS ITS CHEAP SIBLING. `theme-paint-proof` fails
 * if any root value is not a sentinel; this fails in the ordinary suite, in a second, on the
 * source of the rule, for every arrangement, so the declaration cannot be deleted by somebody
 * who never runs the browser gate.
 *
 * ⚠ NO `var()` FALLBACK LITERAL, which `QRPAGER-46`'s invariant forbids in anything the three
 * arrangements write: `var(--color-text, #111)` would be a second default competing with the one
 * `ThemeDefaults` resolves, and `R-268` is what that costs.
 */

/** The page for one arrangement, rendered from the fixture document with every argument fixed. */
function rootColourPage(LandingTemplate $template): string
{
    $document = LandingDocument::fromInput(
        array_replace(LandingFixtures::fullPage(), ['template' => $template->value]),
        new StubMediaReferences,
    );

    return app(LandingRenderer::class)->render($document, 'olive.pages.qrpager.test', 'Olive', [], TenantLanguage::English);
}

/** The declarations of the rule whose selector is EXACTLY `:root`, or null when there is none. */
function rootRuleDeclarations(string $html): ?string
{
    if (preg_match('#<style>(.*?)</style>#s', $html, $style) !== 1) {
        return null;
    }

    return preg_match('/(?:^|\})\s*:root\s*\{([^}]*)\}/', $style[1], $rule) === 1 ? $rule[1] : null;
}

/** Whether a declaration list sets `color` to the text token, with no fallback after it. */
function setsTextColourWithoutFallback(string $declarations): bool
{
    return preg_match('/(?:^|;)\s*color\s*:\s*var\(\s*--color-text\s*\)\s*(?:;|$)/', $declarations) === 1;
}

it('declares the tenant’s text colour on :root, in every arrangement, with no fallback literal', function (LandingTemplate $template): void {
    $declarations = rootRuleDeclarations(rootColourPage($template));

    expect($declarations)->not->toBeNull('the page has no :root rule at all');
    expect(setsTextColourWithoutFallback((string) $declarations))->toBeTrue(
        "{$template->value}'s :root rule does not set color:var(--color-text); it declares [{$declarations}]",
    );

    // The rule's other job is untouched: it still hands the user agent both colour schemes.
    expect(str_contains((string) $declarations, 'color-scheme:light dark'))->toBeTrue();
})->with(LandingTemplate::cases());

it('refuses a :root rule that would leave the user agent’s colour in place — the control', function (): void {
    // The matcher above, shown to say no. A check that has never been shown to refuse
    // anything is the thing this ticket exists to rule out.
    foreach ([
        'color-scheme:light dark',
        'color-scheme:light dark;color:#111111',
        'color-scheme:light dark;color:var(--color-text,#111111)',
        'color-scheme:light dark;background-color:var(--color-text)',
        'color-scheme:light dark;border-color:var(--color-text)',
        '',
    ] as $wrong) {
        expect(setsTextColourWithoutFallback($wrong))->toBeFalse("accepted [{$wrong}]");
    }

    foreach ([
        'color:var(--color-text)',
        'color-scheme:light dark;color:var(--color-text)',
        'color:var(--color-text);color-scheme:light dark',
        'color-scheme:light dark; color : var( --color-text )',
    ] as $right) {
        expect(setsTextColourWithoutFallback($right))->toBeTrue("rejected [{$right}]");
    }

    expect(rootRuleDeclarations('<style>body{margin:0}</style>'))->toBeNull();
    expect(rootRuleDeclarations('no stylesheet at all'))->toBeNull();
});
