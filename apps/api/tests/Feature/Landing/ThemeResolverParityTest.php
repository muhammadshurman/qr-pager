<?php

declare(strict_types=1);

use App\Domain\Landing\LandingTemplate;
use App\Domain\Tenancy\TenantRole;
use App\Features\Landing\LandingPages;
use App\Features\Landing\PublishedSite;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LandingFixtures;
use Tests\Support\SentinelTheme;

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
 * today is a fact about today; the SECOND and THIRD cases below are what keep it one resolver,
 * by counting the emitter's callers rather than trusting that nobody adds another.
 *
 * ⚠ **AND THE SUBJECT IS THE STORED BYTES, NOT A SECOND RENDER.** The published half is read
 * back out of the object store — the pointer `apps/site` serves — so what is compared with the
 * preview is what a visitor receives, not what this test could persuade the renderer to produce.
 */

beforeEach(fn () => LandingFixtures::bucket());

/** The root element's `style` attribute — the one place a resolved theme reaches the page. */
function rootStyle(string $html): string
{
    // ⚠ `toContain()` TAKES NEEDLES, NOT A MESSAGE — a second string argument is a second thing
    // to look for, so a message passed there is asserted rather than reported. Measured on this
    // file's first run: `3 failed`, each one "To contain: the document has no root element".
    // `toBeTrue()` is the form that carries a message.
    expect(str_contains($html, '<html'))->toBeTrue('the document has no root element, so there is nothing to compare');

    preg_match('/<html[^>]*\sstyle="([^"]*)"/u', $html, $matches);

    return $matches[1] ?? '';
}

it('puts the same resolved theme on the preview and on the published page', function (LandingTemplate $template): void {
    $tenant = test()->actingForTenant(role: TenantRole::Owner);

    $document = LandingFixtures::fullPage();
    $document['template'] = $template->value;
    $document['theme'] = SentinelTheme::values();

    $pages = new LandingPages;
    $pages->saveDraft($tenant, $document);
    $published = $pages->publish($tenant);

    $bytes = (string) Storage::disk('s3')->get(PublishedSite::pointer($published->hostname));
    $preview = (string) test()->get('/studio/preview')->getContent();

    $publishedStyle = rootStyle($bytes);

    // Vacuity first: a comparison of two empty strings would pass on a page with no theme at
    // all, which is the failure this case exists to detect.
    foreach (SentinelTheme::values() as $role => $value) {
        expect(str_contains($publishedStyle, $value))
            ->toBeTrue("the published page does not carry {$role}");
    }

    expect(rootStyle($preview))->toBe(
        $publishedStyle,
        "the preview and the published page resolve {$template->value} differently — a themed "
        .'preview over a differently themed page is exactly what makes a studio unjudgeable',
    );
})->with(LandingTemplate::cases());

/*
 * ================================================================================
 * ⚠ THE CALLER COUNT, WIDENED FROM `app/` TO WHEREVER PHP LIVES — Muhammad's finding A
 * ================================================================================
 *
 * Phase 1's guard scanned `app/` with a regex over raw file text. His review: *"it cannot see a
 * second resolver introduced in `bin/`, `tools/`, or a package — and this ticket itself added
 * PHP under `bin/`. A guard that cannot see where the next code lands is the §6 shape."* Two
 * further weaknesses of the regex, found while widening it: it matched a COMMENT that mentioned
 * the call (a false positive that teaches people to ignore it), and it could not read the
 * extensionless `bin/*` scripts at all.
 *
 * So the scan is by PHP's own tokenizer — comments and strings are not code — over every place
 * PHP is kept, including the executables under `bin/` that have no extension and the shebang
 * scripts under the repository's `tools/`.
 *
 * ⚠ AFTER THE `§11` ROUND (QA L9, L10, L11) THE ROOTS ARE WIDER STILL — `bootstrap`, `public`,
 * `artisan`, `apps/api/docker/` (it holds `drain/routes.php`), and the repository's `packages/` and `browser/` — and the scan REPORTS HOW
 * MANY FILES EACH ROOT VISITED, so a directory that is renamed away fails a case instead of being
 * skipped by an `is_dir` and leaving an exact-set assertion green over nothing.
 *
 * ⚠ WHAT IT STILL CANNOT SEE, stated rather than implied: a call made through a variable class
 * name (`$class::declarations(…)`), `call_user_func([ThemeStylesheet::class, 'declarations'])`, or
 * an alias imported under another name. The tokenizer reads names, not types. A second resolver
 * written that way is the boundary of this control, and it is recorded in `§5` with an owner.
 */

