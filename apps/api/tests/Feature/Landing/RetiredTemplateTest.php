<?php

declare(strict_types=1);

use App\Domain\Landing\LandingDocumentInvalid;
use App\Domain\Landing\LandingTemplate;
use App\Features\Landing\LandingPages;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LandingFixtures;

/**
 * ================================================================================
 * `QRPAGER-47` PART A — THE STATED INVARIANT, AND THE FIRST DATA MIGRATION BEHIND IT
 * ================================================================================
 *
 * Muhammad's bar, in his words:
 *
 *     "After the migration, no stored document references a retired case — proven by a test
 *      that fails if any does, across every tenant."
 *
 * Both halves are driven here. *Across every tenant* is a real sweep through the one
 * cross-tenant mechanism this project has — `withoutTenantScope()`, audited — and not a
 * lookup of the tenants the test happens to know about. *Fails if any does* is a case of its
 * own: a document naming a retired arrangement is planted AFTER the migration and the sweep
 * is required to report it, because a sweep that cannot fail proves nothing.
 *
 * ⚠ THE SUBJECT IS THE MIGRATION FILE ITSELF, re-run with `require`. It has already been
 * applied to this database by `TestCase`'s bootstrap, and `up()` is idempotent by
 * construction — the `WHERE` names the value it replaces — so running it again is both safe
 * and the only way to observe it against rows this test controls.
 */
function retireClassicAndEditorial(): object
{
    /** @var object $migration */
    $migration = require base_path('database/migrations/2026_11_12_120000_retire_classic_and_editorial.php');

    return $migration;
}

/**
 * Every stored arrangement in the database, by tenant and column — the sweep the invariant
 * is about.
 *
 * ⚠ IT READS BOTH COLUMNS, because both are stored documents: `MediaLibrary` already treats
 * the pair as one population when it counts media references, and a `published_document`
 * naming a retired case is refused by `LandingDocument::fromStored()` exactly like a draft.
 *
 * @return list<array{tenant: string, column: string, template: string}>
 */
function storedArrangements(): array
{
    /** @var list<array{tenant: string, column: string, template: string}> $found */
    $found = withoutTenantScope(
        'test: sweep every tenant for a retired arrangement',
        static function (): array {
            $rows = DB::connection('pgsql_owner')
                ->table('landing_pages')
                ->get(['tenant_id', 'draft_document', 'published_document']);

            $found = [];

            foreach ($rows as $row) {
                foreach (['draft_document', 'published_document'] as $column) {
                    $stored = $row->{$column};

                    if (! is_string($stored)) {
                        continue;
                    }

                    /** @var array<string, mixed> $document */
                    $document = json_decode($stored, true, 32, JSON_THROW_ON_ERROR) ?: [];
                    $template = $document['template'] ?? null;

                    if (is_string($template)) {
                        $found[] = [
                            'tenant' => (string) $row->tenant_id,
                            'column' => $column,
                            'template' => $template,
                        ];
                    }
                }
            }

            return $found;
        },
        'pgsql_owner',
    );

    return $found;
}

/** Write a document straight into a column, past the validator that would refuse it. */
function plantStoredDocument(string $tenantId, string $column, array $document): void
{
    withTenant($tenantId, function () use ($column, $document): void {
        $page = DB::table('landing_pages')->select('id')->first();

        expect($page)->not->toBeNull('the tenant has no landing page to plant a document in');

        $affected = DB::table('landing_pages')
            ->where('id', $page->id)
            ->update([$column => json_encode($document, JSON_THROW_ON_ERROR)]);

        expect($affected)->toBe(1);
    });
}

