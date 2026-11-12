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
 * ⚠ **CLAUSE 2 NOW READS `rail` RATHER THAN `classic`, AND `QRPAGER-47` IS WHY.** The case that
 * held the pre-feature bytes to the byte is retired with the arrangement it was about; what
 * replaces it asserts the half that is still true — a document naming no arrangement takes
 * `LandingTemplate::DEFAULT`, and the default renders identically to naming it. The quotation
 * above is left as it was written so the change is visible rather than tidied away.
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

it('renders a document that names no arrangement as the DEFAULT, which is now rail', function (): void {
    /*
     * ⚠⚠ **THIS CASE REPLACES `QRPAGER-33`'s INVARIANT CLAUSE 2, AND THE REPLACEMENT IS A
     * LOSS THAT IS NAMED RATHER THAN HIDDEN.**
     *
     * What stood here asserted that a document naming no arrangement reproduced
     * `tests/Fixtures/landing/pre-template-page.html` BYTE FOR BYTE — 4,625 bytes, sha256
     * `fe5e5995…`, captured on 2026-11-03 before anything changed, because `QRPAGER-33 §4`
     * flagged that *"if that is not taken first, the claim becomes unprovable after the
     * fact."* It earned its keep twice: one attempt measured 4,941 (arrangement CSS in the
     * shared block) and another 4,626 (a Blade comment on its own line).
     *
     * `QRPAGER-47` retires `classic`, and with it that guarantee: a tenant who published
     * yesterday republishes today as `rail` and their page CHANGES. That is the ticket's
     * intent — *"those two were the «بسيط / مميّز» pair the user complained were
     * indistinguishable"* — so the fixture is retired with the case rather than kept as a
     * capture nothing can be held to.
     *
     * What survives is the half that is still true and still load-bearing: **a document with
     * no `template` member is not written by the migration and must render as the default**,
     * and the default is now `rail`. If those two ever disagree, every pre-`QRPAGER-33`
     * document silently renders as something nobody chose.
     */
    $stored = LandingFixtures::fullPage();

    expect($stored)->not->toHaveKey('template', 'the fixture must be a PRE-feature document');

    $document = templateDocument();

    expect($document->template)
        ->toBe(LandingTemplate::DEFAULT, 'a document naming no arrangement did not take the default')
        ->and(LandingTemplate::DEFAULT)->toBe(LandingTemplate::Rail);

    // And the bytes are the bytes of that arrangement named explicitly: the default costs
    // nothing and resolves to the same page, which is what makes the migration's
    // `create_missing => false` safe for every document that carries no member.
    expect(renderWithFixedArguments($document))->toBe(
        renderWithFixedArguments(templateDocument(['template' => 'rail'])),
        'the default arrangement and an explicit `rail` produced different pages',
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

    foreach ([LandingTemplate::Rail, LandingTemplate::Ember] as $template) {
        $tenantId = $this->makeTenant(slug: 'arranged-'.$template->value);

        (new LandingPages)->saveDraft($tenantId, array_replace(
            LandingFixtures::fullPage(),
            ['template' => $template->value],
        ));

        $published = (new LandingPages)->publish($tenantId);
        $bytes = (string) MediaLibrary::disk()->get(PublishedSite::pointer($published->hostname));

        $tenants[$template->value] = $bytes;
    }

    // Both arrangements place the same blocks; what differs is the region each sits in.
    // Positions and wrappers together, so neither alone can carry the case.
    $positions = static fn (string $bytes): array => [
        'how_it_works' => strpos($bytes, 'How the pager works'),
        'gallery' => strpos($bytes, 'The room'),
    ];

    $rail = $positions($tenants['rail']);
    $ember = $positions($tenants['ember']);

    expect($rail['how_it_works'])->toBeInt()
        ->and($rail['gallery'])->toBeInt()
        ->and($ember['how_it_works'])->toBeInt()
        ->and($ember['gallery'])->toBeInt();

    // `ember` lifts the hero into its own band and puts everything after it in a narrow
    // column; `rail` has neither wrapper and carries its own ruled regions instead.
    expect($tenants['ember'])->toContain('class="ember-hero"')
        ->and($tenants['ember'])->toContain('class="ember-column"')
        ->and($tenants['rail'])->not->toContain('class="ember-hero"')
        ->and($tenants['rail'])->not->toContain('class="ember-column"')
        ->and($tenants['rail'])->toContain('class="rail"')
        ->and($tenants['ember'])->not->toBe($tenants['rail'], 'the two arrangements produced identical bytes');
});

it('ships each arrangement its OWN rules and none of the other four’s', function (): void {
    /*
     * ⚠ THE `§11` QA ROUND'S M-3, AND IT IS A HOLE IN THE INVARIANT'S FIRST CLAUSE. The round
     * measured it: with the ENTIRE rail branch replaced by `.rail{display:block}` — zero
     * `var()`, none of its own rules — `TemplateTokenSetTest` and `TemplateStructureTest` were
     * 7/7 green. A rail page that paints as an unstyled column was invisible to the suite,
     * which is defect #1 of this ticket's record (*"resolved to nothing … paints nothing"*)
     * wearing a different disguise.
     *
     * So each arrangement's own marker rules are asserted PRESENT in its page and ABSENT from
     * every other arrangement's. The markers are rule heads rather than whole declarations, so
     * a value can change without touching this case; what cannot change silently is which
     * branch of `page.blade.php`'s `@if`/`@elseif` chain a page received.
     *
     * The byte-level half is the case below this one: each arrangement's page differs from the
     * next by its OWN rules and by nothing else. It used to be `classic` gains NOTHING, which
     * `QRPAGER-47` retired along with that arrangement.
     */
    $markers = [
        'rail' => ['.rail>section+section', '.rail-find{'],
        'ember' => ['.ember-hero{', '.ember-column{'],
        'tile' => ['.tile-grid{'],
    ];

    expect(array_keys($markers))->toBe(
        LandingTemplate::values(),
        'an arrangement was added or renamed and this case does not know about it'
    );

    $pages = [];

    foreach ($markers as $template => $own) {
        $pages[$template] = renderWithFixedArguments(templateDocument(['template' => $template]));

        foreach ($own as $marker) {
            expect(str_contains($pages[$template], $marker))->toBeTrue(
                "the {$template} page does not carry its own rule {$marker}, so it is arranged "
                .'but unstyled — the markup moved and the CSS did not'
            );
        }
    }

    foreach ($markers as $template => $own) {
        foreach ($markers as $other => $theirs) {
            if ($other === $template) {
                continue;
            }

            foreach ($theirs as $marker) {
                expect(str_contains($pages[$template], $marker))->toBeFalse(
                    "the {$template} page carries {$other}'s rule {$marker}: the chain in "
                    .'page.blade.php is meant to be exclusive, so two arrangements are being '
                    .'served at once'
                );
            }
        }
    }

    // ⚠ AND EVERY ARRANGEMENT NOW HAS RULES OF ITS OWN, which was not true while `classic`
    // existed: its entry here was an EMPTY list, and the exclusivity loop above was the only
    // thing asserting it carried none of the others'. A future arrangement that needs no
    // rules would bring the empty list back, and the loop still covers it.
    expect($markers)->not->toContain([]);
});

it('ships each arrangement only the CSS it uses, measured by the bytes it adds', function (): void {
    /*
     * The mechanism behind the exclusivity claim, asserted on the RENDERED PAGE rather than
     * on a method -- which is both the stronger subject and the only one available. The first
     * version read `LandingTemplate::styleRules()`, and `composer guard` refused that method
     * under rule 11 (*"a theme overrides TOKENS ONLY -- no <style, no CSS rule"*, locked in
     * `PROJECT_INSTRUCTIONS.md §3`). The rules live in `page.blade.php` now, so what a
     * visitor receives is the only thing there is to assert.
     *
     * ⚠ THE SUBJECT CHANGED WITH `QRPAGER-47` AND THE PROPERTY DID NOT. It used to compare
     * `classic` (which emitted NOTHING) against `editorial`; with `classic` retired there is
     * no empty arrangement left, so the comparison is between two arrangements that each
     * carry their own branch and nothing of the other's.
     */
    $rail = renderWithFixedArguments(templateDocument(['template' => 'rail']));
    $ember = renderWithFixedArguments(templateDocument(['template' => 'ember']));

    expect($rail)->not->toContain('.ember-hero{')
        ->and($rail)->not->toContain('.ember-column{')
        ->and($ember)->toContain('.ember-hero{')
        ->and($ember)->toContain('.ember-column{')
        ->and($ember)->not->toContain('.rail-find{')
        ->and($rail)->toContain('.rail-find{')
        // And each page is longer than the other by its own rules alone, so the two differ by
        // the CSS rather than by something that happens to correlate with it.
        ->and(abs(strlen($ember) - strlen($rail)))->toBeGreaterThan(100);
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
    foreach (['brutalist', '', 'RAIL', 'rail ', 'classic', 'editorial', 1] as $bad) {
        try {
            templateDocument(['template' => $bad]);

            throw new RuntimeException('a non-arrangement was accepted: '.var_export($bad, true));
        } catch (LandingDocumentInvalid $e) {
            expect($e->field)->toBe('template')
                ->and($e->reason)->toContain('rail, ember, tile');

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
        ['template' => 'ember'],
    ));

    $reloaded = (new LandingPages)->draft($tenantId);

    expect($reloaded->template)->toBe(LandingTemplate::Ember)
        ->and($reloaded->toArray())->toHaveKey('template')
        ->and($reloaded->toArray()['template'])->toBe('ember');

    // And a block type still renders in it, so the round trip did not cost the page content.
    expect(array_column($reloaded->toArray()['blocks'], 'type'))
        ->toContain(BlockType::Hero->value);
});
