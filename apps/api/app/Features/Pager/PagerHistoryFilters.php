<?php

declare(strict_types=1);

namespace App\Features\Pager;

use App\Domain\Pager\PagerStatus;
use Carbon\CarbonImmutable;

/**
 * ================================================================================
 * THE HISTORY SCREEN's FILTERS. ONE SOURCE, AND THE "ONE" IS 10d's GATE CLAUSE.
 * ================================================================================
 *
 * `QRPAGER-28 §0`, the named invariant, verbatim:
 *
 *   *"Every history filter combination reachable from the UI returns only rows of the
 *   caller's own tenant, proven by issuing each combination cross-tenant over HTTP and
 *   asserting the refusal or the empty set — not by reading the query builder."*
 *
 * And `§6`: *"The combination list is **derived**, not hand-written."*
 *
 * **That clause is the reason this class exists.** A filter set spread across a FormRequest's
 * `rules()`, a query builder and a React component is three lists, and a test that enumerated
 * any one of them would be enumerating a copy — so a fourth filter added to the screen and
 * forgotten in the test would be a combination nobody ever drove across the tenant boundary.
 * Here there is one declaration, `DIMENSIONS`, and:
 *
 *   - `PagerHistoryRequest::rules()` is built from it;
 *   - `PagerSessions::history()` applies exactly these and nothing else;
 *   - `PagerHistoryIsolationTest` derives its **cartesian product** from it, so a dimension
 *     added here doubles the number of cross-tenant requests the suite makes with no edit to
 *     that file at all;
 *   - the TypeScript screen reads the same names through `historyQueryString()`, and
 *     `apps/web/test/history-filters.test.ts` pins its list against this one.
 *
 * ================================================================================
 * WHY THE TENANT IS NOT A DIMENSION, AND CANNOT BECOME ONE
 * ================================================================================
 *
 * Every filter below narrows WITHIN a scope that is already open. `PagerSessions::history()`
 * runs inside `withTenant()`, so row-level security has already made another restaurant's rows
 * invisible before a single filter is applied — which is why the invariant's expected answer
 * is *"the empty set"* rather than a refusal: there is nothing to refuse, because there is
 * nothing there.
 *
 * ⚠ **AND THAT IS EXACTLY WHY THE TEST ENUMERATES RATHER THAN SAMPLES.** The reasoning above
 * is an argument, and `QRPAGER-28 §0` says *"not by reading the query builder"* precisely
 * because arguments of that shape are how a dropped scope survives review. The one way a
 * filter could escape the scope is a raw fragment or a second connection; nothing below is
 * either, and the enumeration is what makes that a measurement instead of a promise.
 */
final class PagerHistoryFilters
{
    /**
     * The filter dimensions the UI offers, and the ONLY ones.
     *
     * ⚠ THE ORDER IS THE SCREEN'S READING ORDER, not an implementation order — the test
     * reports a failing combination by these names, and a reviewer reading *"status+from+to"*
     * should be able to find those three controls on the screen in that sequence.
     *
     * @var list<string>
     */
    public const DIMENSIONS = ['status', 'from', 'to', 'reference'];

    /**
     * How long a history window may be, in days.
     *
     * ⚠ A BOUND RATHER THAN A PREFERENCE, and it is `§6` Q5's answer for this route: *what is
     * bounded*. A history query with no ceiling is a sequential scan whose size is set by how
     * long a restaurant has been a customer, and `§2.5`'s index on `(tenant_id, status,
     * created_at DESC)` stops helping once the range is the whole table.
     *
     * 92 days is one quarter, which is the longest range a restaurant owner has a reason to
     * ask for in one view (a VAT quarter) and is three times 10d's own replacement for the
     * struck *shift view* (`R-217`). A wider question is a report, not a screen.
     */
    public const MAX_RANGE_DAYS = 92;

    /**
     * The most rows one page returns.
     *
     * The same figure and the same reason as `PagerSessions::board()`'s: a list with no limit
     * is a transaction whose size is set by how busy a restaurant is.
     */
    public const PAGE_SIZE = 100;

