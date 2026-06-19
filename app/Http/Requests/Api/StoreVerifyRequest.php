<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi request verify (ADR D2/D3). `amount` dijaga numerik (diolah bcmath
 * sebagai string di controller/Action, bukan float).
 */
class StoreVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gating via middleware auth:sanctum + store.client + abilities
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nik' => ['required', 'string', 'size:16'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
