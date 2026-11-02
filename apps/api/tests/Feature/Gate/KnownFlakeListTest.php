<?php

declare(strict_types=1);

/*
 * THE NAMED KNOWN-FLAKE LIST, AND THE SPLIT IT EXISTS TO MAKE.
 *
 * WHY ANY OF THIS. Task 9's gate says "all previous tasks' tests still pass". Two live
 * intermittent families ride in this suite, and with them unnamed that clause cannot be
 * discharged honestly -- any new red can be attributed to one of them by assertion.
 * R-185 is this ledger's own row about exactly that: §6's clause was being satisfied
 * "PROBABILISTICALLY rather than demonstrably". So the list is tests/known-flakes.json,
 * bin/classify-reds splits a red run against it, and bin/gate reports the two sides
 * separately. A red outside the list is a real red.
 *
 * WHAT THIS FILE GUARDS, and each clause is here because the list could rot that way:
 *
 *  (1) EVERY ENTRY NAMES A REAL §5 ROW. A list that can carry an invented row id is a
 *      list that can absorb any red, which is the opposite of its purpose.
 *  (2) EVERY ENTRY CARRIES AN OWNER, A MEASURED RATE AND THE COMMIT/DATE IT WAS MEASURED
 *      AT. "Each entry carrying its row, its measured rate and its owner" is the whole
 *      specification; an entry missing one of them is a shrug with a filename.
 *  (3) THE SPLIT ACTUALLY SPLITS -- driven in both directions, including the negative
 *      control that matters most: the same SQLSTATE at a DIFFERENT frame must come out
 *      REAL, or the signature would swallow every connection defect this project writes.
 *  (4) A REAL RED IS NEVER MASKED BY AN ATTRIBUTED ONE BESIDE IT.
 *  (5) A REMOVED ROW IS NOT ALSO A LIVE ENTRY. R-200 was removed by being FIXED, and the
 *      removal is how the fix is proven to have landed.
 *
 * THE NAME-MATCHED CASES ARE PROVEN ON REAL PEST OUTPUT, not on fixtures: planting a red
 * in OutboxWorkerTest (on the list) and in EnumContractTest (not matched) on 2026-11-01
 * produced ATTRIBUTED/exit 2 and REAL/exit 1 respectively. The SIGNATURE cases use
 * committed fixtures of the documented shape, and tests/fixtures/classify-reds/README.txt
 * says so plainly -- no 08006 failure block exists in this tree to capture, because those
 * reds were read and reported rather than committed.
 *
 * ⚠ A NEAR-MISS WORTH KEEPING. The real off-list plant landed at
 * `EnumContractTest.php:112` -- the same line number as R-191's `TestCase.php:112` frame.
 * It came out REAL, correctly, because the frame pattern is anchored to the FILE and not
 * to the line. A pattern of `:112` alone would have mis-attributed it, and that collision
 * happened on the first try rather than being imagined.
 */

use Illuminate\Support\Facades\Process;

