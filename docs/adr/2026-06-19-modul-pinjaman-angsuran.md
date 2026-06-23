# Modul Pinjaman & Angsuran (Kalkulator Angsuran, Jadwal, Pembayaran, Pengembalian saat Lunas)

Membangun modul Pinjaman (Minggu 3) di atas fondasi keuangan modul Simpanan — kalkulator angsuran yang di-unit-test lebih dulu, lalu CRUD pencatatan pinjaman ACC, jadwal angsuran otomatis, pembayaran (potong gaji + manual), dan pengembalian SWP + Tabungan Berjangka saat lunas.

**Author:** Ribka Restu
**Date:** 2026-06-19
**Status:** Implemented (core) — sisa: batch potong gaji angsuran (3c) + security/deploy review

---

## Background

Modul **Simpanan (Minggu 2) selesai** — fondasi keuangan reusable sudah berdiri & terverifikasi ([ADR modul simpanan](2026-06-16-modul-simpanan.md), 197+ test hijau): `SavingsBalanceService` (saldo computed-on-read net-of-reversal), `ReverseTransaction` Action + interface `Reversible`, generator nomor race-safe (`GeneratesTransactionNumber`), idempotency Hidden-uuid compare-or-warn, dan RBAC berbasis permission Shield. Sesuai [Rencana Pengerjaan v5 §Minggu 3](../Rencana_Pengerjaan_Koperasi_v5.md) (29 Juni–3 Juli — *"minggu terberat"*), tahap berikutnya adalah **Pinjaman & Angsuran**.

Lapisan basis data **sudah ada** (migrasi + model Eloquent): `loans`, `installment_schedules`, `installments`, `loan_blacklists`. Skema **hampir final** untuk kebutuhan modul ini — `installments` sudah punya `idempotency_key` + `reversal_of_id`, dan `savings_withdrawals` sudah punya `related_loan_id` + jenis `swp`/`tabungan_berjangka`. Yang **belum ada**: lapisan service (kalkulator angsuran, payment workflow), Filament Resource, PDF, dan **satu migrasi aditif** (guard single-reversal di `installments`, lihat D0).

Dua keputusan governance penting **sudah dikonfirmasi** ([Dokumentasi Sistem v5 §3, §4.5, §5.3](../Dokumentasi_Sistem_Koperasi_v5.md)):

1. **Persetujuan pinjaman dilakukan di LUAR sistem** oleh admin/pengurus. Sistem hanya mencatat pinjaman yang **sudah ACC & cair** — **tidak ada workflow approval di aplikasi.** Skema mencerminkan ini: `loans.status` mulai dari `Cair`, tanpa state draft/pending.
2. **Verifikasi kemampuan potong gaji** = tanggung jawab admin & bendahara (manual). Sistem **membantu menampilkan** angka potongan berjalan (read-only, tidak memblokir) — keputusan tetap di tangan manusia.

Risiko #1 Rencana v5 (*"logika keuangan — angsuran, reversal, saldo — salah → dampak Tinggi"*) memuncak di sini. Karena itu **kalkulator angsuran wajib dibangun & di-unit-test lebih dulu** sebelum CRUD/UI apa pun (pola "fondasi dulu" yang sudah terbukti di modul Simpanan).

Tiga prinsip keuangan non-negotiable ([Dokumentasi §7](../Dokumentasi_Sistem_Koperasi_v5.md)) tetap berlaku dan **dipakai ulang** dari modul Simpanan: (1) saldo dihitung dari transaksi, (2) reversal bukan hapus, (3) pencegahan input ganda (idempotency).

---

## Goals

- **Kalkulator angsuran reusable & ter-unit-test** — hitung potongan pencairan (Admin 1% + SWP 1%), dana diterima, dan jadwal angsuran (Pokok + Jasa + Tabungan Berjangka) secara deterministik dengan **aturan pembulatan eksplisit**.
- **Pencatatan pinjaman ACC** — dua jenis: Jangka Pendek (Sebrakan) & Jangka Panjang; potongan & dana diterima dihitung otomatis (bukan input bebas).
- **Jadwal angsuran otomatis** untuk jangka panjang (`installment_schedules` di-generate saat akad).
- **Pembayaran angsuran** — potong gaji (batch per OPD, reuse engine Simpanan) + manual; reversal + idempotency.
- **Pengembalian SWP + Tabungan Berjangka saat lunas** — direkam sebagai `savings_withdrawals` bertaut `related_loan_id`; **menyalakan** jenis `swp`/`tabungan_berjangka` di `SavingsBalanceService` (yang dititipkan modul Simpanan).
- **Blacklist** — anggota bermasalah tak bisa diajukan pinjaman baru (guard di create).
- **Tunggakan/angsuran bolong** — terdeteksi runtime (tanpa denda, tanpa pemutusan otomatis).
- **Dokumen & cetak** — upload formulir/tanda terima (Media Library), Tanda Terima Pinjaman PDF, kuitansi angsuran PDF.

## Non-Goals

- **Workflow approval pinjaman di sistem** — ACC dilakukan di luar sistem (Dokumentasi §3). Sistem hanya mencatat pinjaman yang sudah cair.
- **Blocking otomatis berbasis kapasitas potong gaji** — sistem hanya **menampilkan** angka bantu; verifikasi & keputusan tetap manual (admin/bendahara).
- **Denda keterlambatan / bunga berjalan atas tunggakan** — tidak ada di aturan koperasi (Dokumentasi §4.6: angsuran boleh bolong, tak ada denda).
- **Pembatasan jumlah pinjaman aktif** — **tidak ada** (keputusan pengurus 2026-06-19). Anggota boleh punya **>1 pinjaman aktif** bila disetujui pengurus (approval di luar sistem; kalau pinjaman sudah dibuat di sistem = sudah diizinkan). Sistem tak memblokir berdasarkan pinjaman berjalan.
- **Modul Keluar/SHU** — pengembalian Simpanan Pokok/Wajib saat anggota keluar = modul lain (di luar scope; sudah dicatat sebagai Open Question modul Simpanan).
- **Laporan & Dashboard pinjaman** (Minggu 4) — rekap angsuran, daftar pinjaman aktif, widget jatuh tempo. Audit per-event disiapkan di sini agar laporan itu mungkin nanti.
- **Perubahan skema besar** — hanya migrasi **aditif** (preseden modul Simpanan): `unique(reversal_of_id)` di `installments` (D0). Tidak mengubah kolom existing.

---

## Design

### Approach

**Fondasi dulu, baru CRUD** — kebenaran kalkulasi finansial dikunci & di-unit-test sebelum ada UI.

```
LoanCalculator (potongan pencairan + jadwal angsuran, pembulatan eksplisit)  ← fondasi, unit-tested duluan
generator nomor LOAN-/ANG- (reuse GeneratesTransactionNumber)                ← fondasi
migrasi aditif unique(reversal_of_id) di installments                        ← fondasi
        ↓ dipakai semua di bawah
Pencatatan pinjaman (LoanResource) → auto-generate schedule → Tanda Terima PDF
Pembayaran angsuran (manual + batch potong gaji) → reversal + idempotency
SWP & Tabungan Berjangka balance (SavingsBalanceService di-nyalakan) → Pengembalian saat lunas
Blacklist guard | Tunggakan runtime | Kapasitas potong-gaji (info read-only)
```

Service & Resource **mengikuti konvensi yang sudah baku** di modul Simpanan: reuse `ReverseTransaction`/`Reversible`, `GeneratesTransactionNumber`, `HasSignedAmount`, idempotency Hidden-uuid compare-or-warn (D4 Simpanan), RBAC permission-based Shield (D7 Simpanan), `LogsActivity` + `AuditTrailRelationManager` wajib per record, `MoneyInput` global, view-page immutable, koreksi via reversal.

> **⚠️ Catatan environment test (warisan modul Simpanan):** test suite jalan di **SQLite `:memory:`**, produksi **MySQL**. `lockForUpdate` no-op di SQLite → klaim race-safe (generator nomor, pembayaran konkuren) hanya bersandar pada **unique constraint backstop** di test default. Invariant konkurensi (mis. double-bayar angsuran yang sama) **wajib diuji terhadap MySQL** atau klaim diturunkan jadi "best-effort + unique backstop".

