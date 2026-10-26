<?php

declare(strict_types=1);

use App\Platform\Database\DriverError;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * PLAN.md §6 Open items: "QueryException::getCode() is not reliably the SQLSTATE; the
 * real code is on the PDO exception underneath, at getPrevious()->errorInfo[0]. Task 2
 * owns error mapping and should start there rather than re-finding it."
 *
 * This file is the measurement that makes "not reliably" precise, kept in the suite so
 * it cannot be re-found a third time.
 */

it('agrees with getCode() for ordinary statement errors', function (string $sql, string $expected): void {
    try {
        DB::select($sql);
        throw new RuntimeException('the statement did not fail');
    } catch (QueryException $e) {
        expect(DriverError::sqlState($e))->toBe($expected)
            ->and((string) $e->getCode())->toBe($expected, 'these agree for statement errors');
    }
})->with([
    'undefined table' => ['select 1 from no_such_table_driver_error', '42P01'],
    'invalid uuid text' => ["select ''::uuid", '22P02'],
    'division by zero' => ['select 1/0', '22012'],
]);

it('disagrees with getCode() for a connection failure, which is the case that matters', function (): void {
    // A refused connection and a wrong password both report getCode() = 7, which is
    // not a SQLSTATE. 08006 is also how a saturated role CONNECTION LIMIT arrives:
    // measured, the 41st qrpager_app connection is refused 08006, never 53300. A
    // mapper built on getCode() therefore reports "SQLSTATE 7" for both.
    config(['database.connections.pgsql_driver_probe' => array_merge(
        (array) config('database.connections.pgsql'),
        ['port' => '59997'],
    )]);

    try {
        DB::connection('pgsql_driver_probe')->select('select 1');
        throw new RuntimeException('the dead port accepted a connection');
    } catch (QueryException $e) {
        expect(DriverError::sqlState($e))->toBe(DriverError::CONNECTION_FAILURE)
            ->and((string) $e->getCode())->toBe('7')
            ->and((string) $e->getCode())->not->toBe(DriverError::CONNECTION_FAILURE);
    } finally {
        DB::purge('pgsql_driver_probe');
    }
});

it('reports nothing rather than a wrong code when there is no driver error', function (): void {
    // R-013's other half: format()'s own RuntimeException has code 0, and the old
    // message suppressed it. A connectivity failure under the same code path would
    // have printed "SQLSTATE 7". Neither is a SQLSTATE, so neither is reported as one.
    expect(DriverError::sqlState(new RuntimeException('no driver involved')))->toBeNull()
        ->and(DriverError::sqlStateForMessage(new RuntimeException('no driver involved')))->toBe('')
        ->and(DriverError::sqlStateForMessage(new RuntimeException('seven', 7)))->toBe('');
});

it('finds the SQLSTATE through a wrapping exception', function (): void {
    try {
        DB::select('select 1 from no_such_table_driver_error');
        throw new RuntimeException('the statement did not fail');
    } catch (QueryException $e) {
        $wrapped = new RuntimeException('wrapped once', 0, new RuntimeException('wrapped twice', 0, $e));

        expect(DriverError::sqlState($wrapped))->toBe('42P01');
    }
});
