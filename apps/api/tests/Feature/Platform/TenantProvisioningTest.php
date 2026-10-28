<?php

declare(strict_types=1);

use App\Domain\Database\TenantTables;
use App\Domain\Provisioning\ProvisioningStatus;
use App\Domain\Tenancy\TenantRole;
use App\Domain\Tenancy\TenantStatus;
use App\Features\Provisioning\DuplicateSlug;
use App\Features\Provisioning\PlatformAudit;
use App\Features\Provisioning\ProvisioningRecordMissing;
use App\Features\Provisioning\TenantProvisioning;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * S1 — tenant provisioning end to end, and the failure paths the ticket's §6 Q6 names.
 *
 * THE GATE CLAUSE THIS FILE IS ABOUT: "the wizard is transactional -- a failure halfway
 * leaves no partial tenant." The ticket says the answer to "what is in the database after a
 * failure at step 4 of 5" must be "nothing", and the honest answer is "nothing of the
 * tenant, and one platform row that says the attempt failed" -- an attempt that leaves no
 * trace at all is one nobody can see failed. Both halves are asserted here.
 */

function requestTenant(string $slug, ?string $email = null, ?string $byUser = null): string
{
    return app(TenantProvisioning::class)->request(
        name: 'Restaurant '.$slug,
        slug: $slug,
        ownerEmail: $email ?? ($slug.'-owner@example.test'),
        requestedByUserId: $byUser,
        actorIp: '203.0.113.10',
    );
}

function uniqueSlug(string $prefix = 'r'): string
{
    return $prefix.'-'.Str::lower(Str::random(12));
}

it('requests a tenant touching platform tables only, and nothing else', function (): void {
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $id = requestTenant(uniqueSlug());

    // THE NAMED INVARIANT, at the service level. The panel's own route surface is driven in
    // PlatformPanelInvariantTest; this is the same property one layer down, so a refactor
    // that moved the write out of the panel and into a helper could not escape it.
    $touched = array_values(array_unique(array_filter(array_map(
        static fn (string $sql): ?string => TenantTables::referencedBy($sql),
        $statements,
    ))));

    expect($touched)->toBe([], 'requesting a tenant reached a tenant table: '.implode(' | ', $touched));

    $row = DB::selectOne('select * from tenant_provisionings where id = ?', [$id]);

    expect($row->status)->toBe(ProvisioningStatus::Pending->value)
        ->and($row->attempts)->toBe(0)
        ->and($row->completed_at)->toBeNull()
        ->and($row->failure_reason)->toBeNull();
});

it('writes the request and its platform audit row in ONE transaction', function (): void {
    $slug = uniqueSlug();
    $id = requestTenant($slug);

    $audit = DB::select(
        'select action, subject_type, subject_id, context, ip_address
           from platform_audit_log where subject_id = ? order by id',
        [$id],
    );

    expect($audit)->toHaveCount(1)
        ->and($audit[0]->action)->toBe(PlatformAudit::TENANT_REQUESTED)
        ->and($audit[0]->subject_type)->toBe('tenant_provisioning')
        ->and($audit[0]->ip_address)->toBe('203.0.113.10');

    // The owner's address is deliberately NOT in the audit context: the trail is read by
    // every platform admin and the provisioning row already carries it once.
    //
    // toEqualCanonicalizing, NOT toBe: `context` is jsonb, which normalises object key
    // order (by key length, then bytewise), so the decoded array comes back as
    // name-then-slug however it was written. Asserting an order here would be asserting a
    // property of Postgres' jsonb representation rather than of this code -- and the thing
    // that actually matters is the SET of keys, because the finding would be a key that
    // should not be there.
    $context = json_decode((string) $audit[0]->context, true);

    expect($context)->toEqualCanonicalizing(['slug' => $slug, 'name' => 'Restaurant '.$slug])
        ->and(array_keys($context))->not->toContain('owner_email');
});

