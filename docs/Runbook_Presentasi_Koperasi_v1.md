# Runbook Presentasi — Sistem Informasi Koperasi Simpan Pinjam

### KPRI KOPEKOMA — Magelang

| | |
|---|---|
| **Dokumen** | Runbook Presentasi & Skrip Demo |
| **Versi** | 1.0 |
| **Tanggal rapat** | Rabu, 19 Agustus 2026 |
| **Peserta** | Pengurus KPRI KOPEKOMA |
| **Durasi demo** | ± 25 menit, sisanya diskusi & keputusan |
| **Acuan** | Rancangan Sistem v5, Rencana Pengerjaan v5, ADR `docs/adr/` |

---

## 1. Tiga hal yang harus keluar dari rapat ini

Demo hanyalah alat. Rapat dianggap berhasil kalau tiga hal berikut sudah ada jawabannya sebelum bubar — bukan sekadar pengurus mengangguk melihat layar.

| # | Hasil | Isi |
|---|---|---|
| 1 | **Tanggal UAT & go-live** | Kapan pengurus menguji dengan data nyata, kapan pelatihan, kapan sistem dipakai resmi |
| 2 | **Data awal koperasi** | Siapa menyerahkan daftar anggota, OPD, dan saldo berjalan — format apa, paling lambat kapan |
| 3 | **Tujuh keputusan tertunda** | Hal-hal yang hanya pengurus yang boleh memutuskan (lihat §5) |

---

## 2. Sebelum berangkat

Kerjakan berurutan. Poin terakhir yang paling sering menyelamatkan rapat — demo langsung bisa gagal karena hal-hal di luar aplikasi.

- [ ] **Bangun ulang data demo** — supaya tidak ada data coba-coba yang nyangkut di layar saat dipresentasikan
- [ ] **Buka semua halaman demo sekali** — pastikan tidak ada error, dan PDF sudah pernah dirender sehingga tidak ada jeda aneh
- [ ] **Cetak contoh dokumen fisik** — kartu anggota, slip setoran, tanda terima pinjaman, kuitansi angsuran, satu laporan PDF berkop
- [ ] **Isi identitas koperasi di Settings** — nama, alamat, telepon, nama penandatangan; itu yang muncul di kop laporan
- [ ] **Screenshot tiap langkah demo** — cadangan kalau laptop, proyektor, atau jaringan bermasalah di lokasi
- [ ] **Backup database sebelum berangkat** — rapat sering berubah jadi sesi coba-coba oleh pengurus; harus bisa dikembalikan

### 2.1 Akun demo

| Peran | Email | Kata sandi | Dipakai untuk |
|---|---|---|---|
| Pengurus / Bendahara | `pengurus@kopekoma.test` | `password` | ACC pencairan, bayar angsuran dari simpanan, unduh laporan |
| Petugas Input | `petugas@kopekoma.test` | `password` | Langkah 11 — menunjukkan tombol mana yang **tidak** muncul |

### 2.2 Perintah menyiapkan data

```bash
php artisan migrate:fresh --seed
php artisan db:seed --class=DemoDataSeeder
```

Menghasilkan 3 OPD, 12 anggota, simpanan enam bulan terakhir, dan lima pinjaman yang masing-masing sengaja dibuat beda kondisi — lancar, menunggak, Sebrakan, siap didebit dari simpanan, dan lunas.

> **Perhatian:** `migrate:fresh` menghapus seluruh isi database. Jalankan hanya di laptop demo, tidak pernah di server koperasi.

### 2.3 Kondisi data demo yang sudah disiapkan

| Anggota | Kondisi pinjaman | Dipakai di langkah |
|---|---|---|
| Sri Wahyuni | 6 dari 12 angsuran terbayar, lancar | 6 |
| Bambang Setiawan | 3 dari 24 terbayar, **5 menunggak** | 1, 6 |
| Tri Handayani | Sebrakan (jangka pendek), belum dibayar | 5 |
| Agus Riyanto | Tagihan Rp 545.000, saldo sukarela Rp 3.250.000 | 8 |
| Endang Purwanti | **Lunas** → draft pengembalian SWP + tabungan berjangka | 9 |
| Slamet Widodo | Masuk daftar blacklist pinjaman | cadangan |
| Joko Susilo | Punya pengajuan pencairan berstatus draft | 9 |

