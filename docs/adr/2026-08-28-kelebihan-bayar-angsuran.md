# Penanganan Kelebihan Bayar Angsuran — Titipan Pokok & Tutup Angsuran Sekaligus

Kelebihan pembayaran angsuran tidak lagi dikreditkan ke Simpanan Sukarela. Bawaannya disimpan sebagai **Titipan Pokok** yang memotong pokok angsuran berikutnya; petugas boleh memilih **menutup angsuran berikutnya sekalian**; dan bila uangnya cukup melunasi seluruh sisa pinjaman, sistem mengarahkan ke **Pelunasan Dipercepat**.

**Author:** Gabriel Advent
**Date:** 2026-08-28
**Status:** Draft

---

## Background

Masukan dari sesi review bersama pengurus (Mbak Iin, poin 1):

> Jika anggota membayar angsuran melebihi nominal yang ditentukan, kelebihan pembayaran dialihkan sebagai pembayaran pokok pada angsuran berikutnya.

Perilaku saat ini ([`LoanPaymentService::creditOverpaymentToSukarela()`](../../app/Services/LoanPaymentService.php)) mengalihkan setiap kelebihan bayar ke **Simpanan Sukarela**, sehingga uang yang niat awalnya "titipan buat bulan depan" berubah jadi saldo simpanan yang harus ditarik lewat alur pencairan (draft → acc → cair).

> **Catatan kejujuran:** paragraf di atas adalah **inferensi penulis**, bukan keluhan yang dinyatakan Mbak Iin. Beliau menyebut sebuah aturan, bukan sebuah masalah. Motif sebenarnya masih perlu dikonfirmasi (lihat OQ-1); bila ternyata berbeda, yang perlu diperbaiki adalah paragraf ini, bukan desainnya.

### Ekspektasi ekonomi: fitur ini tidak menghemat apa pun

Jasa pinjaman di sistem ini **flat**, dihitung dari `principal_amount` dan konstan tiap bulan ([`LoanCalculator::monthlyConstants()`](../../app/Services/LoanCalculator.php)). Membayar pokok lebih awal **tidak menghemat jasa sepeser pun** selama jumlah angsuran tidak berubah. Fitur ini murni **keringanan arus kas + pengurangan kerja administrasi**. Total yang dibayar anggota seumur pinjaman sama persis.

Itu pula alasan kredit dibatasi ke **komponen pokok**: kalau kelebihan boleh menghapus satu jadwal tanpa baris angsuran, jumlah angsuran berkurang → jasa tertagih koperasi berkurang **dan** hak Tabungan Berjangka anggota hangus (akrualnya *count-based*, lihat [`Installment::scopeSignedTimeDeposit()`](../../app/Models/Installment.php)).

**"Tutup angsuran sekalian" tidak melanggar itu**: baris angsurannya tetap dibuat untuk setiap bulan yang ditutup, jadi jasa tetap tertagih dan Tabungan Berjangka tetap terakumulasi. Yang berkurang hanya jumlah kunjungan ke loket.

### Anggota potong gaji: titipan mengendap, dan itu bukan kerusakan

*(Koreksi atas v2 dan v3 — lihat Changelog.)*

Titipan hanya bergerak lewat `Δ = uang diterima − tagihan kontrak`. Payroll OPD selalu memotong **tepat sebesar tagihan kontrak**, sehingga `Δ = 0`: titipan **tidak bertambah dan tidak berkurang** lewat batch potong gaji.

Dua klaim di versi sebelumnya karena itu **salah dan dicabut**:

| Versi | Klaim | Kenyataan |
|---|---|---|
| v2 | Titipan potong-gaji **membengkak** tiap bulan | Tidak. Tetap. |
| v3 | Batch mode tutup-sekalian membuat titipan **terpakai**, anggota selesai lebih cepat | Tidak. Kedua mode identik di batch, kecuali bila titipan sudah ≥ satu tagihan penuh — kasus sempit. |

Yang benar-benar terjadi: anggota potong gaji yang sesekali menyetor manual dan kelebihan akan punya titipan yang **baru terpakai saat ia menyetor manual lagi**. Kalau tidak pernah, titipannya mengendap sampai pinjaman lunas lalu dilimpahkan ke Simpanan Sukarela.

Ini **keterbatasan yang diterima, bukan masalah yang dipecahkan**. Uangnya aman, hitungannya benar, kuitansinya rekonsiliasi. Yang tidak sampai hanyalah manfaat keringanannya.

**Konsekuensi desain: batch potong gaji tidak disentuh sama sekali.** Aturan khusus batch di v3 dicabut — ia lahir dari klaim yang keliru dan tidak memberi manfaat yang sepadan dengan jalur kode tambahannya.

---

## Goals

- Kelebihan bayar tersimpan sebagai **Titipan Pokok** pada pinjaman terkait, bukan Simpanan Sukarela.
- Tagihan angsuran berikutnya yang **ditampilkan dan divalidasi** otomatis dikurangi Titipan Pokok, maksimal sebesar komponen pokok bulan itu; sisanya mengalir ke bulan-bulan berikutnya.
- Petugas dapat memilih **menutup angsuran berikutnya sekalian**, dengan akibat ditampilkan dalam angka sebelum disimpan.
- Bila uang cukup melunasi **seluruh** sisa pinjaman, sistem mengarahkan ke **Pelunasan Dipercepat** — jalur yang lebih murah bagi anggota.
- Saldo kembali 0 begitu terpakai, dan **tetap benar setelah pembatalan angsuran**.
- Jumlah angsuran, total jasa tertagih, dan akrual Tabungan Berjangka **tidak berubah** oleh fitur ini.
- **Seluruh riwayat titipan dapat ditelusuri**: kapan masuk, kapan dipotong, berapa, dan kapan habis — dari kuitansi maupun dari panel riwayat.

## Non-Goals

- **Tidak** mengubah apa pun di jalur batch potong gaji.
- **Tidak** memperpendek tenor atau menggugurkan jasa/Tabungan Berjangka.
- **Tidak** menghapus produk Simpanan Sukarela. Sukarela tetap sumber dana wajib fitur Bayar Angsuran dari Simpanan ([ADR 2026-07-22](2026-07-22-bayar-angsuran-dari-simpanan.md), `DEBIT_SAVINGS_TYPE = 'sukarela'`). Yang dimatikan **hanya jalur kelebihan-bayar → Sukarela di tengah masa pinjaman**.
- **Tidak** melacak titipan per-lot (asal setoran mana). Titipan adalah **satu kantong**, bukan beberapa amplop terpisah — lihat Design.
- **Tidak** mengubah kunci `savingsMustEqualBill` maupun aturan pembebasan jasa pada Pelunasan Dipercepat.
- **Tidak** menyentuh masukan Mbak Yasmin (poin 2-4) — itu mencabut `createRefunds()` dari jalur pelunasan → **ADR terpisah**.
- **Tidak** mengerjakan [ADR Penutupan Akun Anggota](2026-07-13-penutupan-akun-anggota.md) — fitur itu belum diimplementasi dan sengaja dibiarkan. **Tapi ketergantungannya dicatat di sini** agar tidak hilang: ADR itu melunasi pinjaman lewat `pay()`, sehingga saat dikerjakan nanti ia wajib (a) menghitung sisa kewajiban lewat `payoffAmount()`, bukan menjumlahkan `total_due` jadwal — kalau tidak, simpanan anggota bertitipan didebit melebihi kewajibannya; dan (b) membaca ulang saldo Sukarela **setelah** langkah settle, karena pelimpahan sisa titipan terjadi tepat di situ — kalau dihitung sebelumnya, uang pelimpahan tertinggal di rekening anggota yang sudah ditutup.
- **Tidak** memigrasi data lama. Setoran Sukarela hasil kelebihan bayar sudah sah jadi uang anggota; dibiarkan.

---

## Design

### Satu mesin, empat pintu

Enumerasi menyeluruh: **hanya ada 2 tempat di seluruh sistem yang membuat baris angsuran**, keduanya di dalam `LoanPaymentService` ([`:106`](../../app/Services/LoanPaymentService.php), [`:197`](../../app/Services/LoanPaymentService.php)). Tidak ada perintah terjadwal, tidak ada seeder, dan **tidak ada endpoint API** — `routes/api.php` hanya melayani integrasi toko (`/api/v1/store/*`).

| # | Pintu | Perlu diubah? |
|---|---|---|
| 1 | [`InstallmentForm`](../../app/Livewire/Loan/Installment/InstallmentForm.php) (Livewire, manual) | **Ya** — prefill `:251`, anti-korupsi `:336`, dialog konfirmasi |
| 2 | [`InstallmentResource`](../../app/Filament/Resources/InstallmentResource.php) + `CreateInstallment` (Filament, manual) | **Ya** — prefill `:108`, validasi `:236`, helper `:233`, label `:264` |
| 3 | [`BatchInstallmentPayment`](../../app/Livewire/Loan/Installment/BatchInstallmentPayment.php) (Livewire, batch) | **Tidak** |
| 4 | [`Filament/Pages/BatchInstallmentPayment`](../../app/Filament/Pages/BatchInstallmentPayment.php) (Filament, batch) | **Tidak** |

Batch aman tanpa perubahan karena payroll selalu membayar tagihan kontrak, yang **selalu ≥ tagihan efektif** — lantai `:205` dan prefill `:244` tetap benar.

