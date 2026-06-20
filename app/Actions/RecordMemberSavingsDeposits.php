<?php

namespace App\Actions;

use App\Models\SavingsDeposit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Mencatat beberapa setoran simpanan (satu baris per jenis) untuk SATU proses
 * input — dipakai form setoran multi-jenis (item: sekali proses, banyak jenis).
 *
 * Atomic: semua baris dibuat dalam satu transaksi. Idempoten per baris lewat
 * `idempotency_key` — baris yang kuncinya sudah ada dilewati (bukan duplikat),
 * sehingga submit ganda pada form yang sama menjadi no-op yang aman.
 *
 * Nominal locked types (pokok/wajib_belanja/hari_raya) HARUS sudah ditegakkan
 * oleh pemanggil (lihat SavingsDepositResource::enforceAmountRules) — action ini
 * hanya bertanggung jawab atas persistensi + idempotensi + audit per baris.
 */
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

                // Pre-check: lewati baris yang kuncinya sudah tercatat (idempoten).
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
                    // Race: kunci yang sama disisipkan oleh request lain → tetap idempoten.
                    $duplicates++;
                }
            }

            return ['created' => $created, 'duplicates' => $duplicates];
        });
    }
}
