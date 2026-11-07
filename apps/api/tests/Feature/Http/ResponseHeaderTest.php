<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use App\Models\User;
use App\Platform\Http\Middleware\ContentSecurityPolicy;
use App\Platform\Http\Middleware\HardenResponse;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * THE RESPONSE-HARDENING HEADERS, OVER THE WHOLE SURFACE, DERIVED FROM THE ROUTER
 * ================================================================================
 *
 * Task 12. `R-038` (no `nosniff`, `Referrer-Policy` or `X-Frame-Options` on any response),
 * `R-235` (`GET /v1/members` was the only tenant-data route without `private, no-store`, and
 * its body is every staff member's name and email address), and the CSP and CORS halves of
 * `§3` row 12's scope.
 *
 * ⚠ THE WHOLE POINT OF THIS FILE IS THAT IT NAMES NO ROUTES. `R-235`'s review-by asks for
 * exactly that — *"extend the existing assertion to cover **every route on the surface**
 * rather than a named four"* — and `R-235` is what a named four costs: 10b's fix round gave
 * the board, the history, `/v1/tenant` and `/v1/devices` an explicit `private, no-store` for
 * a stated reason, and `StaffShellEndpointsTest` asserted it for those four, so the one route
 * that was missed was missed by the test as well as by the code. A list cannot fail for a
 * route nobody put on it.
 *
 * So the route table is read from `Route::getRoutes()` and every entry is driven. A route
 * added to this application is covered by this file on the commit that adds it, with no
 * edit here.
 *
 * ⚠ AND IT IS DRIVEN ANONYMOUSLY, WHICH IS A FEATURE AND NOT A SHORTCUT. An unauthenticated
 * caller gets 401, 403, 404, 419 or 429 from almost all of it — and those are the responses
 * an attacker generates most of, the ones no controller wrote, and therefore exactly the ones
 * a per-controller header would be missing from. The authenticated 200s are captured
 * separately in the cases below, because the two prove different things.
 */

/**
 * A response's Cache-Control directives, sorted.
 *
 * ⚠ ASSERTED AS A SET AND NOT AS A STRING, because Symfony's `ResponseHeaderBag` RECOMPUTES
 * this header: `private, no-store` is set by `HardenResponse` and reads back as
 * `no-store, private`. Measured — it is how the three cases below first failed
 * (*"-'private, no-store' +'no-store, private'"*). A string comparison here would be
 * asserting Symfony's sort order, which is not the property anybody cares about and which a
 * framework upgrade may change.
 *
 * @return list<string>
 */
function cacheDirectives(string $value): array
{
    $parts = array_values(array_filter(array_map('trim', explode(',', $value))));
    sort($parts);

    return $parts;
}

/**
 * Every route in the table, as [method, uri], with HEAD and the fallback's duplicates dropped.
 *
 * @return list<array{0: string, 1: string}>
 */
function drivableRoutes(): array
{
    $driveable = [];

    /** @var RoutingRoute $route */
    foreach (Route::getRoutes() as $route) {
        $methods = array_values(array_filter(
            $route->methods(),
            static fn (string $m): bool => $m !== 'HEAD',
        ));

        if ($methods === []) {
            continue;
        }

        // ONE method per route, the first: the headers are set by a global middleware on the
        // way out, so the verb cannot change the answer, and driving all seven verbs of the
        // fallback would spend the `unknown` bucket for nothing.
        $uri = '/'.ltrim($route->uri(), '/');

        // Parameters get a syntactically valid uuid. What comes back is a 404 or a 401, which
        // is the point: a response is a response and must carry the headers.
        $uri = (string) preg_replace('#\{[^}]+\}#', (string) Str::uuid7(), $uri);

        $driveable[] = [$methods[0], $uri];
    }

    return array_values(array_unique($driveable, SORT_REGULAR));
}

/** A platform admin: `is_platform_admin`, and deliberately no membership anywhere. */
function headerTestPlatformAdmin(): User
{
    $id = (string) Str::uuid7();

    // The OWNER connection: `qrpager_app` holds no INSERT on `users.is_platform_admin`
    // (SEC L-1 / R-006), so the app role cannot create a platform admin at all.
    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Header Probe',
        'email' => 'hdr-'.Str::lower(Str::random(10)).'@example.test',
        'is_platform_admin' => true,
    ]);

    $user = User::query()->findOrFail($id);
    assert($user instanceof User);

    return $user;
}

