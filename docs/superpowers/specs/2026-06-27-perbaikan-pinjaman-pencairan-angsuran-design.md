# Design — Perbaikan Pinjaman, Pencairan Simpanan & Angsuran

**Tanggal:** 2026-06-27
**Branch:** feat/loan-management
**Status:** Disetujui (menunggu review spec)

## Konteks

Lima permintaan perbaikan diajukan untuk modul Pinjaman, Pencairan Simpanan, dan
Angsuran. Setelah penelusuran kode, sebagian besar fondasi ternyata sudah ada —
spec ini hanya mencakup **delta nyata**, bukan membangun ulang.

Temuan ground-truth vs permintaan:

| Permintaan | Kondisi kode saat ini | Delta nyata |
|---|---|---|
| (1) Pencairan SWP & Tab Berjangka + auto saat lunas | Auto-refund SWP **dan** Tab Berjangka **sudah jalan** di `LoanPaymentService::createRefunds` saat angsuran terakhir → loan `Lunas`, tapi dibuat langsung `status='cair'` & create manual diblok | Ubah auto-create → `draft`; buka whitelist manual + validasi saldo; tampilkan gabungan di UI |
| (2) Jenis pencairan tunai/transfer di Pinjaman | Belum ada kolom | Tambah `disbursement_method` di `loans` + form/tabel |
| (3) Jenis pencairan tunai/transfer di Pencairan Simpanan | **Sudah ada** (migration 2026-06-19) | Verifikasi tampil di form/tabel |
| (4) Rincian angsuran (pokok, swp, dll) | `Installment::breakdown()` **sudah hitung** pokok/bunga/tab/other/total | Tampilkan di infolist & kuitansi |
| (5) "lain-lain" → "Penyesuaian Pembulatan" | breakdown punya key `other` (sudah floor ≥ 0 = murni residu pembulatan) | Rename label di tampilan |

## Keputusan Desain

- **D1 — Lifecycle pencairan SWP/Tab = draft → cair saja.** Tidak ada status
  tolak/reverse untuk pencairan jenis ini. Auto-create menghasilkan draft;
  pengurus menyetujui jadi cair.
- **D2 — Gabung 2 jenis ditampilkan sebagai 1 (Opsi B).** Di DB tetap **2 record**
  (`swp` + `tabungan_berjangka`) agar rekonsiliasi saldo per-tipe di
  `SavingsBalanceService` tidak tersentuh. Di UI menu Pencairan, record yang
  terhubung lewat `related_loan_id` ditampilkan & disetujui sebagai **1 entri
  logis** "Pengembalian Pelunasan". Alasan menolak Opsi A (1 record + kolom
  rincian): mengubah cara engine saldo menghitung pengurang = perubahan paling
  berisiko di atas data finansial; tidak sepadan dengan keuntungan kosmetik.
- **D3 — Saldo draft tidak dikurangi.** `withdrawalNet` hanya menghitung
  `status='cair'`. Konsekuensi yang diterima: setelah loan lunas tapi draft belum
  disetujui, saldo SWP/Tab anggota masih tampak penuh; gate persetujuan manusia
  yang mencegah refund dobel.
- **D4 — Cleanup draft yatim, bukan reverse.** Bila angsuran terakhir di-reverse
  (loan Lunas → Cair) sementara draft refund masih draft (belum cair, belum
  kurangi saldo), draft itu **dihapus** (cleanup), bukan dibuat reversal. Bila
  draft sudah terlanjur cair lalu angsuran di-reverse: kasus langka, dibiarkan
  untuk penanganan manual pengurus.
