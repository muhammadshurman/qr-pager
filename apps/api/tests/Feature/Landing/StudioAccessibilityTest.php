<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use App\Features\Landing\LandingPages;
use App\Features\Landing\MediaLibrary;
use App\Features\Landing\PublishedSite;
use Tests\Support\ImageFixtures;
use Tests\Support\LandingFixtures;

/*
 * ================================================================================
 * §12 — EXEMPT FROM THE VISUAL BAR UNTIL TASK 8, NEVER FROM THE ACCESSIBILITY BAR
 * ================================================================================
 *
 * The exemption is explicit and so is its limit: "Tasks 6-7 precede that and are
 * explicitly exempt from the visual bar, never from the accessibility bar."
 *
 * ⚠ WHAT THIS FILE CAN AND CANNOT MEASURE, STATED RATHER THAN BLURRED. R-118 is the §5
 * row recording that four clauses of the accessibility bar cannot be measured without a
 * browser this machine does not have: a VISIBLE FOCUS RING, a MEASURED 44 px touch
 * target, WCAG AA CONTRAST, and `prefers-reduced-motion` actually suppressing motion.
 * All four are computed styles.
 *
 * So this file asserts what a server-rendered response genuinely settles -- structure,
 * labels, text alternatives, announced state, and the presence of the rules the computed
 * styles would come from -- and claims nothing about the four. R-118's scope now covers
 * this panel and the published page as well as Task 6's, which is recorded there rather
 * than left as an implication.
 */

beforeEach(fn () => LandingFixtures::bucket());

function studioHtml(string $path): string
{
    return (string) test()->get($path)->getContent();
}

/** Parse HTML and return a DOMXPath over it. */
function dom(string $html): DOMXPath
{
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($document);
}

it('gives every studio page exactly one h1 and headings that do not skip a level', function (): void {
    $this->actingForTenant(role: TenantRole::Owner);

    foreach (['/studio', '/studio/media', '/studio/domains'] as $path) {
        $xpath = dom(studioHtml($path));

        $h1 = $xpath->query('//h1');
        expect($h1?->length)->toBe(1, "{$path} does not have exactly one h1");

        // No skipped level: a page with an h1 and an h3 and no h2 is a page a screen
        // reader's heading list reads as having a missing section.
        $levels = [];

        foreach ($xpath->query('//h1|//h2|//h3|//h4|//h5|//h6') ?: [] as $heading) {
            $levels[] = (int) substr($heading->nodeName, 1);
        }

        $previous = 0;

        foreach ($levels as $level) {
            expect($level - $previous)->toBeLessThanOrEqual(1, "{$path} skips from h{$previous} to h{$level}");
            $previous = max($previous, $level);
        }
    }
});

it('labels every form control on every studio page', function (): void {
    // "A labelled control for every input" is §12's own wording, and it is the clause a
    // server-rendered response settles completely.
    $this->actingForTenant(role: TenantRole::Owner);

    foreach (['/studio', '/studio/media', '/studio/domains'] as $path) {
        $xpath = dom(studioHtml($path));

        $unlabelled = [];

        foreach ($xpath->query('//input|//select|//textarea') ?: [] as $control) {
            if (! $control instanceof DOMElement) {
                continue;
            }

            $type = strtolower($control->getAttribute('type'));

            // A hidden input is not a control anybody focuses, and Livewire and CSRF
            // both emit them.
            if (in_array($type, ['hidden', 'submit', 'button'], true)) {
                continue;
            }

            $id = $control->getAttribute('id');

            $labelled = ($id !== '' && ($xpath->query('//label[@for="'.$id.'"]')?->length ?? 0) > 0)
                || $control->getAttribute('aria-label') !== ''
                || $control->getAttribute('aria-labelledby') !== ''
                || ($xpath->query('ancestor::label', $control)?->length ?? 0) > 0;

            if (! $labelled) {
                $unlabelled[] = $path.': <'.$control->nodeName.' id="'.$id.'" type="'.$type.'">';
            }
        }

        expect($unlabelled)->toBe([], implode(', ', $unlabelled));
    }
});

it('announces its own state in text, in a live region, and never by colour alone', function (): void {
    // "Announces its own errors" and "carries no colour-only state", both from §12.1's
    // own preamble. The status text is asserted as TEXT, so a change that moved the
    // state into a colour would fail here.
    $tenant = $this->actingForTenant(role: TenantRole::Owner);

    $builder = studioHtml('/studio');
    $xpath = dom($builder);

    expect($xpath->query('//*[@role="status"]')?->length)->toBeGreaterThan(0)
        ->and($xpath->query('//*[@aria-live="polite"]')?->length)->toBeGreaterThan(0)
        // §12.1's empty state, in the exact words the ticket wrote before this was coded.
        ->and($builder)->toContain('Your page has no blocks yet. Add one to get started.');

    (new LandingPages)->saveDraft($tenant, LandingFixtures::fullPage());

    expect(studioHtml('/studio'))->toContain('Draft — not visible to anyone yet.');

    (new LandingPages)->publish($tenant);

    expect(studioHtml('/studio'))->toContain('Published to ');

    // §12.2's empty and full copy, and §12.3's "none" copy.
    expect(studioHtml('/studio/media'))
        ->toContain('No images yet. PNG, JPEG, WebP, AVIF or script-free SVG, up to 5 MB.');

    expect(studioHtml('/studio/domains'))
        ->toContain('Add your own domain to use it instead.');
});

