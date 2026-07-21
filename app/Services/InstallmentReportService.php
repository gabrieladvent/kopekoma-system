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
     * Baris ter-grup OPD → anggota untuk PDF, dengan subtotal per anggota, per
     * OPD, dan grand total (semua bcmath, net of reversal). Dibangun dari rows()
     * — satu query — jadi grand total konsisten dengan totals(). OPD/anggota
     * ditelusuri lewat rantai loan.member.agency.
     *
     * @param  array<string, mixed>  $filters
     * @return array{groups: array<int, array<string, mixed>>, grand_total: string}
     */
    public function grouped(array $filters): array
    {
        $rows = $this->rows($filters);

        $groups = [];
        $grand = '0';

        foreach ($rows->groupBy(fn (Installment $r): string => $r->loan?->member?->agency?->agency_name ?? '—') as $agency => $agencyRows) {
            $members = [];
            $agencySubtotal = '0';

            foreach ($agencyRows->groupBy(fn (Installment $r): string => (string) ($r->loan?->member_id ?? 0)) as $memberRows) {
                $member = $memberRows->first()?->loan?->member;
                $memberSubtotal = '0';

                foreach ($memberRows as $r) {
                    $memberSubtotal = bcadd($memberSubtotal, (string) $r->signed_amount, self::SCALE);
                }

                $members[] = [
                    'number' => $member?->member_number,
                    'name' => $member?->full_name,
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
