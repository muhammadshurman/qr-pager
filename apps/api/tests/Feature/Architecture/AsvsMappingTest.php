<?php

declare(strict_types=1);

/*
 * ================================================================================
 * `docs/asvs-l2.md` IS HELD TO THE TREE — Task 12
 * ================================================================================
 *
 * `§3` row 12: *"ASVS L2 table complete with evidence per item."* A table of 59 rows citing
 * 123 paths is exactly the kind of artefact that is true on the day it is written and quietly
 * false six commits later, and this project has a name for that: `R-136`, *a citation to a
 * control that is not where it says it is*, which `RepoGuardTest` calls **`8a`'s first
 * blocking finding**.
 *
 * `RepoGuardTest` already enforces it for docblocks that name a test class. It does not read
 * `docs/`, so this file does the same job for the mapping — and it is not hypothetical that
 * such a check bites: during this very task `config/cors.php`'s docblock cited
 * `CorsPolicyTest` while the case lived in `ResponseHeaderTest`, and `RepoGuardTest` went red
 * with *"CorsPolicyTest (cited by config/cors.php)"*. The guard was right; the fix was to
 * create the file.
 *
 * ⚠ WHAT THIS DOES **NOT** CLAIM. It does not check that a cited test actually asserts the
 * requirement beside it — no parser can. It checks the three things that CAN rot mechanically:
 * a path that no longer exists, a row with no verdict, and a **MET** row with no evidence at
 * all. The judgement stays with a reader; what this removes is the possibility of a reader
 * trusting a path that is gone.
 */

/** The mapping, as lines. */
function asvsDocument(): string
{
    $path = base_path('../../docs/asvs-l2.md');

    expect(is_file($path))->toBeTrue('docs/asvs-l2.md is missing: `§3` row 12 requires it at the gate');

    return (string) file_get_contents($path);
}

/**
 * Every requirement row, parsed.
 *
 * @return list<array{id: string, requirement: string, verdict: string, evidence: list<string>, notes: string}>
 */
function asvsRows(): array
{
    $rows = [];

    foreach (explode("\n", asvsDocument()) as $line) {
        // A requirement row starts with a BOLD id and has five columns. The legend and totals
        // tables at the top have two or three, so they are excluded by the count rather than
        // by a line number nobody maintains.
        if (! str_starts_with($line, '| **')) {
            continue;
        }

        $cells = array_map('trim', explode('|', trim($line, '|')));

        if (count($cells) !== 5) {
            continue;
        }

        [$id, $requirement, $verdict, $evidence, $notes] = $cells;

        $id = trim($id, '* ');

        // The legend rows are `| **MET** | ... |` with two cells, already excluded; the totals
        // table likewise. Anything left with a dotted or project-prefixed id is a requirement.
        if ($id === '' || $requirement === '') {
            continue;
        }

        preg_match_all('/`([^`]+)`/', $evidence, $cited);

        $rows[] = [
            'id' => $id,
            'requirement' => $requirement,
            'verdict' => trim($verdict, '* '),
            'evidence' => $evidence === '—' ? [] : array_values($cited[1]),
            'notes' => $notes,
        ];
    }

    return $rows;
}

it('parses the mapping and finds a real table, so every case below has a subject', function (): void {
    // THE POSITIVE CONTROL FOR THE PARSER ITSELF. Every case in this file walks `asvsRows()`,
    // and a parser that silently returned [] would make all of them pass vacuously — the shape
    // `§4a` records four instances of. The live figure is 59 rows; 40 is a floor that cannot
    // be reached by accident and does not need editing when a row is added.
    $rows = asvsRows();

    expect(count($rows))->toBeGreaterThan(
        40,
        'only '.count($rows).' rows parsed out of docs/asvs-l2.md: the parser is broken, or the table is',
    );

    // And the ids really are ids rather than stray bold text picked up by the pattern.
    foreach ($rows as $row) {
        expect(preg_match('/^(\d+\.\d+\.\d+|[A-Z]+-\d+)$/', $row['id']))
            ->toBe(1, "[{$row['id']}] is not an ASVS id or a project-specific one");
    }
});

