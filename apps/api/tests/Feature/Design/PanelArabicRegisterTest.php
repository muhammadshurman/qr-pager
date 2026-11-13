<?php

declare(strict_types=1);

/*
 * ================================================================================
 * THE PANELS' ARABIC IS MODERN STANDARD — PR #22 walkthrough, defect 3
 * ================================================================================
 *
 * Muhammad, reviewing PR #22: *"«لغة الاستوديو — اللي بتقراه إنت» is Jordanian colloquial.
 * Every owner-facing and admin-facing string in both panels is فصحى. That copy is mine, from
 * my artboards, so this is my error propagated into the build."* And: *"This is a rule, not
 * a pass."*
 *
 * Fourteen strings were rewritten when this landed. The rule is here so a fifteenth cannot
 * arrive quietly — from an artboard, from a hurried fix, or from anyone writing the way they
 * speak.
 *
 * ================================================================================
 * ⚠⚠ WHAT THIS CHECK CAN AND CANNOT DO — READ BEFORE TRUSTING IT
 * ================================================================================
 *
 * **It matches a NAMED LIST of dialect words at word boundaries. It does not enforce
 * register.** No test here can: register is a property of grammar and idiom across a whole
 * sentence, and a fluent writer can produce colloquial Arabic using none of the words below.
 * So a green run means *"none of the known markers appear"* and NOT *"this copy is فصحى"*.
 * Muhammad asked for exactly this distinction to be stated rather than papered over.
 *
 * What it does catch is the failure that actually happened: Levantine function words and
 * `ب-` prefixed present-tense verbs pasted from an artboard. That is a narrow net over a
 * real and recurring source, which is worth more than a claim it cannot honour.
 *
 * ⚠ WORD BOUNDARIES, AND THE FIRST VERSION HAD NONE. A bare `str_contains` reported
 * `domains.hostname_help` and `domains.issuing_certificate` — both correct فصحى — because
 * «هاي» sits inside «النهاية» and «هاد» inside «شهادة». A marker list with false positives
 * is a check somebody turns off, so every marker is matched as a whole word.
 *
 * SCOPE: the two Filament panels only — `lang/ar/*`. The customer-facing PWA's copy
 * (`apps/web/src/copy/ar.ts`) is deliberately NOT covered here; its register is a separate
 * ruling Muhammad has reserved.
 */

/**
 * Levantine markers, as whole words.
 *
 * @return list<string>
 */
function colloquialMarkers(): array
{
    return [
        // Function words and pronouns.
        'اللي', 'يلي', 'إنت', 'انت', 'مش', 'هاد', 'هاي', 'هيك', 'هاظ',
        'عشان', 'كمان', 'شو', 'ليش', 'هلق', 'هلأ', 'هسه', 'لهيك',
        // Wanting, in dialect.
        'بدك', 'بدها', 'بدهم', 'بدي', 'بدنا',
        // Quantity and quality, in dialect.
        'منيح', 'كتير', 'شوي', 'شوية', 'بس',
        // "like" as a preposition, and the borrowed English tab.
        'زي', 'تاب',
        // Dialect plurals and possessives this copy has used.
        'زباين', 'زبونك', 'لحالنا', 'لحاله', 'فيك', 'فيكي', 'فينا',
        // `ب-` prefixed present tense — the single commonest tell.
        'بتقراه', 'بيقروه', 'بتظهر', 'بيظهر', 'بينزل', 'بيشيله', 'بيمسح',
        'بيصير', 'بتطلع', 'بتشوف', 'بتقدر', 'بيقدر',
        // `م-` prefixed first person plural.
        'منفحص', 'منقلك', 'منشوف', 'منعمل',
        // Colloquial imperative, where فصحى takes a jussive form.
        'اختار', 'ارجاع',
        // Aspect markers.
        'عم', 'رح',
    ];
}

/**
 * Every Arabic string the panels can show, flattened to `a.b.c` keys.
 *
 * ⚠ DERIVED FROM THE FILES, NOT LISTED. A new catalogue under `lang/ar` is covered the
 * moment it exists, which is the property that makes this a rule rather than a snapshot.
 *
 * @return array<string, string>
 */
function panelArabicStrings(): array
{
    $flatten = static function (array $items, string $prefix = '') use (&$flatten): array {
        $out = [];

        foreach ($items as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $out += $flatten($value, $path);

                continue;
            }

            if (is_string($value)) {
                $out[$path] = $value;
            }
        }

        return $out;
    };

    $all = [];

    foreach (glob(lang_path('ar/*.php')) ?: [] as $file) {
        /** @var array<mixed> $catalogue */
        $catalogue = require $file;

        $all += $flatten($catalogue, basename($file, '.php'));
    }

    return $all;
}

it('reads every panel catalogue, so this rule cannot pass by finding nothing', function (): void {
    $strings = panelArabicStrings();

    // The two catalogues that exist today, named so a glob that silently stops resolving
    // fails here rather than making every case below vacuous.
    expect(count($strings))->toBeGreaterThan(200)
        ->and($strings)->toHaveKey('studio.chrome.panel_language_legend')
        ->and($strings)->toHaveKey('landing.media.in_use_as_logo');
});

it('carries no colloquial marker in either panel catalogue', function (): void {
    $pattern = '/(?<![\p{Arabic}])('
        .implode('|', array_map(static fn (string $word): string => preg_quote($word, '/'), colloquialMarkers()))
        .')(?![\p{Arabic}])/u';

    $offenders = [];

    foreach (panelArabicStrings() as $key => $value) {
        if (preg_match_all($pattern, $value, $found) > 0) {
            $offenders[] = $key.' ['.implode(' ', array_unique($found[1])).'] — '.$value;
        }
    }

    expect($offenders, "these panel strings carry Levantine markers; the panels' Arabic is "
        .'فصحى (PR #22 walkthrough, defect 3). Rewrite them, or — if a marker here is wrong — '
        .'correct the list rather than the copy')->toBe([]);
});

