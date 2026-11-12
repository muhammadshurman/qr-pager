<?php

declare(strict_types=1);

namespace App\Features\Landing\Console;

use Illuminate\Console\Command;
use Throwable;

/**
 * Regenerate the demo restaurant's placeholder imagery. `QRPAGER-48`.
 *
 * ================================================================================
 * ⚠ THIS IS SEPARATE FROM `studio:demo-tenant` ON PURPOSE, AND THE SEAM IS THE POINT
 * ================================================================================
 *
 * Muhammad's ruling of 2026-11-13:
 *
 *   "Do not hard-code the generated images into the seeder. Have it read them from a
 *    directory, and ship the generated set as that directory's default contents. Then
 *    replacing them with real photographs later is a file drop, not a code change."
 *
 * So `studio:demo-tenant` knows only about a DIRECTORY. This command is how that directory's
 * default contents were produced, kept so the provenance is reproducible rather than
 * folklore — and **it never needs to run again**. Dropping real photographs over the files is
 * the supported path, and nothing in the seeder has to change for it.
 */
final class GenerateDemoImageryCommand extends Command
{
    protected $signature = 'studio:demo-imagery {--force : overwrite files that already exist}';

    protected $description = 'Regenerate the placeholder imagery the demo restaurant ships with.';

    /**
     * The set, and the ASPECT RATIOS are the load-bearing part.
     *
     * A grid of squares and a column of full-measure crops is exactly where `tile` and `rail`
     * diverge, so the dishes mix square with portrait and the gallery mixes landscape with
     * tall. A set of one shape would be a flat colour block's failure in a second costume.
     *
     * @var list<array{string, int, int, string, int}>
     */
    private const SET = [
        ['hero.jpg', 1600, 1000, 'grill', 101],
        ['dish-1.jpg', 800, 1000, 'bread', 211],
        ['dish-2.jpg', 900, 900, 'herbs', 222],
        ['dish-3.jpg', 800, 1000, 'grill', 233],
        ['dish-4.jpg', 900, 900, 'herbs', 244],
        ['dish-5.jpg', 900, 1350, 'sweet', 255],
        ['gallery-1.jpg', 1400, 933, 'room', 311],
        ['gallery-2.jpg', 900, 1350, 'sweet', 322],
        ['gallery-3.jpg', 1400, 933, 'coffee', 333],
    ];

    public function handle(): int
    {
        $dir = DemoMedia::directory();

        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            $this->components->error('Could not create '.$dir);

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $written = 0;
        $kept = 0;

        try {
            foreach (self::SET as [$name, $width, $height, $subject, $seed]) {
                $path = $dir.'/'.$name;

                if (is_file($path) && ! $force) {
                    $kept++;

                    continue;
                }

                file_put_contents($path, DemoImagery::photograph($width, $height, $subject, $seed));
                $written++;
            }

            $logo = $dir.'/logo.png';

            if (! is_file($logo) || $force) {
                file_put_contents($logo, DemoImagery::logo());
                $written++;
            } else {
                $kept++;
            }
        } catch (Throwable $e) {
            $this->components->error('refused: '.$e::class.': '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info($written.' written, '.$kept.' left alone, in '.$dir);

        if ($kept > 0 && ! $force) {
            $this->line('  Existing files are never overwritten without --force, so a real');
            $this->line('  photograph dropped in place survives a regeneration.');
        }

        return self::SUCCESS;
    }
}
