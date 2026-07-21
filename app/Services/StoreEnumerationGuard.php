<?php

namespace App\Services;

use App\Models\StoreClient;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Cache;

class StoreEnumerationGuard
{
    private const FAIL_PREFIX = 'store:enum:fail:';

    private const LOCK_PREFIX = 'store:enum:lock:';

    public function assertNotLocked(StoreClient $client): void
    {
        if (Cache::has(self::LOCK_PREFIX.$client->id)) {
            throw new ThrottleRequestsException('Terlalu banyak percobaan tidak valid. Coba lagi nanti.');
        }
    }

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

    public function clear(StoreClient $client): void
    {
        Cache::forget(self::FAIL_PREFIX.$client->id);
    }
}
