<?php

use Illuminate\Support\Facades\DB;

it('runs the suite against the real composed postgres, never sqlite', function (): void {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    expect(DB::selectOne('select current_database() as db')->db)
        ->toBe('qrpager_test');

    expect(DB::selectOne('select version() as v')->v)
        ->toContain('PostgreSQL 16');
});
