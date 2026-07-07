# Skenario Pengujian — Filament Resources & Pages (KOPEKOMA Koperasi System)

> Dokumen ini memetakan skenario uji **positif (+)** dan **negatif (−)** untuk seluruh Filament Resource & Page yang ada.
> Format tabel dirancang agar mudah dikonversi ke Excel (1 baris = 1 test case).
>
> **Legenda Tipe:** `+` = positif/happy path · `−` = negatif/error/edge case
> **Kolom Status** dikosongkan untuk diisi saat eksekusi (Pass/Fail/Blocked).
> Tanda 🟢 = ekspektasi ini sudah benar-benar diuji oleh test Pest yang tepat (terverifikasi).
> Tanda ⚠ = **perilaku saat ini diduga bug / inkonsisten** — JANGAN tulis test yang mengunci perilaku ini sebagai "benar"; konfirmasi ke tim dulu (expected vs current).
> Banyak aksi dijaga oleh **Filament Shield** (permission `view_any/view/create/update/delete` + custom). Skenario izin diuji per resource.

### ⚠ Ringkasan Temuan (perlu konfirmasi tim sebelum jadi test)
Hasil adu dokumen vs kode — perilaku berikut diduga bug/inkonsisten. **Jangan kunci sebagai "benar" tanpa keputusan tim:**
| Ref | Temuan |
|-----|--------|
| FL-LON-16 | `LoanResource` tidak memvalidasi `first_due_date >= disbursement_date` (Livewire memvalidasi) → tanggal mundur lolos |
| FL-WDR-18 | `EditSavingsWithdrawal` reachable via URL `/{record}/edit`, melewati workflow ACC/Cairkan — mutasi finansial tanpa approve |
| FL-MBR-18 | `phone_number` divalidasi `required` sebelum normalisasi → input non-digit lolos lalu tersimpan `null` |
| FL-MBR-21 | Filament `unique(ignoreRecord:true)` tanpa `withoutTrashed` (Livewire pakai `withoutTrashed`) — perilaku NIK vs soft-deleted bisa beda |
| FL-BIP-05 | Overpay batch angsuran belum dipastikan lewat `LoanPaymentService::pay()` (potensi tidak kreditkan Sukarela) |

