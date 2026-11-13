{{--
    Which language the SIGNED-IN PERSON reads the studio in. `QRPAGER-48` item 2.

    ⚠⚠ THE LEGEND NAMES ITS AUDIENCE, AND THAT IS AN ACCEPTANCE CONDITION RATHER THAN POLISH.
    Muhammad, 2026-11-13: *"Label them so an owner cannot confuse them. The topbar one is about
    what THEY read; the page one is about what their CUSTOMERS read. If the wording does not
    make that obvious at a glance, the control is not done."* The page tab's control carries
    the mirror sentence.

    ⚠ A FORM AND A REAL SUBMIT PER CHOICE, not a link: this CHANGES something, so it is a POST
    carrying the session's CSRF token. A GET that mutated a preference would be a state change
    any page could trigger by embedding an image.
--}}
<form method="POST" action="{{ route('filament.studio.panel-language') }}" class="studio-lang" data-panel-language="true">
    @csrf

    {{--
        ⚠⚠ WHERE TO COME BACK TO, CARRIED EXPLICITLY — AND IT WAS `url()->previous()`.

        Muhammad's walkthrough of PR #22: *"clicking AR/EN opens the restaurant's logo
        chooser."* It does not. It navigates the owner FULL-PAGE ONTO A RAW JPEG, which is
        what an image filling the window looks like. Measured: the POST is correct and the
        302 answers `Location: /studio/preview/asset/01a1d2b2-…`.

        The chain: this form sends NO `Referer` (measured — the header is absent), so
        `url()->previous()` falls through to the session's previous URL; Laravel records that
        from the last non-AJAX GET; and the theme tab's PREVIEW IFRAME fetches
        `/studio/preview/asset/{id}` for every image on the page. So "the page they were on"
        was, in fact, the last picture the preview happened to load.

        ⚠ IT IS A ROUTE NAME, NOT A URL, and it is validated against the panel's own page
        routes on the other side. A path would be tenant input that has to be parsed and
        host-checked; a name either is one of this panel's pages or it is not, which is a
        closed question with a closed answer. `/studio/preview` and `/studio/panel-language`
        are not page routes, so neither can be returned to by construction rather than by a
        prefix test that cannot tell a page from a picture.
    --}}
    <input type="hidden" name="from" value="{{ request()->route()?->getName() }}">
    <span class="studio-lang__legend" data-panel-language-legend="true">
        {{ __('studio.chrome.panel_language_legend') }}
    </span>

    @foreach (['ar' => 'ع', 'en' => 'EN'] as $value => $glyph)
        <button
            type="submit"
            name="language"
            value="{{ $value }}"
            class="studio-lang__choice"
            @if (app()->getLocale() === $value) aria-current="true" data-panel-language-current="{{ $value }}" @endif
            {{-- The glyph alone is not a label: a screen reader gets the whole sentence. --}}
            aria-label="{{ __('studio.chrome.panel_language_switch', ['language' => $value === 'ar' ? 'العربية' : 'English']) }}"
        >{{ $glyph }}</button>
    @endforeach
</form>
