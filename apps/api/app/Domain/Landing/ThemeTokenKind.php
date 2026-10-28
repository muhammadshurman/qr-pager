<?php

declare(strict_types=1);

namespace App\Domain\Landing;

/**
 * The kinds of value a theme token may take (decision D6).
 *
 * Separate from ThemeTokens so that rule 11's parse of that file reads a NAMES array whose
 * values are a simple `Kind::Case` each -- the guard reads token NAMES, and a file it has
 * to parse should be as close to data as it can be.
 */
enum ThemeTokenKind: string
{
    case Colour = 'colour';
    case Length = 'length';
    case FontChoice = 'font_choice';
}
