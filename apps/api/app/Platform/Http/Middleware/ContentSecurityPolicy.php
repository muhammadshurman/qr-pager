<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Content-Security-Policy, built per RESPONSE SHAPE — Task 12.
 *
 * ================================================================================
 * TWO POLICIES, BECAUSE THIS ORIGIN SERVES TWO VERY DIFFERENT THINGS
 * ================================================================================
 *
 * `apps/api` is a JSON API *and* two server-rendered Filament panels. A single policy would
 * have to be the weaker of the two everywhere, which would throw away the strongest CSP
 * available anywhere in this project in order to accommodate a framework's markup.
 *
 *   JSON (every `/v1` route, both probes, the fallback, and anything that is not an HTML
 *   document):  `default-src 'none'` — nothing may load, nothing may execute, nothing may
 *   connect. A JSON body has no sub-resources at all, so the strongest possible policy is
 *   also the exactly-correct one. **No `'unsafe-inline'`, no `'unsafe-eval'`.**
 *
 *   HTML (the seven panel pages): `default-src 'self'` with no third-party origin anywhere,
 *   but with `'unsafe-inline'` and `'unsafe-eval'` on `script-src`. That is a deviation from
 *   `§3` row 12's *"CSP has no `unsafe-inline`"* and it is MEASURED rather than assumed. See
 *   below.
 *
 * ================================================================================
 * ⚠ WHY THE PANEL HALF CANNOT MEET THE GATE CLAUSE — MEASURED 2026-11-07
 * ================================================================================
 *
 * Rendered as a signed-in user across **all seven** panel GET routes (`/platform`,
 * `/platform/audit-log`, `/platform/restaurants`, `/studio`, `/studio/domains`,
 * `/studio/media`, `/studio/preview`):
 *
 *     distinct inline <script> blocks ....... 4   (identical on every page)
 *       sha256-fOX+lksixEHsGlx8Mx1lB2Kdg6mXYYTpAmANbotKoOQ=   const loadDarkMode = () => {…}
 *       sha256-ZIF72podw55gm6qb5R/Uoe4m2oM8VubXlhLLG6SPGNo=   Alpine sidebar store
 *       sha256-vcUpJi33fJKTOIFdXtdDsPiiyfwokvTOJeT5PCDnl+U=   window.filamentData = []
 *       sha256-aEvB9ezb+9/boY+W17q22SZiznd5sFWy96zGhi0Kp64=   loadDarkMode()
 *     distinct inline <style> blocks ........ 6
 *     inline style="" attributes ............ 55
 *     inline on*="" handlers ................ 0   (the one good number)
 *
 * **SO HASHES WERE ON THE TABLE FOR `script-src` AND ARE STILL NOT ENOUGH, AND THAT IS THE
 * DECISIVE MEASUREMENT.** Filament drives its entire UI with Alpine, and Alpine evaluates
 * every `x-data` / `x-on:` expression by constructing a function at run time:
 * `vendor/livewire/livewire/dist/livewire.js` carries **6** `AsyncFunction` sites, which is
 * that evaluator. A `script-src` without `'unsafe-eval'` blocks it, and the panels stop
 * working entirely — not degrade, stop.
 *
 * Livewire ships a CSP-safe build for exactly this (`dist/livewire.csp.js`, beside
 * `livewire.csp.min.js.map`, which is in our route table), and it does **not** rescue this:
 * Alpine's CSP build only evaluates a restricted syntax over components registered through
 * `Alpine.data()`, and Filament's own markup uses full expressions throughout. Adopting it
 * would mean replacing vendor markup, which is a different task and a much larger one.
 *
 * ⚠ AND NOTHING IN THIS REPOSITORY COULD HAVE VERIFIED A STRICTER PANEL POLICY ANYWAY, which
 * is the second reason not to ship one. `browser/panel.spec.ts` says so in its own words:
 * *"This is the panel's CSS and markup in a real engine; it is **not** a logged-in session
 * driving Livewire."* So a hashed `script-src` would have been enforced on an admin UI whose
 * JavaScript no instrument here executes — a control shipped blind, which `§8` forbids more
 * plainly than the gate clause demands the control.
 *
 * Recorded as `R-242` with this measurement, the narrowing path and an owner. **What the
 * panel policy DOES buy, and it is not nothing:** no third-party script, style, image, font
 * or connection is permitted from either panel at all — which only became possible once
 * `LocalAvatarProvider` removed `ui-avatars.com`, the one remote origin either panel had —
 * plus no framing, no `<base>` hijack, no `<object>`/`<embed>`, and forms that cannot post
 * off-origin.
 */
