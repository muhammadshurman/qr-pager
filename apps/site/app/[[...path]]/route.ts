import { INVARIANT_HEADERS, serve } from '../../src/serve.ts'
import { configFromEnvironment, objectReader } from '../../src/store.ts'

/**
 * The whole of `apps/site`: one route handler, serving published bytes by `Host`.
 *
 * ⚠ **THERE IS NO `page.tsx`, NO LAYOUT AND NO STYLESHEET IN THIS APP, AND THAT IS `ADR-005`
 * RATHER THAN AN OMISSION.** This process renders nothing. The page a visitor receives was
 * rendered by `App\Features\Landing\LandingRenderer` at publish time and carries its own
 * theme in one attribute; this handler maps `Host` to the key those bytes were written to and
 * returns them. A React tree here would be a second rendering of one contract — the
 * contradiction `QRPAGER-18 §2` names in its own words.
 *
 * It also means this app ships no CSS at all, which is how `R-176` is discharged: that row
 * wanted a compile-level proof that Tailwind's defaults are cleared in `apps/site`, and the
 * stylesheet whose defaults could leak no longer exists. `site-surface.test.ts` asserts the
 * absence, because "there is no CSS" is only a safe answer while it stays true.
 *
 * `force-dynamic` because the answer depends on a REQUEST HEADER. Next's default would try to
 * prerender a route with no dynamic inputs it can see, and a prerendered answer on this
 * surface is one host's page cached for every host — the exact failure `§3` row 11's
 * invariant is about. The declaration is the invariant, expressed in the one place the
 * framework reads.
 */
export const dynamic = 'force-dynamic'

/** No ISR: nothing here regenerates, so there is no revalidation window to get wrong. */
export const revalidate = 0

export async function GET(request: Request): Promise<Response> {
  const config = configFromEnvironment()

  if (config === null) {
    /*
     * A 503 with a reason, not a 500 and not a blank page. A missing bucket variable is a
     * deployment fault, and the honest answer names it without naming the variable's value.
     * It is deliberately NOT a fallback to anything: see `serve()`'s note on why "no page for
     * this host, serve something else" is the one branch that must not exist.
     */
    return new Response('This server is not configured to serve published pages.\n', {
      status: 503,
      headers: { ...INVARIANT_HEADERS, 'content-type': 'text/plain; charset=utf-8' },
    })
  }

  const url = new URL(request.url)
  const answer = await serve(objectReader(config), request.headers.get('host'), url.pathname)

  return new Response(answer.body, { status: answer.status, headers: answer.headers })
}