### Keputusan Desain

#### D0 — Migrasi aditif: guard single-reversal di `installments`

**Dua migrasi aditif** (tidak ubah kolom existing):
1. **`unique('reversal_of_id')` di `installments`** — guard single-reversal (konsisten D3 Simpanan: MySQL & SQLite izinkan multiple-NULL → baris non-reversal aman; dua reversal atas angsuran sama → violation, ditangkap sebagai error bisnis).
2. **Kolom konstanta angsuran di `loans`** (D1b): `monthly_principal`, `monthly_interest`, `monthly_time_deposit` (`decimal(18,2)`, nullable untuk baris existing / diisi saat akad) — tagihan per bulan yang dikunci.
3. **Kolom `disbursement_method` di `savings_withdrawals`** (D8): enum `['tunai','transfer']` nullable — metode pengembalian SWP/tab saat pelunasan (tunai/TF). Tabel milik modul Simpanan, perubahan additive.

`installment_schedules` & `loan_blacklists` tidak butuh migrasi. **Konsekuensi: deploy-reviewer TIDAK di-skip** (3 migrasi pada tabel finansial, satu di antaranya tabel shared `savings_withdrawals`).

#### D1 — Kalkulator angsuran: service murni, pembulatan eksplisit, konstanta dikunci

Inti finansial modul. **Service stateless `LoanCalculator`**, di-unit-test sebelum UI. Sumber rumus: [Dokumentasi §4.5–4.6](../Dokumentasi_Sistem_Koperasi_v5.md).

**Potongan pencairan (Jangka Panjang):**

| Komponen | Rumus | Kolom |
|---|---|---|
| Biaya Admin | `principal_amount × 1%` → pendapatan koperasi | `loans.admin_fee` |
| SWP | `principal_amount × 1%` → simpanan anggota, refund saat lunas | `loans.swp_amount` |
| **Dana diterima** | `principal_amount − admin_fee − swp_amount` | `loans.disbursed_amount` |

**Jangka Pendek (Sebrakan):** tanpa potongan → `admin_fee = 0`, `swp_amount = 0`, `disbursed_amount = principal_amount`, `term_months = 1`, **jadwal 1 baris** (jasa/tab = 0, D4) supaya bisa nunggak & terdeteksi.

**Komponen tiap angsuran (Jangka Panjang)** — basis **Jasa & Tab Berjangka = `principal_amount` (jumlah pinjaman diajukan)**, basis Pokok = `principal_amount / term_months`:

| Komponen | Rumus | Kolom schedule |
|---|---|---|
| Pokok | `ceil(principal_amount / term_months)` → **rupiah utuh, dibulatkan ke ATAS** (**konstan tiap bulan**) | `principal_due` |
| Jasa | **`principal_amount × 0,65%`** (dari jumlah pinjaman, **bukan** pokok) (**konstan tiap bulan**) | `interest_due` |
| Tabungan Berjangka | **`principal_amount × 0,1%`** (dari jumlah pinjaman, **bukan** pokok) (**konstan tiap bulan**) | `time_deposit_due` |
| **Total** | `principal_due + interest_due + time_deposit_due` (**konstan tiap bulan**) | `total_due` |

> **⚠️ KOREKSI rumus (keputusan pengurus 2026-06-19):** Jasa & Tabungan Berjangka dihitung dari **`principal_amount` (jumlah pinjaman diajukan)**, BUKAN dari Pokok per bulan. Dokumentasi Sistem v5 §4.6 versi lama (Pokok × 0,65% → contoh 6.500) **KELIRU** dan sudah ikut diperbaiki di [dokumen sistem](../Dokumentasi_Sistem_Koperasi_v5.md). Nilai yang benar 12x lebih besar.
>
> **Ketiga komponen KONSTAN tiap bulan** — `principal_amount` tetap → Jasa & Tab konstan; Pokok juga konstan. Semua aritmetika pakai **bcmath string**, bukan float.
>
> **Pembulatan = hanya di Pokok, ke ATAS, rupiah utuh.** Hanya Pokok yang melibatkan pembagian (`principal / term`) → `pokok = ceil(principal_amount / term_months)`. Contoh: 10.000.000 / 12 = 833.333,33… → **833.334/bln**. Jasa & Tab tak butuh pembulatan (perkalian persen dari principal). **Dipilih ke ATAS** agar `Σ pokok ≥ principal_amount` → pinjaman **pasti lunas penuh** (ke bawah → kurang bayar).
>
> **C2 RESOLVED (keputusan pengurus 2026-06-19): semua bulan KONSTAN, kelebihan receh diterima.** Tiap bulan pokok sama persis (mis. 833.334 semua). `Σ pokok` boleh lebih beberapa rupiah dari `principal_amount` (12 × 833.334 = 10.000.008, lebih 8) — **diterima, tak ada bulan-terakhir-beda**. `remaining_principal` dihitung dari **pembayaran aktual** (D5), ditampilkan `max(0, principal − Σ principal_paid)`. (Klaim v3 "remaining_principal floor 0 di angsuran terakhir" yang bertabrakan dengan "konstan" dicabut.)

#### D1b — Konstanta angsuran disimpan di tabel `loans` (keputusan pengurus 2026-06-19)

`loans` saat ini **tidak** punya kolom pokok/jasa/tab. Ditambah via **migrasi aditif** (item 0): `monthly_principal`, `monthly_interest`, `monthly_time_deposit` (`decimal(18,2)`) — **konstanta tagihan per bulan**, dihitung `LoanCalculator` saat akad lalu **disimpan & dikunci** (immutable). Ini "kartu tagihan" resmi pinjaman. `installment_schedules` tetap ada untuk **per-bulan `due_date` + `status`** (deteksi tunggakan); nilai due-nya = snapshot konstanta ini. **Sumber kebenaran tagihan = `loans` (konstanta); sumber kebenaran uang = `installments` (aktual, D5).**

**Contoh validasi (Dokumentasi §4.6, sudah dikoreksi):** Pinjam Rp 12.000.000, 12 bulan → **pokok 1.000.000 + jasa (12.000.000 × 0,65% = 78.000) + tab berjangka (12.000.000 × 0,1% = 12.000) = total 1.090.000/bln**. Saat cair: SWP 120.000 + Admin 120.000 → **diterima 11.760.000**. Saat lunas: SWP 120.000 + Σ tab berjangka (12 × 12.000 = 144.000) dikembalikan. Test wajib memuat contoh ini persis.

#### D2 — Generator nomor pinjaman & angsuran (reuse `GeneratesTransactionNumber`)

Reuse trait race-safe modul Simpanan (D2 Simpanan), reset per tahun, 6 digit, immutable:

| Entitas | Kolom | Format | Contoh |
|---|---|---|---|
| Pinjaman | `loans.loan_number` | `PJM-YYYY-NNNNNN` | `PJM-2026-000001` |
| Angsuran | `installments.installment_number` | `ANG-YYYY-NNNNNN` | `ANG-2026-000001` |

#### D3 — Pencatatan pinjaman: potongan otomatis (server-authoritative), bukan input bebas

`LoanResource` Create: petugas pilih anggota + jenis + `principal_amount` + `term_months` + `disbursement_date` + upload dokumen. `admin_fee`/`swp_amount`/`disbursed_amount` **dihitung server** via `LoanCalculator` (`mutateFormDataBeforeCreate`), field di-display read-only — **input client tak dipercaya** (pola D11 Simpanan). Validasi plafon: Jangka Pendek `≤ 1.000.000`, Jangka Panjang `> 1.000.000` (aturan plafon Dokumentasi §4.5).

**Tidak ada "pembatalan pinjaman" (keputusan pengurus 2026-06-19):** pinjaman hanya masuk sistem **setelah ACC** (di luar sistem), jadi tak ada workflow batal/tolak di aplikasi. **Namun koreksi salah-input tetap perlu jalan** (petugas salah ketik nominal/anggota): pinjaman **immutable setelah dibuat**, koreksi = **reversal seluruh record pinjaman** (`ReverseTransaction`, ikut membalik schedule yang ter-generate) — **hanya boleh bila BELUM ada angsuran terbayar**, gate **Pengurus+**, alasan wajib, ter-audit. Ini "koreksi kesalahan pencatatan", **bukan** pembatalan pinjaman bisnis. Bila sudah ada pembayaran → tak bisa di-reversal (reversal pembayaran dulu satu per satu). Konsisten prinsip §7 (koreksi via reversal, bukan hapus/edit).

