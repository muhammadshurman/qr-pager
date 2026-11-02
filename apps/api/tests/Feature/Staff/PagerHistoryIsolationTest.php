<?php

declare(strict_types=1);

use App\Domain\Pager\PagerStatus;
use App\Domain\Tenancy\TenantRole;
use App\Features\Pager\PagerHistoryFilters;
use Illuminate\Support\Facades\DB;

/*
 * ================================================================================
 * 10d's NAMED INVARIANT. `QRPAGER-28 §0`, VERBATIM:
 * ================================================================================
 *
 *   *"Every history filter combination reachable from the UI returns only rows of the caller's
 *   own tenant, proven by issuing each combination cross-tenant over HTTP and asserting the
 *   refusal or the empty set — not by reading the query builder."*
 *
 * And `§6`: *"**every** filter combination reachable from the UI, enumerated and driven
 * cross-tenant over HTTP, each asserting the refusal or the empty set. The combination list is
 * **DERIVED**, not hand-written."*
 *
 * `§0` gives the reason in one line: *"Filters are where a tenant scope gets dropped, so the
 * assertion enumerates them rather than sampling them."*
 *
 * ================================================================================
 * HOW THE COMBINATION LIST IS DERIVED, AND WHY IT IS A FULL POWER SET
 * ================================================================================
 *
 * The screen offers four controls, declared once in `PagerHistoryFilters::DIMENSIONS`:
 *
 *   `status`     a row of toggles, one per `PagerStatus` case. Any subset is reachable.
 *   `from`/`to`  a date range. Set or not.
 *   `reference`  a text box. Set or not.
 *
 * So the reachable set is **the power set of the statuses × the range × the reference**:
 * 2^5 × 2 × 2 = **128 combinations**, each driven in **both directions** = 256 requests.
 *
 * ⚠ **THE POWER SET IS TAKEN IN FULL RATHER THAN REDUCED, AND THE REDUCTION WAS CONSIDERED.**
 * The obvious one — each status alone, plus all, plus none — is 7 × 2 × 2 = 28 and is the kind
 * of thing that reads as thorough. It is a SAMPLE: *"a reasoned skip is not a measurement"* is
 * one of this ticket's own listed traps, and the reason it applies here is specific rather than
 * dogmatic. A dropped scope in a `status in (…)` clause need not be uniform across subset
 * SIZES — the predicate is built by `implode`-ing one placeholder per status, so the
 * single-status path and the multi-status path are different strings, and a reduction that
 * drove only singletons and the full set would never drive a pair. 128 requests against a
 * scoped SELECT on a table with a handful of rows costs a couple of seconds.
 *
 * ⚠ **AND A DIMENSION ADDED TO THE SCREEN DOUBLES THIS SWEEP WITH NO EDIT HERE**, which is the
 * whole point of deriving it. The first case below asserts the dimension list is exactly the
 * four this file knows how to vary, so a fifth fails loudly rather than being silently unswept
 * — the fail-closed shape `RequireAbility` uses.
 *
 * ================================================================================
 * WHY EVERY COMBINATION MUST MATCH SOMETHING IN THE CALLER'S OWN TENANT
 * ================================================================================
 *
 * Every assertion here is an ABSENCE, and an absence passes for free when the filter matches
 * nothing anywhere. A sweep over 128 unsatisfiable combinations is 128 green assertions about
 * nothing — the vacuous shape `PLAN §4a` records four instances of.
 *
 * So the fixture gives **both** tenants a full set: one session in every status, all inside the
 * date range, all sharing a reference substring. Every combination therefore matches at least
 * one row in each tenant, and each request asserts BOTH halves:
 *
 *   the caller's own rows are PRESENT  — so the combination is satisfiable and the query ran;
 *   the other tenant's rows are ABSENT — which is the invariant.
 *
 * A dropped scope fails the second. A broken filter fails the first. Neither can pass quietly.
 */

/**
 * The reachable combinations, derived from the filter declaration.
 *
 * @return list<array{label: string, query: array<string, mixed>}>
 */
function historyCombinations(string $referenceNeedle, string $from, string $to): array
{
    $statuses = PagerStatus::values();
    $combinations = [];

    // The power set of the statuses, by counting in binary over the case list. Derived from
    // the enum, so a status added in PHP doubles this sweep with no edit.
    $subsets = [];

    for ($mask = 0; $mask < (1 << count($statuses)); $mask++) {
        $subset = [];

        foreach ($statuses as $index => $status) {
            if (($mask & (1 << $index)) !== 0) {
                $subset[] = $status;
            }
        }

        $subsets[] = $subset;
    }

    foreach ($subsets as $subset) {
        foreach ([false, true] as $withRange) {
            foreach ([false, true] as $withReference) {
                $query = [];
                $parts = [];

                if ($subset !== []) {
                    $query['status'] = $subset;
                    $parts[] = 'status='.implode('|', $subset);
                }

                if ($withRange) {
                    $query['from'] = $from;
                    $query['to'] = $to;
                    $parts[] = "from={$from},to={$to}";
                }

                if ($withReference) {
                    $query['reference'] = $referenceNeedle;
                    $parts[] = "reference={$referenceNeedle}";
                }

                $combinations[] = [
                    // The label is how a failure identifies itself. In the screen's own
                    // vocabulary rather than as a query string, so a reviewer reading
                    // "status=ready|completed, from=…" can find those controls on the screen.
                    'label' => $parts === [] ? '(no filters)' : implode(', ', $parts),
                    'query' => $query,
                ];
            }
        }
    }

    return $combinations;
}

