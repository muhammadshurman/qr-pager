<?php

declare(strict_types=1);

namespace App\Features\Landing\Panel;

/**
 * The studio's locale, applied where a PANEL MIDDLEWARE cannot reach. `QRPAGER-41` part A.
 *
 * ================================================================================
 * ⚠ WHY A SECOND PLACE AT ALL, WHICH LOOKS LIKE A DUPLICATE AND IS NOT ONE
 * ================================================================================
 *
 * `StudioLocale` is registered in the panel's `authMiddleware`, and that covers the
 * full-page GET: the locale is set before anything renders, so `<html lang>` and `<html dir>`
 * are right and every string in the document is the tenant's.
 *
 * **It does not cover a Livewire action, and this codebase already knew that.**
 * `TenantPanelAccess`'s docblock records the measurement from Task 6's security M-1:
 * *"`POST /livewire-<hash>/update` runs neither `SetUpPanel` nor Filament's
 * `Authenticate`"*. Confirmed again here with `route:list` before writing a line of this:
 *
 *     POST livewire-<hash>/update -> ['web', 'Livewire\…\RequireLivewireHeaders']
 *
 * Two entries, neither of them the panel's stack. So every notification raised by `save`,
 * `publish`, `unpublish`, `store`, `delete`, `add` and `advance` would have rendered in the
 * CONFIGURED locale -- English -- inside an Arabic panel. The defect would have been
 * invisible in any screenshot of a resting page and would have appeared the first time the
 * owner pressed a button.
 *
 * ⚠ **AND IT WAS A TEST THAT SAID SO, not a reading.** `StudioActionsTest`'s publish-failure
 * case drives the page through `Livewire::test()`, which takes the same path: it asserted the
 * Arabic headline, got the English one, and that is the whole reason this file exists.
 *
 * ================================================================================
 * `boot{TraitName}` IS LIVEWIRE'S OWN HOOK, NOT A CONVENTION INVENTED HERE
 * ================================================================================
 *
 * Livewire calls `boot<TraitName>()` on every request a component serves -- the initial
 * render and every subsequent update -- so a page that uses this trait cannot be reached
 * without the locale being set. That is the property worth having: the pages do not have to
 * remember to call anything, and a fifth page added later gets it by using the trait, which
 * `StudioLocalizationTest` requires of every page the panel registers.
 */
trait HasStudioLocale
{
    public function bootHasStudioLocale(): void
    {
        StudioLocale::apply();
    }
}
