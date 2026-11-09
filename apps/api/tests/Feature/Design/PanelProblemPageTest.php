<?php

declare(strict_types=1);

use App\Domain\Http\ProblemType;
use App\Domain\Tenancy\TenantRole;
use App\Models\User;
use App\Platform\Http\PanelProblemPage;
use App\Platform\Http\Problem;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\Support\LandingFixtures;

/*
 * ================================================================================
 * THE HTML ERROR BRANCH, AFTER THE §11 ROUND THAT FOUND SEVEN THINGS WRONG WITH IT
 * ================================================================================
 *
 * `PanelWalkTest` owns the 503 case and the disclosure sweep. This file owns what the
 * security review found and what the QA review found untested, which is nearly everything
 * else about the branch:
 *
 *   S-1  `Retry-After` was dropped (document 10, page none) — R-070 chose that number per
 *        SQLSTATE, and §3 row 4 requires it on every mapped 503.
 *   S-2  `wants()` claimed a star-slash-star guard it did not have: the test sent
 *        `X-Requested-With`, which `fetch()` never does. A browser's own image `Accept`
 *        resolved to the PAGE, so the preview asset route answered 2 kB of HTML on its
 *        error path — an endpoint
 *        whose SUCCESS path carries `default-src 'none'` + `nosniff` + DENY + `no-referrer`
 *        by an earlier §11 ruling.
 *   S-3  `Cache-Control` was downgraded from `no-store` to `no-cache`.
 *   S-4  the page carried no CSP, `nosniff`, `X-Frame-Options` or `Referrer-Policy`.
 *   S-6  `wants()` could throw inside the exception renderer, where a throwable escapes to
 *        the 862,104-byte shape the whole control exists to prevent.
 *   H-4  every panel 4xx now returns an HTML page, `guidance()`'s 4xx arm is live, and
 *        NOTHING asserted either its content or its reason-independence — which is the
 *        named invariant `§4.1` on a surface this task created.
 */

/** A platform admin, inserted as the owner because the app role holds no INSERT on the flag. */
function problemAdmin(): User
{
    $id = (string) Str::uuid7();

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Problem Admin',
        'email' => 'problem-'.Str::lower(Str::random(10)).'@example.test',
        'is_platform_admin' => true,
    ]);

    $user = User::query()->findOrFail($id);
    assert($user instanceof User);

    return $user;
}

/** The headers a real browser navigation carries. */
function navigation(): array
{
    return [
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Sec-Fetch-Dest' => 'document',
        'Sec-Fetch-Mode' => 'navigate',
    ];
}

/** Run $work with the app connection pointed at a closed port. */
function withPanelSourceDown(callable $work): mixed
{
    $port = config('database.connections.pgsql.port');

    config(['database.connections.pgsql.port' => 1]);
    DB::purge('pgsql');

    try {
        return $work();
    } finally {
        config(['database.connections.pgsql.port' => $port]);
        DB::purge('pgsql');
    }
}

it('carries the problem’s own Retry-After onto the page — S-1', function (): void {
    // R-070 chooses this per SQLSTATE: a lock is 1s, a connection failure or a saturated
    // pool is 10s. Measured before the fix: the document carried `retry-after: 10` and the
    // page carried NONE, so the panel's outage page was the one 503 on this surface telling
    // no machine client to back off — during exactly the condition where a retry storm is
    // the cascade §4 › Edge & ops exists to prevent.
    $this->actingAs(problemAdmin());

    $page = withPanelSourceDown(fn () => $this->get('/platform', navigation()));
    $document = withPanelSourceDown(fn () => $this->get('/platform', ['Accept' => 'application/json']));

    expect($page->status())->toBe(503)
        ->and((string) $page->headers->get('content-type'))->toContain('text/html')
        // The SAME value the document carries, not merely "a value".
        ->and($page->headers->get('retry-after'))->toBe($document->headers->get('retry-after'))
        ->and($page->headers->get('retry-after'))->toBe('10');
});

