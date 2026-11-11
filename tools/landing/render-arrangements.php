<?php

declare(strict_types=1);

/*
 * ================================================================================
 * ONE DOCUMENT UNDER EVERY ARRANGEMENT — `QRPAGER-46`'s computed-value instrument
 * ================================================================================
 *
 * `QRPAGER-46` shipped two defects that every test it wrote was blind to, and the only
 * control that caught either was a human looking at a render: a section heading computing at
 * the page title's size in all three arrangements, and `rail` rendering 6,618px tall because
 * `display:block` on the shared `.grid` made every photograph full-measure. Both are COMPUTED
 * values — a font size and a document height — and the ticket's own invariant says *"assert
 * on structure … never on pixels"*, so the structural tests were right and blind at the same
 * time. The `e2e` suite cannot help either: its fixture document names no template, so every
 * preview it renders is `classic` (`R-303`).
 *
 * Muhammad's instruction of 2026-11-11: *"Make that control repeatable instead of leaving it
 * to whoever remembers to look — keep the capture step as a command, not a one-off."*
 * `browser/landing-templates.spec.ts` consumes what this writes, asserts the two computed
 * properties that bit, and captures the twelve photographs. `pnpm run gate:templates` is both
 * halves, and `bin/gate` and CI both carry that one command.
 *
 * ================================================================================
 * ⚠ IT IS A TOOL AND NOT AN ARTISAN COMMAND, AND `composer deptrac` DECIDED THAT
 * ================================================================================
 *
 * It was written as `App\Features\Landing\Console\RenderArrangementsCommand` first, and
 * deptrac refused it with three violations: *"App\Features\Landing\Console\
 * RenderArrangementsCommand must not depend on Tests\Support\LandingFixtures"*. The rule is
 * right and weakening it for an instrument would be the worst possible reason to weaken an
 * architecture rule — the document this renders IS the test fixture, deliberately, because a
 * second sample document would be a second source for *"the document"* and the whole point is
 * to render the SAME one every test in this feature uses.
 *
 * So it lives in `tools/`, outside the layers deptrac governs, in the same posture as
 * `bin/fresh-clone-proof`: a development instrument that may use dev dependencies, that boots
 * the application rather than being part of it, and that refuses to run outside local/testing.
 *
 * ================================================================================
 * ⚠ ONE DOCUMENT, AND THE ARRANGEMENT IS THE ONLY THING THAT VARIES
 * ================================================================================
 *
 * A measurement that changed the content between arrangements would be measuring the content.
 * The document is `LandingFixtures::fullPage()` with its photographs multiplied to SIX,
 * because an arrangement whose whole claim is a photo grid cannot be judged, or bounded in
 * height, from one image.
 *
 * ⚠ AND TWO THEME STATES, because they answer different questions: the fixture tenant's own
 * stored theme (somebody's taste, which is what most published pages are), and NO theme at
 * all — which is what this ticket is for, and where all twelve tokens come from
 * `ThemeDefaults`, i.e. from Rail.
 *
 * USAGE:  php tools/landing/render-arrangements.php [OUT_DIR]
 */

use App\Domain\Landing\LandingDocument;
use App\Domain\Landing\LandingTemplate;
use App\Domain\Tenancy\TenantLanguage;
use App\Features\Landing\LandingRenderer;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\LandingFixtures;
use Tests\Support\StubMediaReferences;

$api = dirname(__DIR__, 2).'/apps/api';

require $api.'/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require $api.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/*
 * ⚠ REFUSED OUTSIDE A DEVELOPMENT ENVIRONMENT, and it fails CLOSED like every other guard in
 * this repository: this loads `Tests\Support`, which exists only with dev dependencies, and
 * it writes files. `FixtureScriptGuardTest`'s precedent is that a guard which refuses every
 * environment it does not recognise is the only kind worth having.
 */
if (! in_array($app->environment(), ['local', 'testing'], true)) {
    fwrite(STDERR, "render-arrangements is a development instrument and refuses to run as {$app->environment()}.\n");

    exit(1);
}

if (! class_exists(LandingFixtures::class)) {
    fwrite(STDERR, "Tests\Support\LandingFixtures is not loadable: install dev dependencies.\n");

    exit(1);
}

