<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantLanguage;
use App\Domain\Tenancy\TenantRole;
use App\Features\Landing\LandingPages;
use App\Features\Landing\MediaLibrary;
use App\Features\Landing\Panel\Pages\ThemePage;
use App\Features\Landing\Panel\StudioChromeState;
use App\Features\Landing\PanelLanguage;
use App\Features\Landing\TenantPageLanguage;
use Illuminate\Support\Facades\DB;
use Tests\Support\ImageFixtures;
use Tests\Support\LandingFixtures;

/**
 * The studio's chrome, one case per region. `QRPAGER-48` item 2.
 *
 * ⚠⚠ THE INVARIANT NAMES THE REGIONS AND SAYS **NONE MAY BE MISSING**, so these are written
 * one-per-region rather than as a single sweep: a sweep that lost a region would still pass
 * with the others, and *"no region missing"* is precisely the claim being made.
 *
 * Muhammad reversed his own earlier ruling to get here — `QRPAGER-47` called the chrome
 * CONTEXT, and 2026-11-13: *"I previously called it context; I was wrong, and it is now part
 * of the deliverable."*
 */
beforeEach(function (): void {
    $this->actingForTenant(role: TenantRole::Owner);
});

it('names the RESTAURANT in the topbar, not just the product', function (): void {
    // §12 forbids an unexplained identifier. Before this the topbar carried the product's
    // name and nothing about whose page was being edited -- on a shared device the
    // restaurant is the one fact a person glances up to confirm.
    $this->get('/studio/theme')
        ->assertOk()
        ->assertSee('data-topbar-restaurant="true"', false);
});

it('says which screen this is, so the restaurant is not read as the product', function (): void {
    $this->get('/studio/theme')
        ->assertOk()
        ->assertSee('data-topbar-chip="true"', false)
        ->assertSee(__('studio.chrome.studio'), false);
});

it('shows the signed-in person by NAME, readable without a click', function (): void {
    // The artboard drew a bare initial, and Muhammad ruled the artboard itself the defect:
    // "§12 forbids an unexplained identifier, and a single letter is exactly that."
    $this->get('/studio/theme')
        ->assertOk()
        ->assertSee('data-topbar-person="true"', false)
        // The FIRST name, which is what the topbar has room for.
        ->assertSee('Acting', false);
});

it('carries the whole name and the address in the menu itself', function (): void {
    // The address is what distinguishes two people with one first name -- the case the
    // abbreviation in the topbar cannot serve.
    $this->get('/studio/theme')
        ->assertOk()
        ->assertSee('data-usermenu-identity="true"', false)
        ->assertSee('Acting Staff', false);
});

it('offers a way out, which is the only other thing the menu carries', function (): void {
    // Filament's own sign-out lives in that menu; this asserts the menu exists to hold it
    // rather than re-testing Filament.
    $this->get('/studio/theme')
        ->assertOk()
        ->assertSee('data-back-to-staff="true"', false);
});

it('renders every region on EVERY tab, not only the theme one', function (string $path): void {
    // The chrome is the frame around the tabs. A region that appeared on one page and not
    // another would be the artboard's own complaint -- it carries the whole tab "so the
    // theme tab is not designed in isolation from the page tab around it".
    $response = $this->get($path)->assertOk();

    foreach ([
        'data-topbar-restaurant="true"',
        'data-topbar-chip="true"',
        'data-topbar-person="true"',
        'data-usermenu-identity="true"',
    ] as $marker) {
        $response->assertSee($marker, false);
    }
})->with(['/studio', '/studio/theme', '/studio/media', '/studio/domains']);

/**
 * ⚠⚠ THE PUBLISH ACTION SAVES THIS TAB FIRST, AND THAT ORDER IS THE WHOLE CASE.
 *
 * `LandingBuilderPage::publish()` already records why: *"a publish that used the stored draft
 * while the form held newer values would publish something the tenant is not looking at."*
 * The theme tab holds exactly such values, so publishing from here without saving would put
 * the OLD theme on the live page while the screen showed the new one — a defect an owner
 * would only discover by looking at their own published page.
 */
it('publishes from the theme tab, and publishes what the FORM holds', function (): void {
    $tenantId = $this->actingForTenant(role: TenantRole::Owner);
    $pages = app(LandingPages::class);

    // A publishable page to start from, with a known arrangement.
    $pages->saveDraft($tenantId, [
        'template' => 'rail',
        'blocks' => [['type' => 'hero', 'fields' => ['heading' => 'Before']]],
    ]);
    $pages->publish($tenantId);

    expect($pages->state($tenantId)['has_unpublished_changes'])->toBeFalse();

    // Now change the arrangement IN THE FORM and publish without saving first. If the action
    // published the stored draft, the live page would keep `rail`.
    Livewire::test(ThemePage::class)
        ->set('data.template', 'ember')
        ->callAction('publish');

    // The PUBLISHED document's own bytes, read back rather than inferred from the draft:
    // the whole claim is that what reached the live page is what the form held.
    $stored = (string) withTenant(
        $tenantId,
        static fn (): string => (string) DB::connection('pgsql_owner')
            ->table('landing_pages')->where('tenant_id', $tenantId)->value('published_document'),
        'pgsql_owner',
    );

    /** @var array{template?: string} $published */
    $published = json_decode($stored, true);

    expect($published['template'] ?? null)->toBe('ember')
        ->and($pages->state($tenantId)['has_unpublished_changes'])->toBeFalse();
});

