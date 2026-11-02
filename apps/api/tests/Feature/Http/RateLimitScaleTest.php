<?php

declare(strict_types=1);

/*
 * ================================================================================
 * `RATELIMIT_SCALE` MULTIPLIES, AND ITS DEFAULT IS 1 — SO PRODUCTION IS THE WRITTEN NUMBER
 * ================================================================================
 *
 * `config/ratelimit.php` carries every bound at its production value and multiplies each by
 * `RATELIMIT_SCALE`. The scale exists for one caller — the browser suite's own stack, which
 * drives ~390 cases from a single IP and crossed `auth-session`'s 60-per-60-s window partway
 * through, so the API answered **429** and the staff shell rendered *"Something went wrong on
 * our side"*. Three red CI runs and two red local ones were reported as a sixty-second timeout
 * before a probe in `browser/support/e2e.ts` named it.
 *
 * ⚠ THIS FILE IS THE CONTROL ON THAT MECHANISM, and it is the half a `RATELIMIT_SCALE=20` in a
 * test script cannot provide: an env knob over a security bound is one `.env` line away from
 * being the production value, and nothing would say so. Two things are asserted:
 *
 *   1. **the default is 1**, so every number in that file is the effective number, and
 *   2. the scale really multiplies, so the browser stack's 20x is not a no-op that leaves the
 *      suite measuring the limiter anyway.
 *
 * `RateLimitTest` is unchanged and still drives the buckets at scale 1 — `R-063`'s clause that
 * a limit is proven by being driven rather than by being configured.
 */

/** The config file, re-evaluated under a given environment. */
function ratelimitConfig(?string $scale): array
{
    $previous = getenv('RATELIMIT_SCALE');

    if ($scale === null) {
        putenv('RATELIMIT_SCALE');
    } else {
        putenv('RATELIMIT_SCALE='.$scale);
    }

    try {
        // `require`, not `require_once`: the file RETURNS an array and must be evaluated again
        // under the new environment. `require_once` would answer `true` on the second call and
        // the test would compare the first result with itself.
        /** @var array<string, mixed> $config */
        $config = require config_path('ratelimit.php');

        return $config;
    } finally {
        if ($previous === false) {
            putenv('RATELIMIT_SCALE');
        } else {
            putenv('RATELIMIT_SCALE='.$previous);
        }
    }
}

it('defaults to 1, so every bound in the file is the effective bound', function (): void {
    $config = ratelimitConfig(null);

    // The three the staff surface and the browser suite actually meet, as exact values. A
    // scale that silently defaulted to anything else would make every number in that file a
    // suggestion.
    expect($config['buckets']['api']['per_ip']['attempts'])->toBe(120);
    expect($config['buckets']['auth-session']['per_ip']['attempts'])->toBe(60);
    expect($config['buckets']['auth-login']['per_ip']['attempts'])->toBe(10);

    // And the window is untouched by the scale: it multiplies attempts, never seconds. A
    // scaled window would change what the bound MEANS rather than how much of it there is.
    expect($config['buckets']['auth-session']['per_ip']['seconds'])->toBe(60);
});

it('is what the APPLICATION resolved, not just what the file would return', function (): void {
    // ⚠ THE CASE A FILE-ONLY TEST WOULD MISS, and `PasskeyOrigins` is this project's precedent
    // for it: a published config that was read and then overridden looked authoritative and
    // was inert. So the resolved container value is asserted too, and it must equal the
    // unscaled default in an ordinary test run.
    expect(config('ratelimit.buckets.auth-session.per_ip.attempts'))->toBe(60);
    expect(config('ratelimit.buckets.api.per_ip.attempts'))->toBe(120);
});

it('multiplies every bound when the browser stack sets it', function (): void {
    $config = ratelimitConfig('20');

    // 20x is what `tools/browsers/bin/e2e` passes. Asserted as exact products rather than as
    // "larger", so a scale that applied to one bucket and not the others fails here.
    expect($config['buckets']['auth-session']['per_ip']['attempts'])->toBe(1200);
    expect($config['buckets']['api']['per_ip']['attempts'])->toBe(2400);
    expect($config['buckets']['auth-login']['per_ip']['attempts'])->toBe(200);
    expect($config['buckets']['api']['per_tenant']['attempts'])->toBe(12000);

    // Windows unchanged, again, under the scale that is actually used.
    expect($config['buckets']['auth-login']['per_ip']['seconds'])->toBe(60);
});

it('refuses to weaken a bound, whatever the environment says', function (): void {
    /*
     * ⚠ THE DIRECTION MATTERS AND IS CLAMPED. `max(1, …)` means a 0, a negative number or a
     * non-numeric string cannot REDUCE a bound — the worst an environment can do is leave it
     * at the written value. A knob that could quietly halve a rate limit is a knob that turns
     * a security control into a configuration accident.
     */
    foreach (['0', '-5', 'nonsense', ''] as $hostile) {
        $config = ratelimitConfig($hostile);

        expect($config['buckets']['auth-session']['per_ip']['attempts'])
            ->toBe(60, "RATELIMIT_SCALE='{$hostile}' changed a bound");
    }
});
