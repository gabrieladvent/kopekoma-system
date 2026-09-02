<?php

namespace App\Livewire\Reports;

use App\Enums\LoanStatus;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsDeposit;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Rekonsiliasi SWP & Tabungan Berjangka — pembanding yang hilang.
 *
 * Sebelum keduanya jadi simpanan sungguhan, saldonya DITURUNKAN dari tabel
 * pinjaman: `SUM(loans.swp_amount)` dan `monthly_time_deposit × angsuran
 * terbayar`. Bentuk itu punya satu sifat yang tak disadari sampai ia dicabut —
 * **ia tak bisa dipalsukan.** Baris setoran liar atau pembalikan tak sah akan
 * langsung terlihat sebagai selisih terhadap data pinjaman.
 *
 * Sekarang saldonya adalah baris `savings_deposits` biasa, dan rumus pembanding
 * itu sudah tidak ada. Dua guard menjaga pintunya (daftar putih jenis setoran
 * dan larangan membalik sendirian), tapi guard hanya menutup pintu yang sudah
 * diketahui. Halaman ini yang menjawab pertanyaan berikutnya: *kalau ada pintu
 * yang belum diketahui, dari mana kita tahu?*
 *
 * Menampilkan HANYA yang tidak cocok. Halaman kosong adalah hasil yang benar,
 * dan itu memang yang diharapkan setiap hari — daftar panjang yang selalu sama
 * akan berhenti dibaca orang.
 */
class LaporanRekonsiliasiPinjaman extends Component
{
    /** Pengurus-only — ini kanal pemeriksaan, bukan layar operasional. */
    public const PERMISSION = 'access_laporan_titipan';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can(self::PERMISSION) ?? false, 403);
    }

    /**
     * Baris yang saldonya TIDAK cocok dengan data pinjaman.
     *
     * Empat query tetap, berapa pun jumlah anggotanya — bukan satu per anggota.
     * Laporan yang memindai seluruh koperasi tak boleh N+1.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        // Seharusnya: SWP = Σ swp_amount pinjaman yang TIDAK dibatalkan.
        // Pinjaman Dibatalkan dikecualikan karena setorannya memang ikut dibalik.
        $swpExpected = Loan::query()
            ->where('status', '!=', LoanStatus::Dibatalkan)
            ->groupBy('member_id')
            ->selectRaw('member_id, COALESCE(SUM(swp_amount), 0) as total')
            ->pluck('total', 'member_id');

        // Seharusnya: Tab Berjangka = monthly_time_deposit × angsuran terbayar
        // (net reversal, baris pelunasan dikecualikan) — rumus count-based yang
        // sama dengan `signedTimeDeposit()`.
        $tabExpected = Installment::query()
            ->join('loans', 'loans.id', '=', 'installments.loan_id')
            ->where('installments.is_settlement', false)
            ->groupBy('loans.member_id')
            ->selectRaw('loans.member_id as member_id')
            ->selectRaw('COALESCE(SUM(CASE WHEN installments.is_reversal = 0 THEN loans.monthly_time_deposit ELSE -loans.monthly_time_deposit END), 0) as total')
            ->pluck('total', 'member_id');

        $recorded = SavingsDeposit::query()
            ->whereIn('savings_type', ['swp', 'tabungan_berjangka'])
            ->groupBy('member_id', 'savings_type')
            ->selectRaw('member_id, savings_type')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_reversal = 0 THEN amount ELSE -amount END), 0) as total')
            ->get()
            ->groupBy('member_id');

        $memberIds = collect($swpExpected->keys())
            ->merge($tabExpected->keys())
            ->merge($recorded->keys())
            ->unique()
            ->values();

        if ($memberIds->isEmpty()) {
            return [];
        }

        $members = Member::query()->whereIn('id', $memberIds)->get()->keyBy('id');

        $rows = [];

        foreach ($memberIds as $id) {
            $byType = $recorded->get($id, collect())->keyBy('savings_type');

            $swp = $this->diff(
                (string) ($byType->get('swp')->total ?? '0'),
                (string) ($swpExpected->get($id) ?? '0'),
            );

            $tab = $this->diff(
                (string) ($byType->get('tabungan_berjangka')->total ?? '0'),
                (string) ($tabExpected->get($id) ?? '0'),
            );

            if ($swp['selisih'] === '0.00' && $tab['selisih'] === '0.00') {
                continue;
            }

            $member = $members->get($id);

            $rows[] = [
                'member_id' => $id,
                'member_name' => $member?->full_name ?? '—',
                'member_number' => $member?->member_number ?? '—',
                'swp' => $swp,
                'tabungan_berjangka' => $tab,
            ];
        }

        return $rows;
    }

    /**
     * @return array{tercatat:string, seharusnya:string, selisih:string}
     */
    private function diff(string $recorded, string $expected): array
    {
        $recorded = bcadd($recorded, '0', 2);
        $expected = bcadd($expected, '0', 2);

        return [
            'tercatat' => $recorded,
            'seharusnya' => $expected,
            'selisih' => bcsub($recorded, $expected, 2),
        ];
    }

    public function render(): View
    {
        $rows = $this->rows();

        return view('livewire.reports.laporan-rekonsiliasi-pinjaman', [
            'rows' => $rows,
            'memberCount' => Member::query()->count(),
        ])->layout('components.layouts.app', ['title' => 'Rekonsiliasi Simpanan Pinjaman']);
    }
}
