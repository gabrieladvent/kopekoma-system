<?php

namespace App\Http\Controllers\Api;

use App\Actions\RecordShoppingUsage;
use App\Actions\ReverseTransaction;
use App\Exceptions\CannotReverseTransaction;
use App\Exceptions\CannotSpendShopping;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreChargeRequest;
use App\Http\Requests\Api\StoreRefundRequest;
use App\Http\Requests\Api\StoreVerifyRequest;
use App\Http\Resources\RefundResource;
use App\Http\Resources\StorePurchaseResource;
use App\Http\Resources\VerifyResource;
use App\Models\Member;
use App\Models\ShoppingTransaction;
use App\Models\StoreClient;
use App\Services\SavingsBalanceService;
use App\Services\StoreEnumerationGuard;
use App\Support\ApiResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint verify & charge pemakaian saldo Wajib Belanja oleh toko (ADR D2/D4b).
 * Controller = orkestrasi (validasi, plafon, lookup, idempotency); eksekusi saldo
 * di Action RecordShoppingUsage; bentuk response di JsonResource (whitelist PII).
 */
class StorePurchaseController extends Controller
{
    public function __construct(
        private readonly SavingsBalanceService $balances,
        private readonly StoreEnumerationGuard $enumGuard,
    ) {}

    /**
     * Read-only: cek apakah saldo cukup. Tak menulis transaksi apa pun (D2).
     */
    public function verify(StoreVerifyRequest $request): JsonResponse
    {
        $client = $this->storeClient($request);
        $this->enumGuard->assertNotLocked($client);

        $member = $this->resolveMember($request->validated('nik'), $client);
        $balance = $this->balances->shoppingBalance($member);

        $amount = $request->validated('amount');
        $affordable = $amount !== null
            ? $this->balances->canSpendShopping($member, (string) $amount)
            : null;

        return ApiResponse::success(
            (new VerifyResource(['balance' => $balance, 'affordable' => $affordable]))->resolve(),
            'Pengecekan saldo berhasil.',
        );
    }

    /**
     * Potong saldo (write). Atomik + idempoten + ter-lock (D2/D4/D5/D6).
     */
    public function charge(StoreChargeRequest $request): JsonResponse
    {
        $client = $this->storeClient($request);
        $this->enumGuard->assertNotLocked($client);

        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        if ($idempotencyKey === '') {
            $this->fail(422, 'Header Idempotency-Key wajib diisi.');
        }

        $amount = (string) $request->validated('amount');

        // Plafon per-transaksi di-enforce DI CONTROLLER (D2/D4b) — bukan di Action,
        // agar jalur manual petugas tak ikut terkurung.
        if (bccomp($amount, (string) config('store.max_charge_per_tx'), 2) > 0) {
            $this->fail(422, 'Nominal melebihi plafon per transaksi.');
        }

        $member = $this->resolveMember($request->validated('nik'), $client);
        $reference = $request->validated('reference_number');
        $hash = $this->idempotencyHash($member->id, $amount, $reference);

        $attributes = [
            'idempotency_key' => $idempotencyKey,
            'idempotency_hash' => $hash,
            'member_id' => $member->id,
            'amount' => $amount,
            'transaction_date' => now()->toDateString(),
            'source' => 'store_api',
            'store_client_id' => $client->id,
            'reference_number' => $reference,
            'recorded_by' => null, // tak ada aktor manusia (D6)
        ];

        try {
            $tx = app(RecordShoppingUsage::class)($attributes);
        } catch (CannotSpendShopping $e) {
            $this->fail(422, $e->getMessage());
        } catch (UniqueConstraintViolationException) {
            return $this->idempotentReplay($client, $idempotencyKey, $hash);
        }

        // Audit "toko mana" — tanpa NIK (D6). Causer null (jalur tanpa User);
        // identitas pelaku terekam lewat properti store_client_id.
        activity()
            ->performedOn($tx)
            ->causedByAnonymous()
            ->event('store_charge')
            ->withProperties([
                'store_client_id' => $client->id,
                'member_id' => $member->id,
                'amount' => $tx->amount,
            ])
            ->log('Pemakaian saldo Wajib Belanja via API toko.');

        return ApiResponse::success(
            (new StorePurchaseResource($tx))->resolve(),
            'Pemakaian saldo berhasil dipotong.',
            201,
        );
    }

