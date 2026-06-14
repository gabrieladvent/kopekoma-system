# Rancangan Basis Data — Sistem Informasi Koperasi Simpan Pinjam

### KPRI KOPEKOMA — Magelang

| | |
|---|---|
| **Dokumen** | Rancangan Basis Data (Database Design) |
| **Versi** | 5.1 |
| **Tanggal** | Juni 2026 |
| **Koperasi** | KPRI KOPEKOMA, Magelang |
| **DBMS** | MySQL 8.0 (InnoDB, utf8mb4) |
| **Acuan** | Dokumen Rancangan Sistem v5 (disetujui) |

> **Catatan**: Dokumen ini melengkapi **Dokumentasi Sistem**. Penjelasan fungsi/alur bisnis ada di dokumen tersebut; dokumen ini fokus pada **struktur basis data** (tabel, kolom, tipe data, relasi, index). Tabel bawaan paket (Spatie, Media Library, dll.) hanya didaftar, tidak dirinci per kolom.

---

## Daftar Isi

1. [Pendahuluan](#1-pendahuluan)
2. [Stack & Paket](#2-stack--paket)
3. [Konvensi Penamaan & Primary Key](#3-konvensi-penamaan--primary-key)
4. [Konvensi Tipe Data](#4-konvensi-tipe-data)
5. [Ringkasan Tabel](#5-ringkasan-tabel)
6. [Diagram Relasi](#6-diagram-relasi)
7. [Detail Tabel Aplikasi](#7-detail-tabel-aplikasi)
   - [7.1 Master](#71-master)
   - [7.2 Simpanan](#72-simpanan)
   - [7.3 Pinjaman](#73-pinjaman)
8. [Tabel Bawaan Paket](#8-tabel-bawaan-paket)
9. [Konfigurasi (Settings)](#9-konfigurasi-settings)
10. [Daftar Index](#10-daftar-index)
11. [Prinsip Desain Keuangan](#11-prinsip-desain-keuangan)
12. [Catatan Desain](#12-catatan-desain)

---

## 1. Pendahuluan

Basis data ini menyimpan seluruh data operasional koperasi: anggota, instansi (OPD), simpanan, pinjaman, angsuran, dokumen, dan data sistem. Rancangan menekankan **integritas data keuangan**.

Prinsip utama:

- Semua nilai uang memakai `DECIMAL`, bukan `FLOAT/DOUBLE`.
- Transaksi keuangan **tidak pernah dihapus** — koreksi via transaksi lawan (reversal).
- Saldo **dihitung dari transaksi**, bukan kolom yang diubah manual.
- Setiap perubahan terekam pada jejak audit (Activity Log).

---

## 2. Stack & Paket

Sistem dibangun dengan **Laravel + Filament**, memanfaatkan paket-paket berikut. Tabel yang dibuat paket-paket ini **tidak dirinci** di dokumen ini (mengikuti skema bawaan masing-masing) — lihat [Bab 8](#8-tabel-bawaan-paket).

| Paket | Kegunaan | Tabel yang Dihasilkan |
|---|---|---|
| **Spatie Laravel Permission** | Peran & hak akses | `roles`, `permissions`, pivot |
| **Filament Shield** | Generator permission per-resource | (memakai tabel Spatie Permission) |
| **Spatie Media Library** | Upload & kelola dokumen/berkas | `media` |
| **Spatie Activity Log** | Jejak audit | `activity_log` |
| **Spatie Laravel Settings** | Konfigurasi global (nominal & persentase) | `settings` |
| **Maatwebsite Excel** | Ekspor/impor Excel | — (tanpa tabel) |
| **DomPDF** | Cetak PDF | — (tanpa tabel) |

---

## 3. Konvensi Penamaan & Primary Key

### Penamaan

| Aspek | Aturan | Contoh |
|---|---|---|
| Nama tabel | `snake_case`, **jamak** | `members`, `savings_deposits` |
| Nama kolom | `snake_case`, **tunggal** | `full_name`, `principal_amount` |
| Foreign key | `<singular_table>_id` | `member_id`, `loan_id` |
| Kolom boolean | awalan `is_` / `has_` | `is_reversal` |
| Kolom tanggal | akhiran `_date` / `_at` | `disbursement_date`, `created_at` |

Semua tabel aplikasi memiliki `created_at` dan `updated_at`.

### Strategi Primary Key

Agar lebih aman, **entitas yang ID-nya muncul di URL** (halaman detail/edit pada panel admin) memakai **UUID** sebagai primary key — sehingga ID tidak dapat ditebak/diurutkan oleh pihak luar.

| Strategi | Dipakai oleh | Tipe PK |
|---|---|---|
| **UUID** (URL-facing & sensitif) | `members`, `agencies`, `loans`, `savings_deposits`, `savings_withdrawals`, `shopping_transactions`, `installments` | `CHAR(36)` |
| **Auto-increment** (lookup & tabel anak) | `grades`, `member_holiday_savings`, `installment_schedules`, `loan_blacklists` | `BIGINT UNSIGNED` |

> **Foreign key mengikuti tipe PK tabel rujukan.** Karena `members`, `agencies`, dan `loans` ber-UUID, maka `member_id`, `agency_id`, `loan_id` bertipe `CHAR(36)`. Sedangkan `grade_id` dan `schedule_id` bertipe `BIGINT UNSIGNED`.

---

## 4. Konvensi Tipe Data

| Jenis Data | Tipe MySQL | Catatan |
|---|---|---|
| UUID (primary key) | `CHAR(36)` | Entitas URL-facing |
| Auto-increment key | `BIGINT UNSIGNED` | Lookup & tabel anak |
| Uang | `DECIMAL(18,2)` | **Wajib**, bukan FLOAT/DOUBLE |
| Persentase | `DECIMAL(6,5)` | Admin 0,01; SWP 0,01; Jasa 0,00650; Tab. Berjangka 0,00100 |
| Teks pendek | `VARCHAR(n)` | |
| Teks panjang | `TEXT` | Alamat, keterangan |
| Tanggal | `DATE` | |
| Tanggal & waktu | `DATETIME` | |
| Pilihan tetap | `ENUM(...)` | Status, jenis, metode |
| Boolean | `TINYINT(1)` | 0/1 |

---

## 5. Ringkasan Tabel

### Tabel Aplikasi (dirinci di Bab 7)

| Kelompok | Tabel | PK | Fungsi |
|---|---|---|---|
| **Master** | `agencies` | UUID | Data OPD/instansi |
| | `grades` | BIGINT | Golongan + nominal simpanan wajib |
| | `members` | UUID | Data anggota |
| **Simpanan** | `member_holiday_savings` | BIGINT | Kepesertaan Hari Raya per anggota/tahun |
| | `savings_deposits` | UUID | Setoran semua jenis simpanan |
| | `savings_withdrawals` | UUID | Pencairan simpanan & pengembalian SWP/tab. berjangka |
| | `shopping_transactions` | UUID | Pemakaian saldo Wajib Belanja |
| **Pinjaman** | `loans` | UUID | Pinjaman yang sudah disetujui |
| | `installment_schedules` | BIGINT | Jadwal angsuran (auto saat cair) |
| | `installments` | UUID | Pembayaran angsuran |
| | `loan_blacklists` | BIGINT | Daftar anggota diblokir dari pinjaman |

### Tabel Bawaan Paket (didaftar di Bab 8)

`users`, `roles`, `permissions`, `media`, `activity_log`, `settings`, `jobs`, `failed_jobs`, dll.

---

## 6. Diagram Relasi

```
agencies (1) ───< members
grades   (1) ───< members

members (1) ───< member_holiday_savings
members (1) ───< savings_deposits
members (1) ───< savings_withdrawals
members (1) ───< shopping_transactions
members (1) ───< loans
members (1) ───< loan_blacklists

loans (1) ───< installment_schedules
loans (1) ───< installments
installment_schedules (1) ───< installments   (0..N)

savings_deposits     (1) ───< savings_deposits      (reversal_of_id; self)
savings_withdrawals  (1) ───< savings_withdrawals   (reversal_of_id; self)
shopping_transactions(1) ───< shopping_transactions (reversal_of_id; self)
installments         (1) ───< installments          (reversal_of_id; self)

media (polymorphic) ──> members / loans   (lampiran dokumen, via Media Library)
```

Keterangan: `───<` = relasi satu-ke-banyak (one-to-many).

---

## 7. Detail Tabel Aplikasi

> Legenda **Key**: PK = Primary Key, FK = Foreign Key, U = Unique, I = Index.

### 7.1 Master

#### `agencies`
Data OPD/instansi tempat anggota bekerja, untuk koordinasi potong gaji.

| Kolom | Tipe | Null | Key | Keterangan |
|---|---|---|---|---|
| `id` | CHAR(36) | – | PK | UUID |
| `agency_code` | VARCHAR(10) | – | U | Kode unik OPD |
| `agency_name` | VARCHAR(150) | – | | Nama OPD/instansi |
| `address` | TEXT | ✓ | | Alamat |
| `payroll_treasurer` | VARCHAR(100) | ✓ | | PIC/bendahara gaji |
| `pic_phone_number` | VARCHAR(15) | ✓ | | No. HP PIC |
| `status` | ENUM('Aktif','Non-Aktif') | – | | Default `Aktif` |
| `created_at` / `updated_at` | DATETIME | ✓ | | |

#### `grades`
Golongan anggota beserta nominal simpanan wajib bulanannya.

| Kolom | Tipe | Null | Key | Keterangan |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | – | PK | Auto-increment |
| `code` | VARCHAR(15) | – | U | `HR-THL`, `GOL-1` … `GOL-4` |
| `name` | VARCHAR(50) | – | | Label tampilan |
| `mandatory_savings_amount` | DECIMAL(18,2) | – | | Nominal simpanan wajib/bulan |
| `is_active` | TINYINT(1) | – | | Default 1 |
| `created_at` / `updated_at` | DATETIME | ✓ | | |

> Seed: HR-THL = 30.000; GOL-1 = 50.000; GOL-2 = 75.000; GOL-3 = 100.000; GOL-4 = 150.000.

#### `members`
Data lengkap anggota koperasi.

| Kolom | Tipe | Null | Key | Keterangan |
|---|---|---|---|---|
| `id` | CHAR(36) | – | PK | UUID |
| `member_number` | VARCHAR(20) | – | U | Dihasilkan otomatis |
| `full_name` | VARCHAR(100) | – | | Sesuai KTP |
| `birth_place` | VARCHAR(50) | – | | |
| `birth_date` | DATE | – | | |
| `gender` | ENUM('L','P') | – | | |
| `nik` | VARCHAR(16) | – | U | No. KTP |
| `nip` | VARCHAR(25) | ✓ | | Untuk ASN |
| `agency_id` | CHAR(36) | – | FK | → `agencies.id` |
| `position` | VARCHAR(100) | ✓ | | Jabatan |
| `grade_id` | BIGINT UNSIGNED | – | FK | → `grades.id` (menentukan nominal wajib) |
| `employment_status` | ENUM('ASN','Honorer') | – | | |
| `payroll_account_number` | VARCHAR(30) | – | | No. rekening gaji |
| `bank_name` | VARCHAR(50) | ✓ | | |
| `address` | TEXT | – | | |
| `phone_number` | VARCHAR(15) | – | | |
| `join_date` | DATE | – | | |
| `exit_date` | DATE | ✓ | | Diisi saat keluar |
| `heir_name` | VARCHAR(100) | – | | Ahli waris |
| `heir_relationship` | VARCHAR(50) | – | | Hubungan ahli waris |
| `heir_phone_number` | VARCHAR(15) | – | | No. HP ahli waris |
| `status` | ENUM('Aktif','Non-Aktif','Keluar','Meninggal') | – | | Default `Aktif` |
| `created_at` / `updated_at` | DATETIME | ✓ | | |
| `deleted_at` | DATETIME | ✓ | | Soft delete |

> Foto anggota & dokumen (formulir permohonan) disimpan via **Media Library** (`media`), bukan kolom di tabel ini.

---

### 7.2 Simpanan

#### `member_holiday_savings`
Kepesertaan & nominal **Simpanan Hari Raya** per anggota untuk satu tahun buku (opsional, ditetapkan saat RAT).

| Kolom | Tipe | Null | Key | Keterangan |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | – | PK | |
| `member_id` | CHAR(36) | – | FK | → `members.id` |
| `period_year` | SMALLINT UNSIGNED | – | I | Tahun buku (mis. 2026) |
| `monthly_amount` | DECIMAL(18,2) | – | | Nominal ditagih per bulan |
| `is_active` | TINYINT(1) | – | | Ikut/tidak tahun itu |
| `notes` | TEXT | ✓ | | |
| `created_at` / `updated_at` | DATETIME | ✓ | | |

> Unik (`member_id`, `period_year`).

#### `savings_deposits`
Setoran seluruh jenis simpanan (termasuk pengisian saldo Wajib Belanja).

| Kolom | Tipe | Null | Key | Keterangan |
|---|---|---|---|---|
| `id` | CHAR(36) | – | PK | UUID |
| `transaction_number` | VARCHAR(25) | – | U | Dihasilkan otomatis |
| `idempotency_key` | CHAR(36) | – | U | Cegah pengiriman ganda |
| `member_id` | CHAR(36) | – | FK | → `members.id` |
| `savings_type` | ENUM('pokok','wajib','hari_raya','wajib_belanja','sukarela') | – | I | Jenis simpanan |
| `amount` | DECIMAL(18,2) | – | | Nominal setoran |
| `deposit_date` | DATE | – | | Tanggal setor |
| `period_month` | DATE | ✓ | I | Periode (YYYY-MM-01) untuk simpanan rutin |
| `deposit_method` | ENUM('potong_gaji','setor_sendiri') | – | | Cara setor |
| `deposited_by` | ENUM('bendahara','anggota') | – | | Siapa yang menyetorkan |
| `reference_number` | VARCHAR(50) | ✓ | | Bukti transfer (opsional) |
| `notes` | TEXT | ✓ | | |
| `is_reversal` | TINYINT(1) | – | | Default 0 |
| `reversal_of_id` | CHAR(36) | ✓ | FK | → `savings_deposits.id` |
| `recorded_by` | CHAR(36) | – | FK | → `users.id` (petugas) |
| `created_at` / `updated_at` | DATETIME | ✓ | | |

> Boleh bolong tanpa denda. Saldo per jenis = Σ setoran − Σ pencairan terkait.

#### `savings_withdrawals`
Pencairan/pengembalian simpanan: Hari Raya (tahunan), Sukarela, pengembalian **SWP** & **Tabungan Berjangka** saat pinjaman lunas, serta Pokok/Wajib saat anggota keluar.

| Kolom | Tipe | Null | Key | Keterangan |
|---|---|---|---|---|
| `id` | CHAR(36) | – | PK | UUID |
| `withdrawal_number` | VARCHAR(25) | – | U | Dihasilkan otomatis |
| `idempotency_key` | CHAR(36) | – | U | |
| `member_id` | CHAR(36) | – | FK | → `members.id` |
| `savings_type` | ENUM('hari_raya','sukarela','swp','tabungan_berjangka','pokok','wajib') | – | I | Sumber dana |
| `amount` | DECIMAL(18,2) | – | | Nominal pencairan |
| `withdrawal_date` | DATE | – | | Tanggal pencairan |
| `related_loan_id` | CHAR(36) | ✓ | FK | → `loans.id` (pengembalian SWP/tab. berjangka) |
| `notes` | TEXT | ✓ | | |
| `is_reversal` | TINYINT(1) | – | | Default 0 |
| `reversal_of_id` | CHAR(36) | ✓ | FK | → `savings_withdrawals.id` |
| `recorded_by` | CHAR(36) | – | FK | → `users.id` |
| `created_at` / `updated_at` | DATETIME | ✓ | | |

#### `shopping_transactions`
**Pemakaian** saldo Wajib Belanja (mengurangi saldo). Pengisian saldo dicatat di `savings_deposits` (tipe `wajib_belanja`).

| Kolom | Tipe | Null | Key | Keterangan |
|---|---|---|---|---|
| `id` | CHAR(36) | – | PK | UUID |
| `member_id` | CHAR(36) | – | FK | → `members.id` |
| `amount` | DECIMAL(18,2) | – | | Nominal belanja |
| `transaction_date` | DATE | – | | Tanggal belanja |
| `source` | ENUM('manual','store_api') | – | | `manual` (transisi) / `store_api` (integrasi toko) |
| `reference_number` | VARCHAR(50) | ✓ | | Referensi dari toko (opsional) |
| `notes` | TEXT | ✓ | | |
| `is_reversal` | TINYINT(1) | – | | Default 0 |
| `reversal_of_id` | CHAR(36) | ✓ | FK | → `shopping_transactions.id` |
| `recorded_by` | CHAR(36) | ✓ | FK | → `users.id` (NULL bila dari API) |
| `created_at` / `updated_at` | DATETIME | ✓ | | |

> **Saldo Wajib Belanja** = Σ `savings_deposits`(wajib_belanja) − Σ `shopping_transactions`.

---

### 7.3 Pinjaman

#### `loans`
Pinjaman yang **sudah disetujui** (persetujuan di luar sistem). Mencakup jangka pendek (Sebrakan) & jangka panjang.

| Kolom | Tipe | Null | Key | Keterangan |
|---|---|---|---|---|
| `id` | CHAR(36) | – | PK | UUID |
| `loan_number` | VARCHAR(25) | – | U | `PJ-YYYY-NNNN` |
| `member_id` | CHAR(36) | – | FK | → `members.id` |
| `loan_type` | ENUM('jangka_pendek','jangka_panjang') | – | I | < 1jt / > 1jt |
| `principal_amount` | DECIMAL(18,2) | – | | Plafon pinjaman |
| `admin_fee` | DECIMAL(18,2) | – | | Biaya admin 1% (jangka panjang) |
| `swp_amount` | DECIMAL(18,2) | – | | SWP 1% (jangka panjang), kembali saat lunas |
| `disbursed_amount` | DECIMAL(18,2) | – | | = plafon − admin − SWP |
| `term_months` | SMALLINT UNSIGNED | – | | Jangka waktu (1 untuk Sebrakan) |
| `disbursement_date` | DATE | – | | Tanggal cair |
| `first_due_date` | DATE | ✓ | | Jatuh tempo angsuran ke-1 (acuan jadwal) |
| `status` | ENUM('Cair','Lunas') | – | I | |
| `notes` | TEXT | ✓ | | |
| `recorded_by` | CHAR(36) | – | FK | → `users.id` |
| `created_at` / `updated_at` | DATETIME | ✓ | | |

> Jangka pendek: `admin_fee` = `swp_amount` = 0, tanpa jadwal angsuran. Dokumen (formulir/tanda terima) via **Media Library**. Blacklist mengacu `loan_blacklists`.

#### `installment_schedules`
Jadwal angsuran yang **dibuat otomatis** saat pinjaman jangka panjang cair.

| Kolom | Tipe | Null | Key | Keterangan |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | – | PK | |
| `loan_id` | CHAR(36) | – | FK | → `loans.id` |
| `installment_seq` | SMALLINT UNSIGNED | – | I | Cicilan ke-N |
| `due_date` | DATE | – | | Jatuh tempo |
| `principal_due` | DECIMAL(18,2) | – | | Pokok = plafon ÷ tenor |
| `interest_due` | DECIMAL(18,2) | – | | Jasa = pokok × 0,65% |
| `time_deposit_due` | DECIMAL(18,2) | – | | Tab. berjangka = pokok × 0,1% |
| `total_due` | DECIMAL(18,2) | – | | Pokok + jasa + tab. berjangka |
| `status` | ENUM('Belum Bayar','Terbayar') | – | I | Default `Belum Bayar` |
| `created_at` / `updated_at` | DATETIME | ✓ | | |

#### `installments`
Pembayaran angsuran aktual. Boleh bolong; koreksi via reversal.

| Kolom | Tipe | Null | Key | Keterangan |
|---|---|---|---|---|
| `id` | CHAR(36) | – | PK | UUID |
| `installment_number` | VARCHAR(25) | – | U | `ANG-YYYYMMDD-NNNN` |
| `idempotency_key` | CHAR(36) | – | U | |
| `loan_id` | CHAR(36) | – | FK | → `loans.id` |
| `schedule_id` | BIGINT UNSIGNED | ✓ | FK | → `installment_schedules.id` |
| `installment_seq` | SMALLINT UNSIGNED | – | | Cicilan ke-N |
| `payment_date` | DATE | – | | Tanggal bayar |
| `due_date` | DATE | – | | Jatuh tempo |
| `principal_paid` | DECIMAL(18,2) | – | | Pokok dibayar |
| `interest_paid` | DECIMAL(18,2) | – | | Jasa dibayar |
| `time_deposit_saved` | DECIMAL(18,2) | – | | Setoran tabungan berjangka |
| `amount_paid` | DECIMAL(18,2) | – | | Total dibayar |
| `remaining_principal` | DECIMAL(18,2) | – | | Sisa pokok setelah pembayaran ini |
| `payment_method` | ENUM('potong_gaji','manual') | – | | |
| `is_reversal` | TINYINT(1) | – | | Default 0 |
| `reversal_of_id` | CHAR(36) | ✓ | FK | → `installments.id` |
| `recorded_by` | CHAR(36) | – | FK | → `users.id` |
| `created_at` / `updated_at` | DATETIME | ✓ | | |

> Saat seluruh pokok lunas, `loans.status` = `Lunas`, lalu dibuat baris `savings_withdrawals` untuk mengembalikan SWP & total Tabungan Berjangka.

#### `loan_blacklists`
Daftar anggota yang diblokir dari pengajuan pinjaman baru.

| Kolom | Tipe | Null | Key | Keterangan |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | – | PK | |
| `member_id` | CHAR(36) | – | FK | → `members.id` |
| `reason` | TEXT | – | | Alasan blacklist |
| `is_active` | TINYINT(1) | – | | Status blokir aktif |
| `blacklisted_at` | DATE | – | | Tanggal diberlakukan |
| `released_at` | DATE | ✓ | | Tanggal dicabut |
| `recorded_by` | CHAR(36) | – | FK | → `users.id` |
| `created_at` / `updated_at` | DATETIME | ✓ | | |

---

## 8. Tabel Bawaan Paket

Tabel berikut **dibuat dan dikelola oleh paket** (mengikuti skema bawaan), sehingga tidak dirinci per kolom:

| Tabel | Sumber | Fungsi |
|---|---|---|
| `users` | Laravel / Filament | Akun pengguna (Super Admin, Pengurus, Petugas) |
| `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` | Spatie Permission + Filament Shield | Peran & hak akses |
| `media` | Spatie Media Library | Penyimpanan dokumen/berkas (foto anggota, formulir, tanda terima) — polymorphic ke `members`, `loans`, dll. |
| `activity_log` | Spatie Activity Log | Jejak audit (siapa, kapan, nilai lama→baru) |
| `settings` | Spatie Laravel Settings | Konfigurasi global (lihat Bab 9) |
| `jobs`, `job_batches`, `failed_jobs` | Laravel | Antrian proses latar belakang |
| `cache`, `password_reset_tokens`, `sessions` | Laravel | Pendukung sistem |

> **Maatwebsite Excel** dan **DomPDF** tidak membuat tabel.

---

## 9. Konfigurasi (Settings)

Nilai konfigurasi global disimpan melalui **Spatie Laravel Settings** (tabel `settings`), bukan tabel domain tersendiri. Contoh nilai:

| Key | Contoh Nilai | Keterangan |
|---|---|---|
| `savings.pokok_amount` | 50000 | Simpanan pokok (sekali) |
| `savings.wajib_belanja_amount` | 100000 | Wajib belanja per bulan |
| `savings.sukarela_min` | 0 | Minimal setor sukarela |
| `loan.admin_fee_rate` | 0.01 | Biaya admin 1% |
| `loan.swp_rate` | 0.01 | SWP 1% |
| `loan.interest_rate` | 0.0065 | Jasa 0,65% dari pokok |
| `loan.time_deposit_rate` | 0.001 | Tabungan berjangka 0,1% dari pokok |
| `loan.short_term_max` | 1000000 | Batas pinjaman jangka pendek (Sebrakan) |

> Nominal **Simpanan Wajib per golongan** tetap di tabel `grades` (bukan settings), karena bervariasi per golongan. Nominal **Hari Raya** di `member_holiday_savings` (per anggota/tahun).

---

## 10. Daftar Index

Selain primary key, index berikut wajib ada:

- `members.nik` — **UNIQUE**
- `members.member_number` — **UNIQUE**
- `members(agency_id)`, `members(grade_id)` — FK
- `agencies.agency_code` — **UNIQUE**
- `grades.code` — **UNIQUE**
- `savings_deposits(member_id, savings_type, period_month)`
- `savings_deposits.idempotency_key` — **UNIQUE**
- `savings_deposits.transaction_number` — **UNIQUE**
- `member_holiday_savings(member_id, period_year)` — **UNIQUE**
- `loans(member_id, status)`
- `loans.loan_number` — **UNIQUE**
- `installment_schedules(loan_id, installment_seq)`
- `installments.idempotency_key` — **UNIQUE**
- `installments(loan_id)`
- `loan_blacklists(member_id, is_active)`
- **Semua kolom foreign key wajib diindeks.**

---

## 11. Prinsip Desain Keuangan

1. **DECIMAL untuk uang** — nominal `DECIMAL(18,2)`, persentase `DECIMAL(6,5)`. Tidak ada FLOAT/DOUBLE.
2. **Reversal, bukan delete** — tabel transaksi (`savings_deposits`, `savings_withdrawals`, `shopping_transactions`, `installments`) memakai `is_reversal` + `reversal_of_id`.
3. **Idempotency** — kolom `idempotency_key` (UUID, unique) pada tabel transaksi mencegah pencatatan ganda.
4. **Saldo dihitung dari transaksi** — tidak ada kolom saldo yang diubah manual (boleh di-cache).
5. **Soft delete** — `members.deleted_at`. Transaksi keuangan memakai reversal, bukan soft delete.
6. **Audit penuh** — seluruh perubahan terekam di `activity_log`.
7. **Integritas transaksi** — operasi yang menyentuh ≥2 tabel dibungkus database transaction.

---

## 12. Catatan Desain

- **UUID untuk entitas URL-facing** — `members`, `agencies`, `loans`, dan tabel transaksi memakai UUID agar ID tidak dapat ditebak/diurutkan. Tabel lookup & anak tetap BIGINT untuk efisiensi.
- **Golongan sebagai relasi** — `members.grade_id` → `grades`, agar perubahan nominal wajib cukup di satu tempat.
- **Dokumen via Media Library** — seluruh lampiran (formulir anggota, tanda terima/formulir pinjaman, foto) disimpan di `media`, bukan kolom path tersebar.
- **Konfigurasi via Settings** — nominal & persentase global memakai paket Settings, sehingga mudah diubah tanpa migrasi.
- **Wajib Belanja dua sisi** — pengisian via `savings_deposits`, pemakaian via `shopping_transactions`; integrasi toko mengisi `shopping_transactions` dengan `source = store_api`.
- **SHU** — tabel khusus SHU **belum dirancang** (metode menunggu ketentuan koperasi). Data dasar tetap bisa ditarik dari tabel transaksi.

---

*Rancangan Basis Data — Sistem Informasi Koperasi Simpan Pinjam KPRI KOPEKOMA (v5.1)*
