{{--
    The four studio tabs — QRPAGER-40.

    ⚠ LINKS WITH `aria-current`, NOT `role="tab"`. Each tab is a separate Filament page
    with its own Livewire state and its own URL, so these are NAVIGATION. `role="tab"`
    promises a screen-reader user that the panels are in this document and that arrow
    keys move between them; neither is true here, and a promise the page cannot keep is
    worse than the plainer control. `aria-current="page"` is what navigation says.

    The labels are in Arabic because the studio's reader is the restaurant owner, and
    `dir` comes from the panel. The active tab carries a 3px brand bottom border and 700
    weight; the rest are `ink-mid` at 500 -- the ticket's own values, and both are set in
    the studio theme's layout rules, never here.
--}}
<nav class="studio-tabs" aria-label="أقسام الاستوديو">
    @foreach ([
        ['page' => \App\Features\Landing\Panel\Pages\LandingBuilderPage::class, 'label' => 'الصفحة'],
        ['page' => \App\Features\Landing\Panel\Pages\MediaLibraryPage::class, 'label' => 'الوسائط'],
        ['page' => \App\Features\Landing\Panel\Pages\ThemePage::class, 'label' => 'الثيم'],
        ['page' => \App\Features\Landing\Panel\Pages\DomainsPage::class, 'label' => 'النطاق'],
    ] as $tab)
        @php($isCurrent = static::class === $tab['page'])

        {{-- ⚠ `panel: 'studio'` IS NOT OPTIONAL. `getUrl()` with no panel resolves against
             the CURRENT one, and in a Livewire update that is whichever panel the request
             established -- measured as `Route [filament.platform.pages.media] not defined`
             when `StudioActionsTest` drove this page. The tab bar belongs to one panel, so
             it names it. --}}
        <a href="{{ $tab['page']::getUrl(panel: 'studio') }}"
           class="studio-tab"
           @if ($isCurrent) aria-current="page" @endif>
            {{ $tab['label'] }}
        </a>
    @endforeach
</nav>
