# KOPEKOMA Store API — Bruno Collection

Koleksi [Bruno](https://www.usebruno.com/) untuk API Integrasi Toko (pemakaian saldo Wajib Belanja).

## Cara Pakai

1. Buka Bruno → **Open Collection** → pilih folder `bruno/kopekoma-store-api`.
2. Pilih environment **Local** (kanan atas) lalu sesuaikan `baseUrl`, `client_id`, `client_secret`, `nik`.
3. Jalankan berurutan: **01 - Token** → **02 - Verify** → **03 - Charge** → **04 - Refund**.
   - `access_token` dan `transaction_number` otomatis tersimpan ke environment lewat post-response script.

## Persiapan Data Lokal

**Cara utama (UI):** panel admin → **Pengaturan** → tabel **"Klien Toko (API Integrasi)"** → tombol **Tambah Klien Toko**. Sistem generate `client_id` + `client_secret`; **secret ditampilkan sekali** — salin untuk dipakai di environment Bruno.

**Alternatif (tinker)** — buat `StoreClient` + anggota bersaldo:

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
