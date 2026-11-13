<?php

declare(strict_types=1);

namespace App\Features\Landing\Panel\Pages;

use App\Domain\Landing\LandingDocumentInvalid;
use App\Features\Landing\LandingPages;
use App\Features\Landing\MediaLibrary;
use App\Features\Landing\MediaRejected;
use App\Features\Landing\Panel\HasStudioLocale;
use App\Features\Landing\Panel\KeepsPreviewInStep;
use App\Features\Landing\Panel\MediaOptions;
use App\Features\Landing\Panel\StudioForm;
use App\Features\Landing\PublishFailed;
use App\Platform\Http\TenantPanelAccess;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

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
    use KeepsPreviewInStep;
    use WithFileUploads;

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
    /**
     * One line of plain language under the title. `QRPAGER-48` item 2.
     *
     * ⚠ IT SAYS WHAT THE SCREEN IS FOR, NOT WHAT IT CONTAINS. `§12`'s bar is a reader with
     * zero technical background, and *"theme and identity"* alone tells such a reader nothing
     * about why they would open it. The artboard carries the same sentence for the same
     * reason — it is the only prose in that region.
     */
    public function getSubheading(): string
    {
        return __('studio.theme.description');
    }

    public function getTitle(): string
    {
        return __('studio.theme.title');
    }

    protected static ?string $slug = 'theme';

    protected static ?int $navigationSort = -1;

    /** @var array<string, mixed> */
    public ?array $data = [];

    /**
     * The Livewire temporary upload for the logo, before anything has looked at it.
     *
     * ⚠ THE SAME SHAPE AS `MediaLibraryPage::$upload`, AND FOR THE SAME REASON: Filament's
     * `FileUpload` would write bytes to the object store itself, with nothing passing
     * `InspectedImage` — content sniffing, the size bound and the SVG policy all skipped.
     * Muhammad's brief names that explicitly, and his ruling of 2026-11-12 names the
     * resolution: *"the artboard specifies the affordance, the brief specifies the pipe."*
     *
     * @var mixed
     */
    public $logoUpload = null;

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
        $tenantId = TenantPanelAccess::tenantId();

        /*
         * ⚠ THE **STORED** ARRANGEMENT, READ HERE AND NOT TAKEN FROM THE FORM STATE. The
         * badge on the cards says `مستخدم الآن` — *in use now* — and the honest answer to
         * that is what the saved draft names, not what the owner has just clicked. The
         * preview follows a click immediately; the document does not until Save, and the
         * badge staying put is the only thing on the screen that says so.
         */
        $stored = app(LandingPages::class)->draft($tenantId)->template->value;

        return $schema
            ->components(StudioForm::themeSchema(new MediaOptions($tenantId), $stored))
            ->statePath('data');
    }

    /**
     * A logo arrived — `QRPAGER-47` Part B item 2.
     *
     * ⚠ A LIVEWIRE HOOK, NOT A `wire:change`, WHICH IS `MediaLibraryPage`'s FINDING RATHER
     * THAN A PREFERENCE: an event handler on the element fires when the BROWSER has the file,
     * which is before Livewire has finished transferring it, so the handler would find the
     * property still null. The hook fires after the transfer by construction.
     *
     * ⚠ AND THE ASSET IS STORED IMMEDIATELY WHILE THE REFERENCE IS NOT. Bytes have to exist
     * to be referenced, so the upload lands in the tenant's library at once — where it is an
     * ordinary asset like any other. The document's `logo` member is FORM STATE until Save,
     * exactly as a block's image is, which is why the preview updates and the published page
     * does not.
     */
    public function updatedLogoUpload(): void
    {
        $tenantId = TenantPanelAccess::tenantId();

        if (! $this->logoUpload instanceof TemporaryUploadedFile) {
            Notification::make()->title(__('studio.notifications.choose_file_first'))->danger()->send();

            return;
        }

        // The same reduction the stored name gets, for the reason `MediaLibraryPage` records:
        // Filament renders a notification title through an allow-list sanitiser rather than
        // `e()`, so the displayed name and the stored name must be one value.
        $name = MediaLibrary::safeName((string) $this->logoUpload->getClientOriginalName());

        try {
            $assetId = app(MediaLibrary::class)->store($tenantId, $name, (string) $this->logoUpload->get());
        } catch (MediaRejected $e) {
            Notification::make()
                ->title(__('studio.notifications.image_refused', ['name' => $name]))
                ->body($e->reason)
                ->danger()
                ->persistent()
                ->send();

            $this->reset('logoUpload');

            return;
        }

        $this->reset('logoUpload');

        $this->data['logo'] = $assetId;

        /*
         * ⚠ THE PREVIEW IS TOLD, BECAUSE NOTHING ELSE WILL TELL IT. The debounce in
         * `preview-sync.blade.php` listens for `input` and `change` on the form wrapper; this
         * change came from a Livewire round trip and fires neither. The stated invariant's
         * second clause — *"reflected in the preview with no Save click and no page refresh"*
         * — covers the logo as much as the arrangement, and this is the line that makes it
         * true for an upload.
         */
        $this->previewChanged();

        Notification::make()->title(__('studio.notifications.image_added', ['name' => $name]))->success()->send();
    }

    /**
     * Remove the logo — the REFERENCE, never the image.
     *
     * Muhammad's ruling of 2026-11-12: *"deleting the logo removes the REFERENCE, not the
     * asset underneath."* So this clears one document member and the upload stays in the
     * media library, where deleting it is a deliberate act with its own confirmation. The
     * field's help line says so, because a word like *remove* on a screen has to say what it
     * actually removes (`§12`).
     */
    public function removeLogo(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->data['logo'] = null;

        $this->previewChanged();
    }

    /**
     * The publish action, in the page header beside the status plate. `QRPAGER-48` item 2.
     *
     * ⚠⚠ IT SAVES THIS TAB FIRST, FOR THE REASON `LandingBuilderPage::publish()` ALREADY
     * GIVES: *"a publish that used the stored draft while the form held newer values would
     * publish something the tenant is not looking at, which is worse than refusing."* The
     * theme tab holds exactly such values — a colour the owner changed and has not saved —
     * so publishing from here without saving would put the OLD theme on the live page while
     * the screen showed the new one.
     *
     * ⚠ AND IT IS NOT A SECOND IMPLEMENTATION OF PUBLISHING. `save()` is this page's own, and
     * `LandingPages::publish()` is the one publisher; what is written here is the ORDER, and
     * the order is the part that is specific to publishing from a tab that holds form state.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label(__('studio.page.publish'))
                ->action(function (): void {
                    $this->save();

                    try {
                        $published = app(LandingPages::class)->publish(TenantPanelAccess::tenantId());
                    } catch (PublishFailed $e) {
                        // The headline comes from the exception, not from a constant here:
                        // `PublishFailed` carries which side of the commit its throw site
                        // leaves, and a fixed sentence would tell the tenant their live page
                        // was untouched when it may not be.
                        Notification::make()
                            ->title(__($e->headline))
                            ->body($e->reason)
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('studio.notifications.published_to', ['hostname' => $published->hostname]))
                        ->success()
                        ->send();
                }),
        ];
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

        /*
         * ⚠ THIS LINE IS THE REPORTED BUG, AND ITS ABSENCE WAS THE WHOLE OF IT. Without
         * it the key written while the owner was on the builder tab kept winning over the
         * draft they had just saved here, so a colour change never reached the preview —
         * and appeared only when they touched the arrangement control, whose write rebuilds
         * the document from one flat state and carried the new theme in by accident.
         */
        $this->forgetUnsavedPreview($tenantId);

        Notification::make()->title(__('studio.notifications.theme_saved'))->success()->send();
    }

    /** `KeepsPreviewInStep`'s abstract, answered with this page's own form. */
    protected function studioForm(): Schema
    {
        return $this->themeForm();
    }

    /**
     * This page's own form schema, resolved by name, as `LandingBuilderPage` does.
     *
     * `$this->form` works at runtime — `InteractsWithSchemas` has a `__get` — and is
     * invisible to PHPStan, which reports it as an undefined property; `getSchema()` is the
     * real method behind that magic. It throws rather than returning null because every
     * caller is already past `canAccess()`, so a null schema would mean the page did not
     * boot. (The docblock was on `studioForm()` until the `§11` QA round's L-10 pointed out
     * that `studioForm()` does neither of those things.)
     */
    private function themeForm(): Schema
    {
        $schema = $this->getSchema('form');

        if (! $schema instanceof Schema) {
            throw new \RuntimeException('the studio theme form did not resolve');
        }

        return $schema;
    }
}
