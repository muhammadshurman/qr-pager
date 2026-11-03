<?php

declare(strict_types=1);

namespace App\Features\Landing;

use App\Domain\Landing\LandingDocument;
use App\Domain\Tenancy\TenantLanguage;
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
     * @param  TenantLanguage|null  $language  the restaurant's own language; `null` means the
     *                                         column's default, for a caller with no row yet
     */
    public function render(
        LandingDocument $document,
        string $hostname,
        string $tenantName,
        array $assetUrls,
        ?TenantLanguage $language = null,
    ): string {
        /*
         * ⚠ AN ARGUMENT, NOT A LOOKUP, WHICH IS THIS CLASS'S WHOLE PROPERTY. The docblock
         * above promises NO DATABASE, NO REQUEST, NO TENANT CONTEXT, and the publish path's
         * checksum depends on rendering the same document twice producing the same bytes.
         * Reading `tenants.language` here would break both: a second read inside phase 2 of
         * `LandingPages::publish()`, which is the phase that is explicitly database-free.
         * The publisher already reads `tenants.name` in phase 1 and the language rides along
         * on that same row -- one read, no new path, and `§2.8`'s named list untouched.
         */
        $language ??= TenantLanguage::DEFAULT;
        $seo = $document->seo;

        return View::make('landing.page', [
            'blocks' => $document->toArray()['blocks'],
            // Whether the first block is a hero, which decides only whether the page's
            // own h1 is visible or visually hidden -- never whether it EXISTS. See the
            // template: exactly one h1 for every possible document (§11 QA review).
            'heroFirst' => ($document->toArray()['blocks'][0]['type'] ?? null) === 'hero',
            'themeStyle' => ThemeStylesheet::declarations($document->theme),
            'tenantName' => $tenantName,
            'title' => $seo['title'] ?? $tenantName,
            'description' => $seo['description'],
            'canonical' => 'https://'.$hostname.'/',
            'ogImage' => $seo['og_image'] === null ? null : ($assetUrls[$seo['og_image']] ?? null),

            // R-131, closed. An `Htmlable` rather than `{!! !!}`: see `StructuredData`'s
            // docblock, and the template's own note on why `{{ }}` is the wrong encoder for
            // JSON and why D5's rule is not carved out to get this feature.
            'structuredData' => StructuredData::for($document, $hostname, $tenantName),

            // R-132, CLOSED by QRPAGER-30. These were `'en'` and `'ltr'`, hard-coded, and
            // that row's own text recorded why: *"there is no per-tenant language anywhere
            // in the data contract ... so this task cannot read one"*, with the note that
            // `dir` would be *"a single attribute to change when Task 11 adds the
            // setting."* It is. `tenants.language` exists, the column's default is `ar`,
            // and the two attributes come from the restaurant.
            //
            // ⚠ `dir` IS DERIVED AND IS NEVER READ FROM ANYWHERE. `TenantLanguage::
            // direction()` is the only mapping on the PHP side, and the ruling's reason for
            // that is not tidiness: a second stored column that disagreed with this one
            // would not fail loudly, it would lay a restaurant's page out backwards. The
            // template's CSS was already written RTL-safe -- no physical left/right
            // property anywhere -- so this is the attribute change that row predicted and
            // nothing else.
            //
            // Guessing the direction from the CONTENT stays rejected, as R-132 recorded: a
            // heuristic that gets a restaurant's own page backwards is worse than a default
            // that is visibly a default.
            'lang' => $language->value,
            'dir' => $language->direction(),

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
