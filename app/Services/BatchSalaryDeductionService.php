<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\SavingsDeposit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class BatchSalaryDeductionService
{
    /** Jenis default bila baris hanya membawa `amount` (kompatibilitas lama). */
    public const SAVINGS_TYPE = 'wajib';

    public const METHOD = 'potong_gaji';

    /**
     * @param  list<array{member_id:string, amount?:string|int|float, deposits?:list<array{type:string, amount:string|int|float, period_month?:string}>}>  $rows
     * @return array{created:int, skipped:int}
     */
    public function run(Agency $agency, string|Carbon $periodMonth, array $rows, ?int $causerId = null): array
    {
        $causerId ??= auth()->id();

        $period = Carbon::parse($periodMonth)->startOfMonth()->toDateString();

        if ($rows === []) {
            throw new InvalidArgumentException('Tidak ada anggota untuk diproses.');
        }

        $deposits = $this->flattenRows($rows);

        foreach ($deposits as $deposit) {
            if (bccomp((string) $deposit['amount'], '0', 2) <= 0) {
                throw new InvalidArgumentException('Nominal setiap setoran harus lebih dari 0.');
            }
        }

        return DB::transaction(function () use ($agency, $period, $deposits, $causerId): array {
            Agency::query()->whereKey($agency->getKey())->lockForUpdate()->first();

            $year = Carbon::parse($period)->year;

            $next = $this->reserveStartNumber($year);

            $created = 0;

            $skipped = 0;

            foreach ($deposits as $deposit) {
                $memberId = (string) $deposit['member_id'];

                $type = (string) $deposit['type'];

                $depositPeriod = (string) ($deposit['period_month'] ?? $period);

                if ($this->alreadyDeducted($memberId, $depositPeriod, $type)) {
                    $skipped++;

                    continue;
                }

                SavingsDeposit::create([
                    'transaction_number' => $this->formatNumber($year, $next++),
                    'idempotency_key' => (string) Str::uuid(),
                    'member_id' => $memberId,
                    'savings_type' => $type,
                    'amount' => (string) $deposit['amount'],
                    'deposit_date' => now()->toDateString(),
                    'period_month' => $depositPeriod,
                    'deposit_method' => self::METHOD,
                    'deposited_by' => 'bendahara',
                    'recorded_by' => $causerId,
                ]);

                $created++;
            }

            activity()
                ->causedBy($causerId)
                ->event('batch_potong_gaji')
                ->withProperties([
                    'agency_id' => $agency->getKey(),
                    'period_month' => $period,
                    'created' => $created,
                    'skipped' => $skipped,
                ])
                ->log("Batch potong gaji OPD {$agency->agency_name} periode {$period}: {$created} setoran, {$skipped} dilewati");

            return [
                'created' => $created,
                'skipped' => $skipped,
            ];
        });
    }

    /**
     * Ratakan baris per-anggota menjadi daftar setoran {member_id, type, amount}.
     * Mendukung dua bentuk baris: `deposits[]` (multi-jenis) atau `amount` tunggal
     * yang diperlakukan sebagai `wajib` (kompatibilitas pemanggil lama).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{member_id:string, type:string, amount:string|int|float, period_month:?string}>
     */
    private function flattenRows(array $rows): array
    {
        $deposits = [];

        foreach ($rows as $row) {
            $memberId = (string) $row['member_id'];

            if (isset($row['deposits']) && is_array($row['deposits'])) {
                foreach ($row['deposits'] as $deposit) {
                    $deposits[] = [
                        'member_id' => $memberId,
                        'type' => (string) $deposit['type'],
                        'amount' => $deposit['amount'],
                        'period_month' => $deposit['period_month'] ?? null,
                    ];
                }

                continue;
            }

            $deposits[] = [
                'member_id' => $memberId,
                'type' => self::SAVINGS_TYPE,
                'amount' => $row['amount'] ?? '0',
                'period_month' => null,
            ];
        }

        return $deposits;
    }

    private function reserveStartNumber(int $year): int
    {
        $prefix = sprintf('STR-%d-', $year);

        $last = SavingsDeposit::query()
            ->where('transaction_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('transaction_number')
            ->value('transaction_number');

        return $last ? ((int) substr($last, -6)) + 1 : 1;
    }

    private function formatNumber(int $year, int $n): string
    {
        return sprintf('STR-%d-%06d', $year, $n);
    }

    private function alreadyDeducted(string $memberId, string $period, string $type = self::SAVINGS_TYPE): bool
    {
        if ($type === 'pokok') {
            return SavingsDeposit::hasActivePokok($memberId);
        }

        return SavingsDeposit::hasActiveDeposit($memberId, $type, $period);
    }
}
