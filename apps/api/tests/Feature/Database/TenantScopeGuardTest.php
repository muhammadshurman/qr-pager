<?php

declare(strict_types=1);

use App\Domain\Database\TenantTables;
use App\Domain\Http\ProblemType;
use App\Platform\Database\TenantScopeGuard;
use App\Platform\Database\TenantScopeMissingException;
use App\Platform\Http\Middleware\ArmTenantScopeGuard;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * The FIRST of the three invariants this task ships on (PLAN.md §3.1, row 2):
 * "a request without tenant context never reaches the DB."
 *
 * Reaches, not "returns rows". RLS already makes an unscoped read return zero rows,
 * and that is a different and weaker property: it is silent, it is indistinguishable
 * from "the tenant has no data", and it does nothing at all for a write that would
 * otherwise be legal. This file is about the statement not executing.
 */

/**
 * A statement Postgres WILL execute successfully on the app connection with no scope,
 * whose text names a tenant table.
 *
 * It has to be both of those at once or the test cannot tell the two mechanisms apart.
 * A plain insert into a tenant table is refused by RLS with 42501 whether or not the
 * guard exists, so the row count would be 0 either way and the test would pass against
 * a guard that does nothing. This deletes from password_reset_tokens -- a GLOBAL table,
 * which the guard does not match, and one the app role may delete from -- with a
 * subquery against `tenants`, which it does match. Unscoped, `select 1 from tenants`
 * returns no rows, so `not exists` is true and the delete takes effect.
 */
function deleteNamingATenantTable(string $email): bool
{
    return (bool) DB::delete(
        'delete from password_reset_tokens where email = ? and not exists (select 1 from tenants)',
        [$email],
    );
}

function seedResetToken(): string
{
    $email = Str::lower(Str::uuid7()->toString()).'@example.test';

    DB::connection('pgsql_owner')->table('password_reset_tokens')->insert([
        'email' => $email,
        'token' => (string) Str::uuid7(),
        'created_at' => now(),
    ]);

    return $email;
}

function resetTokenExists(string $email): bool
{
    return DB::connection('pgsql_owner')->table('password_reset_tokens')->where('email', $email)->exists();
}

it('refuses before Postgres executes the statement, where DB::listen would not — B4 corrected', function (): void {
    $guard = app(TenantScopeGuard::class);

    // 1. Unarmed, the statement works and has an effect. Without this the rest of the
    //    test could pass because the statement never worked in the first place.
    $email = seedResetToken();
    expect(deleteNamingATenantTable($email))->toBeTrue()
        ->and(resetTokenExists($email))->toBeFalse();

    // 2. The mechanism the planning chat specified (B4): a DB::listen callback that
    //    throws. The exception is raised AND THE STATEMENT ALREADY RAN -- listen fires
    //    after execution, which is why its event can carry the elapsed time. A guard
    //    built on it would have made this very file green while the invariant was
    //    false. Reproduced here rather than left in a commit message, so the choice
    //    cannot be quietly undone.
    $email = seedResetToken();
    DB::listen(function (QueryExecuted $event): void {
        // Matched on the statement, exactly as a listen()-based guard would have had
        // to: a listener sees every connection and every query, including the ones
        // this test verifies itself with.
        if (str_contains($event->sql, 'delete from password_reset_tokens')) {
            throw new RuntimeException('a listener that fires too late to stop anything');
        }
    });

    try {
        deleteNamingATenantTable($email);
    } catch (RuntimeException) {
        // expected
    }

    expect(resetTokenExists($email))->toBeFalse('DB::listen fired after execution, so the row was already gone');

    // A listener cannot be removed, so this application instance is finished with.
    $this->refreshApplication();
    $guard = app(TenantScopeGuard::class);

    // 3. beforeExecuting, which is what the guard uses. Same statement, same role,
    //    and the row survives: the refusal happened before Postgres saw it.
    $email = seedResetToken();

    $guard->armedFor(function () use ($email): void {
        expect(fn () => deleteNamingATenantTable($email))
            ->toThrow(TenantScopeMissingException::class);
    });

    expect(resetTokenExists($email))->toBeTrue('the guard let the statement reach Postgres');
});

it('covers every way a statement can be issued, not just the query builder', function (): void {
    $tenant = (string) Str::uuid7();

    app(TenantScopeGuard::class)->armedFor(function () use ($tenant): void {
        $issue = [
            'select' => fn () => DB::select('select count(*) from pager_sessions'),
            'statement' => fn () => DB::statement('update outbox set status = ? where false', ['pending']),
            'affecting' => fn () => DB::delete('delete from push_subscriptions where false'),
            'insert' => fn () => DB::insert('insert into tenant_users (tenant_id) values (?)', [$tenant]),
            'unprepared' => fn () => DB::unprepared('select 1 from audit_log where false'),
            'builder' => fn () => DB::table('tenants')->count(),
            'builder-write' => fn () => DB::table('push_subscriptions')->where('id', $tenant)->delete(),
        ];

        foreach ($issue as $name => $call) {
            expect($call)->toThrow(TenantScopeMissingException::class, '', "{$name} was not refused");
        }
    });
});

