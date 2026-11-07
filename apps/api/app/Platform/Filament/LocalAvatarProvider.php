<?php

declare(strict_types=1);

namespace App\Platform\Filament;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The panel avatar, rendered here as a data URI. NO NETWORK, AND THAT IS THE POINT.
 *
 * ================================================================================
 * WHAT THIS REPLACES, MEASURED ON 2026-11-07 FROM A RENDERED `/platform`
 * ================================================================================
 *
 * Filament's default is `UiAvatarsProvider`, and `HasAvatars::$defaultAvatarProvider` sets it
 * on every panel unless a provider says otherwise. Both of ours took the default. Captured
 * from the rendered HTML of `/platform` as a signed-in platform admin:
 *
 *     https://ui-avatars.com/api/?name=A&format=svg&color=FFFFFF&background=%231f3129
 *
 * **`name=` IS THE SIGNED-IN PERSON'S NAME.** So every page view of either panel made an
 * authenticated administrator's browser issue a request to a third party, with a staff
 * member's name in the query string — and the referrer, the IP and the timing with it. It is
 * not an exfiltration anybody chose; it is a framework default that nobody read.
 *
 * ⚠ AND IT IS THE SECOND HALF OF A JOB TASK 8b ALREADY DID. `QRPAGER-23 SR-4` removed the
 * CDN FONT from both panels — *"the CDN font is gone, proven over the compiled assets and the
 * rendered HTML"* — and an avatar service is the same defect through a different Filament
 * default. One was looked for and found; the other was not looked for.
 *
 * ================================================================================
 * WHY IT MATTERS BEYOND THE LEAK ITSELF
 * ================================================================================
 *
 * It was the ONLY remote origin in either panel's markup, measured across all seven panel
 * routes. Removing it is what lets `ContentSecurityPolicy` give the panels
 * `default-src 'self'` with `img-src 'self' data:` and no third-party host anywhere in the
 * policy. A CSP that had to allow `ui-avatars.com` would be allowing exactly the request
 * this class deletes.
 *
 * ================================================================================
 * WHAT IT RENDERS
 * ================================================================================
 *
 * The same initials on the same background, as an inline SVG data URI. `§12`'s contrast bar
 * is met by construction: white on `gray[950]`, which is the pair Filament's own provider
 * asked the remote service for.
 *
 * ⚠ ONE THING IS GENUINELY DIFFERENT AND IS SAID RATHER THAN GLOSSED: the GLYPH SIZE is
 * computed here, because the remote service was doing that and a data URI has nobody to do
 * it. Filament's initials rule is copied exactly, so no avatar's LETTERS change; `fontSize()`
 * below is the new part, and it exists because a five-word name at a fixed 56px overflowed
 * the box.
 *
 * ⚠ THE INITIALS ARE BUILT WITH FILAMENT'S OWN RULE, deliberately copied rather than
 * invented, including its leading-punctuation strip (a `[SYSTEM] Admin` naming convention
 * must not become `[A`). A different rule here would change every avatar in both panels for
 * no reason, and the point of this class is that nothing visible changes.
 *
 * ⚠ AND THE NAME IS `rawurlencode`d INTO THE SVG AS XML-ESCAPED TEXT, which is not
 * decoration. A data URI is a document: a person whose name contains `<` or `&` would
 * otherwise produce malformed SVG at best, and at worst an injection into a document the
 * browser parses. Initials are single characters taken from a name a tenant manager can set,
 * so they are untrusted input rendered into markup — `htmlspecialchars` first, then the URI
 * encoding.
 */
final class LocalAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initials = $this->initials((string) Filament::getNameForDefaultAvatar($record));

        /*
         * `(string)` on the shade, because `Color::getColor()` is typed `int|string` per shade
         * and `convertToHex()` takes a string. Filament's own provider passes it unguarded and
         * larastan refuses it here, which is the stricter reading and is right: a shade given
         * as an int would be a palette this project does not generate (`QRPAGER-23 SR-4`
         * writes eleven explicit values per family), so the cast is total rather than hopeful.
         */
        $background = Color::convertToHex((string) (FilamentColor::getColor('gray')[950] ?? Color::Gray[950]));

        /*
         * `viewBox` and no width/height, so the panel's own CSS sizes it exactly as it sized
         * the remote SVG. `font-family` names a generic family rather than a webfont: a data
         * URI cannot load one, and asking for `Inter` here would silently fall back anyway.
         */
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128">'
            .'<rect width="128" height="128" fill="%s"/>'
            .'<text x="64" y="64" fill="#FFFFFF" font-family="sans-serif" font-size="%d" '
            .'font-weight="500" text-anchor="middle" dominant-baseline="central">%s</text>'
            .'</svg>',
            htmlspecialchars($background, ENT_QUOTES | ENT_XML1, 'UTF-8'),
            self::fontSize($initials),
            htmlspecialchars($initials, ENT_QUOTES | ENT_XML1, 'UTF-8'),
        );

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Big enough to read, small enough to fit the 128px box.
     *
     * ⚠ THIS IS THE ONE THING THE REMOTE SERVICE DID THAT A DATA URI HAS NOBODY TO DO.
     * `UiAvatarsProvider` joins EVERY segment's initial with a space and hands the string to
     * ui-avatars.com, which sizes the glyphs itself. Keeping its initials rule exactly (so
     * nobody's avatar letters change) means a five-word name arrives here as `A B C D E`, and
     * at a fixed 56px that overflowed the box -- measured in the rendered SVG.
     *
     * So the rule is copied and the SIZING is computed. 56px is the two-glyph case, which is
     * almost every real name, and it steps down from there rather than using `textLength`:
     * `lengthAdjust` would stretch a single initial across the whole box, which looks worse
     * than either.
     */
    private static function fontSize(string $initials): int
    {
        $glyphs = max(1, mb_strlen(str_replace(' ', '', $initials)));

        return match (true) {
            $glyphs <= 2 => 56,
            $glyphs === 3 => 42,
            $glyphs === 4 => 34,
            default => 28,
        };
    }

    /**
     * Filament's own initials rule, copied from `UiAvatarsProvider` with its reason.
     *
     * Its comment, which is the reason the strip is here and not a tidy-up:
     * *"Skip leading punctuation (e.g. a `[SYSTEM] Admin` service-account naming convention)
     * so it doesn't become part of the initials."*
     */
    private function initials(string $name): string
    {
        return str($name)
            ->trim()
            ->explode(' ')
            ->map(function (string $segment): string {
                $letters = preg_replace('/^[^\p{L}\p{N}]+/u', '', $segment);

                return filled($letters) ? mb_substr($letters, 0, 1) : '';
            })
            ->join(' ');
    }
}
