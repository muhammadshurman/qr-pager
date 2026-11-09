<?php

declare(strict_types=1);

namespace App\Features\Landing\Panel\Pages;

use App\Domain\Landing\LandingDocumentInvalid;
use App\Features\Landing\LandingPages;
use App\Features\Landing\Panel\HasStudioLocale;
use App\Features\Landing\Panel\MediaOptions;
use App\Features\Landing\Panel\StudioForm;
use App\Platform\Http\TenantPanelAccess;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

/**
 * `/studio/theme` — the `الثيم` tab: the theme, and search-and-sharing with it.
 *
 * ================================================================================
 * WHY THIS PAGE EXISTS, AND WHY IT IS NOT A SECTION ANY MORE
 * ================================================================================
 *
 * These two were collapsed `Section`s at the bottom of the landing-page form.
 * `QRPAGER-40` moves them here, and Muhammad's ticket is explicit that it is **a
 * deliberate move, not a side effect**: the landing-page form is for the page's CONTENT,
 * and the theme is not content. Search-and-sharing goes with it because it is the same
 * kind of thing — how the page is PRESENTED rather than what is on it.
 *
 * ================================================================================
 * ⚠ THE SAVE MERGES, AND THAT IS NOT A STYLE CHOICE
 * ================================================================================
 *
 * `StudioForm::toDocument()` builds the whole document — blocks, template, theme, seo —
 * from one flat state array. This page's form carries only theme and seo, so handing its
 * state straight to `toDocument()` would write a document with **no blocks**: a tenant's
 * whole page deleted by saving a colour. The landing-page tab has the mirror of the same
 * defect, which is how it was found — by reading `toDocument()` before either tab existed.
 *
 * `StudioForm::merged()` lays this page's state over the stored draft, so the members this
 * form does not carry survive because they are still in the array. `StudioDraftMergeTest`
 * drives it in both directions, per tab, and Muhammad's ruling is that neither page lands
 * without it.
 *
 * The tenant is never a Livewire property, for `LandingBuilderPage`'s reason: it is
 * re-resolved from the authenticated session on every call, including inside every action.
 */
class ThemePage extends Page
{
    use HasStudioLocale;

    protected string $view = 'studio.theme';

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
        return __('studio.theme.title');
    }

    protected static ?string $slug = 'theme';

    protected static ?int $navigationSort = -1;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return TenantPanelAccess::allows();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->themeForm()->fill(StudioForm::fromDocument(
            app(LandingPages::class)->draft(TenantPanelAccess::tenantId())->toArray(),
        ));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(StudioForm::themeSchema(new MediaOptions(TenantPanelAccess::tenantId())))
            ->statePath('data');
    }

    public function save(): void
    {
        $tenantId = TenantPanelAccess::tenantId();

        try {
            app(LandingPages::class)->saveDraft($tenantId, StudioForm::merged($tenantId, $this->themeForm()->getState()));
        } catch (LandingDocumentInvalid $e) {
            // §12.1's invalid state: the field is named and the VALUE is not.
            Notification::make()
                ->title(__('studio.notifications.cannot_publish_field', ['field' => $e->field]))
                ->body($e->reason)
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()->title(__('studio.notifications.theme_saved'))->success()->send();
    }

    /** This page's own form schema, resolved by name, as `LandingBuilderPage` does. */
    private function themeForm(): Schema
    {
        $schema = $this->getSchema('form');

        if (! $schema instanceof Schema) {
            throw new \RuntimeException('the studio theme form did not resolve');
        }

        return $schema;
    }
}
