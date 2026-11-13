<?php

declare(strict_types=1);

use App\Domain\Landing\LandingDocumentInvalid;
use App\Features\Landing\LandingPages;
use App\Features\Landing\MediaLibrary;
use App\Features\Landing\MediaRejected;
use App\Features\Landing\TenantMediaReferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ImageFixtures;
use Tests\Support\LandingFixtures;

/*
 * ================================================================================
 * THE THIRD PART OF THE TASK'S NAMED INVARIANT
 * ================================================================================
 *
 *   "One tenant's media cannot be reached or guessed from another."
 *
 * REACHED and GUESSED are two different claims and they have two different mechanisms,
 * so they are tested separately here:
 *
 *   reached -> RLS on media_assets, plus the fact that every path into the object store
 *              resolves its key from a row inside a tenant scope. There is no method
 *              anywhere that takes an object key from a caller.
 *   guessed -> 128 bits of CSPRNG in the key's leaf, pinned by a CHECK constraint, so
 *              the key is not derivable from the tenant id, the filename, the content or
 *              anything else a second tenant can see.
 */

beforeEach(function (): void {
    $bucket = (string) config('filesystems.disks.s3.bucket');
    $client = MediaLibrary::disk()->getClient();

    if (! $client->doesBucketExist($bucket)) {
        $client->createBucket(['Bucket' => $bucket]);
    }
});

it('shows a tenant only its own assets, and the other tenant none of them', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $library = new MediaLibrary;

    $alphaAsset = $library->store($alpha, 'alpha.png', ImageFixtures::png());
    $library->store($beta, 'beta.png', ImageFixtures::webp());

    expect($library->all($alpha))->toHaveCount(1)
        ->and($library->all($beta))->toHaveCount(1)
        ->and($library->count($alpha))->toBe(1);

    // Named explicitly, not merely counted: beta asking for alpha's id by uuid.
    expect(withTenant($beta, fn () => DB::table('media_assets')->where('id', $alphaAsset)->exists()))
        ->toBeFalse();

    // And with no context at all, which is §3 row 1's clause one applied to a new table.
    DB::connection('pgsql')->disconnect();
    expect(DB::table('media_assets')->count())->toBe(0);
});

it('answers false when the other tenant asks whether an asset exists, with no hint that it does', function (): void {
    // This is the port the document validator consults. If it answered true across
    // tenants, a tenant could reference another tenant's image by uuid and publish it --
    // which would be a cross-tenant read on the most public surface this product has.
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();

    $alphaAsset = (new MediaLibrary)->store($alpha, 'alpha.png', ImageFixtures::png());

    expect(withTenant($alpha, fn () => (new TenantMediaReferences($alpha))->has($alphaAsset)))->toBeTrue()
        ->and(withTenant($beta, fn () => (new TenantMediaReferences($beta))->has($alphaAsset)))->toBeFalse();

    /*
     * The implementation carries BOTH a tenant_id predicate and the RLS policy, and
     * neither half is load-bearing alone. ⚠ THE PROOF MOVED AT `QRPAGER-45` AND THE
     * CLAIM DID NOT.
     *
     * This used to read `(new TenantMediaReferences($alpha))->has($alphaAsset)` from
     * inside beta's scope and expect false — the predicate pointed one way, the policy
     * refusing anyway. That ticket batched the lookup (one id-set read per instance
     * instead of one query per reference) and the `§11` security round measured what it
     * cost: a WARM instance answers from memory, so the policy is no longer consulted on
     * every call and the old expression proved only that the instance happened to be
     * cold.
     *
     * So the class now REFUSES a mismatch outright rather than returning a value that
     * looks like a policy decision, and the policy is proven directly below — against
     * the table, which is where the claim actually lives.
     */
    expect(fn () => withTenant($beta, fn () => (new TenantMediaReferences($alpha))->has($alphaAsset)))
        ->toThrow(LogicException::class);

    // THE POLICY, ON ITS OWN. A predicate pointed at alpha, from inside beta's scope,
    // against the table itself: RLS returns nothing even though the row exists and the
    // `where` names it. This is the half the port used to demonstrate.
    expect(withTenant($beta, fn (): int => DB::table('media_assets')
        ->where('tenant_id', $alpha)
        ->where('id', $alphaAsset)
        ->count()))->toBe(0);

    // ...and the row really is there, so the zero above is the policy and not an absence.
    expect(withTenant($alpha, fn (): int => DB::table('media_assets')
        ->where('id', $alphaAsset)
        ->count()))->toBe(1);
});

