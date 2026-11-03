<?php

declare(strict_types=1);

namespace App\Domain\Landing;

/**
 * Which ARRANGEMENT a tenant's published page uses. `QRPAGER-33`.
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
 * ⚠ NOT A POSTGRES ENUM, AND THAT IS NOT AN EXCEPTION TO `§2.3`
 * ================================================================================
 *
 * `§2.3`'s convention is about a STORED COLUMN: *"the PHP enum is the source; the Postgres
 * type is a native `ENUM` created by migration."* This value is a member of the landing
 * DOCUMENT -- it lives inside `landing_pages.document` jsonb, which is Task 7's contract and
 * is validated by `BlockSchema`/`LandingSchema`, not by a column type. `tenant_language` went
 * the other way on 2026-11-03 (`QRPAGER-30`) precisely because it IS a column.
 *
 * The one-source question still has to be answered, and it is: this enum is the source, and
 * `docs/contracts/landing-page.schema.json`'s `enum` for `template` is GENERATED from
 * `values()` by `php artisan landing:schema-export`, with `bin/landing-schema-check`
 * byte-diffing the committed file against it. The studio's buttons are built from `cases()`.
 * There is no second list to keep in step.
 *
 * ================================================================================
 * TWO CASES, DELIBERATELY, AND THE THIRD IS A FILE AND A LINE
 * ================================================================================
 *
 * Muhammad's scope cut of 2026-11-03, rank 1, in his words: *"the mechanism is the value, the
 * arrangements are content ... A third is then a template file and an enum value, not a run."*
 * Recorded in `PLAN.md §1` and on `QRPAGER-33` so a later reader sees a decision rather than
 * an omission.
 *
 * `compact` is the named next one -- the retired kit's version put the hours, the address and
 * the phone number above the hero, on the reasoning that *"a customer standing outside the
 * restaurant wants the hours, the address and the phone number, not the hero"*. Adding it is
 * one case here plus one file beside `editorial.blade.php`; every proof in this feature is
 * total over `cases()`, so none of them needs a new assertion for it.
 */
enum LandingTemplate: string
{
    /**
     * The builder's own order, one column.
     *
     * ⚠ **IT IS WHAT `landing.page` ALREADY RENDERED, BYTE FOR BYTE, AND THAT IS ASSERTED.**
     * The template this page had before this feature existed was an unnamed `@foreach` over
     * the blocks in stored order; `classic` is that loop, moved into a file and given a name.
     * `tests/Fixtures/landing/pre-template-page.html` was captured BEFORE the first change
     * and `TemplateArrangementTest` requires this arrangement to reproduce it exactly -- so a
     * tenant who published yesterday and republishes today gets the same page.
     */
    case Classic = 'classic';

    /**
     * The hero leads, then the story, with the gallery given the full measure.
     *
     * `how_it_works` is promoted to sit directly under the hero, because on a pager product
     * the question a first-time visitor has is *"what happens when I scan this"*. The gallery
     * runs wide and the menu follows. Anything the arrangement does not name still renders,
     * before the footer, which is the `rest()` safety net the retired kit described and the
     * totality proof enforces.
     *
     * ⚠ **WHY THIS ONE AND NOT `compact` AS THE SECOND:** the ticket's product need is *"a
     * client gets a DESIGNED page instead of a blank one"*, and `classic` is already the plain
     * one. `editorial` is the arrangement that most changes the page's character, so the pair
     * spans the choice a restaurant is actually making. `compact` is a phone-first utility
     * layout and is a better third than a second.
     */
    case Editorial = 'editorial';

    /**
     * The arrangement a document gets when it names none.
     *
     * ⚠ EVERY DOCUMENT STORED BEFORE THIS FEATURE CARRIES NO `template` MEMBER, and there are
     * live ones. So this is not a convenience: it is the clause that keeps `§6.6`'s published
     * bytes stable for a tenant who does nothing, and it is why `template` is NOT on
     * `LandingDocument::REQUIRED` even though it is on `KEYS`.
     */
    public const DEFAULT = self::Classic;

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
     * THE COST OF THAT, NAMED HONESTLY: a third arrangement is one case here, one Blade file
     * under `templates/`, AND one `@if` in `page.blade.php` if it needs rules of its own --
     * which is one line more than the scope cut's *"a template file and an enum value"*.
     * `TemplateArrangementTest` asserts the rendered page rather than a method, which is the
     * stronger subject anyway: it reads what a visitor receives.
     */

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The name a restaurant reads in the builder, in English and in Arabic.
     *
     * ⚠ ON THE ENUM AND NOT IN THE VIEW, so a third case cannot ship with a button labelled
     * `editorial`. `match` is exhaustive, so adding a case without a label does not compile
     * under PHPStan and fails at runtime if it somehow does.
     *
     * `§12`'s zero-technical-background bar: `classic` and `editorial` are the words a
     * designer uses, not the words a restaurant owner uses, so neither is shown.
     */
    public function label(string $locale): string
    {
        // Two plain matches rather than one over a tuple: `match` on an array compares
        // element-wise and would work, and PHPStan cannot read exhaustiveness through it --
        // which is the half that matters here, because exhaustiveness is the mechanism.
        return $locale === 'ar'
            ? match ($this) {
                self::Classic => 'بسيط — أقسامك بالترتيب الذي رتّبته',
                self::Editorial => 'مميّز — صورة افتتاحية كبيرة، ثم كيف يعمل',
            }
        : match ($this) {
            self::Classic => 'Simple — your blocks in the order you arranged them',
            self::Editorial => 'Feature — a large opening image, then how it works',
        };
    }
}