/**
 * One session in every status, all within the range, all sharing `$needle` in the reference.
 *
 * ⚠ READ BACK AFTER WRITING, so a fixture that silently created nothing cannot make 128
 * cross-tenant assertions pass for free. The count is exact — one per status — because a
 * fixture short by one would make every combination naming that status unsatisfiable, and the
 * *own rows are present* half would fail with a message about the wrong thing.
 *
 * @return list<string> the order references, in status order
 */
function seedHistory(object $test, string $tenantId, string $needle): array
{
    $references = [];

    foreach (PagerStatus::values() as $index => $status) {
        $reference = $needle.'-'.$index;

        (function () use ($tenantId, $status, $reference): void {
            $id = $this->makeSession($tenantId, [
                'order_reference' => $reference,
                'status' => $status,
            ]);

            /*
             * `created_at` is pushed into the middle of the range being filtered on.
             * `makeSession()` writes `now()`, and a range of "yesterday to tomorrow" would
             * include it — but then the range filter would be satisfied by accident rather
             * than by construction, and a test whose fixture only accidentally matches is a
             * test that breaks the day somebody changes the range.
             */
            withTenant($tenantId, fn () => DB::connection('pgsql_owner')
                ->table('pager_sessions')
                ->where('id', $id)
                ->update(['created_at' => now()->subDays(3)]), 'pgsql_owner');
        })->call($test);

        $references[] = $reference;
    }

    $found = withTenant(
        $tenantId,
        fn (): int => DB::table('pager_sessions')->whereIn('order_reference', $references)->count(),
    );

    expect($found)->toBe(
        count(PagerStatus::values()),
        'the history fixture is incomplete, so the cross-tenant sweep would be vacuous',
    );

    return $references;
}

/* -------------------------------------------------------------------------------- */

it('knows every filter dimension the screen offers, so a new one cannot go unswept', function (): void {
    /*
     * CASE 1 — the guard that makes the sweep below worth something.
     *
     * `historyCombinations()` varies `status`, the `from`/`to` pair and `reference`. If a fifth
     * control were added to `PagerHistoryFilters::DIMENSIONS` and the screen, this sweep would
     * simply not vary it — and nothing would say so. The combinations would still be 128, still
     * all pass, and one whole dimension would never cross the tenant boundary.
     *
     * Asserted as an EXACT SET rather than a count, so a dimension RENAMED is caught as well as
     * one added: a rename would leave the count at four and the new name unvaried.
     */
    expect(PagerHistoryFilters::DIMENSIONS)->toBe(['status', 'from', 'to', 'reference']);

    // And the derivation really is the full power set, asserted as an exact value: 2^5 × 2 × 2.
    // A `historyCombinations()` that silently returned one combination would otherwise pass
    // every assertion in this file.
    $combinations = historyCombinations('X', '2026-01-01', '2026-01-02');

    expect($combinations)->toHaveCount((2 ** count(PagerStatus::values())) * 2 * 2);
    expect($combinations)->toHaveCount(128);

    // Every label is distinct, so the sweep is 128 DIFFERENT questions rather than one asked
    // 128 times — which is what a bug in the mask arithmetic would produce.
    $labels = array_column($combinations, 'label');

    expect(array_unique($labels))->toHaveCount(128);
});

