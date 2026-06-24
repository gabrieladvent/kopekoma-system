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

class StorePurchaseController extends Controller
{
    public function __construct(
        private readonly SavingsBalanceService $balances,
        private readonly StoreEnumerationGuard $enumGuard,
    ) {}

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

    public function charge(StoreChargeRequest $request): JsonResponse
    {
        $client = $this->storeClient($request);

        $this->enumGuard->assertNotLocked($client);

        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));

        if ($idempotencyKey === '') {
            $this->fail(422, 'Header Idempotency-Key wajib diisi.');
        }

        $amount = (string) $request->validated('amount');

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
            'recorded_by' => null,
        ];

        try {
            $tx = app(RecordShoppingUsage::class)($attributes);

        } catch (CannotSpendShopping $e) {
            $this->fail(422, $e->getMessage());

        } catch (UniqueConstraintViolationException) {
            return $this->idempotentReplay($client, $idempotencyKey, $hash);
        }

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

    public function refund(StoreRefundRequest $request, string $transactionNumber): JsonResponse
    {
        $client = $this->storeClient($request);

        $original = ShoppingTransaction::query()
            ->where('transaction_number', $transactionNumber)
            ->where('source', 'store_api')
            ->where('is_reversal', false)
            ->first();

        if ($original === null || (string) $original->store_client_id !== (string) $client->id) {
            $this->fail(404, 'Transaksi tidak ditemukan.');
        }

        try {
            $reversal = app(ReverseTransaction::class)($original, $request->validated('reason'), null);
        } catch (CannotReverseTransaction) {
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

    private function idempotencyHash(string $memberId, string $amount, ?string $reference): string
    {
        $canonical = implode('|', [$memberId, $amount, (string) $reference]);

        return hash_hmac('sha256', $canonical, (string) config('app.key'));
    }

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