it('sets every hardening header on EVERY route in the table, driven anonymously', function (): void {
    $routes = drivableRoutes();

    // Not vacuous: if the derivation broke -- a renamed facade, an empty router, a filter that
    // excluded everything -- an empty list would pass every assertion below and report the
    // strongest possible result. The live table has ~59 entries; 40 is a floor that cannot be
    // reached by accident and does not need maintaining every time a route is added.
    expect(count($routes))->toBeGreaterThan(
        40,
        'the route table came back with '.count($routes).' entries: the derivation is broken, not the surface',
    );

    $missing = [];

    foreach ($routes as [$method, $uri]) {
        $response = $this->call($method, $uri);
        $headers = $response->headers;

        $expected = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];

        foreach ($expected as $header => $value) {
            if ($headers->get($header) !== $value) {
                $missing[] = sprintf(
                    '%s %s [%d] %s: %s',
                    $method, $uri, $response->getStatusCode(), $header,
                    $headers->get($header) === null ? 'ABSENT' : 'is "'.$headers->get($header).'"',
                );
            }
        }

        if ($headers->get('Content-Security-Policy') === null) {
            $missing[] = sprintf('%s %s [%d] Content-Security-Policy: ABSENT', $method, $uri, $response->getStatusCode());
        }

        if ($headers->get('Permissions-Policy') === null) {
            $missing[] = sprintf('%s %s [%d] Permissions-Policy: ABSENT', $method, $uri, $response->getStatusCode());
        }
    }

    // Reported as one list rather than per route, so a failure names every offender at once
    // -- `RouteSurfaceTest`'s own lesson about asserting a diff instead of a loop.
    expect($missing)->toBe([], "unhardened responses:\n  ".implode("\n  ", $missing));
});

it('denies every sensor capability in Permissions-Policy, from the middleware’s own list', function (): void {
    // The list comes from the guard rather than from a copy here:
    // `RefuseUnservedVendorRoutes::patterns()`'s docblock has the reason, and it cost a real
    // finding -- "a guard whose test carries its own copy of the list is a guard that can
    // disagree with its test".
    $policy = (string) $this->get('/healthz')->headers->get('Permissions-Policy');

    foreach (HardenResponse::deniedPermissions() as $feature) {
        // ⚠ `str_contains(...)->toBeTrue($message)` AND NOT `toContain($needle, $message)`.
        // Pest's `toContain()` takes FURTHER NEEDLES, not a message — so the two-argument form
        // asserts the policy contains the failure text as well, which is how this case first
        // failed ("To contain: [accelerometer] is not denied"). The negated two-argument form
        // is worse: it is silently always green. `RouteSurfaceTest` records the same trap.
        expect(str_contains($policy, $feature.'=()'))->toBeTrue("[{$feature}] is not denied");
    }

    // The ones that would be most costly on an admin surface, named explicitly as well as
    // walked: a count tells you the list changed, this tells you which way.
    expect($policy)->toContain('camera=()')
        ->and($policy)->toContain('microphone=()')
        ->and($policy)->toContain('geolocation=()')
        // A WebAuthn ceremony on THIS origin would need this -- and there is none: the passkey
        // ceremonies run on `apps/web`, and `/passkey-harness` is a local-only fixture page.
        // If a real ceremony ever moves here, this line is what will say so.
        ->and($policy)->toContain('publickey-credentials-get=()');
});

