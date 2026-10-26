<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
 * R-016, the mechanical half.
 *
 * audit_log is append-only: the trigger refuses DELETE and TRUNCATE, which is a gate
 * clause of its own, so the table is deliberately absent from TestCase::RESETTABLE and
 * grows monotonically across runs on a developer machine. CI builds from an empty
 * database every run and is immune by construction.
 *
 * That asymmetry is the danger, and it is specific and delayed: THE FIRST UNFILTERED
 * audit_log COUNT ANYONE ADDS PASSES ON CI AND FAILS LOCALLY. It is the same "correct
 * on a clean build, wrong on a machine carrying earlier state" shape as the stale grant
 * migration that cost a morning on 2026-10-26. A reviewer reading the diff has no
 * reason to notice; this test does.
 *
 * It scans statements rather than lines, so a filter written on the next line still
 * counts, and it only looks at statements that READ rows -- a privilege probe, a
 * refused DELETE and a list of table names are not assertions about row contents and
 * have nothing to filter.
 */

/** Reading row data, as opposed to asking the catalog a question about the table. */
const READS_ROWS = ['->count()', '->exists()', '->get()', '->first()', '->value(', '->pluck(', '->sum('];

/**
 * The source with comments blanked out, line numbers preserved.
 *
 * The same lesson the repo guard learned in 0b59659, arrived at the same way: this
 * file's own docblock SHOWS the forbidden shape while explaining it, so a text scan
 * flags the documentation of its own rule -- measured, it reported line 39 of itself.
 * token_get_all makes comments invisible; replacing each one with its own newlines
 * keeps every reported line number honest.
 */
function auditLogVisibleSource(string $source): string
{
    $visible = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $visible .= str_repeat("\n", substr_count($token[1], "\n"));

            continue;
        }

        $visible .= is_array($token) ? $token[1] : $token;
    }

    return $visible;
}

/**
 * The region that has to carry the filter, for each mention of the table.
 *
 * Starts at the mention's own statement and ends at that statement's `;` -- UNLESS a
 * `{` opens first, in which case it runs to the matching `}`. The brace rule is the
 * whole reason this is not a one-line split, and it was added because the first version
 * missed the site PLAN.md names by name. WorkerScopeTest reads the table through a LOOP
 * VARIABLE:
 *
 *     foreach (['tenants', ..., 'audit_log'] as $table) {
 *         expect($this->asWorker()->table($table)->count())->toBe(0);
 *     }
 *
 * Splitting on `;` puts `audit_log` in one piece and `->count()` in the next, so the
 * read is invisible -- the scan passed with that count unfiltered, measured. Extending
 * to the enclosing block is what makes a read through a variable visible, and it is the
 * realistic shape: a test that checks several tables at once is how this failure
 * actually arrives.
 *
 * Crude about strings and comments on purpose. A real parser would be more precise and
 * would be a second thing to maintain, and the rule only needs to find a `where`
 * somewhere in the same region as the read. Erring wide means a false positive is a
 * filter somebody has to add, which is the direction worth erring in.
 *
 * @return list<array{string, int}>
 */
function auditLogStatements(string $source): array
{
    $source = auditLogVisibleSource($source);
    $regions = [];
    $offset = 0;

    while (($at = strpos($source, 'audit_log', $offset)) !== false) {
        $offset = $at + 9;

        $start = 0;

        foreach ([';', '{', '}'] as $boundary) {
            $found = strrpos(substr($source, 0, $at), $boundary);
            $start = $found === false ? $start : max($start, $found + 1);
        }

        $depth = 0;
        $end = strlen($source);

        for ($i = $start; $i < strlen($source); $i++) {
            $character = $source[$i];

            if ($character === '{') {
                $depth++;
            } elseif ($character === '}') {
                if ($depth === 0) {
                    $end = $i;

                    break;
                }

                $depth--;
            } elseif ($character === ';' && $depth === 0) {
                $end = $i;

                break;
            }
        }

        $regions[] = [substr($source, $start, $end - $start), 1 + substr_count(substr($source, 0, $at), "\n")];
    }

    return $regions;
}

/** Reading row data, as opposed to asking the catalog a question about the table. */
function auditLogReadsRows(string $statement): bool
{
    foreach (READS_ROWS as $read) {
        if (str_contains($statement, $read)) {
            return true;
        }
    }

    return false;
}

