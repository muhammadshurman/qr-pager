<?php

declare(strict_types=1);

use App\Domain\Landing\LandingTemplate;
use App\Domain\Tenancy\TenantRole;
use App\Features\Landing\LandingPages;
use App\Features\Landing\PublishedSite;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LandingFixtures;

/*
 * ================================================================================
 * ONE RESOLVER, OR TWO? — `QRPAGER-49A` ASKED, AND THIS IS THE ANSWER
 * ================================================================================
 *
 * The ticket: *"State explicitly whether the `QRPAGER-45` preview path and the published path
 * resolve through the same resolver. If they differ, that is a finding on its own: a themed
 * preview over an unthemed page is the exact failure this ticket exists to catch."*
 *
 * **They do not differ, and this file is why that is a measurement rather than a reading.**
 * Both paths reach `LandingRenderer::render()`, which calls
 * `ThemeStylesheet::declarations($document->theme, $document->template)` once and puts the
 * result in the root element's `style` attribute. `PreviewController::respond()` is the panel's
 * only caller and `LandingPages::publishUnderLock()` phase 2 is the publisher's; neither
 * resolves a token itself.
 *
 * ⚠ **AN ARGUMENT FROM READING THE CODE IS WHAT THIS FILE REPLACES.** Two call sites agreeing
 * today is a fact about today; the SECOND case below is what keeps it one resolver, by counting
 * the emitter's callers rather than trusting that nobody adds a third.
 *
 * ⚠ **AND THE SUBJECT IS THE STORED BYTES, NOT A SECOND RENDER.** The published half is read
 * back out of the object store — the pointer `apps/site` serves — so what is compared with the
 * preview is what a visitor receives, not what this test could persuade the renderer to produce.
 */

beforeEach(fn () => LandingFixtures::bucket());

/**
 * The six sentinels, one per colour role — the same values `bin/publish-sentinel-theme`
 * publishes, for the same reason: six values that appear nowhere else in the product make a
 * difference between the two paths attributable to a role rather than merely visible.
 *
 * @return array<string, string>
 */
function parityTheme(): array
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

/** The root element's `style` attribute — the one place a resolved theme reaches the page. */
function rootStyle(string $html): string
{
    // ⚠ `toContain()` TAKES NEEDLES, NOT A MESSAGE — a second string argument is a second
    // thing to look for, so a message passed there is asserted rather than reported. Measured
    // here on the first run: `3 failed`, each one "To contain: the document has no root
    // element". `toBeTrue()` is the form that carries a message.
    expect(str_contains($html, '<html'))->toBeTrue('the document has no root element, so there is nothing to compare');

    preg_match('/<html[^>]*\sstyle="([^"]*)"/u', $html, $matches);

    return $matches[1] ?? '';
}

it('puts the same resolved theme on the preview and on the published page', function (LandingTemplate $template): void {
    $tenant = test()->actingForTenant(role: TenantRole::Owner);

    $document = LandingFixtures::fullPage();
    $document['template'] = $template->value;
    $document['theme'] = parityTheme();

    $pages = new LandingPages;
    $pages->saveDraft($tenant, $document);
    $published = $pages->publish($tenant);

    $bytes = (string) Storage::disk('s3')->get(PublishedSite::pointer($published->hostname));
    $preview = (string) test()->get('/studio/preview')->getContent();

    $publishedStyle = rootStyle($bytes);

    // Vacuity first: a comparison of two empty strings would pass on a page with no theme at
    // all, which is the failure this case exists to detect.
    foreach (parityTheme() as $role => $value) {
        expect(str_contains($publishedStyle, $value))
            ->toBeTrue("the published page does not carry {$role}");
    }

    expect(rootStyle($preview))->toBe(
        $publishedStyle,
        "the preview and the published page resolve {$template->value} differently — a themed "
        .'preview over a differently themed page is exactly what makes a studio unjudgeable',
    );
})->with(LandingTemplate::cases());

it('keeps one emitter with one caller, so the two paths cannot drift apart', function (): void {
    /*
     * ⚠ COUNTED OVER `app/`, NOT ASSERTED ABOUT TODAY'S TWO CALL SITES. The case above proves
     * the paths AGREE; this is what keeps them one mechanism. A second caller of
     * `ThemeStylesheet::declarations()` would be a second place that decides what a theme
     * resolves to, and the first symptom of one is a preview that disagrees with the page a
     * restaurant's customers see — which nothing else in this suite would catch, because every
     * other case drives one path at a time.
     *
     * `ThemeStylesheet` itself is excluded: a class may name its own method.
     */
    $callers = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $path = (string) $file->getRealPath();

        if (str_ends_with($path, 'ThemeStylesheet.php')) {
            continue;
        }

        if (preg_match('/ThemeStylesheet::declarations\s*\(/', (string) file_get_contents($path)) === 1) {
            $callers[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($callers)->toBe(
        ['app/Features/Landing/LandingRenderer.php'],
        'the theme is resolved in more than one place, so the preview and the published page '
        .'can now disagree: '.implode(', ', $callers),
    );
});
