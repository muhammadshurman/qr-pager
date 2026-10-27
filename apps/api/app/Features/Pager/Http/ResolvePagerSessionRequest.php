<?php

declare(strict_types=1);

namespace App\Features\Pager\Http;

// INTERNAL NOTES -- LINE COMMENTS ON PURPOSE, SO THEY ARE NOT PUBLISHED.
//
// Scramble ships a FormRequest class's DOCBLOCK verbatim as the `description` of its
// schema in docs/openapi.json. Line comments are invisible to it; a /** */ block is not.
// That is R-052's mechanism -- found there on a CONTROLLER docblock, where it put the shed
// threshold `0.9 x app_limit` into the committed spec -- and it applies to request classes
// just the same, which this file is here to not rediscover. The published docblock below
// says only what a client developer needs.
//
// Why this class exists at all, which is the part that is NOT public API text: everything
// it needs is already on PresentedTokenRequest, so it adds no rule. It is a named type so
// that the route, the controller signature and the generated schema each say which body
// this endpoint takes, rather than three endpoints sharing one class and therefore one
// description. It is also the one customer route that reaches Postgres -- the resolve is
// PagerTokenLookup, a real round trip -- so a rule added to the two subscription bodies
// cannot drift onto the budget-critical poll body by sharing a parent that is concrete.

/**
 * The body for opening a pager session: the code from the QR scan, and nothing else.
 */
final class ResolvePagerSessionRequest extends PresentedTokenRequest {}
