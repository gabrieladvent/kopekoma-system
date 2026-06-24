<?php

use App\Actions\RecordShoppingUsage;
use App\Exceptions\CannotSpendShopping;
use App\Models\Member;
use App\Models\SavingsDeposit;
use App\Models\ShoppingTransaction;
use App\Services\SavingsBalanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Tes invariant over-spend (ADR D4, verification #14). Lock `lockForUpdate` di
 * RecordShoppingUsage menyerialkan charge konkuren untuk member yang sama.
 *
 * WAJIB MySQL — di SQLite lockForUpdate no-op (ADR). Karena butuh dua proses
 * benar-benar paralel membaca data ter-commit, tes ini:
 *   - berada di tests/Unit (di luar LazilyRefreshDatabase, tanpa transaction wrap),
 *   - pakai pcntl_fork,
 *   - skip otomatis bila driver bukan mysql atau pcntl tak tersedia.
 *
 * Jalankan terhadap DB MySQL test:
 *   DB_CONNECTION=mysql DB_DATABASE=kopekoma_test \
 *     vendor/bin/pest tests/Unit/StoreChargeConcurrencyTest.php
 */

uses(TestCase::class);

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Invariant lock hanya berarti di MySQL (no-op di SQLite).');
    }
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl tidak tersedia.');
    }
});

it('two concurrent charges on the same member cannot over-spend (MySQL lock)', function () {
    // Saldo 50rb: hanya SATU charge 40rb yang boleh lolos.
    $member = Member::factory()->create(['status' => 'Aktif']);
    SavingsDeposit::factory()->type('wajib_belanja')->create([
        'member_id' => $member->id,
        'amount' => 50_000,
    ]);
    $memberId = $member->id;

    try {
        // Mulai serempak untuk memaksa tumbukan di lock.
        $startAt = microtime(true) + 0.3;
        $pids = [];

        foreach (range(1, 2) as $i) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                // --- child ---
                DB::purge(); // koneksi PDO tak boleh dipakai lintas-fork
                time_sleep_until($startAt);

                try {
                    app(RecordShoppingUsage::class)([
                        'idempotency_key' => (string) Str::uuid(),
                        'member_id' => $memberId,
                        'amount' => 40_000,
                        'transaction_date' => now()->toDateString(),
                        'source' => 'store_api',
                    ]);
                    exit(10); // charged
                } catch (CannotSpendShopping) {
                    exit(11); // ditolak saldo
                } catch (Throwable) {
                    exit(12); // error lain
                }
            }

            $pids[] = $pid;
        }

        $codes = [];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $codes[] = pcntl_wexitstatus($status);
        }

        DB::purge();

        // Tepat satu charged (10) dan satu ditolak (11) — tak ada error (12).
        sort($codes);
        expect($codes)->toBe([10, 11]);

        // Saldo akhir 10rb (satu charge 40rb), tak pernah negatif; satu transaksi.
        expect(ShoppingTransaction::query()->where('member_id', $memberId)->count())->toBe(1)
            ->and(app(SavingsBalanceService::class)->shoppingBalance($member->fresh()))->toBe('10000.00');
    } finally {
        ShoppingTransaction::query()->where('member_id', $memberId)->delete();
        SavingsDeposit::query()->where('member_id', $memberId)->delete();
        Member::query()->whereKey($memberId)->delete();
    }
});
