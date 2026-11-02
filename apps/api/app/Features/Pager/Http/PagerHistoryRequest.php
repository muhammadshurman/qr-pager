<?php

declare(strict_types=1);

namespace App\Features\Pager\Http;

use App\Domain\Pager\PagerStatus;
use App\Features\Pager\PagerHistoryFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

// INTERNAL NOTES -- LINE COMMENTS ON PURPOSE, SO THEY ARE NOT PUBLISHED.
//
// `CreatePagerSessionRequest` records why: Scramble ships a FormRequest's docblock verbatim as
// its schema `description` in docs/openapi.json (R-052), and `OpenApiSpecTest` fails on a
// §-reference or a SQLSTATE in any published description. Everything below is internal
// reasoning, so it is line comments.
//
// THE RULES ARE BUILT FROM `PagerHistoryFilters::DIMENSIONS`-adjacent constants rather than
// from literals, which is 10d's gate clause reaching into validation: the enum is the one
// source for which statuses exist, `MAX_RANGE_DAYS` is the one source for how wide a window
// may be, and a status added in PHP is accepted here without an edit.
//
// `status` IS AN ARRAY AND NOT A COMMA-SEPARATED STRING. A comma list would need splitting,
// which means a parser, which means a second place where "which statuses were asked for" is
// decided -- and a parser that silently dropped an unknown value would turn a typo into a
// wider query rather than a 422. `?status[]=ready&status[]=completed` is what the browser's
// own `URLSearchParams` emits for a multi-select, so there is nothing to parse on either side.
//
// THE RANGE IS VALIDATED AS A RANGE, not only as two dates. `to` must not precede `from`, and
// the span must not exceed MAX_RANGE_DAYS -- both are 422s with the field named, because the
// alternative is a query that scans a year and answers slowly, which a person reads as the
// screen being broken rather than as the question being too wide.
//
// ⚠ THE PER-FIELD REASONING IS HERE AND NOT ABOVE THE KEYS IN `rules()`, AND THAT COST A
// GATE RED TO RELEARN. `R-052` is not only about the class docblock: Scramble publishes a
// comment above a key in that array as the PROPERTY'S description, which is the mechanism
// `CreatePagerSessionRequest`'s own notes record and which `R-222` hit again at 10b from the
// controller side. The first version of this file put the three notes below above their keys
// and `docs/openapi.json` came out carrying *"`order_reference` is varchar(32)"* — a column
// type in a published contract. `OpenApiSpecTest` fails on a `§`-reference or a SQLSTATE but
// not on a column type, so the catch was a grep of the regenerated spec rather than the suite.
//
//   `status`     AN ARRAY, not a comma-separated string. Absent and empty both mean *every*
//                status, which is what the screen's untoggled filter means --
//                `PagerHistoryFilters::fromValidated()` carries that decision and why the
//                alternative would make the isolation enumeration vacuous for half its
//                combinations. A comma list would need a parser, which is a second place where
//                "which statuses were asked for" is decided, and a parser that silently
//                dropped an unknown value would turn a typo into a WIDER query rather than a
//                422. `?status[]=ready&status[]=completed` is what `URLSearchParams` emits for
//                a multi-select, so there is nothing to parse on either side.
//   `from`/`to`  ISO DATES, not timestamps. A person filters by day; the inclusive-at-both-ends
//                boundary that needs is in `PagerSessions::history()` beside the SQL.
//   `reference`  the same character class and the same ceiling as the column it searches. A
//                longer needle cannot match anything, so accepting one would run a scan to
//                answer nothing; and the regex keeps a `%` and a `_` out of the value, which
//                matters because the query uses `ILIKE`. The wildcards are added in the query
//                rather than taken from the caller, so that is two independent bounds.
//
// authorize() returns true and that is NOT the authorization decision -- the same note
// `CreatePagerSessionRequest` carries. `RequireAbility` has already required
// `Ability::PagerHistory` by route name, and `RequireTenantScope` has already resolved the
// tenant that scopes every query. There is no per-request authorization left for this class.

/**
 * The query for the order history: which states, which dates, and a reference to look for.
 */
final class PagerHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     *
     * ⚠ `'in:…'` BUILT FROM THE ENUM RATHER THAN `Rule::in()`, WHICH IS
     * `UpdateMemberRoleRequest`'s OWN CONVENTION ONE FEATURE OVER — and it is a typing fact
     * rather than a style preference. `Rule::in()` returns `Illuminate\Validation\Rules\In`,
     * which does NOT implement `ValidationRule`, so a `list<string|ValidationRule>` return type
     * does not describe it and PHPStan says so. The honest options were to widen the annotation
     * until it admitted the object, or to keep every rule a string; the second keeps the
     * signature identical to every other FormRequest here and loses nothing — the status list
     * is still derived from `PagerStatus::values()`, so a status added in PHP is accepted
     * without an edit.
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'array', 'max:'.count(PagerStatus::cases())],
            'status.*' => ['string', 'in:'.implode(',', PagerStatus::values())],

            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],

            'reference' => [
                'sometimes',
                'nullable',
                'string',
                'max:32',
                'regex:/^[A-Za-z0-9 \-_#.\/]+$/',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            // Every message is a sentence a restaurant owner can act on -- `§12`'s bar, and the
            // reason none of them names a column, a format string or a status enum value.
            'status.*.in' => 'That is not an order state we have.',
            'from.date_format' => 'Use a date like 2026-11-02 for the start of the range.',
            'to.date_format' => 'Use a date like 2026-11-02 for the end of the range.',
            'to.after_or_equal' => 'The end of the range cannot be before the start.',
            'reference.regex' => 'An order reference may use letters, digits, spaces and - _ # . / only.',
            'reference.max' => 'An order reference is never longer than 32 characters.',
        ];
    }

    /**
     * The range is a range.
     *
     * Checked here rather than in `rules()` because it is a relationship between two fields
     * and a rule cannot express the SPAN -- only the ordering, which `after_or_equal` already
     * does above.
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $from = $this->input('from');
            $to = $this->input('to');

            if (! is_string($from) || ! is_string($to) || $from === '' || $to === '') {
                return;
            }

            $start = strtotime($from);
            $end = strtotime($to);

            if ($start === false || $end === false) {
                // The format rules have already failed; adding a second message about a span
                // nobody can compute would be two errors for one mistake.
                return;
            }

            $days = (int) floor(($end - $start) / 86400);

            if ($days > PagerHistoryFilters::MAX_RANGE_DAYS) {
                $v->errors()->add('to', sprintf(
                    'Choose a range of %d days or fewer. Ask for a shorter period, or look at one month at a time.',
                    PagerHistoryFilters::MAX_RANGE_DAYS,
                ));
            }
        });
    }

    public function filters(): PagerHistoryFilters
    {
        return PagerHistoryFilters::fromValidated($this->validated());
    }
}
