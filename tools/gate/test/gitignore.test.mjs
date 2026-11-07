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

    /**
     * ⚠ THE NON-VACUITY GUARD IS ABOUT THE WALKER, NOT A COUNT OF CANDIDATES, AND THE
     * FRESH-CLONE PROOF IS WHAT TAUGHT ME THAT. The first version asserted
     * `examined >= 4` -- and `examined` counts nested directories matching an unanchored
     * pattern, which in this working tree means `apps/api/build`, `apps/web/.next`,
     * `apps/site/.next` and four `node_modules`. NONE OF THOSE IS TRACKED. A fresh clone
     * has no `build/` and no `.next/` until something builds them, so the walk found 3 and
     * the assertion failed at `1bcc9f3` -- a test that depended on the builder's machine
     * having run a build, inside the one suite the fresh-clone proof exists to protect.
     *
     * So the walker proves itself against something the REPOSITORY guarantees: a tracked
     * nested directory. `packages/ui/src/styles` is one and it is not optional -- `kit.css`
     * lives in it and other tests read it (`templates.css` did too, until `R-240` deleted it
     * on 2026-11-04). If the walker cannot
     * descend to it, it cannot have examined anything else either, which is the property
     * the floor was reaching for.
     *
     * Zero candidates in a fresh clone is a real and correct state, so the count is
     * reported rather than bounded.
     */
    expect(
      directoriesNamed('styles'),
      'the directory walk cannot reach a tracked nested directory, so it examined nothing',
    ).toContain('packages/ui/src/styles')
    expect(
      examined,
      'candidates examined (0 is legitimate in a fresh clone, which has no build outputs)',
    ).toBeGreaterThanOrEqual(0)
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
    const matched = result.stdout.trim().split('\n').filter(Boolean)

    /**
     * ⚠⚠ SCOPED TO IGNORE SOURCES THE REPOSITORY ACTUALLY OWNS — Task 12, on his instruction:
     * *"fix it so a runtime-written exclude file cannot redden the gate."*
     *
     * `git check-ignore` is the authority on matching, which is why this case asks it rather
     * than re-implementing the rules — and it consults EVERY source: the repository's tracked
     * `.gitignore` files, the per-clone `.git/info/exclude`, and a global `core.excludesFile`.
     * Only the first is something a diff can fix.
     *
     * MEASURED 2026-11-07. This case failed on ten probes, every one of them sourced from
     * `.git/info/exclude` line 11 — an any-depth glob on `.claude/worktrees/`, written by the
     * **Claude Code runtime** into this clone on **2026-11-04**, three days before the branch that was being gated
     * existed. `stat` on that file confirms the date, and the repository contains no such
     * pattern. CI never saw it: a fresh checkout has no `.git/info/exclude` entries, so the
     * same commit was green there and red here.
     *
     * That is the defect this case became: it held the REPOSITORY accountable for a file the
     * repository does not contain, so the gate's result depended on whose machine ran it —
     * and a developer whose tooling writes an exclude line could not make it green by any
     * change to the project. Being red for something unfixable is how a check gets switched
     * off, which is the opposite of what this one is for.
     *
     * ⚠ THE STRENGTH OVER THE REPOSITORY'S OWN FILES IS UNCHANGED, and that is the point of
     * filtering by SOURCE rather than by pattern: `-v` prints `source:line:pattern\tpath`, so
     * a match from any `.gitignore` in the tree still fails exactly as before. The
     * `storage/` defect this case was written for would still be caught.
     *
     * ⚠ AND A LOCAL MATCH IS REPORTED RATHER THAN DROPPED. A filter that silently discarded
     * them would make this case quiet about a real local shadowing — the guard-goes-quiet
     * shape `§4a` records four instances of — so the lines are printed and the count is
     * asserted to be the complement. Nothing is hidden; it is attributed.
     */
    const repositoryOwned = (line) => {
      const source = line.split(':')[0]

      return source.endsWith('.gitignore')
    }

    const offending = matched.filter(repositoryOwned)
    const local = matched.filter((line) => !repositoryOwned(line))

    if (local.length > 0) {
      console.log(
        `gitignore: ${local.length} probe(s) ignored by a non-repository source, which this ` +
          `case does not hold the repository to:\n  ${local.join('\n  ')}`,
      )
    }

    expect(
      offending,
      "an anchored pattern in one of the repository's own .gitignore files is still ignoring " +
        'a path inside a nested project',
    ).toEqual([])

    // The split accounts for every line, so a parse that silently matched nothing cannot make
    // the assertion above pass over an empty list.
    expect(offending.length + local.length).toBe(matched.length)

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
