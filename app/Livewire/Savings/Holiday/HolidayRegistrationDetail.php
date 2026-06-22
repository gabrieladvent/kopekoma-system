<?php

namespace App\Livewire\Savings\Holiday;

use App\Livewire\Concerns\InteractsWithAuditTrail;
use App\Models\MemberHolidaySaving;
use App\Models\SavingsDeposit;
use App\Services\SavingsBalanceService;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class HolidayRegistrationDetail extends Component
{
    use InteractsWithAuditTrail;
    use WithPagination;

    public int $holidayId;

    public function mount(MemberHolidaySaving $holiday): void
    {
        $this->authorize('view', $holiday);
        $this->holidayId = $holiday->id;
    }

    public function delete()
    {
        $registration = MemberHolidaySaving::findOrFail($this->holidayId);
        $this->authorize('delete', $registration);

        $registration->delete();
        session()->flash('toast', ['type' => 'success', 'message' => 'Pendaftaran Hari Raya dihapus.']);

        return $this->redirectRoute('savings.holiday', navigate: true);
    }

    protected function auditFieldLabel(string $key): string
    {
        return [
            'member_id' => 'Anggota',
            'period_year' => 'Tahun Program',
            'start_date' => 'Mulai Pengumpulan',
            'end_date' => 'Akhir Pengumpulan',
            'monthly_amount' => 'Nominal Bulanan',
            'is_active' => 'Aktif',
            'notes' => 'Catatan',
        ][$key] ?? $this->defaultAuditFieldLabel($key);
    }

    protected function formatAuditFieldValue(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if ($key === 'monthly_amount') {
            return 'Rp '.number_format((float) $value, 0, ',', '.');
        }

        if ($key === 'is_active') {
            return $value ? 'Aktif' : 'Non-Aktif';
        }

        return $this->defaultFormatAuditFieldValue($key, $value);
    }

    public function render(): View
    {
        $holiday = MemberHolidaySaving::with('member.agency', 'member.grade')->findOrFail($this->holidayId);

        $balance = app(SavingsBalanceService::class)->holidayBalance($holiday->member, $holiday->period_year);

        // Rekap setoran Hari Raya untuk tahun program ini (read-only — koreksi via reversal).
        $deposits = SavingsDeposit::query()
            ->where('member_id', $holiday->member_id)
            ->where('savings_type', 'hari_raya')
            ->whereYear('period_month', $holiday->period_year)
            ->orderByDesc('deposit_date')
            ->paginate(8, ['*'], 'depositsPage');

        $activities = $holiday->activities()->with('causer')->latest()->paginate(10);
        $selectedActivity = $this->auditId
            ? $holiday->activities()->with('causer')->find($this->auditId)
            : null;

        return view('livewire.savings.holiday.holiday-registration-detail', [
            'holiday' => $holiday,
            'balance' => $balance,
            'deposits' => $deposits,
            'activities' => $activities,
            'selectedActivity' => $selectedActivity,
            'diff' => $this->auditDiff($selectedActivity),
        ])->layout('components.layouts.app', ['title' => 'Detail Pendaftaran Hari Raya']);
    }
}
