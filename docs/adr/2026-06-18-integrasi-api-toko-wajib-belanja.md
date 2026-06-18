# Integrasi API Toko — Pemakaian Saldo Wajib Belanja (`source='store_api'`)

Menyediakan API agar aplikasi toko/merchant dapat **memverifikasi akses**, **mengecek biaya/saldo**, lalu **memotong saldo Wajib Belanja** anggota secara aman, idempoten, dan ter-audit — jalur `shopping_transactions.source='store_api'` yang di [ADR Modul Simpanan](2026-06-16-modul-simpanan.md) ditandai sebagai Bab 9 (di luar Minggu 2).

**Author:** gabrieladvent
**Date:** 2026-06-18
**Status:** Draft

---

## Background

Modul Simpanan (Minggu 2) sudah menyediakan **pemakaian Wajib Belanja jalur `manual`** lewat `ShoppingTransactionResource` (item 5a): saldo dua-sisi (`deposits.net('wajib_belanja') − shopping.net()`), idempotency, validasi `≤ saldo`, dan reversal. Fondasi yang **sudah ada dan dipakai ulang** di ADR ini:

- `shopping_transactions.source` **sudah** enum `['manual','store_api']` — kolom `store_api` menunggu jalur ini.
- `shopping_transactions.recorded_by` **nullable** — pas untuk transaksi tanpa aktor manusia (dilakukan sistem/toko).
- `shopping_transactions.idempotency_key` unique (D9 ADR Simpanan) — backstop anti dobel-potong.
- `SavingsBalanceService::shoppingBalance()` & `canSpendShopping()` — sumber kebenaran saldo.
- `ReverseTransaction` Action + `unique(reversal_of_id)` — untuk refund/koreksi.

Yang **belum ada**: lapisan **HTTP API** (belum ada `routes/api.php` berisi rute, belum ada package auth token), model **klien toko**, dan engine pemakaian yang **dipakai bersama** jalur manual + API.

Tiga prinsip keuangan non-negotiable (Dokumentasi §7) tetap berlaku: saldo computed-on-read, reversal bukan hapus, anti input ganda.

---

## Goals

- **Verifikasi akses berbasis token** — toko minta token dulu (client credentials), lalu request lanjutan pakai bearer token ber-scope.
- **Verify biaya (read-only)** — endpoint cek apakah saldo Wajib Belanja anggota cukup, tanpa menulis apa pun.
- **Charge (potong + update saldo)** — endpoint potong saldo secara **atomik, idempoten, ter-lock** (anti over-spend), saldo ter-update otomatis (computed-on-read).
- **Engine dipakai ulang** — satu action `RecordShoppingUsage` dipakai jalur manual (5a) **dan** API, agar aturan saldo identik & tak terduplikasi.
- **Audit penuh** — tiap charge tercatat (`LogsActivity` + event), menyertakan toko mana yang melakukan.
- **Refund/koreksi** via reversal yang sudah ada.

## Non-Goals

- **UI manajemen merchant** (CRUD `StoreClient` lewat Filament) — boleh menyusul; ADR ini fokus kontrak API + engine. Registrasi klien awal cukup via seeder/artisan.
- **Settlement / pembayaran balik ke toko** — koperasi hanya memotong saldo anggota; rekonsiliasi dana toko di luar scope.
- **Top-up saldo Wajib Belanja lewat API** — penambahan saldo tetap lewat setoran (`savings_deposits`), bukan API toko.
- **Webhook / push ke toko** — komunikasi murni request–response dulu.
- **Perubahan kolom existing** — hanya migrasi **aditif** (tabel `store_clients` baru + kolom `store_client_id` nullable di `shopping_transactions`).

---

## Design

### Alur (authorize → capture)

```
Toko / Merchant App                         Koperasi API
  │ 1. POST /api/v1/store/token             {client_id, client_secret}
  │ ───────────────────────────────────►  verifikasi klien aktif → terbitkan bearer (ability shopping:charge, TTL pendek)
  │ ◄───────────────────────────────────  {access_token, token_type:"Bearer", expires_in}
  │
  │ 2. POST /api/v1/store/purchases/verify  Bearer + {nik, amount}
  │ ───────────────────────────────────►  token valid → anggota Aktif by NIK → canSpendShopping (READ-ONLY)
  │ ◄───────────────────────────────────  {affordable, balance, member:{name, member_number}}
  │
  │ 3. POST /api/v1/store/purchases         Bearer + Idempotency-Key + {nik, amount, reference}
  │ ───────────────────────────────────►  lock member → re-cek saldo → potong (ShoppingTransaction source=store_api) → log
  │ ◄───────────────────────────────────  {transaction_number, charged:true, new_balance}
```

