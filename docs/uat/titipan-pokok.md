# UAT — Titipan Pokok

Skenario uji coba manual untuk [ADR 2026-08-28 Kelebihan Bayar Angsuran](../adr/2026-08-28-kelebihan-bayar-angsuran.md).

Angka-angka di dokumen ini **bukan perkiraan** — semuanya dikunci oleh
`tests/Feature/TitipanPokokDemoSeederTest.php`, jadi bila hasil di layar berbeda
dari yang tertulis di sini, yang salah adalah sistemnya, bukan dokumennya.

---

## Menyiapkan

```bash
php artisan migrate:fresh --seed
php artisan db:seed --class=DemoDataSeeder
php artisan db:seed --class=TitipanPokokDemoSeeder
```

Login:

| Peran | Email | Password |
|---|---|---|
| Petugas | `petugas@kopekoma.test` | `password` |
| Pengurus | `pengurus@kopekoma.test` | `password` |

Seluruh pinjaman uji ada di OPD **Dinas Perhubungan Kab. Magelang**.

### Angka acuan

Semua pinjaman uji: **12.000.000 / 12 bulan**.

| Komponen | Nilai |
|---|---|
| Pokok / bulan | 1.000.000 |
| Jasa / bulan | 78.000 |
| Tabungan Berjangka / bulan | 12.000 |
| **Tagihan kontrak / bulan** | **1.090.000** |

Titipan Pokok hanya boleh memotong **pokok**, jadi tagihan efektif tak pernah
turun di bawah 90.000 (jasa + tab. berjangka).

### Keadaan awal tiap pinjaman

| Kode | Anggota | Keadaan |
|---|---|---|
| **T1** | Rahayu Kusumaningrum | 2 angsuran terbayar, titipan 0 |
| **T2** | Yusuf Maulana | Titipan **1.090.000**, angsuran berikutnya #3 |
| **T3** | Wahyu Nugroho | #1 & #2 tertutup **satu setoran**, titipan 0 |
| **T4** | Ratna Dewi Anggraini | Titipan sudah **terpakai** angsuran #2, sisa 90.000 |
| **T5** | Hesti Prabaningrum | **LUNAS** via Pelunasan Dipercepat bertitipan |
| **T6** | Bagus Prakoso | Titipan 1.090.000, sisa pokok 8.000.000 |

---

## Bagian 1 — Dialog alokasi (pakai **T1**)

### 1.1 Kelebihan receh tidak memunculkan apa pun

*Petugas · Angsuran → Catat Pembayaran · anggota Rahayu Kusumaningrum*

| Langkah | Hasil yang diharapkan |
|---|---|
| Pilih anggota & pinjaman | Nominal ter-prefill **1.090.000** |
| Ubah nominal jadi **1.100.000**, Simpan | **Tersimpan langsung, tanpa dialog.** Titipan jadi 10.000 |

> Dialog hanya muncul bila sisa uang cukup menutup **satu angsuran penuh**
> berikutnya. Pembulatan ke atas di loket tak boleh menyulitkan petugas.

**Setelah selesai, ulangi seeder** (`migrate:fresh` + tiga perintah seed) sebelum
lanjut ke 1.2 — atau pakai anggota lain.

### 1.2 Dialog wajib muncul dan berangka

| Langkah | Hasil yang diharapkan |
|---|---|
| Nominal **2.180.000**, Simpan | **Dialog muncul. Belum ada yang tersimpan.** |

Dialog harus menyajikan **kedua pilihan dalam rupiah**, bukan sekadar namanya:

| | Simpan sebagai Titipan Pokok | Tutup angsuran berikutnya sekalian |
|---|---|---|
| Angsuran tertutup | #3 | #3 dan #4 |
| Sisa Titipan Pokok | **1.090.000** | **0** |
| Tagihan berikutnya | #4 → **90.000**<br>#5 → **1.000.000** | #5 → **1.090.000** |

> Perhatikan #5 = **1.000.000**, bukan 1.090.000. Setelah #4 memakai 1.000.000
> dari titipan, sisanya 90.000 dan itu memotong #5. Titipan mengalir, bukan
> habis sekaligus.

### 1.3 Memilih = menyetujui

| Langkah | Hasil yang diharapkan |
|---|---|
| Klik **Simpan sebagai Titipan Pokok** | Langsung tersimpan, **tanpa klik konfirmasi kedua**. 1 baris angsuran dibuat |
| Buka detail pinjaman | Saldo Titipan Pokok **1.090.000** |