it('refuses a duplicate slug with a typed failure and writes no second row', function (): void {
    $slug = uniqueSlug();
    requestTenant($slug);

    expect(fn () => requestTenant($slug))->toThrow(DuplicateSlug::class);

    expect(DB::selectOne('select count(*) as c from tenant_provisionings where slug = ?', [$slug])->c)->toBe(1);

    // And the rolled-back attempt left no audit row either, which is the transaction
    // holding rather than the insert order being lucky.
    expect(DB::selectOne(
        'select count(*) as c from platform_audit_log where context->>? = ?',
        ['slug', $slug],
    )->c)->toBe(1);
});

it('never puts the driver message in the duplicate-slug failure', function (): void {
    $slug = uniqueSlug();
    requestTenant($slug);

    try {
        requestTenant($slug);
        $this->fail('a duplicate slug did not throw');
    } catch (DuplicateSlug $e) {
        // §4 › Edge & ops: no SQL and no bound parameter in an error body at any APP_DEBUG.
        // This message reaches a panel field error, so it is the response body.
        expect($e->getMessage())->not->toContain('insert into')
            ->and($e->getMessage())->not->toContain('tenant_provisionings')
            ->and($e->getMessage())->not->toContain('23505')
            ->and($e->getMessage())->not->toContain($slug)
            ->and($e->getMessage())->toBe('That address is already taken by another restaurant.');
    }
});

it('lower-cases the owner email before it is stored, so the job can match users.email', function (): void {
    $slug = uniqueSlug();
    $id = app(TenantProvisioning::class)->request(
        name: 'Mixed Case',
        slug: $slug,
        ownerEmail: 'Owner.Name@EXAMPLE.test',
        requestedByUserId: null,
    );

    expect(DB::selectOne('select owner_email from tenant_provisionings where id = ?', [$id])->owner_email)
        ->toBe('owner.name@example.test');
});

it('provisions a tenant end to end: registry row, tenant, first owner and a tenant audit row', function (): void {
    $slug = uniqueSlug();
    $id = requestTenant($slug);

    expect(app(TenantProvisioning::class)->provision($id))->toBeTrue();

    $registry = DB::selectOne('select status, attempts, completed_at from tenant_provisionings where id = ?', [$id]);

    expect($registry->status)->toBe(ProvisioningStatus::Completed->value)
        ->and($registry->attempts)->toBe(1)
        ->and($registry->completed_at)->not->toBeNull();

    // The tenant row, read inside its own scope -- which is the only way it IS readable.
    $tenant = withTenant($id, fn () => DB::selectOne('select id, name, slug, status, timezone from tenants'));

    expect($tenant->id)->toBe($id)
        ->and($tenant->slug)->toBe($slug)
        ->and($tenant->status)->toBe(TenantStatus::Active->value)
        ->and($tenant->timezone)->toBe('UTC');

    // The first owner, and exactly one membership.
    $members = withTenant($id, fn () => DB::select(
        'select tu.role, u.email from tenant_users tu join users u on u.id = tu.user_id',
    ));

    expect($members)->toHaveCount(1)
        ->and($members[0]->role)->toBe(TenantRole::Owner->value)
        ->and($members[0]->email)->toBe($slug.'-owner@example.test');

    // The tenant's own first audit entry, which is a different row from the platform one.
    $tenantAudit = withTenant($id, fn () => DB::select(
        "select action, actor_type from audit_log where action = 'tenant.provisioned'",
    ));

    expect($tenantAudit)->toHaveCount(1)
        ->and($tenantAudit[0]->actor_type)->toBe('platform');

    // And the platform trail has both entries.
    expect(array_map(
        static fn (object $r): string => (string) $r->action,
        DB::select('select action from platform_audit_log where subject_id = ? order by id', [$id]),
    ))->toBe([PlatformAudit::TENANT_REQUESTED, PlatformAudit::TENANT_PROVISIONED]);
});

