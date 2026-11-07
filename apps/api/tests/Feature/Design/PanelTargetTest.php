<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use App\Models\User;
use App\Platform\Panel\PanelRoutes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LandingFixtures;

/*
 * ================================================================================
 * EVERY INTERACTIVE CONTROL THE PANELS RENDER IS COVERED BY THE 44px FLOOR
 * ================================================================================
 *
 * `resources/css/filament/targets.css` applies `min-height: var(--target-min)` to Filament's
 * controls, because the browser measured them at 32-40px against `§12`'s 44px bar. The
 * selector list in that file was DERIVED from what the six chrome routes render — and a
 * derived list is a snapshot unless something keeps re-deriving it.
 *
 * ⚠ THE FAILURE THIS REFUSES: Filament adds a component, our panel renders it, the browser
 * check keeps passing because it measures four named elements, and the new control is 36px.
 * `browser/panel.spec.ts` cannot see that — it has no server and no session. This can: it
 * re-derives the set from six real renders and fails on a control the floor does not cover.
 *
 * THE INLINE EXCEPTION IS STATED AND ASSERTED, not silently skipped. `fi-link`,
 * `fi-breadcrumbs-item-label` and `fi-skip-link` are text inside a sentence, where a 44px
 * min-height breaks the line box; WCAG 2.5.8 carries that exception for that reason, and
 * `§12`'s own clause does not write one. `PLAN.md §5` holds that difference with an owner.
 */

/** A platform admin, inserted as the owner because the app role holds no INSERT on the flag. */
function targetAdmin(): User
{
    $id = (string) Str::uuid7();

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Target Admin',
        'email' => 'target-'.Str::lower(Str::random(10)).'@example.test',
        'is_platform_admin' => true,
    ]);

    $user = User::query()->findOrFail($id);
    assert($user instanceof User);

    return $user;
}

/**
 * The INLINE text targets, which take no minimum height.
 *
 * WCAG 2.5.8's own exception, and it is a closed list rather than a pattern so a new control
 * cannot join it by resembling one.
 *
 * @return list<string>
 */
function inlineTextTargets(): array
{
    return [
        'fi-link',
        'fi-breadcrumbs-item-label',
        'fi-skip-link',
        'fi-sr-only',
        // ⚠ A DISABLED CONTROL IS NOT A TOUCH TARGET, and this is the one entry that is not
        // about inline text. Filament renders `<button class="fi-disabled">` for a paginator
        // arrow at the end of its range; it has no action, takes no pointer event and is
        // removed from the tab order, so a 44px floor on it would reserve space for
        // something nobody can hit. Named here rather than covered, because the alternative
        // is a rule that enlarges dead space on every table.
        'fi-disabled',
    ];
}

/**
 * Every interactive element across the six chrome routes, as its list of Filament classes.
 *
 * ⚠ PER ELEMENT AND NOT A FLAT SET OF CLASSES, which is a correction. The first version
 * flattened every class it found and demanded the floor cover each one -- so it reported
 * `fi-color-primary`, `fi-bg-color-600`, `fi-disabled`, `fi-size-md` and `fi-ac-btn-action`
 * as uncovered controls. Those are MODIFIERS: they colour or size a control, they are never
 * the control. A floor has to cover the COMPONENT class on each element, and this returns
 * the element so the assertion can ask that question instead of a wrong one.
 *
 * @return list<list<string>>
 */
