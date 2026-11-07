<?php

declare(strict_types=1);

use App\Domain\Http\ProblemType;
use App\Platform\Http\Middleware\RateLimit;
use App\Platform\Http\Middleware\RefuseUnservedVendorRoutes;
use App\Platform\Readiness\ReadinessStore;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * "Every public route is under /v1" (PLAN.md §3, row 2), with the two probes as the
 * named exception approved on 2026-10-26 and recorded in §6 beside the versioning
 * policy.
 *
 * This replaces the skeleton's ExampleTest, which asserted that GET / returns 200.
 * That route was Laravel's welcome page: a public route outside /v1, so the gate and
 * the placeholder test contradicted each other. The route is gone and the clause is
 * what is tested instead.
 */

/** @return list<string> */
function publicRouteUris(): array
{
    $uris = [];

    /** @var RoutingRoute $route */
    foreach (Route::getRoutes() as $route) {
        $uris[] = '/'.ltrim($route->uri(), '/');
    }

    $uris = array_values(array_unique($uris));
    sort($uris);

    return $uris;
}

it('serves exactly the two unversioned probes and nothing else outside /v1', function (): void {
    // The allowlist, in full. A new route outside /v1 fails here and has to be either
    // moved under /v1 or added to this list with a reason -- which is a diff a reviewer
    // sees, rather than a route table nobody reads.
    //
    // The fallback is on it because it is not a surface: it matches paths that do not
    // exist, and the gate names it -- "the rate limiter covers unknown routes (404s)".
    // Without it every unmatched path would get Laravel's HTML 404 instead of a
    // problem+json document, and nothing would count a scan.
    //
    // `/passkey-harness` joined it at Task 5, and it is the one entry that is NOT a
    // surface and not a 404 catcher. PLAN.md §3 row 5 asks for "a minimal unstyled harness
    // page ... purely to exercise the WebAuthn browser API", because the credential
    // ceremony lives in navigator.credentials and cannot be driven from HTTP. It is a test
    // fixture that happens to need a URL.
    //
    // It is NOT under /v1 deliberately: putting it there would publish it into the
    // versioned public contract, which is worse than an unversioned fixture. And it is
    // served in `local` and `testing` and NOWHERE else -- the case below drives that in six
    // child processes, which is what makes this allowlist entry acceptable rather than a
    // hole. ⚠ That sentence read "it is not served in production at all" until 2026-11-03,
    // and the gap between the two claims was `staging` (QRPAGER-31): measured 200.
    //
    // FOUR PANEL PATHS JOINED AT TASK 6, and they are listed one by one rather than as a
    // `/platform*` prefix on purpose: a wildcard would let a fifth panel route arrive
    // without a diff, which is the whole mechanism this allowlist is.
    //
    // THEY ARE NOT UNDER /v1 AND SHOULD NOT BE. `/v1` versions the REST contract -- §6's
    // "a published path is never changed in place" is a promise to API clients about
    // request and response shapes. The Filament panel is a server-rendered admin UI whose
    // "responses" are HTML pages and Livewire updates; it has no API consumers, so a
    // version in its URL would promise something to nobody and would have to be honoured
    // forever. §12 already treats the panel as a surface of its own.
    //
    // NONE OF THEM IS PUBLIC. All four sit behind the panel's `web` auth guard and
    // Filament's Authenticate middleware, and `canAccessPanel()` additionally requires
    // `is_platform_admin`. PlatformPanelInvariantTest drives every one of them as an
    // anonymous caller and as all five Task 5 principals.
    //
    // `/platform/logout` is Filament's own and is NOT a second authentication surface: it
    // takes no credential and only ends the session Task 5's login established. The panel
    // mounts no `->login()` page, which is the line that would have been one (see
    // PlatformPanelProvider's docblock).
    $allowed = [
        '/healthz',
        '/readyz',
        '/passkey-harness',
        '/platform',
        '/platform/audit-log',
        '/platform/logout',
        '/platform/restaurants',
        // SIX MORE JOINED AT TASK 7, from the SECOND panel. (The comment said FIVE and
        // listed six, which the §11 QA review counted -- this allowlist's whole value is
        // that a reader counts it.) `/studio` is the
        // TENANT-scoped builder (QRPAGER-14 decision D1, SR-1): the 2026-10-28 ruling
        // puts tenant content out of the platform panel's reach, so the builder, its
        // media library and its domains are a panel of their own whose every user is a
        // member of exactly one tenant.
        //
        // They are listed one by one for the same reason the platform's four are: a
        // `/studio*` wildcard would let a sixth arrive without a diff, which is the whole
        // mechanism this allowlist is.
        //
        // NOT UNDER /v1, and the argument above applies unchanged -- these are
        // server-rendered admin pages with no API consumers.
        //
        // NONE OF THEM IS PUBLIC. All six sit behind the panel's `web` guard and
        // Filament's Authenticate -- the preview included, which it was NOT until the
        // §11 security review: it was registered with `routes()` rather than
        // `authenticatedRoutes()`, and Filament applies the auth middleware to only the
        // second. ReviewFixesTest now reads every /studio route's middleware off the
        // router and asserts Authenticate is on it.
        //
        // And every page overrides canAccess() while the preview re-resolves its tenant,
        // because `POST /livewire-<hash>/update` runs neither (Task 6's security M-1).
        // StudioPanelInvariantTest drives every route anonymously, as a platform admin,
        // as staff, and as owner and manager for the positive control -- four of Task
        // 5's five principals. The fifth, a KDS device token, is a BEARER credential on
        // the stateless /v1 surface and carries no session, so it cannot reach a panel
        // route at all; DeviceTokenOnAuthSurfaceTest owns that boundary.
        '/studio',
        '/studio/domains',
        '/studio/logout',
        '/studio/media',
        '/studio/preview',
        '/studio/preview/asset/{asset}',
        '/{fallbackPlaceholder}',
    ];

    // ELEVEN MORE ARRIVED WITH FILAMENT AT TASK 6, and they are matched by PATTERN rather
    // than listed, for a reason that is measured and not stylistic.
    //
    // Nine are Livewire's, under a prefix Livewire 4 derives from the application key:
    // `substr(hash('sha256', config('app.key').'livewire-endpoint'), 0, 8)`, read from
    // Livewire\Mechanisms\HandleRequests\EndpointResolver in this session. It is
    // deliberately per-installation so a universal scanner cannot guess it -- which means
    // **the literal differs on this machine and on CI**, and writing today's value here
    // would be exactly the "pass that depends on something only present on the builder's
    // machine" §6 forbids. So the prefix is `livewire-<8 hex>`.
    //
    // Two are Filament's export/import download routes, and they are REFUSED rather than
    // served: both answered 500 to an anonymous GET, because they read `exports` and
    // `imports`, tables this application never created. RefuseUnservedVendorRoutes answers
    // the ordinary 404 for them and for Livewire's `preview-file`. They still appear in the
    // ROUTE TABLE, which is what this case reads, so they are allowed here and their
    // refusal is asserted in the case below.
    //
    // `upload-file` was refused with `preview-file` until 2026-10-31 and is now SERVED: it
    // is the endpoint /studio/media receives a file on, and refusing it meant the page
    // could not work in a browser for the whole of Task 7. The case below asserts it is
    // served, which is the other half of asserting the three refusals.
    $vendorPatterns = [
        '#^/livewire-[0-9a-f]{8}/#',
        '#^/filament/(exports|imports)/#',
    ];

    $outside = array_values(array_filter(
        publicRouteUris(),
        static function (string $uri) use ($allowed, $vendorPatterns): bool {
            if (str_starts_with($uri, '/v1') || in_array($uri, $allowed, true)) {
                return false;
            }

            foreach ($vendorPatterns as $pattern) {
                if (preg_match($pattern, $uri) === 1) {
                    return false;
                }
            }

            return true;
        },
    ));

    // Asserted as a diff rather than per-route, so a failure names every offender at
    // once. (Pest's toContain takes further needles, not a message, so the per-route
    // form asserted that the allowlist contained the failure text -- which is how this
    // test first failed.)
    expect($outside)->toBe([], 'public routes outside /v1: '.implode(', ', $outside));
});

