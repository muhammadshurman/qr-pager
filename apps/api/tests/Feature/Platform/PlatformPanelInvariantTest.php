<?php

declare(strict_types=1);

use App\Domain\Database\TenantTables;
use App\Domain\Tenancy\TenantRole;
use App\Features\Provisioning\Panel\Resources\PlatformAuditResource;
use App\Features\Provisioning\Panel\Resources\RestaurantResource;
use App\Features\Provisioning\Panel\Resources\RestaurantResource\Pages\ListRestaurants;
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
use Tests\Support\LivewireRequest;

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

        // ⚠ THE LIVEWIRE ENDPOINT IS IN THE SET, which QA M-2 found it was not. The filter
        // was `/platform` alone, so `POST /livewire-<hash>/update` was excluded -- and
        // EVERY table search, sort, paginate and modal submit in this panel goes through
        // that one route. "A panel route with no case fails the suite" was therefore true
        // of the four document routes and false of the busiest one.
        //
        // Matched by pattern because the prefix is derived from APP_KEY and differs per
        // installation; RouteSurfaceTest asserts the pattern still matches the live routes.
        $isPanel = str_starts_with($uri, '/platform')
            || preg_match('#^/livewire-[0-9a-f]{8}/update$#', $uri) === 1;

        if (! $isPanel) {
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

/** One route as a stable key: the method, and the URI with the APP_KEY hash normalised. */
function panelRouteKey(string $method, string $uri): string
{
    return $method.' '.preg_replace('#^/livewire-[0-9a-f]{8}/#', '/livewire-<hash>/', $uri);
}

/**
 * The EXACT status each route answers one principal, measured rather than bounded.
 *
 * ⚠ EXACT, BECAUSE THE RANGES THIS REPLACES BOTH PASSED ON A 404. This case used to
 * assert `toBeLessThan(500)` for a platform admin and `>= 300 and < 500` for an anonymous
 * caller. A global middleware refusing one of these paths answers 404, which satisfies
 * both -- so the suite could not see the very defect that shipped in Task 7, where
 * `RefuseUnservedVendorRoutes` refused an endpoint the product needed and a test asserted
 * the resulting 404 as correct (`R-116`, `§4a`'s post-tag record). An inequality is the
 * vacuous-assertion family with a different face. Measured 2026-10-31.
 *
 * ⚠ AND `POST /livewire-<hash>/update` IS 404 FOR BOTH PRINCIPALS, INCLUDING THE ADMIN.
 * That 404 is Livewire's OWN and not an authorization refusal: `RequireLivewireHeaders`
 * aborts 404 without its header, and `handleUpdate()` aborts again on an empty payload.
 * A bare POST therefore cannot distinguish "served" from "refused by a global guard" at
 * all, which is why this file now also sends a REAL update (the case at the end).
 *
 * @return array<string,int>
 */
function panelExpectations(string $principal): array
{
    return match ($principal) {
        'platform-admin' => [
            'GET /platform' => 200,
            'GET /platform/audit-log' => 200,
            'GET /platform/restaurants' => 200,
            'POST /platform/logout' => 302,
            'POST /livewire-<hash>/update' => 404,
        ],
        // A tenant's own owner, manager or staff: authenticated, and refused by
        // canAccessPanel() rather than by the auth layer -- which is why this is 403 and
        // the anonymous row is 401. The ruling in one status code.
        'tenant-principal' => [
            'GET /platform' => 403,
            'GET /platform/audit-log' => 403,
            'GET /platform/restaurants' => 403,
            'POST /platform/logout' => 403,
            'POST /livewire-<hash>/update' => 404,
        ],
        // No session: Filament's Authenticate answers 401 on every document route, and
        // the logout route refuses for the same reason rather than ending nothing.
        'anonymous' => [
            'GET /platform' => 401,
            'GET /platform/audit-log' => 401,
            'GET /platform/restaurants' => 401,
            'POST /platform/logout' => 401,
            'POST /livewire-<hash>/update' => 404,
        ],
        default => [],
    };
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
        // The four document routes, pinned exactly, plus the Livewire endpoint whose path
        // carries an installation-specific hash and is therefore pinned by shape.
        ->and(array_values(array_filter($routes, static fn (array $r): bool => str_starts_with($r[1], '/platform'))))
        ->toBe([
            ['GET', '/platform'],
            ['GET', '/platform/audit-log'],
            ['GET', '/platform/restaurants'],
            ['POST', '/platform/logout'],
        ]);

    $livewire = array_values(array_filter($routes, static fn (array $r): bool => str_contains($r[1], 'livewire-')));

    expect($livewire)->toHaveCount(1, 'the Livewire update endpoint is not in the enumeration')
        ->and($livewire[0][0])->toBe('POST');
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
    $statuses = [];

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

        // ⚠ THE STATUS CHECK IS NOT HERE ANY MORE (QA M-1). It used to sit in this loop,
        // and under a revert that made the panel query `tenants` the red arrived from IT
        // rather than from the `$failures` assertion below -- because the runtime scope
        // guard refuses an unscoped statement before it is sent, so the listener never
        // fired. The listener half of the invariant's proof had therefore never been seen
        // red while `§3.1` row 6 attributed the whole proof to it.
        //
        // Two assertions about two different things now live in two different cases, so a
        // failure names which one broke. The status is covered by its own case below, and
        // the LISTENER is covered by revert C in bin/provisioning-revert-proof.
        $statuses[$method.' '.$uri] = $response->getStatusCode();
    }

    // ONE assertion with every offender named, rather than one per route: a failure here is
    // "something reached a tenant table" and the only useful next question is "what".
    expect($failures)->toBe([], implode("\n", $failures));
});

