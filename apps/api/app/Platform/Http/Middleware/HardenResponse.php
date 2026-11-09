<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The response-hardening headers, on every response, in ONE place — Task 12. `R-038`, `R-235`.
 *
 * ================================================================================
 * WHY A MIDDLEWARE AND NOT FOUR MORE `JsonResponse` ARGUMENTS
 * ================================================================================
 *
 * `R-038`'s acceptance clause says it in so many words: *"`nosniff` and `Referrer-Policy` on
 * every response including the probes, **set in one place rather than per controller**, and
 * asserted against a captured response from each of the three route shapes"*. And `R-235`'s
 * review-by asks for the same shape from the other end: *"extend the existing assertion to
 * cover **every route on the surface** rather than a named four"*.
 *
 * Per-controller headers are how `R-235` happened. 10b's fix round gave the board, the
 * history, `/v1/tenant` and `/v1/devices` an explicit `private, no-store` for a stated reason
 * — a shared kitchen tablet, where `no-cache` permits a stored copy that survives the lock,
 * the sign-out and the next person's sign-in — and `GET /v1/members` was missed, so **the one
 * route whose body is every staff member's name and email address** kept the framework
 * default. Four controllers agreeing is not a property; one middleware is.
 *
 * ================================================================================
 * WHAT IS SET, AND WHAT EACH ONE IS FOR
 * ================================================================================
 *
 *   X-Content-Type-Options: nosniff        `R-038`. Every body this surface produces is
 *                                          `application/json` or `application/problem+json`
 *                                          with an explicit `Content-Type`, so the practical
 *                                          exposure is small — and the panels are `text/html`,
 *                                          where it is not.
 *   Referrer-Policy: no-referrer           `R-038`. Not `strict-origin-when-cross-origin`:
 *                                          Task 3 puts the raw QR token in a URL FRAGMENT, and
 *                                          although a fragment is not sent in a `Referer` by
 *                                          any browser, the path can still name a tenant and
 *                                          a session id. There is no feature here that needs a
 *                                          referrer, so the strongest value costs nothing.
 *   X-Frame-Options: DENY                  Nothing in this product is meant to be framed. The
 *   + CSP frame-ancestors 'none'           two panels are the surfaces a clickjacking frame
 *                                          would target, and the header's modern replacement
 *                                          is set beside it rather than instead of it, because
 *                                          `X-Frame-Options` is what older middleboxes read.
 *   Permissions-Policy                     Denies the sensor and capability APIs outright. The
 *                                          customer PWA needs notifications and the studio
 *                                          needs none of these, and both are OTHER origins
 *                                          (`apps/web`, `apps/site`) — so on THIS origin the
 *                                          whole list is deniable, which is why it is a flat
 *                                          `=()` rather than a negotiated set.
 *   Cross-Origin-Opener-Policy             `same-origin`, so a panel window cannot be scripted
 *                                          by an opener it did not choose.
 *   Cross-Origin-Resource-Policy           `same-origin`. This whole origin is tenant data and
 *                                          an admin UI; nothing on it is a public sub-resource.
 *   Cache-Control: private, no-store       `R-235`, and see the allowlist below.
 *   Content-Security-Policy                See `ContentSecurityPolicy`, which builds it per
 *                                          response shape.
 *
 * ⚠ `Strict-Transport-Security` IS DELIBERATELY NOT HERE. It is a property of a TLS
 * deployment, this application is served over plain HTTP locally, and a captured response is
 * what `§8` requires before a control is claimed to work. It belongs with the edge
 * configuration in **Task 13** and is recorded there rather than written here as a
 * configuration that has never been observed doing anything.
 *
 * ================================================================================
 * THE CACHE ALLOWLIST, AND WHY IT IS AN ALLOWLIST
 * ================================================================================
 *
 * `private, no-store` is the DEFAULT for every response, and the exceptions are listed. That
 * is the direction this project already uses for route surface
 * (`RouteSurfaceTest`'s `$allowed`) and for refused vendor paths
 * (`RefuseUnservedVendorRoutes::patterns()`), and for the same reason: a new route that should
 * not be cached is covered without anybody remembering, while a new route that SHOULD be
 * cached arrives as a diff a reviewer sees.
 *
 * The exceptions are Livewire's six static asset routes — `livewire.js`, the per-component
 * CSS and JS, and the two source maps. They are version-stamped vendor assets behind a
 * per-installation prefix and they carry no tenant data at all; serving them `no-store` would
 * re-download the panel's JavaScript on every page view of an admin UI, which is a real cost
 * for no gain. Measured from the live router: those are the only GET routes on this origin
 * that are not tenant data, an auth exchange, a panel page or a probe.
 */
final class HardenResponse
{
    /**
     * Paths that may be cached by the client. Everything else gets `private, no-store`.
     *
     * The Livewire prefix is per-installation —
     * `substr(hash('sha256', config('app.key').'livewire-endpoint'), 0, 8)` — so it is matched
     * by PATTERN, exactly as `RouteSurfaceTest` and `RefuseUnservedVendorRoutes` match it, and
     * for the reason that file records: writing today's literal here would be a pass that
     * depends on this machine, which `§6` forbids.
     *
     * ⚠ `preview-file` AND `update` ARE NOT ON THIS LIST and must not be. The first is refused
     * by `RefuseUnservedVendorRoutes`; the second is `POST /livewire-<hash>/update`, which is
     * how every panel interaction travels and therefore carries tenant data in both
     * directions. The pattern below is anchored on the three asset directories and the two
     * map files rather than on the prefix, so neither can be matched by accident.
     *
     * @var list<string>
     */
    private const CACHEABLE = [
        '#^livewire-[0-9a-f]{8}/(css|js)/#',
        '#^livewire-[0-9a-f]{8}/livewire\.js$#',
        '#^livewire-[0-9a-f]{8}/livewire\.[a-z.]*min\.js\.map$#',
    ];

    /**
     * One year, immutable: these are content-addressed vendor assets behind a versioned query.
     */
    private const CACHEABLE_MAX_AGE = 31_536_000;

    /**
     * Every capability this origin has no feature for, denied.
     *
     * Written out rather than left to the browser default, because the default for most of
     * these is "ask the user", and a panel that can ask for a microphone is a panel an XSS can
     * ask for a microphone with.
     */
    private const PERMISSIONS = [
        'accelerometer', 'ambient-light-sensor', 'autoplay', 'battery', 'camera',
        'display-capture', 'document-domain', 'encrypted-media', 'fullscreen',
        'geolocation', 'gyroscope', 'magnetometer', 'microphone', 'midi', 'payment',
        'picture-in-picture', 'publickey-credentials-get', 'screen-wake-lock',
        'serial', 'usb', 'xr-spatial-tracking',
    ];

    /**
     * The one path on this origin that runs a WebAuthn ceremony, and the features it needs.
     *
     * `routes/harness.php` mounts it in `local` and `testing` only. `credentials.create()` was
     * never denied (that feature is not on `PERMISSIONS`); `credentials.get()` was, and that is
     * what `R-100`'s e2e proof failed on in CI. Both are named here so the pair cannot drift.
     */
    private const WEBAUTHN_PATH = 'passkey-harness';

    /** @var list<string> */
    private const WEBAUTHN_FEATURES = ['publickey-credentials-get'];

    public function __construct(private readonly ContentSecurityPolicy $csp) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        /*
         * ⚠ SET WITH `->set()` AND NOT `->setDefault()`-STYLE CONDITIONALS, so a controller
         * cannot weaken one of these by accident. The one header a controller is allowed to
         * decide is `Cache-Control`, and even that is decided here from an allowlist rather
         * than from what the controller happened to send -- `R-235` is precisely a controller
         * that did not send one.
         */
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        /*
         * ⚠ `DENY` UNLESS THE RESPONSE'S OWN POLICY SAYS SAME-ORIGIN, AND THE CONDITION IS
         * THE CSP RATHER THAN A PATH — `QRPAGER-40`, 2026-11-09.
         *
         * `PreviewController` serves the studio's draft with `frame-ancestors 'self'` so the
         * preview pane can frame it (Muhammad's ruling, rank 2, `§1`). This middleware used
         * to overwrite its `SAMEORIGIN` with `DENY` three lines later, which would have left
         * the two headers DISAGREEING about who may frame a tenant's draft — CSP permitting
         * the panel, `X-Frame-Options` refusing it — and the pane blank in every engine that
         * reads the older header. **Found by `PreviewFramingTest`, not by reading this file.**
         *
         * ⚠ **THE EXCEPTION IS DERIVED, NOT LISTED, WHICH IS WHY IT CANNOT BE TRIPPED BY
         * ACCIDENT.** It is not "the preview route is exempt" — a path allowlist here would
         * be a second place to keep in step with `PreviewController`, and the `R-242` ruling
         * on allowlists is that they must be NAMED and TESTED, which is a cost worth paying
         * only when nothing else will do. Here something else does: the two headers answer
         * the SAME question, so the modern one decides and the legacy one follows it. A
         * controller gets `SAMEORIGIN` only by having deliberately set a same-origin
         * `frame-ancestors`, and every other response — including one that merely forgot to
         * set a CSP — still gets `DENY`.
         *
         * The property the original comment claims is intact: a controller still cannot
         * weaken this header by accident, because the only thing that moves it is an explicit
         * framing policy on the same response.
         */
        $response->headers->set('X-Frame-Options', self::frameOptionsFor($response));
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', self::permissionsPolicyFor($request));

        /*
         * ⚠ A POLICY THE RESPONSE ALREADY SET IS LEFT ALONE, AND THIS IS THE ONE HEADER THAT
         * IS TREATED THAT WAY. IT IS NOT A STYLE CHOICE — IT IS A REGRESSION THIS FILE
         * ALMOST SHIPPED, CAUGHT BY THE REPOSITORY'S OWN TESTS.
         *
         * Three responses in this application build their own CSP, every one of them NARROWER
         * than `ContentSecurityPolicy::PANEL`:
         *
         *   PreviewController:122  default-src 'none'; style-src 'unsafe-inline'; sandbox
         *   PreviewController:153  default-src 'none'; img-src 'self'; …
         *   PanelProblemPage:177   default-src 'none'; style-src 'unsafe-inline'; …
         *
         * All three are `text/html`, so the shape rule hands them the PANEL policy — and
         * overwriting them replaced `default-src 'none'` + `sandbox` with
         * `script-src 'self' 'unsafe-inline' 'unsafe-eval'`. The first of those renders a
         * TENANT-AUTHORED landing document inside the studio, so the overwrite would have
         * permitted a tenant's own markup to EXECUTE SCRIPT in a signed-in manager's session:
         * a stored-XSS path opened by the middleware that exists to harden the surface.
         *
         * It was not reasoned out. `PanelProblemPageTest` and `ReviewFixesTest` both went red
         * on *"To contain: default-src 'none'"*, which is those two controls doing exactly
         * what they were written for.
         *
         * So the rule is: this middleware supplies a policy where there is NONE, and never
         * replaces one. A controller that has thought about its own document knows more about
         * it than a global default can, and every instance in this repository is stricter.
         * `ResponseHeaderTest` asserts the whole surface is covered, which is what stops this
         * becoming a hole: a route with no policy still gets one.
         */
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->csp->forResponse($request, $response));
        }

        if (self::isCacheable($request)) {
            $response->headers->set('Cache-Control', 'public, max-age='.self::CACHEABLE_MAX_AGE.', immutable');

            return $response;
        }

        /*
         * `private, no-store` -- `R-235`, and both words earn their place.
         *
         * `no-store` and not `no-cache`: `no-cache` permits a STORED copy that must be
         * revalidated, which is a copy on disk on a device several people use. 10b's fix round
         * named that exact scenario -- a shared kitchen tablet, where the copy survives the
         * lock, the sign-out and the next person's sign-in -- and `GET /v1/members`, whose body
         * is every staff member's name and email address, was the one route it missed.
         *
         * `private` is redundant beside `no-store` for a compliant cache and is kept for the
         * ones that are not: it is the directive a legacy intermediary is most likely to
         * honour, and this surface is all tenant data.
         */
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * The denial list, with the ONE path that needs two of them back.
     *
     * ================================================================================
     * ⚠ THIS EXISTS BECAUSE THE FLAT LIST BROKE A REAL CEREMONY, AND ONLY CI COULD SEE IT
     * ================================================================================
     *
     * `publickey-credentials-get=()` disables `navigator.credentials.get()`. CI run
     * `42372017224`, `[passkey-chromium] browser/passkey.spec.ts:79` (`R-100`):
     *
     *     Expected substring: "passkey login -> 200"
     *     Received string:    "nothing yet"
     *
     * `passkey-harness.blade.php:125` is `credentials.create()` — gated by
     * `publickey-credentials-create`, which this list never denied, so REGISTRATION passed.
     * Line 150 is `credentials.get()`, and line 166 prints `passkey login -> <status>` only
     * after it resolves. So the failure landed exactly one leg in.
     *
     * ⚠ AND `ResponseHeaderTest` ASSERTED THE DENIAL WAS SAFE, WITH A FALSE COMMENT:
     * *"A WebAuthn ceremony on THIS origin would need this — and there is none: the passkey
     * ceremonies run on `apps/web`, and `/passkey-harness` is a local-only fixture page. If a
     * real ceremony ever moves here, this line is what will say so."* It was already here. The
     * page being local-only makes it no less a ceremony, and `R-100`'s whole proof drives it.
     *
     * ⚠ WHY FOUR GREEN LOCAL GATES COULD NOT SEE IT, which is the mechanism rather than bad
     * luck: `routes/harness.php` exists because *"the credential ceremony lives in
     * `navigator.credentials` and cannot be driven from HTTP"*. `composer gate` runs 1,377 PHP
     * tests and no browser; a `Permissions-Policy` is enforced by a browser and by nothing
     * else. The only instrument that can fail on this is `pnpm run gate:e2e`, which the PHP
     * gate does not run. `ResponseHeaderTest` now asserts the HEADER on that path, which is
     * the part a PHP test *can* hold.
     *
     * ⚠ `(self)` AND NOT `*`: the harness page is the document doing the ceremony, so the
     * feature is allowed for this origin and for no embedded third party. And it is this ONE
     * path, not `local`/`testing` globally — the route only exists in those environments
     * (`RouteSurfaceTest` drives six environment names to prove it), so narrowing by path
     * rather than by environment keeps the denial in force for everything else even there.
     */
    private static function permissionsPolicyFor(Request $request): string
    {
        $harness = ltrim($request->getPathInfo(), '/') === self::WEBAUTHN_PATH;

        $directives = [];

        foreach (self::PERMISSIONS as $feature) {
            $directives[] = $harness && in_array($feature, self::WEBAUTHN_FEATURES, true)
                ? $feature.'=(self)'
                : $feature.'=()';
        }

        return implode(', ', $directives);
    }

    /**
     * `SAMEORIGIN` when the response's own CSP permits this origin to frame it, else `DENY`.
     *
     * Only `'self'` EXACTLY, as the whole directive value: a policy listing `'self'` beside
     * another source, or a wildcard, is not a same-origin policy and gets `DENY` here. That
     * keeps the legacy header at least as strict as the modern one in every case, which is
     * the only direction a disagreement between the two is safe in.
     */
    private static function frameOptionsFor(Response $response): string
    {
        $csp = (string) $response->headers->get('Content-Security-Policy', '');

        foreach (array_filter(array_map('trim', explode(';', $csp))) as $directive) {
            $parts = preg_split('/\s+/', $directive, 2);

            if (($parts[0] ?? '') === 'frame-ancestors') {
                return trim($parts[1] ?? '') === "'self'" ? 'SAMEORIGIN' : 'DENY';
            }
        }

        return 'DENY';
    }

    private static function isCacheable(Request $request): bool
    {
        $path = ltrim($request->getPathInfo(), '/');

        foreach (self::CACHEABLE as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The cache allowlist, exposed rather than duplicated in the test.
     *
     * `RefuseUnservedVendorRoutes::patterns()`'s own docblock says why, and that row cost a
     * real finding: *"a guard whose test carries its own copy of the list is a guard that can
     * disagree with its test"* -- and when it was written it had NO caller at all, which `§11`
     * QA L-2 found.
     *
     * @return list<string>
     */
    public static function cacheablePatterns(): array
    {
        return self::CACHEABLE;
    }

    /** @return list<string> */
    public static function deniedPermissions(): array
    {
        return self::PERMISSIONS;
    }
}
