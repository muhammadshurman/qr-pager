<?php

declare(strict_types=1);

namespace App\Features\Landing\Panel\Pages;

use App\Domain\Landing\DomainStatus;
use App\Features\Landing\DomainRefused;
use App\Features\Landing\LandingPages;
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
    protected string $view = 'studio.domains';

    protected static ?string $title = 'Domains';

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
     * @return array{name: string, type: string, value: string}
     */
    public function challenge(string $domainId): array
    {
        $empty = ['name' => '', 'type' => 'TXT', 'value' => ''];

        if (! Str::isUuid($domainId)) {
            return $empty;
        }

        try {
            return app(TenantDomains::class)->challenge(TenantPanelAccess::tenantId(), $domainId);
        } catch (DomainRefused) {
            return $empty;
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
                ->title('That domain was not added')
                ->body($e->reason)
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $this->hostname = '';

        Notification::make()
            ->title('Domain added')
            ->body('Add the TXT record shown below at your DNS provider, then choose Verify.')
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
            Notification::make()->title('That domain is not on your account')->danger()->send();

            return;
        }

        try {
            $status = app(TenantDomains::class)->advance($tenantId, $domainId);
        } catch (DomainRefused $e) {
            Notification::make()->title('That domain was not changed')->body($e->reason)->danger()->send();

            return;
        }

        $notification = match ($status) {
            DomainStatus::PendingDns => Notification::make()
                ->title('We could not verify that domain yet')
                ->body($this->lastError($domainId) ?? 'The TXT record was not found.')
                ->warning(),
            DomainStatus::DnsVerified => Notification::make()
                ->title('DNS verified')
                ->body('We are issuing a certificate — this usually takes a few minutes.')
                ->success(),
            DomainStatus::CertificatePending => Notification::make()
                ->title('Still issuing')
                ->body('Nothing to do yet. Check back in a few minutes.')
                ->info(),
            DomainStatus::Active => Notification::make()
                ->title('Your domain is live')
                ->success(),
            default => Notification::make()->title('No change')->info(),
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
