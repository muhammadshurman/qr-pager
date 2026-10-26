<?php

declare(strict_types=1);

use App\Platform\Database\DatabaseRoles;
use App\Platform\Database\TenantContextException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Composition;

/*
 * Every composition order of withTenant() and withoutTenantScope(), both nesting
 * directions, same connection and cross connection, with and without an outer
 * transaction.
 *
 * This file exists because four fix rounds each guarded one shape and the next
 * composition walked around it (F1 -> H-1 -> H-A). The matrix is the point: a new
 * guard that only covers the shape its author imagined fails here.
 *
 * The connection constants and the probes live in Tests\Support\Composition: a
 * different Laravel connection is a different Postgres session, so a
 * transaction-local GUC set on one is invisible to the others.
 */

it('allows re-entry with the same tenant on the same connection, and stays scoped', function (): void {
    $alpha = $this->makeTenant();
    $this->makeTenant();

    $seen = withTenant($alpha, fn () => withTenant(
        $alpha, fn () => DB::connection(Composition::OWNER)->table('tenants')->count(), Composition::OWNER,
    ), Composition::OWNER);

    expect($seen)->toBe(1);
});

it('refuses a different tenant on the same connection', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();

    expect(fn () => withTenant($alpha, fn () => withTenant($beta, fn () => true, Composition::OWNER), Composition::OWNER))
        ->toThrow(TenantContextException::class, 'cannot be changed');
});

it('refuses to reuse an open scope on another connection', function (): void {
    $alpha = $this->makeTenant();

    expect(fn () => withTenant($alpha, fn () => withTenant($alpha, fn () => true, Composition::OWNER), Composition::APP))
        ->toThrow(TenantContextException::class, 'cannot be reused');
});

it('refuses a bypass opened inside a tenant scope on the same connection', function (): void {
    $alpha = $this->makeTenant();

    expect(fn () => withTenant($alpha, fn () => withoutTenantScope('nested bypass', fn () => true, Composition::OWNER), Composition::OWNER))
        ->toThrow(TenantContextException::class, 'while a tenant context is open');
});

it('refuses a bypass opened inside a tenant scope on another connection', function (): void {
    $alpha = $this->makeTenant();

    expect(fn () => withTenant($alpha, fn () => withoutTenantScope('nested bypass', fn () => true, Composition::OWNER), Composition::APP))
        ->toThrow(TenantContextException::class, 'while a tenant context is open');
});

it('refuses a tenant scope opened inside a bypass on the same connection', function (): void {
    $alpha = $this->makeTenant();

    expect(fn () => withoutTenantScope(
        'scope inside bypass', fn () => withTenant($alpha, fn () => true, Composition::OWNER), Composition::OWNER,
    ))->toThrow(TenantContextException::class, 'while a tenant bypass is active');
});

it('allows a tenant scope inside a bypass on another connection, and it is really scoped', function (): void {
    // Different Postgres session, so the bypass GUC is not set there at all. The app
    // role is also granted no owner_bypass_* policy, so this must behave as normal.
    $alpha = $this->makeTenant();
    $this->makeTenant();

    $seen = withoutTenantScope(
        'cross-connection scope',
        fn () => withTenant($alpha, fn () => DB::connection(Composition::APP)->table('tenants')->count(), Composition::APP),
        Composition::OWNER,
    );

    expect($seen)->toBe(1)
        ->and(Composition::bypassGuc(Composition::APP))->toBe('');
});

it('allows a bypass inside a bypass and restores the outer value', function (): void {
    $this->makeTenant();
    $this->makeTenant();

    withoutTenantScope('outer bypass', function (): void {
        expect(Composition::bypassGuc(Composition::OWNER))->toBe(DatabaseRoles::bypassValue());

        $seen = withoutTenantScope(
            'inner bypass', fn () => DB::connection(Composition::OWNER)->table('tenants')->count(), Composition::OWNER,
        );

        expect($seen)->toBe(2)
            ->and(Composition::bypassGuc(Composition::OWNER))->toBe(DatabaseRoles::bypassValue());
    }, Composition::OWNER);

    expect(Composition::bypassGuc(Composition::OWNER))->toBe('');
});

