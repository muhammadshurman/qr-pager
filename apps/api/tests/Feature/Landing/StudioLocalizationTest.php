<?php

declare(strict_types=1);

use App\Domain\Landing\BlockSchema;
use App\Domain\Landing\BlockType;
use App\Domain\Landing\ThemeTokens;
use App\Domain\Tenancy\TenantLanguage;
use App\Domain\Tenancy\TenantRole;
use App\Features\Landing\LandingPages;
use App\Features\Landing\MediaLibrary;
use App\Features\Landing\Panel\HasStudioLocale;
use App\Features\Landing\Panel\StudioPanelProvider;
use App\Features\Landing\TenantDomains;
use App\Features\Landing\TenantPageLanguage;
use App\Models\User;
use Filament\Panel;
use Tests\Support\ImageFixtures;
use Tests\Support\LandingFixtures;

/*
 * ================================================================================
 * `QRPAGER-41` PART A'S GATE — A TEST THAT FAILS ON AN UNLOCALIZED STRING
 * ================================================================================
 *
 * Muhammad's ticket, in his words: *"The gate for this item is a test that fails on an
 * unlocalized string, not a translation pass somebody has to re-audit by eye."* So this file
 * is the mechanism, and the translation is what makes it pass.
 *
 * The invariant it falsifies: **under `ar`, no owner-visible string in any of the four tabs
 * is English**, with an allow-list declared below that carries A REASON PER ENTRY and is
 * itself checked for dead entries.
 */

beforeEach(fn () => LandingFixtures::bucket());

/**
 * ================================================================================
 * THE ALLOW-LIST. A REASON PER ENTRY, AND NO ENTRY MAY BE DEAD.
 * ================================================================================
 *
 * Muhammad: *"Declare the allow-list with a reason per entry, not just values — a reason per
 * entry is what stops the list growing into a way around the test."* So every entry is a
 * pattern with the argument for it, and `it refuses a dead allow-list entry` requires each
 * one to actually match something in the rendered tabs. An entry that matches nothing is
 * either obsolete or was added to silence a finding, and both are the failure this list is
 * most at risk of.
 *
 * Every entry is one of two kinds, and the distinction is the principle:
 *
 *   **A NAME** — a proper noun that is not translated in any language.
 *   **A VALUE** — something the owner reads or types LITERALLY rather than as prose. The
 *   ticket's own examples are of this kind ("a URL, a hex value"): translating `PNG` or `px`
 *   would make the panel wrong, not more Arabic.
 *
 * Anything that is neither is copy, and copy belongs in `lang/`.
 *
 * @return array<string, string> pattern => reason
 */
function studioAllowList(): array
{
    return [
        // ---- NAMES ------------------------------------------------------------------
        /*
         * ⚠ THE RESTAURANT'S OWN NAME, WHICH `QRPAGER-48` PUT IN THE TOPBAR AND WHICH IS NOT
         * A TRANSLATABLE STRING AT ALL.
         *
         * `tenants.name` is the restaurant's, in whatever language they chose: *Al Baik* in
         * an Arabic panel is CORRECT, and translating it would be the defect. This matched
         * nothing before the chrome existed, because the panel never showed whose page was
         * being edited -- which is the hole `§12` made this ticket fix.
         *
         * Bounded to the harness's own shape (`Tenant <8 hex>`, `TestCase::makeTenant()`)
         * rather than allowing any capitalised word, so it cannot become a way around the
         * test for real untranslated copy.
         */
        '/^Tenant$/' => "the first word of the test harness's tenant NAME (`Tenant <8 hex>`), "
            .'which is the restaurant\'s own and never translated.',
        '/^Pager$/' => 'the second word of the brand name `QR Pager`. The enumeration splits on '
            .'whitespace, so the brand arrives here as two words and `QR` never reaches this list at '
            .'all -- it is two letters, below the three-letter floor. A brand is not translated in '
            .'any language, and the ticket names this one explicitly.',

        '/\bEnglish\b/' => 'the language picker\'s own option label. `§12`\'s ruling, which Muhammad '
            .'reaffirmed for this ticket: a restaurant choosing Arabic looks for العربية, and a picker '
            .'that renders every option in one language makes the choice harder. The option carries '
            .'`lang="en"` so a screen reader pronounces it correctly.',

        // ---- VALUES -----------------------------------------------------------------
        '/\bhttps?:\/\//' => 'a URL scheme. The owner types it into their browser and reads it in '
            .'`https://` — it is a literal, and the ticket allow-lists URLs by name.',

        '/[a-z0-9_-]+(\.[a-z0-9_-]+)+/i' => 'a hostname, a filename or a MIME type — the owner\'s own '
            .'`olive.example.com`, the platform fallback, `olive-room.png`, `image/png`, and the DNS '
            .'challenge record. Each is an identifier the owner must read or type character for '
            .'character; a translated hostname would not resolve.',

        '/#[0-9a-f]{3,8}\b/i' => 'a hex colour value. The ticket allow-lists it by name.',

        '/\b(PNG|JPEG|JPG|WebP|AVIF|SVG|GIF)\b/i' => 'an image format name. It is what the owner\'s own '
            .'file IS, and it appears beside their uploads as well as in the accepted-formats line. '
            .'`SVG` is also the name of the thing an SVG refusal is about.',

        '/\b(px|rem|em)\b/' => 'a CSS unit. These are the literals the owner types into a length '
            .'field — `1rem` is a value, not a word, and a translated unit is an invalid one.',

        '/\b(DNS|TXT|XML|UTF|DOCTYPE|SVG)\b/' => 'a record type or a format name the owner must type at '
            .'their DNS provider, or that names exactly what was wrong with a file. `TXT` is the '
            .'record they create; translating it would make the instruction unfollowable.',

        '/^qrpager-verification=[0-9a-f]+$/' => 'the DNS challenge VALUE itself. The owner copies it '
            .'character for character into a TXT record, and the verifier compares it byte for byte '
            .'-- the most literal literal on any of the four tabs.',
    ];
}