final class ContentSecurityPolicy
{
    /**
     * Every `/v1` response, both probes, the fallback — anything that is not an HTML document.
     *
     * `default-src 'none'` covers every fetch directive that has no explicit value, so this
     * really does deny scripts, styles, images, fonts, frames, media, workers, manifests and
     * `connect()` in one word. The four listed afterwards are the ones `default-src` does NOT
     * cover, and each is a real exfiltration or injection primitive rather than a flourish:
     *
     *   `base-uri 'none'`       a `<base href>` injected into a document rewrites every
     *                           relative URL on it. Not covered by `default-src`.
     *   `form-action 'none'`    where a form may POST. Not covered by `default-src`, and it is
     *                           the directive that stops an injected form exfiltrating.
     *   `frame-ancestors 'none'` who may frame this. The modern `X-Frame-Options`, set beside
     *                           it in `HardenResponse` for older middleboxes.
     *   `sandbox`               with no tokens: no scripts, no forms, no plugins, no
     *                           same-origin. A JSON document needs none of them, and this is
     *                           what makes a body that somehow reached a browser as a document
     *                           inert rather than merely unstyled.
     */
    private const JSON = "default-src 'none'; base-uri 'none'; form-action 'none'; "
        ."frame-ancestors 'none'; sandbox";

    /**
     * The two Filament panels.
     *
     * Read the class docblock before loosening any of this. `'unsafe-inline'` and
     * `'unsafe-eval'` on `script-src` are there by measurement, not by preference, and
     * `R-242` carries the figures.
     *
     * `img-src` takes `data:` for `LocalAvatarProvider`'s inline SVG and for Filament's own
     * inline icons; `blob:` for the media library, which previews a selected file before it is
     * uploaded. Neither admits a remote host.
     *
     * `connect-src 'self'` is Livewire's update endpoint and nothing else. `font-src 'self'`
     * is `QRPAGER-23 SR-4`'s local font provider — the policy now ENFORCES what that task
     * proved, which is the difference between a measurement and a control.
     */
    private const PANEL = "default-src 'self'; "
        ."script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
        ."style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data: blob:; "
        ."font-src 'self'; "
        ."connect-src 'self'; "
        ."object-src 'none'; "
        ."base-uri 'none'; "
        ."form-action 'self'; "
        ."frame-ancestors 'none'";

    /**
     * THE PANEL PATHS, AND THE RELAXATION APPLIES NOWHERE ELSE — Muhammad's condition on `R-242`.
     *
     * His ruling, rank 1: *"the relaxation is a NAMED ALLOWLIST OF PANEL PATHS, never a global
     * weakening — with a test asserting that every non-panel route still gets the strict
     * policy, so a new public route cannot inherit the exemption."*
     *
     * ⚠ AND THAT CONDITION CAUGHT A SECOND INSTANCE OF ITS OWN CLASS IMMEDIATELY. The first
     * version of this class keyed on the RESPONSE SHAPE — `Content-Type: text/html` got the
     * panel policy — which is a global weakening wearing a narrow name: **every** HTML
     * response on this origin inherited it, including `/passkey-harness`, which is not a panel
     * and is a SECOND CREDENTIAL SURFACE. A new HTML route would have inherited it too,
     * silently, which is precisely what he forbade.
     *
     * Listed one by one rather than as a `/platform*` + `/studio*` pair, for the reason
     * `RouteSurfaceTest`'s own allowlist gives: a wildcard lets a new panel route arrive
     * without a diff, and the diff is the mechanism.
     *
     * The Livewire prefix is matched by PATTERN because it is per-installation —
     * `substr(hash('sha256', config('app.key').'livewire-endpoint'), 0, 8)` — so a literal
     * here would be a pass that depends on this machine, which `§6` forbids. It is on the list
     * because `POST livewire-<hash>/update` is how every panel interaction travels.
     *
     * @var list<string>
     */
    private const PANEL_PATHS = [
        '#^platform$#',
        '#^platform/#',
        '#^studio$#',
        '#^studio/#',
        '#^livewire-[0-9a-f]{8}/#',
    ];

