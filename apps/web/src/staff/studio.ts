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

/**
 * ⚠⚠ THE DEFAULT ARGUMENT IS THE LITERAL `process.env.NEXT_PUBLIC_…` EXPRESSION, AND IT HAS TO
 * BE — THIS WAS A REAL DEFECT, CAUGHT BY A BROWSER AND BY NOTHING ELSE.
 *
 * The first version took an ENVIRONMENT (`env: Record<string, string | undefined> =
 * process.env`) and read `env.NEXT_PUBLIC_QRPAGER_STUDIO_URL` off it, which is tidier and
 * testable and **silently broken in production**. Next.js inlines `NEXT_PUBLIC_*` by
 * replacing that exact expression TEXTUALLY at build time; a property read off a variable is
 * not that expression, so nothing was inlined, `process.env` was `{}` in the browser, and the
 * function returned its default for everybody.
 *
 * The unit tests all passed — they injected the environment, which is precisely the path the
 * browser never takes. What caught it was the `e2e` case clicking the link and landing on a
 * **Next.js 404**: the staff app asked for `/studio` on its OWN origin, where no such route
 * exists. A testability choice had defeated the mechanism it was testing.
 *
 * Taking the VALUE rather than the environment keeps both properties: the call site is the
 * literal expression Next can inline, and a test still passes a value with no environment at
 * all.
 */
export function studioUrl(
  configured: string | undefined = process.env.NEXT_PUBLIC_QRPAGER_STUDIO_URL,
): string {
  if (configured === undefined || configured.trim() === '') {
    return SAME_ORIGIN_STUDIO_PATH
  }

  const value = configured.trim()

  /*
   * A same-origin path, and NOT one the browser will resolve to another ORIGIN.
   *
   * ⚠ BOTH SLASHES, AND IT USED TO BE ONE. This read `!value.startsWith('//')`, which closes
   * the protocol-relative case the docblock above names and leaves its twin open: the WHATWG
   * URL parser — the one that resolves every `href` — treats `\` as `/` for special schemes,
   * so `/\evil.example` is NOT a path. Measured with Node's `URL` against
   * `https://staff.example.test/staff`:
   *
   *     "//evil.example"  -> https://evil.example/
   *     "/\evil.example"  -> https://evil.example/      <- passed the old guard
   *     "/studio"         -> https://staff.example.test/studio
   *
   * `§11` SECURITY L-2. The value is a build-time `NEXT_PUBLIC_*`, so reaching it means
   * already controlling the build — which is why it is a hardening gap rather than a live
   * path, and why the fix is still one character: the staff shell renders this as the
   * destination of a control labelled *"edit your page"*, and a member of staff who lands on
   * an attacker's origin and is asked to sign in has every reason to.
   */
  if (value.startsWith('/') && !value.startsWith('//') && !value.startsWith('/\\')) {
    return value
  }

  if (value.startsWith('http://') || value.startsWith('https://')) {
    return value
  }

  return SAME_ORIGIN_STUDIO_PATH
}
