{{--
    §12.3 — /studio/domains, custom domains and TLS.

    All six states, each with the ticket's own copy: none, pending DNS, DNS verified,
    certificate pending (slow), active, and failed.

    The TXT record is in a <code> block with its parts labelled, which is what "copyable"
    means for a keyboard user: selectable text with a name, rather than a copy button that
    is the only way to get at it.
--}}
<x-filament-panels::page>
    @include('studio.partials.tabs')

    <div class="studio-layout">
        <div class="studio-layout__form">
        @php($domains = $this->domains())
        @php($statuses = \App\Domain\Landing\DomainStatus::class)

        <div class="fi-section p-16 space-y-12">
            <h2 class="text-base font-semibold">{{ __('studio.domains.address_heading') }}</h2>

            @if ($domains === [])
                {{-- Three sentences with the hostname emphasised inside them. The partial
                     splits each on its own placeholder; no raw echo, and the emphasis lands
                     where the translator put it rather than where English puts it. --}}
                <p>@include('studio.partials.emphasised', [
                    'line' => __('studio.domains.live_at_fallback'),
                    'placeholder' => ':hostname',
                    'value' => $this->platformFallback(),
                ])</p>
            @else
                <p>@include('studio.partials.emphasised', [
                    'line' => __('studio.domains.published_to'),
                    'placeholder' => ':hostname',
                    'value' => $this->liveHostname(),
                ])</p>
                <p class="text-sm">@include('studio.partials.emphasised', [
                    'line' => __('studio.domains.platform_keeps'),
                    'placeholder' => ':hostname',
                    'value' => $this->platformFallback(),
                ])</p>
            @endif
        </div>

        <form wire:submit="add" class="fi-section p-16 space-y-12">
            <h2 class="text-base font-semibold">{{ __('studio.domains.add_heading') }}</h2>

            <label for="hostname" class="block text-sm font-medium">{{ __('studio.domains.hostname_label') }}</label>

            <input id="hostname" type="text" wire:model="hostname" inputmode="url"
                   autocomplete="off" spellcheck="false" placeholder="olive.example.com"
                   aria-describedby="hostname-help"
                   class="block w-full rounded-lg border px-12 py-8">

            <p id="hostname-help" class="text-sm">
                {{ __('studio.domains.hostname_help') }}
            </p>

            <button type="submit" class="fi-btn fi-btn-size-md fi-color-primary px-16 py-8 rounded-lg">
                {{ __('studio.domains.add_button') }}
            </button>
        </form>

        @foreach ($domains as $domain)
            <div class="fi-section p-16 space-y-12">
                <h2 class="text-base font-semibold">{{ $domain->hostname }}</h2>

                {{-- The state is TEXT, never a colour alone (§12's accessibility bar). --}}
                <div role="status" aria-live="polite" class="space-y-8">
                    @switch($domain->status)
                        @case($statuses::PendingDns)
                            <p><strong>{{ __('studio.domains.waiting_dns') }}</strong>
                                {{ __('studio.domains.waiting_dns_body') }}</p>

                            @php($challenge = $this->challenge($domain->id))

                            {{-- R-154: a MISSING record is said, never rendered as a blank one.
                                 challenge() used to return name '' / type TXT / value '' on both
                                 its non-success paths, and this list printed that -- a tenant was
                                 shown an empty row and told to copy it into their DNS. The
                                 sentence is the same for every reason the record is unavailable,
                                 which keeps §4.2's rule: say what to do, never what exists. --}}
                            @if ($challenge === null)
                                <p>{{ __('studio.domains.challenge_unavailable', ['hostname' => $domain->hostname]) }}</p>
                            @else
                                <dl class="text-sm space-y-4">
                                    <div><dt class="inline font-medium">{{ __('studio.domains.record_name') }}</dt>
                                        <dd class="inline"><code>{{ $challenge['name'] }}</code></dd></div>
                                    <div><dt class="inline font-medium">{{ __('studio.domains.record_type') }}</dt>
                                        <dd class="inline"><code>{{ $challenge['type'] }}</code></dd></div>
                                    <div><dt class="inline font-medium">{{ __('studio.domains.record_value') }}</dt>
                                        <dd class="inline"><code>{{ $challenge['value'] }}</code></dd></div>
                                </dl>
                            @endif

                            @if ($domain->lastError !== null)
                                <p class="text-danger-700 dark:text-danger-200">
                                    {{ __('studio.domains.could_not_verify', [
                                        'hostname' => $domain->hostname,
                                        'reason' => $domain->lastError,
                                    ]) }}
                                </p>
                            @endif
                            @break

                        @case($statuses::DnsVerified)
                            <p><strong>{{ __('studio.domains.dns_verified') }}</strong>
                                {{ __('studio.domains.issuing_certificate') }}</p>
                            @break

                        @case($statuses::CertificatePending)
                            <p><strong>{{ __('studio.domains.still_issuing') }}</strong>
                                {{ __('studio.domains.still_issuing_body', [
                                    'minutes' => $this->pendingMinutes($domain->checkedAt),
                                ]) }}</p>
                            @break

                        @case($statuses::Active)
                            <p><strong>{{ __('studio.domains.live_at', ['hostname' => $domain->hostname]) }}</strong></p>

                            @foreach ($domains as $other)
                                @if ($other->redirectsToDomainId === $domain->id)
                                    <p>{{ __('studio.domains.redirects_here', ['hostname' => $other->hostname]) }}</p>
                                @endif
                            @endforeach
                            @break

                        @case($statuses::Redirecting)
                            <p>{{ __('studio.domains.redirects_to_live') }}</p>
                            @break

                        @case($statuses::Failed)
                            <p class="text-danger-700 dark:text-danger-200">
                                {{ __('studio.domains.could_not_verify', [
                                    'hostname' => $domain->hostname,
                                    'reason' => $domain->lastError ?? __('studio.domains.challenge_not_found'),
                                ]) }}
                            </p>
                            @break
                    @endswitch
                </div>

                @if (in_array($domain->status, [$statuses::PendingDns, $statuses::DnsVerified, $statuses::CertificatePending, $statuses::Failed], true))
                    <button type="button" wire:click="advance('{{ $domain->id }}')"
                            class="fi-btn fi-btn-size-md fi-color-primary px-16 py-8 rounded-lg">
                        @if ($domain->status === $statuses::CertificatePending)
                            {{ __('studio.domains.check_again') }}<span class="sr-only">{{ __('studio.domains.check_again_for', ['hostname' => $domain->hostname]) }}</span>
                        @else
                            {{ __('studio.domains.verify') }}<span class="sr-only">{{ __('studio.domains.verify_for', ['hostname' => $domain->hostname]) }}</span>
                        @endif
                    </button>
                @endif
            </div>
        @endforeach
        </div>

        @include('studio.partials.preview')
    </div>
</x-filament-panels::page>
