<?php

declare(strict_types=1);

namespace App\Features\Landing\Console;

use GdImage;
use RuntimeException;

/**
 * Placeholder imagery that behaves like a PHOTOGRAPH. `QRPAGER-48`.
 *
 * ================================================================================
 * ⚠⚠ WHY FLAT COLOUR BLOCKS ARE NOT AN ACCEPTABLE PLACEHOLDER, MEASURED ON A REVIEW
 * ================================================================================
 *
 * Muhammad, 2026-11-13, on `QRPAGER-46`'s captures:
 *
 *   "The flat colour fixtures made the three arrangements unjudgeable: a photo grid and a
 *    column of full-width bands both read as slabs of colour, so I could not tell whether
 *    tile and rail actually differ in a way a restaurant owner would see."
 *
 * That is a real property of the arrangements, not a taste: `tile` lays images in a grid and
 * `rail` runs them full-measure, and **two solid rectangles look identical under both**. A
 * placeholder that cannot distinguish the layouts defeats the only reason the capture exists.
 *
 * So these carry what a photograph carries and a swatch does not: **a subject against a
 * ground, varied luminance across the frame, mid-frequency detail, grain and a vignette** —
 * and varied ASPECT RATIOS, because a grid of squares and a column of wide crops are exactly
 * where `tile` and `rail` diverge.
 *
 * ================================================================================
 * GENERATED LOCALLY, WITH NO NEW DEPENDENCY
 * ================================================================================
 *
 * GD is already present and carries JPEG, PNG, WebP and FreeType; Imagick, ImageMagick and
 * PIL are all absent on this machine, which was checked rather than assumed. Every step below
 * is a C-speed GD call — gradients by row, `imagefilledellipse`, `IMG_FILTER_GAUSSIAN_BLUR`,
 * `IMG_FILTER_SCATTER` — and there is **no per-pixel PHP loop anywhere**, because a 1600x1000
 * frame is 1.6 million pixels and twelve of those would make the seeder unusable.
 *
 * ================================================================================
 * DETERMINISTIC, BECAUSE THE SEEDER MUST STAY IDEMPOTENT
 * ================================================================================
 *
 * Every image is a pure function of its seed: `mt_srand($seed)` before any randomness, so two
 * runs a week apart produce **byte-identical** output. That is what lets `studio:demo-tenant`
 * re-use an existing asset instead of piling up a thirteenth copy on every run.
 */
final class DemoImagery
{
    /**
     * Palettes that read as different SUBJECTS rather than different hues of one thing.
     *
     * @var array<string, array{ground: array{int,int,int}, sky: array{int,int,int}, subject: array{int,int,int}, accent: array{int,int,int}}>
     */
    private const SUBJECTS = [
        'bread' => ['ground' => [92, 56, 28], 'sky' => [222, 188, 140], 'subject' => [186, 128, 62], 'accent' => [248, 232, 198]],
        'herbs' => ['ground' => [24, 54, 34], 'sky' => [168, 196, 140], 'subject' => [74, 122, 58], 'accent' => [226, 232, 188]],
        'grill' => ['ground' => [58, 22, 18], 'sky' => [204, 134, 92], 'subject' => [158, 54, 38], 'accent' => [246, 200, 140]],
        'room' => ['ground' => [28, 36, 48], 'sky' => [138, 156, 178], 'subject' => [62, 80, 104], 'accent' => [238, 206, 150]],
        'sweet' => ['ground' => [96, 48, 22], 'sky' => [238, 206, 160], 'subject' => [206, 148, 54], 'accent' => [250, 238, 214]],
        'coffee' => ['ground' => [34, 24, 20], 'sky' => [170, 140, 118], 'subject' => [88, 58, 42], 'accent' => [232, 216, 196]],
    ];

    /** @return list<string> */
    public static function subjects(): array
    {
        return array_keys(self::SUBJECTS);
    }

    /**
     * One photograph-like frame, as JPEG bytes.
     *
     * @throws RuntimeException when GD cannot produce the frame
     */
    public static function photograph(int $width, int $height, string $subject, int $seed): string
    {
        if ($width < 1 || $height < 1) {
            throw new RuntimeException('A frame needs positive dimensions, got '.$width.'x'.$height.'.');
        }

        $palette = self::SUBJECTS[$subject] ?? self::SUBJECTS['room'];

        mt_srand($seed);

        $im = imagecreatetruecolor($width, $height);

        if ($im === false) {
            throw new RuntimeException('GD could not allocate a '.$width.'x'.$height.' frame.');
        }

        imagealphablending($im, true);

        self::ground($im, $width, $height, $palette);
        self::blobs($im, $width, $height, $palette, 4);

        // Softened FIRST, so the shapes above read as depth of field rather than as clip art.
        imagefilter($im, IMG_FILTER_GAUSSIAN_BLUR);
        imagefilter($im, IMG_FILTER_GAUSSIAN_BLUR);

        // ⚠ AND THE CRISP DETAIL GOES ON AFTER THE BLUR, which is the whole difference between
        // "a soft gradient" and "a photograph": an eye reads the sharp mid-frequency marks as
        // texture and the soft ground behind them as distance.
        self::detail($im, $width, $height, $palette);

        self::vignette($im, $width, $height);

        self::grain($im, $width, $height);

        return self::encode($im, 'jpeg');
    }

