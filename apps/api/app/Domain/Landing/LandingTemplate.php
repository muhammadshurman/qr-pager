<?php

declare(strict_types=1);

namespace App\Domain\Landing;

/**
 * Which ARRANGEMENT a tenant's published page uses. `QRPAGER-33`, narrowed to three by `QRPAGER-47`.
 *
 * ================================================================================
 * IT ARRANGES; IT DOES NOT RENDER. THAT SPLIT IS THE WHOLE MECHANISM.
 * ================================================================================
 *
 * A block's CONTENT is rendered in exactly one place -- `resources/views/landing/block.blade.php`
 * -- and an arrangement decides order, grouping and emphasis. The retired
 * `packages/ui/src/templates` made the same split for the same reason and wrote it down:
 * *"Three templates each writing their own seven renderings would be twenty-one places a
 * required field could be forgotten, and the test could only check the ones it knew about."*
 *
 * So `TemplateTotalityTest` reads the committed JSON schema and requires every block type to
 * appear in every arrangement's output. A block type added to `BlockSchema` later fails that
 * for each arrangement that does not place it, which is what makes *"no block the tenant
 * placed is absent"* a mechanical fact rather than a habit.
 *
 * ================================================================================
 * ⚠⚠ THREE CASES, AND `classic` / `editorial` ARE RETIRED — `QRPAGER-47`, PART A
 * ================================================================================
 *
 * Muhammad's ticket, in his words: *"Those two were the «بسيط / مميّز» pair the user
 * complained were indistinguishable. rail, ember and tile replace them as the single axis."*
 * So the choice a restaurant makes is now between three arrangements that differ
 * STRUCTURALLY — a ruled column, a dark centred hero, a photo grid — rather than between two
 * that differed by emphasis and read as the same page.
 *
 * ⚠ **THE STORED DOCUMENTS WERE MIGRATED, NOT LEFT TO FAIL VALIDATION.**
 * `2026_11_12_120000_retire_classic_and_editorial` maps `classic → rail` and
 * `editorial → ember` in BOTH `landing_pages.draft_document` and
 * `landing_pages.published_document`, per tenant, keyed, under `§2.10`'s settled pattern. A
 * document that still named a retired case would be refused by `LandingDocument::fromInput()`
 * the next time anything read it — the studio, a republish, the media reference sweep — so
 * the migration is what keeps every tenant's page loadable, and
 * `RetiredTemplateTest` is the test that fails if any document anywhere still names one.
 *
 * ⚠ **AND WHAT RETIRING `classic` COST, NAMED RATHER THAN QUIETLY DROPPED.** `classic` was
 * the arrangement that reproduced `tests/Fixtures/landing/pre-template-page.html` BYTE FOR
 * BYTE, and that fixture was `QRPAGER-33`'s guarantee that *"a tenant who published yesterday
 * and republishes today gets the same page."* Retiring the case retires the guarantee with
 * it: a tenant who was on `classic` now republishes as `rail` and their page CHANGES. That is
 * the ticket's intent — the complaint being answered is that the old pair was
 * indistinguishable — and it is written down here because it is the one thing this change
 * takes away that nothing else gives back.
 *
 * ================================================================================
 * ⚠ NOT A POSTGRES ENUM, AND THAT IS NOT AN EXCEPTION TO `§2.3`
 * ================================================================================
 *
 * `§2.3`'s convention is about a STORED COLUMN: *"the PHP enum is the source; the Postgres
 * type is a native `ENUM` created by migration."* This value is a member of the landing
 * DOCUMENT -- it lives inside `landing_pages.document` jsonb, which is Task 7's contract and
 * is validated by `BlockSchema`/`LandingSchema`, not by a column type. `tenant_language` went
 * the other way on 2026-11-03 (`QRPAGER-30`) precisely because it IS a column.
 *
 * ⚠ AND THAT IS ALSO WHY `QRPAGER-47`'s MIGRATION IS A **DATA** MIGRATION AND NOT A TYPE
 * CHANGE: there is no `ALTER TYPE … DROP VALUE` to write, because there is no type. What
 * exists is rows, which is exactly the case `PROJECT_INSTRUCTIONS.md §4 › Postgres traps`
 * says blocks whoever writes the first one.
 *
 * The one-source question still has to be answered, and it is: this enum is the source, and
 * `docs/contracts/landing-page.schema.json`'s `enum` for `template` is GENERATED from
 * `values()` by `php artisan landing:schema-export`, with `bin/landing-schema-check`
 * byte-diffing the committed file against it. The studio's cards are built from `cases()`.
 * There is no second list to keep in step.
 */
