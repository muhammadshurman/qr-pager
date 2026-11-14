<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Landing\LandingDocument;
use App\Domain\Tenancy\TenantLanguage;
use App\Features\Landing\LandingRenderer;

/**
 * Rendering a landing document in a named arrangement, for the tests that read the page.
 *
 * ⚠ IT IS HERE AND NOT IN A TEST FILE, WHICH IS THE `§11` QA ROUND'S L-3. `renderTemplate()`
 * was a global function in `TemplateTokenSetTest.php`, and `TemplateStructureTest` called it:
 * Pest loads a whole directory for `--filter`, so both files passed that way and in the full
 * suite, but `php artisan test tests/Feature/Landing/TemplateStructureTest.php` — how a single
 * failing file is normally re-run — gave *"Call to undefined function renderTemplate()"* on
 * all three cases. A proof that cannot be re-run from a file list is fragile in the one
 * situation where it matters.
 *
 * ⚠ AND EVERY ARGUMENT IS FIXED, so the bytes are the DOCUMENT's. `LandingRenderer` promises
 * no database, no request and no tenant context, and the publish path's checksum depends on
 * rendering the same document twice producing the same bytes; a helper that varied a hostname
 * or a language between calls would make every comparison in these files a comparison of two
 * different things.
 */
final class LandingPages
{
    /**
     * @param  array<string, string>  $theme  the tenant's stored theme map
     * @param  array<string, mixed>  $overrides  anything else to replace in the fixture
     */
    public static function render(string $template, array $theme = [], array $overrides = []): string
    {
        $document = LandingDocument::fromInput(
            array_replace(LandingFixtures::fullPage(), ['template' => $template, 'theme' => $theme], $overrides),
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
     * The served page's own `<style>` block.
     *
     * Returns the empty string when there is none, which every caller asserts against rather
     * than tolerating: a page with no stylesheet would make a token test vacuous.
     */
    public static function styleBlock(string $html): string
    {
        $found = [];
        preg_match('/<style>(.*?)<\/style>/s', $html, $found);

        return $found[1] ?? '';
    }

    /**
     * Every custom property the page READS — `var(--name…)` in its `<style>` block AND in every
     * `style` attribute, sorted and unique.
     *
     * ⚠ THE ATTRIBUTES ARE `R-325`, CLOSED AT `QRPAGER-49C`. This used to read the `<style>`
     * block only, so `<p style="background-color:var(--color-typo)">` was a reference with
     * nothing behind it that NO guard saw: `scan-css` has no literal to find, the paint harness
     * sees an unresolved `var()` compute to its initial value (the declared non-themed member),
     * and the closure test did not read the attribute. Muhammad's ruling, 2026-11-14: *"a
     * mistyped token name resolves to nothing and the element silently inherits, so the page
     * looks almost right and nothing catches it."*
     *
     * ⚠ READ FROM THE RENDERED HTML, so Blade's `@style([...])` — which renders as exactly this
     * attribute — is covered by the same read and needs no second one. The attribute value is
     * entity-decoded first, because Blade escapes the quotes inside it.
     *
     * @return list<string>
     */
    public static function referencedTokens(string $html): array
    {
        $outsideStyle = preg_replace('/<style>.*?<\/style>/s', '', $html) ?? $html;
        $sources = [self::styleBlock($html)];

        $attributes = [];
        preg_match_all('/(?<![\w-])style\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $outsideStyle, $attributes);

        foreach ([...$attributes[1], ...$attributes[2]] as $value) {
            $sources[] = html_entity_decode($value, ENT_QUOTES);
        }

        $found = [];
        preg_match_all('/var\(\s*(--[a-z0-9-]+)/i', implode("\n", $sources), $found);

        /** @var list<string> $names */
        $names = array_values(array_unique($found[1]));
        sort($names);

        return $names;
    }

    /**
     * The declaration list on the root element, as `property => value`.
     *
     * @return array<string, string>
     */
    public static function rootProperties(string $html): array
    {
        $root = [];
        preg_match('/<html[^>]*\sstyle="([^"]*)"/', $html, $root);

        $properties = [];

        foreach (explode(';', $root[1] ?? '') as $declaration) {
            $parts = explode(':', $declaration, 2);

            if (count($parts) === 2 && str_starts_with(trim($parts[0]), '--')) {
                $properties[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $properties;
    }
}
