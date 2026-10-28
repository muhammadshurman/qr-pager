<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Real bytes for the five accepted image types, and for the files that must be refused.
 *
 * REAL FILES AND NOT FAKES, because the thing under test is content detection: a fixture
 * that was "whatever string the test felt like" would be refused for the wrong reason and
 * the test would pass without exercising anything. Each raster fixture below decodes --
 * `getimagesizefromstring` answers for png, jpeg and webp, and libmagic answers
 * `image/avif` for the ISOBMFF header -- all measured in-session on 2026-10-28.
 */
final class ImageFixtures
{
    /** A 1x1 PNG. */
    public static function png(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
    }

    /** A 1x1 JPEG. */
    public static function jpeg(): string
    {
        return (string) base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIs'
            .'IxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAA'
            .'AAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==',
            true,
        );
    }

    /** A 1x1 lossless WebP. */
    public static function webp(): string
    {
        return (string) base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==', true);
    }

    /**
     * An ISOBMFF `ftyp` box declaring the avif brand -- what libmagic keys on.
     *
     * Not a decodable image, and that is the honest limit of this fixture: AVIF is the
     * one accepted type `getimagesizefromstring` does not answer for (measured), so
     * InspectedImage does not ask it to, and neither does this.
     */
    public static function avif(): string
    {
        return pack('N', 32).'ftyp'.'avif'.pack('N', 0).'avifmif1miaf'.pack('N', 8).'mdat';
    }

    /** An SVG with nothing executable in it. */
    public static function svg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" width="10" height="10">'
            .'<title>A square</title><rect x="0" y="0" width="10" height="10" fill="#ff0000"/></svg>';
    }

    /** A GIF: a real image, and deliberately NOT one of the five. */
    public static function gif(): string
    {
        return (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true);
    }

    /** An HTML document. What gets uploaded as `logo.png`. */
    public static function html(): string
    {
        return '<html><body><script>alert(document.domain)</script></body></html>';
    }

    /**
     * Every SVG shape the policy must refuse, keyed by what it is.
     *
     * @return array<string, string>
     */
    public static function hostileSvgs(): array
    {
        $open = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">';

        return [
            'script element' => $open.'<script>alert(1)</script></svg>',
            'script in defs' => $open.'<defs><script>alert(1)</script></defs></svg>',
            'event handler' => $open.'<rect width="10" height="10" onload="alert(1)"/></svg>',
            'click handler' => $open.'<rect width="10" height="10" onclick="alert(1)"/></svg>',
            'foreignObject' => $open.'<foreignObject><body xmlns="http://www.w3.org/1999/xhtml">x</body></foreignObject></svg>',
            'external image' => $open.'<image href="https://evil.test/x.png"/></svg>',
            'anchor' => $open.'<a href="https://evil.test"><rect width="1" height="1"/></a></svg>',
            'style element' => $open.'<style>rect{fill:url(https://evil.test/x)}</style></svg>',
            'style attribute' => $open.'<rect width="1" height="1" style="fill:red"/></svg>',
            'animate href' => $open.'<animate attributeName="href" to="javascript:alert(1)"/></svg>',
            'use outside the file' => $open.'<use href="https://evil.test/x.svg#a"/></svg>',
            'use a data uri' => $open.'<use href="data:image/svg+xml,%3Csvg/%3E"/></svg>',
            'xlink to elsewhere' => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
                .'<use xlink:href="https://evil.test/x.svg#a"/></svg>',
            'filter with feImage' => $open.'<filter id="f"><feImage href="https://evil.test/x"/></filter></svg>',
            'doctype' => '<!DOCTYPE svg><svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>',
            'entity declaration' => '<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
                .'<svg xmlns="http://www.w3.org/2000/svg"><desc>&x;</desc></svg>',
            'stylesheet instruction' => '<'.'?xml-stylesheet href="https://evil.test/x.css"?'.'>'
                .'<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>',
            'foreign namespace' => '<svg xmlns="http://www.w3.org/2000/svg">'
                .'<html:div xmlns:html="http://www.w3.org/1999/xhtml">x</html:div></svg>',
            'not an svg root' => '<html xmlns="http://www.w3.org/1999/xhtml"><body>x</body></html>',
            'url in a presentation attribute' => $open.'<rect width="1" height="1" fill="url(https://evil.test/x)"/></svg>',
            'malformed xml' => $open.'<rect width="1"',
        ];
    }
}
