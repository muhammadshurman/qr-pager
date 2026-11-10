<?php

declare(strict_types=1);

use App\Domain\Landing\FieldKind;
use App\Domain\Landing\ThemeTokenKind;
use App\Domain\Tenancy\TenantRole;
use App\Features\Landing\LandingPages;
use App\Features\Landing\Panel\StudioForm;
use App\Features\Landing\ThemeStylesheet;
use Tests\Support\LandingFixtures;

/*
 * ================================================================================
 * THE PREVIEW IS AN IFRAME, AND THAT IS A SECURITY CONTROL — `QRPAGER-42`
 * ================================================================================
 *
 * Muhammad, 2026-11-10: *"The iframe is a security control, not a styling detail — prove it
 * holds. Your argument for A1 rests on the preview always being a separate document. If any
 * state renders the tenant stylesheet inline instead … then tenant-controlled CSS lands in
 * an authenticated admin document. Without that test the control disappears silently the day
 * someone inlines the preview for performance."*
 *
 * A1's whole claim — that the panel's grey ground comes from Filament and not from the
 * restaurant's own saved theme — depends on it. The audit found the control holds today by
 * four separate accidents of structure: every tab `@include`s the partial unconditionally,
 * the partial's `<iframe>` has no conditional around it, the phone/desktop toggle is a query
 * parameter on that same element, and `LandingRenderer` has exactly one panel caller which
 * returns a whole HTTP response rather than a string for a Blade. Four accidents are not a
 * control; this file is.
 *
 * ⚠ IT PAIRS WITH `frame-ancestors 'self'` ON `PreviewController`. That decision (2026-11-09)
 * is what lets the pane exist at all; this is what keeps the pane a separate DOCUMENT. Either
 * one alone leaves the other meaningless.
 */

beforeEach(fn () => LandingFixtures::bucket());

/** Parse a panel document. A local helper: Pest's file-scope functions do not cross files. */
function isolationDom(string $html): DOMXPath
{
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($document);
}

/** @return array<string, string> path => the panel document */
function isolationTabs(): array
{
    $tenant = test()->actingForTenant(role: TenantRole::Owner);

    $document = LandingFixtures::fullPage();
    // A theme a tenant could plausibly save, including a value that would be unmistakable
    // if it ever leaked into the panel's own chrome.
    $document['theme'] = ['color.background' => '#eee8e8', 'color.accent' => '#da6110'];

    (new LandingPages)->saveDraft($tenant, $document);

    $html = [];

    foreach (['/studio', '/studio/media', '/studio/theme', '/studio/domains'] as $path) {
        $html[$path] = (string) test()->get($path)->getContent();
    }

    return $html;
}

it('renders the preview as an iframe on every tab, in every state the panel has', function (): void {
    foreach (isolationTabs() as $path => $html) {
        $xpath = isolationDom($html);

        $frames = $xpath->query('//iframe[@data-studio-preview="true"]');

        expect($frames?->length)->toBe(1, "{$path} does not render exactly one preview iframe");

        /*
         * ⚠ AND THE RENDERER'S OUTPUT IS NOT IN THIS DOCUMENT. An iframe that exists beside
         * an inlined copy would pass the count above and fail the point of it, so what is
         * asserted is the absence of the published page's own markers from the panel.
         */
        expect(str_contains($html, '<html lang='))->toBeFalse("{$path} carries a nested document");
    }
});

it('keeps the tenant stylesheet out of the panel document entirely', function (): void {
    /*
     * THE CLAIM A1 RESTS ON, stated as an assertion rather than as an argument: whatever the
     * restaurant saved paints its own page and nothing of the panel.
     *
     * `ThemeStylesheet::declarations()` is the only thing that turns a stored theme into CSS,
     * and the string it produces is what must be absent. Checked by VALUE as well, because a
     * future change could emit the same colours by a different route.
     */
    $declarations = ThemeStylesheet::declarations([
        'color.background' => '#eee8e8',
        'color.accent' => '#da6110',
    ]);

    expect($declarations)->not->toBe('', 'the fixture theme produced no declarations, so this case proves nothing');

    foreach (isolationTabs() as $path => $html) {
        expect(str_contains($html, $declarations))
            ->toBeFalse("{$path} carries the tenant's own stylesheet in the panel document");

        /*
         * ⚠ THE RAW HEX IS *NOT* WHAT IS FORBIDDEN, AND THE FIRST VERSION OF THIS CASE GOT
         * THAT WRONG. It asserted the stored colours appeared nowhere in the panel, and
         * reddened on `/studio/theme` — correctly, because that tab is where the owner EDITS
         * them: `ColourField` renders the value into the picker and its caption. A test that
         * forbade it would have forbidden the feature.
         *
         * What must be absent is the value applied AS A DECLARATION — the tenant's theme
         * painting the panel rather than being edited in it. So the check is for the
         * property form, which is what `ThemeStylesheet` emits and what `<html style>` would
         * carry.
         */
        foreach (['--color-background:#eee8e8', '--color-accent:#da6110'] as $declaration) {
            expect(str_contains($html, $declaration))
                ->toBeFalse("{$path} applies the tenant's theme as CSS in the panel document");
        }
    }
});

