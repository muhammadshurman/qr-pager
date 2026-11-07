<?php

declare(strict_types=1);

namespace App\Platform\Auth\Http;

use Illuminate\Foundation\Http\FormRequest;

/*
 * INTERNAL NOTES -- not a docblock, because a docblock here is published as the request
 * body's schema description (R-052, and OpenApiSpecTest proves it).
 *
 * No `exists` rule on the email and no `min` on the password. Both are deliberate: a
 * validation error that distinguishes "no such account" from "wrong password" is the
 * enumeration oracle the controller's single answer exists to prevent, and a password
 * length rule published in the spec tells an attacker the shape of the search space. The
 * only job of these rules is to refuse a request that is not even well formed.
 */

/**
 * Credentials for signing in.
 */
final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            // The account's email address.
            'email' => ['required', 'string', 'email', 'max:255'],

            // The account's password.
            'password' => ['required', 'string', 'max:1024'],
        ];
    }
}
