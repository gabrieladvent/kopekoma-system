<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreVerifyRequest;
use App\Http\Resources\VerifyResource;
use App\Models\Member;
use App\Models\StoreClient;
use App\Services\SavingsBalanceService;
use App\Services\StoreEnumerationGuard;
use Illuminate\Http\Exceptions\HttpResponseException;

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

    private function storeClient(StoreVerifyRequest $request): StoreClient
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
