<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use App\Models\User;
use App\Platform\Boot\BootChecks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * `R-253` — A SIGNED-OUT PERSON ON /studio OR /platform IS SENT TO SIGN IN
 * ================================================================================
 *
 * The two panels have no login form of their own, deliberately: `§4 › Tenancy` allows two
 * authentication surfaces and never a third, so neither provider calls `->login()` and both
 * docblocks argue it at length. What nobody had asked was what a signed-out BROWSER then
 * sees. Measured on 2026-11-09, with a real navigation's own headers:
 *
 *     GET /studio    ->  401, text/html, 1,990 bytes, "Authentication is required."
 *     GET /platform  ->  401, text/html, 1,993 bytes, the same page
 *
 * Accurate, and a dead end. There is no link, no form and nothing to press, and the way in
 * is a different application on a different port that the page does not name. A restaurant
 * owner who opens a bookmark after their session expires has no next step at all.
 *
 * ⚠ THE HALF THAT IS EASY TO BREAK IS THE `/v1` HALF, which is why it is asserted here
 * beside the redirect rather than in a file of its own: an API client's 401 is the correct
 * answer and a 302 to an HTML page would be a silent, total break of every client.
 */

/** The headers a real browser navigation carries. */
function signInNavigation(): array
{
    return [
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Sec-Fetch-Dest' => 'document',
        'Sec-Fetch-Mode' => 'navigate',
    ];
}

/** A platform admin, inserted as the owner because the app role holds no INSERT on the flag. */
function redirectAdmin(): User
{
    $id = (string) Str::uuid7();

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Redirect Admin',
        'email' => 'redirect-'.Str::lower(Str::random(10)).'@example.test',
        'is_platform_admin' => true,
    ]);

    $user = User::query()->findOrFail($id);
    assert($user instanceof User);

    return $user;
}

beforeEach(function (): void {
    config(['surfaces.staff_signin_url' => 'https://staff.example.test/staff']);
});

it('sends a signed-out browser to the sign-in screen, from every panel path', function (string $path): void {
    $response = $this->get($path, signInNavigation());

    expect($response->status())->toBe(302, "{$path} did not redirect")
        ->and($response->headers->get('Location'))->toBe('https://staff.example.test/staff');

    /*
     * ⚠ `no-store`, AND IT IS NOT DECORATION. A 302 is cacheable by default, and this one is
     * true only while this browser is signed out. These panels are opened from the same
     * shared counter hardware `§12` is written for: a stored redirect would send the NEXT
     * person's panel visit to sign-in after they had already signed in, and the only way out
     * of that is a cache they do not know they have.
     */
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
})->with([
    'the studio root' => ['/studio'],
    'a studio page' => ['/studio/media'],
    'the platform root' => ['/platform'],
    'a platform page' => ['/platform/audit-log'],
]);

it('leaves every API refusal exactly as it was — 401 and a problem document', function (string $path): void {
    $response = $this->getJson($path);

    expect($response->status())->toBe(401, "{$path} did not answer 401")
        ->and($response->headers->get('Content-Type'))->toContain('application/problem+json');
})->with([
    'a tenant route' => ['/v1/tenant'],
    'the identity route' => ['/v1/auth/me'],
    'a pager route' => ['/v1/pager-sessions'],
]);

it('answers a machine on a PANEL path with the document and not a redirect', function (array $headers, string $because): void {
    /*
     * ⚠ THE DISTINCTION IS THE REQUEST'S OWN STATEMENT OF WHAT IT IS FOR, not the path.
     * `PanelProblemPage::wants()` already owns this question and `PanelSignInRedirect` reuses
     * it rather than deriving it again -- its docblock measures why `Accept` cannot answer
     * it: a star-slash-star `Accept` accepts HTML, so `fetch()`, a stylesheet request and an
     * image request would all claim to be navigating. A client that did not say it was
     * navigating gets the machine-readable answer, which is also what `curl` and every
     * monitor get.
     */
    $response = $this->get('/studio', $headers);

    expect($response->status())->toBe(401, "/studio redirected {$because}")
        ->and($response->headers->get('Location'))->toBeNull();
})->with([
    'curl, no Sec-Fetch headers at all' => [['Accept' => '*/*'], 'a client sending no Sec-Fetch-Dest'],
    'fetch()' => [
        ['Accept' => '*/*', 'Sec-Fetch-Dest' => 'empty', 'Sec-Fetch-Mode' => 'cors'],
        'a fetch() call',
    ],
    'an image request' => [
        ['Accept' => 'image/avif,image/webp,*/*;q=0.8', 'Sec-Fetch-Dest' => 'image'],
        'a browser loading an image',
    ],
    'an XHR' => [['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'], 'an XHR'],
]);

it('does NOT redirect a 403, which would be an infinite sign-in loop', function (): void {
    /*
     * ⚠ THE CONDITION THAT MATTERS MOST, AND THE ONE A LOOSER IMPLEMENTATION GETS WRONG. A
     * 403 on a panel means the person IS signed in and this panel is not theirs -- a tenant
     * principal on `/platform`, a platform admin on `/studio` (`§4 › Tenancy`: the flag is
     * not a tenant bypass). Sending that to the staff sign-in form sends somebody already
     * authenticated to a form they will complete successfully and bounce off again, forever.
     * So the redirect is keyed on the PROBLEM TYPE and not on the status class.
     */
    $this->actingForTenant(role: TenantRole::Owner);

    $crossed = $this->get('/platform', signInNavigation());

    expect($crossed->status())->toBe(403, 'a tenant principal on /platform was not refused')
        ->and($crossed->headers->get('Location'))->toBeNull();

    $this->app['auth']->forgetGuards();
    $this->flushSession();
    $this->actingAs(redirectAdmin());

    $other = $this->get('/studio', signInNavigation());

    expect($other->status())->toBe(403, 'a platform admin on /studio was not refused')
        ->and($other->headers->get('Location'))->toBeNull();
});

