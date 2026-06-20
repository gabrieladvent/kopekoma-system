<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\SavingsDeposit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Engine setoran batch potong gaji per OPD (item 3a-2, D5), kini multi-jenis:
 * satu run bisa menyetor beberapa jenis simpanan per anggota dalam sekali proses.
 *
 * Prinsip: **chunked `create()` per baris** (bukan bulk `insert()`) agar audit
 * per-anggota (`LogsActivity`) tetap utuh; nomor transaksi **direservasi sekali**
 * (bukan N lock); **pre-commit dup-check per (anggota, jenis, periode)** mencegah
 * double-run lintas sesi; dan lock per OPD menserialkan run konkuren. Atomic —
 * satu baris gagal, semua batal.
 */
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

        // Normalisasi ke daftar setoran datar {member_id, type, amount} + validasi nominal.
        $deposits = $this->flattenRows($rows);

        foreach ($deposits as $deposit) {
            if (bccomp((string) $deposit['amount'], '0', 2) <= 0) {
                throw new InvalidArgumentException('Nominal setiap setoran harus lebih dari 0.');
            }
        }

        return DB::transaction(function () use ($agency, $period, $deposits, $causerId): array {
            // Lock per OPD: serialisasi dua run konkuren untuk OPD+periode sama.
            Agency::query()->whereKey($agency->getKey())->lockForUpdate()->first();

            // Reservasi nomor STR- sekali; assign berurutan di memori (backstop unique).
            $year = Carbon::parse($period)->year;
            $next = $this->reserveStartNumber($year);

            $created = 0;
            $skipped = 0;

            foreach ($deposits as $deposit) {
                $memberId = (string) $deposit['member_id'];
                $type = (string) $deposit['type'];
                // Periode penyimpanan per setoran (hari_raya = tahun program); default = periode run.
                $depositPeriod = (string) ($deposit['period_month'] ?? $period);

                // Pre-commit dup-check: lewati (anggota, jenis, periode) yang sudah punya
                // setoran AKTIF (slot kosong lagi bila sudah di-reversal → D5).
                if ($this->alreadyDeducted($memberId, $depositPeriod, $type)) {
                    $skipped++;

                    continue;
                }

                // create() per baris (bukan insert) → LogsActivity per anggota utuh.
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

            // Log batch sebagai SATU peristiwa, di atas log per-baris → double-run terdeteksi.
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
     * Sudah ada setoran aktif → skip. Pokok dicek lintas periode (sekali seumur
     * keanggotaan); jenis lain per (member, jenis, periode). Net = 0 (belum ada /
     * sudah di-reversal) → boleh disetor (re-run setelah koreksi).
     */
    private function alreadyDeducted(string $memberId, string $period, string $type = self::SAVINGS_TYPE): bool
    {
        if ($type === 'pokok') {
            return SavingsDeposit::hasActivePokok($memberId);
        }

        return SavingsDeposit::hasActiveDeposit($memberId, $type, $period);
    }
}
