<?php

namespace Database\Factories;

use App\Models\StoreClient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StoreClient>
 */
class StoreClientFactory extends Factory
{
    protected $model = StoreClient::class;

    /**
     * Secret default (plaintext) — di-hash oleh cast `hashed` saat disimpan.
     * Tes memakai konstanta ini untuk login: StoreClientFactory::DEFAULT_SECRET.
     */
    public const DEFAULT_SECRET = 'store-secret';

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Store',
            'client_id' => 'store_'.Str::lower(Str::random(20)),
            'client_secret' => self::DEFAULT_SECRET,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }

    public function canRefund(): static
    {
        return $this->state(fn (array $attributes): array => ['can_refund' => true]);
    }
}
