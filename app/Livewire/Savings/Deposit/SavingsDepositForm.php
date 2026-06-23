<?php

namespace App\Livewire\Savings\Deposit;

use App\Actions\RecordMemberSavingsDeposits;
use App\Filament\Resources\SavingsDepositResource as Resource;
use App\Livewire\Concerns\WithMemberPicker;
use App\Models\SavingsDeposit;
use App\Settings\CooperativeSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Setor Simpanan — mode "Setoran Tunggal".
 *
 * Sekali proses → banyak setoran: satu baris per jenis simpanan yang dicentang
 * & bernominal > 0. Logika bisnis (jenis berlaku, nominal terkunci, anti-duplikat
 * per periode) di-reuse 1:1 dari SavingsDepositResource agar flow identik dengan
 * Filament. Lihat CreateSavingsDeposit::handleRecordCreation() sebagai padanan.
 */
class SavingsDepositForm extends Component
{
    use WithMemberPicker;

    public ?string $deposit_date = null;

    public ?string $period_month = null;

    public string $deposit_method = 'setor_sendiri';

    public string $deposited_by = 'anggota';

    public ?string $reference_number = null;

    public ?string $notes = null;

    /**
     * Baris jenis simpanan: tiap item berisi savings_type, type_label, include,
     * amount, idempotency_key. Dibangun ulang tiap anggota/tanggal/periode berubah.
     *
     * @var list<array<string, mixed>>
     */
    public array $lines = [];

    public function mount(): void
    {
        $this->authorize('create', SavingsDeposit::class);

        $this->deposit_date = now()->toDateString();
        $this->period_month = now()->format('Y-m');
    }

    /** Rebuild baris saat anggota berganti (jenis & nominal terkunci ikut berubah). */
    protected function afterMemberSelected(): void
    {
        $this->rebuildLines();
    }

    public function updatedDepositDate(): void
    {
        $this->rebuildLines();
    }

    public function updatedPeriodMonth(): void
    {
        $this->rebuildLines();
    }

    /**
     * Bangun ulang baris dari helper resource, pertahankan state sebelumnya
     * (centang, nominal sukarela, idempotency_key) lalu beri tahu klien agar
     * total estimasi dihitung ulang.
     */
    public function rebuildLines(): void
    {
        $this->lines = Resource::buildLines(
            $this->member_id,
            $this->deposit_date,
            $this->period_month,
            $this->lines,
        );

        $this->resetErrorBag('lines');
        $this->dispatch('lines-updated');
    }

    /**
     * Label jenis yang disembunyikan karena sudah disetor untuk periode ini.
     *
     * @return list<string>
     */
    public function hiddenTypeLabels(): array
    {
        return Resource::hiddenTypeLabels($this->member_id, $this->deposit_date, $this->period_month);
    }

