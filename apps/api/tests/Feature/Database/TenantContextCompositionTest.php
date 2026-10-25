<?php

declare(strict_types=1);

use App\Platform\Database\DatabaseRoles;
use App\Platform\Database\TenantContextException;
use Illuminate\Support\Facades\DB;

/*
 * Every composition order of withTenant() and withoutTenantScope(), both nesting
 * directions, same connection and cross connection, with and without an outer
 * transaction.
 *
 * This file exists because four fix rounds each guarded one shape and the next
 * composition walked around it (F1 -> H-1 -> H-A). The matrix is the point: a new
 * guard that only covers the shape its author imagined fails here.
 *
 * APP and OWNER are different Laravel connections and therefore different Postgres
 * sessions; set_config(..., true) is transaction-local, so a GUC set on one is
 * invisible to the other.
 */

const APP = 'pgsql';
const OWNER = 'pgsql_owner';

function bypassGuc(string $connection): string
{
    return (string) DB::connection($connection)->selectOne(
        "select coalesce(current_setting('app.bypass_tenant', true), '') as v"
    )->v;
}

it('allows re-entry with the same tenant on the same connection, and stays scoped', function (): void {
    $alpha = $this->makeTenant();
    $this->makeTenant();

    $seen = withTenant($alpha, fn () => withTenant(
        $alpha, fn () => DB::connection(OWNER)->table('tenants')->count(), OWNER,
    ), OWNER);

    expect($seen)->toBe(1);
});

it('refuses a different tenant on the same connection', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();

    expect(fn () => withTenant($alpha, fn () => withTenant($beta, fn () => true, OWNER), OWNER))
        ->toThrow(TenantContextException::class, 'cannot be changed');
});

it('refuses to reuse an open scope on another connection', function (): void {
    $alpha = $this->makeTenant();

    expect(fn () => withTenant($alpha, fn () => withTenant($alpha, fn () => true, OWNER), APP))
        ->toThrow(TenantContextException::class, 'cannot be reused');
});

it('refuses a bypass opened inside a tenant scope on the same connection', function (): void {
    $alpha = $this->makeTenant();

    expect(fn () => withTenant($alpha, fn () => withoutTenantScope('nested bypass', fn () => true, OWNER), OWNER))
        ->toThrow(TenantContextException::class, 'while a tenant context is open');
});

it('refuses a bypass opened inside a tenant scope on another connection', function (): void {
    $alpha = $this->makeTenant();

    expect(fn () => withTenant($alpha, fn () => withoutTenantScope('nested bypass', fn () => true, OWNER), APP))
        ->toThrow(TenantContextException::class, 'while a tenant context is open');
});

it('refuses a tenant scope opened inside a bypass on the same connection', function (): void {
    $alpha = $this->makeTenant();

    expect(fn () => withoutTenantScope(
        'scope inside bypass', fn () => withTenant($alpha, fn () => true, OWNER), OWNER,
    ))->toThrow(TenantContextException::class, 'while a tenant bypass is active');
});

it('allows a tenant scope inside a bypass on another connection, and it is really scoped', function (): void {
    // Different Postgres session, so the bypass GUC is not set there at all. The app
    // role is also granted no owner_bypass_* policy, so this must behave as normal.
    $alpha = $this->makeTenant();
    $this->makeTenant();

    $seen = withoutTenantScope(
        'cross-connection scope',
        fn () => withTenant($alpha, fn () => DB::connection(APP)->table('tenants')->count(), APP),
        OWNER,
    );

    expect($seen)->toBe(1)
        ->and(bypassGuc(APP))->toBe('');
});

it('allows a bypass inside a bypass and restores the outer value', function (): void {
    $this->makeTenant();
    $this->makeTenant();

    withoutTenantScope('outer bypass', function (): void {
        expect(bypassGuc(OWNER))->toBe(DatabaseRoles::bypassValue());

        $seen = withoutTenantScope(
            'inner bypass', fn () => DB::connection(OWNER)->table('tenants')->count(), OWNER,
        );

        expect($seen)->toBe(2)
            ->and(bypassGuc(OWNER))->toBe(DatabaseRoles::bypassValue());
    }, OWNER);

    expect(bypassGuc(OWNER))->toBe('');
});

it('refuses the H-A shape: scope, then bypass, then scope on one connection', function (): void {
    $alpha = $this->makeTenant();

    expect(fn () => withTenant($alpha, fn () => withoutTenantScope(
        'H-A shape', fn () => withTenant($alpha, fn () => true, OWNER), OWNER,
    ), OWNER))->toThrow(TenantContextException::class, 'while a tenant context is open');
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
    $owner = DB::connection(OWNER);

    $owner->transaction(function () use ($owner, $alpha): void {
        $owner->select('select set_config(?, ?, true)', ['app.tenant_id', $alpha]);
        $owner->select('select set_config(?, ?, true)', ['app.bypass_tenant', DatabaseRoles::bypassValue()]);

        expect(fn () => withTenant($alpha, fn () => $owner->table('tenants')->count(), OWNER))
            ->toThrow(TenantContextException::class, 'while a tenant bypass is active');
    });
});

