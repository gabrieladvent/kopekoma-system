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

> `migrate:fresh --seed` sudah menjalankan `RolePermissionSeeder`. Kalau kamu
> menguji di database yang sudah ada, jalankan seeder itu sendiri —
> `php artisan db:seed --class=RolePermissionSeeder` — kalau tidak, tiga
> permission baru (`access_activity_log`, `access_laporan_titipan`,
> `bypass_time_deposit_schedule`) tak ada dan §7 serta §9.4 akan gagal.
>
> Di server, `deploy.sh` kini menjalankan seeder ini sendiri (langkah 5c).

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
| Tampilan dialog | Kepalanya berpembatas **garis + bayangan**, memisahkan pertanyaan dari pilihan |
| Tombol **Batal** | Berbingkai dan berlatar **merah** — terbaca sebagai tombol, bukan keterangan |

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
| Klik **Simpan sebagai Titipan Pokok** | Tombolnya menampilkan **spinner** dan seluruh pilihan mengunci selama proses, lalu langsung tersimpan **tanpa klik konfirmasi kedua**. 1 baris angsuran dibuat |
| Buka detail pinjaman | Saldo Titipan Pokok **1.090.000** |

> Spinner-nya bukan hiasan: karena memilih = langsung menyimpan, jeda tanpa
> penanda terbaca sebagai klik yang tak masuk — dan petugas menekannya lagi.

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
| Isi **90.000**, Simpan | Tersimpan. Titipan **sisa 90.000** — titipan hanya memotong pokok (maks. 1.000.000), jasa & tab. berjangka tetap tertagih |

### 2.1 Kuitansi harus berjumlah

Buka **menu Angsuran** → cari *Yusuf* → baris ber-**Dibayar 2.180.000** (angsuran ke-2) → **Cetak Kuitansi**.

> Nomor angsuran (`ANG-…`) tidak disebut di dokumen ini: penomorannya ikut isi
> database, jadi di setiap lingkungan berbeda. Kenali barisnya dari nominalnya.

| Baris | Nilai |
|---|---|
| Pokok | 1.000.000 |
| Jasa | 78.000 |
| Tabungan Berjangka | 12.000 |
| **Titipan Pokok disisihkan** | **1.090.000** |
| **Total Dibayar** | **2.180.000** |
| Sisa Titipan Pokok | 1.090.000 |

> Σ komponen **wajib** sama dengan total. Kuitansi yang tak berjumlah adalah
> dokumen yang diserahkan ke anggota dalam keadaan salah.

### 2.2 Panel Riwayat Titipan Pokok

Buka **Detail Pinjaman** T2 → panel *Riwayat Titipan Pokok*:

| Tanggal | Angsuran | Masuk | Dipakai | Saldo |
|---|---|---|---|---|
| … | angsuran ke-2 (dibayar 2.180.000) | 1.090.000 | — | 1.090.000 |

Baris yang tidak menggerakkan saldo (pembayaran pas) **tidak boleh muncul** —
panel ini menjelaskan pergerakan, bukan mendaftar transaksi.

Cara membuktikan ketiadaan: **hitung barisnya**. Angsuran terbayar di *Progres
Angsuran* ada 2, panel Titipan hanya berisi 1. Selisih satu itulah pembayaran
pas (angsuran ke-1, dibayar tepat 1.090.000) — dan ia memang tidak ikut.

> **Kerjakan §2.2 sebelum §2.** Setelah pembayaran 90.000, panel berisi dua
> baris: `Masuk 1.090.000 → saldo 1.090.000`, lalu `Dipakai 1.000.000 → saldo
> 90.000`. Yang tetap berlaku: angsuran ke-1 tak boleh muncul.

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

### 5.1 Setoran yang cukup melunasi: ditawarkan, bukan diproses diam-diam

*Petugas · Catat Pembayaran · anggota Bagus Prakoso (T6)*

| Langkah | Hasil yang diharapkan |
|---|---|
| Isi nominal **7.000.000**, Simpan | **Dialog pilihan muncul.** Belum ada yang tersimpan |
| Pilihan **Pelunasan Dipercepat** | Bayar **Rp 6.988.000**, pinjaman LUNAS. Menyebut jasa bulan sisa yang dibebaskan, dan kembalian yang masuk Simpanan Sukarela |
| Pilihan **Tetap mencicil** | Angsuran bulan ini lunas, sisanya jadi **Titipan Pokok**; menyebut bahwa jasa bulan sisa **tetap tertagih** |
| Pilih **Tetap mencicil** | Tersimpan. Pinjaman tetap **Cair**, tak ada baris pelunasan |

