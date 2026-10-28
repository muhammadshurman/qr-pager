<?php

declare(strict_types=1);

use App\Domain\Landing\LandingDocument;
use App\Domain\Landing\LandingDocumentInvalid;
use App\Domain\Landing\LandingSchema;
use App\Domain\Landing\ThemeTokenKind;
use App\Domain\Landing\ThemeTokens;
use App\Features\Landing\LandingPages;
use App\Features\Landing\MediaLibrary;
use App\Features\Landing\PublishedSite;
use App\Features\Landing\ThemeStylesheet;
use Tests\Support\LandingFixtures;
use Tests\Support\StubMediaReferences;

/*
 * ================================================================================
 * §3 row 7, clause 5 — "theme overrides TOKENS ONLY, never components"
 * ================================================================================
 *
 * PLAN.md §3.1 row 7 on this clause: it "names no mechanism. Same empty-phrase shape the
 * fourth review found in 'architecture guards' -- it needs a lint rule proven to fail on
 * a bad file, or it is not checkable."
 *
 * Decision D6 gives it three, and this file drives all three:
 *
 *   an ALLOW-LIST of names     -- a token the tenant invents is refused, not ignored
 *   a TYPED validator per kind -- whose patterns admit no `;`, `}`, `)`, quote or space,
 *                                 so a value cannot end a declaration or start a rule
 *   `composer guard` rule 11   -- proven on a fixture in RepoGuardTest
 */

beforeEach(fn () => LandingFixtures::bucket());

function theme(array $tokens): array
{
    return [
        'blocks' => [['type' => 'hero', 'fields' => ['heading' => 'Hi']]],
        'theme' => $tokens,
    ];
}

it('refuses a token name the schema does not have, rather than ignoring it', function (): void {
    // IGNORING would be worse than refusing: the tenant would believe they had set it.
    foreach (['color.secret', 'display', 'background', 'color-accent', '--color-accent', ''] as $name) {
        expect(fn () => LandingDocument::fromInput(theme([$name => '#ffffff']), new StubMediaReferences))
            ->toThrow(LandingDocumentInvalid::class);
    }
});

it('accepts exactly the twelve tokens and no more', function (): void {
    // The positive control for the case above: an allow-list that refused everything
    // would pass it, which is R-059's shape.
    $accepted = [];

    foreach (ThemeTokens::NAMES as $name => $kind) {
        $value = match ($kind) {
            ThemeTokenKind::Colour => '#112233',
            ThemeTokenKind::Length => '1.5rem',
            ThemeTokenKind::FontChoice => 'serif',
        };

        $document = LandingDocument::fromInput(theme([$name => $value]), new StubMediaReferences);

        expect($document->theme)->toBe([$name => $value]);
        $accepted[] = $name;
    }

    expect($accepted)->toBe(array_keys(ThemeTokens::NAMES))
        ->and($accepted)->toHaveCount(12);
});

it('refuses every value that could end a declaration or start a rule', function (): void {
    // THE POINT OF THE PATTERNS. A theme changes a VALUE; it may never become a RULE.
    // Each of these is a way to do that in CSS, and each is refused by the token's own
    // kind rather than by a blocklist.
    $hostile = [
        'red; } body { display: none',
        '#fff; position: fixed',
        'url(https://evil.test/x)',
        'var(--something-else)',
        'expression(alert(1))',
        'rgb(255 0 0)',
        'color-mix(in srgb, red, blue)',
        '#fff !important',
        "#fff\n}",
        '#ffffff ',
        'red',
        '#fffffff',
        '#ff',
        'attr(data-x)',
    ];

    foreach ($hostile as $value) {
        expect(ThemeTokens::accepts('color.accent', $value))
            ->toBeFalse("[{$value}] was accepted as a colour");

        expect(fn () => LandingDocument::fromInput(theme(['color.accent' => $value]), new StubMediaReferences))
            ->toThrow(LandingDocumentInvalid::class);
    }

    // Lengths and fonts, the same way.
    foreach (['1rem; color: red', '10', 'calc(1rem + 2px)', '1vw', '99999px', '-1rem'] as $value) {
        expect(ThemeTokens::accepts('radius.small', $value))->toBeFalse("[{$value}] was accepted as a length");
    }

    foreach (['Helvetica', 'serif, cursive', '"Comic Sans"', 'system'] as $value) {
        $allowed = array_key_exists($value, ThemeTokens::STACKS);

        expect(ThemeTokens::accepts('font.body', $value))->toBe($allowed);
    }
});

