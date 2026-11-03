<?php

declare(strict_types=1);

namespace App\Features\Contracts\Console;

use App\Domain\Contracts\SiteKeyMirror;
use Illuminate\Console\Command;

/**
 * Writes the generated TypeScript published-site key mirror to packages/contracts (ADR-005).
 *
 * A sibling of `ExportStatusMirrorCommand` in every respect, deliberately: the document is
 * built in `App\Domain\Contracts\SiteKeyMirror`, which has no framework and no filesystem in
 * it, and this command is the I/O and nothing else.
 *
 * It is a SECOND command rather than a second artifact of the first, because the two mirrors
 * have different sources and different reasons to go stale -- `contracts:export` reads the
 * enum registry, this reads `PublishedSite`. One command writing both would fail in a way
 * that names neither.
 *
 * `--check` is the non-writing form `bin/contracts-check` and CI use; `--path` exists for the
 * one caller that proves `--check` bites, for the reason `ExportStatusMirrorCommand`'s own
 * note gives: a test that overwrote the real mirror and restored it afterwards would leave a
 * corrupted committed artifact behind on any hard stop (`R-018`).
 */
class ExportSiteKeyMirrorCommand extends Command
{
    protected $signature = 'contracts:export-site-keys
        {--check : compare instead of writing, and exit 1 on a difference}
        {--path= : the artifact to write or compare, for the test of this command only}';

    protected $description = 'Generate the TypeScript published-site key mirror from PublishedSite';

    public function handle(): int
    {
        $path = is_string($this->option('path')) && $this->option('path') !== ''
            ? (string) $this->option('path')
            : dirname(base_path(), 2).'/'.SiteKeyMirror::RELATIVE_PATH;
        $generated = SiteKeyMirror::encode();

        if ($this->option('check')) {
            $committed = is_file($path) ? (string) file_get_contents($path) : null;

            if ($committed === $generated) {
                $this->line('site key mirror: '.$path.' is current.');

                return self::SUCCESS;
            }

            $this->components->error(
                $committed === null
                    ? $path.' does not exist.'
                    : $path.' is stale: it disagrees with PublishedSite.'
            );
            $this->line('Run `php artisan contracts:export-site-keys` and commit the result.');

            return self::FAILURE;
        }

        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            $this->components->error('could not create '.$directory);

            return self::FAILURE;
        }

        file_put_contents($path, $generated);
        $this->info('site key mirror: wrote '.$path.' ('.strlen($generated).' bytes)');

        return self::SUCCESS;
    }
}
