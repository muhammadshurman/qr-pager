<?php

declare(strict_types=1);

/* What the outbox looks like right now, read as the worker, across tenants. */

use Illuminate\Support\Facades\DB;

require __DIR__.'/outbox-proof-boot.php';

$label = (string) ($argv[1] ?? 'outbox');

$rows = DB::connection('pgsql_worker')->select(
    'select status, count(*) as n, max(attempts) as max_attempts from outbox group by status order by status'
);

echo "{$label}:", PHP_EOL;

foreach ($rows as $row) {
    printf('  %-8s %3d row(s), max attempts %d%s', $row->status, (int) $row->n, (int) $row->max_attempts, PHP_EOL);
}

exit(0);
