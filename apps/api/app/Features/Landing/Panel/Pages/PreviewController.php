<?php

declare(strict_types=1);

namespace App\Features\Landing\Panel\Pages;

use App\Domain\Landing\LandingDocumentInvalid;
use App\Domain\Tenancy\TenantLanguage;
use App\Features\Landing\LandingPages;
use App\Features\Landing\LandingRenderer;
use App\Features\Landing\MediaLibrary;
use App\Platform\Http\TenantPanelAccess;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

        /*
         * ⚠ THE NAME AND THE LANGUAGE FROM ONE ROW, BECAUSE THE PREVIEW MUST AGREE WITH THE
         * PUBLISH. QRPAGER-30. This class's docblock says the preview is *"the tenant's page,
         * not a panel screen"* and that wrapping it in panel chrome *"would make the preview
         * show something other than what gets published"* -- a preview rendered `lang="en"
         * dir="ltr"` for a restaurant whose page publishes as Arabic RTL is the same defect
         * with a different cause. One row inside the tenant's own scope, as before.
         */
        $tenant = withTenant($tenantId, static fn () => DB::table('tenants')->first(['name', 'language']));

        $name = (string) ($tenant->name ?? '');
        $language = TenantLanguage::from((string) ($tenant->language ?? TenantLanguage::DEFAULT->value));

        // The asset URLs a PREVIEW uses are the private library's, read through the
        // panel's own authenticated surface -- not the published `/assets/…` paths,
        // which do not exist until a publish copies them there (D3). A preview that
        // pointed at the published paths would show broken images before the first
        // publish and stale ones after an edit.
        $assetUrls = [];

        foreach ($document->mediaReferences() as $mediaId) {
            $assetUrls[$mediaId] = route('filament.studio.preview.asset', ['asset' => $mediaId]);
        }

        return response($renderer->render($document, $hostname, $name, $assetUrls, $language))
            ->withHeaders(self::headers());
    }

    /** One asset of this tenant's library, for a preview only. */
    public function asset(string $asset, MediaLibrary $library): Response
    {
        $tenantId = TenantPanelAccess::tenantId();

        // SHAPE-CHECKED BEFORE IT REACHES A uuid COLUMN. `where('id', 'not-a-uuid')`
        // raises Postgres 22P02, so a malformed id answered differently from an unknown
        // one -- R-110's shape, which this project closed once already for a bearer
        // token. Both §11 reviewers found it here. 404 is the same answer an unknown id
        // gets, which is §11.2's IDOR rule.
        if (! Str::isUuid($asset)) {
            abort(404);
        }

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
            // TIGHTER THAN THE DOCUMENT'S, deliberately: an image needs no images.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]));
    }

    /**
     * The headers every preview response carries.
     *
     * ⚠ THE CSP AND `nosniff` WERE ON THE ASSET RESPONSE AND NOT ON THE DOCUMENT, which
     * was the asymmetry the §11 security review found: the document is the tenant's own
     * draft rendered to HTML and served from the APPLICATION origin -- the one that
     * carries the `/studio` and `/platform` session cookies -- while the image embedded
     * in it got the stricter treatment. The only thing standing between an escaping
     * defect and session-scoped script execution was Blade's `{{ }}`, which contradicts
     * decision D5's "two independent halves" everywhere else in this task.
     *
     * `default-src 'none'` with `img-src 'self'` and `style-src 'unsafe-inline'` is what
     * the rendered page actually needs: its own images, its own inline stylesheet, and
     * nothing else -- no script of any origin, no frame, no fetch. `frame-ancestors
     * 'none'` keeps the draft out of an iframe, which `X-Frame-Options` says again for
     * anything that does not read CSP.
     *
     * @return array<string, string>
     */
    private static function headers(): array
    {
        return [
            'Cache-Control' => (string) config('landing.preview_cache_control'),
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; "
                ."style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
        ];
    }
}
