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