/*
 * ================================================================================
 * EVERY FIELD KIND HAS AN EXPLICIT DIRECTION DECISION — `QRPAGER-42` A3
 * ================================================================================
 *
 * Muhammad's requirement, 2026-11-10: *"Make the mark EXHAUSTIVE, not a lookup with a silent
 * default. As written, a kind added later gets no mark and the bug returns with no test
 * failing — and the preset ticket is likely to add exactly that: a field whose value IS a
 * literal."*
 *
 * Two mechanisms, deliberately: `match` with no default arm throws `UnhandledMatchError` on
 * an unhandled case, and these cases iterate every enum member so the decision is also
 * asserted rather than merely reachable.
 */
it('decides direction for every field kind, with no silent default', function (): void {
    $decided = [];

    foreach (FieldKind::cases() as $kind) {
        // Throws UnhandledMatchError if a case was added without a decision. That is the
        // failure this case exists to produce, and it names the kind.
        $decided[$kind->value] = StudioForm::holdsLiteralValue($kind);
    }

    expect($decided)->toBe([
        'text' => false,
        'url' => true,
        'email' => true,
        'telephone' => true,
        'media' => true,
        'number' => true,
        'item_list' => false,
    ], 'a field kind changed its direction decision, or a new one arrived undecided');
});

it('decides direction for every theme token kind, with no silent default', function (): void {
    $decided = [];

    foreach (ThemeTokenKind::cases() as $kind) {
        $decided[$kind->value] = StudioForm::tokenHoldsLiteralValue($kind);
    }

    expect($decided)->toBe([
        'colour' => false,
        'length' => true,
        'font_choice' => false,
    ], 'a theme token kind changed its direction decision, or a new one arrived undecided');
});

it('marks the literal fields in the rendered form and leaves the prose fields alone', function (): void {
    /*
     * The decision reaching the DOM, and BOTH halves of it on one page.
     *
     * `/studio/theme` is the surface that carries both: the four `radius`/`space`/`size`
     * tokens are `ThemeTokenKind::Length` — `1rem`, `12px`, literals — and the two
     * search-and-sharing fields are `FieldKind::Text`, which hold the owner's own Arabic. The
     * page tab would not do: its literal fields live inside blocks, which `QRPAGER-40`
     * renders collapsed, so nothing would be marked and the case would pass vacuously.
     *
     * ⚠ THE SECOND HALF IS THE ONE THAT MATTERS. The first version of A3 was a stylesheet
     * rule over every input in the form, which would have marked `seo_title` too and rendered
     * the restaurant's own sentences backwards. That is what this case forbids.
     */
    $this->actingForTenant(role: TenantRole::Owner);

    $xpath = isolationDom((string) $this->get('/studio/theme')->getContent());

    $marked = [];
    $unmarked = [];

    foreach ($xpath->query('//input[@type="text"]') ?: [] as $input) {
        if (! $input instanceof DOMElement) {
            continue;
        }

        $name = $input->getAttribute('wire:model') ?: $input->getAttribute('id');
        $isMarked = str_contains($input->getAttribute('class'), 'studio-ltr');

        $isMarked ? $marked[] = $name : $unmarked[] = $name;
    }

    // Not vacuous on either side: the page must render some of each.
    expect($marked)->not->toBe([], 'no field is marked as holding a literal, so this proves nothing')
        ->and($unmarked)->not->toBe([], 'every field is marked, so the prose half proves nothing');

    // And the halves must not overlap: a prose field carrying the mark is the defect.
    foreach ($unmarked as $name) {
        expect(str_contains((string) $name, 'seo_') || ! str_contains((string) $name, 'radius'))
            ->toBeTrue("a literal field [{$name}] is not marked");
    }
});
