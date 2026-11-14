<?php

declare(strict_types=1);

use App\Domain\Landing\ThemeTokens;
use Tests\Support\SentinelTheme;

/*
 * ================================================================================
 * THE SENTINELS ARE SOUND INSTRUMENTS — `QRPAGER-49A`
 * ================================================================================
 *
 * A sentinel found in a computed value is evidence only while four things hold, and Phase 1
 * stated the fourth as a one-off `grep` in `PLAN.md`:
 *
 *   1. there is one per colour role, and a role added to the contract without one is caught
 *   2. they are six DISTINCT values
 *   3. each is a value the contract itself accepts — they travel the production path, so a
 *      value the validator refuses would never reach a page
 *   4. none of them appears anywhere else in the product
 *
 * (4) is the one that decays: it is true on the day somebody greps and false on the day a
 * designer adds `#ff00e4` to a token file, after which a finding is no longer attributable to
 * the theme. Asserting it here turns the grep into a standing check.
 */

it('has exactly one sentinel for every colour role the contract defines, in the contract’s order', function (): void {
    expect(array_keys(SentinelTheme::values()))->toBe(SentinelTheme::colourRoles());

    // Not vacuous: the contract really does define colour roles, and there are six of them.
    expect(SentinelTheme::colourRoles())->toHaveCount(6);
});

it('catches a colour role added to the contract without a sentinel', function (): void {
    // The real contract is fully covered…
    expect(SentinelTheme::uncovered(SentinelTheme::colourRoles()))->toBe([]);

    // …and a contract that GREW is not: handed a seventh colour role, the detection names it.
    // (This case used to compare `colourRoles()` against a list built by the same code, which can
    // fail only if that code is rewritten — `§11` QA L3.)
    expect(SentinelTheme::uncovered([...SentinelTheme::colourRoles(), 'color.invented']))->toBe(['color.invented']);
    expect(SentinelTheme::uncovered(['color.invented', 'color.other']))->toBe(['color.invented', 'color.other']);
    expect(SentinelTheme::uncovered([]))->toBe([]);
});

it('holds six distinct values, each one the contract accepts', function (): void {
    $values = SentinelTheme::values();

    expect(array_unique($values))->toHaveCount(count($values));

    foreach ($values as $role => $value) {
        expect(ThemeTokens::accepts($role, $value))->toBeTrue("{$role} = {$value} is not a value the contract accepts");
        // lower-case, because `accepts()` takes lower-case hex only and the classifier compares exactly
        expect($value)->toBe(strtolower($value));
    }
});

it('keeps the six far apart, so a misattributed role cannot read as a pass', function (): void {
    // The floor is set from the MEASURED minimum (105.0, `text` / `accent_text`) with headroom,
    // not from a round number: a near miss is 1.0 apart and anything under ~100 would start to
    // let two roles read alike on a screenshot.
    $channels = array_map(
        static fn (string $hex): array => [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))],
        array_values(SentinelTheme::values()),
    );

    foreach ($channels as $i => $a) {
        foreach ($channels as $j => $b) {
            if ($j <= $i) {
                continue;
            }

            $distance = sqrt(array_sum(array_map(static fn (int|float $x, int|float $y): float => (($x - $y) ** 2), $a, $b)));

            expect($distance)->toBeGreaterThan(100, "sentinels {$i} and {$j} are too close in RGB to tell apart");
        }
    }
});

it('appears nowhere else in the product', function (): void {
    /*
     * ⚠ THE PRODUCT, NOT THE REPOSITORY. The sentinels are spelled in this file, in
     * `tests/Support/SentinelTheme.php`, in the instrument's own tests and in the PLAN, and that
     * is the point of them. What must be true is that nothing a visitor or a restaurant owner
     * can reach — the application, its views, its config, the token layer, the Next.js apps and
     * the component kit — carries one, because a colour found on a page is only attributable to
     * the theme if the product has no way to emit it.
     */
    $repo = dirname(base_path(), 2);
    $roots = [
        base_path('app'), base_path('config'), base_path('resources'), base_path('database'), base_path('routes'), base_path('lang'),
        $repo.'/packages/tokens/src', $repo.'/packages/tokens/generated', $repo.'/packages/ui/src',
        $repo.'/apps/web/src', $repo.'/apps/web/app', $repo.'/apps/site/src', $repo.'/apps/site/app',
    ];

    $needle = '/'.implode('|', array_map(static fn (string $hex): string => preg_quote(ltrim($hex, '#'), '/'), SentinelTheme::values())).'/i';
    $scanned = 0;
    $hits = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()
                || preg_match('#/(vendor|node_modules|storage|build|\.next)/#', $file->getPathname()) === 1
                || preg_match('/\.(php|css|ts|tsx|mjs|js|json|html|svg|md)$/', $file->getPathname()) !== 1) {
                continue;
            }

            $scanned++;

            if (preg_match($needle, (string) file_get_contents($file->getPathname())) === 1) {
                $hits[] = str_replace($repo.'/', '', $file->getPathname());
            }
        }
    }

    // Not vacuous: a scan that opened nothing would report "no hits" for any value at all.
    expect($scanned)->toBeGreaterThan(200);
    expect($hits)->toBe([], 'a sentinel colour appears in the product, so a finding can no longer be attributed to the theme: '.implode(', ', $hits));
});