it('refuses the H-A shape: scope, then bypass, then scope on one connection', function (): void {
    $alpha = $this->makeTenant();

    expect(fn () => withTenant($alpha, fn () => withoutTenantScope(
        'H-A shape', fn () => withTenant($alpha, fn () => true, Composition::OWNER), Composition::OWNER,
    ), Composition::OWNER))->toThrow(TenantContextException::class, 'while a tenant context is open');
});

it('checks the bypass before the re-entry short-circuit, not after', function (): void {
    // Defence in depth, with a deliberately synthetic setup. The helpers now refuse to
    // build a state where both GUCs are set, so this sets them directly -- raw
    // set_config in a test only -- to pin the ORDER of the two guards. If the bypass
    // check ever moves below the re-entry short-circuit, a caller holding the same
    // tenant short-circuits into a block that is not scoped, which is exactly the
    // route H-A took to an unscoped query and a committed cross-tenant write.
    $alpha = $this->makeTenant();
    $this->makeTenant();
    $owner = DB::connection(Composition::OWNER);

    $owner->transaction(function () use ($owner, $alpha): void {
        $owner->select('select set_config(?, ?, true)', ['app.tenant_id', $alpha]);
        $owner->select('select set_config(?, ?, true)', ['app.bypass_tenant', DatabaseRoles::bypassValue()]);

        expect(fn () => withTenant($alpha, fn () => $owner->table('tenants')->count(), Composition::OWNER))
            ->toThrow(TenantContextException::class, 'while a tenant bypass is active');
    });
});

it('never yields an unscoped block from any nesting of the two helpers', function (): void {
    // Fix-agnostic: asserts the security property, not the mechanism. Refusing and
    // correctly scoping both pass; reading another tenant's row, or writing one, does
    // not. Before the fifth round the third shape below read every tenant and
    // committed a cross-tenant write.
    //
    // It also pins WHICH outcome each shape produces, because as a pure
    // refused-or-scoped sweep it asserted nothing at all: every shape refuses today,
    // so each iteration landed in the catch, both expectations were skipped and the
    // closing readback passed on a block that had never run (L-3, fifth security
    // review). A shape that starts running instead of refusing is now a failure here,
    // and whoever makes that change has to prove the block is scoped before updating
    // the pin.
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $owner = DB::connection(Composition::OWNER);

    $shapes = [
        'bypass -> scope' => fn () => withoutTenantScope('p1', fn () => withTenant($alpha, fn () => Composition::probe($owner, $beta), Composition::OWNER), Composition::OWNER),
        'scope -> bypass' => fn () => withTenant($alpha, fn () => withoutTenantScope('p2', fn () => Composition::probe($owner, $beta), Composition::OWNER), Composition::OWNER),
        'scope -> bypass -> scope' => fn () => withTenant($alpha, fn () => withoutTenantScope('p3', fn () => withTenant($alpha, fn () => Composition::probe($owner, $beta), Composition::OWNER), Composition::OWNER), Composition::OWNER),
        'bypass -> bypass -> scope' => fn () => withoutTenantScope('p4', fn () => withoutTenantScope('p5', fn () => withTenant($alpha, fn () => Composition::probe($owner, $beta), Composition::OWNER), Composition::OWNER), Composition::OWNER),
    ];

    $outcomes = [];

    foreach ($shapes as $name => $shape) {
        try {
            [$visible, $affected] = $shape();

            expect($visible)->toBe(1, "{$name}: saw {$visible} tenants inside a scoped block")
                ->and($affected)->toBe(0, "{$name}: a keyed cross-tenant UPDATE affected {$affected} rows");

            $outcomes[$name] = 'scoped';
        } catch (TenantContextException) {
            // Refused is a pass: nothing ran unscoped.
            $outcomes[$name] = 'refused';
        }
    }

    // Every shape reached a definite outcome, and that outcome is the one recorded in
    // PLAN.md §4a. Without this the loop above was satisfiable by refusing everything.
    expect($outcomes)->toBe([
        'bypass -> scope' => 'refused',
        'scope -> bypass' => 'refused',
        'scope -> bypass -> scope' => 'refused',
        'bypass -> bypass -> scope' => 'refused',
    ]);

    // And nothing reached the other tenant's row in any shape.
    expect(withoutTenantScope('readback', fn () => $owner->table('tenants')->where('id', $beta)->value('timezone'), Composition::OWNER))
        ->not->toBe('Pacific/Auckland');
});

