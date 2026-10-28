<?php

declare(strict_types=1);

use App\Domain\Landing\ThemeTokens;
use Guard\SourceGuard;
use Guard\Violation;
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

it('rejects a negated variadic matcher given a message, which asserts nothing — rule 7', function (): void {
    // §4 › Postgres traps: "The guard must be proven to fail on a deliberately bad file."
    // This is rule 7's bad file, and it carries FOUR bad shapes rather than one, so the
    // rule is exercised rather than one spelling of it: both variadic matchers, a call
    // broken across lines with a comment in the middle, and one whose first argument is a
    // nested call (so the arity count has to be depth-aware).
    //
    // What rule 7 is for: Pest's toContain and toContainEqual are the only two assertion
    // matchers that are variadic -- 77 others take a real $message -- so under ->not-> a
    // message is read as a second needle and the assertion becomes "does not contain
    // BOTH", trivially true. Three assertions in this repository were that shape and each
    // guarded a named invariant; PLAN.md §4a has the inventory and §1 the decision log.
    $violations = GuardFixtures::violations('vacuous-not-contains', SourceGuard::VACUOUS_NEGATED_CONTAINS);

    expect($violations)->toHaveCount(4)
        ->and($violations[0]->detail)->toContain('VARIADIC');
});

it('leaves the correct forms alone, so rule 7 is not something anyone has to work around', function (): void {
    // The same fixture carries three CORRECT shapes below its bad ones, and none may be
    // flagged: one needle; a needle with a comma INSIDE it (RefusalDiagnosticsTest has a
    // real one); and the positive `toContain($a, $b)`, which legitimately means "contains
    // both" and which fails loudly when it IS misused as a message, so it self-corrects.
    //
    // A guard that cried wolf on any of these would be switched off by the first person
    // who had to read its output, and then it would stop catching the real thing too.
    $violations = GuardFixtures::violations('vacuous-not-contains', SourceGuard::VACUOUS_NEGATED_CONTAINS);
    $lines = array_map(fn ($violation): int => $violation->line, $violations);

    // The four bad shapes are all in the first `it`, the three good ones in the second.
    $source = GuardFixtures::text('vacuous-not-contains');
    $secondBlock = (int) array_search(true, array_map(
        fn (string $line): bool => str_contains($line, 'is NOT flagged when it is correct'),
        explode("\n", $source),
    ), true) + 1;

    foreach ($lines as $line) {
        expect($line)->toBeLessThan($secondBlock,
            "rule 7 flagged line {$line}, which is one of the CORRECT forms below line {$secondBlock}");
    }
});