**Yang HARUS gagal:** klik salah satu mode lalu tekan tombol kembali browser dan
simpan ulang — tidak boleh menghasilkan baris angsuran kedua.

---

## Bagian 2 — Tagihan efektif & kuitansi (pakai **T2**)

*Petugas · Catat Pembayaran · anggota Yusuf Maulana*

| Langkah | Hasil yang diharapkan |
|---|---|
| Pilih anggota & pinjaman | Nominal ter-prefill **90.000** — bukan 1.090.000 |
| Panel tagihan | Menampilkan **Tagihan kontrak 1.090.000**, **Titipan Pokok dipakai 1.000.000**, **Tagihan Bulan Ini 90.000** |
| Cari kalimat "dikreditkan ke Simpanan Sukarela" | **Tidak ada.** Sudah dicabut dari layar loket |
| Isi **89.999**, Simpan | **Ditolak** — "Nominal tidak boleh kurang dari tagihan Rp 90.000" |
| Isi **90.000**, Simpan | Tersimpan. Titipan jadi **0** |

### 2.1 Kuitansi harus berjumlah

Buka kuitansi baris **ANG-2026-000004** (setoran 2.180.000 milik Yusuf):

| Baris | Nilai |
|---|---|
| Pokok | 1.000.000 |
| Jasa | 78.000 |
| Tabungan Berjangka | 12.000 |
| **Titipan Pokok disisihkan** | **1.090.000** |
| **Total** | **2.180.000** |
| Sisa Titipan Pokok | 1.090.000 |

> Σ komponen **wajib** sama dengan total. Kuitansi yang tak berjumlah adalah
> dokumen yang diserahkan ke anggota dalam keadaan salah.

### 2.2 Panel Riwayat Titipan Pokok

Buka **Detail Pinjaman** T2 → panel *Riwayat Titipan Pokok*:

| Tanggal | Angsuran | Masuk | Dipakai | Saldo |
|---|---|---|---|---|
| … | ANG-…04 | 1.090.000 | — | 1.090.000 |

Baris yang tidak menggerakkan saldo (pembayaran pas) **tidak boleh muncul** —
panel ini menjelaskan pergerakan, bukan mendaftar transaksi.

---

## Bagian 3 — Satu setoran, dua angsuran (pakai **T3**)

*Detail pinjaman Wahyu Nugroho*

| Langkah | Hasil yang diharapkan |
|---|---|
| Lihat daftar angsuran | **Dua baris**, keduanya 1.090.000, angsuran #1 dan #2 |
| Buka detail salah satu baris | Menampilkan keterkaitan sesi — *"satu setoran bersama ANG-…"* |
| Cek lampiran bukti | Bila setoran dibuat dengan bukti, bukti melekat di **kedua** baris, bukan hanya yang pertama |
| Saldo Titipan Pokok | **0** |

### 3.1 Pembatalan bersifat per-transaksi

| Langkah | Hasil yang diharapkan |
|---|---|
| Batalkan baris angsuran **#2** saja | Berhasil. Jadwal #2 kembali *Belum Bayar*; #1 tetap terbayar |
| Cek Sisa Pokok di detail angsuran #1 | Sama dengan sisa pokok pinjaman sebenarnya — **11.000.000**, bukan angka lain |

> Pembatalan sengaja **tidak** memaksa membatalkan sepaket. Yang dijaga adalah
> angkanya tetap benar walau jadwalnya berlubang.

---

## Bagian 4 — Guard pembatalan (pakai **T4**)

*Ratna Dewi Anggraini — titipannya sudah dipakai angsuran #2*

| Langkah | Hasil yang diharapkan |
|---|---|
| Coba batalkan angsuran **#1** (setoran 2.180.000) | **DITOLAK.** Pesan menyebut nomor angsuran penghalang: *"titipannya sudah dipakai angsuran ANG-… Batalkan angsuran ANG-… lebih dulu."* |
| Cek daftar angsuran | **Tidak ada baris pembalik yang tertinggal.** Seluruh transaksi dibatalkan |
| Batalkan angsuran **#2** lebih dulu | Berhasil. Titipan kembali **1.090.000** |
| Lalu batalkan angsuran **#1** | Sekarang berhasil. Titipan kembali **0** |

### 4.1 Penolakan wajib meninggalkan jejak

*Pengurus · Sistem → Log Aktivitas*

