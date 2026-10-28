<?php

declare(strict_types=1);

use App\Domain\Landing\LandingPageStatus;
use App\Features\Landing\LandingPages;
use App\Features\Landing\MediaLibrary;
use App\Features\Landing\PublishedSite;
use App\Features\Landing\PublishFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ImageFixtures;
use Tests\Support\LandingFixtures;

/*
 * §3 row 7, clause 1: "publish produces a statically rendered, CDN-cached page".
 *
 * The object exists, its bytes ARE the rendered document, and its ContentType and
 * Cache-Control are read back OUT OF THE STORE rather than asserted against the string
 * that was passed in -- measured through headObject, because what matters is what the
 * service kept.
 *
 * ⚠ WHAT THIS DOES NOT PROVE, stated here rather than left implied: that a CDN honours
 * the header. That is Task 13's, and §6.5 says so. What is this task's is the object's
 * stored metadata, which is the part the application controls.
 */

beforeEach(fn () => LandingFixtures::bucket());

function pages(): LandingPages
{
    return new LandingPages;
}

/**
 * The object's STORED metadata, read back through headObject.
 *
 * Named objectMetadata() and not head(), which is what the first version called it --
 * Laravel ships a global `head()` and the suite died with "Cannot redeclare function
 * head()". GuardFixtures' own docblock records the identical collision with Pest's
 * `fixture()`, which is why both of these live in a class and this one has a long name.
 */
function objectMetadata(string $key): array
{
    return MediaLibrary::disk()->getClient()->headObject([
        'Bucket' => (string) config('filesystems.disks.s3.bucket'),
        'Key' => $key,
    ])->toArray();
}

it('writes a static page whose bytes are the document, with the cache policy on the object', function (): void {
    $tenant = $this->makeTenant(slug: 'olive');
    $asset = (new MediaLibrary)->store($tenant, 'room.png', ImageFixtures::png());

    pages()->saveDraft($tenant, LandingFixtures::fullPage($asset));
    $published = pages()->publish($tenant);

    expect($published->hostname)->toBe('olive.'.config('landing.platform_domain'));

    $pointer = PublishedSite::pointer($published->hostname);
    $snapshot = PublishedSite::snapshot($published->hostname, $published->checksum);

    // The object exists, and the snapshot and the pointer are the SAME bytes.
    $bytes = MediaLibrary::disk()->get($pointer);

    expect($bytes)->toBeString()
        ->and(hash('sha256', (string) $bytes))->toBe($published->checksum)
        ->and(MediaLibrary::disk()->get($snapshot))->toBe($bytes);

    // The page really is the document.
    expect($bytes)->toContain('Welcome to Olive')
        ->and($bytes)->toContain('Fish &amp; Chips, since 1998')
        ->and($bytes)->toContain('Find us')
        ->and($bytes)->toContain('olive.'.config('landing.platform_domain'));

    // Read back out of the store, not out of the call that wrote it.
    foreach ([$pointer, $snapshot] as $key) {
        $metadata = objectMetadata($key);

        expect($metadata['ContentType'])->toBe('text/html; charset=utf-8')
            ->and($metadata['CacheControl'])->toBe(config('landing.cache_control'));
    }
});

it('copies every referenced asset into the site prefix and never serves from the library', function (): void {
    // Decision D3. `media/` is private; a published page serves a COPY under
    // `sites/{hostname}/assets/`, so "public read" is one explicit prefix for the CDN to
    // decide about in Terraform rather than an ACL per object (ADR-004, guard rule 10).
    $tenant = $this->makeTenant(slug: 'copies');
    $asset = (new MediaLibrary)->store($tenant, 'room.png', ImageFixtures::png());

    $privateKey = withTenant($tenant, fn () => DB::table('media_assets')->where('id', $asset)->value('object_key'));

    pages()->saveDraft($tenant, LandingFixtures::fullPage($asset));
    $published = pages()->publish($tenant);

    $copy = PublishedSite::asset($published->hostname, (string) $privateKey);

    expect(MediaLibrary::disk()->exists($copy))->toBeTrue()
        ->and(MediaLibrary::disk()->get($copy))->toBe(ImageFixtures::png());

    // The HTML points at the COPY and names the private prefix nowhere.
    $html = (string) MediaLibrary::disk()->get(PublishedSite::pointer($published->hostname));

    expect($html)->toContain('/assets/'.basename((string) $privateKey))
        ->and($html)->not->toContain('media/'.$tenant);

    // The copy carries the cache policy, which it inherits from the source object --
    // measured, because a server-side copy carries the SOURCE's metadata and Flysystem
    // exposes no way to replace it.
    expect(objectMetadata($copy)['CacheControl'])->toBe(config('landing.cache_control'));
});

