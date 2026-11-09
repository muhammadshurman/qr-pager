<?php

declare(strict_types=1);

namespace App\Features\Landing;

use App\Domain\Landing\LandingDocument;
use App\Domain\Landing\LandingDocumentInvalid;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The tenant's private image library (§12.2).
 *
 * ================================================================================
 * EVERY METHOD OPENS ITS OWN TENANT SCOPE, AND THAT IS §6.3's ANSWER FOR THIS CLASS
 * ================================================================================
 *
 * "Show the exact path by which tenant context reaches every query." It reaches them
 * here, through withTenant() in the service layer, never through middleware --
 * RequireTenantScope's docblock gives the reason: a request-scoped transaction would hold
 * one of the forty qrpager_app connections for the whole request.
 *
 * ================================================================================
 * THE KEY LAYOUT IS THE ISOLATION, AND THE DATABASE ENFORCES IT
 * ================================================================================
 *
 * `media/{tenant_id}/{32 hex}.{ext}`. Two CHECK constraints on media_assets pin that
 * shape, so this class cannot write a key naming another tenant's prefix even if the id
 * it was handed were wrong -- the insert raises 23514 before the object is written. The
 * 32 hex characters are 128 bits of CSPRNG, the same bar §4 › Data sets for the QR token,
 * so a key is not guessable from anything a tenant can see.
 *
 * ================================================================================
 * NO ACL IS SET ANYWHERE IN THIS FILE, AND THAT IS ADR-004 AND GUARD RULE 10
 * ================================================================================
 *
 * `media/` is PRIVATE and never served to the public. What a published page serves is a
 * COPY under `sites/{hostname}/assets/` (decision D3), so "public read" is one explicit
 * prefix for the CDN to decide about in Terraform, never a per-object ACL -- which the
 * local stand-in honours and production refuses, the one place the stand-in is MORE
 * PERMISSIVE than production.
 */
final class MediaLibrary
{
    /** §6.5: 200 assets per tenant. §12.2's "full" state. */
    public const MAX_ASSETS = 200;

    /** The private library's prefix. The published copy lives elsewhere (D3). */
    public const PREFIX = 'media';

