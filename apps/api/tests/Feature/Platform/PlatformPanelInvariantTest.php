<?php

declare(strict_types=1);

use App\Domain\Database\TenantTables;
use App\Domain\Tenancy\TenantRole;
use App\Features\Provisioning\Panel\Resources\PlatformAuditResource;
use App\Features\Provisioning\Panel\Resources\RestaurantResource;
use App\Models\PlatformAuditEntry;
use App\Models\TenantProvisioningRecord;
use App\Models\TenantStatistic;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * THE NAMED INVARIANT FOR TASK 6 (§11, QRPAGER-13 §4.1, PLAN.md §3.1 row 6)
 * ================================================================================
 *
 *   "No panel query reaches a tenant table, and a platform admin with no membership can
 *    read no tenant row by any route the panel exposes."
 *
 * It was written into the ticket and into §3.1 BEFORE any code, which is what §11's round
 * limit requires. Once the §11 cap is reached it is the only clause that blocks `task-6`.
 *
 * ================================================================================
 * HOW IT IS PROVEN — mechanically, and with the route table as the subject
 * ================================================================================
 *
 * A `DB::listen()` query listener over every panel route, with each captured statement
 * classified by `TenantTables::referencedBy()` -- the SAME registry the runtime scope guard
 * matches against, so the test and the guard cannot disagree about what a tenant table is.
 *
 * THE INSTRUMENT IS A LISTENER AND NOT THE POSTGRES LOG, which corrects the ticket.
 * `QRPAGER-13 §4.1` says "the Postgres query log is already this repository's proof
 * mechanism"; it is not, because `R-004` was closed at Task 3 by REMOVING `log_statement=all`
 * from the cluster, so there is no log to read. `PagerPollingTest`'s header records the
 * same correction for the same reason, and names why a listener is the stronger instrument
 * here: it fires for every statement this process issues on ANY connection -- the owner and
 * worker connections included -- and for statements that fail before they are sent.
 *
 * THE ROUTE TABLE IS DERIVED, NOT TYPED OUT. The cases below enumerate the panel's routes
 * from Laravel's own route collection, so **a panel route added without a case fails the
 * suite**. That is the shape §3.1 row 5 settled on at Task 5: a matrix that does not iterate
 * its own subject is unfalsifiable by anyone but its author.
 *
 * AND IT IS REVERT-PROVED. `bin/provisioning-revert-proof`'s revert B adds one
 * `select ... from tenants` to the dashboard's read path and these cases must go red. A
 * test that cannot be made red by the thing it forbids would have been green however the
 * panel was written -- which is the state four assertions in this repository were measured
 * to be in (§4a's FOUR DEAD ASSERTIONS).
 */

/**
 * Every route the panel exposes, from the router rather than from a list here.
 *
 * @return list<array{string, string}> [method, uri]
 */
function panelRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes() as $route) {
        $uri = '/'.ltrim($route->uri(), '/');

        if (! str_starts_with($uri, '/platform')) {
            continue;
        }

        foreach ($route->methods() as $method) {
            if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                continue;
            }

            $routes[] = [$method, $uri];
        }
    }

    sort($routes);

    return array_values($routes);
}

/** The tenant tables a captured statement list touches, as a set. */
function tenantTablesTouched(array $statements): array
{
    $touched = [];

    foreach ($statements as $sql) {
        $table = TenantTables::referencedBy((string) $sql);

        if ($table !== null) {
            $touched[$table] = true;
        }
    }

    $names = array_keys($touched);
    sort($names);

    return $names;
}

/** A platform admin: is_platform_admin, and deliberately no membership anywhere. */
function platformAdmin(): User
{
    $id = (string) Str::uuid7();

    // THE OWNER CONNECTION, and that is Task 5's narrowing showing through rather than a
    // shortcut: `qrpager_app` holds no INSERT and no UPDATE on `users.is_platform_admin`
    // (SEC L-1 / R-006), so the app role CANNOT create a platform admin at all. Measured
    // here the hard way first -- `permission denied for table users`, 42501 -- which is the
    // control working. RoleMatrixTest's own fixture does the same thing for the same reason.
    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Platform Admin',
        'email' => 'platform-'.Str::lower(Str::random(10)).'@example.test',
        'is_platform_admin' => true,
    ]);

    $user = User::query()->findOrFail($id);
    assert($user instanceof User);

    return $user;
}

it('enumerates the panel routes, so the cases below have a subject', function (): void {
    // THE ANTI-VACUITY CASE, and it comes first on purpose. Every case below iterates
    // panelRoutes(); if that returned an empty list they would all pass without asserting
    // anything, which is exactly how an assertion goes green for the wrong reason.
    $routes = panelRoutes();

    expect($routes)->not->toBe([])
        // The four the panel mounts today. Stated as an exact set so a FIFTH route arrives
        // in this diff rather than silently joining the surface the invariant is about.
        ->and($routes)->toBe([
            ['GET', '/platform'],
            ['GET', '/platform/audit-log'],
            ['GET', '/platform/restaurants'],
            ['POST', '/platform/logout'],
        ]);
});