it('reattaches to a connection created after a purge', function (): void {
    app(TenantScopeGuard::class)->armedFor(function (): void {
        expect(fn () => DB::table('tenants')->count())->toThrow(TenantScopeMissingException::class);

        // Which is the case beforeExecuting() alone does not survive: it registers on a
        // Connection OBJECT, and Octane purges and reconnects. The guard hangs on
        // ConnectionEstablished so the next object carries it too.
        DB::purge('pgsql');

        expect(fn () => DB::table('tenants')->count())->toThrow(TenantScopeMissingException::class);
    });
});

it('lets a statement that touches no tenant table through, armed or not', function (): void {
    app(TenantScopeGuard::class)->armedFor(function (): void {
        expect(DB::select('select 1 as ok')[0]->ok)->toBe(1);

        // The four global tables are global because they carry no tenant scope at all
        // (PLAN.md §2.4), so there is no scope that could narrow them and nothing for
        // the guard to refuse. Read on the owner connection, which is where the app
        // role's grants on them are irrelevant -- the point is the guard's verdict.
        foreach (TenantTables::GLOBAL as $table) {
            expect(fn () => DB::connection('pgsql_owner')->table($table)->count())->not->toThrow(
                TenantScopeMissingException::class,
            );
        }

        expect(fn () => DB::select('select count(*) from password_reset_tokens'))->not->toThrow(
            TenantScopeMissingException::class,
        );
    });
});

it('allows the same statement inside a tenant scope', function (): void {
    $tenant = $this->makeTenant();

    app(TenantScopeGuard::class)->armedFor(function () use ($tenant): void {
        expect(withTenant($tenant, fn () => DB::table('tenants')->count()))->toBe(1);
    });
});

it('guards the runtime connections only, so the audited owner bypass still works', function (): void {
    $guard = app(TenantScopeGuard::class);

    expect($guard->guardedConnections())->toBe(['pgsql', 'pgsql_worker']);

    // §2.10's named, audited cross-tenant path runs on the owner connection by design.
    // Refusing it here would break the one legitimate unscoped reader and still not be
    // the control that keeps owner credentials out of a serving process -- that is D9's
    // boot assertion, which is where PLAN.md §5 puts it (R-015).
    $guard->armedFor(function (): void {
        expect(withoutTenantScope('guard test', fn () => DB::connection('pgsql_owner')
            ->table('tenants')->count()))->toBe(0);
    });
});

it('is armed by the GLOBAL middleware stack, which no route can be added without', function (): void {
    /** @var Illuminate\Foundation\Http\Kernel $kernel */
    $kernel = app(Kernel::class);

    expect($kernel->getGlobalMiddleware())->toContain(ArmTenantScopeGuard::class);

    // And it is NOT how the probes' group gets it: the group is empty on purpose, so
    // "health and readiness keep answering" cannot be undone by something added there.
    expect($kernel->getMiddlewareGroups()['probe'] ?? null)->toBe([]);
});

it('refuses an unscoped tenant query made by a real request, and the body leaks nothing', function (): void {
    config(['app.debug' => true]);
    $this->actingForTenant();

    Route::middleware('api')->prefix('v1')->get('probe-unscoped', fn () => DB::table('pager_sessions')->count());

    $response = $this->getJson('/v1/probe-unscoped');
    $body = (string) $response->getContent();

    $response->assertStatus(500)->assertJsonPath('type', ProblemType::ServerError->value);

    // The refusal names a table and a connection, which belong in the log and not in a
    // body -- the same contract the redaction states: names and locations in, values out.
    expect($body)->not->toContain('pager_sessions')
        ->not->toContain('pgsql')
        ->not->toContain('TenantScopeMissingException');
});

it('arms a path that matches no route, because routing happens after the global stack', function (): void {
    // The fallback is the shape the rate limiter also has to cover for 404s (the gate's
    // own clause). What is proven here is the ordering: the guard is already armed by
    // the time anything route-shaped runs at all.
    Route::fallback(fn () => DB::table('outbox')->count());

    $this->getJson('/no-such-path-'.Str::uuid7())->assertStatus(500);

    expect(resetTokenExists('never@example.test'))->toBeFalse();
});

it('is not armed outside a request, so the RLS backstop stays provable', function (): void {
    // Deliberate, and the honest boundary of this control. The enforcement covers
    // exactly what the invariant claims -- a REQUEST -- and console, scheduled and
    // queue work is left to RLS, which is what TenantIsolationTest, ConnectionReuseTest
    // and WorkerScopeTest prove by making this very query and expecting zero rows. An
    // always-armed guard would have needed a suspension helper for them, and an escape
    // hatch in the one class that exists to have none is a worse trade than a named
    // boundary.
    expect(app(TenantScopeGuard::class)->isArmed())->toBeFalse()
        ->and(DB::table('tenants')->count())->toBe(0);
});
