<?php

declare(strict_types=1);

namespace App\Features\Pager;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * ================================================================================
 * THE SCANNABLE HALF OF A PAGER SHEET. Task 10d.
 * ================================================================================
 *
 * `§4.2`'s staff inventory says *Create pager + print QR*, and until 10d nothing in this
 * repository drew one — `§4.3`'s correction records that the only `QrCode` hits in `app/` were
 * 2FA's, and `R-115` is the row for *QR batch generation + printable sheets, never delivered*.
 *
 * ================================================================================
 * WHY THE SERVER DRAWS IT AND THE BROWSER DOES NOT
 * ================================================================================
 *
 * The obvious place is the client: it already holds `qr_payload`, so drawing there costs no
 * round trip. It was rejected on a supply-chain ground rather than a technical one.
 *
 * **There is no QR encoder in the JavaScript workspace and adding one is a new dependency on
 * the surface that renders a credential.** A QR encoder is Reed-Solomon arithmetic and bit
 * packing — a few hundred lines nobody on this project would review line by line — and
 * `package.json` keeps its tree deliberately small (fifteen devDependencies, every one pinned
 * exact). A package that runs in the page that holds a live pager token is the worst place to
 * take an unreviewed transitive tree.
 *
 * **`bacon/bacon-qr-code` is already installed and already reviewed**, because Fortify
 * requires it for `twoFactorQrCodeSvg()`. 10d PROMOTED it to a direct dependency of
 * `apps/api/composer.json` rather than leaning on that: a transitive dependency is one another
 * package may drop at its next minor, and a printed sheet is not a thing to lose that way.
 * Nothing else in the lock moved — measured, only `content-hash`.
 *
 * ================================================================================
 * WHAT IT RETURNS, AND WHAT IT DELIBERATELY IS NOT
 * ================================================================================
 *
 * An **SVG string**, not a PNG and not a data URL:
 *
 *   - **SVG, because the sheet is PRINTED.** A raster at screen resolution is a fuzzy code on
 *     paper, and a phone camera reading a fuzzy code at a counter is the one failure that
 *     looks like the product not working. Vector art prints at the printer's resolution.
 *   - **No `<image>`, no external reference, no script.** The string is paths and rectangles
 *     only, so it carries no fetch and nothing for a CSP to allow — which matters because it
 *     is interpolated into the staff page, and `R-171` already owes that origin a policy.
 *
 * ⚠ **AND IT IS NOT CACHED, STORED OR LOGGED ANYWHERE.** The payload it encodes is a live
 * credential. It is computed per response, in the same call that reveals the token, and the
 * only copy that outlives the request is the one in the browser's memory and on paper —
 * exactly as `PagerSessionController::store()`'s own comment describes for the payload itself.
 */
// INTERNAL NOTES -- LINE COMMENTS ON PURPOSE, SO THEY ARE NOT PUBLISHED.
//
// ⚠ `R-052` STRUCK THIS CLASS FROM A THIRD DIRECTION AND THE SPEC CAUGHT IT. Scramble infers
// `qr_svg`'s type from this method's return and publishes the comments around it as that
// PROPERTY'S `description` in `docs/openapi.json` -- so two block comments inside `svg()` ended
// up in the committed contract, one of them explaining a CSS token exemption and the other an
// accessibility fix. `OpenApiSpecTest`'s staleness check is what reported it.
//
// `CreatePagerSessionRequest` and `PagerHistoryRequest` already carry the same warning for
// FormRequest docblocks and for comments above array keys. This is the third shape: a comment
// anywhere near the expression whose type becomes a published member. Line comments are not
// published, so everything below lives here.
//
// WHY THERE IS NO `width` OR `height` ATTRIBUTE, ONLY A `viewBox`: the sheet decides how big the
// code is, in CSS, at print size. An SVG with intrinsic dimensions would fight that and would be
// the wrong size on exactly one of screen and paper.
//
// WHY THE COLOURS ARE LITERAL `#000`/`#fff` RATHER THAN DESIGN TOKENS -- the one place in this
// project where that is correct. A QR is read by a camera against a contrast threshold, not by a
// person against a palette: `--color-ink` on `--color-paper` is a near-black on a near-white and
// would reduce the decode margin for no benefit. `pnpm lint:tokens` scans CSS files and does not
// reach this string, so the exemption needs stating rather than enforcing.
//
// WHY `aria-hidden="true"` AND NOT `role="img"`, AND A TEST FOUND IT. The first version carried
// `role="img"` on the `<svg>`. The page that renders it wraps it in an element with `role="img"`
// and an `aria-label` naming the ORDER -- because the payload must not be spoken aloud -- so the
// tree had TWO NESTED IMAGES, the inner one with no accessible name at all.
// `staff-issue.test.tsx`'s own case found it: `getByRole('img')` reported "found multiple
// elements". Hidden, there is exactly one announced image and it has the right name, and the
// code is not information a screen reader can convey in any case: it is a camera target.

final class PagerSheet
{
    /**
     * The module size, in SVG user units.
     *
     * A QR's smallest feature is one module, and a scanner needs roughly 2-3 printed pixels
     * per module to decode reliably. At this size a typical version-3 code (29 modules) draws
     * at 232 units, which an `<svg>` with no fixed width scales to whatever the sheet gives it
     * — so the number only sets the internal grid's precision and never the printed size.
     */
    private const MODULE = 8;

    /**
     * The quiet zone, in modules.
     *
     * ⚠ FOUR, WHICH IS THE SPEC's MINIMUM AND NOT A MARGIN SOMEBODY LIKED. ISO/IEC 18004
     * requires four modules of blank around a QR, and a code printed without it is one many
     * scanners refuse — the commonest way a technically correct QR fails in the field, and
     * invisible on a screen where the page background supplies the white by accident.
     */
    private const QUIET_ZONE = 4;

    /**
     * An SVG for one payload.
     *
     * ⚠ ERROR CORRECTION AT **MEDIUM** (~15%), WHICH IS A DELIBERATE MIDDLE RATHER THAN THE
     * MAXIMUM. The sheet is paper in a restaurant: it gets thumbed, folded and splashed, so
     * `LOW` (~7%) is too little. `HIGH` (~30%) is tempting and costs modules — it pushes a
     * payload of this length into a denser version, which makes every module SMALLER at a
     * fixed printed size and therefore HARDER to scan. Medium is what the damage model and
     * the module size agree on.
     */
    public static function svg(string $payload): string
    {
        $matrix = Encoder::encode($payload, ErrorCorrectionLevel::M())->getMatrix();

        $size = $matrix->getWidth();
        $extent = ($size + self::QUIET_ZONE * 2) * self::MODULE;

        $modules = '';

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $matrix->getWidth(); $x++) {
                // `get()` answers 0 for light and 1 for dark. Only the DARK modules are drawn,
                // and the background is one rectangle -- so the output is roughly half the
                // size of one rect per module, and a printer is handed fewer paths.
                if ($matrix->get($x, $y) === 0) {
                    continue;
                }

                $px = ($x + self::QUIET_ZONE) * self::MODULE;
                $py = ($y + self::QUIET_ZONE) * self::MODULE;

                $modules .= sprintf(
                    '<rect x="%d" y="%d" width="%d" height="%d"/>',
                    $px,
                    $py,
                    self::MODULE,
                    self::MODULE,
                );
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" shape-rendering="crispEdges" aria-hidden="true">'
            .'<rect width="%d" height="%d" fill="#fff"/><g fill="#000">%s</g></svg>',
            $extent,
            $extent,
            $extent,
            $extent,
            $modules,
        );
    }
}
