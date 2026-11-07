<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantRole;
use App\Features\Landing\Panel\Pages\DomainsPage;
use App\Features\Landing\TenantDomains;
use App\Features\Provisioning\Panel\Resources\PlatformAuditResource;
use App\Features\Provisioning\Panel\Resources\PlatformAuditResource\Pages\ListPlatformAudit;
use App\Models\User;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\LandingFixtures;
use Tests\Support\LivewireRequest;

/*
 * ================================================================================
 * THE THREE COPY BREACHES QRPAGER-23 §6 LOCATED, FIXED AND PINNED
 * ================================================================================
 *
 * `§6` splits "nothing to show" into three screens because the reader's next move differs —
 * wait, ask someone, or create something — and a single "No records" collapses all three
 * into whichever is most often wrong. Three breaches were confirmed in the source before any
 * code was written, and this file holds each fix to the shape that was asked for.
 *
 * ⚠ WHAT THIS FILE MUST NOT BECOME. `§4.2`: **a refusal may say what to do; it may not say
 * what exists.** Several surfaces here answer "indistinguishable from unknown" DELIBERATELY —
 * eight call sites carry that reasoning — and friendlier copy is exactly how such an
 * equality gets broken by someone trying to be helpful. So the domain case below asserts the
 * EQUALITY of the new sentence across all three reasons the record is unavailable, not just
 * that the sentence is nice. The Task 5 and 6 tests that pin the sign-in equalities are the
 * regression net for the rest and pass unchanged; none of them was touched.
 */

/**
 * The audit table as the panel configures it.
 *
 * A LOCAL helper rather than the `configuredTable()` that `PlatformPanelAccessibilityTest`
 * declares: a function defined in one Pest test file is a global PHP function whose
 * availability depends on which file loaded first, so reaching across would make this file
 * pass or fail on run order.
 */
function copyAuditTable(): Table
{
    $instance = Livewire::test(ListPlatformAudit::class)->instance();
    assert($instance instanceof HasTable);

    return PlatformAuditResource::table(Table::make($instance));
}

/** A platform admin, inserted as the owner because the app role holds no INSERT on the flag. */
function copyAdmin(): User
{
    $id = (string) Str::uuid7();

    DB::connection('pgsql_owner')->table('users')->insert([
        'id' => $id,
        'name' => 'Copy Admin',
        'email' => 'copy-'.Str::lower(Str::random(10)).'@example.test',
        'is_platform_admin' => true,
    ]);

    $user = User::query()->findOrFail($id);
    assert($user instanceof User);

    return $user;
}

it('gives the audit log an empty state that tells the reader to stop waiting', function (): void {
    // ⚠ §4.1's FIRST HALF: an empty state with no next action. It was a heading alone --
    // `Nothing recorded yet` -- with `recordActions([])` and `toolbarActions([])`, so a
    // reader could not tell a filter they had missed from a system that was not recording.
    //
    // And this is the ONE screen where the honest next action is "none, and here is why":
    // the log is append-only and written by the application, so there is no verb to offer
    // and inventing one would be §6.3's "a screen needing a new verb is a finding".
    $this->actingAs(copyAdmin());

    $description = copyAuditTable()->getEmptyStateDescription();

    expect($description)->not->toBeNull('the audit log still has a heading and no description')
        ->and((string) $description)->toContain('fills itself')
        ->and((string) $description)->toContain('nothing to do here');

    // ⚠ AND IT OFFERS NO VERB, which is the half that is easy to get wrong in the other
    // direction: a description promising an action the reader cannot take is worse than a
    // bare heading, because it sends them looking for a button that is not there.
    foreach (['Create', 'Add ', 'New ', 'Try again', 'Retry'] as $verb) {
        expect(str_contains((string) $description, $verb))
            ->toBeFalse("the audit log's empty state offers '{$verb}', which the reader cannot do");
    }
});

