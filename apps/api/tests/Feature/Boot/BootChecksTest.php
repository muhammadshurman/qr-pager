<?php

declare(strict_types=1);

use App\Platform\Boot\BootChecks;
use App\Platform\Boot\RefuseUnsafeBoot;
use App\Platform\Database\DatabaseRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Octane\Events\WorkerStarting;
use Tests\Support\Composition;

/*
 * The gate's FIRST clause -- "boot refuses an unsafe DB role (exit 1, reason logged)"
 * -- plus the four other §6.4 clauses that share the same moment: D8 (sslmode), D9
 * (owner/root credentials, which is also H-C's second Task 2 clause and R-015's real
 * control), R-020 (a session-scoped tenant setting) and APP_DEBUG.
 *
 * Each clause is driven on its own, and one case per clause asserts the OTHERS stay
 * quiet: a boot check that reports five reasons for one fault tells an operator
 * nothing.
 */

beforeEach(function (): void {
    // This environment is `testing`, where privileged credentials and a weak sslmode
    // are exempt by design -- the suite migrates as the owner and local Postgres has
    // no TLS. Each case below sets the environment it is actually about. APP_DEBUG is
    // false unless a case says otherwise, because .env ships it true and the debug
    // clause would otherwise fire in every case.
    config(['app.debug' => false]);
});

it('passes on a correctly configured process, so the refusals below mean something', function (): void {
    expect(app(BootChecks::class)->failures())->toBe([]);
});

it('refuses an unsafe runtime role, which is H-C’s boot-time clause', function (): void {
    // qrpager_root is rolsuper and rolbypassrls: FORCE row level security does not
    // apply to it at all, so every withTenant() block on this connection would be
    // unscoped while reading as scoped. That is H-C, and Task 1 left the boot half to
    // this task.
    $failures = app(BootChecks::class)->runtimeRoleIsSafe('pgsql_root');

    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toStartWith(BootChecks::UNSAFE_RUNTIME_ROLE)
        ->and($failures[0])->toContain('qrpager_root')
        ->and($failures[0])->toContain('SUPERUSER');
});

it('names an unverifiable role as unsafe rather than passing it', function (): void {
    config(['database.connections.pgsql_unreachable' => array_merge(
        (array) config('database.connections.pgsql'), ['port' => 1],
    )]);

    $failures = app(BootChecks::class)->runtimeRoleIsSafe('pgsql_unreachable');

    // "Cannot tell whether row level security applies" must never read as "it does" --
    // the same rule readState()'s attribute defaults follow.
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toStartWith(BootChecks::UNSAFE_RUNTIME_ROLE)
        ->and($failures[0])->toContain('could not be verified');
});

it('refuses a process that can resolve owner or root credentials — D9, R-015', function (): void {
    app()->detectEnvironment(fn () => 'production');

    $failures = app(BootChecks::class)->privilegedCredentialsAreAbsent();

    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toStartWith(BootChecks::PRIVILEGED_CREDENTIALS_RESOLVABLE)
        ->and($failures[0])->toContain('R-015');
});

it('sees credentials baked into cached config, not only in the environment', function (): void {
    app()->detectEnvironment(fn () => 'production');

    // The hole an environment-only check leaves: config:cache evaluates
    // config/database.php while .env is loaded and bakes the result in, after which
    // env() returns null at runtime. Simulated by clearing the variables and leaving
    // the resolved config as it is -- which is exactly the state a cached deployment
    // is in.
    putenv('DB_OWNER_PASSWORD');
    putenv('DB_ROOT_PASSWORD');
    unset($_ENV['DB_OWNER_PASSWORD'], $_SERVER['DB_OWNER_PASSWORD']);
    unset($_ENV['DB_ROOT_PASSWORD'], $_SERVER['DB_ROOT_PASSWORD']);

    $failures = app(BootChecks::class)->privilegedCredentialsAreAbsent();

    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toContain('database.connections.pgsql_owner.password');

    // And with the config cleared too, it passes -- so the case above is about the
    // config half and not about something else being wrong.
    config([
        'database.connections.pgsql_owner.password' => '',
        'database.connections.pgsql_root.password' => '',
        'database.connections.pgsql_bootstrap.password' => '',
    ]);

    expect(app(BootChecks::class)->privilegedCredentialsAreAbsent())->toBe([]);
});

