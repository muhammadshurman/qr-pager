<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use App\Models\User;
use Filament\Pages\BasePage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\LandingFixtures;

/*
 * ================================================================================
 * THE SCREEN-BY-SCREEN WALK — §6's THIRD COLUMN, MEASURED RATHER THAN "UNEXAMINED"
 * ================================================================================
 *
 * `QRPAGER-23 §6.1` filled a 7x3 table and left the *source down* column as **"unexamined"**
 * for six of the seven screens, with the reason stated: *"I have not driven those screens
 * with their data source down, and asserting a verdict now is the shape `R-156` was filed
 * for — a verdict asserted in the same commit that wrote it."* This file drives them.
 *
 * THE INSTRUMENT: the APP connection is pointed at a CLOSED PORT and its cached PDO dropped,
 * so the next read through it fails to connect. That is the service being unreachable rather
 * than a mocked exception — the same distinction `bin/deps` makes between an audit that found
 * nothing and an audit that could not run.
 *
 * ⚠ WHAT THE WALK FOUND, AND IT IS A FINDING RATHER THAN A PASS. All seven screens answer a
 * database outage with **`application/problem+json`, 503, ~160 bytes** — a machine-readable
 * API document on an HTML surface. The security half is CLEAN: no SQLSTATE, no exception
 * class, no role name, no port, no connection string, no stack trace, at `APP_DEBUG=true`,
 * which is what `ProblemRenderer` exists for and the measurement confirms it holds. The
 * *presentation* half is not: a restaurant owner whose page will not load sees raw JSON, and
 * `§6`'s third column asks for "that it is us, not them, and whether to wait or retry".
 *
 * That is recorded here as the measured state. The cases below pin the two halves that must
 * never regress — the status and the non-disclosure — so that fixing the presentation cannot
 * quietly reopen the leak this renderer was built to close.
 */

/** Every concrete GET route either panel serves, derived from the router (SR-3's seven). */
function walkRoutes(): array
{
    $uris = [];

    foreach (Route::getRoutes() as $route) {
        $uri = '/'.ltrim($route->uri(), '/');

        if (! str_starts_with($uri, '/platform') && ! str_starts_with($uri, '/studio')) {
            continue;
        }

        if (! in_array('GET', $route->methods(), true) || str_contains($uri, '{')) {
            continue;
        }

        $uris[] = $uri;
    }

    $uris = array_values(array_unique($uris));
    sort($uris);

    return $uris;
}

/**
 * The panel routes that render FILAMENT CHROME, derived from each route's action class.
 *
 * A Filament page route resolves to a Livewire component extending `Filament\Pages\BasePage`;
 * `/studio/preview` resolves to `PreviewController`, which renders the TENANT'S OWN landing
 * page. That distinction matters here: the preview's `<title>` is the RESTAURANT's name and
 * must NOT carry our brand -- measured, it reads `Tenant 01a19423` from the fixture's own
 * tenant name, which is correct and is why the first version of the case below failed.
 *
 * @return list<string>
 */
function walkChromeRoutes(): array
{
    $chrome = [];

    foreach (Route::getRoutes() as $route) {
        $uri = '/'.ltrim($route->uri(), '/');

        if (! in_array($uri, walkRoutes(), true)) {
            continue;
        }

        $action = $route->getAction('controller');

        if (is_string($action) && is_subclass_of($action, BasePage::class)) {
            $chrome[] = $uri;
        }
    }

    $chrome = array_values(array_unique($chrome));
    sort($chrome);

    return $chrome;
}

/** A platform admin, inserted as the owner because the app role holds no INSERT on the flag. */
function walkAdmin(): User
{
    $id = (string) Str::uuid7();

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Walk Admin',
        'email' => 'walk-'.Str::lower(Str::random(10)).'@example.test',
        'is_platform_admin' => true,
    ]);

    $user = User::query()->findOrFail($id);
    assert($user instanceof User);

    return $user;
}

/**
 * Anything an error body must never carry, each with what it would disclose.
 *
 * `§4 › Edge & ops` as amended, and `ProblemRenderer`'s own gate clause: *"no error response
 * body carries a database role name, a constraint name, SQL or a bound parameter, at any
 * APP_DEBUG setting"*. Measured at `7f1c8f6`, before that renderer existed, an HTML error
 * page carried all of it in 862,104 bytes.
 *
 * @return array<string, string>
 */
