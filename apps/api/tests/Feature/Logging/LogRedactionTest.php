<?php

declare(strict_types=1);

use App\Platform\Database\UnsafeDatabaseRoleException;
use App\Platform\Logging\RedactLogs;
use App\Platform\Logging\Redactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * "Captured logs contain no SQL, no bound parameters, no secrets" -- PLAN.md §3, row 2,
 * and one of the three invariants this task ships on (§3.1).
 *
 * It was FALSE at 7f1c8f6, measured and recorded in §4a: one
 * Log::error($e->getMessage(), ['exception' => $e]) for a real QueryException wrote 22
 * lines carrying the statement and the bound uuid interpolated into it. Laravel builds
 * that message itself in Connection::run(), so this is not about careless logging
 * calls -- there is no way to log a query failure without redaction in between.
 *
 * Every case reads the REAL FILE the logger wrote, not a fake. A log assertion against
 * a spy proves the call was made, not what landed on disk.
 *
 * THE CONTRACT, stated once here so no case has to re-argue it:
 *
 *   REDACTED -- the statement text, every bound value, Postgres's DETAIL/HINT/CONTEXT
 *   lines (a constraint violation's DETAIL is the whole failing row), the SCRAM
 *   verifier, connection-string passwords, any context value under a secret-ish key,
 *   and the stack trace.
 *
 *   KEPT -- the SQLSTATE, the driver's description, the relation and constraint names
 *   inside it, and the throw site's file and line. Those are schema and code
 *   locations, which §2 and the repo publish, so they are not secrets; and a log
 *   carrying a SQLSTATE with no idea which object or which line it concerns is most of
 *   the way to useless. The line is VALUES, STATEMENTS and TRACES out; NAMES and
 *   LOCATIONS in.
 */

beforeEach(function (): void {
    $this->logFile = storage_path('logs/redaction-'.Str::random(12).'.log');

    config([
        'logging.default' => 'redaction_probe',
        'logging.channels.redaction_probe' => [
            'driver' => 'single',
            'path' => $this->logFile,
            'level' => 'debug',
            'tap' => [RedactLogs::class],
        ],
        'app.debug' => true,
    ]);

    // forgetChannel(), not forgetDrivers(): the latter is not a LogManager method, so
    // the facade's __call forwarded it to the default channel's Monolog instance and
    // every case in this file errored before asserting anything.
    Log::forgetChannel();
    Log::forgetChannel('redaction_probe');
});

afterEach(function (): void {
    if (is_string($this->logFile ?? null)) {
        @unlink($this->logFile);
    }
});

function capturedLog(string $path): string
{
    return is_file($path) ? (string) file_get_contents($path) : '';
}

it('taps every configured channel, so a new channel cannot log unredacted', function (): void {
    /** @var array<string, array<string, mixed>> $channels */
    $channels = config('logging.channels');
    $missing = [];

    foreach ($channels as $name => $channel) {
        // 'stack' inherits its children's processors (LogManager::createStackDriver
        // collects getProcessors() from each child), and 'null' discards everything.
        // 'emergency' is not resolved through the manager at all, which is why
        // ReportRedactedException redacts at the producer as well.
        if (in_array($name, ['stack', 'null', 'emergency', 'redaction_probe'], true)) {
            continue;
        }

        /** @var list<class-string> $taps */
        $taps = is_array($channel['tap'] ?? null) ? $channel['tap'] : [];

        if (! in_array(RedactLogs::class, $taps, true)) {
            $missing[] = $name;
        }
    }

    expect($missing)->toBe([], 'channels with no redaction tap: '.implode(', ', $missing));
});

it('strips the statement and the bound parameter from a real query failure', function (): void {
    $bound = (string) Str::uuid7();

    try {
        DB::select('select * from no_such_table_for_log_redaction where id = ?', [$bound]);
    } catch (Throwable $e) {
        report($e);
    }

    $log = capturedLog($this->logFile);

    expect($log)->not->toBe('', 'nothing was logged, so this proves nothing');

    // The bound value and the statement, both measured in that 22-line record.
    expect($log)
        ->not->toContain($bound)
        ->not->toContain('select *')
        ->not->toContain('Connection: pgsql');

    // ...while what an operator acts on survives. The relation name is KEPT, and that
    // is a decision rather than an oversight: §2 publishes the schema, so an object
    // name is not a secret, and a log with the SQLSTATE but no idea which object is
    // most of the way to useless. What must never survive is a VALUE or a statement.
    expect($log)->toContain('42P01')
        ->and($log)->toContain('no_such_table_for_log_redaction')
        ->and($log)->toContain(Redactor::PLACEHOLDER);
});

it('strips the DETAIL line, which carries every column of the failing row', function (): void {
    // The leak this file caught in its own first run. A constraint violation's DETAIL
    // is "Failing row contains (uuid, name, email, ...)" -- the whole row, in the
    // driver's own description, BEFORE the (Connection: ..., SQL: ...) clause. Stripping
    // only that clause left the row dump behind.
    $email = 'detail-leak-probe@example.test';

    try {
        DB::connection('pgsql_owner')->table('users')->insert([
            'id' => (string) Str::uuid7(),
            'name' => 'Detail Leak Probe',
            'email' => strtoupper($email),
        ]);
    } catch (Throwable $e) {
        report($e);
    }

    $log = capturedLog($this->logFile);

    expect($log)->not->toBe('')
        ->and($log)->not->toContain('Failing row contains')
        ->and($log)->not->toContain(strtoupper($email))
        ->and($log)->not->toContain('Detail Leak Probe')
        // The constraint name stays, for the same reason the relation name does.
        ->and($log)->toContain('23514');
});

it('drops the stack trace rather than rendering the statement inside it', function (): void {
    try {
        DB::select('select * from no_such_table_in_the_trace where id = ?', ['trace-bound-value']);
    } catch (Throwable $e) {
        // The shape §4a measured: the exception handed to the logger in context.
        Log::error($e->getMessage(), ['exception' => $e]);
    }

    $log = capturedLog($this->logFile);

    expect($log)
        ->not->toContain('trace-bound-value')
        // No trace: no frame markers, and no "trace" key from Monolog's normaliser.
        ->not->toContain('"trace"')
        ->not->toContain('#1 /')
        ->not->toContain('ApplicationGateway');

    // The throw site IS kept -- file and line, which for a QueryException is where
    // Laravel builds it. That is a location, not a value.
    expect($log)->toContain('Connection.php');

    // 22 lines at 7f1c8f6; one record on one line now, which is the measurement that
    // says the trace is gone rather than merely shortened.
    expect(substr_count(trim($log), "\n"))->toBeLessThan(3);
});

it('logs the role and the reasons for R-021, which the response body must not carry', function (): void {
    Route::middleware('api')->prefix('v1')->get('probe-role-log', fn () => throw new UnsafeDatabaseRoleException(
        'qrpager_app',
        ['it is a SUPERUSER, which ignores FORCE row level security entirely'],
    ));

    $body = (string) $this->getJson('/v1/probe-role-log')->getContent();
    $log = capturedLog($this->logFile);

    // R-021's acceptance condition, both halves in one test so neither can drift.
    expect($log)->toContain('qrpager_app')
        ->and($log)->toContain('SUPERUSER')
        ->and($body)->not->toContain('qrpager_app')
        ->and($body)->not->toContain('SUPERUSER');
});

it('redacts on the emergency logger too, which no tap can reach', function (): void {
    // The one path the RedactLogs tap cannot cover, and the reason
    // ReportRedactedException exists. LogManager::get() falls back to
    // createEmergencyLogger() when a channel cannot be resolved, and that logger is
    // built directly -- new Monolog(...) with a StreamHandler -- so no channel tap is
    // applied to it. Reporting from our own callback and returning false hands it text
    // that is already redacted.
    //
    // Without this test the producer-side redaction was invisible: removing the report
    // callback left all 16 cases in this file and ProblemEnvelopeTest passing, because
    // the sink-side processor covered every one of them.
    $emergency = storage_path('logs/emergency-'.Str::random(12).'.log');
    $bound = (string) Str::uuid7();

    config([
        'logging.channels.emergency.path' => $emergency,
        // A driver the manager cannot build, so resolution fails and the emergency
        // logger is what ends up receiving the record.
        'logging.default' => 'deliberately_broken',
        'logging.channels.deliberately_broken' => ['driver' => 'no-such-driver'],
    ]);

    Log::forgetChannel();
    Log::forgetChannel('deliberately_broken');

    try {
        try {
            DB::select('select * from no_such_table_emergency where id = ?', [$bound]);
        } catch (Throwable $e) {
            report($e);
        }

        $log = capturedLog($emergency);

        expect($log)->not->toBe('', 'the emergency logger wrote nothing, so this proves nothing')
            ->and($log)->not->toContain($bound)
            ->and($log)->not->toContain('select * from')
            ->and($log)->not->toContain('Connection: pgsql')
            ->and($log)->toContain('42P01');
    } finally {
        @unlink($emergency);
    }
});

it('redacts a secret passed in context, by key name', function (): void {
    Log::info('probe', [
        'db_password' => 'local_dev_only',
        'AUTHORIZATION' => 'Bearer abc.def.ghi',
        'nested' => ['api_key' => 'sk-live-1234', 'safe' => 'keep-me'],
    ]);

    $log = capturedLog($this->logFile);

    expect($log)
        ->not->toContain('local_dev_only')
        ->not->toContain('Bearer abc.def.ghi')
        ->not->toContain('sk-live-1234')
        ->and($log)->toContain('keep-me');
});

it('redacts a SCRAM verifier wherever it appears — R-009 backstop', function (): void {
    Log::warning('role statement failed: SCRAM-SHA-256$4096:c2FsdA==$c3RvcmVk:c2VydmVy and more');

    expect(capturedLog($this->logFile))
        ->not->toContain('SCRAM-SHA-256$4096')
        ->not->toContain('c3RvcmVk');
});

it('keeps redacting when the request goes through HTTP', function (): void {
    $bound = (string) Str::uuid7();

    Route::middleware('api')->prefix('v1')->get('probe-http-log', fn () => DB::select(
        'select * from no_such_table_over_http where id = ?', [$bound],
    ));

    $this->getJson('/v1/probe-http-log')->assertStatus(500);

    expect(capturedLog($this->logFile))
        ->not->toContain($bound)
        ->not->toContain('select * from');
});