it('keeps the Livewire prefix pattern matching the real routes, so the allowance cannot go quiet', function (): void {
    // THE OTHER HALF OF THE PATTERN ABOVE. A pattern allowance is only honest while it
    // still matches what it was written for: if Livewire changed its prefix scheme, the
    // case above would stop excluding those routes and fail loudly -- but the matcher in
    // RefuseUnservedVendorRoutes would silently stop refusing the preview endpoint, which
    // is a guard going quiet rather than failing. That is the defect class §4a records four
    // instances of, so it is closed from this side.
    $livewire = array_values(array_filter(
        publicRouteUris(),
        static fn (string $uri): bool => str_contains($uri, 'livewire'),
    ));

    expect($livewire)->not->toBe([], 'no Livewire routes found at all: the panel cannot work');

    foreach ($livewire as $uri) {
        expect(preg_match('#^/livewire-[0-9a-f]{8}/#', $uri))
            ->toBe(1, "Livewire route [{$uri}] no longer matches the prefix pattern the guard and the allowlist both use");
    }
});

it('refuses the three vendor routes it mounts and does not serve', function (): void {
    // Two of these answered 500 to an ANONYMOUS GET before this was fixed, reading tables
    // that were never created; the third is a temporary-file preview endpoint with no
    // caller. All three answer the same 404 problem document an unknown path gets -- the
    // same one deliberately, because a distinct status or body would tell a scanner the
    // path is special, and the refusal would become the disclosure.
    //
    // ⚠ THERE WERE FOUR, AND THE FOURTH WAS A DEFECT THIS CASE PINNED AS CORRECT.
    // `upload-file` was refused on the argument that the panel had no file upload; the
    // guard is a prepended GLOBAL middleware, Task 7 then gave /studio/media a file input,
    // and this case asserted the 404 that broke it. A test that pins a defect as correct
    // behaviour is the defect, so the positive assertion at the end of this case is now
    // part of it: that path must be SERVED. StudioMediaUploadTest drives the upload itself.
    $prefix = '';

    foreach (publicRouteUris() as $uri) {
        if (preg_match('#^(/livewire-[0-9a-f]{8})/#', $uri, $m) === 1) {
            $prefix = $m[1];

            break;
        }
    }

    expect($prefix)->not->toBe('', 'no Livewire prefix found');

    // ⚠ THE PATH SET COMES FROM THE GUARD ITSELF (QA L-2). This case carried its own copy
    // of the four paths while `RefuseUnservedVendorRoutes::patterns()` -- whose docblock
    // says "exposed rather than duplicated in the test: a guard whose test carries its own
    // copy of the list is a guard that can disagree with its test" -- had NO caller at all.
    // A sample URI is derived per pattern, so a fifth refused path is driven here the
    // moment it is added to the guard.
    $patterns = RefuseUnservedVendorRoutes::patterns();

    expect($patterns)->toHaveCount(3, 'the guard\'s pattern list changed; the samples below must follow');

    $samples = [
        ['get', '/filament/exports/1/download'],
        ['get', '/filament/imports/1/failed-rows/download'],
        ['get', $prefix.'/preview-file/anything'],
    ];

    // Every sample must match one of the guard's own patterns, and every pattern must be
    // matched by a sample -- so the two lists cannot drift apart silently.
    foreach ($samples as [, $uri]) {
        $path = ltrim($uri, '/');

        expect(array_filter($patterns, static fn (string $p): bool => preg_match($p, $path) === 1))
            ->not->toBe([], "no guard pattern matches the sample [{$uri}]");
    }

    foreach ($patterns as $pattern) {
        expect(array_filter($samples, static fn (array $s): bool => preg_match($pattern, ltrim($s[1], '/')) === 1))
            ->not->toBe([], "the guard pattern [{$pattern}] has no sample driving it");
    }

    foreach ($samples as [$method, $uri]) {
        $response = $this->{$method}($uri);

        expect($response->getStatusCode())->toBe(404, "{$uri} is still served")
            ->and($response->headers->get('Content-Type'))->toContain('application/problem+json');
    }

    // And the routes the panels DO need are untouched: refusing those would leave a panel
    // that renders and does nothing, which is what happened to /studio/media.
    expect($this->get($prefix.'/livewire.js')->getStatusCode())->toBe(200);

    // THE UPLOAD ENDPOINT IS SERVED, asserted here rather than only in the count above: a
    // count tells you the list changed, and this tells you WHICH WAY. An unsigned POST is
    // refused by Livewire's own `abort_unless(hasValidRelativeSignature(), 401)`, so 401 is
    // the endpoint answering for itself; 404 would mean the guard is intercepting it again
    // and the media library is broken in a browser with the suite still green.
    $upload = $this->post($prefix.'/upload-file');

    expect($upload->getStatusCode())
        ->toBe(401, 'the Livewire upload endpoint is refused again: /studio/media cannot receive a file')
        ->and(array_filter($patterns, static fn (string $p): bool => preg_match($p, ltrim($prefix.'/upload-file', '/')) === 1))
        ->toBe([], 'upload-file is back in the guard\'s pattern list');
});

