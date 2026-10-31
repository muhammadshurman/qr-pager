<?php

declare(strict_types=1);

namespace App\Features\Provisioning\Panel;

use App\Features\Provisioning\Panel\Pages\PlatformDashboardPage;
use App\Features\Provisioning\Panel\Resources\PlatformAuditResource;
use App\Features\Provisioning\Panel\Resources\RestaurantResource;
use App\Platform\Http\Middleware\RateLimit;
use Filament\FontProviders\LocalFontProvider;
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
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The platform admin panel. Filament 5.11.0 on Laravel 13.38.0, both verified in-session.
 *
 * ================================================================================
 * IT LIVES IN app/Features AND NOT IN app/Providers, AND THAT IS A DEPTRAC RULE
 * ================================================================================
 *
 * Rule 2: the Platform layer imports no feature module. This panel's resources reach
 * `App\Features\Provisioning\TenantProvisioning`, so a provider under `app/Providers`
 * would be a Platform → Features edge and `composer deptrac` refuses it.
 * `NotificationsServiceProvider` set the precedent at Task 4 and `bootstrap/providers.php`
 * carries the argument.
 *
 * ================================================================================
 * NO ->login(), AND THAT IS THE MOST IMPORTANT LINE THAT IS NOT HERE
 * ================================================================================
 *
 * `php artisan filament:install --panels` generates `->login()`, which mounts Filament's
 * own Livewire credential form at `/admin/login`. **It is deliberately absent.**
 * `§4 › Tenancy` says there are **two authentication surfaces, never confused** — staff
 * and admins authenticate as users, a customer only by presenting the QR token — and
 * Filament's login page would be a **third**: a second credential form, with its own
 * throttling, its own session handling and no second factor, reaching the same `users`
 * table that Task 5 moved behind `authenticate_user()` and a TOTP challenge.
 *
 * So the panel **reuses Task 5's session** instead. `AuthController::login()` ends in
 * `Auth::guard('web')->login($user)` after the 2FA challenge, so by the time anyone
 * reaches this panel they are authenticated on the `web` guard, with the second factor
 * already enforced by the surface that owns it. The panel authenticates nobody; it only
 * refuses.
 *
 * `authGuard('web')` is therefore stated rather than defaulted, so a later change to
 * `config/auth.php`'s default cannot silently move which guard the panel trusts.
 *
 * ================================================================================
 * §12 — EXEMPT FROM THE VISUAL BAR, NOT FROM THE ACCESSIBILITY BAR
 * ================================================================================
 *
 * This panel is built FUNCTIONALLY in Tasks 6-7 and restyled from the approved tokens in
 * Task 8, which is where the tokens first exist. So there is **no `->colors()` call and no
 * `->font()` call here**: inventing a palette now would be inventing a token set to satisfy
 * a standard that does not exist yet, which `§12` and the ticket's `§5` both forbid in
 * those words. The library default look is explicitly acceptable here and nowhere else.
 *
 * What is NOT waived is the accessibility bar, and the honest statement of it is in
 * `PlatformPanelAccessibilityTest`: what can be asserted from a server-rendered response is
 * asserted there, and what genuinely needs a browser — a visible focus ring, a measured
 * 44px target, `prefers-reduced-motion` — is recorded as needing Task 9's harness rather
 * than claimed. `R-100` is the precedent for saying so instead of asserting it weakly.
 */
class PlatformPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('platform')
            ->path('platform')
            // ================================================================================
            // THE RESTYLE'S REGISTRATIONS (Task 8b). §12's Tasks 6-7 EXEMPTION EXPIRES HERE.
            // ================================================================================
            //
            // The docblock above says this panel is "restyled from the approved tokens in
            // Task 8, which is where the tokens first exist". They exist now, so it is.
            //
            // (a) THE COMPILED THEME. `viteTheme()`'s argument is resolved through
            //     `app(Vite::class)` -- the MANIFEST -- so this exact path must also appear in
            //     `vite.config.js`'s Laravel plugin `input` array. Absent there it is a
            //     `ViteManifestNotFoundException` at render time, not a styling difference.
            //     Read from `HasTheme::getTheme()`, not from documentation.
            ->viteTheme('resources/css/filament/platform/theme.css')
            // (b) THE FACE -- and ⚠ `provider:` IS NOT OPTIONAL, which is the sharp edge.
            //     Measured in `HasFont::getFontProvider()`: with a custom family and no
            //     provider, the default is **`BunnyFontProvider`**, which emits
            //     `<link rel="preconnect" href="https://fonts.bunny.net">` and a stylesheet
            //     from that host. So the obvious fix for `§9.1`'s CDN font -- just call
            //     `->font()` with the approved family -- would have RE-ADDED a third-party
            //     font request to this very surface from a different file, while the commit
            //     message said it removed one. `LocalFontProvider` with no URL emits the empty
            //     string, which is correct here: the `@font-face` rules arrive inside the
            //     theme CSS above, self-hosted and same-origin from `packages/tokens/fonts/`.
            //
            //     The family is READ FROM THE GENERATED TOKEN CONFIG and never typed -- a
            //     second copy of a token value is the `§6.2` second-source defect. The cast
            //     would turn a missing key into `''` silently, so `PanelFontOriginTest`
            //     asserts the rendered `--font-family` is the approved family EXACTLY.
            ->font(
                (string) config('design-tokens.fonts.latin.family'),
                provider: LocalFontProvider::class,
            )
            // The guard Task 5's login establishes. Named, not defaulted — see the docblock.
            ->authGuard('web')
            // EXPLICIT, not discovered. `discoverResources(in: ..., for: ...)` takes a path
            // and a namespace as strings, so the set of panel resources would be whatever
            // happens to be in a directory — and the named invariant's test drives "every
            // panel resource", which has to be an enumerable set for that to mean anything.
            // A resource added here shows up in a diff and in that test's route table.
            ->pages([
                PlatformDashboardPage::class,
            ])
            ->resources([
                RestaurantResource::class,
                PlatformAuditResource::class,
            ])
            // NO WIDGETS. The generator adds AccountWidget and FilamentInfoWidget; the
            // second renders the installed Filament version to anyone who can see the
            // dashboard, which is version disclosure for no benefit, and the first is
            // redundant beside the user menu.
            ->widgets([])
            ->middleware([
                // FIRST IN THE LIST, before StartSession, which is the whole point
                // (security H-2). Every other surface in this application carries a
                // bucket -- /v1, the auth group, even the unknown-path fallback -- and
                // these four routes carried none. StartSession persists a session to
                // carry a CSRF token BEFORE Authenticate refuses, so each anonymous 401
                // minted one Redis key with a 7200 s TTL: measured 25 keys for 25
                // requests, no credential needed. Redis is the session, cache AND queue
                // store (§1), so that loop fills the store the customer pager path
                // depends on. A limiter after StartSession would meter the requests and
                // still mint the keys.
                RateLimit::class.':panel',
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                // ⚠ AuthenticateSession IS DELIBERATELY ABSENT, and that is security L-1
                // rather than an omission. The generator includes it; measured at
                // `263bb16`, it cannot fire in this application: it compares the stored
                // password hash against `getAuthPassword()`, and Task 5's `R-006`
                // narrowing revoked the app role's `SELECT` on `users.password`, so that
                // method returns NULL for every user this role reads. `Store::has()` is
                // false for a null value, so the hash is simply re-stored on every
                // request and the comparison can never differ — a password change would
                // never invalidate an open panel session.
                //
                // A control that is present and cannot fire is worse than one that is
                // absent, because it reads as protection: that is the dead-assertion
                // class `§4a` records four instances of. Restoring the CAPABILITY needs
                // the hash reached through `authenticate_user()`, which is `R-120`'s,
                // owned by Task 12 beside `R-104`'s re-authentication work.
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
