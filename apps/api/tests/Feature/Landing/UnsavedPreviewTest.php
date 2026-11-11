<?php

declare(strict_types=1);

use App\Domain\Landing\LandingDocumentInvalid;
use App\Domain\Tenancy\TenantRole;
use App\Features\Landing\LandingPages;
use App\Features\Landing\MediaLibrary;
use App\Features\Landing\Panel\Pages\LandingBuilderPage;
use App\Features\Landing\Panel\Pages\MediaLibraryPage;
use App\Features\Landing\Panel\Pages\ThemePage;
use App\Features\Landing\Panel\StudioForm;
use App\Features\Landing\TenantMediaReferences;
use App\Features\Landing\UnsavedPreview;
use App\Platform\Http\Middleware\RateLimit;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\ImageFixtures;
use Tests\Support\LandingFixtures;

/*
 * ================================================================================
 * THE UNSAVED PREVIEW — `QRPAGER-45`
 * ================================================================================
 *
 * The ticket's stated invariant is a BROWSER claim: *"changing the arrangement, or editing a
 * block field, is reflected in the preview with no Save click and no page refresh."* That
 * lives in `browser/studio.spec.ts`, because only an engine can run the debounce, the
 * Livewire round trip and the frame reload.
 *
 * What belongs here is everything the browser case CANNOT see, and the most important of it
 * is the tenant boundary. `§4` says Redis carries no RLS: there is no policy behind this
 * store, so the key's namespace is the whole control and a test is the only thing that holds
 * it. `withTenant()` and a Postgres policy would have caught a mistake on every other path
 * in this feature; here nothing would.
 */

beforeEach(fn () => LandingFixtures::bucket());

/**
 * A studio session that survives more than one request, and the cookie that carries it.
 *
 * ⚠ MEASURED RATHER THAN ASSUMED, AND IT COST FIVE RED CASES. Laravel's test client does
 * NOT feed a response's cookies back into the next request, so every `test()->get()` starts
 * a FRESH session — probed directly: three requests, three different ids. `QRPAGER-45` keys
 * the unsaved preview by tenant AND session, so a test that writes the key under one id and
 * then reads the pane under another is testing nothing at all: the key is simply never
 * found, and the case fails for a reason that has nothing to do with the feature.
 *
 * In a real browser the cookie is carried and the id is stable, which is what the browser
 * case proves. Here it has to be carried by hand.
 *
 * @return array{0: string, 1: string} the session id, and the cookie value to send onward
 */
function studioSession(): array
{
    $name = (string) config('session.cookie');

    $response = test()->get('/studio');
    $response->assertOk();

    $carried = '';

    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === $name) {
            $carried = (string) $cookie->getValue();
        }
    }

    expect($carried)->not->toBe('');

    return [session()->getId(), $carried];
}

/** The preview, fetched inside the session `studioSession()` established. */
function previewIn(string $carried): TestResponse
{
    return test()->withUnencryptedCookie((string) config('session.cookie'), $carried)->get('/studio/preview');
}

it('renders the unsaved state in preference to the stored draft', function (): void {
    $tenant = test()->actingForTenant(role: TenantRole::Owner);

    [$sid, $carried] = studioSession();

    $pages = app(LandingPages::class);
    $pages->saveDraft($tenant, LandingFixtures::fullPage());

    // The saved page says one thing...
    expect((string) previewIn($carried)->getContent())->toContain('Welcome to Olive');

    // ...and the unsaved state says another, through the same gate a save would use.
    $edited = LandingFixtures::fullPage();
    $edited['blocks'][0]['fields']['heading'] = 'Typed but never saved';

    app(UnsavedPreview::class)->put($tenant, $sid, $pages->validated($tenant, $edited)->toArray());

    $html = (string) previewIn($carried)->getContent();

    expect($html)->toContain('Typed but never saved')
        ->and($html)->not->toContain('Welcome to Olive');
});

it('writes nothing to the stored draft, so `draft` still means what it meant', function (): void {
    $tenant = test()->actingForTenant(role: TenantRole::Owner);

    [$sid, $carried] = studioSession();

    $pages = app(LandingPages::class);
    $pages->saveDraft($tenant, LandingFixtures::fullPage());

    $edited = LandingFixtures::fullPage();
    $edited['blocks'][0]['fields']['heading'] = 'Typed but never saved';

    app(UnsavedPreview::class)->put($tenant, $sid, $pages->validated($tenant, $edited)->toArray());

    /*
     * ⚠ THE WHOLE POINT OF THE RULING, ASSERTED. Muhammad ruled autosave OUT: this feature
     * *"writes nothing to Postgres on a keystroke"*. If that ever stops being true, the
     * preview would still look right and `draft` would quietly have become `autosave`.
     */
    $stored = $pages->draft($tenant)->toArray();

    expect($stored['blocks'][0]['fields']['heading'])->toBe('Welcome to Olive');
});

it('falls back to the stored draft when there is no unsaved state', function (): void {
    $tenant = test()->actingForTenant(role: TenantRole::Owner);

    [$sid, $carried] = studioSession();

    app(LandingPages::class)->saveDraft($tenant, LandingFixtures::fullPage());

    expect((string) previewIn($carried)->getContent())->toContain('Welcome to Olive');
});

it('shows one tenant NOTHING of another tenant’s unsaved state', function (): void {
    $pages = app(LandingPages::class);

    // The neighbour saves a page and then types something they have not saved.
    $neighbour = test()->actingForTenant(role: TenantRole::Owner);
    [$neighbourSid, $neighbourCookie] = studioSession();
    $pages->saveDraft($neighbour, LandingFixtures::fullPage());

    $secret = LandingFixtures::fullPage();
    $secret['blocks'][0]['fields']['heading'] = 'NEIGHBOUR UNSAVED SECRET';
    app(UnsavedPreview::class)->put($neighbour, $neighbourSid, $pages->validated($neighbour, $secret)->toArray());

    // A different tenant, in a different session, asks for their own preview.
    $mine = test()->actingForTenant(role: TenantRole::Owner);
    [$mySid, $myCookie] = studioSession();
    $pages->saveDraft($mine, LandingFixtures::fullPage());

    $html = (string) previewIn($myCookie)->getContent();

    expect($html)->not->toContain('NEIGHBOUR UNSAVED SECRET');

    /*
     * ⚠ AND THE NEIGHBOUR'S KEY IS REALLY THERE, which is the claim behind the assertion
     * above. Without this, a store that returned null for BOTH tenants — a broken
     * connection, a wrong prefix — would satisfy the `not->toContain` while proving nothing.
     */
    expect(app(UnsavedPreview::class)->get($neighbour, $neighbourSid))->not->toBeNull()
        ->and(app(UnsavedPreview::class)->get($mine, $mySid))->toBeNull()
        ->and($neighbourCookie)->not->toBe($myCookie);
});