it('attaches an existing user rather than creating a second account', function (): void {
    $email = 'returning-'.Str::lower(Str::random(8)).'@example.test';
    $userId = (string) Str::uuid7();

    DB::insert(
        'insert into users (id, name, email, created_at, updated_at) values (?, ?, ?, now(), now())',
        [$userId, 'Returning Owner', $email],
    );

    $id = requestTenant(uniqueSlug(), $email);
    app(TenantProvisioning::class)->provision($id);

    expect(DB::selectOne('select count(*) as c from users where email = ?', [$email])->c)->toBe(1);

    $members = withTenant($id, fn () => DB::select('select user_id from tenant_users'));

    expect($members)->toHaveCount(1)->and($members[0]->user_id)->toBe($userId);
});

it('gives a provisioned owner NO password, because nothing may invent a credential', function (): void {
    $id = requestTenant(uniqueSlug());
    app(TenantProvisioning::class)->provision($id);

    // This project has no self-service registration at any tier (§2.4), and a password this
    // code invented would be a credential nobody asked for and nobody can rotate. Read as
    // the OWNER, because R-006 revoked the app role's SELECT on users.password.
    $row = DB::connection('pgsql_owner')->selectOne(
        'select password, two_factor_secret from users where email = ?',
        [DB::selectOne('select owner_email from tenant_provisionings where id = ?', [$id])->owner_email],
    );

    expect($row->password)->toBeNull()->and($row->two_factor_secret)->toBeNull();
});

it('leaves NO partial tenant when the membership insert fails halfway', function (): void {
    // THE GATE CLAUSE, with the failure INJECTED rather than imagined: "the wizard is
    // transactional -- a failure halfway leaves no partial tenant", and the ticket's §6 Q6
    // asks it as "the wizard fails at step 4 of 5. What is in the database afterwards?"
    //
    // A TEMPORARY TRIGGER ON tenant_users IS THE INJECTION, and the two alternatives were
    // rejected for measured reasons:
    //
    //  (a) Deleting the owner's `users` row from a second connection inside the listener.
    //      MEASURED TO DEADLOCK: the app connection's open transaction holds the row, so
    //      the owner connection's DELETE blocks until a lock timeout -- the first version
    //      of this case hung for over two minutes and never reached an assertion. A test
    //      that waits on a lock is not injecting a failure, it is injecting a different one.
    //  (b) Revoking the app role's INSERT on tenant_users. That mutates a cluster-wide role
    //      attribute, which is the pre-existing debt §0 records three sites of (an
    //      interrupted run leaves local isolation altered until the next db:bootstrap), and
    //      adding a fourth site to test a transaction would be the wrong trade.
    //
    // The trigger is local to this one table, needs no second connection, raises on the
    // SECOND insert after the first has already succeeded inside the same transaction, and
    // is dropped in a finally so an interrupted run leaves nothing behind.
    $slug = uniqueSlug();
    $id = requestTenant($slug, 'halfway-'.Str::lower(Str::random(6)).'@example.test');

    DB::connection('pgsql_owner')->unprepared(<<<'SQL'
        CREATE FUNCTION provisioning_halfway_failure() RETURNS trigger
            LANGUAGE plpgsql AS $$
        BEGIN
            RAISE EXCEPTION 'injected failure at step 4 of 5' USING ERRCODE = '23503';
        END;
        $$;

        CREATE TRIGGER provisioning_halfway_failure
            BEFORE INSERT ON tenant_users
            FOR EACH STATEMENT EXECUTE FUNCTION provisioning_halfway_failure();
    SQL);

    try {
        // QueryException and NOT Throwable::class. Pest's toThrow() resolves a string
        // argument with class_exists(), which is FALSE for an interface -- so
        // toThrow(Throwable::class) is read as "the MESSAGE contains 'Throwable'", which
        // this exception's message does not, and the case failed for a reason that had
        // nothing to do with the code under test. Measured in this session. The same
        // family of silently-wrong expectation shapes is why ExpectationApiTest exists.
        expect(fn () => app(TenantProvisioning::class)->provision($id))->toThrow(QueryException::class);
    } finally {
        DB::connection('pgsql_owner')->unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS provisioning_halfway_failure ON tenant_users;
            DROP FUNCTION IF EXISTS provisioning_halfway_failure();
        SQL);
    }

    // THE ANSWER THE TICKET DEMANDS: nothing of the tenant. Not the tenants row that had
    // already been inserted, not the membership, not the tenant's own audit row.
    expect(withTenant($id, fn () => DB::selectOne('select count(*) as c from tenants')->c))->toBe(0)
        ->and(withTenant($id, fn () => DB::selectOne('select count(*) as c from tenant_users')->c))->toBe(0)
        ->and(withTenant($id, fn () => DB::selectOne('select count(*) as c from audit_log')->c))->toBe(0);

    // And the one platform row that says it failed, with a MAPPED reason. An attempt that
    // left no trace at all would be one nobody can see failed, which is why "nothing" is
    // the right answer for the tenant and the wrong answer for the platform.
    $registry = DB::selectOne('select status, attempts, failure_reason from tenant_provisionings where id = ?', [$id]);

    expect($registry->status)->toBe(ProvisioningStatus::Failed->value)
        ->and($registry->attempts)->toBe(1)
        // The reason is rendered in the panel, so it is a response body: no SQL, no bound
        // parameter, no constraint or trigger name, no SQLSTATE, and not the raw message.
        ->and($registry->failure_reason)->toBe('A referenced record is missing.')
        ->and($registry->failure_reason)->not->toContain('insert into')
        ->and($registry->failure_reason)->not->toContain('tenant_users')
        ->and($registry->failure_reason)->not->toContain('23503')
        ->and($registry->failure_reason)->not->toContain('injected failure');

    // The platform trail records the failure too, and carries the same mapped reason.
    $failed = DB::selectOne(
        'select context from platform_audit_log where subject_id = ? and action = ? order by id desc limit 1',
        [$id, PlatformAudit::TENANT_PROVISION_FAILED],
    );

    expect($failed)->not->toBeNull()
        ->and(json_decode((string) $failed->context, true))->toBe(['reason' => 'A referenced record is missing.']);
});

