/**
 * The public tenant landing renderer (ADR-003). The RENDERER -- the pipeline that serves a
 * page on a tenant's domain -- is Task 11; this task supplies the templates it renders.
 *
 * SEPARATE FROM apps/web, and ADR-003 gives the reason: Task 11's gate is "cold page served
 * from CDN with origin untouched", and one Next.js app serving both a public cached page and
 * an authenticated dashboard makes that gate hard to prove and couples a marketing page's
 * deploy to the staff dashboard's.
 *
 * @type {import('next').NextConfig}
 */
export default {
  reactStrictMode: true,
  transpilePackages: ['@qrpager/ui', '@qrpager/tokens', '@qrpager/contracts'],
  poweredByHeader: false,
}
