<?php

declare(strict_types=1);

use App\Domain\Landing\LandingDocumentInvalid;
use App\Domain\Tenancy\TenantRole;
use App\Features\Landing\LandingPages;
use App\Features\Landing\MediaLibrary;
use App\Features\Landing\Panel\Pages\LandingBuilderPage;
use App\Features\Landing\Panel\Pages\MediaLibraryPage;
use App\Features\Landing\Panel\StudioForm;
use App\Features\Landing\UnsavedPreview;
use App\Platform\Http\Middleware\RateLimit;
use Filament\Forms\Components\Repeater;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Http\UploadedFile;
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

it('meters the preview on its own bucket, not on the one sized for a person clicking', function (): void {
    /*
     * ⚠ H-2. After `QRPAGER-45` one debounce fire is a Livewire update PLUS the preview
     * document PLUS one asset request per media reference — up to 24 — all `no-store`, so
     * the browser refetches every one on every reload. On the `panel` bucket (240/min per
     * IP, whose own comment says it is sized for "a human clicking through an admin
     * panel") a ten-image page burns the whole budget in about eleven seconds and the
     * owner is then 429'd out of the page they are editing.
     *
     * Read off the ROUTER rather than from a second copy of the list, which is the shape
     * `ReviewFixesTest` already uses for `Authenticate` on these same two routes.
     */
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array($route->getName(), [
            'filament.studio.preview',
            'filament.studio.preview.asset',
        ], true));

    expect($routes)->toHaveCount(2, 'the preview routes are not registered under the names this asserts');

    foreach ($routes as $route) {
        /*
         * ⚠ `gatherRouteMiddleware()` AND NOT `$route->gatherMiddleware()`. The latter
         * returns what is ATTACHED and ignores `withoutMiddleware()` exclusions entirely,
         * so the first version of this case reported the panel bucket still present on a
         * route `php artisan route:list` already showed without it. It would have failed
         * forever on a correct fix — which is the opposite of a vacuous assertion and just
         * as useless.
         */
        $middleware = app('router')->gatherRouteMiddleware($route);

        expect($middleware)->toContain(RateLimit::class.':studio-preview')
            ->and($middleware)->not->toContain(RateLimit::class.':panel');

        // ⚠ AND THE AUTH LAYER SURVIVED THE SWAP. `withoutMiddleware()` is a blunt tool and
        // these two routes were single-layered once already (the §11 security review of
        // 2026-10-28); losing `Authenticate` here would open the draft with the suite green.
        expect($middleware)->toContain(Authenticate::class);
    }

    // The bucket is real and meters BOTH halves — §4 › Edge & ops' "per IP AND per tenant".
    expect(config('ratelimit.buckets.studio-preview.per_ip'))->not->toBeNull()
        ->and(config('ratelimit.buckets.studio-preview.per_tenant'))->not->toBeNull();
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