enum LandingTemplate: string
{
    /**
     * RAIL — one ruled column, and the arrangement a document gets when it names none.
     *
     * The approved visual direction (`§1`, 2026-11-08: *"the approved visual direction is
     * RAIL (السِجِل)"*) read as an ARRANGEMENT rather than as a palette: a single column
     * whose every region is separated from the next by one hairline rule, the steps of
     * `how_it_works` numbered down the side, and the menu's prices carried to the end of
     * each row by a leader rule. `docs/design/public/rail.html` is the reference.
     *
     * ⚠ WHAT MAKES IT DIFFERENT IS STRUCTURE, NOT COLOUR — the ticket's own words. The
     * three arrangements share one palette (the defaults `ThemeDefaults` resolves) and
     * differ in regions, grouping and order, which is what `TemplateStructureTest` asserts
     * and why it asserts element counts rather than pixels.
     */
    case Rail = 'rail';

    /**
     * EMBER — a dark centred hero, then everything narrow and centred.
     *
     * The hero is lifted out of the column into a full-measure band that inverts the
     * palette, which is the one arrangement where `color.text` and `color.background`
     * change places. Everything after it sits in a narrower measure than any other
     * arrangement uses, so a page with few blocks still reads as designed.
     * `docs/design/public/ember.html` is the reference.
     */
    case Ember = 'ember';

    /**
     * TILE — a photo grid, with the gallery and the menu as equal tiles.
     *
     * `gallery` and `menu_highlights` are promoted into one grid region of equal cells, so
     * a restaurant whose page is mostly photographs gets a page that is mostly
     * photographs. `docs/design/public/tile.html` is the reference.
     *
     * ⚠ IT RENDERS NO BLOCK OF ITS OWN, like every arrangement: the cells are
     * `landing.block`'s own `<article class="card">` and `<figure>`, re-laid out by rules
     * scoped under this arrangement's root. An arrangement that re-rendered a block would
     * be the twenty-one-places defect `block.blade.php`'s docblock describes.
     */
    case Tile = 'tile';

    /**
     * The arrangement a document gets when it names none.
     *
     * ⚠ EVERY DOCUMENT STORED BEFORE `QRPAGER-33` CARRIES NO `template` MEMBER, and there are
     * live ones, which is why `template` is on `KEYS` and NOT on `LandingDocument::REQUIRED`.
     *
     * ⚠⚠ **IT MOVED FROM `Classic` TO `Rail` IN `QRPAGER-47`, AND THAT MOVE IS WHAT MAKES THE
     * MIGRATION SMALL.** A document with no `template` member needs no write at all: it
     * inherits this constant, and this constant is now the arrangement `classic` was mapped
     * to. So the migration touches only the rows that name a retired case EXPLICITLY —
     * `jsonb_set(…, create_missing => false)` — and every other document is left byte-for-byte
     * as it was stored.
     */
    public const DEFAULT = self::Rail;