it('retires both cases from the enum, which is what makes this a migration and not a rename', function (): void {
    // The premise every case below rests on. If either came back, the migration's mapping
    // would still run and prove nothing, because nothing would be refusing the old value.
    expect(LandingTemplate::tryFrom('classic'))->toBeNull()
        ->and(LandingTemplate::tryFrom('editorial'))->toBeNull()
        ->and(LandingTemplate::values())->toBe(['rail', 'ember', 'tile'])
        ->and(LandingTemplate::DEFAULT)->toBe(LandingTemplate::Rail);

    // And the two the migration writes are real cases — the one thing that could silently
    // falsify its literal mapping, which cannot read the retired names from the enum.
    expect(LandingTemplate::tryFrom('rail'))->not->toBeNull()
        ->and(LandingTemplate::tryFrom('ember'))->not->toBeNull();
});

it('leaves NO stored document naming a retired case, across every tenant', function (): void {
    // Three tenants, three shapes: a draft on `classic`, a PUBLISHED page on `editorial`,
    // and a document that names no arrangement at all — which is every page stored before
    // `QRPAGER-33` and the one the migration must not write.
    $onClassic = $this->makeTenant(slug: 'retire-classic');
    $onEditorial = $this->makeTenant(slug: 'retire-editorial');
    $onNothing = $this->makeTenant(slug: 'retire-absent');

    $pages = new LandingPages;

    foreach ([$onClassic, $onEditorial, $onNothing] as $tenantId) {
        $pages->saveDraft($tenantId, LandingFixtures::fullPage());
    }

    $pages->publish($onEditorial);

    plantStoredDocument($onClassic, 'draft_document', array_replace(
        LandingFixtures::fullPage(),
        ['template' => 'classic'],
    ));
    plantStoredDocument($onEditorial, 'draft_document', array_replace(
        LandingFixtures::fullPage(),
        ['template' => 'editorial'],
    ));
    plantStoredDocument($onEditorial, 'published_document', array_replace(
        LandingFixtures::fullPage(),
        ['template' => 'editorial'],
    ));

    /*
     * ⚠ THE PRE-FEATURE DOCUMENT IS PLANTED TOO, AND THAT IS NOT A SHORTCUT. `saveDraft()`
     * NORMALISES: `LandingDocument::toArray()` writes `template` whether or not the input
     * carried one, so a document stored through the application always has the member. The
     * rows this migration is written for are OLDER than that — stored before `QRPAGER-33`
     * existed — and the only way to have one in a test is to write it the way the database
     * holds it. A version of this case that used `saveDraft()` asserted the absence of a key
     * the application had just added, and failed for that reason rather than for a defect.
     */
    plantStoredDocument($onNothing, 'draft_document', LandingFixtures::fullPage());

    // The planted state is what the migration is for: three documents the application can no
    // longer read. Asserted rather than assumed, so a planting that silently failed would
    // not leave this case passing on an empty premise.
    expect(array_column(storedArrangements(), 'template'))
        ->toContain('classic')
        ->toContain('editorial');

    retireClassicAndEditorial()->up();

    $after = storedArrangements();

    expect(array_column($after, 'template'))
        ->not->toContain('classic')
        ->not->toContain('editorial');

    foreach ($after as $row) {
        expect(LandingTemplate::tryFrom($row['template']))->not->toBeNull(
            "{$row['column']} of tenant {$row['tenant']} still names {$row['template']}, which is not an arrangement"
        );
    }

    // The mapping, both directions of it.
    expect($pages->draft($onClassic)->template)->toBe(LandingTemplate::Rail)
        ->and($pages->draft($onEditorial)->template)->toBe(LandingTemplate::Ember);

    $published = withTenant($onEditorial, static fn (): string => (string) DB::table('landing_pages')
        ->value('published_document'));

    expect(json_decode($published, true, 32, JSON_THROW_ON_ERROR)['template'])->toBe('ember');

    // ⚠ AND THE DOCUMENT THAT NAMED NOTHING WAS NOT WRITTEN AT ALL, which is
    // `create_missing => false` arriving as an observable fact rather than as a flag in a
    // call. It takes `LandingTemplate::DEFAULT`, and the default is now `rail`.
    $absent = withTenant($onNothing, static fn (): string => (string) DB::table('landing_pages')
        ->value('draft_document'));

    expect(json_decode($absent, true, 32, JSON_THROW_ON_ERROR))->not->toHaveKey('template')
        ->and($pages->draft($onNothing)->template)->toBe(LandingTemplate::Rail);
});

