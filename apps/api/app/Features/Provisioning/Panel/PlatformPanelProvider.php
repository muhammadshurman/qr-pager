<?php

declare(strict_types=1);

namespace App\Features\Provisioning\Panel;

use App\Features\Provisioning\Panel\Pages\PlatformDashboardPage;
use App\Features\Provisioning\Panel\Resources\PlatformAuditResource;
use App\Features\Provisioning\Panel\Resources\RestaurantResource;
use App\Platform\Filament\LocalAvatarProvider;
use App\Platform\Http\Middleware\RateLimit;
use App\Platform\Panel\ApprovedPalette;
use Filament\Enums\ThemeMode;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Vite;
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
            // ⚠ LIGHT IS THE DEFAULT, STATED RATHER THAN INHERITED -- `QRPAGER-39`, Muhammad's
            // ruling of 2026-11-08: *"Rail is ink on paper."* Filament's own default is
            // `ThemeMode::System`, so until this line the panel followed the operating
            // system and a restaurant owner on a dark laptop met a dark product -- the one
            // the same ticket re-pointed off `ready-field` precisely because it was the
            // colour of the order-ready state.
            //
            // ⚠⚠ THE SENTENCE THAT STOOD HERE IS SUPERSEDED, AND IT IS STRUCK RATHER THAN
            // DELETED. It read *"Dark stays AVAILABLE: this is the default a person has not
            // chosen yet, not a removal"* -- true on 2026-11-08 and false since the ruling in
            // the docblock below, which REMOVES the dark surface on both panels. The `§11` QA
            // round of 2026-11-10 found the two comments adjacent and contradicting (L-2), in a
            // file where the docblock IS the record, so the correction is recorded in place.
            /*
             * ================================================================================
             * ⚠ LIGHT ONLY. THE DARK SURFACE IS REMOVED, NOT MERELY DEFAULTED AWAY FROM.
             * ================================================================================
             *
             * Muhammad's ruling of 2026-11-10, `§1`, for BOTH panels. `defaultThemeMode` alone
             * was never enough and the measurement is why: with `localStorage['filament:theme:mode']`
             * set to `dark` and the OS preferring LIGHT, the panel rendered `html.class="fi dark"`,
             * `data-theme="dark"`, a body painted from Filament's dark grey, and the token layer
             * flipped -- `ink` and `paper` exchanged values, which is what the dark block in
             * `packages/tokens/generated/tokens.css` does. A person who pressed the switcher once
             * had a dark panel for ever, whatever the default said. (The figures are reproduced by
             * the browser case rather than quoted here: `scan-panel.mjs` reads this file and has no
             * parser, so a colour in PROSE is a finding exactly like one in a value -- `R-260`,
             * which this very docblock hit on its first run.)
             *
             * ⚠ THE REASON IS NOT THE `localStorage` COLLISION BETWEEN THE TWO PANELS -- that is a
             * side effect. In his words: *"/platform's dark mode today is not a feature anyone
             * chose -- it is Filament's unsuppressed default. There is no designed dark palette
             * anywhere in this product: Rail's ready-field is a FIELD, not a mode. So an admin who
             * hits the switcher gets Filament's own dark colours, which is precisely the 'library
             * default look' `§12` rejects, on a surface no token decision and no contrast proof
             * covers. We are not removing a capability; we are closing a state we never specified."*
             *
             * ⚠ AND `->themeSwitcher(false)` IS NOT CALLED, BY MEASUREMENT RATHER THAN BY
             * OVERSIGHT. `user-menu.blade.php:122` renders the control under
             * `hasDarkMode() && (! hasDarkModeForced()) && hasThemeSwitcher()`, so `darkMode(false)`
             * has already removed it. A second flag that changes nothing would read as protection
             * which is not there.
             *
             * ⚠ WHAT `darkMode(false)` ALSO DOES, AND IT IS THE HALF A CUSTOM FIX WOULD HAVE
             * DUPLICATED: `base.blade.php:102-105` emits
             * `localStorage.setItem('filament:theme:mode', 'light')` on every page load, so the
             * stored preference is cleared by the framework rather than by code of ours.
             * VERIFIED IN THE SERVED DOCUMENT, not read from vendor source: signed in as the
             * seeded platform administrator, `/platform` is 42,113 bytes, carries
             * `fi-body fi-panel-platform`, and contains that `setItem` exactly once -- beside
             * the static `data-theme` pin below, which is this panel's own and is the only
             * other script in the head that touches the theme.
             *
             * ⚠ THE FIGURES HERE WERE THE STUDIO'S UNTIL THE `§11` QA ROUND OF 2026-11-10
             * (L-1): the paragraph cited *"54,343 bytes, carries `fi-panel-studio`"* -- a
             * measurement of the OTHER panel, a few lines above this docblock's own warning
             * that a measurement of the wrong document is not evidence. It falsified itself,
             * which is the most useful kind of error to keep.
             *
             * ⚠ AN EARLIER VERSION OF THIS COMMENT SAID THE OPPOSITE, AND THE ERROR IS WORTH
             * KEEPING: the check that "found" it absent had no session, so it measured the
             * 5 KB sign-in redirect rather than the panel. A measurement of the wrong document
             * is not evidence, and `is the panel: true` is the assertion that would have
             * caught it -- which is why the browser cases now assert their premise first.
             *
             * `defaultThemeMode` stays below because it is what the switcher-less panel reads
             * as its mode, and because removing it would make this depend on Filament's default.
             */
            ->darkMode(false)
            ->defaultThemeMode(ThemeMode::Light)
            /*
             * ================================================================================
             * ⚠ `data-theme` IS PINNED TO LIGHT, OR THE TOKEN LAYER GOES DARK ON ITS OWN
             * ================================================================================
             *
             * `->darkMode(false)` above stops FILAMENT adding `.dark`. It does nothing at all
             * to the token layer, which decides independently:
             * `packages/tokens/generated/tokens.css:153` is
             * `@media (prefers-color-scheme: dark) { :root:not([data-theme='light']) { … } }`.
             *
             * So on a machine that prefers dark, this panel resolved the DARK values while
             * having no dark mode -- MEASURED here before the fix: `--color-paper` came back
             * as the dark paper on `/platform`, against a ground painted by Filament's own
             * grey. The studio never showed it because `QRPAGER-40` gave that panel a mirror
             * script for an unrelated reason, and the mirror happens to pin the attribute.
             *
             * A static pin rather than a mirror: with `darkMode(false)` there is no `.dark`
             * to mirror, so an observer would watch for a class that cannot appear. If a dark
             * surface is ever specified for this panel, this is the line that must change
             * with it -- which is the point of it being one line and not a script.
             */
            /*
             * ⚠ AND IT CARRIES A NONCE WHEN ONE IS CONFIGURED, WHICH IS NOT COSMETIC.
             * `§11` L-4, 2026-11-10: Filament's own adjacent script is
             * `<script @if (filled($cspNonce)) nonce="{{ $cspNonce }}" @endif>`
             * (`base.blade.php:103`, fed by `Vite::cspNonce()`), and this hook emitted a bare
             * `<script>`. It executes today only because the panel policy carries
             * `script-src 'self' 'unsafe-inline' 'unsafe-eval'` (`R-242`) -- and a browser
             * IGNORES `'unsafe-inline'` the moment a nonce appears in the policy. So adopting
             * `Vite::cspNonce()` later would have silently stopped this script running, and
             * the symptom would be `/platform` back on the dark palette on an OS-dark machine
             * with nothing failing. MEASURED on the served document: 12 scripts, 0 of them
             * carrying a nonce -- so none is configured in this application today and this
             * branch is a no-op until one is. It is here so that adopting one cannot un-pin
             * the panel.
             */
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                function (): string {
                    $nonce = Vite::cspNonce();
                    $attribute = $nonce === null || $nonce === '' ? '' : ' nonce="'.e($nonce).'"';

                    return '<script'.$attribute.">document.documentElement.dataset.theme = 'light'</script>";
                },
            )
            // ⚠ WITHOUT THIS, EVERY PAGE OF THIS PANEL RENDERED `<title>… - Laravel</title>`.
            // `Panel::getBrandName()` falls back to `config('app.name')`, and `.env.example`
            // ships `APP_NAME=Laravel` — so `§12`'s *"a reviewer cannot name the UI framework
            // from a screenshot"* was false in the one place a screenshot always shows: the
            // browser tab. Measured 2026-11-01 on `/studio/media`: `Media - Laravel`.
            //
            // `app.brand` and NOT `app.name`: that key also feeds the session cookie, the
            // cache prefix and the Redis prefix through `Str::slug`, and the TOTP issuer
            // label. See config/app.php for the measurement and the finding.
            ->brandName((string) config('app.brand'))
            // ================================================================================
            // SR-4's RULING: ELEVEN SHADES PER FAMILY, DERIVED FROM THE APPROVED TOKENS
            // ================================================================================
            //
            // ⚠ NOT `->colors(['primary' => <the approved brand>])`, AND THE ONE-LINER IS
            // WORSE THAN IT
            // LOOKS. `ColorManager::getColors()` passes a STRING through
            // `Color::generatePalette()`, which keeps only the HUE -- lightness and chroma are
            // Filament's own constants at every shade. Measured: the approved value appears at
            // NO shade; `ink` and `brand` are 1.4 degrees of hue apart and
            // produce indistinguishable ladders; and driven through Filament's own resolver it
            // put the light background on shade 400 and gave a dark pair of **3.43:1**, which
            // fails WCAG AA for text and is a gate clause.
            //
            // The ladders are GENERATED into `design-tokens.php` from the approved tokens by
            // the mechanism 8a's dark theme was approved under -- one formula, two caps, the
            // approved value held exactly at its family's anchor shade, every other entry a mix
            // of two approved tokens. The anchors carry the ruling: `danger` is built from
            // `alert-ink` because `FILL_PAIRS` names that as the danger fill with `surface` as
            // its label, and `warning` sits at shade 400 because `alert-fill`'s own approval
            // bounds it to text at >= 24px. `panel-palette.test.mjs` drives Filament's resolver
            // over every family and requires >= 4.5 in BOTH modes, which is the assertion the
            // ruling asked for; `PanelPaletteTest` asserts the rendered panel emits them.
            //
            // READ FROM THE GENERATED CONFIG, never typed: a second copy of 66 colour values
            // is the §6.2 second-source defect at the worst possible scale.
            // ⚠ A CLOSURE, NOT A CALL. `panel()` runs on EVERY boot, so throwing here
            // stopped `php artisan --version` itself when the generated config was
            // absent -- measured -- which coupled the queue worker, the scheduler and
            // every console command to a CSS artefact. Filament evaluates a closure when
            // it first resolves colours, i.e. on a panel render, so a panel request
            // still fails closed and loudly and nothing else does. ApprovedPalette's
            // docblock carries the measurement and why fail-closed is kept.
            ->colors(ApprovedPalette::families(...))
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
            //     would turn a missing key into `''` silently, so `PanelThemeTest`
            //     asserts the rendered `--font-family` is the approved family EXACTLY.
            ->font(
                (string) config('design-tokens.fonts.latin.family'),
                provider: LocalFontProvider::class,
            )
            // ⚠ THE AVATAR, AND IT IS THE SAME DEFECT AS THE FONT ABOVE THROUGH A SECOND
            //     FILAMENT DEFAULT NOBODY READ — Task 12. `HasAvatars::$defaultAvatarProvider`
            //     is `UiAvatarsProvider`, which emits
            //     `https://ui-avatars.com/api/?name=<THE SIGNED-IN PERSON'S NAME>&…` as the
            //     avatar's `src`. Captured from a rendered `/platform` on 2026-11-07:
            //     `…?name=A&format=svg&color=FFFFFF&background=%231f3129`.
            //
            //     So every page view of this panel made an authenticated administrator's
            //     browser hand a staff member's NAME to a third party, with the referrer, the
            //     IP and the timing. The comment above spends ten lines on not re-adding a
            //     third-party FONT request to "this very surface" — and a third-party IMAGE
            //     request carrying a name was already there, because it was a default and the
            //     font was a call.
            //
            //     It was also the ONLY remote origin in either panel's markup, measured across
            //     all seven panel routes, which is what lets `ContentSecurityPolicy` give the
            //     panels `default-src 'self'` with no third-party host anywhere in the policy.
            ->defaultAvatarProvider(LocalAvatarProvider::class)
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
