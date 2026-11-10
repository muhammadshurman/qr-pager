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
use Illuminate\Http\UploadedFile;
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