/**
 * A tenant whose OWN CONTENT IS ARABIC, which is what makes this test able to tell the
 * panel's copy from the restaurant's.
 *
 * ⚠ THE FIXTURE IS NOT `LandingFixtures::fullPage()` AS IT STANDS, and that is the point. That
 * page is authored in English — *"Welcome to Olive"*, *"Menu highlights"*, *"Find us"* — and
 * it renders inside the builder as each block row's summary. An enumeration run against it
 * reports the RESTAURANT's words as untranslated panel copy, which is a false positive that
 * would have had to be allow-listed, and allow-listing *"Welcome"* would have put a hole
 * through the middle of this test.
 *
 * So every authored text path is overwritten with Arabic, read from
 * `LandingFixtures::authoredTextPaths()` rather than listed here: a field added to the
 * fixture later is covered without an edit, which is the same totality the fuzz suite
 * depends on that list for.
 *
 * @return array<mixed>
 */
function arabicPage(?string $mediaId = null): array
{
    $document = LandingFixtures::fullPage($mediaId);

    foreach (LandingFixtures::authoredTextPaths() as $index => $path) {
        LandingFixtures::set($document, $path, 'نص تجريبي '.($index + 1));
    }

    /*
     * ⚠ ONE THEME COLOUR IS SET, AND `QRPAGER-41` PART B IS WHY. Item 5 replaced the six hex
     * TEXT FIELDS with a native picker, so the placeholder `#1a1a1a` that used to appear six
     * times on the theme tab appears nowhere -- and the allow-list's hex entry, which the
     * ticket itself named as an example, became DEAD. The dead-entry case caught it
     * immediately, which is the control working exactly as it was built to.
     *
     * The entry is kept rather than deleted, because a hex is still rendered: the picker's
     * `<output>` reports the chosen value, so an owner who HAS picked a colour sees one. So
     * the fixture now exercises that state instead, which also means the enumeration covers
     * a populated picker rather than only an empty one.
     */
    $document['theme']['color.background'] = '#2b2e33';

    return $document;
}

/**
 * The four tabs, rendered for an Arabic tenant with a full page, an image and a pending
 * domain — so the states that carry the most copy are actually on screen.
 *
 * @return array<string, string> path => HTML
 */
function studioTabsHtml(): array
{
    $tenant = test()->actingForTenant(role: TenantRole::Owner);

    /*
     * ⚠ THE SIGNED-IN ACCOUNT IS RENAMED, FOR THE SAME REASON THE PAGE IS AUTHORED IN ARABIC.
     * `TestCase::actingForTenant` names every user `Acting Staff`, and the panel renders that
     * name in its user menu on all four tabs. It is the ACCOUNT'S OWN NAME -- a restaurant
     * owner called "Sam Baker" would see exactly the same two Latin words -- so reporting it
     * as untranslated panel copy is a false positive, and allow-listing it would have put
     * `/^(Acting|Staff)$/` into a list whose whole purpose is to stay narrow.
     *
     * The name is changed rather than the shared helper, which the entire suite depends on.
     * Both the row and the authenticated instance are updated: Filament renders the name off
     * the in-memory user, so writing only the row would have left the English name on screen
     * and this comment claiming otherwise.
     */
    $user = User::query()->findOrFail(auth()->id());
    $user->forceFill(['name' => 'صاحب المطعم'])->save();
    test()->actingAs($user->fresh());

    $asset = (new MediaLibrary)->store($tenant, 'olive-room.png', ImageFixtures::png());

    (new LandingPages)->saveDraft($tenant, arabicPage($asset));

    app(TenantDomains::class)->add($tenant, 'olive.example.com');

    $html = [];

    foreach (['/studio', '/studio/media', '/studio/theme', '/studio/domains'] as $path) {
        $html[$path] = (string) test()->get($path)->getContent();
    }

    return $html;
}