#### D4 — Auto-generate `installment_schedules` saat akad (kedua jenis — Sebrakan = 1 baris)

Saat `Loan` jangka panjang dibuat → `LoanCalculator::buildSchedule()` menghasilkan N baris `installment_schedules` (seq 1..N, `due_date` per bulan dari `first_due_date`, breakdown D1) dalam **satu transaksi** bersama loan (atomic).

**Jangka Pendek (Sebrakan) JUGA dapat 1 baris schedule (revisi — temuan C4):** seq 1, `due_date = first_due_date` (1 bulan dari pencairan), `principal_due = principal_amount`, `interest_due = 0`, `time_deposit_due = 0`, `total_due = principal_amount`. Alasan: Sebrakan **bisa bolong** (kasus sosial — keputusan pengurus 2026-06-19), dan deteksi tunggakan (D10) bekerja dengan men-scan `installment_schedules`. Tanpa baris ini, Sebrakan yang lewat tempo **tak terdeteksi** & tak masuk warning riwayat. Memodelkan Sebrakan sebagai "pinjaman 1-angsuran tanpa jasa/tab" menyatukan jalur pembayaran, tunggakan, & warning — bukan kontradiksi dengan "tanpa angsuran bulanan" (tetap 1 pelunasan penuh). `first_due_date` wajib untuk **kedua** jenis.

#### D5 — Pembayaran angsuran: nominal aktual diinput petugas, divalidasi ≥ tagihan, jadi sumber kebenaran uang

**Prinsip anti-korupsi & integritas laporan (keputusan pengurus 2026-06-19):** uang di sistem **wajib = uang nyata yang diterima**. Maka saat setor angsuran, petugas **menginput nominal aktual tiap item** (`principal_paid`/`interest_paid`/`time_deposit_saved`), **divalidasi server `≥ konstanta tagihan`** (`loans.monthly_*`, D1b) — **tak boleh kurang dari tagihan** (cegah petugas mencatat setoran lebih kecil dari yang diterima → selisih dikorupsi). Boleh **sama atau lebih** (lebih bayar sah). Kalau anggota tak bayar bulan itu → **bolong** (tak ada baris `installments`, schedule tetap `Belum Bayar`) — bukan pembayaran kurang. **Saldo & laporan (SWP/tab, sisa pokok) dihitung dari `installments.*_paid` aktual, BUKAN dari tagihan teoretis** → uang di sistem tak pernah beda dari yang benar-benar tercatat.

**Upload bukti pembayaran (keputusan pengurus 2026-06-19):** tiap setoran angsuran **menyertakan upload bukti** (slip/foto/kuitansi) yang mendukung nominal diterima — kontrol detektif pendamping validasi `≥ tagihan`. Pakai **Media Library** (Spatie, tabel `media` polymorphic sudah ada → **tanpa migrasi baru**); `Installment` perlu tambah trait `HasMedia`/`InteractsWithMedia` (saat ini belum, beda dari `Loan`). Bukti ikut tampil di view angsuran & audit trail. Apakah bukti **wajib** atau **opsional** (mis. wajib utk `manual`, opsional utk `potong_gaji` batch) → lihat Open Questions.

