<?php

declare(strict_types=1);

use App\Domain\Pager\PagerToken;
use App\Platform\Boot\BootChecks;
use App\Platform\Boot\RefuseUnsafeBoot;
use App\Platform\Database\DatabaseRoles;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
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

/*
 * Security S-5. pager.landing_url is the base of every QR payload, and PagerToken puts
 * the raw token in that URL's fragment. The fragment is protected by the CLIENT being the
 * one to resolve it -- so the protection is a property of the SCHEME, and nothing was
 * checking the scheme. PAGER_LANDING_URL was also absent from .env.example, so "unset"
 * was the likely state rather than the unlikely one.
 */

it('refuses a landing URL that would deliver the token unsafely — S-5', function (string $url, string $because): void {
    app()->detectEnvironment(fn () => 'production');
    config(['pager.landing_url' => $url]);

    $failures = app(BootChecks::class)->landingUrlIsSound();

    expect($failures)->toHaveCount(1, "[{$url}] was accepted, and it is {$because}")
        ->and($failures[0])->toStartWith(BootChecks::UNSAFE_LANDING_URL);
})->with([
    'empty' => ['', 'where an unset PAGER_LANDING_URL lands'],
    'whitespace' => ['   ', 'empty with extra steps'],
    'plaintext' => ['http://pager.example.com/p', 'the scheme that removes the fragment\'s whole protection'],
    'scheme-relative' => ['//pager.example.com/p', 'not absolute, so it resolves nowhere'],
    'path only' => ['/p', 'not a URL at all'],
    'no host' => ['https:///p', 'https with nothing to resolve'],
    'already fragmented' => ['https://pager.example.com/p#x', 'a second # the client cannot split'],
]);

it('accepts the shape a deployment is supposed to carry', function (): void {
    app()->detectEnvironment(fn () => 'production');

    foreach ([
        'https://pager.example.com/p',
        'https://pager.example.com/scan',
        'https://pager.example.com/p?v=2',
        'HTTPS://pager.example.com/p',
    ] as $url) {
        config(['pager.landing_url' => $url]);

        expect(app(BootChecks::class)->landingUrlIsSound())->toBe([], "[{$url}] was refused");
    }
});

it('proves the fragment case is real and not a guess about parse_url', function (): void {
    // The refusal above is only worth having if the failure it prevents is real. This is
    // that failure, measured rather than described: the token is appended after a '#', so
    // a base URL that already has one yields a fragment the client cannot split, and
    // nothing on either side reports it.
    $token = PagerToken::issue();
    $payload = $token->qrPayload('https://pager.example.com/p#already');

    expect(parse_url($payload, PHP_URL_FRAGMENT))->toBe('already#'.$token->reveal())
        ->and(parse_url($payload, PHP_URL_FRAGMENT))->not->toBe($token->reveal());
});

it('exempts local and testing, where the landing URL is whatever the suite builds', function (string $environment): void {
    app()->detectEnvironment(fn () => $environment);
    config(['pager.landing_url' => 'http://localhost/p']);

    expect(app(BootChecks::class)->landingUrlIsSound())->toBe([]);
})->with(['local', 'testing']);

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

/**
 * What $request->ip() becomes under a given TRUSTED_PROXIES value, through the real
 * middleware rather than through reasoning about it.
 *
 * This is the half that makes the refusals below mean something: a list of forbidden
 * spellings is a list of opinions until each one is shown to hand the caller control of
 * the address the per-IP limiter counts.
 */
function forwardedIpUnder(string $value, string $peer): string
{
    config(['trustedproxy.proxies' => $value]);

    $request = Request::create('/healthz', 'GET', [], [], [], [
        'REMOTE_ADDR' => $peer,
        'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
    ]);

    $seen = '';

    try {
        (new TrustProxies)->handle($request, function (Request $request) use (&$seen): void {
            $seen = (string) $request->ip();
        });
    } finally {
        // Symfony keeps the trusted-proxy list STATIC on the Request class, so this
        // helper puts it back. Every kernel request re-sets it anyway, but "something
        // later will fix it" is the assumption §4a records four times on this branch.
        Request::setTrustedProxies([], Request::getTrustedHeaderSet());
    }

    return $seen;
}

