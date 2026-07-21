# Skenario Pengujian — Komponen Livewire (KOPEKOMA)

> Dokumen ini memetakan skenario uji **positif (+)** dan **negatif (−)** untuk seluruh komponen Livewire yang ada.
> Format tabel dirancang agar mudah dikonversi ke Excel (1 baris = 1 test case).
>
> **Legenda Tipe:** `+` = positif/happy path · `−` = negatif/error/edge case
> **Kolom Status** dikosongkan untuk diisi saat eksekusi (Pass/Fail/Blocked).
> Tanda 🟢 = ekspektasi ini sudah benar-benar diuji oleh test Pest yang tepat (sudah diverifikasi, bukan sekadar ada file test sejenis).
> Tanda ⚠ = **perilaku saat ini diduga bug / inkonsisten** — JANGAN tulis test yang mengunci perilaku ini sebagai "benar"; konfirmasi ke tim dulu (expected vs current).

### ⚠ Ringkasan Temuan (perlu konfirmasi tim sebelum jadi test)
| Ref | Temuan |
|-----|--------|
| LW-MBR-28 | `phone_number` divalidasi `required` sebelum normalisasi → input non-digit (`"abc"`) lolos lalu tersimpan `null` diam-diam |
| LW-INS-04 / LW-INT-05 | Refund pelunasan (SWP+Tab) dibuat sebagai withdrawal **draft**, bukan langsung cair — saldo berkurang hanya setelah ACC+Cairkan |

> Catatan lintas-dokumen: sisi **Filament** punya beberapa temuan tambahan (first_due_date tak divalidasi, Edit page withdrawal, unique vs soft-deleted) — lihat `filament-test-scenarios.md`.