it('keeps no-store and the four security headers on the page — S-3, S-4', function (): void {
    $this->actingAs(problemAdmin());

    $page = withPanelSourceDown(fn () => $this->get('/platform', navigation()));

    // `no-store` is PRESENT. Laravel's `response()->view()` merges its own
    // `max-age=0, must-revalidate, no-cache` alongside it; `no-store` is the directive that
    // forbids writing the response to any cache, so the pair is stricter than `no-cache`
    // alone and the downgrade S-3 reported is gone. Asserted as presence rather than as an
    // exact string, because the framework owns the rest of that header.
    expect(str_contains((string) $page->headers->get('cache-control'), 'no-store'))
        ->toBeTrue('the page may be written to a cache');

    $csp = (string) $page->headers->get('content-security-policy');

    expect($csp)->toContain("default-src 'none'")
        ->and($csp)->toContain("style-src 'unsafe-inline'")
        ->and($csp)->toContain("frame-ancestors 'none'")
        ->and($page->headers->get('x-content-type-options'))->toBe('nosniff')
        ->and($page->headers->get('x-frame-options'))->toBe('DENY')
        ->and($page->headers->get('referrer-policy'))->toBe('no-referrer');
});

it('serves the DOCUMENT to everything that did not say it was navigating — S-2', function (): void {
    /*
     * ⚠ THE CASE THE OLD ONE ONLY LOOKED LIKE. It sent `X-Requested-With: XMLHttpRequest`,
     * which `expectsJson()` reads and `fetch()` never sends — so the guard it proved was not
     * the guard a real client needs. These four are real client shapes, and the first is the
     * one that mattered: a browser's own image `Accept` on a binary endpoint.
     */
    LandingFixtures::bucket();

    $admin = problemAdmin();
    $tenant = $this->actingForTenant(role: TenantRole::Owner);

    $cases = [
        'a browser fetching an image' => [
            '/studio/preview/asset/'.Str::uuid7(),
            ['Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8', 'Sec-Fetch-Dest' => 'image'],
            'tenant',
        ],
        'a browser fetching a stylesheet' => [
            '/platform/audit-log',
            ['Accept' => 'text/css,*/*;q=0.1', 'Sec-Fetch-Dest' => 'style'],
            'admin',
        ],
        'fetch() with no Sec-Fetch-Dest of document' => [
            '/platform/audit-log',
            ['Accept' => '*/*', 'Sec-Fetch-Dest' => 'empty', 'Sec-Fetch-Mode' => 'cors'],
            'admin',
        ],
        'curl, which sends no Sec-Fetch headers at all' => [
            '/platform',
            ['Accept' => 'text/html'],
            'admin',
        ],
    ];

    foreach ($cases as $label => [$uri, $headers, $who]) {
        if ($who === 'tenant') {
            $this->actingForTenant(tenantId: $tenant, role: TenantRole::Owner);
        } else {
            $this->actingAs($admin);
        }

        $response = withPanelSourceDown(fn () => $this->get($uri, $headers));
        $type = (string) $response->headers->get('content-type');

        expect(str_contains($type, 'application/problem+json'))
            ->toBeTrue("{$label} got {$type}, so a non-document client was served a page");
    }
});

it('serves the PAGE to a real navigation, so the case above is not "always the document"', function (): void {
    $this->actingAs(problemAdmin());

    $response = withPanelSourceDown(fn () => $this->get('/platform', navigation()));

    expect((string) $response->headers->get('content-type'))->toContain('text/html')
        ->and((string) $response->getContent())->toContain('Wait a minute and reload this page.');
});