| Yang dicari | Hasil yang diharapkan |
|---|---|
| Event **"Pembatalan ditolak"** | **Ada.** Penolakan tanpa jejak berarti percobaan berulang tak terlihat siapa pun |
| Isi jejaknya | Menampilkan **Titipan Pokok sesudah** (negatif), **Titipan Pokok tertahan pelunasan**, dan **Angsuran penghalang** — dengan label Indonesia dan format rupiah, bukan nama kolom mentah |

> Jejak ini sempat **tidak pernah tersimpan sama sekali** (ditulis di dalam
> transaksi yang lalu di-rollback oleh penolakannya sendiri). Periksa
> sungguh-sungguh; ini bukan formalitas.

---

## Bagian 5 — Pelunasan Dipercepat bertitipan (pakai **T5** dan **T6**)

### 5.1 Penjaga: setoran yang cukup melunasi diarahkan, bukan diproses diam-diam

*Petugas · Catat Pembayaran · anggota Bagus Prakoso (T6)*

| Langkah | Hasil yang diharapkan |
|---|---|
| Isi nominal **7.000.000**, Simpan | **Ditolak dengan arahan:** *"Nominal ini cukup untuk melunasi seluruh sisa pinjaman. Gunakan Pelunasan Dipercepat — jumlahnya Rp 6.988.000, lebih ringan bagi anggota karena jasa bulan sisa dibebaskan."* |

Periksa angkanya: sisa pokok 8.000.000 + jasa 78.000 − titipan 1.090.000 =
**6.988.000**. Bila layar menyebut 8.078.000, potongan titipannya hilang.

### 5.2 Hasil pelunasan bertitipan (**T5**, sudah lunas di data uji)

*Detail pinjaman Hesti Prabaningrum*

| Yang diperiksa | Hasil yang diharapkan |
|---|---|
| Status | **Lunas** |
| Nominal baris pelunasan | **78.000** — sisa pokok 1.000.000 + jasa 78.000 − titipan terpakai 1.000.000 |
| Rincian baris pelunasan | Kolom **Titipan Pokok dipakai** terisi **1.000.000**, bukan kosong |
| Simpanan Sukarela anggota | Bertambah **1.000.000** — sisa titipan yang tak terpakai, **tidak hangus** |
| SWP & Tab. Berjangka | **Tetap jadi simpanan anggota** di jenisnya masing-masing. Tidak ada pencairan yang terbit sendiri — lihat §9 |

### 5.3 Panel Riwayat harus memisahkan dua pintu keluar

Panel *Riwayat Titipan Pokok* pada T5, **tiga baris terakhir**:

| Angsuran | Masuk | Dipakai | Saldo | Catatan |
|---|---|---|---|---|
| ANG-…19 | 2.000.000 | — | 2.000.000 | — |
| — | — | 1.000.000 | 1.000.000 | **Memotong jumlah Pelunasan Dipercepat** |
| — | — | 1.000.000 | 0 | **Dilimpahkan ke Simpanan Sukarela saat pinjaman ditutup** |

> Dua baris, bukan satu. Sebelumnya seluruh sisa dilabeli "Dilimpahkan ke
> Sukarela" — termasuk bagian yang sebenarnya dimakan potongan pelunasan dan tak
> pernah sampai ke simpanan anggota.

### 5.4 Guard pelunasan (temuan security review — **wajib diuji**)

Masih di T5:

| Langkah | Hasil yang diharapkan |
|---|---|
| Coba batalkan **ANG-…19** (setoran 3.090.000 yang membuat titipan) — biarkan baris pelunasannya berdiri | **DITOLAK**, dengan pesan menunjuk **baris pelunasannya** sebagai penghalang |
| Cek status pinjaman | Tetap **Lunas**. Sisa pokok tetap **0** |
| Batalkan **baris pelunasan** lebih dulu | Berhasil. Status kembali **Cair**, titipan kembali **2.000.000** |
| Lalu batalkan ANG-…19 | Sekarang berhasil |

> Ini lubang uang yang terverifikasi sebelum diperbaiki: pembatalan lolos,
> potongan pada pelunasan sudah terlanjur diterima, dan koperasi menanggung
> selisihnya. Bila langkah pertama **berhasil**, jangan diteruskan ke produksi.

---

## Bagian 6 — Batch potong gaji (pakai OPD Dinas Perhubungan)

*Petugas · Angsuran → Batch Potong Gaji*

