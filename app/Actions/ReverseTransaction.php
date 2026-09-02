<?php

namespace App\Actions;

use App\Contracts\Reversible;
use App\Exceptions\CannotReverseTransaction;
use App\Filament\Resources\SavingsDepositResource;
use App\Models\SavingsDeposit;
use App\Models\SavingsWithdrawal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ReverseTransaction
{
    private const INACTIVE_STATUSES = ['Keluar', 'Meninggal'];

    /**
     * @template T of Model&Reversible
     *
     * @param  T  $original
     * @param  int|string|false|null  $causerId  user id pelaku. `false` (default) =
     *                                           resolve dari auth user (jalur Filament). `null` eksplisit = anonim/tanpa
     *                                           causer (jalur API store_api tanpa User — ADR D6/D8).
     * @param  bool  $allowInactiveMember  `true` HANYA untuk reverse yang MENGEMBALIKAN
     *                                     dana ke anggota (mis. debit angsuran-dari-simpanan,
     *                                     ADR 2026-07-22 item 1d): membalik debit = menaikkan
     *                                     saldo anggota → selalu boleh walau anggota Keluar/Meninggal.
     * @param  bool  $allowLoanPairedDeposit  `true` HANYA dari `LoanSavingsService`.
     *                                        Setoran `swp`/`tabungan_berjangka` TAK boleh dibalik
     *                                        terpisah — kalau lolos, saldo simpanan anggota turun
     *                                        sementara pinjaman & angsurannya tetap berdiri.
     * @param  bool  $allowPairedInstallmentDebit  `true` HANYA dari `LoanPaymentService::reverse`
     *                                             (konteks `Installment::reverse`). Debit angsuran-dari-simpanan
     *                                             (SavingsWithdrawal ber-`installment_id`) TAK boleh dibalik
     *                                             terpisah — kalau lolos, saldo pulih tapi angsuran tetap
     *                                             terbayar = angsuran gratis. Guard layer-mutasi (ADR 2026-07-22
     *                                             item 1f) mencegah caller baru (command/API/bulk) membocorkannya.
     * @return T baris reversal yang dibuat
     */
    public function __invoke(Model&Reversible $original, string $reason, int|string|false|null $causerId = false, bool $allowInactiveMember = false, bool $allowPairedInstallmentDebit = false, bool $allowLoanPairedDeposit = false): Model
    {
        if ($causerId === false) {
            $causerId = auth()->id();
        }

        if ($original->is_reversal === true) {
            throw CannotReverseTransaction::isAReversal();
        }

        if (mb_strlen(trim($reason)) < 5) {
            throw CannotReverseTransaction::reasonRequired();
        }

        if (! $allowPairedInstallmentDebit && $original instanceof SavingsWithdrawal && $original->installment_id !== null) {
            throw CannotReverseTransaction::pairedInstallmentDebit();
        }

        // Kembaran aturan di atas, untuk sisi SETORAN. Baris `swp` dan
        // `tabungan_berjangka` adalah pasangan dari pencairan pinjaman dan
        // pembayaran angsuran. Dibalik sendirian, saldo simpanan anggota turun
        // sementara pinjaman & angsurannya tetap berdiri — penghapusan hak
        // anggota, bukan koreksi, dan cukup dilakukan satu Petugas tanpa mata
        // kedua. Pembatalannya harus lewat pembatalan pinjaman atau reversal
        // angsuran, yang memanggil ini dengan flag di bawah.
        if (! $allowLoanPairedDeposit
            && $original instanceof SavingsDeposit
            && in_array($original->savings_type, SavingsDepositResource::LOAN_OWNED_TYPES, true)) {
            throw CannotReverseTransaction::loanOwnedDeposit((string) $original->savings_type);
        }

        if (! $allowInactiveMember && in_array($original->member?->status, self::INACTIVE_STATUSES, true)) {
            throw CannotReverseTransaction::memberInactive();
        }

        return DB::transaction(function () use ($original, $reason, $causerId): Model {
            /** @var Model&Reversible $locked */
            $locked = $original->newQuery()->lockForUpdate()->findOrFail($original->getKey());

            if ($locked->is_reversal === true) {
                throw CannotReverseTransaction::isAReversal();
            }

            $attributes = [
                ...$locked->reverseClone(),
                'is_reversal' => true,
                'reversal_of_id' => $locked->getKey(),
                'notes' => $reason,
                'recorded_by' => $causerId,
            ];

            try {
                $reversal = $locked->newInstance()->forceFill($attributes);

                $reversal->save();
            } catch (UniqueConstraintViolationException $e) {
                throw CannotReverseTransaction::alreadyReversed();
            }

            activity()
                ->performedOn($reversal)
                ->causedBy($causerId)
                ->event('reversal')
                ->withProperties([
                    'reversal_of_id' => $locked->getKey(),
                    'amount' => $locked->amount,
                ])
                ->log("Reversal: {$reason}");

            return $reversal;
        });
    }
}