it('reaches NO tenant table on any panel route, as a platform admin', function (): void {
    // THE INVARIANT'S FIRST HALF. Every route, driven as the principal that can actually
    // see the panel, with a listener over every connection.
    $admin = platformAdmin();

    // Real data on both sides of the boundary, so the queries have something to find and a
    // leak would have something to leak. A tenant with a session in it, and a provisioning
    // record with an aggregate row.
    $tenant = $this->makeTenant();
    $this->makeSession($tenant);

    // THROUGH THE GRANTED COLUMNS, in the two steps the real path uses -- insert as
    // `pending`, then move it to `completed`. A one-shot insert with `completed_at` set is
    // refused 42501, because `completed_at` is deliberately NOT in the app role's INSERT
    // grant: a provisioning request is never born already finished. Measured here the hard
    // way first, which is the column grant doing its job rather than an obstacle.
    $provisioning = (string) Str::uuid7();
    DB::insert(
        'insert into tenant_provisionings (id, name, slug, owner_email, status, created_at, updated_at)
         values (?, ?, ?, ?, ?::provisioning_status, now(), now())',
        [$provisioning, 'Visible', 'visible-'.Str::lower(Str::random(8)), 'o@example.test', 'pending'],
    );
    DB::update(
        'update tenant_provisionings set status = ?::provisioning_status, completed_at = now(), updated_at = now()
          where id = ?',
        ['completed', $provisioning],
    );
    DB::connection('pgsql_worker')->insert(
        'insert into tenant_statistics (id, sessions_total, staff_count, computed_at, created_at, updated_at)
         values (?, 7, 2, now(), now(), now())',
        [$provisioning],
    );

    $failures = [];

    foreach (panelRoutes() as [$method, $uri]) {
        $statements = [];

        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $response = $this->actingAs($admin)->call($method, $uri);

        // Not asserted to be 200: /platform/logout redirects, and the point of this case is
        // what the request TOUCHED rather than what it answered. A 500 would be caught by
        // the status case below, which is deliberately separate.
        $touched = tenantTablesTouched($statements);

        if ($touched !== []) {
            $offending = array_values(array_filter(
                $statements,
                static fn (string $sql): bool => TenantTables::referencedBy($sql) !== null,
            ));

            $failures[] = "{$method} {$uri} touched [".implode(', ', $touched).']: '
                .implode(' | ', $offending);
        }

        // Status recorded only so a failure message can carry it.
        expect($response->getStatusCode())->toBeLessThan(500, "{$method} {$uri} answered a server error");
    }

    // ONE assertion with every offender named, rather than one per route: a failure here is
    // "something reached a tenant table" and the only useful next question is "what".
    expect($failures)->toBe([], implode("\n", $failures));
});

it('answers every panel route to an anonymous caller without reaching a tenant table', function (): void {
    // Route default is DENY (§4), and the refusal itself must not touch a tenant table --
    // a 401 that queried on its way out is still a query.
    $failures = [];

    foreach (panelRoutes() as [$method, $uri]) {
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $response = $this->call($method, $uri);

        expect($response->getStatusCode())->toBeGreaterThanOrEqual(300, "{$method} {$uri} served an anonymous caller")
            ->and($response->getStatusCode())->toBeLessThan(500);

        $touched = tenantTablesTouched($statements);

        if ($touched !== []) {
            $failures[] = "{$method} {$uri} (anonymous) touched [".implode(', ', $touched).']';
        }
    }

    expect($failures)->toBe([], implode("\n", $failures));
});

it('refuses the panel to every tenant principal, with no tenant ability needed to decide', function (
    string $role,
): void {
    // THE INVARIANT'S SECOND HALF FROM THE OTHER DIRECTION: a tenant's own owner, manager
    // or staff member is not a platform admin, so the panel refuses them. Driven per role
    // rather than asserted once, because "refused" has to hold for the most privileged
    // tenant principal too -- an owner.
    $tenant = $this->makeTenant();

    $userId = (string) Str::uuid7();
    DB::insert(
        'insert into users (id, name, email, created_at, updated_at) values (?, ?, ?, now(), now())',
        [$userId, 'Tenant '.$role, $role.'-'.Str::lower(Str::random(8)).'@example.test'],
    );
    withTenant($tenant, fn () => DB::insert(
        'insert into tenant_users (id, tenant_id, user_id, role, created_at, updated_at)
         values (?, ?, ?, ?::tenant_role, now(), now())',
        [(string) Str::uuid7(), $tenant, $userId, $role],
    ));

    $user = User::query()->findOrFail($userId);
    assert($user instanceof User);

    // canAccessPanel() is the gate and it reads one boolean, so the refusal costs no query
    // against any tenant table.
    // The panel from Filament's own registry, and not a provider instantiated by hand: a
    // ServiceProvider takes the container as a constructor argument, and a panel built
    // outside the registry would not be the one the routes are mounted from -- so the
    // assertion would be about a different object than the one under test.
    expect($user->canAccessPanel(Filament::getPanel('platform')))->toBeFalse();

    foreach (panelRoutes() as [$method, $uri]) {
        if ($uri === '/platform/logout') {
            // Not a read surface: it ends a session and is harmless to a non-admin.
            continue;
        }

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $response = $this->actingAs($user)->call($method, $uri);

        expect($response->getStatusCode())->toBeGreaterThanOrEqual(400, "{$uri} served a {$role}")
            ->and($response->getStatusCode())->toBeLessThan(500)
            ->and(tenantTablesTouched($statements))->toBe([], "{$uri} touched a tenant table for a {$role}");
    }
})->with([
    TenantRole::Owner->value,
    TenantRole::Manager->value,
    TenantRole::Staff->value,
]);

