import { describe, expect, it } from 'vitest'

import { studioUrl } from '../src/staff/studio'

/**
 * `QRPAGER-48` item 3. Muhammad's condition: *"a test asserts the DEFAULT is the same-origin
 * path, with no env set. Otherwise the production shape is only ever exercised in
 * production."*
 */
describe('where the staff app thinks the studio is', () => {
  it('defaults to the same-origin path when nothing is configured', () => {
    // ⚠ THE WHOLE POINT OF THIS CASE. Local development sets the override, so without this
    // the production shape -- one origin, routed by path, which `§1` decided on 2026-11-13 --
    // would never be exercised anywhere but production.
    expect(studioUrl({})).toBe('/studio')
    expect(studioUrl({ NEXT_PUBLIC_QRPAGER_STUDIO_URL: undefined })).toBe('/studio')
    expect(studioUrl({ NEXT_PUBLIC_QRPAGER_STUDIO_URL: '   ' })).toBe('/studio')
  })

  it('honours an absolute override, which is how dev reaches the other port', () => {
    expect(studioUrl({ NEXT_PUBLIC_QRPAGER_STUDIO_URL: 'http://localhost:8000/studio' })).toBe(
      'http://localhost:8000/studio',
    )
  })

  it('honours a different same-origin path', () => {
    expect(studioUrl({ NEXT_PUBLIC_QRPAGER_STUDIO_URL: '/panel/studio' })).toBe('/panel/studio')
  })

  it('refuses anything that is not a path or an absolute http(s) URL', () => {
    // ⚠ `//evil.example` IS THE CASE WORTH NAMING: it starts with `/` and reads as a path,
    // and a browser treats it as protocol-relative and leaves the origin. "A URL the client
    // can influence is an open redirect wearing a button" -- so a value that cannot be
    // trusted is ignored rather than honoured.
    for (const hostile of [
      '//evil.example/studio',
      'javascript:alert(1)',
      'data:text/html,<script>alert(1)</script>',
      'ftp://example.test/studio',
      'studio',
    ]) {
      expect(studioUrl({ NEXT_PUBLIC_QRPAGER_STUDIO_URL: hostile })).toBe('/studio')
    }
  })
})
