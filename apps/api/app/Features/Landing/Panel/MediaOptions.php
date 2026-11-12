<?php

declare(strict_types=1);

namespace App\Features\Landing\Panel;

use App\Features\Landing\MediaAssetRow;
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

    /** @var list<MediaAssetRow>|null */
    private ?array $loaded = null;

    public function __construct(
        private readonly string $tenantId,
        private readonly MediaLibrary $library = new MediaLibrary,
    ) {}

    /**
     * The same assets, as rows — `QRPAGER-47` Part B item 2.
     *
     * `LogoField` needs the file's own NAME and its real SIZE, which a `<select>`'s label
     * cannot carry: Muhammad's ruling of 2026-11-12 is that *"someone who uploaded the wrong
     * file can see that they did."* Keyed by LOWER-CASED id, because `LandingDocument::media()`
     * lower-cases every reference it stores and a lookup that did not would miss its own
     * asset.
     *
     * One query, shared with `options()` through the same memoised load — the N+1 this class
     * exists to prevent does not come back for a second accessor.
     *
     * @return array<string, MediaAssetRow>
     */
    public function rows(): array
    {
        $rows = [];

        foreach ($this->assets() as $asset) {
            $rows[strtolower($asset->id)] = $asset;
        }

        return $rows;
    }

    /** @return array<string, string> */
    public function options(): array
    {
        if ($this->options !== null) {
            return $this->options;
        }

        $options = [];

        foreach ($this->assets() as $asset) {
            // label() disambiguates two uploads of the same filename by their
            // dimensions, which is the common case for a logo somebody re-exported.
            $options[$asset->id] = $asset->label();
        }

        return $this->options = $options;
    }

    /** @return list<MediaAssetRow> */
    private function assets(): array
    {
        return $this->loaded ??= $this->library->all($this->tenantId);
    }
}