it('is idempotent: the same document publishes to the same checksum', function (): void {
    // The checksum is what the snapshot is named by, so a renderer whose output moved
    // between runs would litter the store with near-identical pages and make the
    // "unchanged since publish" state meaningless.
    $tenant = $this->makeTenant(slug: 'stable');
    $asset = (new MediaLibrary)->store($tenant, 'room.png', ImageFixtures::png());

    pages()->saveDraft($tenant, LandingFixtures::fullPage($asset));

    expect(pages()->publish($tenant)->checksum)->toBe(pages()->publish($tenant)->checksum);
});

it('records the publish on the row, and reports unpublished changes afterwards', function (): void {
    $tenant = $this->makeTenant(slug: 'states');

    pages()->saveDraft($tenant, LandingFixtures::fullPage());
    $published = pages()->publish($tenant);

    $state = pages()->state($tenant);

    expect($state['status'])->toBe(LandingPageStatus::Published)
        ->and($state['published_hostname'])->toBe($published->hostname)
        ->and($state['has_unpublished_changes'])->toBeFalse()
        ->and($state['invalid'])->toBeNull();

    // §12.1's "draft with unpublished changes" state.
    $changed = LandingFixtures::fullPage();
    $changed['blocks'][0]['fields']['heading'] = 'Welcome to Olive, now with chips';
    pages()->saveDraft($tenant, $changed);

    expect(pages()->state($tenant)['has_unpublished_changes'])->toBeTrue();

    // And saving the SAME document back does not count as a change. Compared as the
    // canonical encoding of each document, because Postgres does not preserve key order
    // in jsonb and a textual comparison would report a change that is not one.
    pages()->saveDraft($tenant, LandingFixtures::fullPage());
    expect(pages()->state($tenant)['has_unpublished_changes'])->toBeFalse();
});

it('refuses to publish an empty page, and writes nothing', function (): void {
    $tenant = $this->makeTenant(slug: 'empty');

    pages()->saveDraft($tenant, ['blocks' => []]);

    expect(fn () => pages()->publish($tenant))
        ->toThrow(PublishFailed::class, 'no blocks yet');

    expect(MediaLibrary::disk()->exists(PublishedSite::pointer('empty.'.config('landing.platform_domain'))))
        ->toBeFalse();
});

it('refuses to publish when a referenced image has been deleted, and leaves the live page alone', function (): void {
    // §6.6: "publish fails mid-asset-copy -> fail-closed". This is the same clause one
    // step earlier -- the asset is gone before the copy is attempted -- and it is the
    // reason a STORED draft is re-validated instead of trusted.
    $tenant = $this->makeTenant(slug: 'deleted');
    $library = new MediaLibrary;
    $asset = $library->store($tenant, 'room.png', ImageFixtures::png());

    pages()->saveDraft($tenant, LandingFixtures::fullPage($asset));
    $first = pages()->publish($tenant);

    // Deleting it through the library is refused while the page uses it (§12.2), which
    // is the control working -- so the row is removed underneath, which is the state a
    // concurrent delete or a repair script could leave.
    withTenant($tenant, fn () => DB::table('media_assets')->where('id', $asset)->delete());

    expect(fn () => pages()->publish($tenant))->toThrow(PublishFailed::class);

    // THE LIVE PAGE IS UNCHANGED, which is the half of §12.1's copy that matters:
    // "Publish failed — your live page is unchanged."
    $live = (string) MediaLibrary::disk()->get(PublishedSite::pointer($first->hostname));

    expect(hash('sha256', $live))->toBe($first->checksum);

    // And the row still describes the publish that is actually live.
    expect(pages()->state($tenant)['status'])->toBe(LandingPageStatus::Published);
});

