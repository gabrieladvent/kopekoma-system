<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi request charge (ADR D2/D4/D5). `amount` numerik (diolah bcmath sebagai
 * string). Plafon per-transaksi & Idempotency-Key di-enforce di controller (D4b).
 */
class StoreChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gating via middleware
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nik' => ['required', 'string', 'size:16'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference_number' => ['nullable', 'string', 'max:50'],
        ];
    }
}
