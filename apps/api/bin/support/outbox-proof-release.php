<?php

declare(strict_types=1);

/*
 * Expire the lease on every still-pending row, so the next pass can claim them.
 *
 * THE CLOCK MOVED, NOT THE RULE. The lease is `available_at = now() + lease_seconds`, and
 * the property being proved is "when the lease expires, the next pass finds them". Waiting
 * sixty seconds of wall clock would prove the same thing and make the proof unusable in a
 * suite, so the clock is moved instead -- as the owner, which is a test harness placing a
 * precondition and not an application path (§2.10's pattern).
 *
 * It deliberately does NOT touch status, attempts or anything else the worker decides.
 */

use Illuminate\Support\Facades\DB;

require __DIR__.'/outbox-proof-boot.php';

$released = DB::connection('pgsql_root')->update(
    "update outbox set available_at = now() - interval '1 second' where status = 'pending'"
);

echo "released {$released} leased row(s)", PHP_EOL;

exit(0);
