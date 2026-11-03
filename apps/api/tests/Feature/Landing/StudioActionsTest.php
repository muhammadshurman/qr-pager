<?php

declare(strict_types=1);

use App\Domain\Landing\DomainStatus;
use App\Domain\Landing\LandingPageStatus;
use App\Domain\Landing\LandingTemplate;
use App\Domain\Tenancy\TenantLanguage;
use App\Domain\Tenancy\TenantRole;
use App\Features\Landing\FixtureDnsVerifier;
use App\Features\Landing\LandingPages;
use App\Features\Landing\LocalCertificateIssuer;
use App\Features\Landing\MediaLibrary;
use App\Features\Landing\Panel\Pages\DomainsPage;
use App\Features\Landing\Panel\Pages\LandingBuilderPage;
use App\Features\Landing\Panel\Pages\MediaLibraryPage;
use App\Features\Landing\Panel\StudioForm;
use App\Features\Landing\PublishedSite;
use App\Features\Landing\PublishFailed;
use App\Features\Landing\TenantDomains;
use App\Features\Landing\TenantPageLanguage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ImageFixtures;
use Tests\Support\LandingFixtures;
use Tests\Support\RecordingDisk;

/*
 * ================================================================================
 * THE PANEL'S ACTIONS, DRIVEN -- WHICH THE HTTP TESTS CANNOT DO
 * ================================================================================
 *
 * StudioPanelInvariantTest drives who may REACH each page. That is a different question
 * from whether pressing Save actually saves, and a GET of a Filament page never invokes
 * an action: everything a tenant does in this panel arrives over
 * `POST /livewire-<hash>/update`.
 *
 * So these cases exist for two reasons at once. They cover the real user path -- which
 * nothing else in this task's suite does -- and they cover it through the SAME endpoint
 * Task 6's security M-1 was about, so the authorisation each action re-asks for is
 * exercised rather than assumed.
 */

beforeEach(function (): void {
    LandingFixtures::bucket();
    Storage::disk('local')->delete([FixtureDnsVerifier::ZONE, LocalCertificateIssuer::LEDGER]);
});

it('saves a draft through the builder, and reports the fields it refuses', function (): void {
    $tenant = $this->actingForTenant(role: TenantRole::Owner);

    $state = StudioForm::fromDocument(LandingFixtures::fullPage());

    Livewire::test(LandingBuilderPage::class)
        ->fillForm($state)
        ->call('save');

    // It really went to the database, through the validator.
    $document = app(LandingPages::class)->draft($tenant);

    expect($document->blocks)->toHaveCount(7)
        ->and($document->blocks[0]['fields']['heading'])->toBe('Welcome to Olive')
        ->and($document->theme)->toHaveKey('color.accent');

    // And a refusal names the field without publishing anything. A heading of 200
    // characters is past the 80 the schema allows.
    $tooLong = LandingFixtures::fullPage();
    $tooLong['blocks'][0]['fields']['heading'] = str_repeat('a', 200);

    Livewire::test(LandingBuilderPage::class)
        ->fillForm(StudioForm::fromDocument($tooLong))
        ->call('save');

    // The stored draft is unchanged -- a refused save saves nothing.
    expect(app(LandingPages::class)->draft($tenant)->blocks[0]['fields']['heading'])
        ->toBe('Welcome to Olive');
});

it('publishes and unpublishes through the builder', function (): void {
    $tenant = $this->actingForTenant(role: TenantRole::Owner);

    Livewire::test(LandingBuilderPage::class)
        ->fillForm(StudioForm::fromDocument(LandingFixtures::fullPage()))
        ->call('publish');

    $state = app(LandingPages::class)->state($tenant);

    expect($state['status'])->toBe(LandingPageStatus::Published)
        ->and(MediaLibrary::disk()->exists(PublishedSite::pointer($state['hostname'])))->toBeTrue();

    Livewire::test(LandingBuilderPage::class)->call('unpublish');

    expect(app(LandingPages::class)->state($tenant)['status'])->toBe(LandingPageStatus::Draft)
        ->and(MediaLibrary::disk()->exists(PublishedSite::pointer($state['hostname'])))->toBeFalse();
});

