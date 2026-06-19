<?php

namespace App\Services;

use App\Models\Member;
use Illuminate\Support\Carbon;

/**
 * Buku mutasi simpanan: menggabungkan setoran (masuk), pencairan yang sudah
 * `cair` (keluar), dan pemakaian Wajib Belanja (keluar) jadi satu daftar
 * kronologis dengan saldo berjalan — seperti rekening koran.
 *
 * Tanda efek saldo identik dengan SavingsBalanceService (D1): non-reversal
 * setoran = +, reversal-nya = −; pencairan/pemakaian = −, reversal-nya = +.
 * Total akhir = SavingsBalanceService::totalBalance().
 */
class SavingsMutationService
{
    private const SCALE = 2;

    private const TYPE_LABELS = [
        'pokok' => 'Pokok',
        'wajib' => 'Wajib',
        'sukarela' => 'Sukarela',
        'hari_raya' => 'Hari Raya',
        'wajib_belanja' => 'Wajib Belanja',
    ];

    /**
     * Mutasi anggota, default urut terbaru di atas; saldo berjalan tetap
     * dihitung kronologis (lama → baru).
     *
     * @return list<array{date:Carbon, number:string, source:string, type:string, type_label:string, description:string, masuk:string, keluar:string, saldo:string, is_reversal:bool}>
     */
    public function ledgerFor(Member $member, bool $newestFirst = true): array
    {
        $rows = collect();

        foreach ($member->savingsDeposits()->get() as $d) {
            $rows->push($this->normalize(
                $d->deposit_date, $d->created_at, $d->transaction_number, 'deposit',
                $d->savings_type, $d->amount, $d->is_reversal,
                $d->is_reversal ? 'Pembatalan setoran' : 'Setoran',
            ));
        }

        foreach ($member->savingsWithdrawals()->where('status', 'cair')->get() as $w) {
            $rows->push($this->normalize(
                $w->withdrawal_date, $w->created_at, $w->withdrawal_number, 'withdrawal',
                $w->savings_type, $w->amount, $w->is_reversal,
                $w->is_reversal ? 'Pembatalan pencairan' : 'Pencairan',
                // pencairan/pemakaian membalik tanda dasar (default keluar).
                outflow: true,
            ));
        }

        foreach ($member->shoppingTransactions()->get() as $s) {
            $rows->push($this->normalize(
                $s->transaction_date, $s->created_at, $s->transaction_number ?? '—', 'shopping',
                'wajib_belanja', $s->amount, $s->is_reversal,
                $s->is_reversal ? 'Pembatalan belanja toko' : 'Belanja Toko',
                outflow: true,
            ));
        }

        // Kronologis untuk saldo berjalan: tanggal, lalu created_at sebagai tiebreak.
        // Dua sortBy berurutan + stable sort (PHP 8) = multi-key yang andal.
        $ordered = $rows
            ->sortBy(fn (array $r) => $r['_created'])
            ->sortBy(fn (array $r) => $r['date']->timestamp)
            ->values();

        $running = '0';
        $ordered = $ordered->map(function (array $r) use (&$running): array {
            $running = bcadd($running, $r['_signed'], self::SCALE);
            $r['saldo'] = $running;

            unset($r['_signed'], $r['_created']);

            return $r;
        });

        return ($newestFirst ? $ordered->reverse()->values() : $ordered)->all();
    }

    /**
     * @return array{date:Carbon, number:string, source:string, type:string, type_label:string, description:string, masuk:string, keluar:string, saldo:string, is_reversal:bool, _signed:string, _created:int}
     */
    private function normalize(
        mixed $date,
        mixed $createdAt,
        string $number,
        string $source,
        string $type,
        mixed $amount,
        bool $isReversal,
        string $description,
        bool $outflow = false,
    ): array {
        $amount = bcadd((string) $amount, '0', self::SCALE);

        // Arah dasar: setoran = masuk; pencairan/belanja = keluar. Reversal membalik.
        $isInflow = $outflow ? $isReversal : ! $isReversal;
        $signed = $isInflow ? $amount : '-'.$amount;

        return [
            'date' => Carbon::parse($date),
            'number' => $number,
            'source' => $source,
            'type' => $type,
            'type_label' => self::TYPE_LABELS[$type] ?? $type,
            'description' => $description,
            'masuk' => $isInflow ? $amount : '0',
            'keluar' => $isInflow ? '0' : $amount,
            'is_reversal' => $isReversal,
            '_signed' => $signed,
            '_created' => Carbon::parse($createdAt)->getTimestamp(),
        ];
    }
}
