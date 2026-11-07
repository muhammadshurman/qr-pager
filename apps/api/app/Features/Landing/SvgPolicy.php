<?php

declare(strict_types=1);

namespace App\Features\Landing;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMProcessingInstruction;

/**
 * ================================================================================
 * SVG IS THE ONE UPLOADED FORMAT THAT IS ALSO A DOCUMENT, SO IT GETS A VALIDATOR
 * ================================================================================
 *
 * A PNG served from a tenant's domain is bytes a browser decodes. An SVG served from the
 * same place is an XML DOCUMENT: navigate straight to it and any <script> in it runs, in
 * that tenant's origin. The task's named invariant says "no tenant-authored value can
 * inject script or markup into a published page", and an uploaded SVG is a tenant-authored
 * value that IS markup -- so accepting one unexamined would make the invariant false by
 * the most direct route available.
 *
 * ⚠ §12.2's copy says "PNG, JPEG, WebP, AVIF or SVG-free SVG", which is self-contradictory
 * as written. This is the reading that makes it true and makes it safe: an SVG with
 * nothing executable in it. The alternative readings were (a) refuse SVG outright, which
 * would make a line of approved copy false, and (b) accept it, which would make the
 * invariant false. Recorded here rather than resolved silently.
 *
 * AN ALLOW-LIST, AND IT REFUSES RATHER THAN STRIPS -- the same decision as D5 one layer
 * out, for the same reason: stripping is a transformation whose correctness is a claim
 * about every input anybody will ever upload. Anything not named below is refused, so the
 * failure mode of a construct nobody thought of is a rejected upload rather than a
 * published one.
 *
 * NO DOCTYPE IS PERMITTED AT ALL, which is what takes entity expansion and XXE off the
 * table rather than relying on a parser flag staying correct across a PHP upgrade.
 */
final class SvgPolicy
{
    public const NAMESPACE = 'http://www.w3.org/2000/svg';

    public const XLINK_NAMESPACE = 'http://www.w3.org/1999/xlink';

    /**
     * Shapes, grouping, gradients and text. Nothing that can fetch, navigate, animate or
     * execute.
     *
     * DELIBERATELY ABSENT, each because it is a known vector rather than because nobody
     * needed it: `script` (executes), `foreignObject` (embeds HTML, so the whole HTML
     * attack surface), `image` (fetches), `a` (navigates), `style` (CSS, and `url()` with
     * it), `filter`/`feImage` (fetches), `animate`/`set` (can animate an `href` into
     * existence), `handler`, `use` pointing outside the document (bounded by the href
     * rule below rather than by removing `use`), `pattern` and `mask` (both reference,
     * and neither is needed by a logo).
     *
     * @var list<string>
     */
    public const ELEMENTS = [
        'svg', 'g', 'defs', 'title', 'desc', 'path', 'rect', 'circle', 'ellipse',
        'line', 'polyline', 'polygon', 'text', 'tspan', 'use', 'symbol',
        'linearGradient', 'radialGradient', 'stop', 'clipPath',
    ];

    /**
     * Geometry, presentation and nothing else.
     *
     * `style` is absent on purpose: it is CSS, and CSS carries `url()`. Presentation
     * attributes (`fill`, `stroke`, …) do everything a logo needs and carry no URL.
     * Every `on*` handler is absent by construction -- this is an allow-list.
     *
     * @var list<string>
     */
    public const ATTRIBUTES = [
        'id', 'class', 'width', 'height', 'viewBox', 'version', 'preserveAspectRatio',
        'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy',
        'd', 'points', 'transform', 'gradientUnits', 'gradientTransform', 'spreadMethod',
        'offset', 'stop-color', 'stop-opacity',
        'fill', 'fill-opacity', 'fill-rule', 'opacity',
        'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin',
        'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'stroke-miterlimit',
        'clip-path', 'clip-rule',
        'font-family', 'font-size', 'font-weight', 'font-style', 'letter-spacing',
        'text-anchor', 'dominant-baseline',
    ];

    /** The two attributes that may hold a reference, and only to a fragment of this file. */
    public const REFERENCE_ATTRIBUTES = ['href', 'xlink:href'];

    /**
     * Is $bytes an SVG this application will store? Returns null when it is, or the
     * reason it is not.
     *
     * The reason names the CONSTRUCT (an element or attribute name from the file) and
     * never a value, because a value is tenant-authored content heading for an error
     * message. An element name is bounded by what XML permits in a name, which is checked
     * before it is used.
     */
    public static function refuse(string $bytes): ?string
    {
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            return 'it is not valid UTF-8';
        }

        // A DOCTYPE is refused OUTRIGHT, before parsing, so entity expansion and XXE are
        // not a question about parser flags. libxml_disable_entity_loader is gone in
        // PHP 8 and LIBXML_NOENT means the opposite of what its name suggests; a rule
        // that does not depend on either is a rule that cannot be undone by an upgrade.
        if (preg_match('/<!DOCTYPE/i', $bytes) === 1) {
            return 'it carries a DOCTYPE, which this application never accepts in an SVG';
        }

