<?php

declare(strict_types=1);

namespace App\Platform\Boot;

use App\Platform\Database\DatabaseRoles;
use App\Platform\Database\RuntimeRoleGuard;
use App\Platform\Database\UnsafeDatabaseRoleException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Env;
use Throwable;

/**
 * The ordered boot checks from `§6.4 › config validation at boot`.
 *
 * One class, six clauses, returning REASONS rather than throwing: the caller decides
 * what refusing means -- a worker exits 1, a console command returns 1 -- and a check
 * that returns a list can be tested one clause at a time without catching anything.
 *
 * Between them they close H-C's two remaining Task 2 clauses (calling
 * assertSafeRuntimeRole() at boot, and widening it so a serving process cannot resolve
 * owner or root credentials), D8, D9, R-015 and R-020.
 *
 * NOTHING HERE IS A PRECONDITION ON A REQUEST. These run once, when a process starts
 * serving, and the cost is two round trips per worker. Making any of them per request
 * would put the cost on every request and still not be stronger: a role cannot change
 * under a live connection.
 */
final class BootChecks
{
    public const UNSAFE_RUNTIME_ROLE = 'unsafe-runtime-role';

    public const PRIVILEGED_CREDENTIALS_RESOLVABLE = 'privileged-credentials-resolvable';

    public const WEAK_SSLMODE = 'weak-sslmode';

    public const SESSION_SCOPED_TENANT_SETTING = 'session-scoped-tenant-setting';

    public const DEBUG_ENABLED = 'debug-enabled';

    public const UNTRUSTED_PROXY_CONFIGURATION = 'untrusted-proxy-configuration';

    /**
     * Values that make X-Forwarded-For attacker-controlled rather than trustworthy.
     *
     * Laravel maps '*' and '**' to the catch-all 0.0.0.0/0 and ::/0, so every peer is a
     * trusted proxy and the forwarded header is whatever the caller sent.
     *
     * 'REMOTE_ADDR' JOINED THEM ON 2026-10-27 and it is the one that reads like a
     * restriction: setTrustedProxyIpAddressesToSpecificIps() resolves the token to the
     * CURRENT peer, so the trusted list is always whoever is connecting,
     * isFromTrustedProxy() is always true, and the header is honoured from anywhere.
     * It was accepted here -- and documented as valid in config/trustedproxy.php, and
     * asserted as acceptable in BootChecksTest -- for one round. Measured with peer
     * 198.51.100.77 and X-Forwarded-For 203.0.113.9: ip() was 203.0.113.9 under all
     * three of these, and 198.51.100.77 under a real CIDR the peer is outside of.
     *
     * The ground this clause gives for refusing '*' is "it trusts every peer", and that
     * is a property REMOTE_ADDR shares. The single-ingress assumption that would make
     * REMOTE_ADDR sound is the same one that would make '*' sound, it is written down
     * in config/trustedproxy.php as Task 13's to establish, and it is not true today.
     *
     * @var list<string>
     */
    private const CATCH_ALL_PROXIES = ['*', '**', 'REMOTE_ADDR'];

    /**
     * sslmode values that do not guarantee an encrypted session.
     *
     * `prefer` is the dangerous one, and it is also libpq's default: it silently
     * downgrades to plaintext when the server does not offer TLS, which puts the role
     * passwords and every tenant row on the wire with nothing in any log (F7, security
     * review of task-1). `allow` and `disable` are obvious. Anything else -- require,
     * verify-ca, verify-full -- is accepted here; which of those is required is Task
     * 13's, where sslmode=verify-full is already owned.
     */
    private const WEAK_SSLMODES = ['disable', 'allow', 'prefer'];

    /**
     * The environments in which privileged credentials, a weak sslmode and a debug flag
     * are all legitimate.
     *
     * Local Postgres has no TLS and the suite has to be able to run migrations as the
     * owner and bootstrap roles as root, so these two environments are exempt by
     * design and the exemption is named in one place.
     */
    private const DEVELOPMENT_ENVIRONMENTS = ['local', 'testing'];

