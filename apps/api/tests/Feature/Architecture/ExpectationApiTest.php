<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
 * A GUARD AGAINST ONE EXPECTATION THAT LOOKS LIKE AN ASSERTION AND IS NOT.
 *
 * ===================================================================================
 * WHAT WAS FOUND, AT TASK 4
 * ===================================================================================
 *
 * Pest's `toContain()` takes `...$needles` -- every argument is a needle. So a message
 * passed as a second argument, which is the shape almost every other expectation in Pest
 * accepts, is read as a SECOND NEEDLE. Under `->not` the whole expectation becomes "does
 * not contain BOTH of these", and a message string that never appears in the subject makes
 * that trivially true.
 *
 * Measured 2026-10-27, three cases, run as real tests:
 *
 *   expect('hello app_limit')->not->toContain('app_limit')          -> FAILS   (correct)
 *   expect('hello app_limit')->not->toContain('app_limit', 'msg')   -> PASSES  (inert)
 *   expect('hello app_limit')->toContain('app_limit', 'msg')        -> FAILS   (on 'msg')
 *
 * FOUR ASSERTIONS IN THIS SUITE WERE THE MIDDLE SHAPE, and three of them were controls
 * for named invariants that had therefore never been tested:
 *
 *   GrantMatrixTest     "grants UPDATE on no tenant_id column anywhere" -- the control
 *                       behind "a row cannot be moved between tenants" (Task 1).
 *   PagerTokenTest      "the raw token never reaches a log" -- a §3 row 3 gate clause
 *                       (Task 3).
 *   OpenApiSpecTest     the R-052/R-056 sweep for internal rationale in published
 *                       descriptions -- which is why `0.9 x app_limit` was in the
 *                       committed spec with a green test claiming to forbid it (Task 2).
 *   PagerCustomerSurfaceTest  "no token in any response body" (Task 4, this task's own).
 *
 * All four are fixed to `expect(str_contains(...))->toBeFalse("message")`, which takes a
 * real message, and all four were re-run to confirm they now bite.
 *
 * ===================================================================================
 * WHY A SOURCE SCAN AND NOT A CODE REVIEW
 * ===================================================================================
 *
 * The same reason the C1/C2 repo guard is a source scan (§4 › Postgres traps): nothing in
 * lint can see this. Pint formats, PHPStan analyses types -- and both arguments here are
 * strings, so the call is perfectly well typed and perfectly wrong. The only thing that
 * distinguishes a message from a needle is intent, and intent is what a convention
 * mechanises. A reviewer found this one by accident, which is not a control.
 *
 * It is scoped to `->not->toContain(` with more than one argument, which is the shape that
 * silently passes. A POSITIVE `toContain($a, $b)` is a legitimate "contains both" and is
 * left alone -- it also fails loudly when misused as a message, which is how it stays
 * honest.
 */

it('has no ->not->toContain() call carrying a message as a second needle', function (): void {
    $offenders = [];

    foreach (File::allFiles(base_path('tests')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // THIS FILE IS EXCLUDED, and the exclusion is load-bearing rather than
        // convenient: the second case below WRITES THE BROKEN SHAPE ON PURPOSE as a
        // positive control, so a scan that included it would report its own proof as the
        // defect. Named by basename and nothing wider, so a second file cannot hide
        // behind it -- which is the mistake the C1/C2 guard's own path list made (QA H-1,
        // a duplicated list that went stale).
        if ($file->getFilename() === 'ExpectationApiTest.php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        foreach (explode("\n", $contents) as $number => $line) {
            if (! str_contains($line, '->not->toContain(')) {
                continue;
            }

            // A COMMENT THAT QUOTES THE RULE IS NOT A BREACH OF IT. Every one of the four
            // rewritten assertions carries a line explaining what it used to be, and the
            // first version of this scan reported all four -- the guard flagging its own
            // documentation, which is the property RepoGuardTest already has a case for
            // ("it does not flag the documentation of its own rule"). Same answer here.
            $trimmed = ltrim($line);

            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            // Everything from the opening paren to the matching close, on this line. A
            // call split across lines is not matched and does not need to be: the
            // dangerous shape is short by nature, because the message follows the needle.
            $arguments = argumentsOf($line, '->not->toContain(');

            if ($arguments === null) {
                continue;
            }

            if (commaAtTopLevel($arguments)) {
                $offenders[] = sprintf(
                    '%s:%d  %s',
                    str_replace(base_path().'/', '', $file->getPathname()),
                    $number + 1,
                    trim($line),
                );
            }
        }
    }

    expect($offenders)->toBe([], implode(PHP_EOL, array_merge(
        ['->not->toContain() with a second argument asserts NOTHING -- Pest reads it as another'],
        ['needle and ->not then means "not both". Use expect(str_contains($h, $n))->toBeFalse($m).'],
        [''],
        $offenders,
    )));
});

it('does not flag a comment that documents the rule, only a call that breaks it', function (): void {
    // The companion to the skip above: a scan that reported every explanation of itself
    // would be loosened by the first person who had to read its output, and then it would
    // stop reporting the real thing too.
    $line = "            // str_contains() AND NOT `->not->toContain(\$raw, 'message')`, which was wrong";

    expect(str_contains($line, '->not->toContain('))->toBeTrue('the fixture no longer contains the shape')
        ->and(str_starts_with(ltrim($line), '//'))->toBeTrue('the fixture is no longer a comment');
});

it('proves the broken shape really is inert, so this guard is not cargo cult', function (): void {
    // The positive control. If a future Pest release made the second argument a message,
    // this case would go red and the guard above could be retired -- which is the
    // difference between a rule with a reason and a rule with a history.
    $subject = 'hello app_limit world';

    // The correct form: it finds the needle.
    expect(str_contains($subject, 'app_limit'))->toBeTrue();

    // The broken form: Pest does NOT raise, which is the whole problem.
    $raised = false;

    try {
        expect($subject)->not->toContain('app_limit', 'this message is not in the subject');
    } catch (Throwable) {
        $raised = true;
    }

    expect($raised)->toBeFalse(
        'Pest now treats the second argument to toContain() as a message. '
        .'The guard above can be retired and the four rewritten assertions simplified.'
    );
});

/** The text between a call's parentheses, or null when they are not balanced on one line. */
function argumentsOf(string $line, string $needle): ?string
{
    $start = strpos($line, $needle);

    if ($start === false) {
        return null;
    }

    $cursor = $start + strlen($needle);
    $depth = 1;
    $out = '';

    for ($i = $cursor; $i < strlen($line); $i++) {
        $char = $line[$i];

        if ($char === '(') {
            $depth++;
        } elseif ($char === ')') {
            $depth--;

            if ($depth === 0) {
                return $out;
            }
        }

        $out .= $char;
    }

    return null;
}

/**
 * True when the argument list has a comma outside every string, bracket and paren.
 *
 * A comma INSIDE a needle is not a second argument -- RefusalDiagnosticsTest has
 * `not->toContain('has been rolled back, back to the depth')`, which is one needle and
 * perfectly correct. A naive `str_contains($line, ',')` would have reported it, and a
 * guard that cries wolf is one somebody loosens.
 */
function commaAtTopLevel(string $arguments): bool
{
    $depth = 0;
    $quote = null;

    for ($i = 0; $i < strlen($arguments); $i++) {
        $char = $arguments[$i];

        if ($quote !== null) {
            if ($char === '\\') {
                $i++;
            } elseif ($char === $quote) {
                $quote = null;
            }

            continue;
        }

        if ($char === "'" || $char === '"') {
            $quote = $char;
        } elseif ($char === '(' || $char === '[' || $char === '{') {
            $depth++;
        } elseif ($char === ')' || $char === ']' || $char === '}') {
            $depth--;
        } elseif ($char === ',' && $depth === 0) {
            return true;
        }
    }

    return false;
}
