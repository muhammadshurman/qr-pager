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
    expect($this->get('/studio/preview')->status())->not->toBe(200);
    expect($this->get('/studio/preview/asset/'.Str::uuid7())->status())->not->toBe(200);
});

it('refuses the preview to a staff member and to a platform admin', function (): void {
    $this->actingForTenant(role: TenantRole::Staff);
    expect($this->get('/studio/preview')->status())->not->toBe(200);

    $this->actingAs(LandingFixtures::platformAdmin());
    expect($this->get('/studio/preview')->status())->not->toBe(200);
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

it('stops being reachable again after an unpublish', function (): void {
    $tenant = $this->makeTenant(slug: 'taken-down');
    $pages = new LandingPages;

    $pages->saveDraft($tenant, LandingFixtures::fullPage());
    $published = $pages->publish($tenant);

    expect(MediaLibrary::disk()->exists(PublishedSite::pointer($published->hostname)))->toBeTrue();

    $pages->unpublish($tenant);

    expect(MediaLibrary::disk()->exists(PublishedSite::pointer($published->hostname)))->toBeFalse();
});