### Keputusan Desain

#### D1 — Auth: Sanctum token ke `StoreClient`, ability `shopping:charge`, TTL pendek

Pakai **Laravel Sanctum** (belum terpasang → tambah `laravel/sanctum`). Model baru **`StoreClient`** (`id`, `name`, `client_id` unik, `client_secret` **hashed** via `Hash::make`, `is_active`).

- **`POST /api/v1/store/token`**: validasi `client_id` + `client_secret` (hash check) + `is_active` → `createToken('store-charge', ['shopping:charge'], expiresAt: now()->addHour())` → balas `access_token`.
- Rute charge/verify dilindungi `auth:sanctum` + middleware **`abilities:shopping:charge`** + cek `tokenable` (StoreClient) masih `is_active`.
- Token **short-lived** (default 1 jam) + dapat dicabut (hapus token / non-aktifkan klien). Tak ada session, murni bearer.

> **Kenapa Sanctum, bukan Passport:** klien machine-to-machine sederhana, tak perlu OAuth2 penuh. Sanctum cukup (ability/scope + expiry). Bila kelak banyak merchant pihak-ketiga butuh OAuth2 standar, migrasi ke Passport client_credentials dicatat sebagai follow-up.

#### D2 — Dua fase: verify (read-only) lalu charge (write)

Pisahkan **verify** (cek saldo, tak menulis) dari **charge** (potong). Toko memanggil verify untuk menampilkan "saldo cukup?" sebelum gesek, lalu charge untuk mengeksekusi. Saldo bisa berubah di antara dua panggilan → **charge tetap re-cek saldo otoritatif di dalam lock** (verify hanya indikatif).

#### D3 — Identifikasi anggota: **NIK** (data sensitif → mitigasi ketat)

Anggota diidentifikasi lewat **NIK** (keputusan pengurus 2026-06-18). NIK adalah **data pribadi sensitif**, maka:

- **HTTPS wajib** (TLS) — NIK tak boleh lewat kanal tak terenkripsi.
- **Jangan pernah log NIK plaintext** — log/aktivitas merujuk `member_id`/`member_number`, **bukan** NIK. Request logging harus me-redact field `nik`.
- **Rate limit ketat** pada verify/charge per klien (mis. 60/menit) untuk mencegah **enumerasi NIK** (menebak NIK valid).
- **Pesan error seragam** saat NIK tak ditemukan vs anggota nonaktif — hindari membocorkan keberadaan NIK (balas `404`/`422` generik "anggota tidak valid untuk transaksi").
- Lookup `where('nik', $nik)` harus **exact match** + anggota berstatus **Aktif**.

> **⚠️ Tradeoff (dicatat eksplisit):** memakai NIK mentah sebagai identifier membuat toko **memegang/mengirim NIK** tiap transaksi — permukaan kebocoran PII lebih lebar dibanding token kartu. Alternatif **token/QR kartu** (D-alt) lebih privat tapi butuh tabel kartu + alur penerbitan. Pengurus memilih NIK demi kesederhanaan operasional; mitigasi di atas **wajib** menutup risikonya. Migrasi ke token kartu dicatat sebagai follow-up bila audit privasi menuntut.

#### D4 — Engine `RecordShoppingUsage` dipakai bersama (manual + API)

Ekstrak logika pemakaian ke **invokable Action** `app/Actions/RecordShoppingUsage.php` yang dipakai **dua jalur**: form manual (refactor 5a) dan controller API. Di dalam `DB::transaction`:

1. **Lock baris member** (`lockForUpdate`) — serialisasi anti over-spend (pola sama disburse pencairan D10 Modul Simpanan).
2. **Re-cek** `canSpendShopping(member, amount)` di dalam lock (otoritatif).
3. Buat `ShoppingTransaction` (`source`, `idempotency_key`, `amount`, `transaction_date`, `recorded_by`, `store_client_id`).
4. Saldo **otomatis ter-update** (computed-on-read) — balas `shoppingBalance` terbaru.

