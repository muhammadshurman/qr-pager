import { readFileSync, readdirSync, statSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

import { describe, expect, it } from 'vitest'

import {
  StoreUnreachable,
  configFromEnvironment,
  objectReader,
  signGet,
  type StoreConfig,
} from '../src/store.ts'

/**
 * ================================================================================
 * WHAT THIS APP IS, AND WHAT IT NO LONGER HAS — Task 11 (QRPAGER-18), ADR-005
 * ================================================================================
 *
 * `ADR-005` restates `apps/site` as the local stand-in for the CDN's `Host` -> prefix
 * mapping: it renders nothing, because Task 7 already rendered the page and published the
 * bytes. Three claims follow from that, and a claim about an absence is only safe while it
 * stays true — so each is asserted rather than described.
 *
 * ⚠ **AND THE FIRST ONE IS HOW `R-176` IS DISCHARGED.** That row wanted a compile-level proof
 * that Tailwind's defaults are cleared in `apps/site`, *"the PUBLIC tenant landing renderer"*.
 * There is no stylesheet here any more, so there is nothing for a default to leak into — a
 * stronger answer than the proof it asked for, and worthless the moment somebody adds a CSS
 * file back without reading this. Hence the sweep.
 */

const here = dirname(fileURLToPath(import.meta.url))
const app = resolve(here, '..')

function walk(directory: string): string[] {
  const out: string[] = []

  for (const entry of readdirSync(directory)) {
    if (entry === 'node_modules' || entry === '.next') continue

    const path = join(directory, entry)

    if (statSync(path).isDirectory()) out.push(...walk(path))
    else out.push(path)
  }

  return out
}

const files = walk(app).map((path) => path.slice(app.length + 1))

describe('this app renders nothing', () => {
  it('ships no stylesheet at all, which is what retires R-176 rather than proving it', () => {
    const sheets = files.filter((path) => /\.(css|scss|sass|less)$/.test(path))

    expect(
      sheets,
      'apps/site has a stylesheet again — R-176 was discharged by this surface not existing, ' +
        'so a new sheet needs either that row reopened or a compile-level proof that ' +
        "Tailwind's defaults are cleared",
    ).toEqual([])
  })

  it('ships no React component and no page, so there is no second rendering', () => {
    /*
     * `QRPAGER-18 §2`: *"The block schema is Task 7's single source. This task consumes it.
     * Redefining a block here is the exact contradiction this project is structured to
     * prevent."* A `.tsx` file in this app would be the beginning of that second rendering,
     * and it would arrive one harmless component at a time.
     */
    expect(files.filter((path) => path.endsWith('.tsx'))).toEqual([])
    expect(files.filter((path) => /^app\/.*page\.ts$/.test(path))).toEqual([])
    expect(files.filter((path) => /^app\/.*layout\.ts$/.test(path))).toEqual([])
  })

  it('depends on the generated contract and nothing else from the workspace', () => {
    const manifest = JSON.parse(readFileSync(join(app, 'package.json'), 'utf8')) as {
      dependencies: Record<string, string>
      devDependencies?: Record<string, string>
    }

    expect(
      Object.keys(manifest.dependencies).filter((name) => name.startsWith('@qrpager/')),
    ).toEqual(['@qrpager/contracts'])
    // Tailwind is gone with the stylesheet, and the template kit with the rendering.
    const all = { ...manifest.dependencies, ...manifest.devDependencies }
    for (const name of ['tailwindcss', '@tailwindcss/postcss', '@qrpager/ui', '@qrpager/tokens']) {
      expect(all[name], `${name} is back in apps/site`).toBeUndefined()
    }
  })

  it('declares the route dynamic, which is the invariant in the place the framework reads', () => {
    /*
     * ⚠ A PRERENDERED ANSWER HERE IS ONE HOST'S PAGE SERVED TO EVERY HOST. The answer depends
     * on a request header, which Next cannot see when it decides what to prerender, so the
     * declaration is not a performance hint — it is `§3` row 11's invariant expressed where
     * the framework will act on it. Asserted in the source because the build output says
     * `ƒ (Dynamic)` only when it is there.
     */
    const route = readFileSync(join(app, 'app/[[...path]]/route.ts'), 'utf8')

    expect(route).toContain("export const dynamic = 'force-dynamic'")
    expect(route).toContain('export const revalidate = 0')
  })
})

describe('the store reader', () => {
  const config: StoreConfig = {
    endpoint: 'http://127.0.0.1:8333',
    bucket: 'qrpager-local',
    region: 'us-east-1',
    accessKeyId: 'qrpager',
    secretAccessKey: 'local_dev_only',
  }
  const at = new Date('2026-11-03T12:00:00.000Z')

  it('signs with the three headers it says it signs, and nothing else', () => {
    const headers = signGet(config, '/qrpager-local/sites/alpha.test/index.html', '', at)

    expect(Object.keys(headers).sort()).toEqual([
      'authorization',
      'host',
      'x-amz-content-sha256',
      'x-amz-date',
    ])
    expect(headers['x-amz-date']).toBe('20261103T120000Z')
    expect(headers.host).toBe('127.0.0.1:8333')
    // The SignedHeaders list and the headers actually sent must agree -- a mismatch between
    // those two is the classic SigV4 403 with no clue in it.
    expect(headers.authorization).toContain('SignedHeaders=host;x-amz-content-sha256;x-amz-date')
    expect(headers.authorization).toContain('Credential=qrpager/20261103/us-east-1/s3/aws4_request')
  })

  it('is deterministic for one instant and sensitive to every input', () => {
    /*
     * A property test and NOT a pinned known-answer, deliberately: a pinned signature
     * computed by this same code would prove only that it still agrees with itself. What
     * matters is that the signature moves when any signed input moves — and that the real
     * store accepts the right one and refuses a wrong one, which is a LIVE measurement and
     * lives in `tools/gate/bin/site-isolation-proof`, where a bucket exists.
     */
    const base = signGet(config, '/qrpager-local/sites/alpha.test/index.html', '', at)
    const same = signGet(config, '/qrpager-local/sites/alpha.test/index.html', '', at)

    expect(same.authorization).toBe(base.authorization)

    const signature = (value: string | undefined): string =>
      (value ?? '').split('Signature=')[1] ?? ''

    expect(signature(base.authorization)).toMatch(/^[0-9a-f]{64}$/)

    for (const [label, changed] of [
      ['another key', signGet(config, '/qrpager-local/sites/beta.test/index.html', '', at)],
      ['a query', signGet(config, '/qrpager-local/sites/alpha.test/index.html', 'x=1', at)],
      [
        'another instant',
        signGet(
          config,
          '/qrpager-local/sites/alpha.test/index.html',
          '',
          new Date('2026-11-03T12:00:01.000Z'),
        ),
      ],
      [
        'another secret',
        signGet(
          { ...config, secretAccessKey: 'wrong' },
          '/qrpager-local/sites/alpha.test/index.html',
          '',
          at,
        ),
      ],
      [
        'another region',
        signGet(
          { ...config, region: 'eu-west-1' },
          '/qrpager-local/sites/alpha.test/index.html',
          '',
          at,
        ),
      ],
    ] as const) {
      expect(signature(changed.authorization), label).not.toBe(signature(base.authorization))
    }
  })

  it('reads its configuration from the environment, or refuses to guess one', () => {
    expect(
      configFromEnvironment({
        AWS_ENDPOINT: 'http://s',
        AWS_BUCKET: 'b',
        AWS_ACCESS_KEY_ID: 'k',
        AWS_SECRET_ACCESS_KEY: 's',
      }),
    ).toEqual({
      endpoint: 'http://s',
      bucket: 'b',
      region: 'us-east-1',
      accessKeyId: 'k',
      secretAccessKey: 's',
    })

    /*
     * ⚠ NULL FOR EVERY INCOMPLETE SHAPE, AND NO DEFAULT ENDPOINT OR BUCKET EVER. A default
     * would make a misconfigured deployment read *something*, and on this surface the wrong
     * bucket is worse than no bucket: it is another system's bytes served on a tenant's
     * domain. The handler turns null into a 503 that says so.
     */
    for (const partial of [
      {},
      { AWS_ENDPOINT: 'http://s' },
      { AWS_ENDPOINT: 'http://s', AWS_BUCKET: 'b' },
      { AWS_ENDPOINT: 'http://s', AWS_BUCKET: 'b', AWS_ACCESS_KEY_ID: 'k' },
      { AWS_ENDPOINT: '', AWS_BUCKET: 'b', AWS_ACCESS_KEY_ID: 'k', AWS_SECRET_ACCESS_KEY: 's' },
      {
        AWS_ENDPOINT: 'http://s',
        AWS_BUCKET: '',
        AWS_ACCESS_KEY_ID: 'k',
        AWS_SECRET_ACCESS_KEY: 's',
      },
    ]) {
      expect(configFromEnvironment(partial), JSON.stringify(partial)).toBeNull()
    }
  })

  it('reports an unreachable store as its own failure, not as a missing page', async () => {
    // Driven against a port nothing is listening on. `fetch` rejects; the reader turns that
    // into a typed error rather than letting a raw transport failure reach the handler.
    const read = objectReader({ ...config, endpoint: 'http://127.0.0.1:1' })

    await expect(read('sites/alpha.test/index.html')).rejects.toBeInstanceOf(StoreUnreachable)
  })
})
