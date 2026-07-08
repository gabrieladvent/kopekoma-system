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
     * Baris ter-grup OPD → anggota untuk PDF, dengan subtotal per anggota, per
     * OPD, dan grand total (semua bcmath, net of reversal). Dibangun dari rows()
     * — satu query, tak ada re-query — jadi grand total konsisten dengan totals().
     *
     * @param  array<string, mixed>  $filters
     * @return array{groups: array<int, array<string, mixed>>, grand_total: string}
     */
    public function grouped(array $filters): array
    {
        $rows = $this->rows($filters);

        $groups = [];
        $grand = '0';

        foreach ($rows->groupBy(fn (SavingsDeposit $r): string => $r->member?->agency?->agency_name ?? '—') as $agency => $agencyRows) {
            $members = [];
            $agencySubtotal = '0';

            foreach ($agencyRows->groupBy(fn (SavingsDeposit $r): string => (string) ($r->member_id ?? 0)) as $memberRows) {
                $first = $memberRows->first();
                $memberSubtotal = '0';

                foreach ($memberRows as $r) {
                    $memberSubtotal = bcadd($memberSubtotal, (string) $r->signed_amount, self::SCALE);
                }

                $members[] = [
                    'number' => $first->member?->member_number,
                    'name' => $first->member?->full_name,
                    'rows' => $memberRows,
                    'subtotal' => $memberSubtotal,
                ];

                $agencySubtotal = bcadd($agencySubtotal, $memberSubtotal, self::SCALE);
            }

            $groups[] = [
                'agency' => (string) $agency,
                'members' => $members,
                'subtotal' => $agencySubtotal,
            ];

            $grand = bcadd($grand, $agencySubtotal, self::SCALE);
        }

        return ['groups' => $groups, 'grand_total' => $grand];
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
