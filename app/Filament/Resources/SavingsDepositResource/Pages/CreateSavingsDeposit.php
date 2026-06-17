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

    /**
     * Field payload yang dibandingkan saat key idempotensi bentrok (D4).
     */
    private const COMPARED_FIELDS = ['member_id', 'savings_type'];

    /** Apakah submission ini ter-dedupe ke transaksi yang sudah ada (idempoten). */
    protected bool $idempotentHit = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // recorded_by dipaksa non-null = aktor yang login (bukan field form).
        $data['recorded_by'] = auth()->id();

        // Penegakan aturan nominal di server (jangan percaya field disabled client):
        // locked types ditimpa, sukarela divalidasi minimal, hari_raya cek registrasi.
        return SavingsDepositResource::enforceAmountRules($data);
    }

    /**
     * D4 — compare-or-warn. Double-submit (key sama) ditangkap unique constraint,
     * bukan cek-lalu-insert yang race. Payload identik → dedupe diam (sukses
     * idempoten). Payload beda → WARNING + halt (jangan silent success).
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return SavingsDeposit::create($data);
        } catch (UniqueConstraintViolationException $e) {
            $existing = SavingsDeposit::query()
                ->where('idempotency_key', $data['idempotency_key'] ?? '')
                ->first();

            // Bentrok pada kolom unik lain (mis. transaction_number) → bukan idempotensi.
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

        // amount disimpan decimal:2 ("100000.00") sedang form kirim angka polos
        // ("100000") → bandingkan numerik, bukan string.
        return bccomp((string) $existing->amount, (string) ($data['amount'] ?? '0'), 2) !== 0;
    }
}