/**
 * The text a reader actually sees: text nodes, with `<script>` and `<style>` removed.
 *
 * ⚠ TEXT NODES AND NOT THE RAW HTML. A panel document is mostly attributes — class names,
 * `wire:` directives, Alpine expressions and a Livewire snapshot that is a whole JSON
 * document — and every one of them is English by construction. Matching against the raw
 * HTML would report `flex flex-wrap` as untranslated copy, and the only way to make such a
 * test pass would be an allow-list long enough to let real findings through it.
 *
 * @return list<string>
 */
function visibleWords(string $html): array
{
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $xpath = new DOMXPath($document);

    foreach ($xpath->query('//script|//style') ?: [] as $node) {
        $node->parentNode?->removeChild($node);
    }

    $words = [];

    foreach ($xpath->query('//text()') ?: [] as $node) {
        foreach (preg_split('/\s+/', trim($node->textContent)) ?: [] as $word) {
            if ($word !== '') {
                $words[] = $word;
            }
        }
    }

    return $words;
}

it('leaves no English in any of the four tabs under ar', function (): void {
    $allowed = studioAllowList();
    $findings = [];

    foreach (studioTabsHtml() as $path => $html) {
        foreach (visibleWords($html) as $word) {
            if (! preg_match('/[A-Za-z]{3,}/', $word)) {
                continue;
            }

            foreach (array_keys($allowed) as $pattern) {
                if (preg_match($pattern, $word)) {
                    continue 2;
                }
            }

            $findings[] = $path.': '.$word;
        }
    }

    $findings = array_values(array_unique($findings));

    // str_contains-style message rather than `toBe([], $message)`: the finding list IS the
    // message, and printing it is what makes a failure actionable instead of a count.
    expect($findings)->toBe([], "untranslated strings in the studio:\n  ".implode("\n  ", $findings));
});

it('refuses a dead allow-list entry, so the list cannot grow into a way around the test', function (): void {
    /*
     * ⚠ THE GUARD ON THE GUARD. An allow-list entry that matches nothing is either obsolete
     * or was added to silence a finding that then moved; either way it is a hole nobody is
     * looking at. Requiring every entry to earn its place is what keeps the list honest, and
     * it is the reason the fixture above renders a pending domain and an uploaded image --
     * without them `TXT` and `PNG` would be dead entries and this case would say so.
     */
    $words = [];

    foreach (studioTabsHtml() as $html) {
        $words = [...$words, ...visibleWords($html)];
    }

    $dead = [];

    foreach (studioAllowList() as $pattern => $reason) {
        $matched = false;

        foreach ($words as $word) {
            if (preg_match($pattern, $word)) {
                $matched = true;

                break;
            }
        }

        if (! $matched) {
            $dead[] = $pattern;
        }
    }

    expect($dead)->toBe([], 'allow-list entries that match nothing: '.implode(', ', $dead));
});

it('gives every allow-list entry a reason long enough to be an argument', function (): void {
    // A one-word "reason" is a label, not an argument, and the instruction was explicit that
    // the reason is what stops the list growing. Thirty characters is not a quality bar; it
    // is the floor below which nobody has written one.
    foreach (studioAllowList() as $pattern => $reason) {
        expect(strlen($reason))->toBeGreaterThan(30, "the allow-list entry {$pattern} has no stated reason");
    }
});

it('mirrors every key between ar and en, in both directions', function (string $file): void {
    /*
     * Laravel falls back to the other locale's line for a missing key and raises nothing, so
     * a key present in `en` and absent from `ar` renders an ENGLISH SENTENCE inside an Arabic
     * panel — the exact defect this ticket removes, arriving one key at a time. The reverse
     * is just as bad on the English side.
     */
    $flatten = function (array $lines, string $prefix = '') use (&$flatten): array {
        $flat = [];

        foreach ($lines as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $flat = is_array($value) ? $flat + $flatten($value, $path) : $flat + [$path => $value];
        }

        return $flat;
    };

    $ar = $flatten(require lang_path("ar/{$file}.php"));
    $en = $flatten(require lang_path("en/{$file}.php"));

    expect(array_values(array_diff(array_keys($en), array_keys($ar))))
        ->toBe([], "keys in en/{$file}.php with no Arabic")
        ->and(array_values(array_diff(array_keys($ar), array_keys($en))))
        ->toBe([], "keys in ar/{$file}.php with no English");

    // And no key may be the SAME string in both, which is how an untranslated line hides:
    // it reads as present, it passes the parity check above, and it renders English.
    $untranslated = array_keys(array_filter(
        $en,
        static fn (string $line, string $key): bool => ($ar[$key] ?? null) === $line && ! preg_match('/^[\W\d]*$/u', $line),
        ARRAY_FILTER_USE_BOTH,
    ));

    expect($untranslated)->toBe([], "identical in both languages, so one of them is untranslated: {$file}");
})->with(['studio', 'landing']);

