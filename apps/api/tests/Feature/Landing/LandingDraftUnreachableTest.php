<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use App\Features\Landing\LandingPages;
use App\Features\Landing\MediaLibrary;
use App\Features\Landing\PublishedSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ImageFixtures;
use Tests\Support\LandingFixtures;

/*
 * ================================================================================
 * §3 row 7, clause 2 — "an unpublished draft is not reachable"
 * ================================================================================
 *
 * The FIRST part of this task's named invariant, and the one a preview route could make
 * false on its own. "Not reachable" is three separate claims and they have three
 * different mechanisms, so they are tested separately:
 *
 *   nothing is in the store      -- a publish is the only thing that writes under
 *                                   `sites/`, so an unpublished page has no bytes there
 *                                   to find, by any key, guessed or not
 *   the preview refuses          -- same guard as the panel, and it takes NO INPUT: no
 *                                   tenant parameter, no page id, no token
 *   nothing may cache or index it -- `no-store` and `X-Robots-Tag: noindex`
 */

beforeEach(fn () => LandingFixtures::bucket());

it('writes nothing to the store for a page that has never been published', function (): void {
    $tenant = $this->makeTenant(slug: 'unpublished');
    $pages = new LandingPages;

    $pages->saveDraft($tenant, LandingFixtures::fullPage());

    $hostname = $pages->hostname($tenant);

    // Not just the pointer: the WHOLE PREFIX is empty. A snapshot or a stray asset under
    // `sites/{hostname}/` would be reachable to anyone who guessed its name, and the
    // claim is about the draft being unreachable rather than about one key being absent.
    expect(MediaLibrary::disk()->allFiles(PublishedSite::root($hostname)))->toBe([])
        ->and(MediaLibrary::disk()->exists(PublishedSite::pointer($hostname)))->toBeFalse()
        ->and(MediaLibrary::disk()->exists(PublishedSite::manifest($hostname)))->toBeFalse();

    // And saving the draft again does not start writing one.
    $pages->saveDraft($tenant, LandingFixtures::fullPage());

    expect(MediaLibrary::disk()->allFiles(PublishedSite::root($hostname)))->toBe([]);
});

it('writes nothing to the store when a PREVIEW is rendered', function (): void {
    // A preview that wrote an object would make the draft reachable at a URL afterwards,
    // which is the clause failing by a side effect rather than by a route.
    $tenant = $this->actingForTenant(role: TenantRole::Owner);
    $pages = new LandingPages;

    $pages->saveDraft($tenant, LandingFixtures::fullPage());
    $hostname = $pages->hostname($tenant);

    $this->get('/studio/preview')->assertOk();

    expect(MediaLibrary::disk()->allFiles(PublishedSite::root($hostname)))->toBe([]);
});

it('refuses the preview to an anonymous caller', function (): void {
    // EXACT, not `not->toBe(200)`: an inequality is satisfied by a 404 from a guard that
    // should not be there, which is how `upload-file`'s refusal survived this suite for
    // the whole of Task 7 (`R-116`, `§4a`). 401 is Filament's Authenticate, with no
    // session to decide anything else from.
    expect($this->get('/studio/preview')->status())->toBe(401)
        ->and($this->get('/studio/preview/asset/'.Str::uuid7())->status())->toBe(401);
});

it('refuses the preview to a staff member and to a platform admin', function (): void {
    // 403 for both, and the difference from the 401 above is the point: these two ARE
    // authenticated, and it is `canAccessPanel()` that closes the panel to them -- the
    // seniority rule for staff (D1) and SR-1 for the platform admin.
    $this->actingForTenant(role: TenantRole::Staff);
    expect($this->get('/studio/preview')->status())->toBe(403);

    $this->actingAs(LandingFixtures::platformAdmin());
    expect($this->get('/studio/preview')->status())->toBe(403);
});

it('shows one tenant nothing of another tenants draft, with no parameter to tamper with', function (): void {
    $alpha = $this->actingForTenant(role: TenantRole::Owner);

    (new LandingPages)->saveDraft($alpha, [
        'blocks' => [['type' => 'hero', 'fields' => ['heading' => 'ALPHA DRAFT ONLY']]],
    ]);

    // The preview route takes NO INPUT -- no tenant, no id, no token -- so the only way
    // to ask for another tenant's draft is to be somebody else, and being somebody else
    // answers with their own page.
    $this->actingForTenant(role: TenantRole::Owner);

    $response = $this->get('/studio/preview');

    expect($response->status())->toBe(200)
        ->and($response->getContent())->not->toContain('ALPHA DRAFT ONLY');
});

