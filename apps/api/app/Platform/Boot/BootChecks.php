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
 * One class, five clauses, returning REASONS rather than throwing: the caller decides
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
