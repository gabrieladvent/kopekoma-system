<?php

namespace App\Services;

use App\Enums\WithdrawalStatus;
use App\Exceptions\CannotProcessWithdrawal;
use App\Models\Member;
use App\Models\SavingsWithdrawal;
use App\Models\User;
use App\Settings\CooperativeSettings;
use Illuminate\Support\Facades\DB;

/**
 * Engine pencairan (item 4b-1, D10) — state machine `draft → acc → cair/ditolak`
 * dengan validasi saldo dua-titik (ACC + Cair) dan serialize-lock anti-overdraw
 * saat disburse. Transisi & efek saldo terpusat di sini, bukan di Resource.
 *
 * `cair` & `ditolak` = terminal. Saldo baru berkurang saat `cair` (D1).
 */
class WithdrawalWorkflow
{
    /**
     * Jenis yang punya saldo riil & boleh dicairkan. SWP & Tabungan Berjangka
     * dibuka (revisi 2026-06-27) — saldo dititip modul Pinjaman, divalidasi via
     * SavingsBalanceService::canWithdraw seperti tipe lain.
     *
     * @var list<string>
     */
    public const WITHDRAWABLE_TYPES = ['hari_raya', 'sukarela', 'swp', 'tabungan_berjangka'];

    private const TIME_DEPOSIT_TYPE = 'tabungan_berjangka';

    /** Izin menembus jadwal Tabungan Berjangka — bawaan super_admin saja. */
    public const BYPASS_SCHEDULE_PERMISSION = 'bypass_time_deposit_schedule';

    /**
     * Status anggota yang dikecualikan dari jadwal Tabungan Berjangka.
     *
     * Anggota yang keluar atau meninggal butuh haknya sekarang, bukan menunggu
     * bulan SHU berikutnya. Ini kasus sah — memaksanya menempuh izin bypass akan
     * membuat izin itu jadi kebutuhan operasional harian, dan izin yang sering
     * dipakai berhenti berfungsi sebagai kontrol.
     */
    private const EXEMPT_MEMBER_STATUSES = ['Keluar', 'Meninggal'];

    public function __construct(private readonly SavingsBalanceService $balances) {}

