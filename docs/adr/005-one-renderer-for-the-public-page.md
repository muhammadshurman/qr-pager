# ADR 005 — One renderer for the public page, and it is the publisher's

- Status: accepted
- Date: 2026-11-03
- Supersedes: **`ADR-003`'s description of `apps/site` as "the public tenant landing renderer"
  only.** Nothing else in ADR-003 is touched: pnpm workspaces, the package layout, the
  tokens/ui/contracts split, and the reason `apps/site` is a *separate deployable* from
  `apps/web` all stand. What changes is what that deployable does.
- Amends: `PLAN.md §3` row 11, whose task cell read "Next.js static/ISR rendering of S2 pages"
- Decided by: Muhammad, 2026-11-03, on a question put to him with the measurement below

## Context

`PLAN.md §3` row 11 and `ADR-003` both describe Task 11 as a **Next.js static/ISR renderer** for
the tenant landing page. `PLAN.md §3` row 7 describes Task 7 as the landing page builder whose
scope ends in **"publish → static render"**, with a gate clause of its own: *"Publish produces a
statically rendered, CDN-cached page."*

Task 7 shipped that, and it is live in the repository:

- `App\Features\Landing\LandingRenderer` turns a validated `LandingDocument` plus a hostname into
  the bytes of a page, through the Blade template `landing.page`. Its docblock states the property
  the publish path depends on: **no database, no request, no tenant context** — everything arrives
  as arguments, so rendering the same document twice produces the same bytes.
- `App\Features\Landing\LandingPages::publish()` renders, then writes three objects:
  `sites/{hostname}/{checksum}.html` (the immutable snapshot), `sites/{hostname}/index.html` (the
  pointer, whose write **is** the switch — decision D8) and `sites/{hostname}/manifest.json`.
- `App\Features\Landing\PublishedSite` is the one place the key layout is decided, and its
  docblock records why there is no hostname → tenant lookup at request time: *"The CDN maps `Host`
  -> that prefix (Task 13), so serving a published page involves NO application process, no
  database query and no tenant context at all."* A request-time lookup would be a fifth
  cross-tenant read, which `PROJECT_INSTRUCTIONS.md §4 › Tenancy` forbids without a rank-2 edit.

So the page is already rendered, already static, and already addressed by host. A Next.js renderer
in `apps/site` would be a **second rendering of one contract**.

Three things were measured before the question was put, rather than argued:

1. **The contract has no template selector.** `docs/contracts/landing-page.schema.json` has
   exactly three top-level members — `blocks`, `theme`, `seo` — and `theme` is twelve token values.
   `@qrpager/ui/templates` exports **three** templates (`classic`, `editorial`, `compact`, from
   Task 8a) and **nothing in the contract chooses between them.** Adding a selector is a change to
   `App\Domain\Landing\BlockSchema`, which `QRPAGER-18 §2` puts out of Task 11's scope in its own
   words: *"The block schema is Task 7's single source. This task consumes it. Redefining a block
   here is the exact contradiction this project is structured to prevent."*
2. **The second renderer would create the risk the gate's invariant is about.** With published
   bytes under a per-host prefix, the cache key **is** the host prefix and two tenants cannot
   collide in it — there is no key to get wrong. An ISR renderer has a cache key that must be
   *made* to contain the host, and that is precisely the leak `§3` row 11's invariant names.
3. **`apps/site` has had no local proof of anything.** It is ten files: a placeholder `page.tsx`
   saying the renderer is Task 11, a layout, and config. The one thing on this surface with no
   local proof is the `Host` → prefix mapping itself, which lives in the CDN (Task 13) and
   therefore in no test.

## Decision

**There is one renderer of the public page and it is `LandingRenderer`, reached through
`LandingPages::publish()`.** Task 11 does not render the page a second time.

`apps/site` is retained as a deployable, with its purpose restated: it is the **local stand-in for
the CDN's `Host` → published-prefix mapping**. It resolves a request's host to
`sites/{host}/index.html` in the object store and serves those bytes. It renders no blocks, reads
no database, holds no tenant context and imports no template. This is the same relationship
`ADR-004` establishes for the object store itself: a stand-in locked to an interface, proven by a
round trip rather than by a port check, and nothing under `apps/` depending on which one is running.

Task 11's work is therefore: prove the gate's invariant across the three mechanisms Muhammad named
(cache key, custom-domain mapping, revalidation/republish path), ship the local mapping stand-in,
and discharge the `§5` rows it owns on this surface.

## Consequences

- The `Host` → prefix mapping gets a local, driven proof for the first time, and the proof is run
  against the same code path a CDN misconfiguration would break.
- `R-133` (the platform hostname keeps serving the old page after a custom domain goes live),
  `R-150` (the apex platform domain is not refused) and `R-170` (`manifest.json` may name a
  different checksum from the one `index.html` serves) stop being three loose rows and become the
  three mechanisms of one invariant. They are discharged by the proof or restated by it.
- `@qrpager/ui/templates`' three templates have **no consumer**. That is a real cost of this
  decision and it is named rather than hidden: they are tested, token-clean and unused, and the
  rows that sit on them (`R-175`, `R-179`, `R-180`, `R-183`) do not become cheaper. Giving them a
  consumer needs a `template` member in Task 7's schema, which is Task 7's change to make.
- `R-205`'s Lighthouse question is unaffected: it is about `§3` row 9's customer page, and `§0.0e`
  cuts the Lighthouse round either way.
- The structured-data clause (`R-131`) and the per-tenant `lang`/`dir` clause (`R-132`) are now
  changes to **one** template, `landing.page`, rather than to two.

## Rejected

- **Build the Next.js renderer as written.** Two renderings of one document, a schema change the
  ticket puts out of scope, and the cache-key risk introduced rather than avoided.
- **Both: Next.js public, Blade for the panel preview.** Worse than either. The preview exists so a
  tenant sees what the world will see; two renderers make that promise false the moment they drift,
  and the drift is silent because the two run in different languages with different test suites.
- **Delete `apps/site`.** Tempting, and rejected because the `Host` → prefix mapping is the one
  thing on this surface that no test reaches today. Deleting the app would leave that gap and lose
  the only place a local proof of it can live.
