<?php

namespace App\Filament\Resources\SavingsDepositResource\Pages;

use App\Actions\RecordMemberSavingsDeposits;
use App\Filament\Resources\Pages\BaseCreateRecord;
use App\Filament\Resources\SavingsDepositResource;
use App\Models\SavingsDeposit;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CreateSavingsDeposit extends BaseCreateRecord
{
    protected static string $resource = SavingsDepositResource::class;

    private const SHARED_FIELDS = [
        'member_id',
        'deposit_date',
        'period_month',
        'deposit_method',
        'deposited_by',
        'reference_number',
        'notes',
    ];

    protected int $createdCount = 0;

    protected int $duplicateCount = 0;

    /**
     * Sekali proses → banyak setoran (satu baris per jenis yang dicentang &
     * bernominal > 0). Nominal locked di-enforce ulang di server per baris,
     * lalu seluruh baris dipersist atomik via RecordMemberSavingsDeposits.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $shared = Arr::only($data, self::SHARED_FIELDS);

        if (filled($shared['period_month'] ?? null)) {
            $shared['period_month'] = Carbon::parse($shared['period_month'])->startOfMonth()->toDateString();
        }

        $lines = collect($data['lines'] ?? [])
            ->filter(function (array $line): bool {
                if (! ($line['include'] ?? false)) {
                    return false;
                }

                if (in_array($line['savings_type'] ?? null, SavingsDepositResource::LOCKED_AMOUNT_TYPES, true)) {
                    return true;
                }

                return bccomp((string) ($line['amount'] ?? '0'), '0', 2) > 0;
            })
            ->map(fn (array $line): array => SavingsDepositResource::enforceAmountRules([
                ...$shared,
                'savings_type' => $line['savings_type'],
                'amount' => (string) ($line['amount'] ?? '0'),
                'idempotency_key' => $line['idempotency_key'] ?? (string) Str::uuid(),
            ]))
            ->values()
            ->all();

        if ($lines === []) {
            Notification::make()
                ->warning()
                ->title('Tidak ada jenis simpanan dipilih')
                ->body('Centang minimal satu jenis simpanan dengan nominal lebih dari 0.')
                ->send();

            throw new Halt;
        }

        foreach ($lines as $line) {
            if (($line['savings_type'] ?? null) === 'hari_raya'
                && SavingsDepositResource::activeHolidayRegistration($line['member_id'] ?? null, $line['deposit_date'] ?? null) === null) {
                Notification::make()
                    ->danger()
                    ->title('Hari Raya tidak valid')
                    ->body('Tidak ada program Hari Raya aktif yang memuat tanggal setor ini untuk anggota tersebut.')
                    ->send();

                throw new Halt;
            }
        }

        [$toCreate, $skippedDone] = collect($lines)->partition(
            fn (array $line): bool => ! SavingsDepositResource::typeAlreadyDeposited(
                $line['savings_type'],
                $line['member_id'] ?? null,
                $line['deposit_date'] ?? null,
                $line['period_month'] ?? null,
            ),
        );

        $skippedDoneCount = $skippedDone->count();

        if ($toCreate->isEmpty()) {
            $this->createdCount = 0;

            $this->duplicateCount = $skippedDoneCount;

            return SavingsDeposit::query()
                ->where('member_id', $shared['member_id'])
                ->latest()
                ->firstOrFail();
        }

        $result = app(RecordMemberSavingsDeposits::class)($toCreate->values()->all());

        $this->createdCount = count($result['created']);

        $this->duplicateCount = $result['duplicates'] + $skippedDoneCount;

        if ($result['created'] === []) {
            return SavingsDeposit::query()
                ->where('member_id', $shared['member_id'])
                ->latest()
                ->firstOrFail();
        }

        return $result['created'][0];
    }

    protected function getCreatedNotification(): ?Notification
    {
        $body = "{$this->createdCount} setoran dibuat";

        if ($this->duplicateCount > 0) {
            $body .= ", {$this->duplicateCount} dilewati (sudah tercatat)";
        }

        return Notification::make()
            ->success()
            ->title($this->createdCount > 0 ? 'Setoran tersimpan' : 'Transaksi sudah tercatat')
            ->body($body.'.');
    }
}