    public function isLocked(string $type): bool
    {
        return in_array($type, Resource::LOCKED_AMOUNT_TYPES, true);
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

    public function typeColor(string $type): string
    {
        return Resource::typeColor($type);
    }

    public function typeHint(string $type): string
    {
        return match ($type) {
            'pokok', 'wajib_belanja', 'hari_raya' => 'Nominal terkunci dari ketentuan koperasi / registrasi.',
            'sukarela' => 'Minimal sesuai ketentuan koperasi.',
            'wajib' => 'Default dari golongan anggota; boleh disesuaikan.',
            default => '',
        };
    }

    protected function rules(): array
    {
        return [
            'member_id' => ['required', 'exists:members,id'],
            'deposit_date' => ['required', 'date', 'before_or_equal:today'],
            'period_month' => ['required', 'date_format:Y-m'],
            'deposit_method' => ['required', 'in:'.implode(',', array_keys(Resource::DEPOSIT_METHODS))],
            'deposited_by' => ['required', 'in:'.implode(',', array_keys(Resource::DEPOSITED_BY))],
            'reference_number' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:65535'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'member_id' => 'anggota',
            'deposit_date' => 'tanggal setor',
            'period_month' => 'periode',
            'deposit_method' => 'metode setor',
            'deposited_by' => 'disetor oleh',
            'reference_number' => 'no. referensi',
            'notes' => 'catatan',
        ];
    }

    /**
     * Validasi nominal per baris yang dicentang & tidak terkunci. Error menempel
     * ke field yang benar (lines.{i}.amount) agar terlihat di kartu jenisnya.
     */
    protected function validateLines(): void
    {
        $settings = app(CooperativeSettings::class);
        $sukarelaMin = (int) round((float) $settings->savings_sukarela_min);

        $errors = [];

        foreach ($this->lines as $i => $line) {
            if (! ($line['include'] ?? false) || $this->isLocked($line['savings_type'] ?? '')) {
                continue;
            }

            $amount = (int) round((float) ($line['amount'] ?? 0));

            if ($amount < 1) {
                $errors["lines.$i.amount"] = 'Nominal wajib diisi dan lebih dari 0.';

                continue;
            }

            if (($line['savings_type'] ?? null) === 'sukarela' && $amount < $sukarelaMin) {
                $errors["lines.$i.amount"] = 'Minimal Rp '.number_format($sukarelaMin, 0, ',', '.').'.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function save()
    {
        $this->authorize('create', SavingsDeposit::class);

        $this->validate();
        $this->validateLines();

        $periodDate = Carbon::parse($this->period_month)->startOfMonth()->toDateString();

        $shared = [
            'member_id' => $this->member_id,
            'deposit_date' => $this->deposit_date,
            'period_month' => $periodDate,
            'deposit_method' => $this->deposit_method,
            'deposited_by' => $this->deposited_by,
            'reference_number' => $this->reference_number ?: null,
            'notes' => $this->notes ?: null,
        ];

        // Satu baris per jenis dicentang & bernominal (locked dijamin lewat).
        $lines = collect($this->lines)
            ->filter(function (array $line): bool {
                if (! ($line['include'] ?? false)) {
                    return false;
                }

                if ($this->isLocked($line['savings_type'] ?? '')) {
                    return true;
                }

                return (int) round((float) ($line['amount'] ?? 0)) > 0;
            })
            ->map(fn (array $line): array => Resource::enforceAmountRules([
                ...$shared,
                'savings_type' => $line['savings_type'],
                'amount' => (string) ($line['amount'] ?? '0'),
                'idempotency_key' => $line['idempotency_key'] ?? (string) Str::uuid(),
            ]))
            ->values();

        if ($lines->isEmpty()) {
            $this->dispatch('toast', type: 'warning', message: 'Centang minimal satu jenis simpanan dengan nominal lebih dari 0.');

            return null;
        }

        // Hari Raya hanya sah bila ada program aktif yang memuat tanggal setor.
        foreach ($lines as $line) {
            if (($line['savings_type'] ?? null) === 'hari_raya'
                && Resource::activeHolidayRegistration($line['member_id'] ?? null, $line['deposit_date'] ?? null) === null) {
                $this->dispatch('toast', type: 'error', message: 'Tidak ada program Hari Raya aktif yang memuat tanggal setor ini.');

                return null;
            }
        }

        [$toCreate, $skipped] = $lines->partition(
            fn (array $line): bool => ! Resource::typeAlreadyDeposited(
                $line['savings_type'],
                $line['member_id'] ?? null,
                $line['deposit_date'] ?? null,
                $line['period_month'] ?? null,
            ),
        );

        if ($toCreate->isEmpty()) {
            $this->dispatch('toast', type: 'success', message: 'Semua jenis sudah tercatat untuk periode ini — tidak ada duplikat dibuat.');

            return $this->redirectRoute('savings.deposits', navigate: true);
        }

        $result = app(RecordMemberSavingsDeposits::class)($toCreate->values()->all());

        $created = count($result['created']);
        $duplicates = $result['duplicates'] + $skipped->count();

        if ($result['created'] === []) {
            session()->flash('toast', ['type' => 'success', 'message' => 'Transaksi sudah tercatat sebelumnya.']);

            return $this->redirectRoute('savings.deposits', navigate: true);
        }

        $body = "{$created} setoran tersimpan";
        if ($duplicates > 0) {
            $body .= ", {$duplicates} dilewati (sudah tercatat)";
        }
        session()->flash('toast', ['type' => 'success', 'message' => $body.'.']);

        return $this->redirectRoute('savings.deposits', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.savings.deposit.savings-deposit-form', [
            'savingsTypes' => Resource::SAVINGS_TYPES,
            'depositMethods' => Resource::DEPOSIT_METHODS,
            'depositedByOptions' => Resource::DEPOSITED_BY,
            'hiddenTypes' => $this->hiddenTypeLabels(),
            'total' => $this->totalAmount(),
            'includedCount' => $this->includedCount(),
        ])->layout('components.layouts.app', ['title' => 'Setor Simpanan']);
    }
}
