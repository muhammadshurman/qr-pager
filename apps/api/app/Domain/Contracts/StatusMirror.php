<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

use App\Domain\DatabaseEnum;
use App\Domain\DatabaseEnums;
use ReflectionMethod;

/**
 * ================================================================================
 * THE GENERATED TYPESCRIPT MIRROR OF THE PHP STATUS ENUMS (ADR-003, §6.2)
 * ================================================================================
 *
 * `DatabaseEnums`'s own docblock says it: *"Task 8 generates packages/contracts from this
 * same map rather than hand-copying it."* This class is that generation, and it reads the
 * registry rather than carrying a list -- so an enum added to `DatabaseEnums::map()` appears
 * in the TypeScript without anyone remembering to add it.
 *
 * WHY IT IS GENERATED AND NOT WRITTEN. `ADR-003`: *"a hand-maintained copy is a second
 * source wearing a disguise -- it agrees on the day it is written and drifts silently
 * after, and the drift shows up as a customer seeing the wrong status."* `bin/contracts-check`
 * byte-diffs the committed file against what this class produces, which is the same
 * mechanism as `openapi:check` and `landing:check` one contract over.
 *
 * THE DIRECTION IS THE DIRECTION OF THE SOURCE. The status machine lives in PHP, so PHP
 * emits the TypeScript. A Node script parsing PHP would be a second reader of the same
 * contract -- and the token package runs the other way for the same reason, because its
 * source is TypeScript.
 *
 * No framework and no filesystem in here: this class returns a string and the command does
 * the I/O. That split is what lets a test assert the BYTES without running a command, and
 * lets the check run the command without knowing what is in them -- the same split
 * `LandingSchema` and `ExportLandingSchemaCommand` already use.
 */
final class StatusMirror
{
    /** Where the generated mirror lives, relative to the repository root. */
    public const RELATIVE_PATH = 'packages/contracts/src/generated/status.ts';

    /**
     * The TypeScript source, in full.
     *
     * Deterministic in every respect -- registry order, case order, and no timestamp --
     * because the gate compares BYTES. A generated file carrying the time it was generated
     * can never be byte-compared, which makes the staleness check impossible to write.
     */
    public static function encode(): string
    {
        $out = self::banner();

        foreach (DatabaseEnums::map() as $type => $class) {
            $out .= self::block($type, $class);
        }

        $out .= self::registry();

        return $out;
    }

    private static function banner(): string
    {
        $types = implode(', ', array_keys(DatabaseEnums::map()));

        return <<<TS
            /*
             * GENERATED FILE -- DO NOT EDIT.
             *
             * The TypeScript mirror of the PHP status enums, emitted from
             * App\\Domain\\DatabaseEnums::map() by `php artisan contracts:export`.
             * `composer contracts:check` byte-diffs this file against the PHP source and
             * fails the gate when they disagree.
             *
             * The PHP enum is the ONE source (§6.2, ADR-003). A hand-written copy here would
             * be a second source wearing a disguise: it agrees on the day it is written and
             * drifts silently after, and the drift reaches a customer as the wrong status on
             * the screen they are watching.
             *
             * Mirrored types: {$types}
             *
             * The transition tables below are the PHP tables, not a re-derivation. Where an
             * enum declares no transitions it has none -- that is a fact about the status
             * machine, not a gap in this file.
             */


            TS;
    }

