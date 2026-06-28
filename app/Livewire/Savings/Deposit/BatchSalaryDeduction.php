<?php

namespace App\Livewire\Savings\Deposit;

use App\Filament\Resources\SavingsDepositResource as Resource;
use App\Models\Agency;
use App\Models\Member;
use App\Models\MemberHolidaySaving;
use App\Models\SavingsDeposit;
use App\Services\BatchSalaryDeductionService;
use App\Settings\CooperativeSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Component;

class BatchSalaryDeduction extends Component
{
    public const PERMISSION = 'access_batch_salary_deduction';

    public const EXPORT_PERMISSION = 'export_savings_recap';

    public ?string $agency_id = null;

    public ?string $period_month = null;

    /**
     * Baris per anggota: member_id, member_label, include, lines[] (jenis simpanan
     * dengan include/amount/done).
     *
     * @var list<array<string, mixed>>
     */
    public array $rows = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->can(self::PERMISSION) ?? false, 403);

        $this->period_month = now()->format('Y-m');
    }

    public function updatedAgencyId(): void
    {
        $this->rebuildRows();
    }

    public function updatedPeriodMonth(): void
    {
        $this->rebuildRows();
    }

    public function rebuildRows(): void
    {
        $this->rows = $this->buildRows($this->agency_id, $this->period_month);

        $this->dispatch('rows-updated');
    }

    public function setAllIncluded(bool $value): void
    {
        $this->rows = collect($this->rows)
            ->map(function (array $row) use ($value): array {
                $row['include'] = $value;

                return $row;
            })
            ->all();

        $this->dispatch('rows-updated');
    }

    /**
     * @return Collection<int, Agency>
     */
    public function agencies(): Collection
    {
        return Agency::query()->orderBy('agency_name')->get(['id', 'agency_code', 'agency_name']);
    }

    public function canExport(): bool
    {
        return auth()->user()?->can(self::EXPORT_PERMISSION) ?? false;
    }

    public function isLocked(string $type): bool
    {
        return in_array($type, Resource::LOCKED_AMOUNT_TYPES, true);
    }

    public function typeColor(string $type): string
    {
        return Resource::typeColor($type);
    }

    /**
     * Baris anggota aktif OPD terpilih. Tiap anggota membawa daftar jenis
     * simpanannya sendiri (`lines`) untuk diisi per-orang.
     *
     * @return list<array<string, mixed>>
     */
    protected function buildRows(?string $agencyId, mixed $period): array
    {
        if (blank($agencyId)) {
            return [];
        }

        return Member::query()
            ->where('agency_id', $agencyId)
            ->where('status', 'Aktif')
            ->orderBy('full_name')
            ->get(['id', 'member_number', 'full_name', 'mandatory_savings_amount'])
            ->map(fn (Member $m): array => [
                'member_id' => $m->id,
                'member_label' => "{$m->member_number} — {$m->full_name}",
                'include' => true,
                'lines' => $this->buildMemberTypeLines($m, $period),
            ])
            ->values()
            ->all();
    }

    /**
     * Jenis simpanan untuk satu anggota: wajib (prefill golongan), pokok &
     * wajib_belanja (ketentuan koperasi), + hari_raya bila ada program aktif yang
     * memuat periode. Default tercentang: wajib & hari_raya.
     *
     * @return list<array<string, mixed>>
     */
    protected function buildMemberTypeLines(Member $member, mixed $period): array
    {
        $settings = app(CooperativeSettings::class);

        $registration = $this->holidayRegistrationFor($member->getKey(), $period);

        $periodDate = filled($period) ? Carbon::parse($period)->startOfMonth()->toDateString() : null;

        $lines = [
            ['savings_type' => 'wajib', 'amount' => $member->mandatory_savings_amount === null ? null : (string) (int) round((float) $member->mandatory_savings_amount)],
            ['savings_type' => 'pokok', 'amount' => (string) (int) round((float) $settings->savings_pokok_amount)],
            ['savings_type' => 'wajib_belanja', 'amount' => (string) (int) round((float) $settings->savings_wajib_belanja_amount)],
        ];

        if ($registration !== null) {
            $lines[] = ['savings_type' => 'hari_raya', 'amount' => (string) (int) round((float) $registration->monthly_amount)];
        }

        return array_map(function (array $line) use ($member, $periodDate, $registration): array {
            $type = $line['savings_type'];

            $done = $this->typeAlreadyDeposited($member->getKey(), $type, $periodDate, $registration);

            return [
                'savings_type' => $type,
                'type_label' => Resource::SAVINGS_TYPES[$type],
                'include' => ! $done && in_array($type, Resource::DEFAULT_INCLUDED_TYPES, true),
                'amount' => $line['amount'],
                'done' => $done,
            ];
        }, $lines);
    }

    protected function typeAlreadyDeposited(string $memberId, string $type, ?string $periodDate, ?MemberHolidaySaving $registration): bool
    {
        if ($type === 'pokok') {
            return SavingsDeposit::hasActivePokok($memberId);
        }

        if ($type === 'hari_raya') {
            return $registration !== null
                && SavingsDeposit::hasActiveDeposit($memberId, 'hari_raya', sprintf('%04d-01-01', $registration->period_year));
        }

        return $periodDate !== null && SavingsDeposit::hasActiveDeposit($memberId, $type, $periodDate);
    }

    /**
     * Setoran satu jenis dengan nominal & periode ditegakkan server-side: wajib
     * pakai nominal baris (editable); pokok/wajib_belanja pakai ketentuan
     * koperasi; hari_raya pakai registrasi + periode = tahun program.
     *
     * @return array{type:string, amount:string, period_month:string}|null
     */
    protected function depositForType(string $memberId, string $type, string $period, ?string $rowAmount): ?array
    {
        $settings = app(CooperativeSettings::class);

        $periodDate = Carbon::parse($period)->startOfMonth()->toDateString();

        return match ($type) {
            'wajib' => ['type' => 'wajib', 'amount' => (string) ($rowAmount ?? '0'), 'period_month' => $periodDate],
            'pokok' => ['type' => 'pokok', 'amount' => (string) $settings->savings_pokok_amount, 'period_month' => $periodDate],
            'wajib_belanja' => ['type' => 'wajib_belanja', 'amount' => (string) $settings->savings_wajib_belanja_amount, 'period_month' => $periodDate],
            'hari_raya' => $this->hariRayaDeposit($memberId, $period),
            default => null,
        };
    }

    /**
     * @return array{type:string, amount:string, period_month:string}|null
     */
    private function hariRayaDeposit(string $memberId, string $period): ?array
    {
        $registration = $this->holidayRegistrationFor($memberId, $period);

        if ($registration === null) {
            return null;
        }

        return [
            'type' => 'hari_raya',
            'amount' => (string) $registration->monthly_amount,
            'period_month' => sprintf('%04d-01-01', $registration->period_year),
        ];
    }

    private function holidayRegistrationFor(string $memberId, mixed $period): ?MemberHolidaySaving
    {
        if (blank($period)) {
            return null;
        }

        return Resource::activeHolidayRegistration(
            $memberId,
            Carbon::parse($period)->startOfMonth()->toDateString(),
        );
    }

    public function process()
    {
        abort_unless(auth()->user()?->can(self::PERMISSION) ?? false, 403);

        $this->validate(
            [
                'agency_id' => ['required', 'exists:agencies,id'],
                'period_month' => ['required', 'date_format:Y-m'],
            ],
            [],
            ['agency_id' => 'OPD', 'period_month' => 'periode'],
        );

        $agency = Agency::findOrFail($this->agency_id);

        $rows = collect($this->rows)
            ->filter(fn (array $r): bool => (bool) ($r['include'] ?? false))
            ->map(function (array $r): array {
                $deposits = collect($r['lines'] ?? [])
                    ->filter(fn (array $line): bool => (bool) ($line['include'] ?? false) && ! ($line['done'] ?? false))
                    ->map(fn (array $line): ?array => $this->depositForType(
                        (string) $r['member_id'],
                        (string) $line['savings_type'],
                        $this->period_month,
                        $line['amount'] ?? null,
                    ))
                    ->filter()
                    ->values()
                    ->all();

                return ['member_id' => $r['member_id'], 'deposits' => $deposits];
            })
            ->filter(fn (array $row): bool => $row['deposits'] !== [])
            ->values()
            ->all();

        if ($rows === []) {
            $this->dispatch('toast', type: 'warning', message: 'Aktifkan minimal satu anggota dan centang minimal satu jenis simpanannya.');

            return null;
        }

        try {
            $result = app(BatchSalaryDeductionService::class)->run($agency, $this->period_month, $rows);
        } catch (\InvalidArgumentException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return null;
        }

        session()->flash('toast', ['type' => 'success', 'message' => "Batch selesai — {$result['created']} setoran dibuat, {$result['skipped']} dilewati (sudah disetor)."]);

        return $this->redirectRoute('savings.deposits', navigate: true);
    }

    public function render(): View
    {
        $includedMembers = collect($this->rows)->where('include', true)->count();

        return view('livewire.savings.deposit.batch-salary-deduction', [
            'agencies' => $this->agencies(),
            'includedMembers' => $includedMembers,
            'memberCount' => count($this->rows),
        ])->layout('components.layouts.app', ['title' => 'Batch Potong Gaji']);
    }
}
