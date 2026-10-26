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
    // M-2. Replacing the connection mid-callback resets Laravel's transaction counter,
    // so the COMMIT became a no-op; measured before the fix, run() returned
    // 'callback-finished' with the write gone and nothing raised.
    //
    // Scoped deliberately to THAT shape; the other routes to the same property have
    // their own tests below -- a callback that leaves a transaction open, and one that
    // writes over the tenant setting. (This comment used to say that first gap was
    // open. It was closed in the same range that added the comment, which is how it
    // came to describe the wrong hole -- F11, seventh security review.)
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
    // The format check, exercised on its own. It has to be set from INSIDE a live
    // scope: with no scope open the provenance check added for the sixth review's M-1
    // returns null first, and this test would then pass for a reason other than the one
    // it names -- which is the defect this branch has shipped most often.
    $context = app(TenantContext::class);
    $tenant = $this->makeTenant();

    withTenant($tenant, function () use ($context, $tenant): void {
        DB::select('select set_config(?, ?, true)', ['app.tenant_id', 'not-a-uuid']);

        expect($context->current())->toBeNull();

        // Put it back before the block ends, or clearScope()'s read-back refuses the
        // whole scope -- which is the M-2 postcondition doing its job, not a problem.
        DB::select('select set_config(?, ?, true)', ['app.tenant_id', $tenant]);
    });
});

it('reports no tenant for a setting no scope in this process opened', function (): void {
    // (a), sixth security review M-1: current() had a format check and no provenance
    // check, so it answered with a session-scoped value that run() refuses as a hard
    // failure in the same breath -- the two entry points disagreeing about one value.
    // §4 › Realtime makes this the Redis namespace and Task 4 builds keys from it, so
    // the answer reaching a key is a cross-tenant key with nothing behind it.
    $context = app(TenantContext::class);
    $beta = $this->makeTenant();

    Composition::setSessionGuc('pgsql', 'app.tenant_id', $beta);

    try {
        expect($context->current())->toBeNull();

        // And the two entry points now agree about that same value.
        expect(fn () => withTenant($beta, fn () => true))
            ->toThrow(TenantContextException::class, 'no tenant scope in this process opened it');
    } finally {
        DB::purge('pgsql');
    }
});

it('reports no tenant for a connection it holds no scope on', function (): void {
    $context = app(TenantContext::class);
    $tenant = $this->makeTenant();

    withTenant($tenant, function () use ($context): void {
        // The scope is on pgsql. Asking about any other connection -- including one
        // row level security does not apply to -- is answered without a round trip.
        expect($context->current('pgsql_owner'))->toBeNull()
            ->and($context->current('pgsql_root'))->toBeNull()
            ->and($context->current())->not->toBeNull();
    });
});

it('never reports success when the callback leaves a transaction open', function (): void {
    // (b), sixth security review M-2: the third route to H-B's property failing.
    // Measured before the fix: run() returned 'finished', the scoped write was gone,
    // nothing was raised, transactionLevel() was left at 1 on the pooled connection,
    // and a second withTenant on the same singleton SUCCEEDED by nesting into the
    // first one's transaction. clearScope()'s read-back cannot see this -- the GUC
    // still reads correctly inside the still-open transaction.
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $reference = 'DANGLING-'.Str::random(6);

    $raised = null;
    $returned = null;

    try {
        $returned = withTenant($alpha, function () use ($alpha, $reference): string {
            DB::table('outbox')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $alpha,
                'type' => 'pager.ready',
                'dedupe_key' => $reference,
            ]);

            DB::beginTransaction();   // never committed, never rolled back

            return 'finished';
        });
    } catch (TenantContextException $e) {
        $raised = $e;
    }

    $persisted = withTenant($alpha, fn () => DB::table('outbox')->where('dedupe_key', $reference)->exists());

    // The H-B property, stated the same way as the other two routes to it.
    expect($persisted || $raised !== null)->toBeTrue(
        'withTenant returned '.var_export($returned, true).' and the write is gone',
    );

    // And the connection does not go back to the pool poisoned: no open transaction,
    // no tenant setting, and the next scope on it is a scope rather than a savepoint
    // inside the last request's transaction.
    expect(DB::connection('pgsql')->transactionLevel())->toBe(0)
        ->and(Composition::tenantGuc('pgsql'))->toBe('');

    withTenant($beta, function (): void {
        expect(DB::transactionLevel())->toBe(1);
    });

    if ($raised !== null) {
        expect($raised->getMessage())->not->toContain('set_config')
            ->and($raised->getMessage())->not->toContain('select ')
            ->and($raised->getPrevious())->toBeNull();
    }
});

