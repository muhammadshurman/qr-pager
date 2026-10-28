# QRPAGER-14 — Task 7, Landing page builder, custom domains and TLS (S2)

> Branch `task-7-landing-builder`, cut from the `task-6` tag (`f7a81cc`), with
> `infra-local-mail-and-object-store` merged in its own commit (`a77d92d`) so this task does
> not introduce the object store it is tested against.
>
> The ticket is in Jira (`QRPAGER-14`). This file carries what `§6` requires to be written
> before any code, the `§12` screen specifications, the design decisions with their rejected
> alternatives, and the stop-and-reports. It restates no rule from
> `PROJECT_INSTRUCTIONS.md` or `PLAN.md`.

---

## 0. STATUS AND STOP-AND-REPORTS

**Status at the head of this branch: the seven `§6` answers are written and committed, and
no Task 7 code exists yet.** The handover is `docs/tickets/QRPAGER-14-handover.md`; its
STATUS line reads exactly one of `TASK-7 TAGGED` or `TASK-7 BLOCKED — <one line>`, and it is
pushed, current, at every phase boundary.

### ⚠ THIS SECTION WAS CORRECTED ON 2026-10-28, IN ITS OWN COMMIT, BEFORE ANY CODE

It read, from the moment this file was first committed at `84c919b`:

> **Status: in build.** The handover is `docs/tickets/QRPAGER-14-handover.md` and its STATUS
> line is current at every push.

**Neither half was true of the repository.** The handover did not exist — a `find` over the
whole tree for `*QRPAGER-14*` returned this file and nothing else — so "its STATUS line is
current at every push" described a file with no STATUS line and no pushes. And nothing was
*in build*: `84c919b` changes exactly one file, this one, and its own subject says so —
"the seven §6 answers, **before any code**".

What that line stated was an **intent**. What `§0` is for is the **state**. They are not the
same sentence and the difference is the whole value of the section: a reader who opened this
file to learn where the task stood would have been told there was a handover to read and a
build to review, and both would have been false. This is `PLAN.md §4a`'s most frequently
recorded defect — a record that claims a thing before the thing exists — and it is written
out here rather than quietly overwritten, because the quiet overwrite is what makes the
shape invisible the next time.

**The state at `84c919b`, measured rather than recalled:** `task-7-landing-builder` exists
locally and on `origin` at the same commit (`84c919b`), `git status --porcelain` is empty,
`infra-local-mail-and-object-store` is merged into this branch at `a77d92d` and into no other
branch, there is no `task-7` tag, and the only Task 7 artifact in the tree is this ticket.

**The rule this section now follows:** the status is what the repo shows. A clause here that
describes something not yet in the tree names the commit it is waiting for, or it does not
go here at all.

### SR-1 — `§4 › Tenancy`'s 2026-10-28 ruling and `§4.3`'s page inventory disagree

`PLAN.md §4.3` lists the **landing page builder** among the *Filament admin (Tasks 6–7)*
screens, and `PROJECT_INSTRUCTIONS.md §4 › Tenancy` (added 2026-10-28) says:

> The platform admin panel operates only on tables belonging to no tenant … A platform admin
> holds no tenant ability and no cross-tenant read: they can see that a restaurant exists and
> administer it from outside, and cannot see inside it. … **A panel query that reaches a
> tenant table is a rejected design.**

A landing page, its media and its domains are **tenant content**. So the builder cannot live
in the platform panel, and `§4.3`'s inventory describes a surface the ruling forbids. This is
the same shape as `R-115`, one task later: a `§3`/`§4.3` scope item that the ruling moved.

**Not a blocker, and recorded rather than resolved:** this task builds the builder as a
**tenant-scoped panel** (decision D1), which satisfies both documents without editing either.
`§4.3`'s wording is a rank-3 line in `PLAN.md`; correcting it is this task's `§5` row
(`R-127`), not a builder's edit to `§4`.

### SR-2 — the certificate infrastructure is Task 13's and the gate clause is Task 7's

`§3` row 7 requires *custom domain issues TLS automatically and the old domain redirects*.
The ticket's own out-of-scope table assigns **CloudFront, WAF and certificate infrastructure
as Terraform** to Task 13. Both are true, so this task owns the **application-side
lifecycle** — verification, issuance request, status, activation, redirect — behind two
ports, with the local driver as the one that runs here and the AWS driver deferred to Task 13
(decision D7). What this task cannot demonstrate is an ACM certificate existing in an AWS
account; what it does demonstrate is that **no domain reaches `active` without a verified DNS
challenge and a recorded certificate**, which is the falsifiable half.

