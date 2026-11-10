<?php

declare(strict_types=1);

namespace App\Features\Landing;

use App\Domain\Landing\MediaReferences;
use Illuminate\Support\Facades\DB;

/**
 * The real MediaReferences: a `media_assets` lookup for ONE tenant.
 *
 * CONSTRUCTED WITH A TENANT ID AND NEVER WITH A REQUEST. The id it holds is the one whose
 * scope the caller has already opened, so `has()` runs inside that transaction and RLS
 * narrows it as well -- the `where tenant_id` is belt to the policy's braces, not the
 * control. A class that resolved the tenant itself would be a second answer to "which
 * tenant is this", which is the property RequireTenantScope's docblock depends on.
 *
 * ================================================================================
 * ⚠ ONE QUERY PER INSTANCE, AND IT WAS ONE PER REFERENCE UNTIL `QRPAGER-45`
 * ================================================================================
 *
 * The sentence here used to read *"A document is validated once per save and once per
 * publish, against at most 24 ids"*, and that premise was what made a query per `has()`
 * acceptable. `QRPAGER-45` falsified it: the studio now validates the document BEHIND EVERY
 * KEYSTROKE, twice per debounce fire (`StudioForm::merged()` re-reads and re-validates the
 * stored draft, then `LandingPages::validated()` validates the merged one) and once more
 * when the preview frame re-renders. The `§11` rounds of 2026-11-11 measured the result
 * from both sides — QA's M-7 (29 SELECTs per call) and security's M-2 (*"the client picks
 * the multiplier"*, by how many images sit in the form).
 *
 * **A rate limit sized to that is a limit sized to an unbounded operation.** So the cost is
 * bounded here, at the cause, and the limiter is derived from the measurement afterwards.
 *
 * The id set is read ONCE per instance and answered from memory after that. It is bounded
 * by `MediaLibrary::MAX_ASSETS` (200 per tenant, enforced on upload), and it selects ids
 * only — so the query is flat in the number of references the document cites, which is the
 * property a test can falsify. Twenty-four references now cost the same as one.
 *
 * ⚠ THIS IS STILL NOT A CACHE, AND THE DISTINCTION IS THE LIFETIME. The memo lives for
 * ONE instance, and every caller constructs a new one inside the scope it is validating in
 * (`LandingPages::draft()`, `::saveDraft()`, `::validated()`, `PreviewController`). Nothing
 * survives a request, so the stale-"yes"-after-a-delete hazard the old sentence named is
 * unchanged — and within a single validation pass a set read once is MORE consistent than
 * twenty-four reads taken at twenty-four instants.
 */
final class TenantMediaReferences implements MediaReferences
{
    /** @var array<string, true>|null */
    private ?array $owned = null;

    public function __construct(private readonly string $tenantId) {}

    public function has(string $mediaId): bool
    {
        return array_key_exists(strtolower($mediaId), $this->owned ??= $this->load());
    }

    /**
     * Every media id this tenant owns, as a set.
     *
     * The `where tenant_id` is belt to the policy's braces exactly as it was before: this
     * runs inside the caller's already-open tenant scope and RLS narrows it too.
     *
     * ⚠ NO `limit()`, AND THERE WAS ONE FOR ABOUT TEN MINUTES. It read
     * `->limit(MediaLibrary::MAX_ASSETS)`, on the reasoning that 200 bounds the fetch. But
     * `MAX_ASSETS` is enforced at CREATION only — `MediaLibrary::store()` does a plain
     * `count() >= MAX_ASSETS` and then an `insert()`, with no lock and no unique
     * constraint — so two concurrent uploads can both read 199 and a tenant can hold 201.
     *
     * A read limit would then TRUNCATE the id set, and a perfectly valid reference to that
     * 201st asset would fail validation: `previewChanged()` swallows
     * `LandingDocumentInvalid`, so the owner's preview would simply stop following them
     * with nothing on screen, and their Save would be refused for an image they own. A cap
     * on creation restated at the read is not a bound, it is a second source of truth that
     * can disagree with the first — and the direction it fails in is silent.
     *
     * The fetch is bounded by the creation cap where the cap belongs. Ids only, one tenant,
     * inside that tenant's scope.
     *
     * @return array<string, true>
     */
    private function load(): array
    {
        $ids = DB::table('media_assets')
            ->where('tenant_id', $this->tenantId)
            ->pluck('id');

        $owned = [];

        foreach ($ids as $id) {
            $owned[strtolower((string) $id)] = true;
        }

        return $owned;
    }
}
