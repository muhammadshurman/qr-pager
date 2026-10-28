<?php

declare(strict_types=1);

namespace App\Features\Landing;

use App\Domain\Landing\LandingDocument;
use Illuminate\Support\Facades\View;

/**
 * A validated document plus a hostname -> the bytes of a static page.
 *
 * NO DATABASE, NO REQUEST, NO TENANT CONTEXT. Everything it needs arrives as arguments,
 * which is what makes the same renderer usable from the publisher, from the preview route
 * and from a test without any of them having to agree about state. It is also why
 * rendering the same document twice produces the same bytes -- the checksum the publish
 * path is built on depends on that.
 *
 * Every tenant-authored value reaches the template through `{{ }}`. See the template's
 * own docblock for why there is no `{!! !!}` in it and why the JSON-LD block is R-131.
 */
final class LandingRenderer
{
    /**
     * @param  array<string, string>  $assetUrls  media id -> the published path of its copy
     */
    public function render(
        LandingDocument $document,
        string $hostname,
        string $tenantName,
        array $assetUrls,
    ): string {
        $seo = $document->seo;

        return View::make('landing.page', [
            'blocks' => $document->toArray()['blocks'],
            'themeStyle' => ThemeStylesheet::declarations($document->theme),
            'tenantName' => $tenantName,
            'title' => $seo['title'] ?? $tenantName,
            'description' => $seo['description'],
            'canonical' => 'https://'.$hostname.'/',
            'ogImage' => $seo['og_image'] === null ? null : ($assetUrls[$seo['og_image']] ?? null),

            // ⚠ 'en' AND 'ltr' ARE DEFAULTS, NOT A DECISION, and that is R-132. There is
            // no per-tenant language anywhere in the data contract -- `tenants` carries a
            // timezone and nothing else -- so this task cannot read one. §12 names Arabic
            // as a launch language, so the template's CSS is written RTL-safe (no
            // physical left/right properties anywhere) and `dir` is a single attribute to
            // change when Task 11 adds the setting. Guessing the direction from the
            // content was considered and rejected: a heuristic that gets a restaurant's
            // own page backwards is worse than a default that is visibly a default.
            'lang' => 'en',
            'dir' => 'ltr',

            // A CLOSURE, so an unresolvable id cannot render as an empty src. Every id in
            // a validated document resolves -- LandingDocument refused anything else --
            // and this is the belt to that brace.
            'asset' => static fn (string $mediaId): string => $assetUrls[$mediaId] ?? '',

            // Three numbers in range become an href. There is no field an embed could go
            // in, which is §6.4's rule in its strongest form.
            'map' => static function (array $fields): ?string {
                $latitude = $fields['latitude'] ?? null;
                $longitude = $fields['longitude'] ?? null;

                if (! is_numeric($latitude) || ! is_numeric($longitude)) {
                    return null;
                }

                $zoom = is_numeric($fields['zoom'] ?? null) ? (int) $fields['zoom'] : 15;

                return sprintf(
                    'https://www.openstreetmap.org/?mlat=%s&mlon=%s#map=%d/%s/%s',
                    $latitude, $longitude, $zoom, $latitude, $longitude,
                );
            },
        ])->render();
    }
}
