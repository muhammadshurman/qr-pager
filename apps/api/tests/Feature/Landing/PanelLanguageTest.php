<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantLanguage;
use App\Features\Landing\PanelLanguage;
use App\Features\Landing\TenantPageLanguage;

/**
 * ================================================================================
 * TWO LANGUAGES, TWO FACTS — `QRPAGER-48`
 * ================================================================================
 *
 * Muhammad's ruling of 2026-11-13: the PANEL's language belongs to the PERSON signed in, and
 * `tenants.language` keeps its own meaning — the language a CUSTOMER reads on the published
 * page. Before this ticket ONE column answered both, so a topbar toggle over it would have
 * let an owner change what their customers read by choosing what they themselves read.
 *
 * ⚠ THE LAST CASE IS THE ONE THAT WOULD HAVE CAUGHT THAT, and it is a NEGATIVE on purpose:
 * choosing a panel language must leave the published page's language exactly where it was.
 */
it('reads the panel in the restaurant\'s language when the person has chosen nothing', function (): void {
    $tenantId = $this->actingForTenant();
    $userId = (string) auth()->id();

    app(TenantPageLanguage::class)->set($tenantId, TenantLanguage::English);

    // No panel preference has been set, so the restaurant's language is what resolves --
    // which is EXACTLY the behaviour that shipped before `users.panel_language` existed.
    expect(app(PanelLanguage::class)->current($userId, $tenantId))->toBe(TenantLanguage::English);
});

it('prefers the person\'s own choice over the restaurant\'s', function (): void {
    $tenantId = $this->actingForTenant();
    $userId = (string) auth()->id();

    app(TenantPageLanguage::class)->set($tenantId, TenantLanguage::Arabic);
    app(PanelLanguage::class)->set($userId, TenantLanguage::English);

    expect(app(PanelLanguage::class)->current($userId, $tenantId))->toBe(TenantLanguage::English);
});

it('lets two people in ONE restaurant read different languages, which is the whole ruling', function (): void {
    $tenantId = $this->actingForTenant();
    $first = (string) auth()->id();

    // A second person in the SAME restaurant.
    $this->actingForTenant($tenantId);
    $second = (string) auth()->id();

    expect($first)->not->toBe($second);

    app(TenantPageLanguage::class)->set($tenantId, TenantLanguage::Arabic);
    app(PanelLanguage::class)->set($first, TenantLanguage::English);

    expect(app(PanelLanguage::class)->current($first, $tenantId))->toBe(TenantLanguage::English)
        ->and(app(PanelLanguage::class)->current($second, $tenantId))->toBe(TenantLanguage::Arabic);
});

it('clears back to following the restaurant when the choice is unset', function (): void {
    $tenantId = $this->actingForTenant();
    $userId = (string) auth()->id();

    app(TenantPageLanguage::class)->set($tenantId, TenantLanguage::Arabic);
    app(PanelLanguage::class)->set($userId, TenantLanguage::English);

    expect(app(PanelLanguage::class)->current($userId, $tenantId))->toBe(TenantLanguage::English);

    // `null` is a THIRD answer -- "not chosen" -- and is not the same as choosing Arabic.
    app(PanelLanguage::class)->set($userId, null);

    expect(app(PanelLanguage::class)->current($userId, $tenantId))->toBe(TenantLanguage::Arabic);

    app(TenantPageLanguage::class)->set($tenantId, TenantLanguage::English);

    // Still following, rather than frozen at the value it happened to resolve to.
    expect(app(PanelLanguage::class)->current($userId, $tenantId))->toBe(TenantLanguage::English);
});

it('NEVER changes the published page\'s language, which is the defect it closes', function (): void {
    $tenantId = $this->actingForTenant();
    $userId = (string) auth()->id();

    app(TenantPageLanguage::class)->set($tenantId, TenantLanguage::Arabic);

    // The planted state is asserted FIRST, so this case cannot pass on an empty premise.
    expect(app(TenantPageLanguage::class)->current($tenantId))->toBe(TenantLanguage::Arabic);

    app(PanelLanguage::class)->set($userId, TenantLanguage::English);

    // The person now reads English; the restaurant's customers still read Arabic. One column
    // for both would have made this assertion impossible to write.
    expect(app(PanelLanguage::class)->current($userId, $tenantId))->toBe(TenantLanguage::English)
        ->and(app(TenantPageLanguage::class)->current($tenantId))->toBe(TenantLanguage::Arabic);
});