### SR-3 — `§6.1`'s worker grant assumes a cross-tenant sweep that `§4` does not allow

Recorded 2026-10-28, at the start of the build, before the migration.

`§6.1` below says: *"`worker` gets `SELECT` on `tenant_domains` and `landing_pages` (the
verification and issuance polling commands run as the worker)"*. The parenthetical is the
grant's only justification, and it does not survive `PROJECT_INSTRUCTIONS.md §4 › Tenancy`:

> Cross-tenant reads exist **only** on these named paths: QR token lookup, a user's own
> memberships, the outbox queue, the expiry job. Adding one requires editing this file first.

A command that walks **every** tenant's domains to advance the ones whose certificate has
issued is a fifth cross-tenant read. Adding it means a rank-2 edit to
`PROJECT_INSTRUCTIONS.md`, which is a stop-and-report rather than a build step.

**Resolved without the edit, and recorded rather than resolved in passing:** the lifecycle
runs **inside the tenant's own scope** — from the `/studio/domains` screen, and from
`domains:advance {tenant}`, a console command that takes a tenant argument and opens
`withTenant()` for it. No sweep exists, so no cross-tenant path is needed and
**`qrpager_worker` is granted nothing at all on any of this task's three tables** — a grant
whose only justification was a code path that does not exist would be a widened surface for
nothing, against a grant table whose whole point is deny-by-default.

The product residual — a certificate becomes `active` when someone next looks rather than
the moment it issues — is `PLAN.md R-130`, owned by Task 13, which is where the AWS driver
and its event source arrive. `PLAN.md §2.6` and `§2.7` carry the same record.

---

## 4.1 THE SINGLE INVARIANT THIS TASK SHIPS ON

> **An unpublished draft is not reachable, no tenant-authored value can inject script or
> markup into a published page, and one tenant's media cannot be reached or guessed from
> another.**

That is the clause a review can falsify, and it is the **only** one that blocks `task-7` once
`§11`'s round limit is reached. It is `PLAN.md §3.1` row 7's wording, unchanged.

Three things are deliberately **not** in it, each with its own gate clause and its own `§5`
row if unmet: CDN caching (asserted as object metadata, not as a CDN), automatic TLS (SR-2),
and *theme overrides tokens only* (which `§3.1` row 7 says "names no mechanism" — this task
gives it one, `composer guard` rule 11, proven to fail on a fixture).

**Delivery:** tag `task-7` created and pushed, no PR and no merge.

---

## 6. THE SEVEN PRE-CODING ANSWERS

### 1. Tables, columns, roles, GUCs

**Three new tenant tables**, each with `tenant_id` as its scope column, so C4's meta-test
requires `ENABLE` + `FORCE` and the `tenant_isolation_*` / `owner_bypass_*` pair on all three
— and gets them. `PLAN.md §2.5`, `§2.6` and `§2.7` are updated **before** the migration, as
`§6.1` requires.

| Table | Scope | Why it is a tenant table and not a platform one |
| --- | --- | --- |
| `landing_pages` | `tenant_id` | the page is the tenant's own content; one row per tenant (`UNIQUE (tenant_id)`) |
| `media_assets` | `tenant_id` | tenant-uploaded bytes, served to the public only after a publish |
| `tenant_domains` | `tenant_id` | a hostname a tenant controls, and the redirect graph between them |

**No new GUC.** C1's two are the only ones; every query runs inside `app.tenant_id` opened by
`withTenant()` in the service layer, never by middleware (`RequireTenantScope`'s docblock is
the reason: a request-scoped transaction would hold one of the forty `qrpager_app`
connections for the whole request).

**Two new Postgres enums**, both registered in `DatabaseEnums::map()` so `EnumContractTest`
fails if either side drifts: `landing_page_status` (`draft`, `published`) and `domain_status`
(`pending_dns`, `dns_verified`, `certificate_pending`, `active`, `redirecting`, `failed`).

**Column grants (`§2.7`)**: `app` gets `SELECT`, `INSERT` and a **named column list** on
`UPDATE` for all three; `worker` gets `SELECT` on `tenant_domains` and `landing_pages` (the
verification and issuance polling commands run as the worker) and **nothing at all** on
`media_assets` — no queue job touches media. No role gets `DELETE` on `landing_pages`.

