# Penyederhanaan Input Angsuran: Satu Nominal, Breakdown Komputasi

Mengganti input angsuran 3-komponen (Pokok/Jasa/Tab terpisah) menjadi **satu kolom nominal dibayar**; rincian Pokok/Jasa/Tab dihitung runtime dari konstanta `loans.monthly_*`, dan selisih lebih muncul sebagai pos **"Lain-lain"** di nota penerimaan kas — saldo aktual tetap akurat.

**Author:** Ribka Restu
**Date:** 2026-06-26
**Status:** Implemented (code — 390 test GREEN) — pending deploy (`migrate:fresh` tiap env, Rollout Phase 0–1)

> **Amends** [ADR Modul Pinjaman & Angsuran (2026-06-19)](2026-06-19-modul-pinjaman-angsuran.md) — khusus **D5** (input pembayaran per-komponen), **D7** (sumber saldo Tabungan Berjangka), dan **D8** (nilai refund Tab Berjangka). Keputusan lain ADR tsb tetap berlaku.

---

## Background

Modul Pinjaman sudah Implemented (core). Alur pembayaran angsuran saat ini (D5 ADR lama) meminta petugas **menginput nominal tiap komponen terpisah** — Pokok, Jasa, Tabungan Berjangka — masing-masing divalidasi `≥` konstanta tagihan (`loans.monthly_*`). Lihat [`LoanPaymentService::pay()`](../../app/Services/LoanPaymentService.php) (3 input, 3 `assertNotBelowBill`) dan form [`InstallmentResource`](../../app/Filament/Resources/InstallmentResource.php#L212-L215) (3 `MoneyInput`).

**Masalah yang ditemukan pengurus (2026-06-26):** alur 3-komponen tidak relevan dengan praktik lapangan. Anggota membayar **satu kali, satu angka** — dia tidak peduli (dan tidak seharusnya memikirkan) pemecahan ke Pokok/Jasa/Tab. Karena **patokan breakdown sudah dikunci** di `loans.monthly_principal/interest/time_deposit` saat akad (D1b ADR lama, immutable secara konvensi), meminta petugas mengetik ulang ketiganya = redundan + rawan salah ketik. Yang benar-benar perlu dicatat hanyalah **berapa uang nyata yang diterima**.

Jika nominal yang dibayar **lebih** dari tagihan bulan itu, kelebihannya bukan tambahan pokok/tab, melainkan **penerimaan lain-lain koperasi** — ditampilkan sebagai baris "Lain-lain" pada nota penerimaan kas, **dihitung**, tanpa kolom baru.

Perubahan ini sejalan dengan prinsip §7 (saldo dihitung dari transaksi) — lebih sedikit angka mutable disimpan, lebih banyak dihitung dari sumber kebenaran tunggal. **Namun ia BUKAN refactor ekuivalen** (lihat D5): makna saldo Tabungan Berjangka berubah, dan daya cegah-korupsi bergeser dari per-komponen ke total — keduanya keputusan bisnis sadar pengurus, bukan kebetulan teknis.

---

## Goals

- **Form angsuran satu nominal** — petugas pilih anggota → pinjaman → jadwal, lalu input **satu** `amount_paid` (+ metode bayar + bukti). Prefill = total tagihan bulan itu (`installment_schedules.total_due`).
- **Skema `installments` ramping** — buang 4 kolom turunan (`principal_paid`, `interest_paid`, `time_deposit_saved`, `remaining_principal`); cukup `amount_paid` sebagai satu-satunya angka uang yang disimpan.
- **Breakdown & "Lain-lain" komputasi** — Pokok/Jasa/Tab pada nota/kuitansi dihitung dari `loans.monthly_*`; Lain-lain = `amount_paid − total_due`, tidak disimpan.
- **Saldo tetap akurat** — `SavingsBalanceService::timeDepositBalance`, refund pelunasan, & sisa pokok di-redefine berbasis **jumlah angsuran terbayar × konstanta** (net reversal), bukan jumlah kolom komponen.
- **Anti-korupsi total-level** — validasi tunggal `amount_paid ≥ total_due` + bukti upload (kontrol detektif). Lebih = sah (Lain-lain).

## Non-Goals

- **Alokasi kelebihan ke Pokok atau Tab Berjangka** — kelebihan **selalu** Lain-lain (penerimaan koperasi), tidak mempercepat pelunasan & tidak menambah simpanan anggota (keputusan pengurus 2026-06-26).
- **Mempertahankan kontrol anti-korupsi per-komponen** — sengaja diturunkan ke total-level (lihat D4); per-komponen jadi redundan setelah breakdown dikunci ke konstanta.
- **Mengubah konstanta angsuran / kalkulator** (D1, D1b ADR lama) — `LoanCalculator` & `loans.monthly_*` tetap; ADR ini hanya mengubah sisi **pencatatan pembayaran**.
- **Mengubah FIFO, idempotency, reversal, auto-Lunas** (D5/D8 ADR lama) — alur tetap; hanya bentuk input & sumber turunan yang berubah.
- **"Lain-lain" sebagai entity ber-audit / akun penerimaan** — di luar scope ADR ini (lihat D6 + Open Questions); ADR ini hanya menjamin nilainya dapat dihitung.

---

## Design

### Approach

**Satu kolom uang, sisanya turunan.** `installments.amount_paid` jadi satu-satunya angka uang yang disimpan per pembayaran. Semua rincian (Pokok, Jasa, Tab, Lain-lain, sisa pokok) **dihitung** dari dua sumber kebenaran yang sudah ada: konstanta terkunci `loans.monthly_*` dan jumlah angsuran terbayar (net reversal).

```
loans.monthly_principal / monthly_interest / monthly_time_deposit  ← konstanta terkunci (akad, immutable konvensi)
installment_schedules.total_due = Σ konstanta                       ← tagihan per bulan (sudah ada)
        ↓
Petugas input 1 angka: amount_paid  (validasi ≥ total_due)
        ↓ disimpan: amount_paid saja (1 baris = 1 schedule)
Breakdown nota   = konstanta (Pokok/Jasa/Tab) + Lain-lain (amount_paid − total_due)   ← dihitung
Saldo Tab Berjangka / sisa pokok = net_paid_schedules × konstanta                      ← dihitung
```

### Keputusan Desain

#### D1 — `installments`: satu kolom `amount_paid`, buang 4 kolom turunan

Migrasi mengubah skema `installments`: hapus `principal_paid`, `interest_paid`, `time_deposit_saved`, **dan `remaining_principal`** (D2). Pertahankan `amount_paid` (nominal aktual diterima) sebagai satu-satunya angka uang transaksi.

**⚠️ Koreksi precondition (temuan deploy-reviewer):** asumsi awal "branch-isolated, belum di `main`" **KELIRU**. File migrasi `2026_06_14_090010_create_installments_table.php` **sudah ada di `main` & `development`** (di-scaffold sejak commit awal); hanya *edit*-nya yang belum di-merge. Konsekuensi: **edit `up()` in-place TIDAK mengubah DB yang sudah pernah menjalankan migrasi ini** — Laravel melihat filename sudah tercatat di tabel `migrations` → di-skip. DB apa pun yang dibangun dari `main`/`development` akan menyimpan **4 kolom hantu** `NOT NULL` tanpa default → `Installment::create()` baru (tanpa kolom itu) **gagal insert**.

Maka edit-in-place hanya benar bila **setiap environment di-`migrate:fresh`** (boleh karena tak ada data produksi). Yang perlu dipastikan sebelum eksekusi: enumerasi semua DB yang pernah `migrate` tabel ini (dev user, dev rekan, CI persistent, staging bila ada) dan `migrate:fresh` / `ALTER TABLE … DROP COLUMN` di tiap-tiap. Fallback bila ternyata ada data yang harus diselamatkan di salah satu env = migrasi drop-column aditif + backfill di env itu.

#### D2 — `remaining_principal`: dibuang, jadi turunan (keputusan pengurus 2026-06-26)

Dengan kelebihan selalu → Lain-lain (bukan pokok), **pokok terbayar tiap angsuran = `monthly_principal` persis**. Maka:

```
sisa_pokok(loan) = max(0, principal_amount − net_paid_schedules × monthly_principal)
```

`remaining_principal` jadi turunan murni → **drop kolomnya** (konsisten §7, computed-on-read). Sediakan `Loan::remainingPrincipal()` sebagai satu sumber rumus, dipakai semua pembaca.

**⚠️ Pembaca `remaining_principal` yang WAJIB ikut diubah (temuan critic — terlewat di draft v1):**
[`SchedulesRelationManager`](../../app/Filament/Resources/RelationManagers/SchedulesRelationManager.php) (progres angsuran, baru di-merge commit `03b173a`) membaca `actualPayment($record)?->remaining_principal` (baris 177) dan `remainingPrincipal()` (baris 218-227) `return $latest?->remaining_principal`. Drop kolom tanpa menyentuh ini → "Sisa Pokok" & header progress bar jadi `null`/0. Ganti ke `Loan::remainingPrincipal()` count-based.

#### D3 — Breakdown & "Lain-lain" = komputasi saat nota, tidak disimpan

Pada kuitansi/nota penerimaan kas, rincian ditampilkan dari konstanta loan:

| Baris nota | Sumber | Rumus |
|---|---|---|
| Pokok | `loans.monthly_principal` | konstanta |
| Jasa | `loans.monthly_interest` | konstanta |
| Tabungan Berjangka | `loans.monthly_time_deposit` | konstanta |
| **Lain-lain** | dihitung | `amount_paid − total_due` (≥ 0) |
| **Total Dibayar** | `installments.amount_paid` | tersimpan |
| Sisa Pokok | `Loan::remainingPrincipal()` | count-based (D2) |

Tidak ada kolom; dihitung di [`installment-receipt.blade.php`](../../resources/views/pdf/installment-receipt.blade.php), view/infolist, lewat **helper tunggal** `Installment::breakdown()` (Pokok/Jasa/Tab dari `loan->monthly_*`, Lain-lain dari `amount_paid − schedule->total_due`) agar satu sumber rumus, tidak tersebar di blade.

> **Validasi dgn form fisik (2026-06-26):** breakdown ini cocok dengan **Bukti Penerimaan Kas — Unit Simpan Pinjam** resmi koperasi, yang punya baris **"Lain-lain"** persis untuk kelebihan ini. Istilah di nota disetel mengikuti form resmi: **Pokok → "Piutang SP"**, **Jasa → "Bunga SP"**, "Tabungan Berjangka" & "Lain-lain" tetap (keputusan 2026-06-26, "selaraskan istilah saja"). Form fisik juga mencantumkan **No. Perkiraan** (akun pembukuan) — di luar scope, dicatat untuk laporan Minggu 4.

#### D4 — Validasi anti-korupsi: tunggal `amount_paid ≥ total_due` (lebih lemah dari per-komponen — sadar)

Tiga `assertNotBelowBill` per-komponen → **satu** cek: `amount_paid ≥ installment_schedules.total_due` (= Σ konstanta). Validasi di server (`LoanPaymentService`) **dan** mirror di form (`minValue`/`rule` dari `total_due`); input client tak dipercaya.

> **⚠️ Penurunan kontrol (temuan critic):** model lama per-komponen mencegah "geser uang antar komponen" (mis. catat pokok kurang, tab lebih). Total-only **lebih lemah**: petugas bisa catat `amount_paid = total_due` persis padahal anggota bayar lebih, selisih riil tak terdeteksi sistem. Mitigasi = **bukti upload wajib** (kontrol detektif, sudah ada D5 lama) + audit trail. Klaim ADR bukan "anti-korupsi dipertahankan utuh" melainkan "anti-korupsi total-level + bukti" — diterima pengurus sebagai trade-off demi kesederhanaan input.

#### D5 — Saldo Tabungan Berjangka & refund pelunasan: count-based (redefinisi, BUKAN ekuivalen)

`SavingsBalanceService::timeDepositBalance` (baris 76-88) kini menjumlah `installments.time_deposit_saved` via `scopeSignedTimeDeposit`. Karena kolom hilang, redefine:

```
net_paid_schedules(loan)     = Σ(installments non-reversal) − Σ(installments reversal) untuk loan tsb
tab_berjangka_accrued(loan)  = monthly_time_deposit × net_paid_schedules(loan)
timeDepositBalance(member)   = Σ_over_loans tab_berjangka_accrued − Σ refund (tabungan_berjangka, cair, net reversal)
```

> **⚠️ Ini redefinisi semantik, bukan refactor ekuivalen (temuan critic).** Model lama menyimpan `time_deposit_saved` aktual per baris → petugas dahulu **bisa** input Tab > konstanta dan saldo ikut naik. Model baru memaksa kelebihan ke Lain-lain → Tab **tidak pernah** naik dari overpay. Hasil berbeda untuk kasus overpay. Untuk pra-rilis ini sah (keputusan pengurus), tapi **test lama yang mengandalkan `time_deposit_saved=12000` per baris (mis. `SavingsBalanceServiceTest`) harus ditulis ulang berbasis count**, bukan disesuaikan angka.

**Dua pemakai `scopeSignedTimeDeposit` harus ikut count-based:** (1) `SavingsBalanceService::timeDepositBalance`, (2) [`LoanPaymentService::loanTimeDepositAccrued`](../../app/Services/LoanPaymentService.php#L211-L219) yang dipakai `createRefunds` untuk **nilai refund saat lunas**. Satu rumus (`tab_berjangka_accrued`) dipakai di **balance DAN create-refund** agar refund yang dibatalkan reversal selalu match. SWP refund tak berubah (`loans.swp_amount`).

> **Invariant yang dikunci:** **1 baris `installments` non-reversal = 1 schedule = 1× `monthly_*`**. `net_paid_schedules` dihitung dari baris ber-`schedule_id` distinct (atau Σ tanda baris dengan jaminan idempotency 1-baris-per-schedule yang sudah ada D5 lama). Tanpa invariant ini, count-based menggandakan bila ada >1 baris per schedule — model lama yang baca nominal aktual tak punya risiko itu. FIFO + `unpaidScheduleOptions` (1 schedule terlama) + idempotency = penjaga invariant; test wajib menegaskannya.

#### D6 — "Lain-lain" bukan entity ber-audit; ketergantungan pada immutability `monthly_*` (eksplisit)

Konsekuensi yang diterima sadar (temuan critic — auditabilitas):

1. **Lain-lain hanya hidup sebagai komputasi runtime** (`amount_paid − total_due`), tak ada baris transaksi penerimaan, tak ada activitylog khusus. Untuk koperasi berprinsip "uang di sistem = uang nyata", uang Lain-lain riil masuk kas tapi jejaknya = selisih terhitung. **Diterima** untuk scope ini karena `amount_paid` (uang total) tetap tersimpan & ter-audit; agregat Lain-lain untuk laporan = `Σ(amount_paid − total_due)` (Minggu 4, di luar scope). ADR menyatakan ini batasan sadar, bukan penguatan auditabilitas.
2. **Breakdown historis bergantung KERAS pada `loans.monthly_*` immutable.** `activitylog` me-log fillable → setelah drop kolom, audit pembayaran hanya simpan `amount_paid`; rekonstruksi Pokok/Jasa/Tab historis sepenuhnya dari konstanta loan. Immutability `monthly_*` saat ini **konvensi form, tak ditegakkan DB**. Bila pernah dilanggar, seluruh sejarah breakdown bergeser retroaktif. Model lama (snapshot per baris) tahan terhadap ini. **Rekomendasi:** dokumentasikan ketergantungan ini; pertimbangkan guard immutability di `Loan` (mutator menolak ubah `monthly_*` setelah ada angsuran) — minimal jadi Open Question.

### Alternatives Considered

| Alternative | Pro | Con | Verdict |
|-------------|-----|-----|---------|
| Satu `amount_paid`, breakdown komputasi (D1–D3) | Sesuai praktik lapangan; skema ramping; satu sumber kebenaran (konstanta) | Redefinisi semantik saldo Tab; Lain-lain tak ber-audit; auditabilitas breakdown gantung ke immutability | **Chosen** |
| Tetap 3 kolom komponen (status quo D5 lama) | Rincian eksplisit + snapshot per baris (tahan perubahan konstanta) | Redundan dgn konstanta; rawan salah ketik; tak sesuai praktik | Rejected |
| Kelebihan dialokasi ke Pokok | Pinjaman lunas lebih cepat | Anggota "tak mau mikir"; rumit; ubah makna sisa pokok | Rejected (keputusan pengurus) |
| `migrate:fresh` semua env (D1) | Skema final konsisten; tak ada kolom hantu | Hapus data dev; wajib enumerasi tiap env | **Chosen** (pra-rilis, no prod data) |
| Migrasi drop-column aditif + backfill | Aman bila satu env terlanjur punya data | Mubazir bila semua fresh | **Fallback** (per-env bila ada data) |
| `remaining_principal` tetap kolom | Minim ubah kode | Simpan data turunan; langgar §7; tetap harus sinkron manual | Rejected (D2) |

---

## Rollout Plan

Self-hosted (Laravel 12 + Filament + MySQL). Bukan feature-flag; "fase" = urutan eksekusi aman. **Seluruh perubahan WAJIB satu PR atomik** (migrasi + model + service + factory + test + blade + resource + page + relation manager) — deploy kode-dulu atau migrasi-dulu membuka window error (kolom lama + kode baru tak kompatibel).

| Phase | Behavior | Status |
|-------|----------|--------|
| 0 | Enumerasi env yang sudah `migrate` `installments`; backup DB tiap env | Pending |
| 1 | PR atomik merge; `migrate:fresh` (atau `ALTER … DROP COLUMN`) tiap env | Pending |
| 2 | Verifikasi pasca-deploy (insert sukses, saldo Tab, PDF, reversal) | Pending |

### Phase Transition Checklist

**Phase 0 → 1:**
- [ ] Enumerasi **setiap** DB yang pernah `migrate` tabel `installments` (dev user, dev rekan, CI persistent, staging). Konfirmasi tak ada data produksi.
  <!-- source: manual -->
- [ ] Backup DB tiap env yang menyimpan baris `installments`.
  <!-- source: manual -->
- [ ] Konfirmasi tak ada pekerjaan angsuran in-progress di dev/staging bersama sebelum `migrate:fresh`.
  <!-- source: manual -->

**Phase 1 → 2:**
- [ ] Migrasi + kode landing satu PR atomik; `pest --parallel` GREEN (pre-push gate) — factory & 3 test file sudah diperbarui.
  <!-- source: code | query: pest suite green -->
- [ ] Tiap env: `migrate:fresh` (atau manual DROP COLUMN) dijalankan; `installments` benar-benar tak lagi punya 4 kolom.
  <!-- source: code | query: schema check installments columns -->
- [ ] `config:clear` + `route:clear` + `view:clear` + `cache:clear` (DomPDF view cache). Tak perlu `shield:generate` (tanpa perubahan permission) maupun queue drain (tanpa perubahan FQCN Job).
  <!-- source: manual -->

---

## Key Items

| # | Item | Effort | Parallel? | Status |
|---|------|--------|-----------|--------|
| 1 | **Migrasi** — edit `create_installments` buang `principal_paid`/`interest_paid`/`time_deposit_saved`/`remaining_principal` (D1, D2) | S | — | **Done** |
| 2 | **`Installment` model** — buang 4 kolom dari `$fillable`/`$casts`/`reverseClone`; ganti `scopeSignedTimeDeposit` → count-based (`net_paid_schedules`); tambah helper `breakdown()` (D3, D5) | M | setelah 1 | **Done** |
| 3 | **`Loan::remainingPrincipal()`** — accessor count-based, satu sumber sisa pokok; + soft-guard immutability `monthly_*` (D2, D6) | S | setelah 1 | **Done** |
| 4 | **`LoanPaymentService`** — `pay()` terima 1 `amount_paid`; satu validasi `≥ total_due`; buang 3 `assertNotBelowBill`+`principalPaidNet`; `loanTimeDepositAccrued` → count-based; refund pakai `tab_berjangka_accrued` (D4, D5) | M | setelah 2,3 | **Done** |
| 5 | **`CreateInstallment` page** — oper `amount_paid` (bukan 3 field) ke service (D4) | S | setelah 4 | **Done** |
| 6 | **`SavingsBalanceService`** — `timeDepositBalance` count-based, satu rumus dgn refund (D5) | M | setelah 2 | **Done** |
| 7 | **`InstallmentResource`** — form 1 `MoneyInput` (prefill+rule `total_due`); buang `prefillFromSchedule` 3-field; infolist breakdown via helper + baris Lain-lain (D3, D4) | M | setelah 4 | **Done** |
| 8 | **`SchedulesRelationManager`** — `remaining_principal` → `Loan::remainingPrincipal()`; Sisa Pokok per-baris count-based via `installment_seq` (D2) | S | setelah 3 | **Done** |
| 9 | **Kuitansi PDF** — breakdown dari konstanta + baris **Lain-lain** + sisa pokok count-based (D3) | S | setelah 2 | **Done** |
| 10 | **Test + factory** — `InstallmentFactory` buang 4 field; tulis ulang 3 test berbasis count + kasus overpay→Lain-lain. **390 test GREEN (MySQL `kopekoma_test`)** | L | setelah 4,6 | **Done** |

**Effort:** S < 1 jam, M 1-3 jam, L > 3 jam, — non-code.

> **Dependency:** Item 1 (migrasi) gerbang semua. Item 2 & 3 fondasi service/PDF/relation. **Semua landing satu PR atomik** (Rollout). Test (10) wajib GREEN sebelum merge — risiko #1 v5 (kebenaran finansial) tersentuh langsung; pre-push gate `pest --parallel`.

---

## Key Files

| File | Fungsi |
|------|--------|
| [`database/migrations/2026_06_14_090010_create_installments_table.php`](../../database/migrations/2026_06_14_090010_create_installments_table.php) | Edit — buang 4 kolom turunan (D1, D2) |
| [`app/Models/Installment.php`](../../app/Models/Installment.php) | Edit — `$fillable`/`$casts`/`reverseClone`/`scopeSignedTimeDeposit`; tambah `breakdown()` (D3, D5) |
| [`app/Models/Loan.php`](../../app/Models/Loan.php) | Edit — `remainingPrincipal()` count-based (D2) |
| [`app/Services/LoanPaymentService.php`](../../app/Services/LoanPaymentService.php) | Edit — input 1 nominal, validasi tunggal, `principalPaidNet`/`loanTimeDepositAccrued` count-based (D4, D5) |
| [`app/Filament/Resources/InstallmentResource/Pages/CreateInstallment.php`](../../app/Filament/Resources/InstallmentResource/Pages/CreateInstallment.php) | Edit — oper `amount_paid` ke service (D4) |
| [`app/Services/SavingsBalanceService.php`](../../app/Services/SavingsBalanceService.php) | Edit — `timeDepositBalance` count-based (D5) |
| [`app/Filament/Resources/InstallmentResource.php`](../../app/Filament/Resources/InstallmentResource.php) | Edit — form 1 `MoneyInput`, buang `prefillFromSchedule` 3-field, infolist breakdown (D3, D4) |
| [`app/Filament/Resources/RelationManagers/SchedulesRelationManager.php`](../../app/Filament/Resources/RelationManagers/SchedulesRelationManager.php) | Edit — `remaining_principal` → `remainingPrincipal()` (D2) |
| [`resources/views/pdf/installment-receipt.blade.php`](../../resources/views/pdf/installment-receipt.blade.php) | Edit — breakdown konstanta + baris Lain-lain (D3) |
| `app/Exceptions/CannotProcessPayment.php` | Edit — `belowBill` jadi pesan tunggal |
| [`database/factories/InstallmentFactory.php`](../../database/factories/InstallmentFactory.php) | Edit — buang 4 field uang turunan |
| `tests/Feature/{LoanPaymentService,SavingsBalanceService,InstallmentResource}Test.php` | Edit — tulis ulang berbasis count + kasus overpay/reversal/invariant |

---

## Verification

- [ ] **Precondition env** — tiap DB yang pernah `migrate` `installments` di-`migrate:fresh`/`DROP COLUMN`; konfirmasi skema benar-benar tanpa 4 kolom (cegah kolom-hantu NOT NULL → insert gagal).
- [ ] Bayar tepat tagihan → Lain-lain = 0; breakdown nota = konstanta; schedule `Terbayar`; **insert sukses** (bukti kolom benar hilang).
- [ ] Bayar **lebih** → diterima; Lain-lain = `amount_paid − total_due`; saldo Tab **tidak** bertambah dari kelebihan; sisa pokok turun tepat `monthly_principal`.
- [ ] Bayar **kurang** → ditolak (`amount_paid < total_due`).
- [ ] `timeDepositBalance` = `monthly_time_deposit × net_paid_schedules`; **cocok dgn nilai refund** saat lunas (satu rumus).
- [ ] Reversal pelunasan → loan Cair, refund dibatalkan, `net_paid_schedules` turun → saldo Tab & sisa pokok kembali benar.
- [ ] Sebrakan (jangka pendek, 1 baris, `monthly_principal = principal`, jasa/tab=0) → bayar → Lunas; sisa pokok 0; Tab 0.
- [ ] `SchedulesRelationManager` (progres angsuran) — Sisa Pokok per-baris & header bar benar (bukan 0/null).
- [ ] PDF kuitansi — Pokok/Jasa/Tab dari konstanta + Lain-lain, bukan `Rp 0`.
- [ ] Invariant: tak mungkin >1 baris `installments` non-reversal per schedule (FIFO + idempotency) — diuji.
- [ ] `pest --parallel` GREEN (idealnya invariant konkurensi di MySQL — gap warisan modul Simpanan).

---

## Open Questions

- ~~**Guard immutability `loans.monthly_*`?**~~ **RESOLVED** — soft-guard ditambahkan di `Loan::booted()` (`updating` menolak ubah `monthly_*` bila sudah ada angsuran). Menutup ketergantungan auditabilitas D6.
- **Pos "Lain-lain" di laporan keuangan (Minggu 4)** — perlu agregat penerimaan Lain-lain per periode? Datanya = `Σ(amount_paid − total_due)` tersedia komputasi; pertimbangkan apakah perlu jadi entity ber-audit terpisah (D6).
- **Histori nota lama** — pra-rilis, tak ada kuitansi tercetak yang perlu kompatibel. Konfirmasi final.

---

## Pipeline trace (v1)

| Stage | Agent | Key output | Date |
|---|---|---|---|
| Framing | strategist | (retroactive — not invoked) Masalah: input 3-komponen redundan dgn konstanta terkunci; scope dipersempit ke sisi pencatatan pembayaran | 2026-06-26 |
| Data baseline | data-analyst | skipped: pra-rilis, `installments` belum ada data produksi untuk di-baseline (justru jadi precondition migrasi D1) | 2026-06-26 |
| Design | architect | (retroactive — not invoked) Satu `amount_paid`; breakdown + Lain-lain + saldo = komputasi `loans.monthly_*` × net_paid_schedules | 2026-06-26 |
| Critique | critic | **REVISE** — fondasi sound; 3 lubang ditutup di Ronde 2: reader `remaining_principal` terlewat (`SchedulesRelationManager`), klaim "ekuivalen"/"anti-korupsi utuh" overstated (redefinisi semantik + kontrol total lebih lemah), Lain-lain bukan entity ber-audit | 2026-06-26 |
| Security review | security-reviewer | skipped: tak ada perubahan privilege/akses Shield; namun kontrol anti-korupsi turun dari per-komponen ke total — dicatat sadar di D4 (mitigasi: bukti upload + audit) | 2026-06-26 |
| Deploy review | deploy-reviewer | **GO-WITH-GUARDRAILS** — migrasi sudah ada di `main`/`development` (bukan branch-isolated); edit in-place butuh `migrate:fresh` tiap env atau kolom-hantu NOT NULL → insert gagal. PR atomik + enumerasi env wajib (D1, Rollout) | 2026-06-26 |
| Implementation | implementer (Claude) | 10 item Done di branch `feat/loan-management`; soft-guard immutability `monthly_*` ditambahkan (resolve Open Question). **390 test GREEN** (MySQL `kopekoma_test`) | 2026-06-26 |
| Review | reviewer | pending — belum di-review/commit; deploy `migrate:fresh` tiap env masih perlu | — |

**Ronde**: 2 (critic REVISE → architect re-design: D1 precondition dikoreksi, D2 tambah reader terlewat, D4/D5 diturunkan dari "ekuivalen" ke "redefinisi sadar", D6 baru untuk auditabilitas)
**Skipped stages**: data-analyst (pra-rilis, no baseline), security-reviewer (no privilege/akses change — tapi penurunan kontrol anti-korupsi dicatat di D4)
**Calibration notes**: Draft v1 salah klaim migrasi "branch-isolated" — fakta git: file sudah di `main`/`development`. Miss di stage Framing/Design (asumsi tak diverifikasi ke git); ditangkap deploy-reviewer. Pelajaran: verifikasi klaim "belum di main" ke `git` sebelum menulis precondition migrasi.

---

## Changelog

- **2026-06-26 v4**: Selaraskan istilah nota dgn **Bukti Penerimaan Kas resmi** — "Pokok"→"Piutang SP", "Jasa"→"Bunga SP" di kuitansi angsuran PDF, infolist `InstallmentResource`, detail jadwal `SchedulesRelationManager`, & jadwal di `loan-receipt` PDF. "Lain-lain" & "Tab. Berjangka" tetap. Tak sentuh label Simpanan Pokok (`MemberResource`). 35 test pinjaman/angsuran GREEN.
- **2026-06-26 v3**: Implemented. 10 item Done di branch `feat/loan-management`; soft-guard immutability `monthly_*` ditambahkan (resolve Open Question D6). **390 test GREEN** (MySQL `kopekoma_test`, 1418 assertions). Pending: review/commit + `migrate:fresh` tiap env.
- **2026-06-26 v2**: Ronde 2 pasca-review. Koreksi precondition migrasi (sudah di `main`/`development` → `migrate:fresh` tiap env); tambah `SchedulesRelationManager` & `CreateInstallment` sebagai reader yang harus diubah; turunkan klaim "ekuivalen"→"redefinisi sadar" (D5) & "anti-korupsi utuh"→"total-level + bukti" (D4); tambah D6 (auditabilitas Lain-lain + immutability `monthly_*`); drop `remaining_principal` final (D2); Key Items 7→10, Rollout jadi PR atomik + enumerasi env.
- **2026-06-26 v1**: Initial draft — penyederhanaan input angsuran ke nominal tunggal; amends D5/D7/D8 ADR modul pinjaman.
