<?php

declare(strict_types=1);

namespace Guard;

/**
 * The repo guard PROJECT_INSTRUCTIONS.md §4 › Postgres traps requires:
 *
 *   "A repo guard in CI rejects session-scoped tenant settings, a raw SET app.*, and
 *    any app.* name not in C1. C1 and C2 are enforced mechanically, not by review.
 *    The guard must be proven to fail on a deliberately bad file."
 *
 * It did not exist (R-010) although §4 asserted it as a fact, and the suite meanwhile
 * produces the state C2 forbids at five call sites through one named helper (R-019).
 * Both are closed here: the helper is this guard's single exception, named by path AND
 * by method, never by "tests are exempt".
 *
 * It scans STRING LITERALS, not raw text, via token_get_all(). That is deliberate and
 * it is what makes the guard usable: `set_config(..., false)` appears in a dozen
 * comments in this repo explaining the rule, and a text-matching guard would flag the
 * documentation of the thing it enforces. Comments and identifiers are invisible here;
 * only what can reach Postgres is read.
 */
final class SourceGuard
{
    /** The only two GUCs that exist (C1). Locked in a rank-2 file, so hard-coded here on purpose. */
    public const GUCS = ['app.tenant_id', 'app.bypass_tenant'];

    public const SESSION_SCOPED_SET_CONFIG = 'session-scoped-set-config';

    public const RAW_SET_APP_GUC = 'raw-set-app-guc';

    public const UNKNOWN_APP_GUC = 'unknown-app-guc';

    public const UNEXPECTED_CONFIGURED_GUCS = 'unexpected-configured-gucs';

    public const UNSCANNABLE_PATH = 'unscannable-path';

    /**
     * Rule 7: a negated variadic matcher given more than one argument asserts nothing.
     *
     * WHAT THIS IS FOR, and it is not a style rule. Pest's `toContain` and
     * `toContainEqual` are the ONLY TWO assertion matchers that are variadic --
     * measured by reflection over Pest\Expectation on 2026-10-27: eight variadic
     * methods in all, of which six are debug helpers (dd, dump, ray, ddWhen, ddUnless)
     * and `sequence`, while 77 OTHER matchers take a real `$message` as their last
     * parameter. So an author who writes a message after the needle is right 77 times
     * out of 79, and the two exceptions fail silently.
     *
     * Under `->not->`, a second argument turns the assertion into "does not contain
     * BOTH", which a message that never appears in the subject makes TRIVIALLY TRUE.
     * Measured, as three real tests:
     *
     *   expect('hello app_limit')->not->toContain('app_limit')          -> FAILS (correct)
     *   expect('hello app_limit')->not->toContain('app_limit', 'msg')   -> PASSES (inert)
     *   expect('hello app_limit')->toContain('app_limit', 'msg')        -> FAILS (on 'msg')
     *
     * THIS WAS NOT HYPOTHETICAL. Four assertions in this repository were the middle
     * shape and three of them guarded NAMED GATE INVARIANTS of tasks that are already
     * tagged: "no role may update a tenant key" (Task 1), "the raw token never reaches a
     * log" (Task 3's §3 row 3), and the R-052/R-056 sweep over published OpenAPI
     * descriptions (Task 2) -- which is why docs/openapi.json shipped the capacity
     * figure R-031 removed, under a green test naming that exact string as forbidden.
     *
     * THE POSITIVE FORM IS NOT FLAGGED. `toContain($a, $b)` means "contains both", which
     * is legitimate -- and when it IS misused as a message it fails loudly on the
     * message, so it corrects itself. Only `->not->` is silent, so only `->not->` is a
     * violation. A guard that flagged the safe form too would be loosened by the first
     * person who had to read its output.
     */
    public const VACUOUS_NEGATED_CONTAINS = 'vacuous-negated-contains';

    /**
     * The variadic MATCHERS. Not the variadic debug helpers, which assert nothing anyway.
     *
     * Hard-coded from reflection rather than discovered at run time, for the same reason
     * GUCS is: this guard is standalone by design (`guard/bin/guard` boots no framework
     * and loads no autoloader beyond its own two files), so it cannot ask Pest what its
     * signatures are. ExpectationApiTest carries the reflection that keeps this list
     * honest, and goes red if Pest adds a third.
     *
     * @var list<string>
     */
    public const VARIADIC_MATCHERS = ['toContain', 'toContainEqual'];

    /**
     * Rule 8: exactly one PLAN.md and one BLUEPRINT.md, and only PLAN.md carries a task table.
     *
     * RULE 7 WAS DELIBERATELY SKIPPED HERE, AND THE RESERVATION HELD. It is the
     * vacuous-assertion rule, written on `task-4-notifications`, and at the rebase onto
     * `task-3` (2026-10-27) both rules met in this file with **no collision**: rule 7's
     * constants are `VACUOUS_NEGATED_CONTAINS` and `VARIADIC_MATCHERS`, rule 8's are
     * `DUPLICATE_CANONICAL_DOC`, `CANONICAL_DOCS` and `TASK_TABLE_HEADING`, and the merge
     * was two additive blocks rather than a renumbering. Numbering this 8 rather than
     * renumbering at the rebase keeps both branches' constants stable, so a `§5` row or a
     * CI log naming a rule means
     * the same thing on either side of the merge.
     *
     * WHAT THIS IS FOR, and it is not tidiness. `docs/claude/PLAN.md` and
     * `docs/claude/BLUEPRINT.md` were committed by `e5ebc9a` on this branch, in a commit
     * whose message says "Record only; no behaviour" and does not mention adding them. The
     * BLUEPRINT copy was byte-identical; the PLAN copy was a 1079-line Task-1-era snapshot
     * of a 2583-line file, reading "Last completed: Task 1 / Next up: Task 2" and carrying
     * its own `## 3. Task table` at line 406.
     *
     * That is three rank-2 rules broken at once: `PROJECT_INSTRUCTIONS.md §3 C7` ("One task
     * table only, in PLAN.md"), `CLAUDE.md` ("Nothing else in this repo states current
     * state"), and `§0` precedence, which had two rank-3 documents disagreeing about where
     * the project is. The danger is not the bytes: it is that THE NEXT SESSION TO READ THE
     * REPO MAY ACT ON THE STALE ONE.
     *
     * A deletion takes no regression test, so this is the control instead. It is mechanical
     * for the same reason the C1/C2 rules are: the files arrived without anyone deciding to
     * add them, and review is not a defence against that.
     *
     * TWO CLAUSES:
     *   (a) no file other than `docs/PLAN.md` contains a task-table HEADING;
     *   (b) `docs/BLUEPRINT.md`'s content appears at no other path.
     *
     * (a) matches a HEADING and not the words, because `PROJECT_INSTRUCTIONS.md §3`'s C7 row
     * says "task table duplicated verbatim" in prose and `§7`'s own text mentions it too --
     * a word-matching rule would flag the documentation of the rule it enforces, which is
     * the mistake rule 1 already has a case against.
     */
    public const DUPLICATE_CANONICAL_DOC = 'duplicate-canonical-doc';

