# Dokumentasi Sistem — Sistem Informasi Koperasi Simpan Pinjam

### KPRI KOPEKOMA — Magelang

| | |
|---|---|
| **Dokumen** | Dokumentasi Sistem |
| **Versi** | 5.0 |
| **Tanggal** | Juni 2026 |
| **Koperasi** | KPRI KOPEKOMA, Magelang |
| **Platform** | Aplikasi web internal (Laravel + Filament + MySQL) |
| **Pengguna** | Pengurus & petugas koperasi |
| **Bahasa Antarmuka** | Bahasa Indonesia |

> **Catatan**: Dokumen ini berfokus pada **penjelasan sistem dan alur bisnis**. Spesifikasi teknis basis data (struktur tabel, kolom, relasi, index) **dibahas pada dokumen terpisah**.

---

## Daftar Isi

1. [Pendahuluan](#1-pendahuluan)
2. [Ruang Lingkup](#2-ruang-lingkup)
3. [Peran Pengguna](#3-peran-pengguna)
4. [Modul & Fungsi Sistem](#4-modul--fungsi-sistem)
   - [4.1 Data Anggota](#41-data-anggota)
   - [4.2 Data OPD/Instansi](#42-data-opdinstansi)
   - [4.3 Simpanan](#43-simpanan)
   - [4.4 Setoran Simpanan](#44-setoran-simpanan)
   - [4.5 Pinjaman](#45-pinjaman)
   - [4.6 Angsuran](#46-angsuran)
   - [4.7 Dokumen & Formulir](#47-dokumen--formulir)
   - [4.8 Laporan & Dashboard](#48-laporan--dashboard)
5. [Alur Bisnis Utama](#5-alur-bisnis-utama)
6. [Aturan Bisnis](#6-aturan-bisnis)
7. [Prinsip Keamanan Data](#7-prinsip-keamanan-data)
8. [Hal yang Masih Menunggu Konfirmasi](#8-hal-yang-masih-menunggu-konfirmasi)
9. [Pengembangan Lanjutan](#9-pengembangan-lanjutan)
10. [Glosarium](#10-glosarium)

---

## 1. Pendahuluan

Sistem Informasi Koperasi Simpan Pinjam KPRI KOPEKOMA adalah aplikasi web yang digunakan secara internal oleh pengurus dan petugas koperasi untuk mengelola kegiatan simpan pinjam anggota. Sistem ini menggantikan pencatatan manual yang sebelumnya dilakukan di buku/Excel, sehingga proses menjadi lebih cepat, akurat, dan terdokumentasi.

Anggota koperasi adalah ASN/PNS dan tenaga honorer di lingkungan OPD (Organisasi Perangkat Daerah). Karena itu, **potong gaji** menjadi salah satu cara utama penyetoran simpanan maupun pembayaran angsuran, di samping setor manual.

Dokumen ini menjelaskan **apa saja yang dapat dilakukan sistem**, **bagaimana tiap fungsi bekerja**, serta **aturan bisnis** yang berlaku — sebagai panduan bagi pengurus, petugas, dan pihak pengembang dalam memahami sistem secara menyeluruh.

### Tujuan Sistem

- Menyimpan data anggota, simpanan, dan pinjaman secara terpusat dan selalu terkini.
- Menghitung angsuran dan potongan pinjaman secara otomatis dan konsisten.
- Mencatat setiap transaksi lengkap dengan jejak siapa yang menginput/mengubah.
- Menyimpan dokumen asli (formulir permohonan, tanda terima) dalam bentuk digital.

---

## 2. Ruang Lingkup

### Fungsi yang Dikelola Sistem

1. **Data Anggota** — pengelolaan data lengkap anggota.
2. **Data OPD/Instansi** — pengelolaan instansi tempat anggota bekerja.
3. **Simpanan** — pengelolaan enam jenis simpanan koperasi.
4. **Setoran Simpanan** — pencatatan setoran (potong gaji & setor sendiri).
5. **Pinjaman** — pencatatan pinjaman jangka pendek & jangka panjang beserta potongannya.
6. **Angsuran** — pencatatan pembayaran cicilan pinjaman.
7. **Dokumen & Formulir** — penyimpanan dan pencetakan formulir resmi.
8. **Laporan & Dashboard** — pemantauan dan pelaporan.

### Batasan Sistem

- **Persetujuan pinjaman dilakukan di luar sistem** oleh admin/pengurus. Sistem hanya mencatat pinjaman yang **sudah disetujui (ACC)**.
- **Pengecekan kemampuan potong gaji** (apakah gaji anggota masih cukup untuk dipotong) dilakukan secara manual oleh admin dan bendahara, di luar sistem.
- **Perhitungan SHU (Sisa Hasil Usaha)** belum termasuk pada tahap ini; metode perhitungannya masih menunggu ketentuan koperasi.
- **Integrasi dengan aplikasi toko** untuk Wajib Belanja direncanakan menyusul (lihat [Bab 9](#9-pengembangan-lanjutan)).

---

## 3. Peran Pengguna

Sistem membedakan tiga peran pengguna dengan kewenangan berbeda:

| Peran | Siapa | Kewenangan Utama |
|---|---|---|
| **Super Admin** | Tim IT | Akses penuh ke seluruh fungsi, termasuk manajemen pengguna dan konfigurasi sistem. |
| **Pengurus** | Ketua / Bendahara | Mengelola data dan transaksi, serta mengakses seluruh laporan. |
| **Petugas** | Staf operasional | Menginput transaksi harian (setoran, angsuran, pencatatan pinjaman yang sudah ACC). |

Pembagian peran ini memastikan setiap orang hanya dapat mengakses fungsi yang sesuai dengan tanggung jawabnya, dan setiap tindakan tercatat atas nama pengguna yang melakukannya.

---

## 4. Modul & Fungsi Sistem

### 4.1 Data Anggota

Modul ini mengelola seluruh data anggota koperasi, baik ASN maupun honorer. Untuk setiap anggota, sistem menyimpan:

- **Identitas pribadi** — nomor anggota (dihasilkan otomatis), nama sesuai KTP, tempat & tanggal lahir, jenis kelamin, NIK, dan NIP (khusus ASN).
- **Data kepegawaian** — OPD tempat bekerja, jabatan, **golongan**, status kepegawaian (ASN/honorer), serta tanggal masuk dan tanggal keluar keanggotaan.
- **Data keuangan & kontak** — nomor rekening gaji (untuk potong gaji dan transfer), nama bank, alamat, dan nomor HP.
- **Data ahli waris** — nama, hubungan, dan nomor HP, untuk keperluan klaim apabila diperlukan.

**Hal penting**: golongan anggota **otomatis menentukan nominal simpanan wajib** (lihat 4.3). Bila golongan berubah, nominal wajib akan menyesuaikan.

**Yang dapat dilakukan pada modul ini:**

- Menambah, mengubah, dan menonaktifkan data anggota (data tidak dihapus permanen — hanya berubah status).
- Penomoran anggota otomatis.
- Pencarian dan penyaringan berdasarkan nama, NIK/NIP, OPD, golongan, atau status.
- Mengunggah dan menyimpan **dokumen asli** anggota (formulir permohonan anggota baru).
- Mencetak kartu anggota.
- Melihat riwayat simpanan, pinjaman, dan angsuran per anggota dalam satu tampilan.
- Mengimpor data anggota secara massal dari Excel.

---

### 4.2 Data OPD/Instansi

Modul ini mengelola daftar OPD/instansi tempat anggota bekerja. Datanya digunakan untuk **koordinasi potong gaji** — karena setoran dan angsuran banyak anggota dikumpulkan per instansi.

Untuk setiap OPD, sistem menyimpan kode dan nama instansi, alamat, serta kontak bendahara gaji (PIC) yang menangani potong gaji.

**Yang dapat dilakukan:**

- Menambah, mengubah, dan menonaktifkan data OPD.
- Melihat daftar anggota per OPD.
- Melihat ringkasan per OPD: jumlah anggota, total simpanan, total pinjaman, dan total potongan gaji bulanan.

---

### 4.3 Simpanan

Koperasi memiliki **enam jenis simpanan**. Lima merupakan simpanan keanggotaan, dan dua lainnya terkait dengan pinjaman.

#### A. Simpanan Keanggotaan

**1. Simpanan Pokok**
Dibayar **satu kali** saat anggota bergabung, dengan nominal tetap **Rp 50.000**. Hanya dapat ditarik saat anggota keluar dari koperasi.

**2. Simpanan Wajib**
Dibayar **setiap bulan**, dengan nominal mengikuti **golongan** anggota:

| Golongan | Nominal/Bulan |
|---|---|
| HR / THL (honorer) | Rp 30.000 |
| Golongan I | Rp 50.000 |
| Golongan II | Rp 75.000 |
| Golongan III | Rp 100.000 |
| Golongan IV | Rp 150.000 |

Nominal ini mengikuti golongan secara otomatis. Hanya dapat ditarik saat anggota keluar.

**3. Simpanan Hari Raya**
Nominalnya **fleksibel** dan **opsional** — anggota boleh ikut atau tidak. Besarannya ditawarkan dan disepakati saat **RAT (Rapat Anggota Tahunan, sekitar Februari–Maret)**, dapat berubah setiap tahun. Simpanan ini **ditagih setiap bulan selama satu tahun**, lalu **dibagikan kembali kepada anggota menjelang hari raya tahun berikutnya** (sekitar satu bulan sebelumnya).

> Inilah satu-satunya simpanan yang **dicairkan secara rutin setiap tahun**.

**4. Simpanan Sukarela**
Berfungsi seperti tabungan biasa. Anggota bebas menyetor kapan saja, dan dananya dapat ditarik selama saldo mencukupi.

**5. Wajib Belanja**
Setoran **Rp 100.000 per bulan**, bersifat **opsional** (pada formulir pendaftaran dapat dicoret bagi yang tidak berkenan). Karakteristik utamanya: **dana tidak dapat diuangkan**, hanya dapat **dibelanjakan di toko koperasi**.

Saldo Wajib Belanja memiliki dua sisi:
- **Setoran** menambah saldo belanja.
- **Pemakaian (belanja)** mengurangi saldo belanja.
- Sisa yang belum dipakai tetap tersimpan sebagai saldo.

Selama integrasi dengan aplikasi toko belum berjalan, pemakaian saldo dicatat melalui **menu manual**. Ke depan, transaksi belanja akan otomatis mengurangi saldo melalui **integrasi aplikasi toko** (lihat Bab 9).

#### B. Simpanan Terkait Pinjaman

Dua jenis simpanan berikut terbentuk dari proses pinjaman dan **dikembalikan kepada anggota saat pinjaman lunas**:

- **SWP (Simpanan Wajib Pinjam)** — dipotong **1%** dari nilai pinjaman, satu kali saat pencairan.
- **Tabungan Berjangka** — dikumpulkan setiap bulan melalui angsuran (lihat 4.5 dan 4.6).

#### Ketentuan Umum Simpanan

- **Dua cara menyetor**: **potong gaji** dan **setor sendiri** (manual). Setoran dapat dilakukan melalui bendahara maupun oleh anggota sendiri.
- **Tidak ada sanksi** bila anggota tidak menyetor pada bulan tertentu — bulan yang terlewat dicatat sebagai **"bolong"**, tanpa denda atau tunggakan.
- **Tidak ada potongan** dari koperasi saat anggota menyimpan.
- **Tidak ada bunga simpanan.**

---

### 4.4 Setoran Simpanan

Modul ini mencatat setiap setoran simpanan dari anggota. Untuk tiap setoran dicatat anggota, jenis simpanan, nominal, tanggal, periode bulan (untuk simpanan rutin), serta cara setor (potong gaji / setor sendiri) dan siapa yang menyetorkan.

**Dua mode input:**

- **Input tunggal** — mencatat satu setoran untuk satu anggota.
- **Input kolektif per OPD** — untuk potong gaji bulanan: petugas memilih OPD, sistem menampilkan daftar anggota aktif beserta nominal default sesuai golongan, lalu petugas mencentang dan menyimpan banyak setoran sekaligus.

**Karakteristik penting:**

- Setoran yang sudah tercatat **tidak dapat diubah atau dihapus**. Bila ada kesalahan, koreksi dilakukan melalui **pembatalan (reversal)** — yaitu membuat transaksi lawan, bukan menghapus data asli.
- Saldo simpanan anggota diperbarui otomatis setiap kali ada setoran.
- Sistem dapat mencetak **slip setoran** sebagai bukti.

---

### 4.5 Pinjaman

Modul ini mencatat pinjaman anggota yang **sudah disetujui** (persetujuan dilakukan di luar sistem). Terdapat dua jenis pinjaman:

| Jenis | Plafon | Potongan | Angsuran | Jangka Waktu |
|---|---|---|---|---|
| **Jangka Pendek (Sebrakan)** | < Rp 1.000.000 | Tidak ada | Tidak ada | 1 bulan |
| **Jangka Panjang** | > Rp 1.000.000 | Admin 1% + SWP 1% | Pokok + Jasa + Tabungan Berjangka | Sesuai pengajuan |

> **Semua pinjaman wajib disertai pengisian formulir.** Setelah pinjaman disetujui di luar sistem, anggota mengisi formulir / **Tanda Terima Pinjaman Uang**, kemudian data dimasukkan ke sistem.

#### Potongan Saat Pencairan (Pinjaman Jangka Panjang)

Pada pinjaman jangka panjang, dua potongan dikenakan **satu kali** saat pencairan:

- **Biaya Administrasi 1%** — menjadi **pendapatan koperasi** (tidak dikembalikan).
- **SWP 1%** — merupakan **simpanan milik anggota**, yang **dikembalikan saat pinjaman lunas**.

Sehingga dana yang benar-benar diterima anggota:

> **Pinjaman Diterima = Jumlah Pinjaman − Biaya Admin 1% − SWP 1%**

#### Pinjaman Jangka Pendek (Sebrakan)

Pinjaman kecil di bawah Rp 1.000.000 tanpa potongan apa pun dan tanpa angsuran. Dikembalikan penuh dalam satu bulan — misalnya meminjam pada bulan Juni dan mengembalikan pada bulan Juli.

#### Yang dapat dilakukan pada modul ini

- Mencatat pinjaman yang sudah ACC, dengan perhitungan potongan dan dana diterima secara otomatis.
- Menghasilkan jadwal angsuran otomatis untuk pinjaman jangka panjang.
- Menandai anggota bermasalah ke dalam **daftar blacklist**, sehingga tidak dapat mengajukan pinjaman baru.
- Mengunggah dan menyimpan dokumen pinjaman (formulir / tanda terima).
- Mencetak Tanda Terima Pinjaman Uang dan jadwal angsuran.

---

### 4.6 Angsuran

Modul ini mencatat pembayaran cicilan pinjaman jangka panjang. Setiap angsuran bulanan terdiri atas **tiga komponen**:

| Komponen | Cara Hitung | Keterangan |
|---|---|---|
| **Pokok** | Jumlah Pinjaman ÷ Jangka Waktu | Cicilan pokok pinjaman. |
| **Jasa** | Pokok × **0,65%** | Imbal jasa untuk koperasi. |
| **Tabungan Berjangka** | Pokok × **0,1%** | Simpanan milik anggota, dikembalikan saat lunas. |

> **Angsuran per Bulan = Pokok + Jasa + Tabungan Berjangka**

#### Contoh Perhitungan

> **Pinjaman Rp 12.000.000, jangka waktu 12 bulan** (pokok per bulan = Rp 1.000.000):
>
> - **Saat pencairan**: Admin 1% = Rp 120.000; SWP 1% = Rp 120.000 → **Diterima Rp 11.760.000**.
> - **Angsuran tiap bulan**: Pokok Rp 1.000.000 + Jasa Rp 6.500 + Tabungan Berjangka Rp 1.000 = **Rp 1.007.500**.
> - **Saat lunas**: SWP Rp 120.000 + total Tabungan Berjangka (12 × Rp 1.000 = Rp 12.000) **dikembalikan kepada anggota**.

#### Cara Pembayaran

- **Potong gaji** (cara utama/wajib) atau **manual** (untuk kondisi tertentu / anggota lama).
- Angsuran boleh **bolong** (terlewat) — sistem tetap mencatat tanpa memutus pinjaman secara otomatis.

#### Pengembalian Saat Lunas

Saat angsuran terakhir terbayar, status pinjaman otomatis menjadi **Lunas**, dan anggota menerima kembali:
- **SWP** yang dipotong di awal, dan
- **Tabungan Berjangka** yang terkumpul selama masa pinjaman.

#### Fitur Pendukung

- **Halaman pembayaran cepat**: petugas memasukkan nomor anggota, sistem menampilkan pinjaman aktif dan angsuran yang jatuh tempo, lalu mencatat pembayaran.
- Perhitungan komponen angsuran dilakukan otomatis.
- Mencetak kuitansi pembayaran.
- Koreksi kesalahan melalui pembatalan (reversal).

---

### 4.7 Dokumen & Formulir

Sistem menyimpan dokumen asli secara digital dan mencetak formulir resmi koperasi. Prinsipnya: **dokumen asli (hasil scan/foto) wajib tersimpan di sistem** dan tertaut ke anggota atau transaksi terkait, agar arsip fisik dapat ditelusuri secara digital.

| Formulir | Fungsi |
|---|---|
| **Form Permohonan Anggota Baru** | Pendaftaran anggota beserta kesanggupan membayar simpanan. |
| **Tanda Terima Pinjaman Uang** | Bukti pencairan pinjaman beserta rincian potongan (Admin 1%, SWP 1%). |
| **Formulir Pinjaman** | Wajib untuk semua pinjaman (jangka pendek & panjang). |
| **Slip Setoran / Kuitansi Angsuran** | Bukti transaksi setoran dan angsuran. |
| **Kartu Anggota** | Identitas anggota. |

---

### 4.8 Laporan & Dashboard

#### Dashboard

Halaman utama menampilkan ringkasan kondisi koperasi secara langsung, antara lain:

- Jumlah anggota aktif (per OPD dan golongan).
- **Daftar anggota baru** — untuk keperluan pemantauan menjelang **RAT**.
- Total saldo simpanan per jenis.
- Total pinjaman berjalan dan jumlah pinjaman aktif.
- Akumulasi SWP dan Tabungan Berjangka yang belum dikembalikan.
- Pinjaman yang jatuh tempo dalam waktu dekat.

#### Laporan

Rincian laporan bulanan **akan disusun lebih lanjut pada tahap berikutnya**. Sebagai dasar, sistem menyiapkan laporan operasional standar — rekap setoran, rekap angsuran, daftar saldo simpanan per anggota, daftar pinjaman aktif, serta rekap potongan gaji per OPD — yang seluruhnya dapat diekspor ke PDF/Excel.

---

## 5. Alur Bisnis Utama

### 5.1 Pendaftaran Anggota Baru

1. Calon anggota mengisi **Form Permohonan Anggota Baru** (Simpanan Pokok, Wajib sesuai golongan, Hari Raya, dan opsi Wajib Belanja).
2. Petugas menginput data anggota; golongan menentukan nominal wajib otomatis.
3. Sistem menghasilkan nomor anggota.
4. Petugas mencatat setoran **Simpanan Pokok Rp 50.000**.
5. Dokumen permohonan di-scan dan diunggah ke sistem.
6. Kartu anggota dicetak; anggota menjadi aktif.

### 5.2 Setoran Simpanan Bulanan

- **Potong gaji (kolektif per OPD)**: petugas memilih OPD → sistem menampilkan daftar anggota dengan nominal default → petugas mencentang dan menyimpan sekaligus → rekap dicetak.
- **Setor sendiri**: anggota/bendahara menyetor → petugas mencatat per transaksi → slip dicetak.
- Bulan yang tidak disetor dibiarkan bolong, tanpa denda.

### 5.3 Pencatatan Pinjaman (Setelah ACC)

1. Admin/pengurus **menyetujui pinjaman di luar sistem**.
2. Anggota mengisi **formulir pinjaman / Tanda Terima Pinjaman Uang**.
3. Petugas mencatat pinjaman; sistem menghitung potongan (Admin 1% + SWP 1%) dan dana diterima.
4. Sistem menghasilkan jadwal angsuran (untuk jangka panjang).
5. Dokumen diunggah ke sistem.

### 5.4 Pembayaran Angsuran

1. Petugas membuka halaman pembayaran cepat.
2. Memasukkan nomor anggota → sistem menampilkan pinjaman aktif & angsuran jatuh tempo.
3. Petugas mencatat pembayaran (potong gaji/manual).
4. Sistem mencatat pokok & jasa, serta menambah Tabungan Berjangka.
5. Kuitansi dicetak.
6. Bila ini angsuran terakhir → status **Lunas**, SWP + Tabungan Berjangka dikembalikan.

### 5.5 Pinjaman Jangka Pendek (Sebrakan)

1. Anggota meminjam < Rp 1.000.000 (sudah ACC di luar sistem) dan mengisi formulir.
2. Petugas mencatat pinjaman tanpa potongan dan tanpa angsuran.
3. Anggota mengembalikan penuh pada bulan berikutnya → status Lunas.

### 5.6 Koreksi Transaksi (Reversal)

1. Petugas membuka transaksi yang salah, lalu menekan **Reversal**.
2. Petugas memasukkan alasan koreksi.
3. Sistem membuat transaksi lawan; saldo terkoreksi otomatis.
4. Seluruh perubahan tercatat dalam jejak audit.

---

## 6. Aturan Bisnis

### Anggota
- NIK harus 16 digit dan unik.
- Golongan menentukan nominal simpanan wajib secara otomatis.
- Anggota berstatus "Keluar" tidak dapat bertransaksi.
- Anggota yang memiliki transaksi tidak dapat dihapus permanen.

### Simpanan
- Simpanan Pokok Rp 50.000 dibayar sekali; Simpanan Wajib mengikuti golongan.
- Wajib Belanja Rp 100.000/bulan bersifat opsional; saldonya hanya untuk belanja, tidak diuangkan.
- Simpanan Hari Raya fleksibel; dicairkan menjelang hari raya setiap tahun.
- Boleh bolong tanpa sanksi; tidak ada bunga simpanan dan tidak ada potongan atas simpanan.

### Pinjaman
- Hanya pinjaman yang sudah disetujui (ACC) yang dicatat di sistem.
- **Semua pinjaman wajib disertai pengisian formulir.**
- Jangka pendek (Sebrakan): < Rp 1.000.000, tanpa potongan, tanpa angsuran, 1 bulan.
- Jangka panjang: > Rp 1.000.000, dikenai Admin 1% + SWP 1% saat pencairan.
- Jangka waktu pinjaman jangka panjang diinput per pengajuan.
- Angsuran wajib via potong gaji; anggota dalam blacklist tidak dapat meminjam.

### Angsuran
- Angsuran/bulan = Pokok + Jasa (Pokok × 0,65%) + Tabungan Berjangka (Pokok × 0,1%).
- Boleh bolong; tidak ada pemutusan otomatis.
- SWP dan Tabungan Berjangka dikembalikan saat pinjaman lunas.

### Transaksi Umum
- Tidak ada penghapusan permanen untuk transaksi keuangan.
- Koreksi wajib melalui reversal.
- Setiap transaksi tercatat dalam jejak audit.

---

## 7. Prinsip Keamanan Data

Karena sistem mengelola dana milik anggota, beberapa prinsip dijaga secara konsisten:

- **Jejak audit** — setiap penambahan, perubahan, dan pembatalan data tercatat lengkap: oleh siapa dan kapan.
- **Reversal, bukan hapus** — transaksi keuangan tidak pernah dihapus; koreksi dilakukan dengan transaksi lawan.
- **Saldo dihitung dari transaksi** — saldo bukan angka yang diubah manual, melainkan hasil dari seluruh transaksi.
- **Pencegahan input ganda** — sistem mencegah satu transaksi tercatat dua kali akibat penekanan tombol berulang.
- **Backup harian** — data dan dokumen dicadangkan otomatis setiap hari ke penyimpanan terpisah.
- **Hak akses berjenjang** — setiap pengguna hanya dapat mengakses fungsi sesuai perannya.

---

## 8. Hal yang Masih Menunggu Konfirmasi

| # | Hal | Status |
|---|---|---|
| 1 | Wajib Belanja saat anggota keluar — diuangkan atau dalam bentuk barang? | Belum ditentukan |
| 2 | Bunga simpanan | Diasumsikan **tidak ada** (perlu konfirmasi final) |
| 3 | Perhitungan SHU (Sisa Hasil Usaha) | Belum ditentukan — metode menunggu ketentuan koperasi |
| 4 | Minimal nominal pengambilan simpanan | Belum ditentukan |
| 5 | Rincian laporan bulanan | Akan dibahas pada tahap berikutnya |

---

## 9. Pengembangan Lanjutan

Hal-hal berikut dapat ditambahkan setelah sistem inti berjalan:

- **Integrasi aplikasi toko (Wajib Belanja)** — agar transaksi belanja anggota otomatis mengurangi saldo, beserta migrasi data toko ke sistem koperasi. Selama transisi, pemakaian saldo dicatat manual.
- **Perhitungan SHU** — disusun setelah metode perhitungan SHU ditetapkan koperasi.
- **Portal mandiri anggota** — agar anggota dapat memeriksa saldo dan riwayat secara mandiri.
- **Notifikasi otomatis** — pengingat jatuh tempo dan jadwal RAT.

---

## 10. Glosarium

| Istilah | Definisi |
|---|---|
| **KPRI** | Koperasi Pegawai Republik Indonesia |
| **KOPEKOMA** | Nama koperasi (KPRI Kota Magelang) |
| **ASN** | Aparatur Sipil Negara |
| **OPD** | Organisasi Perangkat Daerah (instansi tempat anggota bekerja) |
| **NIP** | Nomor Induk Pegawai |
| **HR / THL** | Tenaga Harian Lepas / honorer |
| **Golongan** | Tingkat kepangkatan yang menentukan nominal simpanan wajib |
| **SWP** | Simpanan Wajib Pinjam — dipotong 1% saat pinjaman cair, dikembalikan saat lunas |
| **Tabungan Berjangka** | Simpanan 0,1% dari pokok per bulan dalam angsuran, dikembalikan saat lunas |
| **Sebrakan** | Pinjaman jangka pendek < Rp 1 juta, tanpa biaya & tanpa angsuran |
| **Jasa** | Imbal jasa pinjaman untuk koperasi (0,65% dari pokok per bulan) |
| **RAT** | Rapat Anggota Tahunan (sekitar Februari–Maret) |
| **SHU** | Sisa Hasil Usaha |
| **Reversal** | Koreksi transaksi melalui transaksi lawan |
| **Bolong** | Bulan yang tidak disetor/dibayar, tanpa sanksi |

---

*Dokumentasi Sistem — Sistem Informasi Koperasi Simpan Pinjam KPRI KOPEKOMA (v5.0)*
