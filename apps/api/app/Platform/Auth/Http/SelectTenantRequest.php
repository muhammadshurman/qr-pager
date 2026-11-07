<?php

declare(strict_types=1);

namespace App\Platform\Auth\Http;

use Illuminate\Foundation\Http\FormRequest;

/*
 * INTERNAL NOTES -- not a docblock; a docblock here is published (R-052).
 *
 * The uuid rule is shape-checking and not authorisation: ActiveTenant::select() verifies
 * the membership and the tenant's status, and the resolver re-verifies both on every later
 * request. This rule exists so that a malformed value is a 422 rather than reaching a
 * membership lookup.
 */

/**
 * The restaurant to act in.
 */
final class SelectTenantRequest extends FormRequest
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
            // The restaurant's id, as returned by the sign-in response.
            'tenant_id' => ['required', 'string', 'uuid'],
        ];
    }
}
