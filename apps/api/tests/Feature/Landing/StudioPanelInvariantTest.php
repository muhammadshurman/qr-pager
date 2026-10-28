<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use App\Features\Landing\LandingPages;
use App\Features\Landing\Panel\Pages\DomainsPage;
use App\Features\Landing\Panel\Pages\LandingBuilderPage;
use App\Features\Landing\Panel\Pages\MediaLibraryPage;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\LandingFixtures;

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

/** Every route this panel exposes, from the router rather than from a list here. */
function studioRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes() as $route) {
        $uri = '/'.ltrim($route->uri(), '/');

        if (! str_starts_with($uri, '/studio')) {
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

it('enumerates the studio routes, so every case below has a subject', function (): void {
    // THE ANTI-VACUITY CASE, first on purpose: every case below iterates studioRoutes(),
    // and an empty list would make all of them pass without asserting anything.
    expect(studioRoutes())->toBe([
        ['GET', '/studio'],
        ['GET', '/studio/domains'],
        ['GET', '/studio/media'],
        ['GET', '/studio/preview'],
        ['GET', '/studio/preview/asset/{asset}'],
        ['POST', '/studio/logout'],
    ]);
});

it('serves nothing on any studio route to an anonymous caller', function (): void {
    foreach (studioRoutes() as [$method, $uri]) {
        $path = str_replace('{asset}', (string) Str::uuid7(), $uri);

        $response = $this->call($method, $path);

        expect($response->status())->not->toBe(200, "{$method} {$path} served an anonymous caller");
    }
});

it('refuses a platform admin on every studio route and on every page, which is SR-1', function (): void {
    // "A platform admin holds no tenant ability and no cross-tenant read: they can see
    // that a restaurant exists and administer it from outside, and cannot see inside it."
    // The builder is inside.
    $this->actingAs(LandingFixtures::platformAdmin());

    foreach (studioRoutes() as [$method, $uri]) {
        if ($uri === '/studio/logout') {
            continue; // ending a session is not reading tenant content
        }

        $path = str_replace('{asset}', (string) Str::uuid7(), $uri);
        $response = $this->call($method, $path);

        expect($response->status())->not->toBe(200, "{$method} {$path} served a platform admin");
    }

    // AND THE LIVEWIRE LAYER. This is the half the HTTP assertions above cannot see.
    foreach (studioPages() as $page) {
        expect($page::canAccess())->toBeFalse($page.'::canAccess() allowed a platform admin');
    }
});

it('refuses a staff member, who may not edit the page, on both layers', function (): void {
    // D1 and §4 › Security's seniority rule: `staff` runs the floor, not the website.
    $this->actingForTenant(role: TenantRole::Staff);

    foreach (studioRoutes() as [$method, $uri]) {
        if ($uri === '/studio/logout') {
            continue;
        }

        $path = str_replace('{asset}', (string) Str::uuid7(), $uri);

        expect($this->call($method, $path)->status())
            ->not->toBe(200, "{$method} {$path} served a staff member");
    }

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

        foreach (['/studio', '/studio/media', '/studio/domains', '/studio/preview'] as $path) {
            expect($this->get($path)->status())
                ->toBe(200, "{$path} refused a {$role->value}");
        }

        foreach (studioPages() as $page) {
            expect($page::canAccess())->toBeTrue($page."::canAccess() refused a {$role->value}");
        }
    }
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
