<?php

namespace App\Filament\Resources\ShoppingTransactionResource\Pages;

use App\Actions\RecordShoppingUsage;
use App\Exceptions\CannotSpendShopping;
use App\Filament\Resources\Pages\BaseCreateRecord;
use App\Filament\Resources\ShoppingTransactionResource;
use App\Models\ShoppingTransaction;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

class CreateShoppingTransaction extends BaseCreateRecord
{
    protected static string $resource = ShoppingTransactionResource::class;

    private const COMPARED_FIELDS = ['member_id'];

    protected bool $idempotentHit = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['recorded_by'] = auth()->id();

        $data['source'] = 'manual';

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(RecordShoppingUsage::class)($data);
        } catch (CannotSpendShopping $e) {
            Notification::make()
                ->danger()
                ->title('Saldo tidak mencukupi')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            throw new Halt;
        } catch (UniqueConstraintViolationException $e) {
            $existing = ShoppingTransaction::query()
                ->where('idempotency_key', $data['idempotency_key'] ?? '')
                ->first();

            if ($existing === null) {
                throw $e;
            }

            $this->idempotentHit = true;

            if ($this->payloadDiffers($existing, $data)) {
                Notification::make()
                    ->warning()
                    ->title('Submission duplikat dengan data berbeda')
                    ->body('Permintaan ini memakai kunci idempotensi yang sama tetapi nilainya berbeda dari transaksi yang sudah tersimpan. Muat ulang form lalu periksa kembali.')
                    ->persistent()
                    ->send();

                throw new Halt;
            }

            return $existing;
        }
    }

    protected function getCreatedNotification(): ?Notification
    {
        if ($this->idempotentHit) {
            return Notification::make()
                ->success()
                ->title('Pemakaian sudah tercatat')
                ->body('Transaksi ini sudah pernah disimpan sebelumnya — tidak ada duplikat yang dibuat.');
        }

        return parent::getCreatedNotification();
    }

    private function payloadDiffers(ShoppingTransaction $existing, array $data): bool
    {
        foreach (self::COMPARED_FIELDS as $field) {
            if ((string) $existing->{$field} !== (string) ($data[$field] ?? '')) {
                return true;
            }
        }

        return bccomp((string) $existing->amount, (string) ($data['amount'] ?? '0'), 2) !== 0;
    }
}