it('serves the dead-end page rather than a loop when the target is misconfigured', function (string $url, string $because): void {
    /*
     * The fallback is the behaviour this feature replaced, which is bad and FINITE. A
     * `STAFF_SIGNIN_URL` pointing at a panel path on this host would redirect a signed-out
     * browser to a signed-out panel for as long as the browser is willing, and that is one
     * `.env` line away. The boot check refuses the same values outside local and testing;
     * this is the request-time half, because a value can be wrong in an environment where
     * the boot check is exempt.
     */
    config(['surfaces.staff_signin_url' => $url]);

    $response = $this->get('/studio', signInNavigation());

    expect($response->status())->toBe(401, "[{$url}] was redirected to, and it is {$because}")
        ->and($response->headers->get('Location'))->toBeNull()
        ->and((string) $response->getContent())->toContain('<!DOCTYPE html>');
})->with([
    'empty' => ['', 'an unset STAFF_SIGNIN_URL'],
    'whitespace' => ['   ', 'empty with extra steps'],
    'this panel itself' => ['/studio', 'the refusal it came from'],
    'the other panel' => ['/platform', 'the other half of the same loop'],
    'a panel page, same host' => ['http://localhost/studio/media', 'a loop with a path on it'],
]);

it('redirects to another host even when that host\'s path looks like a panel', function (): void {
    // The loop needs BOTH halves: another application's `/studio` is another application's
    // problem, and refusing it would be this check guessing at somebody else's routing.
    config(['surfaces.staff_signin_url' => 'https://elsewhere.example.test/studio']);

    $response = $this->get('/studio', signInNavigation());

    expect($response->status())->toBe(302)
        ->and($response->headers->get('Location'))->toBe('https://elsewhere.example.test/studio');
});

it('carries no return path, because nothing would honour one', function (): void {
    /*
     * `/staff` signs a person into the staff shell; it has nowhere to put a `?next=` and no
     * code that reads one. A parameter nothing honours is a promise to come back that is not
     * kept -- and it is also the shape an open redirect arrives in, so its absence is worth
     * pinning rather than leaving to the reader of a docblock.
     */
    $location = (string) $this->get('/studio/media', signInNavigation())->headers->get('Location');

    expect($location)->toBe('https://staff.example.test/staff')
        ->and($location)->not->toContain('studio')
        ->and($location)->not->toContain('next=')
        ->and($location)->not->toContain('redirect');
});

/*
 * ================================================================================
 * THE BOOT HALF — the value is a SERVING dependency now, so it is checked like one
 * ================================================================================
 */

it('refuses to serve when the sign-in URL would send a locked-out owner nowhere', function (string $url, string $because): void {
    app()->detectEnvironment(fn () => 'production');
    config(['surfaces.staff_signin_url' => $url]);

    $failures = app(BootChecks::class)->staffSignInUrlIsSound();

    expect($failures)->toHaveCount(1, "[{$url}] was accepted, and it is {$because}")
        ->and($failures[0])->toStartWith(BootChecks::UNSAFE_SIGNIN_URL);
})->with([
    'empty' => ['', 'where an unset STAFF_SIGNIN_URL lands'],
    'whitespace' => ['   ', 'empty with extra steps'],
    'the shipped local default' => ['http://localhost:3000/staff', 'a developer machine, in production'],
    'plaintext' => ['http://staff.example.test/staff', 'a credential form a network attacker can rewrite'],
    'scheme-relative' => ['//staff.example.test/staff', 'not absolute, so it resolves nowhere'],
    'path only' => ['/staff', 'not a URL at all, and a 404 on this origin'],
    'no host' => ['https:///staff', 'https with nothing to resolve'],
    'a panel path' => ['https://staff.example.test/studio', 'the redirect loop, stated at boot'],
]);

it('accepts the shape a deployment is supposed to carry, and is exempt locally', function (): void {
    app()->detectEnvironment(fn () => 'production');

    foreach ([
        'https://staff.example.test/staff',
        'https://staff.example.test/sign-in',
        'https://staff.example.test/staff?locale=ar',
        'HTTPS://staff.example.test/staff',
    ] as $url) {
        config(['surfaces.staff_signin_url' => $url]);

        expect(app(BootChecks::class)->staffSignInUrlIsSound())->toBe([], "[{$url}] was refused");
    }

    // And the shipped default is fine where it is true: the compose stack has no TLS and
    // `pnpm --filter web dev` is where /staff answers 200 on a developer's machine.
    app()->detectEnvironment(fn () => 'local');
    config(['surfaces.staff_signin_url' => 'http://localhost:3000/staff']);

    expect(app(BootChecks::class)->staffSignInUrlIsSound())->toBe([]);
});

it('is one of the reasons boot:check refuses, not a check nothing calls', function (): void {
    // ⚠ THE DEAD-CONTROL SHAPE `§4a` RECORDS FOUR INSTANCES OF. A check that is correct and
    // unreferenced is worse than no check: it reads as protection. So this asserts the
    // aggregate, which is what `boot:check` and the serving path actually call.
    app()->detectEnvironment(fn () => 'production');
    config(['surfaces.staff_signin_url' => 'http://not-https.example.test/staff']);

    $mine = array_values(array_filter(
        app(BootChecks::class)->failures(),
        static fn (string $failure): bool => str_starts_with($failure, BootChecks::UNSAFE_SIGNIN_URL),
    ));

    expect($mine)->toHaveCount(1, 'failures() does not include staffSignInUrlIsSound()');
});
