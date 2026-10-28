<?php

declare(strict_types=1);

use App\Features\Landing\InspectedImage;
use App\Features\Landing\MediaLibrary;
use App\Features\Landing\MediaRejected;
use App\Features\Landing\SvgPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ImageFixtures;

/*
 * §3 row 7: "an upload is validated by real content type and size, not by extension".
 *
 * ⚠ THESE RUN AGAINST THE REAL COMPOSED OBJECT STORE, never Storage::fake(). §6 is
 * unconditional -- "no DB mocks for DB behaviour" -- and ADR-004 extends it to this
 * service in the same words: a test that proves a stand-in works must round-trip through
 * the production client library. LocalObjectStoreTest is what fails if the service is
 * absent; these fail with it.
 */

beforeEach(function (): void {
    $bucket = (string) config('filesystems.disks.s3.bucket');
    $client = MediaLibrary::disk()->getClient();

    if (! $client->doesBucketExist($bucket)) {
        $client->createBucket(['Bucket' => $bucket]);
    }
});

function library(): MediaLibrary
{
    return new MediaLibrary;
}

/** The MediaRejected a closure throws, or null. */
function rejection(callable $callback): ?MediaRejected
{
    try {
        $callback();

        return null;
    } catch (MediaRejected $e) {
        return $e;
    }
}

it('refuses an HTML file called logo.png, by content and not by name', function (): void {
    // §3 row 7's own example, and the whole clause in one case: the name says PNG, the
    // bytes say HTML, and the bytes win.
    $tenant = $this->makeTenant();

    $refusal = rejection(fn () => library()->store($tenant, 'logo.png', ImageFixtures::html()));

    expect($refusal?->reason)->toContain('text/html')
        ->and($refusal?->reason)->toContain('not one of the five image types');

    // Nothing was written, in either place.
    expect(withTenant($tenant, fn () => DB::table('media_assets')->count()))->toBe(0);
});

it('accepts each of the five types and refuses a real image that is not one of them', function (): void {
    $tenant = $this->makeTenant();

    foreach ([
        'png' => ImageFixtures::png(),
        'jpg' => ImageFixtures::jpeg(),
        'webp' => ImageFixtures::webp(),
        'avif' => ImageFixtures::avif(),
        'svg' => ImageFixtures::svg(),
    ] as $extension => $bytes) {
        $id = library()->store($tenant, 'upload', $bytes);

        $row = withTenant($tenant, fn () => DB::table('media_assets')->where('id', $id)->first());

        expect($row)->not->toBeNull()
            ->and($row->object_key)->toEndWith('.'.$extension)
            ->and($row->checksum)->toBe(hash('sha256', $bytes))
            ->and($row->byte_size)->toBe(strlen($bytes));

        // The bytes really are in the store, read back through the production driver.
        expect(library()->bytes($tenant, $id))->toBe($bytes);
    }

    // A GIF is a real, decodable image and is still refused: the allow-list is five
    // types, not "anything libmagic calls an image".
    expect(rejection(fn () => library()->store($tenant, 'x.png', ImageFixtures::gif()))?->reason)
        ->toContain('image/gif');
});

it('derives the stored extension from the detected type, never from the filename', function (): void {
    // The filename says .jpg and the bytes are a PNG. The key must say .png, because the
    // key's extension is what a CDN infers a served Content-Type from.
    $tenant = $this->makeTenant();

    $id = library()->store($tenant, 'actually-a-png.jpg', ImageFixtures::png());

    $row = withTenant($tenant, fn () => DB::table('media_assets')->where('id', $id)->first());

    expect($row->object_key)->toEndWith('.png')
        ->and($row->content_type)->toBe('image/png')
        ->and($row->original_name)->toBe('actually-a-png.jpg');
});

it('writes a key under this tenants prefix with a 128-bit random leaf', function (): void {
    $tenant = $this->makeTenant();

    $keys = [];

    for ($i = 0; $i < 5; $i++) {
        $id = library()->store($tenant, 'x.png', ImageFixtures::png());
        $keys[] = withTenant($tenant, fn () => DB::table('media_assets')->where('id', $id)->value('object_key'));
    }

    foreach ($keys as $key) {
        expect($key)->toMatch('#^media/'.preg_quote($tenant, '#').'/[0-9a-f]{32}\.png$#');
    }

    // Five uploads of the SAME bytes produce five different keys. A key derived from the
    // content would be identical across tenants and guessable from the file.
    expect(array_unique($keys))->toHaveCount(5);
});

it('refuses an empty file and one over 5 MB, by size and before anything is written', function (): void {
    $tenant = $this->makeTenant();

    expect(rejection(fn () => library()->store($tenant, 'x.png', ''))?->reason)->toContain('empty');

    // A real PNG header followed by enough padding to pass the cap. The size check runs
    // before detection, so this never reaches libmagic.
    $huge = ImageFixtures::png().str_repeat("\x00", InspectedImage::MAX_BYTES);

    expect(rejection(fn () => library()->store($tenant, 'x.png', $huge))?->reason)
        ->toContain('the limit is 5 MB');

    expect(withTenant($tenant, fn () => DB::table('media_assets')->count()))->toBe(0);
});

