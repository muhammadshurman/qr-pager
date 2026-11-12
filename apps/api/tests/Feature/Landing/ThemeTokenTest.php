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
use App\Features\Landing\ThemeDefaults;
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
    //
    // ⚠⚠ IT USED TO ASSERT THE ATTRIBUTE CARRIED **ONLY THESE THREE**, AND `QRPAGER-47`
    // CHANGED THE SUBJECT RATHER THAN THE PROPERTY. This document names no arrangement, so
    // it took `LandingTemplate::DEFAULT` — which was `classic`, an arrangement that emitted
    // the tenant's overrides and nothing else. The default is now `rail`, and every
    // remaining arrangement resolves EVERY token (`QRPAGER-46`'s invariant: no `var()`
    // fallback literal in their rules, so a token with no value paints nothing). So the
    // attribute now carries twelve properties: the tenant's three, each winning over the
    // default, and nine filled from the generated token layer.
    expect($html)->toContain('--color-accent:#aa0000')
        ->and($html)->toContain('--color-background:#fffff0')
        ->and($html)->toContain('--font-heading:ui-monospace, SFMono-Regular, Menlo, monospace')
        ->and($html)->not->toContain('&quot;');

    // The tenant's three WIN: the default for each is absent from the attribute, which is
    // the half that would break silently if the resolver filled over an override.
    $style = (string) (preg_match('/<html[^>]*style="([^"]*)"/', $html, $found) ? $found[1] : '');

    expect($style)->not->toContain('--color-accent:'.ThemeDefaults::values()['color.accent'])
        ->and($style)->not->toContain('--color-background:'.ThemeDefaults::values()['color.background'])
        ->and(substr_count($style, '--color-accent:'))->toBe(1)
        ->and(explode(';', $style))->toHaveCount(count(ThemeTokens::NAMES));

    // And exactly one <style> element -- the template's own rules, which no tenant value
    // reaches. A theme that could add a second one would be adding a component rule.
    expect(substr_count($html, '<style'))->toBe(1);

    // The page's SHARED rules still read some properties through `var()` with a fallback --
    // `R-268`, frozen by `tools/design-tokens/landing-baseline.json` rather than rewritten --
    // and with every token now emitted, nothing reaches those fallbacks on a rendered page.
    expect($html)->toContain('var(--color-surface,')
        ->and($html)->toContain('var(--radius-large,');
});

it('names every token in the generated contract, so Task 11 reads the same list', function (): void {
    $theme = LandingSchema::document()['properties']['theme'];

    expect(array_keys($theme['properties']))->toBe(array_keys(ThemeTokens::NAMES))
        ->and($theme['additionalProperties'])->toBeFalse();
});

it('can emit none of the order-ready screen\'s three properties, for any input', function (): void {
    /**
     * ⚠ THIS CASE WAS CLAIMED AND DID NOT EXIST. `QRPAGER-15 §6.1` asserted that hostile
     * names and values *"are driven through `ThemeStylesheet::declarations()` ... and the
     * output is asserted to contain none of the three protected properties"*. Measured by
     * the security review: no file under `apps/api` contained those names at all. The
     * property WAS covered -- on the TypeScript side, by `invariant.test.tsx`, which parses
     * this PHP -- so the claim pointed at a layer that did not hold the control.
     *
     * Security L-5. The sentence is corrected in the ticket AND the case it described is
     * written here, because this is the layer the emitter lives in and `§5.1` clause 2 is
     * about what the emitter can produce.
     *
     * THE PROTECTED LIST IS PARSED FROM THE TOKEN SOURCE, never copied. A hard-coded list
     * of three here would pass forever after a fourth was added, which is the duplicated-
     * list shape `guard` rule 11c already exists to prevent.
     */
    $source = (string) file_get_contents(dirname(base_path(), 2).'/packages/tokens/src/source.ts');
    $block = [];
    preg_match('/export const ORDER_READY = \{([^}]*)\}/', $source, $block);
    expect($block[1] ?? null)->not->toBeNull('ORDER_READY could not be parsed from the token source');

    $names = [];
    preg_match_all("/'([a-z0-9-]+)'\s*:/", (string) $block[1], $names);
    $protected = array_map(static fn (string $n): string => '--'.$n, $names[1] ?? []);

    expect($protected)->toHaveCount(3)
        ->and($protected)->toBe(['--ready-field', '--ready-ink', '--ready-signal']);

    /**
     * Every shape a tenant could try, including names that SPELL a protected property and
     * values carrying every character that could end a declaration or start a rule.
     */
    $hostile = [];
    foreach (array_keys(ThemeTokens::NAMES) as $name) {
        $hostile[$name] = '#0a1f17';
    }
    $forged = [
        'ready.field' => '#ff00ff',
        'ready.ink' => '#ff00ff',
        'ready.signal' => '#ff00ff',
        'color.ready_field' => '#ff00ff',
        'color.ready-field' => '#ff00ff',
        'READY.FIELD' => '#ff00ff',
        'color.accent' => '#fff;--ready-field:#ff00ff',
        'color.surface' => '#fff} .qr-ready{background:#ff00ff',
        'color.text' => "#fff\n--ready-ink:#ff00ff",
        'color.muted' => 'var(--ready-signal)',
        'color.background' => 'url(x);--ready-field:#ff00ff',
        'font.body' => "system'--ready-ink:#f0f",
    ];

    foreach ([$hostile, $forged, array_merge($hostile, $forged)] as $theme) {
        $css = ThemeStylesheet::declarations($theme);
        foreach ($protected as $property) {
            // ⚠ NOT `expect($css)->not->toContain($property, $message)`, and `composer guard`
            // caught exactly that here: Pest's `toContain` is VARIADIC, so a message passed
            // as a second argument is read as another NEEDLE and the assertion becomes
            // "does not contain BOTH" -- which a message that never appears makes trivially
            // true. The guard rule exists for this and found it in the very test written to
            // close a vacuity finding.
            expect(str_contains($css, $property))->toBeFalse("the emitter produced {$property}");
        }
        // And it never produces a rule, whatever it was given.
        expect($css)->not->toContain('{')
            ->and($css)->not->toContain('}')
            ->and($css)->not->toContain('@');
    }

    // Non-vacuous: the same emitter DOES produce a themeable property for a legitimate
    // value, so "contains none of the three" is not true of an always-empty output.
    expect(ThemeStylesheet::declarations(['color.accent' => '#0b5fff']))
        ->toContain('--color-accent:#0b5fff');
});
