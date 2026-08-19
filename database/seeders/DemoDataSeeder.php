<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Grade;
use App\Models\Installment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\LoanBlacklist;
use App\Models\Member;
use App\Models\MemberHolidaySaving;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use App\Models\ShoppingTransaction;
use App\Models\User;
use App\Services\LoanCalculator;
use App\Services\LoanPaymentService;
use App\Settings\CooperativeSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Data demo untuk presentasi / UAT — BUKAN untuk produksi.
 *
 * Membangun satu koperasi mini yang lengkap: 3 OPD, 12 anggota lintas golongan,
 * simpanan 6 bulan terakhir (pokok/wajib/sukarela/hari raya/wajib belanja), dan
 * 5 pinjaman yang sengaja dibuat berbeda kondisi supaya tiap fitur punya kasus
 * nyata untuk didemokan:
 *
 *   A. Pinjaman SEHAT      — angsuran lancar, tidak nunggak.
 *   B. Pinjaman NUNGGAK    — 5 angsuran terlewat → muncul di widget tunggakan.
 *   C. Pinjaman SEBRAKAN   — jangka pendek, belum dibayar.
 *   D. Siap BAYAR DARI SIMPANAN — anggota punya saldo sukarela besar, ada satu
 *      jadwal jatuh tempo yang belum dibayar.
 *   E. Pinjaman LUNAS      — memicu draft pengembalian SWP + Tabungan Berjangka.
 *
 * Jalankan:
 *   php artisan migrate:fresh --seed && php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    private const DEMO_NIK_PREFIX = '33080';

    private User $petugas;

    private User $pengurus;

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('DemoDataSeeder tidak boleh dijalankan di produksi.');
        }

        if (Member::query()->where('nik', 'like', self::DEMO_NIK_PREFIX.'%')->exists()) {
            $this->command?->warn('Data demo sudah ada. Jalankan `php artisan migrate:fresh --seed` dulu bila mau membangun ulang.');

            return;
        }

        if (Grade::query()->doesntExist()) {
            $this->call(GradeSeeder::class);
        }

        $this->seedUsers();
        $this->seedCooperativeIdentity();

        $agencies = $this->seedAgencies();
        $members = $this->seedMembers($agencies);

        $this->seedSavings($members);
        $this->seedLoans($members);
        $this->seedBlacklist($members);
        $this->seedPendingWithdrawal($members);

        $this->report();
    }

    /**
     * Akun demo per peran — supaya beda hak akses bisa ditunjukkan saat presentasi
     * (mis. tombol Export & Bayar dari Simpanan hanya muncul untuk Pengurus).
     */
    private function seedUsers(): void
    {
        $this->pengurus = $this->makeUser('Bendahara Koperasi', 'pengurus@kopekoma.test', 'pengurus');
        $this->petugas = $this->makeUser('Petugas Input', 'petugas@kopekoma.test', 'petugas');
    }

    private function makeUser(string $name, string $email, string $role): User
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

    /**
     * Identitas koperasi untuk kop + blok tanda tangan laporan PDF. Hanya diisi
     * bila masih kosong — tidak menimpa data yang sudah diatur pengurus.
     */
    private function seedCooperativeIdentity(): void
    {
        $settings = app(CooperativeSettings::class);

        $settings->cooperative_address ??= 'Jl. Soekarno-Hatta No. 12, Kota Mungkid';
        $settings->cooperative_city ??= 'Magelang';
        $settings->cooperative_phone ??= '+62 293 788123';
        $settings->signatory_name ??= 'Dra. Endang Purwanti';
        $settings->signatory_position ??= 'Ketua KPRI KOPEKOMA';

        $settings->save();
    }

    /**
     * @return array<string, Agency>
     */
    private function seedAgencies(): array
    {
        $rows = [
            ['agency_code' => 'DINDIK', 'agency_name' => 'Dinas Pendidikan dan Kebudayaan Kab. Magelang', 'payroll_treasurer' => 'Suryani, S.E.', 'pic_phone_number' => '+62812345001'],
            ['agency_code' => 'DINKES', 'agency_name' => 'Dinas Kesehatan Kab. Magelang', 'payroll_treasurer' => 'Hartono, S.Sos.', 'pic_phone_number' => '+62812345002'],
            ['agency_code' => 'SETDA', 'agency_name' => 'Sekretariat Daerah Kab. Magelang', 'payroll_treasurer' => 'Wulandari, S.A.P.', 'pic_phone_number' => '+62812345003'],
        ];

        $agencies = [];

        foreach ($rows as $row) {
            $agencies[$row['agency_code']] = Agency::firstOrCreate(
                ['agency_code' => $row['agency_code']],
                $row + [
                    'address' => 'Kota Mungkid, Kabupaten Magelang',
                    'status' => 'Aktif',
                ],
            );
        }

        return $agencies;
    }

    /**
     * @param  array<string, Agency>  $agencies
     * @return array<string, Member>
     */
    private function seedMembers(array $agencies): array
    {
        $grades = Grade::query()->pluck('id', 'code');

        $rows = [
            ['key' => 'sri', 'name' => 'Sri Wahyuni, S.Pd.', 'gender' => 'P', 'grade' => 'GOL-3', 'agency' => 'DINDIK', 'position' => 'Guru Madya', 'join' => '2019-03-01'],
            ['key' => 'bambang', 'name' => 'Bambang Setiawan', 'gender' => 'L', 'grade' => 'GOL-2', 'agency' => 'DINDIK', 'position' => 'Staf Tata Usaha', 'join' => '2020-07-15'],
            ['key' => 'endang', 'name' => 'Dra. Endang Purwanti', 'gender' => 'P', 'grade' => 'GOL-4', 'agency' => 'DINKES', 'position' => 'Kepala Bidang', 'join' => '2016-01-04'],
            ['key' => 'agus', 'name' => 'Agus Riyanto, S.KM.', 'gender' => 'L', 'grade' => 'GOL-3', 'agency' => 'DINKES', 'position' => 'Penyuluh Kesehatan', 'join' => '2018-09-10'],
            ['key' => 'tri', 'name' => 'Tri Handayani', 'gender' => 'P', 'grade' => 'HR-THL', 'agency' => 'SETDA', 'position' => 'Tenaga Harian Lepas', 'join' => '2023-02-01', 'employment' => 'Honorer'],
            ['key' => 'slamet', 'name' => 'Slamet Widodo', 'gender' => 'L', 'grade' => 'GOL-1', 'agency' => 'DINDIK', 'position' => 'Penjaga Sekolah', 'join' => '2021-05-17'],
            ['key' => 'nur', 'name' => 'Nur Hidayah, A.Md.Keb.', 'gender' => 'P', 'grade' => 'GOL-2', 'agency' => 'DINKES', 'position' => 'Bidan Pelaksana', 'join' => '2020-11-02'],
            ['key' => 'joko', 'name' => 'Joko Susilo, S.H.', 'gender' => 'L', 'grade' => 'GOL-4', 'agency' => 'SETDA', 'position' => 'Kepala Bagian Hukum', 'join' => '2015-06-01'],
            ['key' => 'retno', 'name' => 'Retno Palupi, S.E.', 'gender' => 'P', 'grade' => 'GOL-3', 'agency' => 'SETDA', 'position' => 'Analis Keuangan', 'join' => '2019-08-19'],
            ['key' => 'fauzi', 'name' => 'Muhammad Fauzi', 'gender' => 'L', 'grade' => 'GOL-2', 'agency' => 'DINDIK', 'position' => 'Pengelola Data', 'join' => '2022-01-10'],
            ['key' => 'siti', 'name' => 'Siti Rohmah', 'gender' => 'P', 'grade' => 'HR-THL', 'agency' => 'DINKES', 'position' => 'Tenaga Administrasi', 'join' => '2023-04-03', 'employment' => 'Honorer'],
            ['key' => 'dwi', 'name' => 'Dwi Cahyono', 'gender' => 'L', 'grade' => 'GOL-1', 'agency' => 'SETDA', 'position' => 'Pengemudi', 'join' => '2021-09-06', 'status' => 'Non-Aktif'],
        ];

        $members = [];

        foreach ($rows as $i => $row) {
            $seq = $i + 1;
            $gradeId = $grades[$row['grade']] ?? $grades->first();

            $members[$row['key']] = Member::create([
                'full_name' => $row['name'],
                'birth_place' => 'Magelang',
                'birth_date' => Carbon::parse('1985-01-01')->addMonths($seq * 7)->toDateString(),
                'gender' => $row['gender'],
                'nik' => self::DEMO_NIK_PREFIX.str_pad((string) $seq, 11, '0', STR_PAD_LEFT),
                'nip' => '19'.str_pad((string) (800000 + $seq), 16, '0', STR_PAD_LEFT),
                'agency_id' => $agencies[$row['agency']]->id,
                'position' => $row['position'],
                'grade_id' => $gradeId,
                'mandatory_savings_amount' => Grade::find($gradeId)?->mandatory_savings_amount ?? 50000,
                'employment_status' => $row['employment'] ?? 'ASN',
                'payroll_account_number' => '3301'.str_pad((string) $seq, 8, '0', STR_PAD_LEFT),
                'bank_name' => 'Bank Jateng',
                'address' => 'Dusun Krajan RT 0'.($seq % 8 + 1).' RW 02, Kabupaten Magelang',
                'phone_number' => '+628123456'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
                'join_date' => $row['join'],
                'heir_name' => 'Ahli Waris '.Str::before($row['name'], ' '),
                'heir_relationship' => $row['gender'] === 'L' ? 'Istri' : 'Suami',
                'heir_phone_number' => '+628987654'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
                'status' => $row['status'] ?? 'Aktif',
            ]);
        }

        return $members;
    }

    /**
     * Simpanan 6 bulan terakhir. Wajib lewat potong gaji (mirip hasil batch per
     * OPD), sukarela & wajib belanja lewat setor sendiri.
     *
     * @param  array<string, Member>  $members
     */
    private function seedSavings(array $members): void
    {
        $settings = app(CooperativeSettings::class);
        $pokokAmount = (string) $settings->savings_pokok_amount;
        $belanjaAmount = (string) $settings->savings_wajib_belanja_amount;

        foreach ($members as $member) {
            // Simpanan pokok — sekali saat masuk keanggotaan.
            $this->deposit($member, 'pokok', $pokokAmount, Carbon::parse($member->join_date), null, 'setor_sendiri', 'anggota');

            // Simpanan wajib — 6 bulan terakhir, potong gaji.
            for ($back = 6; $back >= 1; $back--) {
                $period = Carbon::now()->startOfMonth()->subMonths($back);

                $this->deposit(
                    $member,
                    'wajib',
                    (string) $member->mandatory_savings_amount,
                    $period->copy()->day(25),
                    $period,
                );
            }
        }

        // Sukarela — nominal berbeda supaya daftar saldo anggota terlihat variatif.
        // Agus sengaja besar: dipakai demo "bayar angsuran dari saldo simpanan".
        $sukarela = [
            'agus' => ['500000', '750000', '1000000', '1000000'],
            'sri' => ['200000', '300000'],
            'joko' => ['1000000', '500000'],
            'retno' => ['250000', '250000', '250000'],
            'endang' => ['400000'],
        ];

        foreach ($sukarela as $key => $amounts) {
            foreach ($amounts as $i => $amount) {
                $this->deposit(
                    $members[$key],
                    'sukarela',
                    $amount,
                    Carbon::now()->subMonths(count($amounts) - $i)->day(12),
                    null,
                    'setor_sendiri',
                    'anggota',
                );
            }
        }

        // Wajib belanja — saldo prepaid untuk toko koperasi (6 bulan).
        foreach (['sri', 'nur', 'fauzi', 'joko'] as $key) {
            for ($back = 6; $back >= 1; $back--) {
                $period = Carbon::now()->startOfMonth()->subMonths($back);

                $this->deposit($members[$key], 'wajib_belanja', $belanjaAmount, $period->copy()->day(25), $period);
            }
        }

        // Pemakaian saldo wajib belanja (manual) — supaya saldo dua sisi terlihat.
        foreach ([['sri', '150000', 20], ['nur', '75000', 9], ['sri', '90000', 4]] as [$key, $amount, $daysAgo]) {
            ShoppingTransaction::create([
                'member_id' => $members[$key]->id,
                'amount' => $amount,
                'transaction_date' => Carbon::now()->subDays($daysAgo)->toDateString(),
                'source' => 'manual',
                'idempotency_key' => (string) Str::uuid(),
                'reference_number' => 'NOTA-'.Carbon::now()->subDays($daysAgo)->format('ymd').'-'.Str::upper(Str::random(4)),
                'notes' => 'Pembelian sembako di toko koperasi',
                'recorded_by' => $this->petugas->id,
            ]);
        }

        // Simpanan Hari Raya — pendaftaran per tahun + setorannya.
        $year = (int) Carbon::now()->format('Y');

        foreach (['sri' => '100000', 'bambang' => '100000', 'nur' => '150000', 'retno' => '200000'] as $key => $monthly) {
            MemberHolidaySaving::create([
                'member_id' => $members[$key]->id,
                'period_year' => $year,
                'monthly_amount' => $monthly,
                'start_date' => Carbon::create($year, 1, 1)->toDateString(),
                'end_date' => Carbon::create($year, 11, 30)->toDateString(),
                'is_active' => true,
                'notes' => 'Pendaftaran simpanan hari raya '.$year,
            ]);

            for ($back = 4; $back >= 1; $back--) {
                $period = Carbon::now()->startOfMonth()->subMonths($back);

                $this->deposit($members[$key], 'hari_raya', $monthly, $period->copy()->day(25), $period);
            }
        }
    }

    private function deposit(
        Member $member,
        string $type,
        string $amount,
        Carbon $date,
        ?Carbon $period = null,
        string $method = 'potong_gaji',
        string $by = 'bendahara',
    ): SavingsDeposit {
        $deposit = SavingsDeposit::create([
            'idempotency_key' => (string) Str::uuid(),
            'member_id' => $member->id,
            'savings_type' => $type,
            'amount' => $amount,
            'deposit_date' => $date->toDateString(),
            'period_month' => $period?->copy()->startOfMonth()->toDateString(),
            'deposit_method' => $method,
            'deposited_by' => $by,
            'reference_number' => $method === 'potong_gaji' ? 'PG-'.$date->format('Ym') : null,
            'recorded_by' => $this->petugas->id,
        ]);

        // Timestamp dimundurkan supaya urutan riwayat & grafik masuk akal
        // (kolom timestamps bukan fillable, jadi tidak bisa lewat create()).
        SavingsDeposit::query()
            ->whereKey($deposit->getKey())
            ->update(['created_at' => $date, 'updated_at' => $date]);

        return $deposit;
    }

    /**
     * Lima pinjaman dengan kondisi berbeda — lihat docblock kelas.
     *
     * @param  array<string, Member>  $members
     */
    private function seedLoans(array $members): void
    {
        // A — sehat: 6 dari 12 angsuran terbayar, jatuh tempo berikutnya belum lewat.
        $loanA = $this->createLoan($members['sri'], 'jangka_panjang', '12000000', 12, Carbon::now()->subMonths(6)->day(10), 'transfer');
        $this->payFirst($loanA, 6, 'potong_gaji');

        // B — nunggak: 3 dari 24 terbayar, sisanya menumpuk lewat tempo.
        $loanB = $this->createLoan($members['bambang'], 'jangka_panjang', '24000000', 24, Carbon::now()->subMonths(8)->day(5), 'transfer');
        $this->payFirst($loanB, 3, 'potong_gaji');

        // C — Sebrakan (jangka pendek), belum dibayar.
        $this->createLoan($members['tri'], 'jangka_pendek', '1000000', 1, Carbon::now()->subDays(14), 'tunai');

        // D — siap didemokan "bayar angsuran dari saldo simpanan": ada satu jadwal
        //     jatuh tempo yang belum dibayar & anggotanya punya saldo sukarela.
        $loanD = $this->createLoan($members['agus'], 'jangka_panjang', '6000000', 12, Carbon::now()->subMonths(3)->day(12), 'tunai');
        $this->payFirst($loanD, 2, 'manual');

        // E — lunas: pembayaran terakhir otomatis membuat draft pengembalian SWP
        //     dan Tabungan Berjangka (menunggu ACC pengurus).
        $loanE = $this->createLoan($members['endang'], 'jangka_panjang', '6000000', 6, Carbon::now()->subMonths(7)->day(20), 'transfer');
        $this->payFirst($loanE, 6, 'potong_gaji');
    }

    private function createLoan(
        Member $member,
        string $type,
        string $principal,
        int $term,
        Carbon $disbursedAt,
        string $method,
    ): Loan {
        $calc = app(LoanCalculator::class);
        $firstDue = $disbursedAt->copy()->addMonthNoOverflow();

        $data = [
            'member_id' => $member->id,
            'loan_type' => $type,
            'principal_amount' => $principal,
            'term_months' => $term,
            'disbursement_date' => $disbursedAt->toDateString(),
            'first_due_date' => $firstDue->toDateString(),
            'disbursement_method' => $method,
            'disbursement_bank' => $method === 'transfer' ? 'Bank Jateng' : null,
            'disbursement_account_number' => $method === 'transfer' ? $member->payroll_account_number : null,
            'status' => 'Cair',
            'recorded_by' => $this->petugas->id,
        ];

        $data = array_merge($data, $calc->disbursement($type, $principal));
        $data = array_merge($data, $calc->monthlyConstants($type, $principal, $term));

        return DB::transaction(function () use ($data, $calc, $type, $principal, $term, $firstDue, $disbursedAt): Loan {
            /** @var Loan $loan */
            $loan = Loan::create($data);

            foreach ($calc->buildSchedule($type, $principal, $term, $firstDue) as $row) {
                InstallmentSchedule::create(['loan_id' => $loan->id] + $row);
            }

            $loan->forceFill(['created_at' => $disbursedAt, 'updated_at' => $disbursedAt])->saveQuietly();

            return $loan;
        });
    }

    /**
     * Bayar N jadwal pertama lewat service asli (bukan insert langsung) supaya
     * status jadwal, pelunasan otomatis, dan draft pengembalian ikut terpicu —
     * persis seperti kalau diinput petugas lewat aplikasi.
     */
    private function payFirst(Loan $loan, int $count, string $method): void
    {
        $service = app(LoanPaymentService::class);

        $schedules = InstallmentSchedule::query()
            ->where('loan_id', $loan->id)
            ->orderBy('installment_seq')
            ->limit($count)
            ->get();

        foreach ($schedules as $schedule) {
            // Dibayar beberapa hari setelah jatuh tempo — realistis untuk potong gaji.
            $paidAt = Carbon::parse($schedule->due_date)->addDays(2);

            $installment = $service->pay($schedule, [
                'amount_paid' => (string) $schedule->total_due,
                'payment_method' => $method,
                'payment_date' => $paidAt->toDateString(),
                'idempotency_key' => (string) Str::uuid(),
            ], $this->petugas->id);

            Installment::query()
                ->whereKey($installment->getKey())
                ->update(['created_at' => $paidAt, 'updated_at' => $paidAt]);
        }
    }

    /**
     * @param  array<string, Member>  $members
     */
    private function seedBlacklist(array $members): void
    {
        LoanBlacklist::create([
            'member_id' => $members['slamet']->id,
            'reason' => 'Menunggak angsuran pinjaman sebelumnya lebih dari 3 bulan berturut-turut.',
            'is_active' => true,
            'blacklisted_at' => Carbon::now()->subMonths(2)->toDateString(),
            'recorded_by' => $this->pengurus->id,
        ]);
    }

    /**
     * Satu pengajuan pencairan simpanan berstatus draft — bahan demo alur
     * persetujuan draft → ACC → cair (ACC hanya boleh Pengurus).
     *
     * @param  array<string, Member>  $members
     */
    private function seedPendingWithdrawal(array $members): void
    {
        SavingsWithdrawal::create([
            'idempotency_key' => (string) Str::uuid(),
            'member_id' => $members['joko']->id,
            'savings_type' => 'sukarela',
            'amount' => '500000',
            'withdrawal_date' => Carbon::now()->subDays(2)->toDateString(),
            'status' => 'draft',
            'disbursement_method' => 'tunai',
            'notes' => 'Pengajuan pencairan simpanan sukarela untuk keperluan keluarga.',
            'recorded_by' => $this->petugas->id,
        ]);
    }

    private function report(): void
    {
        $this->command?->info('Data demo siap:');
        $this->command?->line('  OPD            : '.Agency::count());
        $this->command?->line('  Anggota        : '.Member::count());
        $this->command?->line('  Setoran        : '.SavingsDeposit::count());
        $this->command?->line('  Pinjaman       : '.Loan::count().' (lunas: '.Loan::where('status', 'Lunas')->count().')');
        $this->command?->line('  Angsuran       : '.Installment::count());
        $this->command?->line('  Pencairan      : '.SavingsWithdrawal::count().' (draft menunggu ACC & refund pelunasan)');
        $this->command?->line('');
        $this->command?->line('  Login Pengurus : pengurus@kopekoma.test / password');
        $this->command?->line('  Login Petugas  : petugas@kopekoma.test / password');
    }
}
