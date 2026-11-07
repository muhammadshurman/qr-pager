<?php

declare(strict_types=1);

namespace App\Platform\Auth\Http;

use Illuminate\Foundation\Http\FormRequest;

/*
 * INTERNAL NOTES -- not a docblock; a docblock here is published (R-052).
 *
 * Both fields are optional and exactly one is required, which `required_without` expresses
 * in both directions. A request with neither is a 422 rather than a 401, because it is
 * malformed rather than unauthenticated -- and one with BOTH is accepted, with the
 * authenticator code tried first, so a client that sends both is not punished for it.
 */

/**
 * A second factor: an authenticator code, or one recovery code.
 */
final class TwoFactorChallengeRequest extends FormRequest
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
            // The six digits shown by the authenticator app.
            'code' => ['nullable', 'string', 'min:6', 'max:8', 'required_without:recovery_code'],

            // One of the recovery codes issued when two-factor was enabled. Each works once.
            'recovery_code' => ['nullable', 'string', 'max:64', 'required_without:code'],
        ];
    }
}
