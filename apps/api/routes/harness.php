<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * The WebAuthn harness page, and the ONE route in this project that returns HTML.
 *
 * PLAN.md §3 row 5's task column asks for "a minimal unstyled harness page ... purely to
 * exercise the WebAuthn browser API", because the credential ceremony lives in
 * navigator.credentials and cannot be driven from HTTP. The board's comment of 2026-10-25
 * added it to resolve a contradiction inside the ticket: the gate wanted passkeys proven end
 * to end while the out-of-scope table assigned login screens to Task 10.
 *
 * NOT SERVED IN PRODUCTION, and the condition is in bootstrap/app.php beside the drain
 * proof's -- the only two conditional route loads in the project, both visible in one place.
 * RouteSurfaceTest asserts the SOURCE of that condition in bootstrap/app.php, and says in
 * its own comment why it cannot assert the stronger thing: the route table is compiled once
 * per process, so a test cannot re-register routes under a different APP_ENV without
 * rebuilding the application. This comment used to claim the test "asserts this path is
 * absent from the route table under production", which it does not -- corrected at Task 5's
 * QA review. The absence was verified by hand, out of process, under APP_ENV=production.
 *
 * ON THE `auth-surface` GROUP, because the page needs a session: it reads a CSRF token for
 * its fetches and the ceremony's challenge is stored server-side. Unversioned and outside
 * /v1 on purpose -- it is not API surface, it is a test fixture with a URL, and putting it
 * under /v1 would publish it into the versioned contract.
 */

Route::middleware('auth-surface')
    ->get('passkey-harness', fn () => view('passkey-harness'))
    ->name('harness.passkeys');
