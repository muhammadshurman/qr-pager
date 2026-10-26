<?php

declare(strict_types=1);

use App\Platform\Database\TenantContext;
use App\Platform\Database\TenantContextException;
use App\Platform\Database\UnsafeDatabaseRoleException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Composition;

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

it('never reports success when the work was rolled back', function (): void {
    // H-B, fix-agnostic: this asserts the property, not the mechanism. The callback
    // catches a QueryException and returns -- the shape Task 3's idempotency pattern
    // will use (catch 23505, treat as already-done) -- which leaves the Postgres
    // transaction aborted. Swallowing the clear let this return normally while the
    // write was silently rolled back. Either the write survives or something is
    // raised; "returned fine and the write is gone" must never happen.
    $tenant = $this->makeTenant();
    $reference = 'DURABILITY-'.Str::random(6);

    $raised = null;
    $returned = null;

    try {
        $returned = withTenant($tenant, function () use ($tenant, $reference): string {
            DB::table('outbox')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenant,
                'type' => 'pager.ready',
                'dedupe_key' => $reference,
            ]);

            try {
                DB::select('select 1 / 0');
            } catch (QueryException) {
                // handled inside the callback, on purpose
            }

            return 'callback-finished';
        });
    } catch (TenantContextException $e) {
        $raised = $e;
    }

    $persisted = withTenant($tenant, fn () => DB::table('outbox')->where('dedupe_key', $reference)->exists());

    expect($persisted || $raised !== null)->toBeTrue(
        'withTenant returned '.var_export($returned, true).' and the write is gone',
    );

    // Whatever is raised must carry no statement text and no bindings (§4).
    if ($raised !== null) {
        expect($raised->getMessage())->not->toContain('set_config')
            ->and($raised->getMessage())->not->toContain('select ')
            ->and($raised->getPrevious())->toBeNull();
    }
});

/*
 * H-C, M-1, M-2 and M-3 -- fifth security review, of the fifth round's own diff.
 */

it('refuses to open a tenant scope on a connection row level security cannot narrow', function (): void {
    // H-C. The GUC is set correctly and means nothing: a superuser ignores ENABLE and
    // FORCE row level security entirely, so the block reads every tenant and commits a
    // cross-tenant write while every call site reads as correctly scoped. Measured
    // before the fix: 2 of 2 tenants visible, keyed cross-tenant UPDATE committed.
    $alpha = $this->makeTenant();
    $this->makeTenant();
    $reached = false;

    expect(function () use ($alpha, &$reached): void {
        withTenant($alpha, function () use (&$reached): void {
            $reached = true;
        }, 'pgsql_root');
    })->toThrow(UnsafeDatabaseRoleException::class, 'ignores FORCE row level security');

    expect($reached)->toBeFalse('the callback ran on a connection that ignores row level security');
});

it('refuses a bypass on a connection row level security cannot narrow either', function (): void {
    // H-C on the other helper. The bypass is the named, audited cross-tenant path; a
    // role that ignores row level security makes every other path a cross-tenant one.
    expect(fn () => withoutTenantScope('H-C', fn () => true, 'pgsql_root'))
        ->toThrow(UnsafeDatabaseRoleException::class, 'ignores FORCE row level security');
});

it('refuses to scope on the app connection if that role is ever granted BYPASSRLS', function (): void {
    // Proven against the real cluster rather than asserted, and it is the reachable
    // half of H-C: the connection the application actually serves traffic on, with the
    // one attribute that silently removes tenant isolation.
    $alpha = $this->makeTenant();
    $root = $this->asRoot();
    $root->unprepared('alter role qrpager_app bypassrls');

    try {
        expect(fn () => withTenant($alpha, fn () => true))
            ->toThrow(UnsafeDatabaseRoleException::class, 'it holds BYPASSRLS');
    } finally {
        $root->unprepared('alter role qrpager_app nobypassrls');
        DB::purge('pgsql');
    }

    // And the guard is not merely refusing everything.
    expect(withTenant($alpha, fn () => DB::table('tenants')->count()))->toBe(1);
});

it('still lets the owner connection scope, which §2.10 depends on', function (): void {
    // The guard that closes H-C must NOT be assertSafe()'s full set: that refuses the
    // owner (not a runtime role, holds CREATE on schema public), and §2.10's
    // per-tenant loop runs inside migrations, which run as the owner. This is the
    // clause that would have broken.
    $alpha = $this->makeTenant();
    $this->makeTenant();

    expect(withTenant($alpha, fn () => DB::connection('pgsql_owner')->table('tenants')->count(), 'pgsql_owner'))
        ->toBe(1);
});

