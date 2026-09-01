<?php

namespace App\Services;

use App\Enums\InstallmentScheduleStatus;
use App\Enums\LoanStatus;
use App\Exceptions\CannotProcessPayment;
use App\Models\Agency;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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

    /** Pelunasan dipercepat lewat batch — otoritas yang sama dengan jalur loket. */
    private const SETTLE_PERMISSION = 'settle_early_installment';

    private const SCALE = 2;

    public function __construct(private readonly LoanPaymentService $payments) {}

    /**
     * @param  list<array{schedule_id:string, amount_paid:string|int|float, payment_date?:string, bukti?:?UploadedFile, bukti_path?:?string, bukti_disk?:?string}>  $rows
     * @return array{created:int, skipped:int, skipped_rows:list<array{schedule_id:?string, loan_number:?string, member:?string, reason:string}>}
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

        // Otorisasi pelunasan ditegakkan DI SINI juga, bukan hanya di entry point
        // Livewire. Pelunasan sebaris mengubah satu potongan gaji jadi penutupan
        // seluruh pinjaman; membiarkan penjaganya hidup hanya di satu halaman
        // berarti pemanggil berikutnya (perintah artisan, job, halaman baru)
        // mewarisi kewenangan itu tanpa ada yang memutuskannya. Pola yang sama
        // dengan `pay_installment_from_savings` di LoanPaymentService.
        if (collect($rows)->contains(fn (array $row): bool => (bool) ($row['settle_early'] ?? false))) {
            Gate::forUser($this->actingUser($causerId))->authorize(self::SETTLE_PERMISSION);
        }

        return DB::transaction(function () use ($agency, $period, $rows, $causerId): array {
            // Lock per OPD: serialkan batch bersamaan untuk OPD yang sama
            // (pola engine Simpanan). Anti double-bayar per-jadwal ditegakkan
            // di pay() (lock loan + cek schedule Terbayar + idempotency).
            Agency::query()->whereKey($agency->getKey())->lockForUpdate()->first();

            $created = 0;

            /**
             * Baris yang dilewati, BESERTA sebabnya (ADR 2026-08-28 §Jejak log).
             * Hitungan telanjang "3 dilewati" tak bisa menjawab pertanyaan yang
             * benar-benar diajukan setelah potong gaji: gaji SIAPA yang terpotong
             * tapi angsurannya tak tercatat. Tanpa daftar ini pertanyaan itu tak
             * punya jawaban di mana pun — barisnya memang tak meninggalkan jejak.
             *
             * @var list<array{schedule_id:?string, loan_number:?string, member:?string, reason:string}>
             */
            $skippedRows = [];

            foreach ($rows as $row) {
                $schedule = InstallmentSchedule::with('loan.member')->find($row['schedule_id']);

                // Fail-closed: lewati jadwal yang hilang, sudah terbayar (race dgn
                // batch lain / setoran manual), atau BUKAN milik anggota OPD ini.
                // Cek OPD menjaga invariant "batch per OPD hanya menyentuh OPD ini"
                // walau payload Livewire di-utak-atik (page hanya membangun baris
                // dari anggota OPD terpilih; ini penegakan server-side-nya). pay()
                // tetap penjaga akhir (lock loan + status + idempotency).
                if ($schedule === null) {
                    $skippedRows[] = $this->skippedRow(null, (string) $row['schedule_id'], 'Jadwal angsuran tidak ditemukan');

                    continue;
                }

                if ($schedule->status === InstallmentScheduleStatus::Terbayar) {
                    $skippedRows[] = $this->skippedRow($schedule, (string) $row['schedule_id'], 'Jadwal sudah terbayar');

                    continue;
                }

                if (! $this->belongsToAgency($schedule, $agency)) {
                    $skippedRows[] = $this->skippedRow($schedule, (string) $row['schedule_id'], 'Bukan anggota OPD ini');

                    continue;
                }

                // Pelunasan dipercepat per baris (ADR 2026-07-22 5b): tutup seluruh
                // sisa pinjaman, bukan satu jadwal. Otorisasi `settle_early_installment`
                // ditegakkan di entry point (Livewire process()); guard jenis/status
                // di settleEarly(). Bukti dilampirkan langsung di dalam service.
                if ($row['settle_early'] ?? false) {
                    try {
                        $loan = $schedule->loan;

                        $installment = $this->payments->settleEarly(
                            $loan,
                            [
                                'amount_paid' => $row['amount_paid'],
                                'payment_method' => self::METHOD,
                                'payment_date' => $row['payment_date'] ?? $period,
                            ],
                            $causerId,
                            $row['bukti'] ?? null,
                        );

                        $this->attachBukti($installment, $row['bukti_path'] ?? null, $row['bukti_disk'] ?? null);

                        $created++;
                    } catch (CannotProcessPayment $e) {
                        $skippedRows[] = $this->skippedRow($schedule, (string) $row['schedule_id'], $e->getMessage());
                    }

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
                        // Penjaga "arahkan ke Pelunasan Dipercepat" DIMATIKAN di
                        // jalur potong gaji (R23). Nominalnya angka kontrak yang
                        // ditetapkan bendahara OPD, bukan uang sekaligus yang
                        // diserahkan anggota di loket — jadi tak ada yang perlu
                        // dilindungi. Dibiarkan menyala, penjaga itu melempar dan
                        // batch MENELAN potongannya diam-diam (catch di bawah):
                        // uang terpotong dari gaji, angsuran tak pernah tercatat.
                        redirectToSettlement: false,
                    );

                    // Filament: file sudah tersimpan di disk (getState) → lampirkan dari path.
                    $this->attachBukti($installment, $row['bukti_path'] ?? null, $row['bukti_disk'] ?? null);

                    $created++;
                } catch (CannotProcessPayment $e) {
                    // Pinjaman tak lagi Cair / jadwal terbayar di tengah jalan.
                    $skippedRows[] = $this->skippedRow($schedule, (string) $row['schedule_id'], $e->getMessage());
                }
            }

            $skipped = count($skippedRows);

            // Dibungkus `attributes` DENGAN SENGAJA (R22): panel audit maupun
            // ActivityResource hanya merender `properties.attributes`. Properti
            // datar tersimpan rapi di database lalu tak pernah terlihat siapa pun.
            activity()
                ->causedBy($causerId)
                ->event('batch_angsuran_potong_gaji')
                ->withProperties(['attributes' => [
                    'agency_id' => $agency->getKey(),
                    'period_month' => $period,
                    'created' => $created,
                    'skipped' => $skipped,
                    'skipped_rows' => $skippedRows,
                ]])
                ->log("Batch potong gaji angsuran OPD {$agency->agency_name} periode {$period}: {$created} angsuran, {$skipped} dilewati");

            return [
                'created' => $created,
                'skipped' => $skipped,
                'skipped_rows' => $skippedRows,
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
                || $schedule->status === InstallmentScheduleStatus::Terbayar
                || $schedule->loan?->status !== LoanStatus::Cair
                || ! $this->belongsToAgency($schedule, $agency)) {
                continue;
            }

            // Baris pelunasan (ADR 2026-07-22 5b): batas bawah = jumlah pelunasan
            // (sisa pokok + 1× jasa), bukan tagihan satu jadwal.
            if ($row['settle_early'] ?? false) {
                $loan = $schedule->loan;

                // Satu sumber (ADR 2026-08-28 item 1c) — harus angka yang sama
                // persis dengan yang ditegakkan settleEarly(), termasuk potongan
                // Titipan Pokok. Rumus lokal di sini dulunya duplikat (R2).
                $payoff = $loan->payoffAmount();

                if (bccomp($amount, $payoff, self::SCALE) < 0) {
                    throw new InvalidArgumentException(sprintf(
                        'Nominal pelunasan pinjaman %s kurang dari jumlah pelunasan Rp %s — periksa kembali sebelum memproses batch.',
                        $loan->loan_number,
                        number_format((float) $payoff, 0, ',', '.'),
                    ));
                }

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

    /**
     * Satu entri daftar-dilewati. Nomor pinjaman dan nama anggota ikut dicatat —
     * `schedule_id` saja memaksa pemeriksa menelusuri balik UUID di database,
     * dan jejak yang hanya bisa dibaca lewat query bukan jejak yang terpakai.
     *
     * @return array{schedule_id:?string, loan_number:?string, member:?string, reason:string}
     */
    private function skippedRow(?InstallmentSchedule $schedule, ?string $scheduleId, string $reason): array
    {
        return [
            'schedule_id' => $scheduleId,
            'loan_number' => $schedule?->loan?->loan_number,
            'member' => $schedule?->loan?->member?->full_name,
            'reason' => $reason,
        ];
    }

    /**
     * Pelaku untuk otorisasi pelunasan batch. Pelunasan mengubah nasib seluruh
     * pinjaman, jadi wajib ada pelaku terautentikasi — tak ada jalur anonim.
     */
    private function actingUser(?int $causerId): User
    {
        $user = $causerId !== null ? User::find($causerId) : auth()->user();

        if (! $user instanceof User) {
            throw new AuthorizationException('Pelunasan dipercepat lewat batch memerlukan pengguna terautentikasi.');
        }

        return $user;
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
