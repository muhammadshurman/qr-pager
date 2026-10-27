<?php

use App\Features\Notifications\NotificationsServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    // Task 4's feature wiring. A provider of its own rather than two more lines in
    // AppServiceProvider, because deptrac's rule 2 forbids the Platform layer from
    // importing a feature module -- see NotificationsServiceProvider for the argument.
    NotificationsServiceProvider::class,
];
