# Pelunasan Dipercepat (Early Settlement)

Memungkinkan anggota melunasi seluruh sisa pinjaman sebelum tenor selesai — bayar sisa pokok + 1× jasa, sisa jasa dibebaskan — lewat satu checkbox di form angsuran.

**Author:** gabrieladvent
**Date:** 2026-07-22
**Status:** Draft (rev. 5 — keputusan pengurus difinalisasi: maker-checker Level A, checkbox juga di batch)

> **Changelog rev.2:** perbaiki 3 blocker ronde-1 — reversal tak lagi "buka semua jadwal", gate `remainingPrincipal` net-aware, blast radius 3→5 permukaan.
>
> **Changelog rev.3:** perbaiki temuan ronde-2 — (1) **BLOCKER**: `installment_seq` NOT NULL, butuh migrasi nullable; (2) **BLOCKER/regresi rev.2**: idempotency key `"settle:{loan_id}"` menolak re-settle sah pasca-reverse → harus per-attempt; (3) `breakdown()` harus pakai `settledPrincipal()` **non-gated**, bukan `remainingPrincipal()` yang sudah return 0 (kuitansi Pokok=0); (4) predikat reopening jadwal harus **net-aware**, bukan existence-check; (5) `reverseClone()` bawa `is_settlement` = load-bearing di 3 konsumen; (6) 3 permukaan agregat lagi (Loans/Dashboard/StatsOverview) — aman-by-`status=Cair`, dicatat sebagai guard.
>
> **Changelog rev.5 (keputusan final pengurus):** (1) Maker-checker = **Level A** — `settle_early_installment` dibatasi Pengurus, refund tetap two-eyed, audit `interest_waived`; **tanpa** workflow 2-langkah (pembebasan jasa bukan uang keluar). Jaminan maker≠checker via **pisah role** (opsional, murni konfigurasi). (2) Checkbox pelunasan **juga tersedia di Batch Potong Gaji** — bukan hanya form tunggal; batch harus me-*route* baris settlement ke `settleEarly()` (bukan `pay()`) + gate permission per baris + konfirmasi eksplisit (cegah centang massal tak sengaja).
>
> **Changelog rev.4:** money-model di-**APPROVE** critic ronde-3 (tak ada blocker uang). Ditambahkan **§ Keamanan & Otorisasi** hasil security review yang mem-**BLOCK**: permission khusus `settle_early_installment` (bukan numpang `create_installment`), maker-checker/pemisahan peran untuk waiver jasa, reverse settlement butuh privilege lebih tinggi, audit wajib rekam **jasa yang dibebaskan**. Plus polesan critic: pin `due_date` baris settlement (cegah flag telat palsu di `LoanArrearsService`), 2 display consumer lagi (`InstallmentResource` infolist + blade kuitansi seq null), dan catatan `->change()` migrasi.

---

## Background

Pinjaman anggota memakai **bunga FLAT**: jasa dihitung dari `principal_amount` penuh dan konstan tiap bulan (`LoanCalculator::monthlyConstants`). Sistem sekarang hanya punya satu operasi uang: **bayar 1 jadwal angsuran** (`LoanPaymentService::pay`). "Kelebihan" didefinisikan sebagai `amount_paid − total_due` dari **satu jadwal**, lalu otomatis dialihkan ke Simpanan Sukarela (`creditOverpaymentToSukarela`).

Akibatnya, kasus nyata berikut tidak tertangani dengan benar:

> Pinjaman 5 angsuran. Anggota sudah bayar 2×. Di bulan ke-3 ingin **langsung lunas**.

Saat anggota menyetor uang besar untuk melunasi, sistem membacanya sebagai *"bayar jadwal ke-3 + banyak kelebihan"* → seluruh kelebihan nyasar ke Simpanan Sukarela, padahal maksud anggota adalah **melunasi sisa pinjaman**. Status `Lunas` pun hanya bisa tercapai bila **semua** `InstallmentSchedule` sudah `Terbayar` (`hasUnpaidSchedules`) — tidak ada jalur "lunasi sekaligus".

Konsep yang hilang: **operasi pelunasan dipercepat yang berdiri sendiri**, terpisah dari bayar angsuran biasa.

---

## Goals

