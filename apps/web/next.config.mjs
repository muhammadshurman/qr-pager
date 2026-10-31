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
}