- **D5 — Tanpa kolom breakdown baru.** Rincian angsuran tetap diderive dari
  konstanta `monthly_*` di `loans` (hormati ADR 2026-06-26 "input nominal
  tunggal"). Tidak ada kolom DB baru untuk rincian.

## Cakupan per Poin

### Poin 1 — Pencairan SWP & Tab Berjangka + auto saat lunas

**a. Auto-create jadi draft.**
- `LoanPaymentService::makeRefund` ([app/Services/LoanPaymentService.php:148](../../../app/Services/LoanPaymentService.php#L148)):
  ubah `'status' => 'cair'` menjadi `'draft'`, hapus `'disbursed_at' => now()`.
- `createRefunds` tetap membuat dua record (swp + tab berjangka) terhubung
  `related_loan_id`, masing-masing draft.

**b. Cleanup draft saat reversal.**
- `LoanPaymentService::reverse` ([:110](../../../app/Services/LoanPaymentService.php#L110)):
  ganti `reverseRefunds` → cleanup. Saat loan `Lunas` → `Cair`, hapus
  (delete) record refund ber-`related_loan_id` ini yang masih `status='draft'`.
  Yang sudah `cair` dibiarkan (penanganan manual).

**c. Buka create manual + validasi saldo.**
- `SavingsWithdrawalResource::WITHDRAWAL_TYPES` ([app/Filament/Resources/SavingsWithdrawalResource.php:52](../../../app/Filament/Resources/SavingsWithdrawalResource.php#L52)):
  tambah `'swp' => 'SWP'` dan `'tabungan_berjangka' => 'Tabungan Berjangka'`.
- `availableBalance()` ([:88](../../../app/Filament/Resources/SavingsWithdrawalResource.php#L88)):
  sambungkan tipe `swp` → `SavingsBalanceService::loanSwpBalance`, tipe
  `tabungan_berjangka` → `timeDepositBalance` (method sudah ada).

**d. Tampil gabungan di UI (Opsi B).**
- Di tabel/infolist menu Pencairan, record yang punya `related_loan_id` dan
  bertipe swp/tabungan_berjangka dikelompokkan sebagai 1 entri "Pengembalian
  Pelunasan PJM-xxx" dengan total = swp + tab.
- Aksi approve gabungan: menyetujui satu entri mengubah **kedua** record
  draft → cair sekaligus (atomik, dalam transaksi).

### Poin 2 — Jenis pencairan tunai/transfer di Pinjaman

- Migration baru: `enum('disbursement_method', ['tunai','transfer'])->nullable()`
  di tabel `loans` (mirror pola migration 2026-06-19 untuk savings_withdrawals).
- `Loan::$fillable`: tambah `disbursement_method`.
- `LoanResource`: tambah Select di form + kolom di tabel & infolist.

### Poin 3 — Jenis pencairan tunai/transfer di Pencairan Simpanan

- Sudah ada kolom (`disbursement_method` enum tunai/transfer, migration 2026-06-19).
- Verifikasi field tampil di form, tabel, dan infolist `SavingsWithdrawalResource`;
  lengkapi bila ada yang belum.

### Poin 4 — Rincian angsuran

- Gunakan `Installment::breakdown()` ([app/Models/Installment.php:93](../../../app/Models/Installment.php#L93))
  yang sudah mengembalikan `principal`, `interest`, `time_deposit`, `other`, `total`.
- Tampilkan sebagai grid TextEntry di infolist angsuran & kuitansi pembayaran:
  Pokok, Bunga, Tabungan Berjangka, Penyesuaian Pembulatan, Total.
- Tanpa kolom DB baru.

### Poin 5 — "lain-lain" → "Penyesuaian Pembulatan"

- Pada semua tampilan yang memakai key `other` (kuitansi + infolist angsuran),
  label diganti dari "Lain-lain" menjadi **"Penyesuaian Pembulatan"**.

## Yang Sengaja TIDAK Dikerjakan (YAGNI)

- Tidak menyimpan breakdown ke kolom DB (derive dari konstanta `monthly_*`).
- Tidak mengubah engine rekonsiliasi `SavingsBalanceService` (Opsi A ditolak).
- Tidak menambah status tolak/reverse untuk pencairan SWP/Tab.
- Tidak menyentuh poin 3 di luar verifikasi tampilan.

## Testing

- **Unit/Feature (Pest):**
  - Pelunasan angsuran terakhir → 2 record draft (swp + tab) ter-create,
    `related_loan_id` terisi, saldo belum berubah (masih draft).
  - Approve gabungan → kedua record jadi cair, saldo SWP & Tab berkurang sesuai.
  - Reverse angsuran terakhir saat draft masih draft → draft terhapus, loan Cair.
  - Create manual SWP/Tab: validasi saldo menolak nominal melebihi saldo.
  - `disbursement_method` Loan tersimpan & tampil.
  - `breakdown()` menampilkan Penyesuaian Pembulatan = amount_paid − (pokok+bunga+tab), floor 0.
- **Regresi:** pastikan perhitungan `loanSwpBalance` & `timeDepositBalance` lama
  tetap konsisten (2-record tidak mengubahnya).

## Risiko

- **Perubahan alur lunas yang sudah berjalan** (cair → draft). Mitigasi: test
  end-to-end pelunasan + reversal; pastikan tidak ada konsumen yang mengasumsikan
  refund langsung cair.
- **UI grouping custom** di menu Pencairan. Terisolasi, tidak menyentuh uang.
