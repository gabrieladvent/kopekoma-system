# KOPEKOMA Store API — Bruno Collection

Koleksi [Bruno](https://www.usebruno.com/) untuk API Integrasi Toko (pemakaian saldo Wajib Belanja).

## Cara Pakai

1. Buka Bruno → **Open Collection** → pilih folder `bruno/kopekoma-store-api`.
2. Pilih environment **Local** (kanan atas) lalu sesuaikan `baseUrl`, `client_id`, `client_secret`, `nik`.
3. Jalankan berurutan: **01 - Token** → **02 - Verify** → **03 - Charge** → **04 - Refund**.
   - `access_token` dan `transaction_number` otomatis tersimpan ke environment lewat post-response script.

## Persiapan Data Lokal

Belum ada UI manajemen merchant (lihat ADR — Non-Goal). Buat `StoreClient` + anggota bersaldo via `php artisan tinker`:

```php
use App\Models\StoreClient;
use App\Models\Member;
use App\Models\SavingsDeposit;

// Klien toko (boleh refund). client_secret di-hash otomatis oleh cast.
StoreClient::create([
    'name' => 'Toko Demo',
    'client_id' => 'store_acme',
    'client_secret' => 'store-secret',
    'is_active' => true,
    'can_refund' => true,
]);

// Anggota Aktif + saldo Wajib Belanja 100.000 (pakai NIK yang ada / buat baru).
$member = Member::where('status', 'Aktif')->first();
SavingsDeposit::factory()->type('wajib_belanja')->create([
    'member_id' => $member->id,
    'amount' => 100000,
]);
echo $member->nik; // pakai sebagai var `nik` di environment Bruno
```

Dokumentasi endpoint lengkap: [`docs/api/store-api.md`](../../docs/api/store-api.md).
