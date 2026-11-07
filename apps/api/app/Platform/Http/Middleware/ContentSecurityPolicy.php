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

    public function forResponse(Request $request, Response $response): string
    {
        return self::isHtmlDocument($response) ? self::PANEL : self::JSON;
    }

    /**
     * An HTML document gets the panel policy; everything else gets `default-src 'none'`.
     *
     * ⚠ KEYED ON THE RESPONSE'S `Content-Type` AND NOT ON THE PATH, which is the safe
     * direction in both ways it can be wrong. A path prefix (`/platform`, `/studio`) would
     * hand the WEAKER policy to every future route under those prefixes whatever it served,
     * and would hand the STRICTER one to an HTML response from anywhere else — and there is
     * one: `PanelProblemPage` renders an HTML error document for a panel request, from the
     * exception handler, on whatever path failed. Under a path rule that page would be
     * sandboxed and blank.
     *
     * The default when the type is missing or unrecognised is the JSON policy — the stricter
     * one — because a response whose shape we cannot determine is not a response to relax for.
     */
    private static function isHtmlDocument(Response $response): bool
    {
        $type = (string) $response->headers->get('Content-Type', '');

        return str_contains(strtolower($type), 'text/html');
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