it('answers every panel route the exact status it should, as a platform admin', function (): void {
    // SPLIT OUT OF THE CASE ABOVE (QA M-1). It is a different property -- "the panel works"
    // rather than "the panel touches no tenant table" -- and while the two shared a case, a
    // revert that broke the second was reported by the first. A failure must name which
    // claim it falsified.
    //
    // ⚠ IT ASSERTED `toBeLessThan(500)` UNTIL 2026-10-31, which a refusal passes. The
    // table is exact now, for the reason panelExpectations() gives at length.
    $admin = platformAdmin();
    $expected = panelExpectations('platform-admin');

    expect($expected)->toHaveCount(count(panelRoutes()), 'the table has no entry for every panel route');

    // The logout POST comes last in the sorted set, AFTER the three document routes, and
    // it ends the session -- so this loop's order is load-bearing and is stated here
    // rather than discovered. `actingAs` is re-applied per route, which makes it moot.
    foreach (panelRoutes() as [$method, $uri]) {
        $key = panelRouteKey($method, $uri);

        expect($this->actingAs($admin)->call($method, $uri)->getStatusCode())
            ->toBe($expected[$key], "{$key} did not answer the status this panel's table records");
    }
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

        // EXACT, not ">= 300 and < 500": that range passed on a 404 from a guard, which
        // is the defect shape §4a's post-tag record for Task 7 is about.
        expect($response->getStatusCode())
            ->toBe(panelExpectations('anonymous')[panelRouteKey($method, $uri)], "{$method} {$uri} did not answer the status this panel's table records for an anonymous caller");

        $touched = tenantTablesTouched($statements);

        if ($touched !== []) {
            $failures[] = "{$method} {$uri} (anonymous) touched [".implode(', ', $touched).']';
        }
    }

    expect($failures)->toBe([], implode("\n", $failures));
});