it('holds every refusal inside an outer transaction too, and leaves no residue', function (): void {
    // Every migration body runs at transaction level 1 (PostgresGrammar reports
    // supportsSchemaTransactions), so the refusals have to hold there, and a refused
    // attempt must not leave the enclosing transaction carrying a GUC.
    $alpha = $this->makeTenant();
    $owner = DB::connection(Composition::OWNER);

    $owner->transaction(function () use ($owner, $alpha): void {
        expect($owner->transactionLevel())->toBe(1);

        expect(fn () => withTenant($alpha, fn () => withoutTenantScope('t1', fn () => true, Composition::OWNER), Composition::OWNER))
            ->toThrow(TenantContextException::class, 'while a tenant context is open');

        expect(fn () => withoutTenantScope('t2', fn () => withTenant($alpha, fn () => true, Composition::OWNER), Composition::OWNER))
            ->toThrow(TenantContextException::class, 'while a tenant bypass is active');

        expect((string) $owner->selectOne("select coalesce(current_setting('app.tenant_id', true), '') as v")->v)->toBe('')
            ->and(Composition::bypassGuc(Composition::OWNER))->toBe('')
            ->and($owner->table('tenants')->count())->toBe(0);
    });

    expect(Composition::bypassGuc(Composition::OWNER))->toBe('');
});

it('scopes correctly inside an outer transaction, and clears on the way out', function (): void {
    $alpha = $this->makeTenant();
    $this->makeTenant();
    $owner = DB::connection(Composition::OWNER);

    $owner->transaction(function () use ($owner, $alpha): void {
        $seen = withTenant($alpha, fn () => $owner->table('tenants')->count(), Composition::OWNER);

        expect($seen)->toBe(1)
            ->and((string) $owner->selectOne("select coalesce(current_setting('app.tenant_id', true), '') as v")->v)->toBe('');
    });
});

it('withholds the statement when it cannot even read the context', function (): void {
    // M-A: the pre-check is the first statement run, so on an already-aborted
    // transaction it was the one that failed -- and it propagated a raw QueryException
    // carrying its own SQL, a fresh breach of the clause the H-B fix was about.
    $tenant = $this->makeTenant();
    $owner = DB::connection(Composition::OWNER);

    $owner->transaction(function () use ($owner, $tenant): void {
        try {
            $owner->select('select 1 / 0');
        } catch (QueryException) {
            // the transaction is aborted from here on
        }

        try {
            withTenant($tenant, fn () => true, Composition::OWNER);
            $this->fail('a tenant scope opened on an aborted transaction');
        } catch (TenantContextException $e) {
            expect($e->getMessage())->toContain('could not be read')
                ->and($e->getMessage())->toContain('25P02')
                ->and($e->getMessage())->not->toContain('current_setting')
                ->and($e->getMessage())->not->toContain('select ')
                ->and($e->getPrevious())->toBeNull();
        }
    });
});

it('withholds the statement when a bypass cannot read the session state', function (): void {
    // Was "cannot read the current role": the role, both GUCs and the role's RLS
    // attributes now come from one readState() round trip, so the bypass path has one
    // statement site here where it had two, and this is the one that fails first.
    $owner = DB::connection(Composition::OWNER);

    $owner->transaction(function () use ($owner): void {
        try {
            $owner->select('select 1 / 0');
        } catch (QueryException) {
            // aborted
        }

        try {
            withoutTenantScope('aborted transaction', fn () => true, Composition::OWNER);
            $this->fail('a bypass opened on an aborted transaction');
        } catch (TenantContextException $e) {
            expect($e->getMessage())->toContain('could not be read')
                ->and($e->getMessage())->toContain('25P02')
                ->and($e->getMessage())->not->toContain('current_user')
                ->and($e->getMessage())->not->toContain('select ')
                ->and($e->getPrevious())->toBeNull();
        }
    });
});

