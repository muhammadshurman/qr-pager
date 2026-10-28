<?php

declare(strict_types=1);

namespace App\Features\Landing\Panel\Pages;

use App\Domain\Landing\LandingDocumentInvalid;
use App\Features\Landing\LandingPages;
use App\Features\Landing\LandingRenderer;
use App\Features\Landing\MediaLibrary;
use App\Platform\Http\TenantPanelAccess;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * `/studio/preview` — the draft, rendered, for the tenant who owns it and nobody else.
 *
 * ================================================================================
 * "AN UNPUBLISHED DRAFT IS NOT REACHABLE" — THE FIRST CLAUSE OF THE NAMED INVARIANT
 * ================================================================================
 *
 * A preview is the one place a draft becomes HTML before it is published, so it is the
 * one route that could make that clause false. Three things stop it:
 *
 *   1. It is BEHIND THE SAME GUARD as the panel. `TenantPanelAccess::tenantId()` aborts 403
 *      for anonymous callers, for `staff`, for a platform admin, and for a member of
 *      another tenant -- the same single rule every page in this panel uses.
 *   2. IT TAKES NO INPUT AT ALL. There is no tenant parameter, no page id, no token: the
 *      tenant comes from the authenticated session, so there is nothing to tamper with.
 *      A preview route that accepted an id would be an IDOR waiting to be written.
 *   3. IT WRITES NOTHING. The draft is rendered in memory and returned; no object is put
 *      in the store, so previewing does not make a draft reachable at a URL afterwards.
 *
 * And the response says so: `no-store` (decision D2, `landing.preview_cache_control`) so
 * no cache may keep it, and `X-Robots-Tag: noindex, nofollow` so nothing indexes it if
 * one somehow did.
 *
 * ⚠ IT IS A PLAIN CONTROLLER AND NOT A FILAMENT PAGE, on purpose: the response is a whole
 * HTML DOCUMENT -- the tenant's page, not a panel screen -- and wrapping it in the panel's
 * chrome would make the preview show something other than what gets published.
 */
class PreviewController
{
    public function __invoke(LandingPages $pages, LandingRenderer $renderer): Response
    {
        // No input, no parameter: the tenant is the authenticated session's, and this
        // aborts 403 when there is not exactly one owner/manager membership.
        $tenantId = TenantPanelAccess::tenantId();

        $hostname = $pages->hostname($tenantId);

        try {
            $document = $pages->draft($tenantId);
        } catch (LandingDocumentInvalid $e) {
            // A preview of a draft that no longer validates is a REFUSAL, not a partial
            // render: §12.1's invalid state names the field, and rendering "as much as
            // we can" would show the tenant a page that cannot be published.
            return response(
                view('studio.preview-refused', ['field' => $e->field, 'reason' => $e->reason])->render(),
                422,
            )->withHeaders(self::headers());
        }

        $name = (string) withTenant($tenantId, static fn () => DB::table('tenants')->value('name'));

        // The asset URLs a PREVIEW uses are the private library's, read through the
        // panel's own authenticated surface -- not the published `/assets/…` paths,
        // which do not exist until a publish copies them there (D3). A preview that
        // pointed at the published paths would show broken images before the first
        // publish and stale ones after an edit.
        $assetUrls = [];

        foreach ($document->mediaReferences() as $mediaId) {
            $assetUrls[$mediaId] = route('filament.studio.preview.asset', ['asset' => $mediaId]);
        }

        return response($renderer->render($document, $hostname, $name, $assetUrls))
            ->withHeaders(self::headers());
    }

    /** One asset of this tenant's library, for a preview only. */
    public function asset(string $asset, MediaLibrary $library): Response
    {
        $tenantId = TenantPanelAccess::tenantId();

        // Resolved from a row inside the tenant's own scope -- the id is checked against
        // media_assets, so another tenant's uuid answers 404 rather than bytes.
        $bytes = $library->bytes($tenantId, $asset);

        if ($bytes === null) {
            abort(404);
        }

        $contentType = (string) withTenant($tenantId, static fn () => DB::table('media_assets')
            ->where('id', $asset)->value('content_type'));

        return response($bytes)->withHeaders(array_merge(self::headers(), [
            'Content-Type' => $contentType,
            // An SVG served inline from this origin is a document, and this origin holds
            // the panel's session. SvgPolicy has already refused anything executable, and
            // this is the second half: the browser is told not to sniff, and a strict CSP
            // for this one response removes scripting even if both were wrong.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]));
    }

    /** @return array<string, string> */
    private static function headers(): array
    {
        return [
            'Cache-Control' => (string) config('landing.preview_cache_control'),
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ];
    }
}
