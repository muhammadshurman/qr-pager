{{--
    The live preview pane — QRPAGER-40. Pinned beside the form on every tab.

    ================================================================================
    ⚠ IT FRAMES `/studio/preview`, WHICH IS THE PUBLISH PATH'S OWN RENDERER
    ================================================================================

    `PreviewController` -> `LandingRenderer`, the same renderer `LandingPages::publish()`
    uses. A SECOND RENDERER IS A REJECTED DESIGN (`ADR-005`), so this frames the one that
    exists rather than drawing its own approximation of the page.

    It is frameable at all only because of Muhammad's ruling of 2026-11-09: that response
    carried `frame-ancestors 'none'` and `X-Frame-Options: DENY`, narrowed to `'self'` and
    `SAMEORIGIN` for exactly this pane. Every cross-origin framer is still refused.

    ⚠ NO `sandbox` ATTRIBUTE, AND THAT IS MEASURED RATHER THAN SKIPPED -- see the ticket
    report and `§4a`. A `sandbox` without `allow-same-origin` gives the child an OPAQUE
    origin, and the preview's own policy is `img-src 'self'`: against an opaque origin
    `'self'` matches nothing, so every image in the tenant's page fails to load. Adding
    `allow-same-origin` back hands the frame its real origin again and buys nothing the
    response's own `default-src 'none'` does not already give. The child executes no
    script either way, which is the property that matters.

    The two toggles are plain links with `aria-pressed`, so the pane needs no JavaScript
    of its own: the width is a query parameter this page reads back.
--}}
@php($previewWidth = request()->query('preview') === 'phone' ? 'phone' : 'desktop')
@php($previewLang = request()->query('lang') === 'en' ? 'en' : 'ar')

<aside class="studio-pane" aria-label="معاينة الصفحة">
    <div class="studio-pane__bar">
        @foreach (['desktop' => 'سطح المكتب', 'phone' => 'جوال'] as $value => $label)
            <a href="{{ request()->fullUrlWithQuery(['preview' => $value]) }}"
               class="fi-btn fi-btn-size-md px-16 py-8 rounded-lg underline"
               aria-pressed="{{ $previewWidth === $value ? 'true' : 'false' }}">{{ $label }}</a>
        @endforeach

        @foreach (['ar' => 'العربية', 'en' => 'English'] as $value => $label)
            <a href="{{ request()->fullUrlWithQuery(['lang' => $value]) }}"
               class="fi-btn fi-btn-size-md px-16 py-8 rounded-lg underline"
               aria-pressed="{{ $previewLang === $value ? 'true' : 'false' }}">
                <span lang="{{ $value }}" dir="{{ $value === 'ar' ? 'rtl' : 'ltr' }}">{{ $label }}</span>
            </a>
        @endforeach
    </div>

    <iframe class="studio-pane__frame"
            data-width="{{ $previewWidth }}"
            data-studio-preview="true"
            src="{{ route('filament.studio.preview') }}"
            title="معاينة الصفحة كما ستُنشر"
            loading="lazy"></iframe>
</aside>