it('fails when a document names a retired case, which is what makes the sweep a proof', function (): void {
    /*
     * ⚠ THE FALSIFICATION, AND IT IS MUHAMMAD'S STANDING INSTRUCTION OF 2026-11-03: every new
     * proof is SHOWN TO REDDEN once. The case above would pass just as happily against a
     * sweep that read nothing at all — an empty list contains no `classic` either — so the
     * planted document here is what separates *"no document names a retired case"* from
     * *"this test cannot see documents"*.
     */
    $tenantId = $this->makeTenant(slug: 'retire-falsify');

    (new LandingPages)->saveDraft($tenantId, LandingFixtures::fullPage());

    retireClassicAndEditorial()->up();

    expect(array_column(storedArrangements(), 'template'))->not->toContain('classic');

    // Planted AFTER the migration, exactly as a stale backup restore or a hand-edited row
    // would arrive.
    plantStoredDocument($tenantId, 'draft_document', array_replace(
        LandingFixtures::fullPage(),
        ['template' => 'classic'],
    ));

    $found = array_filter(storedArrangements(), static fn (array $row): bool => $row['template'] === 'classic');

    expect($found)->toHaveCount(1, 'the sweep did not report a planted retired arrangement');

    // And the application refuses that document on READ, which is the failure the migration
    // exists to prevent rather than a second opinion about it.
    expect(fn () => (new LandingPages)->draft($tenantId))
        ->toThrow(LandingDocumentInvalid::class);
});

it('writes nothing on a second run, and nothing to a tenant that named no retired case', function (): void {
    $migrated = $this->makeTenant(slug: 'retire-twice');
    $untouched = $this->makeTenant(slug: 'retire-untouched');

    $pages = new LandingPages;

    $pages->saveDraft($migrated, LandingFixtures::fullPage());
    $pages->saveDraft($untouched, array_replace(LandingFixtures::fullPage(), ['template' => 'tile']));

    plantStoredDocument($migrated, 'draft_document', array_replace(
        LandingFixtures::fullPage(),
        ['template' => 'editorial'],
    ));

    $before = withTenant($untouched, static fn (): string => (string) DB::table('landing_pages')
        ->value('draft_document'));

    retireClassicAndEditorial()->up();

    $once = withTenant($migrated, static fn (): string => (string) DB::table('landing_pages')
        ->value('draft_document'));

    /*
     * ⚠⚠ THE FIRST RUN'S WORK IS ASSERTED BEFORE THE SECOND RUN IS COMPARED TO IT — `§11` QA
     * ROUND, MEDIUM 1, AND IT WAS MEASURED: with `up()` replaced by an immediate `return`,
     * this case stayed GREEN. It read `$once`, ran `up()` again, and compared the two strings
     * — which is equally true of a migration that writes nothing at all, so "idempotent" and
     * "inert" were indistinguishable here. Two of the six cases in this file reddened under
     * that mutation; this one has to be the third.
     */
    expect(json_decode($once, true, 32, JSON_THROW_ON_ERROR)['template'] ?? null)->toBe(
        'ember',
        'the first run did not migrate the planted document, so comparing a second run to it proves nothing'
    );

    // ⚠ THE SECOND RUN IS THE ASSERTION, NOT A WARM-UP. `migrateColumn()` throws when a keyed
    // statement reports a row count other than 1, so a re-run that matched its own output
    // would raise rather than pass quietly — the guard and the idempotency are the same
    // `WHERE`, which is the pattern `§4 › Postgres traps` ends on.
    retireClassicAndEditorial()->up();

    $twice = withTenant($migrated, static fn (): string => (string) DB::table('landing_pages')
        ->value('draft_document'));

    expect($twice)->toBe($once, 'a second run rewrote a document it had already migrated');

    // And the tenant on `tile` is byte-identical: this migration touches rows that name a
    // retired case and no others. A bulk statement would have written this row from inside
    // another tenant's scope, which is the defect the keyed form exists to make impossible.
    $after = withTenant($untouched, static fn (): string => (string) DB::table('landing_pages')
        ->value('draft_document'));

    expect($after)->toBe($before);
});