it('cites no evidence path that does not exist — the R-136 shape, closed from the docs side', function (): void {
    $repo = realpath(base_path('../..'));

    expect($repo)->not->toBeFalse('the repository root could not be resolved');

    $missing = [];

    foreach (asvsRows() as $row) {
        foreach ($row['evidence'] as $cited) {
            /*
             * ⚠ EXISTENCE IS THE WHOLE RULE, AND THE FIRST DRAFT ADDED A SECOND ONE THAT WAS
             * WRONG. It also required a `/` in every citation, on the theory that a
             * slash-less string is prose that landed in the wrong column — and the first run
             * failed on `compose.yaml`, which is a real file at the repository root. The
             * heuristic was guarding against something existence already catches: prose does
             * not exist as a file. A check that invents a constraint the thing it checks does
             * not have is a check that has to be argued with, which is the opposite of useful.
             */
            if (! is_file($repo.'/'.$cited) && ! is_dir($repo.'/'.$cited)) {
                $missing[] = "{$row['id']}: {$cited}";
            }
        }
    }

    // One list rather than the first failure, so a stale table names every bad citation at
    // once — `RouteSurfaceTest`'s lesson about asserting a diff instead of looping.
    expect($missing)->toBe([], "evidence paths that do not exist:\n  ".implode("\n  ", $missing));
});

it('gives every row a verdict from the legend, and nothing softer', function (): void {
    // ⚠ A CLOSED SET, AND THAT IS THE WHOLE VALUE OF THE COLUMN. A mapping whose verdicts
    // drift into "mostly", "largely" or "n/a" is a mapping that can describe a gap as a pass.
    // Four values, and two of them say the thing is not done.
    $allowed = ['MET', 'PARTIAL', 'NOT MET', 'NOT MET (no environment)'];

    $bad = [];

    foreach (asvsRows() as $row) {
        if (! in_array($row['verdict'], $allowed, true)) {
            $bad[] = "{$row['id']}: [{$row['verdict']}]";
        }
    }

    expect($bad)->toBe([], "verdicts outside the legend:\n  ".implode("\n  ", $bad));
});

it('backs every MET row with at least one cited path, because MET is the claim', function (): void {
    // PARTIAL and NOT MET are allowed to cite nothing: *"it is not done"* needs no evidence,
    // and that is the honest direction. **MET** is a claim that a test drives the requirement,
    // and a claim with no citation is the one combination this table must not contain.
    $unevidenced = [];

    foreach (asvsRows() as $row) {
        if ($row['verdict'] === 'MET' && $row['evidence'] === []) {
            $unevidenced[] = "{$row['id']}: {$row['requirement']}";
        }
    }

    expect($unevidenced)->toBe([], "MET with no evidence:\n  ".implode("\n  ", $unevidenced));
});

it('names every row ADR-002 made this task responsible for', function (): void {
    /*
     * ⚠ THE TWO CLAUSES THE GATE NAMES IN SO MANY WORDS, asserted by id rather than left to a
     * reader's count. `§3` row 12: *"ASVS L2 table complete with evidence per item —
     * **including file-upload handling and mail header injection** (ADR-002)"*, and
     * `docs/adr/002-storage-and-mail.md` says the same from its own side: *"Task 12 must cover
     * both in the ASVS L2 mapping: upload handling, and mail header injection."*
     *
     * A table can be long and still miss the two items somebody wrote a gate clause about.
     */
    $byId = [];

    foreach (asvsRows() as $row) {
        $byId[$row['id']] = $row;
    }

    // Mail header injection, and the three file chapters ADR-002's media library creates.
    foreach (['5.2.3', '12.1.1', '12.2.1', '12.3.1', '12.4.1'] as $id) {
        expect(array_key_exists($id, $byId))->toBeTrue("ADR-002's clause needs a row for [{$id}]");

        expect($byId[$id]['evidence'])->not->toBe([], "[{$id}] cites no evidence");
    }

    // And the two his own ticket comment added (QRPAGER-19 comment 10012): the Redis keyspace
    // and the repo guard. Project-specific ids, because ASVS has no chapter for "the one
    // tenant-data store with no enforcement of its own".
    foreach (['REDIS-1', 'GUARD-1'] as $id) {
        expect(array_key_exists($id, $byId))->toBeTrue("QRPAGER-19 comment 10012 needs a row for [{$id}]");
    }
});

it('states the gate clauses it does NOT carry, so the table cannot read as the whole gate', function (): void {
    // `§3.1` row 12's warning, in the project's own words: *"Zero high/critical from SAST and
    // DAST is a tool-output claim: a clean run is not proof, only an absence of findings.
    // Keep it, and do not let it stand in for the three clauses above it."* A mapping document
    // is exactly the artefact that would be read as the whole gate, so it has to point at the
    // other three itself.
    $document = asvsDocument();

    foreach ([
        'unsafe-inline',
        'FuzzSurfaceTest',
        'openapi:check',
        'SurfaceDisclosureTest',
    ] as $needle) {
        expect(str_contains($document, $needle))
            ->toBeTrue("the mapping does not point at [{$needle}], so it reads as the whole gate");
    }

    // And it carries the named invariant verbatim, which is what `§11` requires to be written
    // before the work rather than after the findings.
    expect(str_contains($document, 'at any `APP_DEBUG` setting'))->toBeTrue()
        ->and(str_contains($document, 'derived from'))->toBeTrue();
});
