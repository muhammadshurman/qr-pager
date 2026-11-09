<?php

declare(strict_types=1);

/*
 * ================================================================================
 * FILAMENT'S ARABIC IS INCOMPLETE, AND THESE ARE THE KEYS THE STUDIO RENDERS.
 * ================================================================================
 *
 * `QRPAGER-41` part A took Filament's own `ar` for free -- all ten packages ship one, which
 * is what translates `Collapse all`, the block picker, clone, delete and move up/down with
 * nothing written by us. The enumeration test then found the gap: **a key missing from
 * `ar/components.php` falls back to the English line and raises nothing**, so part of the
 * panel stayed English while the rest translated.
 *
 * MEASURED by diffing the two key sets, `en` against `ar`, over every package this
 * application uses -- 41 keys missing in total, plus `widgets/.../js-widget.php` which has no
 * Arabic file at all. This override carries only the ones the STUDIO actually renders:
 *
 *   builder.reordering.*    the block list's screen-reader announcements. `QRPAGER-40` built
 *                           that list, and `§12`'s accessibility bar is not waived for this
 *                           panel -- an Arabic owner reordering his page heard "Block,
 *                           position 2 of 7" in English.
 *   repeater.reordering.*   the same, for steps, menu items, opening hours and links.
 *   select.required_description   "Select an option." on every Select: the image pickers and
 *                           the two font choices.
 *   select.error_message    what a Select says when its options fail to load.
 *
 * ⚠ **THE OTHER MISSING KEYS ARE DELIBERATELY NOT HERE**, and the reason is the one that
 * keeps this file honest rather than growing: `key_value.*`, `js_field.*` and
 * `file_upload.placeholder` belong to components this panel does not use -- `file_upload`
 * especially, because the media page keeps a plain `<input type="file">` on purpose so every
 * byte passes `InspectedImage` (`§4a`). Translating a string nothing renders would be
 * unverifiable by any test here, and an unverifiable translation is how a language file
 * starts drifting from the product.
 *
 * ⚠ **AND LARAVEL MERGES THIS ON TOP OF THE PACKAGE'S OWN FILE**, key by key
 * (`FileLoader::loadNamespaceOverrides`), so this is a patch and not a replacement: every
 * Arabic string Filament already ships is untouched, and a key Filament adds later arrives
 * without an edit here.
 */

return [

    'builder' => [
        'reordering' => [
            'item' => 'كتلة',
            'position' => ':label، الموضع :position من :count',
            'moved' => 'نُقلت :label إلى الموضع :position من :count',
        ],
    ],

    'repeater' => [
        'reordering' => [
            'item' => 'عنصر',
            'position' => ':label، الموضع :position من :count',
            'moved' => 'نُقل :label إلى الموضع :position من :count',
        ],
    ],

    'select' => [
        'required_description' => 'اختر خيارًا.',
        'error_message' => 'تعذّر تحميل الخيارات. حاول مرة أخرى.',
    ],

];
