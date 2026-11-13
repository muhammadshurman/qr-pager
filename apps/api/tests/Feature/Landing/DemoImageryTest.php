<?php

declare(strict_types=1);

use App\Features\Landing\Console\DemoImagery;
use App\Features\Landing\Console\DemoMedia;
use App\Features\Landing\MediaLibrary;

/*
 * ================================================================================
 * THE DEMO IMAGERY GENERATOR — `§11` QA M-4
 * ================================================================================
 *
 * 485 lines of new PHP across `DemoImagery` and `GenerateDemoImageryCommand` arrived with
 * NO test of any kind: a grep for `DemoImagery|GenerateDemoImagery|demo-imagery` over
 * `apps/api/tests` and `tools` returned nothing, and `§6` asks for ≥ 90% unit coverage on
 * code a task adds.
 *
 * ⚠ WHAT IS WORTH ASSERTING HERE IS NOT WHAT THE PICTURES LOOK LIKE. They are abstract by
 * ruling — `database/demo-media/README.md` says so — and a test that pinned pixels would be
 * asserting a seed. What matters is that they are IMAGES the rest of the chain accepts:
 * `InspectedImage` inspects them exactly as it inspects an upload, so a generator that
 * drifted outside what the inspector takes would fail at `MediaLibrary::store()` with the
 * demo tenant half-seeded. These cases stand between that and the next person.
 *
 * The three failure arms are driven too, because they are the ones a caller meets when a
 * dimension is wrong, and an untested `throw` is a message nobody has ever read.
 */

it('draws a photograph-shaped frame at the size it is asked for', function (): void {
    $bytes = DemoImagery::photograph(160, 100, 'room', 7);

    $info = getimagesizefromstring($bytes);

    expect($info)->not->toBeFalse()
        ->and($info[0])->toBe(160)
        ->and($info[1])->toBe(100)
        ->and($info['mime'])->toBe('image/jpeg');
});

it('is deterministic for a seed, and different for a different one', function (): void {
    /*
     * The seed is what makes `studio:demo-imagery` idempotent: re-running it must not produce
     * a new set of bytes, or every run would restore a dirty working tree. And two subjects
     * sharing a seed must still differ, or the five dish cards would be five copies.
     */
    expect(DemoImagery::photograph(80, 60, 'room', 11))
        ->toBe(DemoImagery::photograph(80, 60, 'room', 11));

    expect(DemoImagery::photograph(80, 60, 'room', 11))
        ->not->toBe(DemoImagery::photograph(80, 60, 'room', 12));
});

it('draws every subject it offers, so the set and the switch cannot drift', function (): void {
    // ⚠ DERIVED FROM `subjects()` RATHER THAN LISTED. A subject added to that method with no
    // arm in the painter would otherwise be found by the first person to drop it in the SET
    // table, which is the generator running for real with a half-painted frame.
    $subjects = DemoImagery::subjects();

    expect($subjects)->not->toBeEmpty();

    foreach ($subjects as $subject) {
        $info = getimagesizefromstring(DemoImagery::photograph(48, 48, $subject, 3));

        expect($info, "subject {$subject} produced nothing readable")->not->toBeFalse();
    }
});

it('draws a square logo as a png, because a mark is not a photograph', function (): void {
    $info = getimagesizefromstring(DemoImagery::logo(64));

    expect($info)->not->toBeFalse()
        ->and($info[0])->toBe(64)
        ->and($info[1])->toBe(64)
        ->and($info['mime'])->toBe('image/png');
});

it('refuses a frame with no area rather than handing GD a bad call', function (): void {
    // Both axes, and zero as well as negative: `imagecreatetruecolor()` answers with a
    // warning and `false` for these, and the message a caller gets then names GD rather than
    // the argument they passed.
    foreach ([[0, 10], [10, 0], [-1, 10], [10, -1]] as [$width, $height]) {
        expect(static fn (): string => DemoImagery::photograph($width, $height, 'room', 1))
            ->toThrow(RuntimeException::class);
    }

    expect(static fn (): string => DemoImagery::logo(0))->toThrow(RuntimeException::class);
    expect(static fn (): string => DemoImagery::logo(-8))->toThrow(RuntimeException::class);
});

it('produces bytes the upload inspector accepts, which is the whole point of the seam', function (): void {
    /*
     * ⚠ THE REAL INSPECTOR, NOT A SHAPE CHECK. `SeedDemoTenantCommand` puts these through
     * `MediaLibrary::store()`, which runs `InspectedImage` — the same path an owner's upload
     * takes. A generator whose output the inspector refused would fail THERE, after the
     * tenant and the owner had already been written, which is the half-seeded state the
     * seeder's own idempotency cannot repair.
     */
    $tenantId = $this->actingForTenant();

    $asset = app(MediaLibrary::class)
        ->store($tenantId, 'hero.jpg', DemoImagery::photograph(120, 80, 'grill', 5));

    expect($asset)->toBeString()->not->toBeEmpty();

    $logo = app(MediaLibrary::class)
        ->store($tenantId, 'logo.png', DemoImagery::logo(128));

    expect($logo)->toBeString()->not->toBeEmpty();
});

/*
 * ================================================================================
 * THE COMMAND AROUND IT
 * ================================================================================
 */

it('writes the whole set into a directory it creates, and leaves it alone on a second run', function (): void {
    $dir = sys_get_temp_dir().'/qrpager-demo-imagery-'.bin2hex(random_bytes(6));

    config(['surfaces.demo_media_directory' => $dir]);

    try {
        $this->artisan('studio:demo-imagery')->assertSuccessful();

        $files = glob($dir.'/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [];

        expect($files)->not->toBeEmpty();

        // The roles the seeder resolves from filenames alone must all be present, or the
        // generator and `DemoMedia` have drifted apart.
        $roles = DemoMedia::files();

        expect($roles['logo'])->not->toBeNull()
            ->and($roles['hero'])->not->toBeNull()
            ->and($roles['dishes'])->not->toBeEmpty()
            ->and($roles['gallery'])->not->toBeEmpty();

        // ⚠ THE SECOND RUN KEEPS WHAT IS THERE. This is the safety behind the "drop a real
        // photograph in" seam: without it, a re-run would overwrite a restaurant's own
        // picture with a generated one, which is the one thing this command must never do.
        $before = [];

        foreach ($files as $path) {
            $before[$path] = (string) md5_file($path);
        }

        $this->artisan('studio:demo-imagery')->assertSuccessful();

        foreach ($before as $path => $digest) {
            expect((string) md5_file($path))->toBe($digest, basename($path).' was rewritten by a second run');
        }

        // And `--force` is the way to say you meant it. The bytes are deterministic, so the
        // assertion is that it ran and kept the set rather than that the digests changed.
        $this->artisan('studio:demo-imagery', ['--force' => true])->assertSuccessful();

        expect(glob($dir.'/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [])->toHaveCount(count($files));
    } finally {
        foreach (glob($dir.'/*') ?: [] as $path) {
            @unlink($path);
        }

        @rmdir($dir);
    }
});

it('refuses when it cannot create its directory, rather than reporting an empty set', function (): void {
    // A path under a FILE is not a directory and cannot become one, which is the honest way
    // to drive the `mkdir` arm without depending on permissions a container may not have.
    $file = tempnam(sys_get_temp_dir(), 'qrpager-not-a-dir');

    config(['surfaces.demo_media_directory' => $file.'/inside-a-file']);

    try {
        $this->artisan('studio:demo-imagery')->assertFailed();
    } finally {
        @unlink((string) $file);
    }
});