it('serves a REAL Livewire update to a platform admin, and that snapshot to nobody else', function (): void {
    // ⚠ THE CASE THIS FILE EXISTED WITHOUT UNTIL 2026-10-31. `POST /livewire-<hash>/update`
    // carries every table search, sort, paginate and modal submit in this panel -- QA M-2
    // put it in panelRoutes() for exactly that reason -- but a BARE POST to it answers 404
    // from Livewire itself, so every case that drives it proves only that something
    // answered. A global middleware refusing the path would pass all of them, which is the
    // defect that shipped in Task 7 one surface over (`R-116`).
    //
    // So this sends a real one, in the browser's sequence: GET the page, read the snapshot
    // the server embedded in it, post it back with `$refresh` on it.
    $admin = platformAdmin();
    $this->actingAs($admin);

    $name = 'REGISTRY-ROW-'.Str::upper(Str::random(6));

    DB::insert(
        'insert into tenant_provisionings (id, name, slug, owner_email, status, created_at, updated_at)
         values (?, ?, ?, ?, ?::provisioning_status, now(), now())',
        [(string) Str::uuid7(), $name, 'reg-'.Str::lower(Str::random(8)), 'o@example.test', 'pending'],
    );

    $get = $this->get('/platform/restaurants');
    $html = (string) $get->getContent();

    // THE CONTROL FOR THE NEEDLE BELOW: the registry page must render this row, or
    // "the update response carries it" proves nothing. `tenant_provisionings` is in
    // TestCase::RESETTABLE and the table's defaultSort is `created_at desc`, so the row
    // inserted a moment ago is the only one and is first -- the status is asserted too,
    // because a 429 from the panel bucket would otherwise read as "the row is missing".
    // str_contains and not ->toContain($needle, $message): the matcher is VARIADIC, so the
    // message becomes a second needle and the assertion then fails for its own text.
    // Guard rule 7 refuses that form, and this case met it while being written.
    expect($get->getStatusCode())->toBe(200)
        ->and(str_contains($html, $name))
        ->toBeTrue('the registry page does not render the row: the needle below would prove nothing');

    $snapshot = LivewireRequest::snapshotFor($html, ListRestaurants::class);

    expect($snapshot)->not->toBe('', 'the registry page embedded no snapshot for its own component');

    $served = $this->postJson(LivewireRequest::updatePath(), LivewireRequest::payload($snapshot), LivewireRequest::HEADERS);

    // 200 AND THE CONTENT. A 200 alone is also what Filament's Topbar answers to anybody,
    // because it holds nothing worth refusing -- which is how the first version of this
    // measurement reached a conclusion about the wrong component.
    expect($served->getStatusCode())->toBe(200, 'the update endpoint did not serve a platform admin: the panel cannot do anything')
        ->and((string) $served->json('components.0.effects.html'))->toContain($name);

    // AND THE SNAPSHOT IS NOT A BEARER TOKEN: it is signed with APP_KEY and carries no
    // session, so a replay is the authorization question asked over HTTP rather than
    // through canAccessPanel(). A tenant owner holds no platform ability (the ruling), and
    // an anonymous caller holds nothing at all.
    $this->actingForTenant(role: TenantRole::Owner);

    $replayed = $this->postJson(LivewireRequest::updatePath(), LivewireRequest::payload($snapshot), LivewireRequest::HEADERS);

    expect($replayed->getStatusCode())->toBe(403, 'a replayed admin snapshot was served to a tenant owner')
        ->and((string) $replayed->json('components.0.effects.html'))->toBe('');

    auth()->logout();
    session()->flush();

    $anonymous = $this->postJson(LivewireRequest::updatePath(), LivewireRequest::payload($snapshot), LivewireRequest::HEADERS);

    expect($anonymous->getStatusCode())->toBe(403, 'a replayed admin snapshot was served to an anonymous caller')
        ->and((string) $anonymous->json('components.0.effects.html'))->toBe('');
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

        // EXACT, not ">= 400 and < 500": that range is satisfied by a 404 from a global
        // guard, so it could not tell "refused because this principal may not" from
        // "refused because something broke". 403 is the ruling; see panelExpectations().
        expect($response->getStatusCode())
            ->toBe(panelExpectations('tenant-principal')[panelRouteKey($method, $uri)], "{$uri} did not refuse a {$role} the way the table records")
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