    /**
     * Rule 9: a workflow that runs on push must not carry a branch pattern that can
     * exclude a pushed branch.
     *
     * WHAT THIS IS FOR. `.github/workflows/ci.yml` triggered on `branches: [main,
     * 'task-*']`, and the branch this rule arrived on -- `infra-local-mail-and-object-store`
     * -- matches neither, so NOTHING RAN ON IT. Measured 2026-10-28:
     * `gh run list --branch infra-local-mail-and-object-store` returns no rows, while the
     * same command for `task-6-provisioning` returns runs on every commit. Two commits had
     * already landed, one of which added a test that fails when its service is absent, and
     * the first verdict on either would have arrived on the PULL REQUEST into `main` -- after
     * the work, in public, which is exactly the condition the 2026-10-26 trigger change (then
     * `main` plus tags only) was made to remove. It removed it for `task-*` and left it for
     * every other name.
     *
     * A GREEN LOCAL GATE AND A GREEN CI ARE TWO DIFFERENT CLAIMS, and this is the second one
     * being absent rather than failing. `R-126`.
     *
     * The rule is a text parse rather than a YAML load, because `guard/bin/guard` boots
     * nothing and loads no autoloader -- the same reason GUCS is hard-coded. It FAILS CLOSED:
     * a trigger it cannot read is a violation, because the alternative is a guard passing on
     * a file it did not understand (rule 6's lesson, R-029's shape).
     *
     * NOT FLAGGED: a workflow with no `push:` trigger at all. A scheduled or
     * `workflow_dispatch` job is a different decision, and flagging it would make this rule
     * the kind nobody keeps. The directory-level clause below is what stops that from
     * becoming a loophole.
     */
    public const WORKFLOW_EXCLUDES_PUSHED_BRANCH = 'workflow-excludes-pushed-branch';

    /** The workflow directory, relative to the REPOSITORY root -- not to `apps/api`. */
    public const WORKFLOW_DIR = '.github/workflows';

    /** The only branch pattern that cannot exclude a pushed branch. */
    public const EVERY_BRANCH = '**';

    /**
     * Rule 10: the application never makes a stored object public through an ACL.
     *
     * `ADR-004` measured this and it is the one place the local stand-in is MORE PERMISSIVE
     * THAN PRODUCTION. SeaweedFS honours object ACLs, so `->setVisibility('public')` returns
     * 200 locally; MinIO answers `NotImplemented`; and a current S3 bucket with ownership
     * enforced answers `AccessControlListNotSupported`. So a Task 7 media path written against
     * the stand-in can be green here and broken in production -- on the published-asset path,
     * which is the one `ADR-002` calls "an explicit, per-path decision in Task 7, never a
     * bucket-wide setting".
     *
     * Documented was not enough: `ADR-002`'s own MinIO row was documented for six tasks while
     * the service was absent (`R-125`). This makes the divergence UNREACHABLE instead -- the
     * mechanism is a bucket policy and CloudFront, decided in Terraform (`PLAN.md §3` row 13),
     * and the application does not get to express it.
     *
     * SCOPED TO THE APPLICATION, which is `§1`'s own wording ("nothing under `apps/` may
     * depend on which one is running"): `app/` and `routes/`. NOT `config/`, where the local
     * `public` disk legitimately declares `'visibility' => 'public'` for a filesystem that has
     * no ACLs at all, and not `tests/`, where a test proving the refusal has to be able to
     * make the call.
     *
     * THE LIMIT, stated rather than left to be discovered: the bare third-argument form
     * `put($path, $contents, 'public')` is NOT matched, because `'public'` is also the name of
     * a disk in this repository and a rule that flagged it would flag `Storage::disk('public')`
     * -- rule 1's own lesson about flagging the documentation of the thing you enforce. What is
     * matched is every form that names the mechanism: the call, the flysystem constant, the
     * option key, and S3's canned ACL values.
     */
    public const PUBLIC_OBJECT_VISIBILITY = 'public-object-visibility';

    /** S3's canned ACLs. Any of them in application code is rule 10's subject. */
    public const CANNED_ACLS = [
        'public-read',
        'public-read-write',
        'authenticated-read',
        'bucket-owner-read',
        'bucket-owner-full-control',
        'aws-exec-read',
        'log-delivery-write',
    ];

    /**
     * The paths rule 10 applies to: application code, relative to `apps/api`.
     *
     * @var list<string>
     */
    public const APPLICATION_PATHS = ['app/', 'routes/'];

    /**
     * Rule 11: a tenant theme overrides TOKENS ONLY, never components.
     *
     * `PLAN.md §3.1` row 7 says that clause "names no mechanism. Same empty-phrase shape
     * the fourth review found in 'architecture guards' -- it needs a lint rule proven to
     * fail on a bad file, or it is not checkable". This is that rule, and it has four
     * clauses because the sentence makes four separate promises:
     *
     *   11a  NO `<style` ANYWHERE in application code. A class that carried its own
     *        stylesheet would be a component styling itself, which is the half of the
     *        clause that says "never components".
     *   11b  NO CSS RULE-SET and no at-rule in a string literal. `.card { … }` IS a
     *        component rule; `@import` and `@font-face` fetch. A theme may change a
     *        VALUE and may never introduce, remove or re-target a rule.
     *   11c  NO THEME TOKEN NAME outside `ThemeTokens`. The allow-list has one home, so
     *        a second copy cannot drift from it -- the duplicated-list shape QA H-1
     *        cost this project once already.
     *   11d  `ThemeTokens::property()` IS CALLED FROM EXACTLY ONE FILE. That is the
     *        "one file may emit a CSS declaration" half, stated as the thing a scanner
     *        can actually see: the only way to build a custom-property name is that
     *        method, so the only file that calls it is the only file that emits one.
     *
     * THE TOKEN LIST IS READ OUT OF `ThemeTokens` BY PARSING IT, never held here. A
     * guard carrying its own copy of the list it exists to keep singular would be the
     * very defect 11c is about.
     */
    public const STYLE_LITERAL = 'style-literal';

    public const CSS_RULE_SET = 'css-rule-set';

    public const THEME_TOKEN_COPIED = 'theme-token-copied';

    public const THEME_EMITTER = 'theme-emitter';

    /** The one home of the token list. Exempt from 11c, and from nothing else. */
    public const THEME_TOKENS_FILE = 'app/Domain/Landing/ThemeTokens.php';

    /** The one file that may build a CSS declaration (11d). */
    public const THEME_STYLESHEET_FILE = 'app/Features/Landing/ThemeStylesheet.php';

    /** A CSS at-rule: all of these either fetch or redefine, and none belongs in app/. */
    public const CSS_AT_RULES = ['@import', '@font-face', '@keyframes', '@media', '@supports'];

    /**
     * The documents that must exist exactly once, relative to the REPOSITORY root.
     *
     * Not `self::PATHS`, which is relative to `apps/api` and lists PHP directories. These
     * live two levels up, which is why `repositoryRoot()` exists.
     *
     * @var array<string, string>
     */
    public const CANONICAL_DOCS = [
        'plan' => 'docs/PLAN.md',
        'blueprint' => 'docs/BLUEPRINT.md',
    ];

    /** A markdown heading whose text is "task table". C7's subject. */
    public const TASK_TABLE_HEADING = '/^#{1,6}\s+.*task\s+table/im';