it('pins the configured bypass value to the literal every policy compares against', function (): void {
    // L-C: a config change must never be able to silently disarm the refusal. run()
    // refuses on ANY non-empty bypass GUC, so the check itself is config-independent;
    // this pins the value used to SET it against what the policies actually read.
    $value = DatabaseRoles::bypassValue();

    $policies = DB::connection(Composition::OWNER)->select(
        "select policyname, qual, with_check from pg_policies
         where schemaname = 'public' and policyname like 'owner_bypass_%' order by policyname"
    );

    expect($policies)->toHaveCount(6);

    foreach ($policies as $policy) {
        expect((string) $policy->qual)->toContain("'{$value}'")
            ->and((string) $policy->with_check)->toContain("'{$value}'");
    }
});

/*
 * Shapes the matrix did not reach until the fifth security review.
 */

it('refuses a bypass when a tenant setting is in force that it did not open', function (): void {
    // L-2: the GUC half of the bypass's "not inside a tenant scope" check had no
    // discriminating test. Both of its existing tests are also caught by the
    // $openScopes half, so deleting the GUC half left the suite green. This is the
    // one shape only the GUC half can catch: a session-scoped value, with no scope
    // open anywhere in this process.
    $this->makeTenant();

    Composition::setSessionGuc(Composition::OWNER, 'app.tenant_id', (string) Str::uuid7());

    expect(fn () => withoutTenantScope('L-2', fn () => true, Composition::OWNER))
        ->toThrow(TenantContextException::class, 'while a tenant context is open');
});

it('refuses to reuse a scope on the worker connection, the other runtime role', function (): void {
    // L-4: the matrix never touched pgsql_worker, which is one of the two runtime
    // roles and owns the §4 cross-tenant expiry path Task 3 builds on.
    $alpha = $this->makeTenant();

    expect(fn () => withTenant($alpha, fn () => withTenant($alpha, fn () => true, Composition::WORKER), Composition::APP))
        ->toThrow(TenantContextException::class, 'cannot be reused');
});

it('scopes a block on the worker connection just as tightly as on the app one', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $worker = DB::connection(Composition::WORKER);

    $seen = withTenant($alpha, fn () => $worker->table('tenants')->count(), Composition::WORKER);

    expect($seen)->toBe(1)
        ->and(Composition::tenantGuc(Composition::WORKER))->toBe('')
        ->and(withTenant($alpha, fn () => $worker->table('tenants')->where('id', $beta)->count(), Composition::WORKER))
        ->toBe(0);
});

it('withholds the statement when it cannot set the tenant setting', function (): void {
    // setGuc()'s catch had no discriminating test after the sixth round moved the
    // clear into clearScope(): the H-B durability test had been the only thing
    // reaching it, and it now reaches clearScope() instead. Measured — replacing
    // setGuc()'s whole catch with `throw $e;` left 183 passing (M-3, sixth security
    // review), so §6's "a regression test that fails without the fix" was not met for
    // the one site that carries the tenant id as a binding.
    //
    // The vector is a GUC name Postgres will read and refuse to write:
    // current_setting('nodot', true) returns NULL -- so readState() succeeds and
    // coalesces to '' -- while set_config('nodot', ...) raises 42704. That lands on
    // setGuc() and nowhere else.
    $tenant = $this->makeTenant();

    config(['tenancy.guc.tenant' => 'nodot']);

    try {
        withTenant($tenant, fn () => true);
        $this->fail('a tenant scope opened with an unwritable GUC name');
    } catch (TenantContextException $e) {
        expect($e->getMessage())->toContain('could not be set')
            ->and($e->getMessage())->toContain('42704')
            // The leaked QueryException would carry the statement AND the tenant id,
            // which Laravel interpolates into the message it builds.
            ->and($e->getMessage())->not->toContain('set_config')
            ->and($e->getMessage())->not->toContain('select ')
            ->and($e->getMessage())->not->toContain($tenant)
            ->and($e->getPrevious())->toBeNull();
    }
});