it('can be retried after a failure, and the retry succeeds and is bounded', function (): void {
    // `failed -> running` is on the transition table, and `attempts` is what stops a retry
    // loop being unbounded. Both are asserted here rather than left to the enum's docblock.
    $id = requestTenant(uniqueSlug());

    DB::connection('pgsql_owner')->unprepared(<<<'SQL'
        CREATE FUNCTION provisioning_halfway_failure() RETURNS trigger
            LANGUAGE plpgsql AS $$
        BEGIN
            RAISE EXCEPTION 'injected failure' USING ERRCODE = '23503';
        END;
        $$;

        CREATE TRIGGER provisioning_halfway_failure
            BEFORE INSERT ON tenant_users
            FOR EACH STATEMENT EXECUTE FUNCTION provisioning_halfway_failure();
    SQL);

    try {
        // QueryException and NOT Throwable::class. Pest's toThrow() resolves a string
        // argument with class_exists(), which is FALSE for an interface -- so
        // toThrow(Throwable::class) is read as "the MESSAGE contains 'Throwable'", which
        // this exception's message does not, and the case failed for a reason that had
        // nothing to do with the code under test. Measured in this session. The same
        // family of silently-wrong expectation shapes is why ExpectationApiTest exists.
        expect(fn () => app(TenantProvisioning::class)->provision($id))->toThrow(QueryException::class);
    } finally {
        DB::connection('pgsql_owner')->unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS provisioning_halfway_failure ON tenant_users;
            DROP FUNCTION IF EXISTS provisioning_halfway_failure();
        SQL);
    }

    expect(DB::selectOne('select status, attempts from tenant_provisionings where id = ?', [$id])->attempts)->toBe(1);

    // The retry, with the trigger gone.
    expect(app(TenantProvisioning::class)->provision($id))->toBeTrue();

    $registry = DB::selectOne('select status, attempts, failure_reason from tenant_provisionings where id = ?', [$id]);

    expect($registry->status)->toBe(ProvisioningStatus::Completed->value)
        ->and($registry->attempts)->toBe(2)
        // The stale reason is CLEARED on success. A completed row carrying the reason it
        // failed once is a row an operator reads as broken.
        ->and($registry->failure_reason)->toBeNull();

    expect(withTenant($id, fn () => DB::selectOne('select count(*) as c from tenants')->c))->toBe(1)
        ->and(withTenant($id, fn () => DB::selectOne('select count(*) as c from tenant_users')->c))->toBe(1);
});