| Langkah | Hasil yang diharapkan |
|---|---|
| Pilih OPD **Dinas Perhubungan Kab. Magelang** | Muncul baris untuk pinjaman yang masih berjalan |
| Baris **Bagus Prakoso (T6)** — kolom pelunasan | **6.988.000** — sudah dikurangi titipan |
| Nominal angsuran biasa | Tetap **1.090.000** (angka kontrak). Payroll memotong angka kontrak, bukan angka efektif |
| Proses batch | Berhasil; titipan T6 **tidak berkurang** oleh potongan sebesar kontrak |

### 6.1 Baris yang dilewati harus bisa ditelusuri

| Langkah | Hasil yang diharapkan |
|---|---|
| Bayar satu angsuran manual dulu, lalu jalankan batch yang memuat jadwal yang sama | Ringkasan menyebut jumlah dilewati |
| *Pengurus* · Log Aktivitas → event **Batch Potong Gaji** | Memuat **daftar baris yang dilewati**: nomor pinjaman, nama anggota, dan **sebabnya** — bukan hanya angka "1 dilewati" |

> Pertanyaan yang benar-benar diajukan setelah potong gaji adalah *gaji siapa
> yang terpotong tapi angsurannya tak tercatat*. Angka telanjang tak menjawabnya.

### 6.2 Pelunasan lewat batch butuh otoritas

| Langkah | Hasil yang diharapkan |
|---|---|
| Login **Petugas**, coba centang pelunasan pada baris batch | Tidak tersedia / ditolak **403** |
| Login **Pengurus**, centang pelunasan | Tersedia, tapi **wajib centang konfirmasi** dulu sebelum diproses |

---

## Bagian 7 — Laporan agregat & hak akses

### 7.1 Laporan Titipan Pokok

*Pengurus · Laporan → Laporan Titipan Pokok*

| Yang diperiksa | Hasil yang diharapkan |
|---|---|
| Total Titipan Pokok Mengendap | **2.270.000** (T2 1.090.000 + T4 90.000 + T6 1.090.000) |
| Jumlah baris | **3** — hanya pinjaman yang benar-benar bertitipan |
| Urutan | Terbesar dulu: Yusuf / Bagus (1.090.000), lalu Ratna (90.000) |
| Kolom | **Tagihan Kontrak** dan **Tagihan Efektif** tampil **berdampingan** |
| T5 (Hesti) | **Tidak muncul** — sudah Lunas, titipannya sudah keluar |
| Filter OPD & pencarian | Berfungsi; total ikut menyesuaikan |

> Selisih Tagihan Kontrak − Tagihan Efektif adalah persis nominal yang bisa
> dikantongi bila petugas menerima uang sebesar kontrak lalu mencatat yang
> efektif. Angka besar yang **tak berubah berbulan-bulan** patut ditanyakan.

### 7.2 Hak akses

| Peran | Halaman | Hasil yang diharapkan |
|---|---|---|
| Petugas | Laporan Titipan Pokok | **403.** Menunya juga tidak muncul di sidebar |
| Petugas | Log Aktivitas | **403** |
| Pengurus | Log Aktivitas | **Bisa dibuka** |
| Pengurus | Sistem → Pengguna / Peran | **403** — membaca jejak ≠ mengelola sistem |

> Kalau Pengurus kena 403 di Log Aktivitas atau Laporan Titipan Pokok, seed
> permission-nya belum dijalankan:
> `php artisan db:seed --class=RolePermissionSeeder`

---

## Bagian 8 — Regresi (yang TIDAK boleh berubah)

| Yang diperiksa | Hasil yang diharapkan |
|---|---|
| Pinjaman yang tak pernah kelebihan bayar (mis. Sri Wahyuni dari `DemoDataSeeder`) | Berperilaku persis seperti sebelumnya. Tak ada panel Titipan Pokok, tak ada baris tambahan di kuitansi |
| Bayar angsuran dari saldo simpanan | Tetap **terkunci tepat sebesar tagihan efektif** — tidak boleh lebih |
| Sebrakan (`jangka_pendek`) dengan kelebihan bayar | Kelebihannya **langsung ke Simpanan Sukarela** saat lunas, tanpa singgah jadi titipan |
| Angka tunggakan di dashboard | Anggota bertitipan **tidak** dilaporkan menunggak lebih besar dari kewajiban riilnya |
| Σ uang seumur pinjaman, kedua mode | **Identik.** Fitur ini keringanan arus kas, bukan potongan — anggota membayar total yang sama |

---

## Bagian 9 — SWP & Tabungan Berjangka sebagai simpanan

