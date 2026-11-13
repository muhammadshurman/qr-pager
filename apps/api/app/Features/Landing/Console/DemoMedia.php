<?php

declare(strict_types=1);

namespace App\Features\Landing\Console;

/**
 * Where the demo restaurant's imagery lives, and what each file is FOR. `QRPAGER-48`.
 *
 * ================================================================================
 * ⚠⚠ THE SEEDER READS A DIRECTORY. IT KNOWS NOTHING ABOUT HOW THE FILES WERE MADE
 * ================================================================================
 *
 * Muhammad's ruling of 2026-11-13: *"replacing them with real photographs later is a file
 * drop, not a code change."* That seam is this class. `studio:demo-tenant` asks here for the
 * files and their ROLES; it never calls the generator, and nothing in it would change if
 * every file were replaced with a photograph of actual food tomorrow.
 *
 * **The role is the filename**, which is what makes the drop work without a manifest to keep
 * in step: `logo.*`, `hero.*`, `dish-N.*`, `gallery-N.*`. Extension is free, so a `.png` may
 * replace a `.jpg`.
 */
final class DemoMedia
{
    /**
     * @return string the committed directory the demo restaurant's imagery is read from
     *
     * ⚠ OVERRIDABLE, AND THE DEFAULT IS THE COMMITTED PATH. The override exists so the
     * MISSING-DIRECTORY branch can be driven by a test (`§11` QA L-3): that branch answers
     * all-nulls and used to let the seeder write a picture-less demo restaurant, and a
     * branch nothing can reach is a branch nothing can prove refuses. It is read through
     * `config()` rather than `env()` directly so a cached config still answers, and it is
     * not documented in `.env.example` because it is not an operating knob — the file drop
     * the class exists for happens IN the committed directory, not beside it.
     */
    public static function directory(): string
    {
        $configured = config('surfaces.demo_media_directory');

        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        return dirname(__DIR__, 4).'/database/demo-media';
    }

    /**
     * Every readable image in the directory, newest convention first, keyed by role.
     *
     * ⚠ SORTED, because `dish-2` must stay the second dish across runs: the document below
     * pairs them with named dishes by position, and a directory listing's natural order is
     * the filesystem's, not a promise.
     *
     * @return array{logo: ?string, hero: ?string, dishes: list<string>, gallery: list<string>}
     */
    public static function files(): array
    {
        $dir = self::directory();
        $found = is_dir($dir) ? (glob($dir.'/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: []) : [];

        sort($found, SORT_NATURAL);

        $set = ['logo' => null, 'hero' => null, 'dishes' => [], 'gallery' => []];

        foreach ($found as $path) {
            $name = basename($path);

            if (str_starts_with($name, 'logo.')) {
                $set['logo'] = $path;
            } elseif (str_starts_with($name, 'hero.')) {
                $set['hero'] = $path;
            } elseif (str_starts_with($name, 'dish-')) {
                $set['dishes'][] = $path;
            } elseif (str_starts_with($name, 'gallery-')) {
                $set['gallery'][] = $path;
            }
        }

        return $set;
    }
}
