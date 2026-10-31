<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Filament\Support\View\Components\BadgeComponent;
use Filament\Support\View\Components\ButtonComponent;
use Filament\Support\View\Components\Contracts\HasColor;
use Filament\Support\View\Components\DropdownComponent\HeaderComponent;
use Filament\Support\View\Components\DropdownComponent\ItemComponent;
use Filament\Support\View\Components\IconButtonComponent;
use Filament\Support\View\Components\LinkComponent;
use Filament\Support\View\Components\ToggleComponent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * SR-4's ASSERTION: FILAMENT'S OWN RESOLVER MUST CLEAR AA ON EVERY FAMILY
 * ================================================================================
 *
 * The ruling's last line is *"Assert the resulting pair."* This is that assertion, and it has
 * to live in PHP because the resolver does: `ButtonComponentColorMap` takes the whole palette,
 * runs `Color::calculateContrastRatio` over it at the 4.5 bar, and PICKS bg/hover/text per
 * component from candidate triplets. A ladder is not a design until that resolver has had its
 * say — which is exactly what the one-liner failed: `->colors(['primary' => '#0B6B4F'])`
 * resolved a dark pair of **3.43:1**.
 *
 * ⚠ THE COMPONENTS ARE DRIVEN, NOT RECONSTRUCTED. Each of Filament's seven colourable
 * components configures its own map -- `ButtonComponent` adds three background candidates,
 * `BadgeComponent` two text slots, and so on -- and copying those configurations here would
 * be a second source that goes stale at the next Filament minor. So this instantiates the
 * vendor's own component and calls `HasColor::getColorMap($ladder)`, which is the one public
 * entry point to that logic.
 *
 * `panel-palette.test.mjs` owns the other half -- the ladder's shape, its anchors and its two
 * caps -- and says in its own header that it cannot prove this one.
 */

/** A platform admin, inserted as the owner because the app role holds no INSERT on the flag. */
function paletteAdmin(): User
{
    $id = (string) Str::uuid7();

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Palette Admin',
        'email' => 'palette-'.Str::lower(Str::random(10)).'@example.test',
        'is_platform_admin' => true,
    ]);

    $user = User::query()->findOrFail($id);
    assert($user instanceof User);

    return $user;
}

/** Every colourable component Filament ships, so no family is asserted against one of them. */
function colourableComponents(): array
{
    return [
        ButtonComponent::class,
        IconButtonComponent::class,
        BadgeComponent::class,
        LinkComponent::class,
        ToggleComponent::class,
        HeaderComponent::class,
        ItemComponent::class,
    ];
}

/** The oklch form of one family's ladder, which is what Filament's resolver consumes. */
function oklchLadder(string $family): array
{
    $palette = Filament::getPanel('platform')->getColors();

    expect($palette)->toHaveKey($family);

    $ladder = [];

    foreach ((array) $palette[$family] as $shade => $hex) {
        $ladder[(int) $shade] = str_starts_with((string) $hex, 'oklch(')
            ? (string) $hex
            : Color::convertToOklch((string) $hex);
    }

    return $ladder;
}

it('registers exactly the six families Filament would otherwise default', function (): void {
    // A family we do not supply keeps `ColorManager::DEFAULT_COLORS` -- amber for primary,
    // red for danger, blue for info -- and the panel would look restyled while carrying none
    // of the approved values. That is the dead-control shape §4a records four instances of,
    // so the set is asserted exactly rather than bounded.
    foreach (['platform', 'studio'] as $id) {
        $families = array_keys(Filament::getPanel($id)->getColors());
        sort($families);

        expect($families)->toBe(['danger', 'gray', 'info', 'primary', 'success', 'warning'], "{$id} panel");
    }
});

it('puts the approved value on the shade a primary button actually renders', function (): void {
    // ⚠ THE DEFECT THE RULING WAS ABOUT, inverted into an assertion. The one-liner's ladder
    // contained `#0B6B4F` at NO shade; this one holds it at 600, and 600 is the shade
    // `ButtonComponent`'s first light candidate asks for -- so the approved brand is
    // literally what a primary button paints.
    $ladder = oklchLadder('primary');
    $map = (new ButtonComponent)->getColorMap($ladder);

    expect($map['bg'])->toBe(600)
        ->and($ladder[600])->toBe(Color::convertToOklch('#0B6B4F'));
});

