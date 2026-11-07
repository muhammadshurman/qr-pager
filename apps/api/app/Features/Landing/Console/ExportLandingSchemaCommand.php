<?php

declare(strict_types=1);

namespace App\Features\Landing\Console;

use App\Domain\Landing\LandingSchema;
use Illuminate\Console\Command;

/**
 * Writes the generated block contract to docs/contracts/landing-page.schema.json (§6.2).
 *
 * The document itself is built in App\Domain\Landing\LandingSchema, which has no framework
 * and no filesystem in it; this command is the I/O and nothing else. That split is what
 * lets LandingSchemaContractTest assert the BYTES without running a command, and lets
 * bin/landing-schema-check run the command without knowing what is in them.
 *
 * `--check` is the non-writing form the gate and CI use: it compares and exits 1 on a
 * difference, naming the command that fixes it.
 */
class ExportLandingSchemaCommand extends Command
{
    protected $signature = 'landing:schema-export
        {--check : compare instead of writing, and exit 1 on a difference}
        {--path= : the artifact to write or compare, for the test of this command only}';

    protected $description = 'Generate docs/contracts/landing-page.schema.json from the PHP block schema';

    public function handle(): int
    {
        // Two levels above apps/api, the same resolution SourceGuard::repositoryRoot()
        // does -- a worktree or a copied tree still finds its docs/.
        //
        // --path EXISTS FOR ONE CALLER: the test that proves --check bites. R-018 is the
        // §5 row saying every `finally` is correct and none survives a kill -9, so a test
        // that proved this by overwriting the REAL contract and restoring it afterwards
        // would leave a corrupted committed artifact behind on any hard stop. QA L-2 made
        // the same call for bin/openapi-check. The test points this at a scratch copy.
        $path = is_string($this->option('path')) && $this->option('path') !== ''
            ? (string) $this->option('path')
            : dirname(base_path(), 2).'/'.LandingSchema::RELATIVE_PATH;
        $generated = LandingSchema::encode();

        if ($this->option('check')) {
            $committed = is_file($path) ? (string) file_get_contents($path) : null;

            if ($committed === $generated) {
                $this->line('landing schema: '.$path.' is current.');

                return self::SUCCESS;
            }

            $this->components->error(
                $committed === null
                    ? $path.' does not exist.'
                    : $path.' is stale: it disagrees with the PHP block schema.'
            );
            $this->line('Run `php artisan landing:schema-export` and commit the result.');

            return self::FAILURE;
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            $this->components->error("Could not create {$directory}.");

            return self::FAILURE;
        }

        file_put_contents($path, $generated);

        $this->line('landing schema: wrote '.strlen($generated).' bytes to '.$path.'.');

        return self::SUCCESS;
    }
}
