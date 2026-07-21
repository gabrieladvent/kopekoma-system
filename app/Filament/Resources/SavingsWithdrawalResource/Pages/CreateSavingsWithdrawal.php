<?php

namespace App\Filament\Resources\SavingsWithdrawalResource\Pages;

use App\Exceptions\CannotProcessWithdrawal;
use App\Filament\Resources\Pages\BaseCreateRecord;
use App\Filament\Resources\SavingsWithdrawalResource;
use App\Models\SavingsWithdrawal;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

class CreateSavingsWithdrawal extends BaseCreateRecord
{
    protected static string $resource = SavingsWithdrawalResource::class;

    private const COMPARED_FIELDS = ['member_id', 'savings_type', 'period_year'];

    protected bool $idempotentHit = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! array_key_exists($data['savings_type'] ?? '', SavingsWithdrawalResource::WITHDRAWAL_TYPES)) {
            throw CannotProcessWithdrawal::unsupportedType((string) ($data['savings_type'] ?? ''));
        }

        $data['recorded_by'] = auth()->id();

        $data['status'] = 'draft';

        if (($data['savings_type'] ?? null) !== 'hari_raya') {
            $data['period_year'] = null;
        }

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return SavingsWithdrawal::create($data);

        } catch (UniqueConstraintViolationException $e) {
            $existing = SavingsWithdrawal::query()
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
                    ->body('Permintaan ini memakai kunci idempotensi yang sama tetapi nilainya berbeda dari pencairan yang sudah tersimpan. Muat ulang form lalu periksa kembali.')
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
                ->title('Pencairan sudah tercatat')
                ->body('Pengajuan ini sudah pernah disimpan sebelumnya — tidak ada duplikat yang dibuat.');
        }

        return parent::getCreatedNotification();
    }

    private function payloadDiffers(SavingsWithdrawal $existing, array $data): bool
    {
        foreach (self::COMPARED_FIELDS as $field) {
            if ((string) $existing->{$field} !== (string) ($data[$field] ?? '')) {
                return true;
            }
        }

        return bccomp((string) $existing->amount, (string) ($data['amount'] ?? '0'), 2) !== 0;
    }
}
