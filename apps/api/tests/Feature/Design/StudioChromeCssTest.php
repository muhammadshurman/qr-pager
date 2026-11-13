<?php

declare(strict_types=1);

/*
 * ================================================================================
 * THE PANEL STYLESHEETS NAME TOKENS THAT EXIST — `QRPAGER-48` `§11` QA H-2 and M-1
 * ================================================================================
 *
 * ⚠ THE DEFECT THIS REFUSES IS SILENT, WHICH IS WHY IT NEEDED A TEST RATHER THAN A HABIT.
 * `QRPAGER-48`'s chrome was authored against `--space-1`, `--space-2`, `--space-3`,
 * `--space-5`, `--space-6`, `--size-12` and `--radius-small`. **None of those exists.** The
 * token source emits the scale by VALUE — `--space-4/8/12/16/24/32/48/64/96`,
 * `--size-13/16/20/28/44/64/96`, `--radius-surface` and `--radius-control`
 * (`packages/tokens/src/source.ts:423`) — so every one of those 29 declarations was invalid
 * at computed-value time and resolved to `unset`.
 *
 * What that looked like in the delivered panel, measured in Chromium: padding `0px`, the
 * status plate's and the footer's state dots `0 × 0` (they are the only non-text indicator
 * in those regions), square corners where a radius was asked for, and `--size-12` text
 * silently at the inherited size. CSS does not warn, the build does not fail, and the page
 * renders — just wrong. It shipped through four green gates.
 *
 * ⚠ AND NOTHING COULD HAVE SEEN IT. `tools/gate/bin/scan-css.mjs` exempts `apps/api` by
 * name; `scan-panel.mjs` reads PHP and Blade for hard-coded utility classes, not CSS
 * variable names; `browser/studio.spec.ts`'s token sweep measures COLOURS only. A var that
 * names nothing is the one mistake the whole token apparatus was blind to.
 *
 * THE CHECK IS OVER EVERY PANEL STYLESHEET, not over the chrome block, because the property
 * is about the authoring surface rather than about this ticket.
 */

/** Every custom property DECLARED anywhere the panel can see, as a set. */
function declaredCustomProperties(): array
{
    $declared = [];

    foreach (panelStyleSources() as $path) {
        preg_match_all('/(--[A-Za-z0-9_-]+)\s*:/', (string) file_get_contents($path), $names);

        foreach ($names[1] as $name) {
            $declared[$name] = true;
        }
    }

    return $declared;
}

/**
 * The files that may DECLARE a property: the generated token set, and the panel's own
 * stylesheets (a component may declare a local variable and immediately use it —
 * `.studio-topbar__mark` does exactly that with `--studio-topbar-mark-ink`).
 *
 * @return list<string>
 */
function panelStyleSources(): array
{
    $tokens = base_path('../../packages/tokens/generated/tokens.css');

    $files = glob(resource_path('css/filament/*.css')) ?: [];
    $nested = glob(resource_path('css/filament/*/*.css')) ?: [];

    return array_values(array_filter(
        array_merge([$tokens], $files, $nested),
        static fn (string $path): bool => is_file($path),
    ));
}

/** Every panel stylesheet that may USE a property — the token file is not one. */
function panelStylesheets(): array
{
    return array_values(array_filter(
        panelStyleSources(),
        static fn (string $path): bool => ! str_contains($path, 'packages/tokens'),
    ));
}

it('names only custom properties that something declares', function (): void {
    $declared = declaredCustomProperties();

    // Not vacuous: the generated token set has to have been found, and these three are the
    // names the chrome's own rules were corrected TO. If the glob stops resolving, this
    // fails here rather than passing an empty comparison below.
    expect(count($declared))->toBeGreaterThan(30)
        ->and($declared)->toHaveKey('--space-8')
        ->and($declared)->toHaveKey('--radius-control')
        ->and($declared)->toHaveKey('--target-min');

    $undefined = [];
    $used = 0;

    foreach (panelStylesheets() as $path) {
        // ⚠ COMMENTS STRIPPED FIRST, for `PanelTargetTest`'s reason: these files argue in
        // prose about the names they rejected, and a check that read the prose would report
        // the argument as the defect.
        $code = (string) preg_replace('#/\*[\s\S]*?\*/#', ' ', (string) file_get_contents($path));

        preg_match_all('/var\(\s*(--[A-Za-z0-9_-]+)/', $code, $names);

        foreach ($names[1] as $name) {
            $used++;

            if (! array_key_exists($name, $declared)) {
                $undefined[] = basename($path).' uses '.$name;
            }
        }
    }

    expect($used)->toBeGreaterThan(20, 'no var() was read at all, so this proved nothing');

    expect(array_values(array_unique($undefined)), 'a panel stylesheet names a custom '.
        'property that nothing declares; CSS resolves it to `unset`, so the rule is silently '.
        'dropped and the page renders wrong rather than failing')->toBe([]);
});

/*
 * ================================================================================
 * THE TWO FOOTERS ARE TWO CLASSES — `§11` QA M-1
 * ================================================================================
 *
 * `QRPAGER-48`'s chrome footer reused `.studio-footer`, which `QRPAGER-40`'s in-form footer
 * already owned (`studio/builder.blade.php:118`). Two different elements under one name
 * means the later rule wins the cascade for BOTH, and it did: the builder's footer lost its
 * `padding-block` and `gap` and gained `position: sticky; inset-block-end: 0`, measured in
 * Chromium as `{"padding":"0px","gap":"normal","position":"sticky"}`.
 *
 * The chrome bar is `.studio-chrome-footer` now. This pins the separation at both ends —
 * one name per element, and one RULE per name, so the next component that wants a footer
 * cannot quietly inherit this one's geometry or overwrite it.
 */
it('gives the chrome footer and the builder footer separate names and separate rules', function (): void {
    $chrome = (string) file_get_contents(resource_path('views/studio/partials/footer-bar.blade.php'));
    $builder = (string) file_get_contents(resource_path('views/studio/builder.blade.php'));

    expect($chrome)->toContain('class="studio-chrome-footer"')
        ->and($chrome)->not->toContain('class="studio-footer"')
        ->and($builder)->toContain('class="studio-footer"')
        ->and($builder)->not->toContain('class="studio-chrome-footer"');

    $code = (string) preg_replace(
        '#/\*[\s\S]*?\*/#',
        ' ',
        (string) file_get_contents(resource_path('css/filament/studio/theme.css')),
    );

    // Exactly one rule opens on each bare class selector. `preg_match_all` over
    // `^\.name {` at line starts is enough here because this file declares every top-level
    // rule that way; a selector reached only as `.name[aria-current]` or inside a `@media`
    // is a state or a breakpoint, not a second component claiming the name.
    expect(preg_match_all('/^\.studio-footer\s*\{/m', $code))->toBe(1)
        ->and(preg_match_all('/^\.studio-chrome-footer\s*\{/m', $code))->toBe(1);

    // And the builder footer still carries the two declarations the collision took from it.
    preg_match('/^\.studio-footer\s*\{([^}]*)\}/m', $code, $rule);

    expect($rule[1] ?? '')->toContain('padding-block:')
        ->and($rule[1] ?? '')->toContain('gap:');
});
