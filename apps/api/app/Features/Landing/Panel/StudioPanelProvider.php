<?php

declare(strict_types=1);

namespace App\Features\Landing\Panel;

use App\Domain\Tenancy\TenantLanguage;
use App\Features\Landing\LanguageNotChanged;
use App\Features\Landing\Panel\Pages\DomainsPage;
use App\Features\Landing\Panel\Pages\LandingBuilderPage;
use App\Features\Landing\Panel\Pages\MediaLibraryPage;
use App\Features\Landing\Panel\Pages\PreviewController;
use App\Features\Landing\Panel\Pages\ThemePage;
use App\Features\Landing\PanelLanguage;
use App\Platform\Filament\LocalAvatarProvider;
use App\Platform\Http\Middleware\RateLimit;
use App\Platform\Http\TenantPanelAccess;
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
use Illuminate\Http\Request;
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
             * VERIFIED IN THE SERVED DOCUMENT, not read from vendor source: the authenticated
             * panel is 54,343 bytes, carries `fi-panel-studio`, and contains that `setItem`
             * exactly once, in the head, immediately before this panel's own mirror script.
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
             * is declared twice with two different values in the same build, one per mode:
             *
             *     grep -ho -- '--color-paper:[^;]*' public/build/assets/*.css | sort -u
             *
             * -- but nothing ever set the attribute here, so every `var(--color-…)` in panel
             * Blade resolved to the LIGHT value inside a dark panel. The studio's own footer
             * bar rendered white on a dark page.
             *
             * ⚠ THE TWO VALUES ARE REPRODUCED BY THAT COMMAND AND NOT QUOTED HERE, and that
             * is not squeamishness: `scan-panel.mjs` reads this file and has no parser, so a
             * hex in a comment is a finding exactly like a hex in a value. Its only exemption
             * is the span inside four copy methods, written narrow on purpose -- *"a blanket
             * per-file or per-line exemption would have covered both"*. Widening it so a
             * docblock could quote a measurement would trade the rule for a sentence. CI
             * caught this at `7e48d0d`, after the gate figures were taken at `c55f786`.
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
            /*
             * THE FOOTER'S SAVE STATE — `QRPAGER-48` item 2.
             *
             * ⚠ IT SHARES THE PLATE'S READ RATHER THAN MAKING ITS OWN. Two chrome regions want
             * the same fact, and a second `state()` call would have turned the corrected figure
             * of TWO scoped reads per render into three. `StudioChromeState` memoises per
             * request, so the chrome costs one read however many regions ask.
             */
            ->renderHook(
                PanelsRenderHook::FOOTER,
                function (): string {
                    if (! TenantPanelAccess::allows()) {
                        return '';
                    }

                    return view('studio.partials.footer-bar', [
                        'footer' => StudioChromeState::of(TenantPanelAccess::tenantId()),
                    ])->render();
                },
            )
            /*
             * THE PUBLISH STATE, ON EVERY TAB — `QRPAGER-48` item 2.
             *
             * ⚠ IT IS THE FRAME, NOT THE PAGE. An owner editing colours needs to know their
             * customers are still seeing last week's page just as much as one editing blocks
             * does, so it renders above every studio page rather than on the builder alone.
             *
             * ⚠⚠ AND IT COSTS A SECOND SCOPED READ PER RENDER. `§6` question 5 claimed this
             * would reuse the form's read; the build falsified that — `state()` and `draft()`
             * each open their own `withTenant` — and `PLAN.md` carries the corrected figure.
             * Accepted rather than optimised: memoising `row()` per request would change
             * shared code four pages and the publish path depend on, to save one read on a
             * page an owner opens deliberately.
             */
            ->renderHook(
                PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE,
                function (): string {
                    if (! TenantPanelAccess::allows()) {
                        return '';
                    }

                    return view('studio.partials.status-plate', [
                        'plate' => StudioChromeState::of(TenantPanelAccess::tenantId()),
                    ])->render();
                },
            )
            /*
             * ⚠ THE TOPBAR'S LEFT HALF — `QRPAGER-48` item 2: the mark, the RESTAURANT'S name
             * and a chip saying which screen this is.
             *
             * `§12` forbids an unexplained identifier, and before this the topbar carried the
             * product's name and nothing about whose page was being edited. On a shared
             * device the restaurant is the one fact a person glances up to confirm.
             */
            ->renderHook(
                PanelsRenderHook::TOPBAR_START,
                fn (): string => view('studio.partials.topbar-identity', [
                    'restaurant' => TenantPanelAccess::membership()->tenantName ?? '',
                ])->render(),
            )
            /*
             * THE STUDIO'S OWN LANGUAGE, BESIDE THE PERSON IT BELONGS TO — `QRPAGER-48`.
             *
             * ⚠ IT SITS IN THE TOPBAR AND NOT ON THE PAGE TAB, which is the whole point: the
             * page tab's control sets what a restaurant's CUSTOMERS read, and this sets what
             * the person signed in reads. One column answered both until 2026-11-13; they are
             * now `tenants.language` and `users.panel_language`, and each control names its
             * own audience so an owner meeting them a minute apart cannot confuse them.
             */
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): string => TenantPanelAccess::allows()
                    ? view('studio.partials.panel-language')->render()
                    : '',
            )
            /*
             * ⚠⚠ THE SIGNED-IN PERSON'S FIRST NAME, READABLE WITHOUT A CLICK.
             *
             * Muhammad's ruling of 2026-11-13, which treats the artboard itself as the defect:
             * *"The artboard's bare initial is itself the defect — `§12` forbids an unexplained
             * identifier, and a single letter is exactly that."* So the name is TEXT beside the
             * avatar, and the avatar alone is what remains on a narrow screen — the collapse is
             * CSS, because the name is not information the small screen needs less, it is
             * information the small screen has no room for.
             */
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                function (): string {
                    $name = trim((string) (auth()->user()?->getAttribute('name') ?? ''));

                    if ($name === '') {
                        return '';
                    }

                    $first = explode(' ', $name)[0];

                    return '<span class="studio-topbar__person" data-topbar-person="true">'
                        .e($first)
                        .'</span>';
                },
            )
            /*
             * AND THE MENU ITSELF CARRIES THE WHOLE NAME AND THE ADDRESS — *"the full name, the
             * email, and «تسجيل الخروج». Nothing else."* The address is what distinguishes two
             * people with one first name, which is the case the abbreviation above cannot serve.
             */
            ->renderHook(
                PanelsRenderHook::USER_MENU_PROFILE_BEFORE,
                function (): string {
                    $user = auth()->user();
                    $name = trim((string) ($user?->getAttribute('name') ?? ''));
                    $email = trim((string) ($user?->getAttribute('email') ?? ''));

                    if ($name === '' && $email === '') {
                        return '';
                    }

                    /*
                     * ⚠ `studio.chrome.signed_in_as` IS THE GROUP'S ACCESSIBLE NAME, AND IT
                     * WAS IN BOTH CATALOGUES WITH NO CALL SITE AT ALL (`§11` QA L-1).
                     *
                     * It is not rendered as visible text, and that is the ticket's own
                     * instruction rather than an oversight: item 2 says the menu shows *"the
                     * full name, the email, and «تسجيل الخروج». Nothing else."* So the
                     * sentence the key exists for becomes what the block is CALLED rather
                     * than another line in it -- which is also the thing the block was
                     * missing, two bare spans having no accessible description of their own.
                     *
                     * `role="group"` because a bare `<div>` takes no accessible name: an
                     * `aria-label` on a generic element is dropped by assistive technology,
                     * so the attribute without the role would have been the same defect in a
                     * new shape -- a key that is present and does nothing.
                     */
                    return '<div class="studio-usermenu__identity" data-usermenu-identity="true"'
                        .' role="group" aria-label="'.e(__('studio.chrome.signed_in_as', ['name' => $name !== '' ? $name : $email])).'">'
                        .'<span class="studio-usermenu__name">'.e($name).'</span>'
                        .'<span class="studio-usermenu__email">'.e($email).'</span>'
                        .'</div>';
                },
            )
            /*
             * ⚠⚠ THE WAY BACK TO THE ORDERS BOARD — `QRPAGER-48` item 3, AND IT REUSES THE
             * KEY THE SIGNED-OUT REDIRECT ALREADY USES RATHER THAN ADDING ONE.
             *
             * `surfaces.staff_signin_url` is the staff app's ENTRY POINT and serves BOTH uses:
             * `PanelSignInRedirect` sends a signed-OUT visitor there, and this sends a
             * signed-IN one. **The name predates the second use** and is deliberately not
             * changed, because other code depends on it — `PLAN.md §4a` carries that sentence
             * so a reader meeting it here finds an explanation rather than a puzzle.
             *
             * ⚠ **AND NO SECOND VALIDATOR IS WRITTEN HERE, DELIBERATELY.** The value is already
             * validated at boot by `BootChecks::staffSignInUrlIsSound()` — absolute, https, a
             * real host, and refused outright if it is itself a panel path on this host (the
             * redirect loop). That posture is **fail-CLOSED** and is the opposite of the staff
             * app's `studioUrl()`, which falls back. `§4a` records why those two must NOT be
             * aligned: this one, wrong, locks everybody out; that one, wrong, points a button
             * somewhere odd.
             */
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                function (): string {
                    $target = config('surfaces.staff_signin_url');

                    if (! is_string($target) || trim($target) === '') {
                        // Nothing to link to. Rendering no control is right: a dead link in
                        // the chrome is worse than an absent one, and in production this
                        // state cannot be reached at all -- the boot check refuses it.
                        return '';
                    }

                    /*
                     * ⚠ AND THE SCHEME IS CHECKED HERE AFTER ALL (`§11` SECURITY L-3). The
                     * paragraph above said no second validator was needed because
                     * `BootChecks::staffSignInUrlIsSound()` covers it — and that method's
                     * FIRST statement is `if ($this->isDevelopment()) { return []; }`, with
                     * `DEVELOPMENT_ENVIRONMENTS = ['local', 'testing']`. So it is skipped in
                     * exactly the two environments where this value is set by hand and by
                     * `tools/browsers/bin/e2e`. The claim was true of production and the
                     * comment stated it without that qualification.
                     *
                     * `e()` is not the answer to this one: it prevents attribute breakout —
                     * `StudioBackToStaffTest`'s third case proves that, correctly — and
                     * leaves the SCHEME untouched, so `javascript:alert(1)` survives it
                     * unchanged and becomes a live anchor in the topbar.
                     *
                     * Fail-closed, like the boot check it backs up: an unusable value renders
                     * no control rather than a control that does something else.
                     */
                    if (! str_starts_with($target, 'https://') && ! str_starts_with($target, 'http://')) {
                        return '';
                    }

                    return '<a class="studio-topbar__back" href="'.e($target).'" data-back-to-staff="true">'
                        .e(__('studio.page.back_to_staff'))
                        .'</a>';
                },
            )
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
            /*
             * ⚠ THE COLOUR PICKER'S ALPINE COMPONENT — `QRPAGER-41` part B item 5.
             *
             * Registered on `alpine:init`, which is the one moment Alpine guarantees is
             * before any `x-data` on the page is evaluated. A plain `<script>` that called
             * `Alpine.data` directly would race Filament's own bundle.
             *
             * ⚠ THE FALLBACK IS READ FROM THE CASCADE, NEVER WRITTEN HERE. `ColourField`
             * sends a token NAME and this resolves it with `getComputedStyle`, so the colour
             * the picker opens on is whatever the token layer says in the mode that is
             * actually on screen. That is also why no hex appears in this file -- the panel
             * scanner reads it, and `R-260` is what happens when one does.
             *
             * ⚠ AND THE SHORTHAND IS EXPANDED, which is not a nicety: `--color-surface`
             * resolves to a THREE-DIGIT hex, `<input type="color">` accepts only the
             * six-digit form, and an unexpanded value is silently ignored by the element --
             * the picker would open on black and nothing would say why. Measured while
             * probing this panel's palette, where the same shorthand made a token look
             * off-palette.
             */
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => <<<'HTML'
                    <script>
                        document.addEventListener('alpine:init', function () {
                            /*
                             * ⚠ WCAG's OWN FORMULA, AND IT IS HERE BECAUSE NOTHING ELSE ON THIS
                             * PAGE CAN DO IT — `QRPAGER-47` Part B item 3. The product's
                             * contrast figures are computed at BUILD time by
                             * `packages/tokens/src/source.ts` and published as a generated
                             * table; a colour the owner is choosing right now is in neither.
                             *
                             * Two implementations of one formula is a duplication worth
                             * naming: this one judges a LIVE value in the browser, that one
                             * judges the product's own palette at build time. They are tested
                             * against each other rather than trusted —
                             * `browser/studio.spec.ts` evaluates this function in a served
                             * panel against the pairs `contrast.json` publishes, floored to
                             * that file's own stated convention.
                             *
                             * 0.03928 is WCAG 2.x's threshold as written in the specification,
                             * not the mathematically exact 0.04045: the point is to agree with
                             * the standard every other tool implements.
                             */
                            function studioChannel(value) {
                                value = value / 255

                                return value <= 0.03928
                                    ? value / 12.92
                                    : Math.pow((value + 0.055) / 1.055, 2.4)
                            }

                            function studioLuminance(hex) {
                                var match = /^#([0-9a-fA-F]{6})$/.exec(hex || '')

                                if (match === null) return null

                                var n = parseInt(match[1], 16)

                                return 0.2126 * studioChannel((n >> 16) & 255)
                                    + 0.7152 * studioChannel((n >> 8) & 255)
                                    + 0.0722 * studioChannel(n & 255)
                            }

                            function studioContrast(a, b) {
                                var la = studioLuminance(a)
                                var lb = studioLuminance(b)

                                if (la === null || lb === null) return null

                                return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05)
                            }

                            /*
                             * ⚠ BOTH ARE ON `window` DELIBERATELY, AND THE REASON IS NAMED
                             * RATHER THAN LEFT AS A SIDE EFFECT — `§11` security round, Low 4.
                             * `studioContrast` is the duplication control's subject:
                             * `browser/studio.spec.ts` evaluates it against every pair
                             * `packages/tokens/generated/contrast.json` publishes, which is the
                             * only thing holding this implementation and the build-time one
                             * together. `studioLuminance` is what the advice's DIRECTION is
                             * computed from, and the same case can hold it to the same table.
                             *
                             * Both are pure functions of two strings with no DOM and no state,
                             * so neither grants a capability an attacker on this page does not
                             * already have — the panel's CSP carries `unsafe-eval` either way
                             * (`R-242`). The alternative was for the spec to drive the Alpine
                             * component's `judge()` through the UI, which would test the
                             * wiring and NOT the formula, and the formula is the thing that can
                             * silently disagree with the generated table.
                             */
                            window.studioContrast = studioContrast
                            window.studioLuminance = studioLuminance

                            window.Alpine.data('studioColour', function (config) {
                                return {
                                    chosen: this.$wire.$entangle(config.statePath),
                                    picked: '',
                                    fallback: '',
                                    advice: '',
                                    init: function () {
                                        var raw = getComputedStyle(this.$el)
                                            .getPropertyValue(config.fallbackToken)
                                            .trim()
                                            .toLowerCase()

                                        if (/^#[0-9a-f]{3}$/.test(raw)) {
                                            raw = '#' + raw.slice(1).split('').map(function (c) {
                                                return c + c
                                            }).join('')
                                        }

                                        // An unresolvable token is a broken token layer, and
                                        // this file does not get to choose a colour instead:
                                        // the fallback stays empty and the element shows its
                                        // own default. Naming one here would be a design
                                        // decision made outside the token source.
                                        this.fallback = /^#[0-9a-f]{6}$/.test(raw) ? raw : ''
                                        this.picked = this.chosen || this.fallback

                                        /*
                                         * ⚠ `$nextTick` AND NOT HERE: the other half of the
                                         * pair is a SIBLING COMPONENT, and on the first pass
                                         * it may not have resolved its own fallback yet — its
                                         * input would still be empty and the ratio would be
                                         * null. Alpine initialises every component before the
                                         * next tick, so by then both inputs carry a value.
                                         */
                                        var self = this

                                        this.$nextTick(function () {
                                            self.judge()
                                        })

                                        this.$watch('picked', function () {
                                            self.judge()
                                        })
                                    },
                                    /*
                                     * Is this colour readable against the one it is PAIRED
                                     * with? `ThemeTokens::CONTRAST_PAIRS` decides which that
                                     * is, which is why a button's label is judged on the
                                     * button and not against the page.
                                     *
                                     * ⚠ THE PARTNER IS READ FROM ITS `data-token` INPUT, whose
                                     * value is the EFFECTIVE colour — the owner's override, or
                                     * the default the picker opened on. So the advice is about
                                     * what a visitor would see, not about what has been
                                     * overridden.
                                     *
                                     * ⚠ AND SILENCE IS THE ANSWER WHEN THERE IS NOTHING TO
                                     * SAY: no pair, no partner on the page, or a value that is
                                     * not a six-digit hex all leave the message empty. A
                                     * warning drawn from a value nobody can see would be worse
                                     * than none.
                                     */
                                    judge: function () {
                                        if (! config.pairedWith) return

                                        var partner = document.querySelector(
                                            '[data-token="' + config.pairedWith + '"]'
                                        )

                                        var against = partner === null ? '' : partner.value
                                        var ratio = window.studioContrast(this.picked, against)

                                        if (ratio === null || ratio >= config.floor) {
                                            this.advice = ''

                                            return
                                        }

                                        /*
                                         * ⚠ THE DIRECTION IS COMPUTED, NOT GUESSED. A colour
                                         * becomes more readable against its pair by moving
                                         * AWAY from it, so the lighter of the two must get
                                         * lighter and the darker darker. One fixed *"darken
                                         * it"* advised the opposite for every light surface,
                                         * which the `§11` QA round measured at 1.75:1.
                                         */
                                        var mine = window.studioLuminance(this.picked)
                                        var theirs = window.studioLuminance(against)

                                        this.advice = mine !== null && theirs !== null && mine >= theirs
                                            ? config.adviceLighten
                                            : config.adviceDarken
                                    },
                                    clear: function () {
                                        this.chosen = ''
                                        this.picked = this.fallback
                                    },
                                }
                            })
                        })
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
                // ⚠ HERE AND NOT IN `middleware()` ABOVE — `QRPAGER-41`. The locale comes
                // from the signed-in user's membership, so this must run AFTER
                // `Authenticate`; in the stack above it would read null every time and do
                // nothing, silently. `StudioLocale`'s own docblock carries the rest.
                StudioLocale::class,
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
                /*
                 * WHICH LANGUAGE THIS PERSON READS THE STUDIO IN — `QRPAGER-48` item 2.
                 *
                 * ⚠ POST, AND INSIDE `authenticatedRoutes` SO IT CARRIES THE PANEL'S OWN
                 * STACK. It changes stored state, so a GET would be a preference any page
                 * could flip by embedding an image. The write is the signed-in person's OWN
                 * row and nobody else's — the id comes from the guard, never from input.
                 *
                 * ⚠⚠ AND IT WRITES `users.panel_language`, NOT `tenants.language`. Those are
                 * different facts and `PLAN.md §2.4` records why: this is what the PERSON
                 * reads; the restaurant's published page language is a property of the
                 * restaurant and is set on the page tab. Writing the tenant column here is
                 * precisely the defect this ticket's column exists to prevent.
                 */
                Route::post('panel-language', function (Request $request) {
                    $chosen = $request->input('language');
                    $language = is_string($chosen) ? TenantLanguage::tryFrom($chosen) : null;

                    if ($language === null) {
                        // An unrecognised value clears the preference rather than guessing:
                        // "follow the restaurant" is the honest reading of "not a language".
                        $language = null;
                    }

                    $userId = auth()->id();

                    if (is_string($userId)) {
                        try {
                            app(PanelLanguage::class)->set($userId, $language);
                        } catch (LanguageNotChanged) {
                            /*
                             * ⚠ CAUGHT, AND IT ESCAPED AS A 500 (`§11` QA L-2). `set()`
                             * raises this when the UPDATE matched no row — which, as its own
                             * docblock says, can only mean the signed-in person's row was
                             * deleted mid-session. That is a real state and it is not an
                             * error in the request: the next page load will meet the
                             * authentication guard and send them to sign in again, which is
                             * the honest answer. An exception page here would show a stack
                             * trace for a toggle.
                             *
                             * Swallowed deliberately rather than reported: there is nothing
                             * the person can do, nothing to retry, and the redirect below
                             * already re-renders the panel in whatever language the row
                             * resolution now yields.
                             */
                        }
                    }

                    /*
                     * ⚠ A NAMED PANEL ROUTE, NOT `back()` (`§11` QA L-6 and SECURITY L-1).
                     * `back()` resolves through `UrlGenerator::previous()`, which prefers the
                     * `Referer` HEADER over the session and passes it through `to()` — and
                     * `to()` returns an absolute or protocol-relative URL unchanged. Measured
                     * against this repo's own vendor tree:
                     *
                     *     https://evil.example/landing -> https://evil.example/landing
                     *     //evil.example/x             -> //evil.example/x
                     *
                     * So this answered `302 Location: https://evil.example/…` for any request
                     * whose Referer said so. It is not drivable today — `PreventRequestForgery`
                     * is on the route and `config/session.php` sets `same_site` to `lax`, so a
                     * cross-site POST carries neither session nor token — but it becomes live
                     * the moment anyone sets `SESSION_SAME_SITE=none`, and `§1`'s one-origin
                     * discussion makes that a plausible future edit.
                     *
                     * The second half is QA L-6, which the same line fixes: with NO previous
                     * URL in the session, `back()` falls through to `/` and sends the person
                     * off the panel entirely. A language toggle that logs you out of the
                     * studio is the shape `§12` refuses.
                     *
                     * The person still returns to the TAB they were on, because a toggle that
                     * always lands on one page is its own small defect — but the previous URL
                     * is admitted only when it is inside this panel on this host, and
                     * anything else falls back to a route resolved by NAME.
                     */
                    $previous = url()->previous();

                    return redirect()->to(
                        self::withinStudio($previous, $request->getHost())
                            ? $previous
                            : route('filament.studio.pages.theme'),
                    );
                })
                    ->name('panel-language')
                    /*
                     * ⚠ A SECOND LIMITER, FOR THE PREVIEW ROUTES' REASON (`§11` SECURITY
                     * L-5). The panel stack's `RateLimit:panel` runs FIRST, before
                     * `StartSession`, so it can only ever count the IP; route middleware is
                     * appended and sorted after `Authenticate`, which is where a tenant
                     * exists to count. `§4 › Edge & ops` asks for both halves and this route
                     * had only one — on the panel's single non-Livewire WRITE.
                     */
                    ->middleware(RateLimit::class.':studio-panel-language-tenant');

                // Named `preview`, not `studio.preview`: Filament prefixes a panel's
                // routes with `filament.{panel id}.`, so the full names are
                // `filament.studio.preview` and `filament.studio.preview.asset`.
                /*
                 * ⚠ NO ROUTE-LEVEL RATE LIMIT HERE, AND THERE WAS ONE FOR ONE COMMIT.
                 * These two routes are metered on the `studio-preview` bucket — the live
                 * preview is not "a human clicking" — but the SELECTION happens in
                 * `RateLimit::bucketFor()` via `config('ratelimit.route_buckets')`, not
                 * here. Doing it here with `withoutMiddleware()` + `->middleware()` moved
                 * the limiter to the END of the stack, behind `StartSession` and
                 * `Authenticate`, which left an anonymous flood unmetered AND minting a
                 * session key per request: Task 6's security H-2 again, measured by the
                 * `§11` round of 2026-11-11. That class's docblock carries the detail.
                 */
                /*
                 * ⚠ THE SECOND LIMITER, AND ITS LATENESS IS THE POINT. Route middleware is
                 * APPENDED and then sorted, so this runs AFTER `Authenticate` — which is
                 * exactly where a tenant resolves and the panel stack's early limiter
                 * cannot. `§4 › Edge & ops` wants per IP AND per tenant; one instance can
                 * only ever satisfy one of them from one position, which is what `R-143`
                 * records for the page routes and what the `§11` round of 2026-11-11 asked
                 * about here. The early instance counts the IP before a session is minted;
                 * this one counts the tenant once there is one.
                 */
                Route::get('preview', PreviewController::class)
                    ->name('preview')
                    ->middleware(RateLimit::class.':studio-preview-tenant');

                Route::get('preview/asset/{asset}', [PreviewController::class, 'asset'])
                    ->name('preview.asset')
                    ->middleware(RateLimit::class.':studio-preview-asset-tenant');
            });
    }

    /**
     * Is this URL a page of THIS panel, on THIS host?
     *
     * ⚠ THE HOST IS CHECKED BEFORE THE PATH, AND A MISSING HOST IS NOT A PASS BY ITSELF.
     * `parse_url('//evil.example/studio')` yields host `evil.example` with no scheme, which
     * is the protocol-relative case; `parse_url('/\evil.example')` yields NO host and the
     * path `/\evil.example`, which a browser still resolves to another origin because the
     * WHATWG parser normalises `\` to `/`. So a backslash anywhere in the authority position
     * disqualifies the value outright rather than being normalised here — this decides
     * whether to trust a string, and a function that repaired it would be guessing at intent.
     *
     * The path test is the panel's own prefix with a boundary, so `/studios-elsewhere`
     * cannot pass as `/studio`.
     */
    private static function withinStudio(string $url, string $host): bool
    {
        if (trim($url) === '' || str_contains($url, '\\')) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return false;
        }

        // An absolute URL is admitted only for this host, over http(s). A relative one
        // carries no host at all, which is the ordinary case for a Referer on this panel.
        if (isset($parts['host']) && $parts['host'] !== $host) {
            return false;
        }

        if (isset($parts['scheme']) && ! in_array($parts['scheme'], ['http', 'https'], true)) {
            return false;
        }

        $path = $parts['path'] ?? '';

        return $path === '/studio' || str_starts_with($path, '/studio/');
    }
}
