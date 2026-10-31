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
            ->toContain('export const '.strtoupper($type).' = [')
            ->toContain("'{$type}': ".strtoupper($type).',');

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

/**
 * Does this TypeScript source DECLARE a second status list? Returns the offending
 * declaration, or null.
 *
 * Extracted from the tree scan below so the detection can be DRIVEN over planted sources
 * rather than measured once by hand. The §4a record says this check was "proven to still
 * bite on both planted forms" -- that was a measurement in a session, and a measurement in
 * a session is not a regression test. The QA review then found a third form it misses.
 */
function secondStatusDeclaration(string $source): ?string
{
    // Two status values in ONE declaration -- an `as const` array, a union type, or an
    // OBJECT LITERAL whose KEYS are the statuses. Two and not three: a list with two of
    // the five is already a second source.
    //
    // ⚠ THE OBJECT-LITERAL FORM WAS MISSED ENTIRELY, AND IT IS THE EASIEST ONE TO WRITE.
    // The pattern matched `[...]` or a line containing `|`, so
    //
    //     const TONE = { 'waiting': 'quiet', 'ready': 'loud' }
    //
    // carried two of the five status values in a hand-written declaration and matched
    // neither alternative: no bracket, no pipe. That is a second source in the shape a tone
    // map, a label map or a sort order actually takes, which is to say the likely one.
    //
    // THE DISCRIMINATOR FOR AN OBJECT LITERAL IS THE TYPE ANNOTATION, NOT THE QUOTING, and
    // the first attempt at this got it wrong. `StatusBadge`'s legitimate tone map is
    //
    //     const TONE: Readonly<Record<PagerStatus, 'waiting' | 'ready' | 'done' | 'stopped'>> = {
    //       waiting: 'waiting', ready: 'ready', completed: 'done', ...
    //     }
    //
    // whose VALUES are two quoted status words -- they are tone names that happen to
    // collide -- so "contains two quoted status strings" flagged the correct pattern. What
    // makes it correct is that it is keyed BY THE GENERATED TYPE: the compiler requires an
    // entry for every status the mirror declares, so the list is DERIVED and a status added
    // in PHP breaks the build there. An object literal with no such annotation enumerates
    // the statuses by hand, whatever its keys are quoted as.
    //
    // So: for an object literal, the KEYS are counted, and a declaration annotated with one
    // of the generated type names is exempt. The type names come from the generated mirror,
    // read from the artefact rather than listed here.
    $generatedTypes = [];
    preg_match_all(
        '/export type (\w+) = \(typeof/',
        (string) file_get_contents(dirname(base_path(), 2).'/'.StatusMirror::RELATIVE_PATH),
        $generatedTypes
    );
    $typeNames = $generatedTypes[1] ?? [];
    expect($typeNames)->not->toBeEmpty('no generated type name was parsed out of the mirror');

    $statuses = ['waiting', 'ready', 'completed', 'cancelled', 'expired'];

    $declarations = [];
    preg_match_all(
        '/(?:const|type)\s+\w+([^=;]*)=\s*(\[[^\]]*\]|\{[^{}]*\}|[^;\n]*\|[^;\n]*)/',
        $source,
        $declarations,
        PREG_SET_ORDER
    );

    foreach ($declarations as $match) {
        $annotation = (string) ($match[1] ?? '');
        $declaration = (string) ($match[2] ?? '');
        $isObjectLiteral = str_starts_with(ltrim($declaration), '{');

        // Keyed by a generated type: exhaustiveness comes from the mirror, not from here.
        foreach ($typeNames as $typeName) {
            if (str_contains($annotation, $typeName)) {
                continue 2;
            }
        }

        $hits = 0;
        foreach ($statuses as $value) {
            // An object literal is judged on its KEYS -- `'waiting':` or `waiting:` --
            // because its values are arbitrary copy. Everything else is judged on the
            // quoted literals it carries, which is what an array or a union is made of.
            $found = $isObjectLiteral
                ? preg_match('/(?:^|[{,\s])\s*(?:\x27'.$value.'\x27|"'.$value.'"|'.$value.')\s*:/', $declaration) === 1
                : str_contains($declaration, "'".$value."'");
            if ($found) {
                $hits++;
            }
        }
        if ($hits >= 2) {
            return $declaration;
        }
    }

    return null;
}

