<?php

declare(strict_types=1);

use App\Features\Landing\Console\DemoMedia;
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