Throw `CannotSpendShopping` (domain exception baru) bila saldo kurang / anggota nonaktif → controller map ke `422`.

> Invariant over-spend ini **lock-dependent → wajib diuji di MySQL** (no-op di SQLite), sama seperti catatan konkurensi Modul Simpanan. Tanpa unique-constraint agregat, lock adalah satu-satunya penjaga "Σ pakai ≤ saldo".

#### D5 — Idempotency: header `Idempotency-Key` → `unique(idempotency_key)`

Charge **wajib** menyertakan header `Idempotency-Key` (UUID dari toko). Disimpan ke `shopping_transactions.idempotency_key`.

- Key sama + **payload sama** → kembalikan transaksi yang sama (`200`, tak dobel-potong) — tangkap `UniqueConstraintViolationException`, fetch existing, balas hasilnya.
- Key sama + **payload beda** → `409 Conflict` (cegah toko diam-diam mengubah nilai di key yang sama).
- Backstop sejati = `unique(idempotency_key)` (berlaku MySQL & SQLite). Lock hanya optimasi anti-race.

#### D6 — Audit "toko mana": kolom aditif `store_client_id` + `recorded_by=null`

Migrasi **aditif**: `shopping_transactions.store_client_id` (uuid/foreignId nullable, FK `store_clients`). Untuk `source='store_api'`: `recorded_by = null` (tak ada user manusia), `store_client_id = <klien>`. Untuk `source='manual'`: `store_client_id = null`, `recorded_by` = petugas (tetap dipaksa non-null, security #M3). Event aktivitas `store_charge` mencatat `store_client_id`, `member_id`, `amount` — **tanpa NIK**.

#### D7 — Kontrak error JSON seragam

| Kode | Kondisi |
|---|---|
| `401 Unauthorized` | token tak ada/kadaluarsa/invalid |
| `403 Forbidden` | token tanpa ability `shopping:charge` / klien nonaktif |
| `404 Not Found` | anggota tak valid untuk transaksi (NIK tak ada — pesan generik) |
| `422 Unprocessable` | saldo kurang, `amount ≤ 0`, anggota nonaktif, payload invalid |
| `409 Conflict` | `Idempotency-Key` dipakai ulang dengan payload berbeda |
| `429 Too Many Requests` | rate limit terlampaui |

Body error konsisten: `{ "message": "...", "code": "INSUFFICIENT_BALANCE" }`.

#### D8 — Refund/koreksi via reversal yang sudah ada

Opsional (boleh fase lanjut): **`POST /api/v1/store/purchases/{transaction_number}/refund`** `{reason}` → jalankan `ReverseTransaction` atas baris store_api itu → saldo kembali. Gated ability terpisah `shopping:refund` (tak semua toko boleh refund). Idempoten via `unique(reversal_of_id)`.

#### D9 — Keamanan & operasional (ringkas)

HTTPS only · token scoped + TTL pendek + revocable · **rate limit per klien** (anti enumerasi NIK & abuse) · **idempotency wajib** di charge · audit tiap charge (tanpa NIK) · anggota wajib Aktif · validasi server `amount > 0` & `≤ saldo` · redaksi NIK di log.

### Alternatives Considered

| Alternative | Pro | Con | Verdict |
|---|---|---|---|
| Sanctum token (D1) | Ringan, ability+TTL, revocable | Bukan OAuth2 penuh | **Chosen** |
| Passport client_credentials | Standar OAuth2 | Berat untuk kebutuhan saat ini | Ditunda (follow-up) |
| API key statis per toko | Paling sederhana | Tak bisa rotasi/TTL, rawan bocor | Rejected |
| Identifier NIK (D3) | Tak perlu tabel kartu | NIK PII tersebar ke toko | **Chosen + mitigasi** |
| Token/QR kartu | Privat, tak ekspos NIK | Butuh tabel & alur penerbitan kartu | Ditunda (follow-up) |
| Dua fase verify+charge (D2) | UX toko jelas; charge tetap otoritatif | 2 round-trip | **Chosen** |
| Charge langsung tanpa verify | 1 round-trip | Toko tak bisa pre-cek saldo | Rejected |
| Engine `RecordShoppingUsage` shared (D4) | Aturan saldo tunggal | Refactor 5a | **Chosen** |

---

## Key Items

| # | Item | Effort | Status |
|---|------|--------|--------|
| 0 | Migrasi aditif: tabel `store_clients`; kolom `store_client_id` (nullable) di `shopping_transactions` | S | Pending |
| 1 | `laravel/sanctum` install + config + model `StoreClient` (+factory) | S | Pending |
| 2 | `RecordShoppingUsage` action (lock + re-cek + create) + `CannotSpendShopping` exception; **refactor 5a manual pakai action ini** | M | Pending |
| 3 | `StoreAuthController@token` (verify kredensial → bearer ability `shopping:charge`, TTL) + rute `routes/api.php` | S | Pending |
| 4 | `StorePurchaseController@verify` (read-only saldo by NIK) | S | Pending |
| 5 | `StorePurchaseController@charge` (idempotency-key, lock, potong, audit `store_charge`, JSON error map) | M | Pending |
| 6 | Middleware ability + rate limit per klien + **redaksi NIK** di log | S | Pending |
| 7 | (Opsional) `@refund` via `ReverseTransaction`, ability `shopping:refund` | S | Pending |
| 8 | Tes feature API (token, verify, charge idempoten, saldo kurang, NIK invalid generik, rate limit) + **tes konkurensi over-spend di MySQL** | M | Pending |

**Effort:** S < 1 jam, M 1–3 jam.

---

## Verification

- [ ] Token hanya terbit untuk klien aktif dengan kredensial benar; token kadaluarsa/ability salah ditolak (D1). <!-- source: code -->
- [ ] Verify mengembalikan saldo & `affordable` tanpa menulis transaksi apa pun (D2). <!-- source: code -->
- [ ] Charge memotong saldo, membuat `ShoppingTransaction(source=store_api, store_client_id, recorded_by=null)`, saldo ter-update (D4/D6). <!-- source: code -->
- [ ] Charge dengan saldo kurang ditolak `422`; `amount ≤ 0` ditolak (D4/D7). <!-- source: code -->
- [ ] `Idempotency-Key` sama + payload sama → tak dobel-potong; payload beda → `409` (D5). <!-- source: code -->
- [ ] **Dua charge konkuren member sama tak bisa over-spend** (lock, diuji di MySQL) (D4). <!-- source: code -->
- [ ] NIK tak ditemukan & anggota nonaktif → pesan generik (tak bocorkan keberadaan NIK); **NIK tak muncul di log** (D3). <!-- source: code -->
- [ ] Rate limit per klien aktif pada verify/charge (D3/D9). <!-- source: code -->
- [ ] Tiap charge ter-log event `store_charge` dengan `store_client_id` (tanpa NIK) (D6). <!-- source: code -->
- [ ] (Bila ada) Refund mengembalikan saldo via reversal; gated `shopping:refund` (D8). <!-- source: code -->

---

## Open Questions

- **TTL token & rotasi** — default 1 jam; perlu refresh-token atau toko minta token baru tiap sesi? (default: minta ulang).
- **Batas nominal per transaksi / per hari** per anggota — perlu plafon harian belanja? (Dokumentasi belum menetapkan; default: hanya `≤ saldo`).
- **Refund: siapa boleh** — semua toko atau hanya toko asal transaksi? (default usulan: hanya toko asal, via `store_client_id` match).
- **Registrasi `StoreClient`** — lewat seeder/artisan dulu (Non-Goal UI); kapan butuh CRUD Filament?
- **Privasi NIK** — bila audit privasi menuntut, migrasi ke token kartu (D3 alt). Konfirmasi pengurus apakah NIK acceptable jangka panjang.

---

## Changelog

- **2026-06-18 v1**: Draft awal. Skema integrasi API toko untuk pemakaian Wajib Belanja: Sanctum token (D1), dua fase verify→charge (D2), identifier **NIK** + mitigasi PII (D3), engine `RecordShoppingUsage` shared (D4), idempotency header (D5), kolom aditif `store_client_id` + audit (D6), kontrak error JSON (D7), refund opsional (D8). Dibangun di atas fondasi `shopping_transactions` (source enum, recorded_by nullable, idempotency, reversal) yang sudah ada dari Modul Simpanan 5a.
