<?php

declare(strict_types=1);

use App\Domain\Database\TenantTables;
use App\Domain\Provisioning\ProvisioningStatus;
use App\Features\Provisioning\PlatformAudit;
use App\Features\Provisioning\TenantProvisioning;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The drain: `platform:provision-tenants`, the out-of-band half of S1.
 *
 * WHY THIS IS A COMMAND AND NOT A QUEUED JOB is argued in the command's own docblock and
 * rests on two measured facts: there is no queued job anywhere in this application, and
 * §2.4 records that `jobs` and `job_batches` were dropped from the skeleton at Task 1 -- so
 * a database queue has no table to write to. The outbox was the other candidate and cannot
 * work here: `outbox.tenant_id` is `NOT NULL REFERENCES tenants (id)`, and the whole point
 * is that the tenant does not exist yet.
 */

function requestFor(string $slug): string
{
    return app(TenantProvisioning::class)->request(
        name: 'Drain '.$slug,
        slug: $slug,
        ownerEmail: $slug.'@example.test',
        requestedByUserId: null,
    );
}

it('drains a pending request end to end, so the panel’s write really becomes a tenant', function (): void {
    $id = requestFor('drain-'.Str::lower(Str::random(8)));

    $this->artisan('platform:provision-tenants')->assertSuccessful();

    expect(DB::selectOne('select status from tenant_provisionings where id = ?', [$id])->status)
        ->toBe(ProvisioningStatus::Completed->value)
        ->and(withTenant($id, fn () => DB::selectOne('select count(*) as c from tenants')->c))->toBe(1)
        ->and(withTenant($id, fn () => DB::selectOne('select count(*) as c from tenant_users')->c))->toBe(1);
});

it('touches no tenant table while deciding WHAT to drain', function (): void {
    // The enumeration is over a platform table, which is what makes the drain query need no
    // tenant context. The tenant tables it then touches are touched INSIDE a scope, one
    // tenant at a time -- TenantProvisioningTest drives that half.
    requestFor('enum-'.Str::lower(Str::random(8)));

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $this->artisan('platform:provision-tenants')->assertSuccessful();

    // The FIRST statement is the drain query, and it is the one that must not name a tenant
    // table: it runs before any scope is opened.
    expect($statements)->not->toBe([])
        ->and(TenantTables::referencedBy($statements[0]))->toBeNull()
        ->and($statements[0])->toContain('tenant_provisionings');
});

it('leaves a `running` row alone, so a stalled attempt is not a hot loop', function (): void {
    // `running` means "in flight on some host", and this pass cannot tell that from
    // "stalled". Recovering those is a deliberate act with its own bound (`attempts`), not
    // this loop's job -- which is what stops running -> pending -> running spinning.
    $id = requestFor('stalled-'.Str::lower(Str::random(8)));

    DB::update(
        'update tenant_provisionings set status = ?::provisioning_status, updated_at = now() where id = ?',
        [ProvisioningStatus::Running->value, $id],
    );

    $this->artisan('platform:provision-tenants')->assertSuccessful();

    expect(DB::selectOne('select status, attempts from tenant_provisionings where id = ?', [$id])->status)
        ->toBe(ProvisioningStatus::Running->value)
        ->and(withTenant($id, fn () => DB::selectOne('select count(*) as c from tenants')->c))->toBe(0);
});

