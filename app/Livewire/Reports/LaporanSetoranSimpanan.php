<?php

namespace App\Livewire\Reports;

use App\Exports\DepositReportExport;
use App\Filament\Pages\Concerns\LogsReportExport;
use App\Filament\Resources\SavingsDepositResource;
use App\Models\Agency;
use App\Models\Member;
use App\Services\DepositReportService;
use App\Support\ReportLetterhead;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LaporanSetoranSimpanan extends Component
{
    use LogsReportExport;

    /** Lihat/preview on-screen — petugas + pengurus (mirror Filament page). */
    public const PERMISSION = 'access_laporan_setoran';

    /** Export PDF/Excel — pengurus-only. */
    public const EXPORT_PERMISSION = 'export_laporan_setoran';

    public string $basis = DepositReportService::BASIS_PERIOD_MONTH;

    public string $start = '';

    public string $end = '';

    /** @var array<int, string> */
    public array $savings_type = [];

    public string $deposit_method = '';

    public string $agency_id = '';

    public string $member_id = '';

    /** Label anggota terpilih (untuk chip; member_id sendiri UUID). */
    public string $memberLabel = '';

    /** Kata kunci pencarian anggota (picker). */
    public string $memberSearch = '';

    /**
     * Filter yang sudah divalidasi & diterapkan (null = belum generate). Preview
     * membaca dari sini agar tabel tak berubah sebelum "Tampilkan" ditekan.
     *
     * @var array<string, mixed>|null
     */
    public ?array $appliedFilters = null;

    public function mount(): void
    {
        // Belt-and-suspenders dengan route middleware can:access_laporan_setoran.
        abort_unless(auth()->user()?->can(self::PERMISSION) ?? false, 403);

        $this->start = now()->startOfMonth()->toDateString();
        $this->end = now()->endOfMonth()->toDateString();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'basis' => ['required', 'in:'.DepositReportService::BASIS_PERIOD_MONTH.','.DepositReportService::BASIS_DEPOSIT_DATE],
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after_or_equal:start', function (string $attribute, mixed $value, \Closure $fail): void {
                if (blank($this->start) || blank($value)) {
                    return;
                }

                if (Carbon::parse($this->start)->diffInDays(Carbon::parse($value)) > 366) {
                    $fail('Rentang maksimum 1 tahun.');
                }
            }],
            'savings_type' => ['array'],
            'savings_type.*' => ['string', 'in:'.implode(',', array_keys(SavingsDepositResource::SAVINGS_TYPES))],
            'deposit_method' => ['nullable', 'in:'.implode(',', array_keys(SavingsDepositResource::DEPOSIT_METHODS))],
            'agency_id' => ['nullable', 'string'],
            'member_id' => ['nullable', 'string'],
        ];
    }

    public function canExport(): bool
    {
        return auth()->user()?->can(self::EXPORT_PERMISSION) ?? false;
    }

    /**
     * Hasil pencarian anggota untuk picker (maks 20; withTrashed agar anggota
     * resign tetap bisa dipilih untuk laporan historis).
     *
     * @return Collection<int, Member>
     */
    #[Computed]
    public function memberResults(): Collection
    {
        if (mb_strlen(trim($this->memberSearch)) < 2) {
            return collect();
        }

        return Member::query()
            ->withTrashed()
            ->where(fn ($q) => $q->where('full_name', 'like', "%{$this->memberSearch}%")
                ->orWhere('member_number', 'like', "%{$this->memberSearch}%"))
            ->orderBy('full_name')
            ->limit(20)
            ->get();
    }

    public function selectMember(string $id): void
    {
        $member = Member::withTrashed()->find($id);

        if ($member === null) {
            return;
        }

        $this->member_id = $member->id;
        $this->memberLabel = "{$member->member_number} — {$member->full_name}";
        $this->memberSearch = '';
    }

    public function clearMember(): void
    {
        $this->member_id = '';
        $this->memberLabel = '';
        $this->memberSearch = '';
    }

    public function generate(): void
    {
        $this->validate();

        $this->appliedFilters = $this->currentFilters();
    }

    public function exportExcel(): BinaryFileResponse
    {
        abort_unless($this->canExport(), 403);

        $this->validate();

        $filters = $this->currentFilters();
        $service = app(DepositReportService::class);
        $rows = $service->rows($filters);

        $this->logReportExport('setoran', 'excel', $filters, $rows->count());

        return Excel::download(
            new DepositReportExport($rows, $service->totals($filters)),
            'laporan-setoran-'.now()->format('Ymd-His').'.xlsx',
        );
    }

    public function exportPdf(): StreamedResponse
    {
        abort_unless($this->canExport(), 403);

        $this->validate();

        $filters = $this->currentFilters();
        $grouped = app(DepositReportService::class)->grouped($filters);

        $this->logReportExport('setoran', 'pdf', $filters, $this->countGroupedRows($grouped));

        $pdf = Pdf::loadView('reports.deposit-pdf', [
            'title' => 'Laporan Setoran Simpanan',
            'subtitle' => $this->filterSummary($filters),
            'kop' => ReportLetterhead::make(),
            'groups' => $grouped['groups'],
            'grandTotal' => $grouped['grand_total'],
            'generatedAt' => now(),
            'savingsTypeLabel' => fn (?string $t): string => $this->savingsTypeLabel($t),
            'depositMethodLabel' => fn (?string $m): string => $this->depositMethodLabel($m),
        ]);

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            'laporan-setoran-'.now()->format('Ymd-His').'.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Filter aktif dari state form, dinormalkan (kosong → null/[]) agar sentinel
     * ALL_OPD/ALL_MEMBER di audit log benar.
     *
     * @return array<string, mixed>
     */
    private function currentFilters(): array
    {
        return [
            'basis' => $this->basis,
            'start' => $this->start,
            'end' => $this->end,
            'savings_type' => array_values(array_filter($this->savings_type)),
            'deposit_method' => $this->deposit_method ?: null,
            'agency_id' => $this->agency_id ?: null,
            'member_id' => $this->member_id ?: null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filterSummary(array $filters): string
    {
        $parts = [
            'Basis: '.(($filters['basis'] ?? '') === DepositReportService::BASIS_DEPOSIT_DATE
                ? 'Tanggal Setor'
                : 'Periode Potong Gaji'),
            'Periode: '.Carbon::parse($filters['start'])->format('d/m/Y').' – '.Carbon::parse($filters['end'])->format('d/m/Y'),
        ];

        if (! empty($filters['savings_type'])) {
            $parts[] = 'Jenis: '.collect((array) $filters['savings_type'])
                ->map(fn (string $t): string => $this->savingsTypeLabel($t))
                ->implode(', ');
        }

        if (! empty($filters['deposit_method'])) {
            $parts[] = 'Metode: '.$this->depositMethodLabel($filters['deposit_method']);
        }

        if (! empty($filters['agency_id'])) {
            $parts[] = 'OPD: '.(Agency::find($filters['agency_id'])?->agency_name ?? $filters['agency_id']);
        }

        if (! empty($filters['member_id'])) {
            $member = Member::withTrashed()->find($filters['member_id']);
            $parts[] = 'Anggota: '.($member?->full_name ?? $filters['member_id']);
        }

        return implode('  |  ', $parts);
    }

    public function savingsTypeLabel(?string $type): string
    {
        return SavingsDepositResource::SAVINGS_TYPES[$type] ?? (string) $type;
    }

    public function depositMethodLabel(?string $method): string
    {
        return SavingsDepositResource::DEPOSIT_METHODS[$method] ?? (string) $method;
    }

    public function render(): View
    {
        $rows = collect();
        $total = '0';

        if ($this->appliedFilters !== null) {
            $service = app(DepositReportService::class);
            $rows = $service->rows($this->appliedFilters);
            $total = $service->totals($this->appliedFilters);
        }

        return view('livewire.reports.laporan-setoran-simpanan', [
            'rows' => $rows,
            'total' => $total,
            'agencyOptions' => Agency::orderBy('agency_name')->pluck('agency_name', 'id'),
            'savingsTypes' => SavingsDepositResource::SAVINGS_TYPES,
            'depositMethods' => SavingsDepositResource::DEPOSIT_METHODS,
        ])->layout('components.layouts.app', ['title' => 'Laporan Setoran Simpanan']);
    }
}
