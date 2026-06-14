# Dokumen Rancangan Sistem Informasi Koperasi Simpan Pinjam

| | |
|---|---|
| **Versi** | 4.0.0 |
| **Tanggal** | Juni 2026 |
| **Teknologi** | Laravel 11 + Filament 3 + MySQL 8 (PHP 8.2+) |
| **Skala Target** | 200–1.000 anggota, 1 lokasi |
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
   - [5.2 Master Data Instansi](#52-master-data-instansi)
   - [5.3 Besaran Simpanan](#53-besaran-simpanan)
   - [5.4 Setoran Simpanan](#54-setoran-simpanan)
   - [5.5 Pinjaman](#55-pinjaman)
   - [5.6 Angsuran](#56-angsuran)
   - [5.7 Laporan](#57-laporan)
6. [Database](#6-database)
7. [Hak Akses](#7-hak-akses)
8. [Alur Bisnis Utama](#8-alur-bisnis-utama)
9. [Aturan Bisnis](#9-aturan-bisnis)
10. [Backup & Keamanan](#10-backup--keamanan)
11. [Rencana Pengerjaan](#11-rencana-pengerjaan)
12. [Risiko & Mitigasi](#12-risiko--mitigasi)
13. [Pengembangan Lanjutan](#13-pengembangan-lanjutan)

---

## 1. Pendahuluan

Sistem Informasi Koperasi Simpan Pinjam (KSP) merupakan aplikasi berbasis web yang digunakan secara internal oleh pengurus dan petugas koperasi. Sistem ini menggantikan pencatatan simpanan dan pinjaman anggota yang sebelumnya dilakukan secara manual, sehingga proses operasional menjadi lebih cepat, akurat, dan terdokumentasi dengan baik.

Dokumen ini menjabarkan rancangan tujuh fungsi inti operasional harian koperasi, mulai dari pengelolaan data anggota hingga pelaporan keuangan. Rancangan disusun dengan fondasi teknis yang kuat agar sistem dapat tumbuh seiring kebutuhan koperasi di masa mendatang.

### Permasalahan yang Diselesaikan

1. Pencatatan simpanan dan pinjaman secara manual di Excel rawan kesalahan.
2. Saldo per anggota sulit diketahui secara langsung dan terkini.
3. Perhitungan angsuran dan denda secara manual memakan waktu.
4. Belum ada jejak audit yang mencatat siapa yang menginput atau mengubah data.
5. Laporan harus disusun secara manual setiap akhir bulan.

### Manfaat yang Diharapkan

- Data anggota, simpanan, dan pinjaman tersimpan terpusat dan terkini.
- Perhitungan angsuran, bunga, dan denda dilakukan otomatis dan konsisten.
- Setiap transaksi tercatat lengkap dengan jejak audit.
- Laporan operasional dan keuangan dapat dihasilkan kapan saja.

---

## 2. Ruang Lingkup

### Fungsi Utama

1. **Master Data Anggota** — pengelolaan data lengkap anggota koperasi.
2. **Master Data Instansi** — pengelolaan instansi tempat anggota bekerja.
3. **Besaran Simpanan** — konfigurasi nominal simpanan pokok, wajib, dan sukarela.
4. **Setoran Simpanan** — pencatatan setoran harian maupun bulanan.
5. **Pinjaman** — pengajuan, persetujuan, dan pencairan pinjaman.
6. **Angsuran** — pencatatan pembayaran cicilan pinjaman.
7. **Laporan** — laporan operasional dan ringkasan keuangan.

### Batasan Sistem

Fokus sistem ini adalah operasional harian koperasi. Beberapa modul lanjutan tidak termasuk dalam pengembangan tahap ini dan dapat diintegrasikan pada pengembangan berikutnya, antara lain: akuntansi double-entry, simpanan berjangka/deposito, perhitungan SHU otomatis, tutup buku bulanan/tahunan, manajemen kas multi-rekening, restrukturisasi pinjaman, portal mandiri anggota, serta notifikasi otomatis (SMS/WhatsApp). Rincian pengembangan lanjutan dijelaskan pada [Bab 13](#13-pengembangan-lanjutan).

---

## 3. Prinsip Desain

Karena sistem ini mengelola **dana milik anggota**, prinsip-prinsip berikut diterapkan secara wajib untuk menjaga integritas dan keamanan data keuangan:

1. **DECIMAL untuk Nilai Uang** — seluruh kolom uang menggunakan `DECIMAL(18,2)`. Tipe `FLOAT/DOUBLE` tidak diperbolehkan.
2. **Database Transaction** — setiap operasi yang menulis ke dua tabel atau lebih dibungkus dalam `DB::transaction()`.
3. **Audit Log** — setiap aksi CREATE/UPDATE/DELETE tercatat lengkap: oleh siapa, kapan, dan perubahan nilai dari/ke berapa.
4. **Reversal, Bukan Hapus** — transaksi keuangan tidak boleh dihapus. Koreksi dilakukan dengan membuat transaksi lawan (reversal).
5. **Idempotency Key** — setiap form transaksi menggunakan UUID untuk mencegah pengiriman ganda.
6. **Saldo Dihitung dari Transaksi** — saldo bukan nilai yang diubah secara manual, melainkan hasil agregat dari transaksi (dapat di-cache untuk performa).
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
| **Manajemen Hak Akses** | Spatie Permission + Filament Shield |
| **Audit Log** | `owen-it/laravel-auditing` |
| **PDF** | DomPDF / Browsershot |
| **Ekspor Excel** | `pxlrbt/filament-excel` |
| **Backup** | Spatie Laravel Backup |
| **Web Server** | Nginx |

### Alasan Pemilihan Filament

- Antarmuka admin siap pakai sehingga pengembangan dapat berfokus pada logika bisnis, bukan membangun tampilan dari awal.
- Form, tabel, dashboard, dan manajemen hak akses telah tersedia secara bawaan.
- Sangat sesuai untuk sistem internal yang berorientasi pada pengelolaan data.
- Mempercepat waktu pengembangan secara signifikan dibanding membangun antarmuka secara murni.

### Pemetaan Modul

| Modul | Pola Implementasi Filament |
|---|---|
| Master Anggota | Resource (CRUD) |
| Master Instansi | Resource (CRUD) |
| Besaran Simpanan | Resource (CRUD) |
| Setoran Simpanan | Resource + Custom Action (reversal) |
| Pinjaman | Resource + Wizard pengajuan |
| Angsuran | Resource + Custom Page pembayaran cepat |
| Laporan | Custom Page dengan filter + ekspor Excel/PDF |
| Dashboard | Filament Widgets |

### Struktur Direktori

```
app/
├── Models/                  # Anggota, Instansi, Pinjaman, dst.
├── Filament/
│   ├── Resources/           # CRUD per modul
│   ├── Pages/               # Halaman laporan & pembayaran angsuran
│   └── Widgets/             # Dashboard
├── Services/                # Logika bisnis (kalkulator bunga, dll)
├── Enums/                   # Status, Jenis Simpanan, dll
└── Policies/

database/
├── migrations/
└── seeders/                 # Role, jenis simpanan default
```

> **Prinsip arsitektur**: Seluruh logika bisnis (perhitungan bunga, pembuatan jadwal angsuran, perhitungan denda) ditempatkan di `app/Services`, bukan di dalam Filament Resource. Hal ini menjaga kode tetap rapi, mudah diuji, dan dapat digunakan ulang.

---

## 5. Modul Sistem

### 5.1 Master Data Anggota

> **Tujuan**: Mengelola data lengkap seluruh anggota koperasi.

#### Field Anggota

| Field | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `no_anggota` | VARCHAR(20) | ✅ | Dihasilkan otomatis: `KSP-YYYY-NNNN` |
| `nik` | VARCHAR(16) | ✅ | Unik |
| `nama_lengkap` | VARCHAR(100) | ✅ | Sesuai KTP |
| `tempat_lahir` | VARCHAR(50) | ✅ | |
| `tanggal_lahir` | DATE | ✅ | |
| `jenis_kelamin` | ENUM | ✅ | L / P |
| `instansi_id` | FK | ✅ | Referensi ke instansi |
| `jabatan` | VARCHAR(100) | – | |
| `tanggal_masuk` | DATE | ✅ | Tanggal menjadi anggota |
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

- CRUD lengkap dengan validasi NIK unik.
- Penomoran anggota otomatis.
- Filter dan pencarian berdasarkan nama, NIK, instansi, dan status.
- Cetak kartu anggota dalam format PDF.
- Tab riwayat simpanan dan pinjaman per anggota.
- Impor data dari Excel (unggah massal dengan validasi).
- **Soft delete** — data anggota tidak dihapus permanen, hanya berubah status.

---

### 5.2 Master Data Instansi

> **Tujuan**: Mengelola data instansi tempat anggota bekerja, untuk mendukung setoran wajib kolektif melalui mekanisme potong gaji.

| Field | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `kode_instansi` | VARCHAR(10) | ✅ | Unik |
| `nama_instansi` | VARCHAR(150) | ✅ | |
| `alamat` | TEXT | – | |
| `kontak_person` | VARCHAR(100) | – | PIC instansi |
| `no_hp_pic` | VARCHAR(15) | – | |
| `status` | ENUM | ✅ | Aktif / Non-Aktif |

#### Fitur

- CRUD instansi.
- Daftar anggota per instansi.
- Statistik per instansi: jumlah anggota, total simpanan, dan total pinjaman.

---

### 5.3 Besaran Simpanan

> **Tujuan**: Mengatur konfigurasi nominal dan jenis simpanan yang berlaku.

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
| `berlaku_sampai` | DATE | NULL = berlaku selamanya |
| `keterangan` | TEXT | |

#### Fitur

- CRUD nominal simpanan dengan penyimpanan riwayat perubahan.
- Saat besaran berubah, ketentuan baru berlaku untuk transaksi setelah `berlaku_mulai`.
- Validasi saat input setoran wajib agar sesuai dengan nominal yang berlaku.

---

### 5.4 Setoran Simpanan

> **Tujuan**: Mencatat setoran simpanan dari anggota.

| Field | Tipe | Keterangan |
|---|---|---|
| `no_transaksi` | VARCHAR(25) | Dihasilkan otomatis: `STR-YYYYMMDD-NNNN` |
| `idempotency_key` | UUID | Mencegah pengiriman ganda |
| `anggota_id` | FK | |
| `jenis_simpanan` | ENUM | Pokok / Wajib / Sukarela |
| `nominal` | DECIMAL(15,2) | |
| `tanggal_setor` | DATE | |
| `bulan_periode` | DATE | Untuk simpanan wajib (YYYY-MM-01) |
| `metode_bayar` | ENUM | Tunai / Transfer / Potong Gaji |
| `no_referensi` | VARCHAR(50) | Nomor bukti transfer (opsional) |
| `keterangan` | TEXT | |
| `is_reversal` | BOOLEAN | Default false |
| `reversal_of_id` | FK | Penunjuk ke transaksi yang dikoreksi |
| `user_input` | FK | Petugas pencatat |

#### Fitur

- **Input tunggal**: form per transaksi.
- **Input kolektif per instansi**: pilih instansi, centang anggota, isi nominal, lalu simpan sekaligus (untuk setoran potong gaji bulanan).
- Validasi: setoran wajib untuk bulan yang sama tidak boleh ganda.
- Validasi: nominal pokok dan wajib harus sesuai konfigurasi.
- Cetak slip setoran (PDF).
- **Tidak dapat diubah atau dihapus**: koreksi dilakukan melalui aksi reversal.
- Saldo simpanan diperbarui otomatis setelah setoran.

---

### 5.5 Pinjaman

> **Tujuan**: Mencatat pengajuan, persetujuan, dan pencairan pinjaman.

| Field | Tipe | Keterangan |
|---|---|---|
| `no_pinjaman` | VARCHAR(25) | `PJ-YYYY-NNNN` |
| `anggota_id` | FK | |
| `jumlah_pinjaman` | DECIMAL(15,2) | Plafon |
| `biaya_admin` | DECIMAL(15,2) | Default 0, dipotong dari pencairan |
| `jumlah_diterima` | DECIMAL(15,2) | = pinjaman − biaya admin |
| `jenis_bunga` | ENUM | Flat / Efektif / Anuitas |
| `suku_bunga_per_bulan` | DECIMAL(5,4) | Persen per bulan |
| `tenor` | INT | Bulan (1–60) |
| `denda_per_hari` | DECIMAL(15,2) | Nominal denda per hari keterlambatan |
| `tanggal_pengajuan` | DATE | |
| `tanggal_disetujui` | DATE | |
| `tanggal_cair` | DATE | |
| `tanggal_jatuh_tempo_pertama` | DATE | |
| `status` | ENUM | Pengajuan / Disetujui / Cair / Lunas / Ditolak |
| `jaminan` | TEXT | Deskripsi jaminan |
| `tujuan_pinjaman` | TEXT | |
| `disetujui_oleh` | FK | Pengurus penyetuju |
| `keterangan` | TEXT | |

#### Metode Perhitungan Bunga

| Metode | Cara Hitung |
|---|---|
| **Flat** | Bunga = Pokok × Suku × Tenor; Angsuran/bulan = (Pokok + Total Bunga) / Tenor |
| **Efektif** | Bunga bulan berjalan = Sisa Pokok × Suku; pokok angsuran tetap, bunga menurun |
| **Anuitas** | Angsuran = P × [i(1+i)ⁿ] / [(1+i)ⁿ − 1] (total angsuran tetap) |

#### Fitur

- **Wizard pengajuan**: Anggota → Plafon & Bunga → Jaminan → Pratinjau Jadwal → Konfirmasi.
- Kalkulator angsuran langsung pada form.
- Alur kerja: **Pengajuan → Disetujui → Cair**.
- Jadwal angsuran dibuat otomatis saat status menjadi Cair.
- Validasi: maksimal pinjaman = N × saldo simpanan (dapat dikonfigurasi).
- Validasi: maksimal satu pinjaman aktif per anggota (dapat dikonfigurasi).
- Cetak surat perjanjian pinjaman (PDF).
- Cetak jadwal angsuran (PDF).

---

### 5.6 Angsuran

> **Tujuan**: Mencatat pembayaran cicilan pinjaman.

| Field | Tipe | Keterangan |
|---|---|---|
| `no_angsuran` | VARCHAR(25) | `ANG-YYYYMMDD-NNNN` |
| `idempotency_key` | UUID | |
| `pinjaman_id` | FK | |
| `jadwal_angsuran_id` | FK | Tautan ke baris jadwal |
| `ke_angsuran` | INT | Cicilan ke-N |
| `tanggal_bayar` | DATE | |
| `jatuh_tempo` | DATE | |
| `hari_terlambat` | INT | Dihitung otomatis |
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

- **Halaman pembayaran cepat**: petugas memasukkan nomor anggota, sistem menampilkan daftar pinjaman aktif dan angsuran jatuh tempo, lalu petugas memasukkan nominal dan menyimpan.
- Perhitungan denda otomatis (hari terlambat × tarif denda).
- Pembayaran sebagian: alokasi otomatis dengan urutan denda → bunga → pokok.
- Pelunasan dipercepat: perhitungan sisa pokok ditambah bunga berjalan.
- Cetak kuitansi (PDF).
- Status pinjaman berubah menjadi **Lunas** secara otomatis saat angsuran terakhir terbayar.
- Reversal jika terjadi kesalahan input.

---

### 5.7 Laporan

Sistem menyediakan rangkaian laporan standar yang umum dibutuhkan koperasi simpan pinjam, dikelompokkan berdasarkan kebutuhan pengguna. Daftar laporan ini dapat disesuaikan lebih lanjut mengikuti kebutuhan operasional koperasi.

#### A. Laporan Operasional Harian/Bulanan

| Laporan | Isi | Format | Pengguna |
|---|---|---|---|
| **Rekap Setoran Harian** | Daftar setoran per hari, total per jenis simpanan, total tunai/transfer | PDF/Excel | Petugas, Pengurus |
| **Rekap Angsuran Harian** | Daftar angsuran masuk per hari, total pokok/bunga/denda | PDF/Excel | Petugas, Pengurus |
| **Rekap Setoran per Periode** | Total setoran dalam rentang tanggal, dikelompokkan per jenis & instansi | Excel | Pengurus |
| **Rekap Pencairan Pinjaman** | Daftar pinjaman cair dalam periode, total dana keluar | Excel | Pengurus |

#### B. Laporan Anggota

| Laporan | Isi | Format | Pengguna |
|---|---|---|---|
| **Daftar Anggota Aktif** | Seluruh anggota berstatus aktif, dikelompokkan per instansi | PDF/Excel | Pengurus |
| **Buku Tabungan Anggota** | Riwayat setoran dan saldo berjalan per anggota | PDF | Petugas, Anggota |
| **Daftar Saldo Simpanan** | Saldo per anggota (Pokok/Wajib/Sukarela), total per jenis | Excel | Pengurus |
| **Kartu Anggota** | Cetak kartu anggota dengan foto dan nomor anggota | PDF | Petugas |

#### C. Laporan Pinjaman

| Laporan | Isi | Format | Pengguna |
|---|---|---|---|
| **Daftar Pinjaman Aktif** | Seluruh pinjaman berjalan, outstanding pokok, sisa tenor | Excel | Pengurus |
| **Jadwal Angsuran per Pinjaman** | Jadwal lengkap pinjaman tertentu | PDF | Petugas, Anggota |
| **Daftar Tunggakan Pinjaman** | Pinjaman dengan angsuran melewati jatuh tempo dan nominal denda | Excel | Pengurus, Penagih |
| **Riwayat Angsuran per Pinjaman** | Seluruh pembayaran cicilan untuk satu pinjaman | PDF | Petugas |
| **Pinjaman Akan Jatuh Tempo** | Angsuran jatuh tempo dalam 7/14/30 hari ke depan | Excel | Petugas |

#### D. Laporan Tunggakan & Kepatuhan

| Laporan | Isi | Format | Pengguna |
|---|---|---|---|
| **Tunggakan Simpanan Wajib** | Anggota yang belum menyetor wajib pada bulan tertentu | Excel | Pengurus |
| **Tunggakan Pinjaman per Instansi** | Tunggakan dikelompokkan per instansi (untuk koordinasi potong gaji) | Excel | Pengurus |

#### E. Laporan Ringkasan Keuangan

| Laporan | Isi | Format | Pengguna |
|---|---|---|---|
| **Ringkasan Bulanan** | Total simpanan masuk, pinjaman cair, angsuran masuk, denda terkumpul | PDF | Pengurus |
| **Ringkasan Saldo Koperasi** | Total dana simpanan, total piutang pinjaman, posisi dana koperasi | PDF | Pengurus |

#### F. Dashboard (Real-time)

Halaman utama menampilkan ringkasan berikut secara langsung:

- Total anggota aktif
- Total saldo simpanan (per jenis)
- Total outstanding pinjaman
- Jumlah tunggakan (pinjaman dan simpanan wajib)
- Grafik tren setoran 6 bulan terakhir
- Grafik tren pencairan pinjaman 6 bulan terakhir
- Pinjaman jatuh tempo minggu ini

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
- `setoran_simpanan(anggota_id, jenis_simpanan, bulan_periode)` untuk validasi setoran ganda
- `setoran_simpanan(idempotency_key)` UNIQUE
- `pinjaman(anggota_id, status)` untuk pengecekan pinjaman aktif
- Seluruh foreign key harus diindeks

### Konvensi Tipe Data

| Data | Tipe | Catatan |
|---|---|---|
| Uang | `DECIMAL(18,2)` | **Wajib**, bukan FLOAT |
| Persentase | `DECIMAL(5,4)` | Contoh: 0,0125 = 1,25% |
| Tanggal | `DATE` | |
| ID | `BIGINT UNSIGNED` | Auto increment |
| UUID | `CHAR(36)` | |

---

## 7. Hak Akses

### Role

| Role | Deskripsi |
|---|---|
| **Super Admin** | Tim IT, akses penuh termasuk manajemen pengguna |
| **Pengurus** | Ketua/Bendahara, persetujuan pinjaman, akses seluruh laporan |
| **Petugas** | Input transaksi harian (setoran, angsuran) |

### Matriks Akses

| Modul | Super Admin | Pengurus | Petugas |
|---|:-:|:-:|:-:|
| Master Anggota | F | F | RA |
| Master Instansi | F | F | R |
| Besaran Simpanan | F | F | R |
| Setoran Simpanan | F | F | F |
| Pinjaman (input) | F | A | I |
| Pinjaman (persetujuan) | F | A | – |
| Angsuran | F | F | F |
| Laporan | F | F | TR |
| Manajemen Pengguna | F | – | – |
| Audit Log | F | R | – |

> **Legenda**: F = Akses Penuh, R = Lihat, RA = Lihat + Tambah, I = Input saja, A = Persetujuan, TR = Terbatas, – = Tidak ada akses

---

## 8. Alur Bisnis Utama

### 8.1 Pendaftaran Anggota Baru

1. Petugas memasukkan data anggota secara lengkap.
2. Sistem menghasilkan nomor anggota secara otomatis.
3. Petugas memasukkan setoran simpanan pokok pertama.
4. Kartu anggota dicetak.
5. Anggota menjadi aktif dan dapat bertransaksi.

### 8.2 Setoran Simpanan Wajib Bulanan

**Input tunggal:**

1. Petugas memilih anggota, memilih jenis Wajib, lalu memasukkan nominal dan periode.
2. Sistem memvalidasi agar tidak ada duplikasi periode.
3. Slip setoran dicetak.

**Input kolektif per instansi (potong gaji):**

1. Petugas memilih instansi, sistem menampilkan daftar anggota aktif.
2. Petugas mencentang anggota; nominal default telah terisi.
3. Submit, seluruh setoran tercatat sekaligus.
4. Rekap setoran instansi dicetak.

### 8.3 Pengajuan & Pencairan Pinjaman

1. Anggota mengajukan, petugas menginput pengajuan melalui wizard.
2. Sistem menampilkan analisa kelayakan (saldo simpanan, riwayat).
3. Pengurus meninjau dan memutuskan **Disetujui** atau **Ditolak**.
4. Petugas memasukkan tanggal cair, status menjadi **Cair**.
5. Sistem menghasilkan jadwal angsuran secara otomatis.
6. Surat perjanjian dan jadwal angsuran dicetak.

### 8.4 Pembayaran Angsuran

1. Petugas membuka halaman pembayaran cepat.
2. Memasukkan nomor anggota/pinjaman, sistem menampilkan pinjaman aktif dan angsuran jatuh tempo.
3. Petugas memasukkan tanggal bayar dan nominal.
4. Sistem menghitung otomatis (denda → bunga → pokok).
5. Kuitansi dicetak.
6. Jika ini angsuran terakhir, status pinjaman menjadi Lunas.

### 8.5 Koreksi Transaksi (Reversal)

1. Petugas membuka transaksi yang salah lalu menekan **Reversal**.
2. Petugas memasukkan alasan koreksi.
3. Sistem membuat transaksi lawan dengan penanda `is_reversal = true`.
4. Saldo terkoreksi secara otomatis.
5. Audit log mencatat seluruh perubahan.

---

## 9. Aturan Bisnis

### Anggota

- NIK harus 16 digit dan unik.
- Anggota berstatus "Keluar" tidak dapat bertransaksi.
- Anggota tidak dapat dihapus permanen jika memiliki transaksi.

### Simpanan

- Setoran wajib: satu anggota satu record per bulan-periode.
- Tidak dapat menyetor mundur lebih dari N bulan (dapat dikonfigurasi).
- Penarikan sukarela hanya dapat dilakukan jika saldo mencukupi.

### Pinjaman

- Maksimal pinjaman dapat dikonfigurasi (default 3× saldo simpanan).
- Maksimal satu pinjaman aktif per anggota (dapat dikonfigurasi).
- Tenor antara 1–60 bulan.
- Pinjaman tidak dapat dicairkan jika status anggota bukan Aktif.

### Angsuran

- Angsuran tidak dapat diinput sebelum pinjaman dicairkan.
- Pembayaran sebagian dialokasikan otomatis dengan urutan denda → bunga → pokok.
- Pembayaran melebihi sisa pokok ditolak (gunakan menu Pelunasan Dipercepat).

### Transaksi Umum

- Tidak ada penghapusan permanen untuk transaksi keuangan.
- Koreksi wajib dilakukan melalui reversal.
- Setiap transaksi tercatat dalam audit log.

---

## 10. Backup & Keamanan

### Backup

- **Otomatis harian** ke dua lokasi:
  1. Penyimpanan internal server.
  2. Cloud atau NAS terpisah (terenkripsi).
- Retensi: 30 hari untuk backup harian, 12 bulan untuk backup bulanan.
- Uji pemulihan (test restore) minimal satu kali per kuartal.

### Keamanan

- **HTTPS** wajib (sertifikat self-signed dapat diterima untuk jaringan lokal).
- **Kebijakan kata sandi**: minimal 8 karakter.
- **Batas sesi**: keluar otomatis setelah 30 menit tidak aktif.
- **Pembatasan login**: 5 kali gagal mengakibatkan penguncian 15 menit.
- **Whitelist IP** opsional untuk membatasi akses ke jaringan internal.
- **Audit log**: merekam seluruh aktivitas (login, CRUD, ekspor).

### Pemulihan Bencana (Disaster Recovery)

- **RTO** (Recovery Time Objective): kurang dari 4 jam.
- **RPO** (Recovery Point Objective): kurang dari 24 jam.
- Tersedia dokumentasi runbook pemulihan langkah demi langkah.

---

## 11. Rencana Pengerjaan

### Total Durasi: 1 Bulan (4 Minggu)

Pengerjaan dijadwalkan selesai dalam satu bulan dengan pembagian per minggu sebagai berikut. Untuk memenuhi target ini, beberapa aktivitas dikerjakan secara paralel dan pengujian dilakukan berkelanjutan di setiap minggu.

| Minggu | Fokus | Cakupan |
|---|---|---|
| **Minggu 1** | Fondasi & Master Data | Setup Laravel + Filament + Shield + paket Spatie, struktur project, seeder role, lokalisasi Bahasa Indonesia, audit log, Master Anggota (Resource, kartu PDF, impor Excel), Master Instansi, Besaran Simpanan, dashboard awal |
| **Minggu 2** | Simpanan & Pinjaman | Setoran Resource, input kolektif per instansi, slip PDF, reversal; Pinjaman Resource + Wizard, kalkulator bunga (Flat/Efektif/Anuitas), alur persetujuan, pembuatan jadwal angsuran otomatis, surat perjanjian PDF |
| **Minggu 3** | Angsuran & Laporan | Angsuran Resource, halaman pembayaran cepat, kalkulasi denda, pelunasan dipercepat, kuitansi PDF; seluruh laporan operasional, ekspor PDF/Excel, dashboard widgets |
| **Minggu 4** | Pengujian & Serah Terima | UAT, perbaikan bug, hardening keamanan, pelatihan pengguna, dokumentasi manual pengguna, deployment ke server produksi |

### Catatan Penjadwalan

- Penulisan unit test untuk perhitungan bunga dan denda dilakukan bersamaan dengan pengembangan modul terkait, bukan ditunda ke akhir.
- Kelengkapan data awal (master anggota dan instansi) dari pihak koperasi diperlukan paling lambat awal Minggu 2 agar pengujian berbasis data nyata dapat berjalan.
- Lingkungan server (hosting, domain, basis data) disiapkan paralel pada Minggu 1.

---

## 12. Risiko & Mitigasi

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Kerusakan data keuangan | Sangat Tinggi | Backup harian + DECIMAL + DB transaction + log immutable |
| Kesalahan kalkulasi bunga | Tinggi | Unit test menyeluruh untuk kalkulator bunga + UAT dengan kasus nyata |
| Kesalahan input transaksi | Sedang | Mekanisme reversal + audit log |
| Penghapusan data keliru | Sedang | Soft delete + RBAC + tanpa hard delete di antarmuka |
| Server bermasalah | Tinggi | Backup harian + dokumentasi pemulihan bencana |
| Lupa kata sandi admin | Rendah | Prosedur reset terkontrol |
| Performa lambat | Rendah | Index basis data + paginasi + cache widget dashboard |
| Keterlambatan data awal dari koperasi | Sedang | Komunikasi kebutuhan data di awal + fitur impor Excel untuk percepatan |

---

## 13. Pengembangan Lanjutan

Sistem ini dirancang dengan fondasi yang memungkinkan penambahan modul di kemudian hari tanpa perlu membangun ulang. Modul-modul berikut dapat dikembangkan pada tahap selanjutnya sesuai kebutuhan dan anggaran koperasi:

- **Akuntansi double-entry** — jurnal, buku besar, dan neraca formal.
- **Simpanan Berjangka / Deposito** — beserta perhitungan bagi hasilnya.
- **Perhitungan SHU otomatis** — distribusi Sisa Hasil Usaha berbasis kontribusi anggota.
- **Tutup buku** — proses penutupan periode bulanan dan tahunan.
- **Manajemen kas multi-rekening** — pencatatan arus kas antar rekening koperasi.
- **Restrukturisasi & top-up pinjaman** — penyesuaian dan penambahan plafon pinjaman berjalan.
- **Portal mandiri anggota** — akses anggota untuk memeriksa saldo dan riwayat secara mandiri.
- **Notifikasi otomatis** — pengingat jatuh tempo via SMS/WhatsApp.

---

## Lampiran: Glosarium

| Istilah | Definisi |
|---|---|
| **KSP** | Koperasi Simpan Pinjam |
| **Simpanan Pokok** | Simpanan satu kali saat menjadi anggota |
| **Simpanan Wajib** | Simpanan rutin bulanan |
| **Simpanan Sukarela** | Simpanan bebas menyerupai tabungan |
| **Outstanding** | Sisa pokok pinjaman yang belum dibayar |
| **Tenor** | Jangka waktu pinjaman dalam bulan |
| **Reversal** | Koreksi transaksi melalui transaksi lawan |
| **Idempotency** | Operasi yang menghasilkan hasil sama meski dijalankan berkali-kali |
| **RBAC** | Role-Based Access Control (kontrol akses berbasis peran) |
| **SHU** | Sisa Hasil Usaha |
| **UAT** | User Acceptance Test (uji penerimaan pengguna) |

---

*Sistem Informasi Koperasi Simpan Pinjam — Versi 4.0.0*
