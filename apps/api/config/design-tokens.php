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
        '--ready-ink' => '#FCFCFA',
        '--ready-signal' => '#18C880',
    ],

    'protected_properties' => [
        '--ready-field',
        '--ready-ink',
        '--ready-signal',
    ],

    'colours' => [
        'light' => [
            'paper' => '#FCFCFA',
            'surface' => '#FFFFFF',
            'surface-2' => '#F7F7F3',
            'hairline' => '#DFDFD7',
            'border-strong' => '#828382',
            'ink' => '#191C22',
            'ink-mid' => '#6B6F7A',
            'brand' => '#191C22',
            'signal' => '#18C880',
            'alert-fill' => '#8C2F27',
            'alert-ink' => '#8C2F27',
        ],
        'dark' => [
            'paper' => '#0A1F17',
            'surface' => '#1D3129',
            'surface-2' => '#2C3E37',
            'hairline' => '#44544D',
            'border-strong' => '#838E89',
            'ink' => '#FCFCFA',
            'ink-mid' => '#ABADB2',
            'brand' => '#FCFCFA',
            'signal' => '#18C880',
            'alert-fill' => '#B37771',
            'alert-ink' => '#CCA49F',
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
            50 => '#F3F3F1',
            100 => '#DFDFDF',
            200 => '#B7B8B9',
            300 => '#909193',
            400 => '#686A6D',
            500 => '#414348',
            600 => '#191C22', // the approved value, exactly
            700 => '#191C22',
            800 => '#191C22',
            900 => '#191C22',
            950 => '#191C22',
        ],
        // danger <- alert-ink @600: FILL_PAIRS names this as the danger fill, with surface as its label
        'danger' => [
            50 => '#F8F4F2',
            100 => '#EEE2DF',
            200 => '#DABEBA',
            300 => '#C79A95',
            400 => '#B37771',
            500 => '#A0534C',
            600 => '#8C2F27', // the approved value, exactly
            700 => '#722B26',
            800 => '#572625',
            900 => '#3D2224',
            950 => '#302023',
        ],
        // success <- signal @600: the approved signal colour
        'success' => [
            50 => '#F3FAF5',
            100 => '#DFF5EA',
            200 => '#B7ECD5',
            300 => '#8FE3C0',
            400 => '#68DAAB',
            500 => '#40D195',
            600 => '#18C880', // the approved value, exactly
            700 => '#18A16B',
            800 => '#187955',
            900 => '#195240',
            950 => '#193E35',
        ],
        // warning <- alert-fill @400: alert-fill carries small text on no shade, by its own approval
        'warning' => [
            50 => '#F8F4F2',
            100 => '#E8D8D5',
            200 => '#C99F9B',
            300 => '#AB6761',
            400 => '#8C2F27', // the approved value, exactly
            500 => '#7B2C26',
            600 => '#6B2926',
            700 => '#5A2725',
            800 => '#492424',
            900 => '#382123',
            950 => '#302023',
        ],
        // info <- ink-mid @600: no approved info colour exists; informational emphasis is neutral
        'info' => [
            50 => '#F6F6F5',
            100 => '#EAEAEA',
            200 => '#D0D1D3',
            300 => '#B7B9BD',
            400 => '#9EA0A7',
            500 => '#848890',
            600 => '#6B6F7A', // the approved value, exactly
            700 => '#585C66',
            800 => '#464952',
            900 => '#33363E',
            950 => '#292D34',
        ],
        // gray <- ink-mid @600: the approved mid-neutral, ramped between paper and ink
        'gray' => [
            50 => '#F6F6F5',
            100 => '#EAEAEA',
            200 => '#D0D1D3',
            300 => '#B7B9BD',
            400 => '#9EA0A7',
            500 => '#848890',
            600 => '#6B6F7A', // the approved value, exactly
            700 => '#585C66',
            800 => '#464952',
            900 => '#33363E',
            950 => '#292D34',
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
        'surface' => '2px',
        'control' => '4px',
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
        'state' => '120ms',
        'spin' => '800ms',
        'easing' => 'linear',
        'reduced' => '0ms',
    ],

    'focus' => [
        'width' => '2px',
        'offset' => '2px',
        'role' => 'brand',
    ],

    'elevation' => [
        'card' => '0 1px 2px rgba(25, 28, 34, 0.06)',
    ],

    'target_min_px' => 44,

    'fonts' => [
        'latin' => [
            'family' => 'Archivo',
            'license' => 'OFL-1.1',
            'subset' => 'latin',
            'files' => [
                'archivo-latin.woff2',
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
