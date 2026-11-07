<?php

declare(strict_types=1);

/*
 * GENERATED FILE -- DO NOT EDIT.
 *
 * Emitted from packages/tokens/src/source.ts by packages/tokens/scripts/generate.mjs.
 * Run `pnpm tokens:generate` to refresh it. `pnpm tokens:check` byte-diffs this file
 * against what the source produces right now and fails the gate when they differ.
 *
 * Editing this file by hand produces a value that disagrees with the single source and
 * is reverted by the next generate -- which is the second-source defect §6.2 forbids.
 *
 * CONSUMED IN 8b (QRPAGER-23), not here. D5 asks for both this file and the CSS-vars file:
 * CSS variables alone would leave panel code hand-writing the values it needs, which is the
 * second source §6.2 exists to prevent.
 *
 * This is CONFIG, not schema. It holds colour and length literals, is identical for every
 * tenant, carries no tenant data, and is read by nothing in Task 8a but its own staleness
 * check. Task 8a touches no table, column, role or GUC (QRPAGER-15 §1).
 *
 * The order-ready roles below are OUTSIDE THEMING (D4) and take the same value in both
 * themes. No value in tenant_landing_pages.theme can produce their CSS property names --
 * App\Domain\Landing\ThemeTokens::NAMES is a closed allow-list of twelve, and the three
 * protected properties are outside the image of ThemeTokens::property() over that list.
 */

