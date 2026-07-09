<?php

namespace App\Livewire\Reports;

use App\Exports\InstallmentReportExport;
use App\Filament\Pages\Concerns\LogsReportExport;
use App\Models\Agency;
use App\Models\Member;
use App\Services\InstallmentReportService;
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

class LaporanAngsuranPinjaman extends Component
{
    use LogsReportExport;

    /** Lihat/preview on-screen — petugas + pengurus (mirror Filament page). */
    public const PERMISSION = 'access_laporan_angsuran';

    /** Export PDF/Excel — pengurus-only. */
    public const EXPORT_PERMISSION = 'export_laporan_angsuran';

    public string $start = '';

    public string $end = '';

    public string $agency_id = '';

    public string $member_id = '';

    public string $memberLabel = '';

    public string $memberSearch = '';

    /**
     * Filter yang sudah diterapkan (null = belum generate).
     *
     * @var array<string, mixed>|null
     */
    public ?array $appliedFilters = null;

    public function mount(): void
    {
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
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after_or_equal:start', function (string $attribute, mixed $value, \Closure $fail): void {
                if (blank($this->start) || blank($value)) {
                    return;
                }

                if (Carbon::parse($this->start)->diffInDays(Carbon::parse($value)) > 366) {
                    $fail('Rentang maksimum 1 tahun.');
                }
            }],
            'agency_id' => ['nullable', 'string'],
            'member_id' => ['nullable', 'string'],
        ];
    }

    public function canExport(): bool
    {
        return auth()->user()?->can(self::EXPORT_PERMISSION) ?? false;
    }

    /**
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
        $service = app(InstallmentReportService::class);
        $rows = $service->rows($filters);

        $this->logReportExport('angsuran', 'excel', $filters, $rows->count());

        return Excel::download(
            new InstallmentReportExport($rows, $service->totals($filters)),
            'laporan-angsuran-'.now()->format('Ymd-His').'.xlsx',
        );
    }

    public function exportPdf(): StreamedResponse
    {
        abort_unless($this->canExport(), 403);

        $this->validate();

        $filters = $this->currentFilters();
        $grouped = app(InstallmentReportService::class)->grouped($filters);

        $this->logReportExport('angsuran', 'pdf', $filters, $this->countGroupedRows($grouped));

        $pdf = Pdf::loadView('reports.installment-pdf', [
            'title' => 'Laporan Angsuran Pinjaman',
            'subtitle' => $this->filterSummary($filters),
            'kop' => ReportLetterhead::make(),
            'groups' => $grouped['groups'],
            'grandTotal' => $grouped['grand_total'],
            'generatedAt' => now(),
        ]);

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            'laporan-angsuran-'.now()->format('Ymd-His').'.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function currentFilters(): array
    {
        return [
            'start' => $this->start,
            'end' => $this->end,
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
            'Periode Bayar: '.Carbon::parse($filters['start'])->format('d/m/Y').' – '.Carbon::parse($filters['end'])->format('d/m/Y'),
        ];

        if (! empty($filters['agency_id'])) {
            $parts[] = 'OPD: '.(Agency::find($filters['agency_id'])?->agency_name ?? $filters['agency_id']);
        }

        if (! empty($filters['member_id'])) {
            $member = Member::withTrashed()->find($filters['member_id']);
            $parts[] = 'Anggota: '.($member?->full_name ?? $filters['member_id']);
        }

        return implode('  |  ', $parts);
    }

    public function render(): View
    {
        $rows = collect();
        $total = '0';

        if ($this->appliedFilters !== null) {
            $service = app(InstallmentReportService::class);
            $rows = $service->rows($this->appliedFilters);
            $total = $service->totals($this->appliedFilters);
        }

        return view('livewire.reports.laporan-angsuran-pinjaman', [
            'rows' => $rows,
            'total' => $total,
            'agencyOptions' => Agency::orderBy('agency_name')->pluck('agency_name', 'id'),
        ])->layout('components.layouts.app', ['title' => 'Laporan Angsuran Pinjaman']);
    }
}
