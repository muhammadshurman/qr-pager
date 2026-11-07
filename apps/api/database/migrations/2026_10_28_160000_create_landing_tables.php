<?php

declare(strict_types=1);

use App\Platform\Database\DatabaseRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task 7's schema: the landing page, its media and its domains (PLAN.md §2.3, §2.5, §2.6, §2.7).
 *
 * THREE TENANT TABLES, so C4 applies without exception: ENABLE + FORCE on each, and the
 * tenant_isolation_* / owner_bypass_* pair on each. RlsCoverageTest reads that back out of
 * pg_catalog rather than out of this file, so the schema and the registry cannot drift.
 *
 * NO FIFTH CROSS-TENANT POLICY. §2.6's named list stays at four. The cost is that there is
 * no sweep over every tenant's domains, which is QRPAGER-14 §0 SR-3 and PLAN.md R-130: the
 * lifecycle runs inside the tenant's own scope, from the panel and from a console command
 * that takes a tenant argument.
 *
 * NOTHING IS GRANTED TO qrpager_worker ON ANY OF THE THREE, for the same reason -- SR-3.
 * The ticket's §6.1 granted it SELECT on two of them to serve a polling sweep; the sweep
 * does not exist, and a grant whose only justification is a code path that was never
 * written is a widened surface for nothing.
 *
 * Enum values are written out literally rather than read from the PHP enums, as every
 * migration in this repository does: a migration that reads today's enum is not a snapshot.
 * EnumContractTest is what keeps the two sides from drifting.
 */
