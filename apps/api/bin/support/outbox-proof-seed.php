<?php

declare(strict_types=1);

/*
 * Seed N pending outbox rows across two tenants, each with a subscribed device.
 *
 * THROUGH THE APPLICATION'S OWN PATHS. PagerSessions::create() issues the token,
 * PushSubscriptions::register() stores the device, and PagerSessions::markReady() writes
 * the outbox row -- in markReady's own transaction, with PagerEvent's own dedupe key. A
 * seeder that inserted outbox rows directly would be proving the worker against rows no
 * production path produces.
 */

use App\Domain\Pager\PagerStatus;
use App\Features\Pager\PagerSessions;
use App\Features\Pager\PushSubscriptions;
use App\Features\Pager\ResolvedPagerSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PushFixtures;

require __DIR__.'/outbox-proof-boot.php';

$rows = max(1, (int) ($argv[1] ?? 8));

// A clean slate, as the suite's own setUp does. The proof asserts exact counts, so a row
// left by an earlier run would be indistinguishable from a row this run lost.
DB::connection('pgsql_root')->unprepared(
    'TRUNCATE push_subscriptions, outbox, pager_sessions, tenant_users, tenants, users RESTART IDENTITY CASCADE'
);

$sessions = app(PagerSessions::class);
$subscriptions = app(PushSubscriptions::class);
$tenants = [];

for ($i = 0; $i < 2; $i++) {
    $id = (string) Str::uuid7();

    withTenant($id, fn () => DB::connection('pgsql_owner')->table('tenants')->insert([
        'id' => $id,
        'name' => 'Proof tenant '.($i + 1),
        'slug' => 'proof-'.str_replace('-', '', $id),
    ]), 'pgsql_owner');

    $tenants[] = $id;
}

$seeded = [];

for ($i = 0; $i < $rows; $i++) {
    $tenant = $tenants[$i % 2];
    $issued = $sessions->create($tenant, 'P-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT));
    $device = PushFixtures::subscription();

    // register() needs a ResolvedPagerSession, which is what the HTTP path would have
    // produced from the token. Built here from the session just created, which is the same
    // object PagerTokenLookup would return for it.
    $resolved = new ResolvedPagerSession(
        $issued->session->id,
        $tenant,
        PagerStatus::Waiting,
        $issued->session->orderReference,
        new DateTimeImmutable('+2 hours'),
        null,
        null,
    );

    $subscriptions->register($resolved, $device['endpoint'], $device['p256dh'], $device['auth']);
    $sessions->markReady($tenant, $issued->session->id);

    $seeded[] = $issued->session->id;
}

$pending = DB::connection('pgsql_worker')->selectOne("select count(*) as n from outbox where status = 'pending'");

echo json_encode([
    'tenants' => count($tenants),
    'sessions' => count($seeded),
    'pending_outbox_rows' => (int) $pending->n,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;

exit((int) $pending->n === $rows ? 0 : 1);
