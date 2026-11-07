<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The platform hostname every tenant gets for free
    |--------------------------------------------------------------------------
    |
    | A tenant's page is live at `{slug}.{platform_domain}` from its first publish,
    | before anyone has configured DNS -- which is §12.3's "none" state: "Your page is
    | live at {slug}.{platform}. Add your own domain to use it instead."
    |
    | It is config and not a constant because the value differs per environment and a
    | published page's canonical URL is built from it.
    |
    */

    'platform_domain' => env('LANDING_PLATFORM_DOMAIN', 'pages.qrpager.test'),

    /*
    |--------------------------------------------------------------------------
    | What the CDN is told about a published object
    |--------------------------------------------------------------------------
    |
    | §6.5: "A published page is a static object ... so the CDN serves every scan burst
    | and the origin sees nothing." `s-maxage` is a year because a publish writes a NEW
    | immutable object and switches a pointer (decision D8) -- nothing is ever edited in
    | place, so there is nothing for a shared cache to hold stale. `max-age` is 60 so a
    | browser that already has the page picks up a re-publish within a minute.
    |
    | THIS IS ASSERTED AS THE OBJECT'S STORED METADATA AND NOTHING MORE. Whether a CDN
    | honours it is Task 13's, and §4a says so rather than this task claiming it.
    |
    */

    'cache_control' => 'public, max-age=60, s-maxage=31536000, stale-while-revalidate=86400',

    /*
    |--------------------------------------------------------------------------
    | What a PREVIEW is told, which is the opposite
    |--------------------------------------------------------------------------
    |
    | Decision D2: the preview route serves `no-store` and `X-Robots-Tag: noindex`, so a
    | draft cannot be cached by anything or indexed by anyone. "An unpublished draft is
    | not reachable" is the first clause of this task's named invariant.
    |
    */

    'preview_cache_control' => 'no-store, no-cache, must-revalidate, max-age=0',

    /*
    |--------------------------------------------------------------------------
    | The two lifecycle drivers (decision D7, SR-2)
    |--------------------------------------------------------------------------
    |
    | `local` runs here: a zone file the developer can edit and a recorded issuance
    | with a delay. `system` resolves TXT records through the real resolver, which is
    | what runs wherever DNS resolves. `aws` is Task 13's and is deliberately ABSENT
    | rather than stubbed -- a binding that resolved to a class which does nothing
    | would be the dead control §4a records four of. Asking for it fails loudly and
    | names the task that owns it.
    |
    */

    'dns_driver' => env('LANDING_DNS_DRIVER', 'local'),

    'certificate_driver' => env('LANDING_CERTIFICATE_DRIVER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | How long the local issuer stays pending
    |--------------------------------------------------------------------------
    |
    | Zero by default so the suite is not slow. It exists because §12.3 has a "still
    | issuing, {minutes} minutes so far" state, and an issuer that answered instantly
    | would make that state unreachable and its copy untestable.
    |
    */

    'local_certificate_delay' => (int) env('LANDING_LOCAL_CERTIFICATE_DELAY', 0),

];
