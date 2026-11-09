<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Where the OTHER half of this product lives
|--------------------------------------------------------------------------
|
| `apps/api` serves two browser surfaces of its own -- the `/studio` and
| `/platform` Filament panels -- and authenticates nobody. Both panels
| deliberately have no `->login()`: `§4 › Tenancy` allows two authentication
| surfaces and never a third, so the credential form lives once, in `apps/web`
| at `/staff`, behind Task 5's 2FA challenge. Each panel provider's docblock
| says so at length.
|
| THE CONSEQUENCE, AND IT IS WHY THIS FILE EXISTS. A signed-out person who
| opens `/studio` meets `Filament\Http\Middleware\Authenticate`, which has no
| login URL to send them to, so the request ends as an `AuthenticationException`
| and `ProblemRenderer` turns it into a 401 -- measured on 2026-11-09 as a
| 1,990-byte HTML page titled "Authentication is required." with NO WAY IN on
| it. That is `R-253`. The panel cannot grow a form, so it has to know the
| address of the one that exists.
|
| ⚠ IT IS AN ABSOLUTE URL AND NOT A PATH, BECAUSE THE TWO SURFACES ARE NOT ONE
| ORIGIN YET. `apps/web` proxies `/v1/*` to this app only when
| `QRPAGER_HARNESS_API_ORIGIN` is set (a harness affordance, `next.config.mjs`),
| and `§4 › Edge` leaves the real join -- CloudFront, one hostname -- to Task 13.
| Until that exists, `/staff` on THIS origin is a 404, and a redirect to a 404 is
| worse than the dead-end page it replaced. So the target is configured, the
| shipped value is the local web dev server, and `BootChecks` refuses to serve
| outside local/testing unless it is an absolute https URL with a host. When
| one origin does arrive, this becomes `https://that-host/staff` and nothing
| else changes.
|
*/

return [

    /*
     * The sign-in screen a signed-out panel visitor is sent to.
     *
     * The shipped default is `pnpm --filter web dev`'s own address, which is where
     * `/staff` answers 200 on a developer's machine. It carries no `?next=`: the staff
     * screen has nowhere to put one today, and a parameter nothing reads is a promise
     * to come back that is not kept.
     */
    'staff_signin_url' => env('STAFF_SIGNIN_URL', 'http://localhost:3000/staff'),

];
