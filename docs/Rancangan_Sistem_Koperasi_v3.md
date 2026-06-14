# Dokumen Rancangan Sistem Informasi Koperasi Simpan Pinjam
### Versi Sederhana — Internal System

| | |
|---|---|
| **Versi** | 3.0.0 (Sederhana) |
| **Tanggal** | 2026 |
| **Teknologi** | Laravel 11 + Filament 3 + MySQL 8 (PHP 8.2+) |
| **Skala Target** | 200–1000 anggota, 1 lokasi |
| **Status** | Draft — Untuk Review |
| **Tipe Akses** | Internal Only |
| **Bahasa UI** | Bahasa Indonesia |

---

## Daftar Isi

1. [Pendahuluan](#1-pendahuluan)
2. [Ruang Lingkup](#2-ruang-lingkup)
3. [Prinsip Desain](#3-prinsip-desain)
4. [Arsitektur & Stack](#4-arsitektur--stack)
5. [Modul Sistem](#5-modul-sistem)
   - [5.1 Master Data Anggota](#51-master-data-anggota)
   - [5.2 Master Data Instansi](#52-master-data-instansi)
   - [5.3 Besaran Simpanan](#53-besaran-simpanan)
   - [5.4 Input Setoran Simpanan](#54-input-setoran-simpanan)
   - [5.5 Input Pinjaman](#55-input-pinjaman)
   - [5.6 Input Angsuran](#56-input-angsuran)
   - [5.7 Laporan](#57-laporan)
6. [Database](#6-database)
7. [Hak Akses](#7-hak-akses)
8. [Alur Bisnis Utama](#8-alur-bisnis-utama)
9. [Aturan Bisnis](#9-aturan-bisnis)
10. [Backup & Keamanan](#10-backup--keamanan)
11. [Roadmap](#11-roadmap)
12. [Risiko & Mitigasi](#12-risiko--mitigasi)

---

## 1. Pendahuluan

Sistem Informasi Koperasi Simpan Pinjam (KSP) berbasis web untuk **digunakan internal** oleh pengurus dan petugas koperasi. Sistem ini memodernisasi pencatatan simpanan dan pinjaman anggota yang sebelumnya manual.

Dokumen ini adalah **versi sederhana (v3)** yang fokus pada 7 fitur utama operasional harian. Modul-modul lanjutan (akuntansi double-entry, SHU engine otomatis, tutup buku, deposito) **tidak masuk scope** versi ini dan dapat ditambahkan di kemudian hari.

### Permasalahan yang Diselesaikan

1. Pencatatan simpanan dan pinjaman manual di Excel rawan error
2. Sulit mengetahui saldo real-time per anggota
3. Perhitungan angsuran dan denda manual memakan waktu
4. Tidak ada riwayat audit siapa input/ubah data
5. Laporan disusun manual setiap akhir bulan

---

## 2. Ruang Lingkup

### Fitur Utama (In-Scope)

1. ✅ **Master Data Anggota** — pengelolaan data lengkap anggota
2. ✅ **Master Data Instansi** — pengelolaan instansi tempat anggota bekerja
3. ✅ **Besaran Simpanan** — konfigurasi nominal simpanan pokok, wajib, sukarela
4. ✅ **Input Setoran Simpanan** — pencatatan setoran harian/bulanan
5. ✅ **Input Pinjaman** — pengajuan dan pencairan pinjaman
6. ✅ **Input Angsuran** — pencatatan pembayaran angsuran
7. ✅ **Laporan-laporan** — laporan operasional & ringkas keuangan

### Out of Scope (Tidak Dikerjakan di v3)

- ❌ Akuntansi double-entry (jurnal, buku besar, neraca formal)
- ❌ Simpanan Berjangka / Deposito
- ❌ SHU engine otomatis (perhitungan SHU dilakukan manual / Excel)
- ❌ Tutup buku bulanan/tahunan
- ❌ Manajemen kas multi-rekening (cukup pencatatan sederhana)
- ❌ Restrukturisasi & top-up pinjaman
- ❌ Portal anggota self-service
- ❌ Notifikasi otomatis (SMS/WA)

> Modul-modul di atas dapat ditambahkan di **fase lanjutan** sesuai kebutuhan dan anggaran.

---

## 3. Prinsip Desain

Walaupun versi sederhana, sistem ini tetap menyimpan **uang anggota** sehingga prinsip berikut **wajib** dijaga:

1. **DECIMAL untuk Uang** — semua kolom uang `DECIMAL(18,2)`. Dilarang `FLOAT/DOUBLE`.
2. **Database Transaction** — operasi yang menulis ke ≥2 tabel dibungkus `DB::transaction()`.
3. **Audit Log** — setiap CREATE/UPDATE/DELETE tercatat: siapa, kapan, dari/ke nilai apa.
4. **Reversal, Bukan Delete** — transaksi keuangan tidak boleh di-delete. Koreksi dengan transaksi lawan (reversal).
5. **Idempotency Key** — form transaksi pakai UUID untuk cegah double-submit.
6. **Saldo Dihitung dari Transaksi** — saldo bukan field yang di-update manual, tapi hasil agregat dari transaksi (boleh di-cache).
7. **Backup Harian** — wajib ada backup database otomatis ke storage terpisah.

---

## 4. Arsitektur & Stack

### Teknologi

| Komponen | Pilihan |
|---|---|
| **Framework** | Laravel 11 (PHP 8.2+) |
| **Admin Panel UI** | Filament 3 |
| **Database** | MySQL 8.0 |
| **Authentication** | Filament built-in (session-based) |
| **RBAC** | Spatie Permission + Filament Shield |
| **Audit Log** | `owen-it/laravel-auditing` |
| **PDF** | DomPDF / Browsershot |
| **Excel Export** | `pxlrbt/filament-excel` |
| **Backup** | Spatie Laravel Backup |
| **Web Server** | Nginx |

### Mengapa Filament?

- Admin panel siap pakai → fokus ke business logic, bukan UI dari nol
- Form, tabel, dashboard, RBAC sudah built-in
- Cocok 100% untuk sistem internal admin-heavy
- Hemat waktu development 30-40% dibanding Blade/Livewire murni

### Pemetaan Modul

| Modul | Pattern Filament |
|---|---|
| Master Anggota | Resource (CRUD) |
| Master Instansi | Resource (CRUD) |
| Besaran Simpanan | Resource (CRUD) |
| Setoran Simpanan | Resource + Custom Action (reversal) |
| Pinjaman | Resource + Wizard pengajuan |
| Angsuran | Resource (read-mostly) + Custom Page bayar cepat |
| Laporan | Custom Page dengan filter + export Excel/PDF |
| Dashboard | Filament Widgets |

### Struktur Direktori Singkat

```
app/
├── Models/                  # Anggota, Instansi, Pinjaman, dst.
├── Filament/
│   ├── Resources/           # CRUD per modul
│   ├── Pages/               # Halaman laporan & bayar angsuran
│   └── Widgets/             # Dashboard
├── Services/                # Logika bisnis (kalkulator bunga, dll)
├── Enums/                   # Status, Jenis Simpanan, dll
└── Policies/

database/
├── migrations/
└── seeders/                 # Role, jenis simpanan default
```

> **Prinsip**: Business logic (kalkulasi bunga, generate jadwal angsuran, hitung denda) **wajib** ada di `app/Services`, **bukan** di Filament Resource.

---

## 5. Modul Sistem

### 5.1 Master Data Anggota

> **Tujuan**: Mengelola data lengkap seluruh anggota koperasi.

#### Field Anggota

| Field | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `no_anggota` | VARCHAR(20) | ✅ | Auto-generate `KSP-YYYY-NNNN` |
| `nik` | VARCHAR(16) | ✅ | Unique |
| `nama_lengkap` | VARCHAR(100) | ✅ | Sesuai KTP |
| `tempat_lahir` | VARCHAR(50) | ✅ | |
| `tanggal_lahir` | DATE | ✅ | |
| `jenis_kelamin` | ENUM | ✅ | L / P |
| `instansi_id` | FK | ✅ | Ref ke instansi |
| `jabatan` | VARCHAR(100) | – | |
| `tanggal_masuk` | DATE | ✅ | Tanggal jadi anggota |
| `tanggal_keluar` | DATE | – | Diisi saat keluar |
| `no_hp` | VARCHAR(15) | ✅ | |
| `alamat` | TEXT | ✅ | |
| `no_rekening_bank` | VARCHAR(30) | – | Untuk transfer |
| `nama_bank` | VARCHAR(50) | – | |
| `ahli_waris_nama` | VARCHAR(100) | ✅ | Untuk klaim |
| `ahli_waris_hubungan` | VARCHAR(50) | ✅ | |
| `ahli_waris_no_hp` | VARCHAR(15) | ✅ | |
| `status` | ENUM | ✅ | Aktif / Non-Aktif / Keluar / Meninggal |
| `foto` | VARCHAR(255) | – | |

#### Fitur

- CRUD lengkap dengan validasi NIK unique
- Auto-generate nomor anggota
- Filter & search by nama/NIK/instansi/status
- Cetak kartu anggota PDF
- Tab riwayat: simpanan & pinjaman per anggota
- Import dari Excel (bulk upload dengan validasi)
- **Soft delete** — data anggota tidak benar-benar dihapus, hanya status berubah

---

### 5.2 Master Data Instansi

> **Tujuan**: Mengelola data instansi tempat anggota bekerja (untuk batch setoran wajib via potong gaji).

| Field | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `kode_instansi` | VARCHAR(10) | ✅ | Unique |
| `nama_instansi` | VARCHAR(150) | ✅ | |
| `alamat` | TEXT | – | |
| `kontak_person` | VARCHAR(100) | – | PIC instansi |
| `no_hp_pic` | VARCHAR(15) | – | |
| `status` | ENUM | ✅ | Aktif / Non-Aktif |

#### Fitur

- CRUD instansi
- Daftar anggota per instansi
- Statistik: jumlah anggota, total simpanan, total pinjaman per instansi

---

### 5.3 Besaran Simpanan

> **Tujuan**: Konfigurasi nominal dan jenis simpanan yang berlaku.

#### Jenis Simpanan

| Jenis | Sifat | Frekuensi | Dapat Ditarik |
|---|---|---|---|
| **Pokok** | Wajib | Sekali (saat masuk) | Hanya saat keluar |
| **Wajib** | Wajib | Bulanan | Hanya saat keluar |
| **Sukarela** | Bebas | Kapan saja | Kapan saja (jika saldo cukup) |

#### Field Konfigurasi

| Field | Tipe | Keterangan |
|---|---|---|
| `jenis_simpanan` | ENUM | Pokok / Wajib / Sukarela |
| `nominal` | DECIMAL(15,2) | Untuk Pokok & Wajib |
| `nominal_minimum` | DECIMAL(15,2) | Untuk Sukarela |
| `berlaku_mulai` | DATE | |
| `berlaku_sampai` | DATE | NULL = selamanya |
| `keterangan` | TEXT | |

#### Fitur

- CRUD nominal simpanan (history perubahan disimpan)
- Saat besaran berubah → berlaku untuk transaksi setelah `berlaku_mulai`
- Validasi saat input setoran wajib: harus sesuai nominal yang berlaku

---

### 5.4 Input Setoran Simpanan

> **Tujuan**: Mencatat setoran simpanan dari anggota.

| Field | Tipe | Keterangan |
|---|---|---|
| `no_transaksi` | VARCHAR(25) | Auto-generate `STR-YYYYMMDD-NNNN` |
| `idempotency_key` | UUID | Cegah double submit |
| `anggota_id` | FK | |
| `jenis_simpanan` | ENUM | Pokok / Wajib / Sukarela |
| `nominal` | DECIMAL(15,2) | |
| `tanggal_setor` | DATE | |
| `bulan_periode` | DATE | Untuk wajib (YYYY-MM-01) |
| `metode_bayar` | ENUM | Tunai / Transfer / Potong Gaji |
| `no_referensi` | VARCHAR(50) | No bukti transfer (opsional) |
| `keterangan` | TEXT | |
| `is_reversal` | BOOLEAN | Default false |
| `reversal_of_id` | FK | Pointer ke transaksi yang di-reverse |
| `user_input` | FK | Petugas |

#### Fitur

- **Input single**: form sederhana per transaksi
- **Input batch per instansi**: pilih instansi → centang anggota → input nominal → simpan sekaligus (untuk potong gaji bulanan)
- Validasi: setoran wajib bulan yang sama tidak boleh dobel
- Validasi: nominal pokok & wajib harus sesuai konfigurasi
- Cetak slip setor (PDF)
- **Tidak bisa edit/delete**: koreksi via reversal action
- Saldo simpanan auto-update setelah setoran

---

### 5.5 Input Pinjaman

> **Tujuan**: Mencatat pengajuan dan pencairan pinjaman.

| Field | Tipe | Keterangan |
|---|---|---|
| `no_pinjaman` | VARCHAR(25) | `PJ-YYYY-NNNN` |
| `anggota_id` | FK | |
| `jumlah_pinjaman` | DECIMAL(15,2) | Plafon |
| `biaya_admin` | DECIMAL(15,2) | Default 0, dipotong dari pencairan |
| `jumlah_diterima` | DECIMAL(15,2) | = pinjaman - biaya_admin |
| `jenis_bunga` | ENUM | Flat / Efektif / Anuitas |
| `suku_bunga_per_bulan` | DECIMAL(5,4) | % per bulan |
| `tenor` | INT | Bulan (1–60) |
| `denda_per_hari` | DECIMAL(15,2) | Nominal denda per hari telat |
| `tanggal_pengajuan` | DATE | |
| `tanggal_disetujui` | DATE | |
| `tanggal_cair` | DATE | |
| `tanggal_jatuh_tempo_pertama` | DATE | |
| `status` | ENUM | Pengajuan / Disetujui / Cair / Lunas / Ditolak |
| `jaminan` | TEXT | Deskripsi jaminan |
| `tujuan_pinjaman` | TEXT | |
| `disetujui_oleh` | FK | User pengurus |
| `keterangan` | TEXT | |

#### Jenis Bunga

| Metode | Cara Hitung |
|---|---|
| **Flat** | Bunga = Pokok × Suku × Tenor; Angsuran/bln = (Pokok + Total Bunga) / Tenor |
| **Efektif** | Bunga bulan ini = Sisa Pokok × Suku; Pokok angsuran tetap, bunga menurun |
| **Anuitas** | Angsuran = P × [i(1+i)^n] / [(1+i)^n − 1] (total angsuran tetap) |

#### Fitur

- **Wizard pengajuan**: Anggota → Plafon & Bunga → Jaminan → Preview Jadwal → Konfirmasi
- Kalkulator angsuran live di form
- Workflow: **Pengajuan → Disetujui → Cair**
- Auto-generate jadwal angsuran saat status = Cair
- Validasi: max pinjaman = N × saldo simpanan (configurable)
- Validasi: 1 anggota = 1 pinjaman aktif (configurable, bisa dimatikan)
- Cetak surat perjanjian pinjaman PDF
- Cetak jadwal angsuran PDF

---

### 5.6 Input Angsuran

> **Tujuan**: Mencatat pembayaran cicilan pinjaman.

| Field | Tipe | Keterangan |
|---|---|---|
| `no_angsuran` | VARCHAR(25) | `ANG-YYYYMMDD-NNNN` |
| `idempotency_key` | UUID | |
| `pinjaman_id` | FK | |
| `jadwal_angsuran_id` | FK | Link ke baris jadwal |
| `ke_angsuran` | INT | Cicilan ke-N |
| `tanggal_bayar` | DATE | |
| `jatuh_tempo` | DATE | |
| `hari_terlambat` | INT | Auto-hitung |
| `nominal_dibayar` | DECIMAL(15,2) | Total |
| `bayar_pokok` | DECIMAL(15,2) | |
| `bayar_bunga` | DECIMAL(15,2) | |
| `bayar_denda` | DECIMAL(15,2) | |
| `sisa_pokok_setelah` | DECIMAL(15,2) | |
| `metode_bayar` | ENUM | Tunai / Transfer / Potong Gaji |
| `tipe_pembayaran` | ENUM | Normal / Pelunasan Dipercepat |
| `is_reversal` | BOOLEAN | |
| `reversal_of_id` | FK | |
| `user_input` | FK | |

#### Fitur

- **Halaman bayar cepat**: petugas input no anggota → tampil daftar pinjaman aktif & angsuran due → input nominal → simpan
- Perhitungan denda otomatis (hari telat × tarif denda)
- Pembayaran parsial: split otomatis (denda → bunga → pokok)
- Pelunasan dipercepat: hitung sisa pokok + bunga berjalan
- Cetak kwitansi PDF
- Status pinjaman → `Lunas` otomatis saat angsuran terakhir terbayar
- Reversal jika salah input

---

### 5.7 Laporan

> Karena Anda bilang belum tahu laporan apa saja, berikut **laporan standar** yang biasa dibutuhkan KSP. Saya kelompokkan per kebutuhan.

#### A. Laporan Operasional Harian/Bulanan

| Laporan | Isi | Format | Pengguna |
|---|---|---|---|
| **Rekap Setoran Harian** | Daftar setoran per hari, total per jenis simpanan, total tunai/transfer | PDF/Excel | Petugas, Pengurus |
| **Rekap Angsuran Harian** | Daftar angsuran masuk per hari, total pokok/bunga/denda | PDF/Excel | Petugas, Pengurus |
| **Rekap Setoran per Periode** | Total setoran dalam rentang tanggal, group by jenis & instansi | Excel | Pengurus |
| **Rekap Pencairan Pinjaman** | Daftar pinjaman cair dalam periode, total dana keluar | Excel | Pengurus |

#### B. Laporan Anggota

| Laporan | Isi | Format | Pengguna |
|---|---|---|---|
| **Daftar Anggota Aktif** | Semua anggota status aktif, group by instansi | PDF/Excel | Pengurus |
| **Buku Tabungan Anggota** | Riwayat setoran + saldo berjalan per anggota | PDF | Petugas, Anggota |
| **Daftar Saldo Simpanan** | Saldo per anggota (Pokok/Wajib/Sukarela), total per jenis | Excel | Pengurus |
| **Kartu Anggota** | Cetak kartu anggota dengan foto & no anggota | PDF | Petugas |

#### C. Laporan Pinjaman

| Laporan | Isi | Format | Pengguna |
|---|---|---|---|
| **Daftar Pinjaman Aktif** | Semua pinjaman berjalan, outstanding pokok, sisa tenor | Excel | Pengurus |
| **Jadwal Angsuran per Pinjaman** | Schedule lengkap pinjaman tertentu | PDF | Petugas, Anggota |
| **Daftar Tunggakan Pinjaman** | Pinjaman dengan angsuran lewat jatuh tempo + nominal denda | Excel | Pengurus, Penagih |
| **Riwayat Angsuran per Pinjaman** | Semua pembayaran cicilan untuk 1 pinjaman | PDF | Petugas |
| **Pinjaman Akan Jatuh Tempo** | Angsuran due dalam 7/14/30 hari ke depan | Excel | Petugas |

#### D. Laporan Tunggakan & Kepatuhan

| Laporan | Isi | Format | Pengguna |
|---|---|---|---|
| **Tunggakan Simpanan Wajib** | Anggota yang belum setor wajib bulan tertentu | Excel | Pengurus |
| **Tunggakan Pinjaman per Instansi** | Group tunggakan by instansi (untuk koordinasi potong gaji) | Excel | Pengurus |

#### E. Laporan Ringkasan Keuangan (Sederhana)

| Laporan | Isi | Format | Pengguna |
|---|---|---|---|
| **Ringkasan Bulanan** | Total simpanan masuk, pinjaman cair, angsuran masuk, denda terkumpul | PDF | Pengurus |
| **Ringkasan Saldo Koperasi** | Total dana simpanan, total piutang pinjaman, dana di koperasi (sederhana) | PDF | Pengurus |

#### F. Dashboard (Real-time, Bukan PDF)

Halaman utama menampilkan:
- 👥 **Total anggota aktif**
- 💰 **Total saldo simpanan** (per jenis)
- 💳 **Total outstanding pinjaman**
- ⚠️ **Jumlah tunggakan** (pinjaman & simpanan wajib)
- 📊 **Grafik tren setoran 6 bulan terakhir**
- 📊 **Grafik tren pencairan pinjaman 6 bulan terakhir**
- 🔔 **Pinjaman jatuh tempo minggu ini**

---

## 6. Database

### Daftar Tabel

| Kategori | Tabel |
|---|---|
| **Master** | `users`, `roles`, `permissions`, `instansi`, `anggota`, `konfigurasi_simpanan` |
| **Transaksi** | `setoran_simpanan`, `pinjaman`, `jadwal_angsuran`, `angsuran` |
| **Sistem** | `audit_log`, `idempotency_key`, `failed_jobs`, `jobs` |

### Relasi Utama

```
instansi (1) ─── (N) anggota
anggota (1) ─── (N) setoran_simpanan
anggota (1) ─── (N) pinjaman
pinjaman (1) ─── (N) jadwal_angsuran
pinjaman (1) ─── (N) angsuran
jadwal_angsuran (1) ─── (0..N) angsuran
users (1) ─── (N) audit_log
```

### Index Wajib

- `anggota.nik` UNIQUE
- `anggota.no_anggota` UNIQUE
- `setoran_simpanan(anggota_id, jenis_simpanan, bulan_periode)` untuk validasi dobel
- `setoran_simpanan(idempotency_key)` UNIQUE
- `pinjaman(anggota_id, status)` untuk cek pinjaman aktif
- Semua FK harus diindex

### Konvensi Tipe Data

| Data | Tipe | Catatan |
|---|---|---|
| Uang | `DECIMAL(18,2)` | **Wajib**, bukan FLOAT |
| Persentase | `DECIMAL(5,4)` | Mis: 0.0125 = 1.25% |
| Tanggal | `DATE` | |
| ID | `BIGINT UNSIGNED` | Auto increment |
| UUID | `CHAR(36)` | |

---

## 7. Hak Akses

### Role

| Role | Deskripsi |
|---|---|
| **Super Admin** | IT, akses penuh + manajemen user |
| **Pengurus** | Ketua/Bendahara, approval pinjaman, akses semua laporan |
| **Petugas** | Input transaksi harian (setoran, angsuran) |

### Matriks Akses

| Modul | Super Admin | Pengurus | Petugas |
|---|:-:|:-:|:-:|
| Master Anggota | F | F | RA |
| Master Instansi | F | F | R |
| Besaran Simpanan | F | F | R |
| Setoran Simpanan | F | F | F |
| Pinjaman (input) | F | A | I |
| Pinjaman (approve) | F | A | – |
| Angsuran | F | F | F |
| Laporan | F | F | TR |
| User Management | F | – | – |
| Audit Log | F | R | – |

> **Legenda**: F=Full, R=Read, RA=Read+Add, I=Input only, A=Approve, TR=Terbatas, –=No access

---

## 8. Alur Bisnis Utama

### 8.1 Pendaftaran Anggota Baru

1. Petugas: input data anggota lengkap
2. Sistem: auto-generate nomor anggota
3. Petugas: input setoran simpanan pokok pertama
4. Cetak kartu anggota
5. Anggota aktif dan bisa bertransaksi

### 8.2 Setoran Simpanan Wajib Bulanan

**Single input:**
1. Petugas: pilih anggota → pilih jenis Wajib → input nominal & periode
2. Sistem: validasi tidak ada duplikasi periode
3. Cetak slip

**Batch per instansi (potong gaji):**
1. Petugas: pilih instansi → tampil daftar anggota aktif
2. Centang anggota + nominal default sudah terisi
3. Submit → semua tercatat sekaligus
4. Cetak rekap setoran instansi

### 8.3 Pengajuan & Pencairan Pinjaman

1. Anggota datang → petugas input pengajuan via wizard
2. Sistem: tampilkan analisa kelayakan (saldo simpanan, riwayat)
3. Pengurus: review → **Disetujui** atau **Ditolak**
4. Petugas: input tanggal cair → status **Cair**
5. Sistem: auto-generate jadwal angsuran
6. Cetak surat perjanjian + jadwal

### 8.4 Pembayaran Angsuran

1. Petugas: buka halaman bayar cepat
2. Input no anggota / no pinjaman → tampil pinjaman aktif & angsuran due
3. Input tanggal bayar & nominal
4. Sistem: hitung otomatis (denda → bunga → pokok)
5. Cetak kwitansi
6. Jika ini angsuran terakhir → status pinjaman = Lunas

### 8.5 Koreksi Transaksi (Reversal)

1. Petugas: buka transaksi yang salah → klik **Reversal**
2. Sistem: input alasan koreksi
3. Sistem: buat transaksi lawan dengan flag `is_reversal=true`
4. Saldo otomatis ter-koreksi
5. Audit log mencatat semua perubahan

---

## 9. Aturan Bisnis

### Anggota
- NIK harus 16 digit & unique
- Anggota status "Keluar" tidak bisa transaksi
- Anggota tidak bisa di-hard-delete jika punya transaksi

### Simpanan
- Setoran wajib: 1 anggota = 1 record per bulan-periode
- Tidak bisa setor mundur lebih dari N bulan (configurable)
- Penarikan sukarela: saldo harus mencukupi

### Pinjaman
- Maksimal pinjaman: configurable (default 3× saldo simpanan)
- Maksimal 1 pinjaman aktif per anggota (configurable)
- Tenor: 1–60 bulan
- Tidak bisa cair jika status anggota ≠ Aktif

### Angsuran
- Tidak bisa input angsuran sebelum pinjaman cair
- Pembayaran parsial: split otomatis (denda → bunga → pokok)
- Pembayaran > sisa pokok ditolak (gunakan menu Pelunasan Dipercepat)

### Transaksi Umum
- Tidak ada hard delete untuk transaksi keuangan
- Koreksi wajib lewat reversal
- Setiap transaksi tercatat di audit log

---

## 10. Backup & Keamanan

### Backup
- **Otomatis harian** ke 2 lokasi:
  1. Storage internal server
  2. Cloud / NAS terpisah (terenkripsi)
- Retensi: 30 hari harian, 12 bulan bulanan
- Test restore minimum 1× per kuartal

### Keamanan
- **HTTPS** wajib (self-signed cert OK untuk LAN)
- **Password policy**: min 8 karakter
- **Session timeout**: 30 menit idle
- **Rate limiting** login: 5x salah = lock 15 menit
- **IP whitelist** opsional: batasi akses ke jaringan internal
- **Audit log**: rekam semua aktivitas (login, CRUD, export)

### Disaster Recovery
- **RTO** (Recovery Time): < 4 jam
- **RPO** (Recovery Point): < 24 jam
- Dokumentasi runbook restore step-by-step

---

## 11. Roadmap

### Estimasi Total: 2.5–3 Bulan

| Fase | Durasi | Fitur |
|---|---|---|
| **Fase 0 — Setup** | 1 minggu | Setup Laravel + Filament + Shield + Spatie packages, struktur project, seeder role, lokalisasi Bahasa Indonesia |
| **Fase 1 — Master Data** | 2 minggu | Master Anggota (Resource, kartu PDF, import Excel), Master Instansi, Besaran Simpanan, audit log, dashboard awal |
| **Fase 2 — Simpanan** | 2 minggu | Setoran Resource, batch setoran per instansi, slip PDF, reversal action, laporan setoran |
| **Fase 3 — Pinjaman** | 3 minggu | Pinjaman Resource + Wizard, kalkulator bunga (Flat/Efektif/Anuitas), workflow approval, jadwal angsuran auto-generate, surat perjanjian PDF, laporan pinjaman |
| **Fase 4 — Angsuran** | 2 minggu | Angsuran Resource, halaman bayar cepat, kalkulasi denda, pelunasan dipercepat, kwitansi PDF, laporan angsuran |
| **Fase 5 — Laporan** | 2 minggu | Semua laporan operasional, ekspor PDF/Excel, dashboard widgets |
| **Fase 6 — UAT & Hardening** | 1 minggu | UAT, bug fixing, training pengguna, dokumentasi user manual |

---

## 12. Risiko & Mitigasi

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Data keuangan korup | Sangat Tinggi | Backup harian + DECIMAL + DB transaction + immutable log |
| Bug kalkulasi bunga | Tinggi | Unit test 100% untuk BungaCalculator + UAT dengan kasus nyata |
| Salah input transaksi | Sedang | Reversal mechanism + audit log |
| User salah hapus | Sedang | Soft delete + RBAC + tidak ada hard delete di UI |
| Server crash | Tinggi | Backup harian + dokumentasi DR |
| Lupa password admin | Rendah | Prosedur reset via DB direct |
| Performance lambat | Rendah | Index DB + pagination + cache widget dashboard |

---

## Lampiran: Glosarium

| Istilah | Definisi |
|---|---|
| **KSP** | Koperasi Simpan Pinjam |
| **Simpanan Pokok** | Simpanan satu kali saat masuk anggota |
| **Simpanan Wajib** | Simpanan rutin bulanan |
| **Simpanan Sukarela** | Simpanan bebas seperti tabungan |
| **Outstanding** | Sisa pokok pinjaman yang belum dibayar |
| **Tenor** | Jangka waktu pinjaman dalam bulan |
| **Reversal** | Koreksi transaksi via transaksi lawan |
| **Idempotency** | Operasi yang menghasilkan hasil sama meski dijalankan berkali-kali |
| **RBAC** | Role-Based Access Control |

---

> ⚠️ **Catatan**: Dokumen ini bersifat *living document*. Versi simpel ini fokus operasional harian. Modul akuntansi double-entry, SHU, deposito, dan tutup buku dapat ditambahkan di versi selanjutnya.

*Sistem Informasi Koperasi Simpan Pinjam v3.0 — Versi Sederhana*