**Daftar Resource & Page**
1. [MemberResource — Anggota](#1-memberresource--anggota)
2. [LoanResource — Pinjaman](#2-loanresource--pinjaman)
3. [InstallmentResource — Angsuran](#3-installmentresource--angsuran)
4. [LoanBlacklistResource — Blacklist](#4-loanblacklistresource--blacklist)
5. [SavingsDepositResource — Setoran](#5-savingsdepositresource--setoran)
6. [SavingsWithdrawalResource — Pencairan](#6-savingswithdrawalresource--pencairan)
7. [ShoppingTransactionResource — Belanja Toko](#7-shoppingtransactionresource--belanja-toko)
8. [MemberSavingsBalanceResource — Saldo Anggota](#8-membersavingsbalanceresource--saldo-anggota)
9. [MemberHolidaySavingResource — Pendaftaran Hari Raya](#9-memberholidaysavingresource--pendaftaran-hari-raya)
10. [AgencyResource — OPD/Instansi](#10-agencyresource--opdinstansi)
11. [GradeResource — Golongan](#11-graderesource--golongan)
12. [UserResource — User](#12-userresource--user)
13. [ActivityResource — Log Aktivitas](#13-activityresource--log-aktivitas)
14. [ManageSettings — Pengaturan](#14-managesettings-page--pengaturan)
15. [BatchInstallmentPayment — Batch Angsuran](#15-batchinstallmentpayment-page--batch-angsuran)
16. [BatchSalaryDeduction — Batch Simpanan](#16-batchsalarydeduction-page--batch-simpanan)
17. [Auth/EditProfile — Profil](#17-autheditprofile-page--profil)
18. [RBAC / Permission Matrix (lintas resource)](#18-rbac--permission-matrix-lintas-resource)
19. [Integrasi Setting → Fitur Hilir](#19-integrasi-setting--fitur-hilir)
20. [Dashboard Widgets](#20-dashboard-widgets)
21. [Relation Managers](#21-relation-managers)
22. [Appendix — Store API (di luar UI)](#22-appendix--store-api-di-luar-ui)

---

## 1. MemberResource — Anggota
Model: `Member` · Menu: Master > Anggota · RelationManagers: Loans, Documents, AuditTrail — 🟢 `MemberResourceTest`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi | Catatan |
|----|----------|------|-----------|---------|-----------|---------|
| FL-MBR-01 | List & filter anggota | + | Izin view_any | Buka list, set filter status/agency/grade/Trashed | List terfilter | 🟢 |
| FL-MBR-02 | Create anggota ASN lengkap | + | Izin create | Isi semua field (NIK 16 digit unik, NIP) → Create | Tersimpan, member_number auto `KM-YYYY-NNNN` | 🟢 |
| FL-MBR-03 | Create anggota Honorer (tanpa NIP) | + | — | employment_status=Honorer, NIP kosong → Create | Tersimpan | 🟢 |
| FL-MBR-04 | Grade auto-set mandatory_savings | + | — | Pilih grade | mandatory_savings_amount ter-isi dari grade | 🟢 |
| FL-MBR-05 | Override mandatory_savings (super_admin/pengurus) | + | Role berwenang | Ubah nilai → Save | Tersimpan sesuai input | |
| FL-MBR-06 | Edit status ke Keluar + exit_date | + | — | Set status=Keluar, isi exit_date → Save | Tersimpan | 🟢 |
| FL-MBR-07 | Normalisasi telepon +62 | + | — | Input `08xxx` → Save | Tersimpan `+62xxx`, tampil lokal | 🟢 |
| FL-MBR-08 | View infolist + saldo simpanan live | + | Member ada | Buka View | Saldo pokok/wajib/sukarela/hari_raya/wajib_belanja dihitung live |
| FL-MBR-09 | Cetak Kartu (PDF) | + | Role super_admin/pengurus | Action `printCard` | PDF kartu ter-generate | |
| FL-MBR-10 | Soft delete / Restore / Force delete | + | Izin terkait | Delete → Restore → ForceDelete | Status soft-delete sesuai aksi | 🟢 |
| FL-MBR-11 | Export anggota | + | Role Petugas+ (canExportMembers) | Action export | File ter-unduh | 🟢 |
| FL-MBR-12 | Import anggota (queue) | + | canImportMembers | Upload file → import | Job ter-queue | 🟢 |
| FL-MBR-13 | NIK duplikat | − | NIK dipakai | Isi NIK sama → Create | Validasi gagal (unique) | 🟢 |
| FL-MBR-14 | NIK bukan 16 digit / non-numeric | − | — | Isi NIK pendek → Create | Validasi gagal | |
| FL-MBR-15 | NIP kosong untuk ASN | − | ASN | Kosongkan NIP → Create | Validasi gagal | |
| FL-MBR-16 | Status Keluar tanpa exit_date | − | — | Set Keluar, exit_date kosong → Save | Validasi gagal | |
| FL-MBR-17 | birth_date masa depan | − | — | Set besok → Save | Validasi gagal (max=today) | |
| FL-MBR-18 | Telepon non-digit lolos required lalu jadi null | ⚠ | — | Input `"abc"` → Save | **Dugaan bug:** `required` divalidasi sebelum `normalizePhone()`, input non-digit lolos lalu tersimpan `null` diam-diam. Ekspektasi seharusnya: validasi gagal |
| FL-MBR-19 | Override mandatory oleh non-pengurus | − | Role petugas | Coba ubah field | Field disabled |
| FL-MBR-20 | Akses tanpa permission | − | Role tanpa view_any | Buka resource | 403 / menu tersembunyi | 🟢 |
| FL-MBR-21 | NIK = NIK milik member soft-deleted | + | Ada member ter–soft-delete NIK X | Create member NIK X → Create | Diterima — ⚠ catatan: Filament pakai `unique(ignoreRecord:true)` TANPA `withoutTrashed` eksplisit (berbeda dgn MemberForm Livewire); konfirmasi perilaku trashed konsisten |

---

## 2. LoanResource — Pinjaman
Model: `Loan` · Menu: Pinjaman > Pinjaman · RelationManagers: Schedules, AuditTrail — 🟢 `LoanResourceTest`, `LoanRbacMatrixTest`, `LoanCalculatorTest`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi | Catatan |
|----|----------|------|-----------|---------|-----------|---------|
| FL-LON-01 | Create jangka panjang + 12 jadwal | + | Member non-blacklist; nominal > loan_short_term_max | Isi form, term=12 → Create | Loan "Cair", 12 InstallmentSchedule | 🟢 |
| FL-LON-02 | Create jangka pendek (sebrakan) | + | Nominal ≤ max | Pilih jangka_pendek (term auto=1) → Create | Loan dibuat, 1 jadwal | 🟢 |
| FL-LON-03 | Pencairan tunai (tanpa bank) | + | method=tunai | Create | Tersimpan tanpa detail bank | 🟢 |
| FL-LON-04 | Pencairan transfer prefill rekening | + | method=transfer | Pilih transfer | Bank & no rekening anggota terprefill (editable) | 🟢 |
| FL-LON-05 | Preview rincian otomatis | + | — | Isi nominal | admin_fee/SWP/disbursed/pokok/jasa/tab dihitung server | 🟢 |
| FL-LON-06 | Peringatan tunggakan & beban potong gaji | + | Member berpinjaman | Pilih member | Warning arrears & deduction load tampil | |
| FL-LON-07 | View infolist lengkap + schedule | + | — | Buka View | Rincian deductions & jadwal tampil |
| FL-LON-08 | Cetak Tanda Terima (PDF) | + | — | Action print | PDF ter-generate |
| FL-LON-09 | Batalkan pinjaman (Cair, belum bayar) | + | Status Cair, no payment, izin reverse | Action Batalkan → konfirmasi | Status→Dibatalkan, jadwal dibuang, audit log | 🟢 |
| FL-LON-10 | Filter loan_type / status | + | Ada data | Set filter | List terfilter | 🟢 |
| FL-LON-11 | Member blacklist aktif | − | Member blacklist | Pilih member → Create | Ditolak (hasActiveBlacklist) | 🟢 |
| FL-LON-12 | jangka_panjang nominal ≤ max | − | — | nominal kecil → Create | Validasi bisnis gagal | 🟢 |
| FL-LON-13 | jangka_pendek nominal > max | − | — | nominal besar → Create | Validasi bisnis gagal | 🟢 |
| FL-LON-14 | principal negatif / 0 | − | — | Input -1000 → Create | Validasi gagal | |
| FL-LON-15 | term_months < 1 | − | — | term=0 → Create | Validasi gagal (min:1) | |
| FL-LON-16 | first_due_date < disbursement_date | ⚠ | — | Tanggal mundur → Create | **Dugaan bug/gap:** `LoanResource` TIDAK punya rule `after_or_equal` (hanya prefill saat blank) → tanggal mundur **lolos**. Bandingkan Livewire LW-LON-16 yang menolak. Ekspektasi seharusnya: ditolak | |
| FL-LON-17 | Transfer tanpa bank/rekening | − | method=transfer | Kosongkan bank → Create | Validasi gagal (required) | |
| FL-LON-18 | Batalkan saat sudah ada pembayaran | − | Ada installment | Action Batalkan | Aksi tersembunyi/ditolak (canCorrect=false) | 🟢 |
| FL-LON-19 | Akses tanpa permission | − | Role tanpa izin | Buka resource | 403 / tersembunyi | 🟢 |
| FL-LON-20 | Boundary: nominal == loan_short_term_max (jangka pendek) | + | nominal tepat = max | jangka_pendek, nominal=max → Create | Diterima (`> max` yang ditolak; `==` sah sebagai sebrakan) | |
| FL-LON-21 | Boundary: nominal == max tapi jangka_panjang | − | nominal tepat = max | jangka_panjang, nominal=max → Create | Ditolak (`<= max` untuk jangka panjang gagal) | |

---

## 3. InstallmentResource — Angsuran
Model: `Installment` · Menu: Pinjaman > Angsuran · RelationManager: AuditTrail — 🟢 `InstallmentResourceTest`, `ReverseTransactionTest`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi | Catatan |
|----|----------|------|-----------|---------|-----------|---------|
| FL-INS-01 | Bayar angsuran (amount = total_due) | + | Loan Cair, jadwal belum bayar | Pilih member→loan→schedule (FIFO), amount=bill → Create | Installment tercatat, jadwal Terbayar | 🟢 |
| FL-INS-02 | Prefill amount sebagai integer | + | Schedule dipilih | Lihat amount_paid | Prefill = total_due, tanpa desimal | 🟢 |
| FL-INS-03 | Bill detail breakdown | + | — | Pilih schedule | Tampil Pokok/Jasa/Tab/Total | 🟢 |
| FL-INS-04 | Overpay → kelebihan ke Sukarela | + | — | amount > bill → Create | Kelebihan dikreditkan Sukarela (SavingsDeposit baru) | Test yg ada hanya cek **label** di view, BELUM submit overpay → butuh test baru |
| FL-INS-05 | Pelunasan → loan Lunas + refund SWP/Tab | + | Jadwal terakhir | Bayar | Loan→Lunas; refund SWP+Tab auto-create sebagai **withdrawal status `draft`** (saldo belum berkurang, perlu ACC+Cairkan) | 🟢 (pelunasan & pembuatan refund); status draft = LoanPaymentService::makeRefund |
| FL-INS-06 | Upload bukti (JPG/PNG/PDF) | + | — | Lampirkan → Create | Bukti tersimpan |
| FL-INS-07 | Cetak Kuitansi (PDF) | + | — | Action print | PDF ter-generate |
| FL-INS-08 | Reversal pembayaran | + | Installment asli, izin reverse | Action Reversal → reason | Installment lawan dibuat, jadwal Belum Bayar, loan kembali Cair | 🟢 |
| FL-INS-09 | Filter payment_method / is_reversal | + | Ada data | Set filter | List terfilter |
| FL-INS-10 | Bayar kurang dari tagihan | − | — | amount < total_due → Create | Validasi gagal (≥ total_due) | 🟢 |
| FL-INS-11 | FIFO: bayar jadwal #3 saat #1 belum | − | — | Lihat opsi schedule | Hanya jadwal terlama belum bayar tersedia | 🟢 |
| FL-INS-12 | Reversal atas reversal | − | is_reversal=true | Action Reversal | Aksi tersembunyi (canReverse=false) | 🟢 |
| FL-INS-13 | Tidak ada schedule (semua lunas) | − | Loan Lunas | Pilih loan | Tidak ada opsi schedule |
| FL-INS-14 | Bukti format invalid | − | — | Upload `.exe` → Create | Validasi gagal | |
| FL-INS-15 | Idempotency double-submit (sekuensial, 1 form) | − | — | Submit 2x dari form sama | Tercegah via idempotency_key. Catatan: key per-mount → tidak menjamin idempoten lintas-request/race |

---

## 4. LoanBlacklistResource — Blacklist
Model: `LoanBlacklist` · Menu: Pinjaman > Blacklist · RelationManager: AuditTrail

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi |
|----|----------|------|-----------|---------|-----------|
| FL-BLK-01 | Tandai blacklist | + | Member belum blacklist aktif | Isi member + reason ≥5 + tanggal → Create | LoanBlacklist (is_active=true) |
| FL-BLK-02 | View detail | + | — | Buka View | Detail + dicatat oleh + alasan |
| FL-BLK-03 | Lepas blacklist | + | is_active=true, izin update | Action Lepas | is_active=false, released_at=hari ini |
| FL-BLK-04 | Filter is_active | + | Ada data | Set filter | List terfilter |
| FL-BLK-05 | Member sudah blacklist aktif | − | — | Pilih member tsb → Create | Validasi gagal (cegah duplikat) |
| FL-BLK-06 | reason < 5 char | − | — | reason "ab" → Create | Validasi gagal (min:5) |
| FL-BLK-07 | Lepas non-aktif | − | is_active=false | Action Lepas | Aksi tersembunyi/no-op |

---

## 5. SavingsDepositResource — Setoran
Model: `SavingsDeposit` · Menu: Simpanan > Setoran · RelationManager: AuditTrail — 🟢 `SavingsDepositResourceTest`, `SavingsMutationServiceTest`, `SavingsRbacMatrixTest`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi | Catatan |
|----|----------|------|-----------|---------|-----------|---------|
| FL-DEP-01 | Setor Pokok (locked) | + | Member belum punya Pokok | Centang pokok → Create | Deposit pokok, nominal dari setting | 🟢 |
| FL-DEP-02 | Setor Wajib (prefill grade, editable) | + | — | Wajib prefill, edit bila perlu → Create | Tersimpan | 🟢 |
| FL-DEP-03 | Setor Sukarela ≥ min | + | — | Isi sukarela ≥ min → Create | Tersimpan | 🟢 |
| FL-DEP-04 | Setor Wajib Belanja (locked) | + | — | Centang → Create | Nominal terkunci dari setting | 🟢 |
| FL-DEP-05 | Setor Hari Raya (ada registrasi) | + | Registrasi aktif | Centang hari_raya → Create | Deposit tahun program, nominal dari registrasi | 🟢 |
| FL-DEP-06 | Setor beberapa jenis sekaligus | + | — | Centang beberapa → Create | 1 deposit per jenis | 🟢 |
| FL-DEP-07 | Cetak Slip (PDF) | + | — | Action print | PDF ter-generate |
| FL-DEP-08 | Reversal setoran | + | Deposit asli, izin reverse | Action Reversal → reason | Transaksi lawan dibuat, saldo disesuaikan | 🟢 |
| FL-DEP-09 | Filter savings_type/method/reversal | + | Ada data | Set filter | List terfilter |
| FL-DEP-10 | Jenis sudah disetor di periode | − | Sudah ada periode | Buka form | Jenis tersembunyi (typeAlreadyDeposited) | 🟢 |
| FL-DEP-11 | Pokok kedua | − | Punya Pokok | Coba setor pokok | Tidak diizinkan (1x seumur hidup) | 🟢 |
| FL-DEP-12 | Sukarela < min | − | — | Isi < min → Create | Validasi gagal | 🟢 |
| FL-DEP-13 | Wajib included amount 0 | − | — | Centang wajib, amount=0 → Create | Validasi gagal | |
| FL-DEP-14 | Hari Raya tanpa registrasi aktif | − | Tidak ada registrasi | Buka form | Baris hari_raya tidak muncul | 🟢 |
| FL-DEP-15 | Reversal atas reversal | − | is_reversal=true | Action Reversal | Aksi tersembunyi |
| FL-DEP-16 | Idempotency double-submit (sekuensial, 1 form) | − | — | Submit 2x dari form sama | Tercegah via idempotency_key (per-baris). Catatan: key per-mount → tidak menjamin idempoten lintas-request/race |

---

## 6. SavingsWithdrawalResource — Pencairan
Model: `SavingsWithdrawal` · Menu: Simpanan > Pencairan · RelationManager: AuditTrail — 🟢 `SavingsWithdrawalResourceTest`, `WithdrawalWorkflowTest`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi | Catatan |
|----|----------|------|-----------|---------|-----------|---------|
| FL-WDR-01 | Ajukan pencairan Sukarela | + | Saldo cukup | Isi member/type/amount ≤ saldo → Create | Withdrawal status draft | 🟢 |
| FL-WDR-02 | Ajukan Hari Raya per tahun | + | Saldo hari_raya tahun X | Pilih period_year, amount ≤ saldo → Create | Draft tahun program | 🟢 |
| FL-WDR-03 | ACC (draft→acc) | + | Status draft, izin approve | Action ACC | Status acc | 🟢 |
| FL-WDR-04 | Cairkan (acc→cair) | + | Status acc, izin disburse | Action Cairkan | Status cair, saldo berkurang | 🟢 |
| FL-WDR-05 | Tolak (draft/acc→ditolak) | + | izin approve | Action Tolak | Status ditolak (final), saldo tetap | 🟢 |
| FL-WDR-06 | Reversal pencairan cair | + | Status cair, bukan refund loan | Action Reversal → reason | Reversal dibuat, saldo kembali | 🟢 |
| FL-WDR-07 | Refund pair SWP+Tab tampil gabung | + | Loan Lunas hasilkan pasangan | Buka list | Tampil 1 entri "Pengembalian Pelunasan" (pairTotal) | 🟢 |
| FL-WDR-08 | Transisi pair atomik | + | Refund pair | ACC/Cairkan/Tolak salah satu | Kedua baris berubah bersamaan | 🟢 |
| FL-WDR-09 | Filter status/type/reversal | + | Ada data | Set filter | List terfilter |
| FL-WDR-10 | Amount > saldo tersedia | − | — | amount > availableBalance → Create | Validasi gagal | 🟢 |
| FL-WDR-11 | Hari Raya tanpa period_year | − | type=hari_raya | Kosongkan tahun → Create | Validasi gagal (required) | |
| FL-WDR-12 | Available kurangi pending draft/acc | − | Ada pending SWP/Tab | Hitung available | Available = saldo − pending (anti double-claim) | 🟢 |
| FL-WDR-13 | ACC non-draft | − | Status bukan draft | Action ACC | Aksi tersembunyi | 🟢 |
| FL-WDR-14 | Cairkan non-acc | − | Status bukan acc | Action Cairkan | Aksi tersembunyi | 🟢 |
| FL-WDR-15 | Reversal non-cair | − | Status bukan cair | Action Reversal | Aksi tersembunyi | 🟢 |
| FL-WDR-16 | Reversal refund pair langsung | − | isLoanRefund=true | Action Reversal | Tersembunyi (reversal lewat reversal angsuran) | 🟢 |
| FL-WDR-17 | Akses tanpa permission | − | Role tanpa izin | Buka resource | 403 / tersembunyi | 🟢 |
| FL-WDR-18 | **Immutability**: edit withdrawal via URL `/{record}/edit` | ⚠ | Withdrawal ada (draft/acc/cair) | Akses langsung route Edit | **Risiko:** `EditSavingsWithdrawal` terdaftar & reachable, melewati workflow ACC/Cairkan — bisa ubah amount/status/period_year tanpa approve. Deposit/Installment/Shopping sengaja TIDAK punya Edit page. Ekspektasi: diblokir atau dibatasi field non-finansial | |
| FL-WDR-19 | Immutability lintas resul: deposit/installment/shopping tak punya Edit | + | — | Cari route/aksi edit | Tidak tersedia (immutable; koreksi via reversal) | 🟢 (SavingsDepositResourceTest meng-assert "no edit page") |

---

## 7. ShoppingTransactionResource — Belanja Toko
Model: `ShoppingTransaction` · Menu: Simpanan > Belanja Toko · RelationManager: AuditTrail — 🟢 `RecordShoppingUsageTest`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi |
|----|----------|------|-----------|---------|-----------|
| FL-SHP-01 | Catat pemakaian ≤ saldo | + | Saldo Wajib Belanja cukup | Isi member + amount ≤ saldo → Create | Transaksi recorded |
| FL-SHP-02 | View detail | + | — | Buka View | Detail + dicatat oleh tampil |
| FL-SHP-03 | Reversal pemakaian | + | Transaksi asli, izin reverse | Action Reversal → reason | Transaksi lawan dibuat, saldo kembali |
| FL-SHP-04 | Filter is_reversal | + | Ada data | Set filter | List terfilter |
| FL-SHP-05 | Amount > saldo | − | — | amount > shoppingBalance → Create | Validasi gagal |
| FL-SHP-06 | Amount < 1 | − | — | amount 0 → Create | Validasi gagal (min:1) |
| FL-SHP-07 | transaction_date masa depan | − | — | Set besok → Create | Validasi gagal (max=today) |
| FL-SHP-08 | Reversal atas reversal | − | is_reversal=true | Action Reversal | Aksi tersembunyi |

---

## 8. MemberSavingsBalanceResource — Saldo Anggota
Model: `Member` (rekap read-only) · Menu: Simpanan > Saldo Anggota — 🟢 `SavingsBalanceServiceTest`, `SavingsBalanceDetailTest`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi |
|----|----------|------|-----------|---------|-----------|
| FL-BAL-01 | List rekap saldo (default Aktif) | + | Ada data | Buka resource | Kolom saldo per jenis + total; default filter status=Aktif |
| FL-BAL-02 | Filter agency/grade/status | + | Ada data | Set filter | List terfilter |
| FL-BAL-03 | Saldo computed-on-read benar | + | Ada transaksi | Lihat kolom | Saldo = aggregate via SavingsBalanceService |
| FL-BAL-04 | Link Detail ke MemberResource.view | + | — | Klik Detail | Diarahkan ke view member |
| FL-BAL-05 | Tidak ada create/edit/delete | − | — | Cari aksi tulis | Tidak tersedia (canCreate=false) |
| FL-BAL-06 | Filter agency kosong | − | — | Filter agency tanpa member | List kosong (tidak error) |

---

## 9. MemberHolidaySavingResource — Pendaftaran Hari Raya
Model: `MemberHolidaySaving` · Menu: Simpanan > Pendaftaran Hari Raya · RelationManagers: Deposits, AuditTrail — 🟢 `MemberHolidaySavingResourceTest`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi | Catatan |
|----|----------|------|-----------|---------|-----------|---------|
| FL-HOL-01 | Daftar Hari Raya | + | Member belum terdaftar tahun itu | Isi member/tanggal/monthly_amount → Create | Tersimpan, period_year derive dari end_date | 🟢 |
| FL-HOL-02 | Edit registrasi | + | — | Ubah nominal/tanggal → Save | Terupdate | 🟢 |
| FL-HOL-03 | View + saldo terkumpul | + | Ada setoran | Buka View | Saldo terkumpul tampil (holidayBalance) | 🟢 |
| FL-HOL-04 | Tab Deposits | + | Ada setoran hari_raya | Buka relation Deposits | Daftar setoran untuk registrasi |
| FL-HOL-05 | Nonaktifkan registrasi | + | — | is_active=false → Save | Setoran hari_raya tahun itu tak tersedia |
| FL-HOL-06 | Delete registrasi | + | Izin delete | Action Delete | Terhapus | 🟢 |
| FL-HOL-07 | Filter period_year/is_active | + | Ada data | Set filter | List terfilter |
| FL-HOL-08 | Member duplikat di tahun sama | − | Sudah terdaftar | Daftar member sama → Create | Validasi gagal (unique member+year) | 🟢 |
| FL-HOL-09 | end_date < start_date | − | — | Set end < start → Save | Validasi gagal (after_or_equal) | |
| FL-HOL-10 | monthly_amount ≤ 0 | − | — | Set 0 → Save | Validasi gagal (min:1) | |

---

## 10. AgencyResource — OPD/Instansi
Model: `Agency` · Menu: Master > OPD/Instansi · RelationManager: AuditTrail — 🟢 `AgencyResourceTest`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi | Catatan |
|----|----------|------|-----------|---------|-----------|---------|
| FL-AGN-01 | Create OPD (kode auto-gen) | + | Izin create | `generateCode()` → isi nama/status → Create | Tersimpan, kode `OPD-NNNN` unik | 🟢 |
| FL-AGN-02 | Edit OPD | + | — | Ubah nama/PIC/telepon → Save | Terupdate | 🟢 |
| FL-AGN-03 | View + jumlah anggota | + | — | Buka View | members_count tampil | 🟢 |
| FL-AGN-04 | Normalisasi telepon PIC | + | — | Input `08xxx` → Save | Tersimpan `+62xxx` | 🟢 |
| FL-AGN-05 | Filter status | + | Ada data | Set filter | List terfilter |
| FL-AGN-06 | Delete / bulk delete | + | Izin delete | Action Delete | Terhapus | 🟢 |
| FL-AGN-07 | Kode duplikat | − | Kode dipakai | Input sama → Create | Validasi gagal (unique) | 🟢 |
| FL-AGN-08 | Nama kosong | − | — | Kosongkan → Create | Validasi gagal (required) | |
| FL-AGN-09 | Delete OPD yang punya anggota | − | OPD punya member | Action Delete | Ditolak / constraint |
| FL-AGN-10 | Telepon PIC invalid | − | — | Input non-digit → Save | Normalisasi null / error |

---

## 11. GradeResource — Golongan
Model: `Grade` · Menu: Master > Golongan · RelationManager: AuditTrail — 🟢 `GradeResourceTest`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi | Catatan |
|----|----------|------|-----------|---------|-----------|---------|
| FL-GRD-01 | Create golongan (kode auto-gen) | + | Izin create | generateCode → isi name/amount → Create | Tersimpan, kode unik | 🟢 |
| FL-GRD-02 | Edit golongan | + | — | Ubah nominal → Save | Terupdate | 🟢 |
| FL-GRD-03 | View + jumlah anggota | + | — | Buka View | members_count tampil | 🟢 |
| FL-GRD-04 | Snapshot: edit nominal tak ubah member lama | + | Member existing | Ubah amount golongan | Member existing tetap (snapshot) | |
| FL-GRD-05 | Delete / bulk delete | + | Izin delete | Action Delete | Terhapus | 🟢 |
| FL-GRD-06 | Kode duplikat | − | Kode dipakai | Input sama → Create | Validasi gagal (unique) | 🟢 |
| FL-GRD-07 | Nama kosong | − | — | Kosongkan → Create | Validasi gagal | |
| FL-GRD-08 | amount ≤ 0 | − | — | Set 0/-1 → Create | Validasi gagal (min:1) | |
| FL-GRD-09 | Delete golongan dipakai member | − | Golongan dipakai | Action Delete | Ditolak / constraint |

---

## 12. UserResource — User
Model: `User` · Menu: Sistem > User · RelationManager: AuditTrail — 🟢 `UserResourceTest`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi | Catatan |
|----|----------|------|-----------|---------|-----------|---------|
| FL-USR-01 | Create user + roles | + | Izin create | Isi name/email/password + roles → Create | User dibuat, password ter-hash | 🟢 |
| FL-USR-02 | Set email_verified_at | + | — | Toggle verified → Save | email_verified_at terisi `now()` | 🟢 |
| FL-USR-03 | Edit user, password kosong | + | — | Edit tanpa password → Save | Password lama dipertahankan | 🟢 |
| FL-USR-04 | Assign multiple role | + | — | Pilih beberapa role → Save | Roles ter-sync | 🟢 |
| FL-USR-05 | Filter roles/is_active/verified | + | Ada data | Set filter | List terfilter |
| FL-USR-06 | View detail | + | — | Buka View | Identitas + status tampil |
| FL-USR-07 | Email duplikat | − | Email dipakai | Isi sama → Create | Validasi gagal (unique) | 🟢 |
| FL-USR-08 | Password confirmation mismatch | − | — | Konfirmasi beda → Create | Validasi gagal | 🟢 |
| FL-USR-09 | Email format invalid | − | — | Isi `abc` → Create | Validasi gagal (email) | |
| FL-USR-10 | Hapus diri sendiri | − | Edit/list diri | Action Delete | Delete tersembunyi (anti self-lockout) | 🟢 |
| FL-USR-11 | Nonaktifkan diri sendiri | − | Edit diri | Toggle is_active | Toggle disabled (isSelf) | 🟢 |
| FL-USR-12 | Akses tanpa permission | − | Role lain | Buka resource | 403 / tersembunyi | 🟢 |

---

## 13. ActivityResource — Log Aktivitas
Model: `Activity` (Spatie) · Menu: Sistem > Log Aktivitas · Read-only — 🟢 `ActivityResourceTest`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi | Catatan |
|----|----------|------|-----------|---------|-----------|---------|
| FL-ACT-01 | Log created/updated/deleted | + | — | CRUD member/loan/installment | Activity tercatat dengan event sesuai | 🟢 |
| FL-ACT-02 | Log custom (koreksi/reversal) | + | — | Batalkan loan / reversal | Activity event custom + reason | 🟢 |
| FL-ACT-03 | View diff old vs attributes | + | — | Buka View aktivitas | Perubahan data key-value tampil | 🟢 |
| FL-ACT-04 | Filter event/subject/causer/tanggal | + | Ada data | Set filter | List terfilter | 🟢 |
| FL-ACT-05 | Read-only (no create/edit/delete) | − | — | Cari aksi tulis | Tidak tersedia (canCreate=false) |
| FL-ACT-06 | Injection di filter | − | — | Masukkan payload di filter | Aman (Filament escaping) |

---

## 14. ManageSettings (Page) — Pengaturan
Page: `Filament\Pages\ManageSettings` · Menu: Sistem > Pengaturan — 🟢 `ManageStoreClientTest`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi |
|----|----------|------|-----------|---------|-----------|
| FL-SET-01 | Update app name + reload | + | Izin manage_settings | Ubah app_name → Save | UI berubah setelah reload |
| FL-SET-02 | Upload logo & favicon | + | — | Upload image valid → Save | Tersimpan |
| FL-SET-03 | Update SMTP + test email | + | — | Isi config → test | Email tes terkirim |
| FL-SET-04 | Update setting koperasi | + | — | Ubah rate/amount → Save | Tersimpan; loan baru ikut rate baru |
| FL-SET-05 | CRUD Store Client | + | — | Create/edit/delete client | Berhasil |
| FL-SET-06 | Copy secret (super_admin) | + | Izin copy_store_client_secret | Action copy | Secret tersalin, activity log |
| FL-SET-07 | Akses tanpa manage_settings | − | Role lain | Buka page | 403 |
| FL-SET-08 | Copy secret tanpa izin | − | Role lain | Action copy | 403 / tersembunyi |
| FL-SET-09 | Upload logo format/ukuran invalid | − | — | Upload non-image/besar → Save | Validasi gagal |
| FL-SET-10 | Mail config invalid | − | — | Port/from salah → Save | Validasi gagal |
| FL-SET-11 | loan_short_term_max ≤ 0 | − | — | Set 0 → Save | Validasi gagal |

---

## 15. BatchInstallmentPayment (Page) — Batch Angsuran
Page: `Filament\Pages\BatchInstallmentPayment` · Permission `access_batch_salary_deduction` — 🟢 `BatchInstallmentPaymentPageTest`, `BatchInstallmentPaymentServiceTest`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi | Catatan |
|----|----------|------|-----------|---------|-----------|---------|
| FL-BIP-01 | Build rows per OPD | + | Izin; OPD punya member berpinjaman | Pilih agency | Repeater per member + pinjaman aktif (FIFO schedule) | 🟢 |
| FL-BIP-02 | Prefill amount = total_bill | + | — | Lihat grid | amount_paid prefill = tagihan FIFO | 🟢 |
| FL-BIP-03 | Submit batch sebagian | + | — | Centang sebagian → Submit | Hanya included diproses, installment recorded, loan settle bila lunas | 🟢 |
| FL-BIP-04 | Upload bukti per baris | + | — | Lampirkan bukti → Submit | Bukti ter-attach | 🟢 |
| FL-BIP-05 | Overpay → kelebihan Sukarela | + | — | amount > bill → Submit | Kelebihan ke Sukarela (valid) — ⚠ **belum diverifikasi**: pastikan batch service memanggil `LoanPaymentService::pay()` (yang handle excess→sukarela), bukan create Installment langsung; jika bypass, overpay batch tidak kreditkan sukarela |
| FL-BIP-06 | Akses tanpa permission | − | Role tanpa izin | Buka page | abort 403 (canAccess) | 🟢 |
| FL-BIP-07 | OPD tanpa pinjaman aktif | − | OPD kosong | Pilih agency | Grid kosong |
| FL-BIP-08 | FIFO violation | − | Jadwal #1 belum bayar | Lihat opsi | Hanya jadwal terlama tersedia |
| FL-BIP-09 | Bukti format invalid | − | — | Upload `.exe` → Submit | Validasi gagal per baris |

---

## 16. BatchSalaryDeduction (Page) — Batch Simpanan
Page: `Filament\Pages\BatchSalaryDeduction` · Permission `access_batch_salary_deduction`, export `export_savings_recap` — 🟢 `BatchSalaryDeductionPageTest`, `BatchSalaryDeductionServiceTest`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi | Catatan |
|----|----------|------|-----------|---------|-----------|---------|
| FL-BSD-01 | Build rows per OPD | + | Izin akses | Pilih agency | Repeater per member dengan jenis wajib/pokok/wajib_belanja | 🟢 |
| FL-BSD-02 | Prefill nominal per jenis | + | — | Lihat grid | wajib=member.mandatory; pokok/wajib_belanja=setting | 🟢 |
| FL-BSD-03 | Submit batch | + | — | Centang member+jenis → Submit | SavingsDeposit dibuat (potong_gaji, bendahara) | 🟢 |
| FL-BSD-04 | Toggle jenis tertentu | + | — | Matikan include_type | Jenis itu di-skip | 🟢 |
| FL-BSD-05 | Export Recap (CSV) | + | Izin export_savings_recap (Manager+) | Action Export | CSV ter-unduh dengan header & data benar | 🟢 |
| FL-BSD-06 | Akses tanpa permission | − | Role tanpa izin | Buka page | abort 403 | 🟢 |
| FL-BSD-07 | Export tanpa izin | − | Role tanpa export | Cari action export | Tersembunyi / 403 | 🟢 |
| FL-BSD-08 | Pokok locked tak bisa diubah | − | — | Coba ubah amount pokok | Field disabled |
| FL-BSD-09 | Pokok untuk member sudah punya Pokok | − | hasActivePokok | Submit | Baris pokok di-skip |
| FL-BSD-10 | Submit duplikat periode | − | Sudah disetor periode itu | Submit ulang | Tercegah (skip done) |

---

## 17. Auth/EditProfile (Page) — Profil
Page: `Filament\Pages\Auth\EditProfile`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi |
|----|----------|------|-----------|---------|-----------|
| FL-PRF-01 | Edit nama | + | Login | Ubah name → Save | Tersimpan |
| FL-PRF-02 | Edit email (unik) | + | — | Ubah email valid → Save | Tersimpan |
| FL-PRF-03 | Ganti password (confirmed) | + | — | Isi password baru + konfirmasi → Save | Terganti |
| FL-PRF-04 | Tidak ubah password (kosong) | + | — | Biarkan password kosong → Save | Password lama dipertahankan |
| FL-PRF-05 | Email duplikat | − | Email dipakai | Ubah → Save | Validasi gagal (unique) |
| FL-PRF-06 | Password confirmation mismatch | − | — | Konfirmasi beda → Save | Validasi gagal |
| FL-PRF-07 | Email format invalid | − | — | Isi `abc` → Save | Validasi gagal |

---

## 18. RBAC / Permission Matrix (lintas resource)
🟢 `RolePermissionMatrixTest`, `LoanRbacMatrixTest`, `SavingsRbacMatrixTest`, `StoreClientMiddlewareTest`, `StoreEnumerationGuardTest`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi | Catatan |
|----|----------|------|-----------|---------|-----------|---------|
| FL-RBAC-01 | super_admin akses semua | + | Role super_admin | Buka tiap resource/aksi | Semua diizinkan (Gate::before) | 🟢 |
| FL-RBAC-02 | pengurus sesuai permission | + | Role pengurus | Buka resource sesuai izin | Hanya yang diberi izin tampil | 🟢 |
| FL-RBAC-03 | petugas batch & transaksi | + | Role petugas | Akses batch & input transaksi | Diizinkan sesuai permission | 🟢 |
| FL-RBAC-04 | Reverse butuh izin khusus | + | Punya `reverse_*` | Action reversal | Diizinkan | 🟢 |
| FL-RBAC-05 | Approve/disburse withdrawal | + | Punya `approve_/disburse_savings::withdrawal` | Action ACC/Cairkan | Diizinkan | 🟢 |
| FL-RBAC-06 | Akses resource tanpa view_any | − | Role tanpa izin | Buka resource | Menu tersembunyi / 403 | 🟢 |
| FL-RBAC-07 | Aksi create/update/delete tanpa izin | − | Role tanpa izin | Coba aksi | Tombol tersembunyi / 403 | 🟢 |
| FL-RBAC-08 | Reverse tanpa izin reverse | − | Tanpa `reverse_*` | Action reversal | Tersembunyi / ditolak | 🟢 |
| FL-RBAC-09 | Batch tanpa `access_batch_salary_deduction` | − | Role lain | Buka page batch | abort 403 | 🟢 |
| FL-RBAC-10 | Store client middleware/enumeration | − | Token store | Akses API store | Diproteksi (middleware + enumeration guard) | 🟢 |

---

---

## 20. Dashboard Widgets
Widget panel Filament: `SavingsStatsOverview`, `SavingsCashInflowChart` (auto-discovered). Tampil di Dashboard panel admin.

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi |
|----|----------|------|-----------|---------|-----------|
| FL-WID-01 | Stat "Total Simpanan" net | + | Ada deposit/withdrawal/belanja | Buka dashboard | Total = Σ deposit − Σ withdrawal(cair) − Σ belanja; reversal dikurangi |
| FL-WID-02 | Stat "Setoran Bulan Ini" | + | Ada setoran bulan berjalan | Buka dashboard | Jumlah & count setoran bulan ini (non-reversal) benar |
| FL-WID-03 | Stat "Anggota Aktif" | + | Ada member | Buka dashboard | Hitung member status=Aktif |
| FL-WID-04 | Chart arus uang masuk 6 bulan | + | Ada setoran historis | Buka dashboard | Bar chart 6 bulan terakhir, nilai per bulan = Σ setoran non-reversal |
| FL-WID-05 | Reversal mengurangi angka | + | Ada setoran lalu reversal | Reversal setoran → buka dashboard | Total & chart turun sesuai reversal |
| FL-WID-06 | Widget saat data kosong | − | DB bersih | Buka dashboard | Tampil Rp 0 / 0 transaksi, tanpa error |
| FL-WID-07 | Visibilitas widget per permission | − | Role terbatas | Buka dashboard | Widget tampil/tidak sesuai kebijakan akses panel |

---

## 21. Relation Managers
RelationManager di dalam resource. Diuji via `livewire(RelationManager::class, ['ownerRecord' => $record, 'pageClass' => ...])`.

| ID | Skenario | Tipe | Resource induk | Langkah | Ekspektasi |
|----|----------|------|----------------|---------|-----------|
| FL-RM-01 | Documents — list dokumen anggota | + | MemberResource | Buka tab Dokumen | Daftar media "documents" tampil |
| FL-RM-02 | Documents — upload/hapus dokumen | + | MemberResource | Upload PDF/JPG, lalu hapus | Media tersimpan/terhapus, audit log |
| FL-RM-03 | Documents — tipe/ukuran ilegal | − | MemberResource | Upload `.exe` / >5MB | Validasi gagal |
| FL-RM-04 | Loans — daftar pinjaman anggota | + | MemberResource | Buka tab Pinjaman | Pinjaman milik member tampil (read-only/link) |
| FL-RM-05 | Schedules — jadwal angsuran | + | LoanResource | Buka tab Jadwal | Baris jadwal (seq, due_date, pokok/jasa/tab/total, status) |
| FL-RM-06 | Deposits — setoran hari raya | + | MemberHolidaySavingResource | Buka tab Setoran | Setoran hari_raya untuk registrasi itu tampil |
| FL-RM-07 | AuditTrail — riwayat perubahan | + | Semua resource | Buka tab Audit | Aktivitas created/updated + diff tampil |
| FL-RM-08 | AuditTrail read-only | − | Semua resource | Cari aksi tulis | Tidak ada create/edit/delete |

---

## 22. Appendix — Store API (di luar UI)
> **Catatan scope:** Endpoint REST `/api/v1/store/*` (`StoreAuthController`, `StorePurchaseController`) **bukan** Livewire/Filament UI, jadi tidak dirinci di tabel utama. Tetapi ini fitur nyata yang dipakai integrasi toko. **Sudah punya cakupan test otomatis luas** — `StoreAuthTokenTest`, `StorePurchaseVerifyTest`, `StorePurchaseChargeTest`, `StorePurchaseRefundTest`, `StoreChargeConcurrencyTest`, `StoreClientMiddlewareTest`, `StoreEnumerationGuardTest`. Dicatat di sini agar inventaris fitur lengkap.

| ID | Skenario | Tipe | Endpoint | Ekspektasi | Catatan |
|----|----------|------|----------|-----------|---------|
| API-STR-01 | Terbitkan token store | + | POST `token` | Token Sanctum dgn ability `shopping:charge`/`refund`, throttle `store-token` | 🟢 |
| API-STR-02 | Verifikasi pembelian | + | POST `purchases/verify` | Validasi saldo wajib belanja member | 🟢 |
| API-STR-03 | Charge pembelian | + | POST `purchases` | ShoppingTransaction tercatat (ability `shopping:charge`, `store.client`) | 🟢 |
| API-STR-04 | Refund pembelian | + | POST `purchases/{no}/refund` | Reversal (ability `shopping:refund`, hanya client `can_refund`) | 🟢 |
| API-STR-05 | Tanpa ability / client salah | − | semua | 403 (middleware abilities + store.client) | 🟢 |
| API-STR-06 | Charge konkuren (race) | − | POST `purchases` | Konsistensi saldo terjaga (locking) | 🟢 |
| API-STR-07 | Throttle berlebih | − | semua | 429 (throttle `store-purchase`/`store-token`) | 🟢 |

---

### Catatan Cakupan
- Skenario bertanda 🟢 sudah punya test otomatis (Pest) — verifikasi/lengkapi, jangan duplikasi.
- Untuk pengujian Filament otomatis gunakan `livewire(Resource\Pages\...)->fillForm()->call('create')->assertHasNoFormErrors()` dan `assertForbidden()` untuk skenario izin.
- Skenario validasi tanggal (`max=today`) gunakan `Carbon::setTestNow()` agar deterministik.
- Aturan bisnis kompleks (FIFO, refund pair D2, available balance D3, idempotency) sebaiknya ditest di service layer juga (sudah ada di `tests/Feature/*ServiceTest.php`).
- **Cetakan PDF/CSV** (kartu anggota, slip setoran, tanda terima pinjaman, kuitansi angsuran, rekap CSV) punya rute ber-permission tersendiri — uji juga akses ditolak tanpa izin `view_*`/`export_savings_recap`.

---

## 19. Integrasi Setting → Fitur Hilir
Alur lintas-fitur: **ubah `ManageSettings` (tab Koperasi) → buat transaksi → verifikasi angka**.
Resource/Page terkait: `LoanResource` (CreateLoan), `SavingsDepositResource`, `BatchSalaryDeduction`, `ShoppingTransactionResource`. Logika kalkulasi terpusat di `LoanCalculator` — 🟢 `LoanCalculatorTest` (saat ini hanya cek rate default, belum alur ubah-setting).

> **Aturan kunci yang diuji:**
> **Rate pinjaman = SNAPSHOT** saat akad (`CreateLoan::mutateFormDataBeforeCreate` → tersimpan di kolom `loans.admin_fee/swp_amount/monthly_interest/monthly_time_deposit/monthly_principal`). Ubah setting → hanya pinjaman **baru** ikut.
> **Nominal simpanan & `loan_short_term_max` = LIVE-READ** saat input (server override di `SavingsDepositResource::mutateFormDataBeforeCreate`; validasi/helper LoanResource). Saldo lama tetap dibaca dari DB.
> Default: pokok 50.000 · wajib_belanja 100.000 · sukarela_min 0 · admin_fee 1% · swp 1% · bunga 0,65% · tab berjangka 0,1% · short_term_max 1.000.000.

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi | Catatan |
|----|----------|------|-----------|---------|-----------|---------|
| FL-INT-01 | Ubah `loan_short_term_max` → jenis pinjaman ikut | + | — | Set max=500.000 → Create Loan nominal 900.000 | Otomatis jangka_panjang (saat max=1jt tadinya sebrakan), jadwal N baris | |
| FL-INT-02 | Ubah `loan_admin_fee_rate` → admin fee baru | + | Jangka panjang | Set admin_fee=2% → Create Loan Rp 10jt | `loans.admin_fee` = 200.000 tersimpan | |
| FL-INT-03 | Ubah `loan_swp_rate` → SWP baru | + | Jangka panjang | Set swp=2% → Create Loan Rp 10jt | `loans.swp_amount` = 200.000; disbursed berkurang | |
| FL-INT-04 | Ubah `loan_interest_rate` → bunga bulanan baru | + | Jangka panjang | Set bunga=1% → Create Loan Rp 12jt | `monthly_interest` = 120.000 di tiap baris jadwal | |
| FL-INT-05 | Ubah `loan_time_deposit_rate` → tab berjangka baru | + | Jangka panjang | Set tab=0,5% → Create Loan Rp 12jt, bayar lunas | `monthly_time_deposit`=60.000; refund Tab (draft) = 60.000 × jumlah angsuran **terbayar non-reversal** | |
| FL-INT-06 | **Snapshot**: ubah rate setelah pinjaman aktif | + | Pinjaman lama ada | Ubah semua rate → buka View pinjaman lama | Angka pinjaman lama TIDAK berubah | |
| FL-INT-07 | Sebrakan abaikan rate | + | — | Set rate apapun → Create jangka_pendek (≤ max) | admin_fee=0, swp=0, bunga=0, tab=0; jadwal 1 baris | 🟢 (LoanCalculatorTest) |
| FL-INT-08 | Ubah `savings_pokok_amount` → setoran pokok baru | + | Member belum punya Pokok | Set pokok=75.000 → Create deposit Pokok | Server override → `amount`=75.000 tersimpan | |
| FL-INT-09 | Ubah `savings_wajib_belanja_amount` → setoran baru | + | — | Set wajib_belanja=150.000 → setor/batch | `amount`=150.000; saldo lama (100.000) tetap | |
| FL-INT-10 | Batch ikut setting wajib_belanja terbaru | + | — | Ubah setting → BatchSalaryDeduction periode baru | Prefill & deposit pakai nominal baru | |
| FL-INT-11 | Ubah `savings_sukarela_min` → input ≥ min lolos | + | — | Set min=50.000 → setor sukarela 50.000 | Diterima | |
| FL-INT-12 | **Snapshot saldo**: ubah pokok tak ubah saldo lama | + | Member punya Pokok 50.000 | Set pokok=75.000 → buka rekap saldo member | Saldo Pokok tetap 50.000 (dari DB) | |
| FL-INT-13 | Ubah `savings_sukarela_min` → input < min ditolak | − | — | Set min=50.000 → setor sukarela 1.000 | Validasi gagal (minValue) | |
| FL-INT-14 | Ubah `loan_short_term_max` tidak retroaktif | − | Pinjaman sebrakan lama | Set max=500.000 → buka pinjaman sebrakan lama | Jenis & term pinjaman lama tetap | |
| FL-INT-15 | Rate/min negatif di setting | − | — | Set rate/min=-1 → Save settings | Validasi gagal (min:0); kalkulasi tidak terpengaruh | |
