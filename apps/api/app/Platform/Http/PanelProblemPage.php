<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
        if ($request->expectsJson()) {
            return false;
        }

        if (! $request->acceptsHtml()) {
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

    /** The page, carrying the problem's own title and status and nothing else. */
    public function respond(Problem $problem): Response
    {
        $status = $problem->type->status();

        return response()
            ->view('errors.panel-problem', [
                'title' => $problem->type->title(),
                'status' => $status,
                'guidance' => $this->guidance($status),
                'brand' => (string) config('app.brand'),
            ], $status)
            ->header('Content-Type', 'text/html; charset=UTF-8');
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
