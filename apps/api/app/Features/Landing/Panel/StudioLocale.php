<?php

declare(strict_types=1);

namespace App\Features\Landing\Panel;

use App\Features\Landing\PanelLanguage;
use App\Platform\Http\TenantPanelAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The studio renders in the RESTAURANT'S OWN LANGUAGE. `QRPAGER-41` part A.
 *
 * ================================================================================
 * ONE LINE TURNS ON THREE THINGS, AND THAT IS WHY THE LOCALE IS THE MECHANISM
 * ================================================================================
 *
 * Muhammad's ticket: *"Every string the owner can see is localized, and the panel follows
 * the tenant's language. `tenants.language` already exists and `dir` is already derived from
 * it — use them; do not add a setting."* So there is no preference, no switcher and no
 * second source: the column the owner already set for his PUBLISHED page decides the panel
 * he sets it from.
 *
 * Setting the application locale -- rather than passing a language into each view -- is what
 * makes the other two follow for free, and all three were MEASURED before this file existed:
 *
 *   1. **Filament's own chrome translates.** Every one of the ten Filament packages ships
 *      `resources/lang/ar`, so `Collapse all`, `Expand all`, `Add a block`'s modal, clone,
 *      delete, reorder, move up/down and the block picker are Arabic with nothing written
 *      here. `§4a` lists exactly which strings that covered, so the remainder is auditable
 *      rather than taken on trust.
 *   2. **The document direction flips.** `filament-panels::layout.direction` is `rtl` in
 *      that package's `ar` file and `base.blade.php` reads it into `<html dir>`.
 *      `PlatformPanelAccessibilityTest` already proved this property on the other panel --
 *      *"switching the locale to Arabic switches the document direction, with no change to
 *      any template"* -- so this is a mechanism with a test, not a hope.
 *   3. **One call site that was already right starts working.** `StudioForm` has read
 *      `$t->label(app()->getLocale())` for the arrangement options since `QRPAGER-33`. The
 *      locale was never anything but `en`, so the Arabic half of `LandingTemplate::label()`
 *      had never once been reached in the panel.
 *
 * ================================================================================
 * ⚠ IT CANNOT REFUSE A REQUEST, AND THAT IS DELIBERATE
 * ================================================================================
 *
 * `TenantPanelAccess::membership()` is the NULLABLE reader; `tenantId()` is the one that
 * `abort(403)`s. This middleware calls the first and returns early on null. A middleware
 * whose job is to choose a language must never be able to change who gets in: the panel's
 * door is `Authenticate` plus every page's own `canAccess()`, and a second gate here would
 * be a copy of that rule in a place no reviewer looks for one.
 *
 * ⚠ AND IT RUNS IN `authMiddleware`, AFTER `Authenticate`. The membership comes from the
 * session, so in the panel's ordinary `middleware()` stack -- which runs before the user is
 * resolved -- it would read null on every request and silently do nothing. That is the
 * failure mode this note exists to stop someone reintroducing by "tidying" the two lists
 * together.
 */
final class StudioLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        self::apply();

        /** @var Response */
        return $next($request);
    }

    /**
     * Set the locale from the acting membership, or leave it alone.
     *
     * ⚠ SHARED WITH `HasStudioLocale`, which is NOT a duplicate of this middleware: a
     * Livewire update never runs the panel's stack, so the trait is the only thing that
     * reaches an action. One function, two entry points, and that file says why there are
     * two.
     */
    public static function apply(): void
    {
        $membership = TenantPanelAccess::membership();

        if ($membership === null) {
            return;
        }

        /*
         * ⚠⚠ THE PERSON'S CHOICE FIRST, THE RESTAURANT'S LANGUAGE SECOND — `QRPAGER-48`.
         *
         * This read `TenantPageLanguage` directly until 2026-11-13, which made the panel's
         * language and the PUBLISHED PAGE's language the same column. Muhammad's ruling
         * separated them: *"two staff at the same restaurant can want different ones"*, and
         * `tenants.language` keeps its own meaning, the language a CUSTOMER reads.
         *
         * `PanelLanguage::current()` resolves `users.panel_language ?? tenants.language`, so
         * a person who has chosen nothing gets exactly the behaviour that shipped before the
         * column existed.
         */
        /*
         * ⚠ THE PERSON'S ID COMES FROM THE GUARD, NOT FROM THE MEMBERSHIP. `Membership`
         * carries TENANT facts — id, slug, name, role, status — and deliberately no user id;
         * it answers *which restaurant am I acting in*, not *who am I*. The panel's
         * `authGuard('web')` is stated in `StudioPanelProvider` precisely so this is not a
         * guess about which guard holds the person.
         */
        $userId = auth()->id();

        if (! is_string($userId)) {
            // No authenticated person: the panel refuses anyway, and leaving the locale
            // alone is what this method already does for a null membership.
            return;
        }

        app()->setLocale(
            app(PanelLanguage::class)->current($userId, $membership->tenantId)->value,
        );
    }
}
