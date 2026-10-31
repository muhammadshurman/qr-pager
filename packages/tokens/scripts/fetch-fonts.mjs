#!/usr/bin/env node
/**
 * ================================================================================
 * FETCH THE TWO SELF-HOSTED FONTS, SUBSET, AND EMIT THEIR @font-face
 * ================================================================================
 *
 * The approved direction fixes two families: **Plus Jakarta Sans** (Latin) and **Tajawal**
 * (Arabic), both **OFL-1.1**, both *"SELF-HOSTED from `packages/tokens`, subset to Arabic +
 * Latin -- no CDN request, because §12's cold-3G bar is a gate clause and a third-party
 * font request is a dependency on someone else's uptime."*
 *
 * On a page a customer reaches by scanning a QR code it is also a per-visitor disclosure of
 * their IP to a third party, which is the half the uptime argument does not cover.
 *
 * ⚠ RUN ONCE, AND THE OUTPUT IS COMMITTED. This is NOT part of `pnpm tokens:generate` and
 * is deliberately not in the gate: it needs the network, and a gate step that needs the
 * network fails for reasons that have nothing to do with the code. What the gate checks
 * instead is the PROPERTY -- that every font URL in the repository is same-origin and every
 * face is `font-display: swap` -- which is `fonts.test.ts`, and which would catch a
 * hand-edited stylesheet reintroducing a CDN request.
 *
 * WHY GOOGLE'S CDN IS THE SOURCE AND NOT THE UPSTREAM REPOSITORY. Subsetting needs
 * `pyftsubset`, and neither `fontTools` nor `woff2_compress` is installed here (measured).
 * Google's `css2` endpoint already serves per-unicode-range woff2 files, so the subsetting
 * is done by the upstream that publishes the font. The files are fetched ONCE, at build
 * time, by this script -- never by a visitor's browser, which is the thing being avoided.
 *
 * Both licenses were verified against Google's own metadata rather than from memory:
 * `license=ofl` for both (§11.7).
 */
import { createHash } from 'node:crypto'
import { mkdirSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'

const PKG = join(fileURLToPath(new URL('..', import.meta.url)))
const OUT = join(PKG, 'fonts')

/** A browser UA, because the css2 endpoint serves woff2 only to a client that supports it. */
const UA =
  'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36'

/**
 * What to fetch. ONE subset per family -- the one that family is for.
 *
 * Plus Jakarta Sans also publishes cyrillic-ext, latin-ext and vietnamese; Tajawal also
 * publishes latin. None is fetched: a subset nobody renders is bytes on a cold 3G start,
 * and the two launch languages are Arabic and English.
 */
const WANTED = [
  {
    family: 'Plus Jakarta Sans',
    query: 'Plus+Jakarta+Sans:wght@400;600',
    subset: 'latin',
    // The latin range, as Google publishes it. Matched rather than assumed, so a change
    // upstream makes this script fail loudly instead of fetching the wrong block.
    marker: 'U+0000-00FF',
    weights: ['400', '600'],
    file: 'plus-jakarta-sans-latin.woff2',
  },
  {
    family: 'Tajawal',
    query: 'Tajawal:wght@400;700',
    subset: 'arabic',
    marker: 'U+0600-06FF',
    weights: ['400', '700'],
    file: 'tajawal-arabic.woff2',
  },
]

/** Parse a css2 response into { weight, unicodeRange, url } per @font-face. */
function parseFaces(css) {
  const faces = []
  for (const block of css.split('@font-face').slice(1)) {
    const weight = /font-weight:\s*(\d+)/.exec(block)?.[1]
    const url = /url\((https:\/\/[^)]+\.woff2)\)/.exec(block)?.[1]
    const range = /unicode-range:\s*([^;]+);/.exec(block)?.[1]?.trim()
    if (weight !== undefined && url !== undefined && range !== undefined) {
      faces.push({ weight, url, range })
    }
  }
  return faces
}

