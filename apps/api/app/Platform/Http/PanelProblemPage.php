<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * The HTML form of a problem document, for the panel screens only — `QRPAGER-23 §6.1a`.
 *
 * ================================================================================
 * WHY THIS EXISTS, MEASURED
 * ================================================================================
 *
 * `§6`'s third column asks a *source down* screen to say "that it is us, not them, and
 * whether to wait or retry". The walk drove all seven panel screens with the database
 * unreachable and measured the answer: **503, `application/problem+json`, 157–172 bytes** —
 * an RFC 7807 document, correct for `/v1` and **raw JSON in a restaurant owner's browser**.
 * The title said it was us; nothing said whether to wait.
 *
 * ================================================================================
 * ⚠ WHY THIS IS SAFE, WHICH IS THE ONLY INTERESTING QUESTION ABOUT IT
 * ================================================================================
 *
 * `ProblemRenderer` is registered for ALL requests deliberately, and the reason is written
 * in its docblock: the clause is *"no error response body carries a database role name, a
 * constraint name, SQL or a bound parameter, at any APP_DEBUG setting"*, and **HTML was the
 * worst offender** — measured at `7f1c8f6`, a `QueryException` rendered as HTML was
 * **862,104 bytes** carrying the full statement and the bound uuid. So adding an HTML branch
 * is touching the control that exists to prevent exactly that.
 *
 * **THE PROPERTY THAT MAKES IT SAFE: this class never sees the throwable.** It takes a
 * `Problem`, which is already the sanitised document — a `ProblemType` case, a path, and
 * nothing else — and the view takes four scalars from it. There is no code path from an
 * exception to this page, so there is nothing to leak and nothing to redact. `PanelWalkTest`
 * drives nine disclosure needles over the rendered HTML at `APP_DEBUG=true` rather than
 * trusting that argument.
 *
 * **AND IT INTRODUCES NO NEW DISTINCTION.** `§4.2`: a refusal may say what to do, never what
 * exists. The title is the enum's own fixed string, rendered verbatim. The "what to do"
 * sentence is chosen by STATUS CLASS — one for 5xx, one for 4xx — never by reason, so a 404
 * and a 403 that already read alike still read alike. A per-case sentence is how a friendly
 * error page becomes an oracle, and that is why there is not one.
 */
final class PanelProblemPage
{
    /**
     * Does this request want the HTML form?
     *
     * TWO conditions, and both are needed. The path must belong to a Filament panel —
     * derived from the registered panels, never a literal, so a panel added later is
     * covered with no edit here — and the client must prefer HTML. An API client that
     * happens to send `Accept: text/html` on `/v1` still gets the document, because `/v1`
     * is not a panel path; and a Livewire request on a panel path gets the document,
     * because it asks for JSON.
     */
    public function wants(Request $request): bool
    {
        // ⚠ WRAPPED, AND THE WRAP IS THE WHOLE SAFETY ARGUMENT'S FOUNDATION. This runs INSIDE
        // the exception renderer: a throwable escaping here escapes to Laravel's own
        // renderer, which at APP_DEBUG=true is the 862,104-byte shape `ProblemRenderer`
        // exists to prevent. `Filament::getPanels()` reads a registry that may not have
        // booted -- an exception raised before the panel providers run reaches this line.
        // So the control that must never fail cannot fail here: anything unexpected means
        // "serve the document", which is always safe.
        try {
            return $this->wantsPage($request);
        } catch (Throwable) {
            return false;
        }
    }

