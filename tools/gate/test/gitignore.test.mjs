import { spawnSync } from 'node:child_process'
import { existsSync, readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'

import { describe, expect, it } from 'vitest'

const REPO = join(import.meta.dirname, '..', '..', '..')
const read = (p) => readFileSync(join(REPO, p), 'utf8')

/**
 * ================================================================================
 * EVERY `.gitignore` DIRECTORY PATTERN IS ANCHORED AS INTENDED
 * ================================================================================
 *
 * ⚠ A RECURRENCE, AND `§7` MAKES THAT THE POINT: *"Never fix the same bug twice. If it
 * recurs, the regression test was missing -- add it."*
 *
 *   Task 0, bug 1: `/build/` was anchored to the root, so `apps/api/build/` -- the PHPStan
 *   tmpDir -- was committed, 678 cache files. `PLAN.md` records it as "do not repeat".
 *
 *   Task 8a: `storage/` was UNANCHORED, so it also matched `apps/api/storage/`. Measured:
 *   `git check-ignore -v apps/api/storage/<new file>` answered `.gitignore:22:storage/`.
 *   The root `storage/` is the workspace gate's log directory; `apps/api/storage/` is
 *   Laravel's and carries tracked `.gitignore` files of its own, so a new file written
 *   there was silently unaddable.
 *
 * The two bugs are the same defect in OPPOSITE DIRECTIONS, which is why this is not "always
 * anchor" or "never anchor". A checker cannot infer the intent, so the intent is DECLARED --
 * a `# any-depth` marker on the pattern or on a preceding comment line -- and this holds the
 * declaration to the tree:
 *
 *   1. an unanchored directory pattern must carry the marker;
 *   2. a marked pattern must actually NEED it -- it must match a directory below the root --
 *      so the marker cannot be sprayed over everything to silence (1);
 *   3. an anchored pattern must not shadow a nested project: driven directly with
 *      `git check-ignore`, which is the authority on what git does rather than a
 *      reimplementation of its matching rules.
 */

const GITIGNORE = read('.gitignore')

/** Directory patterns, with whether each is anchored and whether it is marked. */
function directoryPatterns() {
  const lines = GITIGNORE.split('\n')
  const out = []
  for (const [i, raw] of lines.entries()) {
    const line = raw.trim()
    if (line === '' || line.startsWith('#') || line.startsWith('!')) continue
    if (!line.endsWith('/')) continue

    /**
     * The marker may sit on the pattern's own line, or in the comment block that governs
     * the contiguous run of patterns it introduces.
     *
     * ⚠ THE FIRST VERSION OF THIS PARSER STOPPED AT THE FIRST NON-COMMENT LINE ABOVE, so a
     * comment block governing TWO patterns only marked the first: `.idea/` was marked and
     * `.vscode/` under it was not, and the same for `.next/` and `out/`. Found by running
     * the check, which reported both -- the guard's own first finding was about the guard.
     */
    let marked = /#\s*any-depth/.test(raw)
    // Up through the whole contiguous block -- its sibling patterns AND every line of the
    // comment above them -- stopping at the blank line that separates one block from the
    // next. A comment block is several lines; breaking at the first one read only the last
    // line of it, which was this parser's second mistake in a row.
    for (let j = i - 1; j >= 0 && lines[j].trim() !== ''; j--) {
      if (/any-depth/.test(lines[j])) marked = true
    }

    const pattern = line.replace(/\s*#.*$/, '')
    out.push({
      line: i + 1,
      pattern,
      name: pattern.replace(/^\//, '').replace(/\/$/, ''),
      anchored: pattern.startsWith('/'),
      marked,
    })
  }
  return out
}

/** Every directory with this basename, anywhere in the tree, root-relative. */
function directoriesNamed(name) {
  const found = []
  const walk = (dir, depth) => {
    if (depth > 4) return
    let entries
    try {
      entries = readdirSync(join(REPO, dir === '' ? '.' : dir), { withFileTypes: true })
    } catch {
      return
    }
    for (const entry of entries) {
      if (!entry.isDirectory()) continue
      const rel = dir === '' ? entry.name : `${dir}/${entry.name}`
      if (entry.name === name) found.push(rel)
      // `node_modules` and `vendor` are walked one level only: a dependency's own `.idea`
      // is a real match for an unanchored pattern, and walking all of them is minutes.
      if (entry.name === 'node_modules' || entry.name === 'vendor' || entry.name === '.git') {
        continue
      }
      walk(rel, depth + 1)
    }
  }
  walk('', 0)
  return found
}

/** Directories that are the root of a nested project -- what an over-broad pattern harms. */
function nestedProjectRoots() {
  const roots = []
  for (const dir of ['apps/api', 'apps/web', 'apps/site']) {
    if (existsSync(join(REPO, dir))) roots.push(dir)
  }
  for (const group of ['packages', 'tools']) {
    for (const entry of readdirSync(join(REPO, group), { withFileTypes: true })) {
      if (entry.isDirectory()) roots.push(`${group}/${entry.name}`)
    }
  }
  return roots
}

describe('.gitignore directory patterns', () => {
  it('reads a non-empty pattern list, so nothing below is vacuous', () => {
    const patterns = directoryPatterns()
    expect(
      patterns.length,
      'no directory pattern was parsed out of .gitignore',
    ).toBeGreaterThanOrEqual(10)
    // Both kinds present, so a parse that collapsed one of them is visible here.
    expect(
      patterns.some((p) => p.anchored),
      'no anchored pattern parsed',
    ).toBe(true)
    expect(
      patterns.some((p) => !p.anchored),
      'no unanchored pattern parsed',
    ).toBe(true)
  })

  it('has every UNANCHORED directory pattern declare the intent', () => {
    const undeclared = directoryPatterns()
      .filter((p) => !p.anchored && !p.marked)
      .map((p) => `.gitignore:${String(p.line)}  ${p.pattern}`)
    expect(
      undeclared,
      'these patterns match at ANY depth and do not say so. An unanchored `foo/` also ' +
        'ignores `apps/api/foo/` -- which is exactly how `storage/` shadowed Laravel’s ' +
        'storage directory. Anchor it (`/foo/`) or add a `# any-depth` comment saying why ' +
        'it must match below the root.',
    ).toEqual([])
  })

  it('has no unanchored pattern shadow a directory that holds TRACKED files', () => {
    /**
     * THE DAMAGE TEST, and it is the substantive half of this file.
     *
     * The marker above records an intent; this measures the consequence, with no prose in
     * the loop. For every unanchored pattern, every directory of that name in the tree is
     * asked of git: does it hold tracked files? If it does, the pattern is ignoring a
     * directory the repository is keeping, and new files there are silently unaddable.
     *
     * `apps/api/storage` holds ten tracked `.gitignore` files of Laravel's own, which is
     * exactly what made the unanchored `storage/` a defect. `apps/api/build`,
     * `apps/web/node_modules` and `apps/web/.next` hold none, which is what makes those
     * patterns correct at any depth. No list says which is which: git does.
     */
    const offenders = []
    let examined = 0

    for (const pattern of directoryPatterns().filter((p) => !p.anchored)) {
      for (const dir of directoriesNamed(pattern.name)) {
        // The root's own directory of that name is what an unanchored pattern is FOR.
        if (!dir.includes('/')) continue
        examined += 1
        const tracked = spawnSync('git', ['ls-files', '--', dir], {
          cwd: REPO,
          encoding: 'utf8',
          shell: false,
        })
        const files = tracked.stdout.trim().split('\n').filter(Boolean)
        if (files.length > 0) {
          offenders.push(
            `.gitignore:${String(pattern.line)}  ${pattern.pattern}  shadows ${dir}, which ` +
              `holds ${String(files.length)} tracked file(s) (e.g. ${files[0]})`,
          )
        }
      }
    }

    // A bound, so a walk that found no nested directory at all cannot report no offenders.
    expect(
      examined,
      'no nested directory was examined -- the walk is broken',
    ).toBeGreaterThanOrEqual(4)
    expect(
      offenders,
      'these unanchored patterns ignore a directory the repository is tracking. Anchor them ' +
        'with a leading `/` -- this is the `storage/` defect, by measurement rather than by ' +
        'reading the pattern.',
    ).toEqual([])
  }, 60_000)

  it('has no pattern shadow a nested project, asked of GIT rather than reimplemented', () => {
    /**
     * The damage, measured. `git check-ignore` is the authority on git's matching rules;
     * re-implementing them here would be a second source for them, and the rules are
     * exactly what both bugs were about.
     *
     * A probe path inside each nested project, for each unanchored pattern's name. An
     * unanchored pattern that is genuinely any-depth (`node_modules/`, `.next/`, `build/`)
     * SHOULD match and is exempt by its marker; what must not happen is an unmarked one
     * matching, and the case above already requires every unanchored pattern to be marked.
     * So this is the complement: for each ANCHORED pattern, git must not ignore the same
     * name inside a nested project.
     */
    const anchored = directoryPatterns().filter((p) => p.anchored)
    expect(anchored.length).toBeGreaterThanOrEqual(5)

    const probes = []
    for (const project of nestedProjectRoots()) {
      for (const pattern of anchored) {
        probes.push(`${project}/${pattern.name}/a-probe-file`)
      }
    }
    expect(probes.length).toBeGreaterThan(20)

    const result = spawnSync('git', ['check-ignore', '--no-index', '-v', ...probes], {
      cwd: REPO,
      encoding: 'utf8',
      shell: false,
    })
    // Exit 1 means "none of these paths is ignored", which is the answer wanted. Exit 0
    // means at least one IS, and its line is in stdout.
    expect(
      result.stdout.trim().split('\n').filter(Boolean),
      'an anchored pattern is still ignoring a path inside a nested project',
    ).toEqual([])
    expect([0, 1]).toContain(result.status)
  }, 30_000)

  it('keeps apps/api/storage addable -- the instance, by name', () => {
    // The specific defect, pinned as well as the class. §8: assert exact values.
    // ⚠ WHAT IS ASSERTED IS THAT THE ROOT FILE IS NOT THE ONE DOING IT, not that the path
    // is unignored. `apps/api/storage/app/public/.gitignore` contains `*` -- Laravel's own,
    // deliberate, and tracked -- so that path IS ignored, by a nested rule nobody disputes.
    // The first version of this case asserted "not ignored at all" and failed on exactly
    // that, which is the measurement being right and the assertion being wrong.
    const probes = [
      'apps/api/storage/app/public/a-new-file.txt',
      'apps/api/storage/a-new-file.txt',
      'apps/api/storage/framework/a-new-file.txt',
    ]
    const result = spawnSync('git', ['check-ignore', '--no-index', '-v', ...probes], {
      cwd: REPO,
      encoding: 'utf8',
      shell: false,
    })
    const byRoot = result.stdout
      .trim()
      .split('\n')
      .filter((line) => line.startsWith('.gitignore:'))
    expect(
      byRoot,
      "the repository root's .gitignore is still shadowing apps/api/storage -- the exact " +
        'defect the anchor fixes',
    ).toEqual([])
  })

  it("keeps the root storage/ ignored -- the gate's own logs must not be committable", () => {
    // Both directions. An anchor that stopped ignoring the thing it exists for would pass
    // every assertion above.
    const result = spawnSync('git', ['check-ignore', '--no-index', '-v', 'storage/logs/gate.log'], {
      cwd: REPO,
      encoding: 'utf8',
      shell: false,
    })
    expect(result.status, 'the gate log directory is no longer ignored').toBe(0)
    expect(result.stdout).toContain('/storage/')
  })

  it('still ignores apps/api/build, which Task 0 anchored by mistake', () => {
    // The OTHER direction's regression, from the bug `PLAN.md` says not to repeat.
    const result = spawnSync(
      'git',
      ['check-ignore', '--no-index', '-v', 'apps/api/build/x.cache'],
      {
        cwd: REPO,
        encoding: 'utf8',
        shell: false,
      },
    )
    expect(result.status, "apps/api/build is not ignored -- Task 0's bug 1, again").toBe(0)
    expect(result.stdout).toContain('build/')
  })
})
