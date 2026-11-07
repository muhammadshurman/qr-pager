<?php

declare(strict_types=1);

namespace App\Features\Landing;

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
     * Only tokens the tenant actually set are emitted. A token they left alone falls
     * through to the default in the template's own rules, which is what makes the theme
     * an OVERRIDE rather than a replacement.
     *
     * @param  array<string, string>  $theme
     */
    public static function declarations(array $theme): string
    {
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
