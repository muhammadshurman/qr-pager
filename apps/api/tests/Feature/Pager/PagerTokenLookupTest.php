<?php

declare(strict_types=1);

use App\Domain\Pager\PagerStatus;
use App\Domain\Pager\PagerToken;
use App\Features\Pager\PagerSessions;
use App\Features\Pager\PagerTokenLookup;
use App\Platform\Database\TenantScopeGuard;
use App\Platform\Database\TenantScopeMissingException;
use Illuminate\Support\Facades\DB;

/*
 * The QR token lookup: §4 › Tenancy's named cross-tenant read, built as §2.8 prescribes.
 *
 * §4's wording is the property under test: the token "resolves to exactly one session in
 * exactly one tenant -- built in Task 3, consumed by Task 4". There is no HTTP route in
 * front of it yet, deliberately: the polling and subscription endpoints are Task 4's.
 */

it('resolves a presented token to exactly one session in exactly one tenant, with no context', function (): void {
    $alpha = $this->makeTenant();
    $beta = $this->makeTenant();

    $issued = app(PagerSessions::class)->create($alpha, 'A-500');
    // A second tenant with its own session, so "exactly one" is a real claim rather than
    // a claim about the only row in the table.
    app(PagerSessions::class)->create($beta, 'B-500');

    // NO tenant scope anywhere in this call: the token is what decides the tenant.
    $resolved = app(PagerTokenLookup::class)->resolve($issued->token->reveal());

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($issued->session->id)
        ->and($resolved->tenantId)->toBe($alpha)
        ->and($resolved->tenantId)->not->toBe($beta)
        ->and($resolved->status)->toBe(PagerStatus::Waiting)
        ->and($resolved->orderReference)->toBe('A-500');
});

it('answers null for an unknown token, and for a malformed one', function (): void {
    $this->makeTenant();

    $lookup = app(PagerTokenLookup::class);

    expect($lookup->resolve(PagerToken::issue()->reveal()))->toBeNull()
        ->and($lookup->resolve('not-a-token'))->toBeNull()
        ->and($lookup->resolve(''))->toBeNull();
});

it('does not leak the bypass GUC back to the caller', function (): void {
    $tenant = $this->makeTenant();
    $issued = app(PagerSessions::class)->create($tenant, 'A-501');

    app(PagerTokenLookup::class)->resolve($issued->token->reveal());

    // §2.8: the function "turns on app.bypass_tenant for the duration of its own body,
    // restores the previous value before returning". set_config(..., true) binds to the
    // OUTERMOST transaction (§2.10), so without the restore the bypass would stay on for
    // the rest of the caller's transaction -- and TenantContext::run() refuses to open a
    // scope inside a live bypass, so a leak would break every withTenant() after it.
    $guc = DB::selectOne(
        "select coalesce(nullif(current_setting('app.bypass_tenant', true), ''), 'empty') as value",
    );

    expect((string) $guc->value)->toBe('empty');

    // And the proof that it matters: a scope still opens afterwards.
    expect(withTenant($tenant, fn (): int => 1))->toBe(1);
});

it('leaves the caller’s own queries tenant-bound, so the bypass is the function’s and not the connection’s', function (): void {
    $tenant = $this->makeTenant();
    $issued = app(PagerSessions::class)->create($tenant, 'A-502');

    app(PagerTokenLookup::class)->resolve($issued->token->reveal());

    // The caller read a row through the function and still reads NOTHING directly, which
    // is the whole difference between a SECURITY DEFINER function and a standing bypass
    // for qrpager_app.
    expect(DB::table('pager_sessions')->count())->toBe(0)
        ->and(DB::table('outbox')->count())->toBe(0);
});

it('cannot be used to read the token hash, because the function does not return it', function (): void {
    $tenant = $this->makeTenant();
    $issued = app(PagerSessions::class)->create($tenant, 'A-503');

    $row = DB::selectOne(
        'select * from resolve_pager_token(decode(?, \'hex\'))',
        [$issued->token->hashHex()],
    );

    // §2.8 rule 1, and the reason it is a rule: column grants are NOT applied to a
    // function's result set, so a `RETURNS SETOF pager_sessions` resolver would hand
    // token_hash straight back to qrpager_app and quietly undo §2.7. The narrow
    // RETURNS TABLE is what prevents it, and `select *` here is deliberate -- it asks the
    // function for everything it has.
    expect((array) $row)->not->toHaveKey('token_hash');

    $columns = array_keys((array) $row);
    sort($columns);

    expect($columns)->toBe([
        'expires_at', 'last_notified_at', 'order_reference', 'ready_at', 'session_id', 'status', 'tenant_id',
    ]);
});

