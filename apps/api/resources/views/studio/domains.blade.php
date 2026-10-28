{{--
    §12.3 — /studio/domains, custom domains and TLS.

    All six states, each with the ticket's own copy: none, pending DNS, DNS verified,
    certificate pending (slow), active, and failed.

    The TXT record is in a <code> block with its parts labelled, which is what "copyable"
    means for a keyboard user: selectable text with a name, rather than a copy button that
    is the only way to get at it.
--}}
<x-filament-panels::page>
    @php($domains = $this->domains())
    @php($statuses = \App\Domain\Landing\DomainStatus::class)

    <div class="fi-section p-4 space-y-3">
        <h2 class="text-base font-semibold">Your address</h2>

        @if ($domains === [])
            <p>
                Your page is live at <strong>{{ $this->platformFallback() }}</strong>.
                Add your own domain to use it instead.
            </p>
        @else
            <p>Your page is published to <strong>{{ $this->liveHostname() }}</strong>.</p>
            <p class="text-sm">The platform always keeps
                <strong>{{ $this->platformFallback() }}</strong> for you.</p>
        @endif
    </div>

    <form wire:submit="add" class="fi-section p-4 space-y-3">
        <h2 class="text-base font-semibold">Add a domain</h2>

        <label for="hostname" class="block text-sm font-medium">Hostname</label>

        <input id="hostname" type="text" wire:model="hostname" inputmode="url"
               autocomplete="off" spellcheck="false" placeholder="olive.example.com"
               aria-describedby="hostname-help"
               class="block w-full rounded-lg border px-3 py-2">

        <p id="hostname-help" class="text-sm">
            The hostname only — no https:// and no trailing slash.
        </p>

        <button type="submit" class="fi-btn fi-btn-size-md fi-color-primary px-4 py-2 rounded-lg">
            Add domain
        </button>
    </form>

    @foreach ($domains as $domain)
        <div class="fi-section p-4 space-y-3">
            <h2 class="text-base font-semibold">{{ $domain->hostname }}</h2>

            {{-- The state is TEXT, never a colour alone (§12's accessibility bar). --}}
            <div role="status" aria-live="polite" class="space-y-2">
                @switch($domain->status)
                    @case($statuses::PendingDns)
                        <p><strong>Waiting for DNS.</strong>
                            Add this TXT record at your DNS provider, then choose Verify.</p>

                        @php($challenge = $this->challenge($domain->id))

                        <dl class="text-sm space-y-1">
                            <div><dt class="inline font-medium">Name:</dt>
                                <dd class="inline"><code>{{ $challenge['name'] }}</code></dd></div>
                            <div><dt class="inline font-medium">Type:</dt>
                                <dd class="inline"><code>{{ $challenge['type'] }}</code></dd></div>
                            <div><dt class="inline font-medium">Value:</dt>
                                <dd class="inline"><code>{{ $challenge['value'] }}</code></dd></div>
                        </dl>

                        @if ($domain->lastError !== null)
                            <p class="text-danger-700 dark:text-danger-400">
                                We could not verify {{ $domain->hostname }}: {{ $domain->lastError }}
                            </p>
                        @endif
                        @break

                    @case($statuses::DnsVerified)
                        <p><strong>DNS verified.</strong>
                            We are issuing a certificate — this usually takes a few minutes.</p>
                        @break

                    @case($statuses::CertificatePending)
                        <p><strong>Still issuing</strong>,
                            {{ $this->pendingMinutes($domain->checkedAt) }} minutes so far.
                            Nothing to do yet.</p>
                        @break

                    @case($statuses::Active)
                        <p><strong>Live at {{ $domain->hostname }}.</strong></p>

                        @foreach ($domains as $other)
                            @if ($other->redirectsToDomainId === $domain->id)
                                <p>{{ $other->hostname }} redirects here.</p>
                            @endif
                        @endforeach
                        @break

                    @case($statuses::Redirecting)
                        <p>This domain redirects to your live address.</p>
                        @break

                    @case($statuses::Failed)
                        <p class="text-danger-700 dark:text-danger-400">
                            We could not verify {{ $domain->hostname }}:
                            {{ $domain->lastError ?? 'the challenge was not found.' }}
                        </p>
                        @break
                @endswitch
            </div>

            @if (in_array($domain->status, [$statuses::PendingDns, $statuses::DnsVerified, $statuses::CertificatePending, $statuses::Failed], true))
                <button type="button" wire:click="advance('{{ $domain->id }}')"
                        class="fi-btn fi-btn-size-md fi-color-primary px-4 py-2 rounded-lg">
                    @if ($domain->status === $statuses::CertificatePending)
                        Check again<span class="sr-only"> for {{ $domain->hostname }}</span>
                    @else
                        Verify<span class="sr-only"> {{ $domain->hostname }}</span>
                    @endif
                </button>
            @endif
        </div>
    @endforeach
</x-filament-panels::page>
