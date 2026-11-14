<?php

declare(strict_types=1);

use App\Domain\Landing\LandingTemplate;
use App\Features\Landing\Console\SeedDemoTenantCommand;
use App\Features\Landing\LandingPages;
use App\Features\Landing\PublishedSite;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LandingFixtures;
use Tests\Support\SentinelTheme;

/*
 * ================================================================================
 * `bin/publish-sentinel-theme` — THE ANSWERS TO MUHAMMAD'S FINDING C, AS ASSERTIONS
 * ================================================================================
 *
 * The script writes tenant data: a theme, on a live published page. His review asked four
 * questions of it — does it set tenant context or use a named bypass, does any statement
 * reference no column, is it refused outside local and test, and why was
 * `FixtureScriptGuardTest`'s exact set widened to admit it. The script's own docblock answers
 * them; this file is what stops that paragraph from being the only thing that does.
 *
 * ⚠ WHY A DEDICATED FILE AND NOT THE GENERIC GUARD CASE. `FixtureScriptGuardTest` drives every
 * guarded script under `APP_ENV=production` and asserts it writes no `users` row and no `tenants`
 * row. This script writes neither, so for it that assertion is VACUOUS — it would stay green if
 * the script published a page under production. The refusal case below counts the tables and
 * the object it would actually have written.
 *
 * ⚠ THE BUCKET IS SHARED WITH THE DEVELOPER'S, and so is the demo restaurant's published prefix
 * (`sites/demo-al-sarw.<platform domain>/`): `phpunit.xml` and `.env` both name `qrpager-local`.
 * `LandingFixtures::bucket()` EMPTIES `sites/` and `media/` before every landing case —
 * `QRPAGER-48`'s `§4a` records this and made `studio:demo-tenant` repair it ("re-uploaded (the row
 * was there, the object was not)") rather than touching the suite — so the demo's published page
 * does not survive a suite run whatever this file does. A first draft of this note said the
 * existing seeder tests *"leave the DEFAULT demo there on every suite run"*, which is false: the
 * next case's `beforeEach` erases it.
 *
 * What the cleanup below narrows is the one outcome that is WORSE than absence. If a case that
 * published the sentinel theme were the last writer to that prefix, the developer's demo URL
 * would serve magenta bytes while its database row said default — which reads as a theme bug,
 * not as a missing page. So every case that publishes ends by re-running the seeder, which
 * republishes the default demo. Verified by running this file ALONE and then reading the
 * pointer, because a read after the whole suite would find the bucket emptied by someone else
 * and prove nothing.
 */

beforeEach(fn () => LandingFixtures::bucket());

/**
 * Whether the running case published, and so must restore the shared prefix. Read with no
 * argument; set by passing a bool. A function-local static rather than a global, so the flag
 * cannot be seen or set from anywhere but here.
 */
function sentinelPublished(?bool $set = null): bool
{
    static $published = false;

    if ($set !== null) {
        $published = $set;
    }

    return $published;
}

afterEach(function (): void {
    if (sentinelPublished()) {
        Artisan::call('studio:demo-tenant');
        sentinelPublished(false);
    }
});

/**
 * Run the script as a real process, as `tools/gate/bin/theme-paint-proof` does.
 *
 * @param  list<string>  $arguments
 * @return array{int, string}
 */
function sentinelRun(array $arguments, string $environment): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    $process = proc_open(
        [PHP_BINARY, base_path('bin/publish-sentinel-theme'), ...$arguments],
        $descriptors,
        $pipes,
        base_path(),
        [
            'APP_ENV' => $environment,
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
            // The suite's own database and the bucket the suite itself resolved — passed
            // explicitly so a child never falls back to a `.env` that names another.
            'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
            'AWS_BUCKET' => (string) config('filesystems.disks.s3.bucket'),
            'AWS_ENDPOINT' => (string) config('filesystems.disks.s3.endpoint'),
            'AWS_ACCESS_KEY_ID' => (string) config('filesystems.disks.s3.key'),
            'AWS_SECRET_ACCESS_KEY' => (string) config('filesystems.disks.s3.secret'),
            'AWS_DEFAULT_REGION' => (string) config('filesystems.disks.s3.region'),
            'AWS_USE_PATH_STYLE_ENDPOINT' => 'true',
        ],
    );

    expect($process)->not->toBeFalse('could not start bin/publish-sentinel-theme');

    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);

    foreach ($pipes as $pipe) {
        fclose($pipe);
    }

    return [proc_close($process), $out.$err];
}

