# Design — Perbaikan Pinjaman, Pencairan Simpanan & Angsuran

**Tanggal:** 2026-06-27
**Branch:** feat/loan-management
**Status:** Revisi 2 (pasca self-critique) — menunggu review

## Konteks

Lima permintaan perbaikan untuk modul Pinjaman, Pencairan Simpanan, dan Angsuran.
Penelusuran kode menunjukkan sebagian fondasi sudah ada; spec ini hanya mencakup
**delta nyata**. Revisi 2 mengoreksi tiga klaim yang ternyata keliru di revisi 1
(lihat Lampiran: Koreksi).

Ground-truth (terverifikasi):

| Permintaan | Kondisi kode | Delta nyata |
|---|---|---|
| (1) Pencairan SWP & Tab Berjangka + auto saat lunas | Auto-refund SWP **dan** Tab **sudah jalan** (`LoanPaymentService::createRefunds`), tapi langsung `status='cair'`; create manual diblok | Auto-create → draft; buka whitelist manual + validasi saldo yang sadar draft; tampil 1 entri logis |
| (2) Jenis pencairan tunai/transfer di Pinjaman | Belum ada kolom | Tambah `disbursement_method` di `loans` + form/tabel/infolist |
| (3) Jenis pencairan tunai/transfer di Pencairan Simpanan | Kolom DB ada (migration 2026-06-19), **TAPI belum tampil** di form/tabel/infolist (0 referensi di Resource) | Tambahkan field ke form, tabel, infolist |
| (4) Rincian angsuran (pokok, swp, dll) | `Installment::breakdown()` **sudah hitung** principal/interest/time_deposit/other/total | Tampilkan di infolist & kuitansi |
| (5) "lain-lain" → label baru | breakdown punya key `other` = **kelebihan bayar arbitrer**, bukan pembulatan (validasi hanya larang bayar < tagihan) | Lihat D7 — keputusan terbuka |

## Keputusan Desain

