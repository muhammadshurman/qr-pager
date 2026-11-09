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

    ================================================================================
    ⚠ NO `sandbox` ATTRIBUTE, AND THAT IS MEASURED — condition 3 of the 2026-11-09 ruling
    ================================================================================

    The ruling asked for the MINIMUM token set that still renders the draft's images and
    inline styles, measured in the booted panel, and said to ship without `sandbox` rather
    than guess if no set preserves the render. Driven in the signed-in studio against the
    real `/studio/preview`:

      sandbox absent                          frame reachable, title and styles present
      sandbox=""                              NOT reachable -- the frame has an OPAQUE ORIGIN
      sandbox="allow-same-origin"             reachable, title and styles present
      sandbox="allow-same-origin allow-scripts"  reachable, title and styles present

    The bare attribute is the only one that protects anything, and it is the one that takes
    the frame's origin away. That matters because the preview's OWN policy is
    `img-src 'self'`: against an opaque origin `'self'` matches nothing, so the tenant's
    images cannot load. Putting `allow-same-origin` back hands the real origin over again
    and buys nothing the response's `default-src 'none'` does not already give -- no script
    of any origin, no frame, no fetch -- so the remaining tokens are protection against
    things the child cannot do anyway.

    So: NO token set both preserves the render and adds protection, and the attribute is
    omitted. The child executes no script either way, which is the property that matters.

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
