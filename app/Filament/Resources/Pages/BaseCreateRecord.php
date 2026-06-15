<?php

namespace App\Filament\Resources\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

/**
 * Base Create page for all resources. Redirects to the index after creating
 * and emits a notification that always carries a description (body), not just
 * a title.
 */
abstract class BaseCreateRecord extends CreateRecord
{
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Data berhasil dibuat')
            ->body('Data baru telah disimpan ke sistem.');
    }
}