/**
 * Everything the script could have written for the demo restaurant, in comparable form.
 *
 * @return array{draft: string, published: ?string, checksum: ?string, pointer: ?string}
 */
function sentinelSnapshot(): array
{
    $tenantId = SeedDemoTenantCommand::TENANT_ID;
    $row = withTenant($tenantId, static fn (): ?object => DB::table('landing_pages')->where('tenant_id', $tenantId)->first());

    expect($row)->not->toBeNull('the demo restaurant has no landing_pages row to snapshot');

    $pointer = Storage::disk('s3')->get(PublishedSite::pointer((new LandingPages)->hostname($tenantId)));

    return [
        'draft' => (string) $row->draft_document,
        'published' => $row->published_document === null ? null : (string) $row->published_document,
        'checksum' => $row->published_checksum === null ? null : (string) $row->published_checksum,
        'pointer' => is_string($pointer) ? hash('sha256', $pointer) : null,
    ];
}

function sentinelSeed(): void
{
    expect(Artisan::call('studio:demo-tenant'))->toBe(0, 'the demo seeder did not succeed');
}

it('prints the arrangements from the enum and nothing else', function (): void {
    [$status, $output] = sentinelRun(['--templates'], 'testing');

    expect($status)->toBe(0);
    expect(json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR))->toBe(LandingTemplate::values());
});

it('refuses under production and leaves the row and the published pointer BYTE-IDENTICAL', function (): void {
    sentinelSeed();

    $before = sentinelSnapshot();

    // Not vacuous: there is a published page to leave alone, so "unchanged" means something.
    expect($before['published'])->not->toBeNull();
    expect($before['pointer'])->not->toBeNull();

    [$status, $output] = sentinelRun(['ember'], 'production');

    expect($status)->toBe(1);
    expect(str_contains($output, 'refusing to run'))->toBeTrue($output);
    // The refusal says which environment it saw, because a guard that will not say why it fired
    // is the kind somebody disables.
    expect(str_contains($output, "'production'"))->toBeTrue($output);

    expect(sentinelSnapshot())->toBe($before);
});

it('takes no tenant: an extra argument is refused, and nothing is written for anybody', function (): void {
    sentinelSeed();

    $other = test()->makeTenant();
    $before = sentinelSnapshot();

    [$status, $output] = sentinelRun(['rail', $other], 'testing');

    expect($status)->toBe(1);
    expect(str_contains($output, 'takes no tenant'))->toBeTrue($output);
    expect(sentinelSnapshot())->toBe($before);

    // …and the other tenant never acquired a landing page, which `LandingPages::row()` would
    // have created on first touch had the script been aimed at it.
    $others = withTenant($other, static fn (): int => DB::table('landing_pages')->where('tenant_id', $other)->count());

    expect($others)->toBe(0);
});

it('refuses a template that is not an enum case — including the two retired ones', function (): void {
    sentinelSeed();

    $before = sentinelSnapshot();

    foreach (['classic', 'editorial', 'nonsense', ''] as $name) {
        [$status, $output] = sentinelRun([$name], 'testing');

        expect($status)->toBe(1, "'{$name}' was accepted");
        expect(str_contains($output, 'is not a LandingTemplate case'))->toBeTrue($output);
    }

    expect(sentinelSnapshot())->toBe($before);
});

it('refuses with no argument at all, and says what it accepts', function (): void {
    [$status, $output] = sentinelRun([], 'testing');

    expect($status)->toBe(1);
    expect(str_contains($output, 'exactly one argument'))->toBeTrue($output);

    foreach (LandingTemplate::values() as $case) {
        expect(str_contains($output, $case))->toBeTrue("the refusal does not list {$case}");
    }
});

