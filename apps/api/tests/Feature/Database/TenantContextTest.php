<?php

declare(strict_types=1);

use App\Platform\Database\TenantContext;
use App\Platform\Database\TenantContextException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The withTenant() helper itself (PLAN.md §2.2).
 */

it('rejects anything that is not a uuid before it reaches the database', function (string $value): void {
    expect(fn () => withTenant($value, fn () => true))->toThrow(TenantContextException::class, 'is not a uuid');
})->with([
    'empty' => [''],
    'not a uuid' => ['tenant-one'],
    'sql fragment' => ["' or true --"],
    'truncated uuid' => ['0199ab65-1234-7000-8000-00000000'],
]);

it('returns whatever the callback returns', function (): void {
    expect(withTenant($this->makeTenant(), fn () => 'result'))->toBe('result');
});

it('reports the active tenant inside the callback and nothing outside it', function (): void {
    $tenant = $this->makeTenant();
    $context = app(TenantContext::class);

    expect($context->current())->toBeNull();

    withTenant($tenant, function () use ($context, $tenant): void {
        expect($context->current())->toBe($tenant);
    });

    expect($context->current())->toBeNull();
});

it('allows re-entry with the same tenant, for a service that calls another service', function (): void {
    $tenant = $this->makeTenant();

    $count = withTenant($tenant, fn () => withTenant($tenant, fn () => DB::table('tenants')->count()));

    expect($count)->toBe(1);
});

it('refuses to switch tenant inside an open context', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();

    expect(fn () => withTenant($alpha, fn () => withTenant($beta, fn () => true)))
        ->toThrow(TenantContextException::class, 'cannot be changed');
});

it('refuses to reuse an open context on another connection', function (): void {
    $tenant = $this->makeTenant();

    expect(fn () => withTenant($tenant, fn () => withTenant($tenant, fn () => true, 'pgsql_owner')))
        ->toThrow(TenantContextException::class, 'cannot be reused');
});

it('runs the callback inside one transaction', function (): void {
    $tenant = $this->makeTenant();

    withTenant($tenant, function (): void {
        expect(DB::transactionLevel())->toBe(1);
    });

    expect(DB::transactionLevel())->toBe(0);
});

