{{--
    §12.2 — /studio/media, the media library.

    The five states: empty, uploading, rejected, full, and in use. "Uploading" and
    "rejected" are Livewire states rather than resting ones -- the progress is
    wire:loading and the refusal is a notification carrying the real reason.

    THE FILE INPUT IS A PLAIN <input type="file">, deliberately. Filament's FileUpload
    component writes to a disk itself, which would put bytes in the object store without
    them ever passing InspectedImage -- see MediaLibraryPage's docblock.
--}}
<x-filament-panels::page>
    @include('studio.partials.tabs')

    <div class="studio-layout">
        <div class="studio-layout__form">
        @php($assets = $this->assets())
        @php($full = $this->isFull())

        {{-- ⚠ NO `wire:submit` AND NO SUBMIT BUTTON — `QRPAGER-41` part B item 4. Choosing a
             file uploads it; `MediaLibraryPage::updatedUpload()` carries the reasoning. The
             element stays a `<form>` so the input keeps a form owner and the drop zone's
             `<label for>` keeps working, and so a keyboard Enter on the file input does
             nothing surprising rather than submitting a form with no action. --}}
        <form class="studio-plate studio-field">
            <h2>{{ __('studio.media.add_heading') }}</h2>

            {{--
                THE DROP ZONE IS A LABEL, NOT A DIV WITH A CLICK HANDLER. A <label for> already
                opens the picker on click and on Enter from the control it labels, so the zone
                needs no key handler of its own and cannot become a mouse-only target -- §12's
                "keyboard reachable" without a second code path to keep honest.

                THE INPUT IS STILL A PLAIN <input type="file">, which is the whole point of this
                page (see MediaLibraryPage's docblock): Filament's FileUpload would write bytes to
                the object store without passing InspectedImage. It is `sr-only peer` rather than
                hidden: `hidden` and `display:none` remove it from the tab order, while sr-only
                keeps it focusable and `peer-focus-visible:` puts the ring on the zone, so the
                focus indicator is visible where the eye is -- §12's "visible focus ring".
            --}}
            <div
                x-data="{ over: false }"
                x-on:dragover.prevent="over = true"
                x-on:dragleave.prevent="over = false"
                x-on:drop.prevent="
                    over = false;
                    if (!$refs.input.disabled && $event.dataTransfer.files.length > 0) {
                        $refs.input.files = $event.dataTransfer.files;
                        $refs.input.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                "
            >
                <input x-ref="input" id="media-upload" type="file" wire:model="upload" @disabled($full)
                       accept="image/png,image/jpeg,image/webp,image/avif,image/svg+xml"
                       aria-describedby="media-upload-help"
                       class="sr-only peer">

                <label for="media-upload"
                       data-drop-zone="true"
                       x-bind:data-over="over ? 'true' : 'false'"
                       class="flex flex-col items-center justify-center gap-8 p-32 text-center
                              border-2 border-dashed rounded-surface cursor-pointer
                              border-gray-400 dark:border-gray-600
                              peer-focus-visible:ring-2 peer-focus-visible:ring-offset-2
                              peer-disabled:cursor-not-allowed peer-disabled:opacity-50"
                       x-bind:class="over ? 'border-primary-600 bg-primary-50 dark:bg-primary-950' : ''">
                    <span class="text-base font-medium">{{ __('studio.media.drag_here') }}</span>
                    <span class="text-sm">{{ __('studio.media.or') }}</span>
                    <span data-choose="true"
                          class="fi-btn fi-btn-size-md fi-color-primary px-16 py-8 rounded-control
                                 inline-flex items-center justify-center">
                        {{ __('studio.media.choose') }}
                    </span>
                </label>
            </div>

            <p id="media-upload-help" class="text-sm">
                @if ($full)
                    {{ __('studio.media.full', ['max' => \App\Features\Landing\MediaLibrary::MAX_ASSETS]) }}
                @else
                    {{ __('studio.media.formats') }}
                    {{ __('studio.media.checked_by_contents') }}
                @endif
            </p>

            {{-- ⚠ PROGRESS WHILE IT RUNS, AND IT TARGETS BOTH HALVES — `QRPAGER-41` part B
                 item 4. `wire:target="upload"` covers the transfer; `store` covers the
                 inspection and the write that follow it, which is the part that takes the
                 time on a 5 MB file. Targeting only the first would have shown the owner a
                 line that vanished while the page was still working. --}}
            <p wire:loading wire:target="upload, store" role="status" aria-live="polite" class="text-sm">
                {{ __('studio.media.uploading') }}
            </p>

            @error('upload')
                <p role="alert" class="text-sm text-danger-700 dark:text-danger-200">{{ $message }}</p>
            @enderror
        </form>

        <div class="fi-section p-16 space-y-12">
            <h2 class="font-semibold">
                {{ __('studio.media.library_heading', [
                    'count' => count($assets),
                    'max' => \App\Features\Landing\MediaLibrary::MAX_ASSETS,
                ]) }}
            </h2>

            @if ($assets === [])
                <p>{{ __('studio.media.empty') }}</p>
            @else
                {{--
                    A GRID OF THUMBNAILS, AND IT CARRIES EVERY COLUMN THE TABLE CARRIED: name,
                    type, size, dimensions and delete. The owner could not find where to upload
                    and could not tell one row from another -- a filename is not how anyone
                    recognises their own photograph.

                    THE THUMBNAIL IS THE PANEL'S OWN ASSET ROUTE, `filament.studio.preview.asset`,
                    which already serves a tenant's bytes under the panel's authorisation. No new
                    surface, no public URL, and nothing here reaches the object store directly.

                    `alt=""` IS DELIBERATE AND IS NOT A MISSING LABEL: the filename sits beside
                    the image as visible text, so a non-empty alt would make a screen reader say
                    it twice. The image is decorative BECAUSE the caption is not.
                --}}
                <ul role="list" class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-16">
                    @foreach ($assets as $asset)
                        <li data-asset="{{ $asset->id }}" class="fi-section p-12 space-y-8">
                            <img src="{{ route('filament.studio.preview.asset', ['asset' => $asset->id]) }}"
                                 alt=""
                                 loading="lazy"
                                 class="block w-full aspect-square object-contain rounded-surface bg-gray-50 dark:bg-gray-900">

                            <p class="text-sm font-medium break-words">{{ $asset->originalName }}</p>

                            <p class="text-sm">
                                {{ $asset->contentType }} ·
                                {{ number_format($asset->byteSize / 1024, 1) }} {{ __('studio.media.kilobytes') }} ·
                                {{ $asset->width !== null ? $asset->width.'x'.$asset->height : __('studio.media.not_measured') }}
                            </p>

                            <button type="button" wire:click="delete('{{ $asset->id }}')"
                                    data-media-delete="true"
                                    class="underline inline-flex items-center">
                                {{ __('studio.media.delete') }}<span class="sr-only"> {{ $asset->originalName }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
        </div>

        @include('studio.partials.preview')
    </div>
</x-filament-panels::page>
