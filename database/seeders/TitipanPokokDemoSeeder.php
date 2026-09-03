<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Grade;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\User;
use App\Services\LoanCalculator;
use App\Services\LoanPaymentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Data uji coba Titipan Pokok (ADR 2026-08-28) — BUKAN untuk produksi.
 *
 * Membangun satu OPD berisi enam pinjaman yang **masing-masing sudah berada di
 * keadaan yang dibutuhkan satu skenario UAT**, supaya penguji tidak perlu
 * menyiapkan keadaannya sendiri lebih dulu. Angkanya dibuat bulat: pinjaman
 * 12.000.000 / 12 bulan menghasilkan tagihan 1.090.000 (pokok 1.000.000 + jasa
 * 78.000 + tabungan berjangka 12.000), sehingga tiap potongan titipan bisa
 * dihitung di kepala saat menguji.
 *
 * Seluruh keadaan dibangun lewat `LoanPaymentService` yang asli, bukan insert
 * langsung — jadi status jadwal, saldo titipan, jejak audit, dan draft
 * pengembalian terbentuk persis seperti kalau diinput petugas lewat aplikasi.
 *
 * Jalankan:
 *   php artisan migrate:fresh --seed
 *   php artisan db:seed --class=DemoDataSeeder
 *   php artisan db:seed --class=TitipanPokokDemoSeeder
 *
 * Skenario lengkap beserta hasil yang diharapkan: docs/uat/titipan-pokok.md
 */
class TitipanPokokDemoSeeder extends Seeder
{
    private const NIK_PREFIX = '33099';

    private const AGENCY_CODE = 'DISHUB';

    /** Pinjaman 12.000.000 / 12 bulan → tagihan 1.090.000, pokok 1.000.000. */
    private const PRINCIPAL = '12000000';

    private const TERM = 12;

    private const BILL = '1090000';

    private User $petugas;

    private LoanPaymentService $payments;