/*
 * S-1 and S-6, eighth security review -- the last findings that touch the invariant
 * Task 1 ships on.
 */

it('refuses a bypass after a nested scope exits inside a live one', function (string $derail): void {
    // S-1. No set_config in any branch below, which is the point: the GUC does not have
    // to be overwritten, only emptied, and three ordinary callback calls empty a
    // transaction-local setting. The nested withTenant() then stops short-circuiting as
    // re-entry, takes the transaction path, and -- when $openScopes was a boolean --
    // its finally deleted the record of the outer scope still running around it. Both
    // halves of the bypass guard then passed. Measured before the fix, identically on
    // all three routes: the bypass read 2 of 2 tenants and its keyed cross-tenant
    // UPDATE committed, confirmed from an independent superuser session.
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $owner = DB::connection(Composition::OWNER);
    // Unique per run, deliberately. audit_log is never truncated (R-016), so a reason
    // that repeats across runs matches rows a PREVIOUS run wrote -- and a run made
    // while this very vulnerability was live leaves exactly the row this test looks
    // for. Found by reverting the fix twice: the second revert "failed" on rows from
    // the first. That is R-016's predicted shape, arriving in the test written to prove
    // the fix for S-1.
    $reason = 'S-1 '.$derail.' '.Str::random(10);

    $bypassOpened = false;

    try {
        withTenant($alpha, function () use ($alpha, $beta, $owner, $reason, $derail, &$bypassOpened): void {
            // The scope really is narrowed at this point, or the test proves nothing.
            expect(Composition::probe($owner, $beta))->toBe([1, 0]);

            match ($derail) {
                'commit' => DB::connection(Composition::OWNER)->commit(),
                'rollback' => DB::connection(Composition::OWNER)->rollBack(),
                'purge' => DB::purge(Composition::OWNER),
            };

            withTenant($alpha, fn () => true, Composition::OWNER);

            withoutTenantScope($reason, function () use (&$bypassOpened): void {
                $bypassOpened = true;
            }, Composition::OWNER);
        }, Composition::OWNER);
    } catch (TenantContextException) {
        // Refused somewhere is a pass; what must not happen is the bypass running.
    }

    expect($bypassOpened)->toBeFalse("a bypass opened inside a live tenant scope via {$derail}");

    // And nothing crossed the tenant boundary, checked from a session that can see it all.
    expect($this->asRoot()->table('tenants')->where('id', $beta)->value('timezone'))
        ->not->toBe('Pacific/Auckland')
        ->and($this->asRoot()->table('audit_log')->where('context->reason', $reason)->count())->toBe(0);
})->with(['commit', 'rollback', 'purge']);

it('never reports success when a bypass callback commits and reopens', function (): void {
    // S-6: the depth postcondition cannot see this -- commit() then beginTransaction()
    // leaves the depth matching, while the transaction-local bypass GUC reverted at the
    // COMMIT. Measured before the fix: the second half of the block read [0, 0] with no
    // bypass in force and withoutTenantScope returned successfully, which is §2.10's
    // H-1 silent no-op for a pattern-2 backfill.
    $this->makeTenant();
    $this->makeTenant();
    $owner = DB::connection(Composition::OWNER);

    $raised = null;
    $returned = null;
    $after = null;

    try {
        $returned = withoutTenantScope('S-6', function () use ($owner, &$after): string {
            $owner->commit();
            $owner->beginTransaction();

            $after = $owner->table('tenants')->count();

            return 'finished';
        }, Composition::OWNER);
    } catch (TenantContextException $e) {
        $raised = $e;
    }

    expect($raised)->not->toBeNull(
        'withoutTenantScope returned '.var_export($returned, true)." with the bypass gone (saw {$after} tenants)",
    );
    expect($raised->getMessage())->toContain('was no longer in force')
        ->and($raised->getPrevious())->toBeNull();
});