/**
 * GET one path in a child process whose `APP_ENV` this one chooses, and return the status.
 *
 * `array_merge(getenv(), …)` and not a bare array: `proc_open`'s `$env` REPLACES the
 * environment, so a bare array loses `PATH` and everything `.env` resolution needs -- the
 * lesson `PagerIdempotencyTest` and `MemberConcurrencyTest` both record, where a child that
 * produced no output at all read as a result rather than as a broken launch. `PAO_DISABLE`
 * for the reason CLAUDE.md gives: the child's own failure output should be the same artefact
 * for a person and for an agent.
 */
function statusInEnvironment(string $environment, string $path): int
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    $process = proc_open(
        [PHP_BINARY, base_path('tests/Support/get-path-in-another-process.php'), $path],
        $descriptors,
        $pipes,
        base_path(),
        array_merge(getenv(), ['APP_ENV' => $environment, 'PAO_DISABLE' => '1']),
    );

    expect($process)->not->toBeFalse("could not start the probe for APP_ENV={$environment}");

    $out = trim((string) stream_get_contents($pipes[1]));
    $err = trim((string) stream_get_contents($pipes[2]));

    foreach ($pipes as $pipe) {
        fclose($pipe);
    }

    $status = proc_close($process);

    expect($status)->toBe(0, "the probe failed under APP_ENV={$environment}: {$err}")
        ->and($out)->toMatch('/^\d{3}$/', "the probe printed no status under APP_ENV={$environment}: {$out} {$err}");

    return (int) $out;
}

