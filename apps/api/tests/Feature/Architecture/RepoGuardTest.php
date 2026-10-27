<?php

declare(strict_types=1);

use Guard\SourceGuard;
use Illuminate\Support\Facades\Process;
use Tests\Support\GuardFixtures;

/*
 * "A repo guard that rejects session-scoped tenant settings and unknown app.* names",
 * and "the repo guard ... fails on a deliberately bad file (C1/C2 enforced
 * mechanically)" -- PLAN.md §3, row 2. Closes R-010, whose finding was that §4
 * asserted this guard as a fact while it did not exist, and R-019, which is the one
 * exception it carries.
 *
 * NOT ONE FORBIDDEN LITERAL APPEARS IN THIS FILE, and that is a requirement rather
 * than a style: the guard scans tests/ too. The first version of this file spelled the
 * expected values inline -- 'SET LOCAL app.tenant_id', 'app.current_tenant_id' -- and
 * the guard reported 13 violations in it. Exempting the guard's own test by path would
 * have hidden the one thing worth knowing: every bad shape belongs in
 * guard/fixtures/repo, and the assertions below check the guard against the fixture's
 * own text instead of against a copy of it.
 */

it('passes on the real tree, so a failure below is the fixture and not the repo', function (): void {
    // SourceGuard::PATHS, never a copy of it. This assertion used to spell the list out
    // again, so it could agree with its own duplicate while CI scanned a different set
    // (QA H-1 / security #5).
    expect(GuardFixtures::guard()->scan(SourceGuard::PATHS))->toBe([])
        ->and(GuardFixtures::guard()->scanScannablePaths(SourceGuard::PATHS))->toBe([])
        ->and(GuardFixtures::guard()->scanConfiguredGucs())->toBe([]);
});

it('is wired as a composer script and exits 0 on the real tree', function (): void {
    $result = Process::path(base_path())->run('composer guard');

    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput())
        ->and($result->output())->toContain('C1 and C2 hold');
});

/*
 * Rule 6, and the reason it exists: the guard reported a PASS for paths it had never
 * opened. `guard/bin/guard src nonexistent` printed "C1 and C2 hold across 2 scanned
 * path(s)" and exited 0 -- the count was the paths it was handed, not the files it
 * read, and a path that resolves to nothing was skipped in silence. Renaming app/ would
 * have turned the whole guard off with a green CI.
 *
 * R-029's shape, which is why these assertions are about what the guard DID rather than
 * about what it concluded.
 */

it('counts the files it read, not the paths it was given', function (): void {
    $files = GuardFixtures::guard()->scannedFiles(SourceGuard::PATHS);

    expect($files)->not->toBe([], 'the real tree must produce files to scan');

    $result = Process::path(base_path())->run('composer guard');

    // The number in the success line is the file count, so a run that opened nothing
    // cannot print a plausible pass.
    expect($result->output())->toContain(count($files).' file(s)');
});

it('refuses a configured path that does not resolve, rather than scanning nothing', function (): void {
    $violations = GuardFixtures::guard()->scanScannablePaths(['app', 'definitely-not-a-path-in-this-repo']);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->rule)->toBe(SourceGuard::UNSCANNABLE_PATH)
        ->and($violations[0]->file)->toBe('definitely-not-a-path-in-this-repo');
});

it('refuses a path that resolves but produces no file to read', function (): void {
    // A DEDICATED FIXTURE, because this case used to point at app/Features with the
    // comment "holds only .gitkeep until Task 3 puts a module in it". Task 3 put a module
    // in it and this went red -- not because the guard regressed, but because the
    // directory it borrowed had been repurposed by ordinary work. The comment predicted
    // its own expiry and nothing acted on the prediction, which is §3.2's recurring shape.
    //
    // guard/fixtures/no-php/ holds a README explaining that it must stay free of .php
    // files, which is a contract nobody can break by writing a feature.
    $violations = GuardFixtures::guard()->scanScannablePaths(['guard/fixtures/no-php']);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->rule)->toBe(SourceGuard::UNSCANNABLE_PATH)
        ->and($violations[0]->detail)->toContain('never read')
        ->and($violations[0]->file)->toBe('guard/fixtures/no-php');
});

