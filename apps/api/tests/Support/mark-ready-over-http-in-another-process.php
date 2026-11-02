<?php

declare(strict_types=1);

use App\Platform\Auth\ActiveTenant;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * MARK ONE PAGER SESSION READY **OVER HTTP**, AS A SIGNED-IN STAFF MEMBER, IN A
 * PROCESS OF ITS OWN.
 * ================================================================================
 *
 * `QRPAGER-26 §0`'s named invariant: *"Two staff marking the same session ready at the same
 * instant produce exactly ONE customer notification and ONE terminal state, and both boards
 * converge on it within one poll interval. Asserted by driving two concurrent clients
 * against the real API."*
 *
 * ⚠ **WHY THIS FILE EXISTS WHEN `mark-ready-in-another-process.php` ALREADY DOES, because
 * the difference IS the invariant.** That child calls `PagerSessions::markReady()` — the
 * SERVICE — and proves Task 3's idempotency at the service boundary, which is Task 3's gate
 * clause and is already green. This ticket's clause is explicitly *not* that: the ticket
 * says *"never by trusting Task 3's idempotency from the client side"*, so the thing under
 * test here is the whole request path two real clients take —
 *
 *     ShedLoad → RateLimit → EncryptCookies → StartSession → ValidateCsrfToken
 *              → ResolvePrincipal → RequireTenantScope → RequireAbility
 *              → PagerSessionController::ready → PagerSessions::markReady
 *
 * — because every one of those is a place where two concurrent callers could be handled
 * differently from two sequential ones, and a service-level proof says nothing about any of
 * them.
 *
 * ================================================================================
 * HOW IT AUTHENTICATES, AND WHY A COOKIE RATHER THAN A TOKEN
 * ================================================================================
 *
 * **A bearer token cannot be staff.** `RequestPrincipal::for()`'s first clause is *"a bearer
 * token is a `Device`, whatever its holder is"* — the credential SHAPE decides, so a token
 * holds `pager:read` and nothing else and could not mark anything ready. The staff
 * credential is the first-party session cookie, so that is what this child presents.
 *
 * It builds its OWN session rather than being handed one by the parent, which is what makes
 * the two children two CLIENTS rather than one client sending two requests:
 *
 *   1. a Redis-backed session (`SESSION_DRIVER=redis` from the parent's env — the suite's
 *      own default is `array`, which no second process can read);
 *   2. the `web` guard's own login key, read from `Auth::guard('web')->getName()` rather
 *      than spelled out, so a framework change to that name cannot leave this file
 *      authenticating nobody while still looking correct;
 *   3. `ActiveTenant::SESSION_KEY`, because `AuthenticatedTenantResolver` reads the
 *      selection from the server-side session and `Memberships::sole()` is only a fallback;
 *   4. the session id wrapped exactly as `EncryptCookies` expects it back —
 *      `encrypt(CookieValuePrefix::create($name, $key).$id, false)`. The `false` is not
 *      optional: that middleware decrypts with `serialize: false`, so an encrypted-and-
 *      serialised value fails to decrypt and the request arrives with no session at all.
 *
 * If any of that is wrong the request answers **401** and the parent's assertion fails
 * loudly, which is the right failure mode: there is no path by which a broken login here
 * looks like a successful concurrency result.
 *
 * ⚠ **CSRF IS SATISFIED BY `APP_ENV=testing`, AND THAT IS STATED RATHER THAN HIDDEN.**
 * `ValidateCsrfToken` returns early when the application is running tests, so this child
 * does not prove the CSRF half. It is not meant to: `SessionPolicyTest` drives that
 * deliberately, and a child that also had to mint a CSRF token would be testing two things
 * and proving neither cleanly.
 *
 * Prints exactly one line, which is the parent's whole interface to it:
 *   http:<status>:<applied|idempotent|unknown>
 *   error:<class>:<message>
 *
 * argv: 1 = tenant uuid, 2 = pager session uuid, 3 = acting user uuid,
 *       4 = barrier path prefix (optional; no barrier when absent)
 */

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$tenantId = (string) ($argv[1] ?? '');
$sessionId = (string) ($argv[2] ?? '');
$userId = (string) ($argv[3] ?? '');
$barrier = (string) ($argv[4] ?? '');

try {
    /* ------------------------------------------------- this client's own browser session */

    $store = $app->make('session')->driver();

    $store->setId((string) Str::random(40));
    $store->start();
    $store->put(Auth::guard('web')->getName(), $userId);
    $store->put(ActiveTenant::SESSION_KEY, $tenantId);
    $store->save();

    $cookieName = (string) config('session.cookie');

    /** @var Encrypter $encrypter */
    $encrypter = $app->make('encrypter');

    $cookie = $encrypter->encrypt(
        CookieValuePrefix::create($cookieName, $encrypter->getKey()).$store->getId(),
        false,
    );

    /* --------------------------------------------------------------- the start barrier */

    if ($barrier !== '') {
        // Booted, session written, cookie computed. Everything expensive is behind us, so
        // the window the parent opens contains the request and nothing else. The reasoning
        // is `demote-owner-in-another-process.php`'s and was measured there: without a
        // barrier, whether the two transactions overlapped was luck, and the guard would
        // have let a defect through one run in five.
        @file_put_contents($barrier.'.ready.'.getmypid(), '1');

        $deadline = microtime(true) + 10.0;

        while (! is_file($barrier.'.go')) {
            if (microtime(true) > $deadline) {
                fwrite(STDOUT, "error:BarrierTimeout:the parent never wrote the go file\n");
                exit(1);
            }

            usleep(50);
        }
    }

    /* ----------------------------------------------------------------- the real request */

    $request = Request::create(
        "/v1/pager-sessions/{$sessionId}/ready",
        'POST',
        server: ['HTTP_ACCEPT' => 'application/json'],
        cookies: [$cookieName => $cookie],
    );

    /** @var HttpKernel $http */
    $http = $app->make(HttpKernel::class);

    $response = $http->handle($request);

    $status = $response->getStatusCode();
    $body = json_decode((string) $response->getContent(), true);

    /*
     * `changed` is the server's own word for "this call is the one that moved it", and it is
     * read out of the RESPONSE rather than inferred from the status: both callers get 200,
     * because the transition is idempotent, so the status alone cannot say which one
     * applied it. A body this process cannot read is reported as `unknown` rather than
     * guessed, so a shape change surfaces as an unexpected outcome in the parent instead of
     * as a silently wrong count.
     */
    $changed = is_array($body) && is_array($body['session'] ?? null)
        ? ($body['session']['changed'] ?? null)
        : null;

    $word = $changed === true ? 'applied' : ($changed === false ? 'idempotent' : 'unknown');

    fwrite(STDOUT, "http:{$status}:{$word}\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, 'error:'.get_class($e).':'.$e->getMessage()."\n");
    exit(1);
}