/** Directory names the walk never descends into. Pruned BEFORE descent, so `node_modules` is not read. */
const RESOLVER_SCAN_SKIP = ['vendor', 'node_modules', 'storage', 'build', '.next', '.git', 'generated'];

/**
 * An iterator over every file under a root, never entering a skipped directory.
 *
 * @return Generator<int, string>
 */
function resolverFiles(string $root): Generator
{
    if (is_file($root)) {
        yield $root;

        return;
    }

    $directory = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static fn (SplFileInfo $file): bool => ! ($file->isDir() && in_array($file->getFilename(), RESOLVER_SCAN_SKIP, true)),
    );

    foreach (new RecursiveIteratorIterator($directory) as $file) {
        if ($file instanceof SplFileInfo && $file->isFile()) {
            yield $file->getPathname();
        }
    }
}

/**
 * The roots the scan reads, each of which MUST exist.
 *
 * `tests/Feature` is deliberately NOT here: those files are the family's SUBJECTS and call it as
 * a unit under test. `tests/Support` is helper code a second resolver could hide in.
 *
 * @return list<string>
 */
function resolverScanRoots(): array
{
    $repo = dirname(base_path(), 2);

    return [
        base_path('app'), base_path('bin'), base_path('bootstrap'), base_path('config'), base_path('database'),
        base_path('docker'), base_path('guard'), base_path('public'), base_path('resources'), base_path('routes'),
        base_path('tests/Support'), base_path('artisan'),
        $repo.'/tools', $repo.'/packages', $repo.'/browser',
    ];
}

/**
 * Every call to the theme -> CSS family, outside the test suite's own subjects.
 *
 * @param  list<string>  $extraRoots  roots added to the real ones — how a test plants a resolver
 *                                    without writing into the repository
 * @return array{sites: array<string, list<string>>, visited: array<string, int>}
 */
function resolverCallSites(array $extraRoots = []): array
{
    $family = [
        'ThemeStylesheet' => ['declarations'],
        'ThemeTokens' => ['property', 'cssValue'],
        'ThemeDefaults' => ['fill', 'values'],
    ];

    $repo = dirname(base_path(), 2);
    $sites = [];
    $visited = [];

    foreach ([...resolverScanRoots(), ...$extraRoots] as $root) {
        $visited[$root] = 0;

        if (! file_exists($root)) {
            continue;
        }

        foreach (resolverFiles($root) as $path) {
            $visited[$root]++;

            $source = (string) file_get_contents($path);
            $isPhp = str_ends_with($path, '.php') || str_starts_with($source, '#!/usr/bin/env php');

            if (! $isPhp) {
                continue;
            }

            $label = str_replace($repo.'/', '', $path);

            if (str_ends_with($path, '.blade.php')) {
                // A template is not PHP the tokenizer can read. Its comments are stripped and
                // what remains is searched — `@php` blocks and `{{ }}` echoes included.
                $source = (string) preg_replace('/\{\{--[\s\S]*?--\}\}/', '', $source);

                foreach ($family as $class => $methods) {
                    foreach ($methods as $method) {
                        if (preg_match('/\b'.$class.'::'.$method.'\s*\(/', $source) === 1) {
                            $sites[$label][] = "{$class}::{$method}";
                        }
                    }
                }

                continue;
            }

            $tokens = token_get_all($source);
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                $token = $tokens[$i];

                /*
                 * ⚠ A QUALIFIED NAME IS ONE TOKEN IN PHP 8, NOT A T_STRING. `\App\Features\Landing\
                 * ThemeStylesheet::declarations()` — the most obvious way to write a second
                 * resolver outside a namespace — is a single `T_NAME_FULLY_QUALIFIED`, and the
                 * first version of this scan, which matched only `T_STRING`, walked straight past
                 * it. The planted-resolver control below is what found that, on this file's first
                 * run. The class is the LAST segment, whichever way the name is written.
                 */
                if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }

                $class = ltrim((string) strrchr('\\'.$token[1], '\\'), '\\');

                if (! isset($family[$class])) {
                    continue;
                }

                $next = $i + 1;

                while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                    $next++;
                }

                if (! isset($tokens[$next]) || ! is_array($tokens[$next]) || $tokens[$next][0] !== T_DOUBLE_COLON) {
                    continue;
                }

                $next++;

                while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                    $next++;
                }

                if (isset($tokens[$next]) && is_array($tokens[$next]) && in_array($tokens[$next][1], $family[$class], true)) {
                    $sites[$label][] = $class.'::'.$tokens[$next][1];
                }
            }
        }
    }

    ksort($sites);

    return [
        'sites' => array_map(static function (array $calls): array {
            $calls = array_values(array_unique($calls));
            sort($calls);

            return $calls;
        }, $sites),
        'visited' => $visited,
    ];
}

