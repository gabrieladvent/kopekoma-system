<?php

namespace App\Http\Controllers\Api;

use App\Actions\RecordShoppingUsage;
use App\Exceptions\CannotSpendShopping;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreChargeRequest;
use App\Http\Requests\Api\StoreVerifyRequest;
use App\Http\Resources\StorePurchaseResource;
use App\Http\Resources\VerifyResource;
use App\Models\Member;
use App\Models\ShoppingTransaction;
use App\Models\StoreClient;
use App\Services\SavingsBalanceService;
use App\Services\StoreEnumerationGuard;
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
    public function verify(StoreVerifyRequest $request): VerifyResource
    {
        $client = $this->storeClient($request);
        $this->enumGuard->assertNotLocked($client);

        $member = $this->resolveMember($request->validated('nik'), $client);
        $affordable = $this->balances->canSpendShopping($member, (string) $request->validated('amount'));

        return new VerifyResource(['affordable' => $affordable]);
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
            $this->fail(422, 'IDEMPOTENCY_KEY_REQUIRED', 'Header Idempotency-Key wajib diisi.');
        }

        $amount = (string) $request->validated('amount');

        // Plafon per-transaksi di-enforce DI CONTROLLER (D2/D4b) — bukan di Action,
        // agar jalur manual petugas tak ikut terkurung.
        if (bccomp($amount, (string) config('store.max_charge_per_tx'), 2) > 0) {
            $this->fail(422, 'AMOUNT_EXCEEDS_LIMIT', 'Nominal melebihi plafon per transaksi.');
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
            $this->fail(422, 'INSUFFICIENT_BALANCE', $e->getMessage());
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

        return (new StorePurchaseResource($tx))->response()->setStatusCode(201);
    }

    /**
     * Idempotency replay (D5): key sama → cek kepemilikan klien + hash payload.
     * Bukan milik klien ini → 409 generik (tak bocorkan transaksi klien lain).
     */
    private function idempotentReplay(StoreClient $client, string $key, string $hash): JsonResponse
    {
        $existing = ShoppingTransaction::query()->where('idempotency_key', $key)->first();

        if ($existing === null || (string) $existing->store_client_id !== (string) $client->id) {
            $this->fail(409, 'IDEMPOTENCY_CONFLICT', 'Idempotency-Key sudah dipakai.');
        }

        if (! hash_equals((string) $existing->idempotency_hash, $hash)) {
            $this->fail(409, 'IDEMPOTENCY_PAYLOAD_MISMATCH', 'Idempotency-Key dipakai ulang dengan payload berbeda.');
        }

        return (new StorePurchaseResource($existing))->response()->setStatusCode(200);
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
            $this->fail(404, 'MEMBER_NOT_FOUND', 'Anggota tidak valid untuk transaksi.');
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
     * Kontrak error JSON seragam (D7): { message, code }.
     *
     * @return never
     */
    private function fail(int $status, string $code, string $message): void
    {
        throw new HttpResponseException(
            response()->json(['message' => $message, 'code' => $code], $status)
        );
    }
}
