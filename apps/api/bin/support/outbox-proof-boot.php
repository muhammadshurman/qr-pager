<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Minishlink\WebPush\VAPID;

/*
 * The shared boot for bin/outbox-kill-proof's four helpers.
 *
 * A PLAIN SCRIPT AND NOT AN ARTISAN COMMAND, deliberately: an artisan command would be
 * registered in bootstrap/app.php and would therefore be part of the application's
 * surface -- `php artisan` is the record of what exists, and a proof's scaffolding is not
 * something this product ships. These files are reachable only from bin/outbox-kill-proof.
 *
 * They live in the repository because R-043 is a §5 row about exactly this: a gate clause
 * named a demonstration whose script was in a session scratchpad, so the proof could not
 * be re-run by anyone.
 */

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/*
 * A VAPID KEYPAIR, GENERATED HERE, because the repository holds none and must not.
 *
 * THIS IS WHAT THE PROOF FOUND. The first run of bin/outbox-kill-proof had no VAPID
 * configured, and every row came back `retried` with the push edge never contacted:
 * VAPID::validate() raises inside WebPush's constructor, the dispatcher catches it and
 * backs the row off, and six attempts later it dead-letters. That is now a BOOT CHECK --
 * BootChecks::vapidKeysAreUsable(), which refuses to serve outside local/testing -- and
 * the proof configures a pair of its own so that what it exercises is the worker's
 * crash behaviour rather than a misconfiguration.
 *
 * Generated per process, like the suite's, so nothing is shared and nothing is stored.
 */
$vapid = VAPID::createVapidKeys();

config([
    'webpush.vapid.subject' => 'mailto:proof@qrpager.test',
    'webpush.vapid.public_key' => $vapid['publicKey'],
    'webpush.vapid.private_key' => $vapid['privateKey'],
]);

return $app;