    /**
     * The paths this guard scans. ONE source, and both halves of that matter.
     *
     * It was spelled out twice -- in guard/bin/guard's default and again in
     * RepoGuardTest's "passes on the real tree" assertion -- so the test could agree
     * with a copy of the list while disagreeing with the list CI actually runs. A
     * guard whose coverage is asserted against a duplicate of its own configuration
     * is asserting nothing.
     *
     * What is NOT here is deliberate and is tracked rather than quietly widened:
     * bootstrap/ and docker/ are outside this list, which is a Medium in PLAN.md §5
     * (R-030) with its own owner. This const is where that row gets fixed, in one
     * line, when it is fixed.
     *
     * @var list<string>
     */
    public const PATHS = ['app', 'config', 'database', 'routes', 'tests', 'guard/src'];

    /**
     * The single named exception (R-019): one method, in one file, for one rule.
     *
     * Tests\Support\Composition::setSessionGuc() exists to produce the state C2 forbids,
     * because the refusals it proves -- a tenant setting no scope in this process opened,
     * the bypass-side GUC guard, current()'s provenance check -- cannot be reached any
     * other way, and a test that cannot produce the state cannot prove the refusal.
     *
     * By path and by method, so moving the helper or adding a second one anywhere --
     * including elsewhere in the same file -- fails the guard.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const EXCEPTIONS = [
        'tests/Support/Composition.php' => [
            'setSessionGuc' => [self::SESSION_SCOPED_SET_CONFIG],
        ],

        // Rule 7's positive control: the one place in this repository that writes the
        // broken shape ON PURPOSE, to prove it really is inert. Without this exception
        // the guard flags its own proof, and a guard that reports its own proof as the
        // defect is one somebody switches off.
        //
        // By file AND by function name, like the row above and for the same reason
        // (R-019): moving it, renaming it, or adding a second anywhere — including
        // elsewhere in that same file — fails the guard.
        'tests/Feature/Architecture/ExpectationApiTest.php' => [
            'pestReadsASecondArgumentAsANeedle' => [self::VACUOUS_NEGATED_CONTAINS],
        ],
    ];

    public function __construct(private readonly string $root) {}

    /**
     * @param  list<string>  $relativePaths  files and directories, relative to the root
     * @return list<Violation>
     */
    public function scan(array $relativePaths): array
    {
        $violations = [];

        foreach ($this->scannedFiles($relativePaths) as $relative => $absolute) {
            foreach ($this->scanFile($relative, (string) file_get_contents($absolute)) as $violation) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * Rule 6: every configured path has to produce something to read.
     *
     * A GUARD THAT SCANNED NOTHING MUST NOT REPORT A PASS, and it did. `files()`
     * skipped a path that is neither a file nor a directory, and the success line
     * counted the paths it was GIVEN rather than the files it read -- so
     * `guard/bin/guard src nonexistent` printed "C1 and C2 hold across 2 scanned
     * path(s)" and exited 0 having opened nothing. Renaming app/ to src/ would have
     * disabled the whole guard with a green CI.
     *
     * That is R-029's shape -- a check reporting a conclusion it had not established --
     * in a gate R-029's own candidate list did not name. It is reported through the
     * same channel as every other rule, so the exit code and the message need no
     * special case: an unreadable path is a violation.
     *
     * A directory that contains no .php file counts as unscannable too. "I walked it
     * and read nothing" is the same vacuous pass as "I could not find it", and only the
     * second one has an obvious cause.
     *
     * THE THIRD CASE IS STILL OPEN AND IS R-048. This rule covers a path that resolves
     * to nothing and a path that yields no file. It does not cover a file that is
     * COUNTED and then not read: scan() reads content as
     * `(string) file_get_contents($absolute)`, so a failed open becomes an empty string
     * and yields no violations. Measured 2026-10-27: a file containing a forbidden
     * literal, at mode 000, left `composer guard` printing "C1 and C2 hold across 125
     * file(s)" and exiting 0 -- counted as read, never read. Task 12 owns it with
     * R-030, because the fix is in the same clause.
     *
     * @param  list<string>  $relativePaths
     * @return list<Violation>
     */
    public function scanScannablePaths(array $relativePaths): array
    {
        $violations = [];

        foreach ($relativePaths as $relative) {
            $absolute = $this->root.'/'.$relative;

            if (! is_file($absolute) && ! is_dir($absolute)) {
                $violations[] = new Violation(
                    self::UNSCANNABLE_PATH, $relative, 0,
                    'does not resolve to a file or a directory under the project root, so nothing was '
                    .'read there -- a configured path that cannot be found disables the guard silently',
                );

                continue;
            }

            if ($this->scannedFiles([$relative]) === []) {
                $violations[] = new Violation(
                    self::UNSCANNABLE_PATH, $relative, 0,
                    'resolved, but produced no file to scan -- the guard would report a pass for a '
                    .'path it never read',
                );
            }
        }

        return $violations;
    }

    /**
     * Rule 5: the configured GUC set is exactly C1's two.
     *
     * The other rules cannot see a GUC name that only ever travels as a bound parameter,
     * and in this codebase that is how every name travels -- set_config(?, ?, true) with
     * DatabaseRoles::guc() supplying it. config/tenancy.php is where a third name would
     * actually be introduced, so that is where it is checked.
     *
     * @return list<Violation>
     */
    public function scanConfiguredGucs(string $relativeConfigPath = 'config/tenancy.php'): array
    {
        $absolute = $this->root.'/'.$relativeConfigPath;

        if (! is_file($absolute)) {
            return [new Violation(
                self::UNEXPECTED_CONFIGURED_GUCS, $relativeConfigPath, 0,
                'the tenancy config does not exist, so the GUC names cannot be checked',
            )];
        }

        $array = $this->literalArray((string) file_get_contents($absolute));
        $guc = is_array($array['guc'] ?? null) ? $array['guc'] : [];
        $configured = array_values(array_filter($guc, 'is_string'));

        sort($configured);
        $expected = self::GUCS;
        sort($expected);

        if ($configured === $expected) {
            return [];
        }

        return [new Violation(
            self::UNEXPECTED_CONFIGURED_GUCS, $relativeConfigPath, 0,
            'configured GUCs are ['.implode(', ', array_map('strval', $configured))
            .'], expected exactly ['.implode(', ', $expected).'] (C1)',
        )];
    }

    /**
     * Framework config keys, read from config/app.php rather than listed here.
     *
     * Without this, `config('app.name')` -- a literal of the same shape as a GUC name --
     * would fail rule 3 and the next person would weaken the guard to get a build
     * through. Derived from the file that defines them, so it cannot drift.
     *
     * @return list<string>
     */
    private function frameworkAppConfigKeys(): array
    {
        $absolute = $this->root.'/config/app.php';

        if (! is_file($absolute)) {
            return [];
        }

        $keys = array_keys($this->literalArray((string) file_get_contents($absolute)));

        return array_values(array_map(
            static fn (int|string $key): string => 'app.'.$key,
            $keys,
        ));
    }

    /**
     * The array a config file returns, read from its tokens rather than by executing it.
     *
     * The guard does not boot Laravel, and config/app.php's first line calls env(), so
     * `require` fatals -- which is how this method came to exist rather than being
     * designed. Only literal string keys and literal string values are read; any other
     * value becomes null, and a nested array becomes an array. That is enough for both
     * callers: the key NAMES of config/app.php, and the string VALUES under
     * config/tenancy.php's `guc`.
     *
     * @return array<string, mixed>
     */
    private function literalArray(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn (array|string $token): bool => ! is_array($token)
                || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $index = 0;
        $count = count($tokens);

        while ($index < $count) {
            $token = $tokens[$index];
            $index++;

            if (is_array($token) && $token[0] === T_RETURN) {
                break;
            }
        }

        while ($index < $count && $tokens[$index] !== '[') {
            $index++;
        }

        if ($index >= $count) {
            return [];
        }

        $index++; // step past the opening bracket

        return $this->readArrayBody($tokens, $index);
    }

    /**
     * @param  array<int, array{int, string, int}|string>  $tokens
     * @return array<string, mixed>
     */
    private function readArrayBody(array $tokens, int &$index): array
    {
        $result = [];
        $count = count($tokens);

        while ($index < $count) {
            $token = $tokens[$index];

            if ($token === ']') {
                $index++;

                return $result;
            }

            if ($token === ',') {
                $index++;

                continue;
            }

            // A key, only when it is a literal string followed by =>.
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING
                && ($tokens[$index + 1] ?? null) !== null
                && is_array($tokens[$index + 1]) && $tokens[$index + 1][0] === T_DOUBLE_ARROW) {
                $key = substr($token[1], 1, -1);
                $index += 2;

                $value = $tokens[$index] ?? null;

                if ($value === '[') {
                    $index++;
                    $result[$key] = $this->readArrayBody($tokens, $index);

                    continue;
                }

                if (is_array($value) && $value[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $result[$key] = substr($value[1], 1, -1);
                    $index++;

                    continue;
                }

                $result[$key] = null;
                $this->skipValue($tokens, $index);

                continue;
            }

            $this->skipValue($tokens, $index);
        }

        return $result;
    }

    /**
     * @param  array<int, array{int, string, int}|string>  $tokens
     */
    private function skipValue(array $tokens, int &$index): void
    {
        $depth = 0;
        $count = count($tokens);

        while ($index < $count) {
            $token = $tokens[$index];

            if ($token === '[' || $token === '(') {
                $depth++;
            }

            if ($token === ')') {
                $depth--;
            }

            if ($token === ']') {
                if ($depth === 0) {
                    return;
                }

                $depth--;
            }

            if ($token === ',' && $depth === 0) {
                return;
            }

            $index++;
        }
    }

    /**
     * One file's violations, from source handed in rather than read from disk.
     *
     * Public because the one thing the exception in EXCEPTIONS has to be proven about
     * is that it is scoped to a METHOD and not to a file (R-019), and proving that
     * needs the real path with altered source. The alternative was for the test to
     * write over tests/Support/Composition.php and restore it in a finally, which
     * makes the guard's own test the thing that can break the repo.
     *
     * @return list<Violation>
     */
    /**
     * Rule 7: `->not->toContain(...)` and `->not->toContainEqual(...)` take exactly one argument.
     *
     * TOKENS, NOT TEXT, for the same reason every other rule here scans tokens: this
     * repository documents the rule in a dozen comments, and a text-matching guard would
     * flag the documentation of the thing it enforces. `token_get_all()` makes comments
     * and doc comments invisible, so an explanation of the defect is not the defect --
     * which is the property RepoGuardTest already has a case for on rule 1.
     *
     * The shape being matched is three consecutive significant tokens --
     * `-> not -> <matcher> (` -- with whitespace and comments skipped between them, so
     * a call broken across lines is caught too. The argument list is then walked for a
     * comma at depth zero, outside every string, bracket and paren: a comma INSIDE a
     * needle is one argument, and `not->toContain('rolled back, to the depth')` is
     * perfectly correct. A guard that cried wolf on that would be switched off.
     *
     * @return list<Violation>
     */
    private function scanVacuousAssertions(string $relative, string $source): array
    {
        $violations = [];
        $tokens = token_get_all($source);

        // Significant tokens only, keeping each one's line, so "->not->toContain" spread
        // over three lines with a comment between reads the same as one written inline.
        $significant = [];

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $significant[] = [$token[1], $token[2]];

                continue;
            }

            $significant[] = [$token, $significant === [] ? 1 : $significant[count($significant) - 1][1]];
        }

        $count = count($significant);

        for ($i = 0; $i + 4 < $count; $i++) {
            if ($significant[$i][0] !== '->' || $significant[$i + 1][0] !== 'not') {
                continue;
            }

            if ($significant[$i + 2][0] !== '->') {
                continue;
            }

            $matcher = $significant[$i + 3][0];

            if (! in_array($matcher, self::VARIADIC_MATCHERS, true) || $significant[$i + 4][0] !== '(') {
                continue;
            }

            $arity = $this->arityFrom($significant, $i + 4);
            $line = $significant[$i + 3][1];

            // THE SAME EXCEPTION CHANNEL EVERY OTHER RULE USES, and wiring it was not
            // optional: the first version of this method built its violations directly
            // and never consulted EXCEPTIONS, so rule 7's own positive control was
            // reported as a violation and the guard flagged its own proof.
            if ($arity > 1 && ! $this->isExcepted($relative, $line, self::VACUOUS_NEGATED_CONTAINS, $source)) {
                $violations[] = new Violation(
                    self::VACUOUS_NEGATED_CONTAINS,
                    $relative,
                    $line,
                    "not->{$matcher}() is given {$arity} arguments; it is VARIADIC, so the second is read as another "
                    .'needle and the assertion becomes "does not contain BOTH" -- which a message that never appears '
                    .'makes trivially true. Use one needle, or expect(str_contains($h, $n))->toBeFalse($message)',
                );
            }
        }

        return $violations;
    }