    /**
     * `draft → acc` (Pengurus+). Cek saldo untuk umpan-balik dini; keputusan
     * otoritatif tetap di disburse (lock).
     */
    public function approve(SavingsWithdrawal $withdrawal, ?int $causerId = null): SavingsWithdrawal
    {
        $causerId ??= auth()->id();

        $this->assertTransition($withdrawal, WithdrawalStatus::Acc);
        $this->assertSufficientBalance($withdrawal);

        return DB::transaction(function () use ($withdrawal, $causerId): SavingsWithdrawal {
            $locked = SavingsWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->getKey());

            $this->assertTransition($locked, WithdrawalStatus::Acc);

            $locked->forceFill([
                'status' => WithdrawalStatus::Acc,
                'approved_by' => $causerId,
                'approved_at' => now(),
            ])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($causerId)
                ->event('approved')
                ->log('Pencairan di-ACC');

            return $locked;
        });
    }

    /**
     * `acc → cair` (Pengurus+). Titik uang-keluar: serialize lock per anggota
     * melintasi cek-saldo → update agar dua pencairan konkuren tak over-draw
     * (D10). Lock-dependent → wajib diuji di MySQL (no-op di SQLite).
     */
    public function disburse(SavingsWithdrawal $withdrawal, ?int $causerId = null): SavingsWithdrawal
    {
        $causerId ??= auth()->id();

        $this->assertTransition($withdrawal, WithdrawalStatus::Cair);

        return DB::transaction(function () use ($withdrawal, $causerId): SavingsWithdrawal {
            // Serialisasi per anggota: kunci baris member dulu agar perhitungan
            // saldo → penetapan cair tak balapan dengan pencairan lain.
            Member::query()->whereKey($withdrawal->member_id)->lockForUpdate()->first();

            $locked = SavingsWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->getKey());

            $this->assertTransition($locked, WithdrawalStatus::Cair);
            $this->assertSufficientBalance($locked);
            $this->assertTimeDepositSchedule($locked, $causerId);

            $locked->forceFill([
                'status' => WithdrawalStatus::Cair,
                'disbursed_at' => now(),
            ])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($causerId)
                ->event('disbursed')
                ->withProperties(['amount' => $locked->amount])
                ->log('Pencairan dicairkan');

            return $locked;
        });
    }

    /**
     * `draft|acc → ditolak` (Pengurus+). Terminal — tak bisa di-reopen (cegah
     * bypass gate uang-keluar via edit).
     */
    public function reject(SavingsWithdrawal $withdrawal, ?int $causerId = null): SavingsWithdrawal
    {
        $causerId ??= auth()->id();

        $this->assertTransition($withdrawal, WithdrawalStatus::Ditolak);

        return DB::transaction(function () use ($withdrawal, $causerId): SavingsWithdrawal {
            $locked = SavingsWithdrawal::query()->lockForUpdate()->findOrFail($withdrawal->getKey());

            $this->assertTransition($locked, WithdrawalStatus::Ditolak);

            $locked->forceFill(['status' => WithdrawalStatus::Ditolak])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($causerId)
                ->event('rejected')
                ->log('Pencairan ditolak');

            return $locked;
        });
    }

    private function assertTransition(SavingsWithdrawal $withdrawal, WithdrawalStatus $to): void
    {
        // Daftar transisi sah hidup di enum (WithdrawalStatus::transitionsTo).
        if (! $withdrawal->status->canTransitionTo($to)) {
            throw CannotProcessWithdrawal::illegalTransition($withdrawal->status->value, $to->value);
        }
    }

    /**
     * Tabungan Berjangka dikembalikan **satu kali dalam setahun, bersamaan
     * pembagian SHU** (keputusan pengurus). Ditegakkan di sini — titik uang
     * keluar — dalam dua bagian yang keduanya perlu:
     *
     *   1. **Jendela.** Hanya boleh dicairkan pada bulan pembagian SHU
     *      (`CooperativeSettings::$shu_distribution_month`). Inilah yang membuat
     *      aturannya benar-benar "bersamaan SHU".
     *   2. **Sekali per periode.** Di dalam jendela pun hanya sekali per tahun.
     *      Tanpa ini, jendela sepanjang sebulan bisa dipakai mencairkan
     *      berkali-kali.
     *
     * **Bulan SHU belum ditetapkan (NULL)** → jatuh ke aturan lama: 12 bulan
     * berjalan sejak pencairan terakhir. Lebih longgar dan melayang per anggota
     * — satu orang cair Januari, yang lain Juli, keduanya sah "sekali setahun"
     * tapi tak satu pun bersamaan SHU. Dipakai supaya tak ada yang rusak sebelum
     * koperasi memutuskan bulannya, bukan karena ia setara.
     *
     * **Anggota Keluar / Meninggal DIKECUALIKAN sepenuhnya.** Mereka butuh
     * uangnya sekarang, bukan menunggu bulan SHU. Memaksa kasus sah ini menempuh
     * izin bypass akan membuat izin itu sering diminta — dan izin yang sering
     * dipakai berhenti berfungsi sebagai kontrol.
     *
     * **Bypass lewat permission, bukan lewat peran.** `disburse` sendiri sudah
     * Pengurus-only, jadi membebaskan Pengurus sama saja dengan tak punya aturan
     * — ia takkan pernah menolak siapa pun. Izinnya berdiri sendiri
     * (`bypass_time_deposit_schedule`, bawaan super_admin saja) dan pemakaiannya
     * meninggalkan jejak tersendiri: pertanyaan "kenapa Tab Berjangka anggota ini
     * cair di luar jadwal" selalu punya jawaban tertulis.
     */
    private function assertTimeDepositSchedule(SavingsWithdrawal $withdrawal, ?int $causerId): void
    {
        if ($withdrawal->savings_type !== self::TIME_DEPOSIT_TYPE) {
            return;
        }

        if (in_array($withdrawal->member?->status, self::EXEMPT_MEMBER_STATUSES, true)) {
            return;
        }

        $shuMonth = app(CooperativeSettings::class)->shu_distribution_month;

        $violation = $shuMonth === null
            ? $this->rollingYearViolation($withdrawal)
            : $this->shuWindowViolation($withdrawal, (int) $shuMonth);

        if ($violation === null) {
            return;
        }

        $user = $causerId !== null ? User::find($causerId) : auth()->user();

        if ($user?->can(self::BYPASS_SCHEDULE_PERMISSION) !== true) {
            throw $violation['exception'];
        }

        activity()
            ->performedOn($withdrawal)
            ->causedBy($causerId)
            ->event('pencairan_di_luar_jadwal')
            ->withProperties(['attributes' => [
                'savings_type' => self::TIME_DEPOSIT_TYPE,
                'amount' => $withdrawal->amount,
                'alasan' => $violation['alasan'],
                'last_disbursed_at' => $violation['last_disbursed_at'],
                'next_eligible_at' => $violation['next_eligible_at'],
            ]])
            ->log('Pencairan Tabungan Berjangka di luar jadwal');
    }

    /**
     * Aturan JENDELA SHU: harus di bulan pembagian SHU, dan sekali per tahun.
     *
     * @return array{exception:CannotProcessWithdrawal, alasan:string, last_disbursed_at:?string, next_eligible_at:string}|null
     */
    private function shuWindowViolation(SavingsWithdrawal $withdrawal, int $shuMonth): ?array
    {
        $now = now();

        $alreadyThisYear = SavingsWithdrawal::query()
            ->where('member_id', $withdrawal->member_id)
            ->where('savings_type', self::TIME_DEPOSIT_TYPE)
            ->where('status', WithdrawalStatus::Cair)
            ->where('is_reversal', false)
            ->whereKeyNot($withdrawal->getKey())
            ->whereDoesntHave('reversal')
            ->whereYear('disbursed_at', $now->year)
            ->orderByDesc('disbursed_at')
            ->first();

        // Jendela tahun ini; sudah lewat → jendela tahun depan.
        $window = $now->copy()->startOfYear()->month($shuMonth);
        $next = $window->copy();

        if ($now->month > $shuMonth || $alreadyThisYear !== null) {
            $next = $window->copy()->addYearNoOverflow();
        }

        if ($alreadyThisYear !== null) {
            $last = $alreadyThisYear->disbursed_at ?? $alreadyThisYear->withdrawal_date;

            return [
                'exception' => CannotProcessWithdrawal::timeDepositAlreadyThisYear(
                    $last?->translatedFormat('d M Y') ?? '—',
                    $next->translatedFormat('F Y'),
                ),
                'alasan' => 'sudah cair tahun ini',
                'last_disbursed_at' => $last?->toDateString(),
                'next_eligible_at' => $next->toDateString(),
            ];
        }

        if ($now->month === $shuMonth) {
            return null;
        }

        return [
            'exception' => CannotProcessWithdrawal::timeDepositOutsideShuWindow(
                $window->translatedFormat('F'),
                $next->translatedFormat('F Y'),
            ),
            'alasan' => 'di luar bulan pembagian SHU',
            'last_disbursed_at' => null,
            'next_eligible_at' => $next->toDateString(),
        ];
    }

    /**
     * Aturan cadangan saat bulan SHU belum ditetapkan: 12 bulan berjalan sejak
     * pencairan terakhir yang cair dan belum dibalik.
     *
     * Pencairan yang SUDAH DIBALIK tidak menghalangi — uangnya kembali, jadi
     * jatahnya ikut terbuka lagi. `is_reversal = false` saja tidak cukup:
     * `ReverseTransaction` menyisipkan baris-lawan dan MEMBIARKAN baris aslinya
     * utuh (status tetap `Cair`), jadi baris asli tetap terjaring.
     *
     * @return array{exception:CannotProcessWithdrawal, alasan:string, last_disbursed_at:?string, next_eligible_at:string}|null
     */
    private function rollingYearViolation(SavingsWithdrawal $withdrawal): ?array
    {
        $last = SavingsWithdrawal::query()
            ->where('member_id', $withdrawal->member_id)
            ->where('savings_type', self::TIME_DEPOSIT_TYPE)
            ->where('status', WithdrawalStatus::Cair)
            ->where('is_reversal', false)
            ->whereKeyNot($withdrawal->getKey())
            ->whereDoesntHave('reversal')
            ->orderByDesc('disbursed_at')
            ->first();

        if ($last === null) {
            return null;
        }

        $lastAt = $last->disbursed_at ?? $last->withdrawal_date;

        if ($lastAt === null) {
            return null;
        }

        $nextEligible = $lastAt->copy()->addYearNoOverflow();

        if (now()->greaterThanOrEqualTo($nextEligible)) {
            return null;
        }

        return [
            'exception' => CannotProcessWithdrawal::timeDepositNotDueYet(
                $lastAt->translatedFormat('d M Y'),
                $nextEligible->translatedFormat('d M Y'),
            ),
            'alasan' => 'belum genap 12 bulan sejak pencairan terakhir',
            'last_disbursed_at' => $lastAt->toDateString(),
            'next_eligible_at' => $nextEligible->toDateString(),
        ];
    }

    private function assertSufficientBalance(SavingsWithdrawal $withdrawal): void
    {
        if (! in_array($withdrawal->savings_type, self::WITHDRAWABLE_TYPES, true)) {
            throw CannotProcessWithdrawal::unsupportedType((string) $withdrawal->savings_type);
        }

        $member = $withdrawal->member;
        $amount = (string) $withdrawal->amount;

        $ok = $withdrawal->savings_type === 'hari_raya'
            ? $this->balances->canWithdraw($member, 'hari_raya', $amount, (int) $withdrawal->period_year)
            : $this->balances->canWithdraw($member, $withdrawal->savings_type, $amount);

        if (! $ok) {
            throw CannotProcessWithdrawal::insufficientBalance();
        }
    }
}
