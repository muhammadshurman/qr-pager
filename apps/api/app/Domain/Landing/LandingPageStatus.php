<?php

declare(strict_types=1);

namespace App\Domain\Landing;

use App\Domain\Concerns\HasDatabaseValues;
use App\Domain\DatabaseEnum;

/**
 * Mirrored by the Postgres enum type [landing_page_status] (PLAN.md §2.3).
 *
 * TWO VALUES, AND THE ABSENT THIRD IS THE DESIGN. There is no `publishing`. A publish is
 * write-new-then-switch (QRPAGER-14 decision D8): the immutable {checksum}.html is written
 * first, the stable pointer last, and this row last of all. So there is no moment at which
 * the row is a third thing -- a status that existed only inside a transaction would be one
 * no reader could ever observe, and a crash would strand rows in it with nothing to move
 * them back.
 *
 * In App\Domain, so no framework and no database -- deptrac enforces it.
 */
enum LandingPageStatus: string implements DatabaseEnum
{
    use HasDatabaseValues;

    case Draft = 'draft';
    case Published = 'published';

    /**
     * Both directions are legal and both are ordinary.
     *
     * `published -> draft` is unpublishing, which a tenant may do; `published ->
     * published` is a re-publish, which is the common case and is why the move is not
     * modelled as a one-way door. The table exists so the panel, the service and the
     * tests read one source rather than three conditions that can disagree.
     *
     * @return array<string, list<self>>
     */
    public static function allows(): array
    {
        return [
            self::Draft->value => [self::Published],
            self::Published->value => [self::Draft, self::Published],
        ];
    }

    /** Whether this status may move to $next. */
    public function allowsMoveTo(self $next): bool
    {
        return in_array($next, self::allows()[$this->value], true);
    }

    /** Whether a page in this status has bytes in the object store. */
    public function isLive(): bool
    {
        return $this === self::Published;
    }
}
