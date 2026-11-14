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
 * ⚠ WHAT IT STILL CANNOT SEE, stated rather than implied: a call made through a variable class
 * name (`$class::declarations(…)`), `call_user_func([ThemeStylesheet::class, 'declarations'])`, or
 * an alias imported under another name. The tokenizer reads names, not types. A second resolver
 * written that way is the boundary of this control, and it is recorded in `§5` with an owner.
 */

/**
 * Every call to the theme -> CSS family, outside the test suite's own subjects, by file.
 *
 * @return array<string, list<string>> repository-relative path => `Class::method`, sorted
 */
function resolverCallSites(): array
{
    $family = [
        'ThemeStylesheet' => ['declarations'],
        'ThemeTokens' => ['property', 'cssValue'],
        'ThemeDefaults' => ['fill', 'values'],
    ];

    $repo = dirname(base_path(), 2);

    // `tests/Feature` is deliberately NOT here: those files are the family's SUBJECTS and call
    // it as a unit under test. `tests/Support` is helper code a second resolver could hide in.
    $roots = [
        base_path('app'), base_path('bin'), base_path('config'), base_path('database'),
        base_path('routes'), base_path('resources'), base_path('guard'), base_path('tests/Support'),
        $repo.'/tools',
    ];

    $sites = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $path = $file->getPathname();

            if (preg_match('#/(vendor|node_modules|storage|build)/#', $path) === 1) {
                continue;
            }

            $source = (string) file_get_contents($path);
            $isPhp = str_ends_with($path, '.php') || str_starts_with($source, '#!/usr/bin/env php');

            if (! $isPhp) {
                continue;
            }

            if (str_ends_with($path, '.blade.php')) {
                // A template is not PHP the tokenizer can read. Its comments are stripped and
                // what remains is searched — `@php` blocks and `{{ }}` echoes included.
                $source = (string) preg_replace('/\{\{--[\s\S]*?--\}\}/', '', $source);

                foreach ($family as $class => $methods) {
                    foreach ($methods as $method) {
                        if (preg_match('/\b'.$class.'::'.$method.'\s*\(/', $source) === 1) {
                            $sites[str_replace($repo.'/', '', $path)][] = "{$class}::{$method}";
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
                    $sites[str_replace($repo.'/', '', $path)][] = $class.'::'.$tokens[$next][1];
                }
            }
        }
    }

    ksort($sites);

    return array_map(static function (array $calls): array {
        $calls = array_values(array_unique($calls));
        sort($calls);

        return $calls;
    }, $sites);
}

it('keeps one emitter with one production caller, wherever PHP is kept in the repository', function (): void {
    /*
     * EXACT, in both directions. A new caller is a second place that decides what a theme
     * resolves to; a caller that VANISHED would mean this scan has stopped seeing the code it
     * exists to count, which is the vacuity this file's other cases are written to rule out.
     */
    expect(resolverCallSites())->toBe([
        'apps/api/app/Features/Landing/LandingRenderer.php' => ['ThemeStylesheet::declarations'],
        'apps/api/app/Features/Landing/ThemeStylesheet.php' => [
            'ThemeDefaults::fill',
            'ThemeTokens::cssValue',
            'ThemeTokens::property',
        ],
    ]);
});

it('would SEE a second resolver in bin/ or tools/ — the scan itself, planted and removed', function (): void {
    /*
     * ⚠ THE CONTROL FOR THE CASE ABOVE. A scan that returns the expected two files proves
     * nothing about files it never opens, and Phase 1's scan never opened `bin/` at all. This
     * plants one in each of the two places the review named and requires the scan to report it.
     */
    $planted = [
        base_path('bin/zz-planted-second-resolver') => "#!/usr/bin/env php\n<?php\n\\App\\Features\\Landing\\ThemeStylesheet::declarations([], null);\n",
        dirname(base_path(), 2).'/tools/zz-planted-second-resolver.php' => "<?php\nThemeTokens::cssValue('color.text', '#000000');\n",
    ];

    try {
        foreach ($planted as $path => $body) {
            file_put_contents($path, $body);
        }

        $sites = resolverCallSites();

        expect($sites)->toHaveKey('apps/api/bin/zz-planted-second-resolver');
        expect($sites['apps/api/bin/zz-planted-second-resolver'])->toBe(['ThemeStylesheet::declarations']);
        expect($sites)->toHaveKey('tools/zz-planted-second-resolver.php');
        expect($sites['tools/zz-planted-second-resolver.php'])->toBe(['ThemeTokens::cssValue']);
    } finally {
        foreach (array_keys($planted) as $path) {
            @unlink($path);
        }
    }

    expect(array_keys(resolverCallSites()))->not->toContain('apps/api/bin/zz-planted-second-resolver');
});

it('does not mistake a comment or a string for a call', function (): void {
    $path = base_path('bin/zz-planted-comment');

    file_put_contents($path, "#!/usr/bin/env php\n<?php\n// ThemeStylesheet::declarations([]);\n/* ThemeTokens::cssValue() */\n\$s = 'ThemeStylesheet::declarations(';\n");

    try {
        expect(array_keys(resolverCallSites()))->not->toContain('apps/api/bin/zz-planted-comment');
    } finally {
        @unlink($path);
    }
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

    $walk = static function (string $root, callable $each): void {
        if (! is_dir($root)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()
                && preg_match('#/(vendor|node_modules|storage|build|\.next|generated)/#', $file->getPathname()) !== 1) {
                $each($file->getPathname());
            }
        }
    };

    foreach (['app', 'bin', 'config', 'database', 'routes', 'guard', 'tests/Support'] as $dir) {
        $walk(base_path($dir), static function (string $path) use ($names, $repo, &$hits): void {
            $source = (string) file_get_contents($path);

            if (! (str_ends_with($path, '.php') || str_starts_with($source, '#!/usr/bin/env php'))) {
                return;
            }

            foreach (token_get_all($source) as $token) {
                if (is_array($token)
                    && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                    && preg_match($names, $token[1]) === 1) {
                    $hits[] = str_replace($repo.'/', '', $path).':'.$token[2];
                }
            }
        });
    }

    foreach (['apps/web/src', 'apps/web/app', 'apps/site/src', 'apps/site/app', 'packages/ui/src', 'packages/tokens/src', 'packages/contracts/src'] as $dir) {
        $walk($repo.'/'.$dir, static function (string $path) use ($names, $repo, &$hits): void {
            if (preg_match('/\.(ts|tsx|css|mjs|js)$/', $path) === 1
                && preg_match($names, (string) file_get_contents($path)) === 1) {
                $hits[] = str_replace($repo.'/', '', $path);
            }
        });
    }

    expect($hits)->toBe([], 'a second place names a landing theme role property, so a second resolver may exist: '.implode(', ', $hits));
});
