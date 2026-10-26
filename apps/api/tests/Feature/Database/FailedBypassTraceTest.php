<?php

declare(strict_types=1);

use App\Platform\Database\TenantContextException;
use App\Platform\Logging\RedactLogs;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/*
 * R-005, D7: a bypass that fails leaves no trace.
 *
 * withoutTenantScope() writes its audit_log row INSIDE the same transaction as the
 * work, deliberately, so a bypass that commits always carries it. The price is that a
 * bypass which throws rolls that row back with the work -- so an attacker-triggered or
 * buggy bypass that errors is invisible. F12, raised by the task-1 security review.
 *
 * The fix is a log line, not a second connection: a connection that survives the
 * rollback is a new §2.1 surface and a new failure mode, which is what D7 rejected and
 * what the readiness carrier rejected for R-012. A log line is the one record a
 * rollback cannot take with it.
 *
 * Every case reads the REAL FILE, like LogRedactionTest: an assertion against a spy
 * proves the call was made, not what landed on disk.
 */

beforeEach(function (): void {
    $this->traceFile = storage_path('logs/bypass-'.Str::random(12).'.log');

    config(['logging.channels.tenancy' => [
        'driver' => 'single',
        'path' => $this->traceFile,
        'level' => 'debug',
        'tap' => [RedactLogs::class],
    ]]);

    Log::forgetChannel('tenancy');
});

afterEach(function (): void {
    if (is_string($this->traceFile ?? null) && is_file($this->traceFile)) {
        unlink($this->traceFile);
    }
});

function capturedTrace(string $path): string
{
    return is_file($path) ? (string) file_get_contents($path) : '';
}

it('writes one line naming the reason when a bypass throws — R-005', function (): void {
    $reason = 'task-2 probe: '.Str::random(10);
    $tenant = $this->makeTenant();

    expect(fn () => withoutTenantScope($reason, function () use ($tenant): void {
        DB::connection('pgsql_owner')->table('tenants')->where('id', $tenant)->update(['timezone' => 'UTC']);

        throw new RuntimeException('the bypass failed after doing work');
    }))->toThrow(RuntimeException::class);

    $trace = capturedTrace($this->traceFile);

    // Exactly one line. A bypass failure is the highest-signal event this application
    // can emit and it must not be a burst.
    expect(array_values(array_filter(preg_split('/\R/', trim($trace)) ?: [])))->toHaveCount(1);

    expect($trace)->toContain('tenant bypass failed and left no audit row')
        ->toContain($reason)
        ->toContain('pgsql_owner')
        ->toContain('RuntimeException');
});

it('still leaves zero audit rows, which is the half that was already true', function (): void {
    $reason = 'task-2 probe: '.Str::random(10);

    expect(fn () => withoutTenantScope($reason, fn () => throw new RuntimeException('deliberate')))
        ->toThrow(RuntimeException::class);

    // The audit row rolled back with the work, as designed. The log line is what
    // replaces it -- not a second audit row, which would need a connection that
    // survives the rollback.
    expect($this->asRoot()->table('audit_log')->where('context->reason', $reason)->count())->toBe(0);

    expect(capturedTrace($this->traceFile))->toContain($reason);
});

it('carries no SQL, no bound parameter and no GUC name', function (): void {
    $reason = 'task-2 probe: '.Str::random(10);
    $secret = (string) Str::uuid7();

    // QueryException and not Throwable::class, which cost a confusing failure: Pest's
    // toThrow() treats an argument that is not a CLASS as a message to match, and
    // Throwable is an interface -- so the assertion silently became "the message
    // contains the word Throwable" and reported the whole driver message as the actual
    // value. The concrete type is also the more honest assertion here.
    expect(fn () => withoutTenantScope($reason, function () use ($secret): void {
        // A real driver failure, with a bound parameter that must not appear and a
        // statement that must not appear either.
        DB::connection('pgsql_owner')->select(
            'select * from no_such_table_for_the_bypass_trace where id = ?', [$secret],
        );
    }))->toThrow(QueryException::class);

    $trace = capturedTrace($this->traceFile);

    // THIS CHANNEL'S CONTRACT IS NARROWER than the general log's, which is "values,
    // statements and traces out; names and locations in". Here even the relation name
    // stays out -- not because the redaction processor would remove it (it would not,
    // by design) but because this line is four fields about a failed bypass and has no
    // use for a table name. Measured: passing the throwable's message into the context
    // puts the relation name through, and this case is what catches it. The processor is
    // the backstop; the four fields are the control.
    expect($trace)->toContain($reason)
        // The SQLSTATE is kept: it is the diagnosis, and it is not a value. It also only
        // survives because 'sqlstate' is on the Redactor's NOT_SECRET_KEYS list -- the
        // key contains 'sql', and the substring sweep had been redacting it.
        ->toContain('42P01')
        ->and($trace)->not->toContain($secret)
        ->not->toContain('no_such_table_for_the_bypass_trace')
        ->not->toContain('select *')
        // C1's GUC names never appear: the line says nothing about which setting was in
        // force, because that is not what an operator needs and it is one more thing to
        // keep out of a log.
        ->not->toContain('app.bypass_tenant')
        ->not->toContain('app.tenant_id')
        // No trace, which is where the statement would come back in through the side
        // door -- the measured leak behind the Monolog redaction.
        ->not->toContain('#0 ')
        ->not->toContain('vendor/laravel');
});

it('reports a refusal from the bypass itself, not only a failure inside the callback', function (): void {
    // The bypass that never opens is also a bypass that leaves no audit row, and it is
    // the shape an attacker would produce: calling the named cross-tenant path from
    // somewhere it is not allowed.
    $reason = 'task-2 probe: '.Str::random(10);

    expect(fn () => withoutTenantScope($reason, fn () => true, 'pgsql'))
        ->toThrow(TenantContextException::class, 'may only run as');

    // ...and this one is deliberately NOT reported here. The refusal happens before any
    // transaction opens, so there was never an audit row to lose -- the exception IS the
    // trace, it reaches the application log through the ordinary handler, and a second
    // line on this channel would make "a bypass failed and left no audit row" mean two
    // different things.
    expect(capturedTrace($this->traceFile))->toBe('');
});

it('reports a commit that fails, not just a callback that throws', function (): void {
    $reason = 'task-2 probe: '.Str::random(10);

    // The callback commits and reopens, so restoreBypass() refuses -- a failure that
    // happens AFTER the callback returned successfully. The trace is wrapped around the
    // whole transaction for exactly this: a bypass whose work is discarded at commit
    // time is as untraceable as one whose callback threw.
    expect(fn () => withoutTenantScope($reason, function (): void {
        DB::connection('pgsql_owner')->commit();
        DB::connection('pgsql_owner')->beginTransaction();
    }))->toThrow(TenantContextException::class);

    expect(capturedTrace($this->traceFile))->toContain($reason)
        ->toContain('TenantContextException');
});

it('keeps the channel on the redaction tap, like every other channel', function (): void {
    // Asserted against the real config rather than the test's override: the channel
    // this row adds is one more channel that could be added without the tap, which is
    // the thing LogRedactionTest's sweep exists to prevent.
    $channels = require base_path('config/logging.php');

    expect($channels['channels']['tenancy']['tap'] ?? [])->toBe([RedactLogs::class])
        ->and($channels['channels']['tenancy']['driver'] ?? null)->toBe('single');
});
