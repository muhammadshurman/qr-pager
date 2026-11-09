<?php

declare(strict_types=1);

namespace App\Platform\Http;

use App\Domain\Http\ProblemType;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * A signed-out BROWSER on a panel path is sent to the sign-in screen — `R-253`.
 *
 * ================================================================================
 * WHAT WAS MEASURED, AND WHY IT IS A DEFECT RATHER THAN A STYLE
 * ================================================================================
 *
 * `/studio` and `/platform` have no `->login()` on purpose (`§4 › Tenancy`: two
 * authentication surfaces, never three — each panel provider's docblock argues it). The
 * panels authenticate nobody; they only refuse. Measured signed out on 2026-11-09, with a
 * real navigation's own headers:
 *
 *     GET /studio      Sec-Fetch-Dest: document   ->  401, text/html, 1,990 bytes
 *     GET /platform    Sec-Fetch-Dest: document   ->  401, text/html, 1,993 bytes
 *
 * — `PanelProblemPage`, titled *"Authentication is required."*, with no link, no form and
 * nothing to press. It is an accurate answer to a machine and a dead end to the restaurant
 * owner whose job is on the other side of it, who has no way to learn that the way in is a
 * different application at a different port.
 *
 * ================================================================================
 * ⚠ THE THREE CONDITIONS, AND EVERY ONE OF THEM IS LOAD-BEARING
 * ================================================================================
 *
 * **1. The refusal must be `Unauthenticated` and never `Forbidden`.** A 403 means a person
 * IS signed in and this panel is not theirs — `TenantPanelAccess` for `/studio`, the
 * platform-admin flag for `/platform`. Redirecting that to sign-in sends somebody who is
 * already authenticated to a form they will complete successfully and bounce off again: an
 * infinite loop that looks like a broken login. `§4.2`'s *a refusal may say what to do,
 * never what exists* is satisfied either way; the loop is the reason, not disclosure.
 *
 * **2. It must be a real document navigation, which is `PanelProblemPage::wants()`'s own
 * test and is reused rather than re-derived.** That method already answers *"is this a
 * browser, navigating, to a path belonging to a registered panel"* — `Sec-Fetch-Dest`
 * rather than `Accept`, for the reason its docblock measures: a star-slash-star `Accept`
 * accepts HTML (spelled out rather than written, since it would close this comment), so a
 * `fetch()`, a stylesheet and an image request all claim to want a page. A second
 * implementation of that question is a second thing to keep true.
 *
 * **3. `/v1` is untouched, and that follows from (2) rather than from a special case.**
 * `/v1` is not a panel path, so `wants()` is false for every API request whatever it sends,
 * and an API client's 401 problem document is byte-for-byte what it was. A Livewire request
 * on a panel path `expectsJson()`, so it keeps the document too — which is what Livewire's
 * own client can act on.
 *
 * ================================================================================
 * NO `?next=`, AND A LOOP GUARD
 * ================================================================================
 *
 * The target carries no return path. `/staff` has nowhere to put one — it signs a person
 * into the staff shell, not into a panel — and a parameter nothing reads is a promise to
 * come back that is not kept. It is also the shape an open redirect arrives in, and the
 * only URL this class can emit is the configured one: nothing from the request reaches it.
 *
 * The loop guard is for a MISCONFIGURATION, not for an attack: a `STAFF_SIGNIN_URL` whose
 * host is this one and whose path is itself a panel path would redirect a signed-out
 * browser to a signed-out panel, for as long as the browser is willing. That is a one-line
 * `.env` away and it fails on a shared counter terminal rather than in a test, so the
 * configured URL is refused and the dead-end page is served instead — which is bad, and
 * finite.
 */
final class PanelSignInRedirect
{
    public function __construct(private readonly PanelProblemPage $page) {}

    /**
     * The redirect, or null when this request is not one to redirect.
     *
     * ⚠ WRAPPED, for `PanelProblemPage::wants()`'s own reason and it is the same risk. This
     * runs INSIDE the exception renderer, where an escaping throwable reaches Laravel's own
     * handler — at `APP_DEBUG=true` the 862,104-byte page carrying the SQL that
     * `ProblemRenderer` exists to prevent. `Filament::getPanels()` and `config()` are both
     * reachable before the providers have booted. So anything unexpected means "no
     * redirect", which leaves the behaviour exactly as it was before this class existed.
     */
    public function for(Problem $problem, Request $request): ?RedirectResponse
    {
        try {
            return $this->redirect($problem, $request);
        } catch (Throwable) {
            return null;
        }
    }

    private function redirect(Problem $problem, Request $request): ?RedirectResponse
    {
        if ($problem->type !== ProblemType::Unauthenticated) {
            return null;
        }

        if (! $this->page->wants($request)) {
            return null;
        }

        $target = config('surfaces.staff_signin_url');

        if (! is_string($target) || trim($target) === '') {
            return null;
        }

        $target = trim($target);

        if ($this->wouldLoop($target, $request)) {
            return null;
        }

        /*
         * 302 AND NOT 301: a permanent redirect is cacheable forever by every intermediary,
         * and this one is true only while the person is signed out. `no-store` for the same
         * reason, and more sharply on `/staff`'s own hardware — these panels are opened from
         * the same shared counter devices `§12` is written for, and a stored redirect would
         * send the next person's panel visit to sign-in after they had signed in.
         */
        return new RedirectResponse($target, 302, [
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /**
     * Would this target send a signed-out browser straight back to a signed-out panel?
     *
     * Same host (or no host at all, a relative value) AND a path under a registered panel.
     * Either alone is fine: another host's `/studio` is another application's problem, and
     * this host's `/staff` is the intended answer.
     */
    private function wouldLoop(string $target, Request $request): bool
    {
        $parts = parse_url($target);

        if ($parts === false) {
            return true;
        }

        $host = $parts['host'] ?? null;

        if (is_string($host) && strcasecmp($host, $request->getHost()) !== 0) {
            return false;
        }

        $path = '/'.ltrim((string) ($parts['path'] ?? ''), '/');

        foreach (Filament::getPanels() as $panel) {
            $prefix = '/'.trim($panel->getPath(), '/');

            if ($prefix === '/') {
                continue;
            }

            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