function walkLeaks(): array
{
    return [
        'SQLSTATE' => 'the driver error code',
        'QueryException' => 'the exception class',
        'PDOException' => 'the driver exception class',
        'qrpager_app' => 'a database role name',
        'qrpager_owner' => 'a database role name',
        'connection to server' => 'libpq’s own connect message',
        'Stack trace' => 'a stack trace',
        'vendor/laravel' => 'a framework path',
        'password' => 'a credential field name',
    ];
}

/** Run $work with the app connection pointed at a closed port. */
function withSourceDown(callable $work): mixed
{
    $port = config('database.connections.pgsql.port');

    config(['database.connections.pgsql.port' => 1]);
    DB::purge('pgsql');

    try {
        return $work();
    } finally {
        // ALWAYS restored, even if the assertion throws: a test that left the app
        // connection pointed at port 1 would redden every test after it in the file for a
        // reason that is not a finding.
        config(['database.connections.pgsql.port' => $port]);
        DB::purge('pgsql');
    }
}

it('shows the product in the browser tab, and never the framework', function (): void {
    // ⚠ §12: "a reviewer cannot name the UI framework from a screenshot". It was FALSE in
    // the one place a screenshot always shows. `Panel::getBrandName()` falls back to
    // `config('app.name')` and `.env.example` ships `APP_NAME=Laravel`, so every page of
    // both panels rendered `<title>… - Laravel</title>`. Measured on /studio/media:
    // `Media - Laravel`.
    LandingFixtures::bucket();

    $brand = (string) config('app.brand');

    expect($brand)->toBe('QR Pager');

    foreach (walkRoutes() as $uri) {
        if (str_starts_with($uri, '/studio')) {
            $this->actingForTenant(role: TenantRole::Owner);
        } else {
            $this->actingAs(walkAdmin());
        }

        $html = (string) $this->get($uri)->getContent();

        // /studio/preview renders the tenant's own landing page, not the panel layout, so
        // it carries no panel title at all -- and must not carry the framework's name
        // either, which is the half that applies to every document.
        if (preg_match('#<title>(.*?)</title>#s', $html, $title) === 1) {
            $text = trim(preg_replace('/\s+/', ' ', $title[1]) ?? '');

            expect(str_contains($text, 'Laravel'))
                ->toBeFalse("{$uri} names the framework in its title: {$text}");
        }

        expect(str_contains($html, 'Laravel'))
            ->toBeFalse("{$uri} names the framework in its markup");
    }
});

it('names the product on every panel page that has a title', function (): void {
    // The positive half: "does not say Laravel" would also be true of a page with no title
    // at all, which is the vacuity §4a records four instances of.
    LandingFixtures::bucket();

    $brand = (string) config('app.brand');
    $titled = 0;

    // THE CHROME ROUTES ONLY, derived structurally. /studio/preview renders the tenant's
    // page and its title is the RESTAURANT's name -- our brand there would be us putting
    // our name on their page, which is the opposite of what §12 wants.
    expect(walkChromeRoutes())->toHaveCount(6);

    foreach (walkChromeRoutes() as $uri) {
        if (str_starts_with($uri, '/studio')) {
            $this->actingForTenant(role: TenantRole::Owner);
        } else {
            $this->actingAs(walkAdmin());
        }

        $html = (string) $this->get($uri)->getContent();

        if (preg_match('#<title>(.*?)</title>#s', $html, $title) !== 1) {
            continue;
        }

        $text = trim(preg_replace('/\s+/', ' ', $title[1]) ?? '');

        if ($text === '') {
            continue;
        }

        // ⚠ str_contains() AND NOT `toContain($brand, $message)`. `toContain` is VARIADIC
        // over needles and takes NO message, in the positive form as well as the negated
        // one -- so this asserted the sentence was in the title too and failed with
        // `Expected: Platform - QR Pager  To contain: /platform does not name the product`.
        //
        // AND THE GUARD IS CORRECT NOT TO FLAG THIS FORM, which is worth recording because
        // the instinct after a third encounter is to widen it. `SourceGuard`'s own docblock
        // already argues the case: the negated form is SILENT, the positive form means
        // "contains both" and is legitimate, and when it is misused as a message it fails
        // loudly and corrects itself -- "a guard that flagged the safe form too would be
        // loosened by the first person who had to read its output". All three encounters in
        // this task went exactly that way: guard named the silent one, the two loud ones
        // named themselves. The reservation held; nothing was widened.
        expect(str_contains($text, $brand))
            ->toBeTrue("{$uri} does not name the product in its title: {$text}");
        $titled++;
    }

    // The six chrome routes; /studio/preview is the tenant's page and has its own title.
    expect($titled)->toBe(6);
});

