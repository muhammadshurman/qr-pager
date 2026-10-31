import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

/*
 * ⚠ NO `bunny()` FONT HELPER, AND ITS ABSENCE IS THE POINT (QRPAGER-23 §9.1).
 *
 * This file used to call `bunny('Instrument Sans', { weights: [400, 500, 600] })`, which
 * `laravel-vite-plugin/fonts` sources from **fonts.bunny.net**. Three separate clauses were
 * against it, not one:
 *
 *   - a third-party request on an authenticated surface: someone else's uptime, and a
 *     per-visitor IP disclosure to a party with no reason to see it;
 *   - off-direction: the approved families are Plus Jakarta Sans (Latin) and Tajawal
 *     (Arabic), self-hosted from `packages/tokens/fonts/`;
 *   - `§12`'s "a reviewer cannot name the UI framework from a screenshot" — Instrument Sans
 *     is the Laravel starter-kit face, so it is a framework signature in the one channel a
 *     screenshot shows most plainly.
 *
 * The faces now arrive through `packages/tokens/fonts/fonts.css`, whose every `url()` is
 * relative, so Vite emits the woff2 into this app's own build and serves it same-origin.
 * `PanelFontOriginTest` asserts the rendered panel HTML requests no third-party font host,
 * rather than trusting this comment.
 */
export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // ⚠ THE TWO PANEL THEMES, AND THEY MUST BE INPUTS RATHER THAN IMPORTS.
                // `Panel::viteTheme()` resolves its argument through `app(Vite::class)`,
                // which reads the manifest -- so a theme absent from this array is a
                // ViteManifestNotFoundException at render time, not a styling difference.
                // Read from `HasTheme::getTheme()`, not from documentation.
                'resources/css/filament/platform/theme.css',
                'resources/css/filament/studio/theme.css',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
