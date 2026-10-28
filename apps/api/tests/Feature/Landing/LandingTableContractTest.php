<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * ================================================================================
 * TASK 7'S SCHEMA, PROVEN BY MAKING EVERY CONSTRAINT REFUSE
 * ================================================================================
 *
 * PLAN.md §2.5 states five load-bearing choices in these three tables. A statement in a
 * document is not a control, and a CHECK constraint that has never been seen to fail is
 * indistinguishable from one that was mistyped -- §4a records a round where three gate
 * invariants were silenced by a green test. So every constraint here is driven to its
 * refusal and the SQLSTATE is read.
 *
 * ⚠ MOST OF THESE RUN AS THE OWNER WITH THE BYPASS GUC ON, AND THAT IS THE POINT.
 * A policy is evaluated per role and owner_bypass_* skips it; a CHECK binds every writer.
 * So "a row cannot name another tenant's prefix" is tested from the one position that
 * could get round a policy -- which is a strictly stronger statement than isolation, and
 * it is the half of the named invariant that is not about RLS at all.
 */

/** The SQLSTATE a closure's QueryException carries, or null if it did not throw. */
function landingSqlState(TestCase $case, callable $callback): ?string
{
    return $case->sqlStateOf($callback);
}

it('refuses a media key that names another tenants prefix, even as owner with the bypass on', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();

    // The key says beta; the row says alpha. RLS cannot see the contradiction -- the row's
    // own tenant_id is alpha, so tenant_isolation_media_assets is satisfied. Only the CHECK
    // catches it.
    $state = $this->sqlStateOf(fn () => withoutTenantScope(
        'test: cross-tenant object key, from the one position that bypasses policies',
        fn () => DB::connection('pgsql_owner')->table('media_assets')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $alpha,
            'object_key' => 'media/'.$beta.'/'.bin2hex(random_bytes(16)).'.png',
            'original_name' => 'stolen.png',
            'content_type' => 'image/png',
            'byte_size' => 10,
            'checksum' => hash('sha256', 'x'),
        ]),
        'pgsql_owner',
    ));

    expect($state)->toBe('23514');
});

it('refuses a media key whose leaf is guessable', function (): void {
    $tenant = $this->makeTenant();

    // Every one of these satisfies media_assets_key_is_tenant_scoped -- the prefix is this
    // tenant's own -- and every one fails the unguessable CHECK. The cases are the shapes a
    // careless implementation actually produces: the original filename, a sequence, a short
    // random, an uppercase hex, and a path traversal back out of the prefix.
    $keys = [
        'logo.png',
        '1.png',
        bin2hex(random_bytes(8)).'.png',
        strtoupper(bin2hex(random_bytes(16))).'.png',
        '../'.bin2hex(random_bytes(16)).'.png',
        // .html is the case that found a real gap in the first draft of this constraint:
        // `html` is four lowercase letters, so a loose `[a-z0-9]{2,5}` extension admitted
        // it -- and the extension is what a CDN and S3 infer a served Content-Type from.
        bin2hex(random_bytes(16)).'.html',
        bin2hex(random_bytes(16)).'.jpeg',
        bin2hex(random_bytes(16)),
    ];

    foreach ($keys as $leaf) {
        $state = $this->sqlStateOf(fn () => withTenant($tenant, fn () => DB::connection('pgsql_owner')
            ->table('media_assets')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenant,
                'object_key' => 'media/'.$tenant.'/'.$leaf,
                'original_name' => 'x.png',
                'content_type' => 'image/png',
                'byte_size' => 10,
                'checksum' => hash('sha256', 'x'),
            ]), 'pgsql_owner'));

        expect($state)->toBe('23514', "media/{tenant}/{$leaf} was accepted as an unguessable key");
    }
});

it('accepts the one key shape the application produces, so the CHECK is not merely always-false', function (): void {
    // The positive control. Without it, a CHECK of `false` would pass every case above --
    // which is the vacuous shape R-059 and R-054 both paid for.
    $tenant = $this->makeTenant();

    withTenant($tenant, fn () => DB::connection('pgsql_owner')->table('media_assets')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant,
        'object_key' => 'media/'.$tenant.'/'.bin2hex(random_bytes(16)).'.webp',
        'original_name' => 'ok.webp',
        'content_type' => 'image/webp',
        'byte_size' => 10,
        'checksum' => hash('sha256', 'x'),
    ]), 'pgsql_owner');

    expect(withTenant($tenant, fn () => DB::table('media_assets')->count()))->toBe(1);
});