it('has a label for every field, token, font stack and block type the panel can render', function (): void {
    /*
     * ⚠ TOTALITY, WHICH THE DERIVED LABELS DID NOT NEED AND COULD NOT HAVE HAD. Before this
     * ticket `StudioForm::label()` was `ucfirst(str_replace(...))` over the field name, so
     * every field had a label by construction — including a field added tomorrow. A lookup
     * does not: a missing key renders as `studio.fields.whatever`, visible only to whoever
     * opens that tab. This case is the replacement for that guarantee, and it is read out of
     * `BlockSchema` and `ThemeTokens` rather than listed here, so it cannot go stale.
     */
    $missing = [];

    foreach (['ar', 'en'] as $locale) {
        foreach (BlockType::cases() as $type) {
            if (__('studio.blocks.'.$type->value, [], $locale) === 'studio.blocks.'.$type->value) {
                $missing[] = "{$locale}: studio.blocks.{$type->value}";
            }

            foreach (BlockSchema::fields($type) as $name => $field) {
                foreach ([$name => true, ...array_fill_keys(array_keys($field->item ?? []), true)] as $fieldName => $unused) {
                    if (__('studio.fields.'.$fieldName, [], $locale) === 'studio.fields.'.$fieldName) {
                        $missing[] = "{$locale}: studio.fields.{$fieldName}";
                    }
                }
            }
        }

        foreach (array_keys(ThemeTokens::NAMES) as $name) {
            if (__('studio.tokens.'.$name, [], $locale) === 'studio.tokens.'.$name) {
                $missing[] = "{$locale}: studio.tokens.{$name}";
            }
        }

        foreach (array_keys(ThemeTokens::STACKS) as $stack) {
            if (__('studio.fonts.'.$stack, [], $locale) === 'studio.fonts.'.$stack) {
                $missing[] = "{$locale}: studio.fonts.{$stack}";
            }
        }
    }

    $missing = array_values(array_unique($missing));

    expect($missing)->toBe([], "labels the panel would render as a raw key:\n  ".implode("\n  ", $missing));
});

it('puts the locale on every page the panel registers, including any added later', function (): void {
    /*
     * `StudioLocale` is a middleware and a Livewire update never runs the panel's middleware
     * — `HasStudioLocale`'s docblock carries the measurement. So the trait is what makes an
     * ACTION speak the tenant's language, and a fifth page that forgets it would look correct
     * until the first notification. Read off the panel rather than listed, so adding a page
     * is what makes this case cover it.
     */
    $panel = (new StudioPanelProvider(app()))->panel(Panel::make());

    $without = [];

    foreach ($panel->getPages() as $page) {
        if (! in_array(HasStudioLocale::class, class_uses_recursive($page), true)) {
            $without[] = $page;
        }
    }

    expect($panel->getPages())->not->toBe([])
        ->and($without)->toBe([], 'studio pages that would render an action in the wrong language: '.implode(', ', $without));
});

it('follows the tenant row, so English mirrors cleanly and dir follows with it', function (): void {
    /*
     * The other half of the ticket's clause: *"English stays available and must mirror
     * cleanly."* One tenant, one column changed, both documents measured — which also proves
     * the panel's DIRECTION comes from the locale rather than from any template, the property
     * `PlatformPanelAccessibilityTest` asserts for the other panel.
     */
    $tenant = $this->actingForTenant(role: TenantRole::Owner);

    expect(app(TenantPageLanguage::class)->current($tenant))->toBe(TenantLanguage::DEFAULT)
        ->and(TenantLanguage::DEFAULT)->toBe(TenantLanguage::Arabic);

    $arabic = (string) $this->get('/studio')->getContent();

    app(TenantPageLanguage::class)->set($tenant, TenantLanguage::English);

    $english = (string) $this->get('/studio')->getContent();

    expect($arabic)->toMatch('/<html[^>]*\blang="ar"/')
        ->and($arabic)->toMatch('/<html[^>]*\bdir="rtl"/')
        ->and($arabic)->toContain(__('studio.page.status_heading', [], 'ar'))
        ->and($english)->toMatch('/<html[^>]*\blang="en"/')
        ->and($english)->toMatch('/<html[^>]*\bdir="ltr"/')
        ->and($english)->toContain(__('studio.page.status_heading', [], 'en'));
});
