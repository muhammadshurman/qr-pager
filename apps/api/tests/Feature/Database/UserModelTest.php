<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;

/*
 * users.id is a uuid like every other id in the contract (PLAN.md §2.4), so the
 * model must not expect an auto-incrementing key. Task 5 builds auth on top of
 * this; getting the key type wrong there is a migration, not a fix.
 */

it('gives a new user a uuid key rather than an integer', function (): void {
    $user = User::factory()->create();

    expect($user->getKeyType())->toBe('string')
        ->and($user->getIncrementing())->toBeFalse()
        ->and(Str::isUuid($user->id))->toBeTrue();
});

it('stores the email lower-cased, which the column constraint requires', function (): void {
    $user = User::factory()->create();

    expect($user->email)->toBe(Str::lower($user->email));
});

it('refuses a mixed-case email at the database level', function (): void {
    expect($this->sqlStateOf(fn () => User::factory()->create(['email' => 'Mixed.Case@example.test'])))
        ->toBe('23514');
});

it('hides the password and remember token from serialisation', function (): void {
    $user = User::factory()->create();

    expect(array_keys($user->toArray()))->not->toContain('password')
        ->and(array_keys($user->toArray()))->not->toContain('remember_token');
});
