<?php

declare(strict_types=1);

/*
 * ================================================================================
 * THE ARTEFACT THE PHP HALF CONSUMES IS NOW CHECKED BY THE JOB THE PHP HALF RUNS
 * ================================================================================
 *
 * `QRPAGER-23 §2.1(c)` named this asymmetry before any code was written, and left the
 * decision to the implementation commit:
 *
 *   `apps/api/config/design-tokens.php` is a GENERATED file (`§6.2`: one source, which is
 *   `packages/tokens/src/source.ts`). Its staleness check is `pnpm tokens:check`, a byte
 *   diff -- and that runs in CI's **frontend** job and in `pnpm gate`, neither of which the
 *   PHP half runs. `apps/api/bin/gate` did not check it at all. So a token change that
 *   needed a regenerate was caught by CI and NOT by a local `composer gate`, and this is the
 *   file Task 8b's panel providers now read at boot.
 *
 * THE DECISION: not a new gate step. `bin/gate`'s own docblock refuses to run the frontend
 * gate -- "one script spanning both halves would make a PHP failure and a TypeScript failure
 * indistinguishable in the log" (ADR-003) -- and a `composer tokens:check` that shelled out
 * to pnpm would be exactly that, plus a Node dependency in the PHP gate for a byte diff that
 * already has an owner.
 *
 * Instead this is a TEST, so it runs in `php artisan test` -- which is a step of
 * `composer gate` AND of CI's `gates` job, both of which the PHP half already runs -- and it
 * checks a DIFFERENT property from the byte diff, which is why the two do not overlap:
 *
 *   - `pnpm tokens:check` asks *"is this file what the generator produces right now?"*
 *   - this asks *"does this file AGREE with the other generated artefacts?"*
 *
 * The second catches a hand-edit to the PHP config even when nobody can run Node, and it
 * catches a regenerate of one artefact without the other. Neither is a second source: both
 * sides of every comparison below are generated files, and this file derives nothing.
 */

/** The repository root, from the Laravel app that lives two levels under it. */
function tokensRepoRoot(): string
{
    return dirname(base_path(), 2);
}

/** One CSS block's custom properties, by selector, read from the generated stylesheet. */
function tokensCssBlock(string $selector): array
{
    $css = (string) file_get_contents(tokensRepoRoot().'/packages/tokens/generated/tokens.css');

    // The block from this selector's `{` to its matching `}` at the same nesting level. The
    // generated file is flat inside each block, so the first `}` at line start closes it.
    $start = strpos($css, $selector.' {');
    expect($start)->not->toBeFalse("{$selector} is not in packages/tokens/generated/tokens.css");

    $end = strpos($css, "\n}", (int) $start);
    expect($end)->not->toBeFalse("{$selector} is never closed");

    $block = substr($css, (int) $start, (int) $end - (int) $start);

    $declarations = [];
    preg_match_all('/^\s*(--[a-z0-9-]+)\s*:\s*([^;]+);/mi', $block, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $declarations[$match[1]] = trim($match[2]);
    }

    return $declarations;
}

it('agrees with the generated stylesheet on every colour, in both themes', function (): void {
    $blocks = [
        'light' => tokensCssBlock(':root'),
        'dark' => tokensCssBlock("[data-theme='dark']"),
    ];

    // NOT VACUOUS: the config must actually carry roles, and the stylesheet must actually
    // carry declarations, or "they agree" is true of two empty lists.
    $roles = config('design-tokens.colours');
    expect($roles)->toBeArray()
        ->and(array_keys($roles))->toBe(['light', 'dark']);

    foreach (['light', 'dark'] as $theme) {
        expect(count($roles[$theme]))->toBeGreaterThan(8)
            ->and(count($blocks[$theme]))->toBeGreaterThan(8);

        foreach ($roles[$theme] as $role => $value) {
            $property = "--color-{$role}";

            // ⚠ array_key_exists() AND NOT `toHaveKey($property, $message)`. Pest's
            // signature is `toHaveKey(string|int $key, mixed $value = new Any, string
            // $message = '')`, so a two-argument call reads the message AS THE EXPECTED
            // VALUE -- this file's first run failed with `-'design-tokens.space.4 has no
            // --space-4'  +'0.25rem'`, which is the matcher comparing a sentence to a
            // length. Same shape as the `not->toContain($needle, $message)` trap `composer
            // guard` refuses; this one at least fails loudly rather than vacuously.
            expect(array_key_exists($property, $blocks[$theme]))->toBeTrue(
                "design-tokens.colours.{$theme}.{$role} has no {$property} in the stylesheet"
            );

            // Case-insensitive on purpose: the two emitters agree on the VALUE, and a hex
            // case difference between them would be a generator bug worth seeing rather
            // than a reason for this to be red. Measured today: both emit upper case.
            expect(strtoupper((string) $blocks[$theme][$property]))->toBe(
                strtoupper((string) $value),
                "design-tokens.colours.{$theme}.{$role} disagrees with {$property}"
            );
        }
    }
});

