<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Illuminate\Http\Request;

/**
 * Does this route owe its caller a `poll_after_seconds`, and what is it?
 *
 * ===================================================================================
 * WHY THIS EXISTS AT ALL -- QA `M-3` and `M-4`, 2026-10-27
 * ===================================================================================
 *
 * `PLAN.md §3` row 4: *"every polling response carries `poll_after_seconds` and every
 * 503 and 429 carries `Retry-After`"*. Muhammad's ruling on QA `M-6` was CARRY THE
 * VALUE, DO NOT NARROW THE GATE -- and the first round of that fix carried it on two of
 * the FIVE response shapes the polling route can produce. The other three are not the
 * controller's: `ShedLoad` answers 503 before the handler runs and `RateLimit` answers
 * 429 before that, and both were sending a bare problem document. A client that is shed
 * or throttled is, if anything, the one that most needs telling when to come back.
 *
 * ⚠ AND THE SPEC WAS PUBLISHING A MEMBER ONE OF THEM OMITTED (`M-4`). `docs/openapi.json`
 * declared `PacedProblemDocument` -- with `poll_after_seconds` REQUIRED -- for that
 * route's 503, while `ShedLoad`'s 503 sent a plain `ProblemDocument` under the same
 * media type. That is `R-033`'s "one producer, two readers" broken for one status: the
 * document promised a member a real response did not carry.
 *
 * ===================================================================================
 * WHY A ROUTE DEFAULT AND NOT A ROUTE NAME
 * ===================================================================================
 *
 * Both middlewares are generic and mounted across the whole API, so they must not know
 * what a pager poll is. A hardcoded route name in middleware is the failure mode
 * `AppServiceProvider` already names about the customer path: a second hand-maintained
 * list that the person adding a route forgets to edit. The route declares its own
 * pacing instead -- `->defaults(PacedRoute::KEY, true)` sits in `routes/customer.php`
 * beside the handler it describes -- and the middlewares ask this class.
 *
 * The VALUE is never a route default: it is read from `config('pager.poll_interval')`,
 * the same single source the 200 and the controller's own refusals use, so the three
 * cannot drift.
 */
final class PacedRoute
{
    /** The route default that marks a route as owing its caller a pacing hint. */
    public const string KEY = 'paced';

    /**
     * The interval to advertise on this request's response, or null if it owes none.
     */
    public static function intervalFor(Request $request): ?int
    {
        $route = $request->route();

        if ($route === null || ($route->defaults[self::KEY] ?? false) !== true) {
            return null;
        }

        return max(1, (int) config('pager.poll_interval'));
    }
}