it('refuses a key whose extension disagrees with the detected content type', function (): void {
    // Without this constraint the two controls are independent in the wrong direction: a
    // row records image/png while the object is served as an SVG by the one layer -- the
    // CDN -- that never reads the content_type column.
    $tenant = $this->makeTenant();

    expect($this->sqlStateOf(fn () => withTenant($tenant, fn () => DB::connection('pgsql_owner')
        ->table('media_assets')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant,
            'object_key' => 'media/'.$tenant.'/'.bin2hex(random_bytes(16)).'.svg',
            'original_name' => 'x.png',
            'content_type' => 'image/png',
            'byte_size' => 10,
            'checksum' => hash('sha256', 'x'),
        ]), 'pgsql_owner')))->toBe('23514');
});

it('refuses an unknown content type and an out-of-range size', function (): void {
    $tenant = $this->makeTenant();

    $row = function (array $overrides) use ($tenant): array {
        return array_merge([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant,
            'object_key' => 'media/'.$tenant.'/'.bin2hex(random_bytes(16)).'.png',
            'original_name' => 'x.png',
            'content_type' => 'image/png',
            'byte_size' => 10,
            'checksum' => hash('sha256', 'x'),
        ], $overrides);
    };

    // text/html is the case that matters: an HTML file renamed .png is §3 row 7's own
    // example, and the schema refuses it even if every layer above were to wave it through.
    foreach ([['content_type' => 'text/html'], ['content_type' => 'image/gif'],
        ['byte_size' => 0], ['byte_size' => 5242881], ['checksum' => 'not-a-sha']] as $bad) {
        expect($this->sqlStateOf(fn () => withTenant($tenant, fn () => DB::connection('pgsql_owner')
            ->table('media_assets')->insert($row($bad)), 'pgsql_owner')))
            ->toBe('23514', json_encode($bad).' was accepted');
    }
});

it('refuses a half-published page in either direction', function (): void {
    $tenant = $this->makeTenant();

    $insert = function (array $overrides) use ($tenant): ?string {
        return $this->sqlStateOf(fn () => withTenant($tenant, fn () => DB::connection('pgsql_owner')
            ->table('landing_pages')->insert(array_merge([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenant,
                'draft_document' => '{}',
            ], $overrides)), 'pgsql_owner'));
    };

    // published with nothing recorded -- the shape a publish that wrote the row first
    // would leave behind.
    expect($insert(['status' => 'published']))->toBe('23514');

    // published with three of the four -- the shape a publish that crashed between two
    // column assignments would leave behind.
    expect($insert([
        'status' => 'published',
        'published_document' => '{}',
        'published_checksum' => str_repeat('a', 64),
        'published_at' => now()->toIso8601String(),
    ]))->toBe('23514');

    // draft carrying published columns -- the shape an unpublish that forgot to clear
    // them would leave behind.
    expect($insert([
        'status' => 'draft',
        'published_checksum' => str_repeat('a', 64),
    ]))->toBe('23514');

    // And the one legal published row, so the constraint is not always-false.
    expect($insert([
        'status' => 'published',
        'published_document' => '{}',
        'published_checksum' => str_repeat('a', 64),
        'published_hostname' => 'example.test',
        'published_at' => now()->toIso8601String(),
    ]))->toBeNull();
});

it('allows one landing page per tenant and refuses the second', function (): void {
    $tenant = $this->makeTenant();

    $insert = fn () => withTenant($tenant, fn () => DB::connection('pgsql_owner')
        ->table('landing_pages')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant,
            'draft_document' => '{}',
        ]), 'pgsql_owner');

    $insert();

    expect($this->sqlStateOf($insert))->toBe('23505');
});

it('refuses a draft document over 256 KB', function (): void {
    $tenant = $this->makeTenant();

    $oversized = json_encode(['blocks' => [], 'pad' => str_repeat('a', 262200)], JSON_THROW_ON_ERROR);

    expect($this->sqlStateOf(fn () => withTenant($tenant, fn () => DB::connection('pgsql_owner')
        ->table('landing_pages')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant,
            'draft_document' => $oversized,
        ]), 'pgsql_owner')))->toBe('23514');
});