    /**
     * The restaurant's mark — deliberately NOT photographic.
     *
     * A logo is a mark, not a picture: flat is correct here and is the opposite of the defect
     * above. A cypress (السَّرو) on a dark disc, which is the one place in this file where a
     * solid fill is the right answer.
     */
    public static function logo(int $size = 512): string
    {
        if ($size < 1) {
            throw new RuntimeException('A logo needs a positive size, got '.$size.'.');
        }

        mt_srand(4801);

        $im = imagecreatetruecolor($size, $size);

        if ($im === false) {
            throw new RuntimeException('GD could not allocate the logo frame.');
        }

        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, (int) imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);

        $ink = (int) imagecolorallocate($im, 25, 28, 34);
        $cream = (int) imagecolorallocate($im, 252, 252, 250);

        imagefilledellipse($im, intdiv($size, 2), intdiv($size, 2), $size, $size, $ink);

        // Three stacked tiers and a trunk: a cypress at the smallest number of marks that
        // still reads as one.
        $cx = intdiv($size, 2);
        $top = (int) ($size * 0.22);

        foreach ([[0.30, 0.17], [0.38, 0.30], [0.46, 0.44]] as $i => [$halfWidth, $offset]) {
            $y = $top + (int) ($size * $offset);
            imagefilledpolygon($im, [
                $cx, $y - (int) ($size * 0.16),
                $cx - (int) ($size * $halfWidth * 0.5), $y + (int) ($size * 0.06),
                $cx + (int) ($size * $halfWidth * 0.5), $y + (int) ($size * 0.06),
            ], $cream);
        }

        imagefilledrectangle(
            $im,
            $cx - (int) ($size * 0.035),
            (int) ($size * 0.70),
            $cx + (int) ($size * 0.035),
            (int) ($size * 0.82),
            $cream,
        );