it('scopes rule 7 exception to one function, so the guard cannot be switched off by a file', function (): void {
    // Rule 7's positive control writes the broken shape on purpose, so it is excepted --
    // by file AND by function name, the same way R-019's set_config helper is. A whole-file
    // exception would stop guarding the file that documents the rule, which is the file
    // most likely to grow a second copy of the mistake.
    $guard = new SourceGuard(base_path());

    $excepted = <<<'PHP'
        <?php
        function pestReadsASecondArgumentAsANeedle(): bool {
            expect('x')->not->toContain('x', 'message');
            return true;
        }
        function somethingElse(): void {
            expect('x')->not->toContain('x', 'message');
        }
        PHP;

    $violations = array_values(array_filter(
        $guard->scanSource('tests/Feature/Architecture/ExpectationApiTest.php', $excepted),
        fn ($violation): bool => $violation->rule === SourceGuard::VACUOUS_NEGATED_CONTAINS,
    ));

    // Exactly one: the excepted function is skipped, the other function in the same file
    // is not.
    expect($violations)->toHaveCount(1);

    // And in a DIFFERENT file, even the excepted name is not excepted.
    $elsewhere = array_values(array_filter(
        $guard->scanSource('tests/Feature/Pager/SomeOtherTest.php', $excepted),
        fn ($violation): bool => $violation->rule === SourceGuard::VACUOUS_NEGATED_CONTAINS,
    ));

    expect($elsewhere)->toHaveCount(2);
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

/*
 * ================================================================================
 * RULE 9 — a branch pattern that can exclude a pushed branch
 * ================================================================================
 *
 * `R-126`. The trigger was `branches: [main, 'task-*']` and the branch this rule arrived on
 * matched neither, so CI never ran on it: `gh run list --branch
 * infra-local-mail-and-object-store` returned no rows on 2026-10-28 while the same command
 * for `task-6-provisioning` returned a run per commit. A green local gate and a green CI are
 * two different claims, and the second one was ABSENT rather than failing — which on a branch
 * page looks exactly like no news.
 */

it('reads at least one real workflow, so rule 9 cannot be passing on an empty directory', function (): void {
    // R-029's shape first: the pass has to be over something. Rule 9's own fixtures end in
    // `.fixture` precisely so they are never counted here.
    $workflows = GuardFixtures::guard()->workflowFiles();

    expect($workflows)->not->toBe([])
        ->and(array_keys($workflows))->toContain('.github/workflows/ci.yml');
});

it('passes rule 9 on the real tree, so a failure below is the fixture and not the repo', function (): void {
    expect(GuardFixtures::guard()->scanWorkflowTriggers())->toBe([]);
});

it('rejects a push trigger whose branch patterns can exclude a pushed branch — rule 9', function (): void {
    $violations = GuardFixtures::guard()->scanWorkflowSource(
        'ci.yml',
        GuardFixtures::workflow('excludes-pushed-branch'),
    );

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->rule)->toBe(SourceGuard::WORKFLOW_EXCLUDES_PUSHED_BRANCH)
        // The patterns are quoted back, because a guard that says "wrong" without saying which
        // pattern is one you have to re-derive the answer for.
        ->and($violations[0]->detail)->toContain('main')
        ->and($violations[0]->detail)->toContain('task-*');
});

it('rejects branches-ignore on a push trigger — rule 9, the same hole with the sign reversed', function (): void {
    $violations = GuardFixtures::guard()->scanWorkflowSource(
        'ci.yml',
        GuardFixtures::workflow('branches-ignore'),
    );

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->rule)->toBe(SourceGuard::WORKFLOW_EXCLUDES_PUSHED_BRANCH)
        ->and($violations[0]->detail)->toContain('scratch');
});

it('accepts every-branch written as a block sequence, not only as a flow list — rule 9', function (): void {
    // The real workflow spells it `['**']`. If the parser only understood that spelling, the
    // first person to reformat the file would silently disable the rule.
    expect(GuardFixtures::guard()->scanWorkflowSource('ci.yml', GuardFixtures::workflow('every-branch-block-form')))
        ->toBe([]);
});

it('does not flag a workflow with no push trigger — rule 9', function (): void {
    // A scheduled job is a different decision. Clause (b) over the directory is what stops
    // this from being a loophole.
    expect(GuardFixtures::guard()->scanWorkflowSource('nightly.yml', GuardFixtures::workflow('no-push-trigger')))
        ->toBe([]);
});

it('fails CLOSED on a trigger it cannot read — rule 9', function (): void {
    $violations = GuardFixtures::guard()->scanWorkflowSource(
        'ci.yml',
        GuardFixtures::workflow('unreadable-trigger'),
    );

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->rule)->toBe(SourceGuard::WORKFLOW_EXCLUDES_PUSHED_BRANCH)
        ->and($violations[0]->detail)->toContain('CLOSED');
});

it('reports a directory it found no workflow in, rather than passing it — rule 9', function (): void {
    // Pointed at the fixture directory, which holds five `.yml.fixture` files and no `.yml`.
    // Two things in one assertion: the empty-directory case is a violation (rule 6's lesson),
    // and the fixtures are genuinely invisible to the glob that reads real workflows.
    $violations = GuardFixtures::guard()->scanWorkflowTriggers('apps/api/guard/fixtures/ci');

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->rule)->toBe(SourceGuard::WORKFLOW_EXCLUDES_PUSHED_BRANCH)
        ->and($violations[0]->detail)->toContain('holds no workflow file');
});