it('serves the passkey harness page in local and testing and NOWHERE else', function (): void {
    // ================================================================================
    // QRPAGER-31. THIS CASE USED TO `grep` bootstrap/app.php, AND THAT IS THE DEFECT.
    // ================================================================================
    //
    // It asserted the `if` as a STRING -- `toContain("if (! app()->environment('production'))
    // {\n                require …")` -- having argued that the environment is global state a
    // running test cannot change for a route table built at boot. The premise is true and the
    // conclusion does not follow: a route table cannot be rebuilt IN this process, so the
    // proof belongs in ANOTHER one, which is exactly what `FixtureScriptGuardTest` does for
    // the two fixture scripts and on the stated grounds that an exit code "cannot be asserted
    // from a grep".
    //
    // What the string assertion cost, measured as four real processes BEFORE the fix:
    //
    //     local 200 · testing 200 · staging 200 · production 404
    //
    // `! environment('production')` admits every name that is not exactly that one, so a
    // staging host served a page with an email/password sign-in form posting to the real
    // `/v1/auth/login`. The case was green throughout, because the characters it was reading
    // were the characters it expected -- a test that reads an `if` goes green for every edit
    // that keeps the spelling and changes the meaning, and for every environment name nobody
    // thought of when the `if` was written.
    //
    // ⚠ `local` AND `testing` ARE THE POSITIVE CONTROLS AND THEY ARE NOT DECORATION. Without
    // a 200 in this list, a probe that was simply broken -- a bad path, a child that cannot
    // boot, a typo in the script name -- would 404 everything and read as the strongest
    // possible pass. The 200s are what make the 404s mean something.
    $served = ['local', 'testing'];
    $refused = ['production', 'staging', 'demo', 'qa', 'preview', 'production-eu'];

    foreach ($served as $environment) {
        expect(statusInEnvironment($environment, '/passkey-harness'))
            ->toBe(200, "the harness is unreachable under APP_ENV={$environment}, where the suite and the ceremony need it");
    }

    // The names beyond `production` are the whole point of the change. They are spelled out
    // rather than generated, because the claim is not "some other name 404s" -- it is that
    // the guard is an ALLOWLIST, and an allowlist is only demonstrated by names that were
    // never enumerated anywhere in the application.
    foreach ($refused as $environment) {
        expect(statusInEnvironment($environment, '/passkey-harness'))
            ->toBe(404, "the WebAuthn harness, a second credential surface, is reachable under APP_ENV={$environment}");
    }
});

it('keeps the harness page in this process route table, as the allowlist above assumes', function (): void {
    // The in-process half, kept for one reason: the allowlist case above lists
    // `/passkey-harness` among the paths served outside `/v1`, and that list is only
    // meaningful while the route is actually registered here. A condition that excluded
    // everything would fail this line rather than pass quietly.
    expect(publicRouteUris())->toContain('/passkey-harness');
});

it('has removed Laravel /up, the welcome page and both storage routes', function (): void {
    // Each of these was a public route outside /v1 at 7f1c8f6. /up duplicated liveness
    // and ran the framework's health checks, which touch dependencies -- the opposite
    // of what §4 › Edge & ops asks of a health check. `storage/{path}` came from the
    // local disk's `serve => true` and included a PUT that writes request content to
    // storage/app/private behind a signed URL.
    expect(publicRouteUris())
        ->not->toContain('/up')
        ->not->toContain('/')
        ->not->toContain('/storage/{path}');
});