> **Syarat yang harus dijaga:** ini berlaku selama payroll memotong angka kontrak. Bila suatu saat koperasi ingin memberi tahu bendahara OPD agar memotong angka yang sudah dikurangi titipan, lantai di [`BatchInstallmentPaymentService:205`](../../app/Services/BatchInstallmentPaymentService.php) **harus ikut diubah** — sekarang ia menolak setoran di bawah `total_due`.

Aturan kebenaran uang ditegakkan **di mesin**, bukan di pintu: pintu yang lupa diperbaiki akan ditolak mesin, bukan diam-diam salah hitung.

### Rumus inti

```
titipan_sesudah  = titipan_sebelum + (uang diterima − tagihan KONTRAK)
credit_applied   = max(0, tagihan KONTRAK − uang diterima)
tagihan_efektif  = tagihan KONTRAK − min(titipan, principal_due)
```

**Jebakan yang wajib dihindari:** selisihnya diambil terhadap **tagihan kontrak**, bukan tagihan efektif. Memakai tagihan efektif menghitung ganda titipan yang sudah dipotong, dan saldonya membengkak tiap bulan. Ini bukan kesalahan hipotetis — itu persis kekeliruan tabel v2.

Bukti kebenarannya lewat tiga kasus:

| Kasus | Uang | Kontrak | Δtitipan | `credit_applied` |
|---|---|---|---|---|
| Bayar pas | 1.050.000 | 1.050.000 | 0 | 0 |
| Batch potong gaji (titipan ada) | 1.050.000 | 1.050.000 | **0** ✓ | 0 |
| Bayar tagihan efektif (titipan 1.000.000) | 50.000 | 1.050.000 | −1.000.000 | **1.000.000** ✓ |
| Kelebihan bayar | 2.100.000 | 1.050.000 | +1.050.000 | 0 |

Karena `credit_applied` seluruhnya turunan dari `(kontrak − amount_paid)`, kolom simpanannya **konsisten dengan saldo secara konstruksi** — dan itu memberi invariant yang mudah diuji: `credit_applied == max(0, monthly_total − amount_paid)` pada setiap baris.

Saldo berjalan tetap **diturunkan**, tidak disimpan:

```
titipan(loan) = Σ_signed(amount_paid) − netCount × monthly_total
```

memakai konvensi tanda [`Loan::settledPrincipal()`](../../app/Models/Loan.php); baris `is_settlement = 1` dikecualikan; pinjaman **Lunas** bernilai **0**.

### Dua lapis penyimpanan

| | Bentuk | Alasan |
|---|---|---|
| **Saldo berjalan** | Diturunkan dari riwayat angsuran | Otomatis benar setelah pembatalan; tak ada kolom yang bisa menyimpang |
| **`credit_applied` per baris** | Kolom, **ditulis sekali saat pembuatan, tak pernah di-`UPDATE`** | Jejak audit; pemeriksa bisa membaca satu baris tanpa menghitung ulang riwayat |

`credit_applied` **tidak melanggar** pola buku besar tak-berubah — ia fakta per-transaksi yang ditulis sekali, seperti `amount_paid` yang juga disimpan padahal bisa dihitung.

### Aturan alokasi

```
1. Uang cukup melunasi SELURUH sisa pinjaman?
   → BERHENTI. Arahkan ke Pelunasan Dipercepat.

2. Tutup angsuran berjalan.

3. Sisa uang:
   ├─ BAWAAN → seluruh sisa jadi Titipan Pokok.
   └─ Petugas memilih "tutup sekalian" → ulangi langkah 2 selama
      sisa uang ≥ tagihan efektif angsuran berikutnya.

4. Sisa akhir → Titipan Pokok.
```

Bawaan Titipan Pokok karena itu bunyi asli permintaan Mbak Iin. Batch memakai bawaan yang sama — tidak ada aturan khusus, dan tidak ada dampak karena `Δ = 0`.

**Aturan pencatatan:** dalam satu setoran yang menutup beberapa angsuran, **baris terakhir menyerap sisa uang**. Contoh: setor 3.000.000, tagihan 1.050.000 → baris #2 = 1.050.000, baris #3 = 1.950.000.

### Rumus benar di kedua mode

Pinjaman 5 bulan, tagihan 1.050.000 (pokok 1.000.000 + jasa 50.000). Angsuran #1 lunas normal; di #2 anggota setor 2.100.000:

| Mode | Baris tercatat | Σ dibayar | netCount | × 1.050.000 | Titipan |
|---|---|---|---|---|---|
| **Titipan** *(bawaan)* | #2 = 2.100.000 | 3.150.000 | 2 | 2.100.000 | **1.050.000** |
| **Tutup sekalian** | #2 = 1.050.000, #3 = 1.050.000 | 3.150.000 | 3 | 3.150.000 | **0** |

Total uang sama (5.250.000) dan total jasa sama (5×) pada kedua jalur — yang berbeda hanya bentuk tagihan ke depan.

