<?php

declare(strict_types=1);

namespace App\Features\Members\Http;

use App\Domain\Tenancy\TenantRole;
use Illuminate\Foundation\Http\FormRequest;

/*
 * INTERNAL NOTES -- not a docblock, because a docblock here is PUBLISHED as the request
 * body's schema description (R-052, and OpenApiSpecTest proves it).
 *
 * Authorisation is not in this class: RequireAbility has already refused anyone without
 * `members:write`. authorize() returns true for that reason and not because nothing is
 * checked -- a check in middleware applies to the route whether or not this class exists.
 *
 *   - `lowercase` because users_email_is_lowercase is a CHECK constraint and the exact match
 *     in MemberDirectory::add() has to agree with it. R-007's other half was that a raw
 *     23514 from that constraint surfaced as an unmapped error; Task 2 fixed the mapping and
 *     this is the half that stops it being raised at all.
 *   - `role`'s allowlist is built from TenantRole rather than written out, so adding a role
 *     is a change to one PHP file and not to a validation string somebody could widen
 *     without reading PLAN.md section 2.3.
 */

/**
 * Add an existing account to this restaurant's staff.
 */
final class StoreMemberRequest extends FormRequest
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
            // The address of an existing account, lower-cased.
            'email' => ['required', 'string', 'email', 'max:255', 'lowercase'],

            // The role this member holds in the restaurant.
            'role' => ['required', 'string', 'in:'.implode(',', array_map(
                static fn (TenantRole $r): string => $r->value,
                TenantRole::cases(),
            ))],
        ];
    }

    public function role(): TenantRole
    {
        return TenantRole::from((string) $this->string('role'));
    }
}