- **D1 — Lifecycle pencairan SWP/Tab mengikuti mesin 4-state yang SUDAH ada.**
  `draft →(approve)→ acc →(disburse)→ cair`, lewat `runTransition`
  ([SavingsWithdrawalResource:366](../../../app/Filament/Resources/SavingsWithdrawalResource.php#L366)).
  Frasa "draft → cair" dari diskusi awal adalah penyederhanaan; SWP/Tab TIDAK
  diberi jalur 1-langkah khusus agar konsisten dengan semua pencairan lain.
  Happy-path tidak memakai `reject`; `reject`/`reverse` hanya dipakai sistem (D4).
- **D2 — Tampil 1 entri, 2 record di DB (Opsi B).** Di DB tetap dua record
  (`swp` + `tabungan_berjangka`) terhubung `related_loan_id`, agar rekonsiliasi
  saldo per-tipe `SavingsBalanceService` tidak tersentuh. Di UI, pasangan
  auto-refund ditampilkan sebagai satu entri "Pengembalian Pelunasan" dan
  diproses lewat **satu aksi** yang menjalankan `runTransition` pada KEDUA record
  di tiap langkah (approve keduanya, lalu disburse keduanya), atomik dalam satu
  transaksi. Opsi A (1 record + kolom rincian) ditolak: mengubah engine saldo =
  risiko tertinggi di atas data finansial; tak sepadan dengan keuntungan kosmetik.
- **D3 — Saldo tersedia harus sadar pending.** `withdrawalNet` hanya hitung
  `cair`, jadi draft/acc TIDAK mengurangi saldo. Untuk mencegah refund dobel
  (lihat H3), `availableBalance()` untuk tipe `swp`/`tabungan_berjangka` harus
  mengurangi pula nominal pencairan **pending** (`draft` + `acc`) bertipe sama
  milik anggota itu, sebelum memvalidasi create manual.
- **D4 — Reversal pelunasan memakai `reject`/`reverse`, BUKAN delete.** Bila
  angsuran terakhir di-reverse (loan Lunas → Cair): refund terkait yang masih
  `draft`/`acc` di-`reject` (→ `ditolak`); yang sudah `cair` di-`reverse` (mekanisme
  reversal generik yang sudah ada). Tidak ada hard-delete dokumen bernomor —
  jejak audit & `withdrawal_number` dipertahankan. (Ini sengaja memakai status
  `ditolak`/reversal hanya untuk aksi sistem; happy-path tetap draft→acc→cair.)
- **D5 — Idempotensi auto-create.** `createRefunds` hanya membuat refund baru bila
  belum ada refund non-reversal ber-`related_loan_id` & bertipe sama berstatus
  `draft`/`acc`/`cair`. Mencegah duplikasi saat bayar → lunas → reverse → bayar lagi.
- **D6 — Tanpa kolom breakdown baru.** Rincian angsuran diderive dari konstanta
  `monthly_*` (hormati ADR 2026-06-26). Tidak ada kolom DB rincian.
- **D7 — [TERBUKA] Label pos `other` pada kuitansi.** `other` = kelebihan bayar
  arbitrer (bisa besar), bukan sekadar pembulatan. Rekomendasi: gunakan
  **"Kelebihan Bayar"** (akurat). Bila tetap ingin "Penyesuaian Pembulatan",
  itu hanya jujur jika input kelebihan dibatasi sebatas selisih pembulatan
  (mis. cap < Rp1.000) — perubahan ini menyentuh validasi pembayaran (lebih
  berisiko). **Default spec: "Kelebihan Bayar"; konfirmasi saat review.**
- **D8 — Gating Shield.** Aksi "Setujui Pengembalian" gabungan dan create manual
  SWP/Tab harus di-gating permission Shield (konvensi proyek: gating via
  permission, bukan role hardcoded). Reuse permission existing pencairan bila
  cakupannya sama; tambah permission baru hanya bila perlu pembedaan.

## Cakupan per Poin

### Poin 1 — Pencairan SWP & Tab Berjangka + auto saat lunas

**1a. Auto-create jadi draft (idempoten).**
- `LoanPaymentService::makeRefund` ([:148](../../../app/Services/LoanPaymentService.php#L148)):
  `'status' => 'draft'`, buang `'disbursed_at' => now()`.
- `createRefunds` ([:135](../../../app/Services/LoanPaymentService.php#L135)): guard
  idempotensi D5 sebelum membuat tiap tipe.

**1b. Reversal pelunasan (D4).**
- `LoanPaymentService::reverse` ([:110](../../../app/Services/LoanPaymentService.php#L110)):
  ganti `reverseRefunds` menjadi: untuk refund ber-`related_loan_id` ini —
  `draft`/`acc` → `reject`; `cair` → `reverse`. Tetap atomik.

**1c. Create manual + validasi saldo sadar-pending.**
- `SavingsWithdrawalResource::WITHDRAWAL_TYPES` ([:52](../../../app/Filament/Resources/SavingsWithdrawalResource.php#L52)):
  tambah `'swp'` & `'tabungan_berjangka'`.
- `availableBalance()` ([:88](../../../app/Filament/Resources/SavingsWithdrawalResource.php#L88)):
  `swp` → `loanSwpBalance`, `tabungan_berjangka` → `timeDepositBalance`,
  lalu kurangi pending (`draft`+`acc`) sesuai D3.

**1d. Tampil 1 entri + aksi gabungan (D2).**
- Pasangan auto-refund (sama `related_loan_id`, tipe swp+tab) ditampilkan sebagai
  satu entri "Pengembalian Pelunasan PJM-xxx", total = swp + tab. Pencairan manual
  single-type tetap tampil apa adanya — pembeda: keberadaan `related_loan_id`
  berpasangan. (Mekanisme tampilan: detail di plan; bukan row-merge native
  Filament melainkan penyajian berbasis `related_loan_id`.)
- Aksi "Setujui Pengembalian" & "Cairkan Pengembalian" menjalankan transisi pada
  kedua record (D1/D2), gated Shield (D8).

### Poin 2 — Jenis pencairan tunai/transfer di Pinjaman
- Migration: `enum('disbursement_method', ['tunai','transfer'])->nullable()` di `loans`.
- `Loan::$fillable` + cast bila perlu.
- `LoanResource`: Select di form; kolom tabel & infolist menampilkan label, dengan
  fallback "—" untuk pinjaman lama yang null (M4).

### Poin 3 — Jenis pencairan tunai/transfer di Pencairan Simpanan
- Kolom DB sudah ada; **field belum tampil**. Tambahkan ke form (Select
  tunai/transfer), kolom tabel, dan infolist `SavingsWithdrawalResource`.

### Poin 4 — Rincian angsuran
- Gunakan `Installment::breakdown()` ([app/Models/Installment.php:93](../../../app/Models/Installment.php#L93)).
- Tampilkan grid TextEntry di infolist & kuitansi: Pokok, Bunga, Tabungan
  Berjangka, <label D7>, Total. Tanpa kolom DB baru.

### Poin 5 — Label pos `other`
- Ganti label key `other` sesuai keputusan D7 (default "Kelebihan Bayar") di
  semua tampilan (kuitansi + infolist), konsisten dengan grid Poin 4.

## Yang Sengaja TIDAK Dikerjakan (YAGNI)
- Tidak menyimpan breakdown ke kolom DB.
- Tidak mengubah engine `SavingsBalanceService` (Opsi A ditolak).
- Tidak membuat jalur status 1-langkah khusus SWP/Tab.
- Tidak hard-delete dokumen pencairan.

## Sekuens Implementasi (S1 — pisah agar quick-win tak tersandera)
- **Track A (kecil, low-risk):** Poin 2, Poin 3, Poin 4, Poin 5. Tidak menyentuh
  alur uang; bisa jalan & merge duluan.
- **Track B (kompleks):** Poin 1 (a–d) — state machine, grouping, akuntansi
  pending, reversal. Plan & test tersendiri.

## Testing (Pest)
**Track A**
- `Loan.disbursement_method` tersimpan & tampil; null → "—".
- Field disbursement_method pencairan simpanan tampil & tersimpan.
- `breakdown()` render: <label D7> = amount_paid − (pokok+bunga+tab), floor 0;
  saat bayar pas, pos = 0.

**Track B**
- Lunas → 2 refund **draft** ter-create, `related_loan_id` terisi, saldo belum berubah.
- Idempotensi (D5): bayar→lunas→reverse→bayar lagi tidak menghasilkan refund dobel.
- Setujui Pengembalian → kedua record naik status bersama; cairkan → keduanya `cair`,
  saldo SWP & Tab berkurang tepat.
- Reverse angsuran terakhir saat refund masih draft/acc → keduanya `ditolak`, loan `Cair`.
- Reverse saat refund sudah `cair` → reversal ter-create.
- Create manual SWP/Tab: validasi menolak nominal melebihi (saldo − pending) (D3/H3).
- Regresi: `loanSwpBalance` & `timeDepositBalance` tetap konsisten.

## Risiko
- **Alur lunas berubah** (cair → draft) — uji end-to-end + reversal.
- **Refund dobel** bila D3 (validasi sadar-pending) tidak diterapkan benar — test eksplisit.
- **UI grouping** custom — terisolasi, tidak menyentuh uang.

## Lampiran: Koreksi atas Revisi 1
1. **Status machine**: bukan draft→cair (1 langkah), melainkan draft→acc→cair
   (2 langkah) + reject/reverse. Memengaruhi D1, D2.
2. **Poin 3**: bukan "sudah selesai" — kolom ada tapi belum tampil di UI sama sekali.
3. **Pos `other`**: bukan residu pembulatan, melainkan kelebihan bayar arbitrer
   (validasi hanya larang kurang). Memengaruhi penamaan Poin 5 (D7).
