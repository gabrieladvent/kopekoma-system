<?php

namespace App\Actions;

use App\Models\SavingsDeposit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class RecordMemberSavingsDeposits
{
    /**
     * @param  list<array<string, mixed>>  $lines  tiap baris berisi member_id, savings_type,
     *                                             amount, idempotency_key, dan metadata setoran (deposit_date, period_month,
     *                                             deposit_method, deposited_by, reference_number, notes).
     * @param  int|string|false|null  $causerId  `false` (default) = resolve dari auth user.
     * @return array{created: list<SavingsDeposit>, duplicates: int}
     */
    public function __invoke(array $lines, int|string|false|null $causerId = false): array
    {
        if ($causerId === false) {
            $causerId = auth()->id();
        }

        return DB::transaction(function () use ($lines, $causerId): array {
            $created = [];

            $duplicates = 0;

            foreach ($lines as $line) {
                $key = $line['idempotency_key'] ?? null;

                if ($key !== null && SavingsDeposit::query()->where('idempotency_key', $key)->exists()) {
                    $duplicates++;

                    continue;
                }

                try {
                    $created[] = SavingsDeposit::create([
                        ...$line,
                        'recorded_by' => $causerId,
                    ]);

                } catch (UniqueConstraintViolationException) {
                    $duplicates++;
                }
            }

            return ['created' => $created, 'duplicates' => $duplicates];
        });
    }
}