it('scans every root it names, and every one of them exists and has files in it', function (): void {
    /*
     * ⚠ THE VACUITY GUARD THE BY-NAME CASE LACKED (QA L11), applied to both. A root renamed away
     * used to be skipped by an `is_dir` and `expect($hits)->toBe([])` stayed green over nothing.
     * Here every root must EXIST and the walk must have opened at least one file in it.
     */
    foreach (resolverScanRoots() as $root) {
        expect(file_exists($root))->toBeTrue("the scan names {$root} and it does not exist, so it scanned nothing there");
    }

    foreach (resolverCallSites()['visited'] as $root => $files) {
        expect($files)->toBeGreaterThan(0, "the scan visited no file under {$root}");
    }
});

it('keeps one emitter with one production caller, wherever PHP is kept in the repository', function (): void {
    /*
     * EXACT, in both directions. A new caller is a second place that decides what a theme
     * resolves to; a caller that VANISHED would mean this scan has stopped seeing the code it
     * exists to count, which is the vacuity this file's other cases are written to rule out.
     */
    expect(resolverCallSites()['sites'])->toBe([
        'apps/api/app/Features/Landing/LandingRenderer.php' => ['ThemeStylesheet::declarations'],
        'apps/api/app/Features/Landing/ThemeStylesheet.php' => [
            'ThemeDefaults::fill',
            'ThemeTokens::cssValue',
            'ThemeTokens::property',
        ],
    ]);
});

it('would SEE a second resolver in an extensionless bin script or a tools file — planted outside the repository', function (): void {
    /*
     * ⚠ THE CONTROL FOR THE CASE ABOVE, AND IT NO LONGER WRITES INTO THE REPOSITORY (QA L10). It
     * used to plant `bin/zz-planted-*` and `tools/zz-planted-*` in the real tree, where a kill
     * between the write and the `finally` left executables behind that failed the exact-set case
     * and were globbed by any parallel suite. The planted files now live in a temporary directory
     * handed to the scan as an EXTRA root: the DETECTION is proven here, and that the real `bin/`
     * and `tools/` are opened at all is proven by the visited counts above.
     */
    $tmp = sys_get_temp_dir().'/resolver-plant-'.bin2hex(random_bytes(4));

    mkdir($tmp.'/bin', 0o755, true);
    mkdir($tmp.'/tools', 0o755, true);

    file_put_contents($tmp.'/bin/zz-planted-second-resolver', "#!/usr/bin/env php\n<?php\n\\App\\Features\\Landing\\ThemeStylesheet::declarations([], null);\n");
    file_put_contents($tmp.'/tools/zz-planted.php', "<?php\nThemeTokens::cssValue('color.text', '#000000');\n");
    file_put_contents($tmp.'/tools/zz-comment.php', "<?php\n// ThemeStylesheet::declarations([]);\n/* ThemeTokens::cssValue() */\n\$s = 'ThemeStylesheet::declarations(';\n");

    try {
        $sites = resolverCallSites([$tmp])['sites'];

        expect($sites[$tmp.'/bin/zz-planted-second-resolver'] ?? null)->toBe(['ThemeStylesheet::declarations']);
        expect($sites[$tmp.'/tools/zz-planted.php'] ?? null)->toBe(['ThemeTokens::cssValue']);

        // a comment and a string are not calls
        expect(array_key_exists($tmp.'/tools/zz-comment.php', $sites))->toBeFalse();
    } finally {
        foreach ([$tmp.'/bin/zz-planted-second-resolver', $tmp.'/tools/zz-planted.php', $tmp.'/tools/zz-comment.php'] as $file) {
            @unlink($file);
        }

        @rmdir($tmp.'/bin');
        @rmdir($tmp.'/tools');
        @rmdir($tmp);
    }

    // and the real tree is exactly as it was: nothing planted in it
    expect(array_keys(resolverCallSites()['sites']))->toHaveCount(2);
    expect(glob(base_path('bin/zz-*')))->toBe([]);
});

