<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantLanguage;
use App\Features\Landing\LandingPages;
use App\Features\Landing\LanguageNotChanged;
use App\Features\Landing\MediaLibrary;
use App\Features\Landing\PublishedSite;
use App\Features\Landing\TenantPageLanguage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\LandingFixtures;

/*
 * ================================================================================
 * `QRPAGER-30`'s NAMED INVARIANT, DRIVEN. `R-132` and `R-221`.
 * ================================================================================
 *
 * *"A tenant's published page declares that tenant's own language and the direction derived
 * from it, on the document element, and no other value can reach either attribute."*
 *
 * Four clauses, each a case below. The fifth -- the direction never being read from storage --
 * is in `TenantLanguageContractTest`, because it is a statement about the SCHEMA rather than
 * about a rendered page.
 *
 * ⚠ **THE BYTES, NOT THE RENDERER'S RETURN VALUE.** Every clause that is about a published
 * page reads the object back out of the store through `PublishedSite::pointer()`. `§6.6`'s
 * publish is write-new-then-switch and the pointer's write IS the switch, so the only honest
 * subject for *"a tenant's published page declares"* is what the pointer serves.
 */

beforeEach(fn () => LandingFixtures::bucket());

/** The language of the page the pointer currently serves, read out of the stored bytes. */
function publishedDocumentAttributes(string $tenantId): array
{
    $published = (new LandingPages)->publish($tenantId);
    $bytes = (string) MediaLibrary::disk()->get(PublishedSite::pointer($published->hostname));

    preg_match('/<html lang="([a-z-]*)" dir="([a-z]*)"/', $bytes, $matches);

    return ['lang' => $matches[1] ?? null, 'dir' => $matches[2] ?? null, 'bytes' => $bytes];
}

it('publishes each language with the direction derived from it, and never the other one', function (): void {
    // ⚠ BOTH DIRECTIONS AND BOTH NEGATIVES. Asserting `ar` gives `rtl` leaves a renderer that
    // emits `rtl` unconditionally passing, which is the shape R-132 was: a hard-coded pair
    // that happened to be right for one language.
    $arabic = $this->makeTenant(slug: 'zaytoun');
    $english = $this->makeTenant(slug: 'olive-branch');

    (new TenantPageLanguage)->set($arabic, TenantLanguage::Arabic);
    (new TenantPageLanguage)->set($english, TenantLanguage::English);

    foreach ([$arabic, $english] as $tenantId) {
        (new LandingPages)->saveDraft($tenantId, LandingFixtures::fullPage());
    }

    $ar = publishedDocumentAttributes($arabic);
    $en = publishedDocumentAttributes($english);

    expect($ar['lang'])->toBe('ar')
        ->and($ar['dir'])->toBe('rtl')
        ->and($en['lang'])->toBe('en')
        ->and($en['dir'])->toBe('ltr');

    // Neither page carries the other's pair anywhere in its bytes, not merely on `<html>`.
    expect($ar['bytes'])->not->toContain('dir="ltr"')
        ->and($en['bytes'])->not->toContain('dir="rtl"');
});

it('gives a tenant who has never touched the setting ar, from the column and not from code', function (): void {
    // `makeTenant` INSERTS no `language` -- it writes id, name and slug and nothing else -- so
    // this tenant is exactly the "restaurant that does nothing" the ruling is about, and the
    // value can only have come from the column's own DEFAULT.
    $tenant = $this->makeTenant(slug: 'untouched');

    $stored = withTenant($tenant, static fn (): ?string => DB::table('tenants')->value('language'));

    (new LandingPages)->saveDraft($tenant, LandingFixtures::fullPage());

    expect($stored)->toBe('ar', 'the column default did not reach a row inserted without it')
        ->and(publishedDocumentAttributes($tenant))
        ->toMatchArray(['lang' => 'ar', 'dir' => 'rtl']);
});

it('refuses a third language at the DATABASE, as the app role, and not only in validation', function (): void {
    // ⚠ AS THE APP ROLE. The point of the clause is that the refusal does not depend on the
    // application: a write arriving by any path this role can take is refused by the server.
    //
    // ⚠ AND THE CODE IS `22P02`, NOT `23514`. The ticket's own clause asks for a
    // check_violation because PLAN §1's ruling spelled the column as `varchar(2)` + CHECK; it
    // is a native enum type instead (see TenantLanguage's docblock and QRPAGER-30 comment
    // 10108 for the §6 Q1 answer that decided it), and a native enum refuses an unknown label
    // as invalid_text_representation. The PROPERTY is identical and is what is asserted.
    $tenant = $this->makeTenant(slug: 'third-value');

    $code = null;

    try {
        withTenant($tenant, static fn () => DB::table('tenants')->update(['language' => 'fr']));
    } catch (QueryException $e) {
        $code = $e->getCode();
    }

    expect($code)->toBe('22P02', 'the database accepted a language that is not a launch language');

    // The row is unchanged, which is the half an exception alone does not prove.
    expect(withTenant($tenant, static fn (): ?string => DB::table('tenants')->value('language')))
        ->toBe('ar');
});

it('cannot be set on another restaurant, because the write is inside one tenant scope', function (): void {
    // The standing non-negotiable (`§0.0e`). `tenants` carries FORCE RLS, so an out-of-scope
    // UPDATE affects ZERO rows and raises nothing at all -- which is why `set()` checks the
    // affected count and throws. Without that check this would pass silently and the
    // restaurant would be told its language was saved.
    $alpha = $this->makeTenant(slug: 'alpha-lang');
    $beta = $this->makeTenant(slug: 'beta-lang');

    (new TenantPageLanguage)->set($beta, TenantLanguage::English);

    // Bind alpha's scope and aim the write at beta's row: the only id `set()` accepts is the
    // one it binds, so the attempt is made by binding alpha and updating inside it.
    $affected = withTenant($alpha, static fn (): int => DB::table('tenants')
        ->where('id', $beta)
        ->update(['language' => 'ar']));

    expect($affected)->toBe(0, 'one tenant reached another tenant row')
        ->and((new TenantPageLanguage)->current($beta))->toBe(TenantLanguage::English);
});

it('reports a refusal rather than success when no row was updated', function (): void {
    // The positive control for the clause above: `set()` must actually be capable of throwing,
    // or `$affected !== 1` is an unreachable branch that proves nothing. Driven against an id
    // that has no row -- the shape an out-of-scope write takes under FORCE RLS.
    expect(fn () => (new TenantPageLanguage)->set((string) Str::uuid7(), TenantLanguage::English))
        ->toThrow(LanguageNotChanged::class);
});
