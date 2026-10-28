<?php

declare(strict_types=1);

namespace App\Features\Landing;

use App\Domain\Landing\LandingDocument;
use App\Domain\Landing\LandingDocumentInvalid;
use App\Domain\Landing\LandingPageStatus;
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
 *   3. copy every referenced asset      -- a missing asset refuses the publish
 *   4. write {checksum}.html            -- immutable, named by the hash of its own bytes
 *   5. write manifest.json              -- what is live, for Task 11 and Task 13
 *   6. write index.html                 -- THE POINTER WRITE IS THE SWITCH
 *   7. update landing_pages             -- the database is the last word
 *
 * A failure at 1-5 leaves the live page and the database on the previous publish. A
 * failure at 6 does the same, because nothing before it is reachable: `{checksum}.html`
 * has a name nobody knows and the assets sit beside a pointer that still names the old
 * page.
 *
 * ⚠ THEY ARE NOT ALL IN ONE TRANSACTION, and this sentence said they were until the §11
 * QA review. Steps 1-3 are one short transaction, steps 3-6 hold NO transaction at all,
 * and step 7 is a second short one -- see publish()'s own docblock for why, which is
 * that holding a connection idle across the object writes ran into
 * `idle_in_transaction_session_timeout`. A failure at 7 therefore leaves the pointer
 * switched, which is the residual the next paragraph is about.
 *
 * ⚠ THE ONE RESIDUAL, STATED RATHER THAN LEFT TO BE FOUND: if the pointer write succeeds
 * and then the COMMIT fails, the live page is the new one while the row still says the
 * old. It is self-healing -- a re-publish of the same document renders the same bytes and
 * therefore the same checksum, so it converges -- and the page that is live in that window
 * is a correct page of this tenant, never a partial one. It is not reordered to close it
 * because the alternative (row first) would make a failed pointer write leave the DATABASE
 * claiming a page that is not live, which is the direction that lies to the tenant.
 */
final class LandingPages
{
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
     * Found by the `§11` QA review on 2026-10-28. There are now THREE phases:
     *
     *   1. one SHORT transaction  -- read the draft, validate it, resolve the hostname
     *                                and the asset keys. Needs a tenant scope; touches
     *                                no network.
     *   2. NO transaction         -- copy the assets, render, write the snapshot, the
     *                                manifest and then the pointer. Touches no database.
     *   3. one SHORT transaction  -- write the row.
     *
     * The guarantees are unchanged, because the ORDER is unchanged: the pointer write is
     * still the switch and the database is still the last word. Phase 1 or 2 failing
     * leaves the live page and the row on the previous publish; the residual is still
     * phase 3 failing after the pointer write, and it is still self-healing because a
     * re-publish of the same document renders the same bytes.
     *
     * @throws PublishFailed
     */
    public function publish(string $tenantId): PublishedPage
    {
        // ---- PHASE 1: one short transaction, no network inside it. ----------------
        /** @var array{seen: string, document: LandingDocument, hostname: string, tenantName: string, assets: array<string, string>} $plan */
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
                throw new PublishFailed($e->field.' can\'t be published: '.$e->reason);
            }

            if ($document->isEmpty()) {
                throw new PublishFailed('your page has no blocks yet, so there is nothing to publish');
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
                    throw new PublishFailed('an image this page uses is no longer in your library');
                }

                $keys[$id] = (string) $assets[$id];
            }

            return [
                'seen' => (string) ($row->publishedAt ?? '').'|'.$row->status->value,
                'document' => $document,
                'hostname' => $this->hostnameInScope($tenantId),
                'tenantName' => (string) DB::table('tenants')->where('id', $tenantId)->value('name'),
                'assets' => $keys,
            ];
        });

        $document = $plan['document'];
        $hostname = $plan['hostname'];

        // ---- PHASE 2: no transaction, no database. --------------------------------
        $assetUrls = [];
        $copied = [];

        foreach ($plan['assets'] as $id => $privateKey) {
            $destination = PublishedSite::asset($hostname, $privateKey);

            if (! $this->media->copyObject($privateKey, $destination)) {
                throw new PublishFailed('an image could not be copied to your site; nothing was published');
            }

            $assetUrls[$id] = PublishedSite::assetUrl($privateKey);
            $copied[] = $destination;
        }

        $html = $this->renderer->render($document, $hostname, $plan['tenantName'], $assetUrls);
        $checksum = hash('sha256', $html);

        $this->write(PublishedSite::snapshot($hostname, $checksum), $html, 'text/html; charset=utf-8');

        $this->write(
            PublishedSite::manifest($hostname),
            (string) json_encode([
                'hostname' => $hostname,
                'checksum' => $checksum,
                'published_at' => now()->toIso8601String(),
                'bytes' => strlen($html),
                'assets' => array_values($assetUrls),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'application/json',
        );

        // THE SWITCH.
        $this->write(PublishedSite::pointer($hostname), $html, 'text/html; charset=utf-8');

        // ---- PHASE 3: one short transaction. The database is the last word. -------
        $publishedAt = now();

        withTenant($tenantId, function () use ($tenantId, $plan, $document, $checksum, $hostname, $publishedAt): void {
            // ⚠ GUARDED ON WHAT PHASE 1 SAW. The phases are three transactions now, so
            // an unpublish or a domain activation can land between them -- and an
            // unguarded UPDATE would then write `published` over a row somebody had just
            // taken down, leaving a live pointer and a row that disagrees with it. The
            // window existed before the split too and the split widened it. Found by the
            // §11 review of the fix diff, round 1.
            //
            // A refusal here leaves the OBJECTS written and the row untouched, which is
            // the safe direction: the bytes are unreachable without a pointer this
            // transaction did not write, and the next publish converges.
            $row = $this->row($tenantId);

            if ((string) ($row->publishedAt ?? '').'|'.$row->status->value !== $plan['seen']) {
                throw new PublishFailed(
                    'your page changed while it was publishing; nothing was published, try again'
                );
            }

            DB::table('landing_pages')->where('tenant_id', $tenantId)->update([
                'status' => LandingPageStatus::Published->value,
                'published_document' => $this->encode($document->toArray()),
                'published_checksum' => $checksum,
                'published_hostname' => $hostname,
                'published_at' => $publishedAt,
                'updated_at' => $publishedAt,
            ]);
        });

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
        withTenant($tenantId, function () use ($tenantId): void {
            $row = $this->row($tenantId);

            if ($row->status !== LandingPageStatus::Published || $row->publishedHostname === null) {
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
            foreach ($this->publishedPrefixes($tenantId, $row->publishedHostname) as $prefix) {
                if (MediaLibrary::disk()->deleteDirectory($prefix) === false) {
                    throw new PublishFailed('your site could not be taken down; your page is unchanged');
                }
            }

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
     * Every site prefix this tenant may have published to, newest first.
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
     */
    private function write(string $key, string $contents, string $contentType): void
    {
        $written = MediaLibrary::disk()->put($key, $contents, [
            'ContentType' => $contentType,
            'CacheControl' => (string) config('landing.cache_control'),
        ]);

        if ($written === false) {
            throw new PublishFailed('your site could not be written; your live page is unchanged');
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
