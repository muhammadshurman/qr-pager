<?php

declare(strict_types=1);

namespace App\Features\Devices\Http;

use App\Domain\Auth\Ability;
use App\Features\Devices\DeviceTokens;
use Illuminate\Foundation\Http\FormRequest;

/*
 * ================================================================================
 * INTERNAL NOTES -- deliberately NOT a docblock, because a docblock here is PUBLISHED
 * ================================================================================
 *
 * Scramble ships this class's docblock as the request body's schema `description` in
 * docs/openapi.json, and a rule's own comment as that property's `description`. The first
 * draft of this file put "EXPLICIT, which is QRPAGER-12 §5's word" beside `ttl_minutes` and
 * an R-052 reference in the class docblock, and OpenApiSpecTest caught BOTH in the
 * published artifact -- which is R-052's compensating control doing the thing R-052 records
 * it had never done. A `/* *\/` block is not a docblock, so this text reaches no reader of
 * the API.
 *
 * Authorisation is NOT in this class. RequireAbility has already refused anyone without
 * `devices:write` by the time it runs, which is deliberate: a check in middleware applies
 * to the route whether or not this request class exists, while one in a form request is one
 * a reviewer has to go and find. authorize() returns true for that reason.
 *
 *   - `ttl_minutes` is `required` so a caller cannot omit it and inherit a default.
 *     "Forever" is not expressible, and neither is "whatever the config says this week" --
 *     config/sanctum.php leaves the global `expiration` null for exactly that reason.
 *   - `ability` is an allowlist built from Ability::issuableToDevice() rather than written
 *     out, so widening what a device may hold is a change to one PHP method and not to a
 *     validation string somebody could edit without reading it. Validated here AND refused
 *     again in DeviceTokens::issue(): this layer gives a usable 422, and the service's
 *     throw holds if a future caller reaches it another way.
 *   - `name` is bounded because it reaches audit_log's context and a response body.
 */

/**
 * Issue a token for one kitchen screen.
 */
final class IssueDeviceTokenRequest extends FormRequest
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
            // A label for the screen this token belongs to -- "pass", "grill", "counter 2".
            'name' => ['required', 'string', 'min:1', 'max:120'],

            // The one capability a device token may carry.
            'ability' => [
                'required',
                'string',
                'in:'.implode(',', array_map(
                    static fn (Ability $a): string => $a->value,
                    Ability::issuableToDevice(),
                )),
            ],

            // How long the token is valid for, in minutes. Required: a device token has no
            // default lifetime and cannot be issued without one.
            'ttl_minutes' => ['required', 'integer', 'min:1', 'max:'.DeviceTokens::MAX_TTL_MINUTES],
        ];
    }

    public function ability(): Ability
    {
        return Ability::from((string) $this->string('ability'));
    }
}