Nomor pinjaman berubah setiap database dibangun ulang — pegangannya **nama anggota**, bukan nomor.

---

## 3. Alur demo

Satu benang cerita, bukan tur menu. Tiap langkah menyiapkan panggung untuk langkah berikutnya, dan ditutup dengan pembatalan transaksi — nilai jual terbesar sistem ini bagi koperasi.

### 1. Buka dengan dashboard, bukan menu
`/dashboard`

Tunjukkan angka ringkas: total simpanan, pinjaman berjalan, dan daftar angsuran menunggak. Biarkan pengurus melihat sendiri ada nama yang menunggak sebelum Anda menjelaskan apa pun.

> *"Ini yang dilihat pengurus tiap pagi. Yang merah ini yang perlu ditindaklanjuti bulan ini."*

### 2. Data anggota & kartu anggota
`/master/anggota`

Buka satu anggota, tunjukkan data ahli waris dan dokumen terlampir, lalu cetak kartu anggotanya. Sebutkan nomor anggota dibuat otomatis dan tidak bisa dobel.

### 3. Setoran simpanan — satu anggota
`/setor-simpanan/create`

Input satu setoran sukarela, lalu langsung cetak slipnya. Tekankan nominal simpanan wajib terisi otomatis mengikuti golongan — petugas tidak perlu hafal tabel.

### 4. Batch potong gaji per OPD
`/setor-simpanan/batch`

Bagian yang paling menghemat waktu bendahara: pilih OPD dan periode, seluruh anggota muncul dengan nominal wajibnya, sekali simpan jadi puluhan setoran sekaligus.

> *"Yang biasanya diketik satu-satu tiap awal bulan, di sini jadi satu kali proses per OPD."*

### 5. Pinjaman baru & tanda terima
`/pinjaman/create`

Masukkan pinjaman jangka panjang. Tunjukkan potongan administrasi dan SWP dihitung sistem, dana diterima muncul otomatis, dan jadwal angsuran langsung terbentuk sepanjang tenor. Cetak tanda terimanya.

Tegaskan: persetujuan pinjaman tetap keputusan pengurus di luar sistem — aplikasi hanya mencatat yang sudah di-ACC dan cair.

### 6. Progres angsuran & kasus menunggak
`/pinjaman`

Buka pinjaman **Bambang Setiawan**. Seluruh jadwal terlihat baris per baris: mana yang sudah terbayar, mana yang lewat tempo. Bandingkan dengan pinjaman **Sri Wahyuni** yang lancar.

### 7. Bayar angsuran & kuitansi
`/angsuran/create`

Petugas cukup memasukkan satu angka: berapa uang yang benar-benar diterima. Rincian pokok, jasa, dan tabungan berjangka dihitung sistem. Cetak kuitansinya.

> *"Anggota bayar satu kali satu angka. Petugas tidak perlu memecah sendiri — itu tugas sistem."*

### 8. Bayar angsuran dari saldo simpanan
`/angsuran/create`

Kasus "nitip": anggota minta angsurannya diambilkan dari simpanan sukarelanya. Pakai pinjaman **Agus Riyanto** (tagihan Rp 545.000, saldo sukarela Rp 3.250.000). Tunjukkan bahwa metode ini hanya muncul untuk Pengurus, nominalnya terkunci persis sebesar tagihan, dan bukti persetujuan anggota wajib diunggah.

### 9. Pelunasan & pengembalian otomatis
`/pinjaman` → `/simpanan/pencairan`

Buka pinjaman **Endang Purwanti** yang sudah lunas. Sistem otomatis membuat draft pengembalian SWP dan tabungan berjangka — menunggu ACC pengurus, bukan langsung cair. Lanjutkan ke halaman pencairan untuk meng-ACC salah satunya.