it('rolls the work back when the callback throws', function (): void {
    $tenant = $this->makeTenant();
    $reference = 'ROLLBACK-'.Str::random(6);

    try {
        withTenant($tenant, function () use ($tenant, $reference): void {
            DB::table('outbox')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenant,
                'type' => 'pager.ready',
                'dedupe_key' => $reference,
            ]);

            throw new RuntimeException('deliberate');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(withTenant($tenant, fn () => DB::table('outbox')->count()))->toBe(0);
});

it('passes the tenant id as a bound parameter, never as SQL', function (): void {
    // C2: SET takes no bind parameters, so set_config is the only way to do this.
    // The GUC name is bound too, which leaves no concatenation in the path at all.
    $tenant = $this->makeTenant();
    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    withTenant($tenant, fn () => DB::table('tenants')->count());

    expect($queries)->toContain('select set_config(?, ?, true)')
        ->and(implode(' ', $queries))->not->toContain($tenant);
});

/*
 * Nesting inside a transaction withTenant() did not start (F1, security review of
 * task-1). set_config(..., true) binds to the OUTERMOST transaction, so without
 * save-and-restore the context outlives the block. Migrations are the ordinary
 * case: PostgresGrammar reports supportsSchemaTransactions, so every migration
 * body is already at transaction level 1.
 */

it('works inside a transaction it did not start, and leaves no residue', function (): void {
    $alpha = $this->makeTenant();

    DB::transaction(function () use ($alpha): void {
        $before = DB::selectOne("select coalesce(current_setting('app.tenant_id', true), '') as tenant")->tenant;

        $seen = withTenant($alpha, fn () => DB::table('tenants')->pluck('id')->all());

        expect($seen)->toBe([$alpha])
            ->and(DB::selectOne("select coalesce(current_setting('app.tenant_id', true), '') as tenant")->tenant)
            ->toBe($before);
    });
});

it('never leaves app.tenant_id set behind inside a transaction it does not own', function (): void {
    $alpha = $this->makeTenant();

    DB::transaction(function () use ($alpha): void {
        try {
            withTenant($alpha, fn () => DB::table('tenants')->count());
        } catch (TenantContextException) {
            // refused is also a pass: nothing was set
        }

        expect(DB::selectOne("select coalesce(current_setting('app.tenant_id', true), '') as tenant")->tenant)
            ->toBe('')
            ->and(DB::table('tenants')->count())->toBe(0);
    });
});

it('never leaves app.bypass_tenant set behind inside a transaction it does not own', function (): void {
    $this->makeTenant();
    $this->makeTenant();

    $owner = DB::connection('pgsql_owner');

    $owner->transaction(function () use ($owner): void {
        try {
            $inside = withoutTenantScope(
                'task-1 regression: a nested bypass must not outlive its block',
                fn () => $owner->table('tenants')->count(),
            );

            // The bypass itself still has to work, or the test proves nothing.
            expect($inside)->toBe(2);
        } catch (TenantContextException) {
            // refused is also a pass: nothing was set
        }

        expect($owner->selectOne("select coalesce(current_setting('app.bypass_tenant', true), '') as bypass")->bypass)
            ->toBe('')
            ->and($owner->table('tenants')->count())->toBe(0);
    });
});

/*
 * H-1 and M-3, scoped security review of the task-1 fixes. The F1 fix guarded one
 * direction only: withoutTenantScope() cleared app.tenant_id, but withTenant() did
 * nothing about an active app.bypass_tenant -- and because every owner_bypass_*
 * policy is permissive, a scoped block inside a bypass was not scoped at all.
 */

it('refuses to open a tenant context while a bypass is active', function (): void {
    $alpha = $this->makeTenant();
    $owner = DB::connection('pgsql_owner');

    withoutTenantScope('task-1 regression: refuse to scope inside a bypass', function () use ($owner, $alpha): void {
        expect(fn () => withTenant($alpha, fn () => $owner->table('tenants')->count(), 'pgsql_owner'))
            ->toThrow(TenantContextException::class, 'while a tenant bypass is active');
    }, 'pgsql_owner');
});

it('never runs an unscoped query inside a bypass block', function (): void {
    // Written fix-agnostically: refusing and correctly re-scoping both pass, running
    // unscoped does not. Before the fix this reported every tenant visible and a
    // committed cross-tenant write.
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $owner = DB::connection('pgsql_owner');

    withoutTenantScope('task-1 regression: a bypass must not leak into a scoped block', function () use ($owner, $alpha, $beta): void {
        try {
            $visible = withTenant($alpha, fn () => $owner->table('tenants')->count(), 'pgsql_owner');
            $affected = withTenant($alpha, fn () => $owner->table('tenants')
                ->where('id', $beta)
                ->update(['timezone' => 'Pacific/Auckland']), 'pgsql_owner');

            expect($visible)->toBe(1)
                ->and($affected)->toBe(0);
        } catch (TenantContextException) {
            // Refused is a pass: nothing ran unscoped.
        }
    }, 'pgsql_owner');

    expect(withoutTenantScope(
        'task-1 regression: read the other tenant back',
        fn () => $owner->table('tenants')->where('id', $beta)->value('timezone'),
        'pgsql_owner',
    ))->not->toBe('Pacific/Auckland');
});

it('returns the callback result when the callback handled a database error itself', function (): void {
    // The callback swallows a QueryException, so it returns normally while the Postgres
    // transaction is already aborted. The GUC restore then raises 25P02 -- which must
    // not surface, because it would discard this result and put the set_config
    // statement and its bound tenant id into the message (§4).
    $tenant = $this->makeTenant();

    $result = withTenant($tenant, function (): string {
        try {
            DB::select('select 1 / 0');
        } catch (QueryException) {
            // handled here on purpose
        }

        return 'callback-finished';
    });

    expect($result)->toBe('callback-finished');
});
