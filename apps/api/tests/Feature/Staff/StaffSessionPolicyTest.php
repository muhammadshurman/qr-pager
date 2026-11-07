<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * ================================================================================
 * WHAT THE STAFF SHELL's CREDENTIAL ACTUALLY RESTS ON
 * ================================================================================
 *
 * `QRPAGER-25 §4`: *"Session lifetime and lock behaviour are **asserted**, not configured."*
 *
 * The LOCK half is asserted in three places and none of them is here: the countdown and its
 * "a poll is not activity" property in `apps/web/test/staff-session.test.ts`, the three-step
 * order in `apps/web/test/staff-app.test.tsx`, and the server's own 401 after the lock in
 * `browser/staff.spec.ts`. **This file is the LIFETIME half**, which is the one a client-side
 * test cannot reach: it is configuration, and configuration is exactly the thing the clause
 * says must not be taken on trust.
 *
 * Task 10a is the first task whose PRODUCT SURFACE is a cookie-session browser client. Tasks
 * 5-7 authenticate through sessions too, but their consumers are a test client and two
 * Filament panels; this is the first time a session's lifetime is a property a restaurant's
 * staff experience on a shared device. So the values are pinned here, with the two residuals
 * they leave stated rather than discovered.
 */

it('is served from Redis, and the config default would not work at all', function (): void {
    // ⚠ THE DEFAULT IN `config/session.php` IS `database`, AND THERE IS NO `sessions` TABLE.
    //
    // Measured: `env('SESSION_DRIVER', 'database')` is the line, and the migrations create
    // `pager_sessions` and no `sessions`. So a deployment that does not set the variable
    // boots cleanly, serves `/healthz`, and fails on the first session WRITE -- which is the
    // staff login. `.env.example` sets `redis`, which is why nobody has met it.
    //
    // This case pins the two halves that make the hazard real, so the pairing cannot drift
    // apart silently. The BOOT GUARD that would refuse to serve on an unusable session store
    // is NOT added here -- `BootChecks` is Task 2's control and widening it under a Task 10a
    // ticket is the "fix it while you are in there" move that `§6` Q1 exists to slow down.
    // It is `PLAN.md §5`'s row instead, with an owner and a date.
    expect(config('session.driver'))->not->toBe(
        'database',
        'the session driver is `database` and no `sessions` table exists, so every login would 500',
    );

    $tables = DB::connection('pgsql_owner')->select(
        "select table_name from information_schema.tables where table_schema = 'public' and table_name = 'sessions'",
    );

    expect($tables)->toBe([], 'a `sessions` table now exists, so this case is measuring the wrong hazard');

    // And `.env.example` is what a fresh clone gets, so it is the thing that has to be right.
    $example = file_get_contents(__DIR__.'/../../../.env.example');

    expect($example)->toBeString();
    expect(str_contains((string) $example, 'SESSION_DRIVER=redis'))->toBeTrue(
        '.env.example does not pin the session driver, so a fresh clone takes the unusable default',
    );
});

it('bounds a staff session at two hours, and the residual is named rather than implied', function (): void {
    /*
     * ⚠ 120 MINUTES, AND THE LOCK DOES NOT SHORTEN IT ON THE SERVER. The client locks after
     * fifteen minutes of no human input and destroys the session when it does -- so under
     * ordinary use the window is the idle timeout and not this number.
     *
     * THE RESIDUAL, stated because it is real: a cookie exfiltrated from a shared kitchen
     * device BEFORE the lock fires stays valid for up to two hours, whatever the client then
     * does. Shortening `SESSION_LIFETIME` is not Task 10a's call -- the same value governs
     * the two Filament panels, where a 15-minute expiry during a provisioning wizard would be
     * a different kind of defect -- so it is pinned here and carried as a `§5` row.
     *
     * `expire_on_close` is asserted too: `false` means the session survives the browser
     * closing, which on a shared device is the less safe of the two and is deliberate (a
     * kitchen tablet's browser is never closed). It is the lock that bounds it, which is why
     * these two assertions sit beside each other.
     */
    expect(config('session.lifetime'))->toBe(120);
    expect(config('session.expire_on_close'))->toBeFalse();

    // The cookie's own flags. `http_only` is what keeps the session cookie out of reach of
    // any script on the origin, including one injected into the staff shell -- the staff
    // client never reads it and does not need to, because the browser sends it.
    expect(config('session.http_only'))->toBeTrue();
    expect(config('session.same_site'))->toBe('lax');

    // ⚠ AND `SESSION_SECURE_COOKIE` IS NOT ASSERTED FROM `config()`, deliberately: the suite
    // runs over http, so a secure-only cookie would break every session test and the value is
    // environment-specific by design. What IS assertable is that a fresh clone gets the safe
    // value, which is the half a deployment actually inherits.
    $example = (string) file_get_contents(__DIR__.'/../../../.env.example');

    expect(str_contains($example, 'SESSION_SECURE_COOKIE=true'))->toBeTrue(
        '.env.example does not pin a secure session cookie',
    );
});

it('keeps the XSRF-TOKEN cookie readable by a first-party script, which the staff client needs', function (): void {
    /*
     * The staff client reads `document.cookie` for `XSRF-TOKEN` and sends it back as
     * `X-XSRF-TOKEN`. That works because Laravel writes that cookie with `httpOnly: false`
     * -- measured in `PreventRequestForgery::newCookie()`, seventh argument -- while the
     * SESSION cookie above is `httpOnly: true`.
     *
     * ⚠ THE TWO ARE OPPOSITE ON PURPOSE AND IT IS WORTH ASSERTING, because "make the cookies
     * httpOnly" is a plausible hardening pass that would silently break every write the staff
     * shell makes: the CSRF token would become unreadable, `getTokenFromRequest()` would see
     * nothing, and every POST would be a 419 that names nothing. A test that drives a real
     * request is the only thing that would catch it.
     */
    $tenant = $this->makeTenant();
    $this->actingForTenant($tenant);

    $response = $this->getJson('/v1/auth/me');

    $response->assertStatus(200);

    $cookies = collect($response->headers->getCookies())->keyBy->getName();

    expect($cookies->has('XSRF-TOKEN'))->toBeTrue('no XSRF-TOKEN cookie was issued on a read');
    expect($cookies->get('XSRF-TOKEN')->isHttpOnly())->toBeFalse(
        'the XSRF-TOKEN cookie is httpOnly, so the staff client cannot read it and every write would be a 419',
    );
});
