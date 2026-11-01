/**
 * The customer PWA and the staff dashboard/KDS (ADR-003). Its SCREENS are Tasks 9 and 10;
 * what exists here at Task 8a is the application and its token wiring.
 *
 * `transpilePackages` is what lets the workspace packages ship TypeScript sources rather
 * than a build step each: @qrpager/ui and @qrpager/tokens export `.ts` directly, so Next
 * compiles them as part of this app. Three build pipelines for three packages nobody
 * publishes would be machinery for its own sake -- the same argument ADR-003 makes about
 * Turborepo.
 *
 * @type {import('next').NextConfig}
 */
export default {
  reactStrictMode: true,
  transpilePackages: ['@qrpager/ui', '@qrpager/tokens', '@qrpager/contracts'],
  // No `images.remotePatterns`: nothing here loads a third-party origin, which is Task 9's
  // gate clause ("the landing origin loads NO third-party script") one app over.
  poweredByHeader: false,

  /*
   * QRPAGER-24's ONE-ORIGIN REWRITE, and it is OPT-IN so that it does not exist in any
   * build that does not ask for it.
   *
   * THE PROBLEM IT SOLVES. The harness runs on a `cloudflared` quick tunnel, which exposes
   * ONE origin over HTTPS. iOS will not install or subscribe over plain http, and a page on
   * https cannot fetch http -- so the Laravel API has to be reachable over the same https
   * origin as the page, or the subscribe call is blocked as mixed content and the operator's
   * single session is spent discovering that. Routing `/v1/*` through Next makes page and
   * API same-origin, which also means no CORS configuration is added to `apps/api` -- a
   * change to Task 4's surface that §2 puts out of scope.
   *
   * ⚠ OPT-IN, AND THAT IS THE SECURITY-RELEVANT PART. A catch-all proxy to an internal
   * origin is not something a production build of the customer PWA should carry. It exists
   * only when `QRPAGER_HARNESS_API_ORIGIN` is set, so the default build -- and every build
   * CI makes -- has no rewrite at all. Task 9 and Task 13 own how the real deployment joins
   * these two origins (CloudFront, per `§4 › Edge`); this is a harness affordance and says
   * so.
   */
  async rewrites() {
    const origin = process.env.QRPAGER_HARNESS_API_ORIGIN

    if (!origin) {
      return []
    }

    return [{ source: '/v1/:path*', destination: `${origin}/v1/:path*` }]
  },
}
