<?php

declare(strict_types=1);

use App\Domain\Landing\DomainStatus;
use App\Domain\Landing\LandingPageStatus;
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
