/**
 * Where the studio lives, as far as the staff app is concerned. `QRPAGER-48` item 3.
 *
 * ================================================================================
 * ⚠⚠ THE DEFAULT IS THE PRODUCTION SHAPE, AND DEV IS THE EXCEPTION
 * ================================================================================
 *
 * Muhammad's ruling of 2026-11-13:
 *
 *   "The default is the production shape, `/studio` on the same origin, and dev overrides it.
 *    A default that matches dev would mean production depends on a value someone remembered
 *    to set."
 *
 * So an unset environment yields a **same-origin path**, which is exactly what `§1`'s
 * one-origin decision of 2026-11-13 specifies: the staff app and the studio sit behind one
 * host, routed by path. Locally they are two ports, so local sets the override.
 *
 * ⚠ **THIS IS THE FIRST THING THAT DEPENDS ON THAT DECISION.** `SESSION_DOMAIN` stays `null`
 * and the session cookie is host-only, so this link carries the session **only because the
 * two surfaces share a host**. If anyone later proposes subdomains, this is the file that
 * breaks — and it is written here so they find it rather than discovering it when the cookie
 * stops arriving.
 *
 * ================================================================================
 * READ FROM CONFIG, NEVER FROM THE BROWSER
 * ================================================================================
 *
 * His second condition, and the reason is in his words: *"A URL the client can influence is
 * an open redirect wearing a button."* So this takes an environment, not a request, not a
 * query parameter and not a header — and it is a pure function of it, which is also what
 * makes the default testable without a browser.
 *
 * A value that is neither a same-origin path nor an absolute http(s) URL is **ignored** in
 * favour of the default rather than honoured. `//evil.example` is the case worth naming: it
 * starts with `/` and looks like a path, and a browser reads it as protocol-relative and
 * leaves the origin. Falling back is safe by construction; throwing would turn a
 * misconfiguration into a blank page for every member of staff.
 */
const SAME_ORIGIN_STUDIO_PATH = '/studio'

export function studioUrl(env: Record<string, string | undefined> = process.env): string {
  const configured = env.NEXT_PUBLIC_QRPAGER_STUDIO_URL

  if (configured === undefined || configured.trim() === '') {
    return SAME_ORIGIN_STUDIO_PATH
  }

  const value = configured.trim()

  // A same-origin path, and NOT a protocol-relative one.
  if (value.startsWith('/') && !value.startsWith('//')) {
    return value
  }

  if (value.startsWith('http://') || value.startsWith('https://')) {
    return value
  }

  return SAME_ORIGIN_STUDIO_PATH
}
