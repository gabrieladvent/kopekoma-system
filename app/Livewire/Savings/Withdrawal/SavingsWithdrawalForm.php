<?php

namespace App\Livewire\Savings\Withdrawal;

use App\Filament\Resources\SavingsWithdrawalResource as Resource;
use App\Livewire\Concerns\WithMemberPicker;
use App\Models\Member;
use App\Models\SavingsWithdrawal;
use App\Services\SavingsBalanceService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class SavingsWithdrawalForm extends Component
{
    use WithMemberPicker;

    public ?string $withdrawal_date = null;

    public ?string $disbursement_method = null;

    public ?string $notes = null;

    public array $lines = [];

    public function mount(): void
    {
        $this->authorize('create', SavingsWithdrawal::class);

        $this->withdrawal_date = now()->toDateString();
    }

    /** Ganti/clear anggota → bangun ulang baris sumber dari saldo terkini. */
    protected function afterMemberSelected(): void
    {
        $this->rebuildLines();
    }

    public function rebuildLines(): void
    {
        $this->lines = [];
        $this->resetErrorBag('lines');

        if (blank($this->member_id)) {
            return;
        }

        $member = Member::find($this->member_id);

        if ($member === null) {
            return;
        }

        $service = app(SavingsBalanceService::class);
        $lines = [];

        // Sukarela — satu sumber, saldo akumulatif.
        $sukarela = $service->balanceByType($member, 'sukarela');
        if (bccomp($sukarela, '0', 2) > 0) {
            $lines[] = $this->makeLine('sukarela', null, 'Simpanan Sukarela', $sukarela);
        }

        // SWP & Tabungan Berjangka — saldo dititip modul Pinjaman (ADR D7), kini
        // dapat dicairkan manual. Saldo dasar dikurangi pencairan PENDING (draft+acc)
        // tipe sama (D3): refund auto saat pelunasan masih draft belum mengurangi
        // saldo, jadi tanpa ini pencairan manual bisa meng-klaim saldo yang sama →
        // refund dobel.
        $swp = $this->availablePendingAware($member, 'swp');
        if (bccomp($swp, '0', 2) > 0) {
            $lines[] = $this->makeLine('swp', null, 'SWP', $swp);
        }

        $tab = $this->availablePendingAware($member, 'tabungan_berjangka');
        if (bccomp($tab, '0', 2) > 0) {
            $lines[] = $this->makeLine('tabungan_berjangka', null, 'Tabungan Berjangka', $tab);
        }

        // Hari Raya — saldo per tahun program, tiap tahun jadi baris terpisah.
        foreach ($service->holidayBalancesByYear($member) as $year => $balance) {
            if (bccomp($balance, '0', 2) > 0) {
                $lines[] = $this->makeLine('hari_raya', (int) $year, 'Simpanan Hari Raya '.$year, $balance);
            }
        }

        $this->lines = $lines;
        $this->dispatch('lines-updated');
    }

    /**
     * Saldo tersedia tipe swp/tabungan_berjangka = saldo dasar − pencairan PENDING
     * (draft+acc, non-reversal) tipe sama milik anggota (D3). Floor 0. Mirror
     * SavingsWithdrawalResource::availableBalance.
     */
    protected function availablePendingAware(Member $member, string $type): string
    {
        $balance = app(SavingsBalanceService::class)->balanceByType($member, $type);

        $pending = (string) SavingsWithdrawal::query()
            ->where('member_id', $member->id)
            ->where('savings_type', $type)
            ->where('is_reversal', false)
            ->whereIn('status', ['draft', 'acc'])
            ->sum('amount');

        $available = bcsub($balance, $pending, 2);

        return bccomp($available, '0', 2) < 0 ? '0.00' : $available;
    }

    /**
     * @return array<string, mixed>
     */
    protected function makeLine(string $type, ?int $year, string $label, string $balance): array
    {
        return [
            'savings_type' => $type,
            'period_year' => $year,
            'type_label' => $label,
            'balance' => $balance,
            'include' => false,
            'amount' => null,
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    public function typeColor(string $type): string
    {
        return $type === 'hari_raya' ? 'secondary' : 'primary';
    }

    /** Total estimasi (server-side) — sum nominal baris yang dicentang. */
    public function totalAmount(): int
    {
        return collect($this->lines)
            ->filter(fn (array $line): bool => (bool) ($line['include'] ?? false))
            ->sum(fn (array $line): int => (int) round((float) ($line['amount'] ?? 0)));
    }

    public function includedCount(): int
    {
        return collect($this->lines)->where('include', true)->count();
    }

    protected function rules(): array
    {
        return [
            'member_id' => ['required', 'exists:members,id'],
            'withdrawal_date' => ['required', 'date', 'before_or_equal:today'],
            'disbursement_method' => ['nullable', 'in:'.implode(',', array_keys(Resource::DISBURSEMENT_METHODS))],
            'notes' => ['nullable', 'string', 'max:65535'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'member_id' => 'anggota',
            'withdrawal_date' => 'tanggal pengajuan',
            'disbursement_method' => 'jenis pencairan',
            'notes' => 'catatan',
        ];
    }

    protected function validateLines(): void
    {
        $errors = [];

        foreach ($this->lines as $i => $line) {
            if (! ($line['include'] ?? false)) {
                continue;
            }

            $amount = (int) round((float) ($line['amount'] ?? 0));

            if ($amount < 1) {
                $errors["lines.$i.amount"] = 'Nominal wajib diisi dan lebih dari 0.';

                continue;
            }

            if (bccomp((string) $amount, (string) ($line['balance'] ?? '0'), 2) > 0) {
                $errors["lines.$i.amount"] = 'Melebihi saldo tersedia (Rp '.number_format((float) ($line['balance'] ?? 0), 0, ',', '.').').';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function save()
    {
        $this->authorize('create', SavingsWithdrawal::class);

        $this->validate();
        $this->validateLines();

        $included = collect($this->lines)->filter(
            fn (array $line): bool => ($line['include'] ?? false) && (int) round((float) ($line['amount'] ?? 0)) > 0,
        );

        if ($included->isEmpty()) {
            $this->dispatch('toast', type: 'warning', message: 'Centang minimal satu jenis simpanan dengan nominal lebih dari 0.');

            return null;
        }

        $created = 0;
        $duplicates = 0;

        // Tiap baris = sumber berbeda (sukarela / hari_raya per tahun) → tak ada
        // tumpang-tindih saldo antar baris. Idempotency_key per baris cegah dobel.
        foreach ($included as $line) {
            try {
                SavingsWithdrawal::create([
                    'idempotency_key' => $line['idempotency_key'] ?? (string) Str::uuid(),
                    'member_id' => $this->member_id,
                    'savings_type' => $line['savings_type'],
                    'amount' => (int) round((float) $line['amount']),
                    'withdrawal_date' => $this->withdrawal_date,
                    'status' => 'draft',
                    'period_year' => $line['savings_type'] === 'hari_raya' ? (int) $line['period_year'] : null,
                    'disbursement_method' => $this->disbursement_method ?: null,
                    'notes' => $this->notes ?: null,
                    'recorded_by' => auth()->id(),
                ]);
                $created++;
            } catch (UniqueConstraintViolationException) {
                $duplicates++;
            }
        }

        if ($created === 0) {
            session()->flash('toast', ['type' => 'success', 'message' => 'Pengajuan sudah tercatat sebelumnya.']);

            return $this->redirectRoute('savings.withdrawals', navigate: true);
        }

        $body = "{$created} pengajuan pencairan dibuat sebagai draft — menunggu ACC pengurus";
        if ($duplicates > 0) {
            $body .= ", {$duplicates} dilewati (sudah tercatat)";
        }
        session()->flash('toast', ['type' => 'success', 'message' => $body.'.']);

        return $this->redirectRoute('savings.withdrawals', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.savings.withdrawal.savings-withdrawal-form', [
            'total' => $this->totalAmount(),
            'includedCount' => $this->includedCount(),
            'disbursementMethods' => Resource::DISBURSEMENT_METHODS,
        ])->layout('components.layouts.app', ['title' => 'Pencairan Simpanan']);
    }
}