it('exits non-zero through the real binary when a path cannot be scanned', function (): void {
    // Through the script, because the exit code is what CI reads and the old behaviour
    // was exit 0 with a reassuring message.
    $result = Process::path(base_path())->run('php guard/bin/guard definitely-not-a-path-in-this-repo');

    expect($result->exitCode())->toBe(1, $result->output().$result->errorOutput())
        ->and($result->errorOutput())->toContain(SourceGuard::UNSCANNABLE_PATH);
});

it('rejects a session-scoped tenant setting', function (): void {
    expect(GuardFixtures::violations('session-scoped-set-config', SourceGuard::SESSION_SCOPED_SET_CONFIG))
        ->toHaveCount(1);
});

it('rejects a bound third argument, which the source cannot prove is true', function (): void {
    expect(GuardFixtures::violations('bound-third-argument', SourceGuard::SESSION_SCOPED_SET_CONFIG))
        ->toHaveCount(1);
});

it('rejects an absent third argument, which Postgres defaults to session scope', function (): void {
    $violations = GuardFixtures::violations('missing-third-argument', SourceGuard::SESSION_SCOPED_SET_CONFIG);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->detail)->toContain('absent');
});

it('rejects a raw SET of an app GUC, in both of its spellings', function (): void {
    $violations = GuardFixtures::violations('raw-set-app-guc', SourceGuard::RAW_SET_APP_GUC);
    $text = GuardFixtures::text('raw-set-app-guc');

    expect($violations)->toHaveCount(2, 'the plain and the LOCAL spelling must both be reported');

    // Each report quotes text that is really in the fixture, which proves the guard
    // found the statement rather than this test describing one.
    foreach ($violations as $violation) {
        $quoted = GuardFixtures::quoted($violation);

        expect($text)->toContain($quoted)
            ->and(strtolower($quoted))->toStartWith('set ');
    }

    expect($violations[0]->detail)->not->toBe($violations[1]->detail);
});

it('rejects every app GUC name that is not one of C1 two', function (): void {
    $violations = GuardFixtures::violations('unknown-app-guc', SourceGuard::UNKNOWN_APP_GUC);
    $text = GuardFixtures::text('unknown-app-guc');

    // Two shapes in that fixture: a name written into current_setting, and a name
    // travelling as a bound parameter -- which is how every name in this codebase
    // actually reaches Postgres.
    expect($violations)->toHaveCount(2);

    $names = [];

    foreach ($violations as $violation) {
        $name = GuardFixtures::quoted($violation);

        expect($text)->toContain($name)
            ->and(SourceGuard::GUCS)->not->toContain($name);

        $names[] = $name;
    }

    expect(array_unique($names))->toHaveCount(2, 'both names, not the same one twice');
});

it('rejects a third configured GUC, which no SQL would reveal', function (): void {
    $violations = GuardFixtures::guard()->scanConfiguredGucs(GuardFixtures::path('unexpected-configured-gucs'));

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->rule)->toBe(SourceGuard::UNEXPECTED_CONFIGURED_GUCS);

    $reported = (string) preg_replace('/^configured GUCs are \[(.+?)\].*$/s', '$1', $violations[0]->detail);
    $extra = array_values(array_diff(
        array_map('trim', explode(',', $reported)),
        SourceGuard::GUCS,
    ));

    expect($extra)->toHaveCount(1)
        ->and(GuardFixtures::text('unexpected-configured-gucs'))->toContain($extra[0]);
});

/**
 * Copy a rule 8 fixture into the repository under a real .md name, run the rule, remove it.
 *
 * NO GIT STAGING. The first version staged the planted file with `git add -N`, because the
 * rule listed tracked markdown -- and that broke inside bin/coverage's container, where git
 * refuses the mount for dubious ownership, so three of these cases failed there while
 * passing on the host. The rule walks the filesystem now, and so does this.
 *
 * A crash between the copy and the unlink leaves a stray `.md` in `docs/` -- which the guard
 * itself then reports on the next run, so this helper's own failure mode is the guard
 * working.
 */