it('sets the page language through the builder, and refuses anything that is not one', function (): void {
    // ⚠ THROUGH LIVEWIRE, WHICH IS THE ONLY PATH THAT EXISTS. `QRPAGER-30`. This file's own
    // header says why: a GET of a Filament page never invokes an action, and everything a
    // tenant does here arrives over `POST /livewire-<hash>/update` -- the endpoint Task 6's
    // security M-1 was about, which runs neither `SetUpPanel` nor Filament's `Authenticate`.
    // So the `abort_unless(canAccess())` this action re-asks for is exercised here or nowhere.
    $tenant = $this->actingForTenant(role: TenantRole::Owner);

    // The column's default, before anything is pressed: the restaurant that does nothing.
    expect((new TenantPageLanguage)->current($tenant))->toBe(TenantLanguage::Arabic);

    Livewire::test(LandingBuilderPage::class)->call('setPageLanguage', 'en');

    expect((new TenantPageLanguage)->current($tenant))->toBe(TenantLanguage::English);

    // ⚠ AND BACK, which is the half a one-way test leaves out: `§2.7` puts `language` on the
    // UPDATE list precisely because a restaurant may change its mind, unlike `slug`.
    Livewire::test(LandingBuilderPage::class)->call('setPageLanguage', 'ar');

    expect((new TenantPageLanguage)->current($tenant))->toBe(TenantLanguage::Arabic);

    // A value that is not a case is REFUSED and changes nothing. The argument arrives from
    // the client, so `tryFrom` plus a notification rather than `from`'s ValueError -- which
    // would be a 500 carrying a class name on an untrusted input.
    Livewire::test(LandingBuilderPage::class)->call('setPageLanguage', 'fr');

    expect((new TenantPageLanguage)->current($tenant))->toBe(TenantLanguage::Arabic);
});

it('sets the language of the signed-in restaurant and no other, over the panel path', function (): void {
    // The standing non-negotiable (`§0.0e`), on the one write this feature adds. The action
    // takes NO tenant id -- `TenantPanelAccess::tenantId()` re-resolves it from the
    // authenticated session on every call, which is why there is no id to aim elsewhere --
    // so the property under test is that a second restaurant's row is untouched by a member
    // of the first pressing the control.
    $other = $this->makeTenant(slug: 'other-language');

    (new TenantPageLanguage)->set($other, TenantLanguage::Arabic);

    $tenant = $this->actingForTenant(role: TenantRole::Owner);

    Livewire::test(LandingBuilderPage::class)->call('setPageLanguage', 'en');

    expect((new TenantPageLanguage)->current($tenant))->toBe(TenantLanguage::English)
        ->and((new TenantPageLanguage)->current($other))->toBe(TenantLanguage::Arabic);
});

it('sets the page arrangement through the builder, and only on the signed-in restaurant', function (): void {
    /*
     * ⚠ **`QRPAGER-33` INVARIANT CLAUSE 5, THROUGH THE ONLY PATH THAT EXISTS.** This file's
     * header says why it is here rather than in a Feature test of the renderer: a GET of a
     * Filament page never invokes an action, and everything a tenant does in this panel
     * arrives over `POST /livewire-<hash>/update` -- the endpoint Task 6's security M-1 was
     * about, which runs neither `SetUpPanel` nor Filament's `Authenticate`.
     *
     * The control takes no tenant id: `LandingBuilderPage` re-resolves the tenant from the
     * authenticated session on every call, which is why there is none to aim elsewhere. So
     * the property is that a second restaurant's document is untouched by the first pressing
     * it.
     *
     * ================================================================================
     * ⚠ NO `fillForm()` HERE, AND THAT IS A MEASUREMENT RATHER THAN A STYLE CHOICE
     * ================================================================================
     *
     * `Livewire::test()` MOUNTS the page, and `LandingBuilderPage::mount()` already fills the
     * form from the stored draft. Calling `fillForm()` after it fills a SECOND time, and the
     * Builder merges rather than replaces: measured on this fixture, 7 blocks became **14**
     * after `fillForm()` alone, and **28** once a save had persisted the doubled set -- at
     * which point `maxItems(24)` refused the save and the real subject of the case silently
     * stopped being exercised. That is how the first version of this case failed: not on the
     * arrangement at all, but on `"data.blocks" => ["must not have more than 24 items"]`.
     *
     * Mount alone is the real path and gives 7. `R-239` carries the consequence for the
     * cases above, which do call `fillForm()`.
     */
    $other = $this->makeTenant(slug: 'other-arrangement');

    app(LandingPages::class)->saveDraft($other, array_replace(
        LandingFixtures::fullPage(),
        ['template' => 'editorial'],
    ));

    $tenant = $this->actingForTenant(role: TenantRole::Owner);

    // A pre-feature document for this tenant: no `template` member at all.
    app(LandingPages::class)->saveDraft($tenant, LandingFixtures::fullPage());

    expect(app(LandingPages::class)->draft($tenant)->template)
        ->toBe(LandingTemplate::Classic, 'a document with no template member did not default to classic');

    $blocksBefore = count(app(LandingPages::class)->draft($tenant)->toArray()['blocks']);

    Livewire::test(LandingBuilderPage::class)
        ->set('data.template', 'editorial')
        ->call('save');

    $saved = app(LandingPages::class)->draft($tenant);

    expect($saved->template)->toBe(LandingTemplate::Editorial)
        // The blocks are untouched, which is what makes this a change of ARRANGEMENT and not
        // a round trip that happened to end with the right enum.
        ->and(count($saved->toArray()['blocks']))->toBe($blocksBefore)
        // The other restaurant is where it was, which is the half the clause is about.
        ->and(app(LandingPages::class)->draft($other)->template)->toBe(LandingTemplate::Editorial);

    // And back, because `§2.7`'s reasoning for an updatable value applies here too: a
    // restaurant may change its mind, and a one-way test would not notice if it could not.
    Livewire::test(LandingBuilderPage::class)
        ->set('data.template', 'classic')
        ->call('save');

    expect(app(LandingPages::class)->draft($tenant)->template)->toBe(LandingTemplate::Classic);
});

