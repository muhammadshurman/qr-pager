{{--
    The footer bar's save state. `QRPAGER-48` item 2.

    ⚠ IT REPORTS WHAT THE SERVER HOLDS, AND CLAIMS NO TIME. The artboard reads *"saved as a
    draft a second ago"*, and this does not: nothing stores when the draft was last written,
    so a relative time here would be invented. Saying less and being true is the §12 posture —
    an owner who cannot trust the quiet line cannot trust the loud one either.

    ⚠ THE RESTORE CONTROL THE ARTBOARD DRAWS BESIDE THIS IS `R-307` AND IS DEFERRED, ruled by
    Muhammad on 2026-11-13. Its absence here is a decision, not an omission.
--}}
<div class="studio-footer" data-footer-bar="true">
    @if ($footer['blocks'] === 0)
        <span class="studio-footer__dot" aria-hidden="true"></span>
        <span data-footer-state="true">{{ __('studio.chrome.not_saved') }}</span>
    @else
        <span class="studio-footer__dot" aria-hidden="true"></span>
        <span data-footer-state="true">{{ __('studio.chrome.saved_draft') }}</span>
    @endif
</div>