it('answers every panel 4xx with the SAME page, whatever the reason — H-4, §4.1', function (): void {
    /*
     * ⚠ THE NAMED INVARIANT, ON THE SURFACE THIS TASK CREATED. `§4.1`: a refusal may say what
     * to do; it may not say what exists. `guidance()` is chosen by STATUS CLASS and never by
     * `ProblemType` case, which is the mechanism — and nothing asserted it, so the clause was
     * enforced by a comment.
     *
     * Four principals, three of them refused for DIFFERENT reasons, all on panel paths with a
     * real navigation. The pages must be BYTE-IDENTICAL per status: that is the strongest
     * form of "it does not say what exists", and it is the one a per-reason sentence breaks.
     *
     * ⚠ AND SINCE QRPAGER-38 THE ANONYMOUS CASE IS NO LONGER ONE OF THE PAGES — `R-253`. A
     * signed-out NAVIGATION to a panel path now answers 302 to the staff sign-in screen,
     * because the panels have no login form of their own and the page it used to get was a
     * dead end with nothing on it to press. It is still driven here, as a redirect: the
     * byte-identical claim is about what a REFUSAL PAGE says, and a 302 says nothing at all,
     * which is the strongest form of the same property. `PanelSignInRedirectTest` owns the
     * redirect; this asserts that the page is not what an anonymous navigation gets, so a
     * revert cannot pass both files.
     */
    LandingFixtures::bucket();

    $admin = problemAdmin();
    $tenant = $this->actingForTenant(role: TenantRole::Owner);

    $bodies = [];

    // 302 — anonymous, since `R-253`. NOT in $bodies: there is no page to compare.
    $this->app['auth']->forgetGuards();
    $this->flushSession();
    $anonymous = $this->get('/platform/audit-log', navigation());

    expect($anonymous->status())->toBe(302, 'an anonymous navigation did not reach sign-in')
        ->and((string) $anonymous->getContent())->not->toContain('ask whoever set up your account');

    // 403 — a tenant principal on the platform panel.
    $this->actingForTenant(tenantId: $tenant, role: TenantRole::Owner);
    $crossed = $this->get('/platform', navigation());
    $bodies['403 tenant on platform'] = [$crossed->status(), (string) $crossed->getContent()];

    // 403 — a platform admin on the studio panel. A DIFFERENT reason, same answer.
    $this->actingAs($admin);
    $other = $this->get('/studio', navigation());
    $bodies['403 admin on studio'] = [$other->status(), (string) $other->getContent()];

    foreach ($bodies as $label => [$status, $body]) {
        expect($status)->toBeGreaterThanOrEqual(400, "{$label} was not refused")
            ->and($status)->toBeLessThan(500)
            ->and($body)->toContain('<!DOCTYPE html>')
            // The 4xx sentence, which no test reached before.
            ->and($body)->toContain('ask whoever set up your account')
            // And NOT the 5xx one: the status-class split is the mechanism, so a page
            // carrying both would mean the arms had merged.
            ->and(str_contains($body, 'This is on our side'))
            ->toBeFalse("{$label} carries the 5xx sentence");
    }

    // ⚠ BYTE-IDENTICAL ACROSS THE TWO 403s, whose reasons differ completely — one is a
    // tenant on the platform panel, the other a platform admin on the studio panel.
    expect($bodies['403 tenant on platform'][1])->toBe($bodies['403 admin on studio'][1]);
    expect($bodies['403 tenant on platform'][0])->toBe($bodies['403 admin on studio'][0]);
});

it('fails CLOSED when the panel registry cannot be read — S-6', function (): void {
    // ⚠ THE BRANCH'S ENTIRE SAFETY ARGUMENT RESTS ON THIS. `wants()` runs inside the
    // exception renderer and calls `Filament::getPanels()`; a throwable escaping it escapes
    // to Laravel's own renderer, which at APP_DEBUG=true is the 862,104-byte shape
    // `ProblemRenderer` exists to prevent. Driven rather than argued: the registry is
    // replaced with one that throws, and `wants()` must answer false — the document, which
    // is always safe.
    // ⚠ `Filament::swap()` AND NOT `$this->app->extend('filament', …)`. The facade had
    // already resolved its root by the time the extend ran, so the real manager answered and
    // `wants()` returned TRUE — the case reported a pass for the unfixed shape. A facade's
    // resolved instance is replaced with `swap()`, which is the only form that reaches the
    // call this method actually makes.
    Filament::swap(new class
    {
        /** @return array<string, mixed> */
        public function getPanels(): array
        {
            throw new RuntimeException('the panel registry is unavailable');
        }
    });

    $page = app(PanelProblemPage::class);
    $request = Request::create('/platform', 'GET', server: [
        'HTTP_ACCEPT' => 'text/html',
        'HTTP_SEC_FETCH_DEST' => 'document',
    ]);

    expect($page->wants($request))->toBeFalse();
});

