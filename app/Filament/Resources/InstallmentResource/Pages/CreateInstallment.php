<?php

namespace App\Filament\Resources\InstallmentResource\Pages;

use App\Exceptions\CannotProcessPayment;
use App\Filament\Resources\InstallmentResource;
use App\Filament\Resources\Pages\BaseCreateRecord;
use App\Models\InstallmentSchedule;
use App\Services\LoanPaymentService;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateInstallment extends BaseCreateRecord
{
    protected static string $resource = InstallmentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $schedule = InstallmentSchedule::findOrFail($data['schedule_id']);

        $input = [
            'amount_paid' => $data['amount_paid'],
            'payment_method' => $data['payment_method'] ?? 'manual',
            'payment_date' => $data['payment_date'] ?? now()->toDateString(),
            'idempotency_key' => $data['idempotency_key'] ?? null,
        ];

        try {
            return app(LoanPaymentService::class)->pay(
                $schedule,
                $input,
                auth()->id(),
            );
        } catch (CannotProcessPayment $e) {
            Notification::make()
                ->danger()
                ->title('Pembayaran ditolak')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            throw new Halt;
        }
    }
}
