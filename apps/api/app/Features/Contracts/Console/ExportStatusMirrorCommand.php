<?php

declare(strict_types=1);

namespace App\Features\Contracts\Console;

use App\Domain\Contracts\StatusMirror;
use Illuminate\Console\Command;

/**
 * Writes the generated TypeScript status mirror to packages/contracts (§6.2, ADR-003).
 *
 * The document is built in App\Domain\Contracts\StatusMirror, which has no framework and no
 * filesystem in it; this command is the I/O and nothing else -- the same split
 * ExportLandingSchemaCommand already uses, and for the same reason.
 *
 * `--check` is the non-writing form the gate and CI use: it compares and exits 1 on a
 * difference, naming the command that fixes it.
 */
class ExportStatusMirrorCommand extends Command
{
    protected $signature = 'contracts:export
        {--check : compare instead of writing, and exit 1 on a difference}
        {--path= : the artifact to write or compare, for the test of this command only}';

    protected $description = 'Generate the TypeScript status mirror from the PHP enum registry';

    public function handle(): int
    {
        // Two levels above apps/api, the same resolution ExportLandingSchemaCommand uses --
        // a worktree or a copied tree still finds its packages/.
        //
        // --path EXISTS FOR ONE CALLER: the test that proves --check bites. R-018 is the §5
        // row saying every `finally` is correct and none survives a kill -9, so a test that
        // proved this by overwriting the REAL mirror and restoring it afterwards would leave
        // a corrupted committed artifact behind on any hard stop. The test points this at a
        // scratch copy.
        $path = is_string($this->option('path')) && $this->option('path') !== ''
            ? (string) $this->option('path')
            : dirname(base_path(), 2).'/'.StatusMirror::RELATIVE_PATH;
        $generated = StatusMirror::encode();

        if ($this->option('check')) {
            $committed = is_file($path) ? (string) file_get_contents($path) : null;

            if ($committed === $generated) {
                $this->line('status mirror: '.$path.' is current.');

                return self::SUCCESS;
            }

            $this->components->error(
                $committed === null
                    ? $path.' does not exist.'
                    : $path.' is stale: it disagrees with the PHP enum registry.'
            );
            $this->line('Run `php artisan contracts:export` and commit the result.');

            return self::FAILURE;
        }

        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            $this->components->error('could not create '.$directory);

            return self::FAILURE;
        }

        file_put_contents($path, $generated);
        $this->info('status mirror: wrote '.$path.' ('.strlen($generated).' bytes)');

        return self::SUCCESS;
    }
}
