{{--
    CLASSIC — the builder's own order, one column. `QRPAGER-33`.

    ⚠ THIS IS NOT A NEW ARRANGEMENT. It is what `landing.page` rendered before this feature
    existed, lifted out of that file unchanged and given a name: an unnamed `@foreach` over
    the blocks in stored order. `LandingTemplate::DEFAULT` is this case, so a document with
    no `template` member — which is every document stored before 2026-11-03 — gets it.

    ⚠ AND THE CLAIM IS ASSERTED BYTE FOR BYTE, not argued.
    `tests/Fixtures/landing/pre-template-page.html` was captured BEFORE the first change of
    this feature (4,625 bytes, sha256 fe5e5995…) and `TemplateArrangementTest` requires this
    arrangement to reproduce it exactly. That is `QRPAGER-33`'s invariant clause 2: a tenant
    who published yesterday and republishes today gets the same page, and the proof cannot be
    manufactured after the fact, which is why the capture came first.

    THE SAFE DEFAULT, and chosen for that reason rather than because it is the plainest: it
    renders exactly what the tenant arranged, so a block order that makes sense in the builder
    makes the same sense on the page.
--}}
@foreach ($blocks as $block)
@include('landing.block')
@endforeach
