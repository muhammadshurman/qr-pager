<?php

declare(strict_types=1);

namespace App\Features\Landing;

use App\Domain\Landing\LandingDocument;
use App\Domain\Landing\LandingDocumentInvalid;
use App\Domain\Landing\LandingPageStatus;
use App\Domain\Tenancy\TenantLanguage;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The tenant's landing page: the draft, the publish, and the states §12.1 renders.
 *
 * ================================================================================
 * §6.3 — HOW TENANT CONTEXT REACHES EVERY QUERY IN THIS CLASS
 * ================================================================================
 *
 * Every public method opens `withTenant($tenantId, …)` itself, in the service layer, and
 * nothing here reads a request. The tenant id arrives from the caller, which got it from
 * the panel's own resolution (a membership, §4 › Tenancy's second named cross-tenant
 * path) -- never from a header, a route parameter or a body field.
 *
 * ================================================================================
 * §6.6 — PUBLISH IS WRITE-NEW-THEN-SWITCH, AND THE ORDER IS THE POSTURE (D8)
 * ================================================================================
 *
 *   1. validate the stored draft        -- a document that no longer validates is refused
 *   2. resolve the hostname             -- the active custom domain, else the platform one
 *   3. render and hash                  -- pure: no database, NO OBJECT STORE
 *   4. update landing_pages             -- ONE statement, keyed on what step 1 saw, and
 *                                          its affected-row count is checked
 *   5. copy every referenced asset      -- the FIRST thing that touches the store
 *   6. write {checksum}.html            -- immutable, named by the hash of its own bytes
 *   7. write index.html                 -- THE POINTER WRITE IS THE SWITCH
 *   8. write manifest.json              -- metadata, and a SECOND pointer, so it is last
 *   9. reconcile                        -- if the row now says `draft`, sweep: nothing
 *                                          of this tenant's may be live in that state
 *
 * ⚠ THE ROW IS WRITTEN BEFORE ANY OBJECT AT ALL, WHICH INVERTS DECISION D8, and
 * `QRPAGER-14 §0 SR-4` records why rather than leaving it to be discovered. D8 says "the
 * database is the last word", and with the row last EVERY failure between a store write
 * and the commit left something serving that nothing recorded -- clause 1 of this task's
 * named invariant. Clause 1 failed FIVE times before this ordering, and four of those
 * were fixes for the one before. With the row first the worst case is a row claiming
 * MORE than exists -- `published` while the old page still serves -- which is not an
 * invariant failure, is visible to the tenant in the panel, and is corrected by
 * publishing again.
 *
 * ⚠ AND THE SNAPSHOT IS NOT WRITTEN EARLY "BECAUSE NOTHING NAMES IT". It used to be, and
 * the argument was false: `manifest.json` carries the checksum in its BODY and lives in
 * the prefix the CDN maps `Host` onto, so the name of every snapshot is public for the
 * life of the publish, and the checksum is STABLE across an unpublish and a re-publish
 * (`LandingDraftUnreachableTest` asserts exactly that). "Not reachable" in this project
 * means `allFiles($prefix) === []`; one key's absence was never the standard, and the
 * fifth clause-1 failure was this method writing that key back into a swept prefix after
 * a take-down. See publish()'s own docblock for the four-step route.
 *
 * A failure at 1-4 writes NOTHING and leaves the live page and the database on the
 * previous publish. A failure at 5-8 leaves the row ahead of the store, which is the
 * deliberate residual above, and the tenant is told so in those words
 * (`PublishFailed::afterRecord()`) rather than "your live page is unchanged".
 *
 * ⚠ THEY ARE NOT ALL IN ONE TRANSACTION, and this sentence said they were until the §11
 * QA review. Step 1-3 is one short transaction, step 4 is a second one of ONE statement,
 * and steps 5-8 hold no transaction at all -- see publish()'s own docblock for why,
 * which is that holding a connection idle across the object writes ran into
 * `idle_in_transaction_session_timeout`.
 */
final class LandingPages
{
    /**
     * The advisory-lock class for this feature's per-tenant publish lock.
     *
     * 7 for Task 7. Two-argument advisory locks occupy a space of their own, so this
     * cannot collide with any single-key lock in the repository however it hashes.
     */
    public const LOCK_CLASS = 7;

    public function __construct(
        private readonly LandingRenderer $renderer = new LandingRenderer,
        private readonly MediaLibrary $media = new MediaLibrary,
    ) {}

    /**
     * The tenant's draft, validated.
     *
     * @throws LandingDocumentInvalid when a stored draft no longer satisfies the schema
     */
    public function draft(string $tenantId): LandingDocument
    {
        return withTenant($tenantId, function () use ($tenantId): LandingDocument {
            return LandingDocument::fromStored(
                $this->decode($this->row($tenantId)->draftDocument),
                new TenantMediaReferences($tenantId),
            );
        });
    }

