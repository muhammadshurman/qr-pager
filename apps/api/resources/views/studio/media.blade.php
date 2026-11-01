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
    @php($assets = $this->assets())
    @php($full = $this->isFull())

    <form wire:submit="store" class="fi-section p-16 space-y-12">
        <h2 class="text-base font-semibold">Add an image</h2>

        <label for="media-upload" class="block text-sm font-medium">
            Choose an image
        </label>

        <input id="media-upload" type="file" wire:model="upload" @disabled($full)
               accept="image/png,image/jpeg,image/webp,image/avif,image/svg+xml"
               aria-describedby="media-upload-help"
               class="block w-full text-sm">

        <p id="media-upload-help" class="text-sm">
            @if ($full)
                You have reached {{ \App\Features\Landing\MediaLibrary::MAX_ASSETS }} images. Delete one to add another.
            @else
                PNG, JPEG, WebP, AVIF or script-free SVG, up to 5 MB.
                An image is checked by its contents, not by its name.
            @endif
        </p>

        <p wire:loading wire:target="upload" role="status" aria-live="polite" class="text-sm">
            Uploading…
        </p>

        @error('upload')
            <p role="alert" class="text-sm text-danger-700 dark:text-danger-400">{{ $message }}</p>
        @enderror

        <button type="submit" @disabled($full)
                class="fi-btn fi-btn-size-md fi-color-primary px-16 py-8 rounded-lg disabled:opacity-50">
            Upload
        </button>
    </form>

    <div class="fi-section p-16 space-y-12">
        <h2 class="text-base font-semibold">
            Your images ({{ count($assets) }} of {{ \App\Features\Landing\MediaLibrary::MAX_ASSETS }})
        </h2>

        @if ($assets === [])
            <p>No images yet. PNG, JPEG, WebP, AVIF or script-free SVG, up to 5 MB.</p>
        @else
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Images in your library</caption>
                <thead>
                    <tr>
                        <th scope="col" class="py-8">Name</th>
                        <th scope="col" class="py-8">Type</th>
                        <th scope="col" class="py-8">Size</th>
                        <th scope="col" class="py-8">Dimensions</th>
                        <th scope="col" class="py-8"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($assets as $asset)
                        <tr>
                            <th scope="row" class="py-8 font-normal">{{ $asset->originalName }}</th>
                            <td class="py-8">{{ $asset->contentType }}</td>
                            <td class="py-8">{{ number_format($asset->byteSize / 1024, 1) }} KB</td>
                            <td class="py-8">
                                {{ $asset->width !== null ? $asset->width.'x'.$asset->height : 'not measured' }}
                            </td>
                            <td class="py-8">
                                <button type="button" wire:click="delete('{{ $asset->id }}')"
                                        class="underline">
                                    Delete<span class="sr-only"> {{ $asset->originalName }}</span>
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</x-filament-panels::page>