it('refuses an unsaved state that reaches for another tenant’s media', function (): void {
    $pages = app(LandingPages::class);

    // A real asset, uploaded through the real path, owned by the neighbour.
    $neighbour = test()->actingForTenant(role: TenantRole::Owner);

    [$sid, $carried] = studioSession();

    Livewire::test(MediaLibraryPage::class)
        ->set('upload', UploadedFile::fake()->createWithContent('logo.png', ImageFixtures::png()))
        ->call('store');

    $neighbourMedia = app(MediaLibrary::class)->all($neighbour)[0]->id;

    $mine = test()->actingForTenant(role: TenantRole::Owner);

    [$sid, $carried] = studioSession();

    /*
     * ⚠ THIS IS THE CASE THE WHOLE `validated()` METHOD EXISTS FOR. The unsaved path carries
     * CLIENT-SUPPLIED state to the renderer, so it is the one route on this feature that
     * could show a tenant an asset it does not own. It goes through the same
     * `LandingDocument::fromInput($input, new TenantMediaReferences($tenantId))` that
     * `saveDraft()` does, and that is what refuses this.
     */
    $stolen = LandingFixtures::fullPage($neighbourMedia);

    expect(fn () => $pages->validated($mine, $stolen))->toThrow(LandingDocumentInvalid::class);
});