it('never yields an unscoped block from any nesting of the two helpers', function (): void {
    // Fix-agnostic: asserts the security property, not the mechanism. Refusing and
    // correctly scoping both pass; reading another tenant's row, or writing one, does
    // not. Before the fifth round the third shape below read every tenant and
    // committed a cross-tenant write.
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $owner = DB::connection(OWNER);

    $shapes = [
        'bypass -> scope' => fn () => withoutTenantScope('p1', fn () => withTenant($alpha, fn () => probe($owner, $alpha, $beta), OWNER), OWNER),
        'scope -> bypass' => fn () => withTenant($alpha, fn () => withoutTenantScope('p2', fn () => probe($owner, $alpha, $beta), OWNER), OWNER),
        'scope -> bypass -> scope' => fn () => withTenant($alpha, fn () => withoutTenantScope('p3', fn () => withTenant($alpha, fn () => probe($owner, $alpha, $beta), OWNER), OWNER), OWNER),
        'bypass -> bypass -> scope' => fn () => withoutTenantScope('p4', fn () => withoutTenantScope('p5', fn () => withTenant($alpha, fn () => probe($owner, $alpha, $beta), OWNER), OWNER), OWNER),
    ];

    foreach ($shapes as $name => $shape) {
        try {
            [$visible, $affected] = $shape();

            expect($visible)->toBe(1, "{$name}: saw {$visible} tenants inside a scoped block")
                ->and($affected)->toBe(0, "{$name}: a keyed cross-tenant UPDATE affected {$affected} rows");
        } catch (TenantContextException) {
            // Refused is a pass: nothing ran unscoped.
        }
    }

    // And nothing reached the other tenant's row in any shape.
    expect(withoutTenantScope('readback', fn () => $owner->table('tenants')->where('id', $beta)->value('timezone'), OWNER))
        ->not->toBe('Pacific/Auckland');
});

it('holds every refusal inside an outer transaction too, and leaves no residue', function (): void {
    // Every migration body runs at transaction level 1 (PostgresGrammar reports
    // supportsSchemaTransactions), so the refusals have to hold there, and a refused
    // attempt must not leave the enclosing transaction carrying a GUC.
    $alpha = $this->makeTenant();
    $owner = DB::connection(OWNER);

    $owner->transaction(function () use ($owner, $alpha): void {
        expect($owner->transactionLevel())->toBe(1);

        expect(fn () => withTenant($alpha, fn () => withoutTenantScope('t1', fn () => true, OWNER), OWNER))
            ->toThrow(TenantContextException::class, 'while a tenant context is open');

        expect(fn () => withoutTenantScope('t2', fn () => withTenant($alpha, fn () => true, OWNER), OWNER))
            ->toThrow(TenantContextException::class, 'while a tenant bypass is active');

        expect((string) $owner->selectOne("select coalesce(current_setting('app.tenant_id', true), '') as v")->v)->toBe('')
            ->and(bypassGuc(OWNER))->toBe('')
            ->and($owner->table('tenants')->count())->toBe(0);
    });

    expect(bypassGuc(OWNER))->toBe('');
});

it('scopes correctly inside an outer transaction, and clears on the way out', function (): void {
    $alpha = $this->makeTenant();
    $this->makeTenant();
    $owner = DB::connection(OWNER);

    $owner->transaction(function () use ($owner, $alpha): void {
        $seen = withTenant($alpha, fn () => $owner->table('tenants')->count(), OWNER);

        expect($seen)->toBe(1)
            ->and((string) $owner->selectOne("select coalesce(current_setting('app.tenant_id', true), '') as v")->v)->toBe('');
    });
});

it('pins the configured bypass value to the literal every policy compares against', function (): void {
    // L-C: a config change must never be able to silently disarm the refusal. run()
    // refuses on ANY non-empty bypass GUC, so the check itself is config-independent;
    // this pins the value used to SET it against what the policies actually read.
    $value = DatabaseRoles::bypassValue();

    $policies = DB::connection(OWNER)->select(
        "select policyname, qual, with_check from pg_policies
         where schemaname = 'public' and policyname like 'owner_bypass_%' order by policyname"
    );

    expect($policies)->toHaveCount(6);

    foreach ($policies as $policy) {
        expect((string) $policy->qual)->toContain("'{$value}'")
            ->and((string) $policy->with_check)->toContain("'{$value}'");
    }
});

function probe(mixed $owner, string $alpha, string $beta): array
{
    return [
        $owner->table('tenants')->count(),
        $owner->table('tenants')->where('id', $beta)->update(['timezone' => 'Pacific/Auckland']),
    ];
}
