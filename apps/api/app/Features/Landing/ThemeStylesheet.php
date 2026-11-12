<?php

declare(strict_types=1);

namespace App\Features\Landing;

use App\Domain\Landing\LandingTemplate;
use App\Domain\Landing\ThemeTokens;

/**
 * ================================================================================
 * THE ONE FILE UNDER app/ THAT EMITS A CSS DECLARATION, AND THE GUARD ENFORCES IT
 * ================================================================================
 *
 * `composer guard` rule 11 is decision D6's third mechanism. It refuses, anywhere under
 * `app/`:
 *
 *   11a  a `<style` literal -- no class may carry its own stylesheet;
 *   11b  a CSS RULE-SET -- a selector followed by `{` -- which is what styling a
 *        COMPONENT looks like in CSS, and is the thing "theme overrides tokens only,
 *        never components" forbids. THIS FILE IS NOT EXEMPT from 11b: it emits
 *        DECLARATIONS and never a rule, which is the whole distinction;
 *   11c  a theme token name outside ThemeTokens, so the list cannot be copied.
 *
 * What this class produces is a declaration list for ONE attribute:
 *
 *     --color-background:#ffffff;--font-body:system-ui, sans-serif
 *
 * which the template puts in `style="{{ … }}"` on the root element, Blade-escaped. The
 * page's actual rules live in the template and reference `var(--color-background)`, so a
 * theme can change a VALUE and can never introduce, remove or re-target a rule.
 *
 * WHY AN ATTRIBUTE AND NOT A `<style>` BLOCK. Blade's `{{ }}` escapes for HTML, which is
 * correct inside an attribute and WRONG inside `<style>` -- CSS is not HTML, and a `<`
 * escaped to `&lt;` inside a stylesheet is a broken rule rather than a safe one. Putting
 * the only tenant-derived CSS in an attribute means the one escaping function in play is
 * the right one for where the value lands, and `{!! !!}` never appears (D5).
 *
 * The values cannot carry a quote in any case: ThemeTokens' patterns admit hex colours,
 * bounded lengths and four font keys, none of which contains one. Two independent halves
 * again -- the validator and the escaping -- as everywhere else in this task.
 */
final class ThemeStylesheet
{
    /**
     * The declaration list for a validated theme map.
     *
     * Only tokens the tenant actually set are emitted — unless the page is being rendered in
     * an ARRANGEMENT, in which case the absent ones are filled from the generated token layer
     * before anything is emitted. See `$template` below.
     *
     * @param  array<string, string>  $theme
     * @param  LandingTemplate|null  $template  the arrangement this page is rendered in, or
     *                                          null for a caller that has no document — which
     *                                          emits the tenant's own values and nothing else
     *
     * ⚠ THE ARRANGEMENT DECIDES WHETHER DEFAULTS ARE EMITTED, AND IT IS NOT A STYLE CHOICE.
     *
     * `QRPAGER-46`'s invariant requires the three arrangements to reference no token the
     * resolver does not emit, because their rules carry **no `var()` fallback literal** —
     * `R-268` is what a fallback literal costs (one token, two greys, live). So for all
     * three, every token gets a value: the tenant's if they set one, otherwise
     * `ThemeDefaults`'.
     *
     * ⚠⚠ **IT USED TO ASK THE ARRANGEMENT, AND `QRPAGER-47` MADE THAT QUESTION VACUOUS.**
     * `LandingTemplate::resolvesEveryToken()` answered false for exactly `classic` and
     * `editorial` — the two whose rules carry `var()` fallback literals, and whose published
     * bytes `QRPAGER-33` held stable to the byte. Part A retires both cases, so every
     * remaining arrangement answers true and the method is GONE rather than left returning a
     * constant: a predicate with one possible answer is a branch a reader has to check and a
     * test has to cover for nothing.
     *
     * So the condition is now *"is there an arrangement at all"*, and the `null` arm is not
     * dead code — `ThemeTokenTest` drives this function with a bare theme and no arrangement
     * precisely to assert what a tenant's own values become without any defaults underneath.
     */
    public static function declarations(array $theme, ?LandingTemplate $template = null): string
    {
        if ($template !== null) {
            $theme = ThemeDefaults::fill($theme);
        }

        $declarations = [];

        foreach ($theme as $name => $value) {
            // Validated once more at the emission point. LandingDocument has already
            // refused anything else, so this can only ever be a no-op -- and that is the
            // point: it is the last line of defence before a tenant value becomes CSS,
            // and it costs nothing. A control that is only correct because its caller is
            // correct is not a control.
            if (! ThemeTokens::accepts($name, $value)) {
                continue;
            }

            $declarations[] = ThemeTokens::property($name).':'.ThemeTokens::cssValue($name, $value);
        }

        return implode(';', $declarations);
    }
}