> Dulu keadaan ini **ditolak mentah**. Penolakan itu melindungi anggota dari
> membayar penuh sementara jasa bulan sisa yang seharusnya dibebaskan tetap
> ditagih — tapi ia juga melarang keadaan yang sah: anggota yang membawa uang
> lebih dan memang ingin pinjamannya berjalan terus. Petugas tak punya jalan
> selain menyuruhnya pulang. Sekarang **ditawarkan**, dengan akibat kedua pilihan
> tersaji dalam rupiah — sehingga anggota tak pernah memilih yang lebih mahal
> tanpa tahu ada yang lebih ringan.

Periksa angkanya: sisa pokok 8.000.000 + jasa 78.000 − titipan 1.090.000 =
**6.988.000**. Bila layar menyebut 8.078.000, potongan titipannya hilang.

**Yang HARUS gagal:** dialog ini tak boleh bisa dilewati. Kelebihan bayar yang
cukup melunasi tidak boleh tersimpan tanpa pilihan pernah ditampilkan.

### 5.2 Hasil pelunasan bertitipan (**T5**, sudah lunas di data uji)

*Petugas · anggota Hesti Prabaningrum. Hanya dilihat, tak ada yang diinput.*

> **Baris pelunasan tidak ada di tabel Progres Angsuran.** Ia dibuat tanpa
> jadwal (`schedule_id` null), sedangkan tabel itu digerakkan oleh jadwal —
> karena itu jadwal terakhir tampil *Terbayar* dengan kolom Dibayar `—`.
> Bukanya lewat **menu Angsuran** → cari *Hesti* → baris ber-**Dibayar 78.000**.

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
| Baris **Bagus Prakoso (T6)** — nominal angsuran | Tetap **1.090.000** |
> Nominalnya **angka kontrak**, bukan tagihan efektif. Payroll memotong sesuai
> kontrak; titipan T6 karena itu tidak bergerak. Yang terlihat 1.090.000 — bukan
> 90.000 — memang benar.

**Sebagai Pengurus** (kolom pelunasan disembunyikan dari Petugas — lihat §6.2):

| Langkah | Hasil yang diharapkan |
|---|---|
| Baris **Bagus Prakoso (T6)** — centang pelunasan | Tertulis **"pelunasan Rp 6.988.000, jasa sisa dibebaskan"** — sudah dikurangi titipan. Bila **8.078.000**, potongan titipannya hilang |
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
| Angka tunggakan di dashboard | Kartu **Tunggakan** menampilkan **cacahan jadwal** lewat tempo, bukan rupiah. Cacahan tak bisa terpengaruh titipan — titipan mengurangi nominal, tak pernah membuat jadwal berhenti menunggak. **Tak ada yang bisa dibandingkan; tandai N/A** |
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

> **Angka ini turunan, bukan tetapan.** Rumusnya `12.000 × jumlah baris angsuran
> terbayar (bukan baris pelunasan)`. Skenario yang sudah kamu jalankan menambah
> angsuran — §2 menambah satu ke T2, batch §6 menambah satu ke hampir semua.
> Hitung ulang dari *Progres Angsuran* tiap anggota, jangan cocokkan ke angka di
> atas. Yang **tidak** bergeser: SWP tetap 120.000 (1% pokok, dicatat sekali saat
> pencairan), dan **T5 tetap 132.000** — 11 angsuran, baris pelunasan tidak
> mengakru. Bila T5 jadi 144.000, itu kegagalan sungguhan.

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

### 9.3 Penarikan SWP

*Pengurus · Simpanan → Pencairan → Buat*

| Langkah | Hasil yang diharapkan |
|---|---|
| Pilih anggota Hesti | Baris sumber dana memuat **SWP** dan **Tabungan Berjangka**, dengan saldo di atas |
| Ajukan penarikan SWP 120.000 | Tersimpan sebagai **draft** |
| ACC lalu Cairkan | Saldo SWP jadi **0**; Tab. Berjangka tak tersentuh |

