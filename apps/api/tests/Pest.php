<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/*
 * ⚠ WHY `kdsTokenFor()` IS HERE AND NOT IN THE FILE THAT FIRST NEEDED IT.
 *
 * It was written inside `tests/Feature/Staff/KdsEndpointsTest.php` at 10c, which worked: a
 * function declared in a Pest test file is global once that file is loaded. Then 10d's
 * `PagerHistoryIsolationTest` needed the same helper, and relying on it would have made one
 * test file depend on ANOTHER test file having been loaded first — an ordering Pest does not
 * promise and which `--filter` breaks outright: running the history file alone would fail with
 * *"Call to undefined function kdsTokenFor()"*, which reads as a broken test rather than as a
 * missing include.
 *
 * `tests/Pest.php` is loaded before every file, which is what this section is for.
 */

use App\Domain\Auth\Ability;
use App\Domain\Tenancy\TenantRole;

/**
 * A device token pinned to `$tenantId`, issued through the real route by a real owner.
 *
 * ⚠ ISSUED THROUGH `POST /v1/devices` RATHER THAN BUILT BY HAND, which is `RoleMatrixTest`'s
 * own control and matters for the same reason: the abilities pin and the `tenant:` claim are
 * then the ones the APPLICATION writes, not ones this test invented. A hand-built row would
 * prove nothing about the comparison `AuthenticatedTenantResolver` actually makes.
 *
 * The session is dropped afterwards, which is what a kitchen screen's request looks like: a
 * bearer token and no cookie. Leaving the cookie in place would let `ResolvePrincipal`'s `web`
 * guard answer first and the case would be testing an owner's session, not a device.
 */
function kdsTokenFor(TestCase $test, string $tenantId): string
{
    /*
     * ⚠ `Closure::call()`, NOT A DIRECT CALL, AND THE REASON IS A REAL LANGUAGE RULE RATHER
     * THAN STYLE. `actingForTenant()` and `makeSession()` are `protected` on `Tests\TestCase`,
     * so a plain function cannot reach them — which is why
     * `StaffSurfaceIsolationTest`'s own helpers take their fixtures as ARGUMENTS and say so
     * (*"the two helpers below need nothing from the test case and are plain functions"*).
     *
     * That shape does not work here: what this helper needs is not a value but an ACT — it has
     * to sign in as an owner, issue through the real route, and then drop the session — and
     * passing a token in would mean the caller doing all three. `Closure::call($test)` rebinds
     * `$this` **and the scope** to the test case's class, which is what makes the protected
     * call legal, so the sequence lives in one place and every case gets the same one.
     */
    return (function () use ($tenantId): string {
        $this->actingForTenant($tenantId, TenantRole::Owner);

        $issued = $this->postJson('/v1/devices', [
            'name' => 'kds-probe',
            'ability' => Ability::PagerRead->value,
            'ttl_minutes' => 60,
        ]);

        $issued->assertStatus(201);

        // Drop the cookie, which is what makes the caller a DEVICE. `ResolvePrincipal` tries
        // the `web` guard before `sanctum`, so a request carrying both credentials would
        // resolve as the owner's session and every case here would be testing an owner.
        auth()->forgetGuards();
        app('auth')->guard('web')->logout();

        $token = $issued->json('device.token');

        /*
         * ⚠ NARROWED WITH `is_string()` RATHER THAN WITH `expect(...)->toBeString()->not->...`,
         * AND THE REASON IS THAT **THIS FILE IS ANALYSED BY PHPSTAN WHILE TEST FILES ARE NOT.**
         *
         * `R-002` excludes every `Test.php` file under `tests/` from the analysis (the exclude
         * pattern is a double-star glob, spelled out in prose here because writing it inside a
         * block comment closes the comment), because Pest binds `$this`
         * inside a test closure at run time and PHPStan types it as `TestCall`. `tests/Pest.php`
         * is NOT a `*Test.php` file, so it IS analysed — and the chained form reported *"Access
         * to an undefined property Pest\Mixins\Expectation<mixed>::$not"*, which is a real
         * limitation of the mixin's type rather than a false positive to suppress.
         *
         * A plain narrowing is better here anyway: it tells the compiler what `(string) $token`
         * is casting, instead of asserting it and casting blind.
         */
        if (! is_string($token) || $token === '') {
            throw new RuntimeException(
                'the device token route returned no plaintext token, so every case using this '
                .'helper would present an empty credential and read a 401 as a product failure'
            );
        }

        return $token;
    })->call($test);
}
