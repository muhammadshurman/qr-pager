<?php

declare(strict_types=1);

use App\Platform\Http\Controllers\HealthController;
use App\Platform\Http\Controllers\ReadinessController;
use Illuminate\Support\Facades\Route;

/*
 * The two unversioned infrastructure endpoints.
 *
 * "Every public route is under /v1" (PLAN.md §3, row 2) governs the public API
 * surface. These two are deliberately outside it, approved on 2026-10-26, and the
 * reason is written into PLAN.md §6 with the versioning policy: a load balancer's
 * health-check path must not change because the API's major version did.
 *
 * They are on their own middleware group, not on 'api'. That is the mechanism behind
 * "while health and readiness keep answering" -- they carry no rate limiter, no tenant
 * middleware and no load shedding, so there is nothing that can shed them.
 *
 * Laravel's own /up endpoint was removed in the same change: it was the only other
 * public route, it duplicated liveness, and it runs the framework's health checks,
 * which touch dependencies -- the opposite of what §4 › Edge & ops asks a health check
 * to do.
 */

Route::middleware('probe')->group(function (): void {
    Route::get('/healthz', HealthController::class)->name('probe.health');
    Route::get('/readyz', ReadinessController::class)->name('probe.readiness');
});
