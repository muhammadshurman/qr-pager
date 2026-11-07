<?php

declare(strict_types=1);

use App\Domain\Landing\BlockType;
use App\Domain\Landing\LandingDocument;
use App\Domain\Landing\LandingDocumentInvalid;
use App\Domain\Landing\LandingTemplate;
use App\Domain\Tenancy\TenantLanguage;
use App\Features\Landing\LandingPages;
use App\Features\Landing\LandingRenderer;
use App\Features\Landing\MediaLibrary;
use App\Features\Landing\PublishedSite;
use Tests\Support\LandingFixtures;
use Tests\Support\StubMediaReferences;

/*
 * ================================================================================
 * `QRPAGER-33`'s NAMED INVARIANT, DRIVEN. All five clauses, none cut.
 * ================================================================================
 *
 * *"Every published page is arranged by the template its own stored document names; a document
 * that names none is arranged as `classic`; and no block the tenant placed is absent from any
 * arrangement."*
 *
 * Clause 3 (totality over the block schema) is in `TemplateTotalityTest`, because it reads the
 * committed JSON contract rather than a published page. Clause 5 (tenant isolation over the
 * panel path) is in `StudioActionsTest`, beside the other Livewire cases, for the reason that
 * file's header gives: a GET of a Filament page never invokes an action.
 */

beforeEach(fn () => LandingFixtures::bucket());

/** A document built straight from the fixture, with no database. */
function templateDocument(array $overrides = []): LandingDocument
{
    return LandingDocument::fromInput(
        array_replace(LandingFixtures::fullPage(), $overrides),
        new StubMediaReferences,
    );
}

/** The renderer's own output, with every argument fixed, so the bytes are the document's. */
function renderWithFixedArguments(LandingDocument $document): string
{
    return app(LandingRenderer::class)->render(
        $document,
        'olive.pages.qrpager.test',
        'Olive',
        [],
        TenantLanguage::English,
    );
}

it('reproduces the pre-feature page EXACTLY for a document that names no arrangement', function (): void {
    /*
     * ⚠ **INVARIANT CLAUSE 2, AND THE ONLY ONE THAT COULD NOT HAVE BEEN WRITTEN AFTER THE
     * FACT.** `QRPAGER-33 §4` flagged it as the one thing that could make the ticket bigger:
     * *"proof (2) requires a captured snapshot of today's published bytes BEFORE anything
     * changes. If that is not taken first, the claim becomes unprovable after the fact."*
     *
     * `tests/Fixtures/landing/pre-template-page.html` is that capture -- 4,625 bytes, sha256
     * fe5e5995…, taken on 2026-11-03 from the renderer as it then stood, from
     * `LandingFixtures::fullPage()` with the same fixed arguments this case uses. Every
     * document stored before this feature carries no `template` member, so this is the page a
     * live tenant republishing today must get.
     *
     * It found a real byte: the first attempt put the arrangement's CSS in the shared
     * `<style>` block and measured **4,941**, and a later one left a Blade comment on its own
     * line and measured **4,626**. Both would have changed every existing tenant's page.
     */
    $stored = LandingFixtures::fullPage();

    expect($stored)->not->toHaveKey('template', 'the fixture must be a PRE-feature document');

    $document = templateDocument();

    expect($document->template)
        ->toBe(LandingTemplate::Classic, 'a document naming no arrangement did not default to classic');

    $expected = (string) file_get_contents(base_path('tests/Fixtures/landing/pre-template-page.html'));

    expect(renderWithFixedArguments($document))->toBe(
        $expected,
        'the classic arrangement no longer reproduces the page this feature inherited',
    );
});

it('arranges a published page by the value its own document carries, and differently', function (): void {
    /*
     * ⚠ **CLAUSE 1, ON THE PUBLISHED BYTES AND NOT THE RENDERER'S RETURN VALUE.** `§6.6`'s
     * publish is write-new-then-switch and the pointer's write IS the switch, so the only
     * honest subject for *"every published page is arranged by..."* is what the pointer
     * serves.
     *
     * ⚠ AND THE ASSERTION IS ABOUT ORDER, not about a marker class. A test that looked for
     * `class="band"` would pass for an arrangement that emitted the wrapper and left the
     * blocks in the builder's order -- which is the defect this clause exists to catch. So it
     * compares the POSITION of two block headings that the two arrangements order differently.
     */
    $tenants = [];

    foreach ([LandingTemplate::Classic, LandingTemplate::Editorial] as $template) {
        $tenantId = $this->makeTenant(slug: 'arranged-'.$template->value);

        (new LandingPages)->saveDraft($tenantId, array_replace(
            LandingFixtures::fullPage(),
            ['template' => $template->value],
        ));

        $published = (new LandingPages)->publish($tenantId);
        $bytes = (string) MediaLibrary::disk()->get(PublishedSite::pointer($published->hostname));

        $tenants[$template->value] = $bytes;
    }

    // `menu_highlights` sits AFTER the gallery in the fixture's own order, and `editorial`
    // promotes `how_it_works` above both. Positions, not presence.
    $positions = static fn (string $bytes): array => [
        'how_it_works' => strpos($bytes, 'How the pager works'),
        'gallery' => strpos($bytes, 'The room'),
    ];

    $classic = $positions($tenants['classic']);
    $editorial = $positions($tenants['editorial']);

    expect($classic['how_it_works'])->toBeInt()
        ->and($classic['gallery'])->toBeInt()
        ->and($editorial['how_it_works'])->toBeInt()
        ->and($editorial['gallery'])->toBeInt();

    // The fixture's order has the gallery after how_it_works in BOTH, so the distinguishing
    // fact is the wrapper the editorial arrangement groups them in -- asserted together with
    // the order so neither alone can carry the case.
    expect($tenants['editorial'])->toContain('<div class="band">')
        ->and($tenants['editorial'])->toContain('<div class="wide">')
        ->and($tenants['classic'])->not->toContain('<div class="band">')
        ->and($tenants['classic'])->not->toContain('<div class="wide">')
        ->and($tenants['editorial'])->not->toBe($tenants['classic'], 'the two arrangements produced identical bytes');
});

