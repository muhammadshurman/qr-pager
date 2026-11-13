<?php

declare(strict_types=1);

use App\Features\Landing\Console\DemoMedia;
use App\Features\Landing\LandingPages;
use App\Features\Landing\MediaLibrary;
use Illuminate\Support\Facades\DB;

/**
 * `studio:demo-tenant` is idempotent, imagery included. `QRPAGER-48` item 1.
 *
 * Muhammad's requirement: *"Re-running `studio:demo-tenant` must stay idempotent with the
 * images included — not a growing pile on every run."* An asset is re-used when BOTH its
 * stored name and the sha256 of its bytes match, so this case is the control on that pair:
 * if either half of the key were dropped the second run would store ten more.
 */
it('seeds the same restaurant twice without making a second one, or a second library', function (): void {
    $this->artisan('studio:demo-tenant')->assertSuccessful();

    $tenantId = '01999000-0000-7000-8000-000000000001';

    $count = static fn (string $table): int => (int) withTenant(
        $tenantId,
        static fn (): int => DB::connection('pgsql_owner')->table($table)->where('tenant_id', $tenantId)->count(),
        'pgsql_owner',
    );

    $assetsAfterFirst = $count('media_assets');
    $membershipsAfterFirst = $count('tenant_users');

    // The planted state is asserted BEFORE the second run, so this cannot pass on an empty
    // library -- a seeder that stored nothing at all would otherwise look idempotent.
    expect($assetsAfterFirst)->toBeGreaterThan(0)
        ->and($membershipsAfterFirst)->toBe(1);

    $this->artisan('studio:demo-tenant')->assertSuccessful();

    expect($count('media_assets'))->toBe($assetsAfterFirst)
        ->and($count('tenant_users'))->toBe($membershipsAfterFirst)
        ->and(withoutTenantScope(
            'tests: the demo seeder makes exactly one restaurant',
            static fn (): int => DB::connection('pgsql_owner')->table('tenants')->where('slug', 'demo-al-sarw')->count(),
        ))->toBe(1);
});

it('ships imagery whose roles the seeder can resolve from filenames alone', function (): void {
    $files = DemoMedia::files();

    // The role IS the filename, which is what makes replacing these a file drop rather than
    // a code change. A set that lost its naming would leave the seeder silently imageless.
    expect($files['logo'])->not->toBeNull()
        ->and($files['hero'])->not->toBeNull()
        ->and($files['dishes'])->not->toBeEmpty()
        ->and($files['gallery'])->not->toBeEmpty();

    // ⚠ VARIED ASPECT RATIOS ARE THE POINT, not a detail: a grid of squares and a column of
    // full-measure crops is where `tile` and `rail` diverge, and a set of one shape would be
    // the flat colour block's failure in a second costume.
    $ratios = [];

    foreach ([...$files['dishes'], ...$files['gallery']] as $path) {
        $size = getimagesize($path);
        $ratios[] = round(((int) $size[0]) / ((int) $size[1]), 2);
    }

    expect(count(array_unique($ratios)))->toBeGreaterThan(1)
        ->and(min($ratios))->toBeLessThan(1.0)   // at least one portrait
        ->and(max($ratios))->toBeGreaterThan(1.0); // and at least one landscape
});

/*
 * ================================================================================
 * THE DRAFT REALLY REFERENCES THE IMAGERY — `§11` QA L-3
 * ================================================================================
 *
 * The amendment's words were *"images … are referenced by the hero, dish cards and gallery"*,
 * and what the suite asserted was that `DemoMedia::files()` finds files and that
 * `media_assets` is not empty. Those are both true of a seeder that stores ten images and
 * then writes a document mentioning none of them — `seedPage()` reaches every one of them
 * through `$media['dishes'][0] ?? null`, so a null-for-every-role run produced a complete,
 * publishable, picture-less page and reported success.
 *
 * This reads the STORED DRAFT back and asserts the ids are in it, which is the only form of
 * the claim that cannot pass on an imageless page.
 */
