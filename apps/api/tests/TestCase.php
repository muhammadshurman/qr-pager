<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Audit\AuditActorType;
use App\Domain\Pager\PagerStatus;
use App\Domain\Tenancy\TenantRole;
use App\Platform\Auth\HarnessTenantResolver;
use App\Platform\Readiness\ReadinessStore;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    private static bool $databaseReady = false;

    private static ?string $runMarker = null;

    private static ?string $suiteStartedAt = null;

    /**
     * Truncated between tests. audit_log is deliberately absent: it is append-only,
     * so the trigger refuses to truncate it. Tests scope their audit assertions
     * instead of resetting the table.
     */
    private const RESETTABLE = [
        'push_subscriptions', 'outbox', 'pager_sessions', 'tenant_users', 'tenants', 'users',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$databaseReady) {
            // The suite runs from a fresh clone with nothing but `docker compose up`:
            // roles, the test database and the schema are all created here. The
            // bootstrap holds pg_advisory_lock, so parallel runners queue instead of
            // racing a duplicate CREATE ROLE (PROJECT_INSTRUCTIONS.md §4).
            Artisan::call('db:bootstrap');
            Artisan::call('migrate', ['--database' => 'pgsql_owner', '--force' => true]);

            self::dropStaleProbeRoles();

            self::$databaseReady = true;
        }

        DB::connection('pgsql_owner')
            ->unprepared('TRUNCATE '.implode(', ', self::RESETTABLE).' RESTART IDENTITY CASCADE');

        // The readiness key is cross-test shared state in a REAL store, exactly like the
        // tables above, and it has a 60-second TTL -- so a saturated snapshot one test
        // publishes sheds every /v1 request in the tests after it. Measured: three
        // TenantResolutionTest cases and two LogRedactionTest cases started answering 503
        // the moment ShedLoadTest existed. Cleared here for the same reason the tables
        // are: a test must not inherit the state of the one before it.
        //
        // An absent key is the safe default, and that is not an accident -- shedding
        // fails OPEN on a missing snapshot while readiness fails closed, so clearing it
        // leaves /v1 served and /readyz refusing, which is each one's own posture.
        try {
            $this->app->make(ReadinessStore::class)->forget();
        } catch (Throwable) {
            // A Redis that cannot be reached has no key to clear, and the tests that
            // care about Redis being down say so themselves.
        }
    }

    /**
     * Drop anything withThrowawayRole() left behind.
     *
     * R-018's trade, made self-healing. The helper drops its role in a finally, and a
     * kill -9, an OOM or a CI timeout between the create and the drop leaves one
     * behind: a role named qrpager_probe_* with no privilege on any table and nothing
     * connecting as it -- which is a mess, not a hole, and exactly why the old
     * `alter role qrpager_app bypassrls` approach was worse.
     *
     * This exists because RoleNamingTest pins the cluster's login roles as an EXACT
     * set, so a leftover fails the gate for an unrelated reason the next time anyone
     * runs the suite. That assertion is a control worth keeping exact, so the leftover
     * is swept rather than the assertion loosened. Same shape as db:bootstrap
     * re-asserting NOSUPERUSER NOBYPASSRLS on every run: converge, do not tolerate.
     *
     * Found by RoleNamingTest failing with eight of them, from the runs it took to
     * discover that a new role has no CONNECT privilege on this database.
     */
    private static function dropStaleProbeRoles(): void
    {
        $root = DB::connection('pgsql_root');

        foreach ($root->select("select rolname from pg_roles where rolname like 'qrpager_probe_%'") as $row) {
            if (! is_object($row) || ! is_string($row->rolname ?? null)) {
                continue;
            }

            self::dropRoleAndItsGrants($root, $row->rolname);
        }
    }

    /**
     * DROP OWNED BY before DROP ROLE, which is not optional and was not obvious.
     *
     * Postgres refuses `drop role` with 2BP01 "Dependent objects still exist" while any
     * privilege is granted TO the role -- and one of these tests points
     * tenancy.roles.app at the throwaway and runs db:bootstrap against it, so it ends up
     * holding grants on schema public and CONNECT on the database. DROP OWNED BY revokes
     * privileges on objects in the current database and on shared objects (the database
     * itself included), which is exactly the set in the way.
     *
     * Found by the sweep failing on the leftovers from the runs before this existed.
     */
    private static function dropRoleAndItsGrants(Connection $root, string $role): void
    {
        // These names are generated here out of hex and matched by a literal pattern,
        // so nothing in them came from outside.
        $root->statement("drop owned by {$role}");
        $root->statement("drop role if exists {$role}");
    }

    protected function tearDown(): void
    {
        // Every test boots its own application, and each one opens up to five
        // connections (app, owner, worker, root, bootstrap). Left to the garbage
        // collector these accumulate until Postgres refuses with 08006 "remaining
        // connection slots are reserved for roles with the SUPERUSER attribute" --
        // which it did, at around test 100 of this suite.
        $this->purgeConnections();

        parent::tearDown();
    }

    protected function purgeConnections(): void
    {
        foreach (array_keys(DB::getConnections()) as $name) {
            DB::purge((string) $name);
        }
    }

    protected function asOwner(): Connection
    {
        return DB::connection('pgsql_owner');
    }

    protected function asWorker(): Connection
    {
        return DB::connection('pgsql_worker');
    }

    /** The cluster superuser. Only ever an observer in tests, never a code path. */
    protected function asRoot(): Connection
    {
        return DB::connection('pgsql_root');
    }

    protected function makeTenant(?string $slug = null): string
    {
        $id = (string) Str::uuid7();

        // The real tenant-creation path: context is set to the id about to be inserted,
        // so even this needs no bypass (PLAN.md §2.7).
        withTenant($id, fn () => DB::connection('pgsql_owner')->table('tenants')->insert([
            'id' => $id,
            'name' => 'Tenant '.substr($id, 0, 8),
            // The whole id, not a prefix: Laravel's uuid7 keeps its first 25 hex
            // digits stable inside one millisecond, so a prefix-based slug collided
            // with tenants_slug_unique whenever two were made back to back. Found by
            // the composition matrix, which makes two tenants in most of its tests.
            'slug' => $slug ?? 'tenant-'.str_replace('-', '', $id),
        ]), 'pgsql_owner');

        return $id;
    }

    /**
     * @param  array<string, string|int|null>  $overrides
     */
    protected function makeSession(string $tenantId, array $overrides = []): string
    {
        $id = (string) Str::uuid7();

        $row = array_merge([
            'id' => $id,
            'status' => PagerStatus::Waiting->value,
            'expires_at' => now()->addHours(2)->toIso8601String(),
            'order_reference' => 'A-'.random_int(100, 999),
        ], $overrides);

        // token_hash is bytea, so it is bound as hex and decoded in SQL rather than
        // pushed through PDO as a binary string.
        withTenant($tenantId, fn () => DB::connection('pgsql_owner')->insert(
            'insert into pager_sessions (id, tenant_id, token_hash, order_reference, status, expires_at)
             values (?, ?, decode(?, \'hex\'), ?, ?::pager_status, ?)',
            [
                $row['id'], $tenantId, bin2hex(hash('sha256', (string) $row['id'], true)),
                $row['order_reference'], $row['status'], $row['expires_at'],
            ],
        ), 'pgsql_owner');

        return (string) $row['id'];
    }

    /**
     * Make requests as a tenant, through the pre-Task-5 resolver (B5).
     *
     * This is the ONLY way a request gets a tenant before Task 5, and that is the
     * design: HarnessTenantResolver reads a container binding and never a header, a
     * route parameter, a query string or a cookie, so no HTTP input can choose a
     * tenant. A stub that read a header would be an unauthenticated tenant-switching
     * parameter -- the most dangerous shape this application could ship, looking like
     * perfectly ordinary code.
     *
     * Every /v1 route is deny-by-default behind RequireTenantScope, so a feature test
     * that does not call this gets a 401 with no detail. That is the clause working.
     */
    protected function actingForTenant(?string $tenantId = null): string
    {
        $tenantId ??= (string) Str::uuid7();

        $this->app->instance(HarnessTenantResolver::BINDING, $tenantId);

        return $tenantId;
    }

    /**
     * A token unique to this suite run, for assertions that would otherwise filter on a
     * constant.
     *
     * R-016. audit_log is append-only -- the trigger refuses DELETE and TRUNCATE -- so
     * it is deliberately absent from RESETTABLE and grows monotonically across runs on
     * a developer machine. CI is immune by construction, because it builds from an
     * empty database every run. That asymmetry is the whole danger: the first
     * unfiltered audit_log count anyone adds PASSES ON CI AND FAILS LOCALLY, which is
     * the same "correct on a clean build, wrong on a machine carrying earlier state"
     * shape as the stale grant migration that cost a morning on 2026-10-26.
     *
     * Static, so every row and every assertion in one `php artisan test` agree, and
     * random, so two runs never do. Never a DELETE -- the trigger refuses it, and that
     * refusal is a gate clause of its own.
     */
    protected function runMarker(): string
    {
        return self::$runMarker ??= 'run-'.bin2hex(random_bytes(6));
    }

    /**
     * When this suite run began, for an assertion that reads an external log.
     *
     * Static and set on first use, which in practice is the first test that asks. A
     * per-test window was the first version and it was not good enough: `docker compose
     * logs --since` takes a second-resolution timestamp, so the window either missed a
     * leak from a neighbouring test or caught one at random depending on how fast the
     * suite happened to run that time. A window that starts at the run's start is
     * deterministic.
     *
     * Its limitation, stated rather than discovered: it can only see tests that ran
     * BEFORE the assertion. Pest's file order is deterministic and BootstrapLoggingTest
     * is early, so a leak introduced in a later file is invisible here. What closes that
     * is the same scan run as a CI step after the whole suite, which is where the
     * ordering stops mattering.
     */
    protected function suiteStartedAt(): string
    {
        return self::$suiteStartedAt ??= (new \DateTimeImmutable('-1 second'))->format(\DateTimeInterface::RFC3339);
    }

    /** An action name no earlier run can have written. */
    protected function auditAction(string $action): string
    {
        return $action.'.'.$this->runMarker();
    }

    /**
     * The context column for a row the SUITE writes, carrying the run marker.
     *
     * @param  array<string, string>  $extra
     */
    protected function auditContext(array $extra = []): string
    {
        return json_encode(['run' => $this->runMarker()] + $extra, JSON_THROW_ON_ERROR);
    }

    /** Put one row in every tenant table, so a "no context sees nothing" claim means something. */
    protected function seedEveryTenantTable(string $tenantId): void
    {
        $session = $this->makeSession($tenantId);
        $this->makeOutboxRow($tenantId, $session);

        $userId = (string) Str::uuid7();

        DB::connection('pgsql_owner')->table('users')->insert([
            'id' => $userId,
            'name' => 'Seeded Staff',
            'email' => 'staff-'.substr(str_replace('-', '', $userId), 0, 12).'@example.test',
        ]);

        withTenant($tenantId, function () use ($tenantId, $userId, $session): void {
            $owner = DB::connection('pgsql_owner');

            $owner->table('tenant_users')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'role' => TenantRole::Owner->value,
            ]);

            $owner->table('push_subscriptions')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'pager_session_id' => $session,
                'endpoint' => 'https://push.example.test/'.Str::random(24),
                'p256dh' => Str::random(87),
                'auth' => Str::random(22),
            ]);

            $owner->table('audit_log')->insert([
                'tenant_id' => $tenantId,
                'actor_type' => AuditActorType::User->value,
                'actor_user_id' => $userId,
                'action' => $this->auditAction('seed.created'),
                'context' => $this->auditContext(),
            ]);
        }, 'pgsql_owner');
    }

    protected function makeOutboxRow(string $tenantId, ?string $sessionId = null): string
    {
        $id = (string) Str::uuid7();

        withTenant($tenantId, fn () => DB::connection('pgsql_owner')->table('outbox')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'pager_session_id' => $sessionId,
            'type' => 'pager.ready',
            'dedupe_key' => 'session:'.($sessionId ?? $id).':ready',
        ]), 'pgsql_owner');

        return $id;
    }

    /**
     * Run $callback with a throwaway role holding $attributes, and drop it afterwards.
     *
     * R-018. Three tests used to issue `alter role qrpager_app bypassrls` (one of them
     * `superuser bypassrls`) against the live cluster and revert in a finally. Each
     * finally is correct and none of them survives a kill -9, an OOM, a Ctrl-C or a CI
     * timeout between the two statements -- and in that window row level security
     * narrows nothing for EVERY process on the cluster, including php artisan serve,
     * Filament and a plain psql, none of which go through TenantContext. Nothing
     * reports it either, because the next `php artisan test` silently repairs it:
     * TestCase::setUp runs db:bootstrap, which re-asserts NOSUPERUSER NOBYPASSRLS.
     *
     * The role here is created, granted, used and dropped, so qrpager_app is never
     * altered at all. An interrupted run leaves behind a role named qrpager_probe_*
     * that nothing connects as and that holds no privilege on any table -- which is a
     * mess rather than a hole, and `drop role` is the whole cleanup.
     *
     * $callback receives the connection NAME, because the point is to run the real code
     * path against a real session of that role: the role names are config
     * (tenancy.roles.app), so a caller that needs assertSafe() to treat the throwaway
     * as a runtime role points that config entry at the name this returns.
     *
     * @param  list<string>  $attributes  e.g. ['bypassrls'], ['superuser', 'bypassrls']
     * @param  callable(string, string): void  $callback  (connection name, role name)
     */
    protected function withThrowawayRole(array $attributes, callable $callback): void
    {
        $role = 'qrpager_probe_'.bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(16));
        $connection = 'pgsql_probe';
        $root = $this->asRoot();

        // Quoted by Postgres, from a name this method generated out of hex -- nothing
        // here came from outside.
        $root->statement("create role {$role} with login noinherit password '{$password}'");

        try {
            if ($attributes !== []) {
                $root->statement("alter role {$role} ".implode(' ', $attributes));
            }

            // CONNECT has to be granted explicitly: the bootstrap revokes it from
            // PUBLIC (§2.1), so a new role cannot open a session at all and the
            // connection fails 08006 "permission denied for database" -- which is how
            // the first version of this helper failed, with the refusal under test
            // replaced by a connection error. Nothing else is granted: these tests need
            // a real SESSION of a role with an attribute, never access to a table.
            $database = (string) config('database.connections.pgsql.database');
            $root->statement("grant connect on database \"{$database}\" to {$role}");

            config(["database.connections.{$connection}" => array_merge(
                (array) config('database.connections.pgsql'),
                ['username' => $role, 'password' => $password],
            )]);

            $callback($connection, $role);
        } finally {
            // Order matters: the session has to be gone before the role can be dropped,
            // and a connection left in the manager would be reused by a later test
            // under a role that no longer exists.
            DB::purge($connection);

            try {
                self::dropRoleAndItsGrants($root, $role);
            } catch (Throwable) {
                // A role that cannot be dropped is a named leftover with no privileges,
                // which is the trade this helper makes deliberately. Reporting it here
                // would replace the test's own result with cleanup noise.
            }
        }
    }

    /**
     * The SQLSTATE a failed statement actually carried.
     *
     * Laravel wraps the driver error, so the code is read from the PDO exception
     * underneath rather than from the wrapper (PROJECT_INSTRUCTIONS.md §4 ›
     * Postgres traps: "read the real driver code").
     */
    protected function sqlState(Throwable $e): string
    {
        $previous = $e->getPrevious();

        if ($previous instanceof \PDOException && is_array($previous->errorInfo)) {
            return (string) $previous->errorInfo[0];
        }

        return (string) $e->getCode();
    }

    /** Run $callback and return the SQLSTATE it failed with, or null if it succeeded. */
    protected function sqlStateOf(callable $callback): ?string
    {
        try {
            $callback();

            return null;
        } catch (QueryException $e) {
            return $this->sqlState($e);
        }
    }
}
