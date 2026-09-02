<?php

namespace App\Actions;

use App\Exceptions\UnsupportedSavingsType;
use App\Filament\Resources\SavingsDepositResource;
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

        // Daftar putih jenis setoran — DI SINI, bukan hanya di layar.
        //
        // `savings_type` datang dari payload dan tak pernah divalidasi di jalur
        // mana pun. Dulu enum MySQL `savings_deposits.savings_type` jadi jaring
        // terakhirnya; sejak enum itu dilebarkan untuk `swp` dan
        // `tabungan_berjangka`, jaring itu hilang. Tanpa guard ini, satu request
        // hasil edit bisa mencetak simpanan SWP bernominal bebas — dan karena
        // `swp` ada di `WithdrawalWorkflow::WITHDRAWABLE_TYPES`, saldo palsu itu
        // bisa dicairkan jadi uang sungguhan.
        //
        // Ditegakkan di lapisan mutasi supaya pemanggil BARU (perintah artisan,
        // job, import, layar baru) mewarisinya tanpa perlu mengingatnya.
        foreach ($lines as $line) {
            $type = (string) ($line['savings_type'] ?? '');

            if (! array_key_exists($type, SavingsDepositResource::SAVINGS_TYPES)) {
                throw UnsupportedSavingsType::forType($type);
            }
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