    /**
     * Build a document from form input WITHOUT storing it — `QRPAGER-45`'s unsaved preview.
     *
     * ⚠ THE SAME GATE AS `saveDraft()`, AND DELIBERATELY NOT A SECOND ONE. Both call
     * `LandingDocument::fromInput($input, new TenantMediaReferences($tenantId))`, which is
     * where the schema is enforced AND where a media id belonging to another tenant is
     * refused. The unsaved preview path reaches the renderer with client-supplied state, so
     * it is the one path on this feature that could show a tenant an asset it does not own;
     * routing it through the same static is what stops that, and is why this method exists
     * here rather than as a `LandingDocument::fromInput()` call at the call site.
     *
     * What it does NOT do is touch `landing_pages`. Muhammad's ruling of 2026-11-10 is
     * explicit that this feature *"writes nothing to Postgres on a keystroke"* and that
     * `draft` keeps meaning what it means today.
     *
     * @param  array<mixed>  $input
     *
     * @throws LandingDocumentInvalid when the state does not satisfy the schema
     */
    public function validated(string $tenantId, array $input): LandingDocument
    {
        return withTenant($tenantId, static fn (): LandingDocument => LandingDocument::fromInput(
            $input,
            new TenantMediaReferences($tenantId),
        ));
    }

    /**
     * Validate and store a new draft. Publishing is a separate act.
     *
     * @param  array<mixed>  $input
     *
     * @throws LandingDocumentInvalid
     */
    public function saveDraft(string $tenantId, array $input): LandingDocument
    {
        return withTenant($tenantId, function () use ($tenantId, $input): LandingDocument {
            $document = LandingDocument::fromInput($input, new TenantMediaReferences($tenantId));

            $this->row($tenantId);

            DB::table('landing_pages')->where('tenant_id', $tenantId)->update([
                'draft_document' => $this->encode($document->toArray()),
                'draft_updated_at' => now(),
                'updated_at' => now(),
            ]);

            return $document;
        });
    }

    /**
     * Publish the draft. See the class docblock for the order and why it is that order.
     *
     * ================================================================================
     * NOT ONE BYTE REACHES THE STORE BEFORE THE ROW COMMITS — THE FIFTH CLAUSE-1 FIX
     * ================================================================================
     *
     * The previous ordering copied the assets and wrote the snapshot BEFORE the row, on
     * the argument that `{checksum}.html` is unreachable because nothing names it. The
     * third scoped re-review falsified that argument in one step: `manifest.json` carries
     * the checksum in its BODY, in the prefix the CDN maps `Host` onto, so every checksum
     * a publish produces is published BY NAME for the whole life of that publish -- and
     * the checksum is a hash of the document, which `LandingDraftUnreachableTest` asserts
     * is STABLE across an unpublish and a re-publish. The route is then four steps:
     *
     *   publish -> anyone (a crawler, one look) records `C` from `manifest.json`
     *           -> the tenant unpublishes: the prefix is emptied, the row says `draft`
     *           -> ANY later attempt that dies between the snapshot write and the commit
     *              -- the guard refusing, `statement_timeout`, a worker recycle --
     *           -> `sites/{host}/{C}.html` is back, serving the taken-down page in full,
     *              with the row still at `draft`.
     *
     * That is clause 1 of the named invariant for the FIFTH time, by the same route as
     * the first. The answer is not another compensation: three of the five failures WERE
     * compensations. It is that phase 2 no longer touches the network at all. Rendering
     * never needed a store write -- `PublishedSite::assetUrl()` is a pure function of the
     * private key -- so the HTML and its checksum are computable before anything is
     * copied, and every store write can happen after the row says the page is published,
     * which is the only state in which any of those objects may exist.
     *
     * ================================================================================
     * THE OBJECT WRITES ARE OUTSIDE THE TRANSACTION, AND THAT IS A FIX, NOT A STYLE
     * ================================================================================
     *
     * This method used to run end to end inside ONE `withTenant()`, which is one
     * transaction (`helpers.php`). Between the `media_assets` SELECT and the final
     * `UPDATE` the backend then sat idle for the whole duration of up to 27 object-store
     * operations -- 24 server-side copies of objects up to 5 MB each, plus three writes,
     * plus SDK retries. `config/tenancy.php` sets `idle_in_transaction_session_timeout`
     * to **15s** for `qrpager_app`. Exceed it and Postgres terminates the connection, so
     * the final `UPDATE` fails AFTER the pointer write has already switched the live
     * page -- the one divergence this class's docblock calls a rare residual, reached
     * instead by an ordinary configured timeout. It also held one of the role's forty
     * connections for the whole publish, which is the exact cost `RequireTenantScope`'s
     * docblock cites as the reason this project refuses request-scoped transactions.
     *
     * Found by the `§11` QA review on 2026-10-28. There are now FIVE phases:
     *
     *   1. one SHORT transaction  -- read the draft, validate it, resolve the hostname
     *                                and the asset keys. Needs a tenant scope; touches
     *                                no network.
     *   2. NO transaction         -- render and hash. Touches NEITHER the database NOR
     *                                the network: pure computation over phase 1's plan.
     *   3. one SHORT transaction  -- ONE statement: the row, keyed on what phase 1 saw.
     *   4. NO transaction         -- the assets, the snapshot, the pointer, the manifest.
     *                                Everything reachable, and all of it after the row.
     *   5. one SHORT read         -- reconcile, for the one window phase 4 leaves open.
     *
     * Phase 1, 2 or 3 failing writes NOTHING and leaves the live page and the row on the
     * previous publish. The residual is phase 4 failing, which leaves the row claiming
     * MORE than exists -- `published` while the old page still serves -- and that is the
     * direction `SR-4` chose deliberately, because the other direction is a live page
     * nothing records, which is the invariant. It is visible in the panel, it is named in
     * the failure the tenant sees (`PublishFailed::afterRecord()`), and a re-publish of
     * the same document renders the same bytes and converges.
     *
     * @throws PublishFailed
     */
    public function publish(string $tenantId): PublishedPage
    {
        return $this->withPublishLock($tenantId, fn (): PublishedPage => $this->publishUnderLock($tenantId));
    }

