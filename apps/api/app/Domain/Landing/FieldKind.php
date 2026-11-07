<?php

declare(strict_types=1);

namespace App\Domain\Landing;

/**
 * The kinds of value a block field may hold -- QRPAGER-14 §6.4's table, as a type.
 *
 * There is no `html` and no `richtext`, and that absence is the design (R-128): with no
 * markup field there is no allow-list to get wrong, so a validator bug alone cannot inject
 * and an escaping bug alone cannot either (decision D5).
 *
 * In App\Domain, so no framework and no database -- deptrac enforces it.
 */
enum FieldKind: string
{
    /** Plain text, length-bounded. `<`, `>` and `&` are kept as data and escaped at render. */
    case Text = 'text';

    /** An absolute https: URL, parsed and re-assembled -- never passed through. */
    case Url = 'url';

    /** A mailto: address. Only the contact block has one. */
    case Email = 'email';

    /** A tel: number. Only the contact block has one. */
    case Telephone = 'telephone';

    /** A uuid that must resolve to a media_assets row IN THIS TENANT. */
    case Media = 'media';

    /** A number in a stated range. Never an embed, an iframe or a provider URL. */
    case Number = 'number';

    /** A bounded list of items, each with its own field map. */
    case ItemList = 'item_list';
}