it('refuses a tenant id that is not a uuid, rather than building a key from it', function (): void {
    $store = app(UnsavedPreview::class);

    /*
     * A glob in a Redis key is a pattern in some clients and a wrong-tenant read in any of
     * them. `tenantId()` returns a uuid, so this never fires in practice — which is why it
     * is asserted rather than assumed.
     */
    expect(fn () => $store->get('*', 'sid'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $store->put('a:*', 'sid', []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $store->forget('../other', 'sid'))->toThrow(InvalidArgumentException::class);
});

it('forgets the unsaved state once a save has landed', function (): void {
    $tenant = test()->actingForTenant(role: TenantRole::Owner);

    [$sid, $carried] = studioSession();

    $pages = app(LandingPages::class);
    $pages->saveDraft($tenant, LandingFixtures::fullPage());

    $edited = LandingFixtures::fullPage();
    $edited['blocks'][0]['fields']['heading'] = 'Typed but never saved';
    app(UnsavedPreview::class)->put($tenant, $sid, $pages->validated($tenant, $edited)->toArray());

    expect(app(UnsavedPreview::class)->get($tenant, $sid))->not->toBeNull();

    // The page's own save path, which is what the owner's button reaches.
    Livewire::test(LandingBuilderPage::class)->call('save');

    /*
     * ⚠ THE BUG THIS PREVENTS IS LATER THAN IT LOOKS. Right after a save the key and the
     * stored draft agree, so nothing is visibly wrong. Save, edit, then undo the edit by
     * hand: the key still holds the edit and the owner has no control that clears it.
     */
    expect(app(UnsavedPreview::class)->get($tenant, $sid))->toBeNull();
});

it('treats a corrupt or stale key as nothing to show, and renders the stored draft', function (): void {
    $tenant = test()->actingForTenant(role: TenantRole::Owner);

    [$sid, $carried] = studioSession();

    app(LandingPages::class)->saveDraft($tenant, LandingFixtures::fullPage());

    /*
     * Written past the store, on purpose: this is the shape a schema change leaves behind,
     * and the ruling says an unsaved preview has nothing to refuse. The old behaviour —
     * render the stored draft — is what it must degrade to, never a 422 inside the pane.
     */
    app('redis')->connection()->setex(
        'qrpager:studio:unsaved-preview:'.$tenant.':'.UnsavedPreview::fingerprint($sid),
        60,
        '{"blocks":[{"type":"not-a-block","fields":{}}],"theme":{},"seo":{}}',
    );

    $response = previewIn($carried);

    expect($response->getStatusCode())->toBe(200)
        ->and((string) $response->getContent())->toContain('Welcome to Olive');
});

/*
 * ================================================================================
 * THE THREE SPECIFICATIONS OF 2026-11-11
 * ================================================================================
 */

it('leaves the last valid preview standing when the state stops validating', function (): void {
    $tenant = test()->actingForTenant(role: TenantRole::Owner);

    $pages = app(LandingPages::class);
    $pages->saveDraft($tenant, LandingFixtures::fullPage());

    /*
     * Driven entirely through the page, in one process, so the session the action writes
     * under is the session this case reads back — see `studioSession()` for why an HTTP
     * round trip would not be.
     */
    $page = Livewire::test(LandingBuilderPage::class);

    $valid = LandingFixtures::fullPage();
    $valid['blocks'][0]['fields']['heading'] = 'A valid edit';
    app(UnsavedPreview::class)->put($tenant, session()->getId(), $pages->validated($tenant, $valid)->toArray());

    /*
     * ⚠ MID-EDIT INVALID IS THE NORMAL CASE, NOT THE EXCEPTION — which is the reason
     * autosave was ruled out in the first place. Muhammad's specification of 2026-11-11:
     * *"do not write the key, do not raise anything user-facing, and do NOT clear the
     * existing key."*
     *
     * Clearing it would degrade the pane to the STORED DRAFT, so the preview would jump
     * BACKWARDS to the last save in the middle of typing — a worse experience than the one
     * this ticket exists to fix, and one that would read as data loss.
     */
    /*
     * ⚠ A REQUIRED FIELD EMPTIED, WHICH IS WHAT MID-EDIT ACTUALLY LOOKS LIKE — and NOT an
     * empty block list, which was this case's first attempt and is a perfectly VALID
     * document (§12.1 has an "empty" state for it). That version passed through the gate,
     * wrote the key and asserted nothing.
     */
    $state = StudioForm::fromDocument(LandingFixtures::fullPage());
    $firstBlock = array_key_first($state['blocks']);
    $state['blocks'][$firstBlock]['data']['heading'] = '';

    $page->fillForm($state)->call('previewChanged');

    $held = app(UnsavedPreview::class)->get($tenant, session()->getId());

    expect($held, 'the invalid state cleared the key, so the pane fell back to the last save')
        ->not->toBeNull();

    expect($held['blocks'][0]['fields']['heading'])->toBe('A valid edit');

    // And nothing user-facing was raised: no notification, no redirect, no error.
    $page->assertHasNoErrors();
});

it('refreshes the key’s life on every render, so the TTL bounds inactivity', function (): void {
    $tenant = test()->actingForTenant(role: TenantRole::Owner);

    [$sid, $carried] = studioSession();

    $pages = app(LandingPages::class);
    $pages->saveDraft($tenant, LandingFixtures::fullPage());

    $edited = LandingFixtures::fullPage();
    $edited['blocks'][0]['fields']['heading'] = 'Still being worked on';
    app(UnsavedPreview::class)->put($tenant, $sid, $pages->validated($tenant, $edited)->toArray());

    $key = 'qrpager:studio:unsaved-preview:'.$tenant.':'.UnsavedPreview::fingerprint($sid);

    // Age it most of the way to expiry, as two minutes of reading would.
    app('redis')->connection()->expire($key, 5);
    expect((int) app('redis')->connection()->ttl($key))->toBeLessThanOrEqual(5);

    /*
     * ⚠ THE RENDER IS WHAT KEEPS IT ALIVE. Without this, the bug is: edit, pause, switch
     * tabs and back — the pane reloads onto an expired key and the preview silently reverts
     * to the last save while the edits are still in the form.
     */
    previewIn($carried)->assertOk();

    expect((int) app('redis')->connection()->ttl($key))->toBeGreaterThan(60);
});

it('keeps two sessions of the same tenant out of each other’s preview', function (): void {
    $tenant = test()->actingForTenant(role: TenantRole::Owner);

    [$sid, $carried] = studioSession();

    $pages = app(LandingPages::class);
    $pages->saveDraft($tenant, LandingFixtures::fullPage());

    $mine = LandingFixtures::fullPage();
    $mine['blocks'][0]['fields']['heading'] = 'What THIS tab is typing';

    $other = LandingFixtures::fullPage();
    $other['blocks'][0]['fields']['heading'] = 'What the OTHER tab is typing';

    $store = app(UnsavedPreview::class);

    $store->put($tenant, $sid, $pages->validated($tenant, $mine)->toArray());
    $store->put($tenant, 'a-second-studio-tab', $pages->validated($tenant, $other)->toArray());

    /*
     * ⚠ SAME TENANT, SAME ROLE, DIFFERENT SESSION. Keyed by tenant alone, the second write
     * would have overwritten the first and this tab's preview would show the other one's
     * in-flight edits — two owners editing at once, or simply two tabs, with nothing on
     * screen to explain it.
     */
    $html = (string) previewIn($carried)->getContent();

    expect($html)->toContain('What THIS tab is typing')
        ->and($html)->not->toContain('What the OTHER tab is typing');

    // And both keys are really there, so the assertion above is not satisfied by absence.
    expect($store->get($tenant, $sid))->not->toBeNull()
        ->and($store->get($tenant, 'a-second-studio-tab'))->not->toBeNull();
});

it('never puts a raw session id in a key', function (): void {
    $sessionId = 'a-very-recognisable-session-identifier';

    $fingerprint = UnsavedPreview::fingerprint($sessionId);

    /*
     * A session id IS the credential in the cookie. Anything that can list the keyspace —
     * a dump, a log, a monitoring tool — would otherwise be reading live session tokens out
     * of key names.
     */
    expect($fingerprint)->not->toContain($sessionId)
        ->and($fingerprint)->toMatch('/^[0-9a-f]{32}$/')
        ->and($fingerprint)->not->toBe(UnsavedPreview::fingerprint($sessionId.'x'));
});

it('keeps the preview’s silence out of the save path, which must still tell the owner', function (): void {
    $tenant = test()->actingForTenant(role: TenantRole::Owner);

    app(LandingPages::class)->saveDraft($tenant, LandingFixtures::fullPage());

    $state = StudioForm::fromDocument(LandingFixtures::fullPage());
    $firstBlock = array_key_first($state['blocks']);
    $state['blocks'][$firstBlock]['data']['heading'] = '';

    /*
     * ⚠ THE SAME INVALID STATE DOWN TWO PATHS, AND THEY MUST DISAGREE.
     *
     * `previewChanged()` swallows it: mid-edit invalid is the normal case there, and a
     * refusal behind every keystroke would bury the owner. `save()` must NOT inherit that —
     * §12 requires the message say what to do, and a Save that silently did nothing is the
     * worst outcome this feature could cause. Muhammad asked for this in one line; it is a
     * case instead, because a line cannot fail.
     */
    Livewire::test(LandingBuilderPage::class)
        ->fillForm($state)
        ->call('previewChanged')
        ->assertHasNoErrors();

    Livewire::test(LandingBuilderPage::class)
        ->fillForm($state)
        ->call('save')
        ->assertHasErrors();

    // And the draft the owner already had is untouched by the refused save.
    expect(app(LandingPages::class)->draft($tenant)->toArray()['blocks'][0]['fields']['heading'])
        ->toBe('Welcome to Olive');
});

/*
 * ================================================================================
 * THE `§11` SECURITY ROUND'S M-3 AND M-4 — two controls that could not fail
 * ================================================================================
 */

it('refuses previewChanged to a principal the panel refuses, and writes nothing', function (): void {
    $tenant = test()->actingForTenant(role: TenantRole::Owner);
    $sid = session()->getId();

    $page = Livewire::test(LandingBuilderPage::class);

    /*
     * ⚠ MEASURED BEFORE THIS CASE EXISTED: deleting `abort_unless(static::canAccess(), 403)`
     * from `previewChanged()` left all 248 Landing tests GREEN. Every case in this file and
     * in `StudioActionsTest` acts as an Owner, so only the passing branch was ever driven —
     * and this is the one action reached by a TIMER rather than a click, over
     * `POST /livewire-<hash>/update`, which runs neither `SetUpPanel` nor Filament's
     * `Authenticate`. That deletion is Task 6's security M-1 verbatim.
     *
     * The principal changes AFTER the component is mounted, which is the shape of the real
     * attack: a snapshot captured from an authorised page, replayed by somebody else.
     *
     * ⚠⚠ AND THIS CASE DOES NOT PROVE THE `abort_unless` LINE, WHICH IS WORTH STATING
     * BECAUSE IT LOOKS LIKE IT DOES. Measured: with that line deleted this case STILL
     * PASSES, because `TenantPanelAccess::tenantId()` two lines down calls the same
     * `membership()` and aborts 403 itself. The two checks are one check, so no test can
     * separate them. What this case does prove — and what nothing proved before it — is
     * that the ACTION refuses such a principal AND writes no key, which is the property
     * that matters: a 403 arriving after the write would have leaked the owner's in-flight
     * edits into a store the refused caller's own session can be keyed to.
     */
    test()->actingForTenant(role: TenantRole::Staff);

    /*
     * ⚠ LIVEWIRE'S OWN ASSERTION AND NOT A `try/catch`. The test harness CATCHES the abort
     * and records it as a response status, so a `catch (Throwable)` here never fires and
     * the first version of this case reported "previewChanged served a refused principal"
     * against a `previewChanged` that had refused correctly. A control that cannot
     * distinguish those two is the thing this case exists to prevent.
     */
    $page->call('previewChanged')->assertForbidden();

    // AND NOTHING WAS WRITTEN. A 403 that had already written the key would leak the
    // owner's in-flight edits into a store the refused caller's session can be keyed to.
    expect(app(UnsavedPreview::class)->get($tenant, $sid))->toBeNull();
});

it('refuses a neighbour’s media through previewChanged itself, not only through the service', function (): void {
    $neighbour = test()->actingForTenant(role: TenantRole::Owner);
    app(MediaLibrary::class)->store($neighbour, 'neighbour-asset.png', ImageFixtures::png());
    $stolen = app(MediaLibrary::class)->all($neighbour)[0]->id;

    $mine = test()->actingForTenant(role: TenantRole::Owner);
    $sid = session()->getId();

    /*
     * ⚠ THE ENTRY POINT AND NOT THE SERVICE. The case above this file already asserts that
     * `LandingPages::validated()` throws for another tenant's media — but nothing drove it
     * through `previewChanged()`, which is the path that carries CLIENT-SUPPLIED state to
     * the renderer. §11.2's bar is *"proven by a test, not by reading the code"*: if
     * `previewChanged` stopped calling `validated()` tomorrow, the case named for that risk
     * could not fail. This one can.
     */
    Livewire::test(LandingBuilderPage::class)
        ->fillForm(StudioForm::fromDocument(LandingFixtures::fullPage($stolen)))
        ->call('previewChanged');

    expect(app(UnsavedPreview::class)->get($mine, $sid))->toBeNull();
});

it('renders no neighbour asset even from a key that already holds one', function (): void {
    $neighbour = test()->actingForTenant(role: TenantRole::Owner);
    app(MediaLibrary::class)->store($neighbour, 'neighbour-asset.png', ImageFixtures::png());
    $stolen = app(MediaLibrary::class)->all($neighbour)[0]->id;

    $mine = test()->actingForTenant(role: TenantRole::Owner);
    [$sid, $carried] = studioSession();

    app(LandingPages::class)->saveDraft($mine, LandingFixtures::fullPage());

    /*
     * ⚠ PLANTED PAST THE WRITER, which is the half the write-side case cannot reach. The
     * read path re-validates through `fromStored()` → `fromInput()` with this tenant's own
     * `TenantMediaReferences`, so a key holding a foreign id must not produce an asset URL
     * for it — it must fall through to the stored draft, defence in depth for a store with
     * no policy behind it.
     */
    app('redis')->connection()->setex(
        'qrpager:studio:unsaved-preview:'.$mine.':'.UnsavedPreview::fingerprint($sid),
        60,
        (string) json_encode(LandingFixtures::fullPage($stolen)),
    );

    $html = (string) previewIn($carried)->getContent();

    expect($html)->not->toContain($stolen)
        ->and($html)->toContain('Welcome to Olive');
});

/*
 * ================================================================================
 * THE `§11` QA ROUND'S TWO HIGHS, AND THE CONTROLS IT FOUND MISSING
 * ================================================================================
 */

it('meters the preview on its own bucket, BEFORE the session is started', function (): void {
    /*
     * ⚠ THIS CASE REPLACES ONE THAT ASSERTED ATTACHMENT AND MISSED A HIGH. The first
     * version read the gathered middleware and checked that `RateLimit:studio-preview` was
     * present and `RateLimit:panel` absent. Both were true — and the `§11` security round
     * of 2026-11-11 (H-1) measured what that arrangement actually did: route middleware is
     * APPENDED and then sorted, and `RateLimit` is not in Laravel's priority list, so the
     * limiter ran LAST. Behind `Authenticate`, an anonymous flood was refused 401 before
     * the limiter existed — completely unmetered — and behind `StartSession`, every one of
     * those requests had already minted a 7200s Redis session key. Task 6's security H-2
     * and `R-027`, re-opened by a fix for a rate-limit finding.
     *
     * So POSITION is the property, and it is asserted against `StartSession` and
     * `Authenticate` by name rather than by index, plus the bucket actually being driven.
     */
    $router = app('router');

    foreach (['filament.studio.preview', 'filament.studio.preview.asset'] as $name) {
        $route = $router->getRoutes()->getByName($name);

        expect($route, "the route {$name} is not registered")->not->toBeNull();

        $middleware = array_values(array_map(
            static fn (string $m): string => explode(':', $m)[0],
            $router->gatherRouteMiddleware($route)
        ));

        $limiter = array_search(RateLimit::class, $middleware, true);
        $session = array_search(StartSession::class, $middleware, true);
        $authenticate = array_search(Authenticate::class, $middleware, true);

        expect($limiter, "no rate limiter on {$name}")->not->toBeFalse();
        expect($session, "no StartSession on {$name}")->not->toBeFalse();
        expect($authenticate, "no Authenticate on {$name}")->not->toBeFalse();

        // An anonymous 401 must not mint a session key, and must still be counted.
        expect($limiter)->toBeLessThan($session, "the limiter runs after StartSession on {$name}");
        expect($limiter)->toBeLessThan($authenticate, "the limiter runs after Authenticate on {$name}");
    }

    // And the preview counts against its OWN bucket, chosen by name in config.
    /*
     * ⚠ FETCHED AS AN ARRAY, NOT THROUGH DOT NOTATION. `config()` reads a dot as nesting,
     * and these keys ARE route names containing dots — `config('…route_buckets.filament.studio.preview')`
     * answers null for a map that holds exactly that key. One more assertion that named a
     * property and queried a proxy for it.
     */
    $overrides = (array) config('ratelimit.route_buckets');

    expect($overrides['filament.studio.preview'] ?? null)->toBe('studio-preview')
        ->and($overrides['filament.studio.preview.asset'] ?? null)->toBe('studio-preview-asset');

    /*
     * ⚠ AND A SECOND LIMITER RUNS LATE, WHICH IS THE OTHER HALF OF §4. The early one
     * cannot meter per tenant — it runs before `Authenticate`, so no tenant has resolved and
     * `keysFor()` skips the tenant key entirely. One instance can satisfy only one half of
     * "per IP AND per tenant" from one position, so the preview carries two.
     */
    foreach ([
        'filament.studio.preview' => 'studio-preview-tenant',
        'filament.studio.preview.asset' => 'studio-preview-asset-tenant',
    ] as $name => $late) {
        $route = $router->getRoutes()->getByName($name);
        $middleware = $router->gatherRouteMiddleware($route);

        expect($middleware)->toContain(RateLimit::class.':'.$late);

        // The EARLY bucket carries no per-tenant limit and the LATE one no per-ip, so
        // neither counts the other's dimension twice.
        $early = (array) config('ratelimit.buckets.'.($overrides[$name] ?? ''));
        $lateBucket = (array) config('ratelimit.buckets.'.$late);

        expect($early)->toHaveKey('per_ip')
            ->and($early)->not->toHaveKey('per_tenant');
        expect($lateBucket)->toHaveKey('per_tenant')
            ->and($lateBucket)->not->toHaveKey('per_ip');
    }
});

it('refuses an anonymous flood on the preview, rather than serving it unmetered', function (): void {
    /*
     * ⚠ DRIVEN, NOT CONFIGURED — `R-063`'s own clause, and the `§11` round's L-1. A bucket
     * whose numbers, keys or REACHABILITY regress passes an attachment check; only a 429
     * can fail. This is deliberately shaped like `PanelRateLimitTest` › *refuses an
     * anonymous flood on a panel route*, because it is the same property on a route that
     * lost it for one commit.
     *
     * The limit is read from config so the loop is bounded by the value rather than by a
     * number written here, and it is narrowed for the test: 900 real requests would make
     * this case cost more than it proves.
     */
    config()->set('ratelimit.buckets.studio-preview.per_ip', ['attempts' => 3, 'seconds' => 60]);

    $limit = (int) config('ratelimit.buckets.studio-preview.per_ip.attempts');

    expect($limit)->toBe(3);

    $statuses = [];

    for ($i = 0; $i < $limit + 2; $i++) {
        $statuses[] = test()->get('/studio/preview')->getStatusCode();
    }

    // The first are 401 — anonymous, refused by Authenticate — and the last are 429,
    // which is only possible if the limiter ran BEFORE Authenticate.
    expect(array_slice($statuses, 0, $limit))->each->toBe(401);
    expect(array_slice($statuses, $limit))->each->toBe(429);
});

it('starts a newly added block with no seeded item, so it is valid on arrival', function (): void {
    /*
     * ⚠ H-1, AND IT IS THE TICKET'S OWN INVARIANT. Filament seeds a `Repeater` with ONE
     * item unless told otherwise, and an item's fields are required at item level — so a
     * block added from the picker was invalid before the owner typed anything, Save was
     * refused, and after this ticket the live preview silently stopped following them.
     *
     * Asserted on the SCHEMA rather than through the browser, because this is where the
     * decision lives; `browser/studio.spec.ts` covers what the owner then sees.
     */
    /*
     * ⚠ READ OFF THE SOURCE, AND THE BEHAVIOUR IS PROVEN IN THE BROWSER. Filament's
     * components cannot be walked outside a mounted container, and the property this
     * changes — what a block looks like when ADDED from the picker — exists only on the
     * UI path, which is why a `Livewire::test()` hydrated from a document could never see
     * the defect in the first place. So this case pins the decision where the decision
     * lives, the way `PanelTargetTest` reads `targets.css`, and
     * `browser/studio.spec.ts` drives what the owner then sees.
     */
    $source = (string) file_get_contents(app_path('Features/Landing/Panel/StudioForm.php'));
    $code = (string) preg_replace('#/\*[\s\S]*?\*/#', ' ', $source);

    expect($code)->toContain('FieldKind::ItemList => Repeater::make($name)')
        ->and($code)->toMatch('/FieldKind::ItemList => Repeater::make\(\$name\)(?s).{0,400}?->defaultItems\(0\)/');
});

it('writes the key when the state is valid, which nothing in PHP proved', function (): void {
    $tenant = test()->actingForTenant(role: TenantRole::Owner);
    $sid = session()->getId();

    app(LandingPages::class)->saveDraft($tenant, LandingFixtures::fullPage());

    /*
     * ⚠ M-3. Deleting `put()` from `previewChanged()` left the whole Landing suite green:
     * the feature's CENTRAL WRITE was guarded only by a ten-minute browser suite with four
     * recorded flake families. Every other case in this file asserts what happens when the
     * write does NOT happen.
     */
    $edited = LandingFixtures::fullPage();
    $edited['blocks'][0]['fields']['heading'] = 'WRITTEN BY previewChanged';

    Livewire::test(LandingBuilderPage::class)
        ->fillForm(StudioForm::fromDocument($edited))
        ->call('previewChanged')
        ->assertDispatched('studio-preview-updated');

    $held = app(UnsavedPreview::class)->get($tenant, $sid);

    /*
     * ⚠ SEARCHED RATHER THAN INDEXED, because `fillForm()` MERGES with the mounted state
     * rather than replacing it — the QA round's L-5 measured the same thing from the other
     * side (a save that came back with 14 blocks, not 7). Asserting `blocks[0]` pinned a
     * position this helper does not promise, and failed on a key that had been written
     * perfectly well.
     */
    expect($held)->not->toBeNull()
        ->and(json_encode($held))->toContain('WRITTEN BY previewChanged');
});

it('tells the pane to refresh on save and on publish, not only while typing', function (): void {
    $tenant = test()->actingForTenant(role: TenantRole::Owner);
    $sid = session()->getId();

    app(LandingPages::class)->saveDraft($tenant, LandingFixtures::fullPage());
    app(UnsavedPreview::class)->put($tenant, $sid, app(LandingPages::class)->draft($tenant)->toArray());

    /*
     * ⚠ M-4. All three `dispatch('studio-preview-updated')` calls could be deleted with the
     * PHP suite green — including `save()`'s, which was one of the three defects this
     * ticket found for itself. And `publish()`'s `forget()` was reached by no test at all:
     * no PHP case called publish and the browser suite never clicks it.
     */
    Livewire::test(LandingBuilderPage::class)
        ->call('save')
        ->assertDispatched('studio-preview-updated');

    expect(app(UnsavedPreview::class)->get($tenant, $sid))->toBeNull();

    // And publish, which until now nothing drove.
    app(UnsavedPreview::class)->put($tenant, $sid, app(LandingPages::class)->draft($tenant)->toArray());

    Livewire::test(LandingBuilderPage::class)
        ->call('publish')
        ->assertDispatched('studio-preview-updated');

    expect(app(UnsavedPreview::class)->get($tenant, $sid))->toBeNull();
});

it('costs the same number of queries however many images are on the page', function (): void {
    $tenant = test()->actingForTenant(role: TenantRole::Owner);

    for ($i = 0; $i < 6; $i++) {
        app(MediaLibrary::class)->store($tenant, "cost-{$i}.png", ImageFixtures::png());
    }

    $ids = array_map(static fn ($asset) => $asset->id, app(MediaLibrary::class)->all($tenant));

    $document = static function (int $images) use ($ids): array {
        $blocks = [];

        for ($i = 0; $i < $images; $i++) {
            $blocks[] = ['type' => 'hero', 'fields' => ['heading' => "H{$i}", 'image' => $ids[$i]]];
        }

        return ['blocks' => $blocks, 'theme' => [], 'seo' => []];
    };

    $cost = function (array $doc) use ($tenant): int {
        app(LandingPages::class)->saveDraft($tenant, $doc);

        $page = Livewire::test(LandingBuilderPage::class)->fillForm(StudioForm::fromDocument($doc));

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $page->call('previewChanged');

        return $queries;
    };

    /*
     * ⚠ THE COST, NOT THE CEILING — Muhammad's ruling of 2026-11-11 on the `§11` QA round's
     * H-2. That High is *"a keystroke costs 2 + N metered requests"*, and the first fix
     * raised the rate limit to fit it: the 429 went away and the cost stayed. Worse, the
     * number was chosen to fit an operation the same round's M-7 showed was UNBOUNDED —
     * `TenantMediaReferences::has()` ran one query per reference, twice per call, behind
     * every keystroke.
     *
     * A limit sized to an unbounded operation is not a limit. So this asserts the shape
     * rather than a threshold: **the query count does not grow with the number of images**.
     * `1200` was a number nothing could falsify; this fails the moment the per-reference
     * query comes back.
     */
    $one = $cost($document(1));
    $six = $cost($document(6));

    expect($six)->toBe(
        $one,
        "previewChanged cost {$one} queries with one image and {$six} with six: the cost grows with the page"
    );

    // A bound as well, so a future change that makes BOTH equally expensive still reddens.
    expect($one)->toBeLessThan(45);
})->group('slow');

it('serves every preview response no-store, which is what makes a reload cost N+1', function (): void {
    $tenant = test()->actingForTenant(role: TenantRole::Owner);

    app(MediaLibrary::class)->store($tenant, 'cacheable.png', ImageFixtures::png());
    $asset = app(MediaLibrary::class)->all($tenant)[0]->id;

    /*
     * ⚠ THIS PINS A COST RATHER THAN A FIX, AND IT IS HERE BECAUSE I NEARLY SHIPPED A DEAD
     * ONE. Chasing the `§11` QA round's H-2 I set `Cache-Control: private, max-age=300` on
     * this response, reasoning that an asset id is immutable so the owner's browser may keep
     * it. Measured, the header came back **`no-store, private`**: `HardenResponse` applies
     * `private, no-store` to every response outside a named allowlist, so the change did
     * nothing. A test asserting only `toContain('private')` would have PASSED on it.
     *
     * So the asset stays `no-store`, the per-fire cost stays 1 + N, and making it cacheable
     * is an allowlist entry in a deliberate security control — `R-289`, for the security
     * round to rule on rather than for this ticket to take. What this case does is keep the
     * COST visible: if the header ever changes, the bucket's arithmetic must change with it.
     */
    $cacheControl = (string) test()
        ->get(route('filament.studio.preview.asset', ['asset' => $asset]))
        ->assertOk()
        ->headers->get('Cache-Control');

    expect($cacheControl)->toContain('no-store');

    $document = (string) test()->get('/studio/preview')->headers->get('Cache-Control');

    expect($document)->toContain('no-store');
});

it('gives the empty item list a visible next action, so H-1 traded no dead end', function (): void {
    $tenant = test()->actingForTenant(role: TenantRole::Owner);

    // A block with an EMPTY item list — exactly what `->defaultItems(0)` now produces when
    // the owner adds one from the picker.
    app(LandingPages::class)->saveDraft($tenant, [
        'blocks' => [['type' => 'how_it_works', 'fields' => ['heading' => 'How it works', 'steps' => []]]],
        'theme' => [],
        'seo' => [],
    ]);

    $html = (string) test()->get('/studio')->getContent();

    /*
     * ⚠ §12: "no empty state without a next action." `defaultItems(0)` fixed a block that
     * was invalid on arrival; it must not have bought that with a dead end — a list showing
     * nothing and offering nothing.
     *
     * ⚠ AND THIS READS THE RENDERED DOM RATHER THAN THE SCHEMA, because `addActionLabel()`
     * being configured is configuration, not evidence. The first probe asserted the label
     * string was present and was WORTHLESS: `أضف` is a substring of `أضف كتلة`, the block
     * picker's own label, so it matched a control in a different part of the page.
     */
    $at = strpos($html, 'fi-fo-repeater-add');

    expect($at, 'the empty item list renders no add control at all')->not->toBeFalse();

    $region = substr($html, (int) $at, 600);

    // A real button, wired to the repeater's own add action — not a label that happens to
    // appear, and not a disabled placeholder.
    expect($region)->toContain('<button')
        ->and($region)->toContain("mountAction('add'");

    /*
     * ⚠ AND IT IS NOT THE HIDDEN ONE. The repeater also renders
     * `fi-fo-repeater-actions fi-hidden` when the list is empty — that is the EXPAND-ALL
     * link, correctly hidden because there is nothing to expand. Measured in the rendered
     * page: `fi-hidden` sits on that container and not on the add control. Without this
     * assertion the case above would pass on a repeater whose add button was hidden too,
     * which is precisely the dead end it exists to refuse.
     */
    expect($region)->not->toContain('fi-hidden');
});

it('refuses a media check with no tenant scope, and refuses to load in the wrong one', function (): void {
    $alpha = test()->actingForTenant(role: TenantRole::Owner);
    app(MediaLibrary::class)->store($alpha, 'alpha.png', ImageFixtures::png());
    $alphaAsset = app(MediaLibrary::class)->all($alpha)[0]->id;

    $beta = test()->actingForTenant(role: TenantRole::Owner);

    /*
     * ⚠ TWO GUARANTEES, AND THE THIRD IS NAMED RATHER THAN PRETENDED — `§11` security
     * round M-1, and Muhammad's instruction of 2026-11-11 to assert the id on every call.
     *
     * Batching this lookup took RLS out of the per-call path: a warm instance answered
     * **true** for alpha's asset inside beta's scope, where a live query would have been
     * refused by the policy. The exact per-call check Muhammad asked for turned out to cost
     * a ROUND TRIP — `TenantContext::current()` reads `app.tenant_id` from the database —
     * and measured, it put the cost back on every reference: 36 queries at one image, 56 at
     * six. A guard that reopens the unboundedness it stands beside is not a guard.
     *
     * A FREE exact check is not available: `TenantContext::$openScopes` is
     * `array<string,int>`, a depth counter per connection, and the PHP-side tenant mirror
     * was deliberately REMOVED — `R-017`. So:
     *
     *   1. NO SCOPE AT ALL → throws, per call, free. Muhammad: *"a queued job or a
     *      boot-time call reaching that class with no tenant context — the one that
     *      matters most."*
     *   2. LOADED IN THE WRONG SCOPE → throws, exactly, once per instance, in `load()`
     *      where a query is happening anyway.
     *   3. WARM INSTANCE REUSED INSIDE A DIFFERENT OPEN SCOPE → not detected. `R-293`
     *      carries it, with `R-017` named as what closing it depends on.
     */
    $warm = withTenant($alpha, function () use ($alpha, $alphaAsset): TenantMediaReferences {
        $references = new TenantMediaReferences($alpha);

        expect($references->has($alphaAsset))->toBeTrue();

        return $references;
    });

    // 1. No scope open at all.
    expect(fn () => $warm->has($alphaAsset))->toThrow(LogicException::class);

    // 2. A cold instance that would populate its memo from the wrong scope.
    withTenant($beta, function () use ($alpha, $alphaAsset): void {
        $wrong = new TenantMediaReferences($alpha);

        expect(fn () => $wrong->has($alphaAsset))->toThrow(LogicException::class);
    });

    // 3. The residual, asserted as the CURRENT behaviour so a future fix reddens here and
    //    the fixer finds R-293 rather than wondering whether this was ever considered.
    withTenant($beta, function () use ($warm, $alphaAsset): void {
        expect($warm->has($alphaAsset))->toBeTrue();
    });
});

it('meters the preview per tenant as well as per ip, from the late limiter', function (): void {
    $tenant = test()->actingForTenant(role: TenantRole::Owner);
    app(LandingPages::class)->saveDraft($tenant, LandingFixtures::fullPage());

    /*
     * ⚠ PER-TENANT, AND ONLY THE LATE LIMITER CAN PRODUCE IT. The early instance's bucket
     * (`studio-preview`) carries no `per_tenant` at all, so a tenant-keyed refusal proves
     * the second limiter ran — after `Authenticate`, where a tenant exists.
     *
     * ⚠ AND THIS CASE CANNOT PROVE THE EARLY ONE METERS PER TENANT, WHICH IS WHY IT DOES
     * NOT TRY. `actingAs()` binds the user directly, so in the SUITE a tenant resolves even
     * at index 1 — measured: a per-tenant limit of 2 refuses the third request on a panel
     * page route in-suite, which is the harness, not production. `R-143` records the real
     * behaviour.
     */
    config()->set('ratelimit.buckets.studio-preview.per_ip', ['attempts' => 999, 'seconds' => 60]);
    config()->set('ratelimit.buckets.studio-preview-tenant.per_tenant', ['attempts' => 2, 'seconds' => 60]);

    $statuses = [];

    for ($i = 0; $i < 4; $i++) {
        $statuses[] = test()->get('/studio/preview')->getStatusCode();
    }

    expect(array_slice($statuses, 0, 2))->each->toBe(200);
    expect(array_slice($statuses, 2))->each->toBe(429);
});

/**
 * Every way a class can become resident, as patterns over source.
 *
 * ⚠ ONE MATCHER, USED BY BOTH THE REAL SCAN AND THE NEGATIVE FIXTURE BELOW. A scanner
 * proven on a different code path from the one it ships with proves the other path —
 * `QRPAGER-42`'s own bar, and the reason `fixtures/panel-theme-literals.css` exists.
 *
 * @return list<string>
 */
function residentHolds(string $source, string $class): array
{
    $found = [];
    $code = (string) preg_replace('#/\*[\s\S]*?\*/|//[^\n]*#', ' ', $source);

    // Container bindings of any lifetime, by class constant or by string.
    foreach (['bind', 'singleton', 'scoped', 'instance', 'bindIf', 'singletonIf'] as $method) {
        if (preg_match('/->'.$method.'\s*\(\s*'.preg_quote($class, '/').'::class/', $code) === 1) {
            $found[] = "container {$method}()";
        }
    }

    /*
     * A static PROPERTY that could hold one between requests.
     *
     * ⚠ THE TYPE MUST SIT IMMEDIATELY BEFORE THE VARIABLE. The first version allowed
     * anything between `static` and the class name, and matched
     * `static fn (): LandingDocument => … new TenantMediaReferences($tenantId)` — a
     * CLOSURE, in two files that are doing exactly the right thing. A scanner that reddens
     * on correct code is `R-290`'s shape and invites someone to delete the scanner.
     */
    if (preg_match('/static\s+(?:readonly\s+)?\??'.preg_quote($class, '/').'\s+\$\w+/', $code) === 1) {
        $found[] = 'static property';
    }

    // Assignment into any static, which covers `self::$x = new TenantMediaReferences(...)`.
    if (preg_match('/(?:self|static)::\$\w+\s*=\s*new\s+'.preg_quote($class, '/').'/', $code) === 1) {
        $found[] = 'assigned to a static';
    }

    return $found;
}

it('never lets the tenant media gate become resident, which is what makes the memo safe', function (): void {
    /*
     * ⚠ THE RESIDUAL FROM `§11`'s M-1, CLOSED AT BUILD TIME RATHER THAN PINNED AS A RUNTIME
     * GAP — Muhammad's ruling of 2026-11-11.
     *
     * Batching `has()` took RLS out of the per-call path, so a WARM instance used inside
     * another tenant's scope answers from memory. Detecting that per call needs the tenant
     * id in PHP, which costs a round trip (`TenantContext::current()` reads the GUC) or a
     * second source of truth that can disagree with it — `R-017`, deliberately not reopened:
     * a cap restated at the read is exactly the failure `R-292` records from the other side.
     *
     * But the hazard is only REACHABLE if an instance becomes resident. Today every one is
     * `new TenantMediaReferences($id)` at a call site inside the matching scope, so it does
     * not exist — it is created the day somebody binds it, holds it statically, or captures
     * it in something that outlives the request. That is a source property, and a source
     * property can fail the BUILD instead of leaking at runtime.
     */
    $class = 'TenantMediaReferences';
    $offenders = [];
    $scanned = 0;

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (! str_contains($source, $class)) {
            continue;
        }

        $scanned++;

        foreach (residentHolds($source, $class) as $how) {
            $offenders[] = str_replace(app_path().'/', '', $file->getPathname()).": {$how}";
        }
    }

    // A bound, so a walk that matched nothing cannot pass by vacancy.
    expect($scanned)->toBeGreaterThan(3, 'the scan found almost no file naming the class');

    expect($offenders, 'the tenant media gate is held somewhere that can outlive a request: '
        .'its memo answers from memory, so a resident instance returns one tenant\'s media '
        .'ids inside another tenant\'s scope')->toBe([]);
});

it('catches a resident hold when one is planted, so the scan above is not vacuous', function (): void {
    // The same matcher the real scan uses, against each way the hazard arrives.
    expect(residentHolds('$this->app->singleton(TenantMediaReferences::class, fn () => new TenantMediaReferences($id));', 'TenantMediaReferences'))
        ->toContain('container singleton()');

    expect(residentHolds('$this->app->scoped(TenantMediaReferences::class, $f);', 'TenantMediaReferences'))
        ->toContain('container scoped()');

    expect(residentHolds('private static ?TenantMediaReferences $cached = null;', 'TenantMediaReferences'))
        ->toContain('static property');

    expect(residentHolds('self::$gate = new TenantMediaReferences($tenantId);', 'TenantMediaReferences'))
        ->toContain('assigned to a static');

    // And it does not fire on the shapes the codebase actually uses — including the
    // static CLOSURE that the first version of the property pattern mistook for a
    // property, in two files that were doing the right thing.
    expect(residentHolds('new TenantMediaReferences($tenantId)', 'TenantMediaReferences'))->toBe([]);

    expect(residentHolds(
        'withTenant($id, static fn (): LandingDocument => LandingDocument::fromStored($u, new TenantMediaReferences($id)));',
        'TenantMediaReferences'
    ))->toBe([]);

    // ...nor on a comment that merely discusses binding it.
    expect(residentHolds('// do not ->singleton(TenantMediaReferences::class) here', 'TenantMediaReferences'))->toBe([]);
});

it('issues no Postgres statement on a refused preview request', function (): void {
    $tenant = test()->actingForTenant(role: TenantRole::Owner);
    app(LandingPages::class)->saveDraft($tenant, LandingFixtures::fullPage());

    config()->set('ratelimit.buckets.studio-preview.per_ip', ['attempts' => 1, 'seconds' => 60]);

    // Consume the one allowed attempt.
    test()->get('/studio/preview');

    /*
     * ⚠ THE `§11` SECURITY ROUND'S M-3, AND IT IS CLOSED BY H-1's FIX RATHER THAN SEPARATELY.
     * While the limiter sat at the END of the stack it ran after `StudioLocale`, so a 429 on
     * this route still cost FOUR statements — a `set_config`, the tenant's language, and two
     * `current_setting` reads — and an attacker past the cap kept making us do that work per
     * request. Measured now, with the limiter back at index 1: **zero**.
     *
     * This is the property `§4 › Edge & ops` asks of load shedding, and the shape
     * `PanelRateLimitTest` › *issues no Postgres statement on an anonymous panel refusal*
     * already holds for `/platform`.
     */
    $statements = 0;

    DB::listen(function () use (&$statements): void {
        $statements++;
    });

    $response = test()->get('/studio/preview');

    expect($response->getStatusCode())->toBe(429)
        ->and($statements)->toBe(0, 'a refused preview still touches the database');
});

it('makes the preview agree with the draft after a save on the THEME tab', function (): void {
    $tenant = test()->actingForTenant(role: TenantRole::Owner);
    $sid = session()->getId();

    $pages = app(LandingPages::class);
    $pages->saveDraft($tenant, LandingFixtures::fullPage());

    /*
     * ⚠ THE REPORTED SYMPTOM, REPRODUCED BEFORE IT WAS FIXED. Muhammad: *"a colour change in
     * the theme tab does not reach the preview even after Save. It only appears when he
     * clicks «بسيط» or «مميّز» on the builder page."*
     *
     * The owner has been editing on the BUILDER tab, so a key exists for this session. Then
     * they switch to `الثيم`, change a colour and press Save. `ThemePage::save()` wrote the
     * draft and never forgot the key, so the stale key kept winning and the pane showed
     * something that CONTRADICTED the database — clearable only by waiting out the TTL or
     * by touching the arrangement control, whose write rebuilds the whole document from one
     * flat state and carried the new theme in by accident.
     *
     * ⚠ ASSERTED AT THE STORE RATHER THAN THROUGH THE PANE, and that is the harness and not
     * a weaker claim: `Livewire::test()` runs in the test process, whose session is not the
     * one `test()->get()` carries — see `studioSession()`. In a browser they are one
     * session, which is what `browser/studio.spec.ts` drives. Here the contract itself is
     * the thing to assert: after a save, no unsaved state survives to contradict the draft.
     */
    app(UnsavedPreview::class)->put($tenant, $sid, $pages->draft($tenant)->toArray());

    $document = $pages->draft($tenant)->toArray();
    $document['theme']['color.accent'] = '#abcdef';

    Livewire::test(ThemePage::class)
        ->fillForm(StudioForm::fromDocument($document))
        ->call('save')
        ->assertDispatched('studio-preview-updated');

    // The database took the colour...
    expect($pages->draft($tenant)->toArray()['theme']['color.accent'])->toBe('#abcdef');

    // ...and nothing is left to disagree with it.
    expect(app(UnsavedPreview::class)->get($tenant, $sid))->toBeNull();
});

it('writes the preview key from the THEME tab too, not only from the builder', function (): void {
    $tenant = test()->actingForTenant(role: TenantRole::Owner);
    $sid = session()->getId();

    $pages = app(LandingPages::class);
    $pages->saveDraft($tenant, LandingFixtures::fullPage());

    /*
     * The first clause of the amended invariant: *"on EVERY studio page, a settled change is
     * reflected in the preview with no Save click."* `previewChanged()` lived on the builder
     * page alone, so editing a colour moved nothing until it was saved.
     */
    $document = $pages->draft($tenant)->toArray();
    $document['theme']['color.accent'] = '#123456';

    Livewire::test(ThemePage::class)
        ->fillForm(StudioForm::fromDocument($document))
        ->call('previewChanged')
        ->assertDispatched('studio-preview-updated');

    $held = app(UnsavedPreview::class)->get($tenant, $sid);

    expect($held)->not->toBeNull()
        ->and($held['theme']['color.accent'] ?? null)->toBe('#123456');

    // ⚠ AND THE OTHER TAB'S WORK SURVIVED IT — `merged()`, not `getState()`. A theme write
    // that carried an empty `blocks` is the trap `StudioDraftMergeTest` exists for, one
    // route further on: the owner would watch their page empty as they picked a colour.
    expect($held['blocks'])->not->toBeEmpty();
});