it('disables Publish rather than hiding it when there is nothing to publish', function (): void {
    // A control that disappears is a control a keyboard user cannot find to learn WHY it
    // is unavailable. `disabled` keeps it in the tab order's place and in the document,
    // and the status region above says what is missing.
    $this->actingForTenant(role: TenantRole::Owner);

    $xpath = dom(studioHtml('/studio'));
    $publish = null;

    foreach ($xpath->query('//button') ?: [] as $button) {
        if ($button instanceof DOMElement && trim($button->textContent) === 'Publish') {
            $publish = $button;
        }
    }

    expect($publish)->not->toBeNull('the Publish button is not in the document')
        ->and($publish?->hasAttribute('disabled'))->toBeTrue();
});

it('uses no positive tabindex anywhere, which would reorder the whole page', function (): void {
    $this->actingForTenant(role: TenantRole::Owner);

    foreach (['/studio', '/studio/media', '/studio/domains'] as $path) {
        $xpath = dom(studioHtml($path));

        foreach ($xpath->query('//*[@tabindex]') ?: [] as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            expect((int) $element->getAttribute('tabindex'))
                ->toBeLessThanOrEqual(0, $path.' sets a positive tabindex');
        }
    }
});

it('gives the published page a text alternative for every image and the rules the bar needs', function (): void {
    $tenant = $this->makeTenant(slug: 'a11y');
    $asset = (new MediaLibrary)->store($tenant, 'room.png', ImageFixtures::png());

    $pages = new LandingPages;
    $pages->saveDraft($tenant, LandingFixtures::fullPage($asset));
    $published = $pages->publish($tenant);

    $html = (string) MediaLibrary::disk()->get(PublishedSite::pointer($published->hostname));
    $xpath = dom($html);

    // EVERY image has a non-empty alt. The gallery caption is REQUIRED in BlockSchema for
    // exactly this reason -- a gallery of unlabelled images would pass a nullable field.
    foreach ($xpath->query('//img') ?: [] as $image) {
        if (! $image instanceof DOMElement) {
            continue;
        }

        expect(trim($image->getAttribute('alt')))->not->toBe('', 'an image has no alt text');
    }

    expect($xpath->query('//img')?->length)->toBeGreaterThan(0, 'the page under test has no images at all');

    // One h1, and lang and dir are set (R-132 records that they are defaults).
    expect($xpath->query('//h1')?->length)->toBe(1)
        ->and($html)->toContain('<html lang="en" dir="ltr"')
        // The rules the four unmeasurable clauses would be computed FROM. Their presence
        // is a fact about the document; whether a browser renders them as a visible ring
        // and a 44 px box is R-118's, and is not claimed here.
        ->and($html)->toContain('prefers-reduced-motion:reduce')
        ->and($html)->toContain('focus-visible')
        ->and($html)->toContain('min-height:44px');
});

it('keeps the published page RTL-safe, so the language setting is the only change needed', function (): void {
    // R-132: there is no per-tenant language yet. The CSS is written so that the fix is
    // two attributes -- which is only true if no rule positions anything by LEFT or
    // RIGHT. Asserted over the stylesheet's text, because a physical property added
    // later is exactly the thing that would make the eventual change a rewrite.
    $tenant = $this->makeTenant(slug: 'rtl');
    $pages = new LandingPages;
    $pages->saveDraft($tenant, LandingFixtures::fullPage());
    $published = $pages->publish($tenant);

    $html = (string) MediaLibrary::disk()->get(PublishedSite::pointer($published->hostname));

    preg_match('#<style>(.*?)</style>#s', $html, $matches);
    $css = $matches[1] ?? '';

    expect($css)->not->toBe('');

    foreach (['margin-left', 'margin-right', 'padding-left', 'padding-right',
        'border-left', 'border-right', 'text-align:left', 'text-align:right',
        'left:', 'right:', 'float:'] as $physical) {
        // str_contains and not expect()->not->toContain($needle, $message): that matcher
        // is VARIADIC, so a message-shaped second argument becomes a second NEEDLE and
        // the assertion weakens to "contains neither" -- trivially true of a message
        // that never appears. Guard rule 7 refused the first version of this line.
        expect(str_contains($css, $physical))
            ->toBeFalse("the stylesheet uses the physical property [{$physical}]");
    }
});
