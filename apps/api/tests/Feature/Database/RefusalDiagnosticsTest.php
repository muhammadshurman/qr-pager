<?php

declare(strict_types=1);

use App\Platform\Database\RoleBootstrapper;
use App\Platform\Database\TenantContextException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * R-013, R-023 and R-024: what a refusal is allowed to say.
 *
 * All three rows are about the same property from three directions -- a message must
 * name what it knows, must not claim an outcome it cannot know, and must not become a
 * way for untrusted text to reach a log line. None of them is an access defect, which
 * is why all three are Low; they are the difference between a reason an operator can
 * act on and one they cannot.
 */

/** A connection that cannot be reached, for the one SQLSTATE getCode() gets wrong. */
function deadConnection(string $name = 'pgsql_dead'): string
{
    config(["database.connections.{$name}" => array_merge(
        (array) config('database.connections.pgsql'), ['port' => 1],
    )]);

    return $name;
}

it('names 08006 for a connectivity failure, where getCode() prints 7 — R-013', function (): void {
    $caught = null;

    try {
        withTenant((string) Str::uuid7(), fn () => null, deadConnection());
    } catch (TenantContextException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull('a dead connection was scoped successfully');

    // This is the measurement the row is about. getCode() and errorInfo[0] AGREE for
    // statement errors -- 42P01, 22P02, 22012 -- so a getCode()-based reader looks
    // correct until a connection fails, where getCode() is the integer 7 and the
    // SQLSTATE is 08006. 08006 is also how a saturated role CONNECTION LIMIT arrives.
    expect($caught->getMessage())->toContain('08006')
        ->toContain('could not be read')
        ->not->toContain('SQLSTATE 7')
        // Still carrying no statement and no bindings, which is why the original is
        // deliberately not chained.
        ->and($caught->getPrevious())->toBeNull();
});

it('reports nothing rather than a wrong code when there is no SQLSTATE at all', function (): void {
    // The other half of the same rule: a RuntimeException's code is 0, and printing
    // "(SQLSTATE 0)" would be a lie an operator would chase.
    $message = TenantContextException::statementFailed('read', '')->getMessage();

    expect($message)->toContain('could not be read')->not->toContain('SQLSTATE');
});

it('tells "the statement was never built" from "the statement failed" — R-013', function (): void {
    // Reaching the BUILD phase takes a surgical lever, and this is the honest one:
    // format() runs a `select format(...)` of its own, and the failure has to land on
    // that statement and not on the advisory lock that precedes it. Pointing the
    // bootstrap connection at a dead port fails on `select pg_advisory_lock(...)`
    // instead, outside the redaction entirely -- which is how the first version of this
    // test failed, and the finding is worth keeping: everything before the role
    // statement carries no credential, so it is correctly NOT wrapped.
    //
    // beforeExecuting() is the same framework hook TenantScopeGuard uses, and for the
    // same reason: it fires before the statement reaches Postgres, so the refusal is
    // genuinely in the build phase rather than after it.
    DB::connection('pgsql_bootstrap')->beforeExecuting(function (string $query): void {
        if (str_contains($query, 'select format(')) {
            throw new RuntimeException('the formatter could not be reached');
        }
    });

    $caught = null;

    try {
        app(RoleBootstrapper::class)->run();
    } catch (RuntimeException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull('a role statement that could not be built reported success');

    $message = (string) $caught?->getMessage();

    expect($message)->toContain('Could not build the statement that creates or alters role')
        ->toContain('Nothing was sent to the cluster')
        ->not->toContain('The statement was sent and failed')
        // No SQLSTATE at all here, and that is the point of the other half of R-013:
        // this throwable has code 0, and "(SQLSTATE 0)" would be a code an operator
        // would go looking for. The execute phase names a real one --
        // BootstrapDatabaseCommandTest drives a live 42601 through it.
        ->and($message)->not->toContain('SQLSTATE')
        // And neither phase may carry the credential, which is why building stays
        // inside the redaction: format()'s bound parameters include the verifier.
        ->and($message)->not->toContain('SCRAM-SHA-256')
        ->and($message)->not->toContain('PASSWORD')
        ->and($caught?->getPrevious())->toBeNull();
});

it('does not claim a rollback it cannot know about on the commit-then-reopen path — R-023', function (): void {
    $alpha = $this->makeTenant();
    $reference = 'SURVIVES-'.Str::random(6);

    $caught = null;

    try {
        withTenant($alpha, function () use ($alpha, $reference): void {
            DB::table('outbox')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $alpha,
                'type' => 'pager.ready',
                'dedupe_key' => $reference,
            ]);

            // Commit, then reopen at the same depth. assertScopeSurvived() cannot see
            // this -- the depth matches again -- while the transaction-local setting
            // reverted at the COMMIT, which is what clearScope()'s read-back catches.
            DB::commit();
            DB::beginTransaction();
        });
    } catch (TenantContextException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();

    // THE MEASUREMENT: the row is still there. The old message said "The work has been
    // rolled back", so a caller that believed it and retried would double-write --
    // against §4 › Realtime's "a retry never double-notifies", which is F7's finding
    // surviving in F7's sibling path.
    $survived = withTenant($alpha, fn () => DB::table('outbox')->where('dedupe_key', $reference)->exists());

    expect($survived)->toBeTrue('the premise of this test is gone: the write did not survive');

    $message = (string) $caught?->getMessage();

    expect($message)->toContain('has been ATTEMPTED')
        ->toContain('not safe to retry blindly')
        ->not->toContain('The work has been rolled back')
        ->not->toContain('has been rolled back;');
});

it('reaches the one catch that swallows, and says so rather than claiming success — R-023', function (): void {
    $tenant = $this->makeTenant();

    $caught = null;

    try {
        withTenant($tenant, function (): void {
            // Deeper than the scope opened it, so assertScopeSurvived() will try to
            // unwind -- and the connection is killed underneath, so the rollBack()
            // inside that loop throws and reaches the catch (Throwable) that
            // deliberately swallows. Installing a throw in that catch used to leave the
            // suite green, which is what S-5 recorded: nothing reached the swallow.
            DB::beginTransaction();

            // The ROOT connection, not the owner: pg_terminate_backend needs
            // privileges of the target role or pg_signal_backend, and qrpager_owner has
            // neither -- it answers 42501 "permission denied to terminate process",
            // which is the first thing this test taught me. Root is an observer here,
            // never a code path.
            $pid = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
            DB::connection('pgsql_root')->select('select pg_terminate_backend(?)', [$pid]);
        });
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull('a scope whose connection died reported success');

    // Whatever comes out, it must not be a claim that the unwind worked. The refusal
    // raised from inside the scope is the depth one; Laravel's own transaction wrapper
    // may replace it on a dead connection, so the assertion is on the class of answer
    // rather than on one message.
    $message = (string) $caught?->getMessage();

    expect($message)->not->toContain('has been rolled back, back to the depth')
        ->and($caught)->toBeInstanceOf(TenantContextException::class)
        ->and($message)->toContain('has been ATTEMPTED')
        ->toContain('swallows its own driver errors');
});

it('states what the depth unwind attempted even when it succeeds — R-023', function (): void {
    // The ordinary deeper-depth path, with the connection intact. It is here because
    // the first version of this file asserted the wording only inside an `if
    // ($caught instanceof TenantContextException)` -- and on the killed-connection path
    // Laravel's own wrapper answered with a QueryException, so the assertion never ran
    // and reverting the message left the suite green. An assertion that can skip itself
    // is not an assertion, which is the one shape §4a keeps recording.
    $tenant = $this->makeTenant();

    $caught = null;

    try {
        withTenant($tenant, fn () => DB::beginTransaction());
    } catch (TenantContextException $e) {
        $caught = $e;
    }

    $message = (string) $caught?->getMessage();

    expect($caught)->not->toBeNull()
        ->and($message)->toContain('transaction depth')
        ->toContain('has been ATTEMPTED')
        // The unwind did in fact succeed here, and the message still refuses to say so:
        // it cannot tell, because the loop swallows its own driver errors on purpose.
        ->and($message)->not->toContain('has been rolled back, back to the depth');
});

it('escapes an invalid-UTF-8 value through a real refusal path — R-024', function (): void {
    // The one escaping branch with no test behind it, in the class whose whole job is
    // keeping untrusted text out of a log line. \p{C} with the /u modifier makes
    // preg_replace return NULL on invalid UTF-8, which is the fallback: keep printable
    // ASCII, replace everything else. Reachable as soon as a route passes a path
    // parameter to withTenant, which §4 anticipates for Tasks 2-4 -- and this task is
    // the one that adds the route surface.
    $value = "\xff\xfe".'tenant-'."\x80\x9f".'A'."\x00".'B';

    $caught = null;

    try {
        withTenant($value, fn () => null);
    } catch (TenantContextException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();

    $message = (string) $caught?->getMessage();

    // One line, which is the property that matters: an embedded newline in an exception
    // message is a log entry somebody else wrote.
    expect(preg_split('/\R/', $message))->toHaveCount(1);

    // The printable ASCII survives, so the operator can still see what was rejected.
    // This is what fails when the fallback body is replaced by a constant.
    expect($message)->toContain('tenant-')
        ->toContain('A.B')
        // ...and not one of the invalid bytes reaches the message.
        ->and($message)->not->toContain("\xff")
        ->and($message)->not->toContain("\x80")
        ->and($message)->not->toContain("\x00")
        // Valid UTF-8 throughout, so a JSON log formatter cannot drop the line.
        ->and(mb_check_encoding($message, 'UTF-8'))->toBeTrue();
});

it('counts the messages carrying unvalidated text, or does not count at all — R-024', function (): void {
    // S-8: the docblock said "four of these messages" while five did. A wrong count is
    // worse than none, because it reads as a complete list.
    $source = (string) file_get_contents(app_path('Platform/Database/TenantContextException.php'));

    expect($source)->not->toContain('Four of these messages');

    // Every message that interpolates a value must go through printable(). Read from
    // the source rather than listed here, so a sixth one cannot arrive unnoticed.
    preg_match_all('/public static function (\w+)\(([^)]*)\)/', $source, $matches);

    $interpolating = [];

    foreach ($matches[1] as $index => $name) {
        if (trim($matches[2][$index]) !== '') {
            $interpolating[] = $name;
        }
    }

    // statementFailed takes two, notAUuid one, and so on -- what is pinned is that the
    // set of value-carrying factories is the set the docblock enumerates.
    foreach (['notAUuid', 'alreadyInAnotherTenant', 'tenantContextNotOpenedHere', 'bypassRequiresOwner', 'statementFailed'] as $named) {
        expect($source)->toContain($named)
            ->and($interpolating)->toContain($named);
    }
});