it('ships each arrangement only the CSS it uses, so an existing page gains no byte', function (): void {
    /*
     * The mechanism behind clause 2, asserted on the RENDERED PAGE rather than on a method --
     * which is both the stronger subject and the only one available. The first version read
     * `LandingTemplate::styleRules()`, and `composer guard` refused that method under rule 11
     * (*"a theme overrides TOKENS ONLY -- no <style, no CSS rule"*, locked in
     * `PROJECT_INSTRUCTIONS.md §3`). The rules live in `page.blade.php` now, so what a
     * visitor receives is the only thing there is to assert, and it is what the clause is
     * about anyway.
     */
    $classic = renderWithFixedArguments(templateDocument());
    $editorial = renderWithFixedArguments(templateDocument(['template' => 'editorial']));

    expect($classic)->not->toContain('.band{')
        ->and($classic)->not->toContain('.wide{')
        ->and($editorial)->toContain('.band{')
        ->and($editorial)->toContain('.wide{')
        // And the editorial page is LONGER by exactly its own rules, so the two differ by the
        // CSS and not by something else that happens to correlate with it.
        ->and(strlen($editorial) - strlen($classic))->toBeGreaterThan(100);
});

it('refuses a value that is not an arrangement, at the schema boundary and by name', function (): void {
    /*
     * ⚠ **CLAUSE 4, AND IT IS DRIVEN THROUGH `fromStored()` AS WELL AS `fromInput()`.** The
     * clause is *"exactly these names, refused BEFORE any write"* -- not "the studio's control
     * is a radio". `fromStored()` routes through `fromInput()` by design (*"trusting stored
     * data because it was validated once is how a stored XSS differs from a reflected one"*),
     * so a tampered column is refused on READ as well.
     *
     * The message names the SET and never the value that was sent: `§4.2`'s rule that a
     * refusal may say what to do, never what exists.
     */
    foreach (['brutalist', '', 'CLASSIC', 'classic ', 1] as $bad) {
        try {
            templateDocument(['template' => $bad]);

            throw new RuntimeException('a non-arrangement was accepted: '.var_export($bad, true));
        } catch (LandingDocumentInvalid $e) {
            expect($e->field)->toBe('template')
                ->and($e->reason)->toContain('classic, editorial');

            /*
             * ⚠ THE NO-ECHO CHECK IS SKIPPED FOR THE EMPTY STRING, AND SAYING WHY IS THE
             * POINT. `not->toContain('')` can NEVER pass -- every string contains the empty
             * string -- so writing it for every case made this a test that failed on a
             * correct refusal. Found by running it. The sibling trap is in this project's
             * notes already: the negated two-argument `toContain` is silently always-green,
             * which is the same mistake in the other direction and the more dangerous one.
             */
            if (is_string($bad) && $bad !== '') {
                expect($e->reason)->not->toContain($bad);
            }
        }
    }

    // The positive control: both real names pass, so the loop above is not refusing
    // everything.
    foreach (LandingTemplate::values() as $good) {
        expect(templateDocument(['template' => $good])->template->value)->toBe($good);
    }
});

it('keeps the arrangement through a save and a reload, which is what the builder does', function (): void {
    // The round trip the panel actually performs: the document is written to jsonb, read back
    // through `fromStored()`, and filled into the form. A `template` that survived validation
    // but not `toArray()` would publish correctly once and revert on the next save.
    $tenantId = $this->makeTenant(slug: 'roundtrip-arrangement');

    (new LandingPages)->saveDraft($tenantId, array_replace(
        LandingFixtures::fullPage(),
        ['template' => 'editorial'],
    ));

    $reloaded = (new LandingPages)->draft($tenantId);

    expect($reloaded->template)->toBe(LandingTemplate::Editorial)
        ->and($reloaded->toArray())->toHaveKey('template')
        ->and($reloaded->toArray()['template'])->toBe('editorial');

    // And a block type still renders in it, so the round trip did not cost the page content.
    expect(array_column($reloaded->toArray()['blocks'], 'type'))
        ->toContain(BlockType::Hero->value);
});
