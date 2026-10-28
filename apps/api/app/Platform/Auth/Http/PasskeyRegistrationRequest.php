<?php

declare(strict_types=1);

namespace App\Platform\Auth\Http;

use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest as VendorRequest;

/*
 * INTERNAL NOTES -- not a docblock, because a docblock here is published as the request
 * body's schema description (R-052, and OpenApiSpecTest proves it). The first version of
 * this file put all of the below in a docblock and the whole argument landed in
 * docs/openapi.json, which is R-052's defect for the third time on this branch.
 *
 * WHY THIS CLASS EXISTS. `laravel/passkeys`' own request rules `name` at `max:255`;
 * `PLAN.md §2.4` declares `passkeys.name` as `varchar(120)`. A name of 121-255 characters
 * therefore passed validation and reached the INSERT, where Postgres raises `22001` -- a
 * 500 on a request that should answer 422. Found by reading the vendor rules against the
 * schema at Task 5's security review (SEC L-4).
 *
 * THE FIRST FIX WAS AN `mb_strlen()` GUARD IN THE CONTROLLER, AND IT WAS UNREACHABLE.
 * The vendor request deserialises the credential in `passedValidation()`, which runs once
 * validation PASSES -- so for any payload a test can build (an attestation object has to be
 * valid CBOR, and producing one needs a real authenticator, `R-100`) the 422 came from the
 * vendor's `credential` message and the controller's guard was never reached. The
 * regression test written for it asserted only `assertStatus(422)` and therefore PASSED
 * WITH THE GUARD DELETED, which the `§11` QA review of the fix diff measured and reported
 * as its High. `§6` requires a regression test that fails without the fix; that one could
 * not fail.
 *
 * A RULE IS REACHABLE WHERE THE GUARD WAS NOT. `rules()` runs BEFORE `passedValidation()`,
 * so `max:120` short-circuits the request before any deserialisation is attempted, and the
 * refusal is distinguishable: `errors.name` rather than `errors.credential`. That is what
 * makes the test discriminating, and the controller guard is deleted rather than kept as
 * dead code beside it.
 *
 * 120 IS WRITTEN TWICE, here and in the migration, which is the honest cost of the vendor
 * boundary. `PasskeyTest` reads `character_maximum_length` out of `information_schema` and
 * asserts it is 120, so the two cannot drift silently -- that test fails naming this file
 * if the column ever changes.
 *
 * `authorize()` IS DECLARED EXPLICITLY, and the reason stated here first was wrong, so it is
 * stated precisely instead. What happened: an INTERMEDIATE version of this file -- never
 * committed -- made `composer openapi` publish a `403 AuthorizationException` with an
 * `application/json` body, which would have been two non-problem-document responses on a
 * surface where every refusal is one. Declaring `authorize()` removed it before anything was
 * committed. The second `§11` review of the fix diff checked the two COMMITTED specs, found
 * no 403 on this route in either, and was right that "nothing was removed" between them --
 * the measurement was of a state that only existed in the working tree.
 *
 * It is behaviourally a no-op: the vendor DECLARES `authorize(): bool { return true; }`
 * rather than inheriting it, so no authorization check is lost or added. It stays because
 * every other FormRequest in this project declares its own, and because the spec is generated
 * from these classes.
 */

/**
 * A passkey to register.
 */
final class PasskeyRegistrationRequest extends VendorRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'name' => ['required', 'string', 'max:120'],
        ];
    }
}
