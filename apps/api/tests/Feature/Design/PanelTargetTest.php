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
 * Controls whose TOUCH TARGET IS A WRAPPING LABEL rather than their own box.
 *
 * ⚠ THIS EXISTS BECAUSE `targets.css` CONFLATED TWO THINGS AND THIS FILE HELPED IT. A
 * radio input was floored to 44px on both axes and the comment that put it there said *"a
 * radio's hit area IS its own box -- the label beside it is not part of the control"*, which
 * is false: Filament renders `<label class="fi-fo-radio-label">` AROUND the input, so the
 * whole row selects the option. The floor was therefore deciding how big the circle is
 * PAINTED, which `§12` has no opinion about -- it requires a 44px TARGET. Muhammad's ruling
 * of 2026-11-10 separates them, and `QRPAGER-44` moves the floor to the label.
 *
 * ⚠ IT IS A MAP AND NOT A SKIP LIST, which is the whole point. An entry does not excuse
 * the control from the bar; it says WHICH element has to carry it, and the assertion below
 * then demands that element be floored in `targets.css`. Deleting `.fi-fo-radio-label` from
 * that file reddens here, so the two halves of the ruling cannot drift apart.
 *
 * @return array<string, string> control class => the class that carries its 44px floor
 */
function labelledTargets(): array
{
    return [
        'fi-radio-input' => 'fi-fo-radio-label',
        /*
         * The arrangement picker's radio, visually hidden at 1px with `clip-path: inset(50%)`
         * (`studio/theme.css` `.studio-arrangement__input`) so the whole card selects the
         * option -- `ArrangementField.php:160` renders `<label class="studio-arrangement">`
         * around it, which is the same shape as Filament's radio above and takes the same
         * answer. Surfaced by `QRPAGER-48` `§11` QA H-3 widening the derivation below to the
         * panel's own `studio-` classes; it was under no check before, not merely unfloored.
         */
        'studio-arrangement__input' => 'studio-arrangement',
    ];
}

/**
 * The selectors `targets.css` actually applies the floor to, read from the file.
 *
 * Rule by rule rather than by a flat class sweep, because the question here is not *"does
 * this name appear"* -- `.fi-radio-input` still appears, carrying its painted size -- it is
 * *"does this name appear in a rule that sets `var(--target-min)`"*.
 *
 * @return list<string>
 */
function flooredSelectors(): array
{
    $css = (string) file_get_contents(resource_path('css/filament/targets.css'));
    $code = (string) preg_replace('#/\*[\s\S]*?\*/#', ' ', $css);

    $floored = [];

    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $code, $rules, PREG_SET_ORDER);

    foreach ($rules as $rule) {
        if (! str_contains($rule[2], 'var(--target-min)')) {
            continue;
        }

        foreach (preg_split('/\s*,\s*/', trim($rule[1])) as $selector) {
            $selector = trim((string) $selector);

            if ($selector !== '') {
                $floored[] = $selector;
            }
        }
    }

    return $floored;
}

