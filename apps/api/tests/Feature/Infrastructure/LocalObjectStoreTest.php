<?php

declare(strict_types=1);

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * THE LOCAL OBJECT STORE IS PART OF THE STACK, AND THIS FAILS IF IT IS ABSENT
 * ================================================================================
 *
 * `R-125`'s other half, and `ADR-004`. `LocalMailCatcherTest` closed the mail half on
 * 2026-10-28; this closes the object-store half, in the same shape one service over:
 * `RealDatabaseTest` asserts the suite runs against the real composed Postgres and never
 * SQLite, that file asserts the real composed Mailpit and never `array`, and this one asserts
 * a real composed S3 API and never `Storage::fake()`.
 *
 * WHY IT IS NOT MinIO, WHICH IS WHAT ADR-002 NAMED. MinIO publishes no image a fresh clone
 * holding no credentials can pull: `minio/minio` and `minio/mc` answer `pull access denied ...
 * repository does not exist` and the Docker Hub API answers `404`, while `minio/warp` in the
 * SAME namespace answers `200` -- the control that makes it a finding about MinIO rather than
 * about one laptop. The fourteen candidates, the five instruments and the exact failures are in
 * `docs/adr/004-local-object-store.md`.
 *
 * ⚠ THE NEGATIVE CONTROL BELOW IS NOT DECORATION. Two of the measured candidates --
 * `adobe/s3mock`, and this very image with no credentials configured -- completed the whole
 * round trip while signing with a wrong secret. Against those, every other case in this file
 * would be green and would prove nothing. So the suite asserts the refusal, which is the
 * lesson `R-059` and `R-054` both paid for: a control that cannot fail is not a control.
 *
 * ⚠ IT DOES NOT SKIP WHEN THE SERVICE IS DOWN, for the reason the mail catcher does not:
 * a skipping test restores exactly the condition `R-125` is about -- a clause with no
 * instrument, and nothing going red to say so. `docker compose up -d` starts it.
 */

/** The underlying S3 client of the production driver, which is where bucket creation lives. */
function s3(): S3Client
{
    /** @var AwsS3V3Adapter $disk */
    $disk = Storage::disk('s3');

    return $disk->getClient();
}

/**
 * The bucket is infrastructure rather than a flysystem concept, so it is created the way a
 * production bucket is: through the client, by whoever owns the environment. Idempotent, because
 * the container holds no volume and a `docker compose down` takes the bucket with it.
 */
function bucket(): string
{
    $bucket = (string) config('filesystems.disks.s3.bucket');

    if (! s3()->doesBucketExist($bucket)) {
        s3()->createBucket(['Bucket' => $bucket]);
    }

    return $bucket;
}

it('reaches the local object store, and FAILS rather than skipping when it is absent', function (): void {
    // The §8 question this file exists for: is the instrument there at all? A connection error
    // here is the correct outcome when the service is down -- it is the red that was missing.
    // /healthz and not `/`: the S3 root answers 403 WITHOUT a signature, which is the service
    // behaving correctly and would make a useless probe.
    $endpoint = rtrim((string) config('filesystems.disks.s3.endpoint'), '/');

    expect(Http::timeout(5)->get($endpoint.'/healthz')->status())
        ->toBe(200, 'the object store is not answering: run `docker compose up -d objectstore`');
});

it('uses the REAL driver against the composed service, not the local disk and not a fake', function (): void {
    // RealDatabaseTest's assertion, one service over. `s3` is the production driver; only the
    // endpoint and path-style addressing differ from production, which is ADR-002's own
    // argument for why a local stand-in costs no code.
    expect(config('filesystems.disks.s3.driver'))->toBe('s3')
        ->and(config('filesystems.disks.s3.endpoint'))->toBe('http://127.0.0.1:8333')
        ->and(config('filesystems.disks.s3.bucket'))->toBe('qrpager-local')
        ->and((bool) config('filesystems.disks.s3.use_path_style_endpoint'))->toBeTrue();

    // And the adapter really is the AWS one, so nothing above is satisfied by a local disk
    // wearing the name.
    expect(Storage::disk('s3'))->toBeInstanceOf(AwsS3V3Adapter::class);
});

