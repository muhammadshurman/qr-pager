<?php

declare(strict_types=1);

namespace App\Features\Landing\Panel\Pages;

use App\Domain\Landing\LandingDocument;
use App\Domain\Landing\LandingDocumentInvalid;
use App\Domain\Tenancy\TenantLanguage;
use App\Features\Landing\LandingPages;
use App\Features\Landing\LandingRenderer;
use App\Features\Landing\MediaLibrary;
use App\Features\Landing\TenantMediaReferences;
use App\Features\Landing\UnsavedPreview;
use App\Platform\Http\TenantPanelAccess;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

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
    public function __invoke(LandingPages $pages, LandingRenderer $renderer, UnsavedPreview $preview): Response
    {
        // No input, no parameter: the tenant is the authenticated session's, and this
        // aborts 403 when there is not exactly one owner/manager membership.
        $tenantId = TenantPanelAccess::tenantId();

        /*
         * ================================================================================
         * ⚠ THE UNSAVED STATE FIRST, THE STORED DRAFT OTHERWISE — `QRPAGER-45`
         * ================================================================================
         *
         * Muhammad's ruling of 2026-11-10: *"PreviewController renders from that key when
         * present, else the stored draft as today."* The preview's job is to show what the
         * owner is LOOKING AT, and until this existed it could only show what they had
         * saved.
         *
         * ⚠ THE TENANT ID IS THE SAME ONE, DERIVED THE SAME WAY, AND THAT IS THE WHOLE
         * TENANT CONTROL ON THIS PATH. `§4`: Redis carries no RLS. `tenantId()` above
         * re-resolves it from the authenticated session; no parameter, no header and no
         * query string reaches this key.
         *
         * ⚠ AND IT IS RE-VALIDATED ON READ RATHER THAN TRUSTED. `fromStored()` is
         * `fromInput()`, so the document goes through the same schema and the same
         * `TenantMediaReferences` check the writer already applied — defence in depth for a
         * store that has no policy behind it. Anything that does not survive that is treated
         * as "nothing to show" and the stored draft is rendered, which is the behaviour this
         * route had before the feature.
         */
        /*
         * ⚠ PER TENANT **AND** PER SESSION — Muhammad's specification of 2026-11-11. One key
         * per tenant would mean two studio tabs, or two people holding the owner role,
         * writing over each other: each would watch the other's in-flight edits appear in
         * their own preview, with nothing on screen to explain it. Still tenant-namespaced,
         * so `§4`'s boundary is unchanged — this is finer, not looser.
         *
         * The frame is same-origin and carries the same cookie as the panel that wrote the
         * key, so this resolves to the same session the Livewire action ran in.
         */
        $unsaved = $preview->get($tenantId, session()->getId());

        if ($unsaved !== null) {
            try {
                $document = withTenant(
                    $tenantId,
                    static fn (): LandingDocument => LandingDocument::fromStored(
                        $unsaved,
                        new TenantMediaReferences($tenantId),
                    ),
                );

                return $this->respond($document, $pages, $renderer, $tenantId);
            } catch (Throwable) {
                /*
                 * ⚠ NO REFUSAL HERE, AND THAT IS THE RULING RATHER THAN LENIENCY:
                 * *"an unsaved preview has nothing to refuse, so render it defensively."*
                 * An unsaved state that does not validate should never have been written —
                 * the writer applies the same gate — so reaching this branch means the key
                 * is stale, corrupt or from a schema that has since moved. Falling through
                 * to the stored draft shows the owner something real; a 422 here would put
                 * the studio's own refusal screen inside the preview pane for a document
                 * nobody asked to publish.
                 */
            }
        }

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

        return $this->respond($document, $pages, $renderer, $tenantId);
    }

    /**
     * Render one document as this route's response.
     *
     * ⚠ EXTRACTED AT `QRPAGER-45` SO THE TWO DOCUMENT SOURCES CANNOT DRIFT. The unsaved
     * state and the stored draft now both reach the renderer, and a second copy of this
     * block would be a second set of asset URLs, a second language lookup and a second
     * header set — three ways for the preview of what you are typing to differ from the
     * preview of what you saved, for reasons that have nothing to do with what you typed.
     */
    private function respond(
        LandingDocument $document,
        LandingPages $pages,
        LandingRenderer $renderer,
        string $tenantId,
    ): Response {
        $hostname = $pages->hostname($tenantId);

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
     * nothing else -- no script of any origin, no frame, no fetch.
     *
     * ================================================================================
     * ⚠ WHO MAY FRAME THIS: THE PANEL, AND NOBODY ELSE — `QRPAGER-40`, 2026-11-09
     * ================================================================================
     *
     * **This USED to read `frame-ancestors 'none'` with `X-Frame-Options: DENY`, and the
     * comment here said *"keeps the draft out of an iframe"*. Both are now narrowed to
     * same-origin: `frame-ancestors 'self'` and `SAMEORIGIN`.** A future reader must not
     * find the old sentence beside a policy that permits one, so it is replaced rather
     * than appended to.
     *
     * **WHAT IS NEWLY PERMITTED, exactly:** the authenticated studio panel, served from
     * this same origin, framing its own draft in the preview pane that `QRPAGER-40` pins
     * beside the form on every tab. Nothing else.
     *
     * **WHAT IS STILL REFUSED, and it is the part that matters:**
     *
     *   - **Every third-party framer.** `'self'` is an origin match, so no other site can
     *     frame this response, and clickjacking needs a CROSS-ORIGIN frame. The attack the
     *     original `'none'` was defending against is still defended against.
     *   - **Script, of any origin, framed or not.** `default-src 'none'` is untouched, so
     *     the draft executes nothing either way. Framing changes WHO MAY EMBED the bytes,
     *     never WHAT THE BYTES MAY DO.
     *   - **The §11 finding itself.** That review's subject was draft bytes served from the
     *     session-bearing origin under a WEAKER policy than the image inside them. That
     *     asymmetry is what got fixed and it stays fixed: `default-src 'none'`,
     *     `img-src 'self'`, `style-src 'unsafe-inline'`, `base-uri 'none'` and
     *     `form-action 'none'` are byte-identical to what it left here.
     *
     * **WHY IT MOVED, and the reason is not "a ticket needed it".** Muhammad's ruling of
     * 2026-11-09, rank 2, recorded in `§1`: `'none'` was a **default-deny taken when
     * nothing needed to frame the draft**. The studio preview pane now does, so the deny is
     * narrowed deliberately and on the record rather than left to be discovered as an
     * obstacle and widened quietly by whoever hits it next.
     *
     * **TWO ALTERNATIVES WERE REFUSED** in the same ruling, and they are named here because
     * the next person to need something from this response will reach for one of them: a
     * SECOND ROUTE serving the same bytes with its own framing policy (two responses of one
     * thing under different policies is the asymmetry that produced the original finding),
     * and a `srcdoc` frame inside the panel page (whose child inherits the PANEL policy,
     * `script-src 'unsafe-inline' 'unsafe-eval'` and all -- `R-242` -- which is precisely
     * the execution the strict policy here exists to prevent).
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
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; "
                ."style-src 'unsafe-inline'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'",
        ];
    }
}