it('opens exactly one audited bypass, and does the per-tenant work inside a tenant scope', function (): void {
    /*
     * `§2.10` pattern 1: *"the per-tenant work needs no bypass. Enumerating the tenants does:
     * that one cross-tenant read is the only part the owner cannot do under FORCE RLS."*
     *
     * The audit row is the observable half — written inside the bypass's own transaction, so
     * a bypass that commits always carries one. Counted by REASON, because this test's own
     * sweeps open bypasses too and a count of `tenancy.bypass` rows would include them.
     */
    $tenantId = $this->makeTenant(slug: 'retire-audited');

    (new LandingPages)->saveDraft($tenantId, LandingFixtures::fullPage());

    plantStoredDocument($tenantId, 'draft_document', array_replace(
        LandingFixtures::fullPage(),
        ['template' => 'classic'],
    ));

    $reason = '2026_11_12 QRPAGER-47 retire classic and editorial: list tenants';

    $before = (int) DB::connection('pgsql_root')->table('audit_log')
        ->where('action', 'tenancy.bypass')
        ->where('context->reason', $reason)
        ->count();

    retireClassicAndEditorial()->up();

    $row = DB::connection('pgsql_root')->table('audit_log')
        ->where('action', 'tenancy.bypass')
        ->where('context->reason', $reason)
        ->orderByDesc('created_at')
        ->first();

    expect($row)->not->toBeNull('the migration enumerated tenants without an audited bypass')
        ->and((string) $row->actor_type)->toBe('system')
        ->and($row->tenant_id)->toBeNull();

    $after = (int) DB::connection('pgsql_root')->table('audit_log')
        ->where('action', 'tenancy.bypass')
        ->where('context->reason', $reason)
        ->count();

    // ⚠ EXACTLY ONE MORE, WHICH IS THE *"only the enumeration"* HALF. A migration that opened
    // a bypass per tenant would still migrate the data correctly and would still be audited —
    // and would have widened the one path this project keeps narrow. The count is what says
    // which of the two patterns was used.
    expect($after - $before)->toBe(1);
});

it('rolls back clean and re-applies, with the data valid on both sides', function (): void {
    /*
     * `§6`: *"a rollback of this task's migrations runs clean."* `down()` is a deliberate
     * no-op — `rail` and `ember` are cases on both sides of this ticket, so a rolled-back
     * schema reads every row the migration wrote, and a `down()` that mapped back would
     * rewrite the page of a tenant who CHOSE the ledger. That decision is only honest if the
     * rollback is actually run, so it is run here.
     */
    $tenantId = $this->makeTenant(slug: 'retire-rollback-'.Str::lower(Str::random(6)));

    (new LandingPages)->saveDraft($tenantId, array_replace(
        LandingFixtures::fullPage(),
        ['template' => 'ember'],
    ));

    $before = withTenant($tenantId, static fn (): string => (string) DB::table('landing_pages')
        ->value('draft_document'));

    $rollback = Artisan::call('migrate:rollback', [
        '--database' => 'pgsql_owner',
        '--step' => 1,
        '--force' => true,
    ]);

    expect($rollback)->toBe(0, 'migrate:rollback did not exit 0: '.Artisan::output());

    $forward = Artisan::call('migrate', ['--database' => 'pgsql_owner', '--force' => true]);

    expect($forward)->toBe(0, 'the re-apply did not exit 0: '.Artisan::output());

    $after = withTenant($tenantId, static fn (): string => (string) DB::table('landing_pages')
        ->value('draft_document'));

    expect($after)->toBe($before, 'the rollback and re-apply changed a document that named no retired case')
        ->and((new LandingPages)->draft($tenantId)->template)->toBe(LandingTemplate::Ember);
});