it('refuses an sslmode that can silently downgrade to plaintext — D8', function (): void {
    app()->detectEnvironment(fn () => 'production');

    // Every pgsql connection, not just the default one. .env ships DB_SSLMODE=disable
    // and config/database.php shares it across all five, so a test that moved only
    // `pgsql` would have been testing the four it left behind -- which is how the first
    // version of this case failed, and the check was right.
    $pgsql = ['pgsql', 'pgsql_owner', 'pgsql_worker', 'pgsql_root', 'pgsql_bootstrap'];
    $setAll = static function (string $mode) use ($pgsql): void {
        foreach ($pgsql as $name) {
            config(["database.connections.{$name}.sslmode" => $mode]);
        }
    };

    foreach (['disable', 'allow', 'prefer'] as $mode) {
        $setAll($mode);

        $failures = app(BootChecks::class)->sslmodeIsNotWeak();

        expect($failures)->toHaveCount(1, "sslmode={$mode} was accepted")
            ->and($failures[0])->toStartWith(BootChecks::WEAK_SSLMODE)
            ->and($failures[0])->toContain('pgsql='.$mode);

        // All five named in the one reason, so an operator fixes the set and not the
        // first entry.
        foreach ($pgsql as $name) {
            expect($failures[0])->toContain("{$name}={$mode}");
        }
    }

    foreach (['require', 'verify-ca', 'verify-full'] as $mode) {
        $setAll($mode);

        expect(app(BootChecks::class)->sslmodeIsNotWeak())->toBe([], "sslmode={$mode} was refused");
    }
});

/*
 * Security finding #2. Three ways to get trusted proxies wrong, all three refused, and
 * the exemption for local/testing asserted so the suite's own exemption is deliberate.
 */

it('refuses to serve with no trusted proxy configured, outside local and testing', function (): void {
    app()->detectEnvironment(fn () => 'production');
    config(['trustedproxy.proxies' => null]);

    $failures = app(BootChecks::class)->trustedProxiesAreSound();

    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toStartWith(BootChecks::UNTRUSTED_PROXY_CONFIGURATION)
        // The remedy is in the message, because the person reading it is holding a
        // container that will not start.
        ->and($failures[0])->toContain('TRUSTED_PROXIES');
});

it('refuses a catch-all, which is worse than refusing an empty value', function (string $catchAll): void {
    app()->detectEnvironment(fn () => 'production');
    config(['trustedproxy.proxies' => $catchAll]);

    $failures = app(BootChecks::class)->trustedProxiesAreSound();

    // Laravel maps both spellings to 0.0.0.0/0 and ::/0, so every peer becomes a
    // trusted proxy and X-Forwarded-For is whatever the caller sent -- the per-IP
    // limit is then bypassed by rotating one header.
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toStartWith(BootChecks::UNTRUSTED_PROXY_CONFIGURATION)
        ->and($failures[0])->toContain('every peer');
})->with(['*', '**']);

it('refuses an entry that does not parse, because Symfony would leave it inert', function (): void {
    app()->detectEnvironment(fn () => 'production');

    // One good entry and one typo. The good entry would make this "work" while the
    // typo quietly does nothing, which is the failure mode worth refusing: IpUtils
    // never matches a malformed entry and nothing says so.
    config(['trustedproxy.proxies' => '10.0.0.0/8, 10.0.0.0/33, not-an-address']);

    $failures = app(BootChecks::class)->trustedProxiesAreSound();

    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toStartWith(BootChecks::UNTRUSTED_PROXY_CONFIGURATION)
        ->and($failures[0])->toContain('10.0.0.0/33')
        ->and($failures[0])->toContain('not-an-address');
});

it('accepts a CIDR, a bare address, both families and the REMOTE_ADDR token', function (): void {
    app()->detectEnvironment(fn () => 'production');

    foreach (['10.0.0.0/8', '10.0.0.7', '2001:db8::/32', '2001:db8::1', 'REMOTE_ADDR', '10.0.0.0/8,REMOTE_ADDR'] as $value) {
        config(['trustedproxy.proxies' => $value]);

        expect(app(BootChecks::class)->trustedProxiesAreSound())->toBe([], "[{$value}] was refused");
    }
});

it('exempts local and testing, where the peer is the client', function (string $environment): void {
    app()->detectEnvironment(fn () => $environment);
    config(['trustedproxy.proxies' => null]);

    // No proxy sits in front of either, so trusting nothing is the correct answer and
    // the suite is not asked to configure one.
    expect(app(BootChecks::class)->trustedProxiesAreSound())->toBe([]);
})->with(['local', 'testing']);

it('refuses to serve on a connection carrying a session-scoped tenant setting — R-020', function (): void {
    $tenant = (string) Str::uuid7();

    // The state C2 calls a hard failure, produced through the guard's one named
    // exception. There is no code path in TenantContext that can produce it, which is
    // why the helper exists at all.
    Composition::setSessionGuc(Composition::APP, DatabaseRoles::guc('tenant'), $tenant);

    $failures = app(BootChecks::class)->connectionCarriesNoTenantSetting('pgsql');

    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toStartWith(BootChecks::SESSION_SCOPED_TENANT_SETTING)
        ->and($failures[0])->toContain('ALTER ROLE')
        // The value is deliberately absent: it is a tenant id this process has no
        // claim to, and the refusal does not depend on what it is.
        ->and($failures[0])->not->toContain($tenant);
});