it('answers liveness without reading any dependency', function (): void {
    // Proven by taking both dependencies away from the request: if this endpoint
    // touched Postgres or Redis it could not answer with them unreachable, and §4 ›
    // Edge & ops is explicit that a liveness check which sheds with everything else
    // makes the balancer kill a task that is merely busy.
    config([
        'database.connections.pgsql.host' => '127.0.0.1',
        'database.connections.pgsql.port' => '59997',
        'database.redis.default.port' => '59998',
    ]);

    DB::purge('pgsql');
    Redis::purge('default');

    $response = $this->getJson('/healthz');

    $response->assertOk()->assertExactJson(['status' => 'alive']);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('answers readiness 503 with no snapshot, and opens no database connection to do it', function (): void {
    app(ReadinessStore::class)->forget();

    // Pointed at a dead port: if readiness opened a connection this would be a 500,
    // which is R-012's coupling. It must still ANSWER.
    config(['database.connections.pgsql.port' => '59997']);
    DB::purge('pgsql');

    $this->getJson('/readyz')
        ->assertStatus(503)
        ->assertJsonPath('ready', false)
        ->assertJsonPath('reason', 'no readiness snapshot is available')
        // `probe` is gone entirely rather than null (R-031): the capacity figures were
        // readable by anyone, on a group with no limiter.
        ->assertJsonMissingPath('probe')
        ->assertJsonPath('probe_age_seconds', null);
});

it('answers readiness 503 when redis is unreachable, while liveness stays 200', function (): void {
    config(['database.redis.default.port' => '59998']);
    Redis::purge('default');

    $this->getJson('/readyz')->assertStatus(503)->assertJsonPath('ready', false);
    $this->getJson('/healthz')->assertOk();
});

it('answers an unknown path with a problem document and counts it', function (): void {
    $response = $this->getJson('/no-such-path-'.Str::uuid7());

    $response->assertStatus(404)->assertJsonPath('type', ProblemType::NotFound->value);

    expect($response->headers->get('Content-Type'))->toContain('application/problem+json')
        // No tenant middleware on the fallback, deliberately: an unknown path has no
        // tenant to resolve, and answering 401 would tell a scanner which paths exist.
        ->and($response->status())->not->toBe(401);
});

it('rate limits unknown paths on their own tighter bucket — the gate names this clause', function (): void {
    $limit = (int) config('ratelimit.buckets.unknown.per_ip.attempts');

    expect($limit)->toBeGreaterThan(0)
        // Tighter than the API's own bucket: a 404 costs almost nothing to serve and
        // everything to scan with.
        ->and($limit)->toBeLessThan((int) config('ratelimit.buckets.api.per_ip.attempts'))
        // And no per-tenant limit, because an unmatched path carries no tenant.
        ->and(config('ratelimit.buckets.unknown.per_tenant'))->toBeNull();

    $fallback = collect(Route::getRoutes()->getRoutes())
        ->first(fn (RoutingRoute $route): bool => $route->isFallback);

    expect($fallback)->not->toBeNull()
        ->and($fallback?->gatherMiddleware())->toContain(RateLimit::class.':unknown');
});

it('does not carry the drain proof’s slow route in the repository', function (): void {
    // bootstrap/app.php loads routes/drain.php when it exists and the environment is not
    // production. That is the condition the drain proof relies on, and this is the other
    // half of the bargain: the file is mounted into the drain container by compose and
    // must never be committed, or /drain/slow becomes a real public route that holds a
    // worker thread for three seconds.
    expect(is_file(base_path('routes/drain.php')))->toBeFalse(
        'routes/drain.php is committed; it is a mount, not a route file',
    );

    // The source it is mounted FROM does exist, and lives outside routes/ so no loader
    // can pick it up by accident.
    expect(is_file(base_path('docker/drain/routes.php')))->toBeTrue();

    // And it is not in the served surface, which is what actually matters.
    expect(publicRouteUris())->not->toContain('/drain/slow');
});

it('loads the drain route only outside production, when it is mounted', function (): void {
    // Read from bootstrap/app.php rather than exercised, because exercising it would
    // mean writing routes/drain.php into the repository -- which the case above forbids.
    // Both conditions in one expression, so neither can be dropped without the other
    // being noticed.
    $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

    expect($bootstrap)->toContain("! app()->environment('production') && is_file(__DIR__.'/../routes/drain.php')");
});