    private function wantsPage(Request $request): bool
    {
        if ($request->expectsJson()) {
            return false;
        }

        if (! $request->acceptsHtml()) {
            return false;
        }

        // ⚠ A REAL DOCUMENT NAVIGATION, AND NOT MERELY "ACCEPTS HTML". `Accept: */*` ACCEPTS
        // html, so `acceptsHtml()` says yes to an image request, a stylesheet request and
        // `fetch()` -- and `expectsJson()` only refuses the subset that sets
        // `X-Requested-With`, which `fetch()` never does. Measured: a browser's own
        // `Accept: image/avif,…,image/*,*/*;q=0.8` for `/studio/preview/asset/<uuid>`
        // resolved to the PAGE, so a binary endpoint answered a 2 kB HTML document on its
        // error path -- and that endpoint's SUCCESS path deliberately carries
        // `default-src 'none'`, `nosniff`, `DENY` and `no-referrer` by an earlier §11
        // ruling, none of which the page carried.
        //
        // `Sec-Fetch-Dest` is the browser's own statement of what the response is FOR, it is
        // a forbidden header name so a page cannot forge it, and every engine this project
        // targets sends it. Absent (curl, an old client), the answer is the document: the
        // machine-readable form is the safe default for anything that did not say it was
        // navigating.
        $destination = (string) $request->headers->get('Sec-Fetch-Dest', '');
        $mode = (string) $request->headers->get('Sec-Fetch-Mode', '');

        if ($destination !== 'document' && $mode !== 'navigate') {
            return false;
        }

        $path = '/'.ltrim($request->path(), '/');

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

    /**
     * The page, carrying the problem's own title and status and nothing else.
     *
     * ⚠ THE HEADERS ARE THE PROBLEM'S, NOT A FRESH SET. The first version built a response
     * carrying only `Content-Type`, which silently dropped two things
     * `Problem::toResponse()` sets:
     *
     *   - `Retry-After`, chosen PER SQLSTATE by `DatabaseErrorMapper` (R-070: a lock is 1s,
     *     a connection failure or a saturated pool is 10s). Measured: the document carried
     *     `retry-after: 10` and the page carried none, so the panel's outage page was the one
     *     503 on this surface that told no machine client to back off -- during exactly the
     *     condition where a retry storm is the cascade §4 › Edge & ops exists to prevent.
     *   - `Cache-Control: no-store`, downgraded to Laravel's default `no-cache`, which
     *     permits storage with revalidation.
     *
     * AND IT ADDS WHAT AN HTML DOCUMENT ON THIS ORIGIN NEEDS. An earlier §11 review required
     * `default-src 'none'` + `nosniff` + frame denial on `PreviewController`'s binary
     * responses precisely because they are served from the origin carrying the `/platform`
     * and `/studio` session cookies. This is a new HTML document on that origin; the same
     * reasoning applies, and `style-src 'unsafe-inline'` is all it needs because its only
     * asset is the inline `<style>` block.
     */
    public function respond(Problem $problem): SymfonyResponse
    {
        // ⚠ WRAPPED, FOR THE REASON `wants()` IS. This runs inside the exception renderer and
        // does strictly MORE than `wants()` — fifteen `config()` reads, a view resolve and a
        // Blade compile — and it carried no guard. Measured by moving the view aside and
        // driving a real navigation: `HTTP/1.1 500`, a **0-byte body**, and none of the six
        // headers, because the throwable escaped to `handleShutdown` and the sanitised
        // Problem was discarded. Any partial deploy, unwritable `storage/framework/views` or
        // bad cache state turns every panel error — including the 503 this feature exists
        // for — into a bodyless 500 with no retry hint.
        //
        // The fallback is the document, which needs no view and is what every non-navigation
        // client already gets.
        try {
            return $this->renderPage($problem);
        } catch (Throwable) {
            return $problem->toResponse();
        }
    }

    private function renderPage(Problem $problem): Response
    {
        $status = $problem->type->status();

        $headers = [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            // No script, no external asset, no framing, no form, no base rewrite.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; "
                ."base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
        ];

        if ($problem->retryAfter !== null) {
            $headers['Retry-After'] = (string) $problem->retryAfter;
        }

        return response()->view('errors.panel-problem', [
            'title' => $problem->type->title(),
            'status' => $status,
            'guidance' => $this->guidance($status),
            'brand' => (string) config('app.brand'),
        ], $status, $headers);
    }

    /**
     * What to do, by STATUS CLASS and never by reason.
     *
     * Two sentences for the whole surface. That is deliberate: `§4.2`'s rule is that a
     * refusal may say what to do and may not say what exists, and a sentence per
     * `ProblemType` case would be a per-reason answer — which is precisely how "no such
     * session" and "not your session" stop being the same message. The enum's own title
     * already carries whatever distinction is safe to make.
     */
    private function guidance(int $status): string
    {
        if ($status >= 500) {
            return 'This is on our side, not yours. Nothing you did caused it and nothing is lost. '
                .'Wait a minute and reload this page.';
        }

        return 'If you think you should be able to see this, ask whoever set up your account '
            .'to check it. Going back and trying again will not change the answer.';
    }
}