it('rejects a workflow directory where nothing runs on every pushed branch — rule 9, clause (b)', function (): void {
    // The real defect's shape, at directory level: one workflow, restricted patterns. Planted
    // as a REAL .yml under a temporary directory, because that is the only way to exercise the
    // clause that reads the directory rather than one file's text.
    $directory = base_path('guard/fixtures/ci-planted');
    $planted = $directory.'/ci.yml';

    expect(is_dir($directory))->toBeFalse("{$directory} already exists; refusing to overwrite it");

    mkdir($directory);
    file_put_contents($planted, GuardFixtures::workflow('excludes-pushed-branch'));

    try {
        $violations = GuardFixtures::guard()->scanWorkflowTriggers('apps/api/guard/fixtures/ci-planted');

        // Both clauses fire: the file's own patterns, and "nothing here runs on every branch".
        expect($violations)->toHaveCount(2)
            ->and($violations[0]->file)->toBe('apps/api/guard/fixtures/ci-planted/ci.yml')
            ->and($violations[1]->detail)->toContain('no workflow here runs on every pushed branch');
    } finally {
        @unlink($planted);
        @rmdir($directory);
    }
});

/*
 * ================================================================================
 * RULE 10 — an object ACL in application code
 * ================================================================================
 *
 * `ADR-004`'s one measured divergence, made unreachable instead of only documented. SeaweedFS
 * honours object ACLs, MinIO answers `NotImplemented`, and a current S3 bucket with ownership
 * enforced answers `AccessControlListNotSupported` — so the stand-in is MORE PERMISSIVE than
 * production on the published-asset path, and a green local test there proves nothing about
 * production. `ADR-002` already said public read is "an explicit, per-path decision … never a
 * bucket-wide setting"; it was documented for six tasks that `compose.yaml` had MinIO, too
 * (`R-125`), which is why this one is mechanical.
 */

it('passes rule 10 on the real tree, so a failure below is the fixture and not the repo', function (): void {
    $violations = array_values(array_filter(
        GuardFixtures::guard()->scan(SourceGuard::PATHS),
        static fn ($violation): bool => $violation->rule === SourceGuard::PUBLIC_OBJECT_VISIBILITY,
    ));

    expect($violations)->toBe([]);
});

it('rejects every form that reaches an S3 object ACL from application code — rule 10', function (): void {
    $violations = GuardFixtures::guard()->scanObjectVisibility(
        'app/Fixture.php',
        GuardFixtures::application('sets-public-visibility'),
    );

    // Five, not one: the option key, the call, the flysystem constant, and the canned ACL as
    // both a key and a value. Each is a different way to the same ACL, and a rule that caught
    // only the obvious one would be a rule somebody routes around without meaning to.
    expect($violations)->toHaveCount(5)
        ->and(array_unique(array_map(static fn ($violation): string => $violation->rule, $violations)))
        ->toBe([SourceGuard::PUBLIC_OBJECT_VISIBILITY]);

    $details = implode(' ', array_map(static fn ($violation): string => $violation->detail, $violations));

    expect($details)->toContain('setVisibility')
        ->and($details)->toContain('Visibility')
        ->and($details)->toContain('public-read');
});

it('does not flag the local public disk, whose filesystem has no ACLs — rule 10', function (): void {
    $source = GuardFixtures::application('sets-public-visibility');
    $lines = explode("\n", $source);

    $legitimate = null;

    foreach ($lines as $number => $line) {
        if (str_contains($line, 'legitimate') === false && str_contains($line, "disk('public')")) {
            $legitimate = $number + 1;
        }
    }

    expect($legitimate)->not->toBeNull('the fixture no longer carries the line this test is about');

    $flagged = array_map(
        static fn ($violation): int => $violation->line,
        GuardFixtures::guard()->scanObjectVisibility('app/Fixture.php', $source),
    );

    expect($flagged)->not->toContain($legitimate);
});

it('applies to application code only, so config can declare the local disk — rule 10', function (): void {
    // §1's own wording is "nothing under `apps/` may depend on which one is running". config/
    // is where the local `public` disk legitimately sets a visibility, and tests/ is where a
    // refusal has to be provable.
    $source = GuardFixtures::application('sets-public-visibility');

    expect(GuardFixtures::guard()->scanObjectVisibility('config/filesystems.php', $source))->toBe([])
        ->and(GuardFixtures::guard()->scanObjectVisibility('tests/Feature/Probe.php', $source))->toBe([]);
});

