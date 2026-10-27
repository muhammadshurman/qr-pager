<?php

declare(strict_types=1);

namespace App\Features\Members\Http;

use App\Domain\Tenancy\TenantRole;
use Illuminate\Foundation\Http\FormRequest;

/*
 * INTERNAL NOTES -- not a docblock; a docblock here is published (R-052). Authorisation is
 * RequireAbility's, before this runs; see StoreMemberRequest on why authorize() is true.
 */

/**
 * Change one member's role.
 */
final class UpdateMemberRoleRequest extends FormRequest
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