function withPlantedDoc(string $fixture, string $planted, Closure $assert): void
{
    $root = dirname(base_path(), 2);
    $source = base_path("guard/fixtures/docs/{$fixture}");
    $target = $root.'/'.$planted;

    expect(is_file($source))->toBeTrue("the fixture {$fixture} is missing");
    expect(is_file($target))->toBeFalse("{$planted} already exists; refusing to overwrite it");

    copy($source, $target);

    try {
        $assert((new SourceGuard(base_path()))->scanCanonicalDocs());
    } finally {
        @unlink($target);
    }
}

it('passes rule 8 on the real tree, so a failure below is the fixture and not the repo', function (): void {
    expect((new SourceGuard(base_path()))->scanCanonicalDocs())->toBe([]);
});

it('rejects a second document carrying a task-table heading — rule 8, C7', function (): void {
    // THE DEFECT THIS EXISTS FOR. docs/claude/PLAN.md was a 1079-line Task-1-era copy of a
    // 2583-line file, with its own "## 3. Task table", committed by e5ebc9a in a commit
    // whose message says "Record only; no behaviour". C7 allows one task table, in
    // docs/PLAN.md; CLAUDE.md says nothing else in this repo states current state. A second
    // one is a second claimant to rank 3 of §0, and the danger is not the bytes — it is that
    // the next session to read the repository may act on the stale one.
    withPlantedDoc('stale-plan.md.fixture', 'docs/A-SECOND-PLAN.md', function (array $violations): void {
        expect($violations)->toHaveCount(1)
            ->and($violations[0]->rule)->toBe(SourceGuard::DUPLICATE_CANONICAL_DOC)
            ->and($violations[0]->file)->toBe('docs/A-SECOND-PLAN.md')
            ->and($violations[0]->detail)->toContain('task-table heading')
            // The line is the heading's, not the file's first line, so the report points at
            // the thing rather than at the document.
            ->and($violations[0]->line)->toBeGreaterThan(1);
    });
});

it('rejects a byte-identical copy of the Blueprint — rule 8, clause (b)', function (): void {
    // docs/claude/BLUEPRINT.md was byte-identical to docs/BLUEPRINT.md. The Blueprint is an
    // ARCHIVE and a binding baseline (§0), so a second copy is a second thing to keep in
    // step — and the one that drifts is the one somebody reads.
    withPlantedDoc('duplicate-blueprint.md.fixture', 'docs/BLUEPRINT-COPY.md', function (array $violations): void {
        expect($violations)->toHaveCount(1)
            ->and($violations[0]->rule)->toBe(SourceGuard::DUPLICATE_CANONICAL_DOC)
            ->and($violations[0]->file)->toBe('docs/BLUEPRINT-COPY.md')
            ->and($violations[0]->detail)->toContain('byte-identical');
    });
});

it('does not flag prose that merely mentions a task table, only a heading', function (): void {
    // PROJECT_INSTRUCTIONS.md §3's C7 row says "task table duplicated verbatim" in prose, and
    // §7 mentions it too. A word-matching rule would flag the documentation of the rule it
    // enforces — which is the mistake rule 1 already has a case against. Both those files are
    // tracked .md and the real-tree case above passes, which is the proof; this pins the
    // pattern directly so the reason survives a refactor.
    expect(preg_match(SourceGuard::TASK_TABLE_HEADING, 'the §5 task table duplicated verbatim'))->toBe(0)
        ->and(preg_match(SourceGuard::TASK_TABLE_HEADING, 'One task table only, in PLAN.md'))->toBe(0)
        ->and(preg_match(SourceGuard::TASK_TABLE_HEADING, '## 3. Task table'))->toBe(1)
        ->and(preg_match(SourceGuard::TASK_TABLE_HEADING, '#### the task Table'))->toBe(1);
});

