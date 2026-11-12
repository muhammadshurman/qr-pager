<?php

declare(strict_types=1);

namespace App\Features\Landing;

use App\Domain\Landing\ThemeTokens;

/**
 * The value a landing token takes when the tenant has not set one — `QRPAGER-46`.
 *
 * ================================================================================
 * ⚠ WHY THIS CLASS HAS TO EXIST, WHICH IS A FINDING RATHER THAN A PREFERENCE
 * ================================================================================
 *
 * `§4b` B1 asked for each token's current value and found there is no such thing:
 * `ThemeTokens::NAMES` is *"an allow-list of names against kinds; it carries no defaults and
 * the contract defines none"*, and a token the tenant has not set is **absent from the stored
 * map**. So until this class existed, the default was the FALLBACK LITERAL in
 * `page.blade.php`'s `var()` calls — sixteen of them, which is `R-267` (the file nothing
 * scanned) and `R-268` (`--color-surface` falling back to `#f5f5f5` in one rule and
 * `#f6f6f6` in another, live on every published page).
 *
 * `QRPAGER-46`'s invariant removes the fallback rather than correcting it: *"the set of
 * tokens the template references and the set the resolver emits are EQUAL — no reference
 * with nothing behind it."* A reference with no fallback and no emitted value paints
 * nothing, so the resolver has to carry a default for every token the arrangement reads.
 *
 * ================================================================================
 * ⚠ EVERY VALUE COMES FROM THE GENERATED TOKEN LAYER. NONE IS WRITTEN HERE.
 * ================================================================================
 *
 * `config/design-tokens.php` is emitted from `packages/tokens/src/source.ts` by the same
 * generator as `tokens.css`, and `pnpm tokens:check` byte-diffs it — so a value here is the
 * product's value or the gate is red. A literal in this file would be a second source
 * (`§6.2`), and `composer guard` rule 11 and `scan-panel.mjs` both refuse one in PHP
 * anyway: `R-260` is that refusal happening to a mid-grey written into panel code.
 *
 * The mapping below is `docs/design/public/rail.html` read the way `QRPAGER-46` says to read
 * it — *"translate every literal into a token, never copy one"*. Rail's ground is `paper`,
 * its rules are `hairline`, its secondary text is `ink-mid`, and its call to action is
 * **ink on paper rather than a colour**, which is why `color.accent` resolves to `brand`.
 *
 * ================================================================================
 * ⚠ A DEFAULT IS A CONTRACT VALUE AND GOES THROUGH THE SAME GATE AS A TENANT'S
 * ================================================================================
 *
 * `values()` returns what a tenant would have STORED — `#fcfcfa`, `system`, `4px` — and not
 * the CSS a browser receives. `ThemeStylesheet` then runs it through
 * `ThemeTokens::accepts()` and `cssValue()`, exactly as it runs a tenant's own value, so a
 * default cannot take a shape the contract refuses and a font default cannot skip the
 * `STACKS` lookup. `TemplateTokenSetTest` asserts that of EVERY entry — *it gives every
 * default a value the contract itself accepts* — which is why the hex values are lower-cased
 * here: `accepts()` takes lower-case hex only, deliberately, and the token layer writes
 * `#FCFCFA`.
 *
 * ⚠ AND THAT SENTENCE FIRST CITED A TEST CLASS NAMED AFTER THIS ONE, WHICH DOES NOT EXIST.
 * `RepoGuardTest` refused it in the first full gate, naming the citation and this file — the
 * `R-136` shape, a claim of coverage with nothing behind it. The assertions live in
 * `TemplateTokenSetTest`, beside the invariant they serve.
 *
 * ⚠⚠ AND THE NOTE EXPLAINING THAT FIX TRIPPED THE SAME GUARD A SECOND TIME, because the
 * detector matches `\b[A-Z][A-Za-z0-9]*Test\b` in the FILE's text — backticks and prose
 * included — so quoting the wrong name to explain it is indistinguishable from citing it.
 * Measured twice in one sitting. A guard with no escape hatch is the right trade here: the
 * name is described rather than spelled.
 */
