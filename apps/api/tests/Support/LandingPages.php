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
     * Every custom property the page READS — each `var(--name…)` anywhere in the served document —
     * sorted and unique.
     *
     * ⚠ THE WHOLE DOCUMENT, AND THAT IS `R-325`, CLOSED AT `QRPAGER-49C`. This used to read the
     * `<style>` block only, so `<p style="background-color:var(--color-typo)">` was a reference
     * with nothing behind it that NO guard saw: `scan-css` has no literal to find, the paint
     * harness sees an unresolved `var()` compute to its initial value (the declared non-themed
     * member), and the closure test did not read the attribute. Muhammad's ruling: *"a mistyped
     * token name resolves to nothing and the element silently inherits, so the page looks almost
     * right and nothing catches it."*
     *
     * The first fix of it read `style=""` attributes specifically, and the `§11` QA round showed
     * the shapes that leaves: an UNQUOTED value, `fill="var(--x)"` (a presentation attribute),
     * a second `<style media="print">`. A `var()` is a reference wherever it is written, so this
     * reads them all. It is the RENDERED html, so Blade's `@style([...])` — which renders as an
     * ordinary `style="…"` attribute — needs no reader of its own.
     *
     * The document is entity-decoded first (`var(&#45;&#45;x)` is a reference to a browser). It
     * errs toward REPORTING: a `var(` in a comment or a script is read too, which makes the
     * closure red and a human look, never green.
     *
     * ⚠ KNOWN, STATED, NOT CLOSED: a custom property a template declares and reads LOCALLY
     * (`style="--i:1;transition-delay:calc(var(--i)*1s)"`) reads as a reference to `--i`, which
     * is not emitted, so the equality test would go red. No template does this today; the day one
     * does, that is a ruling on whether locals are exempt, not a quiet exception here. The name
     * class includes `_`, because `var(--color-text_x)` truncated to `--color-text` would report a
     * typo as emitted.
     *
     * @return list<string>
     */
    public static function referencedTokens(string $html): array
    {
        $found = [];
        $matched = preg_match_all('/var\(\s*(--[A-Za-z0-9_-]+)/i', html_entity_decode($html, ENT_QUOTES), $found);

        // A failed regex must be LOUD: `false` here would otherwise read as "no references", which
        // is the closure passing for the wrong reason.
        if ($matched === false || preg_last_error() !== PREG_NO_ERROR) {
            throw new \RuntimeException('referencedTokens: the reference scan failed: '.preg_last_error_msg());
        }

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
