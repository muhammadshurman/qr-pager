<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use App\Features\Landing\InspectedImage;
use App\Features\Landing\MediaLibrary;
use App\Features\Landing\Panel\Pages\MediaLibraryPage;
use Illuminate\Http\UploadedFile;
use Livewire\Facades\GenerateSignedUploadUrlFacade;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;
use Tests\Support\ImageFixtures;
use Tests\Support\LandingFixtures;

/*
 * ================================================================================
 * THE MEDIA UPLOAD OVER HTTP -- THE HALF `Livewire::test` CANNOT SEE
 * ================================================================================
 *
 * StudioActionsTest drives this page with `Livewire::test(MediaLibraryPage::class)
 * ->set('upload', UploadedFile::fake()...)`, which makes the temporary file IN PROCESS:
 * Livewire's test helper writes it to the temporary disk itself and never issues a
 * request. That is a fine driver for `store()`'s branches, and it is why this defect
 * shipped green:
 *
 *     `RefuseUnservedVendorRoutes` refused `livewire-<hash>/upload-file` -- a GLOBAL
 *     prepended middleware, written at Task 6 when the platform panel was the only panel
 *     and had no upload at all -- so from Task 7's first commit the ONE validated upload
 *     surface in the product could not receive a file from a browser. Measured on
 *     2026-10-31 before the fix: this file's first case answered **404
 *     application/problem+json** at the POST below. Nothing in the suite crossed that
 *     endpoint, and `RouteSurfaceTest` asserted the 404 as correct behaviour.
 *
 * So these cases exist to cross the one boundary the in-process driver skips. Step 3 is
 * still `Livewire::test`, because `POST /livewire-<hash>/update` is what a real client
 * sends there and Livewire's test driver IS that endpoint's driver -- but `_finishUpload`
 * is called the way the browser calls it, with the signed path the HTTP response handed
 * back, so the temporary file this test consumes is one that really travelled.
 *
 * The signed URL comes from `GenerateSignedUploadUrlFacade`, the exact call
 * `WithFileUploads::_startUpload()` makes on the non-S3 branch. It is marked `@internal`
 * by the vendor and a TEST may couple to that where app code may not: it buys the
 * fidelity that matters here (the URL under test is the URL the component really hands
 * the browser), and if Livewire moves it this file fails at the seam instead of going
 * quiet -- which is the direction every guard in this repository is pointed.
 */

beforeEach(function (): void {
    LandingFixtures::bucket();

    // ⚠ A HARNESS DETAIL, NOT A PRODUCTION ONE, and it is here rather than inline because
    // it is the one thing about this file that would mislead a reader. Livewire's temporary
    // disk is `config('filesystems.default')` -- `local`, configured -- EXCEPT inside a
    // test run, where `FileUploadConfiguration::disk()` returns the magic name
    // `tmp-for-tests` and `storage()` fakes it lazily on first use. The HTTP controller
    // path does not go through `storage()`: `storeTemporaryFile()` reaches `Storage::disk()`
    // directly and answers 500 on an unconfigured disk. So the fake is established here
    // through Livewire's own accessor, rather than by naming a disk that only exists while
    // the suite is running.
    FileUploadConfiguration::storage();
});

it('carries an image through the real browser path: signed POST over HTTP, then store', function (): void {
    $tenant = $this->actingForTenant(role: TenantRole::Owner);

    // The branch this installation is on, asserted rather than assumed: `FILESYSTEM_DISK`
    // is `local` and there is no `config/livewire.php`, so `isUsingS3()` is false and the
    // upload goes to Livewire's own endpoint. If anyone ever points the temporary disk at
    // S3, the browser PUTs raw bytes straight into the bucket and nothing ever reads them
    // before they land -- which is exactly what MediaLibraryPage exists to prevent -- so
    // this is the assertion that makes that a failing test rather than a silent bypass.
    expect(FileUploadConfiguration::isUsingS3())->toBeFalse(
        'the temporary upload disk is S3: bytes would reach the bucket without InspectedImage',
    );

    // STEP 1 -- what the component hands the browser's JS.
    $url = GenerateSignedUploadUrlFacade::forLocal();

    // STEP 2 -- THE CROSSING THAT MATTERS: the multipart POST the browser then makes.
    $response = $this->post($url, [
        'files' => [UploadedFile::fake()->createWithContent('logo.png', ImageFixtures::png())],
    ]);

    expect($response->getStatusCode())
        ->toBe(200, 'the Livewire upload endpoint is not reachable: the media library cannot work in a browser');

    $signedPath = $response->json('paths.0');

    expect($signedPath)->toBeString()->not->toBe('');

    // STEP 3 -- the component is told the signed path, and the page stores it.
    Livewire::test(MediaLibraryPage::class)
        ->call('_finishUpload', 'upload', [$signedPath], false)
        ->call('store');

    expect(app(MediaLibrary::class)->count($tenant))->toBe(1);

    // And it is the real image, in the object store, not just a row.
    $asset = app(MediaLibrary::class)->all($tenant)[0];

    expect($asset->contentType)->toBe('image/png')
        ->and($asset->checksum)->toBe(hash('sha256', ImageFixtures::png()))
        ->and(MediaLibrary::disk()->exists($asset->objectKey))->toBeTrue();
});

it('refuses an unsigned POST to the upload endpoint, so serving it opened nothing', function (): void {
    $tenant = $this->actingForTenant(role: TenantRole::Owner);

    $path = (string) parse_url(GenerateSignedUploadUrlFacade::forLocal(), PHP_URL_PATH);

    $response = $this->post($path, [
        'files' => [UploadedFile::fake()->createWithContent('logo.png', ImageFixtures::png())],
    ]);

    // 401 from Livewire's own `abort_unless(request()->hasValidRelativeSignature(), 401)`,
    // and NOT the 404 the guard used to answer. The distinction is the whole point of the
    // two cases together: the endpoint is SERVED and refuses on its own terms. A 404 here
    // would mean the pattern is back in `REFUSED` and the case above is passing for the
    // wrong reason; a 200 would mean the signature stopped being the control.
    expect($response->getStatusCode())->toBe(401)
        ->and(app(MediaLibrary::class)->count($tenant))->toBe(0);
});

it('bounds the upload at the library, not at the endpoint, and that gap is deliberate', function (): void {
    $tenant = $this->actingForTenant(role: TenantRole::Owner);

    // Livewire's default temporary-upload rule is `max:12288` (12 MB) and this
    // installation publishes no `config/livewire.php` to narrow it, while
    // InspectedImage::MAX_BYTES is 5 MB and `media_assets_size_is_bounded` is the same
    // number in the database. So a 6 MB file is ACCEPTED by the endpoint as a temporary
    // file and REFUSED by the library, which is what this case pins: the validation that
    // decides what exists is the library's, and the endpoint's bound is only a ceiling on
    // what a signed caller can make the application write to its temporary disk.
    //
    // That ceiling is recorded rather than closed -- narrowing the vendor's rules is a
    // sizing decision and belongs with R-122/R-123's pass, not in a fix to one route.
    expect(InspectedImage::MAX_BYTES)->toBeLessThan(12288 * 1024);

    $response = $this->post(GenerateSignedUploadUrlFacade::forLocal(), [
        'files' => [UploadedFile::fake()->create('big.png', 6144)],
    ]);

    expect($response->getStatusCode())->toBe(200);

    Livewire::test(MediaLibraryPage::class)
        ->call('_finishUpload', 'upload', [$response->json('paths.0')], false)
        ->call('store');

    expect(app(MediaLibrary::class)->count($tenant))->toBe(0);
});
