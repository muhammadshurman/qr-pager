<?php

declare(strict_types=1);

namespace App\Features\Landing\Panel\Pages;

use App\Domain\Landing\DomainStatus;
use App\Features\Landing\DomainRefused;
use App\Features\Landing\LandingPages;
use App\Features\Landing\Panel\HasStudioLocale;
use App\Features\Landing\TenantDomainRow;
use App\Features\Landing\TenantDomains;
use App\Platform\Http\TenantPanelAccess;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;

/**
 * `/studio/domains` — custom domains and TLS (§12.3).
 *
 * The lifecycle advances from HERE, one step per press, inside this tenant's own scope.
 * There is no background sweep anywhere in this task, and that absence is SR-3: walking
 * every tenant's domains is a cross-tenant read and `§4 › Tenancy` names four paths, none
 * of which is this one. The residual -- a certificate becomes active when somebody next
 * looks -- is R-130, owned by Task 13.
 */
class DomainsPage extends Page
{
    use HasStudioLocale;

    protected string $view = 'studio.domains';

    /*
     * ⚠ `getTitle()` AND NOT `protected static ?string $title` — `QRPAGER-41`.
     *
     * A static property initialiser is evaluated when the class is loaded, and PHP permits
     * no function call there, so `__()` cannot appear in it at all. More to the point, a
     * value fixed at class-load time could not follow a locale that is chosen per request
     * from the tenant's own row. Filament calls `getTitle()` when it renders the heading,
     * which is after `StudioLocale` has run, so this is the only form that can be correct.
     */
    public function getTitle(): string
    {
        return __('studio.domains.title');
    }

    protected static ?string $slug = 'domains';

    public string $hostname = '';

    public static function canAccess(): bool
    {
        return TenantPanelAccess::allows();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return list<TenantDomainRow> */
    public function domains(): array
    {
        return app(TenantDomains::class)->all(TenantPanelAccess::tenantId());
    }

    /**
     * Where this tenant's page is actually served: the active custom domain if there is
     * one, else the platform hostname.
     *
     * ⚠ RENAMED FROM platformHostname(), which stated a guarantee it did not make: it
     * delegates to LandingPages::hostname(), which returns the CUSTOM hostname once one
     * is active. §12.3's "none"-state copy was correct only because that branch is
     * unreachable with an active domain -- accidental rather than enforced -- and the
     * line below it was mislabelled. Found by the §11 QA review.
     */
    public function liveHostname(): string
    {
        return app(LandingPages::class)->hostname(TenantPanelAccess::tenantId());
    }

    /** The platform hostname, always -- `{slug}.{platform_domain}`, whatever else exists. */
    public function platformFallback(): string
    {
        return app(LandingPages::class)->platformHostname(TenantPanelAccess::tenantId());
    }

    /**
     * The TXT record for one domain, or an empty record set.
     *
     * ⚠ IT RETURNS RATHER THAN THROWING, and it threw until the §11 review of the fix
     * diff. Every public method on a Livewire component is reachable from
     * `POST /livewire-<hash>/update`, so a crafted call answered 500 here while its
     * sibling `advance()` answered a notification -- the same inconsistency the uuid
     * guards were added for, re-introduced one method over in the pass that added them.
     * Nothing was disclosed (the message is identical for malformed, unknown and
     * foreign), but a 500 is not an answer.
     *
     * ⚠ AND IT RETURNS **null** RATHER THAN A BLANK RECORD, WHICH IS `R-154` (QRPAGER-23
     * §6.2). It used to return `['name' => '', 'type' => 'TXT', 'value' => '']` on both
     * non-success paths, and `domains.blade.php` rendered that straight into the
     * definition list: **a tenant was shown `Name: ` / `Type: TXT` / `Value: ` and told to
     * copy it into their DNS**. The empty case and the source-down case were the same
     * fabricated record, and neither said anything was wrong.
     *
     * `null` is not a smaller lie, it is the absence of one: the template can then say we
     * cannot show the record, which is `§6`'s *source down* screen — it is us, not them,
     * and whether to wait.
     *
     * ⚠ THE ORACLE PROPERTY IS PRESERVED EXACTLY, and that is why this returns ONE null
     * rather than distinguishing the paths. `§4.2`: a refusal may say what to do, never
     * what exists. A malformed id, an id belonging to another tenant and an id that does
     * not exist all return the same `null` and the template says the same sentence —
     * which is the equality `ReviewFixesTest`'s *"refuses the challenge for another
     * tenants domain, in the same words as one that does not exist"* pins one layer down.
     *
     * @return array{name: string, type: string, value: string}|null
     */
    public function challenge(string $domainId): ?array
    {
        if (! Str::isUuid($domainId)) {
            return null;
        }

        try {
            return app(TenantDomains::class)->challenge(TenantPanelAccess::tenantId(), $domainId);
        } catch (DomainRefused) {
            return null;
        }
    }

    /** How long a certificate has been pending, for §12.3's "slow" state. */
    public function pendingMinutes(?string $checkedAt): int
    {
        return $checkedAt === null ? 0 : (int) floor((time() - strtotime($checkedAt)) / 60);
    }

    public function add(): void
    {
        $tenantId = TenantPanelAccess::tenantId();

        try {
            app(TenantDomains::class)->add($tenantId, $this->hostname);
        } catch (DomainRefused $e) {
            Notification::make()
                ->title(__('studio.notifications.domain_not_added'))
                ->body($e->reason)
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $this->hostname = '';

        Notification::make()
            ->title(__('studio.notifications.domain_added'))
            ->body(__('studio.notifications.domain_added_body'))
            ->success()
            ->send();
    }

    public function advance(string $domainId): void
    {
        $tenantId = TenantPanelAccess::tenantId();

        // R-110's shape again: a non-uuid reaching a uuid column raises 22P02, so a
        // malformed id answered differently from an unknown one. Both §11 reviewers
        // found it; the refusal is now the same one a foreign id gets.
        if (! Str::isUuid($domainId)) {
            Notification::make()->title(__('studio.notifications.domain_not_on_account'))->danger()->send();

            return;
        }

        try {
            $status = app(TenantDomains::class)->advance($tenantId, $domainId);
        } catch (DomainRefused $e) {
            Notification::make()->title(__('studio.notifications.domain_not_changed'))->body($e->reason)->danger()->send();

            return;
        }

        $notification = match ($status) {
            DomainStatus::PendingDns => Notification::make()
                ->title(__('studio.notifications.could_not_verify_yet'))
                ->body($this->lastError($domainId) ?? __('studio.notifications.txt_not_found'))
                ->warning(),
            DomainStatus::DnsVerified => Notification::make()
                ->title(__('studio.notifications.dns_verified'))
                ->body(__('studio.domains.issuing_certificate'))
                ->success(),
            DomainStatus::CertificatePending => Notification::make()
                ->title(__('studio.notifications.still_issuing'))
                ->body(__('studio.notifications.still_issuing_body'))
                ->info(),
            DomainStatus::Active => Notification::make()
                ->title(__('studio.notifications.domain_live'))
                ->success(),
            default => Notification::make()->title(__('studio.notifications.no_change'))->info(),
        };

        $notification->send();
    }

    private function lastError(string $domainId): ?string
    {
        foreach ($this->domains() as $domain) {
            if ($domain->id === $domainId) {
                return $domain->lastError;
            }
        }

        return null;
    }
}