return new class extends Migration
{
    private const TENANT_TABLES = ['landing_pages', 'media_assets', 'tenant_domains'];

    public function up(): void
    {
        DatabaseRoles::assertRolesExist(DB::connection());

        DB::unprepared(<<<'SQL'
            CREATE TYPE landing_page_status AS ENUM ('draft', 'published');
            CREATE TYPE domain_status       AS ENUM ('pending_dns', 'dns_verified',
                                                     'certificate_pending', 'active',
                                                     'redirecting', 'failed');

            CREATE TABLE landing_pages (
                id                  uuid                PRIMARY KEY,
                tenant_id           uuid                NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                status              landing_page_status NOT NULL DEFAULT 'draft',
                -- The whole draft, as ONE jsonb column. §6.5's cost argument rests on this:
                -- a publish reads no row per block, because there is no row per block.
                draft_document      jsonb               NOT NULL DEFAULT '{}'::jsonb,
                draft_updated_at    timestamptz         NOT NULL DEFAULT now(),
                -- The four published columns. All NULL or all set, enforced below.
                published_document  jsonb,
                published_checksum  char(64),
                published_hostname  varchar(253),
                published_at        timestamptz,
                created_at          timestamptz         NOT NULL DEFAULT now(),
                updated_at          timestamptz         NOT NULL DEFAULT now(),

                -- ONE PAGE PER TENANT. This is what makes "the tenant's page" a
                -- well-defined object with no selection step anywhere in the code.
                CONSTRAINT landing_pages_tenant_unique UNIQUE (tenant_id),

                -- THE PUBLISH IS ALL OR NOTHING, in the schema and not only in the service.
                -- §6.6's fail-closed posture expressed where no code path can side-step it:
                -- there is no half-published row to crash into.
                CONSTRAINT landing_pages_publish_is_all_or_nothing CHECK (
                    (status = 'draft'
                        AND published_document IS NULL
                        AND published_checksum IS NULL
                        AND published_hostname IS NULL
                        AND published_at       IS NULL)
                    OR
                    (status = 'published'
                        AND published_document IS NOT NULL
                        AND published_checksum IS NOT NULL
                        AND published_hostname IS NOT NULL
                        AND published_at       IS NOT NULL)
                ),

                -- 256 KB is §6.5's bound and LandingDocument::fromInput() is where it is
                -- ENFORCED; these two are the backstop, and they are deliberately 50%
                -- looser so that the validator is always the first to fire.
                --
                -- ⚠ THE TWO COUNT DIFFERENT THINGS, which is why the margin exists.
                -- `jsonb::text` renders a space after every `:` and every `,`, and PHP's
                -- json_encode does not -- measured in this project's container,
                -- octet_length('{"a":1,"b":[1,2,3]}'::jsonb::text) is 24 against 19 for
                -- the same literal. At 256 KB exactly that is a couple of KB of
                -- separators, so a document the validator accepted could still raise
                -- 23514 here: the tenant would get a driver error instead of §12.1's
                -- named refusal, which is the defect the validator was added to remove,
                -- one order of magnitude closer to the limit. Found by the §11 review of
                -- the fix diff, round 1.
                --
                -- 393216 = 1.5 x 262144. The separator expansion cannot approach 50% --
                -- it is two bytes per key or element against the bytes of the key and
                -- value themselves -- so the validator fires first for every document
                -- that can exist, and this constraint keeps its job: catching a writer
                -- that never went through the validator at all.
                CONSTRAINT landing_pages_draft_is_bounded CHECK (
                    octet_length(draft_document::text) <= 393216
                ),
                CONSTRAINT landing_pages_published_is_bounded CHECK (
                    published_document IS NULL OR octet_length(published_document::text) <= 393216
                ),
                CONSTRAINT landing_pages_checksum_is_sha256 CHECK (
                    published_checksum IS NULL OR published_checksum ~ '^[0-9a-f]{64}$'
                )
            );

            CREATE TABLE media_assets (
                id            uuid         PRIMARY KEY,
                tenant_id     uuid         NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                object_key    text         NOT NULL,
                original_name varchar(255) NOT NULL,
                -- The DETECTED type, never the declared one. MediaUploads is what detects it;
                -- this column is where the detection is recorded, so a later reader never has
                -- to trust a filename either.
                content_type  varchar(64)  NOT NULL,
                byte_size     integer      NOT NULL,
                checksum      char(64)     NOT NULL,
                width         integer,
                height        integer,
                created_at    timestamptz  NOT NULL DEFAULT now(),
                updated_at    timestamptz  NOT NULL DEFAULT now(),

                CONSTRAINT media_assets_object_key_unique UNIQUE (object_key),

                -- ================================================================
                -- THE TWO CONSTRAINTS THE TASK'S NAMED INVARIANT RESTS ON
                -- ================================================================
                --
                -- "One tenant's media cannot be reached or guessed from another."
                --
                -- Deliberately CHECK constraints and not policies. A policy is evaluated
                -- per role, and owner_bypass_media_assets would skip it; a CHECK binds
                -- every writer including qrpager_owner with app.bypass_tenant on. So a row
                -- cannot name another tenant's prefix EVEN WITH RLS DISABLED, which is a
                -- strictly stronger statement than isolation.
                CONSTRAINT media_assets_key_is_tenant_scoped CHECK (
                    object_key LIKE ('media/' || tenant_id::text || '/%')
                ),
                -- 32 hex characters of CSPRNG: 128 bits, the same bar §4 › Data sets for
                -- the QR token. The key is therefore not derivable from anything the
                -- tenant, or anyone else, can see.
                --
                -- THE EXTENSION SET IS PART OF THE PATTERN, and it was widened to this from
                -- a bare `[a-z0-9]{2,5}` when LandingTableContractTest drove the constraint
                -- and `media/{tenant}/{32 hex}.html` was ACCEPTED. `html` is four lowercase
                -- letters, so the loose form admitted it -- and the extension is what a CDN
                -- and S3 infer a served Content-Type from, so an .html object key is an
                -- HTML document served from a tenant's own domain however carefully the
                -- content_type column was filled in. One canonical extension per type, so
                -- there is no .jpeg/.jpg pair to disagree about either.
                CONSTRAINT media_assets_key_is_unguessable CHECK (
                    object_key ~ ('^media/' || tenant_id::text || '/[0-9a-f]{32}\.(png|jpg|webp|avif|svg)$')
                ),

                -- AND THE EXTENSION MUST AGREE WITH THE DETECTED TYPE. Without this the two
                -- controls above are independent in the wrong direction: a row could record
                -- `image/png` against a key ending `.svg`, and the object would be served as
                -- an SVG by the one layer that never reads this column.
                CONSTRAINT media_assets_key_matches_content_type CHECK (
                    (content_type = 'image/png'      AND object_key LIKE '%.png')
                    OR (content_type = 'image/jpeg'  AND object_key LIKE '%.jpg')
                    OR (content_type = 'image/webp'  AND object_key LIKE '%.webp')
                    OR (content_type = 'image/avif'  AND object_key LIKE '%.avif')
                    OR (content_type = 'image/svg+xml' AND object_key LIKE '%.svg')
                ),

                -- The five types §12.2's copy names, pinned in the schema as well as in the
                -- validator, for D5's reason: two independent halves.
                CONSTRAINT media_assets_content_type_is_allowed CHECK (
                    content_type IN ('image/png', 'image/jpeg', 'image/webp',
                                     'image/avif', 'image/svg+xml')
                ),
                -- 5 MB, §6.5's bound. Zero bytes is not an image.
                CONSTRAINT media_assets_size_is_bounded CHECK (byte_size > 0 AND byte_size <= 5242880),
                CONSTRAINT media_assets_checksum_is_sha256 CHECK (checksum ~ '^[0-9a-f]{64}$'),
                -- An SVG legitimately has no intrinsic pixel size, so both are nullable --
                -- but a recorded dimension is a positive one.
                CONSTRAINT media_assets_dimensions_are_positive CHECK (
                    (width IS NULL OR width > 0) AND (height IS NULL OR height > 0)
                )
            );

            CREATE INDEX media_assets_library_index ON media_assets (tenant_id, created_at DESC);

            CREATE TABLE tenant_domains (
                id                     uuid          PRIMARY KEY,
                tenant_id              uuid          NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                hostname               varchar(253)  NOT NULL,
                status                 domain_status NOT NULL DEFAULT 'pending_dns',
                verification_token     varchar(64)   NOT NULL,
                dns_verified_at        timestamptz,
                certificate_identifier text,
                certificate_issued_at  timestamptz,
                activated_at           timestamptz,
                redirects_to_domain_id uuid,
                last_error             text,
                checked_at             timestamptz,
                created_at             timestamptz   NOT NULL DEFAULT now(),
                updated_at             timestamptz   NOT NULL DEFAULT now(),

                -- GLOBALLY UNIQUE, and that is an enumeration oracle, accepted as R-129:
                -- a unique index is checked against the real table and bypasses RLS, so a
                -- 23505 says some other tenant holds the hostname. It discloses a fact dig
                -- already answers, and the alternative -- a per-tenant index -- would let
                -- two tenants claim one hostname and make the CDN's Host mapping ambiguous.
                CONSTRAINT tenant_domains_hostname_unique UNIQUE (hostname),

                -- The composite FK target, exactly as pager_sessions carries one for
                -- push_subscriptions and outbox (§2.5).
                CONSTRAINT tenant_domains_tenant_id_id_unique UNIQUE (tenant_id, id),

                -- THE REDIRECT GRAPH CANNOT LEAVE THE TENANT. Referential integrity is
                -- checked against the real table and bypasses RLS, so a cross-tenant
                -- redirect raises 23503 rather than depending on a policy holding. MATCH
                -- SIMPLE: with redirects_to_domain_id NULL the constraint is not checked,
                -- which is the ordinary no-redirect case.
                CONSTRAINT tenant_domains_redirect_stays_in_tenant
                    FOREIGN KEY (tenant_id, redirects_to_domain_id)
                    REFERENCES tenant_domains (tenant_id, id) ON DELETE SET NULL,
                CONSTRAINT tenant_domains_redirect_is_not_self CHECK (
                    redirects_to_domain_id IS NULL OR redirects_to_domain_id <> id
                ),

                -- A hostname, lower-cased, as labels separated by dots. Not a full RFC 1035
                -- grammar: what this rejects is uppercase, a scheme, a port, a path, a
                -- leading or trailing dot and an empty label, which is what an input field
                -- actually receives when it is wrong.
                CONSTRAINT tenant_domains_hostname_is_dns_name CHECK (
                    hostname ~ '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$'
                ),

                -- ================================================================
                -- SR-2's FALSIFIABLE HALF, IN THE SCHEMA
                -- ================================================================
                --
                -- "No domain reaches active without a verified DNS challenge and a recorded
                -- certificate." DomainStatus::allows() says the same thing and binds only
                -- the code that reads it; this binds every writer, owner included.
                CONSTRAINT tenant_domains_active_is_earned CHECK (
                    status <> 'active' OR (
                        dns_verified_at        IS NOT NULL
                        AND certificate_identifier IS NOT NULL
                        AND certificate_issued_at  IS NOT NULL
                        AND activated_at           IS NOT NULL
                    )
                ),
                -- Every status past the DNS challenge carries the timestamp that proves it.
                -- `redirecting` is included: a superseded domain keeps what it earned, which
                -- is what makes redirecting -> active legal later.
                CONSTRAINT tenant_domains_verified_statuses_have_a_timestamp CHECK (
                    status NOT IN ('dns_verified', 'certificate_pending', 'active', 'redirecting')
                    OR dns_verified_at IS NOT NULL
                ),
                -- A pre-verification status cannot be carrying one.
                CONSTRAINT tenant_domains_unverified_statuses_have_none CHECK (
                    status NOT IN ('pending_dns', 'failed') OR dns_verified_at IS NULL
                ),
                -- A redirect is a redirect TO something.
                CONSTRAINT tenant_domains_redirecting_has_a_target CHECK (
                    status <> 'redirecting' OR redirects_to_domain_id IS NOT NULL
                ),
                -- A certificate is recorded as a pair or not at all.
                CONSTRAINT tenant_domains_certificate_is_whole CHECK (
                    (certificate_identifier IS NULL AND certificate_issued_at IS NULL)
                    OR (certificate_identifier IS NOT NULL AND certificate_issued_at IS NOT NULL)
                )
            );

            -- ONE active domain per tenant. The CDN maps Host -> prefix, and two active
            -- hostnames for one tenant would be two prefixes for one page. A superseded
            -- domain is `redirecting`, which this index does not count.
            CREATE UNIQUE INDEX tenant_domains_one_active_per_tenant
                ON tenant_domains (tenant_id)
                WHERE status = 'active';

            CREATE INDEX tenant_domains_tenant_index ON tenant_domains (tenant_id, created_at DESC);
        SQL);

        // C4, no exception: ENABLE + FORCE and the two policies on all three. Written out
        // per table with the role names as literals, as every policy migration here is --
        // a migration is a snapshot, and generated DDL would change meaning for a fresh
        // database the moment config changed.
        DB::unprepared(<<<'SQL'
            ALTER TABLE landing_pages ENABLE ROW LEVEL SECURITY;
            ALTER TABLE landing_pages FORCE  ROW LEVEL SECURITY;

            CREATE POLICY tenant_isolation_landing_pages ON landing_pages
                FOR ALL TO qrpager_app, qrpager_worker, qrpager_owner
                USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
                WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);

            CREATE POLICY owner_bypass_landing_pages ON landing_pages
                FOR ALL TO qrpager_owner
                USING (NULLIF(current_setting('app.bypass_tenant', true), '') = 'on')
                WITH CHECK (NULLIF(current_setting('app.bypass_tenant', true), '') = 'on');

            ALTER TABLE media_assets ENABLE ROW LEVEL SECURITY;
            ALTER TABLE media_assets FORCE  ROW LEVEL SECURITY;

            CREATE POLICY tenant_isolation_media_assets ON media_assets
                FOR ALL TO qrpager_app, qrpager_worker, qrpager_owner
                USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
                WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);

            CREATE POLICY owner_bypass_media_assets ON media_assets
                FOR ALL TO qrpager_owner
                USING (NULLIF(current_setting('app.bypass_tenant', true), '') = 'on')
                WITH CHECK (NULLIF(current_setting('app.bypass_tenant', true), '') = 'on');

            ALTER TABLE tenant_domains ENABLE ROW LEVEL SECURITY;
            ALTER TABLE tenant_domains FORCE  ROW LEVEL SECURITY;

            CREATE POLICY tenant_isolation_tenant_domains ON tenant_domains
                FOR ALL TO qrpager_app, qrpager_worker, qrpager_owner
                USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
                WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);

            CREATE POLICY owner_bypass_tenant_domains ON tenant_domains
                FOR ALL TO qrpager_owner
                USING (NULLIF(current_setting('app.bypass_tenant', true), '') = 'on')
                WITH CHECK (NULLIF(current_setting('app.bypass_tenant', true), '') = 'on');
        SQL);

        // Column grants (§2.7). Deny-by-default: nothing is granted that is not named here,
        // and qrpager_worker is named nowhere in this block -- SR-3.
        DB::unprepared(<<<'SQL'
            -- landing_pages -------------------------------------------------------
            -- NO DELETE FOR ANYONE. A tenant has exactly one page row, so deleting it is
            -- not an editing operation; emptying the page is an UPDATE to 'draft' with an
            -- empty document, which this grant allows.
            GRANT SELECT ON landing_pages TO qrpager_app;
            GRANT INSERT (id, tenant_id, status, draft_document, draft_updated_at,
                          created_at, updated_at) ON landing_pages TO qrpager_app;
            GRANT UPDATE (status, draft_document, draft_updated_at, published_document,
                          published_checksum, published_hostname, published_at,
                          updated_at) ON landing_pages TO qrpager_app;

            -- media_assets --------------------------------------------------------
            -- NO UPDATE AT ALL, FOR ANYONE. Every column describes bytes already in the
            -- object store -- the key, the detected type, the size, the checksum, the
            -- dimensions -- so an update would leave a row that no longer describes its
            -- object. Replacing an image is a new row and a new key, which is also what
            -- keeps the old key valid for a page already published against it.
            GRANT SELECT ON media_assets TO qrpager_app;
            GRANT INSERT (id, tenant_id, object_key, original_name, content_type,
                          byte_size, checksum, width, height, created_at, updated_at)
                ON media_assets TO qrpager_app;
            GRANT DELETE ON media_assets TO qrpager_app;

            -- tenant_domains ------------------------------------------------------
            -- No INSERT grant on the lifecycle columns: a domain is always created at
            -- 'pending_dns' with nothing earned, and every later column is written by an
            -- UPDATE that the transition table and the CHECKs both bound.
            GRANT SELECT ON tenant_domains TO qrpager_app;
            GRANT INSERT (id, tenant_id, hostname, status, verification_token,
                          created_at, updated_at) ON tenant_domains TO qrpager_app;
            GRANT UPDATE (status, dns_verified_at, certificate_identifier,
                          certificate_issued_at, activated_at, redirects_to_domain_id,
                          last_error, checked_at, updated_at) ON tenant_domains TO qrpager_app;
            GRANT DELETE ON tenant_domains TO qrpager_app;
        SQL);

        // ================================================================================
        // ONE REVOKE, AND THE ONE THAT WAS TRIED AND TAKEN BACK OUT
        // ================================================================================
        //
        // qrpager_owner OWNS these tables, so Postgres grants it every privilege
        // implicitly and `GRANT`ing nothing to it achieves nothing. "No role gets DELETE
        // on landing_pages" is therefore false of the owner until it is revoked, and a
        // rule that is false of one of the three roles is the dead-control shape §4a
        // records four of.
        //
        // MEASURED 2026-10-28 before relying on it, because an implicit privilege sounds
        // like one a revoke cannot touch: has_table_privilege('qrpager_owner',
        // 'landing_pages', 'DELETE') answered `t`, the REVOKE ran, and it answered `f`.
        // TRUNCATE is a separate privilege and still answers `t`, which is what keeps
        // TestCase's per-test cleanup working.
        //
        // ⚠ `REVOKE UPDATE ON media_assets FROM qrpager_owner` WAS HERE AND IS GONE,
        // because it broke referential integrity and the suite caught it. Deleting a
        // `tenants` row makes Postgres take a row lock on every referencing table --
        // `SELECT 1 FROM ONLY media_assets x WHERE $1 = tenant_id FOR KEY SHARE OF x` --
        // and **`FOR KEY SHARE` requires the UPDATE privilege**, not just SELECT. So with
        // UPDATE revoked, `delete from tenants` answered `42501: permission denied for
        // table media_assets` from the OWNER connection, and AuditLogImmutabilityTest's
        // "it survives a tenant being deleted" went red.
        //
        // Measured directly afterwards rather than inferred from the test: as owner, with
        // UPDATE revoked, `SELECT 1 FROM ONLY media_assets … FOR KEY SHARE` answered
        // `permission denied`; the GRANT was restored and the identical statement
        // answered `(0 rows)`.
        //
        // So the rule NARROWS rather than being worked around: no role that serves
        // traffic holds UPDATE on media_assets -- qrpager_app and qrpager_worker are
        // granted none and cannot run DDL to give themselves any -- and the owner keeps
        // it because referential integrity needs it. GrantMatrixTest states it in exactly
        // those terms, so the claim and the schema cannot drift.
        //
        // THE LIMIT OF THE REMAINING REVOKE, stated rather than left to be found: the
        // owner can re-grant to itself, because it owns the table. It bounds ACCIDENT --
        // an owner-connection code path that issues a DELETE gets 42501 instead of
        // silently succeeding -- and it is no defence against a compromised owner, which
        // would simply run DDL.
        DB::unprepared(<<<'SQL'
            REVOKE DELETE ON landing_pages FROM qrpager_owner;
        SQL);
    }

    public function down(): void
    {
        foreach (self::TENANT_TABLES as $table) {
            // Identifiers from a private const in this file, never from input.
            DB::statement("DROP POLICY IF EXISTS owner_bypass_{$table} ON {$table}");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table} ON {$table}");
        }

        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS tenant_domains;
            DROP TABLE IF EXISTS media_assets;
            DROP TABLE IF EXISTS landing_pages;

            DROP TYPE IF EXISTS domain_status;
            DROP TYPE IF EXISTS landing_page_status;
        SQL);
    }
};