async function main() {
  mkdirSync(OUT, { recursive: true })

  /** One entry per DISTINCT file, keyed by content hash. */
  const files = new Map()

  for (const spec of WANTED) {
    const cssUrl = `https://fonts.googleapis.com/css2?family=${spec.query}&display=swap`
    const response = await fetch(cssUrl, { headers: { 'user-agent': UA } })
    if (!response.ok) throw new Error(`${cssUrl} -> HTTP ${String(response.status)}`)
    const css = await response.text()
    const faces = parseFaces(css)

    for (const weight of spec.weights) {
      const face = faces.find((f) => f.weight === weight && f.range.includes(spec.marker))
      if (!face) {
        throw new Error(
          `no ${spec.family} @font-face for weight ${weight} carrying ${spec.marker}. ` +
            `Google's subset partitioning has changed; fix WANTED rather than guessing.`,
        )
      }

      const bytes = Buffer.from(
        await (await fetch(face.url, { headers: { 'user-agent': UA } })).arrayBuffer(),
      )

      // wOF2 magic. A redirect to an HTML error page would otherwise be committed as a
      // font, and the failure would surface as unstyled text in a browser rather than here.
      const magic = bytes.subarray(0, 4).toString('ascii')
      if (magic !== 'wOF2') {
        throw new Error(`${face.url} did not return woff2 (magic ${JSON.stringify(magic)})`)
      }

      // ⚠ DEDUPLICATED BY CONTENT, and the first version of this script did not do it.
      //
      // Plus Jakarta Sans is a VARIABLE font: Google serves the SAME woff2 for weight 400
      // and weight 600, with different font-weight declarations around it. Writing one file
      // per weight produced two byte-identical 27,348-byte files -- 27 KB of duplicate
      // payload on the cold 3G start §12's bar is about, which is the one place this would
      // actually be felt.
      //
      // So: one file per distinct hash, and a group covering several weights becomes a
      // single @font-face with a weight RANGE, which is what a variable font wants anyway.
      const hash = createHash('sha256').update(bytes).digest('hex')
      const existing = files.get(hash)
      if (existing) {
        existing.weights.push(weight)
        continue
      }
      files.set(hash, {
        family: spec.family,
        subset: spec.subset,
        file: spec.file,
        range: face.range,
        bytes: bytes.length,
        sha256: hash,
        source: face.url,
        weights: [weight],
      })
    }
  }

  // A family whose weights resolved to DIFFERENT files needs a distinct name per file.
  const byFile = new Map()
  for (const entry of files.values()) {
    const siblings = byFile.get(entry.file) ?? []
    siblings.push(entry)
    byFile.set(entry.file, siblings)
  }
  for (const [name, siblings] of byFile) {
    if (siblings.length === 1) continue
    for (const entry of siblings) {
      entry.file = name.replace(/\.woff2$/, `-${entry.weights.join('-')}.woff2`)
    }
  }

  const emitted = [...files.values()]
  let total = 0
  for (const entry of emitted) {
    const bytes = Buffer.from(
      await (await fetch(entry.source, { headers: { 'user-agent': UA } })).arrayBuffer(),
    )
    writeFileSync(join(OUT, entry.file), bytes)
    total += bytes.length
    console.log(
      `fonts: ${entry.file}  ${String(bytes.length)} bytes  weights ${entry.weights.join(', ')}`,
    )
  }
  console.log(`fonts: ${String(emitted.length)} file(s), ${String(total)} bytes in all`)

  /** `font-weight: 400 600` for a variable face, `font-weight: 400` for a static one. */
  const weightDeclaration = (weights) => {
    const sorted = [...weights].map(Number).sort((a, b) => a - b)
    return sorted.length > 1
      ? `${String(sorted[0])} ${String(sorted[sorted.length - 1])}`
      : String(sorted[0])
  }

  const faceCss = [
    '/*',
    ' * GENERATED by packages/tokens/scripts/fetch-fonts.mjs -- DO NOT EDIT.',
    ' *',
    ' * Both families are OFL-1.1, verified against Google Fonts metadata (license=ofl),',
    ' * not from memory (§11.7).',
    ' *',
    ' * Every url() below is RELATIVE: these faces are served from our own origin. §12\u2019s',
    ' * cold-3G bar is a gate clause, a third-party font request is a dependency on someone',
    ' * else\u2019s uptime, and on a page a customer reaches by scanning a QR code it is also a',
    ' * per-visitor disclosure of their IP to that third party. fonts.test.ts asserts the',
    ' * same-origin property rather than trusting this comment.',
    ' *',
    ' * font-display: swap on every face -- text is readable while the font loads, which is',
    ' * the whole point on a cold start.',
    ' *',
    ' * ONE FILE PER DISTINCT CONTENT. Plus Jakarta Sans is a variable font and Google serves',
    ' * the same woff2 for both its weights, so it is declared once with a weight RANGE',
    ' * instead of twice -- 27 KB that would otherwise be downloaded for nothing.',
    ' *',
    ' * Provenance, so a reviewer can re-fetch and compare:',
    ...emitted.map(
      (e) =>
        ` *   ${e.file}  ${String(e.bytes)} bytes  weights ${e.weights.join('/')}  sha256 ${e.sha256.slice(0, 16)}\u2026\n *     ${e.source}`,
    ),
    ' */',
    '',
    ...emitted.flatMap((e) => [
      '@font-face {',
      `  font-family: '${e.family}';`,
      '  font-style: normal;',
      `  font-weight: ${weightDeclaration(e.weights)};`,
      '  font-display: swap;',
      `  src: url('./${e.file}') format('woff2');`,
      `  unicode-range: ${e.range};`,
      '}',
      '',
    ]),
  ].join('\n')

  writeFileSync(join(OUT, 'fonts.css'), faceCss)
  writeFileSync(
    join(OUT, 'PROVENANCE.json'),
    `${JSON.stringify(
      {
        license: 'OFL-1.1',
        licenseVerifiedFrom: 'https://fonts.google.com/metadata/fonts -- license=ofl for both',
        fetchedBy: 'packages/tokens/scripts/fetch-fonts.mjs',
        totalBytes: total,
        faces: emitted,
      },
      null,
      2,
    )}\n`,
  )
  console.log('fonts: wrote fonts.css and PROVENANCE.json')
}

await main()
