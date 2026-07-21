<?php

namespace App\Services;

use App\Enums\WithdrawalStatus;
use App\Exceptions\CannotProcessWithdrawal;
use App\Models\Member;
use App\Models\SavingsWithdrawal;
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