it('serves no-store and noindex on the preview and on its assets', function (): void {
    // Decision D2. A cached or indexed preview is a reachable draft even when the route
    // is not.
    $tenant = $this->actingForTenant(role: TenantRole::Owner);
    $asset = (new MediaLibrary)->store($tenant, 'logo.png', ImageFixtures::png());

    (new LandingPages)->saveDraft($tenant, LandingFixtures::fullPage($asset));

    $response = $this->get('/studio/preview');

    expect($response->status())->toBe(200)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer');

    $assetResponse = $this->get('/studio/preview/asset/'.$asset);

    expect($assetResponse->status())->toBe(200)
        ->and($assetResponse->headers->get('Cache-Control'))->toContain('no-store')
        ->and($assetResponse->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow')
        ->and($assetResponse->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($assetResponse->headers->get('Content-Security-Policy'))->toContain('sandbox')
        ->and($assetResponse->headers->get('Content-Type'))->toBe('image/png');
});

it('answers 404 when one tenant asks the preview for another tenants asset', function (): void {
    $alpha = $this->actingForTenant(role: TenantRole::Owner);
    $alphaAsset = (new MediaLibrary)->store($alpha, 'alpha.png', ImageFixtures::png());

    expect($this->get('/studio/preview/asset/'.$alphaAsset)->status())->toBe(200);

    $this->actingForTenant(role: TenantRole::Owner);

    // 404 and not 403: another tenant's asset is INDISTINGUISHABLE from one that does not
    // exist, which is §11.2's IDOR rule.
    expect($this->get('/studio/preview/asset/'.$alphaAsset)->status())->toBe(404)
        ->and($this->get('/studio/preview/asset/'.Str::uuid7())->status())->toBe(404);
});

it('refuses to preview a draft that no longer validates, rather than rendering part of it', function (): void {
    // A partial render would show the tenant a page that cannot be published, and would
    // mean the renderer had a second, laxer path through it.
    $tenant = $this->actingForTenant(role: TenantRole::Owner);
    $asset = (new MediaLibrary)->store($tenant, 'logo.png', ImageFixtures::png());

    (new LandingPages)->saveDraft($tenant, LandingFixtures::fullPage($asset));

    // The asset goes away underneath the stored draft, which is the state a repair
    // script or a concurrent delete could leave.
    withTenant($tenant, fn () => DB::table('media_assets')->delete());

    $response = $this->get('/studio/preview');

    expect($response->status())->toBe(422)
        ->and($response->getContent())->toContain("can't be published")
        ->and($response->getContent())->not->toContain('Welcome to Olive');
});

it('leaves NOTHING fetchable under the hostname after an unpublish', function (): void {
    // ⚠ THIS CASE USED TO ASSERT ONLY THAT THE POINTER WAS GONE, and that was the first
    // clause of the named invariant failing by a second route. `unpublish()` deleted
    // `index.html` and left `manifest.json` -- whose body carries the checksum -- and
    // `{checksum}.html`, both in `sites/{hostname}/`, which IS the prefix the CDN maps
    // Host onto. So `GET /manifest.json` handed you the name of the page the tenant had
    // just taken down, and `GET /{checksum}.html` served it in full.
    //
    // The old comment beside it said the snapshot was "unreachable without the pointer".
    // The manifest IS a pointer. Found by the §11 security review, 2026-10-28.
    $tenant = $this->makeTenant(slug: 'taken-down');
    $pages = new LandingPages;
    $asset = (new MediaLibrary)->store($tenant, 'room.png', ImageFixtures::png());

    $pages->saveDraft($tenant, LandingFixtures::fullPage($asset));
    $published = $pages->publish($tenant);

    $root = PublishedSite::root($published->hostname);

    expect(MediaLibrary::disk()->exists(PublishedSite::pointer($published->hostname)))->toBeTrue()
        ->and(MediaLibrary::disk()->exists(PublishedSite::manifest($published->hostname)))->toBeTrue()
        ->and(MediaLibrary::disk()->allFiles($root))->not->toBe([]);

    $pages->unpublish($tenant);

    // THE WHOLE PREFIX, not one key. Anything left here is served by the CDN to anyone
    // who asks for its name, and the manifest is how they learn the names.
    expect(MediaLibrary::disk()->allFiles($root))->toBe([])
        ->and(MediaLibrary::disk()->exists(PublishedSite::pointer($published->hostname)))->toBeFalse()
        ->and(MediaLibrary::disk()->exists(PublishedSite::manifest($published->hostname)))->toBeFalse()
        ->and(MediaLibrary::disk()->exists(PublishedSite::snapshot($published->hostname, $published->checksum)))
        ->toBeFalse();

    // The private library is untouched: an unpublish takes the SITE down and does not
    // delete the tenant's images.
    expect((new MediaLibrary)->count($tenant))->toBe(1);

    // And re-publishing restores it, which is what makes deleting the snapshot safe:
    // the draft is still there, so the same bytes are regenerated.
    $again = $pages->publish($tenant);

    expect($again->checksum)->toBe($published->checksum)
        ->and(MediaLibrary::disk()->exists(PublishedSite::pointer($again->hostname)))->toBeTrue();
});
