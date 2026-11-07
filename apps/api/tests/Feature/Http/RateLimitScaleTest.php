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

/*
 * ================================================================================
 * R-234 — THE SCALE IS IGNORED OUTSIDE `local` AND `testing`
 * ================================================================================
 *
 * The two cases above pin the DEFAULT at 1 and prove the scale multiplies. Neither says
 * anything about a production `.env` that carries the value — which is exactly the exposure
 * `R-234` records: *"ONE `.env` LINE IN PRODUCTION RELAXES THE LOGIN BOUND BY THE SAME FACTOR
 * AS THE POLLING ONE."* At the 20 the browser stack sets, `auth-login` became 200/min/IP in
 * front of a bcrypt, `auth-2fa` 100/min/IP in front of a six-digit code and `devices-issue`
 * 200/min/IP in front of a credential mint.
 *
 * Accepted 2026-11-03 from Task 10's scoped `§11` security review; fixed at Task 12, its
 * named owner. The row's proof-of-fix is quoted in the second case below.
 */

/**
 * The config file, re-evaluated under a chosen APP_ENV as well as a chosen scale.
 *
 * @return array<string, mixed>
 */
function ratelimitConfigIn(string $environment, string $scale): array
{
    /*
     * ⚠ ALL THREE SOURCES, AND THE FIRST DRAFT OF THIS HELPER SET ONLY `putenv()` — WHICH
     * FAILED LOUDLY AND IS WORTH RECORDING, BECAUSE THE SAME SLIP IN THE OTHER DIRECTION WOULD
     * HAVE BEEN SILENT.
     *
     * Laravel reads `env()` through a repository whose adapter chain consults `$_SERVER` and
     * `$_ENV` BEFORE the process environment. `phpunit.xml` declares
     * `<env name="APP_ENV" value="testing"/>`, and PHPUnit writes that into `$_SERVER` and
     * `$_ENV` as well as through `putenv()` — so a `putenv('APP_ENV=production')` here was
     * simply outranked and the file kept resolving `testing`. Measured: the first run of the
     * case below reported *"Failed asserting that 200 is identical to 10"*, i.e. the scale was
     * still being honoured.
     *
     * `RATELIMIT_SCALE` is NOT in `phpunit.xml`, which is why `ratelimitConfig()` above works
     * with `putenv()` alone and why the gap was not visible until a second variable was added.
     *
     * The direction matters: this slip made a real fix look broken. Had the case been written
     * the other way round — asserting 200 under `local` — it would have passed for the wrong
     * reason and certified nothing.
     */
    $previous = [
        'putenv' => getenv('APP_ENV'),
        'server' => $_SERVER['APP_ENV'] ?? null,
        'env' => $_ENV['APP_ENV'] ?? null,
    ];

    putenv('APP_ENV='.$environment);
    $_SERVER['APP_ENV'] = $environment;
    $_ENV['APP_ENV'] = $environment;

    try {
        return ratelimitConfig($scale);
    } finally {
        if ($previous['putenv'] === false) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV='.$previous['putenv']);
        }

        foreach (['server' => '_SERVER', 'env' => '_ENV'] as $key => $global) {
            if ($previous[$key] === null) {
                unset($GLOBALS[$global]['APP_ENV']);
            } else {
                $GLOBALS[$global]['APP_ENV'] = $previous[$key];
            }
        }
    }
}

it('ignores the scale under every environment name that is not local or testing', function (): void {
    // ⚠ AN ALLOWLIST, PROVEN WITH NAMES THAT ARE ENUMERATED NOWHERE IN THE APPLICATION, and
    // that is `QRPAGER-31`'s lesson rather than thoroughness for its own sake. The harness
    // page's guard was `! app()->environment('production')`, which reads as "not production"
    // and admits every other name: measured, `staging` served a sign-in form posting to the
    // real /v1/auth/login, and the test was green throughout because it was reading the `if`.
    //
    // So `demo`, `qa`, `preview` and `production-eu` are here deliberately. The claim is not
    // "some other name is refused" -- it is that the condition is an ALLOWLIST, and only a
    // name nobody wrote down can demonstrate that.
    $refused = ['production', 'staging', 'demo', 'qa', 'preview', 'production-eu'];

    foreach ($refused as $environment) {
        $config = ratelimitConfigIn($environment, '20');

        expect($config['buckets']['auth-login']['per_ip']['attempts'])
            ->toBe(10, "RATELIMIT_SCALE is honoured under APP_ENV={$environment}: a bcrypt is behind this bound")
            ->and($config['buckets']['auth-2fa']['per_ip']['attempts'])
            ->toBe(5, "a six-digit code's bound is relaxed under APP_ENV={$environment}")
            ->and($config['buckets']['devices-issue']['per_ip']['attempts'])
            ->toBe(10, "a credential mint's bound is relaxed under APP_ENV={$environment}");
    }

    // ⚠ THE POSITIVE CONTROLS, AND THEY ARE NOT DECORATION. Without them a condition that
    // refused EVERY environment -- including the one the browser suite needs -- would read as
    // the strongest possible pass here, and the e2e stack would be back to measuring the
    // limiter instead of the application (`R-224`'s episode). The two 200s are what make the
    // 10s above mean something.
    foreach (['local', 'testing'] as $environment) {
        expect(ratelimitConfigIn($environment, '20')['buckets']['auth-login']['per_ip']['attempts'])
            ->toBe(200, "the browser suite cannot raise the bound under APP_ENV={$environment}");
    }
});

