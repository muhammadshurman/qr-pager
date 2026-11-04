<?php

declare(strict_types=1);

namespace App\Features\Provisioning\Panel\Resources;

use App\Domain\Auth\SupportAccessRefused;
use App\Domain\Provisioning\ProvisioningStatus;
use App\Features\Provisioning\Panel\Resources\RestaurantResource\Pages\ListRestaurants;
use App\Features\Support\SupportAccess;
use App\Models\TenantProvisioningRecord;
use App\Platform\Tenancy\SupportMemberships;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The restaurant registry: S1's read surface, over `tenant_provisionings`.
 *
 * ================================================================================
 * EVERY QUERY THIS RESOURCE ISSUES IS AGAINST A PLATFORM TABLE
 * ================================================================================
 *
 * The table's base query is `tenant_provisionings` LEFT JOIN `tenant_statistics` — both
 * platform tables, no RLS, no tenant context needed. That join is how "usage" appears in
 * the registry without a second page and without a per-row query.
 *
 * ⚠ AND IT IS WHY THERE IS NO `tenant` RELATION ON THE MODEL. A relation to a tenant-table
 * model is the realistic way this invariant would be broken later: one `->with('tenant')`
 * for a name column, and every list query joins `tenants`.
 * `PlatformPanelInvariantTest` reflects over the three platform models and fails if a
 * relation method appears, and drives this resource's own page with a query listener.
 *
 * `$model` is the provisioning record and not a "Tenant" model, which is the data contract
 * showing through the UI rather than being hidden by it: the platform's object IS the
 * provisioning record. There is no Eloquent model for `tenants` in this application at all.
 */
class RestaurantResource extends Resource
{
    protected static ?string $model = TenantProvisioningRecord::class;

    protected static ?string $modelLabel = 'restaurant';

    protected static ?string $pluralModelLabel = 'restaurants';

    protected static ?string $slug = 'restaurants';

