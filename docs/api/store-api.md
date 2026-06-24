# API Integrasi Toko — Pemakaian Saldo Wajib Belanja

API agar aplikasi toko/merchant dapat **memverifikasi akses**, **mengecek saldo**, **memotong saldo Wajib Belanja** anggota, dan **refund** secara aman, idempoten, dan ter-audit.

> ADR: [docs/adr/2026-06-18-integrasi-api-toko-wajib-belanja.md](../adr/2026-06-18-integrasi-api-toko-wajib-belanja.md)

---

## Konvensi Umum

- **Base URL:** `https://<host>/api/v1/store`
- **HTTPS wajib.** NIK adalah data pribadi sensitif — tak boleh lewat kanal tak terenkripsi.
- **Format:** JSON. Kirim header `Accept: application/json` dan `Content-Type: application/json`.
- **Autentikasi:** Bearer token (Laravel Sanctum). Ambil token lewat `POST /token`, lalu kirim `Authorization: Bearer <access_token>` di endpoint lain.
- **Registrasi klien:** `client_id` + `client_secret` dibuat oleh pengurus di panel admin → **Pengaturan → Klien Toko**. Secret ditampilkan sekali saat pembuatan (disimpan ter-hash), dan bisa di-reset dari halaman yang sama.

### Envelope Response

Semua response memakai amplop seragam.

**Sukses:**

```json
{
  "response_code": 200,
  "response_message": "Pesan ringkas.",
  "response_data": { "...": "..." }
}
```

**Error:**

```json
{
  "response_code": 422,
  "response_message": "Penjelasan kesalahan."
}
```

`response_code` selalu sama dengan HTTP status code.

### Kode Status

| Status | Makna |
|--------|-------|
| `200 OK` | Berhasil (termasuk replay idempoten). |
| `201 Created` | Charge/refund baru berhasil dibuat. |
| `401 Unauthorized` | Token tak ada/kadaluarsa/invalid, atau kredensial klien salah. |
| `403 Forbidden` | Token tanpa ability yang diperlukan, atau klien nonaktif. |
| `404 Not Found` | Anggota/transaksi tak valid (pesan generik — tak membocorkan keberadaan data). |
| `409 Conflict` | `Idempotency-Key` dipakai ulang (payload berbeda / milik klien lain). |
| `422 Unprocessable` | Validasi gagal, saldo kurang, nominal ≤ 0, atau melebihi plafon. |
| `429 Too Many Requests` | Rate limit / lockout enumerasi aktif. |

---

## 1. Terbitkan Token

Tukar kredensial klien dengan bearer token ber-ability dan TTL pendek (default 1 jam).

```
POST /api/v1/store/token
```

**Body**

| Field | Tipe | Wajib | Keterangan |
|-------|------|-------|------------|
| `client_id` | string | ya | ID klien toko. |
| `client_secret` | string | ya | Secret klien (diverifikasi via hash). |

**Contoh request**

```bash
curl -X POST https://<host>/api/v1/store/token \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"client_id":"store_acme","client_secret":"rahasia"}'
```

**Sukses `200`**

```json
{
  "response_code": 200,
  "response_message": "Token berhasil diterbitkan.",
  "response_data": {
    "access_token": "1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
    "token_type": "Bearer",
    "expires_in": 3600
  }
}
```

- Ability token: `shopping:charge` selalu; `shopping:refund` hanya bila klien diizinkan refund.
- `expires_in` dalam detik.

**Error**

- `401` — kredensial salah atau klien nonaktif (pesan seragam).
- `422` — `client_id`/`client_secret` kosong.
- `429` — terlalu banyak percobaan (anti brute-force).

---

## 2. Verify (Cek Saldo, Read-only)

Cek saldo Wajib Belanja anggota. **Tidak menulis** transaksi apa pun.

```
POST /api/v1/store/purchases/verify
Authorization: Bearer <access_token>
```

**Body**

| Field | Tipe | Wajib | Keterangan |
|-------|------|-------|------------|
| `nik` | string(16) | ya | NIK anggota. |
| `amount` | numeric | **tidak** | Bila dikirim (> 0), response menambahkan `affordable`. |

**Contoh request — saldo saja**

```bash
curl -X POST https://<host>/api/v1/store/purchases/verify \
  -H "Authorization: Bearer <access_token>" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"nik":"3201234567890001"}'
```

**Sukses `200`**

```json
{
  "response_code": 200,
  "response_message": "Pengecekan saldo berhasil.",
  "response_data": { "balance": "100000.00" }
}
```

**Contoh request — cek saldo + kecukupan nominal**

