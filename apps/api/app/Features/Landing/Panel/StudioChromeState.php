<?php

declare(strict_types=1);

namespace App\Features\Landing\Panel;

use App\Features\Landing\LandingPages;

/**
 * The page state the chrome reads, fetched ONCE per request. `QRPAGER-48` item 2.
 *
 * ================================================================================
 * ⚠⚠ THIS EXISTS BECAUSE A `§6` ANSWER WAS FALSIFIED, AND TO STOP IT GETTING WORSE
 * ================================================================================
 *
 * `§6` question 5 claimed the status plate would reuse the form's existing read. It does
 * not: `LandingPages::state()` and `LandingPages::draft()` each call `row()` inside their own
 * `withTenant`, so the plate is a SECOND scoped read — `§2.11`'s four statements and two
 * round trips. `PLAN.md` carries that correction.
 *
 * The chrome then grew a second consumer, the footer's save state. Two regions reading the
 * same fact would have made it a THIRD read for one page view, which is the point at which a
 * corrected figure starts drifting again. `once()` is Laravel's own per-request memo, so the
 * chrome costs exactly one read however many regions ask.
 *
 * ⚠ **PER REQUEST AND NOT LONGER.** A publish or a save changes this state, and both happen
 * over Livewire within a request of their own — so the next render reads afresh. Caching
 * across requests would show an owner a plate that disagrees with what they just did, which
 * is the one failure a status indicator must not have.
 *
 * @phpstan-type ChromeState array{status: \App\Domain\Landing\LandingPageStatus, hostname: string, published_hostname: ?string, published_at: ?string, has_unpublished_changes: bool, blocks: int, invalid: ?string}
 */
final class StudioChromeState
{
    /** @return ChromeState */
    public static function of(string $tenantId): array
    {
        return once(static fn (): array => app(LandingPages::class)->state($tenantId));
    }
}
