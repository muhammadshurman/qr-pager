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