it('returns only the caller\'s own restaurant\'s orders for EVERY filter combination, both directions', function (): void {
    /*
     * 10d's INVARIANT. 128 combinations × 2 directions = 256 requests over HTTP.
     *
     * Driven as two REAL staff members of two REAL restaurants — `actingForTenant()` makes a
     * user with one membership each, so alpha's request genuinely is a different principal from
     * beta's, which is what makes this a cross-tenant test rather than a scope test.
     */
    /*
     * ⚠ THE `api` BUCKET IS RAISED, AND THE FIRST RUN OF THIS CASE FAILED ON IT WITH A **429**.
     *
     * 128 combinations × 2 directions = **256 requests** against a bucket of 120/minute/IP, so
     * the 121st answered 429 and the sweep died a quarter of the way through. Raised here for
     * `RoleMatrixTest`'s own stated reason — *"the limiter is NOT what this case is about"* —
     * and `RateLimitTest` drives the `api` bucket to a real 429 on both of its dimensions, so
     * nothing is lost by taking it out of this one's way.
     *
     * Both halves are raised: per-IP because every request comes from the test client's single
     * address, and per-tenant because two tenants take 128 each against a 600/minute ceiling.
     */
    config([
        'ratelimit.buckets.api.per_ip.attempts' => 10_000,
        'ratelimit.buckets.api.per_tenant.attempts' => 10_000,
    ]);

    $needle = 'HIST';

    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();

    $alphaRefs = seedHistory($this, $alpha, $needle);
    $betaRefs = seedHistory($this, $beta, $needle);

    /*
     * ⚠ THE TWO TENANTS' REFERENCES ARE IDENTICAL BY CONSTRUCTION, AND THAT IS DELIBERATE.
     *
     * Both seeds use the same needle, so `HIST-0` exists in alpha AND in beta. That makes the
     * `reference` filter a genuine cross-tenant lever — a dropped scope returns twice as many
     * rows for the same search — and it means the *absence* assertion cannot be satisfied by
     * the needle simply not matching anything over there.
     *
     * It also means absence cannot be asserted by REFERENCE: both tenants have `HIST-0`. So it
     * is asserted by SESSION ID, which is unique per row, and the ids are collected per tenant
     * below. That is the stronger assertion anyway — an id is the thing an IDOR would leak.
     */
    expect($alphaRefs)->toBe($betaRefs);

    $idsIn = static fn (string $tenant): array => array_map(
        static fn (object $row): string => (string) $row->id,
        withTenant($tenant, fn (): array => DB::select('select id from pager_sessions')),
    );

    $alphaIds = $idsIn($alpha);
    $betaIds = $idsIn($beta);

    expect($alphaIds)->toHaveCount(count(PagerStatus::values()));
    expect($betaIds)->toHaveCount(count(PagerStatus::values()));
    // Disjoint, or "no id of the other tenant appears" would be trivially true.
    expect(array_intersect($alphaIds, $betaIds))->toBe([]);

    $from = now()->subDays(10)->format('Y-m-d');
    $to = now()->format('Y-m-d');
    $combinations = historyCombinations($needle, $from, $to);

    foreach ([[$alpha, $alphaIds, $betaIds, 'alpha'], [$beta, $betaIds, $alphaIds, 'beta']] as [$tenant, $own, $foreign, $name]) {
        // A fresh staff member of this tenant. Re-authenticated per direction rather than per
        // combination: the principal is what matters and 256 logins would measure Fortify.
        $this->actingForTenant($tenant, TenantRole::Staff);

        foreach ($combinations as $combination) {
            $response = $this->getJson('/v1/pager-sessions/history?'.http_build_query($combination['query']));

            // A 200 and not a refusal: every filter below is valid input, and a 422 here would
            // mean the sweep was testing validation instead of isolation.
            $response->assertOk();

            $returned = array_map(
                static fn (array $row): string => (string) $row['id'],
                $response->json('sessions') ?? [],
            );

            /*
             * THE VACUITY GUARD, PER COMBINATION. Every combination matches at least one of
             * this tenant's own rows by construction, so an empty answer means the QUERY is
             * broken rather than that isolation held — and the absence assertion below would
             * have passed for free.
             */
            expect($returned)->not->toBeEmpty(
                "{$name} got no rows at all for [{$combination['label']}], so the cross-tenant "
                .'assertion for that combination would prove nothing'
            );

            // THE INVARIANT. Not one id of the other restaurant, for this combination.
            $leaked = array_values(array_intersect($returned, $foreign));

            expect($leaked)->toBe(
                [],
                "{$name} saw the other restaurant's session(s) ".implode(', ', $leaked)
                ." for [{$combination['label']}]"
            );

            // And everything returned really is this tenant's, which is the same statement from
            // the other side — it catches a row from a THIRD tenant a future fixture adds.
            expect(array_values(array_diff($returned, $own)))->toBe(
                [],
                "{$name} saw rows belonging to no known restaurant for [{$combination['label']}]"
            );
        }
    }
});

it('refuses the history route to a kitchen screen, which is why it is not on pager:read', function (): void {
    /*
     * ⚠ THE ONE ASSERTION THAT PROTECTS THE ABILITY DECISION, AND IT IS WHY `Ability::PagerHistory`
     * EXISTS AT ALL.
     *
     * `pager:read` is the single ability `Ability::issuableToDevice()` will issue to a device
     * token, and `RequestPrincipal::may()` requires both the matrix and the token's own
     * abilities to allow. So this route mapped to `pager:read` would have made a restaurant's
     * entire order record readable by every wall-mounted kitchen screen, on a thirty-day
     * credential, with no session — and nothing would have failed, because the route would
     * simply have worked.
     *
     * `RoleMatrixTest` sweeps `device × every staff route` and therefore also covers this cell.
     * What is added here is the LINK: this case names the decision it protects, so somebody
     * re-mapping the route in good faith reads why not.
     */
    $tenant = $this->makeTenant();
    $this->makeSession($tenant);

    $headers = ['Authorization' => 'Bearer '.kdsTokenFor($this, $tenant)];

    $this->getJson('/v1/pager-sessions/history', $headers)->assertStatus(403);

    // And the device CAN still read the live board, so the refusal above is about this route
    // rather than about a token that reaches nothing.
    $this->getJson('/v1/pager-sessions', $headers)->assertOk();
});