it('leaves the live page serving when the object store goes away mid-publish', function (): void {
    // §6.6: "object store unreachable -> fail-closed; the publish throws, the panel shows
    // the failure, nothing is half-published." Proven by TAKING THE DEPENDENCY DOWN,
    // which is what §4 › Edge & ops asks of a declared posture.
    $tenant = $this->makeTenant(slug: 'outage');

    pages()->saveDraft($tenant, LandingFixtures::fullPage());
    $first = pages()->publish($tenant);

    $changed = LandingFixtures::fullPage();
    $changed['blocks'][0]['fields']['heading'] = 'A heading that must never go live';
    pages()->saveDraft($tenant, $changed);

    $endpoint = config('filesystems.disks.s3.endpoint');
    config()->set('filesystems.disks.s3.endpoint', 'http://127.0.0.1:9');
    Storage::forgetDisk('s3');

    try {
        expect(fn () => pages()->publish($tenant))->toThrow(PublishFailed::class);
    } finally {
        config()->set('filesystems.disks.s3.endpoint', $endpoint);
        Storage::forgetDisk('s3');
    }

    $live = (string) MediaLibrary::disk()->get(PublishedSite::pointer($first->hostname));

    expect(hash('sha256', $live))->toBe($first->checksum)
        ->and($live)->not->toContain('must never go live');

    // The DATABASE is on the previous publish too -- the row is updated last of all, so
    // a failure anywhere before it rolls back with the transaction.
    $row = withTenant($tenant, fn () => DB::table('landing_pages')->where('tenant_id', $tenant)->first());

    expect($row->published_checksum)->toBe($first->checksum);
});

it('writes a manifest naming what is live', function (): void {
    $tenant = $this->makeTenant(slug: 'manifest');
    $asset = (new MediaLibrary)->store($tenant, 'room.png', ImageFixtures::png());

    pages()->saveDraft($tenant, LandingFixtures::fullPage($asset));
    $published = pages()->publish($tenant);

    $manifest = json_decode(
        (string) MediaLibrary::disk()->get(PublishedSite::manifest($published->hostname)),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($manifest['hostname'])->toBe($published->hostname)
        ->and($manifest['checksum'])->toBe($published->checksum)
        ->and($manifest['bytes'])->toBe($published->byteSize)
        ->and($manifest['assets'])->toHaveCount(1);
});

it('takes the page down on unpublish, leaving the snapshot unreachable rather than deleted', function (): void {
    $tenant = $this->makeTenant(slug: 'down');

    pages()->saveDraft($tenant, LandingFixtures::fullPage());
    $published = pages()->publish($tenant);

    pages()->unpublish($tenant);

    // The POINTER is gone, which is what makes the page unreachable -- the CDN maps Host
    // to the prefix and serves index.html.
    expect(MediaLibrary::disk()->exists(PublishedSite::pointer($published->hostname)))->toBeFalse()
        // The snapshot stays: it is immutable, nobody can reach it without the pointer,
        // and deleting it would make an unpublish unrecoverable.
        ->and(MediaLibrary::disk()->exists(PublishedSite::snapshot($published->hostname, $published->checksum)))
        ->toBeTrue();

    $state = pages()->state($tenant);

    expect($state['status'])->toBe(LandingPageStatus::Draft)
        ->and($state['published_hostname'])->toBeNull();
});

it('publishes to the active custom domain once there is one', function (): void {
    $tenant = $this->makeTenant(slug: 'custom');
    $now = now()->toIso8601String();

    // ⚠ ON THE OWNER CONNECTION, and the first version of this case did not -- it inserted
    // as qrpager_app and got 42501. That is the §2.7 grant working: the app role may
    // INSERT only (id, tenant_id, hostname, status, verification_token, created_at,
    // updated_at), because a domain is always created with nothing earned and every
    // lifecycle column arrives through an UPDATE the transition table and the CHECKs
    // both bound. A fixture that sets up an ACTIVE domain is doing something the
    // application deliberately cannot do in one statement.
    withTenant($tenant, fn () => DB::connection('pgsql_owner')->table('tenant_domains')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant,
        'hostname' => 'olive.example.test',
        'verification_token' => bin2hex(random_bytes(16)),
        'status' => 'active',
        'dns_verified_at' => $now,
        'certificate_identifier' => 'cert-local-1',
        'certificate_issued_at' => $now,
        'activated_at' => $now,
    ]), 'pgsql_owner');

    pages()->saveDraft($tenant, LandingFixtures::fullPage());

    expect(pages()->publish($tenant)->hostname)->toBe('olive.example.test');
});