> **Celah yang diketahui, bukan temuan.** Aturan koperasi: SWP dikembalikan
> setelah anggota **keluar dari keanggotaan**. Itu belum ditegakkan karena jalur
> penutupan akun belum ada, jadi untuk sementara SWP bisa dicairkan kapan saja
> seperti di atas. Jangan dilaporkan sebagai bug — tapi ingat bahwa ini harus
> ditutup sebelum sistem dipakai sungguhan.

### 9.4 Tabungan Berjangka hanya boleh cair 1× setahun

Aturan koperasi: Tabungan Berjangka dikembalikan **satu kali dalam setahun**,
bersamaan pembagian SHU.

Patokannya bergantung pada **Bulan Pembagian SHU** di *Pengaturan → Pinjaman*:

| Setelan | Aturan yang berlaku |
|---|---|
| **Belum ditetapkan** | 12 bulan sejak pencairan terakhir tiap anggota. Longgar, dan waktunya berbeda-beda per orang |
| **Ditetapkan** (mis. Maret) | Hanya boleh dicairkan **di bulan itu**, dan **sekali per tahun** |

Anggota berstatus **Keluar / Meninggal selalu dikecualikan** — mereka butuh
haknya sekarang, bukan menunggu bulan SHU.

#### 9.4a Tanpa setelan bulan SHU

*Pengurus · Pengaturan → pastikan Bulan Pembagian SHU = "Belum ditetapkan"*

| Langkah | Hasil yang diharapkan |
|---|---|
| Cairkan Tab. Berjangka anggota mana pun (pertama kali) | **Berhasil** — belum pernah cair, tak ada yang menghalangi |
| Ajukan lagi untuk anggota yang sama | Di **formulir pengajuan** langsung muncul panel merah *"Tabungan Berjangka belum waktunya"* berisi tanggal cair terakhir dan tanggal boleh berikutnya. Pengajuan tetap boleh disimpan |
| Buka detail pengajuan kedua | Panel merah **"Belum waktunya dicairkan"** menetap di halaman. Tombol **Setujui (ACC)** dan **Cairkan Dana** **tidak ditampilkan** |
| Coba ACC lewat jalur lain | **DITOLAK** sejak ACC, bukan menunggu sampai Cairkan. Status tetap **draft** |

> Penolakannya dipindah ke depan. Sebelumnya pengajuan bisa di-ACC lalu baru
> ditolak saat dicairkan — pengurus mengerjakan persetujuan yang sejak awal
> mustahil, dan meninggalkan pengajuan ber-status *acc* yang menggantung
> selamanya. Sebabnya kini tertulis di halaman, bukan lewat notifikasi sekejap.

#### 9.4b Dengan setelan bulan SHU

*Pengurus · Pengaturan → set Bulan Pembagian SHU*

> **Pakai anggota yang belum pernah cair tahun ini.** Aturan jendela SHU punya
> DUA bagian: harus di bulan SHU, **dan** sekali per tahun kalender. Anggota yang
> sudah cair Agustus tetap ditolak di September walau bulan SHU digeser ke
> September — itu bagian "sekali setahun" yang bekerja, bukan setelan yang tak
> terbaca. Kalau §9.4a dijalankan lebih dulu, anggotanya sudah terpakai.

| Langkah | Hasil yang diharapkan |
|---|---|
| Set ke **bulan ini**, lalu cairkan Tab. Berjangka **anggota lain yang masih bersih** | **Berhasil** |
| Ajukan lagi di bulan yang sama, ACC & Cairkan | **DITOLAK**: *"…sudah dicairkan tahun ini …"* — jendela sebulan tak boleh dipakai dua kali |
| Set ke **bulan lain**, lalu coba cairkan anggota yang belum pernah cair | **DITOLAK**: *"…hanya dapat dicairkan pada bulan pembagian SHU (…). Jendela berikutnya: …"* |
| Anggota yang pencairan terakhirnya **13 bulan lalu**, sekarang bukan bulan SHU | **Tetap DITOLAK** — inilah bedanya. Aturan lama meloloskannya, jendela SHU tidak |

#### 9.4c Anggota Keluar / Meninggal

| Langkah | Hasil yang diharapkan |
|---|---|
| Ubah status anggota jadi **Keluar**, set bulan SHU ke bulan lain, lalu cairkan Tab. Berjangka-nya | **Berhasil** |
| Cek Log Aktivitas | **TIDAK ADA** jejak "Pencairan di Luar Jadwal" — ia memang tidak melanggar, bukan menembus |

**Yang paling penting diuji — Pengurus TIDAK boleh lolos begitu saja:**

