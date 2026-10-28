<?php

use App\Features\Landing\LandingServiceProvider;
use App\Features\Notifications\NotificationsServiceProvider;
use App\Features\Provisioning\Panel\PlatformPanelProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    // Task 4's feature wiring. A provider of its own rather than two more lines in
    // AppServiceProvider, because deptrac's rule 2 forbids the Platform layer from
    // importing a feature module -- see NotificationsServiceProvider for the argument.
    NotificationsServiceProvider::class,
    // Task 6's Filament panel, and it lives in app/Features for exactly the same reason:
    // the panel's resources reach TenantProvisioning, so a provider under app/Providers
    // would be a Platform -> Features edge and deptrac's rule 2 forbids it.
    //
    // ⚠ `php artisan filament:install --panels` generated App\Providers\Filament\
    // AdminPanelProvider and REWROTE this file, dropping both comments above and
    // reordering the list. The generated provider was deleted and this file restored by
    // hand. Worth knowing before anyone runs that installer again.
    PlatformPanelProvider::class,
    // Task 7's feature wiring: the two lifecycle ports and which driver each resolves to
    // (QRPAGER-14 decision D7). A feature provider rather than two more lines in
    // AppServiceProvider, for the reason NotificationsServiceProvider is one -- deptrac's
    // rule 2 forbids the Platform layer from importing a feature module.
    LandingServiceProvider::class,
];
