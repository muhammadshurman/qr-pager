<?php

declare(strict_types=1);

namespace App\Features\Landing\Panel;

use App\Features\Landing\MediaLibrary;

/**
 * The tenant's media library as a `<select>`'s options: id => a name a human recognises.
 *
 * Loaded ONCE per form build and passed down, rather than each media field running its
 * own query. A page with a hero image, twelve menu items and a gallery is fifteen media
 * fields; fifteen identical queries is the N+1 shape `§11` asks about, and it would be
 * invisible because each one is cheap.
 */
final class MediaOptions
{
    /** @var array<string, string>|null */
    private ?array $options = null;

    public function __construct(
        private readonly string $tenantId,
        private readonly MediaLibrary $library = new MediaLibrary,
    ) {}

    /** @return array<string, string> */
    public function options(): array
    {
        if ($this->options !== null) {
            return $this->options;
        }

        $options = [];

        foreach ($this->library->all($this->tenantId) as $asset) {
            // label() disambiguates two uploads of the same filename by their
            // dimensions, which is the common case for a logo somebody re-exported.
            $options[$asset->id] = $asset->label();
        }

        return $this->options = $options;
    }
}
