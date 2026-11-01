{{--
    §12.1 — /studio, the landing page builder.

    All six states of the specification are rendered here, each with the exact copy the
    ticket wrote before this file existed: empty, draft, draft with unpublished changes,
    published and unchanged, invalid, and publish failed (the last as a notification,
    because it is the outcome of an action rather than a resting state).

    §12: exempt from the VISUAL bar until Task 8, never from the accessibility bar. So:
    every control is a real <button> or <a>, every state is announced in TEXT and never
    by colour alone, the status region is a live region so a screen reader hears a save,
    and the page has one <h1> with headings in order.
--}}
<x-filament-panels::page>
    @php($state = $this->state())

    <div role="status" aria-live="polite" class="fi-section p-16 space-y-8">
        <h2 class="text-base font-semibold">Status</h2>

        @if ($state['invalid'] !== null)
            {{-- INVALID. The refusing field is named inline and Publish is disabled. --}}
            <p class="text-danger-700 dark:text-danger-400">
                <strong>Not publishable.</strong> {{ $state['invalid'] }}
            </p>
        @elseif ($state['blocks'] === 0)
            <p>Your page has no blocks yet. Add one to get started.</p>
        @elseif ($state['status'] === \App\Domain\Landing\LandingPageStatus::Draft)
            <p>Draft — not visible to anyone yet.</p>
        @elseif ($state['has_unpublished_changes'])
            <p>You have changes that are not published yet.</p>
            <p>Published to {{ $state['published_hostname'] }} on {{ $state['published_at'] }}.</p>
        @else
            <p>Published to {{ $state['published_hostname'] }} on {{ $state['published_at'] }}.</p>
        @endif

        <p class="text-sm">
            Your page will be published to <strong>{{ $state['hostname'] }}</strong>.
        </p>
    </div>

    <form wire:submit="save" class="space-y-24">
        {{ $this->form }}

        <div class="flex flex-wrap gap-12">
            <button type="submit" class="fi-btn fi-btn-size-md fi-color-primary px-16 py-8 rounded-lg">
                Save
            </button>

            <a href="{{ route('filament.studio.preview') }}" target="_blank" rel="noopener"
               class="fi-btn fi-btn-size-md px-16 py-8 rounded-lg underline">
                Preview <span class="sr-only">(opens in a new tab)</span>
            </a>

            {{-- Disabled when there is nothing publishable, which is §12.1's empty and
                 invalid states. `disabled` and not merely a style, so the control is
                 genuinely unavailable to a keyboard and to a screen reader. --}}
            <button type="button" wire:click="publish"
                    @disabled($state['blocks'] === 0 || $state['invalid'] !== null)
                    class="fi-btn fi-btn-size-md fi-color-success px-16 py-8 rounded-lg disabled:opacity-50">
                Publish
            </button>

            {{-- ⚠ ALWAYS OFFERED, NOT ONLY WHEN THE ROW SAYS PUBLISHED. A page can be live
                 with the row at `draft` -- a redirect stub left by a domain activation
                 (R-161), or any state an out-of-band change produces -- and Unpublish is
                 the only verb a tenant has. Hiding it on the row's say-so put the "no
                 path left to take it down" defect back into the VIEW after the service
                 had been fixed for it; the scoped re-review found it there.

                 ⚠ AND IT IS NOT FREE, which this comment used to claim. `R-165` measured
                 it: up to ten ListObjectsV2 + DeleteObjects round trips per call, inside
                 the tenant transaction, on a path that cost zero store calls before the
                 early return was removed -- ~6,000 LIST operations a minute at the
                 panel's 600/min per-tenant bucket, billed to the tenant. Idempotent, yes;
                 free, no, and the row that accepted the cost is the one to read. --}}
            <button type="button" wire:click="unpublish"
                    class="fi-btn fi-btn-size-md fi-color-danger px-16 py-8 rounded-lg">
                @if ($state['status'] === \App\Domain\Landing\LandingPageStatus::Published)
                    Unpublish
                @else
                    Take my site down
                @endif
            </button>
        </div>
    </form>
</x-filament-panels::page>
