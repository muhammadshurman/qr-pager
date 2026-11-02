<?php

declare(strict_types=1);

use Guard\SourceGuard;
use Guard\Violation;

/*
 * ================================================================================
 * `composer guard`'s VERDICT DOES NOT DEPEND ON WHETHER A REVIEW IS IN FLIGHT
 * ================================================================================
 *
 * ⚠ A RECURRENCE, WHICH IS WHAT MAKES THIS A TEST RATHER THAN A ONE-LINE FIX. `§7`: *"Never
 * fix the same bug twice. If it recurs, the regression test was missing -- add it."*
 *
 * `§0.2` (b) made `§11`'s two reviewers run AT THE SAME TIME, each in its own detached
 * worktree under `.claude/worktrees/`. `.gitignore` excludes that path, which keeps the
 * worktrees out of COMMITS and does nothing to a walk of the filesystem. So from Task 10a
 * onward the ordinary state of this repository DURING a review round is that two complete
 * copies of it exist inside it, and every tool that walks the tree sees three `docs/PLAN.md`.
 *
 * Measured on 2026-11-02, in the same sitting, in THREE tools:
 *
 *   1. `eslint .`                   0 findings -> **3,914**, all under a worktree path.
 *   2. `scan-css.mjs`               would have reported every finding three times, and its
 *                                   FLOOR check cannot catch that -- a worktree pushes the
 *                                   count comfortably past the floor.
 *   3. `composer guard`             **4 violations**, every one a `C7` claim about a copy of a
 *                                   correct file: *"carries a task-table heading, and C7
 *                                   allows exactly one"*.
 *
 * The first two were fixed, `tools/gate/test/lint-scope.test.mjs` was written for them, and
 * its last case ends *"a third linter is the only thing left to forget"*. It was forgotten in
 * the same sitting -- `composer guard` runs from `apps/api` and `pnpm lint` from the root, so
 * nothing connects them -- and the PHP gate found it one step later. That is the whole reason
 * this file exists beside the TypeScript one rather than instead of it.
 */

it('prunes the reviewer worktrees from the canonical-doc walk', function (): void {
    /*
     * ⚠ DRIVEN AGAINST A REAL TREE, NOT AGAINST THE PRUNE LIST. Asserting that the array
     * contains `.claude` would pass against a walk that read the list and ignored it, which is
     * the shape every one of this project's vacuity findings has. So a worktree-shaped copy of
     * the two canonical documents is built on disk and the guard is asked.
     *
     * ⚠ AND THE FIXTURE IS `<root>/apps/api`, BECAUSE `repositoryRoot()` IS
     * `dirname($this->root, 2)`. The first version passed the fixture root itself, so the
     * guard walked two directories ABOVE it -- `/tmp`, where `RecursiveDirectoryIterator` hit
     * `snap-private-tmp` and threw `Permission denied`. The red named the iterator rather than
     * the mistake, which is the shape this project calls most expensive.
     */
    $root = storage_path('framework/testing/guard-scope-'.bin2hex(random_bytes(6)));
    $guardRoot = $root.'/apps/api';
    $docs = $root.'/docs';
    $worktree = $root.'/.claude/worktrees/qa-probe/docs';

    foreach ([$guardRoot, $docs, $worktree] as $directory) {
        expect(mkdir($directory, 0o755, true))->toBeTrue();
    }

    $plan = (string) file_get_contents(base_path('../../docs/PLAN.md'));
    $blueprint = (string) file_get_contents(base_path('../../docs/BLUEPRINT.md'));

    // Not vacuous: the fixture carries the two things the rule is about -- a task-table
    // heading and a BLUEPRINT -- so a walk that reached the copy WOULD have something to say.
    expect(preg_match(SourceGuard::TASK_TABLE_HEADING, $plan))->toBe(1, 'the fixture PLAN carries no task table');
    expect($blueprint)->not->toBe('');

    file_put_contents($docs.'/PLAN.md', $plan);
    file_put_contents($docs.'/BLUEPRINT.md', $blueprint);
    file_put_contents($worktree.'/PLAN.md', $plan);
    file_put_contents($worktree.'/BLUEPRINT.md', $blueprint);

    try {
        $violations = (new SourceGuard($guardRoot))->scanCanonicalDocs();

        expect($violations)->toBe(
            [],
            'the canonical-doc walk reached a reviewer worktree: '
            .implode(', ', array_map(static fn (Violation $v): string => $v->file, $violations)),
        );

        /*
         * THE CONTROL. Without it this case would pass against a walk that found nothing
         * anywhere -- a scanner with nothing to scan, which is the failure mode
         * `scan-css.mjs`'s floor check exists for. A duplicate OUTSIDE `.claude` must still be
         * caught, and both rules must still fire: the byte-identical Blueprint and the second
         * task table.
         */
        $elsewhere = $root.'/archive/docs';

        expect(mkdir($elsewhere, 0o755, true))->toBeTrue();
        file_put_contents($elsewhere.'/BLUEPRINT.md', $blueprint);
        file_put_contents($elsewhere.'/PLAN.md', $plan);

        $caught = (new SourceGuard($guardRoot))->scanCanonicalDocs();

        expect($caught)->toHaveCount(2, 'the walk no longer catches duplicates outside .claude');

        $paths = array_map(static fn (Violation $v): string => $v->file, $caught);

        expect($paths)->toContain('archive/docs/BLUEPRINT.md')
            ->and($paths)->toContain('archive/docs/PLAN.md');
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
});