it('refuses a cross-tenant delete in the same words as a delete of nothing', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $library = new MediaLibrary;

    $alphaAsset = $library->store($alpha, 'alpha.png', ImageFixtures::png());

    $foreign = null;
    $absent = null;

    try {
        $library->delete($beta, $alphaAsset);
    } catch (Throwable $e) {
        $foreign = $e->getMessage();
    }

    try {
        $library->delete($beta, (string) Str::uuid7());
    } catch (Throwable $e) {
        $absent = $e->getMessage();
    }

    // IDENTICAL, deliberately: a different message for "exists but is not yours" makes
    // the endpoint an existence oracle for other tenants' assets.
    expect($foreign)->toBe($absent)
        ->and($foreign)->toContain('not in your library');

    // And alpha's asset is still there.
    expect($library->count($alpha))->toBe(1);
});

it('reads back nothing when one tenant asks for another tenants asset by id', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $library = new MediaLibrary;

    $alphaAsset = $library->store($alpha, 'alpha.png', ImageFixtures::png());

    expect($library->bytes($alpha, $alphaAsset))->toBe(ImageFixtures::png())
        ->and($library->bytes($beta, $alphaAsset))->toBeNull();
});

it('gives the object store no method that takes a key from a caller', function (): void {
    // THE STRUCTURAL HALF OF "cannot be reached". The object store has no row level
    // security behind it, so an object key IS a capability: anything that would read a
    // caller-supplied key is a cross-tenant read with nothing underneath it.
    //
    // Asserted over the class's own signatures rather than by trying an attack, because
    // the claim is about what EXISTS. A new public method taking a $key fails here, in
    // the diff, rather than being found later.
    $reflection = new ReflectionClass(MediaLibrary::class);

    $takesAKey = [];

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        foreach ($method->getParameters() as $parameter) {
            if (str_contains(strtolower($parameter->getName()), 'key')) {
                $takesAKey[] = $method->getName().'($'.$parameter->getName().')';
            }
        }
    }

    // copyObject is the ONE exception and it is named here rather than excluded by a
    // pattern: both of its keys are built by this application from a row it read inside
    // a tenant scope, and neither is ever tenant input (decision D3).
    expect($takesAKey)->toBe(['copyObject($sourceKey)', 'copyObject($destinationKey)']);
});

it('makes a key unguessable from everything a second tenant can see', function (): void {
    // 128 bits of CSPRNG in the leaf -- the same bar §4 › Data sets for the QR token --
    // and pinned by media_assets_key_is_unguessable so the application cannot write any
    // other shape even by mistake.
    $alpha = $this->makeTenant();
    $library = new MediaLibrary;

    $bytes = ImageFixtures::png();
    $first = $library->store($alpha, 'logo.png', $bytes);
    $second = $library->store($alpha, 'logo.png', $bytes);

    $keys = withTenant($alpha, fn () => DB::table('media_assets')
        ->whereIn('id', [$first, $second])->pluck('object_key')->all());

    // SAME tenant, SAME filename, SAME bytes -> different keys. A key derived from any
    // of the three would be reproducible by anyone holding the same file.
    expect($keys[0])->not->toBe($keys[1]);

    foreach ($keys as $key) {
        $leaf = basename((string) $key, '.png');

        expect($leaf)->toMatch('/^[0-9a-f]{32}$/')
            // Not derived from the asset id either, which a tenant does see.
            ->and($leaf)->not->toBe(str_replace('-', '', $first))
            ->and($leaf)->not->toBe(str_replace('-', '', $second))
            ->and($leaf)->not->toBe(hash('md5', $bytes));
    }
});

it('keeps the private prefix private: a published page never serves from media/', function (): void {
    // Decision D3, as an assertion about the key rather than about a CDN configuration
    // nobody here can test. `media/` is the library; a published page serves a COPY under
    // `sites/{hostname}/assets/`, so "public read" is one explicit prefix for Terraform
    // to decide about and never an ACL on an object (ADR-004, guard rule 10).
    $alpha = $this->makeTenant();
    $id = (new MediaLibrary)->store($alpha, 'logo.png', ImageFixtures::png());

    $key = withTenant($alpha, fn () => DB::table('media_assets')->where('id', $id)->value('object_key'));

    expect($key)->toStartWith(MediaLibrary::PREFIX.'/')
        ->and($key)->not->toStartWith('sites/');
});