it('does not mistake a live transaction-local scope for a session-scoped one', function (): void {
    $tenant = $this->makeTenant();

    // Inside a scope the setting IS in force, and it is transaction-local, so it is
    // not what R-020 is about. The check is only ever run at boot, where no
    // transaction is open -- this pins that the distinction is real rather than
    // assumed, by asking inside one.
    withTenant($tenant, function (): void {
        expect(app(BootChecks::class)->connectionCarriesNoTenantSetting('pgsql'))->toHaveCount(1);
    });

    // ...and gone the moment the transaction commits, with nothing cleaned up by hand.
    expect(app(BootChecks::class)->connectionCarriesNoTenantSetting('pgsql'))->toBe([]);
});

it('refuses APP_DEBUG outside local', function (): void {
    config(['app.debug' => true]);

    app()->detectEnvironment(fn () => 'production');
    expect(app(BootChecks::class)->debugIsOff())->toHaveCount(1);

    app()->detectEnvironment(fn () => 'local');
    expect(app(BootChecks::class)->debugIsOff())->toBe([]);
});

it('logs every reason at critical and then stops, which is the gate’s own wording', function (): void {
    Log::spy();

    app()->detectEnvironment(fn () => 'production');
    config(['app.debug' => true, 'database.connections.pgsql.sslmode' => 'prefer']);

    $stopped = null;
    app(RefuseUnsafeBoot::class)->enforce(function (int $code) use (&$stopped): void {
        $stopped = $code;
    });

    expect($stopped)->toBe(1);

    // Four reasons, each its own line: credentials, sslmode, trusted proxies, debug.
    // One line carrying all of them is one line an operator has to parse.
    //
    // Trusted proxies joined the list with security finding #2: the suite's
    // TRUSTED_PROXIES is empty, which is correct for local and refused for production,
    // so this production scenario reports it too.
    Log::shouldHaveReceived('critical')->times(4);
});

it('does not stop a healthy boot', function (): void {
    $stopped = null;
    app(RefuseUnsafeBoot::class)->enforce(function (int $code) use (&$stopped): void {
        $stopped = $code;
    });

    expect($stopped)->toBeNull();
});

it('exits 1 as a real process, because an exit code cannot be asserted in-process', function (): void {
    // DB_DATABASE is passed from the suite's own config rather than left to .env: the
    // subprocess does not inherit phpunit's environment block, and the database the
    // suite just bootstrapped is the only one a fresh clone is guaranteed to have.
    // DB_SSLMODE stays `disable` because local Postgres has no TLS -- which is itself
    // the reason the production run below reports THREE reasons and cannot be reduced
    // to one here: a production boot check against a TLS-less cluster always refuses on
    // sslmode as well, and with no TRUSTED_PROXIES it refuses on that too. Both are
    // correct, and both are asserted rather than tolerated.
    $shared = [
        'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
        'DB_SSLMODE' => 'disable',
        'APP_DEBUG' => 'false',
    ];

    $refused = Process::path(base_path())
        ->env([...$shared, 'APP_ENV' => 'production', 'DB_OWNER_PASSWORD' => 'present'])
        ->run('php artisan boot:check');

    $said = $refused->output().$refused->errorOutput();

    expect($refused->exitCode())->toBe(1)
        ->and($said)->toContain(BootChecks::PRIVILEGED_CREDENTIALS_RESOLVABLE)
        ->and($said)->toContain(BootChecks::WEAK_SSLMODE)
        // Security #2, proven on the real-process path as well as in-process: a
        // production boot with no TRUSTED_PROXIES refuses, so the per-IP rate limit
        // cannot silently become one bucket per load balancer.
        ->and($said)->toContain(BootChecks::UNTRUSTED_PROXY_CONFIGURATION)
        // The role was verifiable, so the 1 is the check's verdict and not a process
        // that could not reach Postgres.
        ->and($said)->not->toContain(BootChecks::UNSAFE_RUNTIME_ROLE);

    // And 0 on the same code path in an environment where both of those are legitimate.
    $allowed = Process::path(base_path())
        ->env([...$shared, 'APP_ENV' => 'local'])
        ->run('php artisan boot:check');

    expect($allowed->exitCode())->toBe(0)
        ->and($allowed->output())->toContain('boot checks passed');
});

it('is wired to WorkerStarting first, and to no service provider', function (): void {
    $starting = config('octane.listeners.'.WorkerStarting::class);

    expect($starting[0])->toBe(RefuseUnsafeBoot::class);

    // Deliberately not in a provider: db:bootstrap and migrate boot the same providers
    // and run BEFORE the roles and schema these checks read exist, so a fresh clone
    // could not reach the command that fixes it.
    expect(file_get_contents(app_path('Providers/AppServiceProvider.php')))
        ->not->toContain('RefuseUnsafeBoot')
        ->not->toContain('BootChecks');
});
