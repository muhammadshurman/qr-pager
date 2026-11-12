# OWASP ASVS Level 2 — mapping with evidence per item

> **Task 12 (`QRPAGER-19`) · written 2026-11-07 · `§3` row 12's clause:**
> *"ASVS L2 table complete with evidence per item — including file-upload handling and mail
> header injection (ADR-002)."*

## How to read this, and what it deliberately is not

**Scope: what this codebase actually exposes.** ASVS L2 is roughly 280 requirements across
fourteen chapters, and most of them are about features this product does not have — there are
no customer accounts, no payments, no SAML, no OAuth provider, no WebSockets, no user-data
export. Mapping those would produce rows reading *"not applicable"* in bulk, which is length
rather than evidence. **What is here is every requirement this application's own surface
reaches**, each with the test that drives it.

**Every verdict is one of four, and the uncomfortable ones are the point:**

| verdict | means |
| --- | --- |
| **MET** | a test drives it, and the test is named |
| **PARTIAL** | the control exists and is bounded; the gap is named, with its `§5` row |
| **NOT MET** | it is not done. Said plainly, with where it belongs |
| **NOT MET (no environment)** | it cannot be evidenced here, because nothing is deployed and `§8` forbids claiming a control without captured output |

⚠ **EVERY EVIDENCE PATH IN THIS FILE IS CHECKED BY A TEST.**
`apps/api/tests/Feature/Architecture/AsvsMappingTest.php` parses this document and fails if a
cited file does not exist, if a row carries no verdict, or if a **MET** row cites no evidence
at all. A citation to a control that is not where it says it is is the `R-136` shape and was
`8a`'s first blocking finding — so this table cannot rot into a document that merely looks
thorough.

⚠ **AND A CLEAN TOOL RUN IS NOT ON THIS TABLE, DELIBERATELY.** `§3.1` row 12 says it in the
project's own words: *"Zero high/critical from SAST and DAST is a tool-output claim: a clean
run is not proof, only an absence of findings. Keep it, and do not let it stand in for the
three clauses above it."* Nothing below rests on a scanner having found nothing.

## Totals

| verdict | rows |
| --- | --- |
| **MET** | 47 |
| **PARTIAL** | 8 |
| **NOT MET** | 1 |
| **NOT MET (no environment)** | 3 |
| **total** | **59** |

## V1 Architecture, Design & Threat Modelling

| # | Requirement | Verdict | Evidence | Notes |
| --- | --- | --- | --- | --- |
| **1.2.2** | Communications between application components are authenticated and use least privilege. | **MET** | `apps/api/tests/Feature/Database/GrantMatrixTest.php`<br>`apps/api/tests/Feature/Database/DatabaseRolesTest.php`<br>`apps/api/tests/Feature/Database/WorkerScopeTest.php` | Three DB roles; `app` and `worker` hold no DDL, no BYPASSRLS and cannot become owner. The grant matrix is asserted against the live cluster, not read from a migration. |
| **1.4.4** | The application uses a single vetted access control mechanism. | **MET** | `apps/api/app/Platform/Auth/RouteAbilities.php`<br>`apps/api/tests/Feature/Auth/RoleMatrixTest.php`<br>`apps/api/tests/Feature/Auth/AuthGrantNarrowingTest.php` | One ability per route NAME in `RouteAbilities`, one matrix over all principals. `RequireAbility` takes no parameter, so a route with no mapping is refused rather than served. |
| **1.11.2** | All high-value business logic flows are thread safe and resistant to TOCTOU. | **MET** | `apps/api/tests/Feature/Pager/PagerTransactionTest.php`<br>`apps/api/tests/Feature/Auth/MemberConcurrencyTest.php`<br>`apps/api/tests/Feature/Staff/StaffBoardConcurrencyTest.php`<br>`apps/api/tests/Feature/Support/SupportMembershipTest.php` | Driven with real concurrent processes, not reasoned about. Support access is bounded by a PARTIAL UNIQUE INDEX because the service pre-check is racy by construction. |