it('agrees with the generated stylesheet on every length and size', function (): void {
    $root = tokensCssBlock(':root');

    // config key => the custom-property prefix the stylesheet uses for it.
    $scales = ['space' => '--space-', 'radius' => '--radius-', 'size' => '--size-'];

    $checked = 0;

    foreach ($scales as $key => $prefix) {
        $scale = config("design-tokens.{$key}");

        expect($scale)->toBeArray()
            ->and(count($scale))->toBeGreaterThan(2, "design-tokens.{$key} is too small to mean anything");

        foreach ($scale as $step => $value) {
            $property = $prefix.$step;

            expect(array_key_exists($property, $root))
                ->toBeTrue("design-tokens.{$key}.{$step} has no {$property}");
            expect($root[$property])->toBe((string) $value, "design-tokens.{$key}.{$step} disagrees with {$property}");

            $checked++;
        }
    }

    // One number, so a scale silently emptied cannot pass this case.
    expect($checked)->toBe(19);
});

it('agrees with the generated stylesheet on the three protected properties', function (): void {
    $root = tokensCssBlock(':root');
    $orderReady = config('design-tokens.order_ready');

    expect($orderReady)->toBeArray()->toHaveCount(3);

    foreach ($orderReady as $property => $value) {
        expect(array_key_exists((string) $property, $root))
            ->toBeTrue("{$property} is not in the stylesheet");
        expect(strtoupper((string) $root[(string) $property]))->toBe(strtoupper((string) $value));
    }

    // D4: these three take the same value in BOTH themes, because there is no per-theme
    // variant of the order-ready screen. Asserted here rather than only stated in the
    // config's own comment.
    $dark = tokensCssBlock("[data-theme='dark']");

    foreach (array_keys($orderReady) as $property) {
        if (array_key_exists((string) $property, $dark)) {
            expect(strtoupper((string) $dark[$property]))
                ->toBe(strtoupper((string) $root[$property]), "{$property} differs between the themes");
        }
    }

    expect(config('design-tokens.protected_properties'))->toBe(array_keys($orderReady));
});

it('names a font family the generated @font-face block actually declares', function (): void {
    // ⚠ THE PROPERTY TASK 8b's PANEL PROVIDERS DEPEND ON. Both call
    // `->font((string) config('design-tokens.fonts.latin.family'), …)`, and Filament writes
    // that string into `--font-family` verbatim. A family named here that no @font-face
    // declares renders as the fallback with nothing in any log.
    $fontsCss = (string) file_get_contents(tokensRepoRoot().'/packages/tokens/fonts/fonts.css');

    $declared = [];
    preg_match_all("/font-family:\s*'([^']+)'/", $fontsCss, $matches);
    $declared = array_values(array_unique($matches[1] ?? []));

    expect($declared)->not->toBeEmpty('no @font-face family was parsed, so this check is vacuous');

    foreach (['latin', 'arabic'] as $subset) {
        $family = (string) config("design-tokens.fonts.{$subset}.family");

        // in_array() and not `toContain($family, $message)`: toContain is VARIADIC over
        // needles and takes no message at all, so the second argument becomes a second
        // needle that is never present.
        expect($family)->not->toBe('');
        expect(in_array($family, $declared, true))
            ->toBeTrue("no @font-face declares {$family}");

        // And each file the config names is on disk, same-origin, in the package the
        // stylesheet's relative url() resolves against.
        $files = config("design-tokens.fonts.{$subset}.files");
        expect($files)->toBeArray()->not->toBeEmpty();

        foreach ($files as $file) {
            $path = tokensRepoRoot().'/packages/tokens/fonts/'.$file;
            expect(is_file($path))->toBeTrue("design-tokens names {$file}, which is not on disk")
                ->and(filesize($path))->toBeGreaterThan(1000, "{$file} is implausibly small");
        }
    }
});
