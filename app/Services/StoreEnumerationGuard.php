<?php

namespace App\Services;

use App\Models\StoreClient;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Cache;

/**
 * Lockout enumerasi NIK per klien toko (ADR D3). Rate limit biasa tak cukup —
 * setelah N kegagalan lookup beruntun dalam jendela waktu, klien diblok sementara
 * (cooldown). Counter dibagi endpoint verify & charge. State di cache.
 */
class StoreEnumerationGuard
{
    private const FAIL_PREFIX = 'store:enum:fail:';

    private const LOCK_PREFIX = 'store:enum:lock:';

    /**
     * Tolak (429) bila klien sedang dalam masa cooldown lockout.
     */
    public function assertNotLocked(StoreClient $client): void
    {
        if (Cache::has(self::LOCK_PREFIX.$client->id)) {
            throw new ThrottleRequestsException('Terlalu banyak percobaan tidak valid. Coba lagi nanti.');
        }
    }

    /**
     * Catat satu kegagalan lookup (NIK tak valid). Bila menembus ambang,
     * aktifkan lockout + catat event audit.
     */
    public function recordFailure(StoreClient $client): void
    {
        $key = self::FAIL_PREFIX.$client->id;
        $window = (int) config('store.lockout.window_minutes');
        $max = (int) config('store.lockout.max_failures');

        $failures = Cache::get($key, 0) + 1;
        Cache::put($key, $failures, now()->addMinutes($window));

        if ($failures >= $max) {
            Cache::put(
                self::LOCK_PREFIX.$client->id,
                true,
                now()->addMinutes((int) config('store.lockout.cooldown_minutes')),
            );
            Cache::forget($key);

            activity()
                ->performedOn($client)
                ->event('store_enumeration_lockout')
                ->withProperties(['failures' => $failures])
                ->log('Lockout enumerasi NIK aktif untuk klien toko.');
        }
    }

    /**
     * Reset counter kegagalan setelah lookup berhasil.
     */
    public function clear(StoreClient $client): void
    {
        Cache::forget(self::FAIL_PREFIX.$client->id);
    }
}
