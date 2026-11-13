{{--
    The topbar's left half: the mark, the restaurant, and what this screen is.
    `QRPAGER-48` item 2, translated from `docs/design/studio/theme-tab.html`.

    ⚠ EVERY VALUE IS A TOKEN. The artboard's own header says so — *"Do not copy a literal from
    this file. Every colour, size, radius and duration here stands for a token: translate it,
    never paste it."* The mark is drawn from `currentColor` rather than a fill, so it inherits
    the topbar's ink and cannot drift from it.

    ⚠ THE RESTAURANT'S NAME IS THE POINT OF THIS REGION. `§12`'s zero-technical-background bar
    forbids an unexplained identifier, and before this the topbar carried the product's name
    and nothing about WHOSE page was being edited — on a shared device that is the one fact a
    person glances up to confirm.
--}}
<div class="studio-topbar__identity">
    <span class="studio-topbar__mark" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="22" height="22" focusable="false">
            <rect x="0" y="0" width="24" height="24" rx="2" fill="currentColor" />
            <rect x="5" y="5" width="6" height="6" rx="1" fill="var(--studio-topbar-mark-ink)" />
            <rect x="13" y="5" width="6" height="6" rx="1" fill="var(--studio-topbar-mark-ink)" />
            <rect x="5" y="13" width="6" height="6" rx="1" fill="var(--studio-topbar-mark-ink)" />
        </svg>
    </span>

    @if ($restaurant !== '')
        <span class="studio-topbar__rule" aria-hidden="true"></span>
        <span class="studio-topbar__restaurant" data-topbar-restaurant="true">{{ $restaurant }}</span>
    @endif

    {{-- What this screen is, so the restaurant's name is not mistaken for the product's. --}}
    <span class="studio-topbar__chip" data-topbar-chip="true">{{ __('studio.chrome.studio') }}</span>
</div>
