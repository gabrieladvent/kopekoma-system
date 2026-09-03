# Dokumen Rancangan Sistem Informasi Koperasi Simpan Pinjam

### KPRI KOPEKOMA — Magelang

| | |
|---|---|
| **Versi** | 5.1.0 |
| **Tanggal** | Juni 2026 |
| **Acuan** | Notulen "Kebutuhan Sistem Koperasi", Rapat I — 10 Juni 2026 (Pak Hestu, Gabriel, Ribka) |
| **Koperasi** | KPRI KOPEKOMA, Magelang |
| **Teknologi** | Laravel 11 + Filament 3 + MySQL 8 (PHP 8.2+) |
| **Skala Target** | 200–1.000 anggota, 1 lokasi |
| **Anggota** | ASN/PNS & tenaga honorer (potong gaji via OPD) |
| **Status** | Final — Untuk Persetujuan |
| **Tipe Akses** | Sistem Internal |
| **Bahasa Antarmuka** | Bahasa Indonesia |

---

## Daftar Isi

1. [Pendahuluan](#1-pendahuluan)
2. [Ruang Lingkup](#2-ruang-lingkup)
3. [Prinsip Desain](#3-prinsip-desain)
4. [Arsitektur & Teknologi](#4-arsitektur--teknologi)
5. [Modul Sistem](#5-modul-sistem)
   - [5.1 Master Data Anggota](#51-master-data-anggota)
   - [5.2 Master Data OPD/Instansi](#52-master-data-opdinstansi)
   - [5.3 Simpanan](#53-simpanan)
   - [5.4 Setoran Simpanan](#54-setoran-simpanan)
   - [5.5 Pinjaman](#55-pinjaman)
   - [5.6 Angsuran](#56-angsuran)
   - [5.7 Dokumen & Formulir](#57-dokumen--formulir)
   - [5.8 Laporan & Dashboard](#58-laporan--dashboard)
6. [Database](#6-database)
7. [Hak Akses](#7-hak-akses)
8. [Alur Bisnis Utama](#8-alur-bisnis-utama)
9. [Aturan Bisnis](#9-aturan-bisnis)
10. [Backup & Keamanan](#10-backup--keamanan)
11. [Rencana Pengerjaan](#11-rencana-pengerjaan)
12. [Risiko & Mitigasi](#12-risiko--mitigasi)
13. [Hal yang Masih Menunggu Konfirmasi](#13-hal-yang-masih-menunggu-konfirmasi)
14. [Pengembangan Lanjutan](#14-pengembangan-lanjutan)

---

## 1. Pendahuluan

Sistem Informasi Koperasi Simpan Pinjam KPRI KOPEKOMA merupakan aplikasi berbasis web yang digunakan secara internal oleh pengurus dan petugas koperasi. Sistem ini menggantikan pencatatan simpanan dan pinjaman anggota yang sebelumnya dilakukan secara manual, sehingga proses operasional menjadi lebih cepat, akurat, dan terdokumentasi dengan baik.

Anggota koperasi terdiri atas ASN/PNS dan tenaga honorer di lingkungan OPD (Organisasi Perangkat Daerah). Karena itu, mekanisme **potong gaji** menjadi salah satu cara utama penyetoran simpanan maupun pembayaran angsuran pinjaman.

Dokumen ini menjabarkan rancangan fungsi inti operasional koperasi — mulai dari pengelolaan data anggota, ragam simpanan, pinjaman, hingga pelaporan. Rancangan disusun dengan fondasi teknis yang kuat agar sistem dapat tumbuh seiring kebutuhan koperasi di masa mendatang.

### Permasalahan yang Diselesaikan

1. Pencatatan simpanan dan pinjaman secara manual rawan kesalahan.
2. Saldo per anggota sulit diketahui secara langsung dan terkini.
3. Perhitungan angsuran (pokok, jasa, tabungan berjangka) secara manual memakan waktu.
4. Belum ada jejak audit yang mencatat siapa yang menginput atau mengubah data.
5. Dokumen permohonan dan tanda terima masih berupa kertas yang rawan hilang.

### Manfaat yang Diharapkan

- Data anggota, simpanan, dan pinjaman tersimpan terpusat dan terkini.
- Perhitungan angsuran dan potongan pinjaman dilakukan otomatis dan konsisten.
- Setiap transaksi tercatat lengkap dengan jejak audit.
- Dokumen asli (formulir permohonan, tanda terima) tersimpan digital di sistem.

---

## 2. Ruang Lingkup

### Fungsi Utama

1. **Master Data Anggota** — pengelolaan data lengkap anggota (ASN & honorer).
2. **Master Data OPD/Instansi** — pengelolaan instansi tempat anggota bekerja.
3. **Simpanan** — pengelolaan enam jenis simpanan koperasi.
4. **Setoran Simpanan** — pencatatan setoran (potong gaji & setor sendiri).
5. **Pinjaman** — pencatatan pinjaman jangka pendek & jangka panjang beserta potongannya.
6. **Angsuran** — pencatatan angsuran (pokok, jasa, tabungan berjangka).
7. **Dokumen & Formulir** — penyimpanan dan pencetakan formulir resmi.
8. **Laporan & Dashboard** — pelaporan operasional dan pemantauan.

### Batasan Sistem

- **Persetujuan pinjaman dilakukan di luar sistem** oleh admin/bendahara. Sistem hanya mencatat pinjaman yang **sudah disetujui (ACC)**.
- **Pengecekan kelayakan gaji** ("apakah gaji masih cukup untuk dipotong") dilakukan secara manual oleh admin dan bendahara, di luar sistem.
- **Perhitungan SHU (Sisa Hasil Usaha)** belum termasuk pada tahap ini; metode perhitungannya masih menunggu ketentuan koperasi.
- **Integrasi dengan aplikasi toko (Pak Hestu)** untuk Wajib Belanja dirancang sebagai jalur integrasi, dengan rincian teknis menyusul (lihat [Bab 14](#14-pengembangan-lanjutan)).

---

## 3. Prinsip Desain

Karena sistem ini mengelola **dana milik anggota**, prinsip-prinsip berikut diterapkan secara wajib untuk menjaga integritas dan keamanan data keuangan:

1. **DECIMAL untuk Nilai Uang** — seluruh kolom uang menggunakan `DECIMAL(18,2)`. Tipe `FLOAT/DOUBLE` tidak diperbolehkan.
2. **Database Transaction** — setiap operasi yang menulis ke dua tabel atau lebih dibungkus dalam `DB::transaction()`.
3. **Audit Log** — setiap aksi CREATE/UPDATE/DELETE tercatat lengkap: oleh siapa, kapan, dan perubahan nilai dari/ke berapa.
4. **Reversal, Bukan Hapus** — transaksi keuangan tidak boleh dihapus. Koreksi dilakukan dengan membuat transaksi lawan (reversal).
5. **Idempotency Key** — setiap form transaksi menggunakan UUID untuk mencegah pengiriman ganda.
6. **Saldo Dihitung dari Transaksi** — saldo bukan nilai yang diubah manual, melainkan hasil agregat dari transaksi (dapat di-cache untuk performa).
7. **Backup Harian** — basis data dicadangkan otomatis setiap hari ke penyimpanan terpisah.

---

## 4. Arsitektur & Teknologi

### Teknologi

| Komponen | Pilihan |
|---|---|
| **Framework** | Laravel 11 (PHP 8.2+) |
| **Antarmuka Admin** | Filament 3 |
| **Basis Data** | MySQL 8.0 |
| **Autentikasi** | Filament built-in (berbasis sesi) |
| **Manajemen Hak Akses** | `spatie/laravel-permission` (Filament Shield opsional) |
| **Audit / Activity Log** | `spatie/laravel-activitylog` |
| **PDF** | `barryvdh/laravel-dompdf` |
| **Ekspor Excel/CSV** | Export bawaan Filament 3 (`openspout`); `maatwebsite/excel` hanya bila perlu laporan kompleks |
| **Penyimpanan Dokumen** | Laravel Filesystem (lokal/terenkripsi) |
| **Backup** | Spatie Laravel Backup |
| **Web Server** | Nginx |

> **Prinsip pemilihan paket**: hanya menggunakan paket yang umum, ringan, dan aktif dipelihara. Untuk tiga role yang sederhana, `spatie/laravel-permission` saja sudah cukup tanpa perlu Filament Shield. Ekspor data memanfaatkan fitur bawaan Filament 3 sehingga tidak menambah ketergantungan.

### Alasan Pemilihan Filament

- Antarmuka admin siap pakai sehingga pengembangan dapat berfokus pada logika bisnis, bukan membangun tampilan dari awal.
- Form, tabel, dashboard, dan manajemen hak akses telah tersedia secara bawaan.
- Sangat sesuai untuk sistem internal yang berorientasi pada pengelolaan data.
- Mempercepat waktu pengembangan secara signifikan.

### Pemetaan Modul

| Modul | Pola Implementasi Filament |
|---|---|
| Master Anggota | Resource (CRUD) + unggah dokumen |
| Master OPD/Instansi | Resource (CRUD) |
| Simpanan | Resource per jenis + konfigurasi golongan |
| Setoran Simpanan | Resource + Custom Action (reversal) + input kolektif |
| Pinjaman | Resource + Wizard + kalkulator potongan |
| Angsuran | Resource + Custom Page pembayaran cepat |
| Dokumen & Formulir | Cetak PDF + penyimpanan lampiran |
| Laporan | Custom Page dengan filter + ekspor Excel/PDF |
| Dashboard | Filament Widgets |

### Struktur Direktori

```
app/
├── Models/                  # Member, Agency, SavingsDeposit, Loan, Installment, dst.
├── Filament/
│   ├── Resources/           # CRUD per modul
│   ├── Pages/               # Halaman laporan & pembayaran angsuran
│   └── Widgets/             # Dashboard
├── Services/                # Logika bisnis (InstallmentCalculator, LoanDeductions)
├── Enums/                   # Grade, SavingsType, LoanStatus
└── Policies/

database/
├── migrations/
└── seeders/                 # Role, grade & savings configuration defaults
```

> **Prinsip arsitektur**: Seluruh logika bisnis (perhitungan jasa, tabungan berjangka, potongan SWP/admin, pembuatan jadwal angsuran) ditempatkan di `app/Services`, bukan di dalam Filament Resource. Hal ini menjaga kode tetap rapi, mudah diuji, dan dapat digunakan ulang.

---

## 5. Modul Sistem

### 5.1 Master Data Anggota

> **Tujuan**: Mengelola data lengkap seluruh anggota koperasi (ASN dan honorer).

#### Field Anggota

| Field | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `member_number` | VARCHAR(20) | ✅ | Dihasilkan otomatis |
| `full_name` | VARCHAR(100) | ✅ | Sesuai KTP |
| `birth_place` | VARCHAR(50) | ✅ | |
| `birth_date` | DATE | ✅ | |
| `gender` | ENUM | ✅ | L / P |
| `nik` | VARCHAR(16) | ✅ | Unik (No. KTP) |
| `nip` | VARCHAR(25) | – | Untuk ASN/PNS |
| `agency_id` | FK | ✅ | OPD/instansi tempat bekerja |
| `position` | VARCHAR(100) | – | Jabatan |
| `grade` | ENUM | ✅ | HR/THL / GOL 1 / GOL 2 / GOL 3 / GOL 4 (golongan) |
| `employment_status` | ENUM | ✅ | ASN / Honorer (HR/THL) |
| `payroll_account_number` | VARCHAR(30) | ✅ | No. rekening gaji (potong gaji & transfer) |
| `bank_name` | VARCHAR(50) | – | |
| `address` | TEXT | ✅ | Alamat |
| `phone_number` | VARCHAR(15) | ✅ | No. HP |
| `join_date` | DATE | ✅ | Tanggal jadi anggota |
| `exit_date` | DATE | – | Diisi saat keluar |
| `heir_name` | VARCHAR(100) | ✅ | Ahli waris — untuk klaim |
| `heir_relationship` | VARCHAR(50) | ✅ | Hubungan ahli waris |
| `heir_phone_number` | VARCHAR(15) | ✅ | No. HP ahli waris |
| `status` | ENUM | ✅ | Aktif / Non-Aktif / Keluar / Meninggal |
| `photo` | VARCHAR(255) | – | Foto |

#### Fitur

- CRUD lengkap dengan validasi NIK unik.
- Penomoran anggota otomatis.
- **Golongan otomatis menentukan nominal simpanan wajib** (lihat 5.3).
- Filter dan pencarian berdasarkan nama, NIK/NIP, OPD, golongan, dan status.
- **Unggah & simpan dokumen asli**: formulir permohonan anggota baru (scan/PDF).
- Cetak kartu anggota (PDF).
- Tab riwayat: simpanan, pinjaman, dan angsuran per anggota.
- Impor data dari Excel (unggah massal dengan validasi).
- **Soft delete** — data anggota tidak dihapus permanen, hanya berubah status.

---

### 5.2 Master Data OPD/Instansi

> **Tujuan**: Mengelola data OPD/instansi tempat anggota bekerja, untuk mendukung koordinasi potong gaji.

| Field | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `agency_code` | VARCHAR(10) | ✅ | Unik |
| `agency_name` | VARCHAR(150) | ✅ | Nama OPD/instansi |
| `address` | TEXT | – | Alamat |
| `payroll_treasurer` | VARCHAR(100) | – | PIC potong gaji (bendahara gaji) |
| `pic_phone_number` | VARCHAR(15) | – | No. HP PIC |
| `status` | ENUM | ✅ | Aktif / Non-Aktif |

#### Fitur

- CRUD OPD/instansi.
- Daftar anggota per OPD.
- Statistik per OPD: jumlah anggota, total simpanan, total pinjaman, total potongan gaji bulanan.

---

### 5.3 Simpanan

> **Tujuan**: Mengelola seluruh jenis simpanan anggota. Koperasi memiliki **enam jenis simpanan**, dikelompokkan menjadi simpanan keanggotaan dan simpanan terkait pinjaman.

#### A. Simpanan Keanggotaan

| # | Jenis | Setor | Besaran | Pencairan |
|---|---|---|---|---|
| 1 | **Simpanan Pokok** | Sekali, saat masuk | **Rp 50.000** (pasti & tetap) | Saat keluar |
| 2 | **Simpanan Wajib** | Bulanan | Sesuai **golongan** (lihat tabel) | Saat keluar |
| 3 | **Simpanan Hari Raya** | Bulanan | **Fleksibel & opsional** (ikut/tidak, nominal di-deal saat RAT) | Tiap tahun, menjelang hari raya |
| 4 | **Wajib Belanja** | Bulanan | **Rp 100.000** (wajib, boleh bolong) | Tidak diuangkan — untuk belanja di toko |
| 5 | **Simpanan Sukarela** | Bebas (kapan saja) | Bebas | Kapan saja (jika saldo cukup) |

#### Nominal Simpanan Wajib per Golongan

| Golongan | Nominal/Bulan |
|---|---|
| HR/THL (honorer) | Rp 30.000 |
| GOL 1 | Rp 50.000 |
| GOL 2 | Rp 75.000 |
| GOL 3 | Rp 100.000 |
| GOL 4 | Rp 150.000 |

> Nominal simpanan wajib **mengikuti golongan anggota secara otomatis**. Apabila golongan anggota berubah, nominal wajib menyesuaikan.

#### Catatan per Jenis Simpanan

- **Simpanan Pokok** — dibayar satu kali saat menjadi anggota; nilainya tetap Rp 50.000.
- **Simpanan Hari Raya** — **bersifat opsional** (anggota bisa ikut atau tidak). Nominal **di-deal/ditentukan saat RAT (Rapat Anggota Tahunan, sekitar Februari–Maret)** dan diperbarui paling tidak setahun sekali. Karena nominalnya per-anggota dan berubah tahunan, partisipasi & nominal disimpan **per anggota per tahun buku** (tabel `member_holiday_savings`), bukan di master data anggota. Ditagih tiap bulan selama satu tahun dan **dibagikan menjelang hari raya tahun berikutnya** (sekitar satu bulan sebelumnya). Inilah satu-satunya simpanan yang **dicairkan rutin setiap tahun**.
- **Wajib Belanja** — Rp 100.000/bulan, **bersifat wajib bagi anggota namun boleh bolong** (sama seperti kewajiban lain, tanpa sanksi bila tidak setor). **Tidak dapat diuangkan** — hanya dapat dibelanjakan di toko koperasi; sisa yang belum terpakai dianggap saldo belanja. Saldo belanja dikelola dengan dua sisi: **setoran menambah saldo**, **pemakaian (belanja) mengurangi saldo**.
  - **Pencatatan pemakaian saldo** memiliki dua mode:
    1. **Menu manual (transisi)** — petugas mencatat pemakaian saldo belanja anggota secara manual selama integrasi belum aktif.
    2. **Integrasi API (final)** — sistem koperasi menyediakan **API** untuk aplikasi toko (Pak Hestu), sehingga setiap transaksi belanja anggota otomatis mengurangi saldo secara real-time. Lihat [Bab 14](#14-pengembangan-lanjutan).

#### B. Simpanan Terkait Pinjaman

| Jenis | Sumber | Besaran | Pencairan |
|---|---|---|---|
| **SWP** (Simpanan Wajib Pinjam) | Dipotong saat pencairan pinjaman | **1%** dari pinjaman (sekali) | Saat pinjaman **lunas** |
| **Tabungan Berjangka** | Dikumpulkan tiap bulan via angsuran | **Pokok × 0,1%** per bulan | Saat pinjaman **lunas** |

> Rincian SWP dan Tabungan Berjangka dijelaskan pada modul Pinjaman (5.5) dan Angsuran (5.6).

#### Ketentuan Umum Simpanan

- **Dua mekanisme setor**: **potong gaji** dan **setor sendiri** (manual). Setoran dapat dilakukan melalui bendahara maupun oleh anggota sendiri.
- **Tidak ada sanksi** apabila anggota tidak menyetor pada bulan tertentu. Bulan yang terlewat dicatat sebagai **"bolong"** tanpa denda atau tunggakan wajib.
- **Tidak ada potongan** dari koperasi saat anggota menyimpan.
- **Bunga simpanan**: menunggu konfirmasi koperasi (indikasi awal: tidak ada).
- Saldo per jenis simpanan dihitung otomatis dari transaksi.

---

### 5.4 Setoran Simpanan

> **Tujuan**: Mencatat setoran simpanan dari anggota melalui potong gaji maupun setor sendiri.

| Field | Tipe | Keterangan |
|---|---|---|
| `transaction_number` | VARCHAR(25) | Dihasilkan otomatis |
| `idempotency_key` | UUID | Mencegah pengiriman ganda |
| `member_id` | FK | |
| `savings_type` | ENUM | Pokok / Wajib / Hari Raya / Wajib Belanja / Sukarela |
| `amount` | DECIMAL(15,2) | Nominal |
| `deposit_date` | DATE | Tanggal setor |
| `period_month` | DATE | Untuk simpanan rutin (YYYY-MM-01) |
| `deposit_method` | ENUM | Potong Gaji / Setor Sendiri |
| `deposited_by` | ENUM | Bendahara / Anggota |
| `reference_number` | VARCHAR(50) | Bukti transfer (opsional) |
| `notes` | TEXT | Keterangan |
| `is_reversal` | BOOLEAN | Default false |
| `reversal_of_id` | FK | Penunjuk ke transaksi yang dikoreksi |
| `recorded_by` | FK | Petugas pencatat |

#### Fitur

- **Input tunggal**: form per transaksi.
- **Input kolektif per OPD (potong gaji)**: pilih OPD → tampil daftar anggota aktif dengan nominal default sesuai golongan → centang → simpan sekaligus.
- Tidak ada validasi anti-bolong: bulan terlewat boleh dikosongkan tanpa denda.
- Cetak slip setoran (PDF).
- **Tidak dapat diubah atau dihapus**: koreksi melalui aksi reversal.
- Saldo simpanan diperbarui otomatis setelah setoran.

---

### 5.5 Pinjaman

> **Tujuan**: Mencatat pinjaman yang **sudah disetujui** beserta potongan dan jadwal angsurannya. Persetujuan dilakukan di luar sistem.

#### Jenis Pinjaman

| Jenis | Plafon | Potongan | Angsuran | Tenor |
|---|---|---|---|---|
| **Jangka Pendek (Sebrakan)** | **< Rp 1.000.000** | **Tidak ada** | **Tidak ada** | 1 bulan (pinjam bulan ini, kembalikan bulan depan) |
| **Jangka Panjang** | **> Rp 1.000.000** | Admin 1% + SWP 1% | Pokok + Jasa + Tab. Berjangka | Diinput per pinjaman (mis. 6/12/24 bln) |

> **Semua pinjaman (jangka pendek & jangka panjang) wajib disertai pengisian formulir.** Setelah pinjaman **disetujui (di luar sistem)**, anggota mengisi formulir / **Tanda Terima Pinjaman Uang**, lalu data dimasukkan ke sistem.

#### Field Pinjaman

| Field | Tipe | Keterangan |
|---|---|---|
| `loan_number` | VARCHAR(25) | `PJ-YYYY-NNNN` |
| `member_id` | FK | |
| `loan_type` | ENUM | Jangka Pendek / Jangka Panjang |
| `principal_amount` | DECIMAL(15,2) | Plafon |
| `admin_fee` | DECIMAL(15,2) | 1% dari pinjaman (jangka panjang) — milik koperasi |
| `swp_amount` | DECIMAL(15,2) | SWP 1% dari pinjaman (jangka panjang) — milik anggota |
| `disbursed_amount` | DECIMAL(15,2) | = pinjaman − admin − SWP |
| `term_months` | INT | Tenor: jumlah bulan angsuran, diinput per pinjaman (1 untuk Sebrakan) |
| `disbursement_date` | DATE | Tanggal cair |
| `first_due_date` | DATE | Jatuh tempo angsuran ke-1; dasar pembuatan jadwal angsuran |
| `status` | ENUM | Cair / Lunas |
| `blacklist_checked` | BOOLEAN | Hasil pengecekan blacklist |
| `receipt_document` | VARCHAR(255) | Lampiran formulir / tanda terima (semua pinjaman) |
| `notes` | TEXT | Keterangan |
| `recorded_by` | FK | Petugas pencatat |

#### Potongan Saat Pencairan (Jangka Panjang)

| Komponen | Besaran | Milik | Sifat |
|---|---|---|---|
| **Biaya Admin** | 1% dari pinjaman | Koperasi | Sekali, tidak dikembalikan |
| **SWP** (Simpanan Wajib Pinjam) | 1% dari pinjaman | Anggota | Sekali, **dikembalikan saat lunas** |

> **Pinjaman Diterima = Pinjaman − Biaya Admin − SWP**

#### Fitur

- **Wizard pencatatan pinjaman**: Anggota → Jenis & Plafon → Tenor → Potongan otomatis → Pratinjau Jadwal → Konfirmasi.
- Kalkulator potongan & angsuran langsung pada form.
- Wajib bayar via **potong gaji**.
- **Fitur blacklist**: anggota bermasalah ditandai dan diblokir dari pinjaman baru.
- Jadwal angsuran dibuat otomatis saat pinjaman dicatat (jangka panjang).
- Unggah & simpan dokumen tanda terima pinjaman.
- Cetak Tanda Terima Pinjaman Uang (PDF).
- Cetak jadwal angsuran (PDF).

---

### 5.6 Angsuran

> **Tujuan**: Mencatat pembayaran angsuran pinjaman jangka panjang.

#### Komponen Angsuran per Bulan

| Komponen | Rumus | Keterangan |
|---|---|---|
| **Pokok** | Total Pinjaman ÷ Jangka Waktu | Cicilan pokok |
| **Jasa** | **Pokok × 0,65%** | Tiap bulan, milik koperasi |
| **Tabungan Berjangka** | **Pokok × 0,1%** | Tiap bulan, milik anggota, cair saat lunas |

> **Angsuran/Bulan = Pokok + Jasa + Tabungan Berjangka**
>
> Semua komponen berbasis persen: Jasa & Tabungan Berjangka dihitung dari **Pokok angsuran**, sedangkan Biaya Admin & SWP dihitung dari **plafon pinjaman**.

#### Contoh Perhitungan

> Plafon Rp 12.000.000, tenor 12 bulan → Pokok = Rp 1.000.000:
> - **Saat cair**: Admin 1% = Rp 120.000; SWP 1% = Rp 120.000 → **Diterima = Rp 11.760.000**
> - **Angsuran/bulan**: Pokok Rp 1.000.000 + Jasa (1.000.000 × 0,65% = Rp 6.500) + Tab. Berjangka (1.000.000 × 0,1% = Rp 1.000) = **Rp 1.007.500**
> - **Saat lunas**: SWP Rp 120.000 + Tabungan Berjangka (12 × Rp 1.000 = Rp 12.000) **dikembalikan ke anggota**

#### Field Angsuran

| Field | Tipe | Keterangan |
|---|---|---|
| `installment_number` | VARCHAR(25) | `ANG-YYYYMMDD-NNNN` |
| `idempotency_key` | UUID | |
| `loan_id` | FK | |
| `schedule_id` | FK | Tautan ke baris jadwal angsuran |
| `installment_seq` | INT | Cicilan ke-N |
| `payment_date` | DATE | Tanggal bayar |
| `due_date` | DATE | Jatuh tempo |
| `principal_paid` | DECIMAL(15,2) | Bayar pokok |
| `interest_paid` | DECIMAL(15,2) | Bayar jasa |
| `time_deposit_saved` | DECIMAL(15,2) | Setoran tabungan berjangka |
| `amount_paid` | DECIMAL(15,2) | Total dibayar |
| `remaining_principal` | DECIMAL(15,2) | Sisa pokok setelah bayar |
| `payment_method` | ENUM | Potong Gaji / Manual |
| `is_reversal` | BOOLEAN | |
| `reversal_of_id` | FK | |
| `recorded_by` | FK | Petugas pencatat |

#### Fitur

- **Halaman pembayaran cepat**: petugas memasukkan nomor anggota → tampil pinjaman aktif & angsuran jatuh tempo → input → simpan.
- Pembayaran via **potong gaji** atau **manual (cara lama)**.
- Boleh **bolong**: angsuran terlewat dicatat tanpa pemutusan otomatis.
- Setiap angsuran otomatis menambah akumulasi **Tabungan Berjangka** anggota.
- Cetak kuitansi (PDF).
- Saat angsuran terakhir lunas: status pinjaman menjadi **Lunas**, dan **SWP + Tabungan Berjangka dikembalikan** ke anggota (tercatat sebagai pencairan).
- Reversal jika terjadi kesalahan input.

---

### 5.7 Dokumen & Formulir

> **Tujuan**: Menyimpan dokumen asli secara digital dan mencetak formulir resmi koperasi.

#### Formulir yang Dikelola Sistem

| Formulir | Fungsi | Aksi Sistem |
|---|---|---|
| **Form Permohonan Anggota Baru** | Pendaftaran anggota (Pokok, Wajib, Hari Raya, Wajib Belanja) | Cetak PDF + simpan scan |
| **Tanda Terima Pinjaman Uang** | Bukti pencairan + rincian potongan (Bi Adm 1%, SWP 1%) | Cetak PDF + simpan scan |
| **Formulir Pinjaman** | Wajib untuk **semua pinjaman** (jangka pendek & panjang) | Cetak PDF + simpan scan |
| **Slip Setoran / Kuitansi Angsuran** | Bukti transaksi | Cetak PDF |
| **Kartu Anggota** | Identitas anggota | Cetak PDF |

> **Prinsip**: dokumen asli (hasil scan/foto) **wajib disimpan di sistem** dan tertaut ke anggota/transaksi terkait, sehingga arsip fisik dapat ditelusuri secara digital.

---

### 5.8 Laporan & Dashboard

#### Dashboard (Real-time)

Halaman utama menampilkan:

- Total anggota aktif (per OPD & golongan)
- **Daftar anggota baru** — untuk keperluan **RAT** (pemantauan pendaftar terbaru)
- Total saldo simpanan per jenis (Pokok, Wajib, Hari Raya, Wajib Belanja, Sukarela)
- Total outstanding pinjaman & jumlah pinjaman aktif
- Akumulasi SWP & Tabungan Berjangka yang belum dikembalikan
- Pinjaman jatuh tempo minggu ini

#### Laporan

Daftar laporan bulanan **akan dirinci pada pembahasan berikutnya**. Sebagai dasar, sistem menyiapkan laporan operasional standar: rekap setoran, rekap angsuran, daftar saldo simpanan per anggota, daftar pinjaman aktif, serta rekap potongan gaji per OPD — seluruhnya dapat diekspor ke PDF/Excel.

---

## 6. Database

### Daftar Tabel

| Kategori | Tabel |
|---|---|
| **Master** | `users`, `roles`, `permissions`, `agencies`, `members`, `grades`, `savings_configurations`, `member_holiday_savings` |
| **Transaksi** | `savings_deposits`, `loans`, `installment_schedules`, `installments`, `savings_withdrawals`, `shopping_transactions` |
| **Dokumen** | `documents` (lampiran tertaut anggota/transaksi) |
| **Sistem** | `activity_log`, `idempotency_keys`, `failed_jobs`, `jobs` |

### Relasi Utama

```
agencies (1) ─── (N) members
members (1) ─── (N) savings_deposits
members (1) ─── (N) loans
loans (1) ─── (N) installment_schedules
loans (1) ─── (N) installments
members (1) ─── (N) documents
users (1) ─── (N) activity_log
```

### Index Wajib

- `members.nik` UNIQUE
- `members.member_number` UNIQUE
- `savings_deposits(member_id, savings_type, period_month)`
- `savings_deposits(idempotency_key)` UNIQUE
- `loans(member_id, status)` untuk pengecekan pinjaman aktif
- Seluruh foreign key harus diindeks

### Konvensi Tipe Data

| Data | Tipe | Catatan |
|---|---|---|
| Uang | `DECIMAL(18,2)` | **Wajib**, bukan FLOAT |
| Persentase | `DECIMAL(6,5)` | Admin 0,01 (1%); SWP 0,01 (1%); Jasa 0,0065 (0,65%); Tab. Berjangka 0,001 (0,1%) |
| Tanggal | `DATE` | |
| ID | `BIGINT UNSIGNED` | Auto increment |
| UUID | `CHAR(36)` | |

---

## 7. Hak Akses

### Role

| Role | Deskripsi |
|---|---|
| **Super Admin** | Tim IT, akses penuh termasuk manajemen pengguna |
| **Pengurus** | Ketua/Bendahara, akses seluruh laporan & pencatatan |
| **Petugas** | Input transaksi harian (setoran, angsuran, pinjaman ber-ACC) |

### Matriks Akses

| Modul | Super Admin | Pengurus | Petugas |
|---|:-:|:-:|:-:|
| Master Anggota | F | F | RA |
| Master OPD | F | F | R |
| Konfigurasi Simpanan | F | F | R |
| Setoran Simpanan | F | F | F |
| Pinjaman | F | F | F |
| Angsuran | F | F | F |
| Dokumen | F | F | RA |
| Laporan | F | F | TR |
| Manajemen Pengguna | F | – | – |
| Audit Log | F | R | – |

> **Legenda**: F = Akses Penuh, R = Lihat, RA = Lihat + Tambah, TR = Terbatas, – = Tidak ada akses

---

## 8. Alur Bisnis Utama

### 8.1 Pendaftaran Anggota Baru

1. Calon anggota mengisi **Form Permohonan Anggota Baru** (Pokok, Wajib sesuai golongan, Wajib Belanja, serta Hari Raya yang bersifat opsional).
2. Petugas menginput data anggota; golongan menentukan nominal wajib otomatis.
3. Sistem menghasilkan nomor anggota.
4. Petugas mencatat setoran **Simpanan Pokok Rp 50.000**.
5. Dokumen permohonan di-scan dan diunggah ke sistem.
6. Kartu anggota dicetak; anggota menjadi aktif.

### 8.2 Setoran Simpanan Bulanan

**Potong gaji (kolektif per OPD):**

1. Petugas memilih OPD → tampil daftar anggota aktif + nominal default per golongan.
2. Petugas mencentang anggota dan jenis simpanan → submit sekaligus.
3. Rekap potongan gaji per OPD dicetak.

**Setor sendiri:**

1. Anggota/bendahara menyetor → petugas mencatat per transaksi.
2. Slip setoran dicetak.

> Bulan yang tidak disetor dibiarkan bolong, tanpa denda.

### 8.3 Pencatatan Pinjaman (Setelah ACC)

1. Admin/bendahara **menyetujui pinjaman di luar sistem**.
2. Anggota mengisi **formulir pinjaman / Tanda Terima Pinjaman Uang** (wajib untuk semua pinjaman).
3. Petugas mencatat pinjaman di sistem; sistem menghitung potongan (Admin 1% + SWP 1%) dan **jumlah diterima**.
4. Sistem menghasilkan jadwal angsuran (pokok + jasa + tabungan berjangka).
5. Dokumen tanda terima/permohonan diunggah.

### 8.4 Pembayaran Angsuran

1. Petugas membuka halaman pembayaran cepat.
2. Memasukkan nomor anggota → tampil pinjaman aktif & angsuran jatuh tempo.
3. Petugas mencatat pembayaran (potong gaji/manual).
4. Sistem mencatat pokok, jasa, dan menambah Tabungan Berjangka.
5. Kuitansi dicetak.
6. Jika angsuran terakhir lunas → status **Lunas**, **SWP + Tabungan Berjangka dikembalikan**.

### 8.5 Pinjaman Jangka Pendek (Sebrakan)

1. Anggota meminjam < Rp 1.000.000 (sudah ACC di luar sistem), mengisi formulir pinjaman.
2. Petugas mencatat pinjaman tanpa potongan dan tanpa angsuran.
3. Anggota mengembalikan penuh pada bulan berikutnya → status Lunas.

### 8.6 Koreksi Transaksi (Reversal)

1. Petugas membuka transaksi yang salah → menekan **Reversal**.
2. Memasukkan alasan koreksi.
3. Sistem membuat transaksi lawan (`is_reversal = true`); saldo terkoreksi otomatis.
4. Audit log mencatat seluruh perubahan.

---

## 9. Aturan Bisnis

### Anggota

- NIK harus 16 digit dan unik.
- Golongan menentukan nominal simpanan wajib secara otomatis.
- Anggota berstatus "Keluar" tidak dapat bertransaksi.
- Anggota tidak dapat dihapus permanen jika memiliki transaksi.

### Simpanan

- Simpanan Pokok Rp 50.000, dibayar sekali.
- Simpanan Wajib mengikuti golongan; boleh bolong tanpa sanksi.
- Wajib Belanja Rp 100.000/bulan bersifat **wajib namun boleh bolong**; saldo hanya untuk belanja, tidak diuangkan.
- Simpanan Hari Raya **opsional** (ikut/tidak), nominal di-deal saat RAT; dicairkan menjelang hari raya setiap tahun.
- Tidak ada potongan koperasi atas simpanan. Bunga simpanan menunggu konfirmasi.

### Pinjaman

- Pinjaman dicatat hanya setelah disetujui (ACC) di luar sistem.
- **Semua pinjaman wajib disertai pengisian formulir** (jangka pendek & panjang).
- Pinjaman jangka panjang = nominal **> Rp 1.000.000**, dikenai Admin 1% + SWP 1% saat pencairan.
- Tenor pinjaman jangka panjang diinput per pengajuan (tidak ada batas baku di sistem).
- Angsuran wajib via potong gaji.
- Anggota yang masuk **blacklist** tidak dapat mengajukan pinjaman.
- Pinjaman jangka pendek (Sebrakan) **< Rp 1.000.000**: tanpa potongan, tanpa angsuran, tenor 1 bulan.

### Angsuran

- Angsuran/bulan = Pokok + Jasa (Pokok × 0,65%) + Tabungan Berjangka (Pokok × 0,1%).
- Biaya Admin (1%) dan SWP (1%) dihitung dari plafon; Jasa & Tabungan Berjangka dari Pokok.
- Boleh bolong; tidak ada pemutusan otomatis.
- SWP dan Tabungan Berjangka dikembalikan saat pinjaman lunas.

### Transaksi Umum

- Tidak ada penghapusan permanen untuk transaksi keuangan.
- Koreksi wajib melalui reversal.
- Setiap transaksi tercatat dalam audit log.

---

## 10. Backup & Keamanan

### Backup

- **Otomatis harian** ke dua lokasi: penyimpanan internal server dan cloud/NAS terpisah (terenkripsi).
- Retensi: 30 hari (harian), 12 bulan (bulanan).
- Uji pemulihan minimal satu kali per kuartal.
- **Dokumen lampiran (scan)** ikut dicadangkan.

### Keamanan

- **HTTPS** wajib.
- **Kebijakan kata sandi**: minimal 8 karakter.
- **Batas sesi**: keluar otomatis setelah 30 menit tidak aktif.
- **Pembatasan login**: 5 kali gagal → penguncian 15 menit.
- **Whitelist IP** opsional untuk membatasi akses ke jaringan internal.
- **Audit log**: merekam seluruh aktivitas (login, CRUD, ekspor, unduh dokumen).

### Pemulihan Bencana

- **RTO** < 4 jam, **RPO** < 24 jam.
- Tersedia dokumentasi runbook pemulihan langkah demi langkah.

---

## 11. Rencana Pengerjaan

### Total Durasi: 1 Bulan (4 Minggu)

| Minggu | Fokus | Cakupan |
|---|---|---|
| **Minggu 1** | Fondasi & Master Data | Setup Laravel + Filament + Spatie Permission + paket Spatie, lokalisasi, audit log, Master Anggota (golongan, unggah dokumen, kartu PDF, impor Excel), Master OPD, konfigurasi golongan & jenis simpanan, dashboard awal |
| **Minggu 2** | Simpanan | Enam jenis simpanan, setoran tunggal & kolektif per OPD (potong gaji), menu pemakaian saldo Wajib Belanja (manual), slip PDF, reversal, Form Permohonan Anggota Baru (cetak + simpan scan) |
| **Minggu 3** | Pinjaman & Angsuran | Pinjaman jangka pendek & panjang, kalkulator potongan (Admin/SWP) & angsuran (pokok/jasa/tab. berjangka), blacklist, jadwal otomatis, Tanda Terima Pinjaman (PDF), halaman pembayaran cepat, pengembalian SWP & Tab. Berjangka saat lunas, kuitansi |
| **Minggu 4** | Pengujian & Serah Terima | UAT dengan kasus nyata, perbaikan bug, hardening, pelatihan pengguna, dokumentasi manual, deployment produksi |

### Catatan Penjadwalan

- **Integrasi aplikasi toko (Wajib Belanja)** memerlukan koordinasi teknis dengan pihak aplikasi Pak Hestu dan **dijadwalkan terpisah** dari target satu bulan ini. Pada tahap ini, saldo Wajib Belanja dicatat di sistem koperasi; sinkronisasi pemakaian dengan toko menyusul.
- Unit test untuk kalkulator angsuran dan potongan ditulis bersamaan dengan modul terkait.
- Data awal anggota (termasuk golongan & OPD) dari koperasi diperlukan paling lambat awal Minggu 2 untuk pengujian berbasis data nyata.

---

## 12. Risiko & Mitigasi

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Kerusakan data keuangan | Sangat Tinggi | Backup harian + DECIMAL + DB transaction + log immutable |
| Kesalahan kalkulasi jasa/potongan | Tinggi | Unit test menyeluruh + UAT dengan kasus nyata |
| Kesalahan input transaksi | Sedang | Mekanisme reversal + audit log |
| Penghapusan data keliru | Sedang | Soft delete + RBAC + tanpa hard delete |
| Integrasi toko belum siap | Sedang | Saldo Wajib Belanja tetap dicatat; integrasi dijadwalkan terpisah |
| Keterlambatan data awal koperasi | Sedang | Komunikasi kebutuhan data di awal + impor Excel |
| Server bermasalah | Tinggi | Backup harian + dokumentasi pemulihan bencana |
| Performa lambat | Rendah | Index basis data + paginasi + cache widget dashboard |

---

## 13. Hal yang Masih Menunggu Konfirmasi

| # | Hal | Status |
|---|---|---|
| 1 | Wajib Belanja saat anggota keluar — diuangkan atau dalam bentuk barang? | **Belum ditentukan** |
| 2 | Bunga simpanan | Diasumsikan **tidak ada** (perlu konfirmasi final) |
| 3 | Perhitungan SHU (Sisa Hasil Usaha) | **Belum ditentukan** — metode perhitungan menunggu ketentuan koperasi |
| 4 | Minimal nominal pengambilan simpanan | **Belum ditentukan** |
| 5 | Rincian laporan bulanan | Akan dibahas pada tahap berikutnya |

> Poin-poin di atas tidak menghambat pengerjaan modul inti dan akan disesuaikan setelah dikonfirmasi.

---

## 14. Pengembangan Lanjutan

Sistem dirancang agar modul berikut dapat ditambahkan tanpa membangun ulang:

- **Integrasi penuh aplikasi toko (Pak Hestu) via API** — penyediaan API agar transaksi belanja anggota di toko otomatis mengurangi saldo Wajib Belanja secara real-time, beserta migrasi datanya. Selama transisi, pemakaian saldo dicatat melalui menu manual.
- **Perhitungan SHU** — disusun setelah metode perhitungan SHU ditetapkan koperasi.
- **Akuntansi double-entry** — jurnal, buku besar, dan neraca formal.
- **Tutup buku** — penutupan periode bulanan dan tahunan.
- **Portal mandiri anggota** — pengecekan saldo dan riwayat oleh anggota.
- **Notifikasi otomatis** — pengingat jatuh tempo & jadwal RAT via SMS/WhatsApp.

---

## Lampiran: Glosarium

| Istilah | Definisi |
|---|---|
| **KPRI** | Koperasi Pegawai Republik Indonesia |
| **KOPEKOMA** | Nama koperasi (KPRI Kota Magelang) |
| **ASN** | Aparatur Sipil Negara |
| **OPD** | Organisasi Perangkat Daerah (instansi tempat anggota bekerja) |
| **NIP** | Nomor Induk Pegawai |
| **HR/THL** | Honorer / Tenaga Harian Lepas (golongan simpanan wajib Rp 30.000) |
| **Golongan** | Tingkat kepangkatan (HR/THL, GOL 1–4) yang menentukan nominal simpanan wajib |
| **SWP** | Simpanan Wajib Pinjam — dipotong 1% saat pinjaman cair, dikembalikan saat lunas |
| **Tabungan Berjangka** | Simpanan sebesar Pokok × 0,1% per bulan dalam angsuran, dikembalikan saat lunas |
| **Sebrakan** | Pinjaman jangka pendek < Rp 1 juta, tanpa biaya & tanpa angsuran |
| **RAT** | Rapat Anggota Tahunan (sekitar Februari–Maret) |
| **SHU** | Sisa Hasil Usaha |
| **Reversal** | Koreksi transaksi melalui transaksi lawan |
| **Outstanding** | Sisa pokok pinjaman yang belum dibayar |

---

*Sistem Informasi Koperasi Simpan Pinjam KPRI KOPEKOMA — Versi 5.0.0*
