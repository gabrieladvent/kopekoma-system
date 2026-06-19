# Integrasi API Toko — Pemakaian Saldo Wajib Belanja (`source='store_api'`)

Menyediakan API agar aplikasi toko/merchant dapat **memverifikasi akses**, **mengecek biaya/saldo**, lalu **memotong saldo Wajib Belanja** anggota secara aman, idempoten, dan ter-audit — jalur `shopping_transactions.source='store_api'` yang di [ADR Modul Simpanan](2026-06-16-modul-simpanan.md) ditandai sebagai Bab 9 (di luar Minggu 2).

**Author:** gabrieladvent
**Date:** 2026-06-18
**Status:** Accepted

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

**Model `wajib_belanja` = saldo prepaid (bukan kredit/talangan).** Anggota **menabung dulu** lewat setoran `wajib_belanja` (`SavingsDepositResource`, nominal terkunci dari setting `savings_wajib_belanja_amount`), baru boleh belanja **sebatas saldo**. Charge **hanya mengurangi** saldo prepaid itu (`deposits('wajib_belanja') − usage`) dan **ditolak bila saldo kurang** — anggota tak pernah belanja "ngutang". Konsekuensi desain: validasi `≤ saldo` + lock konkurensi (D4) memang **wajib**, karena tanpa lock dua charge konkuren bisa menembus saldo. Pendanaan saldo **di luar scope** ADR ini (tetap via setoran, lihat Non-Goal top-up).

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
  │ ◄───────────────────────────────────  {affordable}  (tanpa nama/saldo — minim PII, lihat D2)
  │
  │ 3. POST /api/v1/store/purchases         Bearer + Idempotency-Key + {nik, amount, reference_number}
  │ ───────────────────────────────────►  lock member → re-cek saldo → potong (ShoppingTransaction source=store_api) → log
  │ ◄───────────────────────────────────  {transaction_number, charged:true}
