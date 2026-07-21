<?php

namespace App\Livewire\Loan;

use App\Enums\LoanStatus;
use App\Filament\Resources\LoanResource as Resource;
use App\Livewire\Concerns\WithMemberPicker;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\LoanBlacklist;
use App\Models\Member;
use App\Services\LoanArrearsService;
use App\Services\LoanCalculator;
use App\Settings\CooperativeSettings;
use App\Support\MediaFileName;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileCannotBeAdded;

class LoanForm extends Component
{
    use WithFileUploads;
    use WithMemberPicker;

    public string $loan_type = 'jangka_panjang';

    public ?int $principal_amount = null;

    public ?int $term_months = 12;

    public ?string $disbursement_date = null;

    public ?string $first_due_date = null;

    public ?string $disbursement_method = null;

    public ?string $disbursement_bank = null;

    public ?string $disbursement_account_number = null;

    public ?string $notes = null;

    /**
     * Berkas pendukung pinjaman (formulir / tanda terima) — opsional.
     *
     * @var array<int, TemporaryUploadedFile>
     */
    public array $uploads = [];

    public function mount(): void
    {
        $this->authorize('create', Loan::class);

        $this->disbursement_date = now()->toDateString();
        $this->first_due_date = now()->addMonth()->toDateString();
    }

    /**
     * Override picker: anggota dengan blacklist AKTIF tidak ditampilkan supaya
     * tidak bisa diajukan pinjaman baru (tetap dijaga server-side di validateBusiness).
     *
     * @return Collection<int, Member>
     */
    public function memberResults(): Collection
    {
        $blacklisted = LoanBlacklist::query()->where('is_active', true)->pluck('member_id');

        return Member::query()
            ->with(['agency:id,agency_name', 'grade:id,code'])
            ->whereNotIn('id', $blacklisted)
            ->when($this->memberSearch !== '', function ($query) {
                $term = '%'.$this->memberSearch.'%';
                $query->where(fn ($subQuery) => $subQuery->where('full_name', 'like', $term)
                    ->orWhere('member_number', 'like', $term)
                    ->orWhere('nik', 'like', $term)
                    ->orWhere('nip', 'like', $term));
            })
            ->orderBy('member_number')
            ->limit(15)
            ->get();
    }

    public function updatedLoanType(string $value): void
    {
        if ($value === 'jangka_pendek') {
            $this->term_months = 1;
        }
    }

    public function updatedDisbursementDate(?string $value): void
    {
        if (filled($value)) {
            $this->first_due_date = Carbon::parse($value)->addMonth()->toDateString();
        }
    }

    /**
     * Prefill rekening tujuan dari rekening payroll anggota saat metode = transfer
     * (boleh diedit); bersihkan saat bukan transfer. Mirror LoanResource.
     */
    public function updatedDisbursementMethod(?string $value): void
    {
        if ($value !== 'transfer') {
            $this->disbursement_bank = null;
            $this->disbursement_account_number = null;

            return;
        }

        $member = filled($this->member_id) ? Member::find($this->member_id) : null;
        $this->disbursement_bank = $member?->bank_name;
        $this->disbursement_account_number = $member?->payroll_account_number;
    }

    public function isTransfer(): bool
    {
        return $this->disbursement_method === 'transfer';
    }

    public function removeUpload(int $index): void
    {
        unset($this->uploads[$index]);
        $this->uploads = array_values($this->uploads);
    }

    public function isShortTerm(): bool
    {
        return $this->loan_type === 'jangka_pendek';
    }

    public function shortTermMax(): int
    {
        return (int) round((float) app(CooperativeSettings::class)->loan_short_term_max);
    }

    protected function rules(): array
    {
        return [
            'member_id' => ['required', 'exists:members,id'],
            'loan_type' => ['required', 'in:'.implode(',', array_keys(Resource::LOAN_TYPES))],
            'principal_amount' => ['required', 'integer', 'min:1'],
            'term_months' => ['required', 'integer', 'min:1', 'max:120'],
            'disbursement_date' => ['required', 'date'],
            'first_due_date' => ['required', 'date', 'after_or_equal:disbursement_date'],
            'disbursement_method' => ['nullable', 'in:'.implode(',', array_keys(Resource::DISBURSEMENT_METHODS))],
            'disbursement_bank' => [$this->isTransfer() ? 'required' : 'nullable', 'string', 'max:255'],
            'disbursement_account_number' => [$this->isTransfer() ? 'required' : 'nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:65535'],
            'uploads' => ['nullable', 'array', 'max:10'],
            'uploads.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'member_id' => 'anggota',
            'loan_type' => 'jenis pinjaman',
            'principal_amount' => 'jumlah pinjaman',
            'term_months' => 'jangka waktu',
            'disbursement_date' => 'tanggal pencairan',
            'first_due_date' => 'jatuh tempo pertama',
            'disbursement_method' => 'jenis pencairan',
            'disbursement_bank' => 'bank tujuan',
            'disbursement_account_number' => 'no. rekening tujuan',
            'notes' => 'catatan',
            'uploads.*' => 'berkas',
        ];
    }

