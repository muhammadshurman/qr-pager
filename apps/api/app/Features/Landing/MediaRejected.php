<?php

declare(strict_types=1);

namespace App\Features\Landing;

use RuntimeException;

/**
 * An upload the media library refused, with a reason safe to show the uploader.
 *
 * §12.2's copy needs the real reason -- "{name} was refused: it is a {detected} file, not
 * an image." A refusal that says only "invalid file" sends a restaurant owner round a loop
 * they cannot get out of, and a refusal that echoes the file's CONTENTS is the oldest
 * reflected-XSS shape there is. So this carries the DETECTED TYPE, which comes from a
 * bounded set that libmagic produced, and never a byte of the file.
 */
final class MediaRejected extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
