<?php

namespace App\Livewire\Reports;

use App\Enums\InstallmentScheduleStatus;
use App\Enums\LoanStatus;
use App\Models\Agency;
use App\Models\Loan;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Laporan Titipan Pokok — tampilan AGREGAT (ADR 2026-08-28 item 2i).
 *
 * Panel Riwayat di halaman detail pinjaman menjawab *ke mana titipan anggota
 * ini pergi*. Ia tak bisa menjawab *anggota mana yang perlu diperiksa*. Selama
 * hanya itu yang ada, satu-satunya cara menemukan kejanggalan adalah membuka
 * ratusan halaman satu per satu — yang praktis berarti tak ada yang akan
 * menemukannya.
 *
 * Itu penting karena R14 (petugas loket mencatat tagihan efektif sementara
 * anggota menyerahkan tagihan kontrak, lalu mengantongi selisihnya) **diterima
 * secara sadar**, dengan alasan pengamannya adalah pendeteksian pasca-kejadian.
 * Pendeteksian yang baru bisa dipakai setelah pemeriksa curiga lebih dulu bukan
 * pendeteksian — itu konfirmasi. Halaman ini bagian yang memunculkan.
 *
 * Read-only sepenuhnya: tak ada aksi, tak ada ekspor, tak ada nomor dokumen.
 * Seluruh angkanya turunan dari riwayat angsuran yang sudah ada.
 */
class LaporanTitipanPokok extends Component
{
    /**
     * Pengurus, bukan Petugas. Ini kanal pemeriksaan atas risiko yang pelakunya
     * justru bisa Petugas; menaruhnya di tangan yang diperiksa menghapus
     * gunanya. Bukan soal kerahasiaan — soal siapa yang bertugas memeriksa.
     */
    public const PERMISSION = 'access_laporan_titipan';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $agency = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can(self::PERMISSION) ?? false, 403);
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'agency');
    }

    public function hasActiveFilters(): bool
    {
        return filled($this->search) || filled($this->agency);
    }

    /**
     * Baris laporan: satu per PINJAMAN bertitipan, bukan per anggota.
     *
     * Titipan melekat pada pinjaman, bukan pada orang — anggota dengan dua
     * pinjaman berjalan punya dua kantong terpisah yang tak bisa saling
     * memakai. Menggabungkannya jadi satu baris per anggota menampilkan angka
     * yang tak pernah bisa dipakai membayar apa pun.
     *
     * Tiga query, berapa pun jumlah pinjamannya: pinjaman (+ anggota, OPD,
     * jadwal belum bayar) dan satu agregat titipan. Tak ada pemanggilan
     * per-baris — laporan ini memindai seluruh pinjaman aktif, jadi N+1 di sini
     * bukan ketidakrapian melainkan perbedaan antara halaman yang terbuka dan
     * halaman yang time-out.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        // Hanya pinjaman Cair yang bisa bertitipan — Lunas dan Dibatalkan
        // dijawab 0.00 oleh rumusnya sendiri, jadi memuatnya hanya menambah
        // beban tanpa menambah baris.
        /** @var Collection<int, Loan> $loans */
        $loans = Loan::query()
            ->where('status', LoanStatus::Cair)
            ->with([
                'member.agency',
                'schedules' => fn ($query) => $query
                    ->where('status', InstallmentScheduleStatus::BelumBayar)
                    ->orderBy('installment_seq'),
            ])
            ->get();

        // Satu query agregat untuk seluruh pinjaman, bukan satu per pinjaman.
        // Rumusnya tetap hidup di satu tempat — lihat Loan::overpaymentCredits().
        $credits = Loan::overpaymentCredits($loans);

        $rows = [];

        foreach ($loans as $loan) {
            $credit = $credits[$loan->getKey()] ?? '0.00';

            if (bccomp($credit, '0', 2) <= 0) {
                continue;
            }

            if (! $this->matchesFilters($loan)) {
                continue;
            }

            $next = $loan->schedules->first();

            $rows[] = [
                'loan_id' => $loan->getKey(),
                'loan_number' => $loan->loan_number,
                'member_name' => $loan->member?->full_name ?? '—',
                'member_number' => $loan->member?->member_number ?? '—',
                'agency' => $loan->member?->agency?->agency_name ?? '—',
                'credit' => $credit,
                'next_seq' => $next?->installment_seq,
                'contract_bill' => $next ? bcadd((string) $next->total_due, '0', 2) : null,
                // Tagihan efektif angsuran berikutnya — angka yang benar-benar
                // akan diminta di loket. Selisihnya terhadap tagihan kontrak
                // adalah persis besaran yang bisa dikantongi (R14), jadi ia
                // ditampilkan berdampingan, bukan disembunyikan.
                'effective_bill' => $next ? $loan->effectiveBillWithCredit($next, $credit) : null,
            ];
        }

        usort($rows, fn (array $a, array $b): int => bccomp($b['credit'], $a['credit'], 2));

        return $rows;
    }

    private function matchesFilters(Loan $loan): bool
    {
        if (filled($this->agency) && $loan->member?->agency_id !== $this->agency) {
            return false;
        }

        if (blank($this->search)) {
            return true;
        }

        $haystack = mb_strtolower(implode(' ', array_filter([
            $loan->member?->full_name,
            $loan->member?->member_number,
            $loan->loan_number,
        ])));

        return str_contains($haystack, mb_strtolower(trim($this->search)));
    }

    public function render(): View
    {
        $rows = $this->rows();

        $total = array_reduce(
            $rows,
            fn (string $carry, array $row): string => bcadd($carry, $row['credit'], 2),
            '0.00'
        );

        return view('livewire.reports.laporan-titipan-pokok', [
            'rows' => $rows,
            'total' => $total,
            'agencyOptions' => Agency::query()->orderBy('agency_name')->pluck('agency_name', 'id'),
        ])->layout('components.layouts.app', ['title' => 'Laporan Titipan Pokok']);
    }
}
