<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Audit\AuditActorType;
use App\Domain\Pager\PagerStatus;
use App\Domain\Tenancy\TenantRole;
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

            self::$databaseReady = true;
        }

        DB::connection('pgsql_owner')
            ->unprepared('TRUNCATE '.implode(', ', self::RESETTABLE).' RESTART IDENTITY CASCADE');
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
            'slug' => $slug ?? 'tenant-'.substr(str_replace('-', '', $id), 0, 16),
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
                'action' => 'seed.created',
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