    public static function table(Table $table): Table
    {
        return $table
            // THE BASE QUERY: two platform tables, one join, no tenant table. That join is
            // how "usage" reaches the registry without a second page and without a query
            // per row.
            //
            // modifyQueryUsing() AND NOT an override of getEloquentQuery(), for a reason
            // PHPStan found rather than a preference: Eloquent's `Builder` declares TModel
            // as INVARIANT, so an override returning `Builder<TenantProvisioningRecord>`
            // does not satisfy the parent's `Builder<Model>` and cannot be made to without
            // widening or casting -- both of which would be hiding the mismatch instead of
            // removing it. A query modifier takes the builder Filament already built, which
            // is the idiomatic place for this anyway.
            //
            // An explicit select list rather than `select *` plus the joined columns, so a
            // column added to either table does not silently start travelling to the
            // browser, and so that what the panel reads is enumerable by a reader as well
            // as by the invariant test.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->leftJoin('tenant_statistics', 'tenant_statistics.id', '=', 'tenant_provisionings.id')
                ->select([
                    'tenant_provisionings.id',
                    'tenant_provisionings.name',
                    'tenant_provisionings.slug',
                    'tenant_provisionings.owner_email',
                    'tenant_provisionings.status',
                    'tenant_provisionings.attempts',
                    'tenant_provisionings.failure_reason',
                    'tenant_provisionings.completed_at',
                    'tenant_provisionings.created_at',
                    'tenant_statistics.sessions_total',
                    'tenant_statistics.staff_count',
                    'tenant_statistics.median_wait_seconds',
                    'tenant_statistics.computed_at',
                ]))
            ->columns([
                TextColumn::make('name')
                    ->label('Restaurant')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('Address')
                    ->searchable()
                    // The slug is the tenant's public address, so it is shown as typed and
                    // never title-cased: a displayed value that differs from the stored one
                    // is a value somebody will retype wrongly.
                    ->sortable(),
                TextColumn::make('status')
                    ->label('State')
                    // badge() carries the state as TEXT inside the badge, so the figure is
                    // never conveyed by colour alone -- §12's WCAG AA clause. The colour is
                    // Filament's own; this task invents no palette (§12, Task 8 owns tokens).
                    ->badge()
                    ->sortable(),
                TextColumn::make('failure_reason')
                    ->label('Why it failed')
                    // The mapped reason, which is all the record carries: reasonFor() maps a
                    // SQLSTATE and never passes the driver message through, so there is no
                    // SQL and no bound parameter to leak onto this screen (§4 › Edge & ops).
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('sessions_total')
                    ->label('Pager sessions')
                    ->placeholder('No data yet')
                    ->sortable(),
                TextColumn::make('staff_count')
                    ->label('Staff')
                    ->placeholder('No data yet')
                    ->sortable(),
                TextColumn::make('median_wait_seconds')
                    ->label('Median wait')
                    ->suffix(' s')
                    ->placeholder('No data yet')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Requested')
                    ->dateTime()
                    ->sortable(),
            ])
            // Newest first: the row a platform admin has just created is the one they are
            // looking for.
            ->defaultSort('tenant_provisionings.created_at', 'desc')
            ->emptyStateHeading('No restaurants yet')
            ->emptyStateDescription('Use “New restaurant” to provision the first one.')
            /*
             * ================================================================================
             * ONE ROW ACTION, ADDED AT QRPAGER-22: TAKE SUPPORT ACCESS
             * ================================================================================
             *
             * Still NO delete, NO bulk action and NO suspend, for the reasons that were here
             * before and are unchanged: deleting a provisioning record would orphan a live
             * tenant the panel cannot see, and suspending writes to `tenants` -- a tenant
             * table -- which needs the job-reconciliation path creation uses (`R-117`).
             *
             * ⚠ WHY A SURFACE EXISTS AT ALL. `QRPAGER-22 §1` was raised because a platform
             * admin gets **403** at `/studio` by construction, and `§4 › Tenancy`'s support
             * clause is the bridge the ruling named. Without an action the bridge is
             * unreachable and the ticket's product need is unmet -- so this is the feature
             * rather than a convenience on top of it.
             *
             * ⚠ THE REASON FIELD IS REQUIRED HERE **AND** IN THE SERVICE. `§4` says the
             * trail carries "the tenant, the actor and the reason", and
             * `SupportAccess::take()` refuses a blank one before anything exists to audit --
             * `SupportMembershipTest` drives that. This `->required()` is the courtesy; the
             * service is the enforcement, which is the order this project puts them in
             * everywhere (`TenantRole`'s own docblock: "the screen not offering it is not the
             * enforcement").
             */
            ->recordActions([
                Action::make('takeSupportAccess')
                    ->label('Take support access')
                    ->modalHeading('Take support access')
                    ->modalDescription(
                        'You will hold a read-only membership of this restaurant for '
                        .SupportAccess::LIFETIME_MINUTES.' minutes. The restaurant’s owner '
                        .'sees it in their members list while it lasts, and taking it, acting '
                        .'under it and leaving it are written to the audit trail.'
                    )
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why do you need it?')
                            ->helperText('Written to the audit trail. The restaurant can read it.')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(function (TenantProvisioningRecord $record, array $data): void {
                        $actor = Filament::auth()->id();

                        try {
                            app(SupportAccess::class)->take(
                                (string) $record->getKey(),
                                (string) $actor,
                                (string) ($data['reason'] ?? ''),
                                request()->ip(),
                            );
                        } catch (SupportAccessRefused $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Support access taken')
                            ->body('It expires in '.SupportAccess::LIFETIME_MINUTES.' minutes, or when you release it.')
                            ->success()
                            ->send();
                    })
                    /*
                     * ⚠ `getKey()` AND NOT `$record->tenant_id`, WHICH DOES NOT EXIST. This
                     * model's own docblock says it: *"the key IS the tenant's id"*. PHPStan
                     * caught the invented property three times over, which is the right
                     * failure -- an Eloquent magic getter would have returned null at
                     * runtime and the action would have written a membership for the tenant
                     * id `""`.
                     *
                     * ⚠ AND `getAttribute('status')` RATHER THAN `->status`, because no model
                     * in this repository carries `@property` annotations and PHPStan refuses
                     * magic property access. `casts()` maps `status` to `ProvisioningStatus`,
                     * so the comparison is against the enum rather than a string -- and
                     * adding annotations would have put a second copy of the schema beside
                     * the migration, which is what this project refuses everywhere else.
                     *
                     * ⚠ AND HIDDEN UNTIL PROVISIONING HAS COMPLETED, because the key exists
                     * before the tenant ROW does: `provisioning_status` starts `pending` and
                     * `tenant_users.tenant_id` carries an FK to `tenants`. Offering the
                     * action earlier turns a reasonable click into a 500.
                     */
                    ->visible(fn (TenantProvisioningRecord $record): bool => $record->getAttribute('status') === ProvisioningStatus::Completed),

                Action::make('releaseSupportAccess')
                    ->label('Release support access')
                    ->requiresConfirmation()
                    /*
                     * ⚠ THIS ACTION SETS NO COLOUR, AND THE SCANNER ASKING THE QUESTION
                     * IMPROVED THE ANSWER. A `danger` palette call here was flagged by
                     * `lint:panel` as `no-raw-color`: that rule matches a colour-function
                     * call and cannot tell a Filament palette name from a CSS one, and
                     * nothing else in this application calls that setter.
                     *
                     * ⚠ AND THE RULE MATCHES COMMENTS TOO -- this note had to be reworded
                     * twice, because explaining the removal re-introduced the pattern it was
                     * explaining. Measured: 1 finding became 2.
                     *
                     * It is removed rather than exempted, because on reflection the colour
                     * was wrong: RELEASING support is the SAFE act, the one that returns a
                     * restaurant's data to being unreachable. Painting it as destructive
                     * would nudge an admin towards leaving support held, which is the
                     * opposite of what `§4`'s hour-long bound is for.
                     */
                    ->action(function (): void {
                        try {
                            app(SupportAccess::class)->release(
                                (string) Filament::auth()->id(),
                                'released from the platform panel',
                                request()->ip(),
                            );
                        } catch (SupportAccessRefused $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Support access released')->success()->send();
                    })
                    /*
                     * ⚠ SHOWN ONLY WHEN THEY HOLD ONE FOR **THIS** RESTAURANT, which is also
                     * the honest answer to "which one would this release?". A platform admin
                     * holds at most one at a time (`§4`), so the button on another row would
                     * release something the row is not about.
                     */
                    ->visible(fn (TenantProvisioningRecord $record): bool => app(SupportMemberships::class)->heldTenantId((string) Filament::auth()->id())
                            === (string) $record->getKey()),
            ])
            ->toolbarActions([]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        // ONE page. No create page, no edit page and no view page: the create flow is a
        // header action on this page that calls TenantProvisioning::request(), so the write
        // goes through the service's explicit column list rather than through Eloquent mass
        // assignment -- which is also why the model's $fillable is empty.
        return [
            'index' => ListRestaurants::route('/'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return 'Restaurants';
    }

    /**
     * Platform admins only.
     *
     * Belt and braces beside the panel's own `canAccessPanel()` check: this is the resource
     * saying so itself, so a resource reachable by a route that skipped the panel's gate is
     * still refused. Both are driven by PlatformPanelInvariantTest for all five principals.
     */
    public static function canViewAny(): bool
    {
        // Filament::auth(), NOT auth() (QA L-7). `auth()` is the DEFAULT guard,
        // while the panel names `authGuard('web')` explicitly so a change to
        // config/auth.php's default cannot silently move which guard it trusts — and
        // these gates were still bound to that default, so the two could diverge.
        return Filament::auth()->user()?->getAttribute('is_platform_admin') === true;
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }
}
