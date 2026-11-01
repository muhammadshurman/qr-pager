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

/**
 * The headers a real browser navigation carries.
 *
 * ⚠ `Accept: text/html` ALONE NO LONGER REACHES THE PAGE, and that is the §11 security
 * round's S-2 fix rather than a regression: a wildcard Accept and `text/html` are both what
 * a browser
 * sends for an image or a stylesheet, so `PanelProblemPage::wants()` now requires
 * `Sec-Fetch-Dest: document` (or `Sec-Fetch-Mode: navigate`) — a forbidden header name a
 * page cannot forge. Every case below that means to measure the PAGE must therefore
 * navigate, and the disclosure sweep especially: reading JSON instead would quietly return
 * that sweep to the safe half it already covered.
 *
 * @return array<string, string>
 */
function walkNavigation(): array
{
    return [
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Sec-Fetch-Dest' => 'document',
        'Sec-Fetch-Mode' => 'navigate',
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

it('derives all seven panel routes, so no sweep below runs over nothing', function (): void {
    // ⚠ `walkRoutes()` HAD NO SIZE ASSERTION, and three cases loop over it with no bound --
    // the brand sweep, the 503 case and the disclosure sweep, whose `expect($found)->toBe([])`
    // is trivially true of an empty set. Only the CHROME subset was pinned, while the
    // headline claim in the ticket and in PLAN §0.2 is about SEVEN screens.
    expect(walkRoutes())->toBe([
        '/platform',
        '/platform/audit-log',
        '/platform/restaurants',
        '/studio',
        '/studio/domains',
        '/studio/media',
        '/studio/preview',
    ]);
});

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

        // ⚠ ACCEPT: text/html, so the sweep runs over the HTML page and not only over the
        // document. The HTML branch is the shape that leaked 862,104 bytes at `7f1c8f6`,
        // so it is the branch this case has to cover -- a disclosure sweep that only ever
        // read JSON would have been true of the safe half.
        $body = withSourceDown(
            fn (): string => (string) $this->get($uri, walkNavigation())->getContent()
        );

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

it('answers a panel screen with an HTML page and the API with the document', function (): void {
    /*
     * ⚠ THE WALK'S FINDING, FIXED — and this case is the one a reviewer should read first.
     *
     * The walk measured all seven panel screens answering a database outage with
     * `application/problem+json`: correct for `/v1`, raw JSON in a restaurant owner's
     * browser, and silent on `§6`'s "whether to wait or retry". `PanelProblemPage` now
     * renders the SAME problem as HTML for panel paths only.
     *
     * THE PROPERTY THAT MAKES THAT SAFE is asserted by the disclosure case above, run over
     * the HTML body: `PanelProblemPage` never sees the throwable. It takes a `Problem` —
     * already the sanitised document — and the view takes four scalars from it. An HTML
     * error page is the shape that leaked 862,104 bytes at `7f1c8f6`, which is why
     * `ProblemRenderer` is registered for every request, and why the branch is placed AFTER
     * that renderer rather than beside it.
     */
    LandingFixtures::bucket();

    $this->actingAs(walkAdmin());

    $panel = withSourceDown(fn () => $this->get('/platform', walkNavigation()));

    expect($panel->status())->toBe(503)
        ->and((string) $panel->headers->get('content-type'))->toContain('text/html');

    $html = (string) $panel->getContent();

    expect($html)->toContain('<!DOCTYPE html>')
        // The type's own fixed title, verbatim -- not a new sentence invented per reason,
        // which is how a friendly error page becomes §4.2's oracle.
        ->and($html)->toContain('The service is temporarily unable to handle the request.')
        // §6's third column, both halves: it is us, and what to do.
        ->and($html)->toContain('This is on our side, not yours.')
        ->and($html)->toContain('Wait a minute and reload this page.')
        // The product, never the framework -- the same §12 clause as the panel titles.
        ->and($html)->toContain('QR Pager')
        ->and(str_contains($html, 'Laravel'))->toBeFalse('the error page names the framework')
        // ⚠ AND IT DEPENDS ON NO ASSET MANIFEST. The page that exists for things being
        // unavailable must not need the thing that is most often unavailable: a build.
        ->and(str_contains($html, '/build/'))->toBeFalse('the error page loads a built asset');

    // ⚠ THE API SURFACE IS UNTOUCHED, which is the half that would be easy to break. `/v1`
    // is not a panel path, so an API client -- even one sending Accept: text/html -- still
    // gets the document the spec publishes.
    // `/v1` is not a panel path, so even a full browser navigation there gets the document.
    $api = $this->get('/v1/does-not-exist', walkNavigation());

    expect((string) $api->headers->get('content-type'))->toContain('application/problem+json')
        ->and($api->json('type'))->toBe('https://qrpager.dev/problems/not-found');
});

it('builds the error page from tokens, with the seven literals it declares', function (): void {
    /*
     * ⚠ THE ERROR VIEW IS OUTSIDE BOTH LINT SCANNERS, and this case is why that is not a
     * gap. `scan-panel.mjs` derives its subjects from the panel themes' `@source` globs --
     * the set Tailwind scans -- and this page deliberately uses NO Tailwind: it must render
     * when the asset build is the thing that is unavailable. `scan-css.mjs` exempts
     * `apps/api` by name. So nothing would have looked at it.
     *
     * Adding it to a theme's `@source` would be wrong (Tailwind would scan a file with no
     * utilities in it) and adding an exception to the scanner's subject derivation would
     * weaken the one-source story it is built on. So the property is asserted here, in the
     * layer the file lives in, which is also where a reader of the view will look.
     */
    $view = (string) file_get_contents(resource_path('views/errors/panel-problem.blade.php'));

    // Comments out, so prose explaining a value is not read as the value.
    $code = (string) preg_replace(['/\{\{--[\s\S]*?--\}\}/', '#/\*[\s\S]*?\*/#'], ' ', $view);

    expect($code)->not->toMatch('/#[0-9a-f]{3,8}\b/i');

    // Every colour, length and size comes through config(), so the token source stays the
    // one source even on the page that cannot load a stylesheet.
    foreach ([
        'design-tokens.colours.light.paper',
        'design-tokens.colours.dark.paper',
        'design-tokens.space.32',
        'design-tokens.radius.16',
        'design-tokens.size.28',
        'design-tokens.fonts.latin.family',
    ] as $key) {
        expect($code)->toContain("config('{$key}')");
    }

    // THE DECLARED EXCEPTIONS, as an exact set rather than a pattern that walks past the
    // next one. Seven values, each named, and not one of them is a colour, a spacing step
    // or a font size -- the three categories §12 names:
    //
    //   0, 0        a reset. `0` is in the lint rule's own KEYWORD_SAFE list, with the
    //               reason written there: "it carries no unit to get wrong and no scale
    //               to belong to".
    //   1.2, 1.5    unitless line-heights. A ratio, not a length.
    //   100vh       full-viewport centring. A viewport unit is not a step on any ladder.
    //   1px solid   the hairline border. `1px` is the ONE physical-pixel length the system
    //               admits, for the reason patterns.mjs gives: it is a device pixel rather
    //               than a spacing step, and the approved elevation is written in the same
    //               units. The colour is a var().
    //   42rem       the measure -- maximum line length. The token set has no measure
    //               scale, so this is the one genuinely new value on the page, and it is
    //               declared here rather than left for a reviewer to find.
    //
    // ⚠ This list was first written as four from memory of the file and the assertion
    // reddened on the two `0`s and `100vh`. The expected set is now the MEASURED one.
    $magnitudes = [];
    preg_match_all('/:\s*([^;{}]*\d[^;{}]*);/', $code, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $value = trim($match[1]);

        if (str_contains($value, '{{')) {
            continue;
        }

        $magnitudes[] = $value;
    }

    sort($magnitudes);

    expect($magnitudes)->toBe(['0', '0', '1.2', '1.5', '100vh', '1px solid var(--hairline)', '42rem']);
});

it('gives a JSON client on a panel path the document, not the page', function (): void {
    // ⚠ THE CASE THE CONTENT NEGOTIATION EXISTS FOR, and the one an "is it a panel path"
    // check alone would get wrong. Every write either panel makes arrives at
    // `POST /livewire-<hash>/update`, which is a panel path -- a client that got an HTML
    // page there would show the reader nothing at all. Measured from Livewire's own bundle
    // (`livewire.esm.js`): it sends `Accept: application/json` and `X-Livewire: 1`.
    //
    // ⚠ AND BOTH GUARDS ARE NEEDED, which a single case could not show. Removing
    // `expectsJson()` left every assertion in this file GREEN, because `acceptsHtml()`
    // already refuses `Accept: application/json` -- a redundant check no test can
    // distinguish is the dead-control shape `§4a` records. The second row below is the
    // case that separates them: an XHR client sending `Accept: */*` ACCEPTS html, so
    // `acceptsHtml()` says yes and only `expectsJson()` refuses it.
    LandingFixtures::bucket();

    $this->actingAs(walkAdmin());

    foreach ([
        'Livewire' => ['Accept' => 'application/json', 'X-Livewire' => '1'],
        'an XHR client sending */*' => ['Accept' => '*/*', 'X-Requested-With' => 'XMLHttpRequest'],
    ] as $client => $headers) {
        $response = withSourceDown(fn () => $this->get('/platform', $headers));

        $type = (string) $response->headers->get('content-type');

        // str_contains() AND NOT `toContain($needle, $message)`. FOURTH time in this task:
        // toContain is variadic over needles and takes no message in EITHER form, so the
        // sentence became a second needle and this case failed on its own message while
        // the code was correct. The guard refuses the silent negated form only, by a
        // documented decision that still holds -- so the discipline is simply never to
        // write a message on this matcher.
        expect($response->status())->toBe(503, "{$client} got {$response->status()}")
            ->and(str_contains($type, 'application/problem+json'))
            ->toBeTrue("{$client} got {$type} instead of the document")
            ->and($response->json('status'))->toBe(503);
    }
});

it('pairs every colour on the error page against the generated contrast table — M-5', function (): void {
    /*
     * ⚠ THE DEFECT CLASS THAT BLOCKED 8a, ONE SURFACE OVER. The §11 round found that this
     * page had a VOCABULARY check and no PAIR check: the case above asserts it names only
     * `config('design-tokens.…')` keys, which is satisfied by any two tokens put on top of
     * each other. 8a's own review recorded the identical shape as blocking — "a `brand`-on-
     * `alert-ink` rule at 1.14:1 inside a landing template passed everything, because the
     * templates had a vocabulary check and no pair check".
     *
     * There is no breach today; I checked before writing this. The gap was the control.
     *
     * THE PAIRS ARE ENUMERATED WITH THEIR REASON, AND THE ENUMERATION IS HELD TO THE FILE:
     * the second half of this case asserts the view declares exactly the colour properties
     * this list accounts for, so a new pairing cannot arrive without updating it.
     */
    $table = json_decode(
        (string) file_get_contents(dirname(base_path(), 2).'/packages/tokens/generated/contrast.json'),
        true
    );

    expect($table)->toBeArray()->toHaveKey('pairs');

    /** @var list<array<string, mixed>> $pairs */
    $pairs = $table['pairs'];

    expect(count($pairs))->toBeGreaterThan(20, 'the contrast table is too small to check against');

    $find = function (string $fg, string $bg) use ($pairs): array {
        foreach ($pairs as $row) {
            if ($row['theme'] === 'light' && $row['fg'] === $fg && $row['bg'] === $bg) {
                return $row;
            }
        }

        return [];
    };

    // Every foreground/background the page puts together, and where.
    $used = [
        'body text on the ground' => ['ink', 'paper'],
        'card text on the card' => ['ink', 'surface'],
        'the reference line on the card' => ['ink-mid', 'surface'],
    ];

    foreach ($used as $what => [$fg, $bg]) {
        $row = $find($fg, $bg);

        expect($row)->not->toBe([], "{$what}: {$fg} on {$bg} is not in the generated table at all");
        expect($row['pass'])->toBeTrue(
            "{$what}: {$fg} on {$bg} is {$row['ratio']}, below its {$row['kind']} bar"
        );
    }

    // ⚠ `hairline` ON `surface` IS THE CARD'S BORDER AND IS DECORATIVE, which the token
    // source says in its own words. It is asserted as RECORDED-DECORATIVE rather than
    // skipped: a border that silently became a control boundary would need 3:1, and the only
    // way to notice is to look the pair up.
    $border = $find('hairline', 'surface');

    expect($border)->not->toBe([], 'hairline on surface is not in the generated table');
    expect(strtolower((string) $border['what']))->toContain('decorative');

    // AND THE LIST IS HELD TO THE FILE. Every `color:` / `background:` declaration in the
    // view must name a token this enumeration accounts for, so a fourth pairing cannot
    // arrive unmeasured.
    $view = (string) file_get_contents(resource_path('views/errors/panel-problem.blade.php'));
    $code = (string) preg_replace(['/\{\{--[\s\S]*?--\}\}/', '#/\*[\s\S]*?\*/#'], ' ', $view);

    preg_match_all('/(?:^|[^-\w])(?:color|background|border)\s*:[^;]*var\(--([a-z0-9-]+)/mi', $code, $matches);

    $accounted = ['paper', 'ink', 'ink-mid', 'surface', 'hairline'];

    expect(array_values(array_unique($matches[1])))->not->toBe([], 'no colour declaration was found');

    foreach (array_unique($matches[1]) as $token) {
        expect(in_array($token, $accounted, true))
            ->toBeTrue("the page uses --{$token}, which the pair enumeration above does not account for");
    }
});