it('gives EVERY tenant-data route `private, no-store` — R-235, with no route named', function (): void {
    /*
     * ⚠ THE DERIVATION IS THE ASSERTION. Every route is driven and every response must carry
     * `private, no-store` UNLESS its path matches `HardenResponse::cacheablePatterns()` -- the
     * middleware's own list, read from the middleware. So the question this case answers is
     * not "do the four routes somebody listed carry the header" but "is there any response on
     * this origin that may be stored, other than the ones we decided may be".
     *
     * `no-store` and not `no-cache`: `no-cache` permits a stored copy that must be
     * revalidated, i.e. a copy ON DISK on a device several people use. 10b's fix round named
     * that scenario -- a shared kitchen tablet, where the copy survives the lock, the sign-out
     * and the next person's sign-in.
     */
    $wrong = [];
    $cacheable = 0;

    foreach (drivableRoutes() as [$method, $uri]) {
        $response = $this->call($method, $uri);
        $cacheControl = (string) $response->headers->get('Cache-Control', '');

        $isAllowlisted = false;

        foreach (HardenResponse::cacheablePatterns() as $pattern) {
            if (preg_match($pattern, ltrim($uri, '/')) === 1) {
                $isAllowlisted = true;

                break;
            }
        }

        if ($isAllowlisted) {
            $cacheable++;

            if (! str_contains($cacheControl, 'public') || ! str_contains($cacheControl, 'immutable')) {
                $wrong[] = "{$method} {$uri}: allowlisted as cacheable but answered \"{$cacheControl}\"";
            }

            continue;
        }

        if (cacheDirectives($cacheControl) !== ['no-store', 'private']) {
            $wrong[] = "{$method} {$uri} [{$response->getStatusCode()}]: \"{$cacheControl}\"";
        }
    }

    expect($wrong)->toBe([], "responses that may be stored:\n  ".implode("\n  ", $wrong));

    // THE POSITIVE CONTROL ON THE ALLOWLIST ITSELF. Without this, an allowlist that matched
    // NOTHING -- a stale Livewire prefix pattern, say -- would make the loop above assert
    // `private, no-store` for everything and pass, while the panel re-downloaded its
    // JavaScript on every page view and nobody could tell from this file.
    expect($cacheable)->toBeGreaterThan(
        0,
        'the cache allowlist matched no route at all: it has gone stale and this case is now vacuous',
    );
});

it('carries `private, no-store` on GET /v1/members itself, with a real body behind it', function (): void {
    // ⚠ `R-235`'s OWN ROUTE, DRIVEN AUTHENTICATED, because everything above is a 401 and a 401
    // has no body to protect. The row is about a 200 whose body is *"every staff member's name
    // and email address"*, so the proof has to be a 200 with that body in it.
    $this->actingForTenant(role: TenantRole::Owner);

    $owner = auth()->user();
    assert($owner instanceof User);

    $response = $this->getJson('/v1/members');

    $response->assertOk();

    expect(cacheDirectives((string) $response->headers->get('Cache-Control')))->toBe(['no-store', 'private'])
        // The body really is the thing the row is about: an assertion about a header on an
        // empty list would be true and would prove nothing.
        ->and($response->json('members'))->not->toBe([])
        ->and((string) $response->getContent())->toContain((string) $owner->email);
});

it('gives the JSON surface `default-src ’none’` with no unsafe-inline and no unsafe-eval', function (): void {
    // ⚠ `§3` row 12's clause -- *"CSP has no `unsafe-inline`"* -- IS MET HERE IN FULL, and this
    // is the surface it is fully meetable on: a JSON body has no sub-resources, so the
    // strongest possible policy is also the exactly correct one.
    foreach (['/healthz', '/readyz', '/v1/members', '/v1/pager-sessions', '/no-such-path-'.Str::uuid7()] as $path) {
        $csp = (string) $this->getJson($path)->headers->get('Content-Security-Policy');

        expect($csp)->toBe(ContentSecurityPolicy::jsonPolicy(), "[{$path}] is not on the JSON policy")
            ->and($csp)->toContain("default-src 'none'")
            ->and($csp)->not->toContain('unsafe-inline')
            ->and($csp)->not->toContain('unsafe-eval')
            // The four `default-src` does NOT cover, each a real primitive rather than a
            // flourish: a `<base>` rewrite, an exfiltrating form, a clickjacking frame, and
            // scripting a body that reached a browser as a document.
            ->and($csp)->toContain("base-uri 'none'")
            ->and($csp)->toContain("form-action 'none'")
            ->and($csp)->toContain("frame-ancestors 'none'")
            ->and($csp)->toContain('sandbox');
    }
});

