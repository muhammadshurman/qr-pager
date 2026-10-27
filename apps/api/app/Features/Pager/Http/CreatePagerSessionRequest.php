<?php

declare(strict_types=1);

namespace App\Features\Pager\Http;

use Illuminate\Foundation\Http\FormRequest;

// INTERNAL NOTES -- LINE COMMENTS ON PURPOSE, SO THEY ARE NOT PUBLISHED.
//
// MOVED OUT OF THE DOCBLOCK AT TASK 4, because it was R-052 in a second place and nobody
// had looked: Scramble ships a FormRequest's docblock verbatim as its schema `description`
// in docs/openapi.json, so the committed spec was publishing "varchar(32) NOT NULL", the
// SQLSTATEs 23514 and 22001, a §2.5 reference and the name of the users_email_is_lowercase
// constraint. R-052 was raised against a CONTROLLER docblock; the mechanism is the same for
// request classes and this file was the first instance of it. OpenApiSpecTest now fails on
// a §-reference or a SQLSTATE in any published description, so the next one cannot land
// quietly.
//
// VALIDATED AGAINST §2.5'S OWN COLUMN, not against a guess: `order_reference` is
// varchar(32) NOT NULL, so a longer or empty value would reach Postgres and come back as a
// 23514 or a 22001 -- a driver error rendered through the envelope, which tells the caller
// nothing about what to fix. 32 and `required` here mean it is a 422 with the field named,
// which is what the `errors` member of the problem document exists for.
//
// `regex` rather than `string` alone because this value is printed on a physical ticket and
// shown on a staff board: a control character or a newline in it is either a broken label or
// a line break in a dashboard. Printable ASCII plus the separators a kitchen actually uses,
// and nothing else.
//
// authorize() returns true and that is NOT the authorization decision. Every /v1 staff route
// is behind RequireTenantScope, which is deny-by-default and answers 401 with no detail when
// no tenant resolves; the tenant then scopes every query, so there is no per-request
// authorization left for this class to make. Stated because an `authorize(): true` that means
// "something else decided" and one that means "anyone may" look identical.

/**
 * The body for creating a pager session: the order reference to print on the ticket.
 */
final class CreatePagerSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'order_reference' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9 \-_#.\/]+$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'order_reference.regex' => 'The order reference may use letters, digits, spaces and - _ # . / only.',
        ];
    }

    public function orderReference(): string
    {
        /** @var string $reference */
        $reference = $this->validated()['order_reference'];

        return trim($reference);
    }
}
