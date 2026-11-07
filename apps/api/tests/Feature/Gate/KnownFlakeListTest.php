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
    [$code, $output] = classify('r191-moved-frame.txt');

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

it('calls an app-code 08006 REAL even when its trace lists TestCase -- §11 QA M-4', function (): void {
    /*
     * ⚠ THE REVIEWER'S OWN FALSIFICATION, COMMITTED AS A FIXTURE. `§11` QA M-4.
     *
     * The first widening of this matcher keyed the frame on `(?:Connector|TestCase)\.php:\d+`,
     * and `bin/classify-reds` applies `frame` to the WHOLE failure block rather than to the
     * raising frame. A real Pest block routinely lists `tests/TestCase.php` in its trace — so
     * the reviewer took `r191-wrong-frame.txt`, the control for an 08006 raised from
     * `app/Features/Pager/PagerSessions.php:204`, added ONE `tests/TestCase.php` line inside
     * its block, and `classify-reds` answered **ATTRIBUTED, exit 2**.
     *
     * That is the dangerous direction and not a cosmetic one: a genuine connection defect in
     * application code would have been waved off as a host flake, on a surface where that
     * could be a security test. `TestCase` was in the alternation only to keep a PRE-CAPTURE
     * construction matching; that fixture is deleted rather than edited to fit, and the frame
     * is now `Connector.php` alone — the connect path, which is the only thing this row's own
     * `why_not_a_code_defect` measurement identifies and the only thing no code in this
     * repository can cause.
     */
    [$code, $output] = classify('r191-app-code-with-testcase-frame.txt');

    expect($output)->toContain('REAL RED')
        ->and($code)->toBe(1);

    // Not vacuous: the fixture really does carry both halves of the trap -- an 08006 timeout
    // AND a TestCase frame -- so a matcher that went back to the alternation would attribute
    // it and this case would fail.
    $fixture = (string) file_get_contents(base_path('tests/fixtures/classify-reds/r191-app-code-with-testcase-frame.txt'));

    expect(str_contains($fixture, 'SQLSTATE[08006]'))->toBeTrue();
    expect(str_contains($fixture, 'timeout expired'))->toBeTrue();
    expect(str_contains($fixture, 'tests/TestCase.php'))->toBeTrue();
    expect(str_contains($fixture, 'Connector.php'))->toBeFalse('the fixture carries a connect frame, so it is not app-code');
});

it('still attributes R-191 when the retry is EXHAUSTED and the message carries an annotation', function (): void {
    /*
     * TASK 12. The one shape of this family that can still redden a run, and the one most
     * likely to lose its attribution silently.
     *
     * `tests/Support/RetryingPostgresConnector` absorbs the first 08006/timeout expired and
     * re-throws the second from a frame this matcher does not name
     * (`tests/Support/RetryingPostgresConnector.php`), with `[R-191: 2 connect attempt(s) ...]`
     * appended to the driver's message. The obvious prediction is that this breaks the
     * attribution. It does not -- Pest renders the root of the `$previous` chain, so
     * `Connector.php:67` is still the `at` frame -- and `r191-exhausted-retry.txt` is the
     * VERBATIM capture that settled it rather than an argument that it would be fine.
     *
     * This matcher has gone stale twice on exactly this kind of assumption
     * (`known-flakes.json` › `matcher_history`), both times producing an UNEXPLAINED red,
     * which `§4a` calls this project's most expensive defect shape. So the new shape gets a
     * case of its own.
     */
    [$code, $output] = classify('r191-exhausted-retry.txt');

    expect($output)->toContain('ATTRIBUTED')
        ->and($output)->toContain('R-191')
        ->and($output)->toMatch('/REAL REDS[^\n]*: 0/')
        ->and($code)->toBe(2, $output);

    // Not vacuous: the fixture must really carry the annotation AND the retrying connector's
    // own frame, or it is just another copy of r191-moved-frame.txt and proves nothing new.
    $fixture = (string) file_get_contents(base_path('tests/fixtures/classify-reds/r191-exhausted-retry.txt'));

    expect(str_contains($fixture, 'R-191: 2 connect attempt(s)'))->toBeTrue('the fixture carries no annotation')
        ->and(str_contains($fixture, 'tests/Support/RetryingPostgresConnector.php'))->toBeTrue()
        ->and(str_contains($fixture, 'timeout expired'))->toBeTrue()
        ->and(str_contains($fixture, 'Connector.php'))->toBeTrue('the frame the attribution depends on is gone');
});

it('calls the SAME SQLSTATE at a different frame a REAL red -- the control that matters', function (): void {
    [$code, $output] = classify('r191-wrong-frame.txt');

    expect($output)->toContain('REAL RED')
        ->and($output)->toContain('PagerPollingTest')
        ->and($output)->toMatch('/ATTRIBUTED REDS[^\n]*: 0/')
        ->and($code)->toBe(1, $output);
});

it('attributes R-172 in the FOURTH file it struck, which a name list had to be told about', function (): void {
    /*
     * ⚠ MEASURED 2026-11-02 AT `da551e6`, inside `bin/fresh-clone-proof`, and reported as a
     * **REAL RED**: `OutboxCrashTest.php:184`'s
     * `toMatchArray(['claimed' => 1, 'sent' => 1])` answered `claimed` 0. That is `R-172`'s
     * symptom exactly — `OutboxWorker::claim()` returning fewer rows than the test seeded,
     * under full-suite load — in a file its name list did not carry, so the classifier called
     * the gate broken and the verdict said *"do not re-run"*.
     *
     * The file's own README says a family that strikes a different file every time cannot be
     * matched by name. `R-172` IS matched by name, and this is the measurement that proved the
     * name list is not the family.
     */
    [$code, $output] = classify('r172-outbox-crash.txt');

    expect($output)->toContain('ATTRIBUTED RED')
        ->and($output)->toContain('R-172')
        ->and($output)->toContain('Task 12')
        ->and($output)->toMatch('/REAL REDS[^\n]*: 0/')
        ->and($code)->toBe(2, $output);
});