/** @return array<string, mixed> */
function flakeList(): array
{
    return json_decode(
        (string) file_get_contents(base_path('tests/known-flakes.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
}

/** Run the classifier over a fixture and return [exit code, output]. */
function classify(string $fixture): array
{
    $result = Process::path(base_path())->run([
        PHP_BINARY,
        base_path('bin/classify-reds'),
        base_path('tests/fixtures/classify-reds/'.$fixture),
    ]);

    return [$result->exitCode(), $result->output()];
}

it('names a real PLAN §5 row in every entry, live and removed', function (): void {
    $plan = (string) file_get_contents(base_path('../../docs/PLAN.md'));
    $list = flakeList();

    $rows = [];
    preg_match_all('/^\|\s*\*\*(R-\d{3})\*\*\s*\|/m', $plan, $matches);
    $rows = array_unique($matches[1]);

    expect($rows)->not->toBeEmpty('PLAN.md §5 parsed to zero rows -- this assertion would pass vacuously');

    /*
     * in_array + toBeTrue, NOT toContain with a message. Pest's toContain takes its
     * needles as VARARGS, so a message passed as the second argument becomes a second
     * needle and the assertion asserts the message string is in the array -- it reddens
     * with "Failed asserting that an array contains 'known-flakes.json names R-191 ...'",
     * which is the assertion lying about its own subject. Found by this test's first run.
     * The house idiom is expect(<predicate>)->toBeTrue($context).
     */
    foreach ([...$list['flakes'], ...$list['removed']] as $entry) {
        expect(in_array($entry['row'], $rows, true))->toBeTrue(
            "known-flakes.json names {$entry['row']}, which is not a row in PLAN.md §5",
        );
    }
});

it('carries an owner, a measured rate and a measurement provenance in every live entry', function (): void {
    $list = flakeList();

    expect($list['flakes'])->not->toBeEmpty();

    foreach ($list['flakes'] as $flake) {
        /*
         * array_key_exists, NOT toHaveKey($key, $message). toHaveKey's second parameter is
         * the expected VALUE, so the message was being compared against the entry's value
         * and the failure read "Failed asserting that two strings are equal". Same class of
         * mistake as the one above and found the same way.
         */
        foreach (['row', 'title', 'owner', 'rate', 'measured', 'identities', 'match'] as $key) {
            expect(array_key_exists($key, $flake))->toBeTrue("a live entry is missing '{$key}'");
            expect($flake[$key])->not->toBeEmpty("'{$key}' is empty on {$flake['row']}");
        }

        // A rate with no number in it is not a measured rate.
        expect($flake['rate'])->toMatch('/\d/', "{$flake['row']}'s rate carries no figure");

        // A matcher must be one of the two kinds, or the entry matches nothing and is decor.
        expect(isset($flake['match']['tests']) || isset($flake['match']['signature']))
            ->toBeTrue("{$flake['row']} has no usable matcher");
    }
});

it('does not carry a removed row as a live entry as well', function (): void {
    $list = flakeList();

    $live = array_column($list['flakes'], 'row');

    foreach ($list['removed'] as $removed) {
        expect(in_array($removed['row'], $live, true))->toBeFalse(
            "{$removed['row']} is listed as removed AND as live -- one of the two is wrong",
        );
    }
});

it('attributes an R-191-shaped red by its SQLSTATE and frame, in a file no name list carries', function (): void {
    [$code, $output] = classify('r191-attributed.txt');

    expect($output)->toContain('ATTRIBUTED RED')
        ->and($output)->toContain('R-191')
        ->and($output)->toContain('Task 13')
        ->and($output)->toMatch('/REAL REDS[^\n]*: 0/')
        ->and($code)->toBe(2, $output);

    // And the file it struck is on NO name list, so only the signature can have matched.
    $names = [];
    foreach (flakeList()['flakes'] as $flake) {
        $names = [...$names, ...($flake['match']['tests'] ?? [])];
    }
    expect($names)->not->toContain('TenantContextTest');
});

it('attributes an R-191 red whose frame MOVED, which is the defect this matcher shipped with', function (): void {
    /*
     * ⚠ A VERBATIM CAPTURE, NOT A CONSTRUCTED SHAPE, AND IT WAS A LIVE DEFECT FOR TWO TASKS.
     *
     * R-191's matcher named `TestCase.php:112`. Task 9 moved that call site to 222 (46e3e52),
     * and from that commit every red of this family came out REAL: measured at e761f2c during
     * Task 10a, `PasskeyTest` failed with 08006/timeout expired on `pgsql_owner` at
     * `TestCase.php:223` and the verdict read *"REAL RED. 1 of 1 failure(s) are on no row"* --
     * for a fault whose own row describes it exactly.
     *
     * A line-pinned frame goes stale whenever the file moves, and what it produces is an
     * UNEXPLAINED red, which `§4a` calls this project's most expensive defect shape. This case
     * fails if the frame is ever pinned back to a line, and the control below fails if the
     * pattern is widened to swallow any 08006 anywhere. Neither alone is enough.
     */
    [$code, $output] = classify('r191-moved-frame.txt');

    expect($output)->toContain('ATTRIBUTED RED')
        ->and($output)->toContain('R-191')
        ->and($code)->toBe(2);

    // And the frame it carries really is NOT the one the matcher used to name, so this case
    // cannot pass for the old reason.
    $fixture = (string) file_get_contents(base_path('tests/fixtures/classify-reds/r191-moved-frame.txt'));

    expect(str_contains($fixture, 'TestCase.php:223'))->toBeTrue();
    expect(str_contains($fixture, 'TestCase.php:112'))->toBeFalse();
});

it('attributes the same fault on ANOTHER connection, which is what proved the matcher too narrow', function (): void {
    /*
     * ⚠ A SECOND VERBATIM CAPTURE, AND IT IS WHY THE MATCHER KEYS ON THE CONNECT PATH.
     *
     * The first repair pinned `pgsql_owner` and the `TRUNCATE`, which is this row's symptom as
     * its own TITLE describes it. The very next full gate run produced the same fault on a
     * DIFFERENT connection and a different statement -- `PanelProblemPageTest`, 08006 timeout
     * expired on `pgsql`, on a `SELECT` of `users` -- and it came out REAL again.
     *
     * MEASURED AT 94f5b5b rather than argued: full `composer gate` 2 of 2 RED with two
     * different tests on two different connections; bare `php artisan test` 2 of 2 GREEN at
     * 1198 passed / 10604 assertions. That is this row's own recorded rate reproduced at a new
     * commit, and it says the variable is HOST LOAD and not any statement -- the gate runs nine
     * steps before the suite.
     *
     * So the pattern keys on `Connector.php`, where `PDO::connect` is called. **A connect
     * timeout cannot be caused by any code in this repository** -- only by the host, which is
     * exactly what this row's `why_not_a_code_defect` measured. That is the strongest available
     * argument for a wide pattern, and it is why this widening is defensible where a bare
     * `08006` would not be.
     */
    [$code, $output] = classify('r191-other-connection.txt');

    expect($output)->toContain('ATTRIBUTED RED')
        ->and($output)->toContain('R-191')
        ->and($code)->toBe(2);

    $fixture = (string) file_get_contents(base_path('tests/fixtures/classify-reds/r191-other-connection.txt'));

    // The two facts that made the earlier, narrower matcher miss it. Asserted so this case
    // cannot pass against a matcher that went back to pinning either one.
    expect(str_contains($fixture, 'Connection: pgsql,'))->toBeTrue('the fixture is not on another connection');
    expect(str_contains($fixture, 'TRUNCATE'))->toBeFalse('the fixture carries a TRUNCATE after all');
});

it('calls the SAME SQLSTATE at a different frame a REAL red -- the control that matters', function (): void {
    [$code, $output] = classify('r191-wrong-frame.txt');

    expect($output)->toContain('REAL RED')
        ->and($output)->toContain('PagerPollingTest')
        ->and($output)->toMatch('/ATTRIBUTED REDS[^\n]*: 0/')
        ->and($code)->toBe(1, $output);
});

it('never masks a real red with an attributed one beside it', function (): void {
    [$code, $output] = classify('mixed.txt');

    expect($output)->toContain('REAL RED')
        ->and($output)->toContain('R-172')
        ->and($output)->toContain('PublishTest')
        ->and($output)->toMatch('/ATTRIBUTED REDS[^\n]*: 1/')
        ->and($output)->toMatch('/REAL REDS[^\n]*: 1/')
        ->and($code)->toBe(1, $output);
});

it('reports the list before any step, so a stale entry is visible in every log', function (): void {
    $result = Process::path(base_path())->run([PHP_BINARY, base_path('bin/known-flakes-summary')]);

    expect($result->exitCode())->toBe(0);

    foreach (flakeList()['flakes'] as $flake) {
        expect($result->output())->toContain($flake['row'])
            ->and($result->output())->toContain($flake['owner']);
    }

    // The removed one is printed too: a fix that is invisible is a fix nobody can check.
    expect($result->output())->toContain('R-200')
        ->and($result->output())->toContain('REMOVED');
});

it('is wired into bin/gate, on the suite step and on no other', function (): void {
    $gate = (string) file_get_contents(base_path('bin/gate'));

    expect($gate)->toContain('bin/known-flakes-summary')
        ->and($gate)->toContain('bin/classify-reds')
        // Only the suite step is classified -- a pint or stan failure has no test identity.
        ->and($gate)->toContain('if [ "$step" = "php artisan test" ]; then')
        // And the attributed verdict must leave the gate non-zero.
        ->and($gate)->toContain('exit 2');
});