it('chooses its guidance by status CLASS and by nothing else', function (): void {
    // The mechanism behind the equality above, asserted directly so a reviewer can see WHICH
    // line to change to break it. Every 4xx type gets one sentence and every 5xx type the
    // other -- no `ProblemType` case appears in `guidance()` at all.
    $page = app(PanelProblemPage::class);

    $sentences = [];

    foreach (ProblemType::cases() as $type) {
        $response = $page->respond(new Problem($type, '/platform'));
        $body = (string) $response->getContent();
        $class = $type->status() >= 500 ? '5xx' : '4xx';

        preg_match('#<p>(.*?)</p>#s', $body, $match);
        $sentences[$class][$type->value] = trim((string) ($match[1] ?? ''));
    }

    // Both classes are represented, or "one sentence per class" is true of one class.
    expect(array_keys($sentences))->toHaveCount(2);

    foreach ($sentences as $class => $perType) {
        expect(count(array_unique($perType)))
            ->toBe(1, "the {$class} types do not share one sentence: ".implode(' | ', array_unique($perType)));
    }

    // And the two classes differ, or the split does nothing.
    expect(reset($sentences['4xx']))->not->toBe(reset($sentences['5xx']));
});

it('falls back to the document when the page itself cannot render — security 1', function (): void {
    /*
     * ⚠ `respond()` DID MORE WORK THAN `wants()` AND CARRIED NO GUARD. Measured by the §11
     * security round: with the view moved aside, a real navigation got `HTTP/1.1 500`, a
     * **0-byte body**, and none of the six headers — the throwable escaped to
     * `handleShutdown` and the sanitised Problem was discarded. Any partial deploy or
     * unwritable `storage/framework/views` turns every panel error, including the 503 this
     * feature exists for, into a bodyless 500 with no retry hint.
     *
     * Driven by making the view factory throw, which is the same shape as making the panel
     * registry throw for `wants()`.
     */
    View::swap(new class
    {
        public function make(mixed ...$arguments): never
        {
            throw new RuntimeException('the view factory is unavailable');
        }

        public function share(mixed ...$arguments): void {}

        public function exists(mixed ...$arguments): bool
        {
            return true;
        }
    });

    $problem = new Problem(ProblemType::Unavailable, '/platform', retryAfter: 10);
    $response = app(PanelProblemPage::class)->respond($problem);

    // The DOCUMENT, with everything the Problem carries -- not a bodyless 500.
    expect($response->getStatusCode())->toBe(503)
        ->and((string) $response->headers->get('content-type'))->toContain('application/problem+json')
        ->and($response->headers->get('retry-after'))->toBe('10')
        ->and(str_contains((string) $response->headers->get('cache-control'), 'no-store'))->toBeTrue()
        ->and(strlen((string) $response->getContent()))->toBeGreaterThan(80);
});