| Login | Hasil yang diharapkan |
|---|---|
| **Pengurus** | Tetap **ditolak**. Kalau Pengurus lolos, aturannya tak mengikat siapa pun — hanya Pengurus yang bisa mencairkan |
| **super_admin** | **Berhasil**, dan di Log Aktivitas muncul peristiwa **"Pencairan Tabungan Berjangka sebelum genap setahun"** berisi tanggal cair terakhir dan tanggal boleh berikutnya |

| Pemeriksaan lain | Hasil yang diharapkan |
|---|---|
| Cairkan **Sukarela** dua kali berturut-turut | **Boleh** — jadwal setahun hanya berlaku untuk Tabungan Berjangka |
| Batalkan pencairan Tab. Berjangka, lalu ajukan lagi | **Boleh** — uangnya kembali, jadi jadwalnya ikut terbuka lagi |

> Izin tembusnya `bypass_time_deposit_schedule`, bawaannya **super_admin saja**.
> Bisa diberikan ke orang lain lewat *Sistem → Peran & Izin* — tapi itu keputusan
> yang diambil sadar, bukan sesuatu yang menempel otomatis pada jabatan.

### 9.5 Pembalikan harus berpasangan

| Langkah | Hasil yang diharapkan |
|---|---|
| Batalkan satu baris angsuran mana pun (mis. T1 angsuran #2) | Saldo Tab. Berjangka anggota **turun 12.000** |
| Buku mutasi | Muncul baris **"Pembatalan Tabungan Berjangka angsuran"** — baris aslinya tetap ada, dinetralkan, tidak dihapus |
| Batalkan sebuah **pinjaman** yang belum punya angsuran (salah input) | Saldo SWP anggota **turun 120.000**, dengan baris "Pembatalan potongan SWP" |

> Kalau saldo tidak ikut turun, salah ketik petugas menaikkan simpanan anggota
> secara permanen — pinjamannya tinggal catatan, uangnya tetap jadi saldo.

---

### 9.6 SWP & Tab Berjangka tidak boleh masuk Laporan Setoran

*Pengurus · Laporan → Laporan Simpanan*

| Langkah | Hasil yang diharapkan |
|---|---|
| Rentang yang memuat pembayaran angsuran anggota uji, basis **Periode Potong Gaji** | Total **tidak** memuat Tabungan Berjangka. Bandingkan dengan Laporan Angsuran — angka yang sama tak boleh muncul di dua laporan |
| Filter jenis simpanan | SWP & Tab Berjangka **tidak ditawarkan** — memang bukan bagian laporan ini |
| Daftar Setoran (Simpanan → Setoran) | Barisnya **ada** dan bisa difilter per jenis, dengan label "SWP (Simpanan Wajib Pinjaman)" dan "Tabungan Berjangka" — bukan tulisan mentah |
| Buka detail salah satu baris SWP | Tombol **Reversal tidak muncul**. Pembatalannya lewat pembatalan pinjaman |

---

## Bagian 10 — Pengaman hasil security review

### 10.1 Simpanan tak bisa dicetak dari layar setoran

*Petugas · Simpanan → Setoran → Buat*

Layar setoran hanya menawarkan Pokok, Wajib, Sukarela, Hari Raya, Wajib Belanja.
**SWP dan Tabungan Berjangka tidak ada di daftar** — keduanya hanya lahir dari
pencairan pinjaman dan pembayaran angsuran.

| Yang diperiksa | Hasil yang diharapkan |
|---|---|
| Daftar jenis di form setoran | Lima jenis, tanpa SWP & Tab Berjangka |

### 10.2 Setoran SWP/Tab Berjangka tak bisa dibatalkan sendiri

*Simpanan → Setoran → buka baris bertipe SWP*

| Login | Hasil yang diharapkan |
|---|---|
| **Petugas** | Tombol **Reversal tidak muncul** |
| **Pengurus** | Sama — tidak muncul juga |

Pembatalannya lewat jalur yang benar:

| Langkah | Hasil yang diharapkan |
|---|---|
| Batalkan **pinjamannya** | Setoran SWP ikut terbalik, saldo turun |
| Batalkan **angsuran** | Setoran Tab Berjangka bulan itu ikut terbalik |

### 10.3 Pinjaman tak bisa dibatalkan kalau SWP-nya sudah ditarik

| Langkah | Hasil yang diharapkan |
|---|---|
| Cairkan SWP anggota sampai saldonya 0, lalu batalkan pinjamannya | **DITOLAK**: *"SWP-nya … sudah ditarik anggota … Batalkan dulu pencairan SWP tersebut, baru pinjamannya."* |
| Cek saldo SWP anggota | Tetap **0**, tidak minus |
| Batalkan pencairan SWP-nya dulu, lalu batalkan pinjamannya | Sekarang **berhasil** |

> Tanpa penolakan ini saldo simpanan anggota jadi **minus** — dan total
> simpanannya ikut minus. Terverifikasi sebelum diperbaiki.

### 10.4 Rekonsiliasi

*Pengurus · Laporan → Rekonsiliasi Pinjaman*

| Yang diperiksa | Hasil yang diharapkan |
|---|---|
| Setelah seluruh skenario di atas dijalankan | **"Seluruhnya cocok"** — halaman kosong adalah hasil yang benar |
| Kolom | SWP dan Tab Berjangka, masing-masing *Tercatat / Seharusnya / Selisih* |
| Tombol **Periksa Ulang** | Ada, beserta cap waktu *"Diperiksa …"* di bawahnya. Klik → cap waktunya berubah |
| Login Petugas | **403** |

> Halaman ini membandingkan saldo tercatat dengan hitungan ulang dari data
> pinjaman. Kalau ia menampilkan baris, ada saldo yang tak bisa dijelaskan oleh
> pinjaman mana pun — itu yang perlu ditelusuri, apa pun sebabnya.

**Kapan sebaiknya dijalankan?** Perhitungannya dilakukan saat halaman dibuka,
jadi ia selalu menunjukkan keadaan **sekarang** — tidak ada proses terjadwal yang
perlu ditunggu. Yang membuat H+1 tetap masuk akal adalah kebiasaannya, bukan
mesinnya: dijalankan **pagi hari untuk memeriksa hari sebelumnya**, saat seluruh
transaksi kemarin sudah tutup dan pembatalan susulan sudah masuk. Memeriksa di
tengah hari kerja tetap sah, hanya saja selisih yang muncul bisa berupa transaksi
yang memang sedang berjalan.

> Cap waktu *"Diperiksa …"* ada karena halaman yang benar isinya kosong — dan
> halaman kosong tak bisa dibedakan dari halaman basi. Tanpa cap itu, pengurus
> yang baru memperbaiki sesuatu tak punya cara menyatakan "sudah, kan?" selain
> menebak apakah yang ia lihat masih hasil pemuatan setengah jam lalu.

### 10.5 Jejak bisa dicari

*Pengurus · Sistem → Log Aktivitas → filter Aksi*

| Yang diperiksa | Hasil yang diharapkan |
|---|---|
| Daftar filter Aksi | Memuat **"Pencairan di Luar Jadwal"**, "Pembatalan Ditolak", "Pembayaran Angsuran", "Batch Angsuran Potong Gaji", "Koreksi / Pembatalan" |
| Pilih "Pencairan di Luar Jadwal" | Menampilkan bypass yang dilakukan di §9.4 |

> Sebelumnya event-event ini tersimpan tapi tak ada di daftar filter — jejaknya
> ada, tapi tak bisa dipanggil siapa pun.

### 10.6 Izin khusus per pengguna

*super_admin · Sistem → Pengguna → edit seorang Pengurus*

| Yang diperiksa | Hasil yang diharapkan |
|---|---|
| Bagian **Izin Khusus** | Ada, memuat "Tembus Jadwal Tahunan Tabungan Berjangka" |
| Izin yang sudah jadi bawaan peran | **Tidak ditawarkan** di sini |
| Centang izin itu, simpan, lalu uji §9.4 sebagai Pengurus tersebut | Sekarang **lolos**, dan tercatat sebagai pencairan di luar jadwal |

> Berikan izin khusus **di sini**, bukan lewat layar Peran & Izin: izin yang
> ditambahkan ke sebuah peran akan tercabut sendiri saat pembaruan sistem
> berikutnya (seeder memakai `syncPermissions` pada peran).

---

## Cara melaporkan temuan

Sebutkan **kode skenario** (mis. `5.4`), **apa yang muncul di layar**, dan
**nomor angsuran / pinjaman** yang terlibat. Bila menyangkut angka, sertakan
angka yang muncul dan angka yang tertulis di dokumen ini — selisihnya yang
paling cepat menunjukkan letak salahnya.