it('attributes a red whose CLASS NAME Pest cut short, which it called a real red', function (): void {
    /*
     * ⚠ **MEASURED 2026-11-03 AT `773174c`, `QRPAGER-30`, AND THE SAME FILE WAS CLASSIFIED
     * BOTH WAYS IN ONE RUN.** `bin/classify-reds`' block-parser comment asserted *"the class
     * is not truncated"*. It is: Pest truncates the whole LINE to the terminal width, so what
     * gets cut depends on how much room the exception-type column takes.
     *
     *     FAILED  Tests\Feature\Notifications\WorkOutboxComma…      AssertionFailedError
     *     FAILED  Tests\Feature\Notifications\WorkOutboxCommandTest…  QueryException
     *
     * `AssertionFailedError` is six characters longer than `QueryException`, so one line lost
     * the class name and the other kept it — and `WorkOutboxCommandTest` is on `R-172`'s
     * `match.tests`. The verdict was *"REAL RED. 1 of 3 failure(s) are on no row. This is the
     * gate failing. Do not re-run."* on a family that has an owner, a date and a measured
     * rate.
     *
     * The fixture is that exact block, lifted out of the run's own log.
     */
    [$code, $output] = classify('r172-truncated-class.txt');

    expect($output)->toContain('ATTRIBUTED RED')
        ->and($output)->toContain('R-172')
        ->and($output)->toContain('Task 12')
        ->and($output)->toMatch('/REAL REDS[^\n]*: 0/')
        ->and($code)->toBe(2, $output);
});

it('still calls a truncation too short to place a REAL red, which is the control', function (): void {
    /*
     * The guard on the fix, and the reason it is not simply "treat a cut name as a match".
     * `Work…` is four characters; it is a prefix of `WorkOutboxCommandTest` and would also be
     * a prefix of any future `WorkQueueTest`. A matcher that accepted it would attribute an
     * unrelated red to `R-172`, which is the one thing `known-flakes.json`'s first rule
     * forbids: *"a red that is merely unexplained does NOT belong here."*
     *
     * Eight characters is the floor. This fixture carries a deliberately long exception class
     * name so the truncation lands early, which is the mechanism rather than a contrivance.
     *
     * ⚠ **THE THIRD GUARD — an ambiguous prefix matching TWO named families — IS NOT DRIVEN,
     * AND IS NOT CLAIMED TO BE.** No two names on any `match.tests` list share an eight-
     * character prefix today (`OutboxWorkerTest`, `OutboxLeaseTest`, `WorkOutboxCommandTest`,
     * `OutboxCrashTest` share at most `Outbox`), so the branch has no live instance and a
     * fixture for it would have to invent a flake list this test does not read. It is defence
     * in depth against a future name, stated here rather than asserted weakly — `R-100`'s
     * precedent for saying so instead.
     */
    [$code, $output] = classify('r172-truncated-too-short.txt');

    expect($output)->toContain('REAL RED')
        ->and($output)->toMatch('/ATTRIBUTED REDS[^\n]*: 0/')
        ->and($code)->toBe(1, $output);
});

it('keeps every named identity on the matcher, so the two lists cannot drift apart', function (): void {
    /*
     * ⚠ THE MECHANISM AGAINST THE RECURRENCE, AND `§7` IS WHY IT EXISTS RATHER THAN A SHARPER
     * EYE. `R-172` recorded `OutboxCrashTest`-shaped strikes in its prose and its `identities`
     * grew over three tasks, while `match.tests` did not — so the entry DESCRIBED a family it
     * could not attribute. Nothing failed, which is the whole problem: an unmatchable entry is
     * indistinguishable from a working one until a red lands on it.
     *
     * Every identity that NAMES a test class must be on that family's `tests` list. Identities
     * are prose — *"WorkOutboxCommandTest (as R-092, the --limit 2 claim shortfall)"* — so the
     * leading class name is taken and the rest is commentary; an identity explicitly marked as
     * a hypothesis is skipped, because a guess about a cause is not a measured strike.
     */
    $drifted = [];

    foreach (flakeList()['flakes'] as $flake) {
        // A signature-matched family needs no name list at all -- `R-191` strikes a random
        // file, which is the case this must not demand names for.
        if (($flake['match']['signature'] ?? null) !== null) {
            continue;
        }

        $named = $flake['match']['tests'] ?? [];

        foreach ($flake['identities'] as $identity) {
            if (str_contains($identity, 'hypothesis')) {
                continue;
            }

            $class = (string) preg_replace('/\s.*$/', '', trim($identity));

            if (! str_ends_with($class, 'Test')) {
                continue;
            }

            if (! in_array($class, $named, true)) {
                $drifted[] = $flake['row'].' names '.$class.' as an identity but cannot match it';
            }
        }
    }

    expect($drifted)->toBe([], implode('; ', $drifted));

    // Not vacuous: there IS a name-matched family with identities to check, so a pass means
    // the lists agree rather than that the loop found nothing to compare.
    $checked = 0;

    foreach (flakeList()['flakes'] as $flake) {
        if (($flake['match']['signature'] ?? null) === null) {
            $checked += count($flake['match']['tests'] ?? []);
        }
    }

    expect($checked)->toBe(4, 'the name-matched families carry no test names, so nothing was compared');
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