### 10. Laporan per periode
`/laporan/setoran-simpanan` → `/laporan/angsuran-pinjaman`

Pilih rentang bulan, tunjukkan rekap dikelompokkan per OPD dan per anggota, lalu unduh PDF berkop dan Excel-nya. Buka file PDF-nya di layar — kop dan blok tanda tangan adalah hal pertama yang dicari pengurus.

### 11. Ganti akun ke Petugas
`/login`

Masuk sebagai petugas, buka halaman laporan yang sama: tombol unduh tidak ada, dan metode bayar dari simpanan tidak muncul. Pembatasan hak akses terlihat, bukan sekadar dijanjikan.

> *"Petugas bisa mencatat, tapi tidak bisa mengeluarkan uang atau menarik laporan keluar."*

### 12. Penutup: pembatalan transaksi & jejak audit
`/sistem/log-aktivitas`

Kembali sebagai pengurus. Batalkan satu setoran yang salah input, tunjukkan saldo anggota langsung terkoreksi, lalu buka log aktivitas: transaksi asli tetap ada, pembatalannya tercatat, lengkap dengan siapa dan kapan.

> *"Di sini tidak ada tombol hapus untuk transaksi uang. Yang salah dibatalkan dengan transaksi lawan — persis seperti pembukuan manual, tapi otomatis. Jadi angka koperasi selalu bisa dipertanggungjawabkan sampai ke RAT."*

---

## 4. Status pekerjaan

Tampilkan apa adanya. Pengurus lebih percaya pada daftar yang jujur menyebut apa yang belum ada daripada klaim "semua sudah selesai".

### Sudah berjalan

- Data anggota, OPD, golongan
- Simpanan: pokok, wajib, sukarela, hari raya, wajib belanja
- Batch potong gaji per OPD
- Pinjaman, jadwal angsuran, angsuran, pelunasan dipercepat
- Bayar angsuran dari saldo simpanan
- Pencairan simpanan dengan alur persetujuan (draft → ACC → cair)
- Laporan setoran & angsuran + export PDF/Excel
- Pembatalan transaksi & jejak audit
- Hak akses bertingkat & backup terjadwal

### Belum dikerjakan — perlu keputusan

- Penutupan akun anggota (keluar / meninggal)
- Perhitungan SHU
- Laporan khusus RAT
- Integrasi aplikasi toko untuk wajib belanja
- Jenis laporan tambahan di luar dua yang sudah ada

### Menunggu koperasi

- Data anggota & saldo berjalan yang asli
- Jadwal UAT bersama pengurus
- Jadwal pelatihan petugas
- Penetapan siapa memegang peran Pengurus & Petugas

---

## 5. Keputusan yang diminta hari ini

Semua sudah ada usulan default. Kalau pengurus tidak punya preferensi, tawarkan default-nya dan catat sebagai keputusan — jangan biarkan menggantung untuk rapat berikutnya.

| Topik | Yang perlu diputuskan | Usulan |
|---|---|---|
| **Bukti persetujuan debit simpanan** | Saat angsuran diambilkan dari simpanan, cukup unggah bukti bebas (foto surat, WhatsApp), atau perlu formulir kuasa pendebitan baku bertanda tangan? | Formulir kuasa baku — lebih kuat bila ada sengketa |
| **Bukti bayar pelunasan** | Untuk pelunasan dipercepat, bukti pembayaran wajib atau opsional seperti angsuran biasa? | Wajib — nominalnya besar dan sekali jalan |
| **Pembulatan bulan terakhir** | Melunasi di bulan terakhir sedikit lebih murah daripada menuntaskan angsuran normal akibat pembulatan pokok. Dibiarkan atau disamakan? | Dibiarkan — selisih kecil dan menguntungkan anggota |
| **Laporan tambahan** | Selain rekap setoran & angsuran, laporan apa yang benar-benar dipakai tiap bulan? Minta contoh format manualnya. | Kunci maksimal dua tambahan untuk fase ini |
| **SHU & RAT** | Saat ini di luar lingkup. Dibutuhkan? Kalau ya, harus siap sebelum RAT tahun berapa? | Fase terpisah setelah sistem inti dipakai rutin |
| **Penutupan akun anggota** | Alur anggota keluar/pensiun/meninggal belum ada. Sudah ada kasusnya dalam waktu dekat? | Dahulukan bila ada kasus tahun ini |
| **Integrasi toko koperasi** | Saldo wajib belanja masih dipotong manual. Kapan toko disambungkan langsung? | Setelah go-live sistem inti stabil |

