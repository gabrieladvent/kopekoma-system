<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nik' => ['required', 'string', 'size:16'],
            'amount' => ['nullable', 'numeric', 'gt:0'],
        ];
    }
}