it('is executable by the app role and by nothing else', function (): void {
    // The grant, read from the catalog rather than inferred from behaviour.
    $acl = $this->asRoot()->selectOne(
        "select coalesce(array_to_string(proacl, ','), '(default: PUBLIC)') as acl
           from pg_proc p join pg_namespace n on n.oid = p.pronamespace
          where n.nspname = 'public' and p.proname = 'resolve_pager_token'",
    );

    $text = (string) $acl->acl;

    // Measured ACL: `qrpager_owner=X/qrpager_owner,qrpager_app=X/qrpager_owner` -- the
    // owner's own entry plus the one grant. Pinned as an exact set rather than by
    // substring, because the first version of this assertion asserted the ABSENCE of
    // `=X/qrpager_owner,` and that substring is part of the owner's own legitimate entry:
    // it failed on a correct grant, which is a test wrong in the direction that wastes a
    // reviewer's time.
    $entries = explode(',', $text);
    sort($entries);

    expect($entries)->toBe([
        'qrpager_app=X/qrpager_owner',
        'qrpager_owner=X/qrpager_owner',
    ])
        // REVOKE FROM PUBLIC is the control and the grant is only what is left: Postgres
        // grants EXECUTE to PUBLIC by default on a new function, so without the revoke
        // every role in the cluster could call it. A PUBLIC entry has an EMPTY grantee,
        // so it would appear as an entry beginning with '='.
        ->and($text)->not->toMatch('/(^|,)=/')
        ->and($text)->not->toContain('qrpager_worker');

    // And the worker, which must not have it: it has its own named cross-tenant path and
    // the token lookup is not it.
    expect($this->sqlStateOf(fn () => $this->asWorker()->selectOne(
        'select * from resolve_pager_token(decode(?, \'hex\'))', [str_repeat('00', 32)],
    )))->toBe('42501');
});

it('is a SECURITY DEFINER function owned by the owner, with a pinned search_path', function (): void {
    $row = $this->asRoot()->selectOne(
        "select p.prosecdef, pg_get_userbyid(p.proowner) as owner,
                coalesce(array_to_string(p.proconfig, ','), '') as config
           from pg_proc p join pg_namespace n on n.oid = p.pronamespace
          where n.nspname = 'public' and p.proname = 'resolve_pager_token'",
    );

    expect($row->prosecdef)->toBeTrue()
        ->and((string) $row->owner)->toBe('qrpager_owner')
        // SET search_path, with pg_temp last and every reference schema-qualified, which
        // is the form the Postgres manual prescribes for a SECURITY DEFINER function.
        ->and((string) $row->config)->toContain('search_path=')
        ->and((string) $row->config)->toContain('pg_catalog');
});

it('resolves an expired session rather than hiding it, so the PWA can tell expired from invalid', function (): void {
    $tenant = $this->makeTenant();
    $issued = app(PagerSessions::class)->create($tenant, 'A-504');

    // Push it past its TTL, the way the clock would.
    withTenant($tenant, fn () => DB::connection('pgsql_owner')->update(
        'update pager_sessions set expires_at = now() - interval \'1 minute\' where id = ?',
        [$issued->session->id],
    ), 'pgsql_owner');

    $resolved = app(PagerTokenLookup::class)->resolve($issued->token->reveal());

    // Deliberately NOT filtered by TTL or status in SQL. §4.1 and Task 9's gate both
    // require the PWA to handle "expired-token and invalid-token paths" as DIFFERENT
    // paths, so the product has to be able to tell them apart -- and the holder of a
    // token is the legitimate customer, so telling them their session expired is not a
    // disclosure. Filtering would make both cases answer null.
    expect($resolved)->not->toBeNull()
        ->and($resolved->hasExpired(new DateTimeImmutable))->toBeTrue()
        ->and($resolved->status)->toBe(PagerStatus::Waiting);
});

it('is permitted by the scope guard, while a bare pager_sessions statement is still refused', function (): void {
    $tenant = $this->makeTenant();
    $issued = app(PagerSessions::class)->create($tenant, 'A-505');
    $hash = $issued->token->hashHex();

    // ARMED, which is what a real request does -- the guard is armed by
    // ArmTenantScopeGuard on the global middleware stack and is deliberately not armed
    // for console work.
    //
    // The guard permits the function call because the statement names no tenant table,
    // and that is CORRECT: this is one of §4's named context-free paths. But it is
    // INCIDENTAL -- it falls out of a substring match -- so it is pinned here rather than
    // left to be discovered by Task 4, which is the task that will call this inside a
    // request for the first time.
    app(TenantScopeGuard::class)->armedFor(function () use ($hash, $tenant): void {
        // A WELL-FORMED BUT UNKNOWN TOKEN, and the distinction is the point of this
        // correction (QA L-6). `str_repeat('A', 22)` is 22 base64url characters, so
        // PagerToken::fromPresented() accepts it, it IS hashed, and the call DOES make a
        // real resolve_pager_token round trip -- which is exactly what this case needs,
        // because the guard can only be shown to permit a statement that is actually
        // issued. The comment here used to call it "the malformed probe", which would have
        // meant no statement ran at all and the case proved nothing.
        $resolved = app(PagerTokenLookup::class)->resolve(str_repeat('A', 22));

        // It resolves nothing because no session has that hash; the real assertion is the
        // two statements below.
        expect($resolved)->toBeNull();

        // 1. The named cross-tenant path is permitted with no scope open.
        $row = DB::selectOne('select session_id from resolve_pager_token(decode(?, \'hex\'))', [$hash]);
        expect($row)->not->toBeNull();

        // 2. A bare tenant-table statement on the same connection, in the same armed
        // request, is still refused BEFORE it reaches Postgres. So the permission above is
        // specific to the function and not the guard being asleep.
        expect(fn () => DB::table('pager_sessions')->count())
            ->toThrow(TenantScopeMissingException::class);

        // 3. And inside a scope the same statement is fine, so the guard is not simply
        // refusing everything.
        expect(withTenant($tenant, fn (): int => DB::table('pager_sessions')->count()))->toBe(1);
    });
});
