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
 * SERVED IN `local` AND `testing` AND NOWHERE ELSE, and the condition is in bootstrap/app.php
 * beside the drain proof's -- the only two conditional route loads in the project, both
 * visible in one place.
 *
 * ⚠ IT READ `! environment('production')` UNTIL 2026-11-03, WHICH IS NOT THE SAME CLAIM, AND
 * THE DIFFERENCE WAS A SECOND CREDENTIAL SURFACE ON EVERY OTHER ENVIRONMENT NAME. Driven as
 * real processes before the change: `local` 200, `testing` 200, **`staging` 200**,
 * `production` 404 -- so a staging host served this page, with its CSRF token, its email and
 * password fields and its posts to the real `/v1/auth/login`. `QRPAGER-31`. Every fixture
 * script in `apps/api/bin` already failed closed on `local`/`testing`; this route was the one
 * outlier, and it is the only one of them reachable without a shell.
 *
 * ⚠ AND THE TEST NO LONGER READS THE `if` AS A STRING, WHICH IS WHY THAT WAS MISSED. The
 * previous comment here recorded, accurately, that RouteSurfaceTest asserted the SOURCE of
 * the condition and that *"the absence was verified by hand, out of process, under
 * APP_ENV=production"*. The by-hand check is now the committed one:
 * `tests/Support/get-path-in-another-process.php` boots the application in a child whose
 * APP_ENV the test chooses and returns a real status, so the route table is rebuilt per
 * environment instead of being inferred from characters. The suite drives six names,
 * including four nobody enumerated anywhere in this application.
 *
 * ON THE `auth-surface` GROUP, because the page needs a session: it reads a CSRF token for
 * its fetches and the ceremony's challenge is stored server-side. Unversioned and outside
 * /v1 on purpose -- it is not API surface, it is a test fixture with a URL, and putting it
 * under /v1 would publish it into the versioned contract.
 */

Route::middleware('auth-surface')
    ->get('passkey-harness', fn () => view('passkey-harness'))
    ->name('harness.passkeys');
