<?php

declare(strict_types=1);

namespace App\Features\Provisioning\Panel\Resources;

use App\Features\Provisioning\Panel\Resources\PlatformAuditResource\Pages\ListPlatformAudit;
use App\Models\PlatformAuditEntry;
use Filament\Facades\Filament;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The audit log viewer — over `platform_audit_log`, which is a PLATFORM table.
 *
 * ⚠ IT IS NOT A VIEWER OF `audit_log`, AND THAT IS THE WHOLE POINT. `audit_log` is a tenant
 * table: its rows belong to one restaurant, a platform admin holds no membership in any,
 * and a platform-scope row (`tenant_id` NULL) is unreadable by `qrpager_app` in any context
 * — measured, 0 rows, see `QRPAGER-13 §0` SR-3. So this viewer shows what platform admins
 * did to platform records, and a restaurant's own history is visible only to that
 * restaurant. `§3` row 6's "platform admin actions all land in `audit_log`" is met against
 * this table instead, and that substitution is recorded rather than silent.
 *
 * READ-ONLY BY CONSTRUCTION, which the ticket's `§5` requires in those words. There is no
 * create page, no edit page, no row action and no bulk action; the model has an empty
 * `$fillable` and `$timestamps = false`; no role holds UPDATE or DELETE on the table; and
 * the table's own `ENABLE ALWAYS` triggers refuse all three for the superuser too. Four
 * layers, and `PlatformTableContractTest` drives the bottom two.
 */
class PlatformAuditResource extends Resource
{
    protected static ?string $model = PlatformAuditEntry::class;

    protected static ?string $modelLabel = 'platform audit entry';

    protected static ?string $pluralModelLabel = 'platform audit log';

    protected static ?string $slug = 'audit-log';

    public static function table(Table $table): Table
    {
        return $table
            // An explicit select list, through a query modifier rather than an override of
            // getEloquentQuery() -- Eloquent's Builder declares TModel as INVARIANT, so an
            // override returning Builder<PlatformAuditEntry> cannot satisfy the parent's
            // Builder<Model> without widening or casting, and both would hide the mismatch
            // rather than remove it. PHPStan found that; the modifier is the idiomatic place
            // for this anyway.
            //
            // `context` is a free-text jsonb column written by this application's own
            // writers, and the columns are named so a reader can see what reaches the
            // browser.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->select([
                'id', 'created_at', 'action', 'actor_user_id', 'subject_type', 'subject_id',
                'context', 'ip_address',
            ]))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('action')->label('Action')->searchable()->sortable(),
                TextColumn::make('actor_user_id')->label('Actor')->placeholder('System')->searchable(),
                TextColumn::make('subject_type')->label('Subject')->placeholder('—'),
                TextColumn::make('subject_id')->label('Subject id')->placeholder('—'),
                TextColumn::make('ip_address')->label('From')->placeholder('—'),
            ])
            // Newest first, which is the only order an audit log is ever read in.
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Nothing recorded yet')
            ->recordActions([])
            ->toolbarActions([]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListPlatformAudit::route('/'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return 'Platform audit log';
    }

    public static function canViewAny(): bool
    {
        // Filament::auth(), NOT auth() (QA L-7). `auth()` is the DEFAULT guard,
        // while the panel names `authGuard('web')` explicitly so a change to
        // config/auth.php's default cannot silently move which guard it trusts — and
        // these gates were still bound to that default, so the two could diverge.
        return Filament::auth()->user()?->getAttribute('is_platform_admin') === true;
    }

    /** Nothing creates an entry through the panel; the writers are the service and the job. */
    public static function canCreate(): bool
    {
        return false;
    }
}
