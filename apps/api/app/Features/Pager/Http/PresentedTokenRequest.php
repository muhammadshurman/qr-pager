<?php

declare(strict_types=1);

namespace App\Features\Pager\Http;

use App\Domain\Pager\PagerToken;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The base of every customer request: a QR token, in the BODY, never in the URL.
 *
 * WHY THE BODY, AND WHY THESE ROUTES ARE ALL POST -- including the polling one, which
 * reads rather than writes and would otherwise be a GET. `PLAN.md §3` row 3 as amended on
 * 2026-10-27 requires that the raw token reach "no log, no URL path or query string, no
 * error body, [and] nowhere a server receives or logs it". A request body is the only
 * place on an HTTP request that is in none of: the request line, an access log, a
 * `Referer` header, a proxy's URL trace, or a CDN cache key. A header is better than a
 * URL and still worse than a body -- plenty of gateways log headers by name. So the
 * polling endpoint pays an unusual verb to keep the highest-frequency carrier of this
 * secret off every one of those surfaces, and that trade is recorded rather than hidden.
 *
 * It also means the poll response is uncacheable by any shared cache, which it already
 * was: it is per-session data behind a per-session secret, so no cache could serve a
 * second customer from it anyway. `§4 › Realtime`'s "short-TTL cache headers" is answered
 * by `poll_after_seconds` in the body, which is the value a client paces itself by, and
 * the response carries `Cache-Control: no-store`.
 *
 * VALIDATION IS THE SHAPE AND NOTHING MORE. 22 base64url characters, checked by
 * PagerToken::isWellFormed() rather than by a regex repeated here -- one source, and the
 * one that also produces the hash. A malformed token is refused before anything is hashed
 * and before any statement runs, which is what makes a malformed-token loop cost zero
 * round trips.
 *
 * authorize() returns true and that is not the authorization decision. These routes are
 * deliberately NOT on RequireTenantScope (`§4 › Tenancy`: the token IS the customer's
 * authentication, and a customer has no account to resolve a tenant from). The decision
 * is made by whether the token resolves, which happens in the controller because its
 * three outcomes -- resolves, expired, unknown -- are three different HTTP answers and a
 * boolean cannot carry them.
 */
abstract class PresentedTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:'.PagerToken::LENGTH],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            // Deliberately says nothing about the alphabet or the entropy: a validation
            // message on a public, unauthenticated surface is a free hint about the
            // shape of the secret.
            'token.size' => 'That is not a valid pager link.',
            'token.required' => 'That is not a valid pager link.',
        ];
    }

    /**
     * The presented token, or null when it is the right length and the wrong alphabet.
     *
     * `size:22` and PagerToken's own regex are not the same test -- 22 characters of
     * anything passes the first. The second is the one that decides, and it lives in the
     * domain object beside the hash it feeds.
     */
    public function token(): ?PagerToken
    {
        /** @var string $presented */
        $presented = $this->validated()['token'];

        return PagerToken::fromPresented($presented);
    }
}