/*
 * ===================================================================================
 * R-195's THREE UNCOVERED ARMS. Owned by Task 9, closed here.
 * ===================================================================================
 *
 * The row, verbatim: *"the `Sec-Fetch-Mode: navigate` arm is never exercised ALONE (both test
 * helpers send dest *and* mode, and the negative case sends `dest=empty, mode=cors`);
 * `! acceptsHtml()` has no case of its own; and `retryAfter === null` -- a 4xx page carrying
 * no `Retry-After` -- is asserted nowhere. … No defect is reachable through any of them
 * today, which is why this is a row and not a fix: the behaviour is right and the coverage is
 * not."* Its owner cell asks for *"one case per arm, which is three short cases."*
 *
 * ⚠ AND THE ROW'S SIBLING, `R-194`, IS MEASURED SOMEWHERE ELSE AND SAYS SO. Whether a real
 * engine sends `Sec-Fetch-Dest` at all is not answerable from PHP, and `R-194` is owned by
 * Task 9 *"with the E2E harness"* for that reason. `browser/engines.spec.ts` answers it in
 * Chromium, Firefox and WebKit -- all three send `document` on a genuine navigation -- so
 * that row is NARROWED by measurement rather than closed by assertion. These three cases are
 * the PHP half and make no claim about engines.
 */

it('serves the page on Sec-Fetch-Mode ALONE, with no Sec-Fetch-Dest — R-195', function (): void {
    // The arm `wantsPage()` spells as `$destination !== 'document' && $mode !== 'navigate'`:
    // either header on its own is enough. Both existing helpers send both, so this arm had
    // never been taken by itself -- a reader could have deleted the `$mode` half and every
    // test would still have passed.
    $this->actingAs(problemAdmin());

    $page = withPanelSourceDown(fn () => $this->get('/platform', [
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Sec-Fetch-Mode' => 'navigate',
    ]));

    expect($page->status())->toBe(503)
        ->and((string) $page->headers->get('content-type'))->toContain('text/html');
});

it('serves the page on Sec-Fetch-Dest ALONE, with no Sec-Fetch-Mode — R-195', function (): void {
    // The mirror of the case above, so neither half of that `&&` can be removed quietly.
    // WebKit 27.2 sends `Sec-Fetch-Dest` and omits `Sec-Fetch-User`, measured in
    // `browser/engines.spec.ts`, so an engine sending a partial set is a real shape.
    $this->actingAs(problemAdmin());

    $page = withPanelSourceDown(fn () => $this->get('/platform', [
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Sec-Fetch-Dest' => 'document',
    ]));

    expect($page->status())->toBe(503)
        ->and((string) $page->headers->get('content-type'))->toContain('text/html');
});

it('refuses the page to a client that does NOT accept html — R-195', function (): void {
    // `! acceptsHtml()` had no case of its own: every negative case so far failed on the
    // Sec-Fetch arm instead, so this early return was reachable only in principle. An
    // `Accept` that names one non-html type and nothing else is what a binary client sends.
    $this->actingAs(problemAdmin());

    $response = withPanelSourceDown(fn () => $this->get('/platform', [
        'Accept' => 'image/png',
        'Sec-Fetch-Dest' => 'document',
        'Sec-Fetch-Mode' => 'navigate',
    ]));

    // The machine-readable form, even though every OTHER signal said "navigation" -- which is
    // the point: a client that cannot read html must not be sent 2 kB of it.
    expect($response->status())->toBe(503)
        ->and((string) $response->headers->get('content-type'))->toContain('application/problem+json');
});

it('puts NO Retry-After on a 4xx page, because there is nothing to come back for — R-195', function (): void {
    /**
     * The `retryAfter === null` arm. A 503 carries one (asserted at the top of this file); a
     * 403 must not, and that was asserted nowhere -- so a change making `Retry-After`
     * unconditional would have passed every test here while telling a browser to retry a
     * refusal.
     *
     * A 403 is reached without taking the database down: a signed-in platform admin asking
     * for the STUDIO panel, which they hold no membership for.
     */
    $this->actingAs(problemAdmin());

    $page = $this->get('/studio', navigation());

    expect($page->status())->toBeGreaterThanOrEqual(400)
        ->and($page->status())->toBeLessThan(500)
        ->and($page->headers->has('retry-after'))->toBeFalse();
});