final class ThemeDefaults
{
    /*
     * ⚠ THE MAP ITSELF IS IN `ThemeTokens`, AND `composer guard` PUT IT THERE. It lived here
     * first, and rule 11c refused it with twelve findings in one run:
     *
     *     app/Features/Landing/ThemeDefaults.php:69  [theme-token-copied] names the theme
     *     token [color.background]; the list lives only in app/Domain/Landing/ThemeTokens.php
     *
     * which is the same refusal `QRPAGER-33` received for a `match` returning CSS, and it is
     * right for the reason rule 11 states: *"one token list, one emitter."* A second file
     * spelling the twelve names IS a second list, however carefully it is kept in step.
     *
     * So the contract class says WHERE a default comes from — a path into the generated token
     * layer, no colour and no length — and this class resolves it. `§4b` B1's finding was that
     * the contract defines no defaults; this is the smallest change that makes it define
     * their SOURCE without making it define their values.
     */

    /**
     * Every landing token's default, as a contract value.
     *
     * @return array<string, string>
     */
    public static function values(): array
    {
        $defaults = [];

        foreach (ThemeTokens::DEFAULT_SOURCES as $name => $source) {
            $defaults[$name] = self::read($source);
        }

        return $defaults;
    }

    /**
     * The tenant's theme with every absent token filled in.
     *
     * ⚠ THE TENANT'S VALUE ALWAYS WINS, AND AN EMPTY STRING IS NOT A VALUE. `StudioForm::
     * toDocument()` keeps a member *"only when it is a non-empty string"*, so what arrives
     * here is already either a real value or nothing at all; this uses `array_replace` in
     * that order so a stored `#000000` is never overwritten by a default.
     *
     * @param  array<string, string>  $theme  the tenant's stored theme map
     * @return array<string, string>
     */
    public static function fill(array $theme): array
    {
        /*
         * ⚠ FILTERED HERE AS WELL AS AT THE EMISSION POINT — the `§11` security round's L-1.
         * `array_replace()` alone passed any key the caller held straight through: the round
         * measured `fill(['ready.field' => '#000000', …])` returning a THIRTEEN-key map with
         * `ready.field` in it. Nothing escaped — `ThemeStylesheet` re-asks `accepts()` and
         * dropped it, and `--ready-field` is outside theming by decision D4 — but the standard
         * this project holds itself to is written eight lines above that call: *"a control
         * that is only correct because its caller is correct is not a control."* This method
         * is public, and the next caller is not guaranteed to be that one.
         */
        $filled = array_replace(self::values(), $theme);

        return array_filter(
            $filled,
            static fn (string $name): bool => ThemeTokens::knows($name),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * One value out of the generated token layer, by the path `SOURCES` names.
     *
     * ⚠ IT THROWS RATHER THAN FALLING BACK. A missing path means the token layer and this
     * map have drifted — a regenerated `config/design-tokens.php` that dropped a role, say —
     * and the honest outcome of that is a loud failure in the gate, not a page rendered with
     * one property quietly missing. There is nothing to fall back TO: a hard-coded value
     * here is the second source this class exists to avoid.
     */
    private static function read(string $source): string
    {
        if (str_starts_with($source, 'stack.')) {
            $stack = substr($source, strlen('stack.'));

            if (! array_key_exists($stack, ThemeTokens::STACKS)) {
                throw new \RuntimeException("the font stack {$stack} is not in ThemeTokens::STACKS");
            }

            return $stack;
        }

        /** @var mixed $value */
        $value = config('design-tokens.'.$source);

        if (! is_string($value) || $value === '') {
            throw new \RuntimeException("config/design-tokens.php carries no string at {$source}");
        }

        // Lower-cased for `accepts()`, which takes lower-case hex only. A length or a stack
        // key is unaffected by this, and a colour is the only kind the token layer writes in
        // upper case.
        return strtolower($value);
    }
}
