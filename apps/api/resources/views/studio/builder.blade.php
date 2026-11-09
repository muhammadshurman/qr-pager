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
    @include('studio.partials.tabs')

    <div class="studio-layout">
        <div class="studio-layout__form">
        @php($state = $this->state())

        <div role="status" aria-live="polite" class="fi-section p-16 space-y-8">
            <h2 class="text-base font-semibold">{{ __('studio.page.status_heading') }}</h2>

            @if ($state['invalid'] !== null)
                {{-- INVALID. The refusing field is named inline and Publish is disabled. --}}
                <p class="text-danger-700 dark:text-danger-200">
                    <strong>{{ __('studio.page.not_publishable') }}</strong> {{ $state['invalid'] }}
                </p>
            @elseif ($state['blocks'] === 0)
                <p>{{ __('studio.page.empty') }}</p>
            @elseif ($state['status'] === \App\Domain\Landing\LandingPageStatus::Draft)
                <p>{{ __('studio.page.draft') }}</p>
            @elseif ($state['has_unpublished_changes'])
                <p>{{ __('studio.page.unpublished_changes') }}</p>
                <p>{{ __('studio.page.published_at', ['hostname' => $state['published_hostname'], 'date' => $state['published_at']]) }}</p>
            @else
                <p>{{ __('studio.page.published_at', ['hostname' => $state['published_hostname'], 'date' => $state['published_at']]) }}</p>
            @endif

            {{-- The emphasis sits INSIDE a translated sentence, so the partial splits the
                 sentence on its own placeholder and echoes every part with `{{ }}`. Its
                 docblock carries the reason there is no raw echo here. --}}
            <p class="text-sm">
                @include('studio.partials.emphasised', [
                    'line' => __('studio.page.will_publish_to'),
                    'placeholder' => ':hostname',
                    'value' => $state['hostname'],
                ])
            </p>
        </div>

        {{--
            ================================================================================
            THE PAGE LANGUAGE (QRPAGER-30). A fieldset of real buttons, one per enum case.
            ================================================================================

            OUTSIDE the <form> below, deliberately: that form's state is the landing DOCUMENT and
            `StudioForm::toDocument()` turns all of it into blocks/theme/seo. The language is a
            column on `tenants`, so a control inside that form would either have to be stripped
            before the document is built or would ride into it as a member nothing validates.

            A <fieldset> with a <legend>, and each option a real <button> -- §12's own rule for
            this panel: "every control is a real <button> or <a>, every state is announced in
            TEXT and never by colour alone". `aria-pressed` is what a screen reader reads to know
            which one is active; the "Current" word beside it is what a sighted reader with no
            colour perception reads. Neither is the only signal.

            THE LABELS ARE IN THEIR OWN LANGUAGE, not translated into the panel's. A restaurant
            choosing Arabic looks for "العربية", and a language picker that renders every option
            in one language is the one control where translation makes the choice harder. The `lang`
            attribute on each label is what stops a screen reader pronouncing Arabic as English --
            and `dir` beside it, because the two are halves of one fact.
        --}}
        <fieldset class="fi-section p-16 space-y-8">
            <legend class="text-base font-semibold">{{ __('studio.page.language_legend') }}</legend>

            <p class="text-sm">{{ __('studio.page.language_help') }}</p>

            <div class="flex flex-wrap gap-12">
                @foreach ($this->pageLanguages() as $option)
                    @php($isCurrent = $option === $this->pageLanguage())

                    <button type="button"
                            wire:click="setPageLanguage('{{ $option->value }}')"
                            aria-pressed="{{ $isCurrent ? 'true' : 'false' }}"
                            class="fi-btn fi-btn-size-md px-16 py-8 rounded-lg underline">
                        <span lang="{{ $option->value }}" dir="{{ $option->direction() }}">
                            {{ $option === \App\Domain\Tenancy\TenantLanguage::Arabic ? 'العربية' : 'English' }}
                        </span>

                        @if ($isCurrent)
                            <span class="text-sm">{{ __('studio.page.language_current') }}</span>
                        @endif
                    </button>
                @endforeach
            </div>
        </fieldset>

        <form wire:submit="save" class="space-y-24">
            {{ $this->form }}

            {{-- ⚠ A FOOTER BAR — QRPAGER-40 item 6. A hairline top on `surface`, the save
                 state on one side and the actions on the other, which is `rail.html`'s own
                 footer rhythm. `افتح المعاينة` and `انشر` are the ticket's words; the other
                 two keep theirs, because renaming an action the capability gate names is a
                 change to what the owner can DO rather than to the layout. --}}
            <div class="studio-footer">
                <p class="studio-footer__state">
                    @if ($state['invalid'] !== null)
                        {{ __('studio.footer.not_publishable') }}
                    @elseif ($state['blocks'] === 0)
                        {{ __('studio.footer.no_blocks') }}
                    @elseif ($state['has_unpublished_changes'])
                        {{ __('studio.footer.unpublished_changes') }}
                    @else
                        {{ __('studio.footer.saved') }}
                    @endif
                </p>

                <div class="studio-footer__actions">
                <button type="submit" class="fi-btn fi-btn-size-md fi-color-primary px-16 py-8 rounded-lg">
                    {{ __('studio.page.save') }}
                </button>

                <a href="{{ route('filament.studio.preview') }}" target="_blank" rel="noopener"
                   class="fi-btn fi-btn-size-md px-16 py-8 rounded-lg underline">
                    {{ __('studio.preview.open') }} <span class="sr-only">{{ __('studio.preview.opens_in_new_tab') }}</span>
                </a>

                {{-- Disabled when there is nothing publishable, which is §12.1's empty and
                     invalid states. `disabled` and not merely a style, so the control is
                     genuinely unavailable to a keyboard and to a screen reader. --}}
                <button type="button" wire:click="publish"
                        @disabled($state['blocks'] === 0 || $state['invalid'] !== null)
                        class="fi-btn fi-btn-size-md fi-color-success px-16 py-8 rounded-lg disabled:opacity-50">
                    {{ __('studio.page.publish') }}
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
                        {{ __('studio.page.unpublish') }}
                    @else
                        {{ __('studio.page.take_down') }}
                    @endif
                </button>
                </div>
            </div>
        </form>
        </div>

        @include('studio.partials.preview')
    </div>
</x-filament-panels::page>