**Urutan pembayaran FIFO (keputusan pengurus 2026-06-23):** angsuran **wajib dibayar berurutan dari yang paling lama belum bayar** — tak boleh loncat (mis. bayar #3 sementara #2 masih nunggak), karena gap urutan merusak pencatatan & rekonsiliasi. Dropdown jadwal di `InstallmentResource` hanya menampilkan **1 angsuran terlama** (`Belum Bayar`, `installment_seq` terkecil) → petugas tak punya jalan untuk melompat (`unpaidScheduleOptions()` → `orderBy('installment_seq')->limit(1)`). Tidak ada fitur bayar di muka/percepat per-angsuran; pelunasan dipercepat penuh ditangani via jalur pelunasan (D8).

`Installment` mencatat realisasi bayar. Metode: **`potong_gaji`** (utama) / **`manual`**. Tiap pembayaran (dalam transaksi):
1. Buat baris `installments` (idempotency Hidden-uuid compare-or-warn — D4 Simpanan) dengan `principal_paid`/`interest_paid`/`time_deposit_saved`/`amount_paid` (input petugas, tervalidasi ≥ konstanta) + `remaining_principal = max(0, principal − Σ principal_paid)`.
2. Tandai `installment_schedules.status = 'Terbayar'` untuk seq terkait (`schedule_id`).
3. **Auto-Lunas (D8)** dievaluasi.

Reversal pembayaran = `ReverseTransaction` Action (reuse) → membalik schedule ke `Belum Bayar` + (jika perlu) batalkan status Lunas. `is_reversal`/`reversal_of_id` + guard single-reversal (D0). **Jangka Pendek**: bayar schedule seq-1 (`amount_paid = principal_amount`, jasa/tab = 0, D4) → langsung Lunas.

#### D6 — Pembayaran via batch potong gaji per OPD (reuse engine Simpanan)

Angsuran `potong_gaji` per OPD memakai **pola yang sama** dengan `BatchSalaryDeductionService` (chunked `create()` per baris untuk audit per-anggota utuh, reservasi nomor sekali, pre-commit dup-check, lock per OPD, log batch satu peristiwa — D5 Simpanan). **Keputusan:** generalisasi engine batch agar melayani angsuran, **atau** service angsuran-batch terpisah yang mereplikasi pola — lihat Alternatives & Open Questions (hindari premature abstraction). Dup-check angsuran berbasis `(loan_id, schedule_seq)` aktif (bukan periode bulan).

#### D7 — SWP & Tabungan Berjangka: nyalakan `SavingsBalanceService` dari tabel pinjaman

Modul Simpanan **menitipkan** dua jenis ini: `SavingsBalanceService::balanceByType()` saat ini `throw UnsupportedSavingsType('swp'|'tabungan_berjangka')`. Modul Pinjaman **mengganti throw dengan komputasi nyata**, sumbernya **tabel pinjaman** (bukan `savings_deposits` — enum deposits memang tak punya kedua jenis ini; ini **disengaja**):

> **C3 RESOLVED (keputusan pengurus 2026-06-19): SATU pengurangan saja, dari nominal AKTUAL.** Versi draft sebelumnya keliru mengurangi 2× (filter "belum di-refund" **dan** kurangi withdrawal). Rumus benar = **akumulasi (dari nominal aktual yang diinput, D5) − yang sudah dikembalikan**, tanpa filter ganda:

| Jenis | Rumus saldo (computed-on-read) |
|---|---|
| `swp` | `Σ loans.swp_amount` (semua pinjaman anggota) **−** `Σ savings_withdrawals` (type `swp`, `cair`, net reversal) |
| `tabungan_berjangka` | `Σ installments.time_deposit_saved` (**aktual** yang diinput petugas, net reversal) **−** `Σ savings_withdrawals` (type `tabungan_berjangka`, `cair`, net reversal) |

Catatan: SWP = potongan sekali saat cair → akumulasi dari `loans.swp_amount`. Tabungan Berjangka = terkumpul per angsuran → akumulasi dari **`installments.time_deposit_saved` aktual** (bukan tagihan `monthly_time_deposit`), jadi bulan bolong = 0 (konsisten: tak bayar = tak menabung). API baru: `loanSwpBalance(member)`, `timeDepositBalance(member)`, integrasi ke `allBalances()`/`totalBalance()`. Saldo tetap **computed-on-read** — tak ada kolom saldo mutable.

#### D8 — Status Lunas otomatis + pengembalian SWP & Tabungan Berjangka

**Auto-Lunas:** `loans.status → 'Lunas'` saat **semua `installment_schedules` = 'Terbayar'** (Jangka Panjang) atau pembayaran penuh tercatat (Jangka Pendek). Dievaluasi atomik di akhir setiap pembayaran (D5). Reversal pembayaran terakhir membalik Lunas → Cair.

**Pengembalian saat lunas** ([Dokumentasi §4.6](../Dokumentasi_Sistem_Koperasi_v5.md): anggota terima Tabungan Berjangka + SWP): direkam sebagai **`savings_withdrawals`** bertaut `related_loan_id`, type `swp` & `tabungan_berjangka`, sebesar saldo akumulasi pinjaman tsb. **Mengapa di `savings_withdrawals`, bukan tabel baru:** skema `savings_withdrawals` (kolom `related_loan_id` + enum `swp`/`tabungan_berjangka`) **dirancang persis untuk ini** → reuse penuh idempotency/reversal/audit; saldo D7 otomatis ter-net.

**Pemicu refund = OTOMATIS saat pelunasan (keputusan pengurus 2026-06-19) — menutup M2 & M4:** refund **dibuat dalam transaksi yang SAMA** dengan pembayaran terakhir yang membuat loan `Lunas` (atomik, bukan langkah manual terpisah). Begitu pembayaran pelunasan tercatat → loan `Lunas` → di transaksi itu juga, 2 baris `savings_withdrawals` (`swp` + `tabungan_berjangka`) dibuat sebesar saldo akumulasi (D7), status langsung `cair`.

- **Nilai refund** dihitung dari **D7** saat itu juga: SWP = `loans.swp_amount`; Tabungan Berjangka = `Σ installments.time_deposit_saved` aktual pinjaman tsb.
- **M2 (reversal pasca-refund) RESOLVED:** karena refund nempel di transaksi pelunasan, **reversal pembayaran terakhir membalik Lunas→Cair DAN membatalkan kedua refund withdrawal** (reversal berantai dalam satu Action). Tak ada refund yatim.
- **Anti-dobel-refund:** karena refund hanya lahir dari event pelunasan (sekali), dan di-undo via reversal — tak perlu unique-constraint tambahan; guard = "refund hanya boleh saat transisi Cair→Lunas, ditolak bila loan sudah Lunas".
- **M4 (4a coupling) RESOLVED:** bentuk refund kini pasti → item 4a (saldo) & 4b (refund) bisa difinalkan; 4b **tak lagi Blocked**.
- **Metode pengembalian = tunai / transfer (keputusan pengurus 2026-06-19):** saat pelunasan, petugas memilih cara refund **tunai** atau **transfer (TF)**, dicatat pada refund withdrawal → **kolom aditif `disbursement_method` enum `['tunai','transfer']`** di `savings_withdrawals` (nullable; relevan untuk baris refund pinjaman). Untuk audit & rekonsiliasi kas vs bank. (Migrasi pada tabel `savings_withdrawals` milik modul Simpanan — additive, lihat item 0.)

> **Sisa untuk security review:** refund `cair` langsung (tanpa workflow `draft→acc→cair`) = uang keluar tanpa mata-kedua. Diterima karena **nominal refund deterministik** (bukan diskresi petugas — dihitung dari data pinjaman) & ter-audit; tapi konfirmasikan di security pass apakah perlu approval Pengurus untuk pelunasan.

#### D9 — Blacklist: guard di create pinjaman

`loan_blacklists` (sudah ada): anggota dengan blacklist **aktif** (`is_active = true`, belum `released_at`) **tak bisa** dibuatkan pinjaman baru. Guard di `LoanResource` create (validasi form + guard di `mutateFormDataBeforeCreate`/Page, bukan hanya UI). `LoanBlacklistResource` CRUD untuk menandai/melepas (gate permission, D11). `blacklisted_at`/`released_at` + `reason` wajib.

#### D10 — Tunggakan / angsuran bolong: runtime, tanpa denda/sanksi, warning riwayat saat pinjam lagi

[Dokumentasi §4.6](../Dokumentasi_Sistem_Koperasi_v5.md): angsuran boleh bolong; sistem mencatat tanpa memutus pinjaman otomatis, **tanpa denda/sanksi**. Maka tunggakan **dihitung runtime** (`installment_schedules` dengan `due_date < today` AND `status = 'Belum Bayar'`) — **tidak** ada kolom status `Terlambat` atau kolom denda (skema sengaja hanya `Belum Bayar`/`Terbayar`). **Mencakup Sebrakan** (kini punya 1 baris schedule, D4) → pinjaman jangka pendek yang lewat tempo ikut terdeteksi sebagai tunggakan & masuk warning riwayat.

**Warning riwayat angsuran saat mengajukan pinjaman baru (keputusan pengurus 2026-06-19):** sasaran utama warning ini adalah **saat anggota mau pinjam LAGI**. Saat petugas membuat pinjaman baru (`LoanResource` create), sistem menampilkan **peringatan track-record** bila anggota tsb punya riwayat **angsuran bolong / keluar tenor** di pinjaman sebelumnya (saat ini & yang sudah lunas) — mis. *"⚠ Anggota ini punya riwayat N angsuran terlewat pada pinjaman sebelumnya"*. Tujuannya membantu pertimbangan petugas/pengurus sebelum mencatat pinjaman baru. **Bersifat advisory — TIDAK memblokir** (mekanisme blokir = blacklist D9, keputusan manusia). Riwayat dihitung runtime dari `installment_schedules` lintas pinjaman anggota (jumlah pernah-terlewat & yang masih terlewat). Indikator yang sama juga tampil di detail anggota, daftar pinjaman, & halaman pembayaran cepat (3b), serta jadi filter/indikator laporan (Minggu 4).

**Dua metrik track-record (keputusan pengurus 2026-06-23):** warning menggabungkan **dua** sinyal berbeda, karena keduanya jejak yang berbeda:

| Metrik | Definisi runtime | Sumber |
|---|---|---|
| **Tunggakan berjalan** | `due_date < hari ini` AND `status = 'Belum Bayar'` | `installment_schedules` (scope `overdue()`) |
| **Pernah telat bayar** | `installments.payment_date > installments.due_date` (is_reversal = false) | `installments` (`memberLatePaymentCount()`) |

Tanpa metrik kedua, anggota yang **telat bayar tapi akhirnya lunas** lolos dari track-record: begitu schedule jadi `Terbayar`, ia keluar dari hitungan `overdue`, sehingga keterlambatan yang sudah terjadi tak meninggalkan jejak. Metrik "pernah telat" menutup celah ini dengan membandingkan kapan dibayar vs kapan jatuh tempo — **tetap computed-on-read, tanpa migrasi** (kedua kolom sudah ada di `installments`, konsisten prinsip §7: status/keterlambatan dihitung, bukan disimpan sebagai flag). Pesan gabungan, mis.: *"Anggota ini memiliki 2 angsuran masih nunggak dan 3 angsuran pernah dibayar telat…"*.

#### D11 — RBAC permission-based + kapasitas potong gaji (info read-only)

Mengikuti **D7 Simpanan** (gating berbasis Shield permission, bukan role hardcoded). Ability per aksi sensitif; `shield:generate` untuk CRUD standar + ability custom (`reverse`, refund/`disburse`, `export`) via Policy method + entri `RolePermissionSeeder` manual. Default assignment: create pinjaman & angsuran = Petugas+; reversal = Petugas+ (uniform, konsisten kebijakan reversal Simpanan); refund/export = Pengurus+ (pending D8).

**Kapasitas potong gaji (info read-only, keputusan pengurus):** saat create pinjaman, tampilkan ringkasan potongan rutin berjalan anggota — `Σ total_due angsuran aktif jatuh tempo` + `mandatory_savings_amount` (simpanan wajib) — sebagai **info, tidak memblokir** (verifikasi tetap manusia). Helper baca-saja; tak menyentuh kebenaran saldo.

### Alternatives Considered

| Alternative | Pro | Con | Verdict |
|-------------|-----|-----|---------|
| Kalkulator = service murni unit-tested dulu (D1) | Kebenaran finansial terkunci sebelum UI; reusable PDF/laporan | Item pertama tanpa UI terlihat | **Chosen** |
| Kalkulasi inline di Resource/observer | Cepat terlihat | Susah di-unit-test; logika tersebar; risiko #1 v5 | Rejected |
| Pokok `ceil()` ke atas, angsuran konstan (D1) | Semua bulan sama; pinjaman pasti lunas penuh; pembayaran tunai praktis | Σ pokok lebih beberapa rupiah dari principal | **Chosen** (keputusan pengurus) |
| Pokok bulat ke bawah | Σ pokok ≤ principal | Kurang bayar — pokok tak tertutup, pinjaman tak lunas penuh | Rejected |
| Sisa pembulatan disebar ke angsuran terakhir | `Σ principal_due = principal` persis | Bulan terakhir beda nominal — bukan konstan | Rejected (langgar "konstan") |
| Refund = `savings_withdrawals` + `related_loan_id` (D8) | Reuse skema yang memang dirancang utk ini; saldo D7 auto-net; reuse reversal/audit | Refund "milik" pinjaman tapi tersimpan di tabel withdrawal | **Chosen** |
| Refund = tabel `loan_refunds` baru | Konseptual murni di modul pinjaman | Duplikasi mekanisme reversal/idempotency; `related_loan_id`+enum jadi mubazir | Rejected |
| SWP/tab-berjangka balance dari tabel pinjaman (D7) | Single source; enum deposits tak perlu diubah | Service baca lintas-tabel | **Chosen** |
| Buat baris `savings_deposits` swp/tab saat cair | Seragam dgn simpanan lain | Butuh migrasi enum deposits; duplikasi data (sudah ada di loans/installments) | Rejected |
| Generalisasi engine batch utk angsuran (D6) | DRY | Risiko abstraksi prematur lintas-domain | **Open** (lihat Open Questions) |

---

## Rollout Plan

Deploy self-hosted (Laravel 12 + Filament + MySQL); rilis per-PR ke `development` → `main`. Tak ada feature-flag bertahap; "fase" di sini = urutan gating eksekusi yang aman.

| Phase | Behavior | Status |
|-------|----------|--------|
| 0 | Migrasi aditif (D0) + kalkulator (D1) ter-unit-test GREEN | Done |
| 1 | CRUD pinjaman + jadwal otomatis + Tanda Terima PDF (read-path aman) | Done |
| 2 | Pembayaran angsuran (manual + batch) + reversal | Done |
| 3 | SWP/Tab-berjangka balance + pengembalian saat lunas (setelah D8 diputuskan) | Active |

### Phase Transition Checklist

**Phase 0 → 1:** ✅ Validated 2026-06-22 (3/3, via MySQL `kopekoma_test` — 35 test GREEN, 129 assertions)
- [x] Migrasi `unique(reversal_of_id)` di `installments` jalan tanpa error; tak menyentuh kolom existing (D0). — file ada, aditif
  <!-- source: code | query: migration file exists + schema check installments -->
- [x] Unit test kalkulator GREEN termasuk **contoh 12jt/12bln = 1.090.000/bln** (jasa 78.000 + tab 12.000), pokok `ceil`, `Σ pokok ≥ principal` (D1). — `LoanCalculatorTest` pass
  <!-- source: code | query: LoanCalculatorTest pass -->
- [x] Konstanta `monthly_*` tersimpan & terkunci di `loans` saat akad (D1b). — migrasi `000002` + `LoanResourceTest` pass
  <!-- source: code -->

**Phase 1 → 2:** ✅ Validated 2026-06-22 (2/2)
- [x] Jadwal angsuran ter-generate benar: N baris (panjang) & 1 baris (Sebrakan, jasa/tab=0) (D4). — `LoanResourceTest`/`InstallmentResourceTest` pass
  <!-- source: code -->
- [x] Blacklist aktif memblokir create pinjaman baru (D9). — `LoanBlacklistResourceTest`+`LoanResourceTest` pass
  <!-- source: code -->

**Phase 2 → 3:** ✅ Validated 2026-06-22 (4/4)
- [x] Setoran angsuran tolak nominal < tagihan; saldo dari nominal aktual; upload bukti tersimpan (D5). — `LoanPaymentServiceTest`/`InstallmentResourceTest` pass
  <!-- source: code -->
- [x] Pembayaran menandai schedule `Terbayar` & auto-Lunas saat semua terbayar; **pelunasan memicu refund SWP+tab otomatis**; reversal pelunasan membalik Lunas DAN refund (D5, D8). — `LoanPaymentServiceTest` pass
  <!-- source: code -->
- [x] Double-bayar angsuran sama ditolak (idempotency + dup-check), diuji di MySQL untuk konkurensi (D5, D6). — `LoanPaymentServiceTest` pass (di bawah `lockForUpdate` MySQL)
  <!-- source: code -->
- [x] **Refund SWP+tab terbentuk otomatis & atomik saat pelunasan** (status `cair`, nominal dari D7); reversal pelunasan membatalkannya (D8). — `LoanPaymentServiceTest`/`SavingsBalanceServiceTest` pass
  <!-- source: code -->

---

## Key Items

| # | Item | Effort | Parallel? | Status |
|---|------|--------|-----------|--------|
| 0 | **Migrasi aditif** — `unique(reversal_of_id)`+`notes` di `installments`; konstanta `monthly_*` di `loans`; `disbursement_method` di `savings_withdrawals` (D0, D1b, D8) | S | — | **Done** |
| 1a | `LoanCalculator` — potongan pencairan + konstanta angsuran (pokok `ceil`, jasa/tab dari principal) + `buildSchedule()`, settings-driven, bcmath string (D1, D1b) | L | setelah 0 | **Done** |
| 1b | Generator nomor `PJM-`/`ANG-` race-safe (reuse `GeneratesTransactionNumber`) (D2) | S | setelah 0 | **Done** |
| 1c | **Unit test kalkulator** — 6 test GREEN: contoh 12jt/12bln (jasa 78.000/tab 12.000/total 1.090.000), pokok `ceil` (10jt→833.334), jangka pendek vs panjang, basis Jasa/Tab = principal (D1) | M | setelah 1a,1b | **Done** |
| 2a | `LoanResource` — pencatatan pinjaman ACC, potongan otomatis server-authoritative, upload dokumen (Media Library), blacklist guard (D9), kapasitas potong-gaji info (D11), warning riwayat bolong saat create (D10), Policy, immutable. **4 test GREEN** | L | setelah 1c | **Done** |
| 2b | Auto-generate `installment_schedules` saat akad — N baris (panjang) **& 1 baris (Sebrakan, jasa/tab=0)**, atomic dgn loan (D4). Di `CreateLoan` page | M | setelah 1c | **Done** |
| 2d | **Koreksi salah-input pinjaman** — hapus record+schedule (gated `reverse_loan` Pengurus+) hanya bila belum ada angsuran, ter-audit (D3). Aksi di `LoanResource` table+view | S | setelah 2a,2b | **Done** |
| 2c | **Tanda Terima Pinjaman PDF** (DomPDF, rincian Admin/SWP/dana diterima) + **jadwal angsuran** dalam dokumen yang sama. **Test GREEN** | M | setelah 2a,2b | **Done** |
| 3a | **Pembayaran angsuran** — `LoanPaymentService` + `InstallmentResource` (form member→pinjaman aktif→jadwal, prefill konstanta, validasi ≥ tagihan, upload bukti, reversal). **Service+UI test GREEN (5+3 test)** | L | setelah 2b | **Done** |
| 3b | **Pembayaran (pilih dari daftar pinjaman aktif)** — di-cover `InstallmentResource`: member→pinjaman aktif (dibedakan tgl+nominal)→jadwal jatuh tempo→catat (M3). Halaman quick-pay khusus opsional | M | setelah 3a | **Done (via InstallmentResource)** |
| 3c | Pembayaran angsuran via **batch potong gaji per OPD** (reuse pola engine Simpanan / generalisasi — D6) | M | setelah 3a | **Pending (deferrable)** |
| 3d | **Kuitansi angsuran PDF** (D3). Test GREEN | S | setelah 3a | **Done** |
| 4a | **Nyalakan `SavingsBalanceService`** untuk `swp` & `tabungan_berjangka` (ganti throw → komputasi dari tabel pinjaman, single-subtract C3) + `loanSwpBalance`/`timeDepositBalance`; unit test saldo (2 test GREEN) (D7) | M | setelah 3a | **Done** |
| 4b | **Pengembalian SWP + Tabungan Berjangka saat lunas** — refund OTOMATIS atomik di transaksi pelunasan (`savings_withdrawals` + `related_loan_id`, status `cair`, metode tunai/transfer); reversal pelunasan membatalkan refund (D8). Di `LoanPaymentService` (test GREEN) | M | setelah 4a | **Done** |
| 5a | `LoanBlacklistResource` — CRUD tandai + aksi lepas, Policy; guard create di `LoanResource` (D9). **2 test GREEN** | M | setelah 1c | **Done** |
| 6 | **Tunggakan/angsuran bolong** — `LoanArrearsService` (overdue runtime, warning riwayat, kapasitas potong gaji) + kolom Tunggakan di tabel + warning di form create. **GREEN; widget dashboard deferrable** | M | setelah 3a | **Done (core)** |
| 7 | RBAC final — `shield:generate` + `RolePermissionSeeder` (loan/installment/blacklist + `reverse_loan` Pengurus+, `reverse_installment` Petugas+) + matriks. **3 test GREEN** | S | setelah 2a,3a,4a,5a | **Done** |

**Effort:** S < 1 jam, M 1-3 jam, L > 3 jam, — non-code.

> **Dependency:** Item **0 & 1 (kalkulator) gerbang segalanya**; 1c harus GREEN sebelum Resource (risiko #1 v5). **Item 4b BLOCKED** sampai keputusan pengurus atas mekanisme refund (D8). **Jalur pemangkasan timeline** (Minggu 3 = "terberat"): bila mepet, tunda **2c, 3b, 3d, 6** (presentasi/UX murni, tak sentuh kebenaran finansial) ke Minggu 4 — pertahankan 0/1/2a/2b/3a/3c/4a/4b/5a/7 (core korektnes + keamanan).
>
> **⚠️ Invariant konkurensi wajib MySQL:** double-bayar angsuran sama, race generator nomor, dan dup-check batch (3c) — `lockForUpdate` no-op di SQLite; turunkan klaim "race-safe" ke "best-effort + unique backstop" sampai harness MySQL paralel ada (gap warisan modul Simpanan).

---

## Key Files

| File | Fungsi |
|------|--------|
| `database/migrations/2026_06_19_xxxxxx_add_reversal_unique_to_installments.php` | **Baru** — item 0, guard single-reversal (D0) |
| `database/migrations/2026_06_19_xxxxxx_add_installment_constants_to_loans.php` | **Baru** — item 0, kolom `monthly_principal`/`monthly_interest`/`monthly_time_deposit` (D1b) |
| `database/migrations/2026_06_19_xxxxxx_add_disbursement_method_to_savings_withdrawals.php` | **Baru** — item 0, `disbursement_method` tunai/transfer untuk refund (D8) |
| `app/Services/LoanCalculator.php` | **Baru** — 1a, kalkulator potongan + konstanta + jadwal (D1, D1b) |
| `app/Models/Concerns/GeneratesTransactionNumber.php` | Reuse — 1b, tambah format `PJM-`/`ANG-` (D2) |
| `app/Models/Loan.php`, `InstallmentSchedule.php`, `Installment.php` | Ada — tambah `Reversible`/`reverseClone()`, scope, relasi, `HasFactory`; **`Installment` + `HasMedia`/`InteractsWithMedia`** (upload bukti, D5) |
| `app/Models/LoanBlacklist.php` | Ada — 5a |
| `app/Services/SavingsBalanceService.php` | Ubah — 4a, ganti throw `swp`/`tabungan_berjangka` → komputasi tabel pinjaman (D7) |
| `app/Services/LoanPaymentService.php` (atau `InstallmentWorkflow`) | **Baru** — 3a, payment + tandai schedule + auto-Lunas + reversal (D5, D8) |
| `app/Actions/ReverseTransaction.php` + `app/Contracts/Reversible.php` | Reuse — reversal angsuran (D5) |
| `app/Filament/Resources/LoanResource.php` + `Pages/*` + `app/Policies/LoanPolicy.php` | **Baru** — 2a, pencatatan pinjaman (D3, D9, D11) |
| `app/Filament/Resources/LoanBlacklistResource.php` + `Pages/*` + Policy | **Baru** — 5a (D9) |
| `app/Filament/Pages/QuickInstallmentPayment.php` + view | **Baru** — 3b, pembayaran cepat (Dokumentasi §5.4) |
| `app/Services/BatchSalaryDeductionService.php` | Reuse/generalisasi — 3c, angsuran batch potong gaji (D6) |
| `resources/views/pdf/loan-receipt.blade.php`, `installment-schedule.blade.php`, `installment-receipt.blade.php` | **Baru** — 2c/3d (D3) |
| `database/factories/Loan*Factory.php`, `Installment*Factory.php`, `LoanBlacklistFactory.php` | **Baru** — dipakai test 1c + Resource tests |
| `database/seeders/RolePermissionSeeder.php` | Ubah — 7, tambah resource pinjaman + ability custom (D11) |
| `tests/Unit/LoanCalculatorTest.php`, `tests/Feature/{LoanResource,LoanPayment,LoanBalanceIntegration}Test.php` | **Baru** — 1c/2a/3a/4a |

---

## Verification

- [ ] Kalkulator: contoh **12jt/12bln → pokok 1.000.000 + jasa 78.000 + tab 12.000 = 1.090.000/bln**; cair = 11.760.000; lunas kembalikan SWP 120.000 + tab 144.000 (D1). <!-- source: code -->
- [ ] **Jasa = `principal × 0,65%`, Tab = `principal × 0,1%`** (basis jumlah pinjaman, BUKAN pokok) (D1). <!-- source: code -->
- [ ] **Ketiga komponen konstan** tiap bulan (pokok, jasa, tab berjangka sama dari bulan 1..n); aritmetika bcmath, bukan float (D1). <!-- source: code -->
- [ ] **Pokok = `ceil(principal/term)`** rupiah utuh; `Σ pokok ≥ principal` (lunas penuh); `remaining_principal` di-floor 0 di angsuran terakhir, tak negatif (D1). <!-- source: code -->
- [ ] Jangka Pendek: tanpa potongan, `disbursed = principal`, `term = 1`, **tanpa jadwal** (D1, D4). <!-- source: code -->
- [ ] Potongan pencairan dihitung **server** (input client di-override); plafon jenis divalidasi (D3). <!-- source: code -->
- [ ] Jadwal jangka panjang ter-generate atomik bersama loan; gagal → rollback keduanya (D4). <!-- source: code -->
- [ ] **Konstanta angsuran tersimpan & terkunci di `loans`** saat akad (`monthly_*`); = snapshot tagihan (D1b). <!-- source: code -->
- [ ] **Setoran angsuran ditolak bila nominal < konstanta tagihan**; sama/lebih diterima; bolong = tak ada baris (anti-korupsi, D5). <!-- source: code -->
- [ ] **Saldo SWP/tab & laporan dihitung dari nominal AKTUAL** (`installments.*_paid`), bukan tagihan teoretis → uang sistem = uang nyata (D5, D7). <!-- source: code -->
- [ ] **Upload bukti pembayaran** tersimpan via Media Library & tampil di view angsuran/audit (D5). <!-- source: code -->
- [ ] Pembayaran menandai schedule `Terbayar` + set `remaining_principal` dari aktual; auto-Lunas saat semua terbayar (D5, D8). <!-- source: code -->
- [ ] Reversal pembayaran membalik schedule ke `Belum Bayar` + batalkan Lunas; tak bisa reverse-of-reverse (D0, D5). <!-- source: code -->
- [ ] **Double-bayar angsuran sama ditolak** (idempotency + dup-check); konkurensi diuji di MySQL (D5, D6). <!-- source: code -->
- [ ] Saldo `swp` = Σ `loans.swp_amount` − refund cair; `tabungan_berjangka` = Σ `installments.time_deposit_saved` − refund; masuk `allBalances`/`totalBalance` (D7). <!-- source: code -->
- [ ] Pengembalian saat lunas = `savings_withdrawals` bertaut `related_loan_id`, type benar, nominal = saldo akumulasi, **metode tunai/transfer tercatat** (D8). <!-- source: code -->
- [ ] Refund hanya lahir dari transisi Cair→Lunas (ditolak bila sudah Lunas); reversal pelunasan membatalkan refund — tak ada dobel/yatim (D8). <!-- source: code -->
- [ ] Blacklist aktif memblokir create pinjaman (guard server, bukan hanya UI) (D9). <!-- source: code -->
- [ ] Tunggakan terdeteksi runtime (`due_date < today` & `Belum Bayar`); tanpa denda/sanksi/pemutusan otomatis (D10). <!-- source: code -->
- [ ] **Warning riwayat angsuran bolong tampil saat create pinjaman baru** untuk anggota yg pernah keluar tenor; advisory, tidak memblokir (D10). <!-- source: code -->
- [ ] Indikator angsuran terlewat juga tampil di detail anggota, daftar pinjaman, & pembayaran cepat (D10). <!-- source: code -->
- [ ] Kapasitas potong-gaji tampil sbg info read-only saat create; tidak memblokir (D11). <!-- source: code -->
- [ ] Gating permission-based (Shield); create=Petugas+, reversal=Petugas+, refund/export=Pengurus+ (D11). <!-- source: code -->
- [ ] Tanda Terima Pinjaman & kuitansi angsuran PDF ter-render dengan rincian benar (D3). <!-- source: code -->
- [ ] Seluruh transaksi & reversal ter-log `activity_log` dengan causer (konvensi). <!-- source: code -->

---

## Open Questions

- ✅ **(D8) Mekanisme pengembalian SWP + Tabungan Berjangka** — RESOLVED (pengurus 2026-06-19): refund **otomatis & atomik di transaksi pelunasan** (pembayaran terakhir yang membuat Lunas), status `cair`, nominal dari D7. Reversal pelunasan membatalkan refund (tutup M2). Item 4b tak lagi blocked. **Sisa kecil utk security pass:** apakah pelunasan perlu approval Pengurus (refund = uang keluar, tapi nominal deterministik) — default: tanpa approval, ter-audit.
- ✅ **(M3) Multi-pinjaman saat bayar** — RESOLVED (pengurus 2026-06-19): banyaknya pinjaman aktif = urusan ACC di luar sistem; sistem tak membatasi. **Saat bayar, petugas tinggal pilih dari daftar pinjaman aktif anggota** (dibedakan dari **tanggal pencairan + nominal pinjaman**). Pembayaran (3a) & pembayaran cepat (3b) menampilkan list pinjaman aktif + jatuh tempo → pilih satu. Batch potong gaji (3c) = baris per **(anggota, pinjaman aktif)**.
- **(D6) Engine batch angsuran** — generalisasi `BatchSalaryDeductionService` lintas-domain vs service terpisah? (detail teknis, putuskan saat eksekusi 3c — hindari abstraksi prematur).
- **(D5) Bukti pembayaran wajib atau opsional?** — wajib semua, atau wajib utk `manual` & opsional utk `potong_gaji` batch (ratusan baris sekaligus susah lampirkan satu-satu)? Default sementara: wajib utk manual, opsional utk batch.
- **Cara hitung angsuran** — RESOLVED (pengurus 2026-06-19): ketiga komponen **konstan tiap bulan**; **Jasa = `principal × 0,65%`, Tab = `principal × 0,1%`** (basis jumlah pinjaman, BUKAN pokok — koreksi atas Dokumentasi §4.6 lama yang keliru, sudah diperbaiki); **Pokok = `ceil(principal/term)`** (bulat ke atas, rupiah utuh). Kelebihan receh pada Σ pokok diterima (urusan pembayaran tunai di luar sistem).
- **Jangka Pendek lewat jatuh tempo** — RESOLVED (pengurus 2026-06-19): Sebrakan bisa bolong (kasus sosial) → diberi 1 baris schedule (D4) agar masuk deteksi tunggakan runtime & warning riwayat (D10). Tanpa eskalasi/sanksi otomatis.
- **Plafon dinamis** — apakah plafon Rp 1.000.000 (batas jangka pendek/panjang) perlu dari `CooperativeSettings` (settings-driven, seperti nominal simpanan D11 Simpanan) atau cukup konstanta? Default sementara: konstanta, mudah dipindah ke settings.

---

## Pipeline trace (v1)

| Stage | Agent | Key output | Date |
|---|---|---|---|
| Framing | strategist | (retroactive — not invoked) framing inline dari sesi: scope dikunci ke skema migrasi existing; "fondasi kalkulator dulu" mengikuti pola modul Simpanan | 2026-06-19 |
| Data baseline | data-analyst | skipped: greenfield, belum ada pinjaman/angsuran produksi untuk baseline | 2026-06-19 |
| Design | architect | (retroactive — not invoked) desain inline dari storytelling pengurus + alignment Dokumentasi §4.5–4.6 + eksplorasi kode: pembulatan sisa-di-terakhir, refund via savings_withdrawals+related_loan_id, nyalakan SavingsBalanceService swp/tab-berjangka | 2026-06-19 |
| Critique | critic | **invoked inline (bukan subagent)** — verdict **REVISE**: 4 CRITICAL (C1 rumus 12x; C2 konflik konstan vs Σ≥principal vs floor-0; C3 double-count saldo SWP/tab; C4 Sebrakan lolos deteksi tunggakan), 4 MAJOR (M1 koreksi salah-input; M2 reversal pasca-refund; M3 batch angsuran multi-pinjaman; M4 coupling 4a→D8). **Semua (C1–C4, M1–M4) ditutup** (D8 refund atomik saat pelunasan; M3 = pilih dari daftar pinjaman aktif). Sisa: security + deploy review | 2026-06-19 |
| Security review | security-reviewer | **pending — DIREKOMENDASIKAN** (akses data finansial anggota, aksi uang-keluar refund, integritas batch potong-gaji, blacklist) | 2026-06-19 |
| Deploy review | deploy-reviewer | **pending — WAJIB** (migrasi aditif pada tabel finansial `installments`, D0) | 2026-06-19 |
| Implementation | implementer (Claude) | **DONE (core)** — migrasi+kalkulator+payment service+balance (services, 18 test) → LoanResource/InstallmentResource/LoanBlacklistResource + PDF + RBAC seeder/matrix. **222 test GREEN di MySQL, pint clean.** Sisa: 3c batch angsuran, widget dashboard | 2026-06-20 |
| Review | reviewer | pending — security + deploy review belum dijalankan | 2026-06-20 |

**Ronde**: 2 (v1 draft → critique inline REVISE → v4–v9 ditindaklanjuti: **semua temuan C1–C4 & M1–M4 ditutup**. Sisa sebelum Approved: security + deploy review).
**Skipped stages**: data-analyst (greenfield, no prod data). security-reviewer/deploy-reviewer **TIDAK di-skip** — wajib sebelum eksekusi (financial core + migrasi).
**Calibration notes**: critique inline menemukan bug desain nyata yang lolos dari draft awal — C2 (tiga aturan pembulatan saling tabrakan) & C3 (double-count saldo) adalah inkonsistensi yang dibuat saat menyusun D1/D7 tanpa adversarial pass. Pelajaran: ADR finansial wajib critic sebelum dianggap matang.

---

## Changelog

- **2026-06-20 v10 (EKSEKUSI — modul terimplementasi)**: Implementasi penuh inti + UI. **Backend (18 test):** 3 migrasi aditif; `LoanCalculator` (settings-driven, ceil pokok, jasa/tab dari principal); model+factory (Loan/Schedule/Installment/Blacklist, Reversible, media bukti, nomor PJM-/ANG-); `SavingsBalanceService` swp/tabungan_berjangka (single-subtract dari aktual); `LoanPaymentService` (validasi ≥ tagihan anti-korupsi, auto-Lunas, refund tunai/transfer atomik, reversal-batalkan-refund/M2); `LoanArrearsService` (tunggakan/warning/kapasitas). **UI (13 test):** `LoanResource` (potongan server-side, jadwal otomatis atomik, guard blacklist, warning riwayat, kapasitas, upload dokumen, koreksi salah-input/2d, Tanda Terima PDF) + `InstallmentResource` (form member→pinjaman aktif→jadwal, prefill, validasi, bukti, reversal, kuitansi PDF) + `LoanBlacklistResource` (tandai/lepas) + `RolePermissionSeeder` (loan/installment/blacklist + `reverse_loan` Pengurus+, `reverse_installment` Petugas+) + matriks RBAC. **Total 222 test GREEN (845 assertion) di MySQL, pint clean, tanpa regresi.** Sisa (deferrable): 3c batch potong gaji angsuran, widget dashboard, security+deploy review.
- **2026-06-19 v9 (tutup M3 → semua temuan critique selesai)**: Pengurus konfirmasi multi-pinjaman = urusan ACC di luar sistem; **saat bayar, petugas pilih dari daftar pinjaman aktif** (dibedakan tanggal pencairan + nominal). Pembayaran cepat (3b) menampilkan list pinjaman aktif → pilih. **M3 RESOLVED.** Dengan ini **seluruh temuan critique (C1–C4, M1–M4) tertutup**; sisa hanya security + deploy review sebelum Status → Accepted.
- **2026-06-19 v8 (D8 refund diputuskan → tutup M2 & M4)**: Pengurus putuskan mekanisme pengembalian SWP + Tabungan Berjangka. **(D8)** refund **otomatis & atomik di transaksi pelunasan** (pembayaran terakhir yang membuat loan `Lunas`), status `cair`, nominal dari D7 (SWP=`loans.swp_amount`, tab=`Σ installments.time_deposit_saved` aktual). **Metode pengembalian = tunai / transfer** → kolom aditif `disbursement_method` di `savings_withdrawals` (migrasi ke-3, item 0). **(M2 RESOLVED)** reversal pembayaran pelunasan membalik Lunas→Cair **dan** membatalkan refund (reversal berantai, tak ada refund yatim). **(M4 RESOLVED)** bentuk refund pasti → item **4b tak lagi Blocked**. Anti-dobel-refund via guard transisi Cair→Lunas (bukan unique-constraint). Item 0/4b, Key Files, Verification, Open Questions, pipeline trace disesuaikan. **Sisa sebelum Approved:** security review (refund cair tanpa approval — nominal deterministik) + deploy review (3 migrasi finansial).
- **2026-06-19 v7 (klarifikasi multi-pinjaman)**: Pengurus konfirmasi anggota **boleh punya >1 pinjaman aktif** (approval di luar sistem; pinjaman yang sudah tercatat = sudah diizinkan). Sistem **tidak** membatasi jumlah pinjaman aktif (masuk Non-Goals). Konsekuensi pada M3: pembayaran (3a) & pembayaran cepat (3b) harus menampilkan **daftar pinjaman aktif** → petugas pilih yang dibayar; batch (3c) tak bisa asumsi 1 baris = 1 pinjaman. M3 tetap open (detail saat eksekusi 3a–3c), tapi arah jelas.
- **2026-06-19 v6 (C1 verified + upload bukti angsuran)**: **(C1 RESOLVED)** rumus Jasa/Tab = `principal × 0,65%`/`0,1%` **dikonfirmasi pengurus** (sudah ditanya ke sumber otoritatif) — semua temuan CRITICAL kini tertutup. **(D5 + baru)** tiap setoran angsuran **upload bukti pembayaran** via Media Library (tabel `media` polymorphic sudah ada → tanpa migrasi; `Installment` tambah `HasMedia`/`InteractsWithMedia`) — kontrol detektif pendamping validasi `≥ tagihan`. Open Question baru: bukti wajib (manual) vs opsional (batch). Item 3a, Key Files, Verification disesuaikan.
- **2026-06-19 v5 (anti-korupsi + tutup C2/C3)**: Keputusan pengurus memperkuat integritas keuangan. **(D1b baru)** Konstanta angsuran (`monthly_principal`/`monthly_interest`/`monthly_time_deposit`) **disimpan & dikunci di tabel `loans`** saat akad (migrasi aditif, item 0) = kartu tagihan resmi. **(D5 dirombak)** Saat setor angsuran, **petugas input nominal aktual tiap item, divalidasi server `≥ konstanta tagihan`** (tak boleh kurang → cegah korupsi petugas); **saldo & laporan dihitung dari nominal AKTUAL (`installments.*_paid`), bukan tagihan teoretis** → uang di sistem = uang nyata. **(C2 RESOLVED)** semua bulan konstan, kelebihan receh dari pembulatan pokok ke atas diterima; `remaining_principal = max(0, principal − Σ principal_paid)` (klaim "floor 0 di angsuran terakhir" yg bertabrakan dicabut). **(C3 RESOLVED)** saldo SWP/tab = akumulasi − refund (SATU pengurangan; hapus double-count filter "belum di-refund"). Item 0/1a/3a, Key Files, Verification disesuaikan. **Masih open:** C1 (verifikasi rumus ke artefak nyata), M2 (reversal pasca-refund), M3 (batch angsuran multi-pinjaman), M4 (4a↔D8 coupling).
- **2026-06-19 v4 (tindak lanjut critique inline)**: Verdict critique = REVISE. Dua temuan ditutup pengurus: **(C4)** Sebrakan **bisa bolong** (kasus sosial) → diberi **1 baris `installment_schedules`** (jasa/tab=0) agar masuk deteksi tunggakan & warning riwayat (D4, D10, item 2b). **(M1)** Tidak ada "pembatalan pinjaman" (pinjaman hanya masuk pasca-ACC), TAPI koreksi salah-input tetap perlu → **reversal seluruh record pinjaman hanya bila belum ada angsuran terbayar**, Pengurus+, audit (D3, item 2d baru). **Masih terbuka (belum ditindaklanjuti):** C1 (verifikasi rumus 12x ke artefak nyata), C2 (aturan angsuran terakhir: konstan vs Σ≥principal vs remaining_principal floor 0 saling tabrakan), C3 (double-count saldo SWP/tab D7), M2 (reversal pembayaran setelah refund), M3 (kompleksitas batch angsuran multi-pinjaman), M4 (4a coupling ke D8).
- **2026-06-19 v3**: **Koreksi rumus finansial besar (12x)** + warning tunggakan. (1) **Jasa = `principal × 0,65%`, Tab Berjangka = `principal × 0,1%`** — basis **jumlah pinjaman diajukan**, BUKAN pokok per bulan (Dokumentasi §4.6 lama keliru: "Pokok × 0,65%" → contoh 6.500). Contoh 12jt/12bln yang benar: jasa 78.000 + tab 12.000 → **total angsuran 1.090.000/bln** (bukan 1.007.500); refund saat lunas = SWP 120.000 + tab 144.000. **Dokumen [`Dokumentasi_Sistem_Koperasi_v5.md`](../Dokumentasi_Sistem_Koperasi_v5.md) §4.6 ikut diperbaiki** (rumus + contoh). (2) **Pokok dibulatkan ke ATAS ke rupiah utuh** (`ceil`, hanya komponen pokok yg ada pembagian); `Σ pokok ≥ principal` → lunas penuh; `remaining_principal` floor 0. (3) **Tunggakan/bolong tanpa sanksi**, tapi ada **WARNING riwayat saat anggota mengajukan pinjaman baru** (track-record bolong di pinjaman sebelumnya muncul di `LoanResource` create; advisory, tidak memblokir) (D10, item 2a/6). D1/D10, Alternatives, Verification, Open Questions, Key Items disesuaikan.
- **2026-06-19 v2**: Koreksi D1 — pengurus konfirmasi **ketiga komponen angsuran KONSTAN tiap bulan**. (Disempurnakan lagi di v3: basis Jasa/Tab + pembulatan pokok ke atas.)
- **2026-06-19 v1**: Initial draft — modul Pinjaman & Angsuran Minggu 3. Disusun dari storytelling pengurus (jenis pinjaman, potongan, kalkulator angsuran, pengembalian saat lunas), di-align dengan Dokumentasi Sistem v5 §4.5–4.6 & §5.3–5.4 dan skema migrasi existing. Temuan kunci: refund SWP/Tabungan Berjangka memakai `savings_withdrawals` + `related_loan_id` (skema sudah dirancang utk ini); `SavingsBalanceService` swp/tabungan_berjangka yang dititipkan modul Simpanan dinyalakan di sini. Mekanisme pemicu refund (D8) ditunda pengurus → item 4b blocked.