it('cannot have a refusal message forge a log line', function (): void {
    // (c), sixth security review L-1. Measured: with the setting carrying an embedded
    // newline, the message and the Monolog line built from it carried the forged
    // content verbatim. An exception message reaches the log, so a newline in it is a
    // log entry written by whoever set the value.
    $vector = "injected\nFAKE LOG LINE level=emergency token=secret";

    Composition::setSessionGuc('pgsql', 'app.tenant_id', $vector);

    try {
        withTenant($this->makeTenant(), fn () => true);
        $this->fail('a tenant scope opened on a setting this process did not open');
    } catch (TenantContextException $e) {
        $message = $e->getMessage();

        // The control characters are escaped, not the content removed: the forged text
        // survives as inert text on one line, which is the point -- it can no longer be
        // a log entry of its own.
        expect(preg_split('/\R/', $message))->toHaveCount(1)
            ->and($message)->not->toContain("\n")
            ->and($message)->not->toContain("\r")
            ->and($message)->toContain('no tenant scope in this process opened it');
    } finally {
        DB::purge('pgsql');
    }
});

it('bounds a refusal message built from a value it did not write', function (): void {
    Composition::setSessionGuc('pgsql', 'app.tenant_id', str_repeat('A', 8192));

    try {
        withTenant($this->makeTenant(), fn () => true);
        $this->fail('a tenant scope opened on a setting this process did not open');
    } catch (TenantContextException $e) {
        expect(strlen($e->getMessage()))->toBeLessThan(600);
    } finally {
        DB::purge('pgsql');
    }
});

it('never reports success when something in the block wrote over the tenant setting', function (): void {
    // clearScope()'s read-back, on its own. Adding the depth postcondition for (b)
    // took over both routes the read-back used to catch, so removing its comparison
    // left 189 tests passing -- the same way the sixth round orphaned setGuc()'s
    // catch. This is the shape only the read-back can see: the same transaction depth,
    // with the setting overwritten underneath, so every query after that point ran
    // under a tenant the caller never chose.
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $reference = 'OVERWRITTEN-'.Str::random(6);

    $raised = null;
    $returned = null;

    try {
        $returned = withTenant($alpha, function () use ($alpha, $beta, $reference): string {
            DB::table('outbox')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $alpha,
                'type' => 'pager.ready',
                'dedupe_key' => $reference,
            ]);

            // What §4's repo guard is specified to reject and R-010 does not yet exist
            // to reject: a raw set_config inside a block.
            DB::select('select set_config(?, ?, true)', ['app.tenant_id', $beta]);

            return 'finished';
        });
    } catch (TenantContextException $e) {
        $raised = $e;
    }

    $persisted = withTenant($alpha, fn () => DB::table('outbox')->where('dedupe_key', $reference)->exists());

    // NOT the H-B property this time, and the difference is the whole point: here the
    // write does survive, so "either the write survives or something is raised" is
    // satisfied trivially and asserting it proves nothing -- measured, it passes with
    // the read-back deleted. The property that matters is the contract: a block either
    // ran under its caller's tenant throughout, or it reports. So the refusal itself is
    // the assertion, and the rollback is its consequence.
    expect($raised)->not->toBeNull(
        'withTenant returned '.var_export($returned, true).' after the block changed tenant underneath it',
    );
    expect($persisted)->toBeFalse('the refusal did not roll the block back');

    if ($raised !== null) {
        expect($raised->getMessage())->toContain('is not the value this tenant scope set')
            // The value is not named: it is not the caller's tenant.
            ->and($raised->getMessage())->not->toContain($beta)
            ->and($raised->getMessage())->not->toContain('set_config')
            ->and($raised->getPrevious())->toBeNull();
    }
});

