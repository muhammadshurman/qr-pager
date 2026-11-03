<?php

declare(strict_types=1);

namespace App\Features\Landing\Panel\Pages;

use App\Domain\Landing\LandingDocumentInvalid;
use App\Domain\Landing\LandingPageStatus;
use App\Domain\Tenancy\TenantLanguage;
use App\Features\Landing\LandingPages;
use App\Features\Landing\LanguageNotChanged;
use App\Features\Landing\Panel\MediaOptions;
use App\Features\Landing\Panel\StudioForm;
use App\Features\Landing\PublishFailed;
use App\Features\Landing\TenantPageLanguage;
use App\Platform\Http\TenantPanelAccess;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

/**
 * `/studio` — the landing page builder (§12.1).
 *
 * THE TENANT IS NEVER A LIVEWIRE PROPERTY. `TenantPanelAccess::tenantId()` re-resolves it from
 * the authenticated session on every call, including inside every action. A tenant id
 * held as a property would be part of the snapshot the client round-trips, which is the
 * ambient-parameter shape `ActiveTenant`'s docblock refuses in the strongest terms this
 * project has written down.
 *
 * `canAccess()` is overridden, and every action re-asks. Task 6's security M-1 measured
 * why: a custom page's default `canAccess()` allows every authenticated panel user, and
 * `POST /livewire-<hash>/update` runs neither `SetUpPanel` nor Filament's `Authenticate`.
 */
class LandingBuilderPage extends Page
{
    protected string $view = 'studio.builder';

    protected static ?string $title = 'Landing page';

    protected static ?string $slug = '/';