## V2 Authentication

| # | Requirement | Verdict | Evidence | Notes |
| --- | --- | --- | --- | --- |
| **2.1.7** | Passwords are checked against a breach set, or a comparable control bounds guessing. | **PARTIAL** | `apps/api/config/ratelimit.php`<br>`apps/api/tests/Feature/Http/RateLimitTest.php`<br>`apps/api/tests/Feature/Http/RateLimitScaleTest.php` | No breach-corpus check. The bound is `auth-login` at 10/min/IP in front of a bcrypt and `auth-2fa` at 5/min, driven rather than configured, and `R-234` closed the one way a production `.env` could relax them. |
| **2.2.1** | Anti-automation controls are effective against credential stuffing and brute force. | **MET** | `apps/api/tests/Feature/Http/RateLimitTest.php`<br>`apps/api/tests/Feature/Platform/PanelRateLimitTest.php`<br>`apps/api/tests/Feature/Pager/PagerCustomerRateLimitTest.php` | Per-route buckets plus an `auth-surface` backstop that runs BEFORE the session starts — added because group middleware (CSRF, principal resolution) refused 100 requests apiece with zero 429s. |
| **2.2.3** | Secure notifications are sent after credential changes. | **NOT MET** | — | No notification on a password or passkey change. Recorded rather than claimed: nothing in this repository sends one. Outside Task 12's scope — it adds no auth feature — and belongs with Task 6's invite/mail work. |
| **2.5.4** | Shared or default accounts are not present. | **MET** | `apps/api/tests/Feature/Database/RoleNamingTest.php`<br>`apps/api/tests/Feature/Auth/FixtureScriptGuardTest.php` | `RoleNamingTest` pins the cluster's login roles as an EXACT set, so a leftover probe role fails the gate. The two fixture scripts refuse outside `local`/`testing`, proven in child processes. |
| **2.7.2** | Out-of-band authenticators expire after a short period. | **MET** | `apps/api/tests/Feature/Auth/TwoFactorTest.php`<br>`apps/api/tests/Feature/Auth/PasskeyTest.php` | TOTP window and passkey ceremony state. `R-104`'s silent re-keying half was FIXED at Task 5 rather than deferred. |
| **2.8.1** | TOTP verification bounds replay and guessing. | **MET** | `apps/api/tests/Feature/Auth/TwoFactorTest.php`<br>`apps/api/config/ratelimit.php` | `auth-2fa` is the tightest bucket in the project at 5/min/IP: six digits is a million possibilities inside a thirty-second window, so the bound is the control. |

## V3 Session Management

| # | Requirement | Verdict | Evidence | Notes |
| --- | --- | --- | --- | --- |
| **3.2.1** | A new session token is generated on authentication. | **MET** | `apps/api/tests/Feature/Auth/SessionPolicyTest.php`<br>`apps/api/tests/Feature/Staff/StaffSessionPolicyTest.php` | — |
| **3.3.1** | Logout invalidates the session. | **MET** | `apps/api/tests/Feature/Auth/SessionPolicyTest.php`<br>`apps/api/tests/Feature/Auth/AuthUnitGapsTest.php` | — |
| **3.3.3** | Sessions are invalidated after a password change. | **PARTIAL** | `apps/api/tests/Feature/Auth/SessionPolicyTest.php` | ⚠ `R-120`: a PANEL session survives a password change, because `AuthenticateSession` cannot fire on that stack. **Open and NOT closed here** — this task hardened responses, not the session stack. Ticked as partial rather than met, which is the point of this column. |
| **3.4.1** | Cookie-based tokens are Secure, HttpOnly and SameSite. | **MET** | `apps/api/tests/Feature/Auth/SessionPolicyTest.php`<br>`apps/api/config/session.php` | — |
| **3.5.3** | Stateless tokens are validated with a secure algorithm. | **MET** | `apps/api/tests/Feature/Auth/DeviceTokenScopeTest.php`<br>`apps/api/tests/Feature/Auth/DeviceTokenOnAuthSurfaceTest.php` | Sanctum stores `hash('sha256', $plain)`; the plaintext exists exactly once, on the issuing response. |

