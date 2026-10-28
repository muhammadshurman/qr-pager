<?php

declare(strict_types=1);

namespace App\Features\Provisioning\Panel\Resources;

use App\Domain\Provisioning\ProvisioningStatus;
use App\Features\Provisioning\Panel\Resources\RestaurantResource\Pages\ListRestaurants;
use App\Models\TenantProvisioningRecord;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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

    /**
     * The base query: two platform tables, one join, no tenant table.
     *
     * An explicit select list rather than `select *` plus the joined columns, so a column
     * added to either table does not silently start travelling to the browser — and so
     * that what the panel reads is enumerable by a reader as well as by the test.
     */
    public static function getEloquentQuery(): Builder
    {
        return TenantProvisioningRecord::query()
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
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
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
            // NO row action, NO bulk action and NO delete. Deleting a provisioning record
            // would orphan a live tenant that the panel cannot see, which is the one
            // destructive act this surface must not offer. Suspending a restaurant is a
            // write to `tenants` -- a tenant table -- so it is not here either: it needs the
            // same job-reconciliation path as creation, and that is R-117 (§5).
            ->recordActions([])
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
        return auth()->user()?->getAttribute('is_platform_admin') === true;
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function getStatusOptions(): array
    {
        return array_combine(ProvisioningStatus::values(), ProvisioningStatus::values());
    }
}
