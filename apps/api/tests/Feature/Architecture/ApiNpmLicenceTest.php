<?php

declare(strict_types=1);

/*
 * ================================================================================
 * apps/api's OWN npm TREE IS IN THE LICENCE INVENTORY TOO — QRPAGER-23 §9.2a
 * ================================================================================
 *
 * ⚠ FOUND BY RUNNING THE §11 CHECKLISTS OVER MY OWN DIFF, which is the only reason it was
 * found at all. Task 8a's `§11.7` work built two dependency controls and both of them stop
 * at the pnpm workspace:
 *
 *   - `pnpm audit` walks `pnpm-lock.yaml`;
 *   - `tools/gate/test/licences.test.mjs` walks `node_modules/.pnpm`, the pnpm store.
 *
 * `apps/api` is DELIBERATELY not a workspace member (`ADR-003`: listing it "would invite a
 * `package.json` that competes with `composer.json` for ownership"), so it has its own
 * `package-lock.json` and its own flat `node_modules` — and **92 packages were covered by
 * neither control**. `§10 › REPO` states the licence inventory as an obligation the client
 * INHERITS at Transfer Ownership, and an inventory with a hole in it is the shape `§4a`
 * records: a control that reads as coverage while covering less than it says.
 *
 * ================================================================================
 * ONE POLICY, TWO TREES — WHICH IS THE WHOLE POINT
 * ================================================================================
 *
 * This reads `docs/contracts/dependency-licences.json`, the SAME file the workspace check
 * holds its tree to. A second allow-list for the second tree would be the duplicated-list
 * shape this project has already paid for twice (QA H-1, and `guard` rule 11c, which parses
 * `ThemeTokens` rather than copying it): the two copies would diverge on the first new
 * licence family, and the divergence would be silent.
 *
 * It lives on the PHP side rather than beside the workspace check for a mechanical reason:
 * `apps/api/node_modules` is installed by `npm ci` in CI's **gates** job and by
 * `composer setup` locally. CI's **frontend** job installs only the pnpm workspace, so a
 * vitest case asserting this tree would be red there for a reason that is not a finding.
 */

/** The repository root, from the Laravel app that lives two levels under it. */
function licenceRepoRoot(): string
{
    return dirname(base_path(), 2);
}

/**
 * Every installed package in apps/api's npm tree, and the licence it DECLARES.
 *
 * The tree is walked rather than the lockfile parsed, for the reason the workspace check
 * gives: `package-lock.json` carries no licence at all. The fact lives in each package's own
 * `package.json`, which is also what a lawyer or the receiving client would read.
 */
function apiNpmLicences(): array
{
    $root = base_path('node_modules');

    if (! is_dir($root)) {
        return [];
    }

    $out = [];

    $record = function (string $dir) use (&$out): void {
        $manifest = $dir.'/package.json';

        if (! is_file($manifest)) {
            return;
        }

        $pkg = json_decode((string) file_get_contents($manifest), true);

        if (! is_array($pkg) || ! is_string($pkg['name'] ?? null) || ! is_string($pkg['version'] ?? null)) {
            return;
        }

        // `license` is the current field; `licenses` is the deprecated array form and is
        // NORMALISED rather than skipped, because skipping it would hide a package.
        $licence = '(none declared)';

        if (is_string($pkg['license'] ?? null)) {
            $licence = $pkg['license'];
        } elseif (is_array($pkg['licenses'] ?? null)) {
            $types = array_map(
                static fn (mixed $l): string => is_array($l) ? (string) ($l['type'] ?? '') : (string) $l,
                $pkg['licenses'],
            );
            $licence = implode(' OR ', array_filter($types));
        }

        $out[$pkg['name'].'@'.$pkg['version']] = $licence;
    };

    // npm's layout is a FLAT tree with nesting on conflict, so every directory named
    // `node_modules` at any depth holds package roots. Scopes are one level deeper.
    $walk = function (string $modules) use (&$walk, $record): void {
        foreach ((array) scandir($modules) as $entry) {
            if (! is_string($entry) || $entry === '.' || $entry === '..' || $entry === '.bin') {
                continue;
            }

            $path = $modules.'/'.$entry;

            if (! is_dir($path)) {
                continue;
            }

            if (str_starts_with($entry, '@')) {
                foreach ((array) scandir($path) as $scoped) {
                    if (! is_string($scoped) || $scoped === '.' || $scoped === '..') {
                        continue;
                    }
                    $record($path.'/'.$scoped);
                    if (is_dir($path.'/'.$scoped.'/node_modules')) {
                        $walk($path.'/'.$scoped.'/node_modules');
                    }
                }

                continue;
            }

            $record($path);

            if (is_dir($path.'/node_modules')) {
                $walk($path.'/node_modules');
            }
        }
    };

    $walk($root);

    return $out;
}

