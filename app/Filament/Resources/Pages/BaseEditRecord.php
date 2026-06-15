<?php

namespace App\Filament\Resources\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * Base Edit page for all resources. After saving, redirects to the record's
 * detail (view) page when the resource has one, and emits a save notification
 * that always carries a description (body), not just a title.
 */
abstract class BaseEditRecord extends EditRecord
{
    protected function getRedirectUrl(): string
    {
        $resource = static::getResource();

        if (array_key_exists('view', $resource::getPages())) {
            return $resource::getUrl('view', ['record' => $this->getRecord()]);
        }

        return $resource::getUrl('index');
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Perubahan disimpan')
            ->body('Data telah berhasil diperbarui.');
    }
}