    /**
     * @param  list<PagerStatus>  $statuses  empty means *every* status, which is what the
     *                                       screen's untoggled filter means. See below.
     */
    private function __construct(
        public readonly array $statuses,
        public readonly ?CarbonImmutable $from,
        public readonly ?CarbonImmutable $to,
        public readonly ?string $reference,
    ) {}

    /**
     * Build from already-validated input.
     *
     * ⚠ **AN EMPTY `status` LIST MEANS *EVERY* STATUS, NOT *NO* STATUS, AND THAT IS THE ONE
     * SEMANTIC DECISION IN THIS CLASS.** The screen's status control is a row of toggles; with
     * none pressed, a person means *show me everything*, which is what every list UI in the
     * world does and what `§12`'s zero-technical-background bar requires — an untoggled filter
     * that returned nothing would present an empty screen as the default state of a restaurant
     * that has taken a thousand orders.
     *
     * The alternative reading — empty means match nothing — would also make the isolation
     * enumeration **vacuous for half its combinations**: a cross-tenant assertion over a
     * filter that matches nothing in anybody's tenant passes for free. `PagerHistoryIsolationTest`
     * requires each combination to return at least one row in the caller's OWN tenant for
     * exactly that reason, and this reading is what makes that requirement satisfiable.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $statuses = [];

        /** @var list<string> $raw */
        $raw = is_array($validated['status'] ?? null) ? array_values($validated['status']) : [];

        foreach ($raw as $value) {
            $case = PagerStatus::tryFrom((string) $value);

            // A value validated against the enum cannot be null here. Guarded anyway, because
            // this is the boundary between a validator's promise and a query's assumption, and
            // a `null` reaching the `whereIn` below would bind as SQL NULL and match nothing --
            // silently turning a filter into an empty screen.
            if ($case !== null) {
                $statuses[] = $case;
            }
        }

        $reference = is_string($validated['reference'] ?? null) ? trim($validated['reference']) : '';

        return new self(
            statuses: $statuses,
            from: self::day($validated['from'] ?? null),
            to: self::day($validated['to'] ?? null),
            // An all-whitespace search is no search. Normalised here rather than at the query,
            // so `hasReference()` and the SQL cannot disagree about what counts as set.
            reference: $reference === '' ? null : $reference,
        );
    }

    /**
     * The start of the day, in UTC.
     *
     * ⚠ **DATES, NOT TIMESTAMPS, AND THE BOUNDARY IS INCLUSIVE AT BOTH ENDS** — which is what
     * a person means by *"the 3rd to the 5th"* and is not what a naive `created_at <= '2026-11-05'`
     * does: that compares against midnight and silently drops every order taken on the 5th.
     * `PagerSessions::history()` adds a day to `to` and uses a strict `<` for that reason, and
     * its own comment says so beside the SQL.
     */
    private static function day(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value, 'UTC')->startOfDay();
    }

    public function hasStatuses(): bool
    {
        return $this->statuses !== [];
    }

    public function hasReference(): bool
    {
        return $this->reference !== null;
    }

    /**
     * The status values, as strings, for binding.
     *
     * @return list<string>
     */
    public function statusValues(): array
    {
        return array_map(static fn (PagerStatus $s): string => $s->value, $this->statuses);
    }

    /*
     * ⚠ TWO METHODS WERE HERE AND ARE DELETED, AND ONE OF THEM CARRIED A FALSE CLAIM.
     *
     * `activeDimensions()` returned which dimensions were set and its docblock said it was
     * *"used by the audit context and by the isolation test's failure messages"*. It was used
     * by NEITHER: `PagerHistoryIsolationTest` builds its own label from the combination it is
     * driving, and this route writes no audit row. Zero call sites, measured — and a comment
     * asserting a caller that does not exist is worse than the dead code it describes, because
     * the next reader trusts it.
     *
     * `unfiltered()` was a convenience with no caller either; the client's own
     * `NO_HISTORY_FILTERS` is the TypeScript end and `fromValidated([])` is the PHP one.
     *
     * Found by the CI step this branch had not run locally — `php artisan test --coverage
     * --min=90` — which is the gate that makes unused code cost something.
     */
}