it('bites on every planted form of a second status list, and on no legitimate one', function (): void {
    // THE THREE FORMS THAT MUST FIRE. The first two were measured by hand when the subject
    // of this check was narrowed; the third is QA #14 and fired on neither.
    $offending = [
        'as const array' => "export const STATUSES = ['waiting', 'ready'] as const\n",
        'union type' => "export type Status = 'waiting' | 'ready' | 'completed'\n",
        'object literal, quoted keys' => "const TONE = { 'waiting': 'quiet', 'ready': 'loud' }\n",
        'object literal, multi-line' => "const LABELS = {\n  'waiting': 'Waiting',\n  'expired': 'Expired',\n}\n",
        // The same enumeration with BARE keys and no annotation: still hand-written, still
        // a second source, and the form a label map is most naturally typed in.
        'object literal, bare keys, no annotation' => "const LABELS = {\n  waiting: 'Waiting',\n  ready: 'Ready',\n}\n",
        // A double-quoted variant, because the quoting style is not the property.
        'object literal, double-quoted keys' => "const SORT = { \"waiting\": 0, \"ready\": 1 }\n",
    ];
    foreach ($offending as $what => $source) {
        expect(secondStatusDeclaration($source))->not->toBeNull("the {$what} form was not caught");
    }

    // AND THE LEGITIMATE ONES THAT MUST NOT. A check that fires on the correct pattern gets
    // disabled, which is the other half of every proof in this repository.
    $allowed = [
        // StatusBadge's real tone map: keyed BY the generated type, keys are identifiers.
        'Record keyed by the generated type' => "const TONE: Readonly<Record<PagerStatus, string>> = {\n  waiting: 'waiting',\n  ready: 'ready',\n}\n",
        // One value, named for a single legitimate purpose.
        'a single status value' => "const INITIAL = 'waiting'\n",
        // The generated type imported and used, with no list of its own.
        'an import of the generated union' => "import type { PagerStatus } from '@qrpager/contracts'\nconst s: PagerStatus = 'ready'\n",
        // The real tone map from StatusBadge, verbatim in shape: keyed BY the generated
        // type, so the compiler -- not this file -- is what requires every status.
        'the real StatusBadge tone map' => "const TONE: Readonly<Record<PagerStatus, 'waiting' | 'ready' | 'done' | 'stopped'>> = {\n  waiting: 'waiting',\n  ready: 'ready',\n  completed: 'done',\n  cancelled: 'stopped',\n  expired: 'stopped',\n}\n",
        // A mapped type over the generated union: the same derivation, different syntax.
        'a mapped type over the union' => "const M: { [K in PagerStatus]: number } = { waiting: 0, ready: 1 }\n",
    ];
    foreach ($allowed as $what => $source) {
        expect(secondStatusDeclaration($source))->toBeNull("the {$what} form was wrongly flagged");
    }
});

it('writes no second DECLARATION of a status list outside the generated mirror', function (): void {
    // §6.2's clause from the other side: the generated file is the only place a TypeScript
    // status list may be DECLARED. A hand-written copy elsewhere is the defect ADR-003
    // describes, and it would be invisible to the staleness check, which watches one path.
    //
    // ⚠ THE SUBJECT OF THIS ASSERTION WAS NARROWED, and it is stated here rather than done
    // quietly (QRPAGER-15 §8). It first flagged any file containing three or more pager
    // status values, and it caught `packages/contracts/test/mirror.test.ts` -- the test that
    // PINS the generated mirror's contents, which cannot check the values without naming
    // them. That is the opposite of a second source: it is the thing that would catch one.
    //
    // So the subject changed from "the vocabulary appears" to "a second DECLARATION exists",
    // which is what §6.2 is actually about. Two consequences, both deliberate:
    //
    //   - TEST files are out of scope. A test naming a value it asserts on is the same
    //     allowance the §12 lint rule already makes for tests, and for the same reason.
    //   - the SHAPE is matched, not the words: a `const … = [` or a `type … =` union
    //     carrying status literals. `StatusBadge`'s `Record<PagerStatus, …>` tone map keyed
    //     BY the generated type is the correct pattern and must not fire -- it has no list
    //     of its own, which is the whole point of the generated type existing.
    $root = dirname(base_path(), 2);
    $mirror = realpath($root.'/'.StatusMirror::RELATIVE_PATH);

    $offenders = [];
    foreach (['packages', 'apps/web', 'apps/site'] as $dir) {
        $base = $root.'/'.$dir;
        if (! is_dir($base)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                static fn (SplFileInfo $f): bool => ! in_array($f->getFilename(), ['node_modules', '.next'], true)
            )
        );

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || ! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
                continue;
            }

            $path = (string) $file->getRealPath();
            $relative = str_replace($root.'/', '', $path);

            // The generated mirror itself, and tests, which may name what they assert on.
            if ($path === $mirror || str_contains($relative, '/test/') || str_contains($relative, '.test.')) {
                continue;
            }

            if (secondStatusDeclaration((string) file_get_contents($path)) !== null) {
                $offenders[] = $relative;
            }
        }
    }

    expect($offenders)->toBe([], 'these files declare a second, hand-written copy of the status enum');
});