        if (preg_match('/<!ENTITY/i', $bytes) === 1) {
            return 'it declares an XML entity';
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;

            // LIBXML_NONET: no network access from the parser, belt to the DOCTYPE
            // braces. No LIBXML_NOENT, which would EXPAND entities rather than prevent
            // them -- the flag is named for what it does to the output tree.
            $parsed = $document->loadXML($bytes, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($parsed === false || ! $document->documentElement instanceof DOMElement) {
            return 'it is not well-formed XML';
        }

        $root = $document->documentElement;

        if ($root->localName !== 'svg' || $root->namespaceURI !== self::NAMESPACE) {
            return 'its root element is not an SVG';
        }

        // ⚠ THE DOCUMENT, NOT THE ROOT ELEMENT, AND THAT ONE WORD WAS A CRITICAL.
        //
        // This read `self::walk($root)`. `walk()` iterates its argument's CHILDREN and
        // applies `element()` -- the only caller of the attribute allow-list -- to each
        // one. So the root `<svg>`'s own attributes were never examined, and
        // `<svg xmlns="..." onload="alert(document.domain)">` was accepted, stored, and
        // copied by a publish to `sites/{hostname}/assets/{32 hex}.svg` with
        // `ContentType: image/svg+xml`. Navigating to that URL runs the script in the
        // published site's origin -- which is exactly what this class's docblock says it
        // exists to prevent, and which made the task's named invariant false by the most
        // direct route available. Found by the §11 security review on 2026-10-28; all 21
        // hostile fixtures put their payload on a CHILD, so none of them could catch it.
        //
        // Walking the DOCUMENT fixes a second hole in the same line. A processing
        // instruction belongs BEFORE the root, as a sibling of it: an xml declaration,
        // then an xml-stylesheet instruction, then the svg element. A walk that starts
        // at the root never visits one, so the PI refusal below was unreachable for the
        // only position a PI is actually written in. (The instruction is described in
        // words and not written out, because a literal `?` followed by `>` inside a PHP
        // comment CLOSES THE PHP TAG -- which this file has now cost twice.)
        //
        // The document's children are the PI(s), any comment, and the root element, so
        // `element($root)` now runs with everything else unchanged.
        return self::walk($document);
    }

    /** Depth-first over the whole tree. The first refusal wins. */
    private static function walk(DOMNode $node): ?string
    {
        foreach ($node->childNodes as $child) {
            // A processing instruction can be an xml-stylesheet one, which loads external
            // CSS. There is no PI an uploaded logo needs. (The instruction is named in
            // words rather than written out, because a literal `?` followed by `>` inside
            // a PHP comment CLOSES THE PHP TAG -- which is how the first version of this
            // file failed to parse, 100 lines below the comment that caused it.)
            if ($child instanceof DOMProcessingInstruction) {
                return 'it carries an XML processing instruction';
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            if ($refusal = self::element($child)) {
                return $refusal;
            }

            if ($refusal = self::walk($child)) {
                return $refusal;
            }
        }

        return null;
    }

    private static function element(DOMElement $element): ?string
    {
        if ($element->namespaceURI !== self::NAMESPACE) {
            return 'it mixes in a document from another XML namespace';
        }

        if (! in_array($element->localName, self::ELEMENTS, true)) {
            return 'it contains a <'.self::safeName($element->localName).'> element, which this application never stores in an SVG';
        }

        foreach (iterator_to_array($element->attributes) as $attribute) {
            if ($refusal = self::attribute($attribute)) {
                return $refusal;
            }
        }

        return null;
    }

    private static function attribute(DOMAttr $attribute): ?string
    {
        $name = $attribute->prefix === ''
            ? (string) $attribute->localName
            : $attribute->prefix.':'.$attribute->localName;

        // The namespace declarations the document needs to be an SVG at all.
        if ($attribute->namespaceURI === 'http://www.w3.org/2000/xmlns/') {
            return in_array($attribute->nodeValue, [self::NAMESPACE, self::XLINK_NAMESPACE], true)
                ? null
                : 'it declares an XML namespace this application does not accept';
        }

        if (in_array($name, self::REFERENCE_ATTRIBUTES, true)) {
            // A FRAGMENT OF THIS DOCUMENT AND NOTHING ELSE. That is what makes <use>
            // safe to keep: it can only point at a <symbol> or <path> in the same file,
            // never at an external document, a data: URI or an absolute URL.
            return preg_match('/^#[A-Za-z_][\w.\-]*$/', (string) $attribute->nodeValue) === 1
                ? null
                : 'it references something outside the file';
        }

        if (! in_array($name, self::ATTRIBUTES, true)) {
            return 'it sets the attribute ['.self::safeName($name).'], which this application never stores in an SVG';
        }

        $value = (string) $attribute->nodeValue;

        // Belt to the allow-list's braces: a presentation attribute that fetched would be
        // a request from a published page to wherever the tenant chose.
        if (preg_match('/javascript\s*:|data\s*:|&#/i', $value) === 1) {
            return 'its ['.self::safeName($name).'] attribute carries a scheme or an escape sequence';
        }

        // `url(#id)` STAYS, and the first draft of this rule refused it -- which the
        // positive-control case caught, because it is how `fill="url(#gradient)"` and
        // `clip-path="url(#clip)"` work and a policy that refused them would be "refuse
        // SVG" wearing a costume. What is refused is every OTHER url(): a fragment of
        // this same document is a reference that cannot leave it.
        preg_match_all('/url\s*\(([^)]*)\)/i', $value, $urls);

        foreach ($urls[1] as $target) {
            if (preg_match('/^\s*[\'"]?#[A-Za-z_][\w.\-]*[\'"]?\s*$/', $target) !== 1) {
                return 'its ['.self::safeName($name).'] attribute fetches something outside the file';
            }
        }

        return null;
    }

    /**
     * An element or attribute name, made safe to put in a message.
     *
     * XML names are already a bounded set, but this message is going into a panel and
     * possibly a log, and "already bounded" is the assumption §4 › Edge & ops exists
     * because of. A name that does not match is reported as its shape, not its text.
     */
    private static function safeName(?string $name): string
    {
        return preg_match('/^[A-Za-z_][\w.\-:]{0,63}$/', (string) $name) === 1
            ? (string) $name
            : 'an unprintable name';
    }
}