    /**
     * Refund/koreksi via reversal yang sudah ada (D8). Hanya toko asal (match
     * `store_client_id`) boleh me-refund transaksinya. Idempoten lewat
     * `unique(reversal_of_id)`: refund konkuren → satu sukses, lainnya 200.
     */
    public function refund(StoreRefundRequest $request, string $transactionNumber): JsonResponse
    {
        $client = $this->storeClient($request);

        $original = ShoppingTransaction::query()
            ->where('transaction_number', $transactionNumber)
            ->where('source', 'store_api')
            ->where('is_reversal', false)
            ->first();

        // Bukan transaksi store_api yang valid, atau bukan milik klien ini →
        // 404 generik (jangan bocorkan transaksi milik klien lain).
        if ($original === null || (string) $original->store_client_id !== (string) $client->id) {
            $this->fail(404, 'Transaksi tidak ditemukan.');
        }

        try {
            $reversal = app(ReverseTransaction::class)($original, $request->validated('reason'), null);
        } catch (CannotReverseTransaction) {
            // Sudah pernah di-refund (termasuk race konkuren) → idempoten 200.
            $existing = ShoppingTransaction::query()
                ->where('reversal_of_id', $original->id)
                ->firstOrFail();

            return ApiResponse::success(
                (new RefundResource($existing))->resolve(),
                'Transaksi sudah pernah di-refund.',
                200,
            );
        }

        return ApiResponse::success(
            (new RefundResource($reversal))->resolve(),
            'Refund berhasil.',
            201,
        );
    }

    /**
     * Idempotency replay (D5): key sama → cek kepemilikan klien + hash payload.
     * Bukan milik klien ini → 409 generik (tak bocorkan transaksi klien lain).
     */
    private function idempotentReplay(StoreClient $client, string $key, string $hash): JsonResponse
    {
        $existing = ShoppingTransaction::query()->where('idempotency_key', $key)->first();

        if ($existing === null || (string) $existing->store_client_id !== (string) $client->id) {
            $this->fail(409, 'Idempotency-Key sudah dipakai.');
        }

        if (! hash_equals((string) $existing->idempotency_hash, $hash)) {
            $this->fail(409, 'Idempotency-Key dipakai ulang dengan payload berbeda.');
        }

        return ApiResponse::success(
            (new StorePurchaseResource($existing))->resolve(),
            'Pemakaian saldo sudah pernah tercatat (idempoten).',
            200,
        );
    }

    /**
     * HMAC payload kanonik — TANPA NIK (D3/D5): pakai member_id hasil lookup agar
     * kolom hash tak bisa di-balik jadi NIK.
     */
    private function idempotencyHash(string $memberId, string $amount, ?string $reference): string
    {
        $canonical = implode('|', [$memberId, $amount, (string) $reference]);

        return hash_hmac('sha256', $canonical, (string) config('app.key'));
    }

    /**
     * Lookup anggota by NIK — exact match + status Aktif (D3, satu-satunya tempat
     * enforce status). NIK tak valid/non-aktif → 404 generik + catat kegagalan
     * untuk lockout enumerasi. Berhasil → reset counter.
     */
    private function resolveMember(string $nik, StoreClient $client): Member
    {
        $member = Member::query()
            ->where('nik', $nik)
            ->where('status', 'Aktif')
            ->first();

        if ($member === null) {
            $this->enumGuard->recordFailure($client);
            $this->fail(404, 'Anggota tidak valid untuk transaksi.');
        }

        $this->enumGuard->clear($client);

        return $member;
    }

    private function storeClient(Request $request): StoreClient
    {
        /** @var StoreClient $client */
        $client = $request->user();

        return $client;
    }

    /**
     * Kontrak error JSON seragam (D7): envelope { response_code, response_message }.
     *
     * @return never
     */
    private function fail(int $status, string $message): void
    {
        throw new HttpResponseException(ApiResponse::error($message, $status));
    }
}