$out = $argv[1] ?? dirname(__DIR__, 2).'/storage/landing-preview';

if (! is_dir($out) && ! mkdir($out, 0o755, true) && ! is_dir($out)) {
    fwrite(STDERR, "could not create {$out}\n");

    exit(1);
}

/** @var array<string, array{string, array{int<0, 255>, int<0, 255>, int<0, 255>}, positive-int, positive-int}> $photographs */
$photographs = [
    'hero' => ['THE COUNTER', [107, 59, 30], 1600, 700],
    'dish1' => ['COD', [138, 90, 43], 1200, 900],
    'dish2' => ['HADDOCK', [74, 46, 28], 1200, 900],
    'room1' => ['THE ROOM', [16, 73, 76], 1200, 900],
    'room2' => ['THE YARD', [140, 47, 39], 1200, 900],
    'room3' => ['THE PASS', [176, 131, 48], 1200, 900],
];

/**
 * ⚠ THE TYPES ABOVE ARE NARROWED AND THE ALLOCATION BELOW IS CHECKED, BECAUSE `composer stan`
 * REFUSED THE OBVIOUS VERSION while this was still app code — five errors across
 * `imagecreatetruecolor`, `imagecolorallocate` and the two draws. Each was a real statement
 * about GD: a dimension below one and a channel outside 0–255 are runtime failures, and
 * `imagecolorallocate` returns `false` when a palette is exhausted, which would then be passed
 * as a colour. The refusal is what found the unchecked `false`, and the checks are kept here
 * even though `tools/` is outside PHPStan's scanned tree — a guard is not for the scanner.
 */
$photograph = static function (string $path, string $label, array $rgb, int $width, int $height): void {
    $image = imagecreatetruecolor(max(1, $width), max(1, $height));
    $ground = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
    $ink = imagecolorallocate($image, 252, 252, 250);

    if ($ground === false || $ink === false) {
        throw new RuntimeException("GD could not allocate a colour for {$path}");
    }

    imagefilledrectangle($image, 0, 0, $width, $height, $ground);
    imagestring($image, 5, 24, 24, $label, $ink);
    imagepng($image, $path);
    imagedestroy($image);
};

$ids = [];
$assets = [];
$index = 10;

foreach ($photographs as $key => [$label, $rgb, $width, $height]) {
    // Fixed ids rather than generated ones: the same document twice means the same bytes
    // twice, which is the property `LandingRenderer` is built on.
    $ids[$key] = sprintf('01a1c28c-0000-7000-8000-00000000000%x', $index++);
    $photograph($out.'/'.$key.'.png', $label, $rgb, $width, $height);
    $assets[$ids[$key]] = $key.'.png';
}

$document = LandingFixtures::fullPage($ids['hero']);

foreach ($document['blocks'] as $i => $block) {
    if ($block['type'] === 'menu_highlights') {
        $document['blocks'][$i]['fields']['items'][0]['image'] = $ids['dish1'];
        $document['blocks'][$i]['fields']['items'][1]['image'] = $ids['dish2'];
    }

    if ($block['type'] === 'gallery') {
        $document['blocks'][$i]['fields']['images'] = [
            ['image' => $ids['room1'], 'caption' => 'The room'],
            ['image' => $ids['room2'], 'caption' => 'The yard'],
            ['image' => $ids['room3'], 'caption' => 'The pass'],
            ['image' => $ids['hero'], 'caption' => 'The counter'],
        ];
    }
}

$written = 0;

foreach (['tenant' => $document['theme'] ?? [], 'default' => []] as $state => $theme) {
    foreach (LandingTemplate::cases() as $template) {
        $html = app(LandingRenderer::class)->render(
            LandingDocument::fromInput(
                array_replace($document, ['template' => $template->value, 'theme' => $theme]),
                new StubMediaReferences(array_values($ids)),
            ),
            'olive.pages.qrpager.test',
            'Olive',
            $assets,
            TenantLanguage::English,
        );

        file_put_contents($out.'/'.$state.'-'.$template->value.'.html', $html);
        $written++;
    }
}

printf("landing: %d page(s) and %d photograph(s) in %s\n", $written, count($photographs), $out);