```bash
curl -X POST https://<host>/api/v1/store/purchases/verify \
  -H "Authorization: Bearer <access_token>" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"nik":"3201234567890001","amount":50000}'
```

```json
{
  "response_code": 200,
  "response_message": "Pengecekan saldo berhasil.",
  "response_data": { "balance": "100000.00", "affordable": true }
}
```

> **PII:** response membalas `balance` (saldo), tetapi **tetap tanpa** nama anggota maupun `member_number`. Enumerasi NIK dibatasi rate limit + lockout per klien.

**Error**

- `404` — NIK tak ditemukan atau anggota nonaktif (pesan generik).
- `422` — validasi gagal (`nik` bukan 16 char, atau `amount ≤ 0` bila dikirim).
- `429` — rate limit / lockout enumerasi.

---

## 3. Charge (Potong Saldo)

Potong saldo secara atomik, idempoten, dan ter-lock (anti over-spend).

```
POST /api/v1/store/purchases
Authorization: Bearer <access_token>
Idempotency-Key: <uuid>
```

**Header**

| Header | Wajib | Keterangan |
|--------|-------|------------|
| `Idempotency-Key` | ya | UUID unik dari toko. Backstop anti dobel-potong. |

**Body**

| Field | Tipe | Wajib | Keterangan |
|-------|------|-------|------------|
| `nik` | string(16) | ya | NIK anggota. |
| `amount` | numeric | ya | Nominal pemakaian (> 0, ≤ plafon per transaksi). |
| `reference_number` | string(≤50) | tidak | Nomor nota/bukti belanja. |

**Contoh request**

```bash
curl -X POST https://<host>/api/v1/store/purchases \
  -H "Authorization: Bearer <access_token>" \
  -H "Idempotency-Key: 5f9c2b1e-7a3d-4e2a-9c1b-0a1b2c3d4e5f" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"nik":"3201234567890001","amount":40000,"reference_number":"NOTA-001"}'
```

**Sukses `201`**

```json
{
  "response_code": 201,
  "response_message": "Pemakaian saldo berhasil dipotong.",
  "response_data": { "transaction_number": "BLJ-2026-000001", "charged": true }
}
```

**Idempoten `200`** — `Idempotency-Key` + payload sama dipanggil ulang:

```json
{
  "response_code": 200,
  "response_message": "Pemakaian saldo sudah pernah tercatat (idempoten).",
  "response_data": { "transaction_number": "BLJ-2026-000001", "charged": true }
}
```

**Error**

- `422` — saldo kurang, `amount ≤ 0`, atau `amount` melebihi plafon per transaksi.
- `409` — `Idempotency-Key` dipakai ulang dengan payload berbeda, atau key milik klien lain.
- `404` — NIK tak valid/nonaktif. `403` — token tanpa `shopping:charge`. `429` — rate limit/lockout.

---

## 4. Refund (Opsional)

Kembalikan saldo via reversal. Hanya **toko asal** transaksi yang boleh refund, dan token harus ber-ability `shopping:refund`.

```
POST /api/v1/store/purchases/{transaction_number}/refund
Authorization: Bearer <access_token>
```

**Body**

| Field | Tipe | Wajib | Keterangan |
|-------|------|-------|------------|
| `reason` | string(5–255) | ya | Alasan refund. |

**Contoh request**

```bash
curl -X POST https://<host>/api/v1/store/purchases/BLJ-2026-000001/refund \
  -H "Authorization: Bearer <access_token>" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"reason":"barang dikembalikan pembeli"}'
```

**Sukses `201`**

```json
{
  "response_code": 201,
  "response_message": "Refund berhasil.",
  "response_data": { "transaction_number": "BLJ-2026-000002", "refunded": true }
}
```

**Idempoten `200`** — transaksi sudah pernah di-refund:

```json
{
  "response_code": 200,
  "response_message": "Transaksi sudah pernah di-refund.",
  "response_data": { "transaction_number": "BLJ-2026-000002", "refunded": true }
}
```

**Error**

- `404` — transaksi tak ditemukan atau bukan milik klien ini.
- `403` — token tanpa `shopping:refund`.
- `422` — `reason` kurang dari 5 karakter.

---

## Catatan Keamanan

- **NIK** tak pernah muncul di log (di-redaksi) maupun di response. Nama anggota & `member_number` juga tak pernah dibalas (verify hanya saldo).
- **Plafon per transaksi** membatasi blast-radius bila secret bocor.
- **Rate limit + lockout** per klien mencegah brute-force secret & enumerasi NIK.
- **Idempotency-Key** di-scope per klien (ownership-check) — tak bocor lintas-merchant.
- Setiap charge ter-audit (`store_client_id`, `member_id`, `amount` — tanpa NIK).