it('labels the audit subject in human words and keeps the whole value retrievable', function (): void {
    // §6.2's two breaches in one column: `->label('Subject id')` -- system vocabulary -- over
    // a bare uuid7, 36 characters of noise in a table.
    //
    // The VALUE is kept deliberately: an audit log a reader cannot correlate with another
    // screen is one they cannot investigate with. What changed is how much is in their way.
    $this->actingAs(copyAdmin());

    $columns = copyAuditTable()->getColumns();

    expect($columns)->toHaveKey('subject_id');

    $column = $columns['subject_id'];

    expect($column->getLabel())->toBe('Reference')
        // Truncated on screen...
        ->and($column->getCharacterLimit())->toBe(8)
        // ...and NOT truncated out of reach, which is what makes the truncation safe. A
        // prefix nobody can retrieve the rest of would be strictly worse than the noise.
        //
        // `isCopyable()` takes the STATE -- `isCopyable(mixed $state, ?Model $relatedRecord)`
        // -- because copyability can be a closure over the row. Driven with a real uuid
        // rather than called bare, which is also the only form that proves it for the value
        // the column actually carries.
        ->and($column->isCopyable((string) Str::uuid7()))->toBeTrue()
        // And the tooltip hands back the WHOLE value, which is the other half of making an
        // eight-character prefix honest.
        ->and($column->getTooltip('01a1932b-11bf-7000-8000-000000000000'))
        ->toBe('01a1932b-11bf-7000-8000-000000000000');

    // No column on this table carries a system-vocabulary label any more. Driven over the
    // whole table rather than the one column, so the next one cannot arrive quietly.
    foreach ($columns as $name => $each) {
        // str_contains() AND NOT `->not->toContain($needle, $message)`: toContain is
        // VARIADIC over needles and takes no message, so the negated two-argument form is
        // trivially true. `composer guard`'s vacuous-negated-contains rule refuses it, and
        // caught this line -- the second time in this task, which is what a working control
        // looks like.
        expect(str_contains(strtolower((string) $each->getLabel()), ' id'))
            ->toBeFalse("the {$name} column is labelled in system vocabulary");
    }
});

it('says the DNS record is unavailable instead of rendering a blank one', function (): void {
    // ⚠ R-154. `challenge()` returned `['name' => '', 'type' => 'TXT', 'value' => '']` on
    // both non-success paths and the template rendered it, so a tenant was shown
    // `Name: ` / `Type: TXT` / `Value: ` and told to copy that into their DNS. The empty
    // case and the source-down case were the same fabricated record, and neither said
    // anything was wrong.
    LandingFixtures::bucket();

    $tenant = $this->actingForTenant(role: TenantRole::Owner);
    $id = app(TenantDomains::class)->add($tenant, 'copy-fix.example.test');

    $page = Livewire::test(DomainsPage::class)->instance();

    // The positive control FIRST: a real domain still yields a real record, or "returns
    // null when it cannot" would be true for the uninteresting reason.
    $real = $page->challenge($id);

    expect($real)->toBeArray()
        ->and($real['type'])->toBe('TXT')
        ->and($real['value'])->toStartWith('qrpager-verification=')
        ->and($real['name'])->not->toBe('');

    // And the three reasons it can be unavailable all answer the SAME null — §4.2's rule:
    // say what to do, never what exists. This is the equality `ReviewFixesTest` pins one
    // layer down, held at the surface the tenant actually reads.
    $other = $this->makeTenant(slug: 'copy-fix-other');
    $foreign = app(TenantDomains::class)->add($other, 'copy-fix-other.example.test');

    expect($page->challenge('not-a-uuid'))->toBeNull()
        ->and($page->challenge((string) Str::uuid7()))->toBeNull()
        ->and($page->challenge($foreign))->toBeNull();
});

it('renders the real record on the rendered page, with no blank row anywhere in it', function (): void {
    // THE RENDERED OUTPUT, over HTTP. The fabricated-record shape was an empty <code></code>
    // inside the definition list, so its absence is the regression assertion -- and the
    // positive half (the real value IS printed) is what stops "no blank row" from being
    // satisfied by a page that renders no record at all.
    LandingFixtures::bucket();

    $tenant = $this->actingForTenant(role: TenantRole::Owner);
    app(TenantDomains::class)->add($tenant, 'rendered.example.test');

    $html = (string) $this->get('/studio/domains')->getContent();

    expect($html)->toContain('qrpager-verification=')
        ->and($html)->toContain('Value:')
        ->and(str_contains($html, '<code></code>'))
        ->toBeFalse('the page renders an empty code element, which is R-154s blank row');
});