/** The shared policy, read from the contract the workspace check also reads. */
function licencePolicy(): array
{
    $path = licenceRepoRoot().'/docs/contracts/dependency-licences.json';

    expect(is_file($path))->toBeTrue('docs/contracts/dependency-licences.json is missing');

    $policy = json_decode((string) file_get_contents($path), true);

    expect($policy)->toBeArray();

    return is_array($policy) ? $policy : [];
}

/**
 * The licence families one SPDX expression offers, with the composite rule stated.
 *
 * `type-fest@5.10.0` declares `(MIT OR CC0-1.0)` — the only composite expression in this
 * tree today. An OR expression means the recipient MAY TAKE EITHER, so the disposition is
 * the best alternative available, not the worst: a package offered under MIT or CC0-1.0 can
 * be taken under MIT, and MIT is on the permissive list.
 *
 * ⚠ AND `AND` IS NOT THE SAME QUESTION, so it is not treated as one. An AND expression
 * imposes EVERY term at once, so the right disposition is the most restrictive, not the
 * least. Nothing in either tree declares one today, so rather than guess at the rule this
 * returns the terms and the caller refuses the expression outright — a refusal a human then
 * has to disposition, which is the correct outcome for a licence shape nobody has read.
 *
 * @return array{op: string, terms: list<string>}
 */
function licenceTerms(string $expression): array
{
    $clean = trim(str_replace(['(', ')'], '', $expression));

    if (stripos($clean, ' AND ') !== false) {
        return ['op' => 'AND', 'terms' => array_map('trim', preg_split('/\s+AND\s+/i', $clean) ?: [])];
    }

    return ['op' => 'OR', 'terms' => array_map('trim', preg_split('/\s+OR\s+/i', $clean) ?: [])];
}

/**
 * Every licence family the policy accepts: permissive outright, or dispositioned with a
 * reason. Both maps are KEYED by family in the contract.
 */
function licenceFamilies(): array
{
    $policy = licencePolicy();

    return array_merge(
        array_keys((array) ($policy['permissive'] ?? [])),
        array_keys((array) ($policy['dispositioned'] ?? [])),
    );
}

it('walks a real tree, so nothing below can pass over nothing', function (): void {
    $licences = apiNpmLicences();

    // A BOUND rather than an exact count, for the reason the workspace check gives: the
    // total varies with the platform's optional dependencies, so an exact number would be
    // green on this machine and red on a macOS clone. Measured here today: 92.
    expect(count($licences))
        ->toBeGreaterThan(60, 'apps/api/node_modules was not walked -- run `npm ci` in apps/api');

    // And the packages that make this tree a SECOND toolchain are present by name, so a
    // walk that silently missed the interesting ones is visible here rather than inferred.
    $names = array_map(
        static fn (string $key): string => substr($key, 0, (int) strrpos($key, '@')),
        array_keys($licences),
    );

    foreach (['tailwindcss', 'vite', 'laravel-vite-plugin', 'lightningcss'] as $named) {
        expect(in_array($named, $names, true))->toBeTrue("{$named} is not in the walk");
    }
});

