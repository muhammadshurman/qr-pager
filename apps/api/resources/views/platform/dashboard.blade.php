{{--
    The platform dashboard.

    §12: this panel is EXEMPT from the visual bar (no token set exists until Task 8) and is
    NOT exempt from the accessibility bar. So this template uses Filament's own component
    classes and adds no colour, spacing or font value of its own -- there is nothing here for
    Task 8 to have to undo.

    What it does do for accessibility, and each is asserted in PlatformPanelAccessibilityTest:
      - a real <dl>, so the figures are a description list rather than a grid of divs: a
        screen reader reads "Restaurants, 4" rather than "4";
      - every figure's label is its <dt> and is never conveyed by position or colour alone;
      - the staleness of the aggregates is TEXT, not a tooltip and not a colour;
      - no element is given a tabindex, so the tab order is the document order;
      - logical properties only (ms-*/me-* via Filament's own classes), never left/right,
        so the layout is RTL-safe -- Arabic is a launch language and retrofitting it is far
        more expensive than not breaking it.
--}}
<x-filament-panels::page>
    @php($summary = $this->summary())

    <dl class="grid grid-cols-1 gap-16 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Restaurants', $summary->restaurantsTotal, 'Every provisioning record, whatever its state.'],
            ['Active', $summary->restaurantsActive, 'Provisioned and running.'],
            ['In progress', $summary->restaurantsPending, 'Requested and not yet created.'],
            ['Failed', $summary->restaurantsFailed, 'The attempt did not complete. The reason is on the record.'],
            ['Pager sessions', $summary->sessionsTotal, 'Summed across every restaurant.'],
            ['Staff accounts', $summary->staffTotal, 'Summed across every restaurant.'],
            ['Median wait', $summary->medianWaitSeconds === null ? 'No data yet' : $summary->medianWaitSeconds . ' s', 'The median of each restaurant’s own median wait.'],
        ] as [$label, $value, $hint])
            <x-filament::section>
                <dt class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ $label }}</dt>
                <dd class="mt-4 text-3xl font-semibold tracking-tight">{{ $value }}</dd>
                <dd class="mt-4 text-sm text-gray-600 dark:text-gray-300">{{ $hint }}</dd>
            </x-filament::section>
        @endforeach
    </dl>

    {{--
        THE STALENESS, AS TEXT. The aggregates are refreshed every five minutes by
        platform:aggregate-stats, so a figure above can be up to five minutes old. A
        staleness that is not shown is one a reader assumes is zero.

        And the second sentence is the ruling, stated on the screen rather than only in a
        docblock, because the first question a platform admin asks of this page is "why can
        I not see the orders".
    --}}
    <x-filament::section>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            @if ($summary->aggregatesComputedAt === null)
                No aggregates have been computed yet. They are written by
                <code>platform:aggregate-stats</code>.
            @else
                Figures last computed {{ $summary->aggregatesComputedAt }} (UTC), and refreshed
                every five minutes.
            @endif
        </p>
        <p class="mt-8 text-sm text-gray-600 dark:text-gray-300">
            These are aggregates. A platform admin administers a restaurant from the outside
            and cannot see inside it: no order, no pager token and no customer session is
            readable from this panel.
        </p>
    </x-filament::section>
</x-filament-panels::page>