/*
 * EVERY SPELLING OF A CATCH-ALL, not the two that look like one. Refusing '*' and '**'
 * alone left three more accepted, one of which -- REMOTE_ADDR -- this repo documented
 * as valid and asserted as acceptable for a round.
 */

it('refuses every value that hands the caller the address the limiter counts', function (string $catchAll, string $peer): void {
    app()->detectEnvironment(fn () => 'production');
    config(['trustedproxy.proxies' => $catchAll]);

    $failures = app(BootChecks::class)->trustedProxiesAreSound();

    expect($failures)->toHaveCount(1, "[{$catchAll}] was accepted")
        ->and($failures[0])->toStartWith(BootChecks::UNTRUSTED_PROXY_CONFIGURATION)
        ->and($failures[0])->toContain('every peer')
        // THE REASON, MEASURED. The peer is outside any legitimate proxy range, and
        // under each of these values it still dictates ip() by sending one header.
        ->and(forwardedIpUnder($catchAll, $peer))->toBe('203.0.113.9');
})->with([
    // Laravel maps these two to 0.0.0.0/0 and ::/0 itself.
    'star' => ['*', '198.51.100.77'],
    'double star' => ['**', '198.51.100.77'],
    // Resolved to the CURRENT peer, so the trusted list is whoever is connecting.
    'REMOTE_ADDR token' => ['REMOTE_ADDR', '198.51.100.77'],
    // A /0 is '*' written so that it passes a CIDR check. The base address is
    // irrelevant to IpUtils, which is why the third case is not 0.0.0.0.
    'v4 zero prefix' => ['0.0.0.0/0', '198.51.100.77'],
    'v6 zero prefix' => ['::/0', '2001:db8::77'],
    'v4 zero prefix behind a real address' => ['10.0.0.0/0', '198.51.100.77'],
    // Symfony's v4 path reaches "matches everything" by two routes: '0' short-circuits
    // and '00' falls through to a zero-length comparison. Both are refused.
    'padded zero prefix' => ['10.0.0.0/00', '198.51.100.77'],
    // One sound entry does not redeem the list: the limit is only as narrow as its
    // widest member.
    'a real CIDR beside one' => ['10.0.0.0/8,0.0.0.0/0', '198.51.100.77'],
]);

/*
 * THE SAME BYPASS SPELLED AS A LIST. Every refusal above reads one entry at a time and
 * the trusted set is their union, so two entries that are each half a family are each
 * accepted and together are '*'. These are the forms measured at ae43e12, where all
 * three were ACCEPTED while ip() was the caller's own header.
 */

it('refuses entries that trust every peer between them', function (string $list, string $peer): void {
    app()->detectEnvironment(fn () => 'production');
    config(['trustedproxy.proxies' => $list]);

    $failures = app(BootChecks::class)->trustedProxiesAreSound();

    expect($failures)->toHaveCount(1, "[{$list}] was accepted")
        ->and($failures[0])->toStartWith(BootChecks::UNTRUSTED_PROXY_CONFIGURATION)
        // The union clause and not the per-entry one: each half passes that on its own,
        // so a refusal naming a single entry would mean the wrong clause fired.
        ->and($failures[0])->toContain('BETWEEN THEM')
        // THE REASON, MEASURED, as for the single-entry catch-alls above.
        ->and(forwardedIpUnder($list, $peer))->toBe('203.0.113.9');
})->with([
    // The two halves TOUCH without overlapping, which is why the sweep has to treat
    // adjacency as coverage rather than merging on overlap alone.
    'v4 halves, peer in the lower' => ['0.0.0.0/1,128.0.0.0/1', '10.0.0.7'],
    'v4 halves, peer in the upper' => ['0.0.0.0/1,128.0.0.0/1', '255.1.2.3'],
    // The v6 pair. ::/1 against a v4 peer would leave ip() as the peer, so proving this
    // family needs a v6 peer -- the same trap the nine-case dataset above records.
    'v6 halves' => ['::/1,8000::/1', '2001:db8::77'],
    // Quarters, to show the clause is about coverage and not about a pair of /1s.
    'v4 quarters' => ['0.0.0.0/2,64.0.0.0/2,128.0.0.0/2,192.0.0.0/2', '198.51.100.77'],
    // Out of order, and with a sound entry inside the cover: sorting is the sweep's job.
    'unsorted, with a real CIDR among them' => ['128.0.0.0/1,10.0.0.0/8,0.0.0.0/1', '255.1.2.3'],
]);