it('keeps draining after one request fails, and records the failure on its own row', function (): void {
    // A drain, not a batch: one restaurant that cannot be created must not stop the next.
    $bad = requestFor('bad-'.Str::lower(Str::random(8)));
    $good = requestFor('good-'.Str::lower(Str::random(8)));

    // The failure is injected on the FIRST row only, by a trigger that fires for the
    // membership insert of the tenant whose id is $bad. A statement-level trigger cannot
    // see which row it is for, so the injection is a condition on the tenant id read back
    // from the provisioning table inside the trigger body.
    DB::connection('pgsql_owner')->unprepared(<<<SQL
        CREATE FUNCTION drain_selective_failure() RETURNS trigger
            LANGUAGE plpgsql AS \$\$
        BEGIN
            IF current_setting('app.tenant_id', true) = '{$bad}' THEN
                RAISE EXCEPTION 'injected failure for the first row' USING ERRCODE = '23503';
            END IF;
            RETURN NULL;
        END;
        \$\$;

        CREATE TRIGGER drain_selective_failure
            BEFORE INSERT ON tenant_users
            FOR EACH STATEMENT EXECUTE FUNCTION drain_selective_failure();
    SQL);

    try {
        $this->artisan('platform:provision-tenants')->assertSuccessful();
    } finally {
        DB::connection('pgsql_owner')->unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS drain_selective_failure ON tenant_users;
            DROP FUNCTION IF EXISTS drain_selective_failure();
        SQL);
    }

    expect(DB::selectOne('select status from tenant_provisionings where id = ?', [$bad])->status)
        ->toBe(ProvisioningStatus::Failed->value)
        // The second one still got created, which is the property this case exists for.
        ->and(DB::selectOne('select status from tenant_provisionings where id = ?', [$good])->status)
        ->toBe(ProvisioningStatus::Completed->value)
        ->and(withTenant($bad, fn () => DB::selectOne('select count(*) as c from tenants')->c))->toBe(0)
        ->and(withTenant($good, fn () => DB::selectOne('select count(*) as c from tenants')->c))->toBe(1);

    // And the exit status was SUCCESS even though one row failed: a failure is recorded on
    // its row and the pass did its job. A non-zero exit would make the scheduler treat an
    // ordinary duplicate slug as an infrastructure fault.
    expect(DB::selectOne(
        'select count(*) as c from platform_audit_log where subject_id = ? and action = ?',
        [$bad, PlatformAudit::TENANT_PROVISION_FAILED],
    )->c)->toBe(1);
});

it('bounds the drain size, and clamps a caller’s nonsense rather than interpolating it', function (): void {
    // `limit` takes no bind parameter in this builder path, so the option is cast and
    // clamped. The same shape as the expiry job's pager.expiry_batch, for the same reason:
    // an option is caller input.
    foreach (['--limit' => '1'] as $option => $value) {
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = requestFor('limit-'.Str::lower(Str::random(8)));
        }

        $this->artisan('platform:provision-tenants', [$option => $value])->assertSuccessful();

        $completed = DB::selectOne(
            'select count(*) as c from tenant_provisionings where status = ?::provisioning_status',
            [ProvisioningStatus::Completed->value],
        )->c;

        expect($completed)->toBe(1, 'the --limit option did not bound the drain');
    }

    // And a hostile value is clamped, not interpolated: these would be a syntax error or an
    // injection if they reached the SQL.
    foreach (['0', '-5', 'abc', '1; drop table tenants', '99999'] as $hostile) {
        $this->artisan('platform:provision-tenants', ['--limit' => $hostile])->assertSuccessful();
    }

    // The tenants table is still there, which is the injection half of the line above.
    expect(DB::connection('pgsql_owner')->selectOne(
        "select count(*) as c from pg_class where relname = 'tenants'"
    )->c)->toBe(1);
});

it('reports what it did, so an operator reading the log can tell a quiet pass from a stuck one', function (): void {
    requestFor('report-'.Str::lower(Str::random(8)));

    $this->artisan('platform:provision-tenants')
        ->expectsOutputToContain('1 created, 0 failed, of 1 pending.')
        ->assertSuccessful();

    // And an idle pass says so rather than printing nothing.
    $this->artisan('platform:provision-tenants')
        ->expectsOutputToContain('0 created, 0 failed, of 0 pending.')
        ->assertSuccessful();
});

it('is registered and SCHEDULED, which is R-058 applied rather than repeated', function (): void {
    // "A command registered and scheduled nowhere does not enforce anything" was the ruling
    // on the expiry job, which shipped registered and unscheduled and made §2.11 false as
    // deployed. Here the same mistake would make the wizard silently never finish: the
    // panel writes a `pending` row, nothing drains it, and the screen reports success.
    $commands = array_keys(app(Kernel::class)->all());

    expect($commands)->toContain('platform:provision-tenants')
        ->and($commands)->toContain('platform:aggregate-stats');

    $scheduled = array_map(
        static fn (Event $event): string => (string) $event->command,
        app(Schedule::class)->events(),
    );

    $provision = array_values(array_filter(
        $scheduled,
        static fn (string $c): bool => str_contains($c, 'platform:provision-tenants'),
    ));
    $aggregate = array_values(array_filter(
        $scheduled,
        static fn (string $c): bool => str_contains($c, 'platform:aggregate-stats'),
    ));

    expect($provision)->toHaveCount(1)
        ->and($aggregate)->toHaveCount(1);
});