---

## 6. Jadwal yang perlu disepakati

Bawa kalender. Empat tanggal ini yang menentukan kapan koperasi benar-benar bisa berhenti memakai cara lama.

- [ ] **Serah terima data awal** — daftar anggota, OPD, saldo berjalan; sepakati format dan penanggung jawabnya
- [ ] **UAT bersama pengurus** — pengurus menjalankan kasus nyata mereka sendiri, bukan menonton demo
- [ ] **Pelatihan petugas** — setengah hari, dipandu langsung di aplikasi, disertai manual singkat
- [ ] **Mulai dipakai resmi** — tentukan bulan buku pertama yang dicatat di sistem, supaya tidak ada periode abu-abu

---

## 7. Antisipasi pertanyaan

Pertanyaan yang hampir selalu muncul dari pengurus koperasi, beserta jawaban yang bisa langsung dibuktikan di layar.

**Kalau petugas salah input, bagaimana?**
Transaksi keuangan tidak bisa dihapus. Yang salah dibatalkan dengan transaksi lawan, saldo terkoreksi otomatis, dan keduanya tetap terlihat di riwayat beserta nama pembatalnya. Bisa langsung diperagakan — langkah 12.

**Apakah saldo bisa diubah manual oleh petugas?**
Tidak. Saldo bukan angka yang disimpan, melainkan dihitung ulang dari seluruh transaksi setiap kali dibuka. Tidak ada kolom saldo yang bisa diketik.

**Bagaimana kalau datanya hilang?**
Backup database berjalan otomatis terjadwal dan hasilnya dikirim ke saluran pengurus, jadi salinannya tidak hanya ada di satu mesin.

**Petugas bisa lihat data pribadi anggota semua?**
Akses dibatasi per peran. Petugas mencatat transaksi; pengurus yang berwenang mengeluarkan uang, meng-ACC pencairan, dan menarik laporan keluar. Bisa diperagakan — langkah 11.

**Berapa lama petugas belajar memakainya?**
Setengah hari pelatihan untuk alur harian: setoran, batch potong gaji, dan angsuran. Menu disusun mengikuti urutan kerja sehari-hari, bukan urutan tabel database.

**Kalau anggota minta angsurannya dipotong dari simpanan?**
Sudah bisa, tapi sengaja dibatasi: hanya dari simpanan sukarela, hanya oleh pengurus, nominal terkunci persis sebesar tagihan, dan bukti persetujuan anggota wajib diunggah. Bukan sesuatu yang bisa dilakukan diam-diam.

---

## 8. Setelah rapat

Kirim hari itu juga selagi ingatan semua orang masih segar.

- [ ] **Notulen keputusan** — tujuh butir keputusan beserta jawabannya, dikirim untuk dikonfirmasi tertulis
- [ ] **Empat tanggal yang disepakati** — data awal, UAT, pelatihan, go-live
- [ ] **Daftar permintaan baru** — pisahkan mana yang masuk fase ini dan mana yang ditunda, sebelum lingkup melebar diam-diam
- [ ] **Format data awal** — kirim contoh berkas isian supaya koperasi tidak menebak-nebak kolomnya

---

*Runbook Presentasi — Sistem Informasi Koperasi Simpan Pinjam KPRI KOPEKOMA (v1.0), 19 Agustus 2026. Data demo dibangun oleh `database/seeders/DemoDataSeeder.php`.*