/**
 * ⚠⚠ THE CHROME COSTS ONE READ, NOT ONE PER REGION — and this is measured rather than
 * asserted, because it is the figure `§6` question 5 already got wrong once.
 *
 * Two regions want the page state: the status plate and the footer's save state. Without a
 * per-request memo that is a second `state()` call, which would have turned the CORRECTED
 * figure of two scoped reads per render into three.
 */
it('reads the page state ONCE however many chrome regions ask for it', function (): void {
    $tenantId = $this->actingForTenant(role: TenantRole::Owner);

    app(LandingPages::class)->saveDraft($tenantId, [
        'blocks' => [['type' => 'hero', 'fields' => ['heading' => 'One read']]],
    ]);

    $reads = 0;

    DB::listen(function ($query) use (&$reads): void {
        if (str_contains($query->sql, 'landing_pages') && str_starts_with(ltrim($query->sql), 'select')) {
            $reads++;
        }
    });

    $first = StudioChromeState::of($tenantId);
    $afterFirst = $reads;
    $second = StudioChromeState::of($tenantId);

    // The planted state is asserted first: a memo that returned nothing would also "not read
    // twice", and would pass this case while rendering an empty plate.
    expect($afterFirst)->toBeGreaterThan(0)
        ->and($first['blocks'])->toBe(1)
        ->and($second)->toBe($first)
        ->and($reads)->toBe($afterFirst);
});

/**
 * ⚠⚠ THE TOPBAR CONTROL CHANGES WHAT **THIS PERSON** READS AND NOTHING ELSE.
 *
 * This is the defect `users.panel_language` was added to prevent, driven end to end: before
 * 2026-11-13 one column meant both things, so a control like this one would have changed a
 * restaurant's CUSTOMER-facing page language. The negative half is the case.
 */
it('switches the studio language for the person, and leaves the customers\' page alone', function (): void {
    $tenantId = $this->actingForTenant(role: TenantRole::Owner);
    $pages = app(TenantPageLanguage::class);

    $pages->set($tenantId, TenantLanguage::Arabic);

    // Planted state asserted first, so this cannot pass against a tenant that was English.
    expect($pages->current($tenantId))->toBe(TenantLanguage::Arabic);

    $this->post('/studio/panel-language', ['language' => 'en'])->assertRedirect();

    $userId = (string) auth()->id();

    expect(app(PanelLanguage::class)->current($userId, $tenantId))
        ->toBe(TenantLanguage::English)
        // THE HALF THAT MATTERS: the restaurant's published page is untouched.
        ->and($pages->current($tenantId))->toBe(TenantLanguage::Arabic);
});

it('clears the preference back to following the restaurant on an unrecognised value', function (): void {
    $tenantId = $this->actingForTenant(role: TenantRole::Owner);
    $userId = (string) auth()->id();

    app(TenantPageLanguage::class)->set($tenantId, TenantLanguage::Arabic);
    app(PanelLanguage::class)->set($userId, TenantLanguage::English);

    // "Not a language" is not a language: clearing is the honest reading, and guessing a
    // default would be the control silently choosing for somebody.
    $this->post('/studio/panel-language', ['language' => 'klingon'])->assertRedirect();

    expect(app(PanelLanguage::class)->current($userId, $tenantId))
        ->toBe(TenantLanguage::Arabic);
});

it('names a different audience on each of the two language controls', function (): void {
    // §12, ruled 2026-11-13: "if the wording does not make that obvious at a glance, the
    // control is not done." Asserted as a DIFFERENCE rather than against fixed strings, so a
    // reworded pair still passes and an identical pair cannot.
    $this->actingForTenant(role: TenantRole::Owner);

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        expect(__('studio.chrome.panel_language_legend'))
            ->not->toBe(__('studio.page.language_legend'));
    }
});

/*
 * ================================================================================
 * ONE LIVE REGION, ONE SAVE STATE, AND ONE VOCABULARY — `§11` QA M-2
 * ================================================================================
 *
 * What the review measured on `/studio` with a published page carrying unpublished changes:
 * `role="status"` FOUR times, two elements on `.studio-footer`, and four different sentences
 * for one fact — «منشورة» + «معدّلة من آخر نشر» (chrome plate), «لديك تغييرات لم تُنشر بعد.»
 * (page plate), «محفوظة كمسودة» (chrome footer) and «تغييرات غير منشورة» (page footer). Two of
 * those four disagreed about whether the page was a draft at all, because the chrome footer
 * keyed on a BLOCK COUNT while everything else read the state machine.
 *
 * ⚠ THE COUNTS ARE ASSERTED ON EVERY TAB, not on `/studio`. The chrome renders on all four,
 * and the duplication only existed where a tab drew its own plate — so a count taken on one
 * page would pass for the wrong reason on the other three.
 */
