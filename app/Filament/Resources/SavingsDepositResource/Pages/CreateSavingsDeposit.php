<?php

namespace App\Filament\Resources\SavingsDepositResource\Pages;

use App\Filament\Resources\Pages\BaseCreateRecord;
use App\Filament\Resources\SavingsDepositResource;
use App\Models\SavingsDeposit;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

class CreateSavingsDeposit extends BaseCreateRecord
{
    protected static string $resource = SavingsDepositResource::class;

    private const COMPARED_FIELDS = ['member_id', 'savings_type'];

    protected bool $idempotentHit = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['recorded_by'] = auth()->id();

        return SavingsDepositResource::enforceAmountRules($data);
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return SavingsDeposit::create($data);
        } catch (UniqueConstraintViolationException $e) {
            $existing = SavingsDeposit::query()
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
                ->title('Transaksi sudah tercatat')
                ->body('Setoran ini sudah pernah disimpan sebelumnya — tidak ada duplikat yang dibuat.');
        }

        return parent::getCreatedNotification();
    }

    private function payloadDiffers(SavingsDeposit $existing, array $data): bool
    {
        foreach (self::COMPARED_FIELDS as $field) {
            if ((string) $existing->{$field} !== (string) ($data[$field] ?? '')) {
                return true;
            }
        }

        return bccomp((string) $existing->amount, (string) ($data['amount'] ?? '0'), 2) !== 0;
    }
}