**Daftar Modul**
1. [Auth — Login](#1-auth--login)
2. [Master — Anggota (Member)](#2-master--anggota-member)
3. [Master — OPD/Instansi (Agency)](#3-master--opdinstansi-agency)
4. [Master — Golongan (Grade)](#4-master--golongan-grade)
5. [Loan — Pengajuan & Daftar Pinjaman](#5-loan--pengajuan--daftar-pinjaman)
6. [Loan — Blacklist Pinjaman](#6-loan--blacklist-pinjaman)
7. [Loan — Angsuran (Installment)](#7-loan--angsuran-installment)
8. [Loan — Batch Potong Gaji Angsuran](#8-loan--batch-potong-gaji-angsuran)
9. [Savings — Setoran Simpanan (Deposit)](#9-savings--setoran-simpanan-deposit)
10. [Savings — Batch Potong Gaji Simpanan](#10-savings--batch-potong-gaji-simpanan)
11. [Savings — Pencairan Simpanan (Withdrawal)](#11-savings--pencairan-simpanan-withdrawal)
12. [Savings — Belanja Toko (Shopping)](#12-savings--belanja-toko-shopping)
13. [Savings — Registrasi Hari Raya](#13-savings--registrasi-hari-raya)
14. [Savings — Saldo Anggota (Balance & Detail)](#14-savings--saldo-anggota-balance--detail)
15. [System — Users](#15-system--users)
16. [System — Roles & Permission](#16-system--roles--permission)
17. [System — Activity Logs](#17-system--activity-logs)
18. [Settings — Pengaturan Aplikasi](#18-settings--pengaturan-aplikasi)
19. [Settings — Store Clients](#19-settings--store-clients)
20. [Profile — Edit Profil](#20-profile--edit-profil)
21. [Dashboard](#21-dashboard)
22. [Notification Bell](#22-notification-bell)
23. [Integrasi Setting → Fitur Hilir](#23-integrasi-setting--fitur-hilir)

---

## 1. Auth — Login
Komponen: `App\Livewire\Auth\Login`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi |
|----|----------|------|-----------|---------|-----------|
| LW-AUTH-01 | Login kredensial valid | + | User aktif terdaftar | Isi email & password benar → `login()` | Sesi dibuat, redirect ke dashboard |
| LW-AUTH-02 | Login dengan "Ingat saya" | + | User aktif | Centang remember → login | Login sukses + cookie remember-me persisten |
| LW-AUTH-03 | Email tidak terdaftar | − | — | Isi email asing → login | Error `auth.failed`, tidak login |
| LW-AUTH-04 | Password salah | − | User ada | Isi password salah → login | Error `auth.failed`, tidak login |
| LW-AUTH-05 | Email kosong | − | — | Kosongkan email → login | Validasi gagal (required) |
| LW-AUTH-06 | Format email tidak valid | − | — | Isi `abc` → login | Validasi gagal (email) |
| LW-AUTH-07 | Rate limit 5x gagal | − | — | Login salah 6x dengan email+IP sama | Attempt ke-6 di-throttle, pesan `auth.throttle`, event Lockout |
| LW-AUTH-08 | Throttle terpisah per IP+email | + | — | IP A gagal 5x; login dari IP B | IP B tidak ikut terkunci |
| LW-AUTH-09 | Login user nonaktif | − | `is_active=false` | Kredensial benar → login | Ditolak (tidak boleh login) |
| LW-AUTH-10 | Logout | + | Login | POST `/logout` | Sesi di-invalidate + token regenerate, redirect ke login |
| LW-AUTH-11 | Verifikasi email via signed link valid | + | Email belum verified | Klik link `verification.verify` (signed) | Email terverifikasi, redirect profil + toast sukses |
| LW-AUTH-12 | Link verifikasi tidak valid/kadaluarsa | − | — | Akses link dengan signature salah | Ditolak (middleware `signed`), 403 |
| LW-AUTH-13 | Akses halaman auth saat sudah login | − | Sudah login | Buka `/login` | Redirect (middleware `guest`) |

---

## 2. Master — Anggota (Member)
Komponen: `Members`, `MemberForm`, `MemberDetail`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi |
|----|----------|------|-----------|---------|-----------|
| LW-MBR-01 | Create anggota ASN lengkap | + | Punya izin create; agency & grade ada | Isi semua field valid (NIK 16 digit, NIP terisi) → `save()` | Member tersimpan, audit log "created" |
| LW-MBR-02 | Create anggota Honorer (tanpa NIP) | + | employment_status=Honorer | Isi tanpa NIP → save | Tersimpan (NIP nullable untuk Honorer) |
| LW-MBR-03 | Edit anggota | + | Member ada, izin update | Ubah field → save | Terupdate, audit log "updated" + diff |
| LW-MBR-04 | Snapshot mandatory_savings saat create | + | Grade punya nominal | Pilih grade → `updatedGradeId()` | `mandatory_savings_amount` ter-isi otomatis dari grade |
| LW-MBR-05 | Override mandatory_savings (pengurus) | + | Role super_admin/pengurus, mode edit | Ubah nilai mandatory_savings → save | Nilai tersimpan sesuai input |
| LW-MBR-06 | Status Keluar dengan exit_date | + | — | Set status=Keluar + exit_date → save | Tersimpan |
| LW-MBR-07 | Upload dokumen (PDF/JPG/PNG) | + | — | Tambah ≤10 file ≤5MB → save | Media tersimpan koleksi "documents" |
| LW-MBR-08 | Hapus dokumen tersimpan | + | Member punya dokumen, izin update | `deleteDocument(mediaId)` | Media terhapus (soft), audit log |
| LW-MBR-09 | Normalisasi nomor HP | + | — | Input `081234567890` → save | Tersimpan `+6281234567890`, tampil lokal saat edit |
| LW-MBR-10 | Cari anggota (nama/no/NIK/NIP) | + | Ada data | Isi `search` | List terfilter sesuai keyword |
| LW-MBR-11 | Filter status/agency/grade | + | Ada data | Set filter | List sesuai filter |
| LW-MBR-12 | Import Excel valid | + | Izin import; file .xlsx valid ≤10MB | `import()` | Job `ImportMembersJob` ter-queue, toast sukses |
| LW-MBR-13 | Download template | + | Izin import/export | `downloadTemplate()` | File template Excel ter-unduh |
| LW-MBR-14 | Soft-delete anggota | + | Izin delete | `delete(id)` | Soft-delete, audit log |
| LW-MBR-15 | NIK duplikat | − | NIK sudah dipakai | Isi NIK sama → save | Validasi gagal (unique) |
| LW-MBR-16 | NIK bukan 16 digit | − | — | Isi NIK 10 digit → save | Validasi gagal (digits:16) |
| LW-MBR-17 | NIP kosong untuk ASN | − | employment_status=ASN | Kosongkan NIP → save | Validasi gagal (required_if) |
| LW-MBR-18 | birth_date di masa depan | − | — | Set birth_date besok → save | Validasi gagal (before_or_equal:today) |
| LW-MBR-19 | Gender selain L/P | − | — | Set gender `X` → save | Validasi gagal (in) |
| LW-MBR-20 | agency_id/grade_id tidak ada | − | — | Set FK asing → save | Validasi gagal (exists) |
| LW-MBR-21 | Status Keluar/Meninggal tanpa exit_date | − | — | Set Keluar, exit_date kosong → save | Validasi gagal (required_if) |
| LW-MBR-22 | Upload dokumen tipe terlarang | − | — | Unggah `.exe` → save | Validasi gagal (mimes) |
| LW-MBR-23 | Upload dokumen >5MB / >10 file | − | — | Unggah file besar / 11 file | Validasi gagal (max) |
| LW-MBR-24 | Import file non-xlsx/csv | − | — | Unggah `.txt` → import | Validasi gagal (mimes) |
| LW-MBR-25 | Override mandatory_savings (non-pengurus) | − | Role petugas, mode edit | Coba ubah nilai | Field readonly, nilai tidak berubah |
| LW-MBR-26 | Delete tanpa izin | − | Role tanpa permission delete | `delete(id)` | 403 Forbidden |
| LW-MBR-27 | NIK = NIK milik member soft-deleted | + | Ada member ter–soft-delete dgn NIK X | Create member baru NIK X → save | Diterima (`Rule::unique()->withoutTrashed()` di MemberForm) |
| LW-MBR-28 | Telepon non-digit lolos required lalu jadi null | ⚠ | — | Isi phone_number `"abc"` → save | **Dugaan bug:** lolos `required` (validasi sebelum normalisasi) lalu tersimpan `null` diam-diam. Ekspektasi seharusnya: validasi gagal |

---

## 3. Master — OPD/Instansi (Agency)
Komponen: `Agencies`, `AgencyDetail` — 🟢 `MasterAgenciesLivewireTest`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi | Catatan |
|----|----------|------|-----------|---------|-----------|---------|
| LW-AGN-01 | Create OPD valid | + | Izin create | Isi code+nama+status → `save()` | Tersimpan | 🟢 |
| LW-AGN-02 | Generate kode otomatis | + | — | `generateCode()` | Format `OPD-NNNN`, unik | 🟢 |
| LW-AGN-03 | Edit OPD | + | OPD ada, izin update | `edit(id)` → ubah → save | Terupdate; kode tetap unik (self-ignore) | 🟢 |
| LW-AGN-04 | Toggle status aktif | + | — | `toggleActive(id)` | Status Aktif↔Non-Aktif | 🟢 |
| LW-AGN-05 | Delete OPD tanpa anggota | + | OPD tanpa member | `delete(id)` | Terhapus | 🟢 |
| LW-AGN-06 | Normalisasi telepon PIC | + | — | Input `08xxx` → save | Tersimpan `+62xxx` | 🟢 |
| LW-AGN-07 | Cari/filter OPD | + | Ada data | Isi search / set status | List terfilter | 🟢 |
| LW-AGN-08 | Field opsional kosong | + | — | address & pic_phone kosong → save | Tersimpan (nullable) | |
| LW-AGN-09 | Kode kosong | − | — | Kosongkan code → save | Validasi gagal (required) | 🟢 |
| LW-AGN-10 | Kode duplikat | − | Kode sudah ada | Input kode sama → save | Validasi gagal (unique) | 🟢 |
| LW-AGN-11 | Kode >10 / nama >150 char | − | — | Input melebihi batas → save | Validasi gagal (max) | |
| LW-AGN-12 | statusForm invalid | − | — | Set status `Xyz` → save | Validasi gagal (in) | |
| LW-AGN-13 | Delete OPD yang punya anggota | − | OPD punya member | `delete(id)` | Error toast, tidak terhapus | 🟢 |
| LW-AGN-14 | Aksi tanpa izin (create/update/delete) | − | Role non-admin | Panggil aksi | 403 Forbidden | 🟢 |

---

## 4. Master — Golongan (Grade)
Komponen: `Grades`, `GradeDetail`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi |
|----|----------|------|-----------|---------|-----------|
| LW-GRD-01 | Create golongan valid | + | Izin create | Isi code+name+amount → `save()` | Tersimpan |
| LW-GRD-02 | Generate kode otomatis | + | — | `generateCode()` | Format `GOL-NNNN`, unik |
| LW-GRD-03 | Edit golongan | + | Golongan ada | `edit(id)` → ubah → save | Terupdate |
| LW-GRD-04 | Toggle aktif | + | — | `toggleActive(id)` | `is_active` terbalik |
| LW-GRD-05 | amount = 0 diterima | + | — | Set mandatory_savings_amount=0 → save | Tersimpan (min:0) |
| LW-GRD-06 | Cari/filter golongan | + | Ada data | search / filter status | List terfilter |
| LW-GRD-07 | Code/name/amount kosong | − | — | Kosongkan salah satu → save | Validasi gagal (required) |
| LW-GRD-08 | amount negatif | − | — | Set amount=-1 → save | Validasi gagal (min:0) |
| LW-GRD-09 | Code duplikat | − | Code sudah ada | Input sama → save | Validasi gagal (unique) |
| LW-GRD-10 | Code >15 / name >50 | − | — | Input melebihi → save | Validasi gagal (max) |
| LW-GRD-11 | Delete golongan yang punya anggota | − | Golongan dipakai member | `delete(id)` | Error toast, tidak terhapus |
| LW-GRD-12 | Aksi tanpa izin | − | Role non-admin | Panggil aksi | 403 Forbidden |

---

## 5. Loan — Pengajuan & Daftar Pinjaman
Komponen: `Loan\LoanForm`, `Loan\Loans`, `Loan\LoanDetail` — 🟢 `LoanDetailLivewireTest`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi | Catatan |
|----|----------|------|-----------|---------|-----------|---------|
| LW-LON-01 | Create pinjaman jangka panjang | + | Member non-blacklist; nominal > loan_short_term_max | Isi form valid → `save()` | Loan status "Cair" + N jadwal angsuran (sesuai term_months) | |
| LW-LON-02 | Create pinjaman jangka pendek (sebrakan) | + | Nominal ≤ loan_short_term_max | Pilih jangka_pendek → save | Loan dibuat, jadwal 1 baris | |
| LW-LON-03 | Pencairan transfer prefill rekening | + | disbursement_method=transfer | Pilih transfer | Bank & no rekening anggota terprefill | |
| LW-LON-04 | first_due_date auto +1 bulan | + | — | Set disbursement_date | first_due_date otomatis 1 bulan setelahnya |  |
| LW-LON-05 | Preview rincian server-side | + | Input nominal | Lihat preview | admin_fee/SWP/bunga/time_deposit dihitung server (LoanCalculator) | |
| LW-LON-06 | Warning tunggakan & beban potongan | + | Member punya pinjaman berjalan | Pilih member | Tampil peringatan tunggakan & beban potong gaji bulanan | |
| LW-LON-07 | Filter daftar (q/type/status/arrears) | + | Ada data | Set filter | List terfilter; statistik aktif/lunas/overdue/outstanding tampil | |
| LW-LON-08 | Batalkan pinjaman bersih (LoanDetail) | + | Status Cair, belum ada angsuran terbayar | `openCorrect()` → reason ≥5 char → `performCorrect()` | Status→Dibatalkan, jadwal dibersihkan, record dipertahankan (soft) | 🟢 |
| LW-LON-09 | Koreksi salah-input (Loans list) | + | Status Cair, belum ada bayar | `openCorrect(id)`→`performCorrect()` reason ≥5 | Hard-delete loan + jadwal, audit log | |
| LW-LON-10 | Toggle lihat semua jadwal | + | Loan ada | `showAllSchedules=true` | Tampil seluruh proyeksi jadwal | |
| LW-LON-11 | Member blacklist tidak bisa diajukan | − | Member punya blacklist aktif | Cari member di picker | Member tidak muncul/ditolak saat save |  |
| LW-LON-12 | Jangka pendek tapi nominal > max | − | — | jangka_pendek + nominal besar → save | Validasi bisnis gagal |  |
| LW-LON-13 | Jangka panjang tapi nominal ≤ max | − | — | jangka_panjang + nominal kecil → save | Validasi bisnis gagal | |
| LW-LON-14 | principal_amount < 1 / negatif | − | — | Input 0/-1000 → save | Validasi gagal (min:1) | |
| LW-LON-15 | term_months di luar 1–120 | − | — | Input 0 / 200 → save | Validasi gagal (min/max) | |
| LW-LON-16 | first_due_date < disbursement_date | − | — | Set tanggal mundur → save | Validasi gagal (after_or_equal) | |
| LW-LON-17 | Transfer tanpa bank/no rekening | − | method=transfer | Kosongkan bank → save | Validasi gagal (required_if) | |
| LW-LON-18 | Batalkan dengan angsuran sudah terbayar | − | Ada installment recorded | `performCorrect()` | Ditolak / abort(403) | 🟢 |
| LW-LON-19 | Batalkan reason < 5 char | − | — | reason "abc" → performCorrect | Validasi gagal (min:5) | 🟢 |
| LW-LON-20 | Upload berkas tipe/ukuran ilegal | − | — | Unggah `.exe` / >5MB | Validasi gagal | |
| LW-LON-21 | Boundary: nominal == loan_short_term_max (jangka pendek) | + | nominal tepat = max | jangka_pendek, nominal=max → save | Diterima (operator `> max` yang ditolak; `==` sah sebagai sebrakan) |
| LW-LON-22 | Boundary: nominal == max tapi jangka_panjang | − | nominal tepat = max | jangka_panjang, nominal=max → save | Ditolak (`<= max` untuk jangka panjang gagal) |

---

## 6. Loan — Blacklist Pinjaman
Komponen: `Loan\Blacklist\LoanBlacklists`, `LoanBlacklistDetail`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi |
|----|----------|------|-----------|---------|-----------|
| LW-BLK-01 | Tandai blacklist baru | + | Member belum blacklist aktif | `openCreate()` → reason ≥5 → `store()` | LoanBlacklist (is_active=true, recorded_by=auth) |
| LW-BLK-02 | Lepas blacklist | + | Blacklist aktif | `openRelease(id)`→`performRelease()` | is_active=false, released_at=hari ini |
| LW-BLK-03 | Member rilis bisa pinjam lagi | + | Setelah release | Buka LoanForm | Member kembali muncul di picker |
| LW-BLK-04 | Filter q / active | + | Ada data | Set filter | List terfilter |
| LW-BLK-05 | Tandai member yang sudah blacklist aktif | − | Member punya blacklist aktif | Coba pilih member | Member tersembunyi / validasi cegah duplikat |
| LW-BLK-06 | reason < 5 char | − | — | reason "ab" → store | Validasi gagal (min:5) |
| LW-BLK-07 | blacklisted_at kosong | − | — | Kosongkan tanggal → store | Validasi gagal (required) |

---

## 7. Loan — Angsuran (Installment)
Komponen: `Loan\Installment\InstallmentForm`, `Installments`, `InstallmentDetail` — 🟢 `InstallmentLivewireTest`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi | Catatan |
|----|----------|------|-----------|---------|-----------|---------|
| LW-INS-01 | Bayar angsuran pas tagihan | + | Loan Cair, ada jadwal belum bayar | Pilih member→loan→schedule (FIFO), amount=total_due → `pay()` | Installment tercatat, jadwal jadi Terbayar | Happy-path `pay()` BELUM diuji di `InstallmentLivewireTest` (yang ada hanya bill-detail & bukti); analog Filament ada di `InstallmentResourceTest` |
| LW-INS-02 | Prefill bill detail | + | Schedule dipilih | `loadSchedule()` | amount_paid prefill = total_due; tampil rincian pokok/bunga/tab | 🟢 |
| LW-INS-03 | Bayar lebih (overpay) | + | — | amount_paid > tagihan → pay | Kelebihan masuk Simpanan Sukarela | |
| LW-INS-04 | Pelunasan terakhir | + | Sisa 1 jadwal | Bayar jadwal terakhir | Loan→Lunas; refund SWP + Tabungan otomatis dibuat sebagai **withdrawal status `draft`** (saldo belum berkurang — perlu ACC + Cairkan) | Refund = draft (LoanPaymentService::makeRefund), bukan langsung cair |
| LW-INS-05 | Upload bukti PDF/JPG | + | — | Lampirkan bukti valid → pay | Bukti tersimpan, baris di-flag punya bukti | 🟢 |
| LW-INS-06 | Prefill dari URL ?loan=id | + | Buka dari detail pinjaman | Buka form dengan query | loan_id terprefill |
| LW-INS-07 | Render bukti (gambar inline, PDF tombol) | + | Installment punya bukti | Buka detail | Gambar tampil inline; PDF sebagai tombol | 🟢 |
| LW-INS-08 | Reversal angsuran | + | Installment asli (bukan reversal) | `openReverse()` reason ≥5 →`performReverse()` | Jadwal→Belum Bayar, record reversal dibuat (LoanPaymentService) | |
| LW-INS-09 | Filter q/method/reversal | + | Ada data | Set filter | List terfilter |
| LW-INS-10 | Amount < tagihan | − | — | amount_paid < total_due → pay | Validasi gagal (anti-korupsi) | Diuji di `InstallmentResourceTest` (Filament), belum di `InstallmentLivewireTest` |
| LW-INS-16 | Overpay → kelebihan ke Sukarela | + | — | amount_paid > total_due → pay | Kelebihan dikreditkan Simpanan Sukarela (SavingsDeposit baru) | Logika di LoanPaymentService; belum ada test Livewire |
| LW-INS-11 | Bukti tipe terlarang | − | — | Lampirkan `.exe` → pay | Validasi gagal (mimes) | 🟢 |
| LW-INS-12 | Reversal atas record yang sudah di-reversal | − | is_reversal / sudah direversal | Buka aksi reverse | Aksi tersembunyi/ditolak |
| LW-INS-13 | payment_date di masa depan | − | — | Set tanggal besok → pay | Validasi gagal (before_or_equal:today) |
| LW-INS-14 | Bayar tanpa pilih schedule | − | — | Kosongkan schedule_id → pay | Validasi gagal (required) |
| LW-INS-15 | Reversal reason < 5 char | − | — | reason "x" → performReverse | Validasi gagal (min:5) |

---

## 8. Loan — Batch Potong Gaji Angsuran
Komponen: `Loan\Installment\BatchInstallmentPayment` — 🟢 `BatchInstallmentPaymentLivewireTest`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi | Catatan |
|----|----------|------|-----------|---------|-----------|---------|
| LW-BIP-01 | Muat anggota OPD + pinjaman aktif | + | Izin `access_batch_salary_deduction`; OPD punya member berpinjaman | `updatedAgencyId()` | Baris per member + pinjaman Cair; jadwal terlama (FIFO); prefill = tagihan | 🟢 |
| LW-BIP-02 | Proses batch sebagian | + | — | Centang sebagian member/loan → `process()` | Hanya yang dicentang diproses; return {created, skipped} | 🟢 |
| LW-BIP-03 | Lampir bukti per baris | + | — | Tambah bukti per schedule → process | Bukti ter-attach ke Installment terkait | 🟢 |
| LW-BIP-04 | Skip baris yang di-uncheck | + | — | Matikan toggle satu baris → process | Baris itu di-skip | 🟢 |
| LW-BIP-05 | Grand total hanya yang included | + | — | Centang sebagian | Total = Σ baris included saja | 🟢 |
| LW-BIP-06 | Select/deselect semua | + | — | `setAllIncluded(true/false)` | Semua member ter-/tidak tercentang |
| LW-BIP-07 | Akses tanpa permission | − | Role tanpa izin | Buka komponen | 403 / akses ditolak | 🟢 |
| LW-BIP-08 | Skip jadwal sudah terbayar / loan Lunas | − | Member loan Lunas | process | Baris di-skip otomatis |
| LW-BIP-09 | OPD tanpa pinjaman aktif | − | OPD kosong | Pilih OPD | Grid kosong, tidak ada yang diproses |

---

## 9. Savings — Setoran Simpanan (Deposit)
Komponen: `Savings\Deposit\SavingsDepositForm`, `SavingsDeposits`, `SavingsDepositDetail`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi |
|----|----------|------|-----------|---------|-----------|
| LW-DEP-01 | Setor Pokok | + | Member belum punya Pokok | Centang pokok (locked) → `save()` | 1 SavingsDeposit pokok dibuat |
| LW-DEP-02 | Setor Wajib (prefill grade) | + | — | Wajib prefill dari golongan, edit bila perlu → save | Deposit wajib tersimpan |
| LW-DEP-03 | Setor Sukarela ≥ min | + | — | Isi sukarela ≥ savings_sukarela_min → save | Deposit sukarela tersimpan |
| LW-DEP-04 | Setor Wajib Belanja (locked) | + | — | Centang wajib_belanja → save | Nominal terkunci dari setting tersimpan |
| LW-DEP-05 | Setor Hari Raya (ada registrasi) | + | Registrasi aktif utk periode | Centang hari_raya (locked) → save | Deposit hari_raya periode tahun program |
| LW-DEP-06 | Setor beberapa jenis sekaligus | + | — | Centang beberapa baris → save | 1 deposit per jenis yang dicentang & amount>0 |
| LW-DEP-07 | Rebuild lines saat ganti member/tanggal/periode | + | — | Ubah member/deposit_date/period_month | Lines dibangun ulang, jenis sudah-disetor tersembunyi |
| LW-DEP-08 | Reversal setoran | + | Deposit asli | `openReverse()` reason ≥5 →`performReverse()` | Reversal dibuat (ReverseTransaction), saldo disesuaikan |
| LW-DEP-09 | Filter q/type/method/reversal | + | Ada data | Set filter | List terfilter |
| LW-DEP-10 | Idempotency cegah double-submit | + | — | Submit 2x cepat | Hanya 1 record per idempotency_key |
| LW-DEP-11 | Jenis sudah disetor di periode | − | Jenis sudah ada periode itu | Coba setor ulang | Jenis tersembunyi / skip + toast info |
| LW-DEP-12 | Pokok kedua kali | − | Member sudah punya Pokok | Coba setor pokok lagi | Tidak diizinkan (1x seumur keanggotaan) |
| LW-DEP-13 | Sukarela < minimal | − | — | Isi sukarela < min → save | Validasi gagal |
| LW-DEP-14 | Wajib included tapi amount 0 | − | — | Centang wajib, amount=0 → save | Validasi gagal (required/min) |
| LW-DEP-15 | Hari Raya tanpa registrasi aktif | − | Tidak ada registrasi | Coba setor hari_raya | Tidak muncul / toast error, tidak tersimpan |
| LW-DEP-16 | deposit_date di masa depan | − | — | Set besok → save | Validasi gagal (before_or_equal:today) |
| LW-DEP-17 | Reversal atas record reversal | − | is_reversal=true | Buka aksi reverse | Aksi tersembunyi/ditolak |

---

## 10. Savings — Batch Potong Gaji Simpanan
Komponen: `Savings\Deposit\BatchSalaryDeduction` — 🟢 `BatchSalaryDeductionLivewireTest`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi | Catatan |
|----|----------|------|-----------|---------|-----------|---------|
| LW-BSD-01 | Muat anggota OPD + jenis simpanan | + | Izin `access_batch_salary_deduction` | `updatedAgencyId()` | Baris per member dengan jenis wajib/pokok/wajib_belanja (+hari_raya bila ada) |
| LW-BSD-02 | Prefill nominal per jenis | + | — | Lihat grid | wajib=member.mandatory; pokok/wajib_belanja=setting; hari_raya=registrasi |
| LW-BSD-03 | Proses batch | + | — | Centang member+jenis → `process()` | SavingsDeposit dibuat per baris included & belum done; return {created, skipped} |
| LW-BSD-04 | Sukarela opsional terisi | + | — | Isi nominal sukarela → process | Deposit sukarela dibuat | 🟢 |
| LW-BSD-05 | Sukarela dibiarkan kosong | + | — | Biarkan sukarela tanpa nominal → process | Baris sukarela di-skip (nullable) | 🟢 |
| LW-BSD-06 | Rebuild saat ganti periode/OPD | + | — | `updatedPeriodMonth()` / `updatedAgencyId()` | Grid dibangun ulang |
| LW-BSD-07 | Select/deselect semua | + | — | `setAllIncluded(true/false)` | Semua member ter-/tidak tercentang |
| LW-BSD-08 | Akses tanpa permission | − | Tanpa izin | Buka komponen | 403 / akses ditolak |
| LW-BSD-09 | Pokok untuk member yang sudah punya Pokok | − | hasActivePokok=true | process | Baris pokok di-skip |
| LW-BSD-10 | Jenis sudah disetor (done) | − | done=true | process | Baris di-skip |

---

## 11. Savings — Pencairan Simpanan (Withdrawal)
Komponen: `Savings\Withdrawal\SavingsWithdrawalForm`, `SavingsWithdrawals`, `SavingsWithdrawalDetail` — 🟢 `SavingsWithdrawalLivewireTest`, `WithdrawalWorkflowTest`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi | Catatan |
|----|----------|------|-----------|---------|-----------|---------|
| LW-WDR-01 | Build lines dari saldo | + | Member punya saldo | Pilih member → `rebuildLines()` | Hanya sumber dgn balance>0 tampil | 🟢 |
| LW-WDR-02 | Ajukan pencairan Sukarela | + | Saldo sukarela cukup | Centang sukarela, amount ≤ balance → `save()` | Withdrawal status draft | 🟢 |
| LW-WDR-03 | Ajukan pencairan Hari Raya per tahun | + | Saldo hari_raya tahun X | Pilih tahun, amount ≤ saldo → save | Draft dibuat untuk tahun program | 🟢 |
| LW-WDR-04 | Workflow approve→disburse | + | Draft ada; izin approve/disburse | `openConfirm(approve)`→`openConfirm(disburse)` | draft→acc→cair | 🟢 |
| LW-WDR-05 | Tolak pengajuan | + | Status draft/acc; izin approve | `openConfirm(reject)` | Status→ditolak (final) | 🟢 |
| LW-WDR-06 | Reversal pencairan cair | + | Status cair, bukan refund loan | `openReverse()` reason ≥5 | Reversal dibuat, saldo kembali | 🟢 |
| LW-WDR-07 | Refund pair SWP+Tab (D2) | + | Loan Lunas hasilkan pasangan | Lihat list | Ditampilkan 1 entri "Pengembalian Pelunasan" (swp+tab) | 🟢 |
| LW-WDR-08 | Transisi pair atomik | + | Refund pair draft | Approve/disburse/reject salah satu | Kedua baris (swp+tab) berubah bersamaan | 🟢 |
| LW-WDR-09 | Available balance kurangi pending (D3) | + | Ada draft/acc pending tipe sama | Build lines SWP | Available = balance − pending | 🟢 |
| LW-WDR-10 | Amount > saldo | − | — | amount > balance → save | Validasi gagal | 🟢 |
| LW-WDR-11 | Hari Raya tanpa pilih tahun | − | type=hari_raya | Kosongkan period_year → save | Validasi gagal (required) |
| LW-WDR-12 | Approve record non-draft | − | Status bukan draft | openConfirm(approve) | No-op / aksi tersembunyi | 🟢 |
| LW-WDR-13 | Disburse record non-acc | − | Status bukan acc | openConfirm(disburse) | No-op / aksi tersembunyi | 🟢 |
| LW-WDR-14 | Reversal record non-cair | − | Status bukan cair | openReverse | Aksi tersembunyi | 🟢 |
| LW-WDR-15 | Reversal refund pair langsung | − | isLoanRefund=true | openReverse | Ditolak (reversal lewat reversal angsuran) | 🟢 |
| LW-WDR-16 | Akses detail tanpa izin | − | Role tanpa izin | Buka detail | Diblokir | 🟢 |

---

## 12. Savings — Belanja Toko (Shopping)
Komponen: `Savings\Shopping\ShoppingTransactionForm`, `ShoppingTransactions`, `ShoppingTransactionDetail`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi |
|----|----------|------|-----------|---------|-----------|
| LW-SHP-01 | Catat pemakaian ≤ saldo | + | Saldo Wajib Belanja cukup | Pilih member, amount ≤ saldo → `save()` | Transaksi recorded (RecordShoppingUsage) |
| LW-SHP-02 | Saldo Wajib Belanja tampil read-only | + | Member dipilih | `shoppingBalance()` | Saldo tampil, tidak editable |
| LW-SHP-03 | Reversal pemakaian | + | Transaksi asli | `openReverse()` reason ≥5 →`performReverse()` | Reversal dibuat, saldo Wajib Belanja kembali |
| LW-SHP-04 | Idempotency double-submit (sekuensial, 1 mount) | + | — | Submit 2x dari form yang sama | UniqueConstraint ditangkap, hanya 1 record. **Catatan:** key di-generate per-mount → TIDAK menjamin idempoten lintas-request/race (reload = key baru) |
| LW-SHP-05 | Filter q/reversal | + | Ada data | Set filter | List terfilter |
| LW-SHP-06 | Amount > saldo | − | — | amount > saldo Wajib Belanja → save | Validasi gagal (custom validator) |
| LW-SHP-07 | Amount < 1 / negatif | − | — | amount 0/-100 → save | Validasi gagal (min:1) |
| LW-SHP-08 | transaction_date masa depan | − | — | Set besok → save | Validasi gagal (before_or_equal:today) |
| LW-SHP-09 | Reversal atas record reversal | − | is_reversal=true | Buka aksi reverse | Aksi tersembunyi/ditolak |

---

## 13. Savings — Registrasi Hari Raya
Komponen: `Savings\Holiday\HolidayRegistrationForm`, `HolidayRegistrations`, `HolidayRegistrationDetail`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi |
|----|----------|------|-----------|---------|-----------|
| LW-HOL-01 | Daftar Hari Raya baru | + | Member belum terdaftar tahun itu | Isi member/tanggal/monthly_amount → `save()` | MemberHolidaySaving dibuat, period_year dari end_date |
| LW-HOL-02 | Update registrasi | + | Registrasi ada | Ubah nominal/tanggal → save | Terupdate |
| LW-HOL-03 | Nonaktifkan registrasi | + | — | Set is_active=false → save | Setoran hari_raya tahun itu tak lagi tersedia |
| LW-HOL-04 | Hapus registrasi | + | — | `delete(id)` | MemberHolidaySaving terhapus |
| LW-HOL-05 | Lihat detail + saldo + setoran | + | Ada deposit | Buka detail | Saldo terkumpul + daftar setoran + audit trail |
| LW-HOL-06 | Filter q/year/active | + | Ada data | Set filter | List terfilter |
| LW-HOL-07 | Member duplikat di tahun sama | − | Sudah terdaftar tahun itu | Daftar member sama → save | Validasi gagal (unique per period_year) |
| LW-HOL-08 | end_date < start_date | − | — | Set end < start → save | Validasi gagal (after_or_equal) |
| LW-HOL-09 | monthly_amount < 1 | − | — | Set 0 → save | Validasi gagal (min:1) |

---

## 14. Savings — Saldo Anggota (Balance & Detail)
Komponen: `Savings\MemberBalances`, `Savings\MemberSavingsDetail`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi |
|----|----------|------|-----------|---------|-----------|
| LW-BAL-01 | Daftar saldo semua anggota | + | Ada data | Buka MemberBalances | Tabel saldo per jenis + total, default status Aktif |
| LW-BAL-02 | Filter q/agency/grade/status | + | Ada data | Set filter | List terfilter |
| LW-BAL-03 | Detail saldo + ledger | + | Member ada | Buka MemberSavingsDetail | Kartu saldo per jenis + breakdown hari raya per tahun |
| LW-BAL-04 | Filter ledger per jenis | + | — | Set `type` | Mutasi terfilter; total masuk/keluar/saldo akhir benar |
| LW-BAL-05 | Saldo akhir = masuk − keluar | + | Ada mutasi | Lihat total | Saldo akhir konsisten dengan ledger |
| LW-BAL-06 | Komponen read-only | − | — | Coba ubah data | Tidak ada aksi tulis (display saja) |

---

## 15. System — Users
Komponen: `System\Users`, `System\UserForm` — 🟢 `SystemUsersLivewireTest`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi | Catatan |
|----|----------|------|-----------|---------|-----------|---------|
| LW-USR-01 | Create user + roles | + | super_admin | Isi name/email/password/roles → `save()` | User dibuat, password ter-hash, roles ter-sync | 🟢 |
| LW-USR-02 | Set email terverifikasi | + | — | email_verified=true → save | email_verified_at terisi `now()` | 🟢 |
| LW-USR-03 | Edit user, password kosong | + | User ada | Edit tanpa isi password → save | Password lama dipertahankan | 🟢 |
| LW-USR-04 | Toggle aktif user lain | + | — | `toggleActive(id)` | is_active terbalik | 🟢 |
| LW-USR-05 | Hapus user lain | + | — | `delete(id)` | User terhapus | 🟢 |
| LW-USR-06 | Cari user (name/email) | + | Ada data | Isi search | List terfilter | 🟢 |
| LW-USR-07 | Field wajib kosong | − | — | Kosongkan name/email/password → save | Validasi gagal (required) | 🟢 |
| LW-USR-08 | Password < 8 / konfirmasi mismatch | − | — | Isi password lemah → save | Validasi gagal | 🟢 |
| LW-USR-09 | Email duplikat | − | Email dipakai | Isi email sama → save | Validasi gagal (unique) | 🟢 |
| LW-USR-10 | Role tidak ada | − | — | selectedRoles berisi role asing → save | Validasi gagal (exists) | |
| LW-USR-11 | Hapus diri sendiri | − | Edit/list diri | `delete(self.id)` | Ditolak, user tetap ada | 🟢 |
| LW-USR-12 | Nonaktifkan diri sendiri | − | Edit diri | Set is_active=false → save | Dipaksa tetap aktif (anti-lockout) | 🟢 |
| LW-USR-13 | Akses tanpa super_admin | − | Role lain | Buka komponen | 403 Forbidden | 🟢 |

---

## 16. System — Roles & Permission
Komponen: `System\Roles`, `System\RoleForm`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi |
|----|----------|------|-----------|---------|-----------|
| LW-ROL-01 | Create role | + | super_admin | Isi name → `save()` | Role dibuat |
| LW-ROL-02 | Edit role + sync permission | + | Role ada | Pilih permission → save | Permission ter-sync |
| LW-ROL-03 | Select all / clear / toggle group | + | Bukan super_admin | `selectAllPermissions()`/`clearPermissions()`/`toggleGroup()` | Checkbox sesuai aksi |
| LW-ROL-04 | Hapus role tanpa user | + | Role tanpa user | `delete(id)` | Terhapus |
| LW-ROL-05 | Name duplikat (per guard) | − | Name dipakai | Isi sama → save | Validasi gagal (unique) |
| LW-ROL-06 | Name kosong | − | — | Kosongkan → save | Validasi gagal (required) |
| LW-ROL-07 | Hapus role super_admin | − | — | `delete(super_admin)` | Ditolak |
| LW-ROL-08 | Hapus role yang punya user | − | Role dipakai user | `delete(id)` | Error toast, tidak terhapus |
| LW-ROL-09 | Edit permission super_admin | − | Edit role super_admin | Coba uncheck permission | Readonly, tidak bisa diubah (Gate::before) |
| LW-ROL-10 | Aksi tanpa super_admin | − | Role lain | save/delete | 403 Forbidden |

---

## 17. System — Activity Logs
Komponen: `System\ActivityLogs`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi |
|----|----------|------|-----------|---------|-----------|
| LW-ACT-01 | Lihat daftar log | + | super_admin/pengurus | Buka komponen | List aktivitas (paginate 10) |
| LW-ACT-02 | Cari (deskripsi/causer) | + | Ada data | Isi search | List terfilter |
| LW-ACT-03 | Filter event/subject/causer/tanggal | + | Ada data | Set filter | List terfilter |
| LW-ACT-04 | Lihat diff aktivitas | + | — | Klik aktivitas → panel | Tampil diff old/new |
| LW-ACT-05 | Clear filter | + | — | `clearFilters()` | Semua filter reset |
| LW-ACT-06 | Akses tanpa izin | − | Role lain | Buka komponen | 403 / diblokir |
| LW-ACT-07 | Filter tanpa hasil | − | — | Filter rentang kosong | List kosong (tidak error) |

---

## 18. Settings — Pengaturan Aplikasi
Komponen: `Settings\ManageSettings`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi |
|----|----------|------|-----------|---------|-----------|
| LW-SET-01 | Set warna tema (hex valid) | + | — | theme_primary `#FF0000` → `save()` | Tersimpan |
| LW-SET-02 | Reset tema | + | — | `resetTheme()` | primary & secondary null |
| LW-SET-03 | Update branding aplikasi | + | — | Ubah app_name, upload logo/favicon → save | Tersimpan |
| LW-SET-04 | Kelola gambar login (add/reorder/remove) | + | — | Tambah ≤6, `moveLoginImage()`, `removeLoginImage()` | Urutan & daftar sesuai aksi |
| LW-SET-05 | Simpan konfigurasi SMTP | + | — | Isi host/port/from valid → save | Tersimpan |
| LW-SET-06 | Kirim email tes | + | SMTP valid | `sendTestEmail()` | Email terkirim, toast sukses |
| LW-SET-07 | Simpan setting koperasi | + | — | Isi semua rate/amount valid → save | Tersimpan |
| LW-SET-08 | Hex warna tidak valid | − | — | theme_primary `xyz` → save | Validasi gagal (regex) |
| LW-SET-09 | Logo >2MB / favicon >1MB / login >4MB | − | — | Upload melebihi → save | Validasi gagal (max) |
| LW-SET-10 | Upload non-image | − | — | Upload `.pdf` sebagai logo → save | Validasi gagal (image) |
| LW-SET-11 | Tambah >6 gambar login | − | — | Tambah gambar ke-7 | Ditolak (MAX_LOGIN_IMAGES) |
| LW-SET-12 | mail_host kosong / from invalid | − | — | Kosongkan host / from `abc` → save | Validasi gagal |
| LW-SET-13 | mail_port di luar 1–65535 | − | — | port 70000 → save | Validasi gagal |
| LW-SET-14 | mail_encryption selain tls/ssl | − | — | Set `xxx` → save | Validasi gagal (in) |
| LW-SET-15 | Rate/amount negatif atau non-numeric | − | — | Isi -1 / "abc" → save | Validasi gagal (numeric/min:0) |
| LW-SET-16 | Test email recipient invalid | − | — | testRecipient `abc` → sendTestEmail | Validasi gagal |
| LW-SET-17 | Test email SMTP gagal | − | SMTP salah | sendTestEmail | Exception ditangkap, toast error |

---

## 19. Settings — Store Clients
Komponen: `Settings\StoreClients` — 🟢 `ManageStoreClientTest`, `StoreClientTest`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi | Catatan |
|----|----------|------|-----------|---------|-----------|---------|
| LW-STC-01 | Create store client | + | super_admin | Isi name → `createClient()` | client_id (`store_*`) + secret di-generate; secret di-hash & encrypted | 🟢 |
| LW-STC-02 | Default can_refund=false | + | — | Create tanpa centang refund | can_refund=false | 🟢 |
| LW-STC-03 | Regenerate secret | + | Client ada | `regenerate(id)` | Secret baru; hash & encrypted copy diperbarui | 🟢 |
| LW-STC-04 | Toggle active / refund | + | — | `toggleActive()`/`toggleRefund()` | Flag terbalik |
| LW-STC-05 | Reveal secret dgn password | + | Izin copy_store_client_secret | `openReveal()` → password benar → `confirmReveal()` | Credential tampil, activity log `reveal_secret` | 🟢 |
| LW-STC-06 | Delete client | + | — | `deleteClient(id)` | Soft-delete |
| LW-STC-07 | Name kosong / >100 char | − | — | Kosongkan/lebihi → createClient | Validasi gagal | 🟢 |
| LW-STC-08 | Reveal password salah | − | — | Password salah → confirmReveal | Validasi gagal (current_password) | 🟢 |
| LW-STC-09 | Reveal client legacy (tanpa encrypted) | − | Client lama | openReveal | Error "secret belum tersedia"; aksi tersembunyi | 🟢 |
| LW-STC-10 | Akses tanpa permission | − | Role lain | openReveal | Ditolak (canCopySecret=false) | 🟢 |

---

## 20. Profile — Edit Profil
Komponen: `Profile\EditProfile`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi |
|----|----------|------|-----------|---------|-----------|
| LW-PRF-01 | Update nama | + | Login | Ubah name → `saveAccount()` | Tersimpan |
| LW-PRF-02 | Update email (dgn password) | + | — | Ubah email + current_password benar → saveAccount | Tersimpan, email_verified_at=null, verifikasi dikirim |
| LW-PRF-03 | Ganti password | + | — | current_password benar + password baru confirmed → `savePassword()` | Password terganti |
| LW-PRF-04 | Upload avatar | + | — | Pilih image ≤2MB → `savePhoto()` | Avatar tersimpan, file lama dihapus |
| LW-PRF-05 | Hapus avatar | + | Punya avatar | `removePhoto()` | Avatar terhapus, fallback inisial |
| LW-PRF-06 | Kirim ulang verifikasi | + | Belum verified | `resendVerification()` | Notifikasi verifikasi dikirim |
| LW-PRF-07 | Resend saat sudah verified | + | Sudah verified | resendVerification | Toast "sudah terverifikasi" |
| LW-PRF-08 | Nama kosong | − | — | Kosongkan name → saveAccount | Validasi gagal (required) |
| LW-PRF-09 | Email duplikat | − | Email dipakai user lain | Ubah → saveAccount | Validasi gagal (unique) |
| LW-PRF-10 | Ubah email tanpa/ salah current_password | − | — | Kosong/salah password → saveAccount | Validasi gagal (current_password) |
| LW-PRF-11 | Ganti password salah current | − | — | current_password salah → savePassword | Validasi gagal |
| LW-PRF-12 | Password baru mismatch / < kompleksitas | − | — | Konfirmasi beda / lemah → savePassword | Validasi gagal (Password::defaults) |
| LW-PRF-13 | Avatar bukan image / >2MB | − | — | Upload `.pdf` / file besar → savePhoto | Validasi gagal |

---

## 21. Dashboard
Komponen: `Dashboard`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi |
|----|----------|------|-----------|---------|-----------|
| LW-DSH-01 | Tampil metrik keuangan | + | Izin lihat deposit | Buka dashboard | total_balance, this_month, pending_withdrawals, komposisi tampil |
| LW-DSH-02 | Tampil metrik anggota | + | Izin lihat member | Buka dashboard | active/total/new_this_month |
| LW-DSH-03 | Tampil metrik pinjaman | + | Izin lihat loan | Buka dashboard | active/settled/outstanding/overdue/due_soon |
| LW-DSH-04 | Setoran terbaru | + | Izin keuangan | Buka dashboard | 4 setoran terbaru dgn relasi member |
| LW-DSH-05 | Salam sesuai waktu | + | — | Buka dashboard | Greeting pagi/siang/sore/malam |
| LW-DSH-06 | Tanpa izin keuangan | − | Tidak punya izin deposit | Buka dashboard | Metrik keuangan null/tidak tampil |
| LW-DSH-07 | Tanpa izin member/loan | − | Tidak punya izin | Buka dashboard | Metrik terkait null/tidak tampil |

---

## 22. Notification Bell
Komponen: `NotificationBell`

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi |
|----|----------|------|-----------|---------|-----------|
| LW-NTF-01 | Tampil notifikasi belum dibaca | + | Ada notif | Buka bell | Tampil ≤12 terbaru + badge jumlah unread |
| LW-NTF-02 | Tandai dibaca | + | Notif unread | `markAsRead(id)` | read_at terisi |
| LW-NTF-03 | Buka notif (redirect) | + | Notif punya URL | `open(id)` | Ditandai dibaca + redirect ke URL dari DB |
| LW-NTF-04 | Tandai semua dibaca | + | Ada unread | `markAllAsRead()` | Semua read_at terisi |
| LW-NTF-05 | Buka notif tanpa URL | − | data.actions kosong | `open(id)` | Ditandai dibaca, tanpa redirect |
| LW-NTF-06 | URL diambil dari DB (anti-tamper) | − | — | Manipulasi parameter klien | URL tetap dari DB, bukan input klien |

---

### Catatan Cakupan
- Skenario bertanda 🟢 sudah memiliki test otomatis (Pest) — saat implementasi cukup verifikasi/lengkapi, hindari duplikasi.
- Skenario tanpa tanda 🟢 adalah kandidat test baru.
- Validasi waktu (`before_or_equal:today`) sebaiknya diuji dengan `Carbon::setTestNow()` agar deterministik.

---

## 23. Integrasi Setting → Fitur Hilir
Alur lintas-fitur: **ubah `Settings\ManageSettings` (tab Koperasi) → buat transaksi → verifikasi angka**.
Komponen terkait: `Loan\LoanForm`, `Savings\Deposit\SavingsDepositForm` & `BatchSalaryDeduction`, `Savings\Shopping\ShoppingTransactionForm`.

> **Aturan kunci yang diuji:**
> **Rate pinjaman = SNAPSHOT** saat akad (admin fee, SWP, bunga, tabungan berjangka). **Nominal/threshold simpanan & `loan_short_term_max` = LIVE-READ** saat input.
> Setting default: pokok 50.000 · wajib_belanja 100.000 · sukarela_min 0 · admin_fee 1% · swp 1% · bunga 0,65% · tab berjangka 0,1% · short_term_max 1.000.000.

| ID | Skenario | Tipe | Prakondisi | Langkah | Ekspektasi |
|----|----------|------|-----------|---------|-----------|
| LW-INT-01 | Ubah `loan_short_term_max` → jenis pinjaman ikut | + | — | Set max=500.000 → buat pinjaman nominal 900.000 di LoanForm | Otomatis jadi jangka_panjang (sebelumnya sebrakan saat max=1jt) |
| LW-INT-02 | Ubah `loan_admin_fee_rate` → admin fee pinjaman baru | + | Pinjaman jangka panjang | Set admin_fee=2% → buat pinjaman Rp 10jt | Preview & snapshot `admin_fee` = 10jt × 2% = 200.000 |
| LW-INT-03 | Ubah `loan_swp_rate` → SWP pinjaman baru | + | Jangka panjang | Set swp=2% → buat pinjaman Rp 10jt | `swp_amount` = 200.000; disbursed berkurang sesuai |
| LW-INT-04 | Ubah `loan_interest_rate` → bunga bulanan baru | + | Jangka panjang | Set bunga=1% → buat pinjaman Rp 12jt | `monthly_interest` = 120.000 (konstan tiap bulan di jadwal) |
| LW-INT-05 | Ubah `loan_time_deposit_rate` → tab berjangka baru | + | Jangka panjang | Set tab=0,5% → buat pinjaman Rp 12jt | `monthly_time_deposit` = 60.000; refund saat Lunas = 60.000 × jumlah angsuran **terbayar non-reversal** (= term bila lunas penuh) |
| LW-INT-06 | **Snapshot**: ubah rate setelah pinjaman aktif | + | Pinjaman lama sudah ada | Ubah semua rate → buka pinjaman lama | Angka pinjaman lama TIDAK berubah (admin_fee/swp/bunga/tab tetap) |
| LW-INT-07 | Sebrakan abaikan rate | + | — | Set rate apapun → buat jangka_pendek (≤ max) | admin_fee=0, swp=0, bunga=0, tab=0 (jadwal 1 baris) |
| LW-INT-08 | Ubah `savings_pokok_amount` → setoran pokok baru | + | Member belum punya Pokok | Set pokok=75.000 → setor Pokok di SavingsDepositForm | Nominal terkunci prefill & tersimpan 75.000 |
| LW-INT-09 | Ubah `savings_wajib_belanja_amount` → setoran baru | + | — | Set wajib_belanja=150.000 → setor (atau batch) | Nominal locked tersimpan 150.000; saldo lama (100.000) tetap |
| LW-INT-10 | Ubah `savings_sukarela_min` → input ≥ min lolos | + | — | Set min=50.000 → setor sukarela 50.000 | Diterima |
| LW-INT-11 | **Snapshot saldo**: ubah pokok tak ubah saldo lama | + | Member punya Pokok 50.000 | Set pokok=75.000 → cek saldo member lama | Saldo Pokok tetap 50.000 (dibaca dari DB, bukan setting) |
| LW-INT-12 | Ubah `savings_sukarela_min` → input < min ditolak | − | — | Set min=50.000 → setor sukarela 1.000 | Validasi gagal "minimal Rp 50.000" |
| LW-INT-13 | Ubah `loan_short_term_max` tidak retroaktif | − | Pinjaman sebrakan lama (max=1jt) | Set max=500.000 → buka pinjaman sebrakan lama | Jenis & term pinjaman lama tetap (tidak berubah jadi jangka panjang) |
| LW-INT-14 | Rate/min negatif di setting | − | — | Set rate/min = -1 di ManageSettings → save | Validasi gagal (min:0) — transaksi tidak terpengaruh |
