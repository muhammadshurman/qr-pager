<?php

declare(strict_types=1);

use Guard\SourceGuard;
use Pest\Expectation;
use Pest\Mixins\Expectation as MixinExpectation;

/*
 * THE LIBRARY FACT THE GUARD ENCODES, kept where it can go red if the fact changes.
 *
 * The CONTROL for vacuous assertions is `SourceGuard`'s rule 7, run by `composer guard`
 * and as its own CI step, and proven to fail on `guard/fixtures/repo/vacuous-not-contains.php.fixture`
 * by `RepoGuardTest`. A second scanner living here would be a copy of that rule that can
 * drift from it, which is the duplicated-configuration shape `SourceGuard::PATHS` already
 * carries a comment about. So this file does not scan anything.
 *
 * What it does is pin the two facts rule 7 rests on, both of which belong to Pest and
 * neither of which the guard can check for itself — `guard/bin/guard` boots no framework
 * and loads no autoloader beyond its own two files, by design.
 *
 * ===================================================================================
 * THE DEFECT, for whoever reads this first
 * ===================================================================================
 *
 * Pest's `toContain()` takes `...$needles`, so a message in the second argument is read
 * as a SECOND NEEDLE. Under `->not->` the assertion becomes "does not contain BOTH",
 * which a message that never appears in the subject makes trivially true:
 *
 *   expect('hello app_limit')->not->toContain('app_limit')          -> FAILS  (correct)
 *   expect('hello app_limit')->not->toContain('app_limit', 'msg')   -> PASSES (inert)
 *   expect('hello app_limit')->toContain('app_limit', 'msg')        -> FAILS  (on 'msg')
 *
 * Four assertions in this repository were the middle shape and three guarded NAMED GATE
 * INVARIANTS of tasks that are already tagged. `PLAN.md §4a` has the list and `§1` has
 * the decision log entry.
 */

it('finds exactly the variadic matchers rule 7 names, and no others', function (): void {
    // WHAT KEEPS SourceGuard::VARIADIC_MATCHERS HONEST. The guard hard-codes the list
    // because it cannot ask Pest; this is the reflection that makes the hard-coding safe,
    // and it goes red if Pest adds a third variadic matcher or removes one.
    //
    // Debug helpers are excluded deliberately: dd, dump, ray, ddWhen, ddUnless and
    // sequence are variadic too and assert nothing, so a message passed to one of them
    // silences nothing that was ever asserted.
    //
    // BOTH CLASSES, and that is not belt and braces. Measured 2026-10-27:
    //
    //   Pest\Expectation          variadic = dump, dd, ddWhen, ddUnless, ray, sequence
    //   Pest\Mixins\Expectation   variadic = toContain, toContainEqual
    //
    // The MATCHERS are on the mixin and the DEBUG HELPERS are on the facade. The first
    // version of this case read only Pest\Expectation, found none of the two that matter,
    // and reported that "Pest's variadic matchers have changed" -- a false alarm that
    // would have had someone empty SourceGuard::VARIADIC_MATCHERS and switch rule 7 off.
    $debugHelpers = ['dd', 'dump', 'ray', 'ddWhen', 'ddUnless', 'sequence'];
    $variadic = [];

    foreach ([Expectation::class, MixinExpectation::class] as $class) {
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                if ($parameter->isVariadic()) {
                    $variadic[] = $method->getName();
                    break;
                }
            }
        }
    }

    $matchers = array_values(array_unique(array_diff($variadic, $debugHelpers)));
    sort($matchers);

    expect($matchers)->toBe(SourceGuard::VARIADIC_MATCHERS,
        'Pest\'s variadic matchers have changed. SourceGuard::VARIADIC_MATCHERS must be updated to match: '
        .implode(', ', $matchers));
});

it('confirms that 77 other matchers really do take a message, so the mistake is reasonable', function (): void {
    // The reason this defect is worth a mechanical guard rather than a convention: an
    // author who writes a message after the first argument is RIGHT almost everywhere.
    // Measured 2026-10-27 — 77 matchers whose last parameter is literally named $message.
    // Two exceptions out of seventy-nine is not something review catches reliably.
    $withMessage = 0;

    foreach ([Expectation::class, MixinExpectation::class] as $class) {
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $parameters = $method->getParameters();

            if ($parameters !== [] && $parameters[count($parameters) - 1]->getName() === 'message') {
                $withMessage++;
            }
        }
    }

    expect($withMessage)->toBeGreaterThan(50)
        ->and(count(SourceGuard::VARIADIC_MATCHERS))->toBeLessThan(5);
});

it('proves the broken shape really is inert, so rule 7 is not cargo cult', function (): void {
    // THE POSITIVE CONTROL. If a future Pest release made the second argument a message,
    // this case goes red and rule 7 can be retired — which is the difference between a
    // rule with a reason and a rule with a history.
    //
    // The broken call itself is in a NAMED FUNCTION below, because the guard excepts it
    // by file AND by function name (R-019's pattern: never "tests are exempt"). A guard
    // that flagged its own proof would be switched off by the first person who read its
    // output; one that excepted a whole file would stop guarding that file.
    expect(pestReadsASecondArgumentAsANeedle())->toBeTrue(
        'Pest now treats the second argument to toContain() as a message. SourceGuard rule 7 '
        .'can be retired and the rewritten assertions in GrantMatrixTest, PagerTokenTest, '
        .'OpenApiSpecTest and PagerCustomerSurfaceTest simplified.'
    );
});

/**
 * True when `->not->toContain($needle, $message)` passes although $needle IS present.
 *
 * The one place in this repository that writes the broken shape on purpose, excepted in
 * `SourceGuard::EXCEPTIONS` by this file and this function name. Moving it, renaming it,
 * or adding a second one anywhere — including elsewhere in this file — fails the guard.
 */
function pestReadsASecondArgumentAsANeedle(): bool
{
    $subject = 'hello app_limit world';

    // Sanity first: the needle really is present, so a pass below cannot mean "absent".
    if (! str_contains($subject, 'app_limit')) {
        return false;
    }

    try {
        expect($subject)->not->toContain('app_limit', 'this message is not in the subject');
    } catch (Throwable) {
        // It raised, which means Pest read the second argument as a message after all.
        return false;
    }

    return true;
}
