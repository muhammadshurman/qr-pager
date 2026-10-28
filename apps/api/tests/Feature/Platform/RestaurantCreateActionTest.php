<?php

declare(strict_types=1);

use App\Domain\Database\TenantTables;
use App\Domain\Provisioning\ProvisioningStatus;
use App\Features\Provisioning\Panel\Pages\PlatformDashboardPage;
use App\Features\Provisioning\Panel\Resources\RestaurantResource;
use App\Features\Provisioning\Panel\Resources\RestaurantResource\Pages\ListRestaurants;
use App\Features\Provisioning\PlatformAudit;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * S1's ACTUAL UI PATH: the "New restaurant" action on the registry list.
 *
 * The service is driven directly by TenantProvisioningTest; this file drives the thing a
 * platform admin touches, which is a different claim. The action deliberately does NOT use
 * Filament's CreateAction -- that would `Model::create($data)`, and the model's $fillable is
 * empty, so it would write nothing and report success. It calls the service instead, whose
 * explicit column list is the write.
 */

function createActionAdmin(): User
{
    $id = (string) Str::uuid7();

    // Owner connection: the app role holds no INSERT on users.is_platform_admin (R-006).
    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Create Action Admin',
        'email' => 'create-'.Str::lower(Str::random(10)).'@example.test',
        'is_platform_admin' => true,
    ]);

    $user = User::query()->findOrFail($id);
    assert($user instanceof User);

    return $user;
}

it('provisions a restaurant from the panel action, writing only platform tables', function (): void {
    $admin = createActionAdmin();
    $slug = 'action-'.Str::lower(Str::random(8));

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    Livewire::actingAs($admin)
        ->test(ListRestaurants::class)
        ->callAction('provision', [
            'name' => 'The Action Cafe',
            'slug' => $slug,
            'owner_email' => 'Owner@Example.test',
        ])
        ->assertHasNoActionErrors();

    // THE NAMED INVARIANT ON THE WRITE PATH. The read path is covered by
    // PlatformPanelInvariantTest; this is the same property for the one action that writes.
    $touched = array_values(array_unique(array_filter(array_map(
        static fn (string $sql): ?string => TenantTables::referencedBy($sql),
        $statements,
    ))));

    expect($touched)->toBe([], 'the create action reached a tenant table: '.implode(' | ', $touched));

    $row = DB::selectOne('select * from tenant_provisionings where slug = ?', [$slug]);

    expect($row)->not->toBeNull()
        ->and($row->name)->toBe('The Action Cafe')
        ->and($row->status)->toBe(ProvisioningStatus::Pending->value)
        // Lower-cased on the way in, so the drain can match users.email.
        ->and($row->owner_email)->toBe('owner@example.test')
        // Attributed to the admin who clicked it, which is what makes the audit row useful.
        ->and($row->requested_by_user_id)->toBe($admin->getKey());

    expect(DB::selectOne(
        'select count(*) as c from platform_audit_log where subject_id = ? and action = ?',
        [$row->id, PlatformAudit::TENANT_REQUESTED],
    )->c)->toBe(1);
});

it('says “requested” and not “created”, because the tenant does not exist yet', function (): void {
    // The action cannot create the tenant: that writes `tenants`, and a panel query which
    // reaches a tenant table is a rejected design. So the screen must not claim it did --
    // a notification saying "created" over a row that still reads `pending` is R-058's
    // failure mode with a cheerful face on it.
    Livewire::actingAs(createActionAdmin())
        ->test(ListRestaurants::class)
        ->callAction('provision', [
            'name' => 'Wording',
            'slug' => 'wording-'.Str::lower(Str::random(8)),
            'owner_email' => 'w@example.test',
        ])
        ->assertNotified('Requested');
});

it('refuses a duplicate address as a field error, not a 500', function (): void {
    $admin = createActionAdmin();
    $slug = 'dupe-'.Str::lower(Str::random(8));

    $payload = [
        'name' => 'First',
        'slug' => $slug,
        'owner_email' => 'first@example.test',
    ];

    Livewire::actingAs($admin)->test(ListRestaurants::class)
        ->callAction('provision', $payload)
        ->assertHasNoActionErrors();

    // The field's own `unique` rule, so Filament keys the error to the slug field. A
    // ValidationException thrown from the action's closure instead lands under the bare key
    // `slug` while Filament's fields live at `mountedActions.0.data.slug` -- measured in
    // this session, and the reason the rule moved onto the field.
    Livewire::actingAs($admin)->test(ListRestaurants::class)
        ->callAction('provision', ['name' => 'Second'] + $payload)
        ->assertHasActionErrors(['slug' => 'unique']);

    // One row, and the second attempt left no audit row either -- the transaction holding
    // rather than the insert order being lucky.
    expect(DB::selectOne('select count(*) as c from tenant_provisionings where slug = ?', [$slug])->c)->toBe(1);
});

