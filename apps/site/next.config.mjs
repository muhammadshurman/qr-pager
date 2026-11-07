/**
 * The local stand-in for the CDN's `Host` -> published-prefix mapping (ADR-005).
 *
 * ⚠ **THIS APP RENDERS NOTHING, AND ADR-003's DESCRIPTION OF IT AS "the public tenant
 * landing renderer" IS SUPERSEDED.** Task 7 already renders the page and publishes the bytes
 * under `sites/{hostname}/`; a second rendering here would be the contradiction
 * `QRPAGER-18 §2` names. What has had no local proof is the MAPPING -- which host's bytes a
 * request gets -- because in production that lives in the CDN (Task 13) and therefore in no
 * test. This app is where that proof can live.
 *
 * SEPARATE FROM apps/web, which is ADR-003 unchanged and still the right call: a public,
 * cached, credential-free surface and an authenticated dashboard should not share a deploy.
 *
 * `transpilePackages` is down to one: `@qrpager/contracts`, for the generated key layout and
 * hostname rule. The template kit and the tokens are gone with the rendering, and so is
 * Tailwind -- this app ships no stylesheet at all, which is how `R-176` is discharged.
 *
 * @type {import('next').NextConfig}
 */
export default {
  reactStrictMode: true,
  transpilePackages: ['@qrpager/contracts'],
  poweredByHeader: false,
}