it('never unwinds past the depth the caller was already at', function (): void {
    // F1, seventh security review -- a High introduced by the previous round's own fix.
    // The reset loop unwound to 0 instead of to the depth the scope opened at, so a
    // scope nested inside an application transaction took the CALLER's transaction with
    // it. §2.10 says every migration body is already at level 1, so that is the normal
    // case, not an exotic one. Measured before the fix: the caller was left at depth 0,
    // its earlier write was gone, its later writes autocommitted one at a time, and
    // because the caller caught the refusal -- the shape §2.10's own loop uses -- its
    // transaction() returned 'outer-ok' with nothing raised. H-B's failure one level up.
    $alpha = $this->makeTenant();
    $owner = DB::connection('pgsql_owner');
    $first = 'F1-FIRST-'.Str::random(6);
    $second = 'F1-SECOND-'.Str::random(6);

    $observed = $owner->transaction(function () use ($owner, $alpha, $first, $second): array {
        $outer = $owner->transactionLevel();

        withTenant($alpha, fn () => $owner->table('outbox')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $alpha,
            'type' => 'pager.ready', 'dedupe_key' => $first,
        ]), 'pgsql_owner');

        $raised = null;

        try {
            withTenant($alpha, function () {
                DB::connection('pgsql_owner')->beginTransaction();

                return 'inner';
            }, 'pgsql_owner');
        } catch (TenantContextException $e) {
            $raised = $e;
        }

        // The caller swallows it and carries on, which it is entitled to do.
        withTenant($alpha, fn () => $owner->table('outbox')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $alpha,
            'type' => 'pager.ready', 'dedupe_key' => $second,
        ]), 'pgsql_owner');

        return ['outer' => $outer, 'raised' => $raised, 'after' => $owner->transactionLevel()];
    });

    expect($observed['raised'])->not->toBeNull('the dangling transaction was not refused')
        ->and($observed['after'])->toBe(
            $observed['outer'],
            'the refusal unwound past the depth the caller was at',
        );

    // Both of the caller's writes committed together, with its transaction intact.
    $root = $this->asRoot();

    expect($root->table('outbox')->where('dedupe_key', $first)->exists())->toBeTrue()
        ->and($root->table('outbox')->where('dedupe_key', $second)->exists())->toBeTrue();
});

it('never reports success when a bypass callback leaves a transaction open', function (): void {
    // F2, seventh security review: the postcondition went to run() and not to the
    // bypass, so the one path that is cross-tenant by design was still M-2. Measured
    // before the fix: withoutTenantScope returned 'finished', its own audit_log row
    // was uncommitted and invisible to another session, and the connection went back
    // at depth 1 -- where the next scope on it opened as a savepoint inside it.
    $reason = 'F2 regression '.Str::random(6);

    $raised = null;
    $returned = null;

    try {
        $returned = withoutTenantScope($reason, function (): string {
            DB::connection('pgsql_owner')->beginTransaction();

            return 'finished';
        }, 'pgsql_owner');
    } catch (TenantContextException $e) {
        $raised = $e;
    }

    expect($raised)->not->toBeNull('withoutTenantScope returned '.var_export($returned, true));

    // The audit row went with it, and the connection is clean for the next caller.
    expect(DB::connection('pgsql_owner')->transactionLevel())->toBe(0)
        ->and($this->asRoot()->table('audit_log')->where('context->reason', $reason)->count())->toBe(0);

    withoutTenantScope('F2 follow-up', function (): void {
        expect(DB::connection('pgsql_owner')->transactionLevel())->toBe(1);
    }, 'pgsql_owner');
});

it('keeps a driver code from forging a log line on its way into a message', function (): void {
    // F8: $sqlstate is whatever Throwable escaped a statement call, so it is text this
    // class did not produce. Five alphanumerics when it comes from PDO -- and nothing
    // guarantees it comes from PDO.
    $message = TenantContextException::statementFailed('read', "42501\nFAKE LOG LINE level=emergency")
        ->getMessage();

    expect(preg_split('/\R/', $message))->toHaveCount(1)
        ->and($message)->toContain('42501');
});

it('tells the two depth failures apart, because the remedies differ', function (): void {
    // F7: the shallower and deeper branches carry different refusals on purpose -- a
    // commit inside the callback has already committed, a dangling transaction has not
    // -- and collapsing the conditional so both raise one of them left the suite green.
    $tenant = $this->makeTenant();

    $shallower = null;
    try {
        withTenant($tenant, function (): void {
            DB::commit();
        });
    } catch (TenantContextException $e) {
        $shallower = $e->getMessage();
    }

    $deeper = null;
    try {
        withTenant($tenant, function (): void {
            DB::beginTransaction();
        });
    } catch (TenantContextException $e) {
        $deeper = $e->getMessage();
    }

    expect($shallower)->toContain('was no longer in force')
        // And it must not claim the work is gone: measured, on this path it is committed.
        ->and($shallower)->not->toContain('has been discarded')
        ->and($shallower)->toContain('not safe to retry blindly')
        ->and($deeper)->toContain('transaction depth')
        ->and($deeper)->not->toContain('was no longer in force');
});
