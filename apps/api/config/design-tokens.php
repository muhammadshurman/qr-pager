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
            'file' => 'plus-jakarta-sans-latin.woff2',
        ],
        'arabic' => [
            'family' => 'Tajawal',
            'license' => 'OFL-1.1',
            'subset' => 'arabic',
            'file' => 'tajawal-arabic.woff2',
        ],
        'fallback' => 'system-ui, sans-serif',
    ],
];