it('does not flag its own fixtures, which deliberately contain both defects', function (): void {
    // guard/fixtures/docs holds a stale plan WITH a task table and a byte-identical copy of
    // the Blueprint, on purpose. A rule that reported its own proof is one somebody switches
    // off, so that directory is pruned -- and this pins the pruning, because the real-tree
    // case passing could also mean the walk is finding nothing at all.
    $guard = new SourceGuard(base_path());

    expect($guard->scanCanonicalDocs())->toBe([]);

    // The fixtures really are there and really would be violations if they were seen.
    $stale = base_path('guard/fixtures/docs/stale-plan.md.fixture');
    $copy = base_path('guard/fixtures/docs/duplicate-blueprint.md.fixture');

    expect(preg_match(SourceGuard::TASK_TABLE_HEADING, (string) file_get_contents($stale)))->toBe(1)
        ->and(hash_file('sha256', $copy))
        ->toBe(hash_file('sha256', $guard->repositoryRoot().'/'.SourceGuard::CANONICAL_DOCS['blueprint']));
});

it('exits non-zero through the real binary when a canonical document is duplicated', function (): void {
    // The rule reaching the exit code, not just the class. Same shape as the unscannable-path
    // case: a guard whose violations do not fail the process is not a guard.
    withPlantedDoc('stale-plan.md.fixture', 'docs/A-THIRD-PLAN.md', function (): void {
        $result = Process::path(base_path())->run('php guard/bin/guard');

        expect($result->exitCode())->toBe(1)
            ->and($result->errorOutput())->toContain('docs/A-THIRD-PLAN.md')
            ->and($result->errorOutput())->toContain('C7: one task table only');
    });
});

it('does not read comments, so the prose explaining C2 is not a violation', function (): void {
    expect(GuardFixtures::text('comment-only'))->toContain('@see')
        ->and(GuardFixtures::guard()->scan([GuardFixtures::path('comment-only')]))->toBe([]);
});

it('scopes its one exception to a method, not to a file and not to tests as a class', function (): void {
    // R-019's requirement, and the half that is easy to get wrong. The forbidden shape
    // is allowed inside Composition::setSessionGuc() and nowhere else -- not even
    // elsewhere in the same file. Proven through scanSource() with the real path and
    // altered source, so nothing on disk is touched: a guard test that rewrites a
    // tracked file to prove a point is a worse bug than the one it is proving.
    $path = 'tests/Support/Composition.php';
    $real = (string) file_get_contents(base_path($path));

    expect(GuardFixtures::guard()->scanSource($path, $real))->toBe([], 'the real helper is the named exception');

    // The offending statement comes out of the fixture, so it is not written here.
    $statement = '';

    foreach (explode("\n", GuardFixtures::text('session-scoped-set-config')) as $line) {
        if (str_contains($line, 'set_config') && str_contains($line, '$db->select')) {
            $statement = trim(str_replace('$db->', 'DB::connection($connection)->', $line));
        }
    }

    expect($statement)->not->toBe('', 'the fixture no longer carries the statement this test moves');

    // ONE injection, not str_replace's all-occurrences: `return self::guc($connection,`
    // appears in tenantGuc() and in bypassGuc(), so replacing both put the shape in two
    // methods and the guard reported 2 where this test expected 1. The count was right
    // and the test was wrong, which is the only reason it is worth a comment.
    $moved = (string) preg_replace(
        '/return self::guc\(\$connection, /',
        str_replace('$', '\$', $statement).' return self::guc(\$connection, ',
        $real,
        1,
    );

    expect($moved)->not->toBe($real, 'the probe edit did not apply')
        ->and(substr_count($moved, 'set_config'))->toBe(
            substr_count($real, 'set_config') + 1,
            'exactly one statement was injected',
        );

    $violations = GuardFixtures::guard()->scanSource($path, $moved);

    expect($violations)->toHaveCount(1, 'the same shape in a second method of the same file must be reported')
        ->and($violations[0]->rule)->toBe(SourceGuard::SESSION_SCOPED_SET_CONFIG);
});
