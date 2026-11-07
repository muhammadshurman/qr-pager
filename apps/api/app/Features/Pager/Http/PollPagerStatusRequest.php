<?php

declare(strict_types=1);

namespace App\Features\Pager\Http;

// INTERNAL NOTES -- LINE COMMENTS ON PURPOSE, SO THEY ARE NOT PUBLISHED (R-052's
// mechanism; see ResolvePagerSessionRequest for the full note).
//
// Same body as the scan, a separate type for the same reason, and one more that matters
// here: this is the route whose per-request cost is the design's tightest budget -- the
// C6 ceiling of 300 000 concurrent sessions at a 7-second interval is ~42 900 polls per
// second, against two Redis reads and no Postgres. A validation rule added to the
// subscription bodies must not be able to drift onto this one by inheritance, so the
// shared parent is abstract and each concrete body is named.

/**
 * The body for reading a session's live status: the code from the QR scan, and nothing else.
 */
final class PollPagerStatusRequest extends PresentedTokenRequest {}
