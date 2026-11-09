<?php

declare(strict_types=1);

namespace App\Features\Landing\Panel;

use App\Features\Landing\Panel\Pages\DomainsPage;
use App\Features\Landing\Panel\Pages\LandingBuilderPage;
use App\Features\Landing\Panel\Pages\MediaLibraryPage;
use App\Features\Landing\Panel\Pages\PreviewController;
use App\Features\Landing\Panel\Pages\ThemePage;
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
 * and `StudioAccessibilityTest` states honestly what can be asserted from a
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
            ->viteTheme('resources/css/filament/studio/theme.css')
            // ⚠ LIGHT IS THE DEFAULT, STATED RATHER THAN INHERITED -- `QRPAGER-39`, Muhammad's
            // ruling of 2026-11-08: *"Rail is ink on paper."* Filament's own default is
            // `ThemeMode::System`, so until this line the panel followed the operating
            // system and a restaurant owner on a dark laptop met a dark product -- the one
            // the same ticket re-pointed off `ready-field` precisely because it was the
            // colour of the order-ready state. Dark stays AVAILABLE: this is the default a
            // person has not chosen yet, not a removal, and `§12`'s floors are asserted for
            // both themes by `contrast.test.ts` whichever one is showing.
            ->defaultThemeMode(ThemeMode::Light)
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
            ->authGuard('web')
            // EXPLICIT, never discovered. `discoverPages()` takes a directory, so the
            // set of pages would be whatever happens to be in it -- and
            // StudioPanelInvariantTest drives "every page in this panel" as all five
            // Task 5 principals, which needs an enumerable set to mean anything.
            ->pages([
                LandingBuilderPage::class,
                MediaLibraryPage::class,
                ThemePage::class,
                DomainsPage::class,
            ])
            /*
             * ⚠ THE SIDEBAR IS OFF AND THE TABS ARE THE NAVIGATION — `QRPAGER-40`.
             *
             * Muhammad's ticket: *"Tabs replace the sidebar."* The four pages above are
             * still registered — they must be, or their routes do not exist and
             * `StudioPanelInvariantTest` has no enumerable set to drive — but Filament's
             * own sidebar is not rendered, and `studio/partials/tabs.blade.php` is
             * included by each page's view instead.
             *
             * The tabs are LINKS with `aria-current="page"`, not `role="tab"`: each is a
             * separate page with its own URL and its own Livewire state, so they are
             * navigation, and `role="tab"` would promise a screen-reader user that the
             * panels are in this document and that arrow keys move between them. Neither
             * is true, and a promise the page cannot keep is worse than the plainer
             * control.
             */
            ->sidebarCollapsibleOnDesktop(false)
            ->navigation(false)
            /*
             * ⚠ `data-theme` MIRRORS FILAMENT'S OWN MODE, OR EVERY TOKEN IN THIS PANEL IS
             * STUCK ON LIGHT — `QRPAGER-40`, and it was found by looking at the dark
             * screenshots rather than by reading anything.
             *
             * Two theming systems meet in this panel and never touched before this ticket:
             * Filament flips on `html.dark`, and `packages/tokens/generated/tokens.css`
             * flips on `[data-theme='dark']` (or `prefers-color-scheme` when no attribute
             * decides). The compiled stylesheet carries BOTH -- measured, `--color-paper`
             * appears as `#fcfcfa` and `#191c22` in the same file -- but nothing ever set
             * the attribute here, so every `var(--color-…)` in panel Blade resolved to the
             * LIGHT value inside a dark panel. The studio's own footer bar rendered white
             * on a dark page.
             *
             * This is the one line that joins them, and it is deliberately a MIRROR rather
             * than a second source of truth: Filament decides the mode, this copies its
             * decision onto the attribute the token layer reads. The observer keeps it true
             * when the user flips the mode without a reload.
             *
             * It is inline script, which the panel policy already permits (`R-242`'s named
             * allowlist) and which no other mechanism can replace: the class is written by
             * Filament's own boot script before any stylesheet can respond to it.
             */
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => <<<'HTML'
                    <script>
                        (function () {
                            var root = document.documentElement
                            var sync = function () {
                                root.dataset.theme = root.classList.contains('dark') ? 'dark' : 'light'
                            }
                            sync()
                            new MutationObserver(sync).observe(root, {
                                attributes: true,
                                attributeFilter: ['class'],
                            })
                        })()
                    </script>
                    HTML,
            )
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
            // ⚠ authenticatedRoutes() AND NOT routes(), WHICH IS WHERE THIS STARTED.
            //
            // Filament registers `$panel->getRoutes()` OUTSIDE the group that applies
            // `getAuthMiddleware()` (vendor/filament/filament/routes/web.php), so a
            // preview registered with `routes()` got the middleware stack above and
            // NOT `Authenticate` -- while this docblock claimed it got both. Verified
            // with `php artisan route:list --path=studio`: the page routes listed
            // Filament\Http\Middleware\Authenticate and the two preview routes did not.
            //
            // There was no way in: PreviewController calls TenantPanelAccess::tenantId(),
            // which aborts 403, and four cases drive that. But every other panel route
            // was double-layered and this one was single-layered, the written record
            // said otherwise, and no test could tell a 403 from the controller apart
            // from a 401 from the middleware -- so a refactor that moved the guard would
            // have opened the draft with the suite still green. Found by the §11
            // security review, 2026-10-28; ReviewFixesTest now reads the
            // middleware list off the router and asserts Authenticate is on every /studio
            // route. (This comment named StudioPanelInvariantTest, which does not contain
            // that assertion -- a citation to a control that is not where it says, which
            // is the shape R-136 was raised for, in the same pass that raised it.)
            //
            // A second consequence closed with it: with Authenticate absent,
            // $request->user() resolved through config('auth.defaults.guard') rather
            // than this panel's declared authGuard('web'). They coincide today.
            ->authenticatedRoutes(function (): void {
                // Named `preview`, not `studio.preview`: Filament prefixes a panel's
                // routes with `filament.{panel id}.`, so the full names are
                // `filament.studio.preview` and `filament.studio.preview.asset`.
                Route::get('preview', PreviewController::class)->name('preview');
                Route::get('preview/asset/{asset}', [PreviewController::class, 'asset'])
                    ->name('preview.asset');
            });
    }
}
