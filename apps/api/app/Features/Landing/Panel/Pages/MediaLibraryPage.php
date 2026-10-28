<?php

declare(strict_types=1);

namespace App\Features\Landing\Panel\Pages;

use App\Features\Landing\MediaLibrary;
use App\Features\Landing\MediaRejected;
use App\Platform\Http\TenantPanelAccess;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * `/studio/media` — the tenant's image library (§12.2).
 *
 * ⚠ IT DOES NOT USE Filament's FileUpload COMPONENT, and that is the whole point of this
 * page. `FileUpload` stores the file to a disk itself, which would put bytes in the
 * object store without ever passing `InspectedImage` -- so content sniffing, the size
 * bound, the SVG policy and the key layout would all be bypassed by the one surface that
 * actually receives uploads. §3 row 7 says an upload is validated by real content type
 * and size; a component that writes before anything reads the bytes cannot satisfy that.
 *
 * So the upload is a Livewire temporary file, and `MediaLibrary::store()` is the only
 * thing that ever writes to the library.
 */
class MediaLibraryPage extends Page
{
    use WithFileUploads;

    protected string $view = 'studio.media';

    protected static ?string $title = 'Media';

    protected static ?string $slug = 'media';

    protected static ?int $navigationSort = -1;

    /** @var mixed the Livewire temporary upload, before anything has looked at it */
    public $upload = null;

    public static function canAccess(): bool
    {
        return TenantPanelAccess::allows();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return list<object> */
    public function assets(): array
    {
        return app(MediaLibrary::class)->all(TenantPanelAccess::tenantId());
    }

    public function isFull(): bool
    {
        return count($this->assets()) >= MediaLibrary::MAX_ASSETS;
    }

    public function store(): void
    {
        $tenantId = TenantPanelAccess::tenantId();

        if (! $this->upload instanceof TemporaryUploadedFile) {
            Notification::make()->title('Choose a file first')->danger()->send();

            return;
        }

        $name = $this->upload->getClientOriginalName();

        try {
            // The BYTES, read once and handed to the one boundary. The client's
            // filename comes along only to be recorded.
            app(MediaLibrary::class)->store($tenantId, $name, (string) $this->upload->get());
        } catch (MediaRejected $e) {
            // §12.2's "rejected" state: "{name} was refused: {the real reason}". The
            // reason names the DETECTED type, which libmagic produced from a bounded
            // vocabulary -- never a byte of the file.
            Notification::make()
                ->title($name.' was refused')
                ->body($e->reason)
                ->danger()
                ->persistent()
                ->send();

            $this->reset('upload');

            return;
        }

        $this->reset('upload');

        Notification::make()->title($name.' was added')->success()->send();
    }

    public function delete(string $assetId): void
    {
        try {
            app(MediaLibrary::class)->delete(TenantPanelAccess::tenantId(), $assetId);
        } catch (MediaRejected $e) {
            // §12.2's "in use" state names the block, because "this image is in use"
            // leaves the tenant hunting for where.
            Notification::make()
                ->title('That image was not deleted')
                ->body($e->reason)
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()->title('Image deleted')->success()->send();
    }
}
