<?php

declare(strict_types=1);

namespace App\Features\Landing;

use RuntimeException;

/**
 * A publish that refused, with the headline §12.1 shows and the reason beneath it.
 *
 * ================================================================================
 * THERE ARE TWO HEADLINES, AND THE SECOND ONE EXISTS BECAUSE THE FIRST BECAME A LIE
 * ================================================================================
 *
 * The ticket's `§12.1` copy table has one row for this: "Publish failed — your live page
 * is unchanged. {reason}". That sentence was a promise the ORDERING made -- every throw
 * site was at or before the pointer write, so a refusal left the previous page serving.
 *
 * `SR-4` inverted the ordering: `landing_pages` now commits BEFORE anything is written to
 * the store, because with the row last every failure between a store write and the commit
 * left a page live that nothing recorded, which is clause 1 of the named invariant. The
 * consequence is this class's: from the commit onwards the promise is false. A failed
 * manifest write leaves the NEW page live; a failed asset copy leaves the old page live
 * and the ROW already saying `published`, so the tenant reads "your live page is
 * unchanged" beside a panel that says "Published, no unpublished changes".
 *
 * So `afterRecord()` is the throw for everything from phase 4 on, and it tells the tenant
 * what is actually true: the page is recorded as published, it may not be live, publish
 * again. `LandingPublishTest` drives both paths and asserts the headline each one shows.
 */
final class PublishFailed extends RuntimeException
{
    /*
     * ================================================================================
     * ⚠ THE TWO HEADLINES ARE TRANSLATION KEYS NOW, NOT SENTENCES — `QRPAGER-41`
     * ================================================================================
     *
     * They were the English sentences themselves, which a class constant has to be: PHP
     * evaluates a constant initialiser once, at class load, so `__()` cannot appear in one
     * and a sentence fixed there could never follow a locale chosen per request.
     *
     * So the constant names what is wrong and `lang/{ar,en}/landing.php` says it. `$headline`
     * is a KEY and the panel translates it at the moment it renders the notification --
     * `LandingBuilderPage` does `__($e->headline)`. The two call sites that compare a
     * headline keep comparing against the constant and are unaffected by what it holds,
     * which is why this change does not weaken the distinction the class exists to make.
     */

    /** Nothing was written: the store and the row are both on the previous publish. */
    public const LIVE_UNCHANGED = 'landing.publish.live_unchanged';

    /** The row committed and the store did not catch up. Publishing again converges. */
    public const AFTER_RECORD = 'landing.publish.after_record';

    public function __construct(
        public readonly string $reason,
        /** A translation key, not a sentence — see the note above. */
        public readonly string $headline = self::LIVE_UNCHANGED,
    ) {
        parent::__construct($reason);
    }

    /** A failure from publish()'s phase 4 or 5, i.e. after the row has committed. */
    public static function afterRecord(string $reason): self
    {
        return new self($reason, self::AFTER_RECORD);
    }
}