it('still refuses a bypass on a non-owner connection, ahead of which the H-C check sits', function (): void {
    expect(fn () => withoutTenantScope('owner only', fn () => true, 'pgsql'))
        ->toThrow(TenantContextException::class, 'may only run as');
});

it('refuses a tenant setting that no scope in this process opened', function (): void {
    // M-1, and C2 verbatim: "a session-scoped tenant setting is a hard failure".
    // Making the GUC the only source of truth made run() adopt one instead: it
    // short-circuited as re-entry, so the callback ran with NO transaction and the
    // value was never cleared. Measured before the fix: transactionLevel() 0 inside
    // the callback, a write surviving a deliberate throw, and the context still in
    // force after run() returned.
    $alpha = $this->makeTenant();
    $reached = false;

    Composition::setSessionGuc('pgsql', 'app.tenant_id', $alpha);

    try {
        expect(function () use ($alpha, &$reached): void {
            withTenant($alpha, function () use (&$reached): void {
                $reached = true;
            });
        })->toThrow(TenantContextException::class, 'no tenant scope in this process opened it');

        expect($reached)->toBeFalse('the callback ran outside any transaction this helper opened');

        // Refused, not silently tidied up: clearing someone else's session value would
        // hide the misconfiguration that produced it.
        expect(Composition::tenantGuc('pgsql'))->toBe($alpha);
    } finally {
        DB::purge('pgsql');
    }
});

it('runs every callback inside a transaction it opened itself', function (): void {
    // The invariant M-1 broke, asserted on each connection the helper accepts rather
    // than only the default one.
    foreach (['pgsql', 'pgsql_owner', 'pgsql_worker'] as $connection) {
        $tenant = $this->makeTenant();

        withTenant($tenant, function () use ($connection): void {
            expect(DB::connection($connection)->transactionLevel())->toBeGreaterThanOrEqual(1);
        }, $connection);
    }
});

it('never reports success when the connection under an open scope was replaced', function (): void {
    // M-2, fix-agnostic and the same property as the H-B test above: either the write
    // survives or something is raised. Replacing the connection mid-callback resets
    // Laravel's transaction counter, so the COMMIT became a no-op; measured before the
    // fix, run() returned 'callback-finished' with the write gone and nothing raised.
    $tenant = $this->makeTenant();
    $reference = 'RECONNECT-'.Str::random(6);

    $raised = null;
    $returned = null;

    try {
        $returned = withTenant($tenant, function () use ($tenant, $reference): string {
            DB::table('outbox')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenant,
                'type' => 'pager.ready',
                'dedupe_key' => $reference,
            ]);

            // What a reconnect, or DB::purge() from anywhere else in the process, does.
            DB::connection('pgsql')->disconnect();

            return 'callback-finished';
        });
    } catch (TenantContextException $e) {
        $raised = $e;
    }

    $persisted = withTenant($tenant, fn () => DB::table('outbox')->where('dedupe_key', $reference)->exists());

    expect($persisted || $raised !== null)->toBeTrue(
        'withTenant returned '.var_export($returned, true).' and the write is gone',
    );

    if ($raised !== null) {
        expect($raised->getMessage())->not->toContain('set_config')
            ->and($raised->getMessage())->not->toContain('select ')
            ->and($raised->getPrevious())->toBeNull();
    }
});

it('reports the active tenant for a scope on any connection, not just the default one', function (): void {
    // M-3. current() read a PHP field before the mirror was removed, so it answered
    // the same whatever connection the scope was on. Reading the app connection by
    // default made it return null inside a perfectly good scope on another one --
    // and §4 › Realtime builds Redis keys from exactly this value, so null means one
    // key shared by every tenant.
    $context = app(TenantContext::class);

    foreach (['pgsql', 'pgsql_owner', 'pgsql_worker'] as $connection) {
        $tenant = $this->makeTenant();

        withTenant($tenant, function () use ($context, $tenant, $connection): void {
            expect($context->current())->toBe($tenant, "current() inside a scope on {$connection}");
        }, $connection);

        expect($context->current())->toBeNull();
    }
});

it('reports no tenant when the setting holds something that is not a tenant id', function (): void {
    // run() validates on the way in; current() now validates on the way out, so a
    // value this class never wrote cannot leave here as a tenant id.
    $context = app(TenantContext::class);

    Composition::setSessionGuc('pgsql', 'app.tenant_id', 'not-a-uuid');

    try {
        expect($context->current())->toBeNull();
    } finally {
        DB::purge('pgsql');
    }
});