    /**
     * How many top-level arguments the call opening at $open takes.
     *
     * Depth counting over significant tokens, so a nested call, an array literal or a
     * closure in the argument list contributes no commas of its own. Zero for `()`.
     *
     * @param  list<array{string, int}>  $tokens
     */
    private function arityFrom(array $tokens, int $open): int
    {
        $depth = 0;
        $arguments = 0;
        $count = count($tokens);

        for ($i = $open; $i < $count; $i++) {
            $text = $tokens[$i][0];

            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;

                if ($depth === 1) {
                    // The first token inside the call: there is at least one argument
                    // unless the very next token closes it.
                    $arguments = ($i + 1 < $count && $tokens[$i + 1][0] === ')') ? 0 : 1;
                }

                continue;
            }

            if (in_array($text, [')', ']', '}'], true)) {
                $depth--;

                if ($depth === 0) {
                    return $arguments;
                }

                continue;
            }

            if ($text === ',' && $depth === 1) {
                $arguments++;
            }
        }

        return $arguments;
    }

    public function scanSource(string $relative, string $source): array
    {
        return $this->scanFile($relative, $source);
    }

    /**
     * @return list<Violation>
     */
    private function scanFile(string $relative, string $source): array
    {
        $violations = array_merge(
            $this->scanVacuousAssertions($relative, $source),
            $this->scanObjectVisibility($relative, $source),
            $this->scanThemeBoundary($relative, $source),
        );
        $allowed = array_merge(self::GUCS, $this->frameworkAppConfigKeys());

        foreach ($this->stringLiterals($source) as [$text, $line]) {
            foreach ($this->inspect($text, $line) as [$rule, $offsetLine, $detail]) {
                if ($this->isExcepted($relative, $offsetLine, $rule, $source)) {
                    continue;
                }

                if ($rule === self::UNKNOWN_APP_GUC && in_array($detail, $allowed, true)) {
                    continue;
                }

                $violations[] = new Violation($rule, $relative, $offsetLine, match ($rule) {
                    // Phrased without a literal call, because this guard scans its own
                    // source and the first version of this message failed rule 1:
                    // token scanning makes comments invisible, not message strings.
                    self::SESSION_SCOPED_SET_CONFIG => "the third argument of set_config is [{$detail}]; C2 requires the literal true -- a session-scoped tenant setting is a hard failure",
                    self::RAW_SET_APP_GUC => "raw [{$detail}]; C2 forbids SET for an app.* setting -- Postgres SET takes no bind parameters, so use select set_config(name, value, true)",
                    self::UNKNOWN_APP_GUC => "[{$detail}] is not one of C1's two GUCs (".implode(', ', self::GUCS).'); a third name requires editing PROJECT_INSTRUCTIONS.md first',
                    default => $detail,
                });
            }
        }

        return $violations;
    }

    /**
     * Rules 1-4 against one string literal.
     *
     * @return list<array{string, int, string}>
     */
    private function inspect(string $text, int $line): array
    {
        $found = [];
        $lineOf = static fn (int $offset): int => $line + substr_count(substr($text, 0, $offset), "\n");

        // Rule 1 (C2): every set_config() call's third argument is the literal true.
        $offset = 0;
        while (($position = stripos($text, 'set_config', $offset)) !== false) {
            $offset = $position + 10;
            $open = strpos($text, '(', $position);

            if ($open === false || trim(substr($text, $position + 10, $open - $position - 10)) !== '') {
                continue; // `set_config` as a bare word, e.g. an assertion on a message
            }

            $arguments = $this->arguments($text, $open);

            if ($arguments === null) {
                continue; // the call is split across concatenated literals; rule 3 still applies
            }

            $third = isset($arguments[2]) ? strtolower(trim($arguments[2])) : '';

            if ($third !== 'true') {
                $found[] = [self::SESSION_SCOPED_SET_CONFIG, $lineOf($position), $third === '' ? 'absent' : $third];
            }

            // Rule 3 (C1): a GUC named literally in the first argument.
            if (isset($arguments[0]) && preg_match("/^'(app\\.[a-z0-9_]+)'$/i", trim($arguments[0]), $m) === 1) {
                $found[] = [self::UNKNOWN_APP_GUC, $lineOf($position), $m[1]];
            }
        }

        // Rule 2 (C2): a raw SET of an app.* setting, in any of its spellings.
        if (preg_match_all('/\bset\s+(?:local\s+|session\s+)?(app\.[a-z0-9_]+)/i', $text, $matches, PREG_OFFSET_CAPTURE) >= 1) {
            foreach ($matches[0] as $index => $match) {
                $found[] = [self::RAW_SET_APP_GUC, $lineOf((int) $match[1]), (string) $match[0]];
                $found[] = [self::UNKNOWN_APP_GUC, $lineOf((int) $match[1]), strtolower((string) $matches[1][$index][0])];
            }
        }

        // Rule 3 (C1): a GUC named literally inside current_setting().
        if (preg_match_all("/\\bcurrent_setting\\s*\\(\\s*'(app\\.[a-z0-9_]+)'/i", $text, $matches, PREG_OFFSET_CAPTURE) >= 1) {
            foreach ($matches[1] as $match) {
                $found[] = [self::UNKNOWN_APP_GUC, $lineOf((int) $match[1]), strtolower((string) $match[0])];
            }
        }

        // Rule 4 (C1): a whole literal that is a GUC name -- how every name in this
        // codebase actually reaches Postgres, as a bound parameter.
        if (preg_match('/^app\.[a-z0-9_]+$/i', $text) === 1) {
            $found[] = [self::UNKNOWN_APP_GUC, $line, strtolower($text)];
        }

        return $found;
    }

    /**
     * The top-level arguments of a call whose opening parenthesis is at $open.
     *
     * Returns null when the parentheses do not balance inside this literal, which
     * happens when a call is assembled from concatenated pieces -- unresolvable here,
     * and left to rule 3 rather than guessed at.
     *
     * @return list<string>|null
     */
    private function arguments(string $text, int $open): ?array
    {
        $depth = 0;
        $current = '';
        $arguments = [];
        $length = strlen($text);

        for ($i = $open; $i < $length; $i++) {
            $character = $text[$i];

            if ($character === '(') {
                $depth++;

                if ($depth === 1) {
                    continue;
                }
            }

            if ($character === ')') {
                $depth--;

                if ($depth === 0) {
                    $arguments[] = $current;

                    return $arguments;
                }
            }

            if ($character === ',' && $depth === 1) {
                $arguments[] = $current;
                $current = '';

                continue;
            }

            $current .= $character;
        }

        return null;
    }

    /**
     * Every string literal in the source, with the line it starts on.
     *
     * Single-quoted, double-quoted, heredoc and nowdoc bodies. Comments, identifiers
     * and function names are not literals and are therefore never read.
     *
     * @return list<array{string, int}>
     */
    private function stringLiterals(string $source): array
    {
        $literals = [];

        foreach (token_get_all($source) as $token) {
            if (! is_array($token)) {
                continue;
            }

            [$id, $text, $line] = $token;

            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $literals[] = [substr($text, 1, -1), $line];

                continue;
            }

            if ($id === T_ENCAPSED_AND_WHITESPACE || $id === T_INLINE_HTML) {
                $literals[] = [$text, $line];
            }
        }

        return $literals;
    }

    /**
     * Is this violation the one named exception -- right file, right rule, and inside
     * the right method?
     */
    private function isExcepted(string $relative, int $line, string $rule, string $source): bool
    {
        $methods = self::EXCEPTIONS[$relative] ?? null;

        if ($methods === null) {
            return false;
        }

        foreach ($methods as $method => $rules) {
            if (! in_array($rule, $rules, true)) {
                continue;
            }

            [$from, $to] = $this->methodLines($source, $method);

            if ($from !== null && $to !== null && $line >= $from && $line <= $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first and last line of a named function, by walking braces from its
     * declaration. Used only to keep the exception scoped to one method rather than to
     * a whole file (R-019: by path, never "tests are exempt").
     *
     * @return array{int|null, int|null}
     */
    private function methodLines(string $source, string $method): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];

                if (is_array($next) && $next[0] === T_WHITESPACE) {
                    continue;
                }

                if (! is_array($next) || $next[0] !== T_STRING || $next[1] !== $method) {
                    break;
                }

                return $this->braceSpan($tokens, $j, $next[2]);
            }
        }

        return [null, null];
    }

    /**
     * @param  array<int, array{int, string, int}|string>  $tokens
     * @return array{int|null, int|null}
     */
    private function braceSpan(array $tokens, int $from, int $startLine): array
    {
        $depth = 0;
        $line = $startLine;
        $count = count($tokens);

        for ($i = $from; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                $line = $token[2] + substr_count($token[1], "\n");

                continue;
            }

            if ($token === '{') {
                $depth++;
            }

            if ($token === '}') {
                $depth--;

                if ($depth === 0) {
                    return [$startLine, $line];
                }
            }
        }

        return [$startLine, null];
    }

    /**
     * The files a path list actually produces, keyed by their path relative to the root.
     *
     * Public because the binary states the COUNT in its success line: "held across 6
     * paths" was true of a run that read nothing, and the number a reader can act on is
     * the number of files opened.
     *
     * @param  list<string>  $relativePaths
     * @return array<string, string> relative path => absolute path
     */
    /**
     * Rule 8: the canonical documents exist exactly once, and only PLAN.md has a task table.
     *
     * WALKS THE FILESYSTEM, NOT `git ls-files`, and that is a correction. The first version
     * listed tracked markdown, which read well and broke in the one environment that
     * matters most for a guard: `bin/coverage` runs this repository inside a container
     * where git refuses the mount for dubious ownership, so the rule degraded to "could not
     * run" and three of its own tests failed there while passing on the host. A guard whose
     * result depends on who is running it is `R-054`'s shape, and this project has paid for
     * that once already.
     *
     * Walking also catches the defect EARLIER. H-1's two files were committed, but the
     * class is "a download landed in docs/ and nobody noticed" -- and an untracked one is
     * the same hazard one step before the commit.
     *
     * PRUNED, so "anywhere in the tree" stays honest without being slow: `.git`, `vendor`,
     * `node_modules`, `storage`, `build`, and this guard's own fixture directory -- which is
     * excluded because it deliberately CONTAINS a stale plan and a duplicate Blueprint, and
     * a rule that reported its own proof is one somebody switches off.
     *
     * @return list<Violation>
     */
    public function scanCanonicalDocs(): array
    {
        $root = $this->repositoryRoot();
        $planPath = self::CANONICAL_DOCS['plan'];
        $blueprintPath = self::CANONICAL_DOCS['blueprint'];

        $blueprint = @file_get_contents($root.'/'.$blueprintPath);
        $fingerprint = is_string($blueprint) ? hash('sha256', $blueprint) : null;

        $violations = [];

        foreach ($this->markdownFiles($root) as $relative => $absolute) {
            $contents = @file_get_contents($absolute);

            if (! is_string($contents)) {
                continue;
            }

            // (a) C7: exactly one task table, in docs/PLAN.md.
            if ($relative !== $planPath
                && preg_match(self::TASK_TABLE_HEADING, $contents, $match, PREG_OFFSET_CAPTURE) === 1) {
                $violations[] = new Violation(
                    self::DUPLICATE_CANONICAL_DOC,
                    $relative,
                    1 + substr_count(substr($contents, 0, (int) $match[0][1]), "\n"),
                    'carries a task-table heading, and C7 allows exactly one task table, in '.$planPath
                    .'. A second one is a second claimant to rank 3 of §0: the next session to read this '
                    .'repository may act on the stale one',
                );
            }

            // (b) one Blueprint. It is an ARCHIVE and a binding baseline (§0), so a second
            // copy is a second thing to keep in step -- and the one that drifts is the one
            // somebody reads.
            if ($relative !== $blueprintPath && $fingerprint !== null && hash('sha256', $contents) === $fingerprint) {
                $violations[] = new Violation(
                    self::DUPLICATE_CANONICAL_DOC,
                    $relative,
                    1,
                    'is byte-identical to '.$blueprintPath.', which must exist exactly once',
                );
            }
        }

        return $violations;
    }

    /**
     * Rule 9, over every workflow in the repository.
     *
     * Two clauses:
     *   (a) per file -- a `push:` trigger whose `branches:` list does not include `**`, or
     *       which carries a `branches-ignore:` at all, can exclude a pushed branch;
     *   (b) over the directory -- at least one workflow must run on every pushed branch,
     *       because a repository whose only CI is triggered by tags and pull requests has
     *       the same hole without any pattern being wrong.
     *
     * @return list<Violation>
     */
    public function scanWorkflowTriggers(string $relativeDir = self::WORKFLOW_DIR): array
    {
        $files = $this->workflowFiles($relativeDir);

        if ($files === []) {
            // Rule 6's shape: nothing read is not a pass.
            return [new Violation(
                self::WORKFLOW_EXCLUDES_PUSHED_BRANCH,
                $relativeDir,
                1,
                'holds no workflow file, so no push is evaluated by anything -- and a guard that '
                .'reported a pass for a directory it found empty would be asserting nothing',
            )];
        }

        $violations = [];
        $anyRunsOnEveryBranch = false;

        foreach ($files as $relative => $path) {
            $source = (string) file_get_contents($path);
            $trigger = $this->pushTrigger($source);

            foreach ($this->scanWorkflowSource($relative, $source) as $violation) {
                $violations[] = $violation;
            }

            if ($trigger['readable'] && $trigger['push'] && $trigger['branches'] === null && $trigger['ignore'] === null) {
                $anyRunsOnEveryBranch = true;
            }

            if ($trigger['readable'] && $trigger['push'] && $trigger['ignore'] === null
                && $trigger['branches'] !== null && in_array(self::EVERY_BRANCH, $trigger['branches'], true)) {
                $anyRunsOnEveryBranch = true;
            }
        }

        if (! $anyRunsOnEveryBranch) {
            $violations[] = new Violation(
                self::WORKFLOW_EXCLUDES_PUSHED_BRANCH,
                $relativeDir,
                1,
                'no workflow here runs on every pushed branch, so a branch outside whatever patterns '
                .'are configured can be merged having never been evaluated -- which is R-126, measured '
                .'on this repository',
            );
        }

        return $violations;
    }

    /**
     * Every workflow in the directory, keyed by path relative to the repository root.
     *
     * `.fixture` files are not matched, which is why rule 9's own fixtures can live in the
     * tree without being read as workflows -- the same reason the repo fixtures are not `.php`.
     *
     * Public because `guard/bin/guard` reports the COUNT in its success line: a rule whose
     * pass does not say what it read is the vacuous pass rule 6 exists for.
     *
     * @return array<string, string>
     */
    public function workflowFiles(string $relativeDir = self::WORKFLOW_DIR): array
    {
        $directory = $this->repositoryRoot().'/'.$relativeDir;
        $files = [];

        foreach (['yml', 'yaml'] as $extension) {
            foreach (glob($directory.'/*.'.$extension) ?: [] as $path) {
                $files[$relativeDir.'/'.basename($path)] = $path;
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * Rule 9 against one workflow's text.
     *
     * @return list<Violation>
     */
    public function scanWorkflowSource(string $relative, string $source): array
    {
        $trigger = $this->pushTrigger($source);

        if (! $trigger['readable']) {
            return [new Violation(
                self::WORKFLOW_EXCLUDES_PUSHED_BRANCH,
                $relative,
                1,
                'its trigger could not be read, so whether a pushed branch reaches CI cannot be '
                .'established from it. This rule fails CLOSED rather than assuming the permissive case',
            )];
        }

        if (! $trigger['push']) {
            return [];
        }

        $violations = [];

        if ($trigger['ignore'] !== null) {
            $violations[] = new Violation(
                self::WORKFLOW_EXCLUDES_PUSHED_BRANCH,
                $relative,
                $trigger['line'],
                'carries branches-ignore ['.implode(', ', $trigger['ignore']).'] on its push trigger; '
                .'anything it names is a pushed branch that reaches no CI',
            );
        }

        if ($trigger['branches'] !== null && ! in_array(self::EVERY_BRANCH, $trigger['branches'], true)) {
            $violations[] = new Violation(
                self::WORKFLOW_EXCLUDES_PUSHED_BRANCH,
                $relative,
                $trigger['line'],
                'runs on push only for ['.implode(', ', $trigger['branches']).'], so a branch named '
                .'anything else is pushed, reviewed and merged without a verdict. Use '
                .self::EVERY_BRANCH.' -- tag and pull_request triggers are unaffected',
            );
        }

        return $violations;
    }

    /**
     * The push trigger of one workflow, read from its text.
     *
     * `readable` is false when there is no top-level `on:` key at all -- the one case where
     * this parser cannot say anything, and therefore the one it refuses rather than guesses.
     * `branches` and `ignore` are null when the key is absent, which in Actions means every
     * branch; an empty list is distinct from an absent one and is kept as one.
     *
     * Deliberately a line parser over the shapes GitHub accepts (`on: push`,
     * `on: [push, pull_request]`, a block mapping with inline or block sequences) rather than
     * a YAML load: this guard loads no autoloader, which is also why rule 1 hard-codes GUCS.
     *
     * @return array{readable: bool, push: bool, branches: ?list<string>, ignore: ?list<string>, line: int}
     */
    private function pushTrigger(string $source): array
    {
        $lines = preg_split('/\R/', $source) ?: [];

        $result = ['readable' => false, 'push' => false, 'branches' => null, 'ignore' => null, 'line' => 1];

        $inOn = false;
        $keyIndent = null;
        $inPush = false;
        $collecting = null;

        foreach ($lines as $number => $raw) {
            $line = rtrim($raw);
            $text = trim($line);

            if ($text === '' || str_starts_with($text, '#')) {
                continue;
            }

            $indent = strlen($line) - strlen(ltrim($line));

            if ($indent === 0) {
                $inPush = false;
                $collecting = null;
                $keyIndent = null;
                $inOn = preg_match('/^(on|"on"|\x27on\x27)\s*:/', $text) === 1;

                if (! $inOn) {
                    continue;
                }

                $result['readable'] = true;
                $result['line'] = $number + 1;
                $value = trim((string) preg_replace('/^[^:]*:/', '', $text));

                // `on: push` or `on: [push, pull_request]` -- no filters can exist.
                if ($value !== '' && ! str_starts_with($value, '#')) {
                    $result['push'] = $this->mentionsPush($value);
                    $inOn = false;
                }

                continue;
            }

            if (! $inOn) {
                continue;
            }

            $keyIndent ??= $indent;

            if ($indent === $keyIndent) {
                $collecting = null;
                $inPush = preg_match('/^push\s*:/', $text) === 1;

                if ($inPush) {
                    $result['push'] = true;
                    $result['line'] = $number + 1;
                }

                continue;
            }

            if (! $inPush) {
                continue;
            }

            if (preg_match('/^(branches|branches-ignore)\s*:(.*)$/', $text, $match) === 1) {
                $key = $match[1] === 'branches' ? 'branches' : 'ignore';
                $inline = trim($match[2]);
                $result['line'] = $number + 1;

                if ($inline !== '' && ! str_starts_with($inline, '#')) {
                    $result[$key] = $this->flowList($inline);
                    $collecting = null;

                    continue;
                }

                $result[$key] = [];
                $collecting = $key;

                continue;
            }

            if ($collecting !== null && str_starts_with($text, '- ')) {
                $result[$collecting][] = $this->scalar(substr($text, 2));

                continue;
            }

            // Any other key inside push: ends a sequence we were collecting.
            if (preg_match('/^[A-Za-z_-]+\s*:/', $text) === 1) {
                $collecting = null;
            }
        }

        return $result;
    }

    /** Does an `on:` scalar or flow list mention push? */
    private function mentionsPush(string $value): bool
    {
        foreach ($this->flowList($value) as $event) {
            if ($event === 'push') {
                return true;
            }
        }

        return false;
    }

    /**
     * A YAML flow list or bare scalar as a list of unquoted strings.
     *
     * @return list<string>
     */
    private function flowList(string $value): array
    {
        $value = trim($value);

        if (str_starts_with($value, '[')) {
            $value = trim($value, '[]');
        }

        $items = [];

        foreach (explode(',', $value) as $item) {
            $item = $this->scalar($item);

            if ($item !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }

    /** One YAML scalar, unquoted and with a trailing comment removed. */
    private function scalar(string $value): string
    {
        $value = trim($value);
        $value = (string) preg_replace('/\s+#.*$/', '', $value);

        return trim(trim($value), "\"\x27");
    }

    /**
     * Rule 10 against one application file.
     *
     * Tokens, not string literals: the subject is a METHOD NAME and a class constant as
     * well as option values, and rule 1's literal scan cannot see either.
     *
     * @return list<Violation>
     */
    public function scanObjectVisibility(string $relative, string $source): array
    {
        $application = false;

        foreach (self::APPLICATION_PATHS as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                $application = true;

                break;
            }
        }

        if (! $application) {
            return [];
        }

        $violations = [];
        $tokens = token_get_all($source);

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }

            [$id, $text, $line] = $token;

            if ($id === T_STRING && $text === 'setVisibility') {
                $violations[] = new Violation(
                    self::PUBLIC_OBJECT_VISIBILITY,
                    $relative,
                    $line,
                    'calls setVisibility, which is an object ACL on S3. The local stand-in honours '
                    .'ACLs and production does not (ADR-004): a published asset is a bucket policy '
                    .'and CloudFront, decided in Terraform, never a call from here',
                );

                continue;
            }

            if ($id === T_STRING && $text === 'Visibility' && $this->followedByDoubleColon($tokens, $index)) {
                $violations[] = new Violation(
                    self::PUBLIC_OBJECT_VISIBILITY,
                    $relative,
                    $line,
                    'names the flysystem Visibility constant, which reaches S3 as an object ACL '
                    .'(ADR-004). Public visibility is decided in Terraform, not from here',
                );

                continue;
            }

            if ($id !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $value = substr($text, 1, -1);

            if ($value === 'visibility') {
                $violations[] = new Violation(
                    self::PUBLIC_OBJECT_VISIBILITY,
                    $relative,
                    $line,
                    'passes a visibility option to a filesystem call, which on the s3 disk is an '
                    .'object ACL (ADR-004)',
                );

                continue;
            }

            if ($value === 'ACL' || in_array($value, self::CANNED_ACLS, true)) {
                $violations[] = new Violation(
                    self::PUBLIC_OBJECT_VISIBILITY,
                    $relative,
                    $line,
                    "names the S3 ACL [{$value}] directly; ADR-004 keeps object ACLs out of the "
                    .'application entirely, because the stand-in accepts them and production refuses them',
                );
            }
        }

        return $violations;
    }

    /**
     * Rule 11 against one application file.
     *
     * @return list<Violation>
     */
    public function scanThemeBoundary(string $relative, string $source): array
    {
        $application = false;

        foreach (self::APPLICATION_PATHS as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                $application = true;

                break;
            }
        }

        if (! $application) {
            return [];
        }

        $violations = [];
        $tokens = token_get_all($source);
        $names = $this->themeTokenNames();

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }

            [$id, $text, $line] = $token;

            // 11d: the one file that may build a CSS declaration is the one file that
            // calls the method which builds a custom-property name.
            if ($id === T_STRING
                && $text === 'property'
                && $this->precededByThemeTokens($tokens, $index)
                && $relative !== self::THEME_STYLESHEET_FILE) {
                $violations[] = new Violation(
                    self::THEME_EMITTER,
                    $relative,
                    $line,
                    'builds a CSS custom-property name; '.self::THEME_STYLESHEET_FILE
                    .' is the one file that may emit a CSS declaration (QRPAGER-14 D6)',
                );

                continue;
            }

            // INLINE HTML counts for 11a. A PHP file with markup after `?` + `>` is not
            // a string literal, and a `<style>` block there would be invisible to a
            // literal scan -- which is the hole this clause would otherwise have.
            if ($id === T_INLINE_HTML) {
                if (stripos($text, '<style') !== false) {
                    $violations[] = new Violation(
                        self::STYLE_LITERAL,
                        $relative,
                        $line,
                        'carries a style element in inline HTML; a theme overrides tokens only, never components',
                    );
                }

                continue;
            }

            if ($id !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $value = substr($text, 1, -1);

            if (stripos($value, '<style') !== false) {
                $violations[] = new Violation(
                    self::STYLE_LITERAL,
                    $relative,
                    $line,
                    'carries a style element; a theme overrides tokens only, never components',
                );

                continue;
            }

            foreach (self::CSS_AT_RULES as $atRule) {
                if (stripos($value, $atRule) !== false) {
                    $violations[] = new Violation(
                        self::CSS_RULE_SET,
                        $relative,
                        $line,
                        "carries the CSS at-rule [{$atRule}]; application code does not author CSS",
                    );

                    continue 2;
                }
            }

            // A class or id selector followed by a brace. Deliberately NARROW -- an
            // element selector like `p {` would flag a sprintf format and a JSON
            // fragment, and rule 1's own lesson is that a guard which cries wolf gets
            // ignored. A component's own rule is what this is looking for, and a
            // component's rule has a class or an id.
            if (preg_match('/[.#][A-Za-z_][\w-]*\s*\{/', $value) === 1) {
                $violations[] = new Violation(
                    self::CSS_RULE_SET,
                    $relative,
                    $line,
                    'carries a CSS rule-set; a theme changes a token value and never a component rule',
                );

                continue;
            }

            // 11c: the token list has one home.
            if ($relative !== self::THEME_TOKENS_FILE && in_array($value, $names, true)) {
                $violations[] = new Violation(
                    self::THEME_TOKEN_COPIED,
                    $relative,
                    $line,
                    "names the theme token [{$value}]; the list lives only in ".self::THEME_TOKENS_FILE,
                );
            }
        }

        return $violations;
    }

    /**
     * The theme token names, READ OUT OF ThemeTokens rather than copied here.
     *
     * The keys of its `NAMES` array, collected as the string literals that are followed
     * by a `=>` inside that one const declaration. A guard holding its own copy of the
     * list it exists to keep singular would be the defect rule 11c is about.
     *
     * An absent or unreadable file yields an EMPTY list, which makes 11c inert rather
     * than making the whole guard fail -- and that is deliberate: rule 6 already refuses
     * a configured path that reads nothing, and RepoGuardTest pins this list as non-empty
     * so an inert 11c fails the suite rather than passing quietly.
     *
     * @return list<string>
     */
    public function themeTokenNames(): array
    {
        $path = $this->root.'/'.self::THEME_TOKENS_FILE;

        if (! is_file($path)) {
            return [];
        }

        $tokens = token_get_all((string) file_get_contents($path));
        $names = [];
        $inNames = false;
        $depth = 0;

        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_STRING && $token[1] === 'NAMES' && ! $inNames) {
                $inNames = true;

                continue;
            }

            if (! $inNames) {
                continue;
            }

            if ($token === '[') {
                $depth++;

                continue;
            }

            if ($token === ']') {
                $depth--;

                if ($depth <= 0) {
                    break;
                }

                continue;
            }

            if ($depth !== 1 || ! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            if ($this->followedByDoubleArrow($tokens, $index)) {
                $names[] = substr($token[1], 1, -1);
            }
        }

        return $names;
    }

    /** Is the next significant token after $index a `=>`? */
    private function followedByDoubleArrow(array $tokens, int $index): bool
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) && $token[0] === T_DOUBLE_ARROW;
        }

        return false;
    }

    /** Is `property` here the method on ThemeTokens, rather than any other `property`? */
    private function precededByThemeTokens(array $tokens, int $index): bool
    {
        $seenDoubleColon = false;

        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (! $seenDoubleColon) {
                if (! is_array($token) || $token[0] !== T_DOUBLE_COLON) {
                    return false;
                }

                $seenDoubleColon = true;

                continue;
            }

            return is_array($token) && $token[0] === T_STRING && $token[1] === 'ThemeTokens';
        }

        return false;
    }

    /** Is the next significant token after $index a `::`? */
    private function followedByDoubleColon(array $tokens, int $index): bool
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) && $token[0] === T_DOUBLE_COLON;
        }

        return false;
    }

    /**
     * The repository root: two levels above `apps/api`, which is this guard's own root.
     *
     * Resolved rather than assumed, so a worktree or a copied tree still reaches its `docs/`.
     */
    public function repositoryRoot(): string
    {
        return dirname($this->root, 2);
    }

    /**
     * Every `.md` under the repository root, keyed by path relative to it.
     *
     * @return array<string, string>
     */
    private function markdownFiles(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $pruned = ['.git', 'vendor', 'node_modules', 'storage', 'build', 'guard/fixtures'];
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $file) use ($root, $pruned): bool {
                    if (! $file->isDir()) {
                        return true;
                    }

                    $relative = str_replace($root.'/', '', $file->getPathname());

                    foreach ($pruned as $skip) {
                        if ($relative === $skip || str_ends_with($relative, '/'.$skip)) {
                            return false;
                        }
                    }

                    return true;
                },
            ),
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'md') {
                $files[str_replace($root.'/', '', $file->getPathname())] = $file->getPathname();
            }
        }

        ksort($files);

        return $files;
    }

    public function scannedFiles(array $relativePaths): array
    {
        $files = [];

        foreach ($relativePaths as $relative) {
            $absolute = $this->root.'/'.$relative;

            if (is_file($absolute)) {
                $files[$relative] = $absolute;

                continue;
            }

            if (! is_dir($absolute)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $path = str_replace('\\', '/', $file->getPathname());
                $files[ltrim(str_replace(str_replace('\\', '/', $this->root), '', $path), '/')] = $path;
            }
        }

        ksort($files);

        return $files;
    }
}
