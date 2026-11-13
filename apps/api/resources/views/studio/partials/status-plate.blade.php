{{--
    The publish state, and the way to change it. `QRPAGER-48` item 2.

    ⚠ IT RENDERS ON EVERY TAB, because it is the frame rather than the page: an owner editing
    colours needs to know their customers are still seeing last week's page just as much as an
    owner editing blocks does.

    ⚠⚠ AND IT COSTS A SECOND SCOPED READ PER RENDER, which `§6` question 5 originally claimed
    it would not. `state()` and `draft()` each open their own `withTenant`, so this is a real
    cost and `PLAN.md` carries the corrected figure rather than the claim.
--}}
@php($state = $plate)
<div class="studio-plate studio-plate--chrome" role="status" data-status-plate="true">
    @if ($state['invalid'] !== null)
        <span class="studio-plate__dot studio-plate__dot--alert" aria-hidden="true"></span>
        <span data-plate-word="true">{{ __('studio.page.not_publishable') }}</span>
    @elseif ($state['blocks'] === 0)
        <span class="studio-plate__dot studio-plate__dot--quiet" aria-hidden="true"></span>
        <span data-plate-word="true">{{ __('studio.page.empty') }}</span>
    @elseif ($state['status'] === \App\Domain\Landing\LandingPageStatus::Draft)
        <span class="studio-plate__dot studio-plate__dot--quiet" aria-hidden="true"></span>
        <span data-plate-word="true">{{ __('studio.page.draft') }}</span>
    @elseif ($state['has_unpublished_changes'])
        {{-- Published AND edited: two facts, and the second is the one that needs an action. --}}
        <span class="studio-plate__dot studio-plate__dot--live" aria-hidden="true"></span>
        <span data-plate-word="true">{{ __('studio.chrome.published') }}</span>
        <span class="studio-plate__rule" aria-hidden="true"></span>
        <span class="studio-plate__note">{{ __('studio.chrome.unpublished_changes') }}</span>
    @else
        <span class="studio-plate__dot studio-plate__dot--live" aria-hidden="true"></span>
        <span data-plate-word="true">{{ __('studio.chrome.published') }}</span>
    @endif
</div>