it('is idempotent: a redelivered provision leaves one tenant and one completed row', function (): void {
    $id = requestTenant(uniqueSlug());

    expect(app(TenantProvisioning::class)->provision($id))->toBeTrue()
        // false, not an exception: this is the redelivery path, and a completed record is
        // not an error.
        ->and(app(TenantProvisioning::class)->provision($id))->toBeFalse();

    expect(withTenant($id, fn () => DB::selectOne('select count(*) as c from tenants')->c))->toBe(1)
        ->and(withTenant($id, fn () => DB::selectOne('select count(*) as c from tenant_users')->c))->toBe(1);

    // attempts stayed at 1: the second call returned before incrementing, so the counter
    // still means "how many times the tenant-side work was attempted".
    expect(DB::selectOne('select attempts from tenant_provisionings where id = ?', [$id])->attempts)->toBe(1);

    // And exactly one provisioned audit row, not two.
    expect(DB::selectOne(
        'select count(*) as c from platform_audit_log where subject_id = ? and action = ?',
        [$id, PlatformAudit::TENANT_PROVISIONED],
    )->c)->toBe(1);
});

it('throws a typed failure when the provisioning record is gone', function (): void {
    expect(fn () => app(TenantProvisioning::class)->provision((string) Str::uuid7()))
        ->toThrow(ProvisioningRecordMissing::class);
});

it('reaches exactly ONE tenant: the one it creates, and no other', function (): void {
    // THE "NOT A GENERAL BYPASS" HALF OF §6 Q3, driven rather than asserted in a comment.
    // A pre-existing tenant with a session in it; provisioning a DIFFERENT tenant must not
    // make that row visible at any point.
    $other = $this->makeTenant();
    $this->makeSession($other);

    $id = requestTenant(uniqueSlug());

    $seen = [];
    DB::listen(function ($query) use (&$seen): void {
        // Capture the tenant context in force for every statement the job issues.
        $seen[] = $query->sql;
    });

    app(TenantProvisioning::class)->provision($id);

    // Inside the new tenant's scope, the other tenant's session is invisible -- which is
    // RLS rather than this code checking.
    expect(withTenant($id, fn () => DB::selectOne('select count(*) as c from pager_sessions')->c))->toBe(0);

    // And the bypass GUC was never set: a real bypass is what would have made the count
    // above non-zero, so this is the mechanism and the previous line is its effect.
    expect(DB::selectOne("select nullif(current_setting('app.bypass_tenant', true), '') as b")->b)->toBeNull();
});

it('sets no session-scoped tenant context, so nothing leaks to the next request', function (): void {
    $id = requestTenant(uniqueSlug());
    app(TenantProvisioning::class)->provision($id);

    // C2: the third argument to set_config is always true (transaction-scoped). After the
    // job returns, the connection carries no tenant at all -- which is what makes this safe
    // on a pooled Octane worker.
    expect(DB::selectOne("select nullif(current_setting('app.tenant_id', true), '') as t")->t)->toBeNull();
});