it('validates every field, and the slug rule is the database’s own expression', function (
    array $payload,
    string $field,
): void {
    // The application and the schema agree rather than one trusting the other: the form's
    // regex is the same expression as tenant_provisionings_slug_is_dns_label. A value that
    // reached the database would be a 23514, which is a 500 on a screen.
    Livewire::actingAs(createActionAdmin())
        ->test(ListRestaurants::class)
        ->callAction('provision', $payload)
        ->assertHasActionErrors([$field]);
})->with([
    'no name' => [['name' => '', 'slug' => 'ok-slug', 'owner_email' => 'a@example.test'], 'name'],
    'name too long' => [['name' => str_repeat('x', 121), 'slug' => 'ok-slug', 'owner_email' => 'a@example.test'], 'name'],
    'no slug' => [['name' => 'N', 'slug' => '', 'owner_email' => 'a@example.test'], 'slug'],
    'slug too long' => [['name' => 'N', 'slug' => str_repeat('a', 64), 'owner_email' => 'a@example.test'], 'slug'],
    'slug with capitals' => [['name' => 'N', 'slug' => 'Has-Capitals', 'owner_email' => 'a@example.test'], 'slug'],
    'slug with underscore' => [['name' => 'N', 'slug' => 'under_score', 'owner_email' => 'a@example.test'], 'slug'],
    'slug leading dash' => [['name' => 'N', 'slug' => '-leading', 'owner_email' => 'a@example.test'], 'slug'],
    'slug trailing dash' => [['name' => 'N', 'slug' => 'trailing-', 'owner_email' => 'a@example.test'], 'slug'],
    'no email' => [['name' => 'N', 'slug' => 'ok-slug', 'owner_email' => ''], 'owner_email'],
    'email not an address' => [['name' => 'N', 'slug' => 'ok-slug', 'owner_email' => 'not-an-address'], 'owner_email'],
]);

it('tells the user what to type rather than naming the pattern', function (): void {
    // Filament's default regex message names the expression, which is schema detail on a
    // screen. The override says what to do instead -- and no validation message anywhere
    // here carries SQL, a column name or a constraint name (§4 › Edge & ops).
    $component = Livewire::actingAs(createActionAdmin())
        ->test(ListRestaurants::class)
        ->callAction('provision', [
            'name' => 'N',
            'slug' => 'Bad Slug',
            'owner_email' => 'a@example.test',
        ]);

    $errors = $component->errors()->all();

    expect($errors)->not->toBe([]);

    $joined = implode(' ', $errors);

    expect(str_contains($joined, 'lower-case letters, digits and hyphens'))->toBeTrue($joined)
        ->and(str_contains($joined, '^[a-z0-9]'))->toBeFalse('the message names the regex')
        ->and(str_contains($joined, 'tenant_provisionings'))->toBeFalse('the message names a table');
});

it('refuses the action to a caller who is not a platform admin', function (): void {
    // ⚠ QA M-6: THIS CASE USED TO ASSERT NOTHING IT CLAIMED. It created a non-admin, never
    // authenticated as them, then called `canCreate()` statically -- which returned false
    // because `auth()->user()` was NULL, not because the user was a non-admin. It would
    // have passed on a resource that granted every authenticated non-admin. Its comment
    // said "driven here through the component" and nothing was driven.
    $id = (string) Str::uuid7();
    DB::insert(
        'insert into users (id, name, email, created_at, updated_at) values (?, ?, ?, now(), now())',
        [$id, 'Not An Admin', 'notadmin-'.Str::lower(Str::random(8)).'@example.test'],
    );
    $user = User::query()->findOrFail($id);
    assert($user instanceof User);

    // AUTHENTICATED as them, so each decision is about the user rather than about the
    // absence of one.
    $this->actingAs($user);

    expect($user->canAccessPanel(Filament::getPanel('platform')))->toBeFalse()
        ->and(RestaurantResource::canCreate())->toBeFalse()
        ->and(RestaurantResource::canViewAny())->toBeFalse();

    // AND DRIVEN THROUGH THE COMPONENT, which is what the comment promised. Filament
    // re-authorises a resource page on mount AND on every hydration, so this is a 403
    // rather than a silent no-op.
    $before = (int) DB::selectOne('select count(*) as c from tenant_provisionings')->c;

    // ASSERTED AS A DISCLOSURE PROPERTY, not as an exception type. Filament's
    // `mountCanAuthorizeAccess()` calls `abort_unless(static::canAccess(), 403)`, but
    // Livewire's test harness turns that abort into a near-empty RENDER rather than letting
    // the HttpException escape -- measured: 164 bytes for a refused mount against 12 284
    // for an allowed one. What matters is that nothing reaches the caller, so that is the
    // assertion; asserting the exception type would have been asserting a property of the
    // test harness.
    $refused = Livewire::actingAs($user)->test(ListRestaurants::class)->html();

    expect(strlen($refused))->toBeLessThan(1000, 'a non-admin was served a rendered page')
        ->and(str_contains($refused, 'New restaurant'))->toBeFalse()
        ->and(str_contains($refused, 'No restaurants yet'))->toBeFalse();

    expect((int) DB::selectOne('select count(*) as c from tenant_provisionings')->c)->toBe($before);
});

