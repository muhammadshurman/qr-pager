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

    public function summary(): PlatformSummary
    {
        return app(PlatformDashboard::class)->summary();
    }
}
