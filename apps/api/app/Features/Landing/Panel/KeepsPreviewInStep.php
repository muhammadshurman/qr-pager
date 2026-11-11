<?php

declare(strict_types=1);

namespace App\Features\Landing\Panel;

use App\Domain\Landing\LandingDocumentInvalid;
use App\Features\Landing\LandingPages;
use App\Features\Landing\UnsavedPreview;
use App\Platform\Http\TenantPanelAccess;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The studio preview contract, in one place — `QRPAGER-45`, after the delivery was rejected.
 *
 * ================================================================================
 * ⚠ WHY THIS IS A TRAIT AND NOT TWO COPIES, WHICH IS THE WHOLE POINT OF THE FIX
 * ================================================================================
 *
 * `QRPAGER-45` shipped `previewChanged()` and the `forget()` calls on
 * `LandingBuilderPage` alone, because the invariant it was built against named only
 * builder-page controls: *"changing the arrangement, or editing a block field"*. The studio
 * has a SECOND page that writes the same document, and it was in neither the invariant nor
 * the tests.
 *
 * MEASURED, and this is the bug the owner reported: a colour changed on `الثيم` and SAVED
 * did not reach the preview. `ThemePage::save()` never called `forget()`, so the key written
 * while the owner was on the builder tab kept winning over the freshly saved draft — the
 * pane showed something that CONTRADICTED the database, with no way to clear it but waiting
 * out the TTL or clicking the arrangement control, whose write rebuilds the whole document
 * from one flat state and so carried the new theme in by accident.
 *
 * The second clause of the amended invariant is the one that was violated and the one
 * nothing tested: **after a save on any studio page, the preview and the stored draft
 * agree.** A per-page fix is the shape that just failed, so the contract lives here and
 * `StudioPreviewContractTest` asserts that every page which can write the draft implements
 * it.
 *
 * ================================================================================
 * THE CONTRACT
 * ================================================================================
 *
 *   write on a settled change   `previewChanged()`, from the view's debounce
 *   forget on save and publish  `forgetUnsavedPreview()`, so the saved state wins
 *
 * Both halves dispatch `studio-preview-updated`; the reload itself is the view's, which is
 * what keeps `partials/preview.blade.php` free of JavaScript.
 */
trait KeepsPreviewInStep
{
    /**
     * This page's own form, whose state is what the owner is looking at.
     *
     * Each page resolves its schema by its own name — `getSchema('form')` behind a method
     * that throws rather than returning null, because every caller is already past
     * `canAccess()` and a null schema would mean the page did not boot.
     */
    abstract protected function studioForm(): Schema;

    /**
     * Write what the owner is looking at, so the preview can render it.
     *
     * ⚠ `merged()` AND NOT `getState()`, FOR `StudioDraftMergeTest`'s REASON. `toDocument()`
     * builds the WHOLE document from one flat state, so previewing from one tab's state
     * alone would render a page whose other tab is empty — a save from `الثيم` writing
     * `blocks` empty, a save from `الصفحة` writing `theme` empty. That trap is why
     * `merged()` exists, and it applies to every page that reaches this trait.
     *
     * ⚠ IT RE-ASKS `canAccess()` LIKE EVERY OTHER ACTION IN THIS PANEL. `POST
     * /livewire-<hash>/update` runs neither `SetUpPanel` nor Filament's `Authenticate`
     * (Task 6 security M-1), and this one is reached on a TIMER rather than by a click.
     *
     * ⚠ AND AN INVALID STATE IS SILENCE. Mid-edit invalid is the NORMAL condition behind a
     * keystroke, not an error: nothing is written, nothing is raised, and — deliberately —
     * nothing is CLEARED, so the last state that did validate stays on screen instead of
     * the preview jumping backwards to the last save. Both ways of being invalid are
     * caught: Filament's `ValidationException` from `getState()` fires before the document
     * gate ever sees the state.
     */
    public function previewChanged(): void
    {
        abort_unless(static::canAccess(), 403);

        $tenantId = TenantPanelAccess::tenantId();

        try {
            $document = app(LandingPages::class)->validated(
                $tenantId,
                StudioForm::merged($tenantId, $this->studioForm()->getState()),
            );
        } catch (ValidationException|LandingDocumentInvalid) {
            return;
        }

        app(UnsavedPreview::class)->put($tenantId, session()->getId(), $document->toArray());

        $this->dispatch('studio-preview-updated');
    }

    /**
     * Drop the unsaved state, because what is stored is now what the owner meant.
     *
     * ⚠ CALLED FROM EVERY SAVE AND EVERY PUBLISH, ON EVERY PAGE. Leaving it out of ONE page
     * is the reported bug: the key and the draft then disagree and the preview shows the
     * key. The dispatch is as load-bearing as the forget — removing the key means the NEXT
     * render shows the stored draft, and without an event nothing asks for a render, so the
     * frame keeps what it already had.
     */
    protected function forgetUnsavedPreview(string $tenantId): void
    {
        app(UnsavedPreview::class)->forget($tenantId, session()->getId());

        $this->dispatch('studio-preview-updated');
    }
}