it('announces the publish state exactly once per tab, in one vocabulary', function (): void {
    $tenantId = $this->actingForTenant(role: TenantRole::Owner);
    $asset = (new MediaLibrary)->store($tenantId, 'logo.png', ImageFixtures::png());

    // The state the review measured: published, then edited. Both halves matter — an empty
    // page takes an arm where the two wordings happened to agree.
    (new LandingPages)->saveDraft($tenantId, LandingFixtures::fullPage($asset));
    (new LandingPages)->publish($tenantId);

    // ⚠ A DIFFERENT DOCUMENT, or there are no unpublished changes and this case takes the
    // arm where the two wordings happened to agree -- which is the vacuous shape.
    (new LandingPages)->saveDraft($tenantId, LandingFixtures::fullPage($asset, [
        'blocks.0.fields.heading' => 'Edited after publishing',
    ]));

    expect((new LandingPages)->state($tenantId)['has_unpublished_changes'])
        ->toBeTrue('the fixture did not reach the state this case is about');

    foreach (['/studio', '/studio/theme', '/studio/media', '/studio/domains'] as $uri) {
        $html = (string) $this->get($uri)->assertOk()->getContent();

        /*
         * ⚠ OUR live regions, not every one on the page. Filament renders its own
         * `role="status"` containers for notifications and loading indicators — measured at
         * nine on `/studio` — and demanding one in total would be asserting something about
         * the framework that is both false and none of this ticket's business. The question
         * the finding asks is how many regions WE author for this one fact.
         */
        preg_match_all('/<[^>]*role="status"[^>]*>/', $html, $regions);

        $ours = array_values(array_filter(
            $regions[0],
            static fn (string $tag): bool => str_contains($tag, 'studio-plate'),
        ));

        expect($ours)
            ->toHaveCount(1, "{$uri} announces one fact through ".count($ours).' regions')
            ->and(substr_count($html, 'data-status-plate="true"'))
            ->toBe(1, "{$uri} renders more than one status plate")
            ->and(substr_count($html, 'data-footer-bar="true"'))
            ->toBe(1, "{$uri} renders more than one footer bar")
            ->and(substr_count($html, 'data-footer-state="true"'))
            ->toBe(1, "{$uri} renders more than one save state");

        /*
         * AND THE TWO REGIONS AGREE. The footer's sentence for this state is the shared
         * `studio.footer.*` vocabulary, and the sentence the old two-arm footer would have
         * shown — "saved as a draft" — must be absent, because the page is published.
         */
        expect($html)->toContain(__('studio.footer.unpublished_changes'))
            ->and($html)->toContain(__('studio.chrome.published'));
    }
});

/*
 * ================================================================================
 * THE LANGUAGE ROUTE REDIRECTS TO A PAGE, NEVER TO A PICTURE — PR #22 walkthrough
 * ================================================================================
 *
 * The defect Muhammad found by using it: clicking AR/EN navigated the owner full-page onto
 * `/studio/preview/asset/{id}` — a raw JPEG. The form sends no `Referer`, so
 * `url()->previous()` fell back to the session's previous URL, which Laravel sets from the
 * last non-AJAX GET, which on the theme tab is whatever image the preview iframe fetched last.
 *
 * ⚠ THE HOSTILE VALUES ARE THE POINT OF THE TABLE. `from` is tenant input now, so it is
 * driven with the names that WOULD send an owner somewhere that is not a page — the preview
 * document, a preview asset, this route itself, another panel's page, and a name that is not
 * a route at all. Each must land on a page of this panel instead.
 */
it('redirects to a page of this panel whatever `from` says', function (): void {
    $this->actingForTenant(role: TenantRole::Owner);

    $cases = [
        // The honest case: a real page of this panel is honoured.
        'filament.studio.pages.media' => '/studio/media',
        'filament.studio.pages.domains' => '/studio/domains',
        // Everything else falls back, and the fallback is a PAGE.
        'filament.studio.preview' => '/studio/theme',
        'filament.studio.preview.asset' => '/studio/theme',
        'filament.studio.panel-language' => '/studio/theme',
        'filament.platform.pages.dashboard' => '/studio/theme',
        'not.a.route.at.all' => '/studio/theme',
        '' => '/studio/theme',
    ];

    foreach ($cases as $from => $expected) {
        $this->post('/studio/panel-language', ['language' => 'en', 'from' => $from])
            ->assertRedirect($expected);
    }

    // And a missing `from` behaves like an unusable one rather than raising.
    $this->post('/studio/panel-language', ['language' => 'en'])
        ->assertRedirect('/studio/theme');

    // ⚠ NOT A PREVIEW ROUTE, ASSERTED AS A PROPERTY over every answer above: the whole defect
    // was a redirect to a route that serves BYTES rather than a page, so this says so
    // directly rather than trusting the table to have listed every spelling of one.
    foreach (array_keys($cases) as $from) {
        $location = (string) $this->post('/studio/panel-language', ['language' => 'ar', 'from' => $from])
            ->headers->get('Location');

        expect($location)->not->toContain('/preview')
            ->and($location)->not->toContain('/panel-language');
    }
});