    /** @var list<array{kode:string, anggota:string, pinjaman:string, keadaan:string}> */
    private array $report = [];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('TitipanPokokDemoSeeder tidak boleh dijalankan di produksi.');
        }

        if (Member::query()->where('nik', 'like', self::NIK_PREFIX.'%')->exists()) {
            $this->command?->warn('Data uji Titipan Pokok sudah ada. Jalankan `php artisan migrate:fresh --seed` dulu bila mau membangun ulang.');

            return;
        }

        if (Grade::query()->doesntExist()) {
            $this->call(GradeSeeder::class);
        }

        $this->payments = app(LoanPaymentService::class);
        $this->petugas = $this->user('Petugas Input', 'petugas@kopekoma.test', 'petugas');
        $this->user('Bendahara Koperasi', 'pengurus@kopekoma.test', 'pengurus');

        $agency = $this->agency();

        $this->t1($agency);
        $this->t2($agency);
        $this->t3($agency);
        $this->t4($agency);
        $this->t5($agency);
        $this->t6($agency);

        $this->report();
    }

    /**
     * T1 — bersih, siap menguji DIALOG ALOKASI.
     *
     * Dua angsuran terbayar pas, titipan nol. Penguji menyetor 2× tagihan dan
     * dialog "Simpan sebagai Titipan Pokok / Tutup angsuran berikutnya sekalian"
     * harus muncul beserta akibat kedua pilihan dalam rupiah.
     */
    private function t1(Agency $agency): void
    {
        $loan = $this->loanFor($agency, 'Rahayu Kusumaningrum', 'T1');

        $this->payExact($loan, 2);

        $this->note('T1', 'Rahayu Kusumaningrum', $loan, 'Bersih, 2 angsuran terbayar. Titipan 0. Untuk menguji dialog alokasi.');
    }

    /**
     * T2 — SUDAH BERTITIPAN 1.090.000.
     *
     * Untuk menguji tagihan efektif (prefill 90.000), panel Riwayat, kuitansi
     * bertitipan, dan laporan agregat.
     */
    private function t2(Agency $agency): void
    {
        $loan = $this->loanFor($agency, 'Yusuf Maulana', 'T2');

        $this->payExact($loan, 1);
        $this->pay($loan, 2, '2180000');

        $this->note('T2', 'Yusuf Maulana', $loan, 'Titipan 1.090.000. Tagihan efektif angsuran #3 = 90.000.');
    }

    /**
     * T3 — setoran TUTUP SEKALIAN, satu sesi dua baris.
     *
     * Untuk menguji keterkaitan sesi di layar pembatalan ("satu setoran bersama
     * ANG-…") dan bukti yang melekat di kedua baris.
     */
    private function t3(Agency $agency): void
    {
        $loan = $this->loanFor($agency, 'Wahyu Nugroho', 'T3');

        $this->pay($loan, 1, '2180000', LoanPaymentService::MODE_TUTUP_SEKALIAN);

        $this->note('T3', 'Wahyu Nugroho', $loan, 'Angsuran #1 & #2 tertutup satu setoran (satu kunci sesi). Titipan 0.');
    }

    /**
     * T4 — titipan SUDAH TERPAKAI angsuran berikutnya.
     *
     * Untuk menguji guard pembatalan (item 1j): membatalkan setoran yang membuat
     * titipan harus DITOLAK, dengan pesan menyebut angsuran penghalang.
     */
    private function t4(Agency $agency): void
    {
        $loan = $this->loanFor($agency, 'Ratna Dewi Anggraini', 'T4');

        $this->pay($loan, 1, '2180000');   // titipan 1.090.000
        $this->pay($loan, 2, '90000');     // titipan terpakai → sisa 90.000

        $this->note('T4', 'Ratna Dewi Anggraini', $loan, 'Titipan sudah terpakai angsuran #2. Pembatalan #1 harus DITOLAK.');
    }

    /**
     * T5 — LUNAS lewat Pelunasan Dipercepat, titipannya melebihi sisa pokok.
     *
     * Satu-satunya keadaan yang memunculkan **dua** baris penutup di panel
     * Riwayat: sebagian titipan memotong jumlah pelunasan, sisanya dilimpahkan
     * ke Simpanan Sukarela. Sekaligus keadaan yang dipakai menguji lubang yang
     * ditemukan security review (R24): membatalkan setoran pembuat titipan
     * sementara baris pelunasannya dibiarkan berdiri harus DITOLAK.
     *
     * Titipan sebesar ini hanya bisa terbentuk lewat jalur potong gaji — di
     * loket, penjaga Pelunasan Dipercepat mencegahnya tumbuh sejauh itu. Karena
     * itu setoran terakhir dicatat dengan penjaga tersebut dimatikan, persis
     * seperti yang dilakukan `BatchInstallmentPaymentService`.
     */
    private function t5(Agency $agency): void
    {
        $loan = $this->loanFor($agency, 'Hesti Prabaningrum', 'T5');

        $this->payExact($loan, 10);

        // Potongan gaji dinaikkan bendahara → titipan 2.000.000, sisa pokok 1.000.000.
        $this->pay($loan, 11, '3090000', LoanPaymentService::MODE_TITIPAN, redirectToSettlement: false);

        $fresh = $loan->fresh();

        $this->payments->settleEarly(
            $fresh,
            ['amount_paid' => $fresh->payoffAmount(), 'payment_method' => 'manual'],
            $this->petugas->id,
        );

        $this->note('T5', 'Hesti Prabaningrum', $loan, 'LUNAS via pelunasan. Titipan 2.000.000: 1.000.000 memotong pelunasan, 1.000.000 ke Sukarela.');
    }

    /**
     * T6 — bahan uji BATCH POTONG GAJI.
     *
     * Bertitipan 1.090.000 dan berstatus Cair, jadi di layar batch jumlah
     * pelunasannya harus sudah dikurangi titipan. Inilah salinan rumus keempat
     * yang ditemukan security review — bila angka layar lebih besar dari
     * `payoffAmount()`, bendahara memotong gaji melebihi utang anggota.
     */
    private function t6(Agency $agency): void
    {
        $loan = $this->loanFor($agency, 'Bagus Prakoso', 'T6');

        $this->payExact($loan, 3);
        $this->pay($loan, 4, '2180000');

        $fresh = $loan->fresh();

        // Angkanya DIHITUNG, bukan diketik. Catatan yang dicetak ke terminal
        // ikut dibaca penguji sebagai hasil yang diharapkan; versi pertama
        // menyebut 7.078.000 karena lupa memotong titipan penuh, dan itu akan
        // membuat penguji melaporkan "tidak sesuai" atas sistem yang benar.
        $this->note('T6', 'Bagus Prakoso', $loan, sprintf(
            'Titipan %s, sisa pokok %s. Payoff di layar batch harus %s.',
            $this->rupiah($fresh->overpaymentCredit()),
            $this->rupiah($fresh->settledPrincipal()),
            $this->rupiah($fresh->payoffAmount()),
        ));
    }

    // ---------------------------------------------------------------- helper

    private function loanFor(Agency $agency, string $name, string $code): Loan
    {
        $member = $this->member($agency, $name, $code);

        $calc = app(LoanCalculator::class);

        $disbursedAt = Carbon::now()->subMonths(self::TERM)->day(10);
        $firstDue = $disbursedAt->copy()->addMonthNoOverflow();

        $data = [
            'member_id' => $member->id,
            'loan_type' => 'jangka_panjang',
            'principal_amount' => self::PRINCIPAL,
            'term_months' => self::TERM,
            'disbursement_date' => $disbursedAt->toDateString(),
            'first_due_date' => $firstDue->toDateString(),
            'disbursement_method' => 'transfer',
            'disbursement_bank' => 'Bank Jateng',
            'disbursement_account_number' => $member->payroll_account_number,
            'status' => 'Cair',
            'recorded_by' => $this->petugas->id,
        ];

        $data = array_merge(
            $data,
            $calc->disbursement('jangka_panjang', self::PRINCIPAL),
            $calc->monthlyConstants('jangka_panjang', self::PRINCIPAL, self::TERM),
        );

        return DB::transaction(function () use ($data, $calc, $firstDue): Loan {
            /** @var Loan $loan */
            $loan = Loan::create($data);

            foreach ($calc->buildSchedule('jangka_panjang', self::PRINCIPAL, self::TERM, $firstDue) as $row) {
                InstallmentSchedule::create(['loan_id' => $loan->id] + $row);
            }

            return $loan;
        });
    }

    /** Bayar N jadwal pertama tepat sebesar tagihan kontrak — titipan tak bergerak. */
    private function payExact(Loan $loan, int $count): void
    {
        for ($seq = 1; $seq <= $count; $seq++) {
            $this->pay($loan, $seq, self::BILL);
        }
    }

    private function pay(
        Loan $loan,
        int $seq,
        string $amount,
        string $mode = LoanPaymentService::MODE_TITIPAN,
        bool $redirectToSettlement = true,
    ): Installment {
        /** @var InstallmentSchedule $schedule */
        $schedule = InstallmentSchedule::query()
            ->where('loan_id', $loan->id)
            ->where('installment_seq', $seq)
            ->firstOrFail();

        $paidAt = Carbon::parse($schedule->due_date)->addDays(2);

        $installment = $this->payments->pay(
            $schedule,
            [
                'amount_paid' => $amount,
                'payment_method' => 'potong_gaji',
                'payment_date' => $paidAt->toDateString(),
                'idempotency_key' => (string) Str::uuid(),
                'mode' => $mode,
            ],
            $this->petugas->id,
            null,
            redirectToSettlement: $redirectToSettlement,
        );

        Installment::query()
            ->where('session_key', $installment->session_key)
            ->update(['created_at' => $paidAt, 'updated_at' => $paidAt]);

        return $installment;
    }

    private function agency(): Agency
    {
        return Agency::firstOrCreate(
            ['agency_code' => self::AGENCY_CODE],
            [
                'agency_name' => 'Dinas Perhubungan Kab. Magelang',
                'payroll_treasurer' => 'Sukirman, S.E.',
                'pic_phone_number' => '+62812345004',
                'address' => 'Kota Mungkid, Kabupaten Magelang',
                'status' => 'Aktif',
            ],
        );
    }

    private function member(Agency $agency, string $name, string $code): Member
    {
        $seq = (int) mb_substr($code, 1);
        $gradeId = Grade::query()->value('id');

        return Member::create([
            'full_name' => $name,
            'birth_place' => 'Magelang',
            'birth_date' => Carbon::parse('1987-01-01')->addMonths($seq * 5)->toDateString(),
            'gender' => $seq % 2 === 0 ? 'L' : 'P',
            'nik' => self::NIK_PREFIX.str_pad((string) $seq, 11, '0', STR_PAD_LEFT),
            'nip' => '19'.str_pad((string) (900000 + $seq), 16, '0', STR_PAD_LEFT),
            'agency_id' => $agency->id,
            'position' => 'Staf Perhubungan',
            'grade_id' => $gradeId,
            'mandatory_savings_amount' => Grade::find($gradeId)?->mandatory_savings_amount ?? 50000,
            'employment_status' => 'ASN',
            'payroll_account_number' => '3309'.str_pad((string) $seq, 8, '0', STR_PAD_LEFT),
            'bank_name' => 'Bank Jateng',
            'address' => 'Dusun Ngasem RT 0'.($seq % 8 + 1).' RW 01, Kabupaten Magelang',
            'phone_number' => '+628223456'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
            'join_date' => '2020-01-06',
            'heir_name' => 'Ahli Waris '.Str::before($name, ' '),
            'heir_relationship' => $seq % 2 === 0 ? 'Istri' : 'Suami',
            'heir_phone_number' => '+628887654'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
            'status' => 'Aktif',
        ]);
    }

    private function user(string $name, string $email, string $role): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make('password')],
        );

        if (Role::query()->where('name', $role)->where('guard_name', 'web')->exists()) {
            $user->syncRoles([$role]);
        }

        return $user;
    }

    private function rupiah(string $amount): string
    {
        return number_format((float) $amount, 0, ',', '.');
    }

    private function note(string $code, string $member, Loan $loan, string $state): void
    {
        $this->report[] = [
            'kode' => $code,
            'anggota' => $member,
            'pinjaman' => $loan->fresh()->loan_number,
            'keadaan' => $state,
        ];
    }

    private function report(): void
    {
        $this->command?->newLine();
        $this->command?->info('Data uji Titipan Pokok siap — OPD Dinas Perhubungan Kab. Magelang');
        $this->command?->newLine();

        foreach ($this->report as $row) {
            $this->command?->line("  <fg=yellow>{$row['kode']}</> {$row['pinjaman']} — {$row['anggota']}");
            $this->command?->line("     {$row['keadaan']}");
        }

        $this->command?->newLine();
        $this->command?->line('  Skenario lengkap + hasil yang diharapkan: <fg=cyan>docs/uat/titipan-pokok.md</>');
        $this->command?->line('  Login petugas : petugas@kopekoma.test / password');
        $this->command?->line('  Login pengurus: pengurus@kopekoma.test / password');
        $this->command?->newLine();
    }
}