it('round-trips an object through flysystem, with the bytes compared', function (): void {
    // THE CLAUSE, in the form §1 asks for: a round trip through the production client library,
    // never a port check. PUT, GET with the bytes compared, LIST, DELETE.
    //
    // Binary content on purpose -- NUL, 0xff and a PNG signature -- because a stand-in that
    // mangles non-UTF-8 bytes passes a "hello world" probe and then fails a logo upload, which
    // is Task 7's first object.
    bucket();

    $key = 'r-125/probe-'.Str::lower(Str::random(16)).'.bin';
    $bytes = random_bytes(4096)."\x00\xff\xfe\x89PNG\r\n\x1a\n";
    $disk = Storage::disk('s3');

    $disk->put($key, $bytes, ['ContentType' => 'application/octet-stream']);

    try {
        expect($disk->exists($key))->toBeTrue()
            ->and(hash('sha256', (string) $disk->get($key)))->toBe(hash('sha256', $bytes))
            ->and($disk->size($key))->toBe(strlen($bytes))
            // LIST, which is a different S3 operation from GET and has its own signature path.
            ->and($disk->files('r-125'))->toContain($key)
            ->and($disk->mimeType($key))->toBe('application/octet-stream');
    } finally {
        // DELETE, and asserted rather than assumed: the container has no volume, but a store
        // that grows for the length of a suite run is R-016's lesson applied to a service.
        $disk->delete($key);
    }

    expect($disk->exists($key))->toBeFalse('the object was not deleted');
});

it('REFUSES a wrong secret, so the round trip above is not vacuous', function (): void {
    // The negative control. `adobe/s3mock` and this image WITHOUT credentials both completed
    // the entire round trip above while signing with this exact kind of secret; if that ever
    // becomes true here, this case is the one that goes red. Same endpoint, same bucket, same
    // library -- one wrong value.
    $wrong = new S3Client([
        'version' => 'latest',
        'region' => (string) config('filesystems.disks.s3.region'),
        'endpoint' => (string) config('filesystems.disks.s3.endpoint'),
        'use_path_style_endpoint' => true,
        'credentials' => [
            'key' => (string) config('filesystems.disks.s3.key'),
            'secret' => 'definitely-not-the-secret',
        ],
        'http' => ['connect_timeout' => 5, 'timeout' => 10],
        'retries' => 0,
    ]);

    $bucket = bucket();

    try {
        $wrong->putObject(['Bucket' => $bucket, 'Key' => 'r-125/must-not-land.txt', 'Body' => 'nope']);
        $this->fail('the object store accepted a WRONG SECRET: every other case in this file is vacuous');
    } catch (S3Exception $e) {
        expect($e->getAwsErrorCode())->toBe('SignatureDoesNotMatch');
    }

    // And the other half of the same property: no signature at all is refused too, so a
    // tenant's object is not world-readable on a developer's machine.
    $unsigned = Http::timeout(5)->withoutVerifying()
        ->get(rtrim((string) config('filesystems.disks.s3.endpoint'), '/').'/'.$bucket.'/');

    expect($unsigned->status())->toBe(403, 'an unsigned request listed the bucket');
});

it('is declared in compose.yaml with a PINNED tag, bound to 127.0.0.1 only', function (): void {
    // §1's conditions that are properties of the file rather than of the service: a pinned tag,
    // and a port a developer's whole network cannot reach. Read from the file, because that is
    // what a clone gets.
    $compose = (string) file_get_contents(__DIR__.'/../../../../../compose.yaml');

    expect($compose)->toContain('objectstore:')
        ->and($compose)->toContain('127.0.0.1:8333:8333');

    // The tag, matched rather than quoted: `latest` -- or any floating tag -- fails §1's third
    // condition, and it is the reason chainguard/minio was measured and not chosen.
    expect(preg_match('/image:\s*(\S+)/', substr($compose, (int) strpos($compose, 'objectstore:')), $m))
        ->toBe(1, 'the objectstore service declares no image');

    expect($m[1])->toMatch('/^chrislusf\/seaweedfs:\d+\.\d+$/')
        ->and($m[1])->not->toContain('latest');
});
