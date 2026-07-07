<?php

namespace App\Services;

use App\Models\SavingsDeposit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DepositReportService
{
    private const SCALE = 2;

    /** Basis default = rekonsiliasi payroll (period_month, sudah ter-index). */
    public const BASIS_PERIOD_MONTH = 'period_month';

    /** Basis alternatif untuk sukarela/hari_raya (period_month NULL). */
    public const BASIS_DEPOSIT_DATE = 'deposit_date';

    /**
     * Baris detail — SEMUA baris termasuk reversal (transparansi audit), dengan
     * `signed_amount` per baris (reversal → negatif). Member di-eager-load
     * `withTrashed` agar anggota resign tetap tampil di laporan historis (kalau
     * tidak → under-count saat rekonsiliasi).
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, SavingsDeposit>
     */
    public function rows(array $filters): Collection
    {
        return $this->baseQuery($filters)
            ->select('savings_deposits.*')
            ->selectRaw('CASE WHEN is_reversal = 0 THEN amount ELSE -amount END as signed_amount')
            ->with(['member' => fn ($q) => $q->withTrashed()->with('agency')])
            ->orderBy($this->basis($filters))
            ->orderBy('transaction_number')
            ->get();
    }

    /**
     * Grand total net (terbayar − reversal), bcmath. Agregat TERPISAH dari rows()
     * — mirror idiom SavingsBalanceService::allBalances() (grouped signed net),
     * bukan reuse scopeSignedAmount yang membuang kolom detail.
     *
     * @param  array<string, mixed>  $filters
     */
    public function totals(array $filters): string
    {
        $net = $this->baseQuery($filters)
            ->selectRaw('COALESCE(SUM(CASE WHEN is_reversal = 0 THEN amount ELSE -amount END), 0) as net')
            ->value('net');

        return bcadd((string) ($net ?? '0'), '0', self::SCALE);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<SavingsDeposit>
     */
    private function baseQuery(array $filters): Builder
    {
        $basis = $this->basis($filters);

        $query = SavingsDeposit::query()
            ->whereBetween($basis, [$filters['start'], $filters['end']]);

        // Basis period_month: baris NULL (sukarela/hari_raya) tak punya periode
        // payroll → dikecualikan (peringatan di UI). whereBetween sudah membuang
        // NULL, ini menegaskan niatnya.
        if ($basis === self::BASIS_PERIOD_MONTH) {
            $query->whereNotNull('period_month');
        }

        if (! empty($filters['savings_type'])) {
            $query->whereIn('savings_type', (array) $filters['savings_type']);
        }

        if (! empty($filters['deposit_method'])) {
            $query->where('deposit_method', $filters['deposit_method']);
        }

        // Filter OPD lewat member; withTrashed agar anggota resign tak hilang.
        if (! empty($filters['agency_id'])) {
            $query->whereHas('member', fn ($q) => $q->withTrashed()->where('agency_id', $filters['agency_id']));
        }

        if (! empty($filters['member_id'])) {
            $query->where('member_id', $filters['member_id']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function basis(array $filters): string
    {
        return ($filters['basis'] ?? self::BASIS_PERIOD_MONTH) === self::BASIS_DEPOSIT_DATE
            ? self::BASIS_DEPOSIT_DATE
            : self::BASIS_PERIOD_MONTH;
    }
}