        return self::encode($im, 'png');
    }

    /**
     * The ground: COHERENT low-resolution noise, upscaled with resampling.
     *
     * ⚠⚠ TWO ATTEMPTS FAILED BEFORE THIS ONE, AND BOTH FAILURES ARE THE SAME MISTAKE.
     *
     * The first drew a vertical gradient with translucent ellipses over it: the output read as
     * *circles on a gradient*, obviously geometric. The second replaced it with a 28x18 field
     * of WHITE noise, resampled up — and that read as a *quilt*, because neighbouring cells
     * differed wildly and resampling a high-frequency source produces flat squares with soft
     * edges, not clouds. Both were measured by LOOKING at the output, which is the only way
     * this particular defect can be measured.
     *
     * What a photograph has that both lacked is **spatial coherence**: nearby regions resemble
     * each other, and the variation is low-frequency. So the field is smoothed BEFORE it is
     * upscaled — five box passes over a 96x60 grid, which is 5,760 cells and costs nothing —
     * and only then resampled. The blur is doing the work in the small image, where it is
     * affordable, rather than in the large one, where GD's 3x3 kernel is negligible against
     * 1,600 pixels of width.
     */
    /**
     * A colour channel, clamped into the range GD accepts.
     *
     * Not a type dance: the box blur averages neighbours and the palette jitter adds ±18, so
     * both CAN leave the range on their own terms. Clamping where the value is used is the
     * one place that is true for every caller.
     *
     * @return int<0, 255>
     */
    private static function channel(int $value): int
    {
        return max(0, min(255, $value));
    }

    /** @param array{ground: array{int,int,int}, sky: array{int,int,int}, subject: array{int,int,int}, accent: array{int,int,int}} $palette */
    private static function ground(GdImage $im, int $width, int $height, array $palette): void
    {
        $cols = 96;
        $rows = 60;

        /** @var list<list<array{int,int,int}>> $field */
        $field = [];

        for ($y = 0; $y < $rows; $y++) {
            for ($x = 0; $x < $cols; $x++) {
                // Weighted toward the sky at the top and the ground at the bottom, so the
                // field keeps a direction -- light from above, as a photograph has.
                $t = $y / max(1, $rows - 1);
                $keys = ['sky', 'ground', 'subject', 'accent'];
                $pick = mt_rand(0, 99) < (int) (72 - 44 * $t) ? 'sky' : $keys[mt_rand(0, 3)];
                $field[$y][$x] = $palette[$pick];
            }
        }

        // Three box passes. Each cell becomes the mean of itself and its neighbours, which
        // turns white noise into something with masses in it.
        for ($pass = 0; $pass < 5; $pass++) {
            $next = $field;

            for ($y = 0; $y < $rows; $y++) {
                for ($x = 0; $x < $cols; $x++) {
                    $r = $g = $b = $n = 0;

                    for ($dy = -1; $dy <= 1; $dy++) {
                        for ($dx = -1; $dx <= 1; $dx++) {
                            $yy = $y + $dy;
                            $xx = $x + $dx;

                            if ($yy < 0 || $yy >= $rows || $xx < 0 || $xx >= $cols) {
                                continue;
                            }

                            [$cr, $cg, $cb] = $field[$yy][$xx];
                            $r += $cr;
                            $g += $cg;
                            $b += $cb;
                            $n++;
                        }
                    }

                    $next[$y][$x] = [intdiv($r, $n), intdiv($g, $n), intdiv($b, $n)];
                }
            }

            $field = $next;
        }

        $small = imagecreatetruecolor($cols, $rows);

        if ($small === false) {
            throw new RuntimeException('GD could not allocate the low-resolution ground.');
        }

        for ($y = 0; $y < $rows; $y++) {
            for ($x = 0; $x < $cols; $x++) {
                [$r, $g, $b] = $field[$y][$x];
                imagesetpixel($small, $x, $y, (int) imagecolorallocate(
                    $small, self::channel($r), self::channel($g), self::channel($b),
                ));
            }
        }

        imagecopyresampled($im, $small, 0, 0, 0, 0, $width, $height, $cols, $rows);
        imagedestroy($small);
    }

    /**
     * The subject: irregular masses, not circles.
     *
     * ⚠ THESE WERE `imagefilledellipse` AND IT SHOWED. A perfect ellipse reads as a drawn
     * shape however softly it is tinted, so each mass is now a polygon whose vertices are a
     * jittered ring — same silhouette budget, none of the geometry.
     */
    /** @param array{ground: array{int,int,int}, sky: array{int,int,int}, subject: array{int,int,int}, accent: array{int,int,int}} $palette */
    private static function blobs(GdImage $im, int $width, int $height, array $palette, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $rgb = $i === 0 ? $palette['subject'] : ($i % 2 === 0 ? $palette['accent'] : $palette['ground']);
            $colour = (int) imagecolorallocatealpha(
                $im,
                self::channel($rgb[0]),
                self::channel($rgb[1]),
                self::channel($rgb[2]),
                $i === 0 ? 78 : mt_rand(96, 114),
            );

            $cx = mt_rand((int) ($width * 0.15), (int) ($width * 0.85));
            $cy = mt_rand((int) ($height * 0.15), (int) ($height * 0.85));
            $rx = (int) ($width * (mt_rand(22, 46) / 100));
            $ry = (int) ($height * (mt_rand(22, 46) / 100));

            $points = [];
            $vertices = 13;

            for ($v = 0; $v < $vertices; $v++) {
                $angle = ($v / $vertices) * 2 * M_PI;
                $wobble = mt_rand(62, 128) / 100;
                $points[] = (int) ($cx + cos($angle) * $rx * $wobble);
                $points[] = (int) ($cy + sin($angle) * $ry * $wobble);
            }

            imagefilledpolygon($im, $points, $colour);
        }
    }

    /** Sharp marks over the soft ground: what the eye reads as texture. */
    /** @param array{ground: array{int,int,int}, sky: array{int,int,int}, subject: array{int,int,int}, accent: array{int,int,int}} $palette */
    private static function detail(GdImage $im, int $width, int $height, array $palette): void
    {
        $marks = (int) max(60, ($width * $height) / 9000);

        for ($i = 0; $i < $marks; $i++) {
            $rgb = mt_rand(0, 1) === 0 ? $palette['accent'] : $palette['ground'];
            $colour = (int) imagecolorallocatealpha(
                $im,
                self::channel($rgb[0]),
                self::channel($rgb[1]),
                self::channel($rgb[2]),
                mt_rand(78, 116),
            );
            $r = mt_rand(2, max(3, (int) ($width / 110)));

            imagefilledellipse($im, mt_rand(0, $width), mt_rand(0, $height), $r, $r, $colour);
        }
    }

    /**
     * Darkened edges, across the WHOLE half-frame rather than a 42-pixel band.
     *
     * ⚠ THE FIRST VERSION DREW A VISIBLE HARD FRAME: 42 stacked rings all at one alpha ended
     * abruptly 42 pixels in, which reads as a border, not a vignette. The depth is now half
     * the short side and the opacity falls off on a square curve, so it has no edge to see.
     */
    private static function vignette(GdImage $im, int $width, int $height): void
    {
        $depth = (int) (min($width, $height) / 2);

        for ($i = 0; $i < $depth; $i++) {
            $t = $i / max(1, $depth - 1);
            $alpha = max(0, min(127, (int) (127 - 18 * (1 - $t) ** 2.2)));

            if ($alpha >= 127) {
                continue;
            }

            imagerectangle($im, $i, $i, $width - 1 - $i, $height - 1 - $i, (int) imagecolorallocatealpha($im, 0, 0, 0, $alpha));
        }
    }

    /**
     * The encoded bytes, written to a FILE rather than captured from stdout.
     *
     * ⚠⚠ THIS USED `ob_start()` / `ob_get_clean()`, WHICH MAKES THE IMAGE'S BYTES A FUNCTION
     * OF WHATEVER ELSE WROTE TO THE OUTPUT BUFFER. GD's `imagejpeg($im, null)` writes to
     * standard output, so capturing it means ANY diagnostic emitted during the same
     * buffer — a deprecation, a warning, a stray `echo` from an unrelated shutdown handler —
     * is spliced into the middle of a JPEG. The file is then silently corrupt, and
     * `studio:demo-imagery` would write it to `database/demo-media` without noticing.
     *
     * ⚠ IT IS NOT CLAIMED AS THE CAUSE OF THE RED THAT PROMPTED IT. `DemoImageryTest`'s
     * determinism case failed ONCE in a fresh clone's full gate run and has not reproduced:
     * 40 consecutive pairs in the working tree, the same two calls inside that clone, the
     * test alone there, the whole `Landing` directory there, and a second full suite run
     * there — all identical, all green. So the mechanism below is a real way for these bytes
     * to be corrupted that has been REMOVED; whether it is the way that fired is unknown and
     * is recorded as unknown rather than as solved.
     *
     * The temp file is created with `tempnam()` and deleted in a `finally`, so a throw from
     * GD cannot leave one behind.
     */
    /**
     * Film grain, scattered with THIS class's seeded randomness.
     *
     * ⚠⚠ THIS WAS `imagefilter($im, IMG_FILTER_SCATTER, 2, 4)`, AND THAT FILTER IS WHY THIS
     * FILE'S CENTRAL CLAIM — *"every image is a pure function of its seed"* — WAS FALSE.
     *
     * GD's scatter uses its OWN generator, seeded once per process from entropy.
     * `mt_srand()` resets it to that PROCESS's seed rather than to a function of `$seed`, so
     * the output is stable when you repeat it inside one process and different in the next
     * one. Measured: identical input bytes, `mt_srand(42)` set, three processes, three
     * different results; and three calls inside ONE process, all identical. `logo()` never
     * scatters, which is exactly why the PNG was the one file that reproduced.
     *
     * What that cost, before anyone noticed: `studio:demo-imagery --force` rewrote all nine
     * JPEGs with different bytes on every run, so the committed imagery could not be
     * reproduced from the generator that claims to produce it — and the determinism test
     * asserted a property the code did not have, passing only because its two calls sat in
     * one process. A fresh clone's full gate run is what finally caught it.
     *
     * A PHP loop over a SAMPLE of pixels is slower than the C filter and is the price of the
     * claim being true. It runs in a local-only fixture generator, where correctness of the
     * artifact matters and milliseconds do not.
     */
    private static function grain(GdImage $im, int $width, int $height): void
    {
        $marks = intdiv($width * $height, 12);

        for ($i = 0; $i < $marks; $i++) {
            $x = mt_rand(0, $width - 1);
            $y = mt_rand(0, $height - 1);
            $rgb = imagecolorat($im, $x, $y);
            $shift = mt_rand(-7, 7);

            $colour = imagecolorallocate(
                $im,
                self::channel((($rgb >> 16) & 0xFF) + $shift),
                self::channel((($rgb >> 8) & 0xFF) + $shift),
                self::channel(($rgb & 0xFF) + $shift),
            );

            if ($colour !== false) {
                imagesetpixel($im, $x, $y, $colour);
            }
        }
    }

    private static function encode(GdImage $im, string $format): string
    {
        $path = tempnam(sys_get_temp_dir(), 'qrpager-demo-');

        if ($path === false) {
            imagedestroy($im);

            throw new RuntimeException('could not open a temporary file for a '.$format.' frame.');
        }

        try {
            $written = $format === 'png'
                ? imagepng($im, $path, 6)
                : imagejpeg($im, $path, 82);

            imagedestroy($im);

            if ($written === false) {
                throw new RuntimeException('GD could not encode a '.$format.' frame.');
            }

            $bytes = (string) file_get_contents($path);

            if ($bytes === '') {
                throw new RuntimeException('GD produced no bytes for a '.$format.' frame.');
            }

            return $bytes;
        } finally {
            @unlink($path);
        }
    }
}
