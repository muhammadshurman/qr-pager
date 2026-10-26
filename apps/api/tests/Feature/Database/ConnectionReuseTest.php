<?php

declare(strict_types=1);

use App\Platform\Database\TenantContext;
use Illuminate\Support\Facades\DB;

/*
 * Acceptance gate, clause 4: "context does not leak across a single-connection pool".
 *
 * Everything below runs on one connection, in one process, with no reconnect --
 * which is exactly what Octane does between requests (Task 2).
 */

it('does not carry tenant context from one transaction to the next on one connection', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $this->makeSession($alpha);
    $this->makeSession($beta);
    $this->makeSession($beta);

    $pid = DB::selectOne('select pg_backend_pid() as pid')->pid;

    expect(withTenant($alpha, fn () => DB::table('pager_sessions')->count()))->toBe(1);

    // Between the two tenants, with no context, the same backend sees nothing.
    expect(DB::table('pager_sessions')->count())->toBe(0);

    expect(withTenant($beta, fn () => DB::table('pager_sessions')->count()))->toBe(2);

    expect(DB::table('pager_sessions')->count())->toBe(0);

    // ...and it really was one connection throughout.
    expect(DB::selectOne('select pg_backend_pid() as pid')->pid)->toBe($pid);
});

it('sets the guc transaction-locally, never for the session', function (): void {
    $tenant = $this->makeTenant();

    withTenant($tenant, function () use ($tenant): void {
        expect((string) DB::selectOne("select current_setting('app.tenant_id', true) as id")->id)->toBe($tenant);
    });

    // C2: the third argument of set_config is always true, so a commit drops it.
    expect((string) DB::selectOne("select current_setting('app.tenant_id', true) as id")->id)->toBe('');
});

it('drops tenant context when the transaction rolls back', function (): void {
    $tenant = $this->makeTenant();

    try {
        withTenant($tenant, function (): void {
            DB::table('audit_log')->insert(['actor_type' => 'system', 'action' => 'rollback.probe']);

            throw new RuntimeException('deliberate');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect((string) DB::selectOne("select current_setting('app.tenant_id', true) as id")->id)->toBe('');
    expect(app(TenantContext::class)->current())->toBeNull();
    expect(DB::connection('pgsql_owner')
        ->table('audit_log')->where('action', 'rollback.probe')->count())->toBe(0);
});