/*
 * ================================================================================
 * RULE 11 — "a theme overrides TOKENS ONLY, never components" (QRPAGER-14 D6)
 * ================================================================================
 *
 * PLAN.md §3.1 row 7 says that clause "names no mechanism. Same empty-phrase shape the
 * fourth review found in 'architecture guards' -- it needs a lint rule proven to fail on
 * a bad file, or it is not checkable." These cases are the proof that it fails on one.
 */

it('refuses every form that makes a theme more than a token map (rule 11)', function (): void {
    $violations = GuardFixtures::guard()->scanThemeBoundary(
        'app/Fixture/StylesAComponent.php',
        GuardFixtures::theme('styles-a-component'),
    );

    $rules = array_map(static fn (Violation $v): string => $v->rule, $violations);

    // All four clauses fire on the one fixture: a style element, an at-rule, a class
    // rule-set, the token list copied, and a declaration built outside the one emitter.
    expect($rules)->toContain(SourceGuard::STYLE_LITERAL)
        ->and($rules)->toContain(SourceGuard::CSS_RULE_SET)
        ->and($rules)->toContain(SourceGuard::THEME_TOKEN_COPIED)
        ->and($rules)->toContain(SourceGuard::THEME_EMITTER);

    // The at-rule and the rule-set are two different lines, so the rule is not one check
    // reported twice.
    $cssLines = array_values(array_map(
        static fn (Violation $v): int => $v->line,
        array_filter($violations, static fn (Violation $v): bool => $v->rule === SourceGuard::CSS_RULE_SET),
    ));

    expect(count(array_unique($cssLines)))->toBe(2);
});

it('leaves the four legitimate shapes in that fixture alone (rule 11)', function (): void {
    // THE NEGATIVE CONTROL, and it is why rule 11b matches a class or an id and never a
    // bare element name: `p {` appears in sprintf formats and in prose, and a guard that
    // cried wolf would be switched off. Rule 1's own recorded lesson.
    $violations = GuardFixtures::guard()->scanThemeBoundary(
        'app/Fixture/StylesAComponent.php',
        GuardFixtures::theme('styles-a-component'),
    );

    $lines = array_map(static fn (Violation $v): int => $v->line, $violations);

    // The legitimate() method's body. Nothing in it may be flagged.
    foreach (range(34, 48) as $line) {
        expect($lines)->not->toContain($line);
    }
});

it('applies rule 11 to app/ and routes/ and to nothing else', function (): void {
    // resources/views is deliberately out of scope: the published page's own rules live
    // in its Blade template, which is where a page's layout belongs. What rule 11
    // forbids is a CLASS carrying styling.
    $source = GuardFixtures::theme('styles-a-component');

    expect(GuardFixtures::guard()->scanThemeBoundary('app/Anything.php', $source))->not->toBe([])
        ->and(GuardFixtures::guard()->scanThemeBoundary('routes/web.php', $source))->not->toBe([])
        ->and(GuardFixtures::guard()->scanThemeBoundary('config/landing.php', $source))->toBe([])
        ->and(GuardFixtures::guard()->scanThemeBoundary('tests/Feature/X.php', $source))->toBe([]);
});

it('reads the token list out of ThemeTokens rather than holding a copy (rule 11c)', function (): void {
    // A guard carrying its own copy of the list it exists to keep singular would be the
    // very defect 11c is about. So the names are PARSED out of the one file that owns
    // them -- and pinned non-empty here, because an empty list would make 11c inert and
    // every case above would still pass on the other three clauses.
    $names = GuardFixtures::guard()->themeTokenNames();

    expect($names)->toBe(array_keys(ThemeTokens::NAMES))
        ->and($names)->not->toBe([]);
});

it('does not flag ThemeTokens for owning the list, or ThemeStylesheet for emitting', function (): void {
    // The two exemptions, driven against the REAL files rather than against a fixture:
    // if either were flagged, `composer guard` would be red on a clean tree and the rule
    // would have to be switched off -- which is how a guard stops guarding.
    $guard = GuardFixtures::guard();

    foreach ([SourceGuard::THEME_TOKENS_FILE, SourceGuard::THEME_STYLESHEET_FILE] as $file) {
        $violations = $guard->scanThemeBoundary($file, (string) file_get_contents(base_path($file)));

        expect($violations)->toBe([], $file.' is flagged by the rule it is exempt from');
    }
});