```

### Keputusan Desain

#### D1 — Auth: Sanctum token ke `StoreClient`, ability `shopping:charge`, TTL pendek

Pakai **Laravel Sanctum** (belum terpasang → tambah `laravel/sanctum`). Model baru **`StoreClient`** (`id`, `name`, `client_id` unik, `client_secret` **hashed** via `Hash::make`, `is_active`).

- **`POST /api/v1/store/token`**: validasi `client_id` + `client_secret` (hash check) + `is_active` → `createToken('store-charge', ['shopping:charge'], expiresAt: now()->addHour())` → balas `access_token`.
  - **Rate limit + lockout pada endpoint token** (per `client_id` & per IP) — `Hash::check` di sini adalah permukaan **credential-stuffing `client_secret`**; tanpa pembatas, secret bisa di-brute-force. Lockout D3 hanya menutup enumerasi NIK di verify/charge, **bukan** endpoint token ini.
- Rute charge/verify dilindungi `auth:sanctum` + middleware **`abilities:shopping:charge`** + cek `tokenable` (StoreClient) masih `is_active`.
- **`tokenable` wajib bertipe `StoreClient`** — middleware menolak token milik `User` (manusia) agar token sesi petugas tak bisa dipakai jalur charge API. Karena `personal_access_tokens.tokenable` polymorphic, `auth:sanctum` bisa mengembalikan tokenable apa pun; pembatasan tipe ini eksplisit dan diuji.
- Token **short-lived** (default 1 jam) + dapat dicabut (hapus token / non-aktifkan klien). Tak ada session, murni bearer.

> **Kenapa Sanctum, bukan Passport:** klien machine-to-machine sederhana, tak perlu OAuth2 penuh. Sanctum cukup (ability/scope + expiry). Bila kelak banyak merchant pihak-ketiga butuh OAuth2 standar, migrasi ke Passport client_credentials dicatat sebagai follow-up.

> **Catatan blast-radius:** TTL pendek melindungi *token* yang bocor, **bukan** `client_secret` yang bocor (pemegang secret bisa cetak token baru sesuka hati). Karena itu pembatasan kerusakan tak boleh bergantung pada TTL saja — lihat plafon per-transaksi (D2) dan lockout enumerasi (D3).

#### D2 — Dua fase: verify (read-only) lalu charge (write); response minim-PII + plafon per-transaksi

Pisahkan **verify** (cek saldo, tak menulis) dari **charge** (potong). Toko memanggil verify untuk menampilkan "saldo cukup?" sebelum gesek, lalu charge untuk mengeksekusi. Saldo bisa berubah di antara dua panggilan → **charge tetap re-cek saldo otoritatif di dalam lock** (verify hanya indikatif).

- **Response minim-PII.** Verify membalas **hanya `{affordable: bool}`** — **tanpa** nama anggota, `member_number`, maupun nominal saldo. Membalas nama/saldo akan menjadikan verify sebuah *oracle*: merchant mana pun bisa menukar NIK → identitas + profil kekayaan anggota, membatalkan mitigasi PII D3. Charge membalas `{transaction_number, charged}` tanpa `new_balance` (toko tak perlu tahu sisa saldo anggota).
- **Plafon nominal per transaksi (wajib di v1).** **Controller API** (bukan shared Action — lihat D4b) menolak `amount` di atas plafon per-transaksi (`config('store.max_charge_per_tx')`, mis. Rp 2.000.000) sebelum memanggil Action. Ini membatasi blast-radius bila `client_secret` bocor: penyerang tak bisa kuras saldo besar dalam satu hentakan; jalur manual petugas **tak** terkena plafon ini. Plafon **harian per anggota** menyusul bila pengurus menetapkan (Open Question).

#### D3 — Identifikasi anggota: **NIK** (data sensitif → mitigasi ketat)

Anggota diidentifikasi lewat **NIK** (keputusan pengurus 2026-06-18). NIK adalah **data pribadi sensitif**, maka:

- **HTTPS wajib** (TLS) — NIK tak boleh lewat kanal tak terenkripsi.
- **Jangan pernah log NIK plaintext** — log/aktivitas merujuk `member_id`/`member_number`, **bukan** NIK. Request logging harus me-redact field `nik`.
- **Rate limit + lockout per klien** terhadap enumerasi NIK. Rate limit biasa (mis. 60/menit) **tidak cukup** — 60/menit ≈ 86 ribu percobaan/hari, sementara ruang tebak NIK menyempit drastis oleh prefix wilayah+tanggal-lahir. Maka tambahkan **lockout**: setelah N kegagalan lookup beruntun (mis. 10) dalam jendela waktu, klien diblokir sementara (cooldown) dan event dicatat untuk audit. Verify dan charge berbagi counter ini. **Penyimpanan counter/lockout:** cache (database-backed, sesuai stack) ber-key `store:lockout:{store_client_id}`; event blokir ditulis ke activity log untuk audit.
- **Pesan error seragam** saat NIK tak ditemukan vs anggota nonaktif — hindari membocorkan keberadaan NIK (balas `404`/`422` generik "anggota tidak valid untuk transaksi").
- Lookup `where('nik', $nik)` harus **exact match** + anggota berstatus **Aktif** — ini **satu-satunya tempat** enforce status: anggota tak ada **maupun** non-aktif sama-sama gagal di sini → `404` generik (D4 **tidak** lagi mengecek status, hindari dua jalur untuk kondisi sama).
- **Residual oracle (diterima eksplisit):** walau verify hanya balas `{affordable}`, NIK valid (`200`) tetap dapat dibedakan dari NIK invalid (`404`). Ini *boolean existence oracle* yang tak terhindarkan dari lookup-by-NIK; mitigasinya **murni lockout + rate limit di atas**, bukan penyamaran response. Risiko ini diterima sadar, bukan terlewat.

> **⚠️ Tradeoff (dicatat eksplisit):** memakai NIK mentah sebagai identifier membuat toko **memegang/mengirim NIK** tiap transaksi — permukaan kebocoran PII lebih lebar dibanding token kartu. Alternatif **token/QR kartu** (D-alt) lebih privat tapi butuh tabel kartu + alur penerbitan. Pengurus memilih NIK demi kesederhanaan operasional; mitigasi di atas **wajib** menutup risikonya. Migrasi ke token kartu dicatat sebagai follow-up bila audit privasi menuntut.

#### D4 — Engine `RecordShoppingUsage` dipakai bersama (manual + API)

Ekstrak logika pemakaian ke **invokable Action** `app/Actions/RecordShoppingUsage.php` yang dipakai **dua jalur**: form manual (refactor 5a) dan controller API. Di dalam `DB::transaction`:

1. **Lock baris member** (`lockForUpdate`) — serialisasi anti over-spend (pola sama disburse pencairan D10 Modul Simpanan).
2. **Re-cek** `canSpendShopping(member, amount)` di dalam lock (otoritatif).
3. Buat `ShoppingTransaction` (`source`, `idempotency_key`, `amount`, `transaction_date`, `recorded_by`, `store_client_id`) — **`transaction_number` selalu di-generate** (dipakai sebagai path refund D8; jangan biarkan null untuk jalur store_api).
4. Saldo **otomatis ter-update** (computed-on-read). Action **mengembalikan** `shoppingBalance` terbaru ke pemanggil.

> **Catatan response (lihat D2):** action *internal* return saldo terbaru — jalur **manual** memang butuh untuk menampilkan sisa. Tapi **controller API yang membuangnya** dari response charge (`{transaction_number, charged}` saja, tanpa `new_balance`) supaya saldo anggota tak bocor ke toko. Saldo bocor/tidak ditentukan di lapisan controller, bukan di action.

**Tipe `amount`:** divalidasi & dioperasikan sebagai **string desimal / integer rupiah** (bcmath), **bukan float** — `canSpendShopping(Member, string $amount)` memang menerima string. Request `amount` ditolak bila bukan numerik bulat/desimal valid atau ≤ 0 (hindari galat pembulatan float di nominal uang).

Throw `CannotSpendShopping` (domain exception baru) bila **saldo kurang** → controller map ke `422`. Status Aktif **tidak** dicek di sini — itu sudah di-enforce saat lookup-by-NIK (D3); anggota non-aktif tak pernah sampai ke Action.

> Invariant over-spend ini **lock-dependent → wajib diuji di MySQL** (no-op di SQLite), sama seperti catatan konkurensi Modul Simpanan. Tanpa unique-constraint agregat, lock adalah satu-satunya penjaga "Σ pakai ≤ saldo".

#### D4b — Pembagian tanggung jawab: Action → Controller → API Resource

Tiga lapis, satu kebijakan satu rumah. Memisahkan **eksekusi** (Action), **orkestrasi** (Controller), dan **pembungkus output** (API Resource) memastikan kebijakan yang berbeda antara jalur manual dan API **tidak bocor** ke jalur yang salah.

| Lapis | Tanggung jawab | **Bukan** tanggung jawabnya |
|---|---|---|
| **Action `RecordShoppingUsage`** (eksekusi, shared manual+API) | Lock member · re-cek `canSpendShopping` di dalam lock · create `ShoppingTransaction` · return objek domain (transaksi + `shoppingBalance` terbaru). Tak sadar HTTP. | Plafon merchant, bentuk response, kode HTTP, idempotency-key parsing — **tak boleh** ada di sini (kalau ada, jalur manual ikut terkurung). |
| **Controller API** (orkestrasi, khusus API) | Validasi request (`amount` string/bcmath, NIK) · **enforce plafon per-transaksi** (khusus API) · lookup member by NIK (+ status) · idempotency: ownership-check + hash + map `UniqueConstraintViolation` → `200`/`409` · panggil Action · map `CannotSpendShopping` → `422` · serahkan hasil ke Resource. | Logika saldo/lock (itu milik Action) · merakit JSON tangan (itu milik Resource). |
| **API Resource** (`JsonResource`, khusus API) | **Whitelist field output** — satu-satunya tempat yang menentukan apa yang keluar. `StorePurchaseResource` → `{transaction_number, charged}`; `VerifyResource` → `{affordable}`. NIK/saldo/nama/`member_number` **tak pernah** ada di whitelist → minim-PII jadi **jaminan struktural**, bukan kedisiplinan tiap controller. | Keputusan bisnis/lock. |

Konsekuensi yang dipegang desain ini:

- **Plafon** (D2) di **Controller**, bukan Action → koreksi manual petugas tak terkena plafon merchant.
- **Buang saldo dari response** (D2/D4) dilakukan oleh **Resource** (whitelist), bukan dihafal controller → tak bisa lupa.
- **Saldo untuk UI manual** (Filament) datang dari nilai return Action langsung — tak lewat `JsonResource`, jadi tetap tampil.

#### D5 — Idempotency: `unique(idempotency_key)` global tetap; isolasi merchant via ownership-check

Charge **wajib** menyertakan header `Idempotency-Key` (UUID dari toko). Disimpan ke `shopping_transactions.idempotency_key`, **bersama `idempotency_hash`** (HMAC-SHA256, key = `APP_KEY`, atas payload kanonik **`{member_id, amount, reference_number}`** — **tanpa NIK**, lihat D3; pakai `member_id` hasil lookup, bukan NIK, supaya kolom hash tak bisa di-balik jadi NIK).

- **Constraint tak diubah — tetap `unique(idempotency_key)` global.** Ini menjaga sifat migrasi **aditif** (item 0 hanya *menambah* kolom, tak drop/recreate constraint) **dan** menjaga idempotency jalur **manual** yang sudah ada (mengganti ke composite `(store_client_id, idempotency_key)` akan jebol untuk manual karena `store_client_id` NULL → banyak NULL diperbolehkan MySQL).
- **Isolasi lintas-merchant via ownership-check, bukan via constraint.** Saat insert kena `UniqueConstraintViolationException`, fetch baris existing lalu cek **`store_client_id` cocok** dengan klien pemanggil:
  - cocok + **hash sama** → kembalikan transaksi yang sama (`200`, tak dobel-potong).
  - cocok + **hash beda** → `409 Conflict` (toko diam-diam mengubah nilai di key yang sama).
  - **tak cocok** (key dipakai klien/jalur lain) → `409 Conflict` generik — **tak** membocorkan isi transaksi milik klien lain.
- **Urutan tulis:** baris transaksi (termasuk `idempotency_key`) ditulis **di dalam** `DB::transaction` yang sama dengan lock member (D4). Request kedua dengan key sama memblok di unique index sampai commit pertama, lalu menerima `UniqueConstraintViolationException` → masuk jalur fetch-existing + ownership-check di atas. Lock hanya optimasi anti-race; unique constraint adalah backstop sejati (berlaku MySQL & SQLite).

#### D6 — Audit "toko mana": kolom aditif `store_client_id` + `recorded_by=null`

Migrasi **aditif**: `shopping_transactions.store_client_id` (uuid/foreignId nullable, FK `store_clients`). Untuk `source='store_api'`: `recorded_by = null` (tak ada user manusia), `store_client_id = <klien>`. Untuk `source='manual'`: `store_client_id = null`, `recorded_by` = petugas (tetap dipaksa non-null, security #M3). Payload `reference_number` dari toko disimpan ke kolom existing `shopping_transactions.reference_number` (bukan kolom baru). Event aktivitas `store_charge` mencatat `store_client_id`, `member_id`, `amount` — **tanpa NIK**.

> **Causer activity log:** jalur API tak punya `User` causer. `LogsActivity` di-set causer **null** (bukan user manusia); identitas pelaku tetap terekam lewat properti event `store_client_id`. Jangan paksa causer ke `StoreClient` karena bukan `User` (relasi causer di paket activitylog mengarah ke users).

#### D7 — Kontrak error JSON seragam

| Kode | Kondisi |
|---|---|
| `401 Unauthorized` | token tak ada/kadaluarsa/invalid |
| `403 Forbidden` | token tanpa ability `shopping:charge` / klien nonaktif |
| `404 Not Found` | anggota tak valid untuk transaksi (NIK tak ada **atau** non-aktif — pesan generik, satu jalur, lihat D3) |
| `422 Unprocessable` | saldo kurang, `amount ≤ 0`/bukan numerik, `amount > plafon`, payload invalid |
| `409 Conflict` | `Idempotency-Key` dipakai ulang dengan payload berbeda (hash beda) |
| `429 Too Many Requests` | rate limit terlampaui / lockout enumerasi aktif |

Body error konsisten: `{ "message": "...", "code": "INSUFFICIENT_BALANCE" }`.

#### D8 — Refund/koreksi via reversal yang sudah ada

Opsional (boleh fase lanjut): **`POST /api/v1/store/purchases/{transaction_number}/refund`** `{reason}` → jalankan `ReverseTransaction` atas baris store_api itu → saldo kembali. Gated ability terpisah `shopping:refund` (tak semua toko boleh refund). Idempoten via `unique(reversal_of_id)`: dua refund konkuren atas transaksi yang sama → satu sukses, satunya kena `UniqueConstraintViolationException` → **map ke `200` mengembalikan reversal yang sudah ada** (pola catch-violation sama seperti D5), bukan `500`. Hanya **toko asal** (match `store_client_id`) yang boleh me-refund transaksinya sendiri.

> **Catatan kode (sentuh model, bukan pure-reuse):** [`ShoppingTransaction::reverseClone()`](../../app/Models/ShoppingTransaction.php) saat ini **tidak** menyalin `store_client_id` → baris refund store_api lahir tanpa atribusi toko, memutus audit D6 di sisi reversal. Item 7 **harus** menambah `'store_client_id' => $this->store_client_id` ke `reverseClone()` (`idempotency_hash` reversal boleh null — dedup dijaga `unique(reversal_of_id)`, bukan hash).

#### D9 — Keamanan & operasional (ringkas)

HTTPS only · token scoped + TTL pendek + revocable · `tokenable` wajib `StoreClient` · **rate limit/lockout di endpoint token (anti brute-force secret) & verify/charge (anti enumerasi NIK)** · **idempotency wajib** di charge (global unique + ownership-check, hash tanpa NIK) · **plafon nominal per transaksi** · audit tiap charge (tanpa NIK, causer null) · anggota wajib Aktif · validasi server `amount` string/bcmath `> 0`, `≤ saldo`, `≤ plafon` (tolak float) · redaksi NIK di log · response minim-PII (verify hanya `affordable`, charge tanpa saldo).

### Alternatives Considered

| Alternative | Pro | Con | Verdict |
|---|---|---|---|
| Sanctum token (D1) | Ringan, ability+TTL, revocable | Bukan OAuth2 penuh | **Chosen** |
| Passport client_credentials | Standar OAuth2 | Berat untuk kebutuhan saat ini | Ditunda (follow-up) |
| API key statis per toko | Paling sederhana | Tak bisa rotasi/TTL, rawan bocor | Rejected |
| Identifier NIK (D3) | Tak perlu tabel kartu | NIK PII tersebar ke toko | **Chosen + mitigasi** |
| Token/QR kartu | Privat, tak ekspos NIK | Butuh tabel & alur penerbitan kartu | Ditunda (follow-up) |
| Dua fase verify+charge (D2), verify balas `{affordable}` saja | UX toko jelas; charge tetap otoritatif; tak ekspos PII | 2 round-trip | **Chosen** |
| Verify balas nama+saldo | UI toko lebih kaya | Oracle NIK→identitas+kekayaan, batalkan mitigasi D3 | Rejected |
| Charge langsung tanpa verify | 1 round-trip | Toko tak bisa pre-cek saldo | Rejected |
| `unique(idempotency_key)` global + **ownership-check** saat konflik (D5) | Aditif murni; idempotency manual aman; isolasi merchant tetap terjaga | Logika konflik di app, bukan murni DB | **Chosen** |
| Composite `unique(store_client_id, idempotency_key)` | Isolasi di level DB | `store_client_id` NULL untuk manual → banyak NULL diizinkan MySQL → **idempotency manual jebol**; bukan aditif | Rejected |
| Engine `RecordShoppingUsage` shared (D4) | Aturan saldo tunggal | Refactor 5a | **Chosen** |

---

## Key Items

| # | Item | Effort | Status |
|---|------|--------|--------|
| 0 | Migrasi **aditif murni**: tabel `store_clients`; kolom `store_client_id` (nullable) + `idempotency_hash` (nullable) di `shopping_transactions`. **`unique(idempotency_key)` global tidak diubah** (jaga idempotency manual + aditif) | S | **Done** |
| 1 | `laravel/sanctum` install + config + model `StoreClient` (+factory), `secret` hashed | S | **Done** |
| 2 | `RecordShoppingUsage` action (lock + re-cek + create) + `CannotSpendShopping` exception; **refactor 5a manual pakai action ini** | M | **Done** |
| 3 | `StoreAuthController@token` (verify kredensial → bearer ability `shopping:charge`, TTL) + **rate limit/lockout endpoint token** + rute `routes/api.php` | S | Pending |
| 4 | `StorePurchaseController@verify` + **`VerifyResource`** (read-only, whitelist `{affordable}` saja) | S | Pending |
| 5 | `StorePurchaseController@charge` + **`StorePurchaseResource`** (idempotency-key + hash + ownership-check, **plafon per-tx di controller**, Action eksekusi, audit `store_charge`, whitelist `{transaction_number, charged}`, JSON error map) | M | Pending |
| 6 | Middleware ability + **pembatas `tokenable=StoreClient`** + rate limit & **lockout enumerasi** per klien + **redaksi NIK** di log | S | Pending |
| 7 | (Opsional) `@refund` via `ReverseTransaction`, ability `shopping:refund`, **match toko asal**, catch-violation → 200, **`reverseClone()` salin `store_client_id`** | S | Pending |
| 8 | Tes feature API (token + **brute-force secret diblok**, verify minim-PII, charge idempoten, **key sama lintas-klien → 409 bukan bocor**, saldo kurang, plafon, `amount` float ditolak, NIK invalid generik, lockout, **tokenable bukan User ditolak**) + **tes konkurensi over-spend di MySQL** | M | Pending |

**Effort:** S < 1 jam, M 1–3 jam.

---

## Verification

- [ ] Token hanya terbit untuk klien aktif dengan kredensial benar; token kadaluarsa/ability salah ditolak (D1). <!-- source: code -->
- [ ] Token dengan `tokenable=User` (manusia) ditolak di rute store; hanya `StoreClient` diterima (D1). <!-- source: code -->
- [ ] Verify membalas **`{affordable}` saja** — tanpa nama/`member_number`/saldo — dan tak menulis transaksi apa pun (D2). <!-- source: code -->
- [ ] **`JsonResource` hanya emit field whitelist** — uji terisolasi `VerifyResource`/`StorePurchaseResource` tak pernah keluarkan `nik`/saldo/nama walau diberi model lengkap (D4b). <!-- source: code -->
- [ ] **Plafon di-enforce di controller API, bukan Action** — charge manual (5a) tak terkena plafon (D2/D4b). <!-- source: code -->
- [ ] Charge memotong saldo, membuat `ShoppingTransaction(source=store_api, store_client_id, recorded_by=null)`, saldo ter-update (D4/D6). <!-- source: code -->
- [ ] Charge dengan saldo kurang ditolak `422`; `amount ≤ 0` ditolak; **`amount` > plafon per-tx ditolak `422`** (D2/D4/D7). <!-- source: code -->
- [ ] `Idempotency-Key` sama + hash sama (klien sama) → tak dobel-potong; hash beda → `409` (D5). <!-- source: code -->
- [ ] **Key sama dari klien lain → `409` generik** (ownership-check, isi transaksi klien lain tak bocor) (D5). <!-- source: code -->
- [ ] `idempotency_hash` **tidak berisi/membocorkan NIK** (HMAC atas `member_id`, bukan NIK) (D3/D5). <!-- source: code -->
- [ ] Idempotency jalur **manual** masih utuh (unique global tak diubah) (D5). <!-- source: code -->
- [ ] Endpoint token: **brute-force `client_secret` diblok** rate limit/lockout (D1). <!-- source: code -->
- [ ] `amount` non-numerik/float invalid ditolak; diolah sebagai string bcmath (D4). <!-- source: code -->
- [ ] **Dua charge konkuren member sama tak bisa over-spend** (lock, diuji di MySQL) (D4). <!-- source: code -->
- [ ] NIK tak ditemukan & anggota nonaktif → pesan generik (tak bocorkan keberadaan NIK); **NIK tak muncul di log** (D3). <!-- source: code -->
- [ ] Rate limit **dan lockout enumerasi** per klien aktif pada verify/charge (D3/D9). <!-- source: code -->
- [ ] Tiap charge ter-log event `store_charge` dengan `store_client_id` (tanpa NIK) (D6). <!-- source: code -->
- [ ] (Bila ada) Refund mengembalikan saldo via reversal; gated `shopping:refund`; hanya toko asal; refund konkuren → satu sukses, lainnya `200` idempoten (D8). <!-- source: code -->

---

## Open Questions

- **TTL token & rotasi** — default 1 jam; perlu refresh-token atau toko minta token baru tiap sesi? (default: minta ulang).
- **Plafon harian per anggota** — plafon **per-transaksi** sudah wajib di v1 (D2). Apakah perlu plafon **harian** per anggota di atas itu? (Dokumentasi belum menetapkan; default v1: per-transaksi saja).
- **Refund: siapa boleh** — semua toko atau hanya toko asal transaksi? (default usulan: hanya toko asal, via `store_client_id` match).
- **Plafon per-merchant vs global** — `max_charge_per_tx` saat ini satu nilai global (D2). Perlukah plafon per `StoreClient` (kios kecil vs toko besar)? (default v1: global).
- **Registrasi `StoreClient`** — lewat seeder/artisan dulu (Non-Goal UI); kapan butuh CRUD Filament?
- **Privasi NIK** — bila audit privasi menuntut, migrasi ke token kartu (D3 alt). Konfirmasi pengurus apakah NIK acceptable jangka panjang.

---

## Changelog

- **2026-06-18 v1**: Draft awal. Skema integrasi API toko untuk pemakaian Wajib Belanja: Sanctum token (D1), dua fase verify→charge (D2), identifier **NIK** + mitigasi PII (D3), engine `RecordShoppingUsage` shared (D4), idempotency header (D5), kolom aditif `store_client_id` + audit (D6), kontrak error JSON (D7), refund opsional (D8). Dibangun di atas fondasi `shopping_transactions` (source enum, recorded_by nullable, idempotency, reversal) yang sudah ada dari Modul Simpanan 5a.
- **2026-06-19 v2**: Perketat keamanan setelah review. (D1) `tokenable` wajib `StoreClient`, catatan blast-radius secret≠token. (D2) verify balas `{affordable}` saja (anti-oracle PII), **plafon nominal per-transaksi wajib di v1**. (D3) tambah **lockout enumerasi** (rate limit saja tak cukup). (D5) idempotency key **di-scope per klien** (`unique(store_client_id, idempotency_key)`) + kolom `idempotency_hash` untuk deteksi "payload beda", urutan tulis vs lock dijelaskan. (D6) `reference_number` dipetakan ke kolom existing. (D8) refund konkuren idempoten + match toko asal. Key Items 0/4/5/6/8 + Verification + Alternatives disesuaikan.
- **2026-06-19 v5 (Accepted)**: Konfirmasi pengurus model `wajib_belanja` = **saldo prepaid** (anggota nabung dulu, belanja sebatas saldo, charge ditolak bila kurang) — bukan kredit/talangan. Background ditambah pernyataan eksplisit ini + validasi `≤ saldo` + lock (D4) dikonfirmasi **wajib**, bukan over-engineering. Pendanaan saldo via `SavingsDepositResource` (sudah ada, di luar scope). D8/item 7: catat `reverseClone()` harus salin `store_client_id` agar refund store_api tak kehilangan atribusi toko. Status: Draft → **Accepted**.
- **2026-06-19 v4**: Tetapkan arsitektur **Action → Controller → API Resource** (D4b baru): Action = eksekusi shared, Controller = orkestrasi + plafon (khusus API), **`JsonResource` = whitelist output** sehingga minim-PII jadi jaminan struktural. Klarifikasi tersisa: plafon dipindah eksplisit ke controller (manual bebas plafon); status Aktif di-enforce **hanya** di lookup D3 (non-aktif → `404`, bukan `422`); `transaction_number` selalu di-generate; penyimpanan counter lockout (cache) ditetapkan; residual boolean-oracle verify diterima eksplisit. Item 4/5 + Verification + error table disesuaikan.
- **2026-06-19 v3**: Perbaiki regresi v2 + lubang tersisa. (D5) **batalkan composite unique** — pertahankan `unique(idempotency_key)` global (aditif murni + idempotency manual aman), isolasi merchant via **ownership-check `store_client_id` saat konflik**; `idempotency_hash` jadi **HMAC atas `member_id`, tanpa NIK** (cegah hash di-balik jadi NIK). (D4) perjelas action return saldo tapi controller API membuangnya (hilangkan kontradiksi vs D2); `amount` wajib **string/bcmath, tolak float**. (D1) **rate limit/lockout endpoint token** (anti brute-force `client_secret`). (D6) causer activity log **null** untuk jalur API. (Open Q) plafon per-merchant vs global. Item 0 jadi **aditif murni** (tak drop unique). Alternatives + Verification disesuaikan.