it('refuses when the demo restaurant has not been seeded, rather than creating one', function (): void {
    [$status, $output] = sentinelRun(['rail'], 'testing');

    expect($status)->toBe(1);
    expect(str_contains($output, 'the demo restaurant is not seeded'))->toBeTrue($output);
});

it('publishes the sentinel theme under every arrangement and changes nothing else in the document', function (LandingTemplate $template): void {
    sentinelSeed();

    sentinelPublished(true);

    $tenantId = SeedDemoTenantCommand::TENANT_ID;
    $before = withTenant($tenantId, static fn (): object => (object) DB::table('landing_pages')->where('tenant_id', $tenantId)->first());
    $beforeDraft = json_decode((string) $before->draft_document, true, flags: JSON_THROW_ON_ERROR);

    [$status, $output] = sentinelRun([$template->value], 'testing');

    expect($status)->toBe(0, $output);

    /** @var array{template: string, hostname: string, checksum: string, theme: array<string, string>} $manifest */
    $manifest = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['template'])->toBe($template->value);
    expect($manifest['theme'])->toBe(SentinelTheme::values());

    // The published BYTES carry the theme and are the ones the manifest names — read back out of
    // the store, so this is the page a visitor receives and not the script's account of it.
    $bytes = (string) Storage::disk('s3')->get(PublishedSite::pointer($manifest['hostname']));

    expect(hash('sha256', $bytes))->toBe($manifest['checksum']);

    foreach (SentinelTheme::values() as $role => $value) {
        expect(str_contains($bytes, $value))->toBeTrue("the published page does not carry {$role}");
    }

    // ONE DOCUMENT, UNCHANGED BUT FOR TWO MEMBERS: every block, the SEO and the logo are
    // exactly what they were.
    $after = withTenant($tenantId, static fn (): object => (object) DB::table('landing_pages')->where('tenant_id', $tenantId)->first());
    $afterDraft = json_decode((string) $after->draft_document, true, flags: JSON_THROW_ON_ERROR);

    expect($afterDraft['template'])->toBe($template->value);

    // ⚠ COMPARED AS A MAP, NOT AS A SEQUENCE. Postgres `jsonb` does not preserve key order — it
    // stores keys by length and then alphabetically — so the stored theme comes back in a
    // different order from the one the script wrote. Three cases failed on exactly that.
    $stored = $afterDraft['theme'];
    $expected = SentinelTheme::values();

    ksort($stored);
    ksort($expected);

    expect($stored)->toBe($expected);

    unset($beforeDraft['template'], $beforeDraft['theme'], $afterDraft['template'], $afterDraft['theme']);

    expect($afterDraft)->toBe($beforeDraft);
    expect($beforeDraft['blocks'])->not->toBe([], 'the demo document has no blocks, so "unchanged" proves nothing');
})->with(LandingTemplate::cases());

it('contains no owner connection, no bypass and no bulk statement — in its CODE, comments stripped', function (): void {
    /*
     * ⚠ THE COMMENTS ARE STRIPPED FIRST, BY THE TOKENIZER. The script's own docblock names every
     * one of the forbidden things in order to say it does not use them, so a plain substring
     * scan would fail on the explanation of the control. This reads what the interpreter would
     * run.
     */
    $code = '';

    foreach (token_get_all((string) file_get_contents(base_path('bin/publish-sentinel-theme'))) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                continue;
            }

            $code .= $token[1];
        } else {
            $code .= $token;
        }
    }

    // Not vacuous: stripping must have left the program, not an empty string.
    expect($code)->toContain('LandingPages')->and($code)->toContain('SeedDemoTenantCommand::TENANT_ID');

    foreach (['pgsql_owner', 'pgsql_root', 'withoutTenantScope', 'DB::connection(', 'DB::unprepared', 'DB::statement', 'DB::raw', '->delete(', '->truncate(', '->update(', '->insert(', 'set_config'] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("bin/publish-sentinel-theme contains `{$forbidden}`, which its docblock says it never does");
    }

    // The one statement of its own: a keyed read, inside a tenant scope.
    expect(substr_count($code, 'DB::'))->toBe(1);
    expect($code)->toContain("withTenant(\$tenantId, fn (): ?string => DB::table('tenants')->where('id', \$tenantId)->value('name'))");
});