    public function __construct(
        private readonly Application $app,
        private readonly RuntimeRoleGuard $roles,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Every reason this process must not start serving, in the §6.4 order.
     *
     * @return list<string>
     */
    public function failures(?string $connection = null): array
    {
        $connection ??= $this->defaultConnection();

        return array_values(array_filter([
            ...$this->runtimeRoleIsSafe($connection),
            ...$this->privilegedCredentialsAreAbsent(),
            ...$this->sslmodeIsNotWeak(),
            ...$this->trustedProxiesAreSound(),
            ...$this->connectionCarriesNoTenantSetting($connection),
            ...$this->debugIsOff(),
        ]));
    }

    /**
     * H-C, clause one: the half PLAN.md §0 records as still owed by Task 2.
     *
     * Task 1 built assertSafeRuntimeRole() and nothing called it at boot, so a process
     * could serve every request as a role row level security does not apply to and
     * every test would still pass. The per-call half landed on task-1; this is the
     * boot-time half.
     *
     * @return list<string>
     */
    public function runtimeRoleIsSafe(string $connection): array
    {
        try {
            $this->roles->assertSafe($connection);

            return [];
        } catch (UnsafeDatabaseRoleException $refusal) {
            // The role name and the reasons are kept: this reason goes to a LOG, never
            // to a response body, which is R-021's acceptance condition stated exactly
            // and which ProblemRenderer is what enforces on the other side.
            return [self::UNSAFE_RUNTIME_ROLE.': '.$refusal->getMessage()];
        } catch (Throwable $error) {
            // A connection failure is not a safe role. "Cannot tell" must never read as
            // "it is fine" -- the same rule readState()'s attribute defaults follow.
            return [self::UNSAFE_RUNTIME_ROLE.': the runtime role on connection '
                ."[{$connection}] could not be verified (".$error::class.')'];
        }
    }

    /**
     * D9, which is also H-C's second clause and R-015's real control.
     *
     * R-015's residue is that a callback inside a perfectly valid tenant scope can
     * query any OTHER connection directly, and pgsql_root and pgsql_owner are defined
     * unconditionally in every environment. PLAN.md §5 is explicit that the control is
     * not in the tenancy layer at all: a serving process must not be able to resolve
     * root or owner credentials. This is that control.
     *
     * READ FROM BOTH THE ENVIRONMENT AND THE RESOLVED CONFIG, and the second half is
     * not belt and braces. `config:cache` evaluates config/database.php while .env is
     * still loaded and bakes the result into the cache, after which env() returns null
     * at runtime -- so an environment-only check would pass while the credentials are
     * still resolvable through config. That is an unproven control, which is the one
     * shape §4a keeps recording. Reading the config closes it, and reading the
     * environment still matters because a container passes these as real process
     * variables that no config value has to mention.
     *
     * @return list<string>
     */
    public function privilegedCredentialsAreAbsent(): array
    {
        if ($this->isDevelopment()) {
            return [];
        }

        $resolvable = [];

        foreach (['DB_OWNER_PASSWORD', 'DB_ROOT_PASSWORD'] as $variable) {
            if ($this->isNonEmpty(Env::get($variable))) {
                $resolvable[] = $variable;
            }
        }

        foreach (['owner' => 'pgsql_owner', 'root' => 'pgsql_root', 'bootstrap' => 'pgsql_bootstrap'] as $name) {
            if ($this->isNonEmpty(config("database.connections.{$name}.password"))) {
                $resolvable[] = "database.connections.{$name}.password";
            }
        }

        if ($resolvable === []) {
            return [];
        }

        return [self::PRIVILEGED_CREDENTIALS_RESOLVABLE.': a serving process must not be able to connect as '
            .'the owner or the cluster superuser, because a tenant scope narrows one connection and not the '
            .'others (R-015). These resolve to a value: '.implode(', ', array_unique($resolvable))
            .'. Remove them from this task definition; migrations and db:bootstrap run as their own '
            .'processes with their own credentials (PLAN.md §2.1).'];
    }

    /**
     * D8. Named per connection, because one weak entry is enough to put credentials on
     * the wire and the default is shared by every pgsql connection in the file.
     *
     * @return list<string>
     */
    public function sslmodeIsNotWeak(): array
    {
        if ($this->isDevelopment()) {
            return [];
        }

        $weak = [];

        /** @var array<string, mixed> $connections */
        $connections = (array) config('database.connections', []);

        foreach ($connections as $name => $settings) {
            if (! is_array($settings) || ($settings['driver'] ?? null) !== 'pgsql') {
                continue;
            }

            $mode = is_string($settings['sslmode'] ?? null) ? strtolower($settings['sslmode']) : '';

            if (in_array($mode, self::WEAK_SSLMODES, true)) {
                $weak[] = (string) $name.'='.$mode;
            }
        }

        if ($weak === []) {
            return [];
        }

        return [self::WEAK_SSLMODE.': '.implode(', ', $weak).'. [prefer] is libpq\'s own default and '
            .'silently downgrades to an unencrypted session when the server does not offer TLS, with '
            .'nothing in any log (F7). Use require or stronger; Task 13 owns verify-full.'];
    }

    /**
     * Security finding #2: what $request->ip() returns is what the per-IP limit counts.
     *
     * With no trusted proxy, ip() is the raw REMOTE_ADDR -- measured: REMOTE_ADDR
     * 10.0.0.7 plus X-Forwarded-For 203.0.113.9 gave 10.0.0.7. Behind the locked ALB
     * that is the balancer's own ENI, so the per-IP bucket becomes one bucket per ALB
     * node shared by every client, the per-IP limit §4 › Edge & ops requires does not
     * exist, and the limiter inverts into a shared-fate denial of service.
     *
     * THREE WAYS TO GET THIS WRONG, AND ALL THREE REFUSE HERE. Empty is the bug above.
     * Trusting every peer is worse: the forwarded header becomes whatever the caller
     * sent and the limit is bypassed by rotating it. And an entry that does not parse
     * is the quietest of the three -- Symfony's IpUtils simply never matches it, so a
     * typo in a CIDR silently restores the first case with nothing in any log. A check
     * that accepted a malformed value would be a control that reports success it has
     * not established, which is the shape this branch has paid for twice.
     *
     * THE MIDDLE CASE IS A SET OF VALUES AND NOT A PAIR OF LITERALS -- see
     * trustsEveryPeer(). Refusing '*' and '**' alone left three spellings of the same
     * thing accepted, one of them documented as valid, which is a control refusing the
     * mistake it can recognise rather than the mistake.
     *
     * Exempt in local and testing: neither has a proxy in front of it, so the peer IS
     * the client and trusting nothing is correct there.
     *
     * The single-ingress assumption this depends on is written down in
     * config/trustedproxy.php and owned by Task 13 along with the CIDR itself.
     *
     * @return list<string>
     */
    public function trustedProxiesAreSound(): array
    {
        if ($this->isDevelopment()) {
            return [];
        }

        $entries = $this->configuredProxies();

        if ($entries === []) {
            return [self::UNTRUSTED_PROXY_CONFIGURATION.': no trusted proxy is configured, so '
                .'$request->ip() is the peer address. Behind a load balancer that is the balancer, '
                .'and the per-IP rate limit becomes one bucket shared by every client on this task '
                .'(§4 › Edge & ops requires a limit per IP). Set TRUSTED_PROXIES to the CIDR the '
                .'balancer connects from -- never to a catch-all; see config/trustedproxy.php.'];
        }

        foreach ($entries as $entry) {
            if ($this->trustsEveryPeer($entry)) {
                return [self::UNTRUSTED_PROXY_CONFIGURATION.': TRUSTED_PROXIES trusts every peer '
                    ."([{$entry}]), which makes X-Forwarded-For whatever the caller sent -- the per-IP "
                    .'rate limit is then bypassed by rotating one header, which is worse than having '
                    .'no value at all. Name the balancer\'s CIDR instead, with a prefix of at least 1.'];
            }
        }

        $invalid = array_values(array_filter(
            $entries,
            fn (string $entry): bool => ! $this->isProxyAddress($entry),
        ));

        if ($invalid !== []) {
            return [self::UNTRUSTED_PROXY_CONFIGURATION.': TRUSTED_PROXIES carries entries that are '
                .'not an address or a CIDR: ['.implode('], [', $invalid).']. Symfony never matches '
                .'such an entry, so it would be inert and $request->ip() would quietly go back to '
                .'being the peer address.'];
        }

        return [];
    }

    /**
     * The entries, from whichever of the two sources TrustProxies will really use.
     *
     * ResolvedTrustedProxies, never config() directly: the middleware prefers the
     * trustProxies(at: ...) override and reading the config alone made this clause
     * blind to the one call that sets a catch-all in one line. That class carries the
     * measurement.
     *
     * @return list<string>
     */
    private function configuredProxies(): array
    {
        /** @var mixed $configured */
        $configured = ResolvedTrustedProxies::asTrustProxiesReadsThem();

        $raw = match (true) {
            is_string($configured) => explode(',', $configured),
            is_array($configured) => $configured,
            default => [],
        };

        return array_values(array_filter(
            array_map(static fn (mixed $entry): string => is_string($entry) ? trim($entry) : '', $raw),
            static fn (string $entry): bool => $entry !== '',
        ));
    }

    /**
     * Does this entry make every peer a trusted proxy?
     *
     * FOUR SPELLINGS, AND ONLY TWO OF THEM LOOK LIKE ONE. The literals and the
     * REMOTE_ADDR token are in CATCH_ALL_PROXIES above. The fourth is a /0 prefix,
     * which reads like a CIDR and is one -- measured against the pinned Symfony's
     * IpUtils: checkIp4() returns true for ANY v4 peer when the netmask is 0, whatever
     * the base address, so 10.0.0.0/0 is as total as 0.0.0.0/0; checkIp6() does the
     * same for every v6 peer. A /0 is '*' written so that it passes a CIDR check.
     *
     * The prefix is compared as a NUMBER rather than against the string '0', because
     * Symfony's v4 path reaches the same place by two routes: '0' short-circuits to
     * true, and '00' falls through to a zero-length substr_compare that also returns
     * true. Refusing on (int) 0 covers both and cannot be narrower than the library.
     *
     * A /0 whose address does not parse is a typo rather than a catch-all, and the
     * malformed clause says so more usefully, so it is left to fall through.
     */
    private function trustsEveryPeer(string $entry): bool
    {
        if (in_array($entry, self::CATCH_ALL_PROXIES, true)) {
            return true;
        }

        if (! str_contains($entry, '/')) {
            return false;
        }

        [$address, $prefix] = explode('/', $entry, 2);

        return ctype_digit($prefix)
            && (int) $prefix === 0
            && filter_var($address, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * An address, or a CIDR whose prefix is between 1 and the family's width.
     *
     * The floor of 1 is deliberate duplication: a /0 is already refused above as a
     * catch-all, and repeating the bound here means the ORDER of the two clauses is not
     * the only thing standing between a /0 and a pass. REMOTE_ADDR is no longer
     * accepted anywhere -- it is in CATCH_ALL_PROXIES.
     */
    private function isProxyAddress(string $entry): bool
    {
        if (! str_contains($entry, '/')) {
            return filter_var($entry, FILTER_VALIDATE_IP) !== false;
        }

        [$address, $prefix] = explode('/', $entry, 2);

        if (filter_var($address, FILTER_VALIDATE_IP) === false || ! ctype_digit($prefix)) {
            return false;
        }

        // The ceiling is per family: a /64 is ordinary for IPv6 and meaningless for v4.
        $bits = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;

        return (int) $prefix >= 1 && (int) $prefix <= $bits;
    }

    /**
     * R-020. One round trip, and only sound at boot: there is no transaction open yet,
     * so anything app.tenant_id holds here is session-scoped by definition -- a
     * set_config(..., false), an operator's SET, or a pooled connection handed over
     * carrying one. C2 makes that a hard failure, and TenantContext refuses to adopt
     * such a value, so a process that starts on one would refuse every unit of work it
     * was given. Refusing at boot turns that from a stream of confusing per-request
     * failures into one reason, once.
     *
     * The REMEDY is in the message rather than only in §2.10, because the person
     * reading it is holding a container that will not start.
     *
     * @return list<string>
     */
    public function connectionCarriesNoTenantSetting(string $connection): array
    {
        $guc = DatabaseRoles::guc('tenant');

        try {
            $row = $this->db->connection($connection)->selectOne(
                'select coalesce(current_setting(?, true), ?) as value', [$guc, ''],
            );
        } catch (Throwable) {
            // Already reported by the role check above, which runs first on the same
            // connection. Reporting it twice would make one fault look like two.
            return [];
        }

        $value = is_object($row) && is_string($row->value ?? null) ? $row->value : '';

        if ($value === '') {
            return [];
        }

        // The value is NOT named. It is a tenant id belonging to whoever set it, this
        // process has no claim to it, and the refusal does not depend on what it is.
        return [self::SESSION_SCOPED_TENANT_SETTING." on connection [{$connection}]: a tenant setting is "
            .'in force outside any transaction, so it is session-scoped. Every tenant scope sets it with '
            .'the third argument true (C2), so nothing in this application can have produced it. Remedy: '
            .'reset it on the server side with ALTER ROLE ... RESET, or ALTER DATABASE ... RESET, and '
            .'restart -- see PLAN.md §2.10.'];
    }

    /** @return list<string> */
    public function debugIsOff(): array
    {
        if ($this->app->environment('local') || ! (bool) config('app.debug')) {
            return [];
        }

        return [self::DEBUG_ENABLED.": APP_DEBUG is true and the environment is [{$this->app->environment()}]. "
            .'§4 forbids it outside local. The error envelope redacts at any APP_DEBUG setting, so this is '
            .'not the only control -- but the debug page is 862 KB of this application\'s internals, '
            .'measured, and nothing should be one configuration flag away from serving it.'];
    }

    public function defaultConnection(): string
    {
        $name = config('database.default');

        return is_string($name) && $name !== '' ? $name : DatabaseRoles::connection('app');
    }

    private function isDevelopment(): bool
    {
        return $this->app->environment(self::DEVELOPMENT_ENVIRONMENTS);
    }

    private function isNonEmpty(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
