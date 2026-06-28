<?php

namespace App\Livewire\Savings\Shopping;

use App\Actions\RecordShoppingUsage;
use App\Exceptions\CannotSpendShopping;
use App\Livewire\Concerns\WithMemberPicker;
use App\Models\Member;
use App\Models\ShoppingTransaction;
use App\Services\SavingsBalanceService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class ShoppingTransactionForm extends Component
{
    use WithMemberPicker;

    /** Idempotency (D4/D6): satu render = satu key, anti double-submit. */
    public string $idempotencyKey = '';

    public ?int $amount = null;

    public ?string $transaction_date = null;

    public ?string $reference_number = null;

    public ?string $notes = null;

    public function mount(): void
    {
        $this->authorize('create', ShoppingTransaction::class);
        $this->idempotencyKey = (string) Str::uuid();
        $this->transaction_date = now()->toDateString();
    }

    /** Reset nominal saat anggota berganti (saldo berbeda). */
    protected function afterMemberSelected(): void
    {
        $this->amount = null;
    }

    /** Saldo Wajib Belanja anggota terpilih (null bila belum pilih). */
    public function shoppingBalance(): ?string
    {
        if (blank($this->member_id)) {
            return null;
        }

        $member = Member::find($this->member_id);

        return $member === null
            ? null
            : app(SavingsBalanceService::class)->shoppingBalance($member);
    }

    protected function rules(): array
    {
        return [
            'member_id' => ['required', 'exists:members,id'],
            'amount' => [
                'required',
                'integer',
                'min:1',
                function (string $attribute, $value, \Closure $fail): void {
                    $balance = $this->shoppingBalance();

                    if ($balance !== null && bccomp((string) $value, $balance, 2) > 0) {
                        $fail('Nominal melebihi saldo Wajib Belanja (Rp '.number_format((float) $balance, 0, ',', '.').').');
                    }
                },
            ],
            'transaction_date' => ['required', 'date', 'before_or_equal:today'],
            'reference_number' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:65535'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'member_id' => 'anggota',
            'amount' => 'nominal pemakaian',
            'transaction_date' => 'tanggal pemakaian',
            'reference_number' => 'no. referensi',
            'notes' => 'catatan',
        ];
    }

    public function save()
    {
        $this->authorize('create', ShoppingTransaction::class);

        $validated = $this->validate();

        $attributes = [
            'idempotency_key' => $this->idempotencyKey,
            'member_id' => $validated['member_id'],
            'amount' => $validated['amount'],
            'transaction_date' => $validated['transaction_date'],
            'reference_number' => $validated['reference_number'] ?: null,
            'notes' => $validated['notes'] ?: null,
            'source' => 'manual',
            'recorded_by' => auth()->id(),
        ];

        try {
            $transaction = app(RecordShoppingUsage::class)($attributes);
        } catch (CannotSpendShopping $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        } catch (UniqueConstraintViolationException $e) {
            // Double-submit dengan kunci idempotensi sama: pakai transaksi yang sudah ada.
            $transaction = ShoppingTransaction::where('idempotency_key', $this->idempotencyKey)->first();

            if ($transaction === null) {
                throw $e;
            }

            session()->flash('toast', ['type' => 'success', 'message' => 'Pemakaian sudah tercatat — tidak ada duplikat yang dibuat.']);

            return $this->redirectRoute('savings.shopping.show', $transaction, navigate: true);
        }

        session()->flash('toast', ['type' => 'success', 'message' => 'Pemakaian Wajib Belanja tercatat.']);

        return $this->redirectRoute('savings.shopping.show', $transaction, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.savings.shopping.shopping-transaction-form', [
            'balance' => $this->shoppingBalance(),
        ])->layout('components.layouts.app', ['title' => 'Catat Belanja Toko']);
    }
}