it('stays at 10 in a REAL production process carrying RATELIMIT_SCALE=20 — the row’s own proof of fix', function (): void {
    /*
     * ⚠ WHY A CHILD PROCESS, when the case above already drives six environment names.
     *
     * The case above re-`require`s the config FILE, which proves what the file returns. It
     * cannot prove what a booted application resolved, because `APP_ENV` is read by the config
     * loader before any test's first line runs — and `R-234`'s proof-of-fix is written about a
     * process, not a file: *"auth-login stays at 10 with `RATELIMIT_SCALE=20` and
     * `APP_ENV=production`."*
     *
     * It also closes the one thing this fix genuinely cannot decide from inside the file: a
     * config CACHED under `local` and shipped would carry the scaled numbers. That is a
     * deployment fault rather than a repository one, and the only instrument that would see it
     * is a real process, which is this one.
     *
     * `array_merge(getenv(), …)` and not a bare array, for the reason `RouteSurfaceTest` and
     * `MemberConcurrencyTest` both record: `proc_open`'s `$env` REPLACES the environment, so a
     * bare array loses `PATH` and everything `.env` resolution needs, and a child that
     * produced no output at all would read as a result.
     */
    $read = function (string $environment, string $scale, string $key): string {
        $process = proc_open(
            [PHP_BINARY, base_path('tests/Support/get-config-in-another-process.php'), $key],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            array_merge(getenv(), [
                'APP_ENV' => $environment,
                'RATELIMIT_SCALE' => $scale,
                'PAO_DISABLE' => '1',
            ]),
        );

        expect($process)->not->toBeFalse("could not start the probe for APP_ENV={$environment}");

        $out = trim((string) stream_get_contents($pipes[1]));
        $err = trim((string) stream_get_contents($pipes[2]));

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        expect(proc_close($process))
            ->toBe(0, "the probe failed under APP_ENV={$environment}: {$err}");

        return $out;
    };

    // The child really is production, read from the child rather than assumed: without this a
    // mis-passed variable reverses the proof in silence.
    expect($read('production', '20', 'app.env'))->toBe('production');

    expect($read('production', '20', 'ratelimit.buckets.auth-login.per_ip.attempts'))
        ->toBe('10', 'a production process honours RATELIMIT_SCALE: the login bound is relaxed 20x');

    expect($read('production', '20', 'ratelimit.buckets.auth-2fa.per_ip.attempts'))
        ->toBe('5', 'a production process relaxes the second-factor bound');

    // THE POSITIVE CONTROL, in a real process too: a probe that was simply broken would report
    // the unscaled number for every environment and read as the strongest possible pass.
    expect($read('local', '20', 'ratelimit.buckets.auth-login.per_ip.attempts'))
        ->toBe('200', 'the probe reports the unscaled bound even under local: it is measuring nothing');
});

it('refuses to read a config key that could carry a secret, which is this task’s own invariant', function (): void {
    // The instrument above boots the real application with the real `.env`, so one dotted
    // string separates `database.connections.pgsql.password` from a CI log. Task 12's named
    // invariant is that no surface discloses a secret; a test helper is a surface.
    $process = proc_open(
        [PHP_BINARY, base_path('tests/Support/get-config-in-another-process.php'), 'database.connections.pgsql.password'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        base_path(),
        array_merge(getenv(), ['PAO_DISABLE' => '1']),
    );

    $out = trim((string) stream_get_contents($pipes[1]));
    $err = trim((string) stream_get_contents($pipes[2]));

    foreach ($pipes as $pipe) {
        fclose($pipe);
    }

    expect(proc_close($process))->not->toBe(0, 'the probe printed a database password')
        ->and($out)->toBe('', 'the probe printed something for a refused key')
        ->and($err)->toContain('readable prefixes');
});
