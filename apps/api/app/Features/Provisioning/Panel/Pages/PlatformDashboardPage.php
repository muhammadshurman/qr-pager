<?php

declare(strict_types=1);

namespace App\Features\Provisioning\Panel\Pages;

use App\Features\Provisioning\Panel\PlatformDashboard;
use App\Features\Provisioning\Panel\PlatformSummary;
use Filament\Pages\Page;

/**
 * The platform dashboard. Reads `PlatformDashboard`, which reads platform tables only.
 *
 * A PLAIN PAGE AND NOT Filament's Dashboard CLASS, because that one's value is widget
 * discovery and this panel registers no widgets: the generator's two defaults were dropped
 * (one of them renders the installed Filament version to anyone who can see the page) and a
 * widget per tile would be a query per tile, which is the N+1 shape `§11` asks about. One
 * page, one summary object, two statements.
 */
class PlatformDashboardPage extends Page
{
    protected string $view = 'platform.dashboard';

    protected static ?string $title = 'Platform';

    protected static ?string $slug = '/';

    protected static ?int $navigationSort = -1;

    /**
     * Platform admins only — and this method's ABSENCE was security M-1.
     *
     * Filament re-authorises a RESOURCE on every Livewire hydration (`canViewAny()` is
     * called from both `mount…` and `hydrate…`), but a custom PAGE inherits
     * `CanAuthorizeAccess::canAccess()`, whose own comment reads *"Custom pages default to
     * allowing access for all authenticated panel users."* Measured at `263bb16`:
     *
     *     PlatformDashboardPage::canAccess() with NO user:   true
     *     PlatformDashboardPage::canAccess() as a non-admin: true
     *     non-admin rendered the component, html length=12284
     *
     * The HTTP route was never the hole — `/platform` answers 401 anonymously. The hole was
     * `POST /livewire-<hash>/update`, which runs neither `SetUpPanel` nor Filament's
     * `Authenticate`, so for this one component the only remaining barrier was the
     * snapshot's HMAC — and a snapshot is signed with `APP_KEY` and bound to no user and no
     * session, so one leaked admin page is a permanent key to the platform-wide figures.
     *
     * `=== true`, matching `User::canAccessPanel()`: a strict comparison fails closed if the
     * column ever becomes nullable or string-typed.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->getAttribute('is_platform_admin') === true;
    }

    public function summary(): PlatformSummary
    {
        return app(PlatformDashboard::class)->summary();
    }
}