    protected static ?int $navigationSort = -2;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return TenantPanelAccess::allows();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->landingForm()->fill(StudioForm::fromDocument(
            app(LandingPages::class)->draft(TenantPanelAccess::tenantId())->toArray(),
        ));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(StudioForm::schema(new MediaOptions(TenantPanelAccess::tenantId())))
            ->statePath('data');
    }

    /**
     * This page's own form schema, resolved by name.
     *
     * `$this->form` works at runtime -- InteractsWithSchemas has a `__get` -- and is
     * invisible to PHPStan, which reports it as an undefined property. getSchema() is the
     * real method behind that magic, so this is the same object reached by its own name.
     * It throws rather than returning null, because every caller here is already past
     * canAccess() and a null schema would mean the page did not boot.
     */
    private function landingForm(): Schema
    {
        $schema = $this->getSchema('form');

        if (! $schema instanceof Schema) {
            throw new \RuntimeException('the studio builder form did not resolve');
        }

        return $schema;
    }

    /**
     * §12.1's state table, for the view.
     *
     * @return array{status: LandingPageStatus, hostname: string, published_hostname: ?string, published_at: ?string, has_unpublished_changes: bool, blocks: int, invalid: ?string}
     */
    public function state(): array
    {
        return app(LandingPages::class)->state(TenantPanelAccess::tenantId());
    }

    /**
     * ================================================================================
     * THE PAGE LANGUAGE — `QRPAGER-30`, and it is NOT part of the document
     * ================================================================================
     *
     * `tenants.language` is a column, and `dir` is derived from it and never stored
     * (Muhammad's ruling of 2026-11-03). It is read here rather than held as a property for
     * the same reason the tenant id is not a property: a value in the Livewire snapshot is a
     * value the client round-trips and can return changed.
     *
     * The CONTROL is a pair of buttons in the view, one per case, and never a text field --
     * `QRPAGER-30 §3` says *"a two-option choice and never a free-text field"*. The set of
     * cases is read from the enum, so a third launch language is one PHP case and a migration
     * rather than an edit here.
     */
    public function pageLanguage(): TenantLanguage
    {
        return app(TenantPageLanguage::class)->current(TenantPanelAccess::tenantId());
    }

    /** @return list<TenantLanguage> */
    public function pageLanguages(): array
    {
        return TenantLanguage::cases();
    }

    public function setPageLanguage(string $language): void
    {
        // Re-asked, like every other action on this page: `POST /livewire-<hash>/update`
        // runs neither `SetUpPanel` nor Filament's `Authenticate` (Task 6 security M-1).
        abort_unless(static::canAccess(), 403);

        $chosen = TenantLanguage::tryFrom($language);

        if ($chosen === null) {
            /*
             * ⚠ `tryFrom` AND A REFUSAL, NOT `from`. The argument arrives from the client, so
             * an unexpected value is an untrusted input rather than a programming error: a
             * `ValueError` from `from()` would be a 500 carrying a class name. The column
             * would refuse it too (`22P02`) -- this is the belt to that brace, and
             * `§4.2`'s rule keeps the message free of what was sent.
             */
            Notification::make()->title('That is not one of the available languages')->danger()->send();

            return;
        }

        try {
            app(TenantPageLanguage::class)->set(TenantPanelAccess::tenantId(), $chosen);
        } catch (LanguageNotChanged $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        /*
         * ⚠ IT SAYS THE PAGE MUST BE PUBLISHED AGAIN, because it must. `§6.6`'s published
         * bytes are immutable and the pointer only moves on a republish, so a restaurant that
         * changed this and walked away would see no change on its live page and would have no
         * way to know why. `§12`'s zero-technical-background bar makes that sentence part of
         * the feature rather than a nicety.
         */
        Notification::make()
            ->title('Language saved')
            ->body('Publish again to apply it to your live page.')
            ->success()
            ->send();
    }

    public function save(): void
    {
        $tenantId = TenantPanelAccess::tenantId();

        try {
            app(LandingPages::class)->saveDraft($tenantId, StudioForm::toDocument($this->landingForm()->getState()));
        } catch (LandingDocumentInvalid $e) {
            // §12.1's "invalid" state: "{Field} can't be published: {reason}". The field
            // is named and the VALUE is not -- see LandingDocumentInvalid for why.
            Notification::make()
                ->title($e->field." can't be published")
                ->body($e->reason)
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()->title('Draft saved')->success()->send();
    }

    public function publish(): void
    {
        $tenantId = TenantPanelAccess::tenantId();

        // SAVE FIRST. A publish that used the stored draft while the form held newer
        // values would publish something the tenant is not looking at, which is worse
        // than refusing. If the save fails its notification is already on screen.
        try {
            app(LandingPages::class)->saveDraft($tenantId, StudioForm::toDocument($this->landingForm()->getState()));
        } catch (LandingDocumentInvalid $e) {
            Notification::make()
                ->title($e->field." can't be published")
                ->body($e->reason)
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        try {
            $published = app(LandingPages::class)->publish($tenantId);
        } catch (PublishFailed $e) {
            // ⚠ THE HEADLINE COMES FROM THE EXCEPTION, AND IT USED TO BE A CONSTANT HERE.
            // `SR-4` put the row's commit BEFORE every object write, so "your live page is
            // unchanged" stopped being true of every failure on that path -- after the
            // pointer write it is false outright. A fixed sentence here would tell the
            // tenant their page was untouched while the panel beside it said "Published,
            // no unpublished changes". PublishFailed carries which of the two states its
            // throw site leaves; the third scoped re-review found this one.
            Notification::make()
                ->title($e->headline)
                ->body($e->reason)
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title('Published to '.$published->hostname)
            ->success()
            ->send();
    }

    public function unpublish(): void
    {
        // ⚠ IT CATCHES, AND IT DID NOT. `unpublish()` raises `PublishFailed` when a
        // sweep answers false -- the s3 disk is `'throw' => false`, so a store that is
        // down returns false rather than raising -- and this control is rendered
        // UNCONDITIONALLY, for every tenant in every state, because it is the only verb
        // that can take down a page the row has lost track of. Uncaught, a store blip
        // turned the one control whose job is to make a page unreachable into a Livewire
        // 500 instead of §12.1's named refusal. The third scoped re-review found it.
        try {
            app(LandingPages::class)->unpublish(TenantPanelAccess::tenantId());
        } catch (PublishFailed $e) {
            Notification::make()
                ->title('Take-down failed — your page is still public')
                ->body($e->reason)
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()->title('Your page is no longer public')->success()->send();
    }
}