it('carries no licence family that is in neither the allow-list nor a disposition', function (): void {
    // ⚠ BOTH MAPS ARE KEYED BY LICENCE FAMILY, so the families are the KEYS and the values
    // are the measured count and the note. The first version of this line read `permissive`
    // with array_values() and every lookup missed -- which failed loudly here and would have
    // been a silently empty allow-list in a check written the other way round.
    $acceptable = licenceFamilies();

    expect(count($acceptable))->toBeGreaterThan(8, 'the policy is too small to mean anything');

    $unknown = [];

    foreach (apiNpmLicences() as $package => $expression) {
        $terms = licenceTerms($expression);

        // An AND expression imposes every term at once and nothing has read one yet, so it
        // is refused rather than guessed at. See licenceTerms().
        if ($terms['op'] === 'AND') {
            $unknown[] = "{$package}: {$expression} (an AND expression, which needs a human)";

            continue;
        }

        $offered = array_values(array_intersect($terms['terms'], $acceptable));

        if ($offered === []) {
            $unknown[] = "{$package}: {$expression}";
        }
    }

    expect($unknown, 'these packages in apps/api/node_modules declare a licence family that '.
        'is in neither `permissive` nor `dispositioned` in docs/contracts/dependency-licences.json '.
        '-- add it with its reach, its obligation and a decision (§11.7, §10)')->toBe([]);
});

it('refuses a copyleft family outright, which is what makes the sweep above a policy', function (): void {
    // THE FALSIFICATION, driven through the same function rather than asserted about it. If
    // a GPL package appeared in this tree the case above must go red, and this proves the
    // mechanism does that without planting a package on disk.
    $policy = licencePolicy();
    $acceptable = licenceFamilies();

    foreach (array_keys((array) ($policy['refused'] ?? [])) as $refused) {
        if (str_starts_with((string) $refused, '$')) {
            continue;
        }

        expect(in_array($refused, $acceptable, true))
            ->toBeFalse("{$refused} is on the refusal list AND on an acceptance list");

        // A single refused term offers nothing acceptable, so the sweep reports it.
        $terms = licenceTerms((string) $refused);
        expect(array_intersect($terms['terms'], $acceptable))->toBe([]);
    }

    // And an AND expression is refused even when one of its terms is permissive, which is
    // the asymmetry licenceTerms() exists to keep.
    expect(licenceTerms('(MIT AND GPL-3.0)')['op'])->toBe('AND');
    expect(licenceTerms('(MIT OR CC0-1.0)')['op'])->toBe('OR');
});