## V4 Access Control

| # | Requirement | Verdict | Evidence | Notes |
| --- | --- | --- | --- | --- |
| **4.1.1** | The application enforces access control rules on a trusted service layer. | **MET** | `apps/api/tests/Feature/Database/TenantIsolationTest.php`<br>`apps/api/tests/Feature/Database/RlsCoverageTest.php`<br>`apps/api/tests/Feature/Auth/PlatformAdminIsNotABypassTest.php` | Enforced in Postgres by RLS, not only in PHP. A platform admin is not a bypass and has its own test saying so. |
| **4.1.3** | Least privilege: users can only access functions for which they hold authorisation. | **MET** | `apps/api/tests/Feature/Auth/RoleMatrixTest.php`<br>`apps/api/tests/Feature/Pager/PagerGrantNarrowingTest.php`<br>`apps/api/tests/Feature/Support/SupportIsolationTest.php` | Support access is read-only by the PRINCIPAL rather than by the stored role — the stored role is `staff`, which holds `pager:create`, so had the role decided, every support membership ever taken could have opened sessions. |
> ⚠ **UPDATED 2026-11-12 BY `QRPAGER-47`'s `§11` SECURITY ROUND (Low 5).** The ticket added a
> new id-taking input (the landing document's `logo`, a media id supplied over Livewire) and a
> SECOND file-upload entry point (the theme tab's drop zone, over `MediaLibrary::store()`), and
> five rows below under-cited as a result: **4.2.1**, **5.1.3**, **12.1.1**, **12.2.1** and
> **12.5.2** now name `LandingLogoTest`. ⚠ **`AsvsMappingTest` COULD NOT HAVE CAUGHT THIS:** it
> fails when a CITED file is missing, never when a new surface goes uncited — so this table can
> stop describing the application without any gate going red. Whoever adds the next input or
> entry point has to come here by hand, which is what that finding is really about.

| **4.2.1** | Sensitive data and APIs are protected against IDOR. | **MET** | `apps/api/tests/Feature/Pager/PagerIdorTest.php`<br>`apps/api/tests/Feature/Landing/LandingLogoTest.php`<br>`apps/api/tests/Feature/Staff/PagerHistoryIsolationTest.php`<br>`apps/api/tests/Feature/Landing/MediaIsolationTest.php`<br>`apps/api/tests/Feature/Staff/StaffSurfaceIsolationTest.php` | Every route taking an id is proven inaccessible cross-tenant over HTTP, and the refusal is 404 rather than 403 so the id's existence is never confirmed. |
| **4.3.1** | Administrative interfaces use appropriate multi-factor authentication. | **PARTIAL** | `apps/api/tests/Feature/Platform/PlatformPanelInvariantTest.php`<br>`apps/api/tests/Feature/Auth/TwoFactorTest.php` | Both panels sit behind the `web` guard and `canAccessPanel()` additionally requires `is_platform_admin`; 2FA exists and is **not compulsory** for platform admins. A policy decision rather than a defect — named here so that it is a decision. |

## V5 Validation, Sanitisation & Encoding

| # | Requirement | Verdict | Evidence | Notes |
| --- | --- | --- | --- | --- |
| **5.1.1** | The application has defences against HTTP parameter pollution and mass assignment. | **MET** | `apps/api/tests/Feature/Http/FuzzSurfaceTest.php`<br>`apps/api/tests/Feature/Database/UserModelTest.php` | The fuzz corpus posts `id`, `tenant_id` and `is_platform_admin` to EVERY route as mass-assignment bait. |
| **5.1.3** | All input is validated. | **MET** | `apps/api/tests/Feature/Http/FuzzSurfaceTest.php`<br>`apps/api/tests/Feature/Auth/MembersTest.php`<br>`apps/api/tests/Feature/Landing/LandingSchemaContractTest.php`<br>`apps/api/tests/Feature/Landing/LandingLogoTest.php` | Every route driven with 30 hostile payloads in the body, with hostile values in every path parameter, and with 10 hostile headers: no 5xx. ~1,800 requests at `FUZZ_SEED=20261107`. |
| **5.2.3** | User-supplied content is sanitised against mail header injection. | **MET** | `apps/api/tests/Feature/Infrastructure/LocalMailCatcherTest.php`<br>`apps/api/tests/Feature/Http/FuzzSurfaceTest.php` | **ADR-002's named clause.** The corpus carries `a@b.test\r\nBcc: victim@c.test` and a bare CRLF against every route and every field, and mail is sent through the real Mailpit transport rather than an `array` mailer (`R-125`). |
| **5.2.5** | The application protects against template injection. | **MET** | `apps/api/tests/Feature/Http/FuzzSurfaceTest.php`<br>`apps/api/tests/Feature/Landing/LandingXssFuzzTest.php` | `{{ 7*7 }}` is in the corpus. `QRPAGER-33` made `landing/block.blade.php` the ONE place a block's markup is decided. |
| **5.2.6** | The application protects against SSRF. | **PARTIAL** | `apps/api/tests/Feature/Notifications/WebPushDispatcherTest.php` | ⚠ `R-084`: an arbitrary `https://` push endpoint IS an SSRF primitive — a browser supplies it and the worker fetches it. Bounded by Guzzle's pinned options and by the endpoint arriving on a subscription rather than in a request field. **Not closed.** It needs an egress allowlist, which is infrastructure: **Task 13**. |
| **5.3.3** | Output encoding prevents XSS, contextually. | **MET** | `apps/api/tests/Feature/Landing/LandingXssFuzzTest.php`<br>`apps/api/tests/Feature/Landing/StructuredDataTest.php`<br>`apps/api/tests/Feature/Http/FuzzSurfaceTest.php` | The landing renderer is fuzzed with hostile block content, and the studio preview is served `default-src 'none'; sandbox` so tenant markup cannot execute even if escaping failed — which Task 12 nearly BROKE: see `R-242` and `ResponseHeaderTest`'s own case for it. |
| **5.3.4** | Parameterised queries are used; output encoding prevents SQL injection. | **PARTIAL** | `apps/api/tests/Feature/Http/FuzzSurfaceTest.php`<br>`apps/api/tests/Feature/Pager/PagerTransitionTest.php` | ⚠ `R-064`: the status `UPDATE` is assembled by string interpolation — from an ENUM case, not from request input, so no caller can reach it. It is still interpolation and the row stays open. Everything else on the surface is bound. |

## V7 Error Handling & Logging

| # | Requirement | Verdict | Evidence | Notes |
| --- | --- | --- | --- | --- |
| **7.1.1** | The application does not log credentials or other secrets. | **MET** | `apps/api/tests/Feature/Logging/LogRedactionTest.php`<br>`apps/api/tests/Feature/Http/SurfaceDisclosureTest.php` | The real log FILE is read, not a spy — *"a log assertion against a spy proves the call was made, not what landed on disk."* Task 12 adds a surface-wide scan for secrets read from the live configuration. |
| **7.1.3** | The application logs security-relevant events. | **MET** | `apps/api/tests/Feature/Database/AuditLogImmutabilityTest.php`<br>`apps/api/tests/Feature/Pager/PagerAuditActorTest.php`<br>`apps/api/tests/Feature/Auth/MembersTest.php` | `audit_log` is append-only, superuser included. `R-237` closed the gap where **every** pager transition was audited with no actor at all. |
| **7.4.1** | A generic message is shown on an unexpected error. | **MET** | `apps/api/tests/Feature/Http/ProblemEnvelopeTest.php`<br>`apps/api/tests/Feature/Http/SurfaceDisclosureTest.php` | One RFC 7807 document for every throwable, at ANY `APP_DEBUG` setting. Measured 2026-11-07: **149 bytes** against Laravel's **32,425-byte** debug envelope carrying the statement, the database name, the host and port, and the whole trace. |

## V8 Data Protection

| # | Requirement | Verdict | Evidence | Notes |
| --- | --- | --- | --- | --- |
| **8.1.1** | Sensitive data is protected from caching on the client. | **MET** | `apps/api/tests/Feature/Http/ResponseHeaderTest.php` | `R-235`. `private, no-store` is the DEFAULT for every response, with an explicit allowlist of six vendor static assets — asserted over the router's own table rather than over a named four, which is how `R-235` happened. |
| **8.3.1** | Sensitive data is sent in the body or headers, never in the URL query string. | **PARTIAL** | `apps/api/tests/Feature/Pager/PagerTokenTest.php`<br>`apps/api/tests/Feature/Http/SurfaceDisclosureTest.php` | ⚠ A RULED DEVIATION rather than an oversight: the QR token is in the URL **fragment** (security S-1, 2026-10-27; `§3` row 3's *"never in any URL"* was amended). A fragment is never sent to a server and never placed in a `Referer`, and `Referrer-Policy: no-referrer` is now set on every response regardless. |
| **8.3.4** | All sensitive data created and processed is identified and has a protection policy. | **MET** | `apps/api/tests/Feature/Http/SurfaceDisclosureTest.php`<br>`apps/api/tests/Feature/Logging/LogRedactionTest.php` | **This is Task 12's named invariant**, and its proof drives the whole router-derived surface at both `APP_DEBUG` settings over bodies, headers and the log file. |

## V9 Communications

| # | Requirement | Verdict | Evidence | Notes |
| --- | --- | --- | --- | --- |
| **9.1.1** | TLS is used for all client connectivity. | **NOT MET (no environment)** | `apps/api/config/database.php` | Nothing is deployed, so there is no response to capture, and `§8` forbids claiming a control without output. `DB_SSLMODE` fails CLOSED at `require` by default and the local opt-out is explicit in `.env.example`, which is visible in a diff. Edge TLS and HSTS are **Task 13**. |
| **9.2.2** | Connections to and from the server use trusted TLS certificates. | **NOT MET (no environment)** | `apps/api/config/database.php` | Same reason. `sslmode => require` is the default rather than `prefer`, which silently downgrades — F7, from the security review of `task-1`. |

## V10 Malicious Code

| # | Requirement | Verdict | Evidence | Notes |
| --- | --- | --- | --- | --- |
| **10.3.2** | The application has integrity controls on third-party code and loads no code from untrusted sources. | **MET** | `apps/api/tests/Feature/Design/PanelThemeTest.php`<br>`apps/api/tests/Feature/Http/ResponseHeaderTest.php`<br>`apps/api/tests/Feature/Architecture/ApiNpmLicenceTest.php` | No third-party origin in either panel's markup, asserted over the rendered HTML AND as a property of the whole CSP string. **Task 12 removed the last one:** Filament's default `UiAvatarsProvider` was sending the SIGNED-IN PERSON'S NAME to `ui-avatars.com` on every panel page view. |

## V11 Business Logic

| # | Requirement | Verdict | Evidence | Notes |
| --- | --- | --- | --- | --- |
| **11.1.4** | The application has anti-automation controls on high-value flows. | **MET** | `apps/api/tests/Feature/Http/RateLimitTest.php`<br>`apps/api/tests/Feature/Pager/PagerCustomerRateLimitTest.php` | `devices-issue` is the tightest per-tenant bucket in the project because it mints a credential into `personal_access_tokens` — a GLOBAL table with no RLS behind it, so the rows are shared with every other tenant's. |

## V12 Files & Resources

| # | Requirement | Verdict | Evidence | Notes |
| --- | --- | --- | --- | --- |
| **12.1.1** | Uploaded files cannot exhaust storage or memory. | **MET** | `apps/api/tests/Feature/Landing/MediaUploadTest.php`<br>`apps/api/tests/Feature/Landing/StudioMediaUploadTest.php`<br>`apps/api/tests/Feature/Landing/LandingLogoTest.php` | **ADR-002's named clause.** The size bound is asserted against the real composed object store, not a faked disk. |
| **12.2.1** | Uploaded files are validated by type and content, not by extension. | **MET** | `apps/api/tests/Feature/Landing/MediaUploadTest.php`<br>`apps/api/tests/Feature/Landing/StudioMediaUploadTest.php`<br>`apps/api/tests/Feature/Landing/LandingLogoTest.php` | ADR-002: *"an uploaded file is validated by type, size and content."* |
| **12.3.1** | User-submitted filenames cannot be used directly by the filesystem. | **MET** | `apps/api/tests/Feature/Landing/MediaUploadTest.php`<br>`apps/api/tests/Feature/Http/FuzzSurfaceTest.php` | The corpus carries `../../../../etc/passwd` and its percent-encoded form against every route, every field and every path parameter. |
| **12.4.1** | Files from untrusted sources are stored outside the web root with limited permissions. | **MET** | `apps/api/tests/Feature/Landing/MediaIsolationTest.php`<br>`apps/api/tests/Feature/Infrastructure/LocalObjectStoreTest.php` | Object storage, not the local disk. Laravel's `storage/{path}` routes — including a PUT that writes request content into `storage/app/private` behind a signed URL — were REMOVED at Task 2, and `RouteSurfaceTest` asserts they stay gone. |
| **12.5.2** | Uploaded files are not served with a user-controlled content type. | **MET** | `apps/api/tests/Feature/Landing/MediaIsolationTest.php`<br>`apps/api/tests/Feature/Landing/LandingLogoTest.php`<br>`apps/api/tests/Feature/Http/ResponseHeaderTest.php` | `X-Content-Type-Options: nosniff` on every response (`R-038`), and the preview asset carries its own `default-src 'none'; img-src 'self'` policy which Task 12's middleware is forbidden to overwrite. |

## V13 API & Web Service

| # | Requirement | Verdict | Evidence | Notes |
| --- | --- | --- | --- | --- |
| **13.1.3** | API URLs do not expose sensitive information. | **MET** | `apps/api/tests/Feature/Http/OpenApiSpecTest.php`<br>`apps/api/tests/Feature/Http/RouteSurfaceTest.php` | The generated spec is checked against the live routes by `composer openapi:check`, a gate step. `R-052` closed a controller docblock that shipped the load-shed threshold as an endpoint description. |
| **13.2.1** | Enabled HTTP methods are a valid choice for the resource. | **MET** | `apps/api/tests/Feature/Http/RouteSurfaceTest.php`<br>`apps/api/tests/Feature/Http/FuzzSurfaceTest.php` | Every route outside `/v1` is on a hand-maintained allowlist, so a new public route arrives as a diff a reviewer sees rather than in a route table nobody reads. |
| **13.2.5** | REST services verify the Content-Type and reject unexpected ones. | **MET** | `apps/api/tests/Feature/Http/FuzzSurfaceTest.php` | Hostile `Content-Type`, `Accept`, `Accept-Encoding`, `Accept-Language`, `Authorization`, `Origin`, `Host`, `Cookie` and `X-Forwarded-*` headers driven against seven paths: no 5xx. |
| **13.2.6** | CORS is configured restrictively. | **MET** | `apps/api/tests/Feature/Http/CorsPolicyTest.php`<br>`apps/api/config/cors.php` | Closed **by decision** now rather than by `config/cors.php` being absent (`R-038` measured the latter). Driven: a real `OPTIONS` preflight and a simple cross-origin `GET` from `https://evil.example.com` against four paths, with no `Access-Control-*` header in either answer. |

## V14 Configuration

| # | Requirement | Verdict | Evidence | Notes |
| --- | --- | --- | --- | --- |
| **14.1.3** | Server configuration is hardened per the deployment guides. | **PARTIAL** | `apps/api/bin/fresh-clone-proof`<br>`compose.yaml` | The composed services are the real ones and the gates run from a fresh clone. The DEPLOYED configuration is **Task 13**. |
| **14.2.1** | All components are up to date, with a dependency inventory. | **MET** | `apps/api/bin/deps`<br>`apps/api/tests/Feature/Architecture/ApiNpmLicenceTest.php` | `composer deps` is a gate step and fails CLOSED on an unreachable registry: an unmeasured tree is not a pass. |
| **14.2.3** | Third-party assets are loaded from a trusted source or self-hosted. | **MET** | `apps/api/tests/Feature/Design/PanelThemeTest.php`<br>`apps/api/tests/Feature/Http/ResponseHeaderTest.php` | Self-hosted fonts (`QRPAGER-23 SR-4`) and, from Task 12, a self-rendered avatar. The CSP now ENFORCES what those tasks measured, which is the difference between a measurement and a control. |
| **14.3.2** | Debug modes are disabled and server details are not disclosed. | **MET** | `apps/api/tests/Feature/Http/SurfaceDisclosureTest.php`<br>`apps/api/tests/Feature/Http/ProblemEnvelopeTest.php` | ⚠ Stronger than the requirement, deliberately: `ProblemRenderer` is registered for ALL requests, so the property holds **with `APP_DEBUG=true`** — and `.env.example` ships `APP_DEBUG=true`, so that is the configuration a developer actually runs. |
| **14.4.1** | Every response sets a safe Content-Type with a character set. | **MET** | `apps/api/tests/Feature/Http/ProblemEnvelopeTest.php`<br>`apps/api/tests/Feature/Http/ResponseHeaderTest.php` | — |
| **14.4.3** | A Content-Security-Policy is in place that mitigates XSS. | **PARTIAL** | `apps/api/tests/Feature/Http/ResponseHeaderTest.php`<br>`apps/api/app/Platform/Http/Middleware/ContentSecurityPolicy.php` | ⚠ Two policies. **JSON surface:** `default-src 'none'` with `sandbox`, no `unsafe-inline`, no `unsafe-eval` — the clause MET. **Panels:** `'unsafe-inline'` and `'unsafe-eval'` are required by measurement; see **`R-242`** for the figures (4 inline scripts, 6 style blocks, 55 style attributes, 0 handlers) and for why hashes are not enough (Alpine constructs functions at run time). |
| **14.4.4** | All responses set X-Content-Type-Options: nosniff. | **MET** | `apps/api/tests/Feature/Http/ResponseHeaderTest.php` | `R-038`. Driven over every route in the router's table, plus a 404, a **driven** 429 and a rendered exception — the responses no controller wrote, which is where a per-controller header would have been missing. |
| **14.4.5** | A Strict-Transport-Security header is included. | **NOT MET (no environment)** | — | Deliberately not set. It is a property of a TLS deployment, nothing here is served over TLS, and a header committed without a captured response is exactly the claim `§8` forbids. **Task 13**, with the edge configuration. |
| **14.4.6** | A suitable Referrer-Policy header is included. | **MET** | `apps/api/tests/Feature/Http/ResponseHeaderTest.php` | `no-referrer`, not `strict-origin-when-cross-origin`: nothing on this origin needs a referrer, and a path can name a tenant and a session id. |
| **14.4.7** | Content is not served inside a frame unless intended. | **MET** | `apps/api/tests/Feature/Http/ResponseHeaderTest.php` | `X-Frame-Options: DENY` and CSP `frame-ancestors 'none'`, set together: the header for older middleboxes, the directive for everything else. |

## Project-specific — QRPAGER-19 comment 10012

| # | Requirement | Verdict | Evidence | Notes |
| --- | --- | --- | --- | --- |
| **REDIS-1** | Every Redis key holding tenant data is namespaced by a tenant id derived from the caller's context, never from client input. | **MET** | `apps/api/tests/Feature/Pager/PagerProjectionTest.php`<br>`apps/api/tests/Feature/Database/TenantContextTest.php`<br>`apps/api/tests/Feature/Http/RateLimitTest.php` | His reason: *"Redis is the one tenant-data store in this system with NO enforcement of its own — isolation there is entirely a naming convention, and conventions decay across fourteen tasks."* The projection keys are `t:<tenant>:pager:*`; the token pointers are `pager:tok:<sha256>`, a hash carrying no tenant data; the limiter buckets are keyed by `sha1(ip)` and by a tenant id from the resolver, never from a request field. |
| **GUARD-1** | The Task 2 lint rule still rejects session-scoped tenant settings, raw `SET app.*` and unknown `app.*` names, and still fails on a deliberately bad file. | **MET** | `apps/api/tests/Feature/Architecture/RepoGuardTest.php`<br>`apps/api/tests/Feature/Architecture/ArchitectureGuardTest.php`<br>`apps/api/tests/Feature/Architecture/GuardWorktreeScopeTest.php` | His reason: *"A guard that has silently stopped running is worse than no guard."* `composer guard` reports C1/C2 across **446 files in 6 paths** and is driven against deliberately bad fixtures. It produced a true positive during this very task: a `config/cors.php` docblock citing a test that did not exist. |

## Project-specific — §4 invariants

| # | Requirement | Verdict | Evidence | Notes |
| --- | --- | --- | --- | --- |
| **INV-1** | Cross-tenant reads exist only on the four named paths. | **MET** | `apps/api/tests/Feature/Database/TenantScopeGuardTest.php`<br>`apps/api/tests/Feature/Auth/MembershipFunctionGrantTest.php`<br>`apps/api/tests/Feature/Support/SupportIsolationTest.php` | `QRPAGER-22` kept the list at FOUR by making support access an ORDINARY membership row with an `expires_at`, read through the `user_memberships()` path that already existed, rather than adding a fifth. |
| **INV-2** | The app refuses to boot on an unsafe database role. | **MET** | `apps/api/tests/Feature/Boot/BootChecksTest.php`<br>`apps/api/tests/Feature/Database/RuntimeRoleGuardTest.php` | — |
| **INV-3** | Tenant context never leaks across a pooled connection. | **MET** | `apps/api/tests/Feature/Database/ConnectionReuseTest.php`<br>`apps/api/tests/Feature/Octane/RequestBoundaryTest.php`<br>`apps/api/tests/Feature/Auth/AuthOctaneIsolationTest.php` | ⚠ And `Tests\TestCase`'s class docblock now states what the feature suite does NOT evidence about this (`R-102`): the harness runs one container per test, so the instruments for request isolation are the two named here — not an ordinary green feature test. |


## The three clauses this table does not carry

`§3` row 12 names four things. This file is the second. The other three are discharged by
tests rather than documented here, and are listed so the gate can be read off one page:

| clause | where it is discharged |
| --- | --- |
| CSP has no `unsafe-inline` | `apps/api/tests/Feature/Http/ResponseHeaderTest.php` — **met in full on the JSON surface**; the panel half is `R-242`, with the measurement |
| the fuzz suite finds no 5xx | `apps/api/tests/Feature/Http/FuzzSurfaceTest.php` — ~1,800 requests at `FUZZ_SEED=20261107`; the only 5xx in the whole corpus was `/readyz`'s designed 503, which is excused by path AND status and asserted non-vacuous |
| the OpenAPI spec is checked against the live routes and exposes no endpoint that should be private | `composer openapi:check` (a gate step) and `apps/api/tests/Feature/Http/OpenApiSpecTest.php` |

And the invariant `§11` requires a task to name **before it starts**, which this task's `§3`
row now carries:

> *"No response from any surface, at any `APP_DEBUG` setting, carries a secret, a token, a QR
> value, SQL or a bound parameter — proven by driving the WHOLE route surface, derived from
> the router rather than hand-written, and asserting zero occurrences in captured bodies,
> headers and log output."*

Its proof is `apps/api/tests/Feature/Http/SurfaceDisclosureTest.php`, whose **first case is a
positive control**: a route that discloses every needle must be FOUND by the same scanner, or
no absence assertion in that file means anything.
