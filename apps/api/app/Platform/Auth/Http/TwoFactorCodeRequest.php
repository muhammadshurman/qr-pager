<?php

declare(strict_types=1);

namespace App\Platform\Auth\Http;

use Illuminate\Foundation\Http\FormRequest;

/*
 * INTERNAL NOTES -- not a docblock; a docblock here is published (R-052).
 */

/**
 * A code from the authenticator.
 */
final class TwoFactorCodeRequest extends FormRequest
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
            'code' => ['required', 'string', 'min:6', 'max:8'],
        ];
    }
}
