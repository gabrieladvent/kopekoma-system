<?php

namespace App\Filament\Resources\LoanResource\Pages;

use App\Filament\Resources\LoanResource;
use App\Filament\Resources\Pages\BaseCreateRecord;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Services\LoanCalculator;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateLoan extends BaseCreateRecord
{
    protected static string $resource = LoanResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $calc = app(LoanCalculator::class);

        $data['recorded_by'] = auth()->id();

        $data['status'] = 'Cair';

        if (($data['loan_type'] ?? null) === 'jangka_pendek') {
            $data['term_months'] = 1;
        }

        $term = (int) ($data['term_months'] ?? 1);

        $data = array_merge($data, $calc->disbursement($data['loan_type'], $data['principal_amount']));

        $data = array_merge($data, $calc->monthlyConstants($data['loan_type'], $data['principal_amount'], $term));

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        if (LoanResource::hasActiveBlacklist($data['member_id'] ?? null)) {
            Notification::make()
                ->danger()
                ->title('Pinjaman ditolak')
                ->body('Anggota sedang dalam daftar blacklist pinjaman.')
                ->persistent()
                ->send();

            throw new Halt;
        }

        return DB::transaction(function () use ($data): Loan {
            /** @var Loan $loan */
            $loan = Loan::create($data);

            $rows = app(LoanCalculator::class)->buildSchedule(
                $loan->loan_type,
                (string) $loan->principal_amount,
                (int) $loan->term_months,
                $loan->first_due_date,
            );

            foreach ($rows as $row) {
                InstallmentSchedule::create(['loan_id' => $loan->id] + $row);
            }

            return $loan;
        });
    }
}
