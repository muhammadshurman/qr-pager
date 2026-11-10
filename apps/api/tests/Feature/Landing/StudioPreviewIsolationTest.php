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

it('renders the preview as an iframe on all four tabs, for an owner with a saved draft', function (): void {
    foreach (isolationTabs() as $path => $html) {
        $xpath = isolationDom($html);

        $frames = $xpath->query('//iframe[@data-studio-preview="true"]');

        expect($frames?->length)->toBe(1, "{$path} does not render exactly one preview iframe");

        /*
         * ⚠ AND THE RENDERER'S OUTPUT IS NOT IN THIS DOCUMENT. An iframe that exists beside
         * an inlined copy would pass the count above and fail the point of it, so what is
         * asserted is the absence of the published page's own markers from the panel.
         */
        /*
         * ⚠ COUNTED, NOT SEARCHED FOR A STRING WITH AN ATTRIBUTE IN IT. This was
         * `str_contains($html, '<html lang=')`, and the `§11` SECURITY round showed that it
         * passed only because Filament's own `base.blade.php` happens to put a NEWLINE
         * between `<html` and `lang=`: vendor formatting, one `prettier` run away from
         * making this assertion vacuous. Counting the opening tag depends on no attribute
         * and on no whitespace.
         */
        expect(substr_count($html, '<html'))->toBe(1, "{$path} carries a nested document");
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

it('serves the framed preview under a policy that permits it nothing', function (): void {
    /*
     * ================================================================================
     * ⚠ THE ELEMENT IS NOT THE CONTROL, AND THE `§11` SECURITY ROUND OF 2026-11-10 SAID SO
     * ================================================================================
     *
     * *"What this file asserts is a security property; what actually holds it is elsewhere.
     * The frame is same-origin and carries no `sandbox` attribute … the thing that keeps
     * tenant content from reaching `parent.document` is the child response's
     * `default-src 'none'`."* Correct, and the iframe count above says nothing about it — a
     * reader of this file took the count for the control, which is how a control disappears
     * while its test stays green.
     *
     * So the policy is asserted HERE, beside the structure it depends on. `PreviewFramingTest`
     * asserts the same directive for the clickjacking half; this is not a duplicate of it but
     * the other half of A1's claim: tenant-authored CSS renders in a document that may load
     * nothing, run nothing and submit nowhere.
     */
    $tenant = test()->actingForTenant(role: TenantRole::Owner);

    (new LandingPages)->saveDraft($tenant, LandingFixtures::fullPage());

    $policy = (string) test()->get('/studio/preview')->headers->get('Content-Security-Policy');

    expect($policy)->not->toBe('', 'the framed preview is served with no policy at all');

    $directives = [];

    foreach (array_filter(array_map('trim', explode(';', $policy))) as $directive) {
        $parts = preg_split('/\s+/', $directive, 2) ?: [];
        $directives[$parts[0]] = $parts[1] ?? '';
    }

    expect($directives['default-src'] ?? null)
        ->toBe("'none'", 'the framed preview may load something, so tenant CSS is not contained by it')
        ->and(array_key_exists('script-src', $directives))
        ->toBeFalse('the framed preview declares a script source, which `default-src` would otherwise refuse')
        ->and($directives['frame-ancestors'] ?? null)
        ->toBe("'self'", 'the pane may be framed cross-origin, which is the other half of this control');
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

it('marks every literal control on the page tab and no prose control — A3', function (): void {
    /*
     * ================================================================================
     * ⚠ THE CASE THE `§11` QA ROUND PROVED WAS MISSING, AND ITS REVERT PROOF
     * ================================================================================
     *
     * QA H-3, 2026-11-10: *"the five `FieldKind` marks have no test anywhere; all five can be
     * deleted with the suite green."* Proven there by revert — `tests/Feature/Landing`,
     * `Feature/Design` and `Unit` came to **279 passed, zero failures** with every
     * `->extraInputAttributes($literal)` removed. The only case that looked at the mark read
     * `//input[@type="text"]` on `/studio/theme`, a node set that CANNOT CONTAIN a `url`,
     * `email`, `tel`, `number` or `select` control. So A3's five delivered roles were
     * asserted by nothing.
     *
     * ⚠ AND THE OLD CASE'S STATED REASON FOR CHOOSING THAT TAB WAS FALSE, WHICH IS WHY THIS
     * ONE EXISTS RATHER THAN BEING AN EXTRA ASSERTION ON IT. It claimed the page tab's
     * literal fields *"live inside blocks, which `QRPAGER-40` renders collapsed, so nothing
     * would be marked"*. Measured on this fixture: `/studio` renders **38** controls with a
     * `wire:model`, of which **8** carry the mark — 3 `url`, 3 `number`, 1 `email`, 1 `tel`.
     * A collapsed section still renders its inputs. The field count made that sentence easy
     * to believe and the DOM refutes it.
     *
     * BY INPUT TYPE, which is the one mapping that cannot drift from the decision: `StudioForm`
     * chooses the HTML type FROM the `FieldKind`, so `url`/`email`/`tel`/`number` are exactly
     * the literal kinds on this tab and `text` is `FieldKind::Text`. Both halves are asserted
     * non-vacuously, so a mark cannot be lost and a prose field cannot gain one.
     */
    $xpath = isolationDom(isolationTabs()['/studio']);

    /** @var array<string, array{marked: int, unmarked: int}> $byType */
    $byType = [];

    foreach ($xpath->query('//input[@*[starts-with(name(), "wire:model")]]') ?: [] as $input) {
        if (! $input instanceof DOMElement) {
            continue;
        }

        $type = $input->getAttribute('type');

        // `radio` is the template chooser and `hidden`/`checkbox` hold no literal a reader
        // sees, so neither is in A3's scope. Named rather than filtered silently.
        if (in_array($type, ['radio', 'checkbox', 'hidden', ''], true)) {
            continue;
        }

        $key = str_contains($input->getAttribute('class'), 'studio-ltr') ? 'marked' : 'unmarked';
        $byType[$type] ??= ['marked' => 0, 'unmarked' => 0];
        $byType[$type][$key]++;
    }

    foreach (['url', 'email', 'tel', 'number'] as $type) {
        expect(($byType[$type]['marked'] ?? 0) > 0)
            ->toBeTrue("no [{$type}] control on /studio is marked, so this case proves nothing about that kind")
            ->and($byType[$type]['unmarked'] ?? 0)
            ->toBe(0, "a [{$type}] control is NOT marked, and its `FieldKind` says its value is a literal");
    }

    expect(($byType['text']['unmarked'] ?? 0) > 0)
        ->toBeTrue('no prose control is rendered, so the half that protects the owner\'s Arabic proves nothing')
        ->and($byType['text']['marked'] ?? 0)
        ->toBe(0, 'a prose control carries the literal mark, which renders the owner\'s own sentences backwards');

    /*
     * ⚠ AND THE MEDIA CHOOSER, WHOSE MARK IS ON THE WRAPPER BECAUSE IT HAS NOWHERE ELSE TO GO.
     * QA H-2 measured that `extraInputAttributes` is discarded by a non-native `Select`
     * (`Select.php:1925` spreads the bag only `if ($isNative)`), so A3's media role shipped as
     * a no-op while the enum case asserted the decision was `true`. This is the assertion that
     * the delivery and the decision agree.
     */
    $wrappers = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' studio-ltr-select ')]");

    expect(($wrappers?->length ?? 0) > 0)
        ->toBeTrue('no media chooser carries the select mark, so `FieldKind::Media` decides `true` and delivers nothing');
});

it('marks exactly the four length tokens on the theme tab, by name — A3', function (): void {
    /*
     * ⚠ SET EQUALITY, AND THE CASE THIS REPLACES COULD ONLY FAIL FOR A FIELD NAMED `radius`.
     * QA M-1, 2026-11-10: it asserted
     * `str_contains($name, 'seo_') || ! str_contains($name, 'radius')`, which is
     * `false || !false` for every other unmarked literal — *"marking only `radius` → PASS;
     * marking everything except `radius` → fails naming `data.theme_radius_small`"*. Two of
     * the four length tokens were unguarded, and `§1`'s own ruling of the same day says an
     * invariant is a SET EQUALITY and not a count.
     *
     * The four names are the `ThemeTokenKind::Length` tokens. A fifth arriving unmarked, or
     * any of these four losing its mark, is one red naming the set difference.
     */
    $xpath = isolationDom(isolationTabs()['/studio/theme']);

    $marked = [];

    foreach ($xpath->query('//input[@*[starts-with(name(), "wire:model")]]') ?: [] as $input) {
        if (! $input instanceof DOMElement || ! str_contains($input->getAttribute('class'), 'studio-ltr')) {
            continue;
        }

        $path = $input->getAttribute('wire:model')
            ?: ($input->getAttribute('wire:model.live') ?: $input->getAttribute('wire:model.blur'));
        $segments = explode('.', (string) $path);
        $marked[] = end($segments);
    }

    sort($marked);

    expect($marked)->toBe([
        'theme_radius_large',
        'theme_radius_small',
        'theme_size_heading',
        'theme_space_section',
    ], 'the set of marked length tokens changed, or a length token arrived unmarked');

    /*
     * AND THE FONT CHOICES ARE SELECTS AND MUST *NOT* BE MARKED: `ThemeTokenKind::FontChoice`
     * decides `false`, because what the owner reads there is a font's NAME in his own
     * language, not a value typed character by character. This is the half that would redden
     * if somebody marked every select rather than the kinds that hold a literal.
     */
    $wrappers = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' studio-ltr-select ')]");

    expect($wrappers?->length)->toBe(0, 'a font choice is marked as holding a literal, and its value is a name');
});
