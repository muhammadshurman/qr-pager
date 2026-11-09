<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use Tests\Support\LandingFixtures;

/*
 * ================================================================================
 * `QRPAGER-40` — THE PREVIEW IS FRAMEABLE BY THIS ORIGIN AND BY NOTHING ELSE
 * ================================================================================
 *
 * Muhammad's ruling of 2026-11-09, rank 2, moved `PreviewController`'s framing from
 * `frame-ancestors 'none'` / `X-Frame-Options: DENY` to `'self'` / `SAMEORIGIN`, so the
 * studio's preview pane can frame the draft it is pinned beside.
 *
 * ⚠ **THIS FILE IS THE FOURTH TEST HIS RULING ASKED FOR, AND ITS JOB IS THE OPPOSITE OF
 * THE OTHER THREE.** `ReviewFixesTest` and `ResponseHeaderTest` assert the value IS
 * `'self'`; a widening to `*` would fail them, but a widening to `'self' https://evil.test`
 * would NOT -- both still `toContain("frame-ancestors 'self'")`. So this one asserts what
 * the directive may not become: no wildcard, no second source, not absent, and not a
 * scheme. A later loosening fails the suite instead of passing it.
 *
 * ⚠ **AND IT PINS THE OTHER FIVE DIRECTIVES BYTE-FOR-BYTE**, which is condition 1 of the
 * ruling expressed as a control rather than as a paragraph: *only the framing clause
 * moves*. If a future change relaxes `default-src` or `form-action` while editing the
 * framing, the directive-set comparison below names the one that moved.
 */

/** The policy as it stood before `QRPAGER-40`, directive by directive. */
const FRAMING_BEFORE = [
    'default-src' => "'none'",
    'img-src' => "'self'",
    'style-src' => "'unsafe-inline'",
    'frame-ancestors' => "'none'",
    'base-uri' => "'none'",
    'form-action' => "'none'",
];

/** @return array<string, string> */
function previewDirectives(string $csp): array
{
    $out = [];

    foreach (array_filter(array_map('trim', explode(';', $csp))) as $directive) {
        $parts = preg_split('/\s+/', $directive, 2);
        $out[$parts[0]] = $parts[1] ?? '';
    }

    return $out;
}

function previewResponse(): Illuminate\Testing\TestResponse
{
    $tenant = test()->actingForTenant(role: TenantRole::Owner);

    (new App\Features\Landing\LandingPages)->saveDraft($tenant, LandingFixtures::fullPage());

    return test()->get('/studio/preview');
}

it('permits this origin to frame the draft, and states it as exactly one source', function (): void {
    $csp = previewDirectives((string) previewResponse()->headers->get('Content-Security-Policy'));

    expect($csp)->toHaveKey('frame-ancestors');

    $value = $csp['frame-ancestors'];

    // Exactly `'self'`, as a whole value -- not merely containing it.
    expect($value)->toBe("'self'");

    /*
     * ⚠ THE FOUR WIDENINGS THIS CASE EXISTS TO REFUSE, each written as its own assertion so
     * a failure names which one happened rather than saying "the value changed".
     */
    expect(str_contains($value, '*'))->toBeFalse('frame-ancestors took a wildcard');
    expect(preg_split('/\s+/', $value))->toHaveCount(1, 'frame-ancestors took a second source');
    expect(str_contains($value, 'http'))->toBeFalse('frame-ancestors took a scheme or a host');
    expect($value)->not->toBe("'none'", 'frame-ancestors is back to none, and the preview pane cannot render');
});

it('says the same thing in X-Frame-Options, for a client that does not read CSP', function (): void {
    // The two must agree. A `SAMEORIGIN` header beside a `'none'` policy, or the reverse,
    // is a surface where two engines disagree about who may frame a tenant's draft.
    expect(previewResponse()->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
});

it('moved the framing clause and nothing else — condition 1, as a control', function (): void {
    $after = previewDirectives((string) previewResponse()->headers->get('Content-Security-Policy'));

    // Same directives, no additions and no removals.
    expect(array_keys($after))->toEqual(array_keys(FRAMING_BEFORE));

    $moved = [];

    foreach (FRAMING_BEFORE as $name => $was) {
        if (($after[$name] ?? null) !== $was) {
            $moved[$name] = sprintf('%s -> %s', $was, $after[$name] ?? '<absent>');
        }
    }

    expect($moved)->toBe(['frame-ancestors' => "'none' -> 'self'"]);
});

it('refuses a cross-origin framer, which is the attack the original none was for', function (): void {
    /*
     * ⚠ ASSERTED ON THE POLICY RATHER THAN BY DRIVING A BROWSER, and the limit is stated
     * rather than hidden: no PHP test can prove a browser honours `frame-ancestors`. What
     * this case proves is that the response still TELLS every engine to refuse a
     * cross-origin frame -- which is the half this repository controls. The browser half is
     * the engines' own, and `§12`'s bar is met by the directive being correct.
     */
    $csp = previewDirectives((string) previewResponse()->headers->get('Content-Security-Policy'));

    expect($csp['frame-ancestors'])->not->toContain('evil')
        ->and($csp['frame-ancestors'])->toBe("'self'")
        // Clickjacking needs a cross-origin frame, and `'self'` is an ORIGIN match: a
        // different scheme, host or port is a different origin and is refused.
        ->and($csp['default-src'])->toBe("'none'");
});