/**
 * Every interactive element across the six chrome routes, as its list of Filament classes.
 *
 * ⚠⚠ THIS LOOP CANNOT SEE THE TOPBAR, THE STATUS PLATE OR THE FOOTER, AND THAT IS STATED
 * HERE RATHER THAN DISCOVERED AGAIN. Measured 2026-11-13: with `/platform` requested first
 * — which is this loop's own order, `PanelRoutes::chrome()` being sorted — every
 * `QRPAGER-48` chrome marker is absent from all four `/studio` renders
 * (`data-topbar-restaurant`, `data-topbar-chip`, `data-topbar-person`, `data-back-to-staff`,
 * `data-panel-language`, `data-status-plate`, `data-footer-bar`). Request a studio route
 * FIRST in the same test and all seven appear. The page body renders either way — that is
 * how `.studio-tab` and `.studio-colour__clear` reach the set below — so this is the panel's
 * render HOOKS not registering, not a broken request.
 *
 * It is a property of the TEST CLIENT and not of the runtime, which is why it is a note and
 * not a finding: `FilamentManager::bootCurrentPanel()` latches on `$isCurrentPanelBooted`
 * and `Panel::boot()` is what calls `registerRenderHooks()`, while the manager is bound with
 * `$this->app->scoped('filament', …)` (`FilamentServiceProvider.php:72`) — so a real request
 * boundary resets it, and `config/octane.php` wires Octane's
 * `FlushTemporaryContainerInstances` (which calls `forgetScopedInstances()`) on
 * `OperationTerminated`. Several `get()` calls inside ONE test share the first one's booted
 * panel. `app()->forgetScopedInstances()` between iterations was tried and does NOT restore
 * the hooks, so the cause is further in than the latch; it was not chased, because:
 *
 * ⚠ THE CHROME'S CONTROLS ARE FLOORED AND PROVEN ELSEWHERE, BY A BETTER INSTRUMENT.
 * `browser/studio.spec.ts` sweeps every interactive box in the live chrome at 390px and
 * measures the rendered geometry rather than asking whether a selector is named in a file —
 * which is what caught the same defect on the staff side of this ticket
 * (`browser/staff.spec.ts:128`, commit `710a596`). `targets.css` carries the floor for
 * `.studio-topbar__back` and `.studio-lang__choice` with that reasoning beside it. What THIS
 * file still owns is every control in the page BODY, which is where the widened prefix below
 * found three that nothing had ever checked.
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

    // ⚠ SEVEN SINCE `QRPAGER-40` ADDED `/studio/theme` — it was six, and the count is a
    // FLOOR on the derivation rather than a description of the panel, so it moves with the
    // panel and a route that stopped rendering still fails here.
    expect($chrome)->toHaveCount(7);

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
                if (! is_string($class) || str_contains($class, ':')) {
                    continue;
                }

                /*
                 * ⚠ TWO PREFIXES SINCE `QRPAGER-48` `§11` QA H-3, AND IT USED TO BE `fi-`
                 * ALONE. That was accurate only while every control a panel route rendered
                 * was a Filament component. `QRPAGER-48`'s chrome renders two of its own --
                 * `<a class="studio-topbar__back">` and `<button class="studio-lang__choice">`
                 * -- and both arrived under `§12`'s floor with NOTHING going red, because a
                 * class this filter drops cannot appear in `$uncovered` below. The derivation
                 * was not one entry behind; it was structurally unable to see the control.
                 *
                 * That is the same shape as `FixtureScriptGuardTest`'s `bin/*` glob against
                 * the same ticket's artisan seeder (QA M-3), found in the same round. A
                 * derived set is only derived over the ground it actually walks.
                 *
                 * A LIST RATHER THAN A WIDENED PATTERN. Admitting every class would pull in
                 * utility and state names -- the modifier problem this function's docblock
                 * already records -- so a namespace joins by being named, and the panel has
                 * exactly two.
                 */
                $owned = false;

                foreach (['fi-', 'studio-'] as $prefix) {
                    if (str_starts_with($class, $prefix)) {
                        $owned = true;

                        break;
                    }
                }

                if (! $owned) {
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
    // The selectors the floor actually applies to, read from the file rather than listed
    // here -- a second copy of that list is what this test exists to prevent.
    //
    // ⚠ DERIVED FROM THE RULES THAT DECLARE `var(--target-min)`, AND IT USED TO BE EVERY
    // RULE IN THE FILE (`preg_match_all('/^\.([a-z0-9-]+)\s*[,{]/m', ...)`). That was
    // accurate only while every rule in `targets.css` WAS a floor, which stopped being true
    // at `QRPAGER-44`: that ticket adds a rule painting `.fi-radio-input` at `--space-24`,
    // and under the old derivation THAT rule reported the radio as covered -- a control
    // marked as meeting the 44px bar by the very line that shrank it below it.
    //
    // MEASURED RATHER THAN REASONED: with `.fi-fo-radio-label` deleted from the floor, so
    // the radio genuinely had no 44px target anywhere, the old derivation still passed this
    // case. It is the dead-control shape, and it was introduced and caught in the same hour.
    $covered = array_map(
        static fn (string $selector): string => ltrim($selector, '.'),
        flooredSelectors(),
    );

    expect(count($covered))->toBeGreaterThan(8, 'targets.css applies to almost nothing');

    $uncovered = [];
    $labelled = labelledTargets();

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

        // Or its target is a wrapping label -- and then THAT element must be floored, which
        // is a demand rather than an exemption. See `labelledTargets()`.
        $carrier = null;

        foreach ($element as $class) {
            if (array_key_exists($class, $labelled)) {
                $carrier = $labelled[$class];

                break;
            }
        }

        if ($carrier !== null && in_array($carrier, $covered, true)) {
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

/*
 * ================================================================================
 * THE TARGET AND THE PAINT ARE TWO DECISIONS — `QRPAGER-44`, Muhammad's ruling
 * ================================================================================
 *
 * The ruling: *"§12 requires a 44px TOUCH TARGET. It says nothing about the painted size.
 * The current implementation conflates them by putting --target-min on the control itself."*
 *
 * This is the pair that cannot be half-applied. Put the floor back on `.fi-radio-input` and
 * the first expectation's partner fails; take it off `.fi-fo-radio-label` and the control
 * loses its target with nothing to say so. Both halves are asserted here, against the file
 * rather than against a second copy of the list.
 */
it('puts the radio target on its label and the painted circle on the space scale', function (): void {
    $floored = flooredSelectors();

    // A bound first, so a regex that matched nothing cannot make the two claims below pass
    // by vacancy -- the dead-control shape this suite has recorded four times.
    expect(count($floored))->toBeGreaterThan(5);

    // The LABEL carries the bar, because the label is what a pointer hits.
    expect(in_array('.fi-fo-radio-label', $floored, true))->toBeTrue();

    // And the INPUT does not -- otherwise the painted size below is inert and this file
    // would read as though it had been applied. `.studio-colour-input` records that trap.
    expect(in_array('.fi-radio-input', $floored, true))->toBeFalse();

    $css = (string) file_get_contents(resource_path('css/filament/targets.css'));
    $code = (string) preg_replace('#/\*[\s\S]*?\*/#', ' ', $css);

    // The circle is painted from the space scale on both axes, and never from a literal.
    expect($code)->toMatch('/\.fi-radio-input\s*\{[^}]*inline-size:\s*var\(--space-\d+\)/')
        ->and($code)->toMatch('/\.fi-radio-input\s*\{[^}]*block-size:\s*var\(--space-\d+\)/');
});