it('refuses every path to active that has not earned it', function (): void {
    $tenant = $this->makeTenant();

    $insert = function (array $overrides) use ($tenant): ?string {
        return $this->sqlStateOf(fn () => withTenant($tenant, fn () => DB::connection('pgsql_owner')
            ->table('tenant_domains')->insert(array_merge([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenant,
                'hostname' => strtolower(Str::random(12)).'.example.test',
                'verification_token' => bin2hex(random_bytes(16)),
            ], $overrides)), 'pgsql_owner'));
    };

    $now = now()->toIso8601String();

    // SR-2's falsifiable half, four ways. Each of these is a domain claiming to be live
    // without one of the two things that earn it.
    expect($insert(['status' => 'active']))->toBe('23514');
    expect($insert(['status' => 'active', 'dns_verified_at' => $now, 'activated_at' => $now]))->toBe('23514');
    expect($insert([
        'status' => 'active', 'certificate_identifier' => 'cert-1',
        'certificate_issued_at' => $now, 'activated_at' => $now,
    ]))->toBe('23514');
    expect($insert([
        'status' => 'active', 'dns_verified_at' => $now,
        'certificate_identifier' => 'cert-1', 'certificate_issued_at' => $now,
    ]))->toBe('23514');

    // dns_verified without the timestamp that proves it.
    expect($insert(['status' => 'dns_verified']))->toBe('23514');
    // pending_dns CARRYING a verification it has not done.
    expect($insert(['status' => 'pending_dns', 'dns_verified_at' => $now]))->toBe('23514');
    // half a certificate.
    expect($insert(['status' => 'dns_verified', 'dns_verified_at' => $now, 'certificate_identifier' => 'c']))->toBe('23514');
    // redirecting with nothing to redirect to.
    expect($insert(['status' => 'redirecting', 'dns_verified_at' => $now]))->toBe('23514');

    // The positive control: everything earned, so the constraint is not always-false.
    expect($insert([
        'status' => 'active', 'dns_verified_at' => $now,
        'certificate_identifier' => 'cert-1', 'certificate_issued_at' => $now,
        'activated_at' => $now,
    ]))->toBeNull();
});

it('keeps the redirect graph inside the tenant, by foreign key rather than by policy', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();
    $now = now()->toIso8601String();

    $betaDomain = (string) Str::uuid7();

    withTenant($beta, fn () => DB::connection('pgsql_owner')->table('tenant_domains')->insert([
        'id' => $betaDomain,
        'tenant_id' => $beta,
        'hostname' => 'beta-'.strtolower(Str::random(8)).'.example.test',
        'verification_token' => bin2hex(random_bytes(16)),
    ]), 'pgsql_owner');

    // From the bypass position again: the FK is checked against the real table, so the
    // refusal does not depend on beta's row being invisible to alpha.
    $state = $this->sqlStateOf(fn () => withoutTenantScope(
        'test: cross-tenant redirect target',
        fn () => DB::connection('pgsql_owner')->table('tenant_domains')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $alpha,
            'hostname' => 'alpha-'.strtolower(Str::random(8)).'.example.test',
            'verification_token' => bin2hex(random_bytes(16)),
            'status' => 'redirecting',
            'dns_verified_at' => $now,
            'redirects_to_domain_id' => $betaDomain,
        ]),
        'pgsql_owner',
    ));

    expect($state)->toBe('23503');
});

it('allows one active domain per tenant and refuses a second', function (): void {
    $tenant = $this->makeTenant();
    $now = now()->toIso8601String();

    $active = fn () => withTenant($tenant, fn () => DB::connection('pgsql_owner')
        ->table('tenant_domains')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant,
            'hostname' => strtolower(Str::random(12)).'.example.test',
            'verification_token' => bin2hex(random_bytes(16)),
            'status' => 'active',
            'dns_verified_at' => $now,
            'certificate_identifier' => 'cert-'.strtolower(Str::random(6)),
            'certificate_issued_at' => $now,
            'activated_at' => $now,
        ]), 'pgsql_owner');

    $active();

    // Two active hostnames for one tenant would be two CDN prefixes for one page.
    expect($this->sqlStateOf($active))->toBe('23505');
});

it('refuses a hostname that is not a hostname, and one another tenant already holds', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();

    $insert = function (string $tenant, string $hostname): ?string {
        return $this->sqlStateOf(fn () => withTenant($tenant, fn () => DB::connection('pgsql_owner')
            ->table('tenant_domains')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenant,
                'hostname' => $hostname,
                'verification_token' => bin2hex(random_bytes(16)),
            ]), 'pgsql_owner'));
    };

    foreach (['Example.test', 'https://example.test', 'example.test/path', 'example.test:443',
        '.example.test', 'example..test', 'example', '-bad.example.test'] as $bad) {
        expect($insert($alpha, $bad))->toBe('23514', "[{$bad}] was accepted as a hostname");
    }

    // R-129, measured rather than asserted: the unique index is checked against the real
    // table, so beta's claim on alpha's hostname is refused although beta cannot read the
    // row. That is the oracle the row documents, and this is what it looks like.
    expect($insert($alpha, 'shared.example.test'))->toBeNull();
    expect($insert($beta, 'shared.example.test'))->toBe('23505');
    expect(withTenant($beta, fn () => DB::table('tenant_domains')->count()))->toBe(0);
});