    /**
     * One enum: its values, its type, and its transition table if it has one.
     *
     * @param  class-string<DatabaseEnum&\BackedEnum>  $class
     */
    private static function block(string $type, string $class): string
    {
        $constant = self::screamingCase($type);
        $tsType = self::pascalCase($type);
        $values = $class::values();

        $out = "/** Postgres enum type `{$type}`, mirrored from ".self::shortName($class).". */\n";
        $out .= "export const {$constant} = [\n";
        foreach ($values as $value) {
            $out .= "  '{$value}',\n";
        }
        $out .= "] as const\n\n";
        $out .= "export type {$tsType} = (typeof {$constant})[number]\n\n";

        $transitions = self::transitions($class);
        if ($transitions !== null) {
            $out .= "/**\n";
            $out .= " * Every transition the domain permits, and nothing else.\n";
            $out .= " *\n";
            $out .= " * An empty list is a TERMINAL status. Read from the PHP table rather than\n";
            $out .= " * re-derived, so the two cannot disagree.\n";
            $out .= " */\n";
            $out .= "export const {$constant}_TRANSITIONS: Readonly<Record<{$tsType}, readonly {$tsType}[]>> = {\n";
            foreach ($transitions as $from => $to) {
                $list = $to === []
                    ? '[]'
                    : '['.implode(', ', array_map(static fn (string $v): string => "'{$v}'", $to)).']';
                $out .= "  '{$from}': {$list},\n";
            }
            $out .= "}\n\n";
            $out .= "/** Whether `from` may move to `to`. The TypeScript side of the same table. */\n";
            $out .= "export function {$tsType}CanMoveTo(from: {$tsType}, to: {$tsType}): boolean {\n";
            $out .= "  return {$constant}_TRANSITIONS[from].includes(to)\n";
            $out .= "}\n\n";
        }

        return $out;
    }

    /**
     * The transition table for an enum, in either of the two shapes this codebase uses.
     *
     * `PagerStatus::allows()` is an INSTANCE method returning the next statuses for one
     * case; `LandingPageStatus`, `DomainStatus` and `ProvisioningStatus` declare a STATIC
     * `allows()` returning the whole map. Both are read here rather than normalised in the
     * enums, because changing eight tagged enums to suit a generator would be the generator
     * dictating the domain.
     *
     * Returns null when the enum declares no transitions at all.
     *
     * @param  class-string<DatabaseEnum&\BackedEnum>  $class
     * @return array<string, list<string>>|null
     */
    private static function transitions(string $class): ?array
    {
        if (! method_exists($class, 'allows')) {
            return null;
        }

        $table = [];

        if ((new ReflectionMethod($class, 'allows'))->isStatic()) {
            $raw = $class::allows();
            foreach ($raw as $from => $to) {
                $table[(string) $from] = self::caseValues($to);
            }

            return $table;
        }

        // The instance form. `cases()` rather than ReflectionEnum: it is the enum's own API,
        // PHPStan understands it from the `&\UnitEnum` in the registry's type, and it needs
        // no reflection object at all. ReflectionEnum was the first attempt and PHPStan was
        // right to refuse it -- `class-string<DatabaseEnum>` promised nothing about enums,
        // which is the gap the registry's type now closes.
        foreach ($class::cases() as $case) {
            $to = $case->allows();
            $table[(string) $case->value] = self::caseValues($to);
        }

        return $table;
    }

    /**
     * @param  array<array-key, \BackedEnum>  $cases
     * @return list<string>
     */
    private static function caseValues(array $cases): array
    {
        return array_values(array_map(
            static fn (\BackedEnum $case): string => (string) $case->value,
            $cases,
        ));
    }

    /** The registry itself, so a consumer can enumerate every mirrored type. */
    private static function registry(): string
    {
        $out = "/**\n";
        $out .= " * Every mirrored Postgres enum type, keyed by its type name.\n";
        $out .= " *\n";
        $out .= " * Emitted from App\\Domain\\DatabaseEnums::map(), so a type added there appears here\n";
        $out .= " * without anyone remembering to add it -- and the staleness check fails until the\n";
        $out .= " * generated file is committed.\n";
        $out .= " */\n";
        $out .= "export const DATABASE_ENUMS = {\n";
        foreach (DatabaseEnums::map() as $type => $class) {
            $out .= "  '{$type}': ".self::screamingCase($type).",\n";
        }
        $out .= "} as const\n\n";
        $out .= "export type DatabaseEnumName = keyof typeof DATABASE_ENUMS\n";

        return $out;
    }

    private static function screamingCase(string $type): string
    {
        return strtoupper($type);
    }

    private static function pascalCase(string $type): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $type)));
    }

    /** @param class-string $class */
    private static function shortName(string $class): string
    {
        $parts = explode('\\', $class);

        return (string) end($parts);
    }
}
