# Rule 11 fixtures — the theme boundary

`SourceGuard::scanThemeBoundary()` applies to `app/` and `routes/` only, the same scope as
rule 10 and for the same reason (`§1`: "nothing under `apps/` may depend on which one is
running"). `resources/views/` is NOT in scope: the published page's own rules live in its
Blade template, which is where a page's layout belongs — what rule 11 forbids is a CLASS
carrying styling, which is what "a theme overrides tokens only, never components" means.

`styles-a-component.php.fixture` carries all four matched forms — a `<style` literal, a
CSS at-rule, a class-selector rule-set, a copied theme token name, and a call to
`ThemeTokens::property()` from a file that is not the one emitter — plus four lines that
must NOT be flagged.
