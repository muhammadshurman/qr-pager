{{--
    The footer bar's save state. `QRPAGER-48` item 2.

    ⚠ IT REPORTS WHAT THE SERVER HOLDS, AND CLAIMS NO TIME. The artboard reads *"saved as a
    draft a second ago"*, and this does not: nothing stores when the draft was last written,
    so a relative time here would be invented. Saying less and being true is the §12 posture —
    an owner who cannot trust the quiet line cannot trust the loud one either.

    ⚠ THE RESTORE CONTROL THE ARTBOARD DRAWS BESIDE THIS IS `R-307` AND IS DEFERRED, ruled by
    Muhammad on 2026-11-13. Its absence here is a decision, not an omission.
--}}
{{--
    ⚠⚠ IT READS THE SAME STATE MACHINE THE PLATE READS, AND IT USED TO READ A BLOCK COUNT.

    `§11` QA M-2. This had two arms keyed on `blocks === 0` alone and its own vocabulary
    (`studio.chrome.saved_draft` / `not_saved`), so on a page that was PUBLISHED with
    unpublished changes it said *"Saved as a draft"* while the plate two regions above said
    *"Live · edited since you last published"*. One fact, two sentences, and they disagreed
    about whether the page was a draft at all.

    The four arms and the four keys are `studio.footer.*` — the vocabulary `QRPAGER-40`'s
    builder footer already used — so the product now has ONE set of words for this state
    rather than one per place it is shown. The two `studio.chrome.*` keys this used are
    removed from both catalogues rather than left unused.
--}}
<div class="studio-chrome-footer" data-footer-bar="true">
    <span class="studio-chrome-footer__dot" aria-hidden="true"></span>

    @if ($footer['invalid'] !== null)
        <span data-footer-state="true">{{ __('studio.footer.not_publishable') }}</span>
    @elseif ($footer['blocks'] === 0)
        <span data-footer-state="true">{{ __('studio.footer.no_blocks') }}</span>
    @elseif ($footer['has_unpublished_changes'])
        <span data-footer-state="true">{{ __('studio.footer.unpublished_changes') }}</span>
    @else
        <span data-footer-state="true">{{ __('studio.footer.saved') }}</span>
    @endif
</div>