it('lets a platform admin read no tenant row through the panel, with real rows on both sides', function (): void {
    // THE INVARIANT'S SECOND HALF, as a disclosure question rather than a query-count one.
    // A tenant with a session carrying a distinctive order reference; the panel's rendered
    // output must not contain it anywhere.
    $admin = platformAdmin();
    $tenant = $this->makeTenant('leak-check-'.Str::lower(Str::random(8)));

    $marker = 'ORDERREF'.Str::upper(Str::random(8));
    $this->makeSession($tenant, ['order_reference' => $marker]);

    foreach (panelRoutes() as [$method, $uri]) {
        if ($method !== 'GET') {
            continue;
        }

        $body = (string) $this->actingAs($admin)->call($method, $uri)->getContent();

        // str_contains() and NOT `->not->toContain($needle, $message)`. Pest's toContain()
        // is VARIADIC: a message in the second argument is read as a SECOND NEEDLE, the
        // negated assertion becomes "contains neither", and a message that never appears in
        // the subject makes it trivially true. These two lines were written that way and
        // SourceGuard rule 7 refused them -- which is the control working on the very
        // assertions that carry this task's named invariant.
        expect(str_contains($body, $marker))
            ->toBeFalse("{$uri} rendered another tenant's order reference")
            // And not the tenant's id either, which would be a cross-tenant identifier even
            // with no content behind it.
            ->and(str_contains($body, $tenant))
            ->toBeFalse("{$uri} rendered a tenant id");
    }
});

it('gives the platform models NO relation to any tenant table, which is how this would break later', function (
    string $class,
): void {
    // THE DURABILITY HALF, and the most valuable case in this file. The invariant holds
    // today because the panel's queries are hand-written against platform tables. The
    // realistic way it stops holding is an Eloquent RELATION: one `->with('tenant')` for a
    // name column and every list query joins `tenants`, quietly, through a code path no
    // reviewer reads twice.
    //
    // So the models are reflected over and any method returning a Relation fails this.
    $reflection = new ReflectionClass($class);

    $relations = [];

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class || $method->getNumberOfParameters() > 0) {
            continue;
        }

        $type = $method->getReturnType();

        if (! $type instanceof ReflectionNamedType) {
            continue;
        }

        $name = $type->getName();

        if (is_a($name, Relation::class, true)) {
            $relations[] = $method->getName().'(): '.$name;
        }
    }

    expect($relations)->toBe([], $class.' declares a relation, which is how a panel query reaches a tenant table: '
        .implode(', ', $relations));
})->with([
    TenantProvisioningRecord::class,
    TenantStatistic::class,
    PlatformAuditEntry::class,
]);

it('points every platform model at a platform table, and never at a tenant one', function (string $class): void {
    $model = new $class;
    assert($model instanceof Model);

    expect(TenantTables::GLOBAL)->toContain($model->getTable())
        ->and(TenantTables::TENANT)->not->toContain($model->getTable());
})->with([
    TenantProvisioningRecord::class,
    TenantStatistic::class,
    PlatformAuditEntry::class,
]);

it('registers exactly the resources and pages the invariant was measured against', function (): void {
    // The panel's registered set, pinned. `discoverResources()` was deliberately not used
    // (see PlatformPanelProvider): a discovered set is whatever is in a directory, and
    // "every panel resource" has to be enumerable for the cases above to mean anything.
    $panel = Filament::getPanel('platform');

    expect(array_values($panel->getResources()))->toBe([
        RestaurantResource::class,
        PlatformAuditResource::class,
    ])
        // No widgets: the generator's two defaults were dropped, one of which renders the
        // installed Filament version to anyone who can see the dashboard.
        ->and($panel->getWidgets())->toBe([]);
});

it('mounts no second login page, because that would be a third authentication surface', function (): void {
    // §4 › Tenancy: two authentication surfaces, never confused. Filament's ->login() would
    // be a third -- its own credential form, its own throttling, and no second factor,
    // against the same `users` table Task 5 moved behind authenticate_user() and a TOTP
    // challenge. PlatformPanelProvider omits it; this is the assertion that it stays omitted.
    $uris = array_map(static fn (array $r): string => $r[1], panelRoutes());

    expect($uris)->not->toContain('/platform/login')
        ->and(Route::has('filament.platform.auth.login'))->toBeFalse();

    // And the panel trusts the guard Task 5's login establishes, named rather than defaulted.
    expect(Filament::getPanel('platform')->getAuthGuard())->toBe('web');
});