    /**
     * The WebAuthn harness page, which needs a policy of its own and had NO business having the
     * panel's.
     *
     * ⚠ THIS ENTRY EXISTS BECAUSE THE SHAPE-KEYED VERSION BROKE A REAL CEREMONY IN CI, AND
     * ONLY IN CI. `/passkey-harness` is `text/html`, so it took the panel policy — and
     * `HardenResponse`'s `Permissions-Policy` denied `publickey-credentials-get`, which is the
     * feature `navigator.credentials.get()` needs. CI run `42372017224`:
     *
     *     ✘ [passkey-chromium] browser/passkey.spec.ts:79 R-100 — registers against a virtual
     *       authenticator and then signs in with it
     *       Expected substring: "passkey login -> 200"
     *       Received string:    "nothing yet"
     *
     * `passkey-harness.blade.php:125` is `navigator.credentials.create()` — gated by
     * `publickey-credentials-create`, which was never denied, so REGISTRATION passed. Line 150
     * is `navigator.credentials.get()`, and line 166 prints `passkey login -> <status>` only
     * after it resolves. The login leg never ran.
     *
     * ⚠ AND MY OWN TEST ASSERTED THE DENIAL WAS SAFE, WITH A COMMENT THAT WAS FALSE:
     * *"A WebAuthn ceremony on THIS origin would need this — and there is none … If a real
     * ceremony ever moves here, this line is what will say so."* The ceremony was already
     * there. `routes/harness.php` exists precisely because *"the credential ceremony lives in
     * `navigator.credentials` and cannot be driven from HTTP"* — so no number of PHP tests
     * could see it, and four green local gates did not.
     *
     * Its own policy: same-origin everything, `'unsafe-inline'` for its one inline script
     * (it is a hand-written fixture page, not Alpine, so NO `'unsafe-eval'`), and nothing else.
     */
    private const HARNESS_PATH = 'passkey-harness';

    private const HARNESS = "default-src 'self'; "
        ."script-src 'self' 'unsafe-inline'; "
        ."style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data:; "
        ."connect-src 'self'; "
        ."object-src 'none'; "
        ."base-uri 'none'; "
        ."form-action 'self'; "
        ."frame-ancestors 'none'";

    public function forResponse(Request $request, Response $response): string
    {
        $path = ltrim($request->getPathInfo(), '/');

        if ($path === self::HARNESS_PATH) {
            return self::HARNESS;
        }

        return self::isPanelPath($path) ? self::PANEL : self::JSON;
    }

    /**
     * ⚠ KEYED ON THE PATH NOW, NOT ON THE RESPONSE'S `Content-Type`, AND THE REASON THE OLD
     * KEY LOOKED SAFER IS WORTH KEEPING.
     *
     * The shape key was chosen because `PanelProblemPage` renders an HTML error document from
     * the exception handler, on whatever path failed — so a path rule would have sandboxed a
     * panel error page and left it blank. That reasoning was right and is now handled a better
     * way: `HardenResponse` never REPLACES a policy a response built for itself, and
     * `PanelProblemPage` and `PreviewController` all build their own (narrower) ones. So the
     * path key costs nothing and closes the inheritance hole the shape key opened.
     */
    private static function isPanelPath(string $path): bool
    {
        foreach (self::PANEL_PATHS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function panelPathPatterns(): array
    {
        return self::PANEL_PATHS;
    }

    /** The harness policy, exposed for the same reason the other two are. */
    public static function harnessPolicy(): string
    {
        return self::HARNESS;
    }

    /** The JSON policy, exposed so the test reads the guard's own value rather than a copy. */
    public static function jsonPolicy(): string
    {
        return self::JSON;
    }

    /** The panel policy, exposed for the same reason. */
    public static function panelPolicy(): string
    {
        return self::PANEL;
    }
}
