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
    public function scanSource(string $relative, string $source): array
    {
        return $this->scanFile($relative, $source);
    }

    /**
     * @return list<Violation>
     */
    private function scanFile(string $relative, string $source): array
    {
        $violations = [];
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