    /** Validasi bisnis: ambang Sebrakan vs Jangka Panjang + blacklist. */
    protected function validateBusiness(): void
    {
        $max = $this->shortTermMax();
        $maxRp = 'Rp '.number_format($max, 0, ',', '.');
        $amount = (int) $this->principal_amount;

        $errors = [];

        if ($this->loan_type === 'jangka_panjang' && $amount <= $max) {
            $errors['principal_amount'] = "Jangka Panjang harus di atas {$maxRp}. Untuk ≤ {$maxRp} gunakan Sebrakan (Jangka Pendek).";
        }

        if ($this->loan_type === 'jangka_pendek' && $amount > $max) {
            $errors['principal_amount'] = "Sebrakan (Jangka Pendek) maksimal {$maxRp}. Di atas itu gunakan Jangka Panjang.";
        }

        if (Resource::hasActiveBlacklist($this->member_id)) {
            $errors['member_id'] = 'Anggota ini sedang dalam daftar blacklist pinjaman — tidak dapat mengajukan pinjaman baru.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function save()
    {
        $this->authorize('create', Loan::class);

        if ($this->isShortTerm()) {
            $this->term_months = 1;
        }

        $this->validate();
        $this->validateBusiness();

        $calc = app(LoanCalculator::class);
        $term = (int) $this->term_months;

        $data = [
            'member_id' => $this->member_id,
            'loan_type' => $this->loan_type,
            'principal_amount' => (string) $this->principal_amount,
            'term_months' => $term,
            'disbursement_date' => $this->disbursement_date,
            'first_due_date' => $this->first_due_date,
            'disbursement_method' => $this->disbursement_method ?: null,
            'disbursement_bank' => $this->isTransfer() ? ($this->disbursement_bank ?: null) : null,
            'disbursement_account_number' => $this->isTransfer() ? ($this->disbursement_account_number ?: null) : null,
            'notes' => $this->notes ?: null,
            'status' => LoanStatus::Cair,
            'recorded_by' => auth()->id(),
        ];

        // Potongan & konstanta dihitung SERVER (input client tak dipercaya, D3/D1b).
        $data = array_merge($data, $calc->disbursement($this->loan_type, $this->principal_amount));
        $data = array_merge($data, $calc->monthlyConstants($this->loan_type, $this->principal_amount, $term));

        $loan = DB::transaction(function () use ($data, $calc, $term): Loan {
            /** @var Loan $loan */
            $loan = Loan::create($data);

            // Auto-generate jadwal angsuran (N baris / 1 baris Sebrakan), atomik (D4).
            $rows = $calc->buildSchedule(
                $loan->loan_type,
                (string) $loan->principal_amount,
                $term,
                $loan->first_due_date,
            );

            foreach ($rows as $row) {
                InstallmentSchedule::create(['loan_id' => $loan->id] + $row);
            }

            return $loan;
        });

        $this->attachUploads($loan);

        session()->flash('toast', ['type' => 'success', 'message' => 'Pinjaman '.$loan->loan_number.' tercatat & jadwal angsuran dibuat.']);

        return $this->redirectRoute('loans.show', $loan, navigate: true);
    }

    private function attachUploads(Loan $loan): void
    {
        if (empty($this->uploads)) {
            return;
        }

        foreach ($this->uploads as $file) {
            try {
                $loan->addMedia($file->getRealPath())
                    ->usingFileName(MediaFileName::for($file))
                    ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
                    ->toMediaCollection('dokumen');
            } catch (FileCannotBeAdded) {
                // Lewati berkas yang tak bisa dilampirkan (tipe tak cocok).
            }
        }

        $this->reset('uploads');
    }

    public function render(): View
    {
        return view('livewire.loan.loan-form', [
            'loanTypes' => Resource::LOAN_TYPES,
            'disbursementMethods' => Resource::DISBURSEMENT_METHODS,
            'preview' => $this->buildPreview(),
            'arrears' => $this->memberInsight(),
        ])->layout('components.layouts.app', ['title' => 'Pinjaman Baru']);
    }

    /**
     * Simulasi pinjaman (server-side, sumber kebenaran sama dgn saat akad).
     *
     * @return array<string, mixed>
     */
    private function buildPreview(): array
    {
        $settings = app(CooperativeSettings::class);

        $base = [
            'has' => false,
            'admin_fee' => '0',
            'swp_amount' => '0',
            'disbursed_amount' => '0',
            'monthly_principal' => '0',
            'monthly_interest' => '0',
            'monthly_time_deposit' => '0',
            'monthly_total' => '0',
            'total_repayment' => '0',
            'admin_rate' => $this->percent($settings->loan_admin_fee_rate),
            'swp_rate' => $this->percent($settings->loan_swp_rate),
            'interest_rate' => $this->percent($settings->loan_interest_rate),
            'time_deposit_rate' => $this->percent($settings->loan_time_deposit_rate),
        ];

        $principal = (int) $this->principal_amount;
        $term = (int) $this->term_months;

        if ($principal < 1 || $term < 1) {
            return $base;
        }

        $calc = app(LoanCalculator::class);
        $d = $calc->disbursement($this->loan_type, $principal);
        $c = $calc->monthlyConstants($this->loan_type, $principal, $term);
        $monthlyTotal = $calc->monthlyTotal($this->loan_type, $principal, $term);

        return array_merge($base, $d, $c, [
            'has' => true,
            'monthly_total' => $monthlyTotal,
            'total_repayment' => bcmul($monthlyTotal, (string) max(1, $this->isShortTerm() ? 1 : $term), 2),
        ]);
    }

    /**
     * Info riwayat & kapasitas anggota terpilih (read-only, D10/D11).
     *
     * @return array{warning: ?string, load: ?string}
     */
    private function memberInsight(): array
    {
        if (blank($this->member_id)) {
            return ['warning' => null, 'load' => null];
        }

        $member = Member::find($this->member_id);

        if ($member === null) {
            return ['warning' => null, 'load' => null];
        }

        $service = app(LoanArrearsService::class);

        return [
            'warning' => $service->arrearsWarning($member),
            'load' => $service->monthlyDeductionLoad($member),
        ];
    }

    /** Rate desimal → persen ringkas, mis. 0.0065 → "0,65%". */
    private function percent(float $rate): string
    {
        $value = rtrim(rtrim(number_format($rate * 100, 4, '.', ''), '0'), '.');

        return str_replace('.', ',', $value).'%';
    }
}
