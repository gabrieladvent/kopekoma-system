<?php

namespace App\Livewire\Loan;

use App\Enums\InstallmentScheduleStatus;
use App\Enums\LoanStatus;
use App\Filament\Resources\LoanResource as Resource;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Loans extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $type = 'all';

    #[Url]
    public string $status = 'all';

    #[Url]
    public string $arrears = 'all'; // all | overdue

    // Modal koreksi salah-input
    public bool $showCorrect = false;

    public ?string $correctId = null;

    public string $correctReason = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Loan::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedArrears(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'type', 'status', 'arrears');
        $this->resetPage();
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== '' || $this->type !== 'all' || $this->status !== 'all' || $this->arrears !== 'all';
    }

    /** Koreksi salah-input hanya bila belum ada angsuran terbayar & punya ability. */
    public function canCorrect(Loan $record): bool
    {
        return Resource::canCorrect($record);
    }

    public function openCorrect(string $id): void
    {
        $record = Loan::findOrFail($id);
        abort_unless($this->canCorrect($record), 403);

        $this->correctId = $id;
        $this->correctReason = '';
        $this->resetErrorBag();
        $this->showCorrect = true;
    }

    public function closeCorrect(): void
    {
        $this->showCorrect = false;
        $this->reset('correctId', 'correctReason');
    }

    public function performCorrect(): void
    {
        $record = Loan::findOrFail($this->correctId);
        abort_unless($this->canCorrect($record), 403);

        $this->validate(
            ['correctReason' => ['required', 'string', 'min:5', 'max:65535']],
            [
                'correctReason.required' => 'Alasan koreksi wajib diisi.',
                'correctReason.min' => 'Alasan koreksi minimal 5 karakter.',
            ],
            ['correctReason' => 'alasan koreksi'],
        );

        if (Resource::hasPayments($record)) {
            $this->closeCorrect();
            $this->dispatch('toast', type: 'error', message: 'Pinjaman sudah punya angsuran terbayar — koreksi tidak dapat dilakukan.');

            return;
        }

        DB::transaction(function () use ($record): void {
            activity()
                ->performedOn($record)
                ->causedBy(auth()->id())
                ->event('koreksi')
                ->withProperties([
                    'loan_number' => $record->loan_number,
                    'member_id' => $record->member_id,
                    'principal_amount' => $record->principal_amount,
                ])
                ->log('Pembatalan salah-input pinjaman: '.$this->correctReason);

            // Record DIPERTAHANKAN sebagai histori (status → Dibatalkan); hanya
            // jadwal proyeksi yang dibuang agar tak terhitung tunggakan.
            //
            // Sebelumnya di sini `$record->delete()` — dan Loan TIDAK memakai
            // SoftDeletes, jadi itu hard-delete. Karena koreksi hanya boleh atas
            // pinjaman berstatus "Cair", SWP anggota sudah terpotong dan saldo
            // SWP diturunkan dari SUM(loans.swp_amount) — menghapus baris ini
            // melenyapkan simpanan yang benar-benar sudah dibayar anggota, tanpa
            // reversal entry. Nomor pinjaman (dari MAX()) juga jadi terpakai ulang.
            //
            // Selaras dgn LoanDetail::performCorrect & LoanResource::performCorrection.
            InstallmentSchedule::where('loan_id', $record->id)->delete();
            $record->update(['status' => LoanStatus::Dibatalkan]);
        });

        $this->closeCorrect();
        $this->dispatch('toast', type: 'success', message: 'Pinjaman dibatalkan — tetap tersimpan sebagai histori, jadwal dibersihkan, tercatat di audit.');
    }

    public function render(): View
    {
        $loans = Loan::query()
            ->with('member:id,member_number,full_name')
            ->withCount([
                'schedules as overdue_count' => fn ($q) => $q->overdue(),
                'schedules as schedules_total',
                'schedules as schedules_paid' => fn ($q) => $q->where('status', InstallmentScheduleStatus::Terbayar),
            ])
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where('loan_number', 'like', $term)
                    ->orWhereHas('member', fn ($m) => $m->where('full_name', 'like', $term)
                        ->orWhere('member_number', 'like', $term));
            })
            ->when($this->type !== 'all', fn ($q) => $q->where('loan_type', $this->type))
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->arrears === 'overdue', fn ($q) => $q->whereHas('schedules', fn ($s) => $s->overdue()))
            ->latest('created_at')
            ->paginate(10);

        return view('livewire.loan.loans', [
            'loans' => $loans,
            'loanTypes' => Resource::LOAN_TYPES,
            'stats' => $this->stats(),
        ])->layout('components.layouts.app', ['title' => 'Pinjaman']);
    }

    /**
     * Ringkasan ringkas untuk header (bento stats).
     *
     * @return array{active:int, settled:int, overdue:int, outstanding:string}
     */
    private function stats(): array
    {
        $active = Loan::query()->where('status', LoanStatus::Cair)->count();
        $settled = Loan::query()->where('status', LoanStatus::Lunas)->count();
        $overdue = InstallmentSchedule::query()->overdue()->count();

        // Sisa pokok kasar = Σ principal pinjaman aktif − Σ pokok terbayar (net reversal).
        $principalActive = (string) (Loan::query()->where('status', LoanStatus::Cair)->sum('principal_amount') ?? '0');
        $paidNet = (string) (DB::table('installments')
            ->join('loans', 'loans.id', '=', 'installments.loan_id')
            ->where('loans.status', LoanStatus::Cair)
            ->selectRaw('COALESCE(SUM((CASE WHEN installments.is_reversal = 0 THEN 1 ELSE -1 END) * loans.monthly_principal), 0) as net')
            ->value('net') ?? '0');

        $outstanding = bcsub($this->money($principalActive), $this->money($paidNet), 2);
        if (bccomp($outstanding, '0', 2) < 0) {
            $outstanding = '0.00';
        }

        return [
            'active' => $active,
            'settled' => $settled,
            'overdue' => $overdue,
            'outstanding' => $outstanding,
        ];
    }

    private function money(string $value): string
    {
        return bcadd($value, '0', 2);
    }
}
