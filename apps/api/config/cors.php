<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CORS — CLOSED, BY DECISION RATHER THAN BY THE ABSENCE OF THIS FILE
|--------------------------------------------------------------------------
|
| ⚠ WHY THIS FILE EXISTS AT ALL, GIVEN THAT IT TURNS NOTHING ON. Until Task 12 there was no
| `config/cors.php` in this repository, so `cors.paths` resolved to an empty array and
| Laravel's `HandleCors` matched nothing. The security review of `task-2` measured exactly
| that and wrote it down (`R-038`): *"CORS is inert and safe today — `config/cors.php` is
| absent, so `cors.paths` is empty and `HandleCors` never matches"*.
|
| Inert and safe is the right posture. **Inert by accident is not a posture at all.** A
| missing file says nothing to the next person, is indistinguishable from an oversight, and is
| one `php artisan vendor:publish --tag=cors` away from becoming Laravel's shipped default —
| which is `'paths' => ['api/*', 'sanctum/csrf-cookie']`, `'allowed_methods' => ['*']` and
| `'allowed_origins' => ['*']`. That default on this surface would open every `/v1` route to
| every origin on the internet. This file is the difference between a control and a vacancy,
| and `CorsPolicyTest` is what makes it one.
|
|--------------------------------------------------------------------------
| WHY CLOSED IS CORRECT, AND IT IS THE ARCHITECTURE'S OWN WORDS
|--------------------------------------------------------------------------
|
| Nothing is meant to call this API cross-origin. `apps/web/next.config.mjs` states the design
| and the reason, and it is a deployment decision rather than a preference:
|
|   *"ONE origin over HTTPS. iOS will not install or subscribe over plain http, and a page on
|   https cannot fetch http — so the Laravel API has to be reachable over the same https
|   origin as the page … Routing `/v1/*` through Next makes page and API same-origin, which
|   also means NO CORS CONFIGURATION IS ADDED TO `apps/api`."*
|
| So the customer PWA, the staff dashboard, the KDS and the landing renderer all reach `/v1`
| as same-origin requests — through Next in the harness, and through CloudFront in the real
| deployment, which is **Task 13**'s (`§4 › Edge`). A browser sends no `Origin` it needs
| answering and makes no preflight.
|
| ⚠ AND THE COST OF BEING WRONG IS NOT SYMMETRIC, which is why the empty list is kept rather
| than a hopeful allowlist of today's dev hosts. `/v1` is a COOKIE-authenticated surface
| (`auth-surface` runs `StartSession` and `ValidateCsrfToken`), so an
| `Access-Control-Allow-Origin` with `Access-Control-Allow-Credentials` is a cross-site read
| of tenant data with the victim's own session. Being too closed breaks a call in an obvious,
| immediate, local way; being too open is silent. If a genuine cross-origin consumer ever
| arrives, it arrives as a diff on this file with an origin named in it — which is the same
| mechanism `RouteSurfaceTest`'s allowlist is, and for the same reason.
|
| ⚠ `allowed_origins` IS `[]` AND NOT `['*']`, and the two are NOT equivalent even with an
| empty `paths`. `paths` is what decides whether the middleware looks at a request at all, so
| today it is the only thing load-bearing — but a future diff that adds a path and forgets the
| origins would inherit whatever is written here. `[]` makes that diff fail closed and
| visibly; `['*']` would make it open the surface in the same edit that was meant to narrow
| it. Every list below is empty for that reason: the file's defaults are what a careless
| future change lands on.
|
*/

return [

    /*
     * The paths `HandleCors` considers. EMPTY: no path on this origin answers a CORS
     * preflight or carries an `Access-Control-*` header.
     *
     * `CorsPolicyTest` drives a real `OPTIONS` preflight and a real cross-origin `GET` at
     * `/v1/members`, `/v1/auth/me` and `/healthz` and asserts no `Access-Control-Allow-Origin`
     * comes back — because an empty array here is a configuration, and a configuration is not
     * a captured response (`§8`).
     */
    'paths' => [],

    'allowed_methods' => [],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [],

    'exposed_headers' => [],

    'max_age' => 0,

    /*
     * ⚠ FALSE, AND THIS IS THE ONE VALUE IT WOULD BE MOST EXPENSIVE TO GET WRONG. With
     * credentials allowed, a permitted origin can read a response made with the victim's
     * session cookie — which on this surface is every tenant row that session can see. It is
     * false here so that any future diff that opens `paths` has to change THIS line too, in
     * the same review, to create that exposure.
     */
    'supports_credentials' => false,

];