- Anggota bisa melunasi seluruh sisa pinjaman kapan saja sebelum tenor selesai.
- **Kebijakan pelunasan (sudah diputuskan):** `jumlah pelunasan = sisa pokok + 1× jasa (monthly_interest)`. Jasa bulan-bulan yang belum jatuh tempo **dibebaskan**; tabungan berjangka masa depan **tidak dipaksa**.
- Saat lunas, refund SWP + Tabungan Berjangka yang **sudah** terakumulasi tetap dibuat (reuse `createRefunds`) — tidak lebih, tidak kurang.
- Kelebihan setoran **di atas** jumlah pelunasan boleh tetap dialihkan ke Simpanan Sukarela.
- Operasi **reversal-aware** (implements `Reversible`) — pelunasan bisa dibatalkan, mengembalikan loan ke `Cair`, jadwal ke `Belum Bayar`, dan membersihkan refund (reuse `cleanupRefunds`).
- UX satu titik tumpu: **checkbox "Pelunasan Dipercepat"** di form angsuran.

## Non-Goals

- Tidak mengubah model bunga (tetap FLAT).
- Tidak ada diskon/penalti berbasis persentase — kebijakan sudah fix di "sisa pokok + 1 jasa".
- Tidak mengubah alur `pay()` angsuran normal.
- Tidak menangani pelunasan sebagian (partial prepayment) — hanya pelunasan penuh.
- Tidak untuk `loan_type = jangka_pendek` (Sebrakan) yang memang sudah lunas sekali bayar.

---

## Design

### Titik tumpu UX: checkbox di form angsuran

Di `app/Livewire/Loan/Installment/InstallmentForm.php` (dan/atau batch payment page), tambahkan toggle **"Pelunasan Dipercepat"**. Perilaku saat dicentang:

1. Form berpindah mode: alih-alih menagih `total_due` satu jadwal, sistem menghitung & menampilkan:
   - **Sisa pokok** (`Loan::remainingPrincipal()` — sudah ada)
   - **Jasa** = `monthly_interest` (1×)
   - **Jumlah pelunasan** = sisa pokok + jasa
   - **Refund yang akan diterima**: SWP + Tabungan Berjangka terakumulasi (preview)
2. User mengisi nominal uang yang diserahkan.
3. Validasi:
   - `uang < jumlah pelunasan` → **tolak** dengan pesan jelas ("Uang kurang untuk pelunasan. Kurang Rp X.").
   - `uang == jumlah pelunasan` → lunaskan.
   - `uang > jumlah pelunasan` → lunaskan; **selisih** dialihkan ke Simpanan Sukarela (reuse `creditOverpaymentToSukarela`).

Checkbox mati = perilaku lama (`pay()`) tidak berubah.

### Aturan "1× jasa" (harus eksplisit — sumber double-charge)

`payoff = sisa pokok + 1× monthly_interest`. Yang dimaksud "1× jasa" adalah **jasa untuk satu bulan berjalan**, yaitu jadwal `Belum Bayar` **paling awal**. Aturan pencegah dobel:

- Pelunasan dilakukan **sebagai pengganti** pembayaran bulan berjalan, bukan tambahan. Jasa 1× = jasa jadwal `Belum Bayar` paling awal.
- Jika anggota **sudah** membayar bulan berjalan secara normal (jadwal itu `Terbayar`, jasa-nya sudah tertagih), maka "bulan berjalan" bergeser ke jadwal `Belum Bayar` berikutnya — jasa tetap **1×** untuk jadwal itu, **tidak dobel**. Sisa pokok otomatis sudah berkurang karena angsuran normal tadi ikut mengurangi count.
- Konsekuensi: jumlah `1× monthly_interest` selalu benar selama minimal ada 1 jadwal `Belum Bayar` (dijamin oleh guard `status === Cair`).

### Operasi service baru: `LoanPaymentService::settleEarly()`

Sejajar `pay()`, semantik berbeda. Atomic (`DB::transaction`). Langkah — **urutan lock→guard wajib**:

1. `DB::transaction` → `Loan::lockForUpdate()->findOrFail()`. **Re-baca status SETELAH lock**, baru guard: `status === Cair`, `loan_type !== jangka_pendek`. (Cegah race `settleEarly`↔`pay`.)
2. `settledPrincipal = $loan->settledPrincipal()` — **helper baru non-gated** (count baris normal non-settlement net × monthly_principal, floor 0). **JANGAN** pakai `remainingPrincipal()`: begitu loan `Lunas` + ada settlement aktif, ia sudah return `0.00` (gate #1), sehingga kuitansi Pokok jadi 0. Keduanya beda peran: `settledPrincipal()` = "berapa yang dibayar pelunasan" (non-gated), `remainingPrincipal()` = "sisa loan sekarang" (gated → 0 setelah lunas).
3. `payoff = settledPrincipal + monthly_interest`.
4. Validasi `amount_paid ≥ payoff`, else `CannotProcessPayment::belowSettlement()` (exception baru). **Tolak sebelum membuat record apa pun** — transaksi rollback bersih.
5. Buat **satu** `Installment` ber-flag `is_settlement = true`, `amount_paid = amount_paid`, `schedule_id = null`, `installment_seq = null`, **`payment_date = now`, `due_date = payment_date`** (pin sama — cegah `LoanArrearsService` menghitungnya telat karena `payment_date > due_date`). ⚠️ **`installment_seq` saat ini NOT NULL** (`create_installments_table.php:17`) — butuh migrasi jadikan nullable dulu (lihat Key Files). `installment_seq` null adalah **sengaja**: itu yang bikin kalkulator seq-based (`remainingAfter`) tahu harus cabang → 0.
6a. **Idempotency:** `idempotency_key` harus **per-attempt** (mis. UUID dari UI/`Str::uuid()`), **BUKAN** `"settle:{loan_id}"`. Key per-loan akan menolak re-settle sah setelah settle→reverse→settle. Proteksi double-submit datang dari guard `status===Cair` (dibaca setelah lock) + tombol UI disabled saat submit, bukan dari key deterministik.
6. Tandai **semua** `InstallmentSchedule` `Belum Bayar` → `Terbayar`. (Jadwal yang sudah `Terbayar` normal **tidak disentuh**.)
7. `excess = amount_paid − payoff`; jika > 0 → `creditOverpaymentToSukarela`.
8. `loan->status = Lunas`; `createRefunds($loan)`.
9. Log activity `event('pelunasan_dipercepat')` `withProperties`: `settled_principal`, `interest_charged`, **`interest_waived`** = `(term_months − net_paid − 1) × monthly_interest`, `excess_to_sukarela`, dan ID withdrawal refund terpicu (lihat § Keamanan #4).

### ⚠️ Masalah inti: invariant money-model (ADR 2026-06-26)

ADR `2026-06-26-input-angsuran-nominal-tunggal` mengunci prinsip **"1 baris installment = 1 bulan konstanta penuh (pokok + jasa + tab)"**. Baris pelunasan (jasa 1×, tab 0, pokok = banyak bulan) melanggar itu. Review adversarial mengoreksi blast radius: bukan 3, tapi **5 permukaan** yang menghitung sisa pokok / akrual dari count atau seq. Semua wajib special-case `is_settlement`, konsisten:

| # | Kalkulator | File | Masalah tanpa penanganan | Penanganan |
|---|-----------|------|--------------------------|------------|
| 1 | `remainingPrincipal()` | `Loan.php:87-98` | Baris settlement dihitung → sisa pokok salah | Exclude `is_settlement` dari count. **Gate net-aware**: bila `(Σ settlement non-reversal − Σ settlement reversal) > 0` → `0.00`; else formula normal. (Bukan "ada baris non-reversal" — original tetap `is_reversal=0` setelah dibalik, lihat Reversal.) |
| 2 | `scopeSignedTimeDeposit()` | `Installment.php:78-85` | Baris settlement menambah `monthly_time_deposit` → **over-refund** tab | Tambah `WHERE installments.is_settlement = 0` — settlement bukan bulan menabung. |
| 3 | `breakdown()` | `Installment.php:94-115` | Atribusi pokok/jasa/tab salah | Cabang `is_settlement`: `principal = $loan->settledPrincipal()` (**non-gated** — bukan `remainingPrincipal()` yang sudah 0), `interest = monthly_interest`, `time_deposit = 0`, `other = amount_paid − payoff` (floor 0). |
| 4 | `InstallmentDetail::remainingAfter()` | `InstallmentDetail.php:116-120` | `principal − seq × monthly_principal`; settlement `seq=null` → hitung salah / nota salah | Cabang `is_settlement` → `0.00`. (Inilah kenapa `installment_seq` di baris settlement **null**, bukan "seq berikutnya".) |
| 5 | `SchedulesRelationManager::remainingAfter()` | `SchedulesRelationManager.php:206-210` | Sama, tapi berbasis `InstallmentSchedule.installment_seq` | Jadwal yang ditutup pelunasan → tampilkan `0.00` (atau tanda "Lunas dipercepat"). |

> **Refactor WAJIB (bukan sekadar disarankan — load-bearing):** ekstrak **dua** helper di `Loan`, jadi satu-satunya sumber logika count:
> - `settledPrincipal(): string` — **non-gated**: `principal_amount − (net count baris `is_settlement=0` × monthly_principal)`, floor 0. Dipakai `settleEarly` step 2 & `breakdown()` #3.
> - `hasActiveSettlement(): bool` — **net-aware**: `(Σ is_settlement=1,is_reversal=0) − (Σ is_settlement=1,is_reversal=1) > 0`. Dipakai gate `remainingPrincipal()` #1.
>
> Menyalin logika ini inline di 3+ tempat = drift hazard yang sudah terbukti (rev.1 salah di satu salinan).

### Permukaan agregat lain — aman-by-`status=Cair` (jangan diutak-atik, tapi catat)

Tiga query agregat sisa pokok **tidak** memfilter `is_settlement`: `Loans.php:194`, `Dashboard.php:125`, `SavingsStatsOverview.php:60-73`. **Tidak** perlu dipatch **selama** invariant dipegang: loan lunas via settlement → `status = Lunas` → terekslusi oleh `WHERE loans.status = Cair` mereka; loan yang di-reverse → `Cair` dengan settlement+reversal saling meniadakan di net count. ⚠️ **Guard untuk masa depan:** jika kelak menambah *partial settlement* (loan tetap `Cair` setelah bayar sebagian besar), ketiga query ini akan diam-diam salah — mereka **harus** ikut exclude `is_settlement` saat itu. Dicatat di sini agar tidak jadi ranjau.

**Kunci desain: tidak ada kolom uang baru.** `settledPrincipal` tetap derivable dari count baris normal. Satu-satunya state baru: **boolean `is_settlement`**. Ini menghormati filosofi ADR 2026-06-26 (uang dihitung dari konstanta + count).

### Reversal (diperbaiki — sebelumnya blocker)

⚠️ **`reverseClone()` WAJIB menyertakan `is_settlement=true` (+ `schedule_id=null`, `installment_seq=null`).** Saat ini (`Installment.php:167-179`) TIDAK. `ReverseTransaction` (`ReverseTransaction.php:50-52`) hanya menyalin hasil `reverseClone()` + `is_reversal/reversal_of_id`. Kalau patch ini kelewat, **tiga** konsumen rusak sekaligus & jadi silent money bug: (a) gate net-aware tetap `>0` → `remainingPrincipal` nyangkut 0 setelah reverse; (b) exclude-count `settledPrincipal` salah −1; (c) `scopeSignedTimeDeposit` tak menangkap baris-lawan → net tab meleset −`monthly_time_deposit`. **Ketiga konsumen wajib punya test terpisah.**

**Reopening jadwal — tidak boleh "buka semua", dan harus NET-AWARE (bukan existence-check):** baris settlement `schedule_id=null` tidak mencatat jadwal mana yang ditutup. Aturan benar:

> Buka (`→ Belum Bayar`) hanya `InstallmentSchedule` `Terbayar` yang **net pembayaran normalnya = 0**, yaitu `(Σ installment normal is_reversal=0) − (Σ is_reversal=1)` yang menunjuknya via `schedule_id` **= 0**.

Kenapa net, bukan sekadar "tidak punya installment normal": skenario **bayar bln-1 → reverse bln-1 → settle → reverse settle**. Baris asli bln-1 (`is_reversal=0`) **tetap ada** walau sudah dibatalkan. Existence-check akan mengira jadwal-1 "dibayar normal" → tidak dibuka → nyangkut `Terbayar` tanpa pembayaran valid (schedule drift). Net-check (asli − reversal = 0) benar menandai jadwal-1 sebagai kosong → dibuka.

Alur `reverse()` lain yang sudah ada tetap dipakai: `Lunas → Cair`, `reverseOverpaymentCredit` (match `reference_number`, jalan karena settlement tetap punya `installment_number`), `cleanupRefunds`.

**Catatan `ReverseTransaction` generik:** ia meng-clone via `reverseClone()` dan menyisipkan baris `is_reversal=true` — tidak menghapus/mengubah original. Perlu dipastikan ia tidak tersandung `schedule_id=null` (kolom nullable, seharusnya aman). Reopening jadwal adalah tanggung jawab `LoanPaymentService::reverse()`, bukan `ReverseTransaction`.

### Approach

Fitur baru sebagai **operasi terpisah** + **flag boolean**, bukan menambal logika kelebihan-bayar. Reuse maksimal: `remainingPrincipal`, `createRefunds`, `creditOverpaymentToSukarela`, `cleanupRefunds`, status `Lunas`, mekanisme `Reversible`.

### Alternatives Considered

| Alternative | Pro | Con | Verdict |
|-------------|-----|-----|---------|
| **Flag `is_settlement` + special-case 3 helper** | Tanpa kolom uang baru; setia ke invariant count-based; reuse penuh | 3 helper harus di-patch konsisten | **Chosen** |
| Buat N baris installment (satu per jadwal sisa) | Count-based helper "otomatis" benar untuk pokok | Over-refund tab bulan yang tak pernah disetor; jasa sisa ikut tertagih (langgar kebijakan) | Rejected |
| Simpan kolom `settlement_principal`, `settlement_interest` | Breakdown eksplisit | Langgar filosofi "hanya amount_paid disimpan"; nambah surface migrasi | Rejected |
| Tambal `pay()` agar kelebihan melunasi sisa pinjaman | Perubahan kecil | Semantik ambigu (nabung vs lunas); tak bisa preview; sulit reversal | Rejected |

---

## Keamanan & Otorisasi (BLOCKER pra-rollout — hasil security review)

Money-model sudah benar, tapi pelunasan adalah **aksi finansial yang membebaskan pendapatan koperasi (jasa) dan memicu refund** — bukan sekadar catat 1 pembayaran. Gate-nya **tidak boleh** sama dengan angsuran biasa. Kontrol berikut **wajib** ada sebelum `shield:generate` & rollout:

### 1. Permission khusus, bukan numpang `create_installment`
Saat ini `InstallmentForm` hanya `authorize('create', Installment::class)`, dan `create_installment` dipegang **petugas & pengurus** (`RolePermissionSeeder.php:14,70-78`). Artinya **petugas mana pun bisa membebaskan jasa tanpa batas pada loan siapa saja.**
- Buat permission baru **`settle_early_installment`**, hanya untuk **Pengurus/Bendahara**.
- Gate **server-side di dua lapis**: di setiap entry point (form tunggal **dan Batch Potong Gaji**) **dan** di dalam `settleEarly()` (defense in depth). Menyembunyikan checkbox saja **tidak cukup** — flag `is_settlement` masih bisa di-POST.
- Karena `settleEarly()` menjadi satu-satunya jalur eksekusi (form & batch sama-sama memanggilnya), gate lapis-dalam di sana **menutup semua** entry point sekaligus.

### 2. Maker-checker = Level A (keputusan final — bukan workflow 2-langkah)
Refund uang-keluar **sudah** two-eyed (draft withdrawal → `approve`+`disburse` Pengurus-only, `SavingsWithdrawalPolicy.php:83-94`). Pembebasan jasa itu sendiri **bukan uang keluar** (pendapatan tak ditagih), jadi kontrolnya cukup **pemisahan peran + audit**, bukan persetujuan pra-eksekusi.
- **Dipilih:** batasi `settle_early_installment` ke **Pengurus/Bendahara**; refund tetap di-approve pengurus. **Tanpa** langkah "ajukan→konfirmasi" untuk pelunasannya (over-engineering — tak ada kas berpindah saat waiver).
- **Jaminan maker ≠ checker (opsional, murni konfigurasi):** pisah role — mis. settle = Bendahara, approve refund = Ketua, tidak ada akun tunggal memegang keduanya. Ini pilihan pengurus; permission saja tak menjamin orang berbeda bila satu akun punya kedua izin.
- ⚠️ `super_admin` memegang semua permission → mengkolapskan pemisahan. Wajib jadi akun **break-glass**, bukan operasional harian.

### 3. Reverse settlement butuh privilege lebih tinggi
`reverse_installment` dipegang **petugas & pengurus** (seeder :29,38). Reverse pelunasan meng-unwind pengakuan pendapatan + membatalkan refund draft + menarik kredit sukarela — jauh lebih berkonsekuensi. Dengan re-settle diizinkan (idempotency per-attempt), satu petugas bisa loop `settle → reverse → settle`.
- Reverse baris `is_settlement=true` harus di-gate di level **`reverse_loan`** (Pengurus-only, seeder :44), dengan `canReverse()`/`InstallmentPolicy::reverse` **bercabang** pada `is_settlement`.

### 4. Audit wajib rekam jasa yang dibebaskan
Step 9 `event('pelunasan_dipercepat')` saja tidak cukup (`logFillable()->logOnlyDirty()` tak bisa menurunkan jasa yang dibebaskan). Activity log **wajib** `withProperties`:
`settled_principal`, `interest_charged` (1×), **`interest_waived`** (selisih vs lunas normal = `(term − net paid − 1) × monthly_interest`), `excess_to_sukarela`, dan **ID withdrawal refund** yang terpicu. Reversal settlement mencatat field sama + reason. Tanpa `interest_waived`, pertanyaan audit *"bulan ini kita bebaskan pendapatan berapa, siapa yang otorisasi"* tak terjawab.

---

## Key Files

| File | Fungsi |
|------|--------|
| `database/migrations/xxxx_add_is_settlement_to_installments.php` | **Baru** — kolom boolean `is_settlement` default false, indexed |
| `database/migrations/xxxx_make_installment_seq_nullable.php` | **Baru** — `installment_seq` → `nullable()`. ⚠️ `->change()` **harus restate definisi penuh** (`unsignedSmallInteger('installment_seq')->nullable()->change()`) agar atribut unsigned/size tak hilang. ALTER tabel finansial hidup → **wajib lewat deploy-reviewer** |
| `app/Services/LoanPaymentService.php` | **Baru** `settleEarly()`; `reverse()` — buka **hanya** jadwal tanpa installment normal aktif (bukan semua) |
| `app/Models/Loan.php:87-98` | `remainingPrincipal()` gate **net-aware**; **baru** `hasActiveSettlement()` + `settledPrincipal()` (single source, dipanggil helper lain) |
| `app/Models/Installment.php:78-85` | `scopeSignedTimeDeposit()` — filter `installments.is_settlement = 0` |
| `app/Models/Installment.php:94-115` | `breakdown()` — cabang `is_settlement` |
| `app/Models/Installment.php:167-179` | `reverseClone()` — sertakan `is_settlement` (+ `schedule_id=null`, `installment_seq=null`) |
| `app/Models/Installment.php:28-50` | `$fillable` + cast boolean `is_settlement` |
| `app/Livewire/Loan/Installment/InstallmentDetail.php:116-120` | `remainingAfter()` — cabang `is_settlement` → 0 **(terlewat di rev.1)** |
| `app/Filament/Resources/RelationManagers/SchedulesRelationManager.php:206-210` | `remainingAfter()` — jadwal ditutup pelunasan → 0/badge **(terlewat di rev.1)** |
| `app/Livewire/Loan/Installment/InstallmentForm.php` | Checkbox "Pelunasan Dipercepat" + preview payoff/refund + validasi uang |
| `app/Filament/Pages/BatchInstallmentPayment.php` + `app/Livewire/Loan/Installment/BatchInstallmentPayment.php` | **Dukung pelunasan** — checkbox per baris; route baris settlement ke `settleEarly()` (bukan `pay()`); gate `settle_early_installment` per baris; **konfirmasi eksplisit** sebelum commit (cegah centang massal tak sengaja) |
| `app/Exceptions/CannotProcessPayment.php` | **Baru** `belowSettlement()` |
| `app/Filament/Resources/InstallmentResource.php:265-266` | "Sisa Pokok" infolist pakai `remainingPrincipal()` gated → tampil 0 utk baris settlement; putuskan tampilan (label "Pelunasan") **(display consumer terlewat)** |
| `resources/views/pdf/installment-receipt.blade.php:41` + `resources/views/livewire/loan/installment/installment-detail.blade.php:21` | "Angsuran ke-{seq}" → kosong utk seq null; ganti label "Pelunasan Dipercepat" **(kosmetik)** |
| `app/Services/LoanArrearsService.php:49-56` | Pin `due_date = payment_date` di baris settlement agar tak terhitung telat (`payment_date > due_date`) → cegah `arrearsWarning` palsu |
| `database/seeders/RolePermissionSeeder.php` | **Baru** permission `settle_early_installment` (Pengurus/Bendahara); reverse settlement → `reverse_loan` |
| `app/Policies/InstallmentPolicy.php` | **Baru** `settleEarly()` + cabang `reverse()` pada `is_settlement` |
| `app/Livewire/Loan/Installment/InstallmentForm.php` | Gate checkbox + authorize server-side `settle_early_installment` (dua lapis dgn service) |

---

## Key Items

| # | Item | Effort | Parallel? | Status |
|---|------|--------|-----------|--------|
| 1a | Migrasi: kolom `is_settlement` (bool default false, indexed) + `installment_seq` → nullable (`->change()` restate penuh) | S | ✅ bisa sekarang | **Done** |
| 1b | `Loan`: `settledPrincipal()` **non-gated** + `hasActiveSettlement()` **net-aware** + gate `remainingPrincipal()` | M | setelah 1a | **Done** |
| 1c | `Installment`: filter `is_settlement=0` di `scopeSignedTimeDeposit`, cabang `breakdown()`, `reverseClone()` bawa `is_settlement`+seq/schedule null, `$fillable`+cast | M | setelah 1a | **Done** |
| 2a | `LoanPaymentService::settleEarly()` (payoff, guard-after-lock, idempotency per-attempt, `due_date=payment_date`, tutup jadwal, excess→sukarela, `Lunas`, `createRefunds`, audit `interest_waived`) + exception `belowSettlement()` | L | setelah 1b, 1c | **Done** |
| 2b | `reverse()`: reopening jadwal **net-aware** multi-schedule + cabang `is_settlement` | M | setelah 2a | **Done** |
| 3a | Permission `settle_early_installment` + seeder + `InstallmentPolicy` (settleEarly + reverse settlement → `reverse_loan`) | M | setelah 2a | Pending |
| 4a | 5 permukaan display: `InstallmentDetail::remainingAfter`, `SchedulesRelationManager::remainingAfter`, `InstallmentResource` infolist, blade receipt + detail (label "Pelunasan Dipercepat") | M | setelah 1b, 1c | Pending |
| 5a | UI form tunggal `InstallmentForm`: checkbox + preview payoff/refund + validasi uang + authorize dua lapis | L | setelah 2a, 3a | Pending |
| 5b | UI **Batch Potong Gaji**: checkbox per baris + route `settleEarly()` + gate per baris + konfirmasi eksplisit **(kandidat fase-2 — putuskan saat sampai sini)** | L | setelah 5a | Pending |

**Effort:** S = < 1 jam, M = 1–3 jam, L = > 3 jam.
**Test-first wajib (silent-money-bug):** 1c (`reverseClone` → 3 konsumen pulih), 2b (bayar→reverse→settle→reverse), 1b (`settledPrincipal` vs `remainingPrincipal`, kuitansi ≠ 0).

---

## Verification

- [ ] Pinjaman 5 bulan, bayar 2 normal, pelunasan bulan ke-3: `payoff = 3×pokok + 1×jasa`; loan jadi `Lunas`.
- [ ] Uang < payoff → ditolak, tak ada record dibuat (atomic).
- [ ] Uang == payoff → lunas, tak ada kredit sukarela.
- [ ] Uang > payoff → lunas + selisih masuk Simpanan Sukarela sebesar `uang − payoff`.
- [ ] `remainingPrincipal()` = `0.00` setelah pelunasan.
- [ ] Refund Tabungan Berjangka = akrual dari **2 angsuran normal saja** (bukan 3/5) — tidak over-refund.
- [ ] Refund SWP dibuat sekali (idempoten via `hasActiveRefund`).
- [ ] `breakdown()` baris settlement: principal = sisa pokok saat itu, interest = 1× jasa, time_deposit = 0.
- [ ] Reverse pelunasan → loan `Cair`; **hanya** jadwal yang ditutup pelunasan kembali `Belum Bayar` (jadwal 1 & 2 yang dibayar normal **tetap** `Terbayar`); kredit sukarela ditarik; refund dibersihkan; `remainingPrincipal` **pulih** ke nilai sebelum pelunasan (bukan tetap 0).
- [ ] Idempotency: submit ganda dengan `idempotency_key` sama ditolak oleh unique index (bukan hanya guard status).
- [ ] Guard di-baca setelah `lockForUpdate` — dua request `settleEarly`/`pay` paralel pada loan sama tidak dobel.
- [ ] **Anti double-jasa:** bayar bulan-3 normal (jasa tertagih) lalu pelunasan → payoff hanya menagih 1× jasa untuk jadwal Belum Bayar berikutnya, tidak dobel.
- [ ] `remainingAfter()` (InstallmentDetail & SchedulesRelationManager) untuk baris/jadwal pelunasan menampilkan `0`, bukan `principal − seq × monthly_principal`.
- [ ] `jangka_pendek` (Sebrakan) tidak menampilkan opsi pelunasan dipercepat.
- [ ] Uji balance total: `amount_paid = settledPrincipal + monthly_interest + (kredit sukarela)` — tidak ada rupiah hilang/tercipta.
- [ ] Insert baris settlement dengan `installment_seq = null` **berhasil** (migrasi nullable sudah jalan) — tidak kena integrity constraint.
- [ ] **Re-settle sah:** settle → reverse → settle lagi **diterima** (idempotency key per-attempt, bukan per-loan).
- [ ] **Kuitansi/nota** baris pelunasan menampilkan Pokok = sisa pokok sebenarnya (via `settledPrincipal()` non-gated), **bukan Rp 0**.
- [ ] **Net-aware reopening:** bayar bln-1 → reverse bln-1 → settle → reverse settle → jadwal-1 **kembali** `Belum Bayar` (tidak nyangkut `Terbayar`).
- [ ] `reverseClone()` baris-lawan settlement membawa `is_settlement=1` → uji ketiga konsumen (`remainingPrincipal`, `settledPrincipal`, `scopeSignedTimeDeposit`) pulih benar pasca-reverse.
- [ ] Baris settlement `due_date = payment_date` → **tidak** menaikkan `memberLatePaymentCount` / `arrearsWarning`.
- [ ] **Otorisasi:** petugas tanpa `settle_early_installment` **tidak bisa** pelunasan walau POST `is_settlement=1` langsung (gate server-side, bukan UI saja).
- [ ] Reverse baris `is_settlement` ditolak untuk role tanpa `reverse_loan`.
- [ ] Activity log settlement memuat `interest_waived`, `settled_principal`, `excess_to_sukarela`, ID refund — jasa dibebaskan bisa direkonstruksi untuk audit.
- [ ] Maker ≠ checker: settler dan approver refund bukan identitas sama (di luar `super_admin` break-glass).
- [ ] **Batch:** baris ber-checkbox pelunasan di-route ke `settleEarly()` (bukan `pay()`); baris tanpa checkbox tetap `pay()` normal; keduanya dalam satu batch tidak saling mengganggu.
- [ ] **Batch guard:** baris pelunasan menagih preview payoff benar per anggota; ada konfirmasi eksplisit; `settle_early_installment` dicek per baris (petugas tak bisa menyelundupkan pelunasan lewat batch).

---

## Open Questions

- Apakah baris pelunasan perlu nomor transaksi berformat khusus (mis. prefix `PLN`) atau tetap `ANG`? (default: tetap `ANG`.)
- Bukti pembayaran (media `bukti`) wajib untuk pelunasan? (default: opsional, sama seperti angsuran.)
- ~~Menandai loan lunas via pelunasan vs normal untuk audit?~~ **RESOLVED → wajib.** Diangkat jadi requirement di § Keamanan #4 (`interest_waived` di activity log).
- ~~Shield/permission aksi pelunasan?~~ **RESOLVED oleh security review → § Keamanan.** Permission `settle_early_installment` (Pengurus/Bendahara), gate dua lapis, maker-checker, reverse via `reverse_loan`.
- ~~Entry point batch?~~ **RESOLVED → ya, checkbox juga di Batch Potong Gaji.** Route ke `settleEarly()`, gate per baris, konfirmasi eksplisit (lihat Key Files & §Keamanan #1).
- ~~Maker-checker penuh vs restrict-role?~~ **RESOLVED → Level A** (restrict-role + audit, tanpa workflow 2-langkah); maker≠checker via pisah-role opsional (§Keamanan #2).
- **Bug pre-existing (di luar scope, jangan diklaim tertutup ADR ini):** `LoanDetail.php:186` mereferensikan kolom `remaining_principal` yang **tidak ada** di tabel installments → selalu `null` → selalu tampil `principal_amount` penuh. Layak diperbaiki terpisah; catat agar tim tidak mengira pelunasan yang menyebabkannya.
- **Pembulatan (koreksi arah):** karena `Σ monthly_principal ≥ principal_amount` (`ceilPrincipalPerMonth`), yang **over-collect** surplus pembulatan justru jalur **normal** di bulan terakhir; `settledPrincipal` (floor 0) sudah menagih **persis** sisa. Jadi pelunasan di bulan terakhir sedikit lebih murah daripada menuntaskan normal. Perlu keputusan: biarkan (toleran, memihak anggota) atau samakan? (default: biarkan.)