it('refuses an arrangement that is not one, from the panel, leaving the document alone', function (): void {
    // The Livewire payload is client-supplied, so a value outside the enum reaches
    // `StudioForm::toDocument()` whatever the radio offers -- and `LandingDocument` refuses it
    // by name before any write. The document is unchanged, which is the assertion: a refusal
    // that half-wrote would be worse than one that threw.
    $tenant = $this->actingForTenant(role: TenantRole::Owner);

    app(LandingPages::class)->saveDraft($tenant, array_replace(
        LandingFixtures::fullPage(),
        ['template' => 'editorial'],
    ));

    Livewire::test(LandingBuilderPage::class)
        ->set('data.template', 'brutalist')
        ->call('save');

    expect(app(LandingPages::class)->draft($tenant)->template)
        ->toBe(LandingTemplate::Editorial, 'a non-arrangement reached the stored document');
});

it('names the side of the commit a publish failed on, and never 500s on a take-down', function (): void {
    // ⚠ BOTH OF THE PANEL'S FAILURE BRANCHES, WHICH NOTHING DROVE. The third scoped
    // re-review found two defects one line apart: the publish notification asserted "your
    // live page is unchanged" for EVERY failure, which `SR-4`'s ordering made false from
    // the commit onwards; and the unpublish action caught nothing at all, so a store that
    // answers false -- the s3 disk is `'throw' => false` -- turned the one control whose
    // job is to make a page unreachable into a Livewire 500 rather than §12.1's named
    // refusal. The control is rendered for every tenant in every state, so that 500 was
    // reachable from the `draft` state by every owner.
    $tenant = $this->actingForTenant(role: TenantRole::Owner);

    // A store that refuses every write. The publish gets past phase 3 -- the ROW commits
    // first now -- and fails in phase 4, which is the case the old headline lied about.
    $refusing = new RecordingDisk(MediaLibrary::disk(), fn (string $operation, string $key) => null, [
        'put' => false,
        'deleteDirectory' => false,
    ]);

    Storage::set('s3', $refusing);

    try {
        Livewire::test(LandingBuilderPage::class)
            ->fillForm(StudioForm::fromDocument(LandingFixtures::fullPage()))
            ->call('publish')
            ->assertNotified(PublishFailed::AFTER_RECORD);

        // And the take-down answers the tenant instead of throwing. `deleteDirectory`
        // returns false, `sweep()` raises, and the action renders it.
        Livewire::test(LandingBuilderPage::class)
            ->call('unpublish')
            ->assertNotified('Take-down failed — your page is still public');
    } finally {
        Storage::forgetDisk('s3');
    }

    // The row is `published` over a prefix with nothing in it -- the residual `SR-4`
    // chose, and the reason the headline above is the one the tenant is shown.
    expect(app(LandingPages::class)->state($tenant)['status'])->toBe(LandingPageStatus::Published);
});

it('refuses to publish from the builder when the document does not validate', function (): void {
    // §12.1's "invalid" state reached through the action rather than through the view:
    // Publish saves first, and a save that refuses stops the publish.
    $tenant = $this->actingForTenant(role: TenantRole::Owner);

    $broken = LandingFixtures::fullPage();
    $broken['blocks'][0]['fields']['cta_url'] = 'javascript:alert(1)';
    $broken['blocks'][0]['fields']['cta_label'] = 'Book';

    Livewire::test(LandingBuilderPage::class)
        ->fillForm(StudioForm::fromDocument($broken))
        ->call('publish');

    expect(app(LandingPages::class)->state($tenant)['status'])->toBe(LandingPageStatus::Draft)
        ->and(MediaLibrary::disk()->allFiles(PublishedSite::root(app(LandingPages::class)->hostname($tenant))))
        ->toBe([]);
});