it('finds no second implementation by NAME in PHP strings or in the TypeScript half', function (): void {
    /*
     * ⚠ A SECOND RESOLVER NEED NOT CALL THE FIRST. It could emit `--color-accent` itself, in PHP
     * or in the Next.js apps, and the caller count above would never see it. So the six landing
     * role names are searched for directly: PHP string literals outside Blade (the template is
     * the CONSUMER of these names and is allowed to spell them), and the TypeScript and CSS
     * sources of `apps/web`, `apps/site` and `packages/*`.
     *
     * ⚠ `--color-surface` IS EXCLUDED AND THE EXCLUSION IS THE DECLARED BOUNDARY, not a
     * convenience: the product's own token layer defines a `--color-surface`, so the name proves
     * nothing in either direction. The other four names appear nowhere in the TypeScript half
     * today (measured 2026-11-14), which is what makes their absence assertable.
     */
    $names = '/--color-(background|text|muted|accent-text|accent)(?![a-z-])/';
    $repo = dirname(base_path(), 2);
    $hits = [];
    $visited = 0;

    foreach (['app', 'bin', 'config', 'database', 'routes', 'guard', 'tests/Support'] as $dir) {
        expect(is_dir(base_path($dir)))->toBeTrue("{$dir} does not exist, so the by-name scan read nothing there");

        foreach (resolverFiles(base_path($dir)) as $path) {
            $visited++;
            $source = (string) file_get_contents($path);

            if (! (str_ends_with($path, '.php') || str_starts_with($source, '#!/usr/bin/env php'))) {
                continue;
            }

            foreach (token_get_all($source) as $token) {
                if (is_array($token)
                    && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                    && preg_match($names, $token[1]) === 1) {
                    $hits[] = str_replace($repo.'/', '', $path).':'.$token[2];
                }
            }
        }
    }

    foreach (['apps/web/src', 'apps/web/app', 'apps/site/src', 'apps/site/app', 'packages/ui/src', 'packages/tokens/src', 'packages/contracts/src'] as $dir) {
        expect(is_dir($repo.'/'.$dir))->toBeTrue("{$dir} does not exist, so the by-name scan read nothing there");

        foreach (resolverFiles($repo.'/'.$dir) as $path) {
            $visited++;

            if (preg_match('/\.(ts|tsx|css|mjs|js)$/', $path) === 1
                && preg_match($names, (string) file_get_contents($path)) === 1) {
                $hits[] = str_replace($repo.'/', '', $path);
            }
        }
    }

    // Not vacuous: a scan that opened nothing would report "no hits" for any name at all.
    expect($visited)->toBeGreaterThan(300);
    expect($hits)->toBe([], 'a second place names a landing theme role property, so a second resolver may exist: '.implode(', ', $hits));
});
