<?php

declare(strict_types=1);

namespace App\Features\Landing;

use App\Domain\Landing\MediaReferences;
use App\Platform\Database\DatabaseRoles;
use App\Platform\Database\TenantContext;
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
        $this->assertStillInOwnScope();

        return array_key_exists(strtolower($mediaId), $this->owned ??= $this->load());
    }

    /**
     * Refuse to answer from memory outside the scope this instance belongs to.
     *
     * ================================================================================
     * ⚠ THE MEMO'S HAZARD, CLOSED AT THE CLASS RATHER THAN BY AN AUDIT
     * ================================================================================
     *
     * Before `QRPAGER-45` every `has()` was a live query, so RLS refused a foreign id on
     * every call no matter who held the object — which is what `MediaIsolationTest`'s
     * *"neither half is load-bearing alone"* rests on. A memo takes the policy out of the
     * per-call path: the `§11` security round of 2026-11-11 (M-1) measured a WARM instance
     * answering **true** for tenant alpha's asset while running inside tenant beta's scope.
     *
     * Every construction site today is `new TenantMediaReferences($id)` inside the matching
     * `withTenant($id, …)`, and nothing binds the class in the container — but
     * `laravel/octane` is a dependency, so the application can be RESIDENT between requests,
     * and an instance that outlived its scope would be state leaking across tenants through
     * memory. That is `§4`'s pooled-connection hazard one layer up.
     *
     * Muhammad's ruling of 2026-11-11: *"A lifetime audit goes stale the first time someone
     * binds it in the container; a self-check cannot."* So the instance carries its tenant
     * id already, and now compares it to the LIVE scope on every call and throws otherwise.
     * It costs no query — `TenantContext::current()` is the scope the process itself opened
     * — so the batching this guard protects is unaffected.
     *
     * Fail CLOSED: a mismatch is a programming error, not an input, so it raises rather than
     * answering false. Answering false would be a silent refusal of a tenant's own media.
     */
    private function assertStillInOwnScope(): void
    {
        /*
         * ⚠ FREE, AND THE EXACT CHECK HAD TO MOVE — SEE `load()`. `TenantContext::current()`
         * is NOT free: it calls `readState()`, which reads `app.tenant_id` from the database.
         * Calling it per `has()` put a round trip back on every media reference and the cost
         * grew with the page again — measured, 36 queries at one image and 56 at six, which
         * is exactly the unboundedness `R-291` and the `§11` QA round's M-7 had just closed.
         * A guard that reopens the High it stands next to is not a guard.
         *
         * `hasOpenScope()` reads a PHP array and costs nothing. It closes the case Muhammad
         * named as the one that matters most — a queued job or a boot-time call reaching this
         * class with NO tenant context at all — and the exact identity is asserted once, in
         * `load()`, where a query is being made anyway.
         *
         * WHAT IS LEFT, STATED RATHER THAN IMPLIED: a warm instance reused inside a
         * DIFFERENT open scope is not detected. Closing that per call needs the tenant id
         * available in PHP, and that mirror was deliberately REMOVED — `R-017` carries
         * reintroducing it, and `TenantContext::current()`'s own comment explains why the
         * shape check cannot stand in for it. `R-293` records the residual with that
         * dependency named.
         */
        if (! app(TenantContext::class)->hasOpenScope(DatabaseRoles::connection('app'))) {
            throw new \LogicException(
                'a media reference check ran with no tenant scope open'
            );
        }
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
        /*
         * ⚠ THE EXACT IDENTITY CHECK LIVES HERE, WHERE A QUERY IS ALREADY HAPPENING.
         * One round trip per INSTANCE rather than one per reference, so the batching the
         * `§11` rounds asked for survives. It refuses to populate a memo from a scope that
         * is not this instance's — which is the state that would make every later answer
         * wrong, and the one an Octane-resident object could otherwise reach.
         */
        $scope = app(TenantContext::class)->current();

        if ($scope !== $this->tenantId) {
            throw new \LogicException(
                'a media reference set was loaded outside the tenant scope it was built for'
            );
        }

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
