<?php

declare(strict_types=1);

namespace App\Domain\Landing;

/**
 * The seven blocks of PLAN.md §4.4, and nothing else.
 *
 * NOT a Postgres enum, deliberately: a block type is never a column. The whole draft is
 * one jsonb document (§2.5), which is what makes a publish cost no read per block, so
 * there is no table for a type to be the type OF. It is in the data contract all the same,
 * because `docs/contracts/landing-page.schema.json` is generated from it and Task 11's
 * renderer consumes that -- which is the same single-source rule, one layer out.
 *
 * In App\Domain, so no framework and no database -- deptrac enforces it.
 */
enum BlockType: string
{
    case Hero = 'hero';
    case HowItWorks = 'how_it_works';
    case MenuHighlights = 'menu_highlights';
    case Gallery = 'gallery';
    case HoursLocation = 'hours_location';
    case Contact = 'contact';
    case Footer = 'footer';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The label the builder shows. Here rather than in the panel, because the generated
     * JSON Schema carries it too and Task 11's renderer needs the same words.
     */
    public function label(): string
    {
        return match ($this) {
            self::Hero => 'Hero',
            self::HowItWorks => 'How the pager works',
            self::MenuHighlights => 'Menu highlights',
            self::Gallery => 'Gallery',
            self::HoursLocation => 'Hours, location and map',
            self::Contact => 'Contact and social',
            self::Footer => 'Footer',
        };
    }
}
