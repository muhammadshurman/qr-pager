<?php

declare(strict_types=1);

namespace App\Platform\Panel;

use RuntimeException;

/**
 * The approved Filament palettes, read from the generated token config — SR-4.
 *
 * ================================================================================
 * ONE ACCESSOR, IN App\Platform, AND BOTH REASONS ARE MECHANICAL
 * ================================================================================
 *
 * It was a 15-line private method duplicated BYTE-IDENTICALLY in both panel providers, which
 * is the duplicated-logic shape this project has paid for twice. It cannot live in either
 * feature: `deptrac` rule 2 forbids Platform from importing a feature, and a helper in
 * `App\Features\Provisioning` imported by `App\Features\Landing` would be a
 * feature-to-feature edge. `App\Platform` is the one place both may import from, which is the
 * same argument `DatabaseRoles` and `TenantPanelAccess` already rest on.
 *
 * ================================================================================
 * ⚠ AND THE THROW NO LONGER HAPPENS AT BOOT, WHICH IS THE PART THAT MATTERED
 * ================================================================================
 *
 * `PanelProvider::panel()` runs on EVERY boot, so a `RuntimeException` there was not scoped
 * to a panel request. Measured with the config key renamed: `php artisan --version` itself
 * printed the exception — so a missing CSS artefact stopped the queue worker that delivers
 * the order-ready push, the scheduler, and every console command. `pnpm tokens:check` is a
 * gate step, not a runtime guarantee: a deploy shipping a partial config, or a
 * `config:cache` built before generation, lands exactly there.
 *
 * So the providers now pass THIS CLASS AS A CLOSURE to `->colors()`, which Filament evaluates
 * when it first resolves colours — i.e. on a panel render. A panel request still fails
 * closed and loudly, with the same message; nothing else does. Fail-closed is kept because
 * the alternative is Filament silently using amber, which is the dead-control shape §4a
 * records four instances of — but it is kept on the surface it is about.
 */
final class ApprovedPalette
{
    /** The config key, named once so the byte check and the accessor cannot disagree. */
    public const string CONFIG_KEY = 'design-tokens.filament_palette';

    /**
     * @return array<string, array<int, string>>
     */
    public static function families(): array
    {
        $palette = config(self::CONFIG_KEY);

        if (! is_array($palette) || $palette === []) {
            throw new RuntimeException(
                self::CONFIG_KEY.' is missing. Run `pnpm tokens:generate`; without it Filament '
                .'falls back to its own default families and the panel renders amber where the '
                .'brand should be.'
            );
        }

        /** @var array<string, array<int, string>> $palette */
        return $palette;
    }
}
