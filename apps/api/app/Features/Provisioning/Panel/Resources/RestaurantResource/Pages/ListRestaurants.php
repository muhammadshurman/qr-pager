<?php

declare(strict_types=1);

namespace App\Features\Provisioning\Panel\Resources\RestaurantResource\Pages;

use App\Features\Provisioning\DuplicateSlug;
use App\Features\Provisioning\OwnerNeedsConsent;
use App\Features\Provisioning\Panel\Resources\RestaurantResource;
use App\Features\Provisioning\TenantProvisioning;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Str;

/**
 * The registry list, and the create action — S1's write surface.
 *
 * ================================================================================
 * THE CREATE ACTION CALLS THE SERVICE, NOT ELOQUENT
 * ================================================================================
 *
 * Filament's `CreateAction` on a resource would `Model::create($data)` — mass assignment
 * into `tenant_provisionings`. This action calls `TenantProvisioning::request()` instead,
 * for three reasons that are each worth the extra code:
 *
 *   1. **Mass assignment is off** (`$fillable = []` on the model), so `create()` would
 *      write nothing and silently succeed. `§11.4` wants mass assignment guarded; an empty
 *      fillable plus a service with an explicit column list is that guard as an absence.
 *   2. The request and its `platform_audit_log` row are **one transaction**. An action that
 *      created the row and then audited it could leave either without the other.
 *   3. `status` and `attempts` are not the caller's to set. A form that could post
 *      `status: completed` would mark a restaurant provisioned without provisioning it.
 *
 * ================================================================================
 * WHAT HAPPENS AFTER IT RETURNS, SAID PLAINLY ON THE SCREEN
 * ================================================================================
 *
 * The restaurant is NOT created by the time this action finishes. It cannot be: creating it
 * writes `tenants` and `tenant_users`, and a panel query that reaches a tenant table is a
 * rejected design, so the tenant-side work is `platform:provision-tenants`' and runs within
 * the minute. The notification says so in those terms rather than claiming success — a
 * screen that reports "created" while the row still reads `pending` is the failure mode
 * that made R-058 worth having.
 */
class ListRestaurants extends ListRecords
{
    protected static string $resource = RestaurantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('provision')
                ->label('New restaurant')
                ->modalHeading('Provision a restaurant')
                ->modalSubmitActionLabel('Request it')
                // EVERY FIELD VALIDATED, and the slug's rule is the SAME expression as the
                // database CHECK rather than a looser one -- two sides agreeing, instead of
                // the application trusting the database to catch what it let through.
                ->schema([
                    TextInput::make('name')
                        ->label('Name')
                        ->required()
                        ->maxLength(120)
                        ->helperText('What the restaurant is called. Up to 120 characters.'),
                    TextInput::make('slug')
                        ->label('Address')
                        ->required()
                        ->maxLength(63)
                        ->regex('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/')
                        // THE UNIQUENESS IS A FIELD RULE, not only a caught exception, and
                        // that is a correction rather than a belt-and-braces addition.
                        // Measured in this session: a ValidationException thrown from the
                        // action's own closure lands under the bare key `slug`, while
                        // Filament attaches a field's errors at
                        // `mountedActions.0.data.slug` -- so the duplicate message was
                        // raised and would NOT have appeared beside the field it is about.
                        // A rule on the field is keyed by Filament itself and cannot drift.
                        //
                        // The unique index on the table is still the real guard; this rule
                        // is the ordinary case and the catch below is the race.
                        ->unique(table: 'tenant_provisionings', column: 'slug')
                        ->helperText('Lower-case letters, digits and hyphens. This becomes the restaurant’s web address and cannot be changed later.')
                        ->validationMessages([
                            // Filament's default regex message names the pattern, which is
                            // schema detail on a screen. These say what to do instead, and
                            // neither carries a table name, a column name or SQL.
                            'regex' => 'Use lower-case letters, digits and hyphens only, starting and ending with a letter or digit.',
                            'unique' => 'That address is already taken by another restaurant.',
                        ]),
                    TextInput::make('owner_email')
                        ->label('Owner’s email address')
                        ->required()
                        ->email()
                        ->maxLength(255)
                        // THE CONSENT RULE, ON THE FIELD so Filament keys the error to it
                        // (security M-2). The same rule runs again in the drain, because the
                        // answer can change in the minute between — this one is for the
                        // human, that one is the contract.
                        //
                        // A closure rule rather than a thrown ValidationException: an
                        // exception from the action's closure lands under the bare key
                        // `owner_email` while Filament's fields live at
                        // `mountedActions.0.data.owner_email`, which is the same trap the
                        // slug's uniqueness hit.
                        //
                        // A closure RETURNING the rule, not the rule itself: Filament
                        // evaluates a `->rule()` closure through its OWN injector, which
                        // supplies `$state`, `$component` and friends -- not the validator's
                        // `$attribute, $value, $fail`. Passing the rule directly threw
                        // "[$attribute] was unresolvable", measured here.
                        ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                            if (! is_string($value) || $value === '') {
                                return;
                            }

                            if (app(TenantProvisioning::class)->refusalForOwnerEmail($value) !== null) {
                                // ONE MESSAGE FOR BOTH REASONS. Distinguishing them would
                                // let this form be used to probe whether an address has an
                                // account and whether it is a platform admin's — the same
                                // enumeration argument that makes login answer identically
                                // for "no such account" and "wrong password".
                                $fail('That address cannot be made the owner of a new restaurant here. It needs an invitation the person accepts themselves.');
                            }
                        })
                        ->helperText('The first owner. Use an address that has no account yet; an existing account has to accept an invitation instead.'),
                ])
                ->action(function (array $data): void {
                    try {
                        app(TenantProvisioning::class)->request(
                            name: (string) $data['name'],
                            slug: Str::lower((string) $data['slug']),
                            ownerEmail: (string) $data['owner_email'],
                            requestedByUserId: auth()->id() === null ? null : (string) auth()->id(),
                            actorIp: request()->ip(),
                        );
                    } catch (OwnerNeedsConsent $e) {
                        // The race between the field rule above and this insert: the account
                        // could have gained a membership in between. Same shape as the
                        // duplicate-slug race below, and the message is the exception's own,
                        // which names no account and no reason.
                        Notification::make()
                            ->title('Not provisioned')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    } catch (DuplicateSlug $e) {
                        // THE RACE, AND ONLY THE RACE. The ordinary duplicate is caught by
                        // the field's `unique` rule above, where Filament keys the error to
                        // the field. This path is two admins submitting the same address
                        // between that check and the insert, which the table's unique index
                        // refuses and nothing in PHP can prevent.
                        //
                        // A notification rather than a field error, because re-keying a
                        // hand-thrown ValidationException to Filament's mounted-action path
                        // would mean reproducing an internal state path by hand -- a string
                        // that goes stale silently the next time Filament changes it. The
                        // message is the exception's own and carries no SQL, no constraint
                        // name and no SQLSTATE (§4 › Edge & ops).
                        Notification::make()
                            ->title('Not provisioned')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Requested')
                        // NOT "created". See the class docblock: the tenant does not exist
                        // yet and this screen must not say that it does.
                        ->body('The restaurant is queued for provisioning and will appear as Active within a minute. If it fails, the reason is shown on its row.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
