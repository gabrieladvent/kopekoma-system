<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{balance: string, affordable: bool|null} $resource
 */
class VerifyResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = ['balance' => (string) $this->resource['balance']];

        if (($this->resource['affordable'] ?? null) !== null) {
            $data['affordable'] = (bool) $this->resource['affordable'];
        }

        return $data;
    }
}