    /*
     * ⚠ THERE IS NO `styleRules()` METHOD HERE, AND ITS ABSENCE IS A LOCKED INVARIANT RATHER
     * THAN A DESIGN PREFERENCE. `QRPAGER-33` wrote one -- a `match` returning each
     * arrangement's CSS -- and `composer guard` refused it:
     *
     *     app/Domain/Landing/LandingTemplate.php:120  [css-rule-set] carries a CSS rule-set;
     *     a theme changes a token value and never a component rule
     *
     * Rule 11 is locked in `PROJECT_INSTRUCTIONS.md §3` and the guard's own message says *"a
     * change there comes first"*. It was the right refusal and no change was asked for: the
     * rule exists so that CSS lives in CSS and a theme can only move a token value, and
     * arrangement rules in a PHP `match` are exactly the shape it is written against. The
     * guard scans `app/` and `routes/`, so the rules now live where the rest of this page's
     * CSS already does -- inside `resources/views/landing/page.blade.php`'s `<style>`, behind
     * one `@if` on this enum.
     *
     * THE COST OF THAT, NAMED HONESTLY: a fourth arrangement is one case here, one Blade file
     * under `templates/`, AND one `@elseif` in `page.blade.php` if it needs rules of its own.
     * `TemplateArrangementTest` asserts the rendered page rather than a method, which is the
     * stronger subject anyway: it reads what a visitor receives.
     */

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The name a restaurant reads in the studio, in English and in Arabic.
     *
     * ⚠ ON THE ENUM AND NOT IN THE VIEW, so a fourth case cannot ship with a card labelled
     * `tile`. `match` is exhaustive, so adding a case without a label does not compile
     * under PHPStan and fails at runtime if it somehow does.
     *
     * `§12`'s zero-technical-background bar: the case names are the words a designer uses,
     * not the words a restaurant owner uses, so none of them is shown.
     *
     * ⚠⚠ `QRPAGER-47` SPLIT THIS INTO `name()` + `description()` AND KEPT `label()` AS THE
     * COMPOSITION OF THE TWO. The theme tab's cards need them APART — the approved artboard
     * sets the name at card-title weight with the sentence under it in the muted colour —
     * and anything that needs one line still has one. Composed rather than duplicated, so
     * there is no third place a name can drift.
     */
    public function label(string $locale): string
    {
        return $this->name($locale).' — '.$this->description($locale);
    }

    /**
     * The arrangement's own name, and nothing else: the card's title.
     *
     * The Arabic forms are the approved artboard's, which is also what Muhammad's ticket
     * writes — *"السِجِل (default), جَمر, بَلاط"*. They carry the short vowels because the
     * three words are read as names here rather than as ordinary nouns, and a restaurant
     * owner should not have to guess which reading is meant.
     */
    public function name(string $locale): string
    {
        // Two plain matches rather than one over a tuple: `match` on an array compares
        // element-wise and would work, and PHPStan cannot read exhaustiveness through it --
        // which is the half that matters here, because exhaustiveness is the mechanism.
        return $locale === 'ar'
            ? match ($this) {
                self::Rail => 'السِجِل',
                self::Ember => 'جَمر',
                self::Tile => 'بَلاط',
            }
        : match ($this) {
            self::Rail => 'Ledger',
            self::Ember => 'Ember',
            self::Tile => 'Tile',
        };
    }

    /**
     * What the arrangement is FOR, in a restaurant's own terms — the artboard's own copy.
     *
     * ⚠ IT DESCRIBES THE PAGE AND THE KIND OF PLACE, NEVER THE MECHANISM. *"A single column
     * whose every region is separated by one hairline rule"* is this file's docblock talking
     * to a programmer; *"quiet and clear, for a restaurant that wants to look organised"* is
     * the sentence an owner can choose from. `§12`: no system vocabulary on a screen.
     */
    public function description(string $locale): string
    {
        return $locale === 'ar'
            ? match ($this) {
                self::Rail => 'هادي وواضح. خطوط فاصلة رقيقة، وأسعار مرتبة بعمود. للمطاعم يلي بدها تبيّن نظام.',
                self::Ember => 'واجهة غامقة وخط كبير بالوسط. للمشاوي والمطاعم المسائية.',
                self::Tile => 'مربعات صور بشبكة. للكافيهات والحلويات يلي الصورة عندها هي البيع.',
            }
        : match ($this) {
            self::Rail => 'Quiet and clear: thin rules between sections, prices in a column. For a place that wants to look organised.',
            self::Ember => 'A dark opening with large centred type. For grills and places that fill up in the evening.',
            self::Tile => 'A grid of photographs. For cafés and bakeries where the picture is the sale.',
        };
    }
}