it('emits declarations and never a rule, and only for tokens that were set', function (): void {
    // ThemeStylesheet is the one file in app/ that may build a declaration (rule 11d).
    // What it produces is a DECLARATION LIST for one attribute -- no selector, no brace,
    // no at-rule -- which is the difference between overriding a token and styling a
    // component.
    $css = ThemeStylesheet::declarations([
        'color.accent' => '#0b5fff',
        'font.body' => 'serif',
        'radius.large' => '12px',
    ]);

    expect($css)->toBe('--color-accent:#0b5fff;--font-body:ui-serif, Georgia, serif;--radius-large:12px')
        ->and($css)->not->toContain('{')
        ->and($css)->not->toContain('}')
        ->and($css)->not->toContain('@');

    // A token that was not set emits NOTHING, so the template's own default applies --
    // which is what makes a theme an override rather than a replacement.
    expect(ThemeStylesheet::declarations([]))->toBe('');

    // And it re-validates at the emission point: a value that somehow got past the
    // document validator is dropped here rather than concatenated into CSS. A control
    // that is only correct because its caller is correct is not a control.
    expect(ThemeStylesheet::declarations(['color.accent' => 'red; }']))->toBe('')
        ->and(ThemeStylesheet::declarations(['not.a.token' => '#fff']))->toBe('');
});

it('puts the theme in one attribute of the published page and nowhere else', function (): void {
    $tenant = $this->makeTenant(slug: 'themed');
    $pages = new LandingPages;

    $pages->saveDraft($tenant, theme([
        'color.accent' => '#aa0000',
        'color.background' => '#fffff0',
        'font.heading' => 'mono',
    ]));

    $published = $pages->publish($tenant);
    $html = (string) MediaLibrary::disk()->get(PublishedSite::pointer($published->hostname));

    // ONE attribute, on the root element, in a DETERMINISTIC order -- LandingDocument
    // ksorts the theme, which is what keeps a re-publish of an unchanged page producing
    // the same checksum and therefore the same snapshot name.
    //
    // AND NO ESCAPING ARTEFACT IN IT. Blade escapes this attribute as HTML, which is the
    // correct encoder for where it lands -- and the font stacks are chosen to need no
    // quotes, so the escaped output is byte-identical to the declaration list. That is
    // the design in ThemeTokens::STACKS arriving as an observable fact.
    expect($html)->toContain('style="--color-accent:#aa0000;--color-background:#fffff0;--font-heading:ui-monospace, SFMono-Regular, Menlo, monospace"')
        ->and($html)->not->toContain('&quot;');

    // And exactly one <style> element -- the template's own rules, which no tenant value
    // reaches. A theme that could add a second one would be adding a component rule.
    expect(substr_count($html, '<style'))->toBe(1);

    // The page's rules read the properties through var() with a default, so a token the
    // tenant did not set falls through rather than rendering as empty.
    expect($html)->toContain('var(--color-surface,')
        ->and($html)->toContain('var(--radius-large,');
});

it('names every token in the generated contract, so Task 11 reads the same list', function (): void {
    $theme = LandingSchema::document()['properties']['theme'];

    expect(array_keys($theme['properties']))->toBe(array_keys(ThemeTokens::NAMES))
        ->and($theme['additionalProperties'])->toBeFalse();
});
