<?php

declare(strict_types=1);

namespace App\Features\Landing;

use finfo;

/**
 * What an uploaded file actually IS, decided from its bytes.
 *
 * §3 row 7: "an upload is validated by real content type and size, not by extension".
 * The filename reaches this class only as something to RECORD; nothing here branches on
 * it, and the extension that ends up in the object key is derived from the detected type
 * (EXTENSIONS below), never from what the uploader called the file.
 */
final class InspectedImage
{
    /**
     * The five types §12.2's copy names, each mapped to the ONE extension its object key
     * may carry. One canonical extension per type, so there is no .jpg/.jpeg pair for the
     * schema's key constraint and the CDN to disagree about.
     *
     * @var array<string, string>
     */
    public const EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/svg+xml' => 'svg',
    ];

    /** 5 MB (§6.5), matching media_assets_size_is_bounded. */
    public const MAX_BYTES = 5242880;

    private function __construct(
        public readonly string $contentType,
        public readonly string $extension,
        public readonly int $byteSize,
        public readonly string $checksum,
        public readonly ?int $width,
        public readonly ?int $height,
    ) {}

    /**
     * Inspect $bytes, or refuse them.
     *
     * MEASURED IN-SESSION ON 2026-10-28 rather than recalled, because the whole clause
     * rests on what libmagic actually answers here: a 1x1 PNG -> image/png, a GIF ->
     * image/gif (refused: not one of the five), an HTML document -> text/html, a
     * GIF89a header with a script appended -> image/gif, an SVG carrying a script ->
     * image/svg+xml, a non-SVG XML document -> text/xml, and an ISOBMFF ftyp box with
     * the avif brand -> image/avif. getimagesizefromstring answers for PNG, JPEG and
     * WebP and returns false for AVIF and SVG, which is why width and height are
     * nullable rather than required.
     *
     * @throws MediaRejected
     */
    public static function of(string $bytes): self
    {
        $size = strlen($bytes);

        if ($size === 0) {
            throw new MediaRejected(__('landing.media.empty'));
        }

        if ($size > self::MAX_BYTES) {
            throw new MediaRejected(__('landing.media.too_large', [
                'size' => sprintf('%.1f', $size / 1048576),
                'max' => (int) (self::MAX_BYTES / 1048576),
            ]));
        }

        $detected = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (! is_string($detected) || ! array_key_exists($detected, self::EXTENSIONS)) {
            // §12.2's copy: "{name} was refused: it is a {detected} file, not an image."
            // The detected type is libmagic's output, from a bounded vocabulary, and is
            // the one thing about the file that is safe to repeat back.
            throw new MediaRejected(__('landing.media.wrong_type', [
                'detected' => self::safeType($detected),
            ]));
        }

        if ($detected === 'image/svg+xml') {
            if ($refusal = SvgPolicy::refuse($bytes)) {
                throw new MediaRejected(__('landing.media.svg_refused', ['refusal' => $refusal]));
            }

            return new self($detected, self::EXTENSIONS[$detected], $size, hash('sha256', $bytes), null, null);
        }

        // A SECOND, INDEPENDENT DECODE for the raster types. libmagic reads a few magic
        // bytes; getimagesizefromstring parses the container. A file that passes one and
        // not the other is not an image either way, and the disagreement is the
        // interesting case -- it is what a polyglot looks like.
        $measured = @getimagesizefromstring($bytes);

        if ($detected === 'image/avif') {
            // AVIF is the one accepted type getimagesizefromstring does not answer for
            // (measured). Its dimensions stay null rather than being guessed.
            return new self($detected, self::EXTENSIONS[$detected], $size, hash('sha256', $bytes), null, null);
        }

        if ($measured === false || $measured['mime'] !== $detected) {
            throw new MediaRejected(__('landing.media.does_not_decode'));
        }

        return new self(
            $detected,
            self::EXTENSIONS[$detected],
            $size,
            hash('sha256', $bytes),
            $measured[0] > 0 ? $measured[0] : null,
            $measured[1] > 0 ? $measured[1] : null,
        );
    }

    /** A detected type, made safe to put in a message shown to the uploader. */
    private static function safeType(mixed $detected): string
    {
        return is_string($detected) && preg_match('#^[a-z0-9.+\-]{1,32}/[a-z0-9.+\-]{1,64}$#i', $detected) === 1
            ? $detected
            : 'unrecognised';
    }
}
