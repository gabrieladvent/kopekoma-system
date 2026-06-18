<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\SavingsDeposit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Engine setoran batch potong gaji per OPD (item 3a-2, D5).
 *
 * Prinsip: **chunked `create()` per baris** (bukan bulk `insert()`) agar audit
 * per-anggota (`LogsActivity`) tetap utuh; nomor transaksi **direservasi sekali**
 * (bukan N lock); **pre-commit dup-check** mencegah double-run lintas sesi; dan
 * lock per OPD menserialkan run konkuren. Atomic — satu baris gagal, semua batal.
 */
class BatchSalaryDeductionService
{
    public const SAVINGS_TYPE = 'wajib';

    public const METHOD = 'potong_gaji';

    /**
     * @param  list<array{member_id:string, amount:string|int|float}>  $rows
     * @return array{created:int, skipped:int, skipped_member_ids:list<string>}
     */
    public function run(Agency $agency, string|Carbon $periodMonth, array $rows, ?int $causerId = null): array
    {
        $causerId ??= auth()->id();
        $period = Carbon::parse($periodMonth)->startOfMonth()->toDateString();

        if ($rows === []) {
            throw new InvalidArgumentException('Tidak ada anggota untuk diproses.');
        }

        // Pre-flight per baris: nominal harus > 0 (jangan matikan validasi).
        foreach ($rows as $row) {
            if (bccomp((string) ($row['amount'] ?? '0'), '0', 2) <= 0) {
                throw new InvalidArgumentException('Nominal setiap anggota harus lebih dari 0.');
            }
        }

        return DB::transaction(function () use ($agency, $period, $rows, $causerId): array {
            // Lock per OPD: serialisasi dua run konkuren untuk OPD+periode sama.
            Agency::query()->whereKey($agency->getKey())->lockForUpdate()->first();

            // Reservasi nomor STR- sekali; assign berurutan di memori (backstop unique).
            $year = Carbon::parse($period)->year;
            $next = $this->reserveStartNumber($year);

            $created = 0;
            $skipped = [];

            foreach ($rows as $row) {
                $memberId = (string) $row['member_id'];

                // Pre-commit dup-check: lewati anggota yang sudah punya setoran wajib
                // AKTIF di periode ini (slot kosong lagi bila sudah di-reversal → D5).
                if ($this->alreadyDeducted($memberId, $period)) {
                    $skipped[] = $memberId;

                    continue;
                }

                // create() per baris (bukan insert) → LogsActivity per anggota utuh.
                SavingsDeposit::create([
                    'transaction_number' => $this->formatNumber($year, $next++),
                    'idempotency_key' => (string) Str::uuid(),
                    'member_id' => $memberId,
                    'savings_type' => self::SAVINGS_TYPE,
                    'amount' => (string) $row['amount'],
                    'deposit_date' => now()->toDateString(),
                    'period_month' => $period,
                    'deposit_method' => self::METHOD,
                    'deposited_by' => 'bendahara',
                    'recorded_by' => $causerId,
                ]);

                $created++;
            }

            // Log batch sebagai SATU peristiwa, di atas log per-baris → double-run terdeteksi.
            activity()
                ->causedBy($causerId)
                ->event('batch_potong_gaji')
                ->withProperties([
                    'agency_id' => $agency->getKey(),
                    'period_month' => $period,
                    'created' => $created,
                    'skipped' => count($skipped),
                ])
                ->log("Batch potong gaji OPD {$agency->agency_name} periode {$period}: {$created} setoran, ".count($skipped).' dilewati');

            return [
                'created' => $created,
                'skipped' => count($skipped),
                'skipped_member_ids' => $skipped,
            ];
        });
    }

    /**
     * Nomor urut STR- berikutnya untuk tahun ini (di-lock sekali untuk seluruh batch).
     */
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

    /**
     * Net setoran wajib (member, periode) > 0 → sudah ada setoran aktif → skip.
     * Net = 0 (belum ada / sudah di-reversal) → boleh disetor (re-run setelah koreksi).
     */
    private function alreadyDeducted(string $memberId, string $period): bool
    {
        $net = SavingsDeposit::query()
            ->where('member_id', $memberId)
            ->where('savings_type', self::SAVINGS_TYPE)
            ->whereDate('period_month', $period)
            ->signedAmount()
            ->value('net');

        return bccomp((string) ($net ?? '0'), '0', 2) > 0;
    }
}
