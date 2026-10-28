<?php

declare(strict_types=1);

namespace App\Features\Landing\Panel;

use App\Features\Landing\Panel\Pages\DomainsPage;
use App\Features\Landing\Panel\Pages\LandingBuilderPage;
use App\Features\Landing\Panel\Pages\MediaLibraryPage;
use App\Features\Landing\Panel\Pages\PreviewController;
use App\Platform\Http\Middleware\RateLimit;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * ================================================================================
 * THE TENANT-SCOPED PANEL — SR-1, AND WHY IT IS NOT THE PLATFORM PANEL
 * ================================================================================
 *
 * `PLAN.md §4.3` lists the landing page builder among the *Filament admin (Tasks 6-7)*
 * screens, and `PROJECT_INSTRUCTIONS.md §4 › Tenancy` (added 2026-10-28) says the
 * platform admin panel "operates only on tables belonging to no tenant", that a platform
 * admin "holds no tenant ability and no cross-tenant read", and that **"a panel query
 * that reaches a tenant table is a rejected design"**.
 *
 * A landing page, its media and its domains are tenant content. So the builder cannot be
 * a platform-panel screen, and this is decision D1: a SECOND panel, at `/studio`, whose
 * every user is a member of exactly one tenant and whose every query runs inside that
 * tenant's scope. `§4.3`'s wording is corrected by `R-127` rather than by an edit to
 * `§4`. Muhammad accepted SR-1 on 2026-10-28 before the build began.
 *
 * ================================================================================
 * NO ->login(), FOR THE REASON THE PLATFORM PANEL HAS NONE
 * ================================================================================
 *
 * `§4 › Tenancy`: two authentication surfaces, never confused. Filament's own login page
 * would be a THIRD -- a second credential form with its own throttling, its own session
 * handling and no second factor, reaching the same `users` table Task 5 moved behind
 * `authenticate_user()` and a TOTP challenge. This panel authenticates nobody; it only
 * refuses. `authGuard('web')` is stated rather than defaulted so a later change to
 * `config/auth.php` cannot silently move which guard it trusts.
 *
 * ================================================================================
 * §12 — EXEMPT FROM THE VISUAL BAR UNTIL TASK 8, NEVER FROM THE ACCESSIBILITY BAR
 * ================================================================================
 *
 * No `->colors()` and no `->font()`: inventing a palette now would be inventing a token
 * set to satisfy a standard that does not exist yet. What is NOT waived is accessibility,
 * and `StudioPanelAccessibilityTest` states honestly what can be asserted from a
 * server-rendered response and what genuinely needs a browser (R-118's precedent).
 */
class StudioPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            // NOT ->default(). The platform panel is the default one; two defaults is a
            // configuration error Filament resolves by whichever provider ran last.
            ->id('studio')
            ->path('studio')
            ->authGuard('web')
            // EXPLICIT, never discovered. `discoverPages()` takes a directory, so the
            // set of pages would be whatever happens to be in it -- and
            // StudioPanelInvariantTest drives "every page in this panel" as all five
            // Task 5 principals, which needs an enumerable set to mean anything.
            ->pages([
                LandingBuilderPage::class,
                MediaLibraryPage::class,
                DomainsPage::class,
            ])
            ->widgets([])
            ->middleware([
                // FIRST, before StartSession, which is Task 6's security H-2 applied
                // before a review can find it again: StartSession persists a session to
                // carry a CSRF token BEFORE Authenticate refuses, so each anonymous 401
                // would otherwise mint one Redis key with a 7200 s TTL. Redis is the
                // session, cache AND queue store, so that loop fills the store the
                // customer pager path depends on.
                RateLimit::class.':panel',
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                // AuthenticateSession is absent for the reason the platform panel's
                // docblock gives in full: it cannot fire on this application's grants
                // (R-120), and a control that is present and cannot fire reads as
                // protection while being none.
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            // The preview, inside the panel's own route group so it inherits exactly the
            // middleware above -- session, CSRF, the limiter and Authenticate -- rather
            // than a second stack in routes/web.php that could drift from this one.
            ->routes(function (): void {
                // Named `preview`, not `studio.preview`: Filament prefixes a panel's
                // routes with `filament.{panel id}.`, so the full names are
                // `filament.studio.preview` and `filament.studio.preview.asset`.
                Route::get('preview', PreviewController::class)->name('preview');
                Route::get('preview/asset/{asset}', [PreviewController::class, 'asset'])
                    ->name('preview.asset');
            });
    }
}