Perubahan terpisah dari Titipan Pokok, tapi ikut terlihat di skenario yang sama.
Keduanya sekarang **simpanan sungguhan** — punya baris setoran bernomor
transaksi, seperti Pokok/Wajib/Sukarela. Yang membedakan hanya pintu masuknya:
SWP lahir saat pinjaman cair, Tabungan Berjangka saat tiap angsuran dibayar.
Tak ada setoran manual untuk keduanya.

### 9.1 Setorannya terbit sendiri, dan terlihat

Pinjaman uji: **12.000.000 / 12 bulan** → SWP 1% = **120.000**, Tabungan
Berjangka 0,1% = **12.000 per angsuran**.

*Pengurus · Simpanan → Saldo Anggota → buka salah satu anggota T1–T6*

| Yang diperiksa | Hasil yang diharapkan |
|---|---|
| Kartu saldo | Ada kartu **SWP** dan **Tab. Berjangka**, bukan hanya Pokok/Wajib/Sukarela |
| SWP | **120.000** |
| Tab. Berjangka | 12.000 × jumlah angsuran yang sudah dibayar |
| Buku mutasi | Barisnya ada, berbunyi **"Potongan SWP saat pencairan pinjaman"** dan **"Tabungan Berjangka dari angsuran"** — bukan "Setoran" |
| Total saldo | **Termasuk** keduanya. Dulu tidak — anggota melihat angka lebih kecil dari simpanan yang benar-benar ia punya |

Cek cepat per pinjaman uji:

| Kode | Angsuran dibayar | Tab. Berjangka |
|---|---|---|
| T1 | 2 | 24.000 |
| T2 | 2 | 24.000 |
| T3 | 2 | 24.000 |
| T4 | 2 | 24.000 |
| T5 | 11 (+1 pelunasan) | **132.000** — baris pelunasan **tidak** menambah |
| T6 | 4 | 48.000 |

> T5 yang paling penting: pelunasan dipercepat membebaskan jasa bulan sisa, dan
> tabungan bulan sisa memang tak pernah disetor. Kalau angkanya 144.000, baris
> pelunasan salah ikut mengakru.

### 9.2 Lunas tidak memindahkan apa pun

*Pinjaman T5 (Hesti Prabaningrum) — sudah Lunas*

| Yang diperiksa | Hasil yang diharapkan |
|---|---|
| Daftar Pencairan | **Tidak ada** draft pengembalian SWP maupun Tab. Berjangka atas nama Hesti |
| Saldo SWP Hesti | **Tetap 120.000** |
| Saldo Tab. Berjangka Hesti | **Tetap 132.000** |

> Ini kebalikan dari perilaku lama, yang menerbitkan dua draft pencairan begitu
> pinjaman lunas. Kalau draft itu masih muncul, perubahannya belum jalan.

### 9.3 Anggota mengambilnya sendiri

*Pengurus · Simpanan → Pencairan → Buat*

| Langkah | Hasil yang diharapkan |
|---|---|
| Pilih anggota Hesti | Baris sumber dana memuat **SWP** dan **Tabungan Berjangka**, dengan saldo di atas |
| Ajukan penarikan SWP 120.000 | Tersimpan sebagai **draft** |
| ACC lalu Cairkan | Saldo SWP jadi **0**; Tab. Berjangka tak tersentuh |

> Gerbang mata-kedua tidak hilang — ia pindah ke sini, ke saat anggota
> benar-benar meminta uangnya.

### 9.4 Pembalikan harus berpasangan

| Langkah | Hasil yang diharapkan |
|---|---|
| Batalkan satu baris angsuran mana pun (mis. T1 angsuran #2) | Saldo Tab. Berjangka anggota **turun 12.000** |
| Buku mutasi | Muncul baris **"Pembatalan Tabungan Berjangka angsuran"** — baris aslinya tetap ada, dinetralkan, tidak dihapus |
| Batalkan sebuah **pinjaman** yang belum punya angsuran (salah input) | Saldo SWP anggota **turun 120.000**, dengan baris "Pembatalan potongan SWP" |

> Kalau saldo tidak ikut turun, salah ketik petugas menaikkan simpanan anggota
> secara permanen — pinjamannya tinggal catatan, uangnya tetap jadi saldo.

---

## Cara melaporkan temuan

Sebutkan **kode skenario** (mis. `5.4`), **apa yang muncul di layar**, dan
**nomor angsuran / pinjaman** yang terlibat. Bila menyangkut angka, sertakan
angka yang muncul dan angka yang tertulis di dokumen ini — selisihnya yang
paling cepat menunjukkan letak salahnya.