function renderedControlElements(): array
{
    LandingFixtures::bucket();

    $admin = targetAdmin();
    $tenant = test()->actingForTenant(role: TenantRole::Owner);
    $classes = [];

    // ⚠ DERIVED, NOT LISTED. This carried a typed six-element array while
    // `PanelRoutes::chrome()` already derives exactly that set structurally -- which is the
    // §11 QA round's M-4, and against PLAN §4.3's own ruling that the walk's subject list is
    // "DERIVED from that table, never hand-listed". A seventh chrome route would have been
    // invisible to this floor check, which is one of the two controls R-174 rests on.
    $chrome = PanelRoutes::chrome();

    expect($chrome)->toHaveCount(6);

    foreach (array_keys($chrome) as $uri) {
        if (str_starts_with($uri, '/studio')) {
            test()->actingForTenant(tenantId: $tenant, role: TenantRole::Owner);
        } else {
            test()->actingAs($admin);
        }

        $html = (string) test()->get($uri)->getContent();

        preg_match_all('/<(?:button|a|input|select|textarea)\b[^>]*class="([^"]*)"/', $html, $matches);

        foreach ($matches[1] as $list) {
            $element = [];

            foreach (preg_split('/\s+/', trim($list)) as $class) {
                // Variant classes (`hover:fi-…`, `dark:fi-…`) style a state, never a box.
                if (! is_string($class) || ! str_starts_with($class, 'fi-') || str_contains($class, ':')) {
                    continue;
                }

                $element[] = $class;
            }

            if ($element === []) {
                continue;
            }

            sort($element);
            // Keyed, so the same control on six pages is one subject rather than thirty.
            $classes[implode(' ', $element)] = $element;
        }
    }

    ksort($classes);

    return array_values($classes);
}

it('derives a real control set from six rendered pages', function (): void {
    $elements = renderedControlElements();
    $flat = array_merge(...$elements);

    // A bound AND two names, so a derivation that silently returned a handful cannot pass:
    // `fi-btn` and `fi-input` are on every one of these screens.
    expect(count($elements))->toBeGreaterThan(10)
        ->and(in_array('fi-btn', $flat, true))->toBeTrue()
        ->and(in_array('fi-input', $flat, true))->toBeTrue();
});

it('covers every rendered control, or names it as an inline exception', function (): void {
    $css = (string) file_get_contents(resource_path('css/filament/targets.css'));

    // The selectors the floor actually applies to, read from the file rather than listed
    // here -- a second copy of that list is what this test exists to prevent.
    //
    // ⚠ `\s*[,{]` AND NOT `[,{]`. The LAST selector of a group is followed by a space before
    // the brace, so the tighter pattern missed exactly one per group -- it reported
    // `fi-ta-header-cell-sort-btn` and `fi-user-menu-trigger` as uncovered when both are in
    // the file. A reader of that failure would have added them a second time.
    preg_match_all('/^\.([a-z0-9-]+)\s*[,{]/m', $css, $selectors);

    $covered = $selectors[1];

    expect(count($covered))->toBeGreaterThan(8, 'targets.css applies to almost nothing');

    $uncovered = [];

    foreach (renderedControlElements() as $element) {
        // ONE covered class is enough: the floor is a min-height on the element, and an
        // element matching any covered selector has it.
        if (array_intersect($element, $covered) !== []) {
            continue;
        }

        // Or the element is an inline text target, which takes no minimum by WCAG 2.5.8.
        if (array_intersect($element, inlineTextTargets()) !== []) {
            continue;
        }

        $uncovered[] = implode(' ', $element);
    }

    expect($uncovered, 'these interactive classes are rendered by a panel screen and are '.
        'neither covered by targets.css nor named as a WCAG 2.5.8 inline exception, so a '.
        'control under 44px could arrive without any check going red')->toBe([]);
});

it('states the token rather than the number, so 44 lives in one place', function (): void {
    $css = (string) file_get_contents(resource_path('css/filament/targets.css'));
    $code = (string) preg_replace('#/\*[\s\S]*?\*/#', ' ', $css);

    // ⚠ THE COMMENTS ARE STRIPPED FIRST. The file's header quotes the measured pixel sizes
    // -- 29x32, 33x36, 1280x36 -- because that measurement is why it exists, and a check
    // that read the prose would be refusing its own evidence.
    expect($code)->toContain('var(--target-min)')
        ->and($code)->not->toMatch('/\d+px/')
        ->and($code)->not->toContain('44');

    // And the token it names is real, with the value §12 asks for.
    expect((int) config('design-tokens.target_min_px'))->toBe(44);

    $tokens = (string) file_get_contents(dirname(base_path(), 2).'/packages/tokens/generated/tokens.css');
    expect($tokens)->toContain('--target-min: 44px;');
});