it('clears WCAG AA on the resolved button pair, in BOTH modes, for every family', function (): void {
    // ⚠ THIS IS THE RULING'S OWN ASSERTION. `->colors()` with a bare hex measured 3.43:1 in
    // dark mode, and AA is a gate clause rather than a preference.
    //
    // The ratio is computed with Filament's own `calculateContrastRatio` over the shades
    // Filament itself chose, so the number is the one the framework would stand behind -- not
    // a reimplementation of it that could disagree.
    $failures = [];
    $measured = 0;

    foreach (array_keys(Filament::getPanel('platform')->getColors()) as $family) {
        $ladder = oklchLadder($family);
        $map = (new ButtonComponent)->getColorMap($ladder);

        foreach ([['bg', 'text', 'light'], ['dark:bg', 'dark:text', 'dark']] as [$bgSlot, $textSlot, $mode]) {
            $bg = $ladder[$map[$bgSlot]];
            // Shade 0 is Filament's own "white" sentinel, resolved by
            // `resolveColorShadeFromPalette` -- not a palette entry.
            $text = $map[$textSlot] === 0 ? 'oklch(1 0 0)' : $ladder[$map[$textSlot]];

            $ratio = Color::calculateContrastRatio($bg, $text);
            $measured++;

            if ($ratio < Color::WCAG_AA_TEXT) {
                $failures[] = sprintf(
                    '%s %s: shade %s on shade %s is %.2f, below %.1f',
                    $family, $mode, (string) $map[$textSlot], (string) $map[$bgSlot], $ratio, Color::WCAG_AA_TEXT
                );
            }
        }
    }

    // Twelve pairs: six families, two modes. Stated so a family dropped from the palette
    // cannot make this case pass by having nothing to measure.
    expect($measured)->toBe(12);
    expect($failures)->toBe([]);
});

it('refuses the bare-hex ladder, which is what makes the case above an assertion', function (): void {
    // THE FALSIFICATION, driven through the same resolver with the same bar. If this ever
    // stops failing, `Color::generatePalette()` has changed and the ruling should be re-read
    // rather than the ladder kept out of habit.
    $generated = [];

    foreach (Color::generatePalette('#0B6B4F') as $shade => $value) {
        $generated[(int) $shade] = (string) $value;
    }

    $map = (new ButtonComponent)->getColorMap($generated);

    // The approved value is at no shade of the generated ladder -- the defect itself.
    expect($generated)->not->toContain(Color::convertToOklch('#0B6B4F'));

    $darkBg = $generated[$map['dark:bg']];
    $darkText = $map['dark:text'] === 0 ? 'oklch(1 0 0)' : $generated[$map['dark:text']];
    $ratio = Color::calculateContrastRatio($darkBg, $darkText);

    expect($ratio)->toBeLessThan(Color::WCAG_AA_TEXT);
    expect(round($ratio, 2))->toBe(3.43);
});

it('resolves a map for every family through every colourable component', function (): void {
    // The other six components each configure their own slots, and `ButtonComponentColorMap`
    // throws a LogicException on a palette it cannot process. A family that resolved for a
    // button and threw for a badge would break the panel on the first notification.
    $resolved = 0;

    foreach (array_keys(Filament::getPanel('platform')->getColors()) as $family) {
        $ladder = oklchLadder($family);

        foreach (colourableComponents() as $component) {
            $instance = app($component);
            assert($instance instanceof HasColor);

            $map = $instance->getColorMap($ladder);

            expect($map)->not->toBe([], "{$component} resolved nothing for {$family}");

            foreach ($map as $slot => $shade) {
                // Every slot resolves to a shade the ladder HAS, or to white. A slot naming a
                // shade that is absent is how `probeTextLightness` silently falls back to
                // white, which is the failure mode a partial ladder would have had.
                expect($shade === 0 || array_key_exists((int) $shade, $ladder))
                    ->toBeTrue("{$component} resolved {$family} {$slot} to shade {$shade}, which the ladder lacks");
            }

            $resolved++;
        }
    }

    // Six families times seven components.
    expect($resolved)->toBe(42);
});

it('emits the approved value into the rendered panel, where a browser reads it', function (): void {
    // The end of the chain: the ladders are generated in Node, written to PHP, registered on
    // the panel, and emitted by `AssetManager::renderStyles()` as custom properties in the
    // document head. Asserted at the last link, because every one before it could be right
    // and the page still carry Filament's defaults.
    $html = (string) $this->actingAs(paletteAdmin())->get('/platform')->getContent();

    // ⚠ MATCHED WITH \s* RATHER THAN A LITERAL SPACE, because the emitted form carries NONE:
    // measured, the document has `--primary-600:oklch(0.47 0.094 166.529);`. The first
    // version of this assertion spelled a space after the colon and failed on the
    // whitespace while every value was already correct. The VALUE is what this case is
    // about, so the whitespace is tolerated and the value is exact.
    //
    // `warning`'s approved value sits at 400 and not 600, because `alert-fill`'s own approval
    // bounds it to text at >= 24px -- asserted at its own shade rather than skipped.
    foreach ([
        'primary' => [600, '#0B6B4F'],
        'danger' => [600, '#96351A'],
        'success' => [600, '#3DDC97'],
        'warning' => [400, '#E4572E'],
        'info' => [600, '#5A6A62'],
        'gray' => [600, '#5A6A62'],
    ] as $family => [$anchor, $hex]) {
        $oklch = Color::convertToOklch($hex);

        expect($html)->toMatch('/--'.$family.'-'.$anchor.':\s*'.preg_quote($oklch, '/').';/');
    }

    // And Filament's own defaults are gone: amber is what `primary` was.
    foreach (Color::Amber as $shade => $value) {
        expect(str_contains($html, "--primary-{$shade}: {$value};"))
            ->toBeFalse("the panel still emits Filament's amber at primary-{$shade}");
    }
});
