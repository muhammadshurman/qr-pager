<?php

declare(strict_types=1);

use App\Domain\Contracts\StatusMirror;
use App\Domain\DatabaseEnums;
use App\Domain\Pager\PagerStatus;

/**
 * ================================================================================
 * THE GENERATED TS STATUS MIRROR: IT MATCHES THE PHP, AND --check BITES.
 * ================================================================================
 *
 * `§3` row 8's gate clause, verbatim: *"the TS status mirror is generated and CI fails when
 * it is stale -- no hand-written copy."* `§3.1` row 8 names it as one of the two clauses
 * this task ships on, so it is half the named invariant's company rather than a nicety.
 *
 * The sibling of LandingSchemaContractTest and OpenApiSpecTest, deliberately: same split
 * (a pure encoder plus a command that does I/O), same proof (the bytes, then the check).
 */
it('mirrors every enum in the registry, so a new one cannot be forgotten', function (): void {
    $generated = StatusMirror::encode();

    // EVERY type in the registry, not a sample. A generator that covered seven of eight
    // would look correct in a spot check and drop the eighth silently.
    expect(DatabaseEnums::map())->toHaveCount(8);

    foreach (DatabaseEnums::map() as $type => $class) {
        expect($generated)
            ->toContain("export const ".strtoupper($type)." = [")
            ->toContain("'{$type}': ".strtoupper($type).",");

        foreach ($class::values() as $value) {
            expect($generated)->toContain("  '{$value}',");
        }
    }
});

it('emits PagerStatus\'s transition table exactly as the PHP declares it', function (): void {
    $generated = StatusMirror::encode();

    // The table is READ from the enum, never re-derived -- so this assertion is built the
    // same way, from allows(). A literal table written out here would be a third copy of
    // the thing the whole mechanism exists to keep singular.
    foreach (PagerStatus::cases() as $case) {
        $to = array_map(static fn (PagerStatus $s): string => "'{$s->value}'", $case->allows());
        $list = $to === [] ? '[]' : '['.implode(', ', $to).']';

        expect($generated)->toContain("  '{$case->value}': {$list},");
    }
});

it('emits a terminal status as an empty list rather than omitting it', function (): void {
    // An omitted key would make the TS Record incomplete and the type unsound: a consumer
    // indexing it would get undefined where the contract promises an array.
    expect(StatusMirror::encode())
        ->toContain("  'completed': [],")
        ->toContain("  'cancelled': [],")
        ->toContain("  'expired': [],");
});

it('is byte-identical between two calls, so the gate can compare bytes', function (): void {
    // A generated file carrying a timestamp can never be byte-compared, which would make
    // the staleness check impossible to write. This is the property that keeps it writable.
    expect(StatusMirror::encode())->toBe(StatusMirror::encode());
});

it('matches the committed artifact, so the repository is not already stale', function (): void {
    $path = dirname(base_path(), 2).'/'.StatusMirror::RELATIVE_PATH;

    expect(is_file($path))->toBeTrue("{$path} does not exist; run `php artisan contracts:export`");
    expect((string) file_get_contents($path))->toBe(
        StatusMirror::encode(),
        'the committed TS mirror disagrees with the PHP enum registry; run `php artisan contracts:export`'
    );
});

/**
 * ⚠ THE CHECK IS PROVEN TO FAIL, which is the half that makes it a control.
 *
 * `--path` points at a scratch copy rather than the real artifact. R-018 is the §5 row
 * saying every `finally` is correct and none survives a kill -9, so a test that proved this
 * by corrupting the committed mirror and restoring it afterwards would leave a broken
 * artifact behind on any hard stop. QA L-2 made the same call for bin/openapi-check.
 */
it('exits 0 on a current file and 1 on a stale one, driven rather than read', function (): void {
    $scratch = tempnam(sys_get_temp_dir(), 'status-mirror-').'.ts';

    try {
        // Current.
        file_put_contents($scratch, StatusMirror::encode());
        expect($this->artisan('contracts:export', ['--check' => true, '--path' => $scratch]))
            ->assertExitCode(0);

        // Stale by ONE CHARACTER, which is the realistic shape: an enum value edited in PHP
        // and the artifact not regenerated.
        file_put_contents($scratch, str_replace("'waiting'", "'waitingx'", StatusMirror::encode()));
        expect($this->artisan('contracts:export', ['--check' => true, '--path' => $scratch]))
            ->assertExitCode(1);

        // Stale by ABSENCE, which is what a fresh clone of a branch that forgot to commit
        // the artifact looks like.
        unlink($scratch);
        expect($this->artisan('contracts:export', ['--check' => true, '--path' => $scratch]))
            ->assertExitCode(1);

        // And it writes a current file when asked to, so the fix the error names works.
        expect($this->artisan('contracts:export', ['--path' => $scratch]))->assertExitCode(0);
        expect((string) file_get_contents($scratch))->toBe(StatusMirror::encode());
    } finally {
        if (is_file($scratch)) {
            unlink($scratch);
        }
    }
});

it('writes no second definition of a status anywhere in the workspace', function (): void {
    // §6.2's clause from the other side: the generated file is the only place a TypeScript
    // status list may exist. A hand-written copy elsewhere is the defect ADR-003 describes,
    // and it would be invisible to the staleness check -- which only watches one path.
    $root = dirname(base_path(), 2);
    $mirror = $root.'/'.StatusMirror::RELATIVE_PATH;

    $offenders = [];
    foreach (['packages', 'apps/web', 'apps/site'] as $dir) {
        $base = $root.'/'.$dir;
        if (! is_dir($base)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                static fn (SplFileInfo $f): bool => $f->getFilename() !== 'node_modules'
            )
        );

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || ! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
                continue;
            }
            $path = (string) $file->getRealPath();
            if ($path === realpath($mirror)) {
                continue;
            }
            $source = (string) file_get_contents($path);
            // A status list is recognisable: two or more pager status values as quoted
            // literals in one file. Narrow on purpose -- a single word like 'ready' is
            // ordinary English and would make this fire on copy.
            $hits = 0;
            foreach (['waiting', 'completed', 'cancelled', 'expired'] as $value) {
                if (str_contains($source, "'{$value}'")) {
                    $hits++;
                }
            }
            if ($hits >= 3) {
                $offenders[] = str_replace($root.'/', '', $path);
            }
        }
    }

    expect($offenders)->toBe([], 'these files look like a second, hand-written copy of the status enum');
});
