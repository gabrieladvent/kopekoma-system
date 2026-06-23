<?php

namespace App\Livewire\Savings\Holiday;

use App\Livewire\Concerns\WithMemberPicker;
use App\Models\MemberHolidaySaving;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Component;

class HolidayRegistrationForm extends Component
{
    use WithMemberPicker;

    public ?int $holidayId = null;

    public ?string $start_date = null;

    public ?string $end_date = null;

    public ?int $monthly_amount = null;

    public bool $is_active = true;

    public ?string $notes = null;

    public function mount(?MemberHolidaySaving $holiday = null): void
    {
        if ($holiday && $holiday->exists) {
            $this->authorize('update', $holiday);

            $this->holidayId = $holiday->id;
            $this->member_id = $holiday->member_id;
            $this->start_date = $holiday->start_date?->toDateString();
            $this->end_date = $holiday->end_date?->toDateString();
            $this->monthly_amount = $holiday->monthly_amount;
            $this->is_active = $holiday->is_active;
            $this->notes = $holiday->notes;
            $this->hydrateSelectedMember();

            return;
        }

        $this->authorize('create', MemberHolidaySaving::class);
        $this->start_date = now()->startOfYear()->toDateString();
        $this->end_date = now()->endOfYear()->toDateString();
    }

    /**
     * Tahun program diturunkan dari tahun `end_date` (kunci pengelompokan saldo D1).
     */
    public function derivedYear(): ?int
    {
        return blank($this->end_date) ? null : (int) Carbon::parse($this->end_date)->year;
    }

    protected function rules(): array
    {
        return [
            'member_id' => [
                'required',
                'exists:members,id',
                Rule::unique('member_holiday_savings', 'member_id')
                    ->where('period_year', $this->derivedYear())
                    ->ignore($this->holidayId),
            ],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'monthly_amount' => ['required', 'integer', 'min:1'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:65535'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'member_id' => 'anggota',
            'start_date' => 'tanggal mulai',
            'end_date' => 'tanggal akhir',
            'monthly_amount' => 'nominal bulanan',
            'notes' => 'catatan',
        ];
    }

    protected function messages(): array
    {
        return [
            'member_id.unique' => 'Anggota ini sudah terdaftar pada tahun program tersebut.',
            'end_date.after_or_equal' => 'Tanggal akhir harus sama atau setelah tanggal mulai.',
        ];
    }

    public function save()
    {
        $this->holidayId
            ? $this->authorize('update', MemberHolidaySaving::findOrFail($this->holidayId))
            : $this->authorize('create', MemberHolidaySaving::class);

        $validated = $this->validate();

        $data = [
            'member_id' => $validated['member_id'],
            'period_year' => $this->derivedYear(),
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'monthly_amount' => $validated['monthly_amount'],
            'is_active' => $this->is_active,
            'notes' => $validated['notes'] ?: null,
        ];

        if ($this->holidayId) {
            $registration = MemberHolidaySaving::findOrFail($this->holidayId);
            $registration->update($data);
            session()->flash('toast', ['type' => 'success', 'message' => 'Pendaftaran Hari Raya diperbarui.']);
        } else {
            $registration = MemberHolidaySaving::create($data);
            session()->flash('toast', ['type' => 'success', 'message' => 'Pendaftaran Hari Raya ditambahkan.']);
        }

        return $this->redirectRoute('savings.holiday.show', $registration, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.savings.holiday.holiday-registration-form')
            ->layout('components.layouts.app', [
                'title' => $this->holidayId ? 'Edit Pendaftaran Hari Raya' : 'Daftar Hari Raya',
            ]);
    }
}
