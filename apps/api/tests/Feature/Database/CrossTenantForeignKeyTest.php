<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Gate clause added on the ticket: "a push subscription cannot point at another
 * tenant's session".
 *
 * This is the one isolation case a per-table tenant_id policy does not catch:
 * both rows are legitimately yours by tenant, and the relationship is the
 * violation. The composite foreign key is what makes it impossible -- referential
 * integrity checks bypass row level security, so the key is checked against the
 * real table rather than against what the caller can see.
 */

it('refuses a subscription pointing at another tenant session', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $betaSession = $this->makeSession($beta);

    $state = $this->sqlStateOf(fn () => withTenant($alpha, fn () => DB::table('push_subscriptions')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $alpha,
        'pager_session_id' => $betaSession,
        'endpoint' => 'https://push.example.test/'.Str::random(24),
        'p256dh' => Str::random(87),
        'auth' => Str::random(22),
    ])));

    expect($state)->toBe('23503');
});

it('accepts a subscription pointing at its own tenant session', function (): void {
    $tenant = $this->makeTenant();
    $session = $this->makeSession($tenant);

    withTenant($tenant, fn () => DB::table('push_subscriptions')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant,
        'pager_session_id' => $session,
        'endpoint' => 'https://push.example.test/'.Str::random(24),
        'p256dh' => Str::random(87),
        'auth' => Str::random(22),
    ]));

    expect(withTenant($tenant, fn () => DB::table('push_subscriptions')->count()))->toBe(1);
});

it('refuses an outbox row pointing at another tenant session', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $betaSession = $this->makeSession($beta);

    $state = $this->sqlStateOf(fn () => withTenant($alpha, fn () => DB::table('outbox')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $alpha,
        'pager_session_id' => $betaSession,
        'type' => 'pager.ready',
        'dedupe_key' => 'cross-tenant',
    ])));

    expect($state)->toBe('23503');
});

it('cascades subscriptions when their session goes, without crossing tenants', function (): void {
    $tenant = $this->makeTenant();
    $session = $this->makeSession($tenant);

    withTenant($tenant, fn () => DB::table('push_subscriptions')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant,
        'pager_session_id' => $session,
        'endpoint' => 'https://push.example.test/'.Str::random(24),
        'p256dh' => Str::random(87),
        'auth' => Str::random(22),
    ]));

    withTenant($tenant, fn () => DB::connection('pgsql_owner')
        ->table('pager_sessions')->where('id', $session)->delete(), 'pgsql_owner');

    expect(withTenant($tenant, fn () => DB::table('push_subscriptions')->count()))->toBe(0);
});

it('enforces one outbox row per logical event, which is Task 3 idempotency', function (): void {
    $tenant = $this->makeTenant();
    $session = $this->makeSession($tenant);

    $row = fn (): array => [
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant,
        'pager_session_id' => $session,
        'type' => 'pager.ready',
        'dedupe_key' => "session:{$session}:ready",
    ];

    withTenant($tenant, fn () => DB::table('outbox')->insert($row()));

    expect($this->sqlStateOf(fn () => withTenant($tenant, fn () => DB::table('outbox')->insert($row()))))
        ->toBe('23505');

    expect(withTenant($tenant, fn () => DB::table('outbox')->count()))->toBe(1);
});

it('scopes the dedupe key per tenant, so two tenants never collide', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();

    foreach ([$alpha, $beta] as $tenant) {
        withTenant($tenant, fn () => DB::table('outbox')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant,
            'type' => 'pager.ready',
            'dedupe_key' => 'shared-key',
        ]));
    }

    expect($this->asWorker()->table('outbox')->count())->toBe(2);
});
