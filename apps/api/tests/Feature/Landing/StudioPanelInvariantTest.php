<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use App\Features\Landing\LandingPages;
use App\Features\Landing\MediaLibrary;
use App\Features\Landing\Panel\Pages\DomainsPage;
use App\Features\Landing\Panel\Pages\LandingBuilderPage;
use App\Features\Landing\Panel\Pages\MediaLibraryPage;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\ImageFixtures;
use Tests\Support\LandingFixtures;
use Tests\Support\LivewireRequest;

/*
 * ================================================================================
 * WHO MAY REACH `/studio`, DRIVEN RATHER THAN READ (§11.3, D1, SR-1)
 * ================================================================================
 *
 * D1: "Access requires a membership of `owner` or `manager` in exactly one tenant --
 * `staff` cannot edit the page, which is §4 › Security's seniority rule applied to
 * content." SR-1: a platform admin holds no tenant ability, so the tenant-scoped panel
 * is closed to them too.
 *
 * TWO LAYERS ARE DRIVEN SEPARATELY, because Task 6's security M-1 measured that they are
 * not the same layer:
 *
 *   the HTTP route   -- the panel's middleware stack, Authenticate, canAccessPanel()
 *   canAccess()      -- what `POST /livewire-<hash>/update` reaches, which runs NEITHER
 *                       `SetUpPanel` NOR Filament's `Authenticate`
 *
 * A page whose HTTP route refuses and whose canAccess() allows is a page any authenticated
 * user can drive over Livewire. That was a High on Task 6. Both halves are asserted here.
 */

beforeEach(fn () => LandingFixtures::bucket());

/**
 * Every route this panel exposes, from the router rather than from a list here.
 *
 * ⚠ AND THE LIVEWIRE UPDATE ENDPOINT IS ONE OF THEM, which this function omitted until
 * 2026-10-31. It carries every write this panel makes -- save, publish, unpublish,
 * upload, delete, add a domain, advance a domain -- and it is NOT under `/studio`, so a
 * prefix scan never saw it. The consequence was that the one endpoint through which this
 * panel does anything had no case in this file at all, while every action test drove it
 * in process through `Livewire::test()`. That is the shape that hid `R-116` for the whole
 * of Task 7, one surface over: a path the product needs, with nothing crossing it.
 */
function studioRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes() as $route) {
        $uri = '/'.ltrim($route->uri(), '/');

        // Matched by SHAPE and never by literal, for the reason LivewireRequest's
        // docblock gives: the prefix is derived from APP_KEY and differs per machine.
        $isUpdate = preg_match('#^/livewire-[0-9a-f]{8}/update$#', $uri) === 1;

        if (! str_starts_with($uri, '/studio') && ! $isUpdate) {
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

/** The three page classes this panel mounts. */
function studioPages(): array
{
    return [LandingBuilderPage::class, MediaLibraryPage::class, DomainsPage::class];
}

/** One route as a stable key: the method, and the URI with the APP_KEY hash normalised. */
function studioRouteKey(string $method, string $uri): string
{
    return $method.' '.preg_replace('#^/livewire-[0-9a-f]{8}/#', '/livewire-<hash>/', $uri);
}

/** @return list<string> */
function studioRouteKeys(): array
{
    return array_map(static fn (array $r): string => studioRouteKey($r[0], $r[1]), studioRoutes());
}

/**
 * The EXACT status each route answers one principal, measured rather than bounded.
 *
 * ⚠ EXACT, AND THAT IS THE WHOLE POINT OF THIS TABLE. Every case below used to assert
 * `->not->toBe(200)`, which is an inequality -- and an inequality is the vacuous-assertion
 * family with a different face. A 404 from a guard that should not be there passes
 * "not 200", which is exactly how `RefuseUnservedVendorRoutes` refusing `upload-file`
 * survived this suite for the whole of Task 7 (`R-116`, and `§4a`'s post-tag record).
 * Measured 2026-10-31, one principal per case so that no session is carried between them
 * -- the first version of that measurement reused one and produced a different, wrong
 * matrix.
 *
 * THE THREE REFUSAL SHAPES, each meaning something different:
 *   401  no session at all, or no session for THIS panel (Filament's Authenticate)
 *   403  authenticated, and this panel is closed to this principal (canAccessPanel)
 *   404  the asset id does not exist in this tenant -- an owner's own answer, not a refusal
 *
 * ⚠ AND `POST /livewire-<hash>/update` IS 404 FOR EVERY PRINCIPAL, INCLUDING AN OWNER.
 * That 404 is NOT an authorization refusal and must never be read as one: Livewire's
 * `RequireLivewireHeaders` aborts 404 without its own header, and `handleUpdate()` aborts
 * 404 again on an empty payload. A bare POST therefore cannot distinguish "served" from
 * "refused by a global guard" AT ALL. The case at the end of this file is what settles
 * that, by sending a real update; this entry exists so a change in the value is something
 * somebody has to explain.
 *
 * @return array<string,int>
 */
function studioExpectations(string $principal): array
{
    $update = ['POST /livewire-<hash>/update' => 404];

    $uniform = static fn (int $status): array => [
        'GET /studio' => $status,
        'GET /studio/domains' => $status,
        'GET /studio/media' => $status,
        'GET /studio/preview' => $status,
        'GET /studio/preview/asset/{asset}' => $status,
        'POST /studio/logout' => $status,
    ];

    return match ($principal) {
        // No session: every route, logout included, answers Filament's 401.
        'anonymous' => $update + $uniform(401),
        // Authenticated and closed out by the seniority rule, on every route.
        'staff' => $update + $uniform(403),
        // SR-1, with ONE exception that is not a hole: `/studio/logout` answers 401
        // rather than 403, because ending a session this principal never had for this
        // panel is Filament's own route and it refuses at the auth layer, one step
        // earlier than canAccessPanel(). Nothing tenant-scoped is reachable either way.
        'platform-admin' => $update + $uniform(403) + ['POST /studio/logout' => 401],
        // The positive control. The asset id is a fresh uuid, so 404 is the right answer
        // and NOT a refusal; logout redirects, which is what 302 is.
        'owner', 'manager' => $update + [
            'GET /studio' => 200,
            'GET /studio/domains' => 200,
            'GET /studio/media' => 200,
            'GET /studio/preview' => 200,
            'GET /studio/preview/asset/{asset}' => 404,
            'POST /studio/logout' => 302,
        ],
        default => [],
    };
}

/** Drive every route for one principal against the table, exactly. */
function assertStudioStatuses(string $principal, bool $skipLogout = false): void
{
    $expected = studioExpectations($principal);

    expect($expected)->toHaveCount(count(studioRoutes()), "the table has no entry for every route ({$principal})");

    foreach (studioRoutes() as [$method, $uri]) {
        $key = studioRouteKey($method, $uri);

        if ($skipLogout && $key === 'POST /studio/logout') {
            continue;
        }

        $path = str_replace('{asset}', (string) Str::uuid7(), $uri);

        expect(test()->call($method, $path)->status())
            ->toBe($expected[$key], "{$key} did not answer the status this panel's table records");
    }
}

it('enumerates the studio routes, so every case below has a subject', function (): void {
    // THE ANTI-VACUITY CASE, first on purpose: every case below iterates studioRoutes(),
    // and an empty list would make all of them pass without asserting anything.
    //
    // The Livewire prefix is normalised to `<hash>` rather than pinned, because it is
    // derived from APP_KEY -- the pin is on the SHAPE and the position, so a seventh
    // route still arrives in a diff.
    expect(studioRouteKeys())->toBe([
        'GET /studio',
        'GET /studio/domains',
        'GET /studio/media',
        'GET /studio/preview',
        'GET /studio/preview/asset/{asset}',
        'POST /livewire-<hash>/update',
        'POST /studio/logout',
    ]);
});

it('answers an anonymous caller the exact status every studio route should', function (): void {
    assertStudioStatuses('anonymous');
});

it('refuses a platform admin on every studio route and on every page, which is SR-1', function (): void {
    // "A platform admin holds no tenant ability and no cross-tenant read: they can see
    // that a restaurant exists and administer it from outside, and cannot see inside it."
    // The builder is inside.
    $this->actingAs(LandingFixtures::platformAdmin());

    // Every route, logout included: the table carries its 401 rather than this case
    // skipping it, because a skipped route is a route nobody is asserting anything about.
    assertStudioStatuses('platform-admin');

    // AND THE LIVEWIRE LAYER. This is the half the HTTP assertions above cannot see.
    foreach (studioPages() as $page) {
        expect($page::canAccess())->toBeFalse($page.'::canAccess() allowed a platform admin');
    }
});

it('refuses a staff member, who may not edit the page, on both layers', function (): void {
    // D1 and §4 › Security's seniority rule: `staff` runs the floor, not the website.
    $this->actingForTenant(role: TenantRole::Staff);

    assertStudioStatuses('staff');

    foreach (studioPages() as $page) {
        expect($page::canAccess())->toBeFalse($page.'::canAccess() allowed a staff member');
    }
});

it('serves an owner and a manager, so the refusals above are discriminating', function (): void {
    // THE POSITIVE CONTROL, and without it every case in this file would pass against a
    // panel that refused everybody -- which is R-059's shape and the reason this project
    // writes one beside each refusal.
    foreach ([TenantRole::Owner, TenantRole::Manager] as $role) {
        $this->actingForTenant(role: $role);

        // EVERY route this time, not four of them, and against the same exact table the
        // refusals use -- so "serves" and "refuses" are the same assertion with different
        // expected values rather than two differently-shaped claims.
        //
        // The logout POST is held back and driven last on purpose: it ENDS THE SESSION,
        // so running it mid-loop would make every later assertion in this iteration test
        // an anonymous caller instead. That ordering is stated rather than relied upon.
        assertStudioStatuses($role->value, skipLogout: true);

        foreach (studioPages() as $page) {
            expect($page::canAccess())->toBeTrue($page."::canAccess() refused a {$role->value}");
        }

        expect($this->post('/studio/logout')->status())
            ->toBe(302, "/studio/logout refused a {$role->value}");
    }
});

it('serves a REAL Livewire update to an owner, and that snapshot to nobody else', function (): void {
    // ⚠ THE CASE THIS FILE EXISTED WITHOUT UNTIL 2026-10-31, and the one the table above
    // cannot replace: `POST /livewire-<hash>/update` answers 404 to a bare POST from
    // ANY principal (Livewire's own refusal, see studioExpectations()), so the only way
    // to tell "this endpoint works" from "a global middleware is refusing it" is to send
    // a real one. Every write this panel makes arrives here, and every other test in this
    // task drives those writes with `Livewire::test()`, in process, never crossing it.
    //
    // The sequence is the browser's: GET the page, read the snapshot the server embedded
    // in it, post it back with a call on it. `$refresh` changes nothing.
    $tenant = $this->actingForTenant(role: TenantRole::Owner);

    // THE MEDIA PAGE AND NOT THE BUILDER, because this case needs a needle that the
    // server renders into HTML. The builder's heading lives in Filament form state, which
    // a `$refresh` round-trips without printing -- measured, and it made the first version
    // of this case fail for a reason that had nothing to do with the endpoint.
    app(MediaLibrary::class)->store($tenant, 'owner-can-see-this.png', ImageFixtures::png());

    $snapshot = LivewireRequest::snapshotFor(
        (string) $this->get('/studio/media')->getContent(),
        MediaLibraryPage::class,
    );

    expect($snapshot)->not->toBe('', 'the media page embedded no snapshot: the GET above is not what it was');

    $served = $this->postJson(LivewireRequest::updatePath(), LivewireRequest::payload($snapshot), LivewireRequest::HEADERS);

    // 200 AND THE CONTENT, because a 200 alone would also be satisfied by a re-render of
    // the wrong component -- the first version of this measurement proved something about
    // Filament's Topbar, which re-renders for anybody because it holds no tenant content.
    expect($served->status())->toBe(200, 'the update endpoint did not serve an owner: the panel cannot do anything')
        ->and((string) $served->json('components.0.effects.html'))->toContain('owner-can-see-this.png');

    // AND THE SAME SNAPSHOT IS NOT A BEARER TOKEN. It is signed with APP_KEY and carries
    // no session, so replaying one captured from an authorised page is the Task 6
    // security M-1 question asked over HTTP rather than over canAccess(). Measured: 403
    // with no HTML for all three, and `delete` likewise refused without deleting.
    foreach ([
        'other-tenant staff' => fn () => $this->actingForTenant(role: TenantRole::Staff),
        'platform admin' => fn () => $this->actingAs(LandingFixtures::platformAdmin()),
    ] as $who => $become) {
        $become();

        $replayed = $this->postJson(LivewireRequest::updatePath(), LivewireRequest::payload($snapshot), LivewireRequest::HEADERS);

        expect($replayed->status())->toBe(403, "a replayed owner snapshot was served to a {$who}")
            ->and((string) $replayed->json('components.0.effects.html'))->toBe('');
    }

    auth()->logout();
    session()->flush();

    $anonymous = $this->postJson(LivewireRequest::updatePath(), LivewireRequest::payload($snapshot), LivewireRequest::HEADERS);

    expect($anonymous->status())->toBe(403, 'a replayed owner snapshot was served to an anonymous caller')
        ->and((string) $anonymous->json('components.0.effects.html'))->toBe('');
});

it('shows a tenant only its own page, media and domains', function (): void {
    // The panel is tenant-scoped, so "another tenant's content" must not be reachable --
    // and there is no route that takes a tenant, so the only way to ask is to be someone
    // else. Each actingForTenant() call makes a different user in a different tenant.
    $alpha = $this->actingForTenant(role: TenantRole::Owner);

    app(LandingPages::class)->saveDraft($alpha, [
        'blocks' => [['type' => 'hero', 'fields' => ['heading' => 'ALPHA SECRET HEADING']]],
    ]);

    expect($this->get('/studio/preview')->getContent())->toContain('ALPHA SECRET HEADING');

    // A different owner, in a different tenant, on the same route.
    $beta = $this->actingForTenant(role: TenantRole::Owner);

    expect($beta)->not->toBe($alpha);

    $response = $this->get('/studio/preview');

    expect($response->status())->toBe(200)
        ->and($response->getContent())->not->toContain('ALPHA SECRET HEADING');
});

it('keeps the tenant out of the Livewire snapshot, so it cannot be tampered with', function (): void {
    // The tenant is re-resolved from the session on every call rather than held as a
    // Livewire property. A property is part of the snapshot the client round-trips, and
    // a tenant id arriving from the client is the ambient-parameter shape ActiveTenant's
    // docblock refuses in the strongest terms this project has written down.
    //
    // Asserted over the PAGE CLASSES' public properties, because the claim is about what
    // exists: a `public string $tenantId` added later fails here, in the diff.
    foreach (studioPages() as $page) {
        foreach ((new ReflectionClass($page))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            // str_contains, not ->not->toContain($needle, $message): guard rule 7
            // refused that form here, correctly -- the matcher is variadic and the
            // message would have become a second needle.
            expect(str_contains(strtolower($property->getName()), 'tenant'))
                ->toBeFalse($page.' holds a tenant in a public Livewire property');
        }
    }
});
