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
    expect(GuardFixtures::guard()->scan(['app', 'config', 'database', 'routes', 'tests', 'guard/src']))->toBe([])
        ->and(GuardFixtures::guard()->scanConfiguredGucs())->toBe([]);
});

it('is wired as a composer script and exits 0 on the real tree', function (): void {
    $result = Process::path(base_path())->run('composer guard');

    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput())
        ->and($result->output())->toContain('C1 and C2 hold');
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
