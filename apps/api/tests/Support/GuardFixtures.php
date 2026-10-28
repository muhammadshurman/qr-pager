<?php

declare(strict_types=1);

namespace Tests\Support;

use Guard\SourceGuard;
use Guard\Violation;

/**
 * Helpers for the two guard tests.
 *
 * A class rather than file-scope functions, for the reason Composition is a class: the
 * first version of RepoGuardTest declared `fixture()` at file scope and took the whole
 * suite down with "Cannot redeclare function fixture()" -- Pest ships one. The failure
 * names the redeclaration rather than the collision, so it costs time to read.
 *
 * Holds no forbidden literal, deliberately: the guard scans tests/ as well, and every
 * bad shape belongs in guard/fixtures/repo.
 */
final class GuardFixtures
{
    public static function guard(): SourceGuard
    {
        return new SourceGuard(base_path());
    }

    public static function path(string $name): string
    {
        return "guard/fixtures/repo/{$name}.php.fixture";
    }

    public static function text(string $name): string
    {
        return (string) file_get_contents(base_path(self::path($name)));
    }

    /** One rule 9 fixture: a workflow trigger, read as text the way the guard reads it. */
    public static function workflow(string $name): string
    {
        return (string) file_get_contents(base_path("guard/fixtures/ci/{$name}.yml.fixture"));
    }

    /** One rule 10 fixture: application source, scanned under an `app/` path. */
    public static function application(string $name): string
    {
        return (string) file_get_contents(base_path("guard/fixtures/visibility/{$name}.php.fixture"));
    }

    /** @return list<Violation> */
    public static function violations(string $name, string $rule): array
    {
        return array_values(array_filter(
            self::guard()->scan([self::path($name)]),
            static fn (Violation $violation): bool => $violation->rule === $rule,
        ));
    }

    /** The text a violation quoted, unwrapped from its message. */
    public static function quoted(Violation $violation): string
    {
        return (string) preg_replace('/^(?:raw )?\[(.+?)\].*$/s', '$1', $violation->detail);
    }
}
