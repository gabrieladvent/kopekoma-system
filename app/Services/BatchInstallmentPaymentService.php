<?php

namespace App\Services;

use App\Exceptions\CannotProcessPayment;
use App\Models\Agency;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Batch potong gaji angsuran per OPD (ADR pinjaman 3c / D6). Mereplikasi pola
 * engine batch Simpanan (lock per OPD, log batch satu peristiwa) tapi DELEGASI
 * tiap baris ke {@see LoanPaymentService::pay()} — sumber kebenaran pembayaran
 * angsuran (validasi ≥ tagihan, FIFO schedule, idempotency, auto-Lunas + refund
 * SWP/Tab atomik, audit per-anggota). Service ini hanya mengorkestrasi: pilih
 * jadwal terlama belum bayar tiap pinjaman aktif, bayar, hitung created/skipped.
 *
 * Bukan generalisasi `BatchSalaryDeductionService` (domain berbeda: schedule
 * FIFO + refund pelunasan) — keputusan D6 menghindari abstraksi prematur.
 */
class BatchInstallmentPaymentService
{
    public const METHOD = 'potong_gaji';

    private const SCALE = 2;

    public function __construct(private readonly LoanPaymentService $payments) {}

    /**
     * @param  list<array{schedule_id:string, amount_paid:string|int|float, payment_date?:string, bukti?:?UploadedFile, bukti_path?:?string, bukti_disk?:?string}>  $rows
     * @return array{created:int, skipped:int}
     */
    public function run(
        Agency $agency,
        string|Carbon $periodMonth,
        array $rows,
        ?int $causerId = null,
    ): array {
        $causerId ??= auth()->id();

        $period = Carbon::parse($periodMonth)->startOfMonth()->toDateString();

        if ($rows === []) {
            throw new InvalidArgumentException('Tidak ada angsuran untuk diproses.');
        }

        $this->assertRowsValid($rows, $agency);

        return DB::transaction(function () use ($agency, $period, $rows, $causerId): array {
            // Lock per OPD: serialkan batch bersamaan untuk OPD yang sama
            // (pola engine Simpanan). Anti double-bayar per-jadwal ditegakkan
            // di pay() (lock loan + cek schedule Terbayar + idempotency).
            Agency::query()->whereKey($agency->getKey())->lockForUpdate()->first();

            $created = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                $schedule = InstallmentSchedule::with('loan.member')->find($row['schedule_id']);

                // Fail-closed: lewati jadwal yang hilang, sudah terbayar (race dgn
                // batch lain / setoran manual), atau BUKAN milik anggota OPD ini.
                // Cek OPD menjaga invariant "batch per OPD hanya menyentuh OPD ini"
                // walau payload Livewire di-utak-atik (page hanya membangun baris
                // dari anggota OPD terpilih; ini penegakan server-side-nya). pay()
                // tetap penjaga akhir (lock loan + status + idempotency).
                if ($schedule === null
                    || $schedule->status === 'Terbayar'
                    || ! $this->belongsToAgency($schedule, $agency)) {
                    $skipped++;

                    continue;
                }

                try {
                    $installment = $this->payments->pay(
                        $schedule,
                        [
                            'amount_paid' => $row['amount_paid'],
                            'payment_method' => self::METHOD,
                            'payment_date' => $row['payment_date'] ?? $period,
                        ],
                        $causerId,
                        // Livewire: UploadedFile langsung dilampirkan di dalam pay().
                        $row['bukti'] ?? null,
                    );

                    // Filament: file sudah tersimpan di disk (getState) → lampirkan dari path.
                    $this->attachBukti($installment, $row['bukti_path'] ?? null, $row['bukti_disk'] ?? null);

                    $created++;
                } catch (CannotProcessPayment) {
                    // Pinjaman tak lagi Cair / jadwal terbayar di tengah jalan.
                    $skipped++;
                }
            }

            activity()
                ->causedBy($causerId)
                ->event('batch_angsuran_potong_gaji')
                ->withProperties([
                    'agency_id' => $agency->getKey(),
                    'period_month' => $period,
                    'created' => $created,
                    'skipped' => $skipped,
                ])
                ->log("Batch potong gaji angsuran OPD {$agency->agency_name} periode {$period}: {$created} angsuran, {$skipped} dilewati");

            return [
                'created' => $created,
                'skipped' => $skipped,
            ];
        });
    }

    /**
     * Pra-validasi sebelum transaksi: nominal > 0 dan ≥ tagihan jadwal. Nominal
     * di bawah tagihan = kesalahan input (potensi korupsi selisih) → GAGALKAN
     * seluruh batch agar petugas membetulkan, BUKAN dilewati diam-diam (prinsip
     * "uang di sistem = uang nyata", D4/D5). Jadwal yang sudah terbayar / pinjaman
     * tak aktif diabaikan di sini (akan dilewati saat eksekusi).
     *
     * @param  list<array{schedule_id:string, amount_paid:string|int|float, payment_date?:string}>  $rows
     */
    private function assertRowsValid(array $rows, Agency $agency): void
    {
        $schedules = InstallmentSchedule::query()
            ->with('loan.member')
            ->whereIn('id', collect($rows)->pluck('schedule_id')->filter()->all())
            ->get()
            ->keyBy('id');

        foreach ($rows as $row) {
            $amount = (string) $row['amount_paid'];

            if (bccomp($amount, '0', self::SCALE) <= 0) {
                throw new InvalidArgumentException('Nominal setiap angsuran harus lebih dari 0.');
            }

            $schedule = $schedules->get($row['schedule_id']);

            // Jadwal yang akan dilewati saat eksekusi (tak ada / terbayar / pinjaman
            // tak aktif / bukan OPD ini) tak perlu divalidasi nominalnya.
            if ($schedule === null
                || $schedule->status === 'Terbayar'
                || $schedule->loan?->status !== 'Cair'
                || ! $this->belongsToAgency($schedule, $agency)) {
                continue;
            }

            if (bccomp($amount, (string) $schedule->total_due, self::SCALE) < 0) {
                throw new InvalidArgumentException(sprintf(
                    'Nominal angsuran #%d kurang dari tagihan Rp %s — periksa kembali sebelum memproses batch.',
                    $schedule->installment_seq,
                    number_format((float) $schedule->total_due, 0, ',', '.'),
                ));
            }
        }
    }

    private function belongsToAgency(InstallmentSchedule $schedule, Agency $agency): bool
    {
        return $schedule->loan?->member?->agency_id === $agency->getKey();
    }

    /**
     * Lampirkan bukti per-baris (opsional). File sudah tersimpan di disk media
     * oleh FileUpload (getState) → pindahkan ke koleksi `bukti` angsuran. File
     * tmp yang hilang (race / dibersihkan) di-skip diam-diam: bukti pendukung,
     * bukan syarat sah pembayaran yang sudah ter-commit.
     */
    private function attachBukti(Installment $installment, ?string $path, ?string $disk): void
    {
        if (blank($path)) {
            return;
        }

        $disk ??= config('media-library.disk_name');

        if (! Storage::disk($disk)->exists($path)) {
            return;
        }

        $installment->addMediaFromDisk($path, $disk)->toMediaCollection('bukti');
    }
}