it('finds a planted colloquial string, so the matcher is not matching nothing', function (): void {
    /*
     * ⚠ THE CONTROL. A marker list is a grep, and a grep that silently stopped matching
     * would make the case above pass over copy nobody had checked — the exact shape
     * `MembershipCallSiteTest`'s planted call site exists for. This plants the sentence
     * Muhammad actually found and asserts it is caught.
     */
    $pattern = '/(?<![\p{Arabic}])('
        .implode('|', array_map(static fn (string $word): string => preg_quote($word, '/'), colloquialMarkers()))
        .')(?![\p{Arabic}])/u';

    expect(preg_match($pattern, 'لغة الاستوديو — اللي بتقراه إنت'))->toBe(1);
    expect(preg_match($pattern, 'اختار ثيم جاهز، وبعدها عدّل عليه زي ما بدك.'))->toBe(1);

    // And it does NOT fire on the فصحى those were rewritten to, nor on the two strings a
    // boundary-less version falsely reported.
    expect(preg_match($pattern, 'لغة الاستوديو — ما تقرؤه أنت'))->toBe(0);
    expect(preg_match($pattern, 'اختَر ثيمًا جاهزًا، ثم عدّله كما تشاء.'))->toBe(0);
    expect(preg_match($pattern, 'اسم المضيف فقط — بلا https:// وبلا شرطة مائلة في النهاية.'))->toBe(0);
    expect(preg_match($pattern, 'نُصدر شهادة — يستغرق ذلك عادة بضع دقائق.'))->toBe(0);
});

/*
 * ================================================================================
 * NO FIELD PATH AND NO ENGLISH REASON REACHES THE OWNER — `R-262`
 * ================================================================================
 *
 * `LandingDocumentInvalid` carries a dotted path and an English sentence, both written for a
 * programmer. Five places put that pair in front of the owner verbatim — the publish
 * refusal, the preview refusal, the theme tab's save, the builder's save and publish, and
 * the status plate, which built its sentence by CONCATENATING English past the translator.
 * Muhammad met the result in Arabic: «seo.og_image لا يمكن نشره: names an image that is not
 * in your library».
 *
 * ⚠ THE RULE, NOT THE FIVE INSTANCES. A sixth display site is one `->body($e->reason)` away,
 * and it would read correctly in review — which is how the first five got there.
 */
it('renders no raw field path and no raw reason from an invalid document', function (): void {
    $sources = array_merge(
        glob(app_path('Features/Landing/Panel/Pages/*.php')) ?: [],
        glob(app_path('Features/Landing/Panel/*.php')) ?: [],
        glob(app_path('Features/Landing/*.php')) ?: [],
    );

    expect(count($sources))->toBeGreaterThan(10, 'the globs found almost nothing to check');

    $offenders = [];
    $examined = 0;

    foreach ($sources as $path) {
        // `InvalidField` is the one place allowed to read the pair: it is what turns it into
        // a sentence an owner can act on.
        if (str_ends_with($path, 'InvalidField.php')) {
            continue;
        }

        $code = (string) preg_replace(['#/\*[\s\S]*?\*/#', '#//[^\n]*#'], ' ', (string) file_get_contents($path));

        /*
         * ⚠ SCOPED TO `catch (LandingDocumentInvalid …)` BLOCKS, AND THE FIRST VERSION WAS
         * NOT. It flagged every `->body($e->reason)` in the panel and reported four sites
         * that are CORRECT: `MediaRejected` and `PublishFailed` carry reasons this
         * application already translated, and refusing those would have meant deleting good
         * messages to satisfy a grep. Only `LandingDocumentInvalid` carries English prose
         * and a dotted path built for a programmer, so only its blocks are the subject.
         */
        preg_match_all('/catch\s*\(\s*LandingDocumentInvalid\s+\$(\w+)\s*\)\s*\{/', $code, $catches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($catches as $catch) {
            $examined++;
            $variable = $catch[1][0];
            $open = $catch[0][1] + strlen($catch[0][0]);
            $depth = 1;
            $i = $open;

            while ($i < strlen($code) && $depth > 0) {
                $depth += match ($code[$i]) {
                    '{' => 1, '}' => -1, default => 0
                };
                $i++;
            }

            $body = substr($code, $open, $i - $open);

            foreach ([
                '/->body\(\s*\$'.$variable.'->reason\s*\)/' => 'shows ->reason as a notification body',
                '/->title\(\s*\$'.$variable.'->reason\s*\)/' => 'shows ->reason as a notification title',
                "/'field'\s*=>\s*\\\$".$variable.'->field/' => 'passes ->field to a translator',
                "/'reason'\s*=>\s*\\\$".$variable.'->reason/' => 'passes ->reason to a translator',
                '/\$'.$variable.'->(field|reason)\s*\./' => 'concatenates the pair into a string',
                '/=>\s*\$'.$variable.'->(field|reason)\b/' => 'hands the pair to a view',
            ] as $pattern => $what) {
                if (preg_match($pattern, $body) === 1) {
                    $offenders[] = basename($path).' '.$what;
                }
            }
        }
    }

    // Not vacuous: the panel really does catch this exception, in several places.
    expect($examined)->toBeGreaterThan(3, 'no LandingDocumentInvalid catch block was found at all');

    expect($offenders, 'these put a programmer-facing field path or English reason in front '
        .'of the owner (R-262). Use `InvalidField::sentence()`, which names the tab, the '
        .'field and the next action')->toBe([]);
});
