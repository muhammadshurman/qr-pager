<?php

declare(strict_types=1);

namespace App\Features\Provisioning\Panel\Resources\PlatformAuditResource\Pages;

use App\Features\Provisioning\Panel\Resources\PlatformAuditResource;
use Filament\Resources\Pages\ListRecords;

/** The audit viewer. No header actions: there is nothing to do here but read. */
class ListPlatformAudit extends ListRecords
{
    protected static string $resource = PlatformAuditResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