it('gives the panel HTML its own policy, with no third-party origin anywhere in it', function (): void {
    /*
     * ⚠ THIS POLICY CARRIES `'unsafe-inline'` AND `'unsafe-eval'`, WHICH IS A DEVIATION FROM
     * `§3` row 12 AND IS MEASURED RATHER THAN PREFERRED -- `R-242` and
     * `ContentSecurityPolicy`'s docblock carry the figures. In one line: Filament drives its
     * UI with Alpine, Alpine evaluates every `x-data` expression by constructing a function at
     * run time (6 `AsyncFunction` sites in `livewire.js`), and a `script-src` without
     * `'unsafe-eval'` stops the panels working entirely.
     *
     * What this case holds is the half that IS achievable and is worth holding: NO THIRD-PARTY
     * ORIGIN of any kind. That only became true once `LocalAvatarProvider` removed
     * `ui-avatars.com` -- the one remote host either panel had, and it was carrying the
     * signed-in person's name.
     */
    $admin = headerTestPlatformAdmin();

    $response = $this->actingAs($admin)->get('/platform');

    $response->assertOk();

    // The premise: this really is an HTML document, so the shape-keyed policy applies.
    expect($response->headers->get('Content-Type'))->toContain('text/html');

    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->toBe(ContentSecurityPolicy::panelPolicy())
        ->and($csp)->toContain("default-src 'self'")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->toContain("base-uri 'none'")
        ->and($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->toContain("font-src 'self'")
        ->and($csp)->toContain("connect-src 'self'");

    // ⚠ NO SCHEME-AND-HOST ANYWHERE IN THE POLICY. Asserted as a property of the whole string
    // rather than host by host, so a future `->font()` or avatar provider that re-adds a CDN
    // cannot be accommodated by quietly widening one directive. `data:` and `blob:` are
    // schemes with no host and are the two the media library and the inline avatar need.
    expect($csp)->not->toMatch('#https?://#', "the panel policy names a remote origin: {$csp}");
});

it('serves the panel markup with no third-party request in it — the avatar leak, closed', function (): void {
    /*
     * ⚠ MEASURED BEFORE THE FIX, FROM A RENDERED `/platform` ON 2026-11-07:
     *
     *     https://ui-avatars.com/api/?name=A&format=svg&color=FFFFFF&background=%231f3129
     *
     * `name=` is the SIGNED-IN PERSON'S NAME. Filament's `HasAvatars::$defaultAvatarProvider`
     * is `UiAvatarsProvider`, both panels took the default, and so every page view of either
     * panel made an authenticated administrator's browser hand a staff member's name to a
     * third party -- with the referrer, the IP and the timing.
     *
     * It is the same defect `QRPAGER-23 SR-4` already fixed for the CDN FONT, through a second
     * Filament default nobody read. The CSP case above is the enforcement; this is the markup.
     */
    $admin = headerTestPlatformAdmin();

    $html = (string) $this->actingAs($admin)->get('/platform')->getContent();

    // One needle, for the reason the Permissions-Policy case records: `not->toContain()` with a
    // second argument treats it as another needle and the assertion becomes always-green.
    expect(str_contains($html, 'ui-avatars.com'))
        ->toBeFalse('the panel is back on the remote avatar service');

    // And the general property rather than the one host, for the reason the CSP case gives: a
    // different CDN would pass a check for this one. Every absolute URL in the markup must be
    // this application's own origin.
    preg_match_all('#(?:src|href)="(https?://[^"]+)"#i', $html, $matches);

    $foreign = array_values(array_filter(
        array_unique($matches[1]),
        static fn (string $url): bool => ! str_starts_with($url, (string) config('app.url')),
    ));

    expect($foreign)->toBe([], 'the panel loads from a third party: '.implode(', ', $foreign));

    // NOT VACUOUS: the page really does carry absolute URLs (Filament's own asset tags), so an
    // empty `$matches` would mean the regex stopped matching rather than that the markup is
    // clean -- which is the direction that reads as the strongest possible pass.
    expect($matches[1])->not->toBe([], 'no absolute URLs found at all: this case is matching nothing');

    // The avatar is still THERE and is still an avatar: a provider that returned an empty
    // string would pass every assertion above and silently remove the thing it replaced.
    expect($html)->toContain('data:image/svg+xml;base64,');
});

it('hardens a 404, a 429 and a rendered exception — the responses no controller wrote', function (): void {
    /*
     * ⚠ THE REGISTRATION POSITION IS WHAT THIS CASE IS ABOUT. `HardenResponse` is prepended
     * LAST in `bootstrap/app.php`, which makes it the OUTERMOST middleware -- and a
     * middleware's post-`$next()` code runs outermost LAST, so that is the only position that
     * sees the final response whatever produced it. Registered anywhere further in, the
     * headers would be present on controller responses and missing from the refusals, which
     * are the responses an attacker generates most of.
     *
     * Three shapes that no controller in this application returns: the fallback's 404, the
     * limiter's 429, and the exception handler's problem document.
     */
    $shapes = [];

    // (a) the fallback 404
    $shapes['404'] = $this->getJson('/no-such-path-'.Str::uuid7());

    // (b) a 429 from a real bucket, driven rather than configured (R-063's clause). The
    // customer token route is the tightest bucket reachable without a credential.
    $limit = (int) config('ratelimit.buckets.pager-token.per_ip.attempts');
    $throttled = null;

    for ($i = 0; $i <= $limit + 1; $i++) {
        $response = $this->postJson('/v1/pager/session', ['token' => str_repeat('a', 64)]);

        if ($response->getStatusCode() === 429) {
            $throttled = $response;

            break;
        }
    }

    expect($throttled)->not->toBeNull("the pager-token bucket did not refuse within {$limit} + 2 requests");
    $shapes['429'] = $throttled;

    // (c) a rendered exception. The harness route exists in `local` and `testing` only and is
    // the project's own way of reaching the exception handler over HTTP.
    Route::get('/_header-probe-throws', function (): void {
        throw new RuntimeException('a probe, and its message must not reach the body');
    });

    $shapes['500'] = $this->getJson('/_header-probe-throws');

    expect($shapes['500']->getStatusCode())->toBe(500);

    foreach ($shapes as $label => $response) {
        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff', "[{$label}] has no nosniff")
            ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer', "[{$label}] has no Referrer-Policy")
            ->and($response->headers->get('X-Frame-Options'))->toBe('DENY', "[{$label}] has no X-Frame-Options")
            ->and(cacheDirectives((string) $response->headers->get('Cache-Control')))
            ->toBe(['no-store', 'private'], "[{$label}] may be stored")
            ->and($response->headers->get('Content-Security-Policy'))->toBe(
                ContentSecurityPolicy::jsonPolicy(),
                "[{$label}] is not on the JSON policy",
            );
    }
});

it('never REPLACES a policy a response built for itself — the regression this nearly shipped', function (): void {
    /*
     * ⚠ THE CASE FOR THE DEFECT `HardenResponse` ITSELF ALMOST INTRODUCED, and it is here
     * rather than only in a docblock because the next person to simplify that `if` needs a
     * red test and not a paragraph.
     *
     * Three responses in this application build their own CSP and all three are NARROWER than
     * the panel policy: `PreviewController` twice and `PanelProblemPage` once, each
     * `default-src 'none'`. They are `text/html`, so the shape rule hands them the PANEL
     * policy — and the first version of this middleware OVERWROTE it, replacing
     * `default-src 'none'; style-src 'unsafe-inline'; sandbox` with
     * `script-src 'self' 'unsafe-inline' 'unsafe-eval'`.
     *
     * `/studio/preview` renders a TENANT-AUTHORED landing document. So that overwrite
     * permitted a tenant's own markup to execute script inside a signed-in manager's session:
     * a stored-XSS path opened BY the middleware meant to harden the surface. It was caught by
     * `PanelProblemPageTest` and `ReviewFixesTest` going red on *"To contain: default-src
     * 'none'"*, not by anybody reasoning about it.
     */
    $this->actingForTenant(role: TenantRole::Owner);

    $preview = $this->get('/studio/preview');

    expect($preview->status())->toBe(200);

    $csp = (string) $preview->headers->get('Content-Security-Policy');

    /*
     * ⚠ `PreviewController` HAS TWO POLICIES AND THIS ROUTE CARRIES THE SECOND ONE — measured,
     * because the first draft of this case asserted `sandbox` here and failed.
     *
     *   the ASSET response  `default-src 'none'; style-src 'unsafe-inline'; sandbox`
     *   the PAGE response   `default-src 'none'; img-src 'self'; style-src 'unsafe-inline';
     *                        frame-ancestors 'none'; base-uri 'none'; form-action 'none'`
     *
     * `/studio/preview` is the page. Both are `default-src 'none'` and both are narrower than
     * the panel policy, which is the property this case is about; `sandbox` belongs to the
     * bytes served for a tenant's document, not to the page that frames them.
     */
    expect($csp)->toContain("default-src 'none'")
        ->and($csp)->toContain("frame-ancestors 'none'")
        // And NOT the panel policy's script allowances, which is the actual regression.
        ->and(str_contains($csp, 'unsafe-eval'))
        ->toBeFalse('the tenant-content preview was given the panel policy: its markup can execute script');

    // It is still HARDENED, which is the other half: skipping the CSP must not skip the rest.
    expect($preview->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($preview->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($preview->headers->get('Referrer-Policy'))->toBe('no-referrer');

    // THE POSITIVE CONTROL: a response with NO policy of its own still gets one, or "do not
    // overwrite" would have become "do not set".
    $json = $this->getJson('/no-such-path-'.Str::uuid7());

    expect($json->headers->get('Content-Security-Policy'))
        ->toBe(ContentSecurityPolicy::jsonPolicy(), 'a response with no policy of its own got none');
});