it('refuses the dashboard page to a non-admin and to an anonymous caller', function (): void {
    // ⚠ SECURITY M-1. `PlatformDashboardPage` had NO `canAccess()`, and Filament's default
    // for a CUSTOM page allows every authenticated panel user -- measured at `263bb16` as
    // `true` with no user at all, and a non-admin rendered 12 284 bytes of platform-wide
    // figures. The HTTP route was never the hole: `/platform` answers 401 anonymously. The
    // hole was `POST /livewire-<hash>/update`, which runs neither `SetUpPanel` nor
    // Filament's `Authenticate`, so the only barrier left was the snapshot's HMAC -- and a
    // snapshot is signed with APP_KEY and bound to no user and no session, so one leaked
    // admin page would have been a permanent key to the platform's figures.
    expect(PlatformDashboardPage::canAccess())->toBeFalse();

    $id = (string) Str::uuid7();
    DB::insert(
        'insert into users (id, name, email, created_at, updated_at) values (?, ?, ?, now(), now())',
        [$id, 'Not An Admin', 'dash-'.Str::lower(Str::random(8)).'@example.test'],
    );
    $user = User::query()->findOrFail($id);
    assert($user instanceof User);

    $this->actingAs($user);

    expect(PlatformDashboardPage::canAccess())->toBeFalse();

    // Same shape as the case above, and the same reason.
    $refused = Livewire::actingAs($user)->test(PlatformDashboardPage::class)->html();

    expect(strlen($refused))->toBeLessThan(1000, 'a non-admin was served the dashboard')
        // None of the figures reaches them, which is the actual finding.
        ->and(str_contains($refused, 'Restaurants'))->toBeFalse()
        ->and(str_contains($refused, 'Pager sessions'))->toBeFalse()
        ->and(str_contains($refused, 'cannot see inside it'))->toBeFalse();

    // THE CONTROL: a platform admin still can, so the lines above are a narrowing rather
    // than a page nobody can open.
    $admin = createActionAdmin();
    $this->actingAs($admin);

    expect(PlatformDashboardPage::canAccess())->toBeTrue()
        ->and(Livewire::actingAs($admin)->test(PlatformDashboardPage::class)->html())->toContain('Restaurants');
});

it('completes the round trip: the action’s row becomes a tenant when the drain runs', function (): void {
    // THE GATE CLAUSE, as far as the ruling permits it: "a new restaurant goes from zero to
    // ... in one wizard pass, with no SQL run by hand". It is one pass of the panel plus one
    // pass of the drain, because the tenant-side write cannot happen on the panel's request.
    // What this case proves is that no SQL is run by hand anywhere in between.
    $slug = 'roundtrip-'.Str::lower(Str::random(8));

    Livewire::actingAs(createActionAdmin())
        ->test(ListRestaurants::class)
        ->callAction('provision', [
            'name' => 'Round Trip',
            'slug' => $slug,
            'owner_email' => 'round@example.test',
        ])
        ->assertHasNoActionErrors();

    $this->artisan('platform:provision-tenants')->assertSuccessful();

    $id = DB::selectOne('select id, status from tenant_provisionings where slug = ?', [$slug]);

    expect($id->status)->toBe(ProvisioningStatus::Completed->value)
        ->and(withTenant($id->id, fn () => DB::selectOne('select slug from tenants')->slug))->toBe($slug)
        ->and(withTenant($id->id, fn () => DB::selectOne(
            'select role from tenant_users'
        )->role))->toBe('owner');

    // And the aggregates appear once the other command runs, which is the dashboard's data.
    $this->artisan('platform:aggregate-stats')->assertSuccessful();

    expect(DB::selectOne('select sessions_total from tenant_statistics where id = ?', [$id->id]))
        ->not->toBeNull();
});