it('answers a CRAFTED challenge call with null, which is where the blank row was reachable', function (): void {
    /*
     * ⚠ A CORRECTION TO §6.1's OWN CELL, found by trying to make this test fail.
     *
     * The ticket says of `R-154`: *"rendered as a blank TXT row with no notification. A
     * tenant is shown an empty record they are told to copy."* Measured: **a tenant is
     * not.** `domains.blade.php` calls `$this->challenge($domain->id)` only for ids it
     * read from `$this->domains()` in the SAME render, and `TenantDomains::challenge()`
     * throws only when the row is absent -- so the id is always a uuid and the row is
     * always there. Reverting the fix and re-running the rendered-page case above leaves
     * it GREEN, which is how the gap was found rather than argued.
     *
     * Where the fabricated record WAS reachable is here: every public method on a Livewire
     * component is callable from `POST /livewire-<hash>/update`, and a crafted call got
     * back a credential-shaped `{name: '', type: 'TXT', value: ''}` -- an empty record
     * presented as a real one. That is the surface this case drives, because it is the one
     * that exists.
     *
     * The template branch stays as defence for the race the page cannot rule out (a domain
     * removed between `domains()` and `challenge()` in one render) and is labelled in the
     * view as what it is.
     */
    LandingFixtures::bucket();

    $tenant = $this->actingForTenant(role: TenantRole::Owner);
    app(TenantDomains::class)->add($tenant, 'crafted.example.test');

    $other = $this->makeTenant(slug: 'crafted-other');
    $foreign = app(TenantDomains::class)->add($other, 'crafted-other.example.test');

    $snapshot = LivewireRequest::snapshotFor(
        (string) $this->get('/studio/domains')->getContent(),
        DomainsPage::class,
    );

    expect($snapshot)->not->toBe('', 'the domains page embedded no snapshot: the GET is not what it was');

    // All three reasons the record can be unavailable, through the real endpoint, and all
    // three must answer the SAME thing -- §4.2: say what to do, never what exists.
    foreach (['not-a-uuid', (string) Str::uuid7(), $foreign] as $id) {
        $response = $this->postJson(
            LivewireRequest::updatePath(),
            LivewireRequest::payload($snapshot, 'challenge', [$id]),
            LivewireRequest::HEADERS,
        );

        expect($response->status())->toBe(200, "the endpoint refused the call for {$id}");

        $returns = $response->json('components.0.effects.returns');

        // ⚠ array_key_exists AND NOT `$returns[0] ?? 'absent'`. `??` coalesces on NULL, so
        // the sentinel fired for the value this case is asserting IS null -- the first
        // version failed with "Failed asserting that 'absent' is null", which is the
        // assertion refuting its own instrument. The endpoint's shape, measured, is
        // `effects.returns == [null]`.
        expect($returns)->toBeArray()
            ->and(array_key_exists(0, (array) $returns))
            ->toBeTrue("the endpoint returned no value at all for challenge({$id})")
            ->and($returns[0])->toBeNull("challenge({$id}) returned a value")
            // And again on the serialised form, because an assertion on the null alone
            // would also pass against a response carrying a DIFFERENT fabricated shape.
            ->and(json_encode($returns))->not->toContain('TXT');
    }

    // THE POSITIVE CONTROL, through the same endpoint: the tenant's own domain still yields
    // a real record, so "null for everything" is not what was just proven.
    $own = app(TenantDomains::class)->all($tenant)[0]->id;

    $served = $this->postJson(
        LivewireRequest::updatePath(),
        LivewireRequest::payload($snapshot, 'challenge', [$own]),
        LivewireRequest::HEADERS,
    );

    expect((string) json_encode($served->json('components.0.effects.returns')))
        ->toContain('qrpager-verification=')
        ->and((string) json_encode($served->json('components.0.effects.returns')))->toContain('TXT');
});