it('answers every panel screen 503 when its database is unreachable', function (): void {
    // §6's SOURCE DOWN column, for all seven screens, which §6.1 left "unexamined" for six.
    LandingFixtures::bucket();

    $admin = walkAdmin();
    $tenant = $this->actingForTenant(role: TenantRole::Owner);

    foreach (walkRoutes() as $uri) {
        if (str_starts_with($uri, '/studio')) {
            $this->actingForTenant(tenantId: $tenant, role: TenantRole::Owner);
        } else {
            $this->actingAs($admin);
        }

        [$status, $body] = withSourceDown(function () use ($uri): array {
            $response = $this->get($uri);

            return [$response->status(), (string) $response->getContent()];
        });

        // 503 and not 500: "come back" rather than "something broke". That distinction is
        // the whole of §6's third column and it is already right.
        expect($status)->toBe(503, "{$uri} answered {$status} with its database down");

        // ⚠ AND IT SAYS IT IS US. A body that said nothing would also carry no leak.
        expect($body)->toContain('temporarily unable');
    }
});

it('leaks nothing from any panel screen with its database unreachable', function (): void {
    // THE CLAUSE `ProblemRenderer` EXISTS FOR, measured on the panel surface rather than on
    // the API one -- "no error response body carries a database role name, a constraint
    // name, SQL or a bound parameter, at any APP_DEBUG setting".
    LandingFixtures::bucket();

    expect(config('app.debug'))->toBeTrue('this case is only interesting with APP_DEBUG true');

    $admin = walkAdmin();
    $tenant = $this->actingForTenant(role: TenantRole::Owner);
    $found = [];

    foreach (walkRoutes() as $uri) {
        if (str_starts_with($uri, '/studio')) {
            $this->actingForTenant(tenantId: $tenant, role: TenantRole::Owner);
        } else {
            $this->actingAs($admin);
        }

        $body = withSourceDown(fn (): string => (string) $this->get($uri)->getContent());

        foreach (walkLeaks() as $needle => $what) {
            if (stripos($body, $needle) !== false) {
                $found[] = "{$uri} discloses {$what} ({$needle})";
            }
        }

        // Not vacuous: the body must BE the error document, or "carries no leak" is true of
        // an empty string.
        expect(strlen($body))->toBeGreaterThan(80, "{$uri} returned a body too short to be the error document");
    }

    expect($found)->toBe([]);
});

it('records the measured source-down body, which is problem+json on an HTML surface', function (): void {
    /*
     * ⚠ THE WALK'S FINDING, pinned as a measurement so the fix can be judged against it.
     *
     * A panel screen is an HTML surface a person navigates to. With the database
     * unreachable it answers an RFC 7807 document -- correct for `/v1`, and raw JSON in a
     * restaurant owner's browser. `§6`'s third column asks the screen to say "it is us, not
     * them, and whether to wait or retry"; the title says the first and nothing says the
     * second.
     *
     * This case asserts the CURRENT shape exactly. When the presentation is fixed it must
     * change, and changing it is how a reviewer finds this note -- which is the point:
     * `ProblemRenderer` is registered for ALL requests deliberately, because an HTML error
     * page was once 862,104 bytes carrying the SQL and a role name. Any HTML branch has to
     * carry the non-disclosure case above with it.
     */
    LandingFixtures::bucket();

    $this->actingAs(walkAdmin());

    $response = withSourceDown(fn () => $this->get('/platform'));

    expect($response->headers->get('content-type'))->toContain('application/problem+json');

    $document = json_decode((string) $response->getContent(), true);

    expect($document)->toBe([
        'type' => 'https://qrpager.dev/problems/unavailable',
        'title' => 'The service is temporarily unable to handle the request.',
        'status' => 503,
        'instance' => '/platform',
    ]);
});