    /**
     * Store $bytes as a new asset of $tenantId, and return its id.
     *
     * ORDER MATTERS AND IS FAIL-CLOSED. The row is inserted inside the tenant
     * transaction and the object is written before that transaction commits, so:
     *
     *   - the object write throwing rolls the row back -> no row, no object;
     *   - a commit failing after the object write leaves an ORPHAN OBJECT -> a key in
     *     the store with no row, which nothing reads and NOTHING REPORTS.
     *
     * ⚠ THIS USED TO SAY "which `media:orphans` reports". That command does not exist --
     * it was named in two docblocks and in the ticket and was never written, so an
     * accepted residual rested on a compensating control that was not there. Found by
     * the §11 QA review on 2026-10-28, which is §4a's most-recorded defect shape: a
     * record claiming a thing before the thing exists. The residual is now R-136, with
     * an owner, and the citation is gone rather than the command being invented in a
     * fix pass.
     *
     * The reverse order would risk the one state that actually breaks something: a row
     * pointing at bytes that are not there, which a publish would then fail on.
     *
     * @throws MediaRejected
     */
    public function store(string $tenantId, string $originalName, string $bytes): string
    {
        // BEFORE anything is written. An upload is refused by what it IS, and the size
        // and type checks are the cheapest work in this method.
        $image = InspectedImage::of($bytes);

        $id = (string) Str::uuid7();

        return withTenant($tenantId, function () use ($tenantId, $id, $originalName, $image, $bytes): string {
            if (DB::table('media_assets')->count() >= self::MAX_ASSETS) {
                throw new MediaRejected(
                    __('landing.media.library_full', ['max' => self::MAX_ASSETS])
                );
            }

            $key = self::key($tenantId, $image->extension);

            DB::table('media_assets')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'object_key' => $key,
                // RECORDED, never branched on. The extension in the key above came from
                // the detected type; this column exists so a tenant can recognise their
                // own file in a list.
                'original_name' => self::safeName($originalName),
                'content_type' => $image->contentType,
                'byte_size' => $image->byteSize,
                'checksum' => $image->checksum,
                'width' => $image->width,
                'height' => $image->height,
            ]);

            // Inside the transaction on purpose -- see the docblock. A failure here
            // rolls the row back.
            //
            // ⚠ THE RETURN VALUE IS CHECKED, AND IT HAS TO BE. config/filesystems.php
            // sets `'throw' => false` on the s3 disk, so a failed PUT returns FALSE
            // rather than raising -- measured against a bucket that does not exist, where
            // the first version of this method committed a media_assets row and wrote
            // nothing. That is precisely the one state the ordering above exists to
            // prevent: a row pointing at bytes that are not there, which the tenant
            // discovers at their next publish.
            //
            // ⚠ THE METADATA IS SET HERE AND NOT AT PUBLISH TIME, which looks odd on a
            // PRIVATE object that is never served -- and is the only place it can be set.
            // A publish copies this object into the site prefix SERVER-SIDE (D3, §6.5's
            // cost argument), an S3 server-side copy carries the SOURCE's metadata, and
            // Flysystem's copy() exposes no MetadataDirective. So an object stored bare
            // produces a published copy with no Content-Type and no Cache-Control --
            // measured, after LandingPublishTest read the copy's headers back and found
            // CacheControl absent.
            //
            // Both values are correct for this object wherever it is served from: the
            // bytes are immutable (the key's leaf is CSPRNG and is never reused), so a
            // long shared cache can never go stale, and the Content-Type is the type the
            // bytes were DETECTED as rather than anything the uploader claimed.
            $written = self::disk()->put($key, $bytes, [
                'ContentType' => $image->contentType,
                'CacheControl' => (string) config('landing.cache_control'),
            ]);

            if ($written === false) {
                throw new MediaRejected(__('landing.media.not_stored'));
            }

            return $id;
        });
    }

    /**
     * Delete an asset, refusing when the page still references it (§12.2's "in use").
     *
     * Checks the DRAFT and the PUBLISHED document. The published page's own copy lives
     * under the site prefix and would keep serving either way (D3), but a re-publish of
     * a document referencing a deleted asset is refused -- so allowing the delete would
     * arm a failure for the tenant's next publish instead of refusing now.
     *
     * @throws MediaRejected
     */
    public function delete(string $tenantId, string $assetId): void
    {
        withTenant($tenantId, function () use ($tenantId, $assetId): void {
            $asset = DB::table('media_assets')->where('id', $assetId)->first();

            if ($asset === null) {
                throw new MediaRejected(__('landing.media.not_in_library'));
            }

            $references = new TenantMediaReferences($tenantId);

            foreach (['draft_document', 'published_document'] as $column) {
                $stored = DB::table('landing_pages')->value($column);

                if (! is_string($stored)) {
                    continue;
                }

                /** @var array<mixed> $decoded */
                $decoded = json_decode($stored, true, 32, JSON_THROW_ON_ERROR) ?: [];

                // A document that no longer validates cannot be read for references, and
                // refusing the delete is the safe direction: the alternative is deleting
                // an asset a repairable page still points at.
                try {
                    $document = LandingDocument::fromStored($decoded, $references);
                } catch (LandingDocumentInvalid) {
                    throw new MediaRejected(__('landing.media.page_unreadable'));
                }

                if ($block = $document->blockUsing($assetId)) {
                    throw new MediaRejected(
                        __('landing.media.in_use_by_block', ['block' => __('studio.blocks.'.$block->value)])
                    );
                }
            }

            DB::table('media_assets')->where('id', $assetId)->delete();

            // After the row, for the same reason the store writes the object before the
            // row commits: an orphan object is reportable and inert, a row pointing at
            // nothing is not.
            self::disk()->delete((string) $asset->object_key);
        });
    }

    /**
     * The tenant's library, newest first. What §12.2's table renders.
     *
     * @return list<MediaAssetRow>
     */
    public function all(string $tenantId): array
    {
        return withTenant($tenantId, static fn (): array => array_values(
            DB::table('media_assets')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get()
                ->map(static fn (object $row): MediaAssetRow => MediaAssetRow::from($row))
                ->all()
        ));
    }

    public function count(string $tenantId): int
    {
        return withTenant($tenantId, static fn (): int => DB::table('media_assets')->count());
    }

    /**
     * Read one of THIS TENANT'S assets back out of the store.
     *
     * ⚠ IT TAKES AN ASSET ID AND NEVER AN OBJECT KEY, which is deliberate and is the
     * difference between a method and an IDOR. A `bytes(string $objectKey)` would be a
     * read of any path in the bucket by whoever could reach the caller -- the object
     * store has no row level security behind it, so the key IS the capability. Here the
     * key is resolved from a row inside the tenant's own scope, so a caller can only ever
     * name an asset RLS already showed them.
     */
    public function bytes(string $tenantId, string $assetId): ?string
    {
        $key = withTenant($tenantId, static fn () => DB::table('media_assets')
            ->where('id', $assetId)
            ->value('object_key'));

        if (! is_string($key)) {
            return null;
        }

        $contents = self::disk()->get($key);

        return is_string($contents) ? $contents : null;
    }

    /**
     * Copy an object inside the store, server-side.
     *
     * The publish path (D3): `media/{tenant}/…` is the private library and is never
     * public; `sites/{hostname}/assets/…` holds only what a published page references.
     * Both keys are built by this application from a row it read inside a tenant scope --
     * neither is ever tenant input -- which is why this one may take keys where bytes()
     * above may not.
     *
     * Server-side, so the bytes never travel to the application and back: §6.5's cost
     * argument for a publish depends on that.
     */
    public function copyObject(string $sourceKey, string $destinationKey): bool
    {
        return self::disk()->copy($sourceKey, $destinationKey);
    }

    /** `media/{tenant}/{32 hex}.{ext}` -- the shape two CHECK constraints pin. */
    public static function key(string $tenantId, string $extension): string
    {
        return self::PREFIX.'/'.$tenantId.'/'.bin2hex(random_bytes(16)).'.'.$extension;
    }

    public static function disk(): Filesystem
    {
        return Storage::disk('s3');
    }

    /**
     * An uploaded filename, reduced to something safe to STORE and show.
     *
     * It is never used to build a path -- the key's leaf is CSPRNG -- so this is about
     * what a panel renders and a log might carry, not about traversal. Anything outside
     * a conservative set becomes an underscore, and the result is bounded to the column.
     *
     * PUBLIC since the §11 security review: the panel used the RAW client filename in a
     * notification title, and Filament renders a title through `sanitizeHtml()` -- an
     * allow-list sanitiser rather than `e()` -- so the stored value and the DISPLAYED
     * value were two different values. One reduction, one call site each.
     */
    public static function safeName(string $name): string
    {
        $name = (string) preg_replace('/[^\w .\-()]+/u', '_', $name);
        $name = trim($name) === '' ? 'image' : trim($name);

        return mb_substr($name, 0, 255);
    }
}