/*
 * ================================================================================
 * DELETING AN IMAGE DOES NOT DEPEND ON THE REST OF THE PAGE BEING VALID
 * ================================================================================
 *
 * Muhammad's walkthrough of PR #22, defect 2. The media tab answered
 * «لم تُحذف الصورة — تعذّرت قراءة صفحتك الآن» for EVERY delete, because `delete()` built a
 * validated `LandingDocument` to ask *"is this asset referenced?"* — so one invalid field
 * anywhere refused every delete on the tab. In his page it was `seo.og_image` naming an
 * image that was no longer in the library.
 *
 * ⚠ THE OWNER WAS TRAPPED, AND THAT IS WHAT MAKES IT MORE THAN A MESSAGE PROBLEM. The same
 * validation gates the preview, so they could not see the page either, and the thing they
 * needed to delete to fix it was the thing the refusal was about. His ruling: *"Answering
 * 'is this asset referenced?' needs the stored document's raw references, not a VALID
 * document."*
 *
 * The fixture plants exactly that state: a stored draft carrying an og_image that names an
 * asset which is NOT in the library, so `LandingDocument::fromStored()` raises.
 */
function plantBrokenSharingImage(string $tenantId, string $keepAssetId): void
{
    $document = LandingFixtures::fullPage($keepAssetId);

    // ⚠ THROUGH THE REAL PATH FIRST, which creates the row. Without this the UPDATE below
    // matches nothing, the draft stays empty, and all three cases pass having planted
    // nothing — the vacuous shape, and it is how the first version of this helper failed.
    (new LandingPages)->saveDraft($tenantId, $document);

    $document['seo']['og_image'] = (string) Str::uuid7();

    // Written past `saveDraft()` on purpose: the panel cannot CREATE this state, and that is
    // not the same as the state not existing — an asset deleted by another path, or a
    // restore, leaves exactly this behind. The defect is about what the panel does when it
    // MEETS it.
    withTenant($tenantId, static function () use ($document): void {
        DB::connection('pgsql_owner')
            ->table('landing_pages')
            ->update(['draft_document' => json_encode($document, JSON_THROW_ON_ERROR)]);
    }, 'pgsql_owner');
}

it('deletes an unreferenced image even when another field of the page is invalid', function (): void {
    $tenant = $this->makeTenant();
    $library = new MediaLibrary;

    $used = $library->store($tenant, 'used.png', ImageFixtures::png());
    $spare = $library->store($tenant, 'spare.png', ImageFixtures::png());

    plantBrokenSharingImage($tenant, $used);

    // The planted state is real: the document genuinely does not validate.
    expect(static fn () => (new LandingPages)->draft($tenant))
        ->toThrow(LandingDocumentInvalid::class);

    // ⚠ AND THE DELETE GOES THROUGH. `$spare` is referenced by nothing, which is a question
    // about the stored bytes and not about whether the page would publish.
    $library->delete($tenant, $spare);

    expect($library->count($tenant))->toBe(1);
});

it('still refuses to delete an image the broken page references, and names where', function (): void {
    $tenant = $this->makeTenant();
    $library = new MediaLibrary;

    $used = $library->store($tenant, 'used.png', ImageFixtures::png());

    plantBrokenSharingImage($tenant, $used);

    /*
     * The safe direction is kept — this is the half the old coupling got right, and losing
     * it while fixing the other half would be the worse trade: an asset the page points at
     * must not vanish because the page is temporarily unreadable.
     */
    $message = null;

    try {
        $library->delete($tenant, $used);
    } catch (MediaRejected $e) {
        $message = $e->getMessage();
    }

    expect($message)->not->toBeNull()
        ->and($library->count($tenant))->toBe(1);

    // And it NAMES a place rather than reporting that the page cannot be read.
    expect($message)->not->toBe(__('landing.media.page_unreadable'))
        ->and($message)->toBe(__('landing.media.in_use_by_block', [
            'block' => __('studio.blocks.hero'),
        ]));
});

it('names the sharing image when that is what holds the reference', function (): void {
    $tenant = $this->makeTenant();
    $library = new MediaLibrary;

    $shared = $library->store($tenant, 'shared.png', ImageFixtures::png());

    // A document that is otherwise fine, whose og_image is this asset.
    $document = LandingFixtures::fullPage(null);

    (new LandingPages)->saveDraft($tenant, $document);

    $document['seo']['og_image'] = $shared;

    withTenant($tenant, static function () use ($document): void {
        DB::connection('pgsql_owner')
            ->table('landing_pages')
            ->update(['draft_document' => json_encode($document, JSON_THROW_ON_ERROR)]);
    }, 'pgsql_owner');

    $message = null;

    try {
        $library->delete($tenant, $shared);
    } catch (MediaRejected $e) {
        $message = $e->getMessage();
    }

    // ⚠ THE SHARING IMAGE USED TO BE UNNAMEABLE: it is not in a block and it is not the
    // logo, so the old path fell through both checks and let the delete happen — which is
    // how a published page ends up sharing a 404. `§12.2` wants the field named.
    expect($message)->toBe(__('landing.media.in_use_as_sharing_image'))
        ->and($library->count($tenant))->toBe(1);
});