it('refuses a single range wider than any balancer, which the old floor of 1 did not', function (string $entry, string $peer): void {
    app()->detectEnvironment(fn () => 'production');
    config(['trustedproxy.proxies' => $entry]);

    $failures = app(BootChecks::class)->trustedProxiesAreSound();

    expect($failures)->toHaveCount(1, "[{$entry}] was accepted")
        ->and($failures[0])->toStartWith(BootChecks::UNTRUSTED_PROXY_CONFIGURATION)
        ->and($failures[0])->toContain('wider than any load balancer')
        // The peer is INSIDE the range, which is the point: ip() is its own header.
        ->and(forwardedIpUnder($entry, $peer))->toBe('203.0.113.9');
})->with([
    // Accepted AND pinned as acceptable in the test below for one round. Half of every
    // v4 address there is.
    'v4 half' => ['0.0.0.0/1', '10.0.0.7'],
    'v4 sixteenth' => ['0.0.0.0/4', '10.0.0.7'],
    // One short of the floor, so the boundary is pinned from both sides: /7 refused
    // here, /8 accepted below.
    'one below the v4 floor' => ['10.0.0.0/7', '10.0.0.7'],
    'v6 half' => ['::/1', '2001:db8::77'],
    'one below the v6 floor' => ['2001:db8::/31', '2001:db8::77'],
]);

it('sees a catch-all set through trustProxies(at:), which is not in the config at all', function (): void {
    app()->detectEnvironment(fn () => 'production');

    // The config is sound. The override is not, and it is the one call a maintainer
    // reaches for -- $middleware->trustProxies(at: '*') in bootstrap/app.php, which
    // PLAN.md §4a names as the alternative this fix deliberately did not take.
    // TrustProxies prefers it over the config, so a check reading only the config
    // reported a pass while ip() was the caller's own header. Measured, then fixed.
    config(['trustedproxy.proxies' => '10.0.0.0/8']);
    TrustProxies::at('*');

    try {
        $failures = app(BootChecks::class)->trustedProxiesAreSound();

        expect($failures)->toHaveCount(1)
            ->and($failures[0])->toStartWith(BootChecks::UNTRUSTED_PROXY_CONFIGURATION)
            ->and($failures[0])->toContain('every peer');
    } finally {
        TrustProxies::flushState();
    }

    // And with the override gone the same config is accepted, so the case above is
    // about the override and not about the CIDR.
    expect(app(BootChecks::class)->trustedProxiesAreSound())->toBe([]);
});

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

it('accepts a CIDR and a bare address, in both families', function (): void {
    app()->detectEnvironment(fn () => 'production');

    // REMOTE_ADDR is deliberately NOT here. It was, for one round, and it trusts every
    // peer -- see the refusal dataset above.
    //
    // NEITHER IS `0.0.0.0/1`, WHICH THIS LINE PINNED AS ACCEPTABLE FOR ONE ROUND on the
    // ground that it is "the narrowest prefix that is still a range rather than the whole
    // internet". It is half of every address there is, and the floor it was pinning
    // excluded nothing. The boundary now pinned is the real one: /8 and /32 accepted
    // here, /7 and /31 refused by the dataset above.
    // The last two are the union clause's other direction: several ranges in ONE family
    // that do not cover it must still pass, or a two-subnet balancer would be refused.
    foreach (['10.0.0.0/8', '10.0.0.7', '10.0.0.0/32', '2001:db8::/32', '2001:db8::1', '2001:db8::/128', '10.0.0.0/8,2001:db8::/32', '10.0.0.0/8,192.168.0.0/16', '10.0.0.0/8,10.0.0.0/8,172.16.0.0/12'] as $value) {
        config(['trustedproxy.proxies' => $value]);

        expect(app(BootChecks::class)->trustedProxiesAreSound())->toBe([], "[{$value}] was refused");
    }

    // The contrast that makes the refusals above readable: under a real CIDR the peer
    // sits outside of, the forwarded header is ignored and ip() is the peer.
    expect(forwardedIpUnder('10.0.0.0/8', '198.51.100.77'))->toBe('198.51.100.77');
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