return [
    'mix_colour_space' => 'srgb-8bit-component-linear-round-half-up',

    /*
     * The order-ready screen's three colours. OUTSIDE THEMING (D4): this list takes no
     * theme, because there is no per-theme variant of it. No value in
     * tenant_landing_pages.theme can produce any of these property names.
     */
    'order_ready' => [
        '--ready-field' => '#0A1F17',
        '--ready-ink' => '#F2F4F1',
        '--ready-signal' => '#3DDC97',
    ],

    'protected_properties' => [
        '--ready-field',
        '--ready-ink',
        '--ready-signal',
    ],

    'colours' => [
        'light' => [
            'paper' => '#F2F4F1',
            'surface' => '#FFFFFF',
            'surface-2' => '#E7EBE6',
            'hairline' => '#D3DAD2',
            'border-strong' => '#77847C',
            'ink' => '#10231B',
            'ink-mid' => '#5A6A62',
            'brand' => '#0B6B4F',
            'signal' => '#3DDC97',
            'alert-fill' => '#E4572E',
            'alert-ink' => '#96351A',
        ],
        'dark' => [
            'paper' => '#0A1F17',
            'surface' => '#1D3028',
            'surface-2' => '#2A3D36',
            'hairline' => '#42524B',
            'border-strong' => '#7E8A84',
            'ink' => '#F2F4F1',
            'ink-mid' => '#9DA7A1',
            'brand' => '#3DDC97',
            'signal' => '#3DDC97',
            'alert-fill' => '#E4572E',
            'alert-ink' => '#E99176',
        ],
    ],

    /*
     * SR-4, ruled 2026-11-01: the Filament colour families, as ELEVEN-SHADE LADDERS.
     *
     * ->colors() is Filament's only sanctioned colour extension point and it wants eleven
     * shades per family. Handed a single hex it keeps ONLY THE HUE -- Color::generatePalette()
     * imposes its own lightness and chroma at every shade -- so the approved value appears
     * nowhere, two approved colours 1.4 degrees apart collapse into one family, and the
     * resolved dark pair measured 3.43:1, which fails the AA gate clause.
     *
     * So each ladder is DERIVED, by the mechanism the dark theme was already approved under:
     * one formula, two caps, the approved value held EXACTLY at its family's anchor shade, and
     * every other entry a mix of two approved tokens. The anchor is per-family and carries the
     * ruling -- danger is built from alert-ink because FILL_PAIRS names that as the
     * danger fill, and warning sits at shade 400 because alert-fill's own approval bounds
     * it to text at >= 24px. The reasons are in packages/tokens/scripts/panel-theme.mjs.
     *
     * Filament does the WCAG arithmetic itself over whatever it is given, so the assertion
     * that matters is on ITS resolved pair: panel-palette.test.mjs drives
     * ButtonComponentColorMap over every family and requires >= 4.5 in both modes.
     */
    'filament_palette' => [
        // primary <- brand @600: the approved brand, on the shade a primary button uses
        'primary' => [
            50 => '#E9EFEB',
            100 => '#D5E3DC',
            200 => '#ACCBC0',
            300 => '#84B3A4',
            400 => '#5C9B88',
            500 => '#33836B',
            600 => '#0B6B4F', // the approved value, exactly
            700 => '#0C5B43',
            800 => '#0D4A37',
            900 => '#0E3A2B',
            950 => '#0F3125',
        ],
        // danger <- alert-ink @600: FILL_PAIRS names this as the danger fill, with surface as its label
        'danger' => [
            50 => '#EEECE8',
            100 => '#E6DCD6',
            200 => '#D6BAB0',
            300 => '#C6998B',
            400 => '#B67865',
            500 => '#A65640',
            600 => '#96351A', // the approved value, exactly
            700 => '#77311A',
            800 => '#592D1A',
            900 => '#3A291B',
            950 => '#2B271B',
        ],
        // success <- signal @600: the approved signal colour
        'success' => [
            50 => '#EBF3ED',
            100 => '#DBF1E6',
            200 => '#BBEDD6',
            300 => '#9CE9C6',
            400 => '#7CE4B6',
            500 => '#5DE0A7',
            600 => '#3DDC97', // the approved value, exactly
            700 => '#33B27B',
            800 => '#28875E',
            900 => '#1E5D42',
            950 => '#194834',
        ],
        // warning <- alert-fill @400: alert-fill carries small text on no shade, by its own approval
        'warning' => [
            50 => '#F1EEE9',
            100 => '#F0D8CE',
            200 => '#ECAD99',
            300 => '#E88263',
            400 => '#E4572E', // the approved value, exactly
            500 => '#C54F2B',
            600 => '#A64828',
            700 => '#874026',
            800 => '#693923',
            900 => '#4A3120',
            950 => '#3A2D1F',
        ],
        // info <- ink-mid @600: no approved info colour exists; informational emphasis is neutral
        'info' => [
            50 => '#ECEEEB',
            100 => '#DFE2DF',
            200 => '#C4CAC6',
            300 => '#AAB2AD',
            400 => '#8F9A94',
            500 => '#75827B',
            600 => '#5A6A62', // the approved value, exactly
            700 => '#495A52',
            800 => '#384A42',
            900 => '#273931',
            950 => '#1F3129',
        ],
        // gray <- ink-mid @600: the approved mid-neutral, ramped between paper and ink
        'gray' => [
            50 => '#ECEEEB',
            100 => '#DFE2DF',
            200 => '#C4CAC6',
            300 => '#AAB2AD',
            400 => '#8F9A94',
            500 => '#75827B',
            600 => '#5A6A62', // the approved value, exactly
            700 => '#495A52',
            800 => '#384A42',
            900 => '#273931',
            950 => '#1F3129',
        ],
    ],

    'space' => [
        4 => '0.25rem',
        8 => '0.5rem',
        12 => '0.75rem',
        16 => '1rem',
        24 => '1.5rem',
        32 => '2rem',
        48 => '3rem',
        64 => '4rem',
        96 => '6rem',
    ],

    'radius' => [
        8 => '0.5rem',
        16 => '1rem',
        24 => '1.5rem',
    ],

    'size' => [
        13 => '0.8125rem',
        16 => '1rem',
        20 => '1.25rem',
        28 => '1.75rem',
        44 => '2.75rem',
        64 => '4rem',
        96 => '6rem',
    ],

    'motion' => [
        'state' => '160ms',
        'entrance' => '240ms',
        'easing' => 'cubic-bezier(.32,.72,0,1)',
        'reduced' => '0ms',
    ],

    'focus' => [
        'width' => '2px',
        'offset' => '2px',
        'role' => 'brand',
    ],

    'elevation' => [
        'card' => '0 1px 2px rgba(16, 35, 27, 0.06)',
    ],

    'target_min_px' => 44,

    'fonts' => [
        'latin' => [
            'family' => 'Plus Jakarta Sans',
            'license' => 'OFL-1.1',
            'subset' => 'latin',
            'files' => [
                'plus-jakarta-sans-latin.woff2',
            ],
        ],
        'arabic' => [
            'family' => 'Tajawal',
            'license' => 'OFL-1.1',
            'subset' => 'arabic',
            'files' => [
                'tajawal-arabic-400.woff2',
                'tajawal-arabic-700.woff2',
            ],
        ],
        'fallback' => 'system-ui, sans-serif',
    ],
];
