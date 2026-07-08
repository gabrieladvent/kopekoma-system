<?php

namespace App\Services;

use App\Models\Installment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class InstallmentReportService
{
    private const SCALE = 2;

    /**
     * Baris detail — SEMUA baris termasuk reversal, dengan `signed_amount` per
     * baris (reversal → negatif). Rantai loan.member.agency di-eager-load, member
     * `withTrashed` agar anggota resign tetap tampil.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Installment>
     */
    public function rows(array $filters): Collection
    {
        return $this->baseQuery($filters)
            ->select('installments.*')
            ->selectRaw('CASE WHEN is_reversal = 0 THEN amount_paid ELSE -amount_paid END as signed_amount')
            ->with(['loan.member' => fn ($q) => $q->withTrashed()->with('agency')])
            ->orderBy('payment_date')
            ->orderBy('installment_number')
            ->get();
    }

    /**
     * Grand total net (terbayar − reversal), bcmath. Agregat terpisah dari rows().
     *
     * @param  array<string, mixed>  $filters
     */
    public function totals(array $filters): string
    {
        $net = $this->baseQuery($filters)
            ->selectRaw('COALESCE(SUM(CASE WHEN is_reversal = 0 THEN amount_paid ELSE -amount_paid END), 0) as net')
            ->value('net');

        return bcadd((string) ($net ?? '0'), '0', self::SCALE);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Installment>
     */
    private function baseQuery(array $filters): Builder
    {
        $query = Installment::query()
            ->whereBetween('payment_date', [$filters['start'], $filters['end']]);

        // Angsuran tak punya member_id langsung — filter OPD via loan.member.
        if (! empty($filters['agency_id'])) {
            $query->whereHas('loan.member', fn ($q) => $q->withTrashed()->where('agency_id', $filters['agency_id']));
        }

        if (! empty($filters['member_id'])) {
            $query->whereHas('loan', fn ($q) => $q->where('member_id', $filters['member_id']));
        }

        return $query;
    }
}
