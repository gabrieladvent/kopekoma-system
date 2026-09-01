<?php

namespace App\Livewire\Loan\Installment;

use App\Filament\Resources\InstallmentResource as Resource;
use App\Livewire\Concerns\InteractsWithAuditTrail;
use App\Models\Installment;
use App\Services\LoanPaymentService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class InstallmentDetail extends Component
{
    use InteractsWithAuditTrail;
    use WithPagination;

    public string $installmentId;

    public bool $showReverse = false;

    public string $reverseReason = '';

    public function mount(Installment $installment): void
    {
        $this->authorize('view', $installment);
        $this->installmentId = $installment->id;
    }

    public function canReverse(Installment $record): bool
    {
        return ! $record->is_reversal
            && ! $record->isReversed()
            && (auth()->user()?->can('reverse', $record) ?? false);
    }

    public function openReverse(): void
    {
        $record = Installment::findOrFail($this->installmentId);
        abort_unless($this->canReverse($record), 403);

        $this->reverseReason = '';
        $this->resetErrorBag();
        $this->showReverse = true;
    }

    public function closeReverse(): void
    {
        $this->showReverse = false;
        $this->reset('reverseReason');
    }

    public function performReverse(): void
    {
        $record = Installment::findOrFail($this->installmentId);
        abort_unless($this->canReverse($record), 403);

        $this->validate(
            ['reverseReason' => ['required', 'string', 'min:5', 'max:65535']],
            [
                'reverseReason.required' => 'Alasan reversal wajib diisi.',
                'reverseReason.min' => 'Alasan reversal minimal 5 karakter.',
            ],
            ['reverseReason' => 'alasan reversal'],
        );

        try {
            app(LoanPaymentService::class)->reverse($record, $this->reverseReason);

            $this->closeReverse();
            $this->dispatch('toast', type: 'success', message: 'Reversal berhasil — jadwal kembali Belum Bayar.');
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['reverseReason' => $e->getMessage()]);
        }
    }

    protected function auditFieldLabel(string $key): string
    {
        return [
            'loan_id' => 'Pinjaman',
            'schedule_id' => 'Jadwal',
            'installment_seq' => 'Angsuran ke',
            'payment_date' => 'Tgl Bayar',
            'due_date' => 'Jatuh Tempo',
            'principal_paid' => 'Pokok',
            'interest_paid' => 'Jasa',
            'time_deposit_saved' => 'Tab. Berjangka',
            'amount_paid' => 'Total Dibayar',
            'remaining_principal' => 'Sisa Pokok',
            'payment_method' => 'Metode Bayar',
            'notes' => 'Catatan',
            'is_reversal' => 'Reversal',
            // Titipan Pokok (ADR 2026-08-28 item 2g). Peta ini eksplisit per-layar
            // — kolom baru TIDAK terbaca otomatis, jadi tanpa baris-baris di bawah
            // jejak audit tampil dengan nama kolom mentah dan angka tak terformat.
            // Jejak audit adalah kontrol utama atas risiko loket yang diterima
            // sadar (R14/R19), jadi setengah jadi di sini bukan soal kosmetik.
            'mode' => 'Mode Alokasi',
            'credit_applied' => 'Titipan Pokok dipakai',
            'credit_before' => 'Titipan Pokok sebelum',
            'credit_after' => 'Titipan Pokok sesudah',
            'session_key' => 'Kunci Sesi',
            'blocking_installment' => 'Angsuran penghalang',
            'schedules_closed' => 'Angsuran ditutup',
            'credit_exhausted' => 'Titipan Pokok habis',
            'credit_leftover_to_sukarela' => 'Sisa titipan ke Sukarela',
            'credit_in_settlement' => 'Titipan Pokok tertahan pelunasan',
            'excess_to_sukarela' => 'Kelebihan uang ke Sukarela',
            'settled_principal' => 'Sisa pokok ditutup',
            'interest_charged' => 'Jasa ditagih',
            'interest_waived' => 'Jasa dibebaskan',
            'reversed_installment' => 'Angsuran dibatalkan',
            // `pay()` menulis properti `seq`, bukan `installment_seq`.
            'seq' => 'Angsuran ke',
        ][$key] ?? $this->defaultAuditFieldLabel($key);
    }

    protected function formatAuditFieldValue(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($key) {
            'principal_paid', 'interest_paid', 'time_deposit_saved',
            'amount_paid', 'remaining_principal',
            'credit_applied', 'credit_before', 'credit_after',
            'credit_leftover_to_sukarela', 'credit_in_settlement', 'excess_to_sukarela',
            'settled_principal', 'interest_charged', 'interest_waived' => 'Rp '.number_format((float) $value, 0, ',', '.'),
            'credit_exhausted' => $value ? 'Ya' : 'Tidak',
            'payment_method' => Resource::PAYMENT_METHODS[$value] ?? (string) $value,
            'mode' => LoanPaymentService::MODE_LABELS[$value] ?? (string) $value,
            default => $this->defaultFormatAuditFieldValue($key, $value),
        };
    }

    /**
     * Sisa pokok pinjaman — **satu sumber**, `Loan::settledPrincipal()`
     * (ADR 2026-08-28 item 2h, OQ-9).
     *
     * Versi lama menghitungnya dari **nomor urut jadwal**
     * (`principal_amount − seq × monthly_principal`), dan komentarnya mengklaim
     * itu konsisten dengan `settledPrincipal()`. Klaim itu menyimpan asumsi
     * tersembunyi yang tak pernah ditulis: **angsuran terbayar berurutan tanpa
     * lubang**. Membatalkan angsuran mana pun sudah bisa melubanginya sejak dulu;
     * ADR ini memperbesar peluangnya lewat pembatalan per-transaksi atas sesi
     * multi-angsuran (#3 lunas, #2 dibatalkan). Di keadaan itu layar menampilkan
     * sisa pokok yang lebih kecil dari kenyataan — dua angka berbeda dari aplikasi
     * yang sama.
     *
     * Ini **perbaikan bug lama yang menumpang secara sadar**: angka pada layar
     * detail berubah untuk data yang sudah ada, dan itu disengaja.
     */
    protected function remainingAfter(Installment $installment): string
    {
        if ($installment->is_settlement) {
            return '0.00';
        }

        // Didelegasikan, bukan dihitung ulang. Varian "sampai baris ini" sempat
        // dicoba dan justru melahirkan angka KETIGA: baris pembalik bernomor lebih
        // besar daripada angsuran yang dilihat, sehingga pembatalan tak terhitung
        // dan layar kembali menampilkan sisa pokok yang terlalu kecil. Satu sumber
        // menutup divergensinya untuk selamanya.
        return $installment->loan?->settledPrincipal() ?? '0.00';
    }

    /**
     * Angsuran lain dari SETORAN yang sama (ADR 2026-08-28 item 2d).
     *
     * Pembatalan tetap per-transaksi — memaksa satu sesi dibatalkan sepaket akan
     * menciptakan konsep yang sistem ini belum punya, dan tiap baris sudah punya
     * nomor transaksinya sendiri. Yang ditambahkan hanya pemberitahuan: agar
     * petugas tak membatalkan separuh penerimaan tunai tanpa sadar ada
     * pasangannya. Memberi tahu, bukan memaksa.
     *
     * @return list<string>
     */
    public function sessionSiblings(Installment $installment): array
    {
        if (blank($installment->session_key)) {
            return [];
        }

        return Installment::query()
            ->where('session_key', $installment->session_key)
            ->whereKeyNot($installment->getKey())
            ->orderBy('installment_number')
            ->pluck('installment_number')
            ->all();
    }

    public function render(): View
    {
        $installment = Installment::with(['loan.member.agency', 'recordedBy', 'reversalOf'])
            ->findOrFail($this->installmentId);

        $activities = $installment->activities()->with('causer')->latest()->paginate(8);
        $selectedActivity = $this->auditId
            ? $installment->activities()->with('causer')->find($this->auditId)
            : null;

        return view('livewire.loan.installment.installment-detail', [
            'installment' => $installment,
            'paymentMethodLabel' => Resource::PAYMENT_METHODS[$installment->payment_method] ?? $installment->payment_method,
            'breakdown' => $installment->breakdown(),
            'sessionSiblings' => $this->sessionSiblings($installment),
            'remaining' => $this->remainingAfter($installment),
            'bukti' => $installment->getFirstMedia('bukti'),
            'activities' => $activities,
            'selectedActivity' => $selectedActivity,
            'diff' => $this->auditDiff($selectedActivity),
        ])->layout('components.layouts.app', ['title' => 'Detail Angsuran']);
    }
}
