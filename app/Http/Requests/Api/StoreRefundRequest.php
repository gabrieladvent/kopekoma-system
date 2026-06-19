<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi request refund (ADR D8). `reason` wajib (min 5 char, selaras
 * ReverseTransaction).
 */
class StoreRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gating via middleware (auth:sanctum + abilities:shopping:refund + store.client)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }
}