it('holds this tree to the SAME disposition the workspace tree was given', function (): void {
    /*
     * ⚠ THE PART THAT WOULD OTHERWISE BE ASSUMED. "Every licence family is on the list" is
     * satisfied by MPL-2.0 being dispositioned at all -- but that disposition was written
     * about the WORKSPACE tree, naming `lightningcss` and `lightningcss-linux-x64-gnu` and
     * reasoning that they are build-time dependencies of Tailwind v4 that ship no code to a
     * browser. If a DIFFERENT MPL-2.0 package appeared in apps/api's tree, the sweep above
     * would pass while the recorded reasoning covered none of it.
     *
     * ⚠ AND THE DRIFT §9.2a RECORDED IS NOT THERE. The pre-coding reading noted
     * "lightningcss 1.32.0 in the panel tree against 1.32.0 + 1.33.0 in the workspace" and
     * ruled it recorded-without-acting. Re-measured by this file's own nested walk on
     * 2026-11-01, apps/api carries BOTH:
     *
     *     1.32.0  node_modules/lightningcss                       (Tailwind 4.3.3)
     *     1.33.0  node_modules/vite/node_modules/lightningcss     (Vite 8)
     *
     * The earlier figure came from a TOP-LEVEL-ONLY view of a tree npm nests on conflict,
     * so it undercounted. The two trees agree, and there is no drift to carry -- which is
     * why this case asserts the package IDENTITIES rather than a version bound nobody would
     * be able to act on.
     */
    $policy = licencePolicy();
    $mpl = (array) ($policy['dispositioned']['MPL-2.0'] ?? []);
    $named = array_values((array) ($mpl['packages'] ?? []));

    expect($named)->not->toBeEmpty('the MPL-2.0 disposition names no package');

    /*
     * ⚠ THE BASE NAME, for the reason the block below spells out: a platform binary's name
     * carries its target, and which targets `npm ci` resolves depends on the machine. The
     * fresh-clone proof caught this with `lightningcss-linux-x64-musl`, which a clean
     * install takes and an incremental one does not. The disposition names the PACKAGE; the
     * platform suffix is not a different package.
     */
    $base = static fn (string $package): string => (string) preg_replace(
        '/-(?:linux|darwin|win32|freebsd|android)-[a-z0-9-]+$/',
        '',
        substr($package, 0, (int) strrpos($package, '@'))
    );

    $namedBases = array_values(array_unique(array_map(
        static fn (string $n): string => (string) preg_replace(
            '/-(?:linux|darwin|win32|freebsd|android)-[a-z0-9-]+$/',
            '',
            $n
        ),
        $named
    )));

    $unnamed = [];
    $seen = [];

    foreach (apiNpmLicences() as $package => $expression) {
        if ($expression !== 'MPL-2.0') {
            continue;
        }

        $seen[] = $package;

        if (! in_array($base($package), $namedBases, true)) {
            $unnamed[] = $package;
        }
    }

    /*
     * ⚠ THE FAMILY, NOT AN IDENTITY LIST, and the fresh-clone proof is what taught me the
     * difference. This asserted the exact four `name@version` strings my dev tree carries
     * and went RED in a clone: `npm ci` resolved `lightningcss-linux-x64-musl@1.32.0` and
     * `@1.33.0` as well, because a clean install takes the optional platform binaries for
     * both glibc and musl while an incremental one keeps only the host's.
     *
     * `licences.test.mjs`'s own header had already written the argument I then ignored:
     * *"a per-package list goes stale at every install and differs by PLATFORM ... a
     * byte-diffed table of package names would therefore be red on one machine and green on
     * another, which is the 'works on the builder's machine' shape the fresh-clone proof
     * exists for."* It exists for exactly this, and it caught exactly this.
     *
     * So the property is the BASE NAME: every MPL-2.0 package must be one the disposition
     * names, with any platform suffix stripped. A new MPL-2.0 package still reddens; a
     * macOS clone, a musl clone and an arm64 clone do not.
     */
    $bases = array_values(array_unique(array_map($base, $seen)));
    sort($bases);

    expect($bases)->toBe(['lightningcss']);

    // Not vacuous: the tree really does carry MPL-2.0 packages, and more than one of them --
    // "every base name is lightningcss" would be true of an empty list.
    expect(count($seen))->toBeGreaterThanOrEqual(2);
    expect($seen)->toContain('lightningcss@1.32.0');

    expect($unnamed, 'these MPL-2.0 packages in apps/api/node_modules are not named by the '.
        "MPL-2.0 disposition, so that disposition's reasoning -- build-time only, no code ".
        'shipped to a browser -- has not been checked against them')->toBe([]);
});

it('takes the one composite expression in this tree under its permissive half', function (): void {
    // Named explicitly, because a composite expression is exactly where a sweep quietly
    // decides something nobody wrote down. `type-fest` is the only one today; the assertion
    // is that it IS still composite, so a relicense upstream reddens here rather than
    // passing under a rule that no longer applies.
    $licences = apiNpmLicences();

    $composite = array_filter(
        $licences,
        static fn (string $expression): bool => str_contains($expression, ' OR ')
            || str_contains($expression, ' AND '),
    );

    expect($composite)->toHaveCount(1);

    $expression = (string) reset($composite);
    expect($expression)->toBe('(MIT OR CC0-1.0)')
        ->and(array_key_first($composite))->toStartWith('type-fest@');

    $terms = licenceTerms($expression);
    expect($terms['op'])->toBe('OR')
        ->and($terms['terms'])->toBe(['MIT', 'CC0-1.0']);

    // MIT is permissive, so the package is taken under MIT. CC0-1.0 is dispositioned in the
    // contract anyway, so either half is acceptable -- stated so the OR rule is not doing
    // load-bearing work it was never checked for.
    $policy = licencePolicy();
    expect(array_key_exists('MIT', (array) ($policy['permissive'] ?? [])))->toBeTrue()
        ->and(array_key_exists('CC0-1.0', (array) ($policy['dispositioned'] ?? [])))->toBeTrue();
});