it('refuses every hostile SVG, each for a reason naming the construct', function (): void {
    // THE ALLOW-LIST, DRIVEN. §12.2's copy says "SVG-free SVG", which is self-
    // contradictory as written; the reading that makes it both true and safe is "an SVG
    // with nothing executable in it", and this is what that means in practice.
    $tenant = $this->makeTenant();

    foreach (ImageFixtures::hostileSvgs() as $what => $bytes) {
        // ⚠ THE POLICY IS DRIVEN DIRECTLY FIRST, and that is the half the §11 security
        // review found missing. Going only through store() lets ANOTHER control satisfy
        // the assertion: `stylesheet instruction` was refused by the CONTENT-TYPE gate,
        // because libmagic answers `text/xml` for a buffer that starts with that
        // instruction, so SvgPolicy's own processing-instruction rule was never
        // consulted -- and the rule turned out to be unreachable for the position a PI
        // is actually written in. A refusal from the wrong control is a green test for a
        // dead one, which is the shape §4a records four of.
        $this->assertNotNull(
            SvgPolicy::refuse($bytes),
            "SvgPolicy accepted an SVG carrying a {$what}",
        );

        // And end to end, through the real upload path.
        $this->assertNotNull(
            rejection(fn () => library()->store($tenant, 'logo.svg', $bytes)),
            "the media library stored an SVG carrying a {$what}",
        );
    }

    expect(withTenant($tenant, fn () => DB::table('media_assets')->count()))->toBe(0);

    // And the clean one is accepted, so the policy is not simply refusing every SVG --
    // which every case above would also pass against.
    expect(library()->store($tenant, 'logo.svg', ImageFixtures::svg()))->toBeString();
});

it('accepts the SVG constructs a logo actually needs, so the allow-list is usable', function (): void {
    // The other half of the positive control: a policy nobody can upload a real logo
    // through would be "refuse SVG" wearing a costume, and §12.2's copy would be false.
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" '
        .'viewBox="0 0 100 50" width="100" height="50">'
        .'<title>Logo</title><desc>A mark</desc>'
        .'<defs><linearGradient id="g" gradientUnits="objectBoundingBox">'
        .'<stop offset="0" stop-color="#ff0000"/><stop offset="1" stop-color="#0000ff"/>'
        .'</linearGradient><clipPath id="c"><rect width="50" height="50"/></clipPath>'
        .'<symbol id="s"><circle cx="5" cy="5" r="4"/></symbol></defs>'
        .'<g transform="translate(10 10)" clip-path="url(#c)">'
        .'<path d="M0 0 L10 10 Z" fill="url(#g)" stroke="#000000" stroke-width="2"/>'
        .'<text x="20" y="20" font-family="serif" font-size="12" text-anchor="middle">Hi</text>'
        .'</g><use xlink:href="#s"/></svg>';

    expect(SvgPolicy::refuse($svg))->toBeNull();

    // `url(#id)` inside a presentation attribute is the one URL form that stays: it is a
    // reference to this document's own gradient, which is how gradients work at all.
    expect(SvgPolicy::refuse(str_replace('url(#g)', 'url(https://evil.test/g)', $svg)))->not->toBeNull();
});

it('refuses a raster file whose container does not decode as the type it claims', function (): void {
    // libmagic reads a few magic bytes; getimagesizefromstring parses the container. A
    // file that passes one and not the other is not an image either way, and the
    // disagreement is the interesting case -- it is what a polyglot looks like.
    $tenant = $this->makeTenant();

    $truncated = substr(ImageFixtures::png(), 0, 20);

    expect(rejection(fn () => library()->store($tenant, 'x.png', $truncated)))->not->toBeNull();
});

it('stops at 200 assets, which is §12.2s full state', function (): void {
    $tenant = $this->makeTenant();

    // Inserted directly: 200 round trips through the object store to prove an arithmetic
    // bound would be a slow test proving the same thing. The cap itself is read from the
    // table, so the rows are what it counts.
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

    expect(library()->count($tenant))->toBe(MediaLibrary::MAX_ASSETS);

    expect(rejection(fn () => library()->store($tenant, 'x.png', ImageFixtures::png()))?->reason)
        ->toContain('already holds 200 images');
});

it('leaves no row behind when the object write fails, which is the fail-closed order', function (): void {
    // §6.6: the row is inserted inside the tenant transaction and the object is written
    // before it commits, so an object failure rolls the row back. The reverse order would
    // risk the one state that breaks a publish: a row pointing at bytes that are not there.
    $tenant = $this->makeTenant();

    // ⚠ THE ENDPOINT IS TAKEN AWAY, NOT THE BUCKET, and the difference is measured rather
    // than assumed. Pointing the disk at a bucket that does not exist SUCCEEDS against
    // SeaweedFS -- it creates the bucket on PUT -- which is a second place the local
    // stand-in is more permissive than production, after the ACL one ADR-004 records.
    // A test written against the missing bucket would have been green while proving
    // nothing, which is how the FIRST version of this case passed over a real defect.
    //
    // Port 9 is `discard`: nothing listens, so the client fails to connect. That is the
    // posture §4 › Edge & ops asks to be tested by TAKING THE DEPENDENCY DOWN.
    $endpoint = config('filesystems.disks.s3.endpoint');
    config()->set('filesystems.disks.s3.endpoint', 'http://127.0.0.1:9');
    Storage::forgetDisk('s3');

    try {
        $refusal = rejection(fn () => library()->store($tenant, 'x.png', ImageFixtures::png()));

        // FAIL-CLOSED, and the reason it is a refusal rather than an unhandled error:
        // `'throw' => false` is set on the s3 disk in config/filesystems.php, so a failed
        // PUT RETURNS FALSE and raises nothing. The first version of this method ignored
        // the return value, and against an unreachable store it committed a media_assets
        // row and wrote no object -- a row pointing at bytes that are not there, which the
        // tenant would discover at their next publish.
        expect($refusal)->not->toBeNull('an unreachable object store did not refuse the upload')
            ->and($refusal?->reason)->toContain('nothing was saved');
    } finally {
        config()->set('filesystems.disks.s3.endpoint', $endpoint);
        Storage::forgetDisk('s3');
    }

    expect(withTenant($tenant, fn () => DB::table('media_assets')->count()))->toBe(0);
});