it('references the stored imagery from the hero, the dishes and the gallery', function (): void {
    $this->artisan('studio:demo-tenant')->assertSuccessful();

    $tenantId = '01999000-0000-7000-8000-000000000001';

    $assets = withTenant(
        $tenantId,
        static fn (): array => DB::connection('pgsql_owner')
            ->table('media_assets')
            ->where('tenant_id', $tenantId)
            ->pluck('id')
            ->all(),
        'pgsql_owner',
    );

    expect($assets)->not->toBeEmpty();

    $document = app(LandingPages::class)->draft($tenantId);

    /*
     * ⚠ ASKED OF THE DOCUMENT, NOT OF ITS JSON. `mediaReferences()` is the list the PUBLISHER
     * reads to decide which objects to copy, and `blockUsing()` answers which block a given
     * asset appears in -- so this asserts the same facts the publish path acts on rather than
     * whether a uuid happens to appear somewhere in an encoded string.
     */
    $referenced = $document->mediaReferences();

    sort($assets);
    $sorted = $referenced;
    sort($sorted);

    // Every image the seeder stored is used by the page it writes. A weaker "at least one"
    // would pass on a document that referenced the logo and dropped the five dishes, which
    // is the shape the `?? null` chain actually produces.
    expect($sorted)->toBe($assets);

    // And the three ROLES the amendment names are each filled, read off the document.
    $roles = [];

    foreach ($referenced as $id) {
        $block = $document->blockUsing($id);

        if ($block !== null) {
            $roles[$block->value] = ($roles[$block->value] ?? 0) + 1;
        }
    }

    expect($roles)->toHaveKey('hero')
        ->and($roles)->toHaveKey('menu_highlights')
        ->and($roles)->toHaveKey('gallery')
        ->and($roles['menu_highlights'])->toBeGreaterThan(1, 'only one dish card carries an image');
});

it('refuses rather than seeding a restaurant with no pictures', function (): void {
    config(['surfaces.demo_media_directory' => base_path('database/demo-media-that-is-not-there')]);

    $this->artisan('studio:demo-tenant')->assertFailed();
});

/*
 * ================================================================================
 * THE ROW OUTLIVES THE OBJECT, AND THE COMMAND REPAIRS IT — `§11` QA, 2026-11-13
 * ================================================================================
 *
 * ⚠⚠ THIS REPOSITORY CREATES THIS STATE ON ITSELF, SERIALLY, WITH NO CONCURRENCY.
 *
 *   - `phpunit.xml` runs the suite against DB_DATABASE `qrpager_test`,
 *   - and against AWS_BUCKET `qrpager-local`,
 *   - and `.env` gives the DEVELOPMENT database `qrpager` that same bucket.
 *
 * So `php artisan test` truncates one database while `LandingFixtures::bucket()` calls
 * `deleteDirectory('media')` on the store they SHARE. The demo tenant lives in the
 * development database, so its rows survive and its objects do not — and this repo's own
 * `§6` tells every contributor to run that suite before every commit.
 *
 * Re-use was decided from `(original_name, checksum)` alone, so the next run reported
 * `0 stored, 10 re-used` and then failed at publish with `image_not_copied`, and so did
 * every run after it. The command could not repair the state the project's own gate had
 * put it in, and the only way back was deleting rows by hand.
 *
 * ⚠ THE FIRST VERSION OF THIS FINDING WAS WITHDRAWN AS "concurrency only", on the reasoning
 * that the suite truncates the tables and sweeps the store together. That reasoning was
 * wrong — it truncates a DIFFERENT DATABASE from the one the seeder writes to — and the
 * note is here because the wrong version is the more persuasive one.
 */
it('repairs its own tenant when the shared bucket was swept under it', function (): void {
    $this->artisan('studio:demo-tenant')->assertSuccessful();

    $tenantId = '01999000-0000-7000-8000-000000000001';

    $keys = withTenant(
        $tenantId,
        static fn (): array => DB::connection('pgsql_owner')
            ->table('media_assets')
            ->where('tenant_id', $tenantId)
            ->pluck('object_key')
            ->all(),
        'pgsql_owner',
    );

    expect($keys)->not->toBeEmpty();

    // Exactly what the suite does to the shared bucket, and nothing else: the ROWS stay.
    MediaLibrary::disk()->deleteDirectory('media/'.$tenantId);

    foreach ($keys as $key) {
        expect(MediaLibrary::disk()->exists((string) $key))->toBeFalse();
    }

    // The planted state asserted before the run, so this cannot pass on a store that was
    // never emptied.
    $this->artisan('studio:demo-tenant')->assertSuccessful();

    foreach ($keys as $key) {
        expect(MediaLibrary::disk()->exists((string) $key))
            ->toBeTrue('the seeder reported success and left '.$key.' missing');
    }

    /*
     * ⚠ AND THE KEYS ARE THE SAME ONES. A repair that minted new keys would "work" and
     * orphan every reference the stored draft and the published page already hold —
     * which is a worse state than the one being repaired, and is why `restore()` writes
     * back to the row's own key instead of calling `store()` again.
     */
    $after = withTenant(
        $tenantId,
        static fn (): array => DB::connection('pgsql_owner')
            ->table('media_assets')
            ->where('tenant_id', $tenantId)
            ->pluck('object_key')
            ->all(),
        'pgsql_owner',
    );

    sort($keys);
    sort($after);

    expect($after)->toBe($keys);
});