**Structural isolation, in the schema rather than in a policy:**

```sql
CONSTRAINT media_assets_key_is_tenant_scoped
    CHECK (object_key LIKE ('media/' || tenant_id::text || '/%'))
CONSTRAINT media_assets_key_is_unguessable
    CHECK (object_key ~ ('^media/' || tenant_id::text || '/[0-9a-f]{32}\.[a-z0-9]{2,5}$'))
```

So a row cannot name another tenant's prefix even with RLS disabled, and the leaf name is 32
hex characters of CSPRNG — 128 bits, the same bar `§4 › Data` sets for the QR token. The
reverse direction (a key in the store with no row) is reported by nothing — `media:orphans` was
named here and in two docblocks and was never written, which the `§11` QA review found. The
residual is `PLAN.md R-136`; the citation is removed rather than the command being invented in a
fix pass.

### 2. The block schema has ONE source, shared with Task 11's renderer

`App\Domain\Landing\BlockType` (the seven blocks of `PLAN.md §4.4`) and
`App\Domain\Landing\BlockSchema` (each block's fields, their types and their bounds) are the
source. From them, `php artisan landing:schema-export` generates
**`docs/contracts/landing-page.schema.json`**, a JSON Schema document which is committed and
is what Task 11's renderer consumes.

`bin/landing-schema-check` re-exports and diffs, is a step in `bin/gate` and a step in CI, and
fails when the committed artifact is stale — the same mechanism `openapi:check` already is for
the OpenAPI document, for the same reason: *a stale generated file is the quietest kind of
wrong*. **There is no second definition of a block anywhere**, and nothing hand-copies one.

### 3. Tenant context, including how a published page is addressed

**In the builder:** the user's tenant comes from `public.user_memberships(uuid)` — `§4 ›
Tenancy`'s second named cross-tenant path, built at Task 5 — and every read and write then
happens inside `withTenant($tenantId, …)` in the service layer. The panel adds **no** new
cross-tenant path.

**In the published page: there is no request-time tenant resolution at all, and that is the
design.** A publish writes a static document to the object store under
`sites/{hostname}/index.html`. The CDN maps `Host` → that prefix (Task 13), so serving a
published page involves **no application process, no database query and no tenant context**.

That is deliberate, and it is the answer to the question the ticket asks — *how a published
page is addressed by custom domain before any tenant context exists*. **A hostname → tenant
lookup at request time would be a fifth cross-tenant read**, which `§4 › Tenancy` forbids
without a rank-2 edit first. So the design does not need one: the mapping lives in the key
layout and in the CDN, where the hostname already is.

### 4. Security — the sanitisation boundary, once, server-side

**Every block is tenant-authored content, and no tenant-authored value is ever HTML.** The
boundary is one class, `App\Domain\Landing\LandingDocument::fromInput()`, and it is a
**validator, not a sanitiser**: an input that does not match the schema is **refused**, never
stripped and re-rendered.

| Field kind | Rule |
| --- | --- |
| text, heading, paragraph | plain text only, length-bounded, `<`, `>` and `&` kept as data and escaped at render |
| url | `https:` only, plus `mailto:` and `tel:` on the contact block, parsed by `parse_url` and re-assembled — never passed through |
| media reference | a uuid that must resolve to a `media_assets` row **in this tenant**; a foreign id resolves to nothing and the publish is refused |
| map | `latitude`, `longitude`, `zoom` as numbers in range. **Never an embed, an iframe or a provider URL** |
| theme token | an allow-listed name and a typed value (D6) |
| SEO | `title` ≤ 60, `description` ≤ 160, `og_image` a media reference, `structured_data` **derived** from the above and never authored |

There is **no rich-text field in v1**, so there is no HTML allow-list to get wrong. That is a
product limitation stated here rather than a gap: a tenant who needs bold text in a paragraph
gets it in a later task through a markup-free markdown subset, which is `R-128`.

Rendering escapes on output as well — `{{ }}` in the Blade template, never `{!! !!}` — so the
two halves are independent: a validator bug alone cannot inject, and an escaping bug alone
cannot either.

### 5. Scale — the cost of a publish and of serving a page

**Serving: origin untouched.** A published page is a static object with
`Cache-Control: public, max-age=60, s-maxage=31536000, stale-while-revalidate=86400`, so the
CDN serves every scan burst and the origin sees nothing. This is asserted as the object's
stored metadata, which is what the application controls; the CDN behaviour itself is Task 13's
and is stated as such rather than claimed.

**Publishing: bounded and infrequent.** One publish = one document render (no database read
per block — the draft is a single `jsonb` column), one `sha256`, one immutable object write,
one copy per referenced asset (bounded by the block schema: at most 24 media references per
page), one pointer write, one manifest write, and one `UPDATE` inside one transaction. Cost is
O(assets), the assets are already in the store, and the copy is server-side.

**Bounded:** the draft document is capped at 256 KB; blocks at 24 per page; each list block at
12 items; an upload at 5 MB and at the five image types below; media assets at 200 per tenant.
Every bound is a validator rule with a test, not a comment.

### 6. Failure — publish halfway, wrong DNS, a certificate that never issues

| Failure | Posture | Mechanism |
| --- | --- | --- |
| publish fails after the snapshot write | **fail-closed, previous page stays live** | the immutable `{checksum}.html` is written first, the stable `index.html` pointer **last**, and the database row is updated last of all — a failure at any step leaves the live page and the database on the previous publish |
| publish fails mid-asset-copy | **fail-closed** | assets are copied before the pointer write; a missing asset refuses the publish |
| object store unreachable | **fail-closed** | the publish throws, the panel shows the failure, nothing is half-published |
| DNS is wrong or absent | **fail-closed** | the domain stays `pending_dns` with a reason; `active` is unreachable without `dns_verified_at`, enforced by a CHECK constraint |
| certificate never issues | **fail-closed** | the domain stays `certificate_pending`; the old domain keeps serving because it is still the primary |
| a verification or issuance command crashes mid-run | **idempotent** | both are keyed per domain and re-runnable; neither writes a status it cannot re-derive |
| Redis down | unchanged from `R-027` | the panel's limiter posture is Task 2's, not this task's |

### 7. Research, verified by running code in this session

- `league/flysystem-aws-s3-v3 3.35.3` + `aws/aws-sdk-php 3.402.2` against the composed
  SeaweedFS: put, get with bytes compared, list, delete, a 20 MB multipart upload, a presigned
  GET, prefix listing with a delimiter, copy, move and `deleteDirectory` — all measured on
  2026-10-28 and recorded in `docs/adr/004-local-object-store.md`.
- **Object ACLs are refused in this application**, mechanically (`composer guard` rule 10):
  the stand-in honours them and production does not, so public read is a per-path CDN decision
  and never an ACL. That is ADR-004's trap, carried into this task's design (D3).
- `Filament\Tables\Table::records(?Closure)` exists in Filament 5.11 — verified in
  `vendor/filament/tables/src/Table/Concerns/HasRecords.php:42` — which is what lets a panel
  table be fed from a collection loaded inside a tenant scope, rather than from a query
  Filament executes outside one.
- Certificate issuance and DNS verification are **ports with a local driver** in this task; the
  measured output of each driver is in the handover.

---

## D. DESIGN DECISIONS, WITH THE ALTERNATIVE REJECTED

**D1 — the builder is a tenant-scoped Filament panel (`/studio`), not the platform panel.**
Rejected: the platform panel reading tenant tables (a rejected design per `§4`, SR-1);
deferring the builder to Task 10 (then S2 ships nothing in the task that owns it). Access
requires a membership of `owner` or `manager` in exactly one tenant — `staff` cannot edit the
page, which is `§4 › Security`'s seniority rule applied to content.

**D2 — no new `/v1` REST endpoint.** Everything is panel routes and console commands, so the
public API surface does not grow and `openapi:check` stays green with no new paths. Preview is
a panel route behind the same guard, serving `Cache-Control: no-store` and
`X-Robots-Tag: noindex`.

**D3 — a publish copies referenced assets into the site prefix.** `media/{tenant}/…` is the
private library and is **never** public; `sites/{hostname}/assets/…` holds only what a
published page references. So "public read on published assets" is one explicit prefix for the
CDN (Task 13) rather than an ACL per object, and an unpublished draft's media is unreachable
for the same reason its HTML is: it was never written there.

**D4 — the block schema is generated, not duplicated** (`§6.2` above).

**D5 — no HTML in, no HTML out.** Validator plus output escaping, independently.

**D6 — the theme is a token map, enforced three ways.** An allow-list of token names
(`ThemeTokens::NAMES`, one source), a typed validator per token (colour, length, font stack,
radius, number), and `composer guard` **rule 11**: one file in `app/` may emit a CSS
declaration, no other file may contain a `<style` literal, and the token list may not be
copied. Proven on a fixture, which is what `§3.1` row 7 asks for.

**D7 — two ports for the domain lifecycle** (`DnsVerifier`, `CertificateIssuer`), fail-closed,
local drivers here, AWS at Task 13 (SR-2).

**D8 — publish is write-new-then-switch.** The pointer write is the switch, the database is
the last word, and nothing is ever partially live.

---

## 12. SCREEN SPECIFICATIONS

Written before the screens were coded, as `§12` requires. The panel is **exempt from the
visual bar until Task 8 and never from the accessibility bar**: every screen below is
keyboard reachable, has a labelled control for every input, announces its own errors, and
carries no colour-only state.

### 12.1 `/studio` — Landing page builder

**Purpose:** the tenant's owner or manager edits and publishes the restaurant's public page.

| State | What is shown | Copy |
| --- | --- | --- |
| empty (no page yet) | the seven block toggles, all off, and a disabled Publish | "Your page has no blocks yet. Add one to get started." |
| draft, unpublished | block list with reorder, Save, Preview, Publish | "Draft — not visible to anyone yet." |
| draft with unpublished changes | as above, plus a changed-since-publish marker | "You have changes that are not published yet." |
| published and unchanged | as above, with the live hostname and the publish time | "Published to {hostname} on {date}." |
| invalid | the refusing field named, inline, and Publish disabled | "{Field} can't be published: {reason}" |
| publish failed | the previous publish still live, the reason shown | "Publish failed — your live page is unchanged. {reason}" |

### 12.2 `/studio/media` — Media library

| State | What is shown | Copy |
| --- | --- | --- |
| empty | an upload control and the accepted types | "No images yet. PNG, JPEG, WebP, AVIF or SVG-free SVG, up to 5 MB." |
| uploading | per-file progress, cancellable | "Uploading {name}…" |
| rejected | the file named and the real reason | "{name} was refused: it is a {detected} file, not an image." |
| full | upload disabled at 200 assets | "You have reached 200 images. Delete one to add another." |
| in use | a delete attempt on a referenced asset is refused | "{name} is used by the {block} block. Remove it there first." |

### 12.3 `/studio/domains` — Custom domains and TLS

| State | What is shown | Copy |
| --- | --- | --- |
| none | the platform hostname, and an add control | "Your page is live at {slug}.{platform}. Add your own domain to use it instead." |
| pending DNS | the exact TXT record to create, copyable | "Add this TXT record at your DNS provider, then choose Verify." |
| DNS verified | issuance in progress | "DNS verified. We are issuing a certificate — this usually takes a few minutes." |
| certificate pending, slow | the elapsed time and what to do | "Still issuing, {minutes} minutes so far. Nothing to do yet." |
| active | the live hostname, and which domain redirects to it | "Live at {hostname}. {old} redirects here." |
| failed | the reason and the retry | "We could not verify {hostname}: {reason}" |

---

## 3. THE ACCEPTANCE GATE, CLAUSE BY CLAUSE

Filled in as each is demonstrated; the evidence lands in the handover with the commit it was
measured at.

| `§3` row 7 clause | Where it is proven |
| --- | --- |
| publish produces a statically rendered, CDN-cached page | `LandingPublishTest` — the object exists, its bytes are the rendered document, its `ContentType` and `Cache-Control` are asserted from the store |
| an unpublished draft is not reachable | `LandingDraftUnreachableTest` — the store holds nothing for an unpublished page, the preview route refuses anonymously and across tenants, and `no-store` + `noindex` are asserted |
| a tenant cannot inject script through any block | `LandingXssFuzzTest` — every block, every field, 40+ payloads |
| custom domain issues TLS automatically and the old domain redirects | `DomainLifecycleTest` + `DomainRedirectTest` (SR-2 bounds what "issues" means here) |
| theme overrides tokens only, never components | `ThemeTokenTest` + `composer guard` rule 11, proven on a fixture |
| an upload is validated by real content type and size, not by extension | `MediaUploadTest` — a PNG-named HTML file is refused by content, not by name |
| one tenant's asset path cannot be guessed or reached from another | `MediaIsolationTest` — RLS, the key CHECK constraints, and a cross-tenant fetch attempt |