    private function publishUnderLock(string $tenantId): PublishedPage
    {
        // ---- PHASE 1: one short transaction, no network inside it. ----------------
        /** @var array{seenStatus: string, seenPublishedAt: ?string, document: LandingDocument, hostname: string, tenant: object{name: string, language: string}, assets: array<string, string>} $plan */
        $plan = withTenant($tenantId, function () use ($tenantId): array {
            $row = $this->row($tenantId);

            // The STORED draft is re-validated, never trusted. An asset it referenced
            // can have been deleted and the schema can have been narrowed by a deploy.
            try {
                $document = LandingDocument::fromStored(
                    $this->decode($row->draftDocument),
                    new TenantMediaReferences($tenantId),
                );
            } catch (LandingDocumentInvalid $e) {
                throw new PublishFailed(__('landing.publish.field_invalid', ['field' => $e->field, 'reason' => $e->reason]));
            }

            if ($document->isEmpty()) {
                throw new PublishFailed(__('landing.publish.no_blocks'));
            }

            $references = $document->mediaReferences();

            // One query for every referenced asset, bounded at 24 by the document cap.
            $assets = DB::table('media_assets')
                ->whereIn('id', $references === [] ? ['00000000-0000-0000-0000-000000000000'] : $references)
                ->pluck('object_key', 'id')
                ->all();

            $keys = [];

            foreach ($references as $id) {
                if (! isset($assets[$id])) {
                    // Cannot happen after validation, and it is checked anyway: the step
                    // this guards is a COPY, and copying from a key that is not there
                    // would publish a page with a broken image rather than refusing.
                    throw new PublishFailed(__('landing.publish.image_missing'));
                }

                $keys[$id] = (string) $assets[$id];
            }

            return [
                // ⚠ THE TWO COLUMNS, NOT A TOKEN BUILT FROM THEM. Phase 3 puts these in
                // the UPDATE's own WHERE clause, so they have to be comparable to the
                // columns; the concatenated `'{published_at}|{status}'` string the guard
                // used to carry could only be compared in PHP, which is what made the
                // guard a SECOND statement and left a window between it and the write.
                'seenStatus' => $row->status->value,
                'seenPublishedAt' => $row->publishedAt,
                'document' => $document,
                'hostname' => $this->hostnameInScope($tenantId),
                // ⚠ ONE ROW, TWO COLUMNS, AND IT WAS ALREADY ONE READ. `language` joins
                // `name` on the row this phase already fetches (QRPAGER-30) rather than
                // arriving as a second query -- phase 2 is the database-free phase and the
                // renderer takes the language as an ARGUMENT for exactly that reason.
                // `§2.8`'s named list of cross-tenant reads is untouched: this is the
                // tenant's own row, inside its own scope.
                'tenant' => (object) (array) DB::table('tenants')
                    ->where('id', $tenantId)
                    ->first(['name', 'language']),
                'assets' => $keys,
            ];
        });

        $document = $plan['document'];
        $hostname = $plan['hostname'];

        // ---- PHASE 2: no transaction, NO DATABASE AND NO NETWORK. -----------------
        //
        // The copies USED TO BE HERE, and that is the fifth clause-1 failure in one
        // line. They are not needed to render: the published URL of an asset is a pure
        // function of its private key, so the document's media ids map to their URLs
        // with no round trip, and the destination keys are computed here and copied in
        // phase 4.
        $assetUrls = [];
        $destinations = [];

        foreach ($plan['assets'] as $id => $privateKey) {
            $assetUrls[$id] = PublishedSite::assetUrl($privateKey);
            $destinations[$privateKey] = PublishedSite::asset($hostname, $privateKey);
        }

        $html = $this->renderer->render(
            $document,
            $hostname,
            (string) $plan['tenant']->name,
            $assetUrls,
            TenantLanguage::from((string) $plan['tenant']->language),
        );
        $checksum = hash('sha256', $html);

        // ---- PHASE 3: the row, and it is the FIRST thing this method writes. ------
        $publishedAt = now();

        /** @var int $affected */
        $affected = withTenant($tenantId, function () use ($tenantId, $plan, $document, $checksum, $hostname, $publishedAt): int {
            // ⚠ ONE STATEMENT, KEYED ON WHAT PHASE 1 SAW, AND ITS ROW COUNT IS CHECKED.
            // This is `§4 › Postgres traps` in the one place in this feature where it
            // decides whether a page becomes reachable: SELECT, then UPDATE … WHERE the
            // key AND what the SELECT saw, then check the affected rows. It replaced a
            // read-then-unconditional-UPDATE pair in two separate `withTenant()` calls
            // -- which is two TRANSACTIONS, because `TenantContext::run()` wraps each
            // call in one, so the guard committed and released before the write began
            // (`R-163`, and the docblock that called it "one short transaction" was
            // wrong about its own code).
            //
            // `is not distinct from` rather than `=`: `published_at` is NULL on a draft
            // row, and `NULL = NULL` is NULL, so `=` would never match the commonest
            // case this guard exists for -- publishing a page that was just taken down.
            return DB::table('landing_pages')
                ->where('tenant_id', $tenantId)
                ->where('status', $plan['seenStatus'])
                ->whereRaw('published_at is not distinct from cast(? as timestamptz)', [$plan['seenPublishedAt']])
                ->update([
                    'status' => LandingPageStatus::Published->value,
                    'published_document' => $this->encode($document->toArray()),
                    'published_checksum' => $checksum,
                    'published_hostname' => $hostname,
                    'published_at' => $publishedAt,
                    'updated_at' => $publishedAt,
                ]);
        });

        if ($affected !== 1) {
            // ⚠ NOTHING TO COMPENSATE, AND THAT IS THE POINT OF THE ORDERING. This
            // method has written nothing at all yet -- no copy, no snapshot, no pointer,
            // no manifest -- so a refusal here leaves the live page, the row and the
            // prefix exactly as they were. The two compensations it used to carry were
            // each written to undo a switch that had already happened, and each
            // introduced a clause-1 failure of its own; the third version left a
            // snapshot whose name the manifest had already published. Removing the need
            // for a compensation is worth more than getting one right.
            throw new PublishFailed(__('landing.publish.changed_while_publishing'));
        }

        // ---- PHASE 4: everything reachable, all of it after the row. --------------
        //
        // THE ROW IS WRITTEN BEFORE ANY OBJECT, which inverts decision D8's "the
        // database is the last word" -- see `QRPAGER-14 §0 SR-4` for why, recorded rather
        // than resolved in passing. In one line: with the row last, every failure between
        // a store write and the commit left something serving that nothing recorded,
        // which is clause 1 of the named invariant, and clause 1 failed five times before
        // this ordering. With the row first the worst case is a row that claims MORE than
        // exists -- `published` while the old page still serves -- which is not an
        // invariant failure, is visible to the tenant, and is fixed by publishing again.
        //
        // ⚠ EVERY FAILURE BELOW IS `afterRecord()`, NOT A PLAIN `PublishFailed`. The
        // panel's "Publish failed — your live page is unchanged" is a promise about the
        // STORE, and from here down the ROW has already moved, so that sentence would be
        // a false claim to the tenant -- true about the page and wrong about what the
        // panel will show them one line later. The ticket's `§12.1` copy table is written
        // for the old ordering and `SR-4` now carries this consequence.
        $copied = [];

        foreach ($destinations as $privateKey => $destination) {
            if (! $this->media->copyObject($privateKey, $destination)) {
                throw PublishFailed::afterRecord(__('landing.publish.image_not_copied'));
            }

            $copied[] = $destination;
        }

        // The immutable snapshot, before the pointer and the manifest, because both of
        // them name it.
        $this->write(
            PublishedSite::snapshot($hostname, $checksum),
            $html,
            'text/html; charset=utf-8',
            'your page could not be written, so it is recorded as published but is not live yet; publish again',
        );

        $this->write(
            PublishedSite::pointer($hostname),
            $html,
            'text/html; charset=utf-8',
            'your page could not be put in place, so it is recorded as published but is not live yet; publish again',
        );

        // LAST, because it NAMES THE SNAPSHOT in its body -- this file's `unpublish()`
        // docblock says exactly that, and a manifest written before the thing it names
        // was reachable is how the fourth clause-1 failure happened.
        $this->write(
            PublishedSite::manifest($hostname),
            (string) json_encode([
                'hostname' => $hostname,
                'checksum' => $checksum,
                'published_at' => $publishedAt->toIso8601String(),
                'bytes' => strlen($html),
                'assets' => array_values($assetUrls),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'application/json',
            'your page is live, but the site metadata beside it could not be written; publish again',
        );

        /*
         * ⚠ **READ BACK, AND THAT IS `R-170`'s FIX.**
         *
         * `write()` only fails when `put()` RETURNS FALSE. The gap that row records is the one
         * where the manifest write fails, the publish throws `afterRecord`, and the tenant
         * does not publish again: `index.html` and the row then carry the new checksum while
         * `manifest.json` names the previous one, indefinitely and in silence.
         * `PublishedSite` calls the manifest *"what the CDN and Task 11 read to learn what is
         * live"*, so a consumer that trusts it reads a stale answer.
         *
         * The reconciliation is a read of the thing just written. It costs one GET per publish
         * and it converts a silent disagreement into a reported one, which is the whole
         * difference: the tenant is told *publish again* with the page already live, rather
         * than nothing being said to anyone.
         *
         * ⚠ **AND IT IS A `put()` THEN A GET RATHER THAN A RETRY LOOP**, deliberately. A retry
         * would make a flapping store take minutes and would still have to end in a verdict;
         * one write and one check end in the verdict immediately, and the publish path's whole
         * posture (`afterRecord`) is already *the page is live, the metadata is not, say so*.
         *
         * ⚠ **THE OTHER HALF OF `R-170` IS THAT NOTHING SERVES THIS FILE.** `ADR-005` made
         * `apps/site` read the POINTER and refuse every other path, so a stale manifest is not
         * reachable from the serving surface at all -- `site-isolation-proof` asserts
         * `/manifest.json` answers 404, and `domain-move-proof` asserts the agreement itself.
         * Both halves are needed: the absence of a consumer today is not a reason to let the
         * file lie, because the next consumer is a CDN configuration nobody will test.
         */
        $written = MediaLibrary::disk()->get(PublishedSite::manifest($hostname));
        /** @var array{checksum?: string}|null $decoded */
        $decoded = is_string($written) ? json_decode($written, true) : null;

        if (($decoded['checksum'] ?? null) !== $checksum) {
            throw PublishFailed::afterRecord(__('landing.publish.metadata_mismatch'));
        }

        // ---- PHASE 5: reconcile the one window phase 4 leaves open. ---------------
        //
        // ⚠ THIS IS A COMPENSATION, AND IT IS THE ONE SHAPE OF COMPENSATION THIS METHOD
        // CAN SAFELY CARRY. The two it used to have deleted objects while the row might
        // still have said `published`, so each could delete a LIVE page's own objects.
        // This one fires only when the row says `draft`, which is the single state in
        // which nothing of this tenant's may be live at all -- so the sweep it performs
        // is the same sweep `unpublish()` performs, on a prefix that is already supposed
        // to be empty, and it cannot take down a page another actor published.
        //
        // The window it closes: an `unpublish()` landing between the commit in phase 3
        // and the last write in phase 4 empties the prefix and sets the row to `draft`,
        // and this method then writes the pointer into it -- a live page with the row at
        // `draft`, which is clause 1 again. The advisory lock prevents that across
        // processes (and `R-158` records that the second caller aborts rather than
        // waits); it does NOT prevent it within one session, where the lock is
        // re-entrant, nor after `R-160`'s leak loses it with the connection.
        $recorded = withTenant($tenantId, fn (): ?object => DB::table('landing_pages')
            ->where('tenant_id', $tenantId)
            ->first(['status', 'published_checksum']));

        $stillOurs = $recorded !== null
            && (string) $recorded->status === LandingPageStatus::Published->value
            && (string) $recorded->published_checksum === $checksum;

        if (! $stillOurs) {
            if ($recorded === null || (string) $recorded->status === LandingPageStatus::Draft->value) {
                // The prefixes are resolved inside the scope and swept OUTSIDE it, so
                // the store round trips are not held against
                // `idle_in_transaction_session_timeout` (`R-144`, `R-165`).
                $this->sweep(withTenant(
                    $tenantId,
                    fn (): array => $this->publishedPrefixes($tenantId, $hostname),
                ));

                throw PublishFailed::afterRecord(__('landing.publish.taken_down_while_publishing'));
            }

            // The row records a DIFFERENT publish, so some other actor holds the prefix
            // and this one must not touch it. Reachable only with the lock lost
            // (`R-160`); recorded as `R-169` rather than guessed at here.
            throw PublishFailed::afterRecord(__('landing.publish.another_publish_finished'));
        }

        return new PublishedPage(
            $hostname,
            $checksum,
            strlen($html),
            $copied,
            $publishedAt->toIso8601String(),
        );
    }

    /**
     * Take the page down: the WHOLE site prefix is deleted and the row returns to `draft`.
     *
     * ⚠ IT USED TO DELETE THE POINTER ALONE, and the comment here said the snapshot was
     * "immutable and unreachable without the pointer". **The manifest IS a pointer.**
     * `sites/{hostname}/manifest.json` carries the checksum in its body and survived,
     * in the same prefix the CDN maps Host onto -- so after an unpublish,
     * `GET /manifest.json` handed anyone the name of `GET /{checksum}.html`, which
     * served the page in full, with its assets still beside it under `/assets/`. That is
     * the first clause of this task's named invariant -- "an unpublished draft is not
     * reachable" -- failing by a route nothing tested. Found by the §11 security review
     * on 2026-10-28.
     *
     * Deleting the snapshots costs nothing, which is the part the old comment had
     * backwards: the DRAFT is still in the database, so a re-publish renders the same
     * bytes and produces the same checksum. `LandingDraftUnreachableTest` asserts that
     * round trip, so "recoverable" is a measured property rather than a reason to leave
     * a page serving after it was taken down.
     *
     * The tenant's own library under `media/{tenant}/` is NOT touched: this takes the
     * site down, it does not delete their images.
     */
    public function unpublish(string $tenantId): void
    {
        $this->withPublishLock($tenantId, function () use ($tenantId): void {
            $this->unpublishUnderLock($tenantId);
        });
    }

    private function unpublishUnderLock(string $tenantId): void
    {
        withTenant($tenantId, function () use ($tenantId): void {
            $row = $this->row($tenantId);

            // ⚠ IT DOES NOT RETURN EARLY ON A `draft` ROW ANY MORE, and that early return
            // was the thing that made two separate clause-1 failures PERMANENT rather
            // than transient. Whenever a page is live with the row at `draft` -- a
            // process killed between the pointer write and the row write, a refused
            // phase 3 whose compensation itself failed -- the only verb a tenant has is
            // Unpublish, and it looked at the row, saw `draft`, and did nothing. There
            // was then no path left in the application that could take the page down.
            //
            // So it now sweeps the prefixes on every call. A take-down of a page that
            // was already down is a few DELETEs against keys that are not there, which
            // is cheap and idempotent; a take-down that refuses because the ROW says
            // there is nothing to take down is a page nobody can remove.
            if ($row->publishedHostname === null && $row->status !== LandingPageStatus::Published) {
                // No hostname was ever recorded, so the only prefixes this tenant can
                // have written to are the platform fallback and its own domains -- which
                // publishedPrefixes() covers from the tenant's slug and tenant_domains.
                $this->sweep($this->publishedPrefixes($tenantId, $this->platformHostnameInScope($tenantId)));

                return;
            }

            // ⚠ EVERY HOSTNAME THIS TENANT HAS EVER PUBLISHED TO, not just the current
            // one. A domain switch leaves the superseded hostname serving a redirect --
            // which is the point -- but `republish()` only OVERWRITES index.html and
            // manifest.json there, so every earlier `{checksum}.html` and every copied
            // asset survives under `sites/{old}/`. Deleting only `published_hostname`
            // left those fetchable by exact URL after a take-down, to anyone who had
            // recorded a name while the site was live. Found by the §11 review of the
            // fix diff, round 1.
            $this->sweep($this->publishedPrefixes(
                $tenantId,
                $row->publishedHostname ?? $this->platformHostnameInScope($tenantId),
            ));

            // The whole prefix, pointer and manifest and every snapshot and every copied
            // asset -- everything the CDN would serve for this hostname.
            //
            // ⚠ THE RETURN VALUE IS CHECKED, AND IT WAS NOT. The s3 disk is configured
            // `'throw' => false`, so a failed deleteDirectory returns FALSE and raises
            // nothing -- the identical trap this file already documents for `put()` two
            // methods down, missed on the one path whose whole job is to make a page
            // unreachable. A transient store failure left index.html serving the full
            // page while the row said `draft`, and the guard above (`status !==
            // Published -> return`) then made every retry a no-op, so there was no way
            // left to take it down. Found by the §11 review of the fix diff, round 1.
            //
            // Thrown BEFORE the row is updated, so the row stays `published` and a retry
            // still has something to do.

            DB::table('landing_pages')->where('tenant_id', $tenantId)->update([
                'status' => LandingPageStatus::Draft->value,
                'published_document' => null,
                'published_checksum' => null,
                'published_hostname' => null,
                'published_at' => null,
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * The row as §12.1 needs it: which of the six states the builder is in.
     *
     * @return array{status: LandingPageStatus, hostname: string, published_hostname: ?string, published_at: ?string, has_unpublished_changes: bool, blocks: int, invalid: ?string}
     */
    public function state(string $tenantId): array
    {
        return withTenant($tenantId, function () use ($tenantId): array {
            $row = $this->row($tenantId);
            $status = $row->status;

            $invalid = null;
            $blocks = 0;

            try {
                $document = LandingDocument::fromStored(
                    $this->decode($row->draftDocument),
                    new TenantMediaReferences($tenantId),
                );
                $blocks = count($document->blocks);
            } catch (LandingDocumentInvalid $e) {
                // §12.1's "invalid" state: the refusing field named, inline. The VALUE is
                // not carried -- see LandingDocumentInvalid for why.
                $invalid = $e->field.' can\'t be published: '.$e->reason;
            }

            return [
                'status' => $status,
                'hostname' => $this->hostnameInScope($tenantId),
                'published_hostname' => $row->publishedHostname,
                'published_at' => $row->publishedAt,
                // Compared as the CANONICAL encoding of each document, not as raw jsonb:
                // Postgres does not preserve key order in jsonb, so a textual comparison
                // of the two columns would report a change that is not one.
                'has_unpublished_changes' => $status === LandingPageStatus::Published
                    && $this->canonical($row->draftDocument) !== $this->canonical($row->publishedDocument),
                'blocks' => $blocks,
                'invalid' => $invalid,
            ];
        });
    }

    /**
     * The hostname this tenant's page is served at.
     *
     * The ACTIVE custom domain if there is one, else `{slug}.{platform_domain}` -- which
     * is §12.3's "none" state: a page is live from its first publish, before anyone has
     * touched DNS.
     */
    public function hostname(string $tenantId): string
    {
        return withTenant($tenantId, fn (): string => $this->hostnameInScope($tenantId));
    }

    /**
     * `{slug}.{platform_domain}`, ALWAYS -- whatever custom domain exists.
     *
     * `hostname()` answers "where is this page served", which is a different question
     * and a different answer once a custom domain is active. §12.3's "none" state needs
     * this one, and the panel method that served it was named for this and returned
     * that (the §11 QA review).
     */
    public function platformHostname(string $tenantId): string
    {
        return withTenant($tenantId, fn (): string => $this->platformHostnameInScope($tenantId));
    }

    /** The same answer for a caller that has already opened the scope. */
    private function platformHostnameInScope(string $tenantId): string
    {
        $slug = (string) DB::table('tenants')->where('id', $tenantId)->value('slug');

        return $slug.'.'.config('landing.platform_domain');
    }

    /**
     * The same answer, for a caller that has ALREADY opened the scope.
     *
     * ⚠ THE SPLIT EXISTS BECAUSE THE SINGLE METHOD WAS A DEFECT, and the scope guard is
     * what found it. `hostname()` was public, queried `tenant_domains` directly, and
     * relied on its caller having opened a scope -- true of `publish()`, which calls it
     * from inside one, and false of `DomainsPage::platformHostname()`, which is the
     * panel's own first query. The guard refused the statement before it reached
     * Postgres ("A statement touching [tenant_domains] was refused ... no tenant scope is
     * open there") and `/studio/domains` answered 500 for an owner.
     *
     * That is §3.1 row 2's invariant doing exactly its job, and the lesson is the one
     * RequireTenantScope's docblock already states: a method that NEEDS a scope either
     * opens one or is private to a caller that has. This pair is both halves, named so
     * neither can be used by mistake.
     */
    private function hostnameInScope(string $tenantId): string
    {
        $custom = DB::table('tenant_domains')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->value('hostname');

        if (is_string($custom)) {
            return $custom;
        }

        $slug = (string) DB::table('tenants')->where('id', $tenantId)->value('slug');

        return $slug.'.'.config('landing.platform_domain');
    }

    /**
     * Serialise publish and unpublish for ONE tenant, without holding a transaction.
     *
     * ================================================================================
     * WHY A LOCK AND NOT A GUARD -- THE ROUND-2 INVARIANT FAILURE
     * ================================================================================
     *
     * `publish()` wrote the pointer in phase 2 and checked in phase 3 whether the row
     * had moved underneath it. Round 1 of the fix diff's review asked for that check and
     * round 2 found what it actually produced: the check runs AFTER the switch, so an
     * `unpublish()` landing between the phases left the page LIVE and the row at
     * `draft` -- and `unpublish()` returns early when the status is not `published`, so
     * nothing could ever take it down again. An unpublished page, reachable, permanently.
     * That is the first clause of this task's named invariant, and it was introduced by
     * the fix for a Medium.
     *
     * A guard cannot close it, because the damage is done one phase before the guard
     * runs. A row lock would close it and would mean holding a transaction across the
     * object writes -- which is the High that the phase split exists to fix. So: a
     * SESSION-level advisory lock, which serialises the two operations per tenant and
     * holds no transaction at all.
     *
     * TWO-ARGUMENT, so it lives in a different lock space from every one-argument key
     * in this repository -- `TestCase::SUITE_LOCK_KEY` and `tenancy.bootstrap_lock_key`
     * are both single-key locks and cannot collide with these whatever they hash to.
     * (Measured: `pg_locks.objsubid` is 1 for a one-key lock and 2 for a two-key one.)
     *
     * ⚠ WHAT IT DOES AND DOES NOT GUARANTEE, measured rather than assumed, because the
     * first version of this docblock overstated both:
     *
     *   - ACROSS PROCESSES it does NOT make the second caller wait. `qrpager_app` carries
     *     `lock_timeout = 2s` (`config/tenancy.php`), and a publish holds this lock for
     *     far longer than that, so a concurrent publish or unpublish ABORTS with 55P03
     *     rather than queueing. That still prevents the interleave -- one of the two
     *     fails instead of both proceeding -- so clause 1 is protected, but "serialises"
     *     is the wrong word for it and `R-158` is the row.
     *   - WITHIN ONE SESSION it gives no exclusion at all: Postgres session advisory
     *     locks are re-entrant, so a nested call re-acquires instantly. That is why
     *     `ReviewFixesTest`'s interleave case can still force the race in-process, and
     *     why publish()'s phase-5 reconciliation is a real path rather than dead code.
     *   - IF THE PROCESS DIES the lock is held until the CONNECTION closes, which under
     *     Octane is not the same thing as the request ending (`R-160`).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withPublishLock(string $tenantId, Closure $callback): mixed
    {
        // The APP connection, resolved rather than named: `config('tenancy.connections.app')`
        // is what `withTenant()` defaults to, so the lock and the work it guards cannot
        // end up on two different connections after a config change. This was the one
        // query in the class that hard-coded `'pgsql'`.
        $connection = DB::connection((string) config('tenancy.connections.app'));
        $objectId = self::lockObjectId($tenantId);

        $connection->statement('select pg_advisory_lock(?, ?)', [self::LOCK_CLASS, $objectId]);

        try {
            return $callback();
        } finally {
            // SWALLOWED, for the reason TenantContext::assertScopeSurvived() swallows its
            // rollback: this runs while an exception may already be in flight, and an
            // unlock that throws would REPLACE it -- so a publish that failed for a
            // reason the tenant needs to see would surface as a lock error instead. The
            // connection closing releases the lock anyway, which is what makes losing
            // this statement survivable.
            try {
                $connection->statement('select pg_advisory_unlock(?, ?)', [self::LOCK_CLASS, $objectId]);
            } catch (Throwable) {
                // The lock outlives this request and dies with the connection.
            }
        }
    }

    /** A tenant id as a signed 32-bit advisory-lock object id. */
    private static function lockObjectId(string $tenantId): int
    {
        $hash = crc32($tenantId);

        return $hash > 2147483647 ? $hash - 4294967296 : $hash;
    }

    /**
     * Delete every one of these prefixes, or refuse.
     *
     * The return value is checked for the reason this file checks every other one: the
     * s3 disk is configured `'throw' => false`, so a failed delete answers false and
     * raises nothing -- and a take-down that reports success while the page is still
     * served is the worst of the shapes this task has produced.
     *
     * @param  list<string>  $prefixes
     */
    private function sweep(array $prefixes): void
    {
        foreach ($prefixes as $prefix) {
            if (MediaLibrary::disk()->deleteDirectory($prefix) === false) {
                throw new PublishFailed(__('landing.publish.take_down_failed'));
            }
        }
    }

    /**
     * Every site prefix this tenant may have published to.
     *
     * ⚠ COMPLETE ONLY WHILE TWO THINGS STAY TRUE, named here because the scoped
     * re-review found them unstated: `tenants.slug` never changes (there is no Edit page
     * on `RestaurantResource`, so the platform fallback this derives is stable), and a
     * `tenant_domains` row is never deleted (`R-138` — there is no delete path). Break
     * either and a prefix is orphaned with the row at `draft`, which is clause 1 of the
     * named invariant again. `R-168`.
     *
     * The hostname currently recorded on the row, every hostname in `tenant_domains`
     * (an `active` one is the current target and a `redirecting` one is a superseded
     * target that still has objects under it), and the platform fallback the first
     * publish always went to. De-duplicated, because they overlap by design.
     *
     * @return list<string>
     */
    private function publishedPrefixes(string $tenantId, string $publishedHostname): array
    {
        $hostnames = [$publishedHostname, $this->platformHostnameInScope($tenantId)];

        foreach (DB::table('tenant_domains')->pluck('hostname') as $hostname) {
            $hostnames[] = (string) $hostname;
        }

        return array_values(array_unique(array_map(
            static fn (string $hostname): string => PublishedSite::root($hostname),
            array_unique($hostnames),
        )));
    }

    /** The row, created on first touch. One per tenant -- UNIQUE (tenant_id). */
    private function row(string $tenantId): LandingPageRow
    {
        $row = DB::table('landing_pages')->where('tenant_id', $tenantId)->first();

        if ($row !== null) {
            return LandingPageRow::from($row);
        }

        DB::table('landing_pages')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'draft_document' => '{}',
        ]);

        return LandingPageRow::from((object) DB::table('landing_pages')->where('tenant_id', $tenantId)->first());
    }

    /**
     * Write one object with its content type and the cache policy §6.5 states.
     *
     * NO VISIBILITY AND NO ACL, which is ADR-004 and guard rule 10: the stand-in honours
     * object ACLs and production refuses them, so public read is a per-prefix CDN
     * decision made in Terraform and never a call from here.
     *
     * The return value is CHECKED because the s3 disk is configured `'throw' => false`
     * -- a failed PUT returns false and raises nothing, which is how a publish could
     * otherwise report success having written nothing (measured on the media path).
     *
     * ⚠ THE REASON IS THE CALLER'S, AND IT IS `afterRecord()`. Every call site is now in
     * phase 4, i.e. after the row has committed, so the one sentence this used to raise
     * -- "your live page is unchanged" -- is the one thing that is no longer true of all
     * of them: by the pointer write it is false outright, and before it the row already
     * disagrees with the store. Each call site says what state its own failure leaves.
     */
    private function write(string $key, string $contents, string $contentType, string $reason): void
    {
        $written = MediaLibrary::disk()->put($key, $contents, [
            'ContentType' => $contentType,
            'CacheControl' => (string) config('landing.cache_control'),
        ]);

        if ($written === false) {
            throw PublishFailed::afterRecord($reason);
        }
    }

    /** @param array<mixed> $document */
    private function encode(array $document): string
    {
        return (string) json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return array<mixed> */
    private function decode(string $stored): array
    {
        try {
            $decoded = json_decode($stored, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** A stored jsonb column as a stable string, for comparing two of them. */
    private function canonical(mixed $stored): string
    {
        if (! is_string($stored)) {
            return '';
        }

        $decoded = $this->decode($stored);
        $this->sortDeep($decoded);

        return $this->encode($decoded);
    }

    /** @param array<mixed> $value */
    private function sortDeep(array &$value): void
    {
        foreach ($value as &$child) {
            if (is_array($child)) {
                $this->sortDeep($child);
            }
        }

        unset($child);

        if (! array_is_list($value)) {
            ksort($value);
        }
    }
}