/** `where(` for the builder, `where ` for raw SQL. Either is a filter; neither is not. */
function auditLogIsFiltered(string $statement): bool
{
    return str_contains($statement, 'where(') || str_contains($statement, 'where ');
}

it('has no audit_log assertion in the suite that reads rows without a filter', function (): void {
    $offenders = [];

    foreach (File::allFiles(base_path('tests')) as $file) {
        // .fixture, not .php: the deliberately bad statements live beside this file
        // under tests/Fixtures/auditlog and are fed in explicitly below. The first
        // version of this test wrote them inline and the scan flagged its own fixture
        // -- which is exactly what the repo guard went through in 0b59659, and the
        // answer is the same one: move the bad shape into a fixture rather than exempt
        // the test by path, because exempting the test hides the one thing worth
        // knowing.
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = 'tests/'.$file->getRelativePathname();

        foreach (auditLogStatements((string) $file->getContents()) as [$statement, $line]) {
            if (! auditLogReadsRows($statement) || auditLogIsFiltered($statement)) {
                continue;
            }

            $offenders[] = "{$relative}:{$line}";
        }
    }

    expect($offenders)->toBe([], 'audit_log row reads with no filter: '.implode(', ', $offenders)
        ."\nThe table is never truncated, so an unfiltered count passes on CI's empty "
        .'database and fails on a developer machine. Filter on the tenant, the action, or '
        .'$this->runMarker() -- never a DELETE, which the trigger refuses.');
});

it('does not flag the documentation of its own rule', function (): void {
    // This file's docblock shows the forbidden shape in order to explain it, and the
    // first version of the scan reported line 39 of itself. Comments are invisible
    // because the source is read through token_get_all -- the same thing the repo guard
    // does, for the same reason.
    $own = (string) file_get_contents(__FILE__);

    expect(auditLogVisibleSource($own))->not->toContain('foreach ([\'tenants\'')
        // ...and the line numbering survives, so a real offender is still reported at
        // the line it is on.
        ->and(substr_count(auditLogVisibleSource($own), "\n"))->toBe(substr_count($own, "\n"));
});

it('fails on a deliberately bad fixture, so the scan above is worth something', function (): void {
    // A meta-test nobody has seen fail is a meta-test nobody should trust.
    $bad = (string) file_get_contents(base_path('tests/Fixtures/auditlog/unfiltered-count.php.fixture'));

    $statements = auditLogStatements($bad);

    expect($statements)->toHaveCount(1)
        ->and(auditLogReadsRows($statements[0][0]))->toBeTrue()
        ->and(auditLogIsFiltered($statements[0][0]))->toBeFalse();

    // ...and accepts the same read once it is filtered, with the filter on the NEXT
    // LINE, which is the case a line-based scan would get wrong.
    $good = (string) file_get_contents(base_path('tests/Fixtures/auditlog/filtered-count.php.fixture'));
    $filtered = auditLogStatements($good);

    expect($filtered)->toHaveCount(1)
        ->and(auditLogReadsRows($filtered[0][0]))->toBeTrue()
        ->and(auditLogIsFiltered($filtered[0][0]))->toBeTrue()
        ->and(substr_count($filtered[0][0], "\n"))->toBeGreaterThan(0);
});

it('gives every row the suite writes a run marker to filter on', function (): void {
    $marker = $this->runMarker();

    expect($marker)->toStartWith('run-')
        // Stable within a run: every row and every assertion in one `php artisan test`
        // has to agree, or the filter finds nothing.
        ->and($this->runMarker())->toBe($marker)
        ->and($this->auditAction('probe'))->toBe('probe.'.$marker)
        ->and(json_decode($this->auditContext(), true))->toBe(['run' => $marker])
        ->and(json_decode($this->auditContext(['why' => 'x']), true))->toBe(['run' => $marker, 'why' => 'x']);
});

it('writes the marker into audit_log.context where the suite seeds a row', function (): void {
    $tenant = $this->makeTenant();
    $this->seedEveryTenantTable($tenant);

    $row = $this->asRoot()->table('audit_log')
        ->where('tenant_id', $tenant)
        ->where('action', $this->auditAction('seed.created'))
        ->first();

    expect($row)->not->toBeNull()
        ->and(json_decode((string) $row?->context, true))->toBe(['run' => $this->runMarker()]);
});
