<?php

declare(strict_types=1);

use App\Platform\Database\TenantContext;
use App\Platform\Database\TenantContextException;
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