Contoh mengalir lintas bulan pada mode Titipan (setor 3.000.000 di #2):

| Angsuran | Tagihan kontrak | Titipan sebelum | Tagihan efektif | Setor | `credit_applied` | Titipan sesudah |
|---|---|---|---|---|---|---|
| 2 | 1.050.000 | 0 | 1.050.000 | **3.000.000** | 0 | 1.950.000 |
| 3 | 1.050.000 | 1.950.000 | **50.000** | 50.000 | 1.000.000 | 950.000 |
| 4 | 1.050.000 | 950.000 | **100.000** | 100.000 | 950.000 | 0 |
| 5 | 1.050.000 | 0 | 1.050.000 | 1.050.000 | 0 | 0 |

Σ = 5.250.000 ✓

### Konfirmasi di loket

Dialog **hanya muncul bila sisa uang ≥ tagihan efektif angsuran berikutnya**. Pembulatan biasa tidak memunculkan apa pun.

> **Uang diterima Rp 2.100.000 — melebihi tagihan bulan ini (Rp 1.050.000).**
>
> ◉ **Simpan sebagai Titipan Pokok** — *bawaan*
>  Angsuran **#2 lunas**. Sisa Rp 1.050.000 memotong pokok bulan berikutnya:
>  angsuran #3 → **Rp 50.000**, angsuran #4 → **Rp 1.000.000**.
>
> ○ **Tutup sekalian angsuran berikutnya**
>  Angsuran **#2 dan #3 lunas**. Tidak ada sisa.
>  Bulan depan: angsuran #4, Rp 1.050.000.

**Akibat wajib ditampilkan dalam angka**, bukan hanya nama pilihan.

> **Jangan disederhanakan belakangan.** Tiga tampilan — dialog berakibat-angka, kuitansi ber-"Titipan dipakai / Sisa Titipan", dan panel Riwayat Titipan Pokok — **adalah** kanal penjelasan mekanisme ini. Tagihan yang berubah-ubah ("bulan ini 50.000, bulan depan 1.000.000") bukan konsep koperasi yang bisa diturunkan petugas dari pengetahuan mereka; itu akibat keputusan desain di ADR ini, jadi sistem yang wajib menjelaskannya, bukan petugas yang wajib menghafalnya. Dialog dua-tombol tanpa angka memang lebih murah dibuat — tapi mencabutnya berarti mencabut satu-satunya penjelasan yang petugas punya di depan anggota, sekaligus melemahkan pengaman yang jadi dasar penerimaan R14.

### Penjaga Pelunasan Dipercepat

| | 3 bulan tersisa |
|---|---|
| Tutup sekalian | 3 pokok + **3 jasa** = 3.150.000 |
| Pelunasan Dipercepat | 3 pokok + **1 jasa** = 3.050.000 ([`settleEarly()`](../../app/Services/LoanPaymentService.php)) |

Bila setoran cukup melunasi seluruh sisa dan diproses diam-diam sebagai "tutup sekalian", **anggota membayar jasa lebih banyak tanpa ada yang sadar**. Pengecekan ini karena itu berjalan **paling awal**, mengalahkan bawaan maupun pilihan petugas.

**Ambangnya harus eksplisit:** `uang diterima ≥ payoffAmount()` — yaitu jumlah pelunasan yang **sudah dikurangi titipan**. Dibandingkan terhadap sisa kontrak mentah, anggota bertitipan tak akan pernah terdeteksi dan justru kehilangan pembebasan jasanya.

**Penjaga ini kedap, dan bisa dibuktikan.** Menutup seluruh `k` angsuran sisa berharga `k × tagihan`, sedangkan pelunasan berharga `k × pokok + 1 × jasa`. Karena `k × tagihan = k×pokok + k×jasa + k×tab`, selisihnya `(k−1)×jasa + k×tab ≥ 0` untuk setiap `k ≥ 1`. Artinya **setiap nominal yang mampu menutup semua angsuran pasti sudah melewati ambang pelunasan lebih dulu** — tidak ada celah nominal di mana anggota terlanjur membayar jasa berlebih.

### Idempotensi setoran multi-baris

`idempotency_key` pada `installments` bersifat **UNIQUE** ([`create_installments_table:14`](../../database/migrations/2026_06_14_090010_create_installments_table.php)). Satu setoran yang menutup N angsuran membutuhkan N kunci berbeda.

Kuncinya **diturunkan secara pasti dari satu kunci sesi**, bukan digenerate acak per baris:

```
kunci_baris = kunci_sesi + "-" + urutan   (1, 2, 3, …)
```

- Acak per baris → klik simpan dua kali menghasilkan 4 baris, perlindungan idempotensi **hilang**.
- Diturunkan → klik kedua menghasilkan kunci yang sama persis, ditolak indeks unik. Persis fungsi yang diinginkan.

> **Konsekuensi schema yang terlewat sampai implementasi 0a:** `idempotency_key` dideklarasikan `$table->uuid()` — yaitu `char(36)`, pas persis satu UUID. Kunci turunan `"<uuid>-1"` panjangnya 38 karakter, jadi **tidak muat**: MySQL mode ketat menolaknya. Kolomnya karena itu dilebarkan jadi `varchar(64)` di migrasi yang sama, indeks UNIQUE-nya tetap. Alternatif yang dipertimbangkan — UUIDv5 deterministik dari `(kunci_sesi, urutan)` — ditolak karena kuncinya jadi tak terbaca mata dan keterkaitan sesinya hilang dari kunci itu sendiri, padahal sifat deterministiknya sama saja.

Kunci sesi juga disimpan sebagai **penanda sesi** pada tiap baris, dipakai untuk menampilkan keterkaitan antar transaksi (lihat Pembatalan). Tidak dipakai untuk memaksa pengelompokan.

### Pembatalan angsuran

**Pembatalan tetap per-transaksi.** Setiap baris angsuran punya nomor transaksinya sendiri (`ANG-…`); memaksa satu sesi dibatalkan sepaket akan menciptakan konsep baru yang sistem ini belum punya. Petugas memilih transaksi mana yang dibatalkan, seperti sekarang.

Layar pembatalan **menampilkan keterkaitan sesi** — misal *"ANG-0012 — satu setoran bersama ANG-0013"* — agar petugas tidak membatalkan separuh penerimaan tunai tanpa sadar ada pasangannya. Memberi tahu, bukan memaksa.

Bila hanya satu dari dua dibatalkan, hasilnya angsuran #3 lunas tapi #2 belum. Sistem kuat menghadapinya: pinjaman kembali Cair dan form berikutnya otomatis menawarkan #2 (FIFO). Bukan keadaan rusak, tapi harus terlihat.

**Membatalkan setoran yang membuat titipan mengembalikan seluruhnya** — uang kembali penuh dan titipannya ikut hilang — **kecuali titipannya sudah terpakai**. Bila sudah, saldo akan minus, dan **guard presisi** di `reverse()` menolak sambil menyebut **nomor angsuran penghalang** yang harus dibatalkan lebih dulu. Guard hanya menggigit bila titipan memang pernah ada dan sudah terpakai.

`credit_applied` **wajib ikut disalin di [`Installment::reverseClone()`](../../app/Models/Installment.php)** — daftar di sana adalah allowlist eksplisit, dan komentarnya sudah mendokumentasikan persis kelas bug ini untuk `is_settlement`: *"tanpa ini, reverse tak pulih benar."*

### Bukti setoran

- **Setoran yang menutup beberapa angsuran:** bukti dilampirkan ke **setiap baris**. Filenya foto kecil; duplikasinya tak berarti bagi penyimpanan, tapi tiap baris jadi berdiri sendiri saat diperiksa. Bukti yang hanya menempel di baris pertama membuat baris kedua tampak tanpa bukti di mata pemeriksa.
- **Setoran yang memotong pokok:** buktinya harus lebih rinci — lihat Kuitansi.

**Mekanisme lampirannya tidak boleh disalin dari pola yang ada.** [`LoanPaymentService:120`](../../app/Services/LoanPaymentService.php) sekarang memakai `$installment->addMedia($bukti)->toMediaCollection('bukti')`. `addMedia()` **memindahkan** file sumbernya — untuk `UploadedFile`, berkas sementaranya habis setelah panggilan pertama, sehingga **panggilan kedua untuk baris berikutnya gagal** dan seluruh transaksi batal.

Dua jalan yang sah: `->preservingOriginal()` pada setiap lampiran, atau simpan unggahannya sekali ke disk lalu lampirkan per baris lewat `addMediaFromDisk()` — pola yang sudah dipakai batch di [`:238`](../../app/Services/BatchInstallmentPaymentService.php), dan yang juga butuh `preservingOriginal()` bila dipakai berulang untuk berkas yang sama.

### Kuitansi

[`Installment::breakdown()`](../../app/Models/Installment.php) menghitung rincian dari konstanta pinjaman. Dengan tagihan efektif, kuitansi jadi tidak konsisten dengan dirinya sendiri (komponen berjumlah 1.050.000 tapi total tertulis 50.000).

Kuitansi wajib memuat baris tambahan. Untuk baris yang **memakai** titipan:

```
Piutang SP                      1.000.000
Bunga SP                           50.000
Titipan Pokok dipakai          −1.000.000
─────────────────────────────────────────
Total diterima                     50.000

Sisa Titipan Pokok                      0   ← habis di setoran ini
```

**"Titipan Pokok dipakai"** menjawab *berapa yang dipotong*; **"Sisa Titipan Pokok"** menjawab *kapan habis* (begitu 0, berarti habis di setoran itu). Angka pertama dibaca dari `credit_applied`, bukan dihitung ulang.

**Arah sebaliknya wajib ikut ada.** Baris yang justru *menyisihkan* titipan — mis. baris terakhir sebuah setoran multi-angsuran yang menyerap sisa — juga harus menutup. Setoran 3.000.000 mode tutup-sekalian, baris #3 tercatat 1.950.000:

```
Piutang SP                     1.000.000
Bunga SP                          50.000
Titipan Pokok disisihkan        +900.000
────────────────────────────────────────
Total diterima                 1.950.000

Sisa Titipan Pokok               900.000
```

Tanpa baris **"Titipan Pokok disisihkan"** komponen hanya berjumlah 1.050.000 sementara totalnya 1.950.000. "Sisa Titipan Pokok" adalah **saldo**, bukan komponen — ia tidak ikut menjumlah dan tidak bisa menggantikan baris ini.

Aturan umumnya, dan ini yang wajib dipegang implementasi:

```
dipakai      = max(0, tagihan kontrak − dibayar)   → tampil negatif
disisihkan   = max(0, dibayar − tagihan kontrak)   → tampil positif
```

Persis satu dari keduanya bisa bukan-nol pada satu baris, sehingga kuitansi selalu menutup. `credit_applied` NULL pada baris lama (sebelum fitur ini) diperlakukan sebagai **0** — baik oleh `breakdown()` maupun oleh rumus saldo.

### Panel Riwayat Titipan Pokok

Di halaman detail pinjaman, satu tabel kronologis:

| Tanggal | Transaksi | Masuk | Dipakai | Saldo |
|---|---|---|---|---|
| 12/03 | ANG-0012 | 1.050.000 | — | 1.050.000 |
| 12/04 | ANG-0018 | — | 1.000.000 | 50.000 |
| 12/05 | ANG-0024 | — | 50.000 | **0** |

Menjawab *kapan masuk, kapan dipotong dan berapa, kapan habis* — seluruhnya dihitung dari riwayat angsuran yang sudah ada, tanpa mesin tambahan.

**Keputusan: tidak ada pelacakan per-lot.** Bila titipan berasal dari beberapa setoran, sistem tidak mencatat potongan ini "berasal dari setoran yang mana". Titipan adalah satu kantong, bukan beberapa amplop; tabel di atas sudah menjawab semua pertanyaan praktis, sementara pelacakan per-lot menuntut mesin FIFO-lot yang jauh lebih berat.

### Jejak log

Mesinnya sudah ada — `Installment` memakai `LogsActivity` dan `pay()` sudah menulis event `angsuran`. Yang ditambah adalah isi propertinya:

| Kejadian | Yang dicatat |
|---|---|
| Setoran | mode (`titipan` \| `tutup_sekalian`), jumlah angsuran ditutup, `credit_applied`, saldo titipan **sebelum** dan **sesudah**, kunci sesi |
| Titipan habis | ditandai pada properti setoran yang menghabiskannya — tidak perlu event terpisah |
| Pembatalan | transaksi yang dibalik, saldo titipan sebelum & sesudah |
| Pembatalan **ditolak** guard | nomor angsuran penghalang — agar terlihat siapa mencoba apa dan kenapa gagal |
| Pelimpahan ke Sukarela saat lunas | nilai yang dilimpahkan + angsuran penutupnya |

### Kunci transaksi

Tagihan efektif dan hasil alokasi **wajib dihitung ulang di dalam transaksi setelah `lockForUpdate()`** — tidak boleh dipercaya dari payload Livewire maupun dari pratinjau dialog.

Pratinjau bisa basi (petugas membuka form, pembayaran lain masuk, petugas menyimpan). Bila alokasi final berbeda dari yang dikonfirmasi, transaksi **ditolak** dengan pesan minta ulangi — bukan disimpan diam-diam dengan hasil berbeda.

**Yang dibandingkan adalah saldo titipan**, bukan bentuk alokasinya: form mengirim balik saldo titipan yang dipakai saat pratinjau dihitung, lalu service membandingkannya dengan saldo di dalam kunci. Berbeda → tolak. Ini pemeriksaan versi, bukan pemeriksaan bentuk — membandingkan jumlah baris saja bisa diakali payload yang mengaku alokasi apa pun.

### Hak akses

Pemilihan mode (`titipan` \| `tutup_sekalian`) adalah **wewenang Petugas**, sama seperti mencatat angsuran biasa. Alasannya: menutup dua angsuran = mencatat dua angsuran, dan jumlahnya dibatasi oleh uang yang benar-benar diterima — bukan oleh kewenangan. Tidak ada penambahan daya rusak, jadi tidak ada permission baru.

Yang tetap Pengurus: pembatalan angsuran (`reverse_loan`) dan pencairan Simpanan Sukarela — keduanya tidak berubah oleh ADR ini.

### Saat pinjaman lunas

`creditOverpaymentToSukarela()` **tidak dihapus — dipindah pemicunya**, dari "setiap kelebihan bayar" jadi "sisa titipan saat pinjaman ditutup".

- **Lunas normal:** sisa titipan dikreditkan ke Sukarela, ditautkan ke angsuran penutup — mesin pembalikannya sudah ada di [`:284`](../../app/Services/LoanPaymentService.php).
- **Pelunasan Dipercepat:** `payoff` dikurangi titipan yang belum terpakai, dan **`credit_applied` ditulis di baris pelunasan** agar jejak audit tidak putus justru di transaksi terbesar.
- **Sebrakan (`jangka_pendek`):** hanya 1 jadwal — langsung ke jalur lunas.
- **Invariant:** *pinjaman Lunas selalu bertitipan 0.* Terverifikasi `LoanStatus::Lunas` hanya di-set di `LoanPaymentService`. Ini **pernyataan, bukan turunan** — wajib dijaga test.
- **Pinjaman Dibatalkan juga bertitipan 0** — guard diperluas ke `Lunas` **atau** `Dibatalkan`. Hari ini aman secara konstruksi: [`LoanResource::canCorrect()`](../../app/Filament/Resources/LoanResource.php) mensyaratkan `! hasPayments()`, jadi pinjaman yang dibatalkan tidak punya angsuran sama sekali → titipan 0 dengan sendirinya. **Tapi ini ketergantungan yang menanggung beban:** pembatalan menghapus seluruh jadwal angsuran ([`Loans.php:143`](../../app/Livewire/Loan/Loans.php)), sehingga bila guard `hasPayments` suatu saat dilonggarkan, titipan pada pinjaman dibatalkan jadi **tak terlihat dan tak bisa diambil siapa pun** — tak ada jadwal untuk memakannya, dan tak ada pelimpahan. Guard eksplisit + test penjaga jauh lebih murah daripada menemukannya belakangan.

### Verifikasi asumsi

Rumus bergantung pada `total_due` seragam di semua baris jadwal. **Terverifikasi:** jadwal dibuat di dua tempat ([`LoanForm:249`](../../app/Livewire/Loan/LoanForm.php), [`CreateLoan:66`](../../app/Filament/Resources/LoanResource/Pages/CreateLoan.php)), keduanya lewat `buildSchedule()`, dan **tidak ada jalur yang meng-update baris jadwal setelah dibuat**. Konstanta `monthly_*` dikunci saat akad oleh hook `Loan::booted()`.

### Alternatives Considered

| Alternative | Pro | Con | Verdict |
|---|---|---|---|
| **Kolom saldo di `loans`, di-`UPDATE`** | Persis permintaan awal; query murah | Saat angsuran lama dibatalkan setelah titipannya terpakai, kolom jadi minus tanpa jejak dan **bocor diam-diam** | **Rejected** |
| **Murni turunan, tak ada yang disimpan** (v1) | Nol migrasi | Melemahkan `belowBill()` berlabel "Anti-korupsi total-level" — pemeriksa tak bisa memverifikasi tanpa menghitung ulang seluruh riwayat | **Rejected** |
| **Saldo turunan + `credit_applied` per baris** | Benar setelah pembalikan; jejak audit; konsisten dengan saldo secara konstruksi | Satu migrasi kolom | **Chosen** |
| **Hanya Titipan Pokok** | Cakupan kecil | Anggota yang mau bayar 2 bulan sekaligus dapat angka ganjil dan tetap harus datang lagi bulan depan | **Rejected** |
| **Hanya "tutup angsuran sekaligus"** | Tak ada saldo mengambang | Kelebihan receh (pembulatan) tak punya tempat — itu justru kasus asli Mbak Iin | **Rejected** |
| **Keduanya, bawaan "tutup sekalian"** | Saldo mengambang paling sedikit | Bukan bunyi permintaan asli | **Rejected** |
| **Keduanya, bawaan Titipan Pokok** | Sesuai permintaan asli; kasus bayar-beberapa-bulan tetap terlayani | Titipan jadi jalur umum, pengamannya jadi beban inti | **Chosen** |
| **Aturan khusus batch (mode tutup-sekalian)** — v3 | — | Didasari klaim keliru; `Δ = 0` membuatnya nyaris tanpa efek, tapi menambah jalur kode & matriks test | **Rejected** — dicabut di v4 |
| **Pembatalan sesi sepaket (grup)** | Satu penerimaan tunai = satu pembalikan | Menciptakan konsep "sesi" yang sistem belum punya; tiap baris sudah punya nomor transaksi sendiri | **Rejected** — diganti penanda sesi yang informatif |
| **Pelacakan titipan per-lot** | Bisa menyebut potongan berasal dari setoran mana | Mesin FIFO-lot; panel riwayat sudah menjawab semua pertanyaan praktis | **Rejected** |
| **Kelebihan menghapus jadwal tanpa baris angsuran** | Anggota hemat jasa | Jasa koperasi berkurang **dan** hak Tab. Berjangka hangus | **Rejected** |
| **Kolom saldo di `members`** | Kredit lintas pinjaman | Titipan pinjaman A menyasar pinjaman B tanpa diminta | **Rejected** |
| **Wajib batalkan dari yang terbaru (LIFO) selalu** | Aturan satu kalimat | Mengekang pembatalan pada pinjaman yang tak pernah bertitipan | **Rejected** — dipersempit jadi guard presisi |
| **Feature flag via `CooperativeSettings`** | Bisa dimatikan tanpa deploy | Menggandakan jalur kode & matriks test untuk satu koperasi tunggal | **Rejected** |

---

## Rollout Plan

| Phase | Behavior | Status |
|---|---|---|
| 0 | Baseline — semua kelebihan bayar → Simpanan Sukarela | — |
| 1 | Migrasi `credit_applied` + kunci sesi; perilaku belum berubah | **In Progress** — item 0a Done, menunggu OQ-1/OQ-2 |
| 2 | Titipan Pokok + tutup-sekalian + penjaga Pelunasan Dipercepat aktif | Pending |
| 3 | Observasi 1 siklus penggajian penuh + rekonsiliasi | Pending |

**Catatan jujur:** pemisahan migrasi **tidak** membuat rollback Phase 2 → 1 sepenuhnya bersih — kolom sudah terisi dan `breakdown()` versi lama akan mengabaikannya, sehingga kuitansi angsuran tersebut kembali tidak rekonsiliasi. Rollback perilaku harus disertai keputusan sadar soal kuitansi yang sudah terbit.

### Phase Transition Checklist

**Phase 0 → 1:**
- [ ] **OQ-1 dijawab Mbak Iin** — motif sebenarnya, untuk memperbaiki Background
  <!-- source: manual -->
- [ ] **OQ-2 dijawab Mbak Iin** — cakupan "hide sukarela": jalurnya saja, bukan produk simpanannya
  <!-- source: manual -->
- [x] Migrasi `credit_applied` nullable, aman untuk baris lama
  <!-- source: code | query: grep credit_applied database/migrations | threshold: nullable -->

**Phase 1 → 2:**
- [ ] Suite hijau penuh termasuk 4 file test lama yang ditulis ulang
  <!-- source: code | query: php artisan test | threshold: 0 failed -->
- [ ] Invariant `credit_applied == max(0, monthly_total − amount_paid)` lulus di seluruh baris
  <!-- source: code | query: php artisan test --filter=CreditApplied | threshold: 0 failed -->
- [ ] `security-reviewer` menyetujui pelemahan lantai `belowBill()` dengan kompensasi `credit_applied`
  <!-- source: manual -->
- [ ] `deploy-reviewer` menyetujui urutan migrasi-lalu-perilaku, termasuk rollback yang tidak bersih
  <!-- source: manual -->

**Phase 2 → 3:**
- [ ] Tidak ada error pada jalur `pay` / `reverse` / `settleEarly` sejak deploy
  <!-- source: flare | query: search_errors LoanPaymentService | threshold: count = 0 -->
- [ ] Uji petik: pinjaman bertitipan → total pokok terkumpul = `principal_amount`
  <!-- source: manual -->
- [ ] Uji petik: kuitansi bertitipan — komponen berjumlah sama dengan total
  <!-- source: manual -->
- [ ] Regresi batch: satu siklus potong gaji berjalan tanpa perubahan perilaku
  <!-- source: manual -->
- [ ] Konfirmasi pengurus: dialog konfirmasi dipahami, tidak sekadar diklik
  <!-- source: manual -->

---

## Key Items

| # | Item | Effort | Parallel? | Status |
|---|---|---|---|---|
| 0a | Migrasi: `credit_applied` (decimal 18,2, nullable) + `session_key` (nullable, index) di `installments`; **`idempotency_key` dilebarkan `char(36)` → `varchar(64)`** agar kunci turunan muat (lihat Idempotensi) | S | ✅ | **Done** |
| 1a | `Loan::overpaymentCredit()` — saldo turunan + guard status **Lunas atau Dibatalkan** → `0.00` | S | ✅ | Pending |
| 1b | `Loan::effectiveBill(InstallmentSchedule)` — **satu-satunya sumber** tagihan efektif | S | setelah 1a | Pending |
| 1c | `Loan::payoffAmount()` — **satu-satunya sumber** jumlah pelunasan, dikurangi titipan; cabut rumus duplikat di `settleEarly()` dan `BatchInstallmentPaymentService:192` | M | setelah 1a | Pending |
| 1d | `LoanPaymentService::allocate()` — alokasi bertingkat (deteksi pelunasan → tutup angsuran → sisa jadi titipan), mode `titipan` \| `tutup_sekalian` | L | setelah 1b, 1c | Pending |
| 1e | `LoanPaymentService::pay()` — `belowBill()` pakai tagihan efektif **di dalam lock**; N baris via `allocate()`; baris terakhir menyerap sisa; `credit_applied = max(0, kontrak − dibayar)`; kunci sesi berurut; hapus kredit-ke-Sukarela di tengah masa pinjaman | L | setelah 1d, 0a | Pending |
| 1f | Tolak transaksi bila **saldo titipan** saat pratinjau ≠ saldo di dalam kunci (pratinjau basi) — pemeriksaan versi, bukan bentuk | M | setelah 1e | Pending |
| 1g | Lampirkan bukti ke **setiap** baris dalam satu sesi — wajib `preservingOriginal()` atau simpan-sekali-lalu-lampirkan-dari-disk; `addMedia($uploadedFile)` polos **gagal di baris kedua** | M | setelah 1e | Pending |
| 2f | Angka tagihan/tunggakan pakai tagihan efektif, bukan `total_due` — `SavingsStatsOverview:81` (agregat), `OverdueInstallmentsTable:52` (per baris), `SendInstallmentReminders:134` (isi pengingat ke petugas) | M | setelah 1b | Pending |
| 2g | `InstallmentDetail::auditFieldLabel()` (`:78`) & `formatAuditFieldValue()` (`:98`) — daftarkan `credit_applied` dan `session_key`; tanpa ini jejak audit tampil dengan nama kolom mentah dan angka tak terformat | S | setelah 0a | Pending |
| 2h | `InstallmentDetail::remainingAfter()` (`:112`) — ubah jadi berbasis **jumlah** angsuran bersih agar sejalan dengan `settledPrincipal()`; koreksi komentar `:112-114` yang mengklaim konsistensi tanpa menyebut asumsi "tanpa lubang". **Perbaikan bug lama yang menumpang — lihat OQ-9** | M | ✅ | Pending |
| 1h | `LoanPaymentService` — limpahkan sisa titipan ke Sukarela saat pinjaman jadi Lunas, tautkan ke angsuran penutup | M | setelah 1e | Pending |
| 1i | `LoanPaymentService::settleEarly()` — pakai `payoffAmount()`; tulis `credit_applied` di baris pelunasan | M | setelah 1c | Pending |
| 1j | `LoanPaymentService::reverse()` — guard tolak pembatalan yang membuat titipan negatif; pesan menyebut nomor angsuran penghalang | M | setelah 1a | Pending |
| 1k | `Installment::reverseClone()` — salin `credit_applied` dan `session_key` | S | setelah 0a | Pending |
| 1l | `Installment::breakdown()` — baris "Titipan Pokok dipakai" (−) **dan "Titipan Pokok disisihkan" (+)**, plus saldo "Sisa Titipan Pokok"; komponen wajib rekonsiliasi di **kedua** arah; `credit_applied` NULL diperlakukan 0 | M | setelah 0a | Pending |
| 1m | Perluas properti activity log sesuai tabel Jejak log | M | setelah 1e, 1j | Pending |
| 2a | `InstallmentForm` — prefill & anti-korupsi pakai tagihan efektif; dialog konfirmasi berakibat-angka | L | setelah 1e | Pending |
| 2b | Blade form angsuran — baris Titipan Pokok; cabut kalimat "dikreditkan ke Simpanan Sukarela" (`:174`) | M | setelah 2a | Pending |
| 2c | `InstallmentResource` + `CreateInstallment` — prefill `:108`, validasi `:236`, helper `:233`, label `:264` | M | setelah 1e, 1l | Pending |
| 2d | Layar pembatalan — tampilkan keterkaitan sesi ("satu setoran bersama ANG-…") | S | setelah 1k | Pending |
| 2e | `LoanDetail` — saldo Titipan Pokok + panel **Riwayat Titipan Pokok** | M | setelah 1a | Pending |
| 3a | **Tulis ulang** `LoanPaymentServiceTest:96-114` | M | setelah 1e | Pending |
| 3b | **Tulis ulang** `SavingsMutationServiceTest:91-107` | S | setelah 1h | Pending |
| 3c | **Tulis ulang** `EarlySettlementServiceTest:87` + `EarlySettlementModelTest:82` | M | setelah 1i | Pending |
| 3d | Test: titipan mengalir lintas beberapa bulan (tabel Design) | M | setelah 1e | Pending |
| 3e | Test: kedua mode menghasilkan Σ uang & Σ jasa identik | M | setelah 1e | Pending |
| 3f | Test: invariant `credit_applied == max(0, kontrak − dibayar)` | S | setelah 1e | Pending |
| 3g | Test: idempotensi — klik simpan dua kali tidak menghasilkan baris ganda | M | setelah 1e | Pending |
| 3h | Test: pembatalan — titipan pulih; guard menolak urutan salah; `credit_applied` ikut terbalik | M | setelah 1j, 1k | Pending |
| 3i | Test: pelunasan dipercepat bertitipan tak menagih dobel | M | setelah 1i | Pending |
| 3j | Test: setoran yang cukup melunasi seluruh sisa **tidak** diproses sebagai tutup-sekalian | M | setelah 1d | Pending |
| 3k | Test: kuitansi menutup di **kedua** arah — baris yang memakai titipan **dan** baris yang menyisihkannya; baris lama ber-`credit_applied` NULL tetap menutup | M | setelah 1l | Pending |
| 3l | Test: invariant "Lunas ⇒ titipan 0" | S | setelah 1h | Pending |
| 3m | Test: pratinjau basi ditolak | M | setelah 1f | Pending |
| 3n | **Regresi batch**: potong gaji berperilaku persis seperti sebelum perubahan, titipan tak bergerak (`Δ = 0`) | M | setelah 1e | Pending |
| 3o | Test: setoran multi-angsuran — bukti benar-benar melekat di **semua** baris, tak ada baris tanpa bukti | M | setelah 1g | Pending |
| 3p | Test penjaga: pinjaman yang sudah punya angsuran **tidak bisa** dibatalkan; pinjaman Dibatalkan bertitipan 0 | S | setelah 1a | Pending |
| 3q | Test: dengan lubang jadwal (#3 lunas, #2 dibatalkan), Sisa Pokok di layar detail **sama** dengan `settledPrincipal()` | M | setelah 2h | Pending |

**Effort:** S = small (< 1 jam), M = medium (1-3 jam), L = large (> 3 jam), — = observasi/non-code

---

## Key Files

| File | Fungsi |
|---|---|
| `database/migrations/2026_08_28_000001_add_credit_applied_to_installments.php` | **Baru** — jejak audit + penanda sesi + pelebaran `idempotency_key` |
| `app/Models/Loan.php` | `overpaymentCredit()`, `effectiveBill()`, `payoffAmount()` — sejajar `settledPrincipal()` |
| `app/Models/Installment.php` | `breakdown()` (`:97`), `reverseClone()` (`:194`) — allowlist eksplisit |
| `app/Services/LoanPaymentService.php` | Satu-satunya pembuat baris angsuran (`:106`, `:197`); rumah `allocate()`, `pay()`, `settleEarly()`, `reverse()` |
| `app/Livewire/Loan/Installment/InstallmentForm.php` | Prefill (`:251`), anti-korupsi (`:336`), dialog konfirmasi |
| `app/Filament/Resources/InstallmentResource.php` + `Pages/CreateInstallment.php` | Pintu manual **kedua** — prefill (`:108`), validasi (`:236`), helper (`:233`), label (`:264`) |
| `resources/views/livewire/loan/installment/installment-form.blade.php` | `bill` Alpine (`:3`), tagihan (`:115`), teks Sukarela (`:174`) |
| `app/Livewire/Loan/LoanDetail.php` | Ringkasan + panel Riwayat Titipan Pokok |
| `app/Livewire/Loan/Installment/InstallmentDetail.php` | Peta label audit (`:78`, `:98`) — kolom baru wajib didaftarkan; `remainingAfter()` (`:112`) diubah jadi berbasis jumlah |
| `app/Filament/Widgets/SavingsStatsOverview.php` · `OverdueInstallmentsTable.php` | Angka tunggakan (`:81` agregat, `:52` per baris) — masih `total_due` kontraktual |
| `app/Filament/Resources/LoanResource.php` | `canCorrect()` (`:77`) — guard `! hasPayments` yang menopang invariant "Dibatalkan ⇒ titipan 0" |
| `app/Services/BatchInstallmentPaymentService.php` | **Tidak diubah** — tapi rumus pelunasan duplikat (`:192`) tetap dialihkan ke `payoffAmount()`; lantai `:205` dicatat sebagai titik yang harus ikut berubah bila payroll berubah |
| `app/Models/InstallmentSchedule.php` | `principal_due` / `total_due` — konstanta akad, **tidak** diubah |
| `app/Actions/ReverseTransaction.php` | Mesin pembalikan generik; guard dipasang di `LoanPaymentService::reverse()` |
| `tests/Feature/LoanPaymentServiceTest.php` · `SavingsMutationServiceTest.php` · `EarlySettlementServiceTest.php` · `EarlySettlementModelTest.php` | Ditulis ulang |
| `tests/Feature/BatchInstallmentPaymentServiceTest.php` | Regresi — memastikan batch **tidak** berubah perilaku |

---

## Verification

- [ ] Setor 3.000.000 di angsuran #2 (tagihan 1.050.000), mode Titipan → tagihan #3 = 50.000, #4 = 100.000, #5 = 1.050.000; Σ = 5.250.000.
- [ ] Setor 2.100.000, mode Tutup Sekalian → #2 dan #3 lunas, titipan 0, #4 tagihan penuh.
- [ ] Kedua mode: Σ uang identik dan Σ jasa identik (5×).
- [ ] `credit_applied` pada setiap baris = `max(0, tagihan kontrak − dibayar)`.
- [ ] `settledPrincipal()` sama dengan pembayaran normal pada nomor angsuran yang sama.
- [ ] Akrual Tabungan Berjangka **identik** dengan pinjaman tanpa kelebihan bayar.
- [ ] Kuitansi bertitipan: Σ komponen = total; baris "Sisa Titipan Pokok" menunjukkan 0 pada setoran yang menghabiskannya.
- [ ] Panel Riwayat Titipan Pokok menampilkan masuk / dipakai / saldo secara kronologis.
- [ ] Setoran multi-baris: klik simpan dua kali tidak menghasilkan baris ganda; bukti melekat di **setiap** baris.
- [ ] Layar pembatalan menampilkan keterkaitan sesi.
- [ ] Membatalkan setoran yang membuat titipan mengembalikan seluruhnya dan titipan hilang — bila belum terpakai.
- [ ] Bila titipan sudah terpakai: pembatalan ditolak dengan pesan menyebut angsuran penghalang; setelah penghalang dibatalkan, berhasil dan titipan pulih.
- [ ] Baris pembalik membawa `credit_applied` dan `session_key` yang sama dengan aslinya.
- [ ] Setoran yang cukup melunasi **seluruh** sisa diarahkan ke Pelunasan Dipercepat.
- [ ] Pelunasan dipercepat bertitipan: `payoff` berkurang tepat sebesar titipan; `credit_applied` terisi di baris pelunasan.
- [ ] **Batch potong gaji: perilaku tidak berubah sama sekali.** `Δtitipan = 0` selama nominalnya tetap sebesar tagihan kontrak. Nominal batch **bisa dinaikkan petugas** ([`Filament/Pages/BatchInstallmentPayment:141`](../../app/Filament/Pages/BatchInstallmentPayment.php) memakai `minValue`, bukan nilai terkunci); bila dinaikkan, titipan memang terbentuk — dan itu perilaku yang benar, bukan pengecualian.
- [ ] Bayar dari saldo simpanan tetap terkunci tepat sebesar tagihan efektif.
- [ ] Pratinjau basi ditolak, bukan disimpan dengan hasil berbeda.
- [ ] Invariant: tak ada pinjaman **Lunas maupun Dibatalkan** dengan titipan ≠ 0; pinjaman yang sudah punya angsuran tetap tak bisa dibatalkan.
- [ ] Setoran multi-angsuran: bukti melekat di **semua** baris — tidak ada baris yang kehilangan lampiran karena berkas sumbernya sudah dipindah.
- [ ] Angka tunggakan di dashboard memakai tagihan efektif; anggota bertitipan tidak dilaporkan menunggak lebih besar dari kewajiban riilnya.
- [ ] Jejak audit menampilkan `credit_applied` dan `session_key` dengan label Indonesia dan format rupiah — bukan nama kolom mentah.
- [ ] **Sisa Pokok di layar detail angsuran sama dengan `settledPrincipal()` walau ada lubang jadwal.** Skenario uji: setor 2× tagihan mode tutup-sekalian (#2 & #3 lunas) → batalkan **#2 saja** → detail #3 harus menampilkan sisa pokok untuk **1** angsuran bersih, bukan 3.
- [ ] Sebrakan (`jangka_pendek`) dengan kelebihan bayar → langsung ke Sukarela saat lunas.
- [ ] Log memuat mode, jumlah angsuran ditutup, `credit_applied`, saldo sebelum & sesudah, kunci sesi; pembatalan yang ditolak mencatat angsuran penghalang.
- [ ] Regresi: pinjaman yang tak pernah kelebihan bayar berperilaku persis seperti sebelum perubahan.

---

## Open Questions

**OQ-0 — Risiko loket: DITERIMA (keputusan diambil, dicatat untuk `security-reviewer`).**

`belowBill()` diberi label eksplisit *"Anti-korupsi total-level"* ([`InstallmentForm:333`](../../app/Livewire/Loan/Installment/InstallmentForm.php)). Fitur ini **menurunkan lantai itu** tepat pada anggota yang punya titipan, dan itu membuka satu jalur:

1. Anggota punya titipan Rp 1.000.000 → tagihan efektifnya Rp 50.000.
2. Anggota menyerahkan Rp 1.050.000 — jumlah yang biasa ia bayar, karena ia tidak melacak titipannya.
3. Petugas mencatat Rp 50.000. Lantai lolos.
4. Selisih Rp 1.000.000 tidak pernah masuk sistem.

**Pembukuannya tetap rekonsiliasi sempurna** — angsuran lunas, titipan terpakai secara sah, kuitansi berjumlah benar. Tidak ada invariant yang dilanggar, karena tidak ada apa pun di sistem yang mencatat berapa uang fisik berpindah tangan. Paparannya sebesar titipan anggota, yang bisa jutaan.

Ini **bukan bug dan tidak punya tambalan teknis**: manfaat fitur ini (lantai boleh turun) dan celahnya (lantai boleh turun) adalah hal yang sama persis. Mempertahankan lantai di tagihan kontrak menutup celahnya sekaligus meniadakan fiturnya.

Alternatif yang dipertimbangkan — membatasi pemakaian titipan per angsuran dengan gerbang Pengurus di atas ambang — **ditolak**: gerbang harian untuk risiko sesekali dinilai terlalu menyulitkan petugas dan anggota.

**Keputusan: risiko diterima.** Pengaman yang berlaku semuanya berupa pendeteksian setelah kejadian, bukan pencegahan:
- kuitansi menampilkan **Sisa Titipan Pokok** dan diserahkan ke anggota — ini naik peran dari kenyamanan jadi **kontrol utama**;
- **Panel Riwayat Titipan Pokok** menampilkan masuk / dipakai / saldo;
- jejak activity log mencatat `credit_applied` dan saldo sebelum-sesudah tiap setoran.

Catatan: sistem **tidak punya kanal notifikasi ke anggota** — [`SendInstallmentReminders:29`](../../app/Console/Commands/SendInstallmentReminders.php) mengirim ke `User` (petugas), bukan ke anggota. Mitigasi paling efektif — memberi tahu anggota tagihannya sebelum ia datang, sehingga uang berlebih tak pernah ada di meja — karena itu **tidak tersedia tanpa membangun kanal baru**, dan berada di luar cakupan ADR ini.

**OQ-9 — "Sisa Pokok" di layar detail angsuran — DIPUTUSKAN: DIPERBAIKI.** `CLOSED`

Keputusan pemilik produk: perbaiki sekalian, jangan sekadar dicatat. Alasannya: ADR ini yang membuat lubang jadwal lebih sering terjadi, jadi ADR ini pula yang menanggung akibatnya. Item 2h.

Ini **perbaikan bug lama yang ikut menumpang** — reviewer perlu tahu bahwa layar detail angsuran berubah angkanya untuk data yang sudah ada, dan itu disengaja, bukan efek samping Titipan Pokok.

Uraian masalahnya:

[`InstallmentDetail::remainingAfter()`](../../app/Livewire/Loan/Installment/InstallmentDetail.php) menghitung `principal_amount − seq × monthly_principal` — berbasis **nomor urut jadwal**. `Loan::settledPrincipal()` menghitung berbasis **jumlah** angsuran. Komentar di kode mengklaim keduanya konsisten, dan itu benar **selama angsuran terbayar berurutan tanpa lubang**.

ADR ini secara sadar mengizinkan lubang: pembatalan per-transaksi atas sesi multi-angsuran bisa menyisakan angsuran #3 terbayar sementara #2 tidak. Di keadaan itu layar detail menampilkan Sisa Pokok yang lebih kecil dari kenyataan.

Contoh konkret — pinjaman 5.000.000 / 5 bulan, pokok 1.000.000 per bulan. Anggota setor 2.100.000 di #2 dengan mode tutup-sekalian (#2 dan #3 lunas), lalu **#2 saja dibatalkan**:

| Sumber angka | Cara berpikir | Sisa Pokok |
|---|---|---|
| Layar detail `remainingAfter()` | "ini angsuran **nomor 3**" → 3 × 1.000.000 terbayar | **2.000.000** |
| `Loan::settledPrincipal()` | "**ada berapa** angsuran?" → 1 | **4.000.000** |

Selisih 2 juta, dari aplikasi yang sama.

Divergensinya **bukan bawaan ADR ini** — membatalkan angsuran mana pun sudah mungkin sejak dulu, jadi bug ini sudah ada. Yang ADR ini lakukan adalah memperbesar peluang munculnya.

**Perbaikannya:** `remainingAfter()` diubah jadi berbasis jumlah angsuran bersih, sejalan dengan `settledPrincipal()` — dan komentar di `:112-114` yang mengklaim keduanya sudah konsisten dikoreksi, karena klaim itu menyimpan asumsi tersembunyi (tanpa lubang) yang tidak pernah ditulis.

**OQ-1 — Apa masalah aslinya menurut Mbak Iin?** Background ADR ini menyimpulkan sendiri bahwa masalahnya adalah alur pencairan Sukarela yang ribet. Beliau hanya menyebut aturan. Bila motifnya ternyata lain (mis. anggota bingung membaca kuitansi), yang perlu diperbaiki adalah Background — dan dalam kasus tertentu, sebagian fitur ini mungkin tak diperlukan.

**OQ-2 — Cakupan "hide sukarela".** Diasumsikan hanya jalur kelebihan-bayar → Sukarela. Menyembunyikan produk Simpanan Sukarela akan mematikan fitur Bayar Angsuran dari Simpanan (sumber dananya sukarela-only).

**OQ-3 — Patokan kelebihan = total tagihan, bukan pokok.** Bukan pilihan bebas: `belowBill()` dan [`InstallmentForm:336`](../../app/Livewire/Loan/Installment/InstallmentForm.php) sudah menolak pembayaran di bawah total tagihan. Tetap perlu dikonfirmasi karena kalimat aslinya berbunyi "kelebihan dari pokok".

**OQ-4 — Anggota kehilangan basis SHU?** Di perilaku lama kelebihan jadi Simpanan Sukarela; di desain baru jadi kredit pinjaman yang tak terhitung sebagai simpanan. Dampaknya nol hari ini — [`Dokumentasi_Sistem_Koperasi_v5.md:76`](../Dokumentasi_Sistem_Koperasi_v5.md) menyatakan SHU **belum diterapkan**. Tapi masukan Mbak Yasmin poin 3 bicara soal pembagian SHU tahunan.

**OQ-5 — Guard pembatalan mengekang?** Apakah pengurus pernah perlu membatalkan satu angsuran lama tanpa mengusik yang sesudahnya?

**OQ-6 — Petugas mengunci Pengurus.** Guard pembatalan berarti entri **Petugas** bisa memblokir tindakan **Pengurus** (`reverse_loan`). Bukan eskalasi hak akses, tapi pembalikan arah kendali.

**OQ-7 — Tampilan tabel progres angsuran.** `SchedulesRelationManager` menampilkan `total_due` kontraktual. Perlu kolom tagihan efektif, atau cukup di form pembayaran? Cenderung cukup di form.

**OQ-8 — Tagihan efektif nol.** Bila rate jasa & tab. berjangka disetel 0, tagihan efektif bisa 0 dan aturan `min:1` menolak pembayaran yang sah. Kecil kemungkinannya; murah dijaga.

---

## Risk Register

| # | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| R1 | Lantai `belowBill()` — kontrol anti-korupsi — jadi variabel & tak kasatmata | Selisih sah tampak identik dengan selisih dikorupsi | `credit_applied` per baris + log + baris kuitansi + panel riwayat |
| R2 | Perbaikan payoff dipasang di satu jalur, jalur satunya menagih dobel | Anggota rugi, sunyi | `payoffAmount()` satu sumber; rumus duplikat di batch dicabut |
| R3 | `Δtitipan` dihitung terhadap tagihan **efektif**, bukan kontrak | Saldo membengkak tiap bulan — kekeliruan v2 terulang di kode | Rumus ditulis eksplisit + test invariant 3f + regresi batch 3n |
| R4 | Setoran pelunasan diproses sebagai tutup-sekalian | Anggota bayar jasa berlebih tanpa ada yang sadar | Pengecekan pelunasan berjalan paling awal |
| R5 | Kunci idempotensi acak per baris | Klik ganda menghasilkan baris ganda; perlindungan hilang | Kunci sesi + nomor urut, deterministik; test 3g |
| R6 | Pembatalan sebagian sesi menyisakan lubang jadwal | Angsuran #3 lunas tapi #2 belum | Diterima sadar; FIFO menawarkan #2 lagi; layar pembatalan menampilkan keterkaitan sesi |
| R7 | Titipan jadi jalur umum (bawaan), bukan kasus pinggiran | Semua pengaman jadi beban inti | Diterima sadar; tercermin di effort Key Items |
| R8 | Invariant "Lunas ⇒ titipan 0" adalah pernyataan, bukan turunan | Jalur baru menuju Lunas tanpa pelimpahan → uang hilang dari layar | Test 3l; terverifikasi `LoanStatus::Lunas` hanya di `LoanPaymentService` |
| R9 | Dialog konfirmasi bertaruhan rendah → diklik buta | Anggota dapat bentuk tagihan yang tak dia mau | Dialog hanya muncul bila sisa ≥ satu angsuran; akibat wajib berangka |
| R10 | Rollback Phase 2 → 1 tidak bersih | Kuitansi terbit kembali tak rekonsiliasi | Diakui di Rollout Plan |
| R11 | Payroll suatu saat memotong angka yang sudah dikurangi titipan | Lantai batch `:205` menolak setoran sah | Dicatat sebagai syarat eksplisit di Design |
| R12 | Nama lama "Kelebihan Bayar" masih dipakai kuitansi untuk arti berbeda | Salah paham pengurus | Konsep baru bernama **Titipan Pokok** |
| R13 | Angka tunggakan memakai `total_due` kontraktual — [`SavingsStatsOverview:81`](../../app/Filament/Widgets/SavingsStatsOverview.php) (agregat) dan [`OverdueInstallmentsTable:52`](../../app/Filament/Widgets/OverdueInstallmentsTable.php) (per baris) | Tunggakan anggota bertitipan dilaporkan lebih besar dari kewajiban riilnya | Item 2f; `monthlyDeductionLoad()` sendiri tetap benar karena memang kontraktual |
| R17 | `addMedia($uploadedFile)` memindahkan berkas sumber | Baris kedua dalam satu sesi gagal melampirkan bukti → seluruh transaksi batal | `preservingOriginal()` atau simpan-sekali-lampirkan-per-baris; ditulis eksplisit di Design |
| R19 | Kolom baru tak terdaftar di peta label audit `InstallmentDetail:78` | Jejak audit — **kontrol utama atas R14 yang diterima** — tampil setengah jadi: nama kolom mentah, angka tak terformat | Item 2g; peta itu eksplisit per-layar (pola `InteractsWithAuditTrail`), jadi kolom baru tak otomatis terbaca |
| R20 | `InstallmentDetail::remainingAfter()` (`:112`) menghitung sisa pokok dari **nomor urut jadwal**, sementara `Loan::settledPrincipal()` dari **jumlah** angsuran | Keduanya hanya sama bila angsuran terbayar berurutan tanpa lubang. Pembatalan per-transaksi atas sesi multi-angsuran **bisa menyisakan lubang** (#3 terbayar, #2 tidak) → layar detail menampilkan Sisa Pokok yang berbeda dari sisa pokok pinjaman sebenarnya | **DIPERBAIKI** (item 2h) — `remainingAfter()` dibuat berbasis jumlah, sejalan `settledPrincipal()`. Bug ini **sudah ada** sebelum ADR ini; perbaikannya menumpang secara sadar karena ADR ini memperbesar peluang munculnya (OQ-9) |
| R18 | Guard `canCorrect()` (`! hasPayments`) dilonggarkan di kemudian hari | Titipan pada pinjaman Dibatalkan jadi tak terlihat dan tak bisa diambil — jadwalnya sudah dihapus, tak ada pelimpahan | Guard `overpaymentCredit()` diperluas ke `Dibatalkan`; test penjaga bahwa pinjaman berangsuran tak bisa dibatalkan |
| **R14** | **Petugas loket mencatat tagihan efektif sementara anggota menyerahkan tagihan kontrak, lalu mengantongi selisihnya** | **Paparan sebesar titipan anggota. Pembukuan tetap rekonsiliasi sempurna — tidak terdeteksi oleh audit mana pun** | **DITERIMA sebagai risiko sadar (OQ-0).** Tidak ada pencegahan teknis — manfaat fitur dan celahnya adalah hal yang sama. Pendeteksian: kuitansi ber-Sisa Titipan Pokok, Panel Riwayat, activity log. Gerbang Pengurus ditolak karena menyulitkan operasional |
| R15 | Pratinjau alokasi dibandingkan berdasarkan bentuk (jumlah baris), bukan versi | Payload manipulatif bisa mengaku alokasi apa pun | Yang dibandingkan **saldo titipan** saat pratinjau vs saat di dalam kunci |
| R16 | Kuitansi hanya menutup satu arah (memakai titipan), tidak saat menyisihkan | Nota multi-angsuran tidak berjumlah — dokumen yang diserahkan ke anggota salah | Dua baris wajib: "dipakai" (−) dan "disisihkan" (+); test 3k menguji **kedua** arah |

---

## Pipeline trace (v7)

| Stage | Agent | Key output | Date |
|---|---|---|---|
| Framing | strategist | *(retroactive — not invoked)* — framing lewat diskusi langsung: poin 1 (Mbak Iin) dipisah dari poin 2-4 (Mbak Yasmin) jadi dua ADR | 2026-08-28 |
| Data baseline | data-analyst | `skipped: perubahan maju-saja tanpa migrasi data; konstanta acuan dibaca dari CooperativeSettings.` Kebutuhan angka "proporsi potong-gaji vs tunai" gugur — batch tidak disentuh, jadi proporsinya tak mengubah desain. | 2026-08-28 |
| Design | architect | *(retroactive — not invoked)* — alokasi bertingkat + saldo turunan + `credit_applied` per baris | 2026-08-28 |
| Critique | critic | *(retroactive — not invoked)* — 4 ronde self-critique. R1: 5 lubang, 2 bubar. R2: 3 BERAT (jalur batch, premis potong-gaji, kontrol anti-korupsi) + kuitansi tak rekonsiliasi. R3: 2 pintu Filament terlewat, ongkos/manfaat terbalik. R4: klaim batch v3 salah, kunci unik idempotensi jebol, laporan batch menyesatkan, pembalikan sebagian lolos guard. R5 (fokus security & finance): **jalur korupsi loket (R14)** — temuan terberat lima ronde, kuitansi tak menutup saat menyisihkan titipan (R16), ambang pelunasan belum berangka, hak akses mode belum dinyatakan, pembandingan pratinjau berbasis bentuk. Sekaligus **empat hal diverifikasi aman**: penjaga pelunasan kedap (bukti aljabar), bayar-dari-simpanan tak bisa membuat titipan, pembatalan sebagian tak merusak sisa pokok, pembulatan `ceil` tak bocor ke titipan. R6 (area yang belum pernah disentuh): `addMedia()` memindahkan berkas sehingga bukti multi-baris **gagal di baris kedua** (R17); tabrakan dengan ADR Penutupan Akun Anggota (dicatat sebagai ketergantungan, ADR itu sendiri tidak dikerjakan atas keputusan pemilik produk); angka tunggakan dashboard melebih-lebihkan (R13 kini menyebut lokasinya); pinjaman **Dibatalkan** diverifikasi aman lewat guard `canCorrect()`, ketergantungannya dicatat (R18). R7 (laporan, ekspor, layar angsuran): kolom baru tak terdaftar di peta label audit sehingga kontrol utama R14 tampil setengah jadi (R19); `remainingAfter()` berbasis nomor urut vs `settledPrincipal()` berbasis jumlah — divergensi lama yang diperbesar ADR ini (R20 → OQ-9). Diverifikasi aman: `InstallmentReportService` menjumlah `amount_paid` bertanda (uang tunai riil, tetap benar), dan `ExportSalaryDeductionRecap` ternyata rekap **simpanan**, bukan angsuran — tak bersinggungan. | 2026-08-28 |
| Security review | security-reviewer | pending — **wajib, dan agenda utamanya sudah spesifik: OQ-0 / R14.** Pemilik produk telah **menerima** risiko loket secara sadar dan menolak gerbang Pengurus; review diminta menilai apakah pendeteksian pasca-kejadian (kuitansi + panel riwayat + log) memadai sebagai satu-satunya pengaman atas kontrol yang kodenya sendiri melabeli "Anti-korupsi". Sekunder: guard baru pada jalur `reverse` ber-privilege Pengurus | — |
| Deploy review | deploy-reviewer | pending — ada migrasi kolom; urutan Phase 1 → 2 dan rollback yang tidak bersih (R10) perlu ditinjau | — |
| Implementation | implementer / human | in progress — item **0a** (schema) selesai; pelebaran `idempotency_key` ditemukan saat implementasi | 2026-08-28 |
| Review | reviewer | pending | — |

**Ronde**: 7
**Skipped stages**: `data-analyst` (alasan gugur — lihat barisnya)
**Calibration notes**: Pola kesalahan berulang yang teridentifikasi: **menyatakan perilaku dinamis tanpa menghitungnya lebih dulu**. Terjadi tiga kali pada topik yang sama (perilaku titipan pada potong gaji) — v2 mengklaim membengkak, v3 mengklaim terpakai, keduanya salah; baru v4 menghitung `Δ = uang − kontrak` dan mendapat jawaban benar (tetap). Juga dua kali melewatkan pintu masuk (R1: service batch; R2: dua halaman Filament), baru teratasi di R3 lewat enumerasi dari **mesin** (`grep` pemanggil service + `Installment::create` + `routes/api.php`), bukan dari daftar layar. Pelajaran: (1) hitung sebelum mengklaim dinamika; (2) enumerasi dari mesin, bukan dari UI.

---

## Changelog

- **2026-08-28 v8**: Item **0a selesai** — migrasi `credit_applied` (decimal 18,2, nullable) + `session_key` (uuid, nullable, index) di `installments`, plus `$fillable`/`$casts` di `Installment`. **Temuan implementasi:** kunci idempotensi turunan `kunci_sesi + "-" + urutan` (38 karakter) **tidak muat** di `idempotency_key` yang dideklarasikan `uuid` = `char(36)`; kolomnya dilebarkan jadi `varchar(64)` dalam migrasi yang sama, indeks UNIQUE dipertahankan. Tanpa ini item 1e tidak bisa dijalankan seperti tertulis. Dikunci test `InstallmentCreditColumnsTest` (kolom ada, NULL aman untuk baris lama, kunci turunan muat, duplikat tetap ditolak UNIQUE). Perilaku belum berubah sama sekali — suite penuh hijau (611 passed).
- **2026-08-28 v7**: Ronde kritik ke-7 (laporan, ekspor, layar daftar/detail angsuran). **R19** — `credit_applied` & `session_key` tak akan terbaca di jejak audit karena peta label di `InstallmentDetail:78` bersifat eksplisit per-layar; ini penting karena jejak audit adalah kontrol utama atas risiko R14 yang diterima (item 2g). **R20 → OQ-9** — `remainingAfter()` berbasis nomor urut jadwal sementara `settledPrincipal()` berbasis jumlah angsuran; keduanya hanya sama tanpa lubang, dan ADR ini memperbesar peluang lubang lewat pembatalan per-transaksi. Divergensinya lama, bukan bawaan ADR ini. **Keputusan pemilik produk: diperbaiki** (item 2h + test 3q) — perbaikan bug lama yang menumpang secara sadar, ditandai agar reviewer tahu kenapa ada layar berubah angkanya. Diverifikasi aman: `InstallmentReportService` (menjumlah uang tunai riil, benar apa adanya) dan `ExportSalaryDeductionRecap` (rekap simpanan, tak bersinggungan).
- **2026-08-28 v6**: Ronde kritik ke-6 (area yang belum pernah disentuh: media, ADR tetangga, widget, status pinjaman). **`addMedia($uploadedFile)` memindahkan berkas sumbernya**, sehingga keputusan "bukti melekat di setiap baris" tidak bisa dijalankan dengan pola kode yang ada — dua jalan sah ditulis eksplisit (R17). Ketergantungan ke [ADR Penutupan Akun Anggota](2026-07-13-penutupan-akun-anggota.md) dicatat di Non-Goals — ADR itu **tidak dikerjakan** atas keputusan pemilik produk, tapi saat dikerjakan nanti wajib memakai `payoffAmount()` dan membaca ulang saldo Sukarela setelah settle. R13 kini menyebut dua lokasi konkret (`SavingsStatsOverview:81`, `OverdueInstallmentsTable:52`) plus item 2f. Guard `overpaymentCredit()` diperluas ke status **Dibatalkan**; aman hari ini karena `canCorrect()` melarang pembatalan pinjaman berangsuran, tapi ketergantungan itu dicatat (R18) dan dijaga test 3p.
- **2026-08-28 v5**: Ronde kritik ke-5 (fokus security & finance). **Risiko korupsi loket (R14) dinaikkan jadi OQ-0 dan DITERIMA secara sadar** — gerbang Pengurus atas pemakaian titipan dipertimbangkan lalu ditolak karena menyulitkan operasional harian; pengaman yang tersisa seluruhnya bersifat pendeteksian pasca-kejadian, dan itu dicatat apa adanya untuk `security-reviewer`. Diperbaiki: kuitansi kini menutup di **kedua** arah — baris "Titipan Pokok disisihkan" (+) ditambahkan, sebelumnya nota multi-angsuran tidak berjumlah (R16); ambang penjaga Pelunasan Dipercepat didefinisikan sebagai `uang ≥ payoffAmount()` beserta bukti aljabar bahwa penjaganya kedap; hak akses pemilihan mode dinyatakan Petugas-level; pembandingan pratinjau basi diubah dari berbasis bentuk jadi berbasis **saldo titipan** (R15); `credit_applied` NULL diperlakukan 0; klaim `Δ = 0` di batch dipersempit — nominal batch bisa dinaikkan petugas. Diverifikasi aman: penjaga pelunasan, bayar-dari-simpanan, pembatalan sebagian, pembulatan `ceil`.
- **2026-08-28 v4**: **Koreksi klaim batch v3** — batch mode tutup-sekalian tidak membuat titipan terpakai; `Δ = uang − tagihan kontrak = 0` pada potong gaji, jadi kedua mode identik di sana. Aturan khusus batch **dicabut**; batch tidak disentuh sama sekali (syarat: payroll tetap memotong angka kontrak, dicatat sebagai R11). Ditambahkan: rumus inti eksplisit dengan jebakan "kontrak vs efektif" (R3), idempotensi kunci sesi + nomor urut, pembatalan **per-transaksi** dengan penanda keterkaitan sesi (bukan pembalikan sepaket), bukti melekat di setiap baris, kuitansi bertitipan dengan baris "Titipan Pokok dipakai" dan "Sisa Titipan Pokok", panel Riwayat Titipan Pokok, keputusan **tanpa pelacakan per-lot**, dan perluasan jejak activity log. Background diberi catatan kejujuran bahwa motif masalahnya adalah inferensi penulis, belum dikonfirmasi.
- **2026-08-28 v3**: Dua mekanisme digabung jadi alokasi bertingkat — bawaan Titipan Pokok, opsi tutup sekalian, penjaga Pelunasan Dipercepat. Koreksi tabel v2 yang keliru soal titipan membengkak. Dua pintu Filament masuk cakupan.
- **2026-08-28 v2**: Jalur batch masuk cakupan; `credit_applied` sebagai jejak audit; `breakdown()` & `reverseClone()` masuk Key Items; 4 file test lama ditandai tulis-ulang; konsep dinamai **Titipan Pokok**; Risk Register.
- **2026-08-28 v1**: Initial draft.
