<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * A filesystem that DELEGATES every call to a real one and tells an observer first.
 *
 * NOT A FAKE, and the distinction is the whole reason this class is acceptable under §6's
 * "no DB mocks for DB behaviour" and ADR-004's "a test that proves a stand-in works must
 * round-trip through the production client library". Every method hands straight to the
 * real `s3` disk, so a test running with this installed exercises exactly the same
 * service it would otherwise; the only thing added is a note of WHEN each call happened.
 *
 * It exists for one question a test could not otherwise ask: is a database transaction
 * open at the moment this application writes to the object store? That is the §11 QA
 * review's second High, and the honest instrument for it is to look at the real call.
 *
 * @mixin Filesystem
 */
final class RecordingDisk implements Filesystem
{
    /**
     * @param  Closure(string): mixed  $observe
     * @param  array<string, mixed>  $answers  operations to answer with a fixed value
     *                                         INSTEAD of delegating -- for driving the
     *                                         `'throw' => false` failure paths, where
     *                                         the real disk returns false rather than
     *                                         raising and there is no other way to make
     *                                         it do so on demand.
     */
    public function __construct(
        private readonly Filesystem $inner,
        private readonly Closure $observe,
        private readonly array $answers = [],
    ) {}

    public function put($path, $contents, $options = []): mixed
    {
        ($this->observe)('put');

        if (array_key_exists('put', $this->answers)) {
            return $this->answers['put'];
        }

        return $this->inner->put($path, $contents, $options);
    }

    public function copy($from, $to): bool
    {
        ($this->observe)('copy');

        return $this->inner->copy($from, $to);
    }

    /** @param  string|array<int, string>  $paths */
    public function delete($paths): bool
    {
        ($this->observe)('delete');

        return $this->inner->delete($paths);
    }

    public function deleteDirectory($directory): bool
    {
        ($this->observe)('deleteDirectory');

        if (array_key_exists('deleteDirectory', $this->answers)) {
            return (bool) $this->answers['deleteDirectory'];
        }

        return $this->inner->deleteDirectory($directory);
    }

    /**
     * Everything else on the contract, delegated unchanged.
     *
     * Written out rather than left to a `__call`, because `MediaLibrary::disk()` is
     * typed as the Filesystem CONTRACT and a decorator that does not implement it is a
     * TypeError at the first call -- which is how the first version of this class
     * failed. The bodies below were generated from the interface by reflection, so they
     * cannot drift from it by a typo.
     */
    public function path($path)
    {
        return $this->inner->path($path);
    }

    public function exists($path)
    {
        return $this->inner->exists($path);
    }

    public function get($path)
    {
        return $this->inner->get($path);
    }

    public function readStream($path)
    {
        return $this->inner->readStream($path);
    }

    /**
     * @param  mixed  $file
     * @param  array<string, mixed>|string  $options
     */
    public function putFile($path, $file = null, $options = [])
    {
        return $this->inner->putFile($path, $file, $options);
    }

    /**
     * @param  mixed  $file
     * @param  mixed  $name
     * @param  array<string, mixed>|string  $options
     */
    public function putFileAs($path, $file, $name = null, $options = [])
    {
        return $this->inner->putFileAs($path, $file, $name, $options);
    }

    /** @param  array<string, mixed>  $options */
    public function writeStream($path, $resource, array $options = [])
    {
        return $this->inner->writeStream($path, $resource, $options);
    }

    public function getVisibility($path)
    {
        return $this->inner->getVisibility($path);
    }

    public function setVisibility($path, $visibility)
    {
        return $this->inner->setVisibility($path, $visibility);
    }

    public function prepend($path, $data)
    {
        return $this->inner->prepend($path, $data);
    }

    public function append($path, $data)
    {
        return $this->inner->append($path, $data);
    }

    public function move($from, $to)
    {
        return $this->inner->move($from, $to);
    }

    public function size($path)
    {
        return $this->inner->size($path);
    }

    public function lastModified($path)
    {
        return $this->inner->lastModified($path);
    }

    public function files($directory = null, $recursive = false)
    {
        return $this->inner->files($directory, $recursive);
    }

    public function allFiles($directory = null)
    {
        return $this->inner->allFiles($directory);
    }

    public function directories($directory = null, $recursive = false)
    {
        return $this->inner->directories($directory, $recursive);
    }

    public function allDirectories($directory = null)
    {
        return $this->inner->allDirectories($directory);
    }

    public function makeDirectory($path)
    {
        return $this->inner->makeDirectory($path);
    }

    /** Anything beyond the contract -- the s3 disk's own getClient(), for instance. */
    /** @param array<int, mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->inner->{$method}(...$arguments);
    }
}
