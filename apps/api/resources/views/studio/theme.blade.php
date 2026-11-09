{{--
    §12 — /studio/theme, the `الثيم` tab.

    Theme and search-and-sharing, moved out of the landing-page form at QRPAGER-40
    because that form is for the page's CONTENT and these are not. The page is a form
    and a save; everything else on this tab -- the tab bar above it and the preview
    pane beside it -- is the panel's own chrome.
--}}
<x-filament-panels::page>
    @include('studio.partials.tabs')

    <div class="studio-layout">
        <div class="studio-layout__form">
        <form wire:submit="save" class="space-y-24">
            {{ $this->form }}

            <div class="flex flex-wrap gap-12">
                <button type="submit" class="fi-btn fi-btn-size-md fi-color-primary px-16 py-8 rounded-lg">
                    {{ __('studio.theme.save') }}
                </button>
            </div>
        </form>
        </div>

        @include('studio.partials.preview')
    </div>
</x-filament-panels::page>