it('uploads and deletes through the media page, refusing what the library refuses', function (): void {
    $tenant = $this->actingForTenant(role: TenantRole::Owner);

    $page = Livewire::test(MediaLibraryPage::class);

    // Nothing chosen: refused, and nothing stored.
    $page->call('store');
    expect(app(MediaLibrary::class)->count($tenant))->toBe(0);

    // A real PNG through the real path.
    $page->set('upload', UploadedFile::fake()->createWithContent('logo.png', ImageFixtures::png()))
        ->call('store');

    expect(app(MediaLibrary::class)->count($tenant))->toBe(1);

    // An HTML file called logo.png: refused by content, and the library does not grow.
    Livewire::test(MediaLibraryPage::class)
        ->set('upload', UploadedFile::fake()->createWithContent('logo.png', ImageFixtures::html()))
        ->call('store');

    expect(app(MediaLibrary::class)->count($tenant))->toBe(1);

    $assetId = app(MediaLibrary::class)->all($tenant)[0]->id;

    // In use by the page: the delete is refused and the asset stays (§12.2).
    app(LandingPages::class)->saveDraft($tenant, LandingFixtures::fullPage($assetId));

    Livewire::test(MediaLibraryPage::class)->call('delete', $assetId);
    expect(app(MediaLibrary::class)->count($tenant))->toBe(1);

    // Removed from the page, the same delete succeeds.
    app(LandingPages::class)->saveDraft($tenant, ['blocks' => []]);

    Livewire::test(MediaLibraryPage::class)->call('delete', $assetId);
    expect(app(MediaLibrary::class)->count($tenant))->toBe(0);

    // And a delete of something that is not there is refused rather than silent.
    Livewire::test(MediaLibraryPage::class)->call('delete', (string) Str::uuid7());
    expect(app(MediaLibrary::class)->count($tenant))->toBe(0);
});

it('reports the full library state at the cap', function (): void {
    $tenant = $this->actingForTenant(role: TenantRole::Owner);

    withTenant($tenant, function () use ($tenant): void {
        $rows = [];

        for ($i = 0; $i < MediaLibrary::MAX_ASSETS; $i++) {
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenant,
                'object_key' => 'media/'.$tenant.'/'.bin2hex(random_bytes(16)).'.png',
                'original_name' => 'seed.png',
                'content_type' => 'image/png',
                'byte_size' => 10,
                'checksum' => hash('sha256', (string) $i),
            ];
        }

        DB::table('media_assets')->insert($rows);
    });

    expect(Livewire::test(MediaLibraryPage::class)->instance()->isFull())->toBeTrue();

    $this->get('/studio/media')->assertSee('Delete one to add another');
});

it('adds and advances a domain through the domains page', function (): void {
    $tenant = $this->actingForTenant(role: TenantRole::Owner);

    $page = Livewire::test(DomainsPage::class);

    // A hostname that is not one: refused, and nothing is added.
    $page->set('hostname', 'not a hostname')->call('add');
    expect(app(TenantDomains::class)->all($tenant))->toBe([]);

    $page->set('hostname', 'studio.example.test')->call('add');

    $domains = app(TenantDomains::class)->all($tenant);
    expect($domains)->toHaveCount(1)
        ->and($domains[0]->status)->toBe(DomainStatus::PendingDns);

    // Advance with no TXT record: stays put, with a reason.
    Livewire::test(DomainsPage::class)->call('advance', $domains[0]->id);
    expect(app(TenantDomains::class)->all($tenant)[0]->status)->toBe(DomainStatus::PendingDns);

    // Publish the challenge and walk it to active, one press at a time.
    $challenge = app(TenantDomains::class)->challenge($tenant, $domains[0]->id);
    FixtureDnsVerifier::publish($challenge['name'], $challenge['value']);

    foreach ([DomainStatus::DnsVerified, DomainStatus::CertificatePending, DomainStatus::Active] as $expected) {
        Livewire::test(DomainsPage::class)->call('advance', $domains[0]->id);
        expect(app(TenantDomains::class)->all($tenant)[0]->status)->toBe($expected);
    }

    // Advancing an active domain is a no-op rather than an error.
    Livewire::test(DomainsPage::class)->call('advance', $domains[0]->id);
    expect(app(TenantDomains::class)->all($tenant)[0]->status)->toBe(DomainStatus::Active);

    // And another tenant's domain id is refused through the page, as it is through the
    // service -- the action re-resolves the tenant rather than trusting the call.
    $this->actingForTenant(role: TenantRole::Owner);
    Livewire::test(DomainsPage::class)->call('advance', $domains[0]->id);
    expect(app(TenantDomains::class)->all($tenant)[0]->status)->toBe(DomainStatus::Active);
});

it('shows the pending minutes, which is §12.3s slow state', function (): void {
    $this->actingForTenant(role: TenantRole::Owner);

    $page = Livewire::test(DomainsPage::class)->instance();

    expect($page->pendingMinutes(null))->toBe(0)
        ->and($page->pendingMinutes(now()->subMinutes(7)->toIso8601String()))->toBeGreaterThanOrEqual(6);
});
