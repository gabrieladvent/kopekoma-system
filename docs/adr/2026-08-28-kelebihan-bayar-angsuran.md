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
| 3 | [`BatchInstallmentPayment`](../../app/Livewire/Loan/Installment/BatchInstallmentPayment.php) (Livewire, batch) | ~~Tidak~~ → **Ya** (R23, dan lagi di v25) |
| 4 | [`Filament/Pages/BatchInstallmentPayment`](../../app/Filament/Pages/BatchInstallmentPayment.php) (Filament, batch) | **Tidak** |

**Klaim "batch tidak disentuh sama sekali" gagal dua kali, dan cara gagalnya sama.** Pertama R23 — penjaga Pelunasan Dipercepat menyala di jalur payroll dan lemparannya ditelan `catch`. Kedua (v25) — pintu 3 menghitung **jumlah pelunasan** dengan rumus lokalnya sendiri, salinan keempat yang lupa memotong Titipan Pokok, sementara validasi batch dan `settleEarly()` sudah memakai angka yang benar. Keduanya luput dari enumerasi awal karena yang dienumerasi adalah *pembuat baris angsuran*; pintu 3 tidak membuat baris, ia **menyodorkan angka yang dipakai bendahara untuk memotong gaji** — dan angka itu sama berakibat-uangnya.

Untuk pembayaran biasa batch memang aman apa adanya: payroll membayar tagihan kontrak, yang **selalu ≥ tagihan efektif** — lantai `:205` dan prefill `:244` tetap benar.

Pintu 2 dan 4 memang **mati**: panel Filament tidak didaftarkan sama sekali ([`bootstrap/providers.php`](../../bootstrap/providers.php)), jadi tak ada rutenya. Kelas-kelasnya tetap ada karena method statisnya masih dipakai route Livewire untuk cetak PDF. Konsekuensinya: perbaikan yang hanya dipasang di pintu 1 dan 3 sudah menutup seluruh permukaan yang hidup — tapi bila panel itu dihidupkan lagi suatu hari, pintu 2 dan 4 masuk lagi tanpa pernah diperiksa ulang.

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

**Potongan titipannya dibatasi `settledPrincipal()`** *(ditetapkan saat implementasi 1c; ADR sebelumnya hanya menulis "dikurangi titipan" tanpa batas)*. Alasannya sama dengan batas `principal_due` di tagihan efektif: Titipan **Pokok** membayar pokok, tak pernah menggerus jasa. Tanpa batas ini titipan besar bisa menghapus 1× jasa yang justru merupakan satu-satunya jasa yang masih ditagih pada pelunasan. Sisa titipan di atas batas tidak hangus — ia dilimpahkan ke Simpanan Sukarela saat pinjaman jadi Lunas (item 1h), jadi anggota tetap menerima uangnya utuh.

**Penjaga ini kedap, dan bisa dibuktikan.** Menutup seluruh `k` angsuran sisa berharga `k × tagihan`, sedangkan pelunasan berharga `k × pokok + 1 × jasa`. Karena `k × tagihan = k×pokok + k×jasa + k×tab`, selisihnya `(k−1)×jasa + k×tab ≥ 0` untuk setiap `k ≥ 1`. Artinya **setiap nominal yang mampu menutup semua angsuran pasti sudah melewati ambang pelunasan lebih dulu** — tidak ada celah nominal di mana anggota terlanjur membayar jasa berlebih.

**Koreksi batas `k`: penjaganya berlaku mulai `k ≥ 2`, bukan `k ≥ 1`** *(ditetapkan saat implementasi 1d)*. Pada `k = 1` selisihnya tinggal `0×jasa + 1×tab` — tidak ada jasa yang dibebaskan sama sekali, jadi tak ada apa pun yang perlu dilindungi. Yang ada justru kerugian: baris pelunasan bertanda `is_settlement`, dan baris bertanda itu **dikecualikan dari akrual Tabungan Berjangka** ([`Installment::scopeSignedTimeDeposit()`](../../app/Models/Installment.php)). Membelokkan angsuran terakhir ke Pelunasan Dipercepat karena itu **menghanguskan akrual tab bulan terakhir** bagi setiap anggota yang membayar angsuran pamungkasnya secara normal — perubahan perilaku besar yang tak pernah diminta ADR ini.

Penjaga juga dilewati untuk **sebrakan** (`jangka_pendek`): [`settleEarly()`](../../app/Services/LoanPaymentService.php) memang menolaknya lewat `notSettleable()`, jadi membelokkannya berarti mengarahkan petugas ke jalan buntu.

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

**Koreksi (v25 — temuan `security-reviewer`).** Versi sebelumnya menulis "yang tetap Pengurus: pembatalan angsuran (`reverse_loan`)". Itu **salah**, dan salahnya mengubah kesimpulan. Pembatalan baris angsuran biasa dijaga `reverse_installment` ([`InstallmentPolicy:77`](../../app/Policies/InstallmentPolicy.php)), dan permission itu dipegang **Petugas maupun Pengurus** ([`RolePermissionSeeder:28`](../../database/seeders/RolePermissionSeeder.php)). `reverse_loan` menjaga pembatalan **pinjaman**, bukan angsuran.

Yang benar-benar tetap Pengurus: pembatalan pinjaman (`reverse_loan`), Pelunasan Dipercepat (`settle_early_installment`), bayar-dari-simpanan (`pay_installment_from_savings`), dan pencairan Simpanan Sukarela. Tidak ada yang berubah oleh ADR ini.

**Konsekuensinya untuk R14:** Petugas memegang `create_installment` + `reverse_installment` + `access_batch_salary_deduction` sekaligus — ia bisa mencatat lalu membatalkan sendiri tanpa mata kedua. Pemisahan tugas itu **sudah begitu sebelum ADR ini**; yang ADR ini ubah adalah nilai yang bisa digerakkan kombinasi tersebut. Layak jadi ADR tersendiri, dan dicatat di Follow-up.

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

> **Sistem ini belum pernah naik produksi.** Rilis pertamanya adalah pemasangan baru di atas database kosong, bukan perubahan atas sistem yang sedang berjalan. Itu menggugurkan seluruh premis "data lama" di bawah — lihat catatan per-baris.

| Phase | Behavior | Status |
|---|---|---|
| 0 | Baseline — semua kelebihan bayar → Simpanan Sukarela | **Tidak pernah berjalan di produksi** |
| 1 | Migrasi `credit_applied` + kunci sesi; perilaku belum berubah | **Done** — item 0a. OQ-1/OQ-2 tetap terbuka tapi **bukan penghalang**: keduanya soal ketepatan Background dan penamaan, bukan soal benar-tidaknya mesin |
| 2 | Titipan Pokok + tutup-sekalian + penjaga Pelunasan Dipercepat aktif | **Done** — mesin (1a–1m) dan seluruh pintu UI (2a–2i) selesai, termasuk perbaikan temuan kedua review |
| 3 | Observasi 1 siklus penggajian penuh + rekonsiliasi | **Pending — hanya bisa jalan setelah rilis pertama** |

### Rollback — dikoreksi setelah `deploy-reviewer`

Versi sebelumnya menyebut rollback Phase 2 → 1 "tidak sepenuhnya bersih". **Itu terlalu ringan.** Tiga hal yang baru ketahuan saat ditinjau:

1. **`migrate:rollback` DILARANG untuk migrasi ini.** `down()` versi pertama menyempitkan `idempotency_key` kembali ke `char(36)`, padahal setoran multi-angsuran menyimpan kunci turunan 38 karakter — `MODIFY` gagal, DDL MySQL tidak transaksional, dan `Migrator::runDown()` baru menghapus catatan migrasi **setelah** `down()` selesai. Hasilnya keadaan setengah jadi: kolom sudah hilang, migrasi masih dianggap ter-apply, `migrate` tak mengembalikannya. **Sudah diperbaiki** — `down()` kini tidak membalik pelebaran kolom sama sekali (aman: UUID 36 karakter muat di `varchar(64)`, dan kode lama tak peduli lebar kolom). Jalan mundur untuk skema tetap **restore dump**, bukan `down()`.

2. **Rollback kode wajib all-or-nothing.** Kunci `other` di `breakdown()` **dicabut**, bukan sekadar diabaikan. Revert yang hanya menyentuh `app/` tapi meninggalkan blade (atau sebaliknya) membuat kuitansi PDF dan layar detail melempar `Undefined array key` — petugas tak bisa mencetak kuitansi sama sekali. Rollback harus ke tag pre-rilis utuh, lalu `view:clear`.

3. **Rollback membuka R21 dari arah sebaliknya** — *berlaku sejak rilis pertama, bukan sebelumnya.* `pay()` lama mengkreditkan kelebihan bayar ke Sukarela; baris Phase 2 yang sudah ber-`credit_applied` tetap tersimpan. Bila kode di-roll-forward lagi kemudian, saldo titipan periode rollback **plus** setoran Sukarela periode rollback bisa membayar keringanan yang sama dua kali. Anggota bertitipan saat rollback wajib didaftar dan direkonsiliasi manual.

**Larangan permanen:** jangan pernah menjalankan `UPDATE installments SET credit_applied = 0` pada baris pra-rilis "biar konsisten". NULL **adalah** penanda yang menutup R21; mengisinya 0 menarik seluruh kelebihan bayar historis ke dalam saldo titipan. Di produksi baris seperti itu tidak akan pernah ada (rilis pertama = database kosong), tapi larangan ini tetap berlaku untuk database dev/staging yang isinya bisa terbawa naik.

### Phase Transition Checklist

**Phase 0 → 1:**
- [ ] **OQ-1 dijawab Mbak Iin** — motif sebenarnya, untuk memperbaiki Background
  <!-- source: manual -->
- [ ] **OQ-2 dijawab Mbak Iin** — cakupan "hide sukarela": jalurnya saja, bukan produk simpanannya
  <!-- source: manual -->
- [x] Migrasi `credit_applied` nullable, aman untuk baris lama
  <!-- source: code | query: grep credit_applied database/migrations | threshold: nullable -->

**Phase 1 → 2:**
- [x] **OQ-10 diputuskan** — opsi B terpasang di `overpaymentCredit()`; baris pra-fitur tak dihitung sebagai titipan (R21)
  <!-- source: code | query: php artisan test --filter=TitipanPokokLoan | threshold: 0 failed -->
- [x] ~~**Verifikasi produksi (bukan gerbang):** hitung baris angsuran lama yang membayar di atas `total_due`~~ — **GUGUR**: sistem belum pernah naik produksi, jadi tak ada baris angsuran lama di sana. Paparan yang dicegah opsi B = nol secara konstruksi
  <!-- source: manual -->
- [x] Suite hijau penuh termasuk 4 file test lama yang ditulis ulang — 742 passed, 4 skipped
  <!-- source: code | query: php artisan test | threshold: 0 failed -->
- [x] Invariant `credit_applied == max(0, monthly_total − amount_paid)` lulus di seluruh baris — item 3f, `AllocateInstallmentTest`
  <!-- source: code | query: php artisan test --filter=Allocate | threshold: 0 failed -->
- [ ] `security-reviewer` menyetujui pelemahan lantai `belowBill()` dengan kompensasi `credit_applied` — **review sudah dijalankan (verdict BLOCK) dan SELURUH keberatannya sudah dikerjakan**, termasuk dua syarat yang dulu jadi keputusan pemilik produk: akses log untuk Pengurus (v26) dan laporan agregat (v28). Yang belum: review ulang untuk mengganti verdict-nya. Ini konfirmasi, bukan pekerjaan yang tersisa
  <!-- source: manual -->
- [x] `deploy-reviewer` menyetujui urutan migrasi-lalu-perilaku — **syaratnya berubah**: maintenance window diminta untuk melindungi jendela di mana kode baru sudah aktif sementara `migrate` menyusul, dan di jendela itu SELURUH pencatatan angsuran gagal keras. Pada rilis pertama di atas database kosong jendela itu tidak ada penggunanya — `migrate` jalan sebelum ada yang bisa membuka sistem. `php artisan down` tetap murah dan tetap dianjurkan, tapi ia bukan lagi syarat. Yang **tetap** syarat: `migrate` selesai sebelum trafik pertama masuk
  <!-- source: manual -->
- [x] Section Rollback ADR diamandemen sesuai temuan `deploy-reviewer` (`down()` rusak, revert wajib all-or-nothing, R21 arah sebaliknya)
  <!-- source: manual -->
- [x] ~~**Ukur paparan produksi** sebelum rilis: jumlah baris kelebihan bayar historis + daftar anggotanya~~ — **GUGUR**: tak ada anggota yang pernah kelebihan bayar di produksi, jadi tak ada yang perlu diberitahu bahwa titipannya tampil 0
  <!-- source: manual -->
- [x] ~~**Ada baris `amount_paid < total_due` di data lama?**~~ — **GUGUR** bersama premis yang sama. Asumsi "kurang bayar tak pernah ada karena ditolak `belowBill()`" berlaku penuh: seluruh baris angsuran produksi akan dibuat oleh kode yang sudah memuat lantai itu
  <!-- source: manual -->
- [ ] **Seed permission** setelah deploy: `php artisan db:seed --class=RolePermissionSeeder` — `access_activity_log` (v26) dan `access_laporan_titipan` (v28) tidak muncul sendiri. Tanpa ini Pengurus kena 403 di dua kanal pendeteksian R14 sekaligus. Seeder idempotent (`firstOrCreate` + `syncPermissions`)
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
| 1a | `Loan::overpaymentCredit()` — saldo turunan + guard status **Lunas atau Dibatalkan** → `0.00` + saringan `credit_applied IS NOT NULL` (R21/OQ-10); plus `Loan::monthlyTotal()` sebagai satu sumber **tagihan kontrak** | S | ✅ | **Done** |
| 1b | `Loan::effectiveBill(InstallmentSchedule)` — **satu-satunya sumber** tagihan efektif | S | setelah 1a | **Done** |
| 1c | `Loan::payoffAmount()` — **satu-satunya sumber** jumlah pelunasan, dikurangi titipan (**dibatasi `settledPrincipal()`**); cabut rumus duplikat di `settleEarly()` dan `BatchInstallmentPaymentService:192` | M | setelah 1a | **Done** |
| 1d | `LoanPaymentService::allocate()` — alokasi bertingkat (deteksi pelunasan → tutup angsuran → sisa jadi titipan), mode `titipan` \| `tutup_sekalian`; plus `Loan::effectiveBillWithCredit()` agar rumus tagihan efektif tetap satu tempat saat disimulasikan | L | setelah 1b, 1c | **Done** |
| 1e | `LoanPaymentService::pay()` — `belowBill()` pakai tagihan efektif **di dalam lock**; N baris via `allocate()`; baris terakhir menyerap sisa; `credit_applied = max(0, kontrak − dibayar)`; kunci sesi berurut; hapus kredit-ke-Sukarela di tengah masa pinjaman | L | setelah 1d, 0a | **Done** |
| 1f | Tolak transaksi bila **saldo titipan** saat pratinjau ≠ saldo di dalam kunci (pratinjau basi) — pemeriksaan versi, bukan bentuk | M | setelah 1e | **Done** |
| 1g | Lampirkan bukti ke **setiap** baris dalam satu sesi — wajib `preservingOriginal()` atau simpan-sekali-lalu-lampirkan-dari-disk; `addMedia($uploadedFile)` polos **gagal di baris kedua** | M | setelah 1e | **Done** |
| 2f | Angka tagihan/tunggakan pakai tagihan efektif, bukan `total_due` — `SavingsStatsOverview:81` (agregat), `OverdueInstallmentsTable:52` (per baris), `SendInstallmentReminders:134` (isi pengingat ke petugas). Dikuras berurutan lewat `LoanArrearsService::effectiveBills()` | M | setelah 1b | **Done** |
| 2g | `InstallmentDetail::auditFieldLabel()` (`:78`) & `formatAuditFieldValue()` (`:98`) — daftarkan `credit_applied` dan `session_key`; tanpa ini jejak audit tampil dengan nama kolom mentah dan angka tak terformat | S | setelah 0a | **Done** |
| 2h | `InstallmentDetail::remainingAfter()` (`:112`) — **didelegasikan ke `settledPrincipal()`**, bukan dihitung ulang; koreksi komentar `:112-114` yang mengklaim konsistensi tanpa menyebut asumsi "tanpa lubang". **Perbaikan bug lama yang menumpang — lihat OQ-9** | M | ✅ | **Done** |
| 1h | `LoanPaymentService` — limpahkan sisa titipan ke Sukarela saat pinjaman jadi Lunas, tautkan ke angsuran penutup | M | setelah 1e | **Done** — dibundel dengan 1e; tanpanya 1e menelantarkan uang |
| 1i | `LoanPaymentService::settleEarly()` — pakai `payoffAmount()`; tulis `credit_applied` di baris pelunasan; **limpahkan sisa titipan tak terpakai ke Sukarela** | M | setelah 1c | **Done** |
| 1j | `LoanPaymentService::reverse()` — guard tolak pembatalan yang membuat titipan negatif; pesan menyebut nomor angsuran penghalang | M | setelah 1a | **Done** |
| 1k | `Installment::reverseClone()` — salin `credit_applied` dan `session_key` | S | setelah 0a | **Done** — dibundel dengan 1e; tanpanya pembatalan tak memulihkan titipan |
| 1l | `Installment::breakdown()` — baris "Titipan Pokok dipakai" (−) **dan "Titipan Pokok disisihkan" (+)**, plus saldo "Sisa Titipan Pokok"; komponen wajib rekonsiliasi di **kedua** arah; `credit_applied` NULL diperlakukan 0. Kunci `other` dicabut (R12) → `credit_reserved` | M | setelah 0a | **Done** |
| 1m | Perluas properti activity log sesuai tabel Jejak log — **plus tulis payload-nya di bawah kunci yang benar-benar dirender layar** (lihat R22); menulis properti yang tak pernah tampil bukan kontrol, hanya biaya | M | setelah 1e, 1j | **Done** |
| 2a | `InstallmentForm` — prefill & anti-korupsi pakai tagihan efektif; dialog konfirmasi berakibat-angka | L | setelah 1e | **Done** |
| 2b | Blade form angsuran — baris Titipan Pokok; cabut kalimat "dikreditkan ke Simpanan Sukarela" (`:174`) | M | setelah 2a | **Done** |
| 2c | `InstallmentResource` + `CreateInstallment` — prefill `:108`, validasi `:236`, helper `:233`, label `:264` | M | setelah 1e, 1l | **Done** |
| 2d | Layar pembatalan — tampilkan keterkaitan sesi ("satu setoran bersama ANG-…") | S | setelah 1k | **Done** |
| 2e | `LoanDetail` — saldo Titipan Pokok + panel **Riwayat Titipan Pokok** | M | setelah 1a | **Done** |
| 2i | **Laporan agregat Titipan Pokok** — daftar pinjaman bertitipan (terbesar dulu), total koperasi, tagihan kontrak **berdampingan** dengan tagihan efektif; Pengurus-only | M | setelah 1a | **Done** (v28) |
| 3a | **Tulis ulang** `LoanPaymentServiceTest:96-114` | M | setelah 1e | **Done** |
| 3b | **Tulis ulang** `SavingsMutationServiceTest:91-107` | S | setelah 1h | **Done** |
| 3c | **Tulis ulang** `EarlySettlementServiceTest:87` + `EarlySettlementModelTest:82` | M | setelah 1i | **Done** |
| 3d | Test: titipan mengalir lintas beberapa bulan (tabel Design) | M | setelah 1e | **Done** — `TitipanPokokLoanTest` |
| 3e | Test: kedua mode menghasilkan Σ uang & Σ jasa identik | M | setelah 1e | **Done** — `TitipanPokokEquivalenceTest` |
| 3f | Test: invariant `credit_applied == max(0, kontrak − dibayar)` | S | setelah 1e | **Done** — `AllocateInstallmentTest` |
| 3g | Test: idempotensi — klik simpan dua kali tidak menghasilkan baris ganda | M | setelah 1e | **Done** — `InstallmentSessionTest` (dua lapis) |
| 3h | Test: pembatalan — titipan pulih; guard menolak urutan salah; `credit_applied` ikut terbalik | M | setelah 1j, 1k | **Done** |
| 3i | Test: pelunasan dipercepat bertitipan tak menagih dobel | M | setelah 1i | **Done** |
| 3j | Test: setoran yang cukup melunasi seluruh sisa **tidak** diproses sebagai tutup-sekalian | M | setelah 1d | **Done** — `AllocateInstallmentTest` |
| 3k | Test: kuitansi menutup di **kedua** arah — baris yang memakai titipan **dan** baris yang menyisihkannya; baris lama ber-`credit_applied` NULL tetap menutup | M | setelah 1l | **Done** |
| 3l | Test: invariant "Lunas ⇒ titipan 0" | S | setelah 1h | **Done** — beserta bukti uangnya pindah ke Sukarela, bukan sekadar saldo menjawab 0 |
| 3m | Test: pratinjau basi ditolak | M | setelah 1f | **Done** |
| 3n | **Regresi batch**: potong gaji berperilaku persis seperti sebelum perubahan, titipan tak bergerak (`Δ = 0`) | M | setelah 1e | **Done** — dan menemukan R23 |
| 3o | Test: setoran multi-angsuran — bukti benar-benar melekat di **semua** baris, tak ada baris tanpa bukti | M | setelah 1g | **Done** — diverifikasi gagal saat `preservingOriginal()` dicabut |
| 3p | Test penjaga: pinjaman yang sudah punya angsuran **tidak bisa** dibatalkan; pinjaman Dibatalkan bertitipan 0 | S | setelah 1a | **Done** |
| 3r | **Test penjaga R21:** setiap baris yang dibuat `pay()` dan `settleEarly()` mengisi `credit_applied` non-NULL (0 bila tak memakai titipan) — baris ber-NULL sengaja hilang dari saldo, jadi jalur yang lupa mengisinya membuat titipan menguap diam-diam | S | setelah 1e, 1i | **Done** |
| 3q | Test: dengan lubang jadwal (#3 lunas, #2 dibatalkan), Sisa Pokok di layar detail **sama** dengan `settledPrincipal()` | M | setelah 2h | **Done** |
| 3s | Test: laporan agregat — hanya pinjaman bertitipan, urut terbesar, Σ koperasi, tertutup untuk Petugas, dan **bentuk jamak sepakat dengan bentuk tunggal** (penjaga R2) | M | setelah 2i | **Done** (v28) |

**Effort:** S = small (< 1 jam), M = medium (1-3 jam), L = large (> 3 jam), — = observasi/non-code

---

## Key Files

| File | Fungsi |
|---|---|
| `database/migrations/2026_08_28_000001_add_credit_applied_to_installments.php` | **Baru** — jejak audit + penanda sesi + pelebaran `idempotency_key` |
| `app/Models/Loan.php` | `monthlyTotal()`, `overpaymentCredit()`, `effectiveBill()`, `payoffAmount()` — sejajar `settledPrincipal()` |
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
- [ ] **Batch potong gaji: perilaku tidak berubah sama sekali.** `Δtitipan = 0` selama nominalnya tetap sebesar tagihan kontrak. Nominal batch **bisa dinaikkan petugas** (`minValue`, bukan nilai terkunci); bila dinaikkan, titipan memang terbentuk — dan itu perilaku yang benar, bukan pengecualian. *(Klaim "tidak berubah sama sekali" sudah gugur dua kali — R23 dan R2 keempat; yang diuji di sini tinggal jalur pembayaran biasanya.)*
- [ ] Bayar dari saldo simpanan tetap terkunci tepat sebesar tagihan efektif.
- [ ] Pratinjau basi ditolak, bukan disimpan dengan hasil berbeda.
- [ ] Invariant: tak ada pinjaman **Lunas maupun Dibatalkan** dengan titipan ≠ 0; pinjaman yang sudah punya angsuran tetap tak bisa dibatalkan.
- [ ] Setoran multi-angsuran: bukti melekat di **semua** baris — tidak ada baris yang kehilangan lampiran karena berkas sumbernya sudah dipindah.
- [ ] Angka tunggakan di dashboard memakai tagihan efektif; anggota bertitipan tidak dilaporkan menunggak lebih besar dari kewajiban riilnya.
- [ ] Jejak audit menampilkan `credit_applied` dan `session_key` dengan label Indonesia dan format rupiah — bukan nama kolom mentah. **Periksa di dua layar**: detail angsuran *dan* detail pinjaman (event `pembatalan_ditolak` hanya muncul di yang kedua — itu yang membuat R19 sempat tampak tertutup padahal belum).
- [ ] Laporan agregat Titipan Pokok: Σ-nya sama dengan penjumlahan manual baris-barisnya; tertutup untuk Petugas; tagihan kontrak dan efektif tampil berdampingan.
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

**OQ-10 — Baris lama yang kelebihan bayar akan dihitung sebagai titipan, padahal kelebihannya SUDAH dibayarkan ke Sukarela. — DIPUTUSKAN: OPSI B.** `CLOSED`

`overpaymentCredit()` kini hanya menghitung baris ber-`credit_applied` **non-NULL**, yaitu baris yang ditulis oleh fitur ini. Dipilih tanpa menunggu angka produksi karena opsi B **dominan di kedua dunia**: no-op bila produksi bersih, pencegah kerugian bila tidak. Angka produksi turun status dari **gerbang** jadi **verifikasi**.

Keberatan "saldo tak lagi murni turunan" gugur setelah diperiksa: `credit_applied` ditulis sekali saat baris dibuat, tak pernah di-`UPDATE`, dan ikut disalin `reverseClone()` (1k) — penandanya stabil dan tetap benar setelah pembatalan. Saldo tetap murni turunan, atas himpunan baris milik fitur ini.

Kebenaran per kasus baris lama: bayar pas → kontribusinya ke titipan nol, disaring atau tidak hasilnya sama; kelebihan → disaring, benar; kurang bayar → tak pernah ada, ditolak `belowBill()`. Pinjaman campuran baris lama + baru juga benar. Ketiganya dikunci test.

**Harga yang dibayar:** jalur pembuat baris angsuran yang lupa mengisi `credit_applied` akan hilang diam-diam dari saldo. Hari ini pembuatnya hanya `pay()` dan `settleEarly()` — enumerasi yang sudah dikunci ADR ini — dan dijaga test 3r.

<details>
<summary>Uraian masalah aslinya (dicatat untuk reviewer)</summary>

Ditemukan saat implementasi 1c. `overpaymentCredit()` murni turunan dari `amount_paid`, dan `pay()` menyimpan `amount_paid` = **seluruh uang yang diterima**, termasuk kelebihannya — kelebihan itu lalu dikreditkan terpisah ke Simpanan Sukarela. Akibatnya, setiap baris angsuran lama yang membayar di atas tagihan **otomatis muncul sebagai saldo Titipan Pokok**, padahal uangnya sudah lama diserahkan ke anggota dalam bentuk simpanan.

Begitu `payoffAmount()` dan `effectiveBill()` aktif, anggota itu menerima keringanan yang **sama, dua kali**: sekali sebagai saldo Sukarela, sekali lagi sebagai potongan tagihan/pelunasan. Koperasi yang menanggung selisihnya, dan pembukuannya tetap tampak rekonsiliasi.

Non-Goals sudah menyatakan setoran Sukarela lama dibiarkan — tapi itu keputusan tentang **uang yang sudah terlanjur keluar**, bukan tentang apakah baris yang sama boleh dihitung ulang sebagai titipan. Keduanya terlewat dibedakan sampai v11.

Ini **bukan jendela sementara**: baris lama tetap ada selamanya, jadi pelimpahan gandanya permanen sampai pinjaman tersebut lunas.

Baseline dev (2026-08-28, read-only): **0 baris** kelebihan bayar dari 17 angsuran, **0** setoran Sukarela hasil pengalihan. Jadi belum tentu ada paparannya — **angka produksi yang menentukan**, dan itulah yang perlu dijawab.

Tiga jalan, semuanya murah, tapi pilihannya bukan milik implementer:

| Opsi | Cara | Konsekuensi |
|---|---|---|
| **A — Tidak ada paparan** | Query produksi menunjukkan 0 baris kelebihan bayar | Tak perlu apa-apa. Cukup pastikan sebelum Phase 2 rilis |
| **B — Batas waktu** | `overpaymentCredit()` hanya menghitung baris ber-`credit_applied IS NOT NULL` (penanda "ditulis kode baru") | Murah & eksplisit; tapi saldo tak lagi murni turunan dari riwayat |
| **C — Netto** | Kurangi saldo dengan Σ setoran Sukarela hasil pengalihan (`reference_number = installment_number`) | Tetap murni turunan; tapi bergantung pada tautan `reference_number` yang tidak dijamin unik |

**Dipilih B.**

</details>

**OQ-1 — Apa masalah aslinya menurut Mbak Iin?** Background ADR ini menyimpulkan sendiri bahwa masalahnya adalah alur pencairan Sukarela yang ribet. Beliau hanya menyebut aturan. Bila motifnya ternyata lain (mis. anggota bingung membaca kuitansi), yang perlu diperbaiki adalah Background — dan dalam kasus tertentu, sebagian fitur ini mungkin tak diperlukan.

**OQ-2 — Cakupan "hide sukarela".** Diasumsikan hanya jalur kelebihan-bayar → Sukarela. Menyembunyikan produk Simpanan Sukarela akan mematikan fitur Bayar Angsuran dari Simpanan (sumber dananya sukarela-only).

**OQ-3 — Patokan kelebihan = total tagihan, bukan pokok.** Bukan pilihan bebas: `belowBill()` dan [`InstallmentForm:336`](../../app/Livewire/Loan/Installment/InstallmentForm.php) sudah menolak pembayaran di bawah total tagihan. Tetap perlu dikonfirmasi karena kalimat aslinya berbunyi "kelebihan dari pokok".

**OQ-4 — Anggota kehilangan basis SHU?** Di perilaku lama kelebihan jadi Simpanan Sukarela; di desain baru jadi kredit pinjaman yang tak terhitung sebagai simpanan. Dampaknya nol hari ini — [`Dokumentasi_Sistem_Koperasi_v5.md:76`](../Dokumentasi_Sistem_Koperasi_v5.md) menyatakan SHU **belum diterapkan**. Tapi masukan Mbak Yasmin poin 3 bicara soal pembagian SHU tahunan.

**OQ-5 — Guard pembatalan mengekang?** Apakah pengurus pernah perlu membatalkan satu angsuran lama tanpa mengusik yang sesudahnya?

**OQ-6 — Petugas mengunci Pengurus.** ~~Guard pembatalan berarti entri Petugas bisa memblokir tindakan Pengurus (`reverse_loan`).~~ **Premisnya keliru (v25):** pembatalan angsuran dijaga `reverse_installment`, yang dipegang Petugas juga — jadi tak ada pembalikan arah kendali; Petugas yang terkunci bisa membuka sendiri dengan membatalkan angsuran penghalang. Yang tersisa dari kekhawatiran ini justru soal pemisahan tugas, dipindah ke §Hak akses.

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
| **R14** | **Petugas loket mencatat tagihan efektif sementara anggota menyerahkan tagihan kontrak, lalu mengantongi selisihnya** | **Paparan sebesar titipan anggota. Pembukuan tetap rekonsiliasi sempurna — tidak terdeteksi oleh audit mana pun** | **DITERIMA sebagai risiko sadar (OQ-0).** Tidak ada pencegahan teknis — manfaat fitur dan celahnya adalah hal yang sama. Pendeteksian: kuitansi ber-Sisa Titipan Pokok, Panel Riwayat, activity log, dan **Laporan agregat Titipan Pokok** (item 2i). Tiga yang pertama hanya bisa dipakai setelah pemeriksa curiga pada anggota tertentu — itu konfirmasi, bukan pendeteksian; laporan agregat adalah bagian yang memunculkan. Akses log dikembalikan ke Pengurus lewat `access_activity_log` (v26); tanpa itu satu dari empat kanal tak terjangkau orang yang bertugas memakainya. Gerbang Pengurus atas pemakaian titipan tetap ditolak karena menyulitkan operasional |
| **R21** | **Baris angsuran lama yang kelebihan bayar dihitung sebagai Titipan Pokok, padahal kelebihannya sudah dikreditkan ke Simpanan Sukarela** | **Anggota menerima keringanan yang sama dua kali — sekali sebagai saldo Sukarela, sekali sebagai potongan tagihan/pelunasan. Koperasi menanggung selisihnya, dan pembukuan tetap tampak rekonsiliasi. Permanen, bukan jendela sementara** | **DITUTUP (OQ-10, opsi B)** — `overpaymentCredit()` hanya menghitung baris ber-`credit_applied` non-NULL. **Paparannya ternyata nol secara konstruksi (v27):** sistem belum pernah naik produksi, jadi tak ada baris angsuran lama di sana. Saringannya **tetap dipasang** — ia yang menjaga risiko turunan yang masih hidup: jalur pembuat baris yang lupa mengisi kolom itu membuat barisnya hilang diam-diam dari saldo (test 3r), dan database dev/staging yang isinya bisa terbawa naik |
| ~~R22~~ | **DITUTUP (item 1m).** Properti activity log bergaya datar (`withProperties([...])`) tidak dirender oleh layar mana pun — baik panel audit Livewire (`InteractsWithAuditTrail::auditDiff()`) maupun `ActivityResource` hanya membaca `properties.attributes` dan `properties.old` | Seluruh tabel **Jejak log** di Design — mode, saldo sebelum/sesudah, kunci sesi, angsuran penghalang — tertulis di database tapi **tak terlihat siapa pun di UI**. Padahal jejak log adalah salah satu dari tiga kanal pendeteksian yang jadi syarat diterimanya R14 | Ditemukan saat implementasi 2g. Kolom model (`credit_applied`, `session_key`) AMAN — ia ikut event `created` lewat `logFillable()` dan dirender normal; yang tak terlihat hanya properti event kustom. **Diperbaiki:** seluruh payload event kustom `LoanPaymentService` kini dibungkus `attributes`, sehingga terender di kedua layar tanpa menyentuh `ReverseTransaction` yang generik |
| **R23** | **Penjaga Pelunasan Dipercepat ikut menyala di jalur potong gaji, dan batch MENELAN lemparannya diam-diam** (`catch (CannotProcessPayment) { $skipped++; }`) | **Gaji anggota sudah terpotong, angsurannya tak pernah tercatat.** Pemicunya sempit tapi nyata: titipan cukup besar + dua angsuran tersisa membuat jumlah pelunasan turun di bawah tagihan kontrak satu bulan. Terverifikasi reproducible: `created: 0, skipped: 1` | **DIPERBAIKI** — `pay()` menerima `redirect_to_settlement`, dan jalur batch mematikannya. Nominal payroll adalah angka kontrak yang ditetapkan bendahara, bukan uang sekaligus yang diserahkan anggota di loket; tak ada yang perlu dilindungi di sana. Dikunci test 3n. **Ditemukan justru oleh test regresi batch — klaim "batch tidak disentuh sama sekali" ternyata tidak benar** |
| **R24** | **Guard pembatalan (item 1j) buta terhadap Titipan Pokok yang sudah dimakan Pelunasan Dipercepat** | **Batalkan setoran yang membuat titipan, biarkan baris pelunasannya berdiri: `overpaymentCredit()` mengecualikan baris pelunasan, jadi saldo baris biasa kembali 0 dan guard meluluskannya. Potongan pada pelunasan sudah terlanjur diterima — koperasi menanggung selisihnya. Terverifikasi reproducible: pelunasan 1.999.000 diterima, pembatalan lolos, sisa pokok kembali 4.000.000** | **DIPERBAIKI (v25)** — guard memakai `Loan::overpaymentCreditNetOfSettlement()`; blocker query mencari baris pelunasan lebih dulu supaya pesannya menunjuk yang benar. Urutan yang sah (batalkan pelunasan dulu) tidak terhalang: Σ_signed-nya kembali nol dengan sendirinya. Dikunci `TitipanPokokSettlementReversalTest` |
| **R25** | **Event `pembatalan_ditolak` tak pernah benar-benar tersimpan** | **Ditulis di dalam `DB::transaction()` lalu lemparannya me-rollback semuanya, baris `activity_log` termasuk. Klaim v16 bahwa penolakan "menulis event `pembatalan_ditolak`" salah sejak awal — dan justru inilah peristiwa yang paling perlu terlihat: bentuknya sama persis dengan percobaan menarik kembali uang yang sudah terpakai** | **DIPERBAIKI (v25)** — payload dititipkan pada `CannotReverseTransaction::$auditPayload`, ditulis `reverse()` **setelah** rollback. Dikunci test |
| **R26** | **Jejak `pembatalan_angsuran` menenangkan padahal ada uang bergerak** | Pada pinjaman yang dilunasi dipercepat, `credit_before` dan `credit_after` sama-sama `0.00` (guard status Lunas) sementara titipan anggota sesungguhnya sudah dimakan potongan pelunasan. Pemeriksa yang membuka log melihat transaksi yang tampak tak menggerakkan apa pun | **DIPERBAIKI (v25)** — properti `credit_in_settlement` ditambahkan ke `pembatalan_angsuran` dan `pembatalan_ditolak`, beserta labelnya di `LoanDetail` dan `InstallmentDetail` |
| **R27** | **Batch potong gaji melaporkan jumlah baris yang dilewati, bukan barisnya** | Pertanyaan yang benar-benar diajukan setelah potong gaji adalah *gaji siapa yang terpotong tapi angsurannya tak tercatat*. "3 dilewati" tak menjawabnya, dan barisnya memang tak meninggalkan jejak di mana pun | **DIPERBAIKI (v25)** — `run()` mengembalikan dan mencatat `skipped_rows` (nomor pinjaman, nama anggota, sebab), dibungkus `attributes` agar terender (bentuk R22) |
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
| Security review | security-reviewer | **BLOCK.** Dua pemblokir, keduanya diverifikasi ulang sendiri sebelum dikerjakan dan keduanya nyata: guard pembatalan buta terhadap titipan yang dimakan Pelunasan Dipercepat (**R24**, terverifikasi reproducible), dan salinan **keempat** rumus payoff di pintu batch — satu-satunya yang lupa memotong titipan, dan pintu yang angkanya dipakai memotong gaji. Temuan turunan: jejak `pembatalan_angsuran` menenangkan padahal ada uang bergerak (R26), batch tak mencatat baris mana yang dilewati (R27), `$modeConfirmed` bisa dikirim klien, `redirect_to_settlement` kunci array bebas, otoritas pelunasan batch hanya di halaman. Ikut ketahuan saat menulis testnya: event `pembatalan_ditolak` **tak pernah tersimpan** karena di-rollback lemparannya sendiri (**R25** — koreksi klaim v16). Atas OQ-0/R14 sendiri: pendeteksian dinilai belum memadai selama Pengurus tak bisa membuka log aktivitas (gate `manage-system` = super_admin) dan tak ada tampilan agregat titipan — **keduanya keputusan pemilik produk, belum dikerjakan** | 2026-09-01 |
| Deploy review | deploy-reviewer | **DEPLOY WITH CONDITIONS.** Urutan migrasi-lalu-perilaku disetujui, tapi hanya bila ditegakkan lewat maintenance window — `git pull` membuat kode baru aktif seketika sementara `migrate` menyusul, dan di jendela itu SELURUH pencatatan angsuran gagal keras (kolom belum ada + kunci 38 karakter ke `char(36)`). Rollback sebagaimana ditulis ADR **ditolak** sampai diamandemen: `down()` rusak dan half-apply, revert parsial membuat kuitansi tak bisa dicetak, dan rollback membuka R21 dari arah sebaliknya. Juga: `ALTER … MODIFY varchar(64)` selalu `ALGORITHM=COPY` (rebuild tabel + lock tulis); polling widget 5 detik mengalikan N+1 tagihan efektif; `down()` dan polling **sudah diperbaiki**, sisanya masuk runbook rilis | 2026-08-29 |
| Implementation | implementer / human | in progress — **SELURUH Key Items selesai** (0a, 1a–1m, 2a–2h, 3a–3r), plus perbaikan temuan kedua review (R24–R27 dan pengerasan v25). Sisa murni non-kode: dua keputusan pemilik produk (akses log Pengurus, tampilan agregat titipan), verifikasi produksi, dan runbook rilis | 2026-09-01 |
| Review | reviewer | pending | — |

**Ronde**: 7
**Skipped stages**: `data-analyst` (alasan gugur — lihat barisnya)
**Calibration notes**: Pola kesalahan berulang yang teridentifikasi: **menyatakan perilaku dinamis tanpa menghitungnya lebih dulu**. Terjadi tiga kali pada topik yang sama (perilaku titipan pada potong gaji) — v2 mengklaim membengkak, v3 mengklaim terpakai, keduanya salah; baru v4 menghitung `Δ = uang − kontrak` dan mendapat jawaban benar (tetap). Juga dua kali melewatkan pintu masuk (R1: service batch; R2: dua halaman Filament), baru teratasi di R3 lewat enumerasi dari **mesin** (`grep` pemanggil service + `Installment::create` + `routes/api.php`), bukan dari daftar layar. Pelajaran: (1) hitung sebelum mengklaim dinamika; (2) enumerasi dari mesin, bukan dari UI.

---

## Changelog

- **2026-09-01 v30**: **Bahan uji coba manual.** `TitipanPokokDemoSeeder` membangun satu OPD berisi enam pinjaman yang masing-masing **sudah berada di keadaan yang dibutuhkan satu skenario** — termasuk keadaan yang tak bisa dicapai lewat UI biasa (T5: titipan melebihi sisa pokok, hanya terbentuk lewat jalur potong gaji). Seluruhnya dibangun lewat `LoanPaymentService` yang asli, bukan insert langsung, jadi status jadwal, saldo, jejak audit, dan draft pengembalian terbentuk persis seperti input petugas. Angkanya dibuat bulat (12.000.000 / 12 bulan → tagihan 1.090.000, pokok 1.000.000) supaya potongan titipan bisa dihitung di kepala saat menguji.

  [`docs/uat/titipan-pokok.md`](../uat/titipan-pokok.md) memuat 8 bagian skenario beserta **hasil yang diharapkan berupa angka**, bukan deskripsi. Angka-angka itu dikunci `TitipanPokokDemoSeederTest` — bila seeder bergeser tanpa dokumennya ikut bergeser, testnya merah. Tanpa penguncian itu penguji akan melaporkan "tidak sesuai" atas sistem yang benar, dan kepercayaan pada seluruh daftar ikut hilang.

  Dua skenario ditandai **wajib**: §4.1 (penolakan pembatalan harus meninggalkan jejak — R25, jejaknya sempat tak pernah tersimpan) dan §5.4 (guard pelunasan — R24, lubang uang yang terverifikasi; bila langkah pertamanya berhasil, jangan diteruskan ke produksi).

  Suite penuh hijau (752 passed, 4 skipped).
- **2026-09-01 v29**: **Sinkronisasi bookkeeping** — Rollout Plan masih menyebut Phase 1 "menunggu OQ-1/OQ-2" dan Phase 2 "pintu UI (2a–2c) belum", padahal keduanya selesai berbulan… tidak, sejak v22. Keduanya ditandai **Done**; OQ-1/OQ-2 dinyatakan eksplisit **bukan penghalang** (soal ketepatan Background dan penamaan, bukan benar-tidaknya mesin). Phase 3 ditandai hanya bisa jalan setelah rilis pertama.

  Dua checklist Phase 1→2 dicentang (suite hijau, invariant `credit_applied`). Satu **sengaja dibiarkan terbuka**: persetujuan `security-reviewer` atas pelemahan lantai `belowBill()`. Reviewnya sudah dijalankan dan seluruh keberatannya sudah dikerjakan — termasuk dua yang dulu berstatus keputusan pemilik produk (akses log Pengurus v26, laporan agregat v28) — tapi verdict-nya masih BLOCK sampai ada review ulang. Mencentangnya sendiri berarti menandai persetujuan yang tak pernah diberikan.

  Verification list ditambah dua baris yang temuan review buat perlu: periksa jejak audit di **dua** layar (event `pembatalan_ditolak` dicatat pada pinjaman, bukan pada angsuran — itu yang membuat R19 sempat tampak tertutup padahal belum), dan uji petik laporan agregat. Catatan batch dikoreksi: klaim "perilaku tidak berubah sama sekali" sudah gugur dua kali, yang tersisa untuk diuji tinggal jalur pembayaran biasanya.
- **2026-09-01 v28**: **Item 2i — laporan agregat Titipan Pokok.** Menutup celah terakhir yang ditandai `security-reviewer`: seluruh pengaman R14 yang diterima sadar bersifat pendeteksian, tapi ketiganya (kuitansi, panel riwayat, jejak log) hanya bisa dipakai **setelah** pemeriksa curiga pada anggota tertentu. Tak ada satu pun layar yang memunculkan kecurigaan itu; dengan ratusan pinjaman berjalan, mencari yang janggal berarti membuka ratusan halaman — yang praktis berarti tak ada yang mencari.

  Halaman ini menjawab tiga hal yang sebelumnya tak punya tempat: **berapa total titipan mengendap di seluruh koperasi**, **pinjaman mana saja yang menahannya** (urut terbesar), dan **berapa selisih tagihan kontrak vs tagihan efektif** tiap baris — selisih itu persis nominal yang bisa dikantongi bila petugas menerima uang sebesar kontrak lalu mencatat yang efektif, jadi keduanya ditampilkan berdampingan alih-alih hanya yang efektif.

  **Pengurus-only (`access_laporan_titipan`), bukan Petugas.** Ini kanal pemeriksaan atas risiko yang pelakunya bisa Petugas; menaruhnya di tangan yang diperiksa menghapus gunanya. Bukan soal kerahasiaan.

  **Satu keputusan implementasi yang menahan R2 dari bentuk barunya.** Laporan ini membaca ratusan pinjaman sekaligus, dan cara termudahnya adalah menulis satu query `GROUP BY` tersendiri — yaitu menyalin rumus saldo ke tempat kedua, persis pola yang sudah empat kali menggigit ADR ini. Sebagai gantinya rumusnya dijadikan **bentuk jamak** (`Loan::overpaymentCredits(iterable)`, satu query agregat), dan `overpaymentCredit()` yang lama diubah jadi pemanggilan bentuk jamak untuk satu pinjaman. Rumusnya kini hidup di satu tempat saja, dan test 3s mengunci bahwa keduanya sepakat.

  Baris laporan adalah **per pinjaman**, bukan per anggota: titipan melekat pada pinjaman dan dua pinjaman berjalan punya kantong terpisah yang tak bisa saling memakai — menggabungkannya akan menampilkan angka yang tak pernah bisa dipakai membayar apa pun. Read-only penuh; ekspor PDF/Excel **sengaja tidak dibuat** (di luar cakupan; tambahkan bila pengurus memintanya).

  Suite penuh hijau (742 passed, 4 skipped).
- **2026-09-01 v27**: **Sistem belum pernah naik produksi — beberapa syarat rilis gugur, dan satu asumsi jadi lebih kuat.**

  **Yang gugur.** Seluruh premis "data lama" hilang: tak ada baris angsuran produksi yang pernah kelebihan bayar, jadi tak ada paparan R21 untuk diukur dan tak ada anggota yang perlu diberitahu bahwa titipannya akan tampil `0`. Dua item checklist verifikasi produksi ditandai gugur, bukan dicoret — supaya jelas bahwa ia pernah jadi syarat dan kenapa berhenti jadi syarat. Syarat maintenance window dari `deploy-reviewer` juga melemah: jendela berbahaya yang ia lindungi (kode baru aktif sementara `migrate` menyusul, seluruh pencatatan angsuran gagal keras) tidak punya pengguna pada pemasangan baru. `php artisan down` tetap dianjurkan karena murah; yang tetap syarat hanyalah `migrate` selesai sebelum trafik pertama.

  **Yang justru menguat.** Asumsi "kurang bayar tak pernah ada karena ditolak `belowBill()`" kini berlaku penuh — seluruh baris angsuran produksi akan dibuat oleh kode yang sudah memuat lantai itu, tak ada pengecualian historis.

  **Yang TIDAK berubah.** Saringan opsi B (`credit_applied IS NOT NULL`) tetap dipasang. Paparan historisnya nol, tapi ia menjaga dua hal yang masih hidup: jalur pembuat baris yang lupa mengisi kolom itu (test 3r), dan database dev/staging yang isinya bisa terbawa naik. Larangan `UPDATE credit_applied = 0` juga tetap, dengan alasan yang sama. Perbaikan `down()`, aturan revert all-or-nothing, dan R24–R27 tidak tersentuh oleh ini sama sekali — semuanya soal perilaku kode, bukan soal data lama.

  Ditambahkan ke checklist: **seed permission setelah deploy** (`db:seed --class=RolePermissionSeeder`) — `access_activity_log` dari v26 tidak muncul sendiri.
- **2026-09-01 v26**: **Akses log aktivitas dikembalikan ke permission + role.** Rutenya dijaga gate `manage-system` (= `hasRole('super_admin')`), sementara menu sidebar-nya sudah tampil untuk Pengurus — jadi orang yang seharusnya memakai kanal pendeteksian R14 melihat menunya lalu kena 403. Permission baru `access_activity_log` dipegang Pengurus; rute log dipisah dari grup `manage-system` (membaca jejak ≠ mengelola sistem), dan nav memakai `can()` bukan `hasAnyRole()`. Pengguna & Peran tetap super_admin. Dikunci `ActivityLogAccessTest`.

  **Koreksi v25:** klaim "pintu 4 bukan pintu mati" salah — panel Filament tidak didaftarkan sama sekali di `bootstrap/providers.php`, jadi pintu 2 dan 4 memang tak punya rute. Yang benar dari kekhawatiran itu: bila panel dihidupkan lagi, keduanya masuk lagi tanpa pernah diperiksa ulang.
- **2026-09-01 v25**: **`security-reviewer` dijalankan — verdict BLOCK.** Kedua temuan pemblokir diverifikasi ulang sendiri sebelum dikerjakan; keduanya nyata.

  **R24 — guard pembatalan buta terhadap titipan yang dimakan pelunasan.** Anggota bertitipan melunasi dipercepat, potongan titipan mengecilkan jumlah pelunasan, pinjaman jadi Lunas. Petugas lalu membatalkan setoran yang **membuat** titipan itu dan membiarkan baris pelunasannya berdiri. `overpaymentCredit()` mengecualikan baris pelunasan, jadi saldo baris biasa kembali 0, guard tak melihat apa pun, dan pembatalan lolos — sementara potongan pada pelunasan sudah terlanjur diterima. Diperbaiki dengan `Loan::overpaymentCreditNetOfSettlement()`; baris pelunasan kini juga dicari sebagai blocker dan didahulukan, karena menyebut angsuran biasa di pesan hanya mengirim petugas ke jalan buntu. Urutan yang sah tidak terhalang: begitu pelunasannya ikut dibatalkan, Σ_signed-nya nol dan pengurangan itu hilang sendiri.

  **R25 ikut ketahuan saat menulis testnya, dan ini koreksi terhadap klaim v16.** Event `pembatalan_ditolak` ditulis di dalam `DB::transaction()`, lalu lemparannya me-rollback baris `activity_log`-nya sendiri. Jadi sejak item 1j selesai, **penolakan pembatalan tidak pernah meninggalkan jejak apa pun** — padahal itu peristiwa yang bentuknya sama persis dengan percobaan menarik kembali uang yang sudah terpakai. Payload kini dititipkan pada exception dan ditulis setelah rollback.

  **R2 muncul untuk keempat kalinya, di pintu yang paling berakibat.** [`BatchInstallmentPayment::buildLoanLine()`](../../app/Livewire/Loan/Installment/BatchInstallmentPayment.php) menghitung jumlah pelunasan dengan rumus lokalnya sendiri — satu-satunya salinan yang lupa memotong Titipan Pokok. Angka itulah yang dipakai bendahara OPD untuk memotong gaji, jadi anggota bertitipan dipotong lebih besar dari yang ia utang; kelebihannya memang berakhir di Simpanan Sukarela, tapi ia tak pernah menyetujui uang itu pindah ke sana. Diarahkan ke `Loan::payoffAmount()`. Tabel §Satu mesin, empat pintu dikoreksi: klaim "batch tidak disentuh" kini gagal dua kali (R23 dan ini), dan cara gagalnya sama — yang dienumerasi adalah pembuat baris angsuran, sementara pintu 3 tidak membuat baris, ia menyodorkan angka.

  **R26/R27 — dua kanal pendeteksian R14 diperbaiki.** `credit_in_settlement` ditambahkan ke jejak pembatalan (sebelumnya `credit_before = credit_after = 0.00` pada pinjaman yang dilunasi — menenangkan padahal ada uang bergerak), dan batch potong gaji kini mencatat **daftar** baris yang dilewati beserta sebabnya, bukan cuma angkanya. Panel Riwayat Titipan Pokok dulu melabeli seluruh sisa titipan sebagai "Dilimpahkan ke Simpanan Sukarela" termasuk bagian yang dimakan pelunasan — kini dipisah jadi dua baris. Peta label audit `LoanDetail` dilengkapi; **R19 ternyata belum benar-benar tertutup** — event `pembatalan_ditolak` dicatat pada pinjaman, bukan pada angsuran, jadi peta di `InstallmentDetail` tak menjangkaunya.

  **Dua pengerasan.** `$mode`/`$modeConfirmed` di `InstallmentForm` diberi `#[Locked]` — sebagai properti publik biasa, klien tinggal mengirim `modeConfirmed = true` untuk melewati dialog yang merupakan satu-satunya tempat anggota diperlihatkan bahwa uangnya menutup lebih dari satu bulan. Dan `redirect_to_settlement` dipindah dari kunci `$input` jadi **parameter bernama** `pay(..., bool $redirectToSettlement)`; sebagai kunci array ia bisa disisipkan pemanggil mana pun tanpa terlihat di tanda tangan metode. Otoritas `settle_early_installment` kini ditegakkan **di dalam** `BatchInstallmentPaymentService` juga, bukan hanya di halaman Livewire — pola yang sama dengan `pay_installment_from_savings`.

  **Koreksi §Hak akses.** ADR ini menulis "pembatalan angsuran (`reverse_loan`) tetap Pengurus". Salah: baris angsuran biasa dijaga `reverse_installment`, yang dipegang **Petugas juga**. OQ-6 ("Petugas mengunci Pengurus") karena itu premisnya gugur. Yang tersisa adalah soal pemisahan tugas — Petugas memegang catat + batalkan + batch sekaligus, sudah begitu sebelum ADR ini, dicatat sebagai calon ADR tersendiri.

  Suite penuh hijau (730 passed, 4 skipped).
- **2026-08-29 v24**: **`deploy-reviewer` dijalankan — DEPLOY WITH CONDITIONS.** Dua temuan langsung diperbaiki di kode:

  **`down()` migrasi rusak.** Ia menyempitkan `idempotency_key` kembali ke `char(36)` padahal setoran multi-angsuran menyimpan kunci 38 karakter. `MODIFY` gagal, DDL MySQL tidak transaksional, dan `Migrator::runDown()` baru menghapus catatan migrasi setelah `down()` selesai — jadi kolomnya hilang sementara migrasi masih dianggap ter-apply, dan `migrate` tak mengembalikannya. Kini `down()` tidak membalik pelebaran sama sekali; membiarkannya `varchar(64)` aman karena UUID 36 karakter muat di dalamnya.

  **Polling widget 5 detik.** Bawaan Filament, tak pernah di-override, dan angka tunggakan kini memanggil `overpaymentCredit()` per pinjaman — satu tab dashboard yang dibiarkan terbuka mengulang seluruh rangkaian itu tiap 5 detik. Dimatikan di kedua widget; angka tunggakan tak pernah berubah secepat itu.

  **Section Rollback diamandemen** — versi lama menyebutnya "tidak sepenuhnya bersih", dan itu terlalu ringan: `migrate:rollback` dilarang, revert kode wajib all-or-nothing (kunci `other` dicabut, revert parsial membuat kuitansi tak bisa dicetak), dan rollback membuka R21 dari arah sebaliknya. Ditambah larangan permanen: jangan pernah `UPDATE credit_applied = 0` pada baris pra-rilis.

  **Syarat rilis yang belum bisa saya penuhi sendiri:** urutan deploy wajib memakai maintenance window (`php artisan down` sebelum `git pull`) — tanpa itu ada jendela di mana kode baru hidup di atas skema lama dan seluruh pencatatan angsuran gagal keras. Plus pengukuran paparan produksi, yang butuh akses DB produksi.
- **2026-08-29 v23**: **Seluruh Key Items selesai** — enam item test terakhir (3b, 3c, 3e, 3n, 3p, 3r) tuntas.

  **R23 — dan ini temuan terpenting sepanjang implementasi.** Test regresi batch (3n) langsung membongkar klaim ADR sendiri bahwa "batch tidak disentuh sama sekali": penjaga Pelunasan Dipercepat dari item 1d ikut menyala di jalur potong gaji, dan `BatchInstallmentPaymentService` **menelan lemparannya diam-diam** lewat `catch (CannotProcessPayment) { $skipped++; }`. Akibatnya **gaji anggota sudah terpotong tapi angsurannya tak pernah tercatat**. Terverifikasi reproducible sebelum perbaikan: `created: 0, skipped: 1`. Pemicunya sempit tapi nyata — titipan cukup besar ditambah dua angsuran tersisa membuat jumlah pelunasan turun di bawah tagihan kontrak satu bulan. Diperbaiki: `pay()` menerima `redirect_to_settlement`, jalur batch mematikannya; nominal payroll adalah angka kontrak yang ditetapkan bendahara, bukan uang sekaligus yang diserahkan anggota di loket, jadi tak ada yang perlu dilindungi di sana.

  **3e** menguji klaim inti yang selama ini hanya ada di dokumen: kedua mode menghasilkan Σ uang, Σ jasa, dan akrual Tabungan Berjangka yang **identik** — dijalankan sampai kedua pinjaman Lunas, bukan sekadar satu setoran. **3p** menahan pintu R18 (`canCorrect()` menolak pinjaman berangsuran). **3r** mengunci bahwa kedua pembuat baris selalu mengisi `credit_applied`; jalur yang lupa membuat titipan menguap diam-diam. **3b** dan **3c** ditulis ulang — label "Pengalihan kelebihan dana" tetap sama tapi artinya berubah (kini hanya lahir saat pinjaman ditutup), dan pelunasan bertitipan kini diuji tidak menagih dobel.
- **2026-08-29 v22**: Item **2c, 2d, 2f, 2h selesai — seluruh Key Items berkode selesai.**

  **2c** — pintu manual kedua: prefill, lantai validasi, dan helper rincian di `InstallmentResource` semuanya pindah ke tagihan efektif; helper text berhenti menjanjikan kelebihan bayar ke Sukarela. **2d** — layar pembatalan menampilkan "Satu setoran bersama ANG-…"; memberi tahu, bukan memaksa, sesuai keputusan Design.

  **2f** — angka tunggakan memakai tagihan efektif di tiga tempat. Titipan dikuras **berurutan per pinjaman** lewat `LoanArrearsService::effectiveBills()`, bukan dipotong per baris: titipan satu kantong per pinjaman, jadi memotongnya di setiap baris tertunggak akan melaporkan tunggakan lebih kecil dari kenyataan — arah kesalahan yang merugikan koperasi. Hasil per barisnya dimemoisasi per request; tanpa itu tabel 10 baris memicu puluhan query.

  **2h / OQ-9 ditutup** — `remainingAfter()` kini **mendelegasikan** ke `settledPrincipal()`. Varian "berbasis jumlah sampai baris ini" sempat ditulis dan justru melahirkan angka KETIGA: baris pembalik bernomor lebih besar daripada angsuran yang dilihat, sehingga pembatalannya tak terhitung dan layar kembali menampilkan sisa pokok yang terlalu kecil. Satu sumber menutup divergensinya untuk selamanya. Item **3q** ikut selesai.

  **Bug lama kedua yang tersingkap, di luar cakupan ADR:** `settlementPreview()` di `InstallmentForm` menghitung payoff sendiri — anggota bertitipan akan MELIHAT jumlah pelunasan yang berbeda dari yang DITEGAKKAN `settleEarly()`. Bentuk R2 untuk ketiga kalinya; dialihkan ke `payoffAmount()`.

  **Seluruh item implementasi (0a, 1a–1m, 2a–2h) selesai.** Enam item **test** masih terbuka dan sengaja tidak diklaim selesai: **3b**, **3c** (tulis ulang empat berkas test lama — semuanya hijau apa adanya, tapi belum ditulis ulang untuk mencerminkan Titipan Pokok), **3e** (kedua mode menghasilkan Σ uang & Σ jasa identik), **3n** (regresi batch satu siklus), **3p** (penjaga `canCorrect()`), **3r** (penjaga R21 pada `settleEarly()`). Suite penuh hijau (709 passed).
- **2026-08-29 v21**: Item **2a, 2b, 1f selesai — pintu loket akhirnya terbuka.** Petugas kini bisa memilih mode `tutup_sekalian`; sebelum ini mesinnya jalan tapi separuh permintaan asli tak pernah sampai ke loket.

  Prefill, lantai anti-korupsi, dan kunci tepat-tagihan jalur bayar-dari-simpanan semuanya pindah ke **tagihan efektif**. Panel tagihan menampilkan kontrak → titipan dipakai → tagihan bulan ini, plus sisa titipan anggota; kalimat "dikreditkan ke Simpanan Sukarela" dicabut (R12).

  **Dialog berakibat-angka** muncul hanya bila sisa uang cukup menutup angsuran berikutnya — pembulatan biasa tak memunculkan apa pun. Kedua pilihan menyebut angsuran mana yang lunas, sisa titipannya berapa, dan **tagihan bulan-bulan berikutnya dalam rupiah**. Memilih = menyetujui: langsung tersimpan, tanpa klik kedua. `1f` dibundel karena dialog tanpanya justru berbahaya — akibat yang dikonfirmasi di depan anggota bisa berbeda dari yang tersimpan; kini saldo titipan dikirim balik sebagai versi dan ditolak bila bergeser di dalam lock.

  **Dua rumus duplikat lagi dicabut:** `settlementPreview()` di form menghitung payoff sendiri, sehingga anggota bertitipan akan melihat jumlah pelunasan yang berbeda dari yang ditegakkan `settleEarly()` — persis bentuk R2, kini dialihkan ke `payoffAmount()`.

  **Bug lama yang tersingkap:** aturan validasi `bukti` di jalur `pay()` tidak punya `nullable`, sehingga `file` ikut dijalankan atas nilai null dan **pembayaran tunai tanpa unggahan selalu ditolak** — kasus paling lazim di loket. Jalur `settle()` di berkas yang sama sudah benar; jalur ini terlewat. Tak pernah ketahuan karena seluruh test lama kebetulan selalu mengisi bukti. Diperbaiki; **di luar cakupan ADR ini, ditandai agar reviewer tahu kenapa ada perubahan validasi**. Item **3m** ikut selesai. Suite penuh hijau (701 passed).
- **2026-08-29 v20**: Item **2e dan 1m selesai — kanal pendeteksian ketiga atas R14 kini benar-benar ada.**

  **2e** — panel **Riwayat Titipan Pokok** di halaman detail pinjaman, plus saldo berjalan. Seluruhnya diturunkan dari riwayat angsuran yang sudah ada: gerak per baris `Δ = uang diterima − tagihan kontrak`, dengan tanda dibalik untuk baris pembalik. Bentuk itu menangani pembatalan dengan sendirinya dan Σ Δ persis sama dengan `overpaymentCredit()` — dikunci test. Baris ber-Δ nol dilewati; ia tak menggerakkan saldo dan hanya jadi derau. Panel disembunyikan pada pinjaman yang tak pernah bertitipan. Ditambah **baris penutup sintetis** "Dilimpahkan ke Simpanan Sukarela" saat pinjaman ditutup — tanpanya tabel berhenti pada saldo yang secara fisik sudah tidak ada, dan pertanyaan *kapan habis* justru tak terjawab.

  **1m + R22 ditutup** — seluruh payload event kustom `LoanPaymentService` kini dibungkus `attributes`, satu-satunya bentuk yang dirender panel audit Livewire maupun `ActivityResource`. `ReverseTransaction` yang generik sengaja **tidak** disentuh: ia dipakai simpanan dan belanja juga, jadi mengubah bentuknya di sana adalah perubahan di luar cakupan ADR ini. Sebagai gantinya `reverse()` menulis event `pembatalan_angsuran` sendiri, dengan saldo titipan sebelum & sesudah — saldo "sebelum" dibaca sebelum baris pembalik dibuat, sesudah itu angkanya sudah jadi "sesudah". Properti baru: `schedules_closed`, `credit_exhausted` (penanda titipan habis, menempel di setoran yang menghabiskannya — bukan event terpisah), `credit_leftover_to_sukarela` di baris pelunasan. Semuanya berlabel Indonesia dan berformat rupiah. Suite penuh hijau (689 passed).
- **2026-08-29 v19**: Item **2g selesai** — `credit_applied`, `session_key`, dan properti Titipan Pokok lain kini punya label Indonesia dan format rupiah di panel audit; nama kolom mentah tak lagi bocor ke layar pemeriksa. Ikut didaftarkan `seq`, yang selama ini luput karena `pay()` menulis properti bernama `seq` sementara peta hanya mengenal `installment_seq`.

  **Temuan baru: R22 — separuh tabel Jejak log tidak terlihat siapa pun.** Panel audit Livewire (`InteractsWithAuditTrail::auditDiff()`) dan `ActivityResource` sama-sama hanya membaca `properties.attributes` dan `properties.old`. Properti datar yang ditulis `withProperties([...])` pada event kustom `angsuran` dan `pembatalan_ditolak` — mode, saldo titipan sebelum & sesudah, kunci sesi, angsuran penghalang — **tersimpan di database tapi tak dirender di mana pun**. Kolom model sendiri aman: ia ikut event `created` lewat `logFillable()`. Konsekuensinya untuk item **1m**: memperluas properti log saja tidak cukup — payload-nya harus ditulis di bawah kunci yang memang dirender, atau kanal pendeteksian ketiga atas R14 hanya ada di atas kertas. Item 1m diperbarui untuk mencakup itu.
- **2026-08-29 v18**: Item **1l selesai** — kuitansi kini menutup di **kedua** arah (R16): `pokok + jasa + tab − dipakai + disisihkan = total diterima`, dengan "dipakai" dibaca dari `credit_applied` dan tidak dihitung ulang. Kunci `other` **dicabut** dan diganti `credit_reserved` (R12): uang itu bukan lagi "Kelebihan Bayar" yang berangkat ke Sukarela, melainkan Titipan Pokok yang mengendap di pinjaman — memakai nama lama menyesatkan pengurus. Ditambah `credit_balance` sebagai "Sisa Titipan Pokok", yang menjawab *kapan habis*.

  **Aturan penutupan yang ditetapkan saat implementasi:** saldo pada baris TERAKHIR pinjaman yang sudah Lunas/Dibatalkan dilaporkan **0**, karena sisa titipan memang sudah dilimpahkan ke Sukarela di baris itu (1h/1i). Tanpa aturan ini kuitansi penutup menampilkan titipan yang secara fisik sudah tidak ada — angka salah pada dokumen yang diserahkan ke anggota, persis kelas kesalahan yang R16 lahir darinya. Urutan riwayat memakai `installment_number` yang monoton dan zero-padded, sehingga urutan leksikalnya sama dengan urutan waktu.

  Tiga tampilan diperbarui: PDF kuitansi, panel detail Livewire, dan infolist Filament — yang terakhir juga berhenti memakai label "Kelebihan Bayar". `breakdown()` dimemoisasi per instance; infolist Filament memanggilnya 5× per record. Item **3k** ikut selesai (8 test). Suite penuh hijau (680 passed).
- **2026-08-29 v17**: Item **1i selesai** — baris pelunasan kini menulis `credit_applied`, jadi jejak audit tak lagi putus di transaksi terbesar. Angkanya datang dari `Loan::payoffCreditApplied()` yang dipecah keluar dari `payoffAmount()`: potongan yang **ditagihkan** dan potongan yang **dicatat** karena itu berasal dari satu sumber dan tak bisa menyimpang — menghitungnya ulang di service adalah persis bentuk R2. Dikunci test bahwa `amount_paid + credit_applied` sama dengan payoff kontraktual, yaitu tidak ada tagihan ganda.

  **Lubang yang ditemukan sambil jalan:** potongan titipan pada pelunasan dibatasi sisa pokok (ketetapan v11), tapi `settleEarly()` tidak pernah melimpahkan sisa titipan di atas batas itu — begitu status jadi Lunas, `overpaymentCredit()` menjawab `0.00` dan uang anggota lenyap tanpa jejak. Dokumentasi v11 sudah menjanjikan pelimpahan itu; kodenya belum ada. Kini sisa titipan digabung dengan kelebihan uang loket jadi **satu** setoran Sukarela — satu deposit per angsuran, agar pembalikannya juga satu lewat mesin yang sudah ada. Dikunci test: titipan 5.250.000 atas sisa pokok 4.000.000 → `credit_applied` 4.000.000, Sukarela 1.250.000, saldo akhir 0. Item **3i** ikut selesai. Suite penuh hijau (672 passed).
- **2026-08-29 v16**: Item **1j selesai** — guard presisi di `reverse()` menolak pembatalan yang membuat saldo Titipan Pokok minus, dan pesannya menyebut nomor angsuran penghalang yang harus dibatalkan lebih dulu. **Penempatannya load-bearing:** guard dijalankan PALING AKHIR dalam transaksi, setelah status pinjaman dipulihkan dari Lunas ke Cair — dijalankan lebih awal ia akan buta, karena `overpaymentCredit()` menjawab `0.00` selama status masih Lunas. Penolakannya me-rollback seluruh transaksi (dikunci test: tak ada baris pembalik yang tersisa) dan menulis event `pembatalan_ditolak` beserta angsuran penghalangnya. Guard hanya menggigit bila titipan pernah ada DAN sudah terpakai — pinjaman yang tak pernah bertitipan tidak terkekang, itu yang membedakannya dari aturan LIFO menyeluruh yang ditolak di Alternatives; dikunci test tersendiri. Item **3h** ikut selesai. Suite penuh hijau (670 passed).
- **2026-08-29 v15**: Item **1g selesai** — bukti pembayaran kini melekat di **setiap** baris sesi, lewat `preservingOriginal()` per lampiran. Lubang jejak audit dari v14 ditutup. Item **3o** ikut selesai, dan test-nya **diverifikasi benar-benar menangkap R17**: dengan `preservingOriginal()` dicabut, lampiran baris kedua melempar dan seluruh transaksi batal — bukan sekadar kehilangan berkas diam-diam. Suite penuh hijau (666 passed).
- **2026-08-29 v14**: Item **1e selesai — perilaku produksi berubah mulai di sini.** `pay()` menulis N baris lewat `allocate()` di dalam lock, dengan penanda sesi dan kunci idempotensi turunan (`kunci_sesi + "-" + urutan`); `credit_applied` terisi di setiap baris; lantai `belowBill()` kini bertumpu tagihan efektif; **kredit-ke-Sukarela di tengah masa pinjaman dicabut.** `savingsMustEqualBill` tetap berlaku, patokannya pindah ke tagihan efektif sesuai Verification.

  **Dua item ikut dibundel karena 1e sendirian tidak benar.** **1h** — tanpa pelimpahan sisa titipan saat pinjaman ditutup, saldo yang tersisa lenyap begitu status jadi Lunas (guard status menjawab `0.00`), jadi uang anggota hilang dari layar tanpa jejak; saldonya wajib dibaca **sebelum** status berubah. **1k** — tanpa `credit_applied` di `reverseClone()`, baris-lawan ber-NULL tersaring keluar oleh opsi B, sehingga **pembatalan tidak memulihkan titipan sama sekali**. Keduanya terungkap dari test yang gagal, bukan dari pembacaan ulang ADR.

  **Dua fixture test lama ternyata mustahil di data nyata:** `makeLoan()` dan `savingsTestLoan()` mematok `principal_amount` 1.000.000 berapa pun jumlah jadwalnya, sementara `monthly_principal` juga 1.000.000 — satu angsuran seakan melunasi seluruh pokok padahal jadwalnya masih tersisa. `buildSchedule()` tak pernah menghasilkan itu. Penjaga Pelunasan Dipercepat benar mendeteksinya sebagai "uang ini cukup melunasi semuanya"; fixture-nya yang diperbaiki jadi `1.000.000 × N`. Item **3a** ikut selesai — tiga test Sukarela ditulis ulang jadi test Titipan Pokok.

  **Yang sengaja ditinggal:** bukti pembayaran masih melekat di baris pertama saja (item **1g**, R17) — ditandai TODO di kode. Suite penuh hijau (665 passed).
- **2026-08-29 v13**: Item **1d selesai** — `LoanPaymentService::allocate()`, alokasi bertingkat murni-hitungan (tidak menyentuh database) dengan mode `titipan` \| `tutup_sekalian`. Kedua contoh angka di Design direproduksi persis sebagai test: setor 2.100.000 (kedua mode) dan setor 3.000.000 (baris #3 menyerap sisa → 1.950.000, titipan 900.000). Tambahan `Loan::effectiveBillWithCredit()` — `allocate()` mensimulasikan beberapa angsuran sekaligus sementara saldo di database belum bergerak, jadi tagihan angsuran kedua dan seterusnya harus dihitung dari saldo berjalan; tanpa ini rumusnya pasti tersalin dan menyimpang. **Koreksi terhadap bukti aljabar penjaga pelunasan: batasnya `k ≥ 2`, bukan `k ≥ 1`.** Pada `k = 1` tak ada jasa yang dibebaskan, sementara baris `is_settlement` dikecualikan dari akrual Tabungan Berjangka — membelokkan angsuran terakhir ke pelunasan berarti menghanguskan akrual tab bulan pamungkas setiap anggota. Penjaga juga dilewati untuk sebrakan, yang `settleEarly()` memang tolak. **Penjaga baru:** jadwal `$start` yang basi (BelumBayar di memori, Terbayar di database) ditolak — tanpa itu alokasi bergeser diam-diam ke jadwal berikutnya dan petugas membayar angsuran yang bukan ia maksud. Suite penuh hijau (655 passed).
- **2026-08-29 v12**: **OQ-10 ditutup — opsi B.** `Loan::overpaymentCredit()` kini menyaring `credit_applied IS NOT NULL`, sehingga kelebihan bayar pra-fitur (yang uangnya sudah diserahkan sebagai Simpanan Sukarela) tidak dihitung ulang sebagai Titipan Pokok. Dipilih **tanpa menunggu angka produksi** karena B dominan di kedua dunia — no-op bila produksi bersih, pencegah kerugian bila tidak; angka produksi karena itu turun status dari gerbang jadi verifikasi. Keberatan "saldo tak lagi murni turunan" gugur: `credit_applied` ditulis sekali, tak pernah di-`UPDATE`, ikut disalin `reverseClone()`. Konsekuensi baru yang harus dijaga: **jalur pembuat baris angsuran yang lupa mengisi `credit_applied` membuat barisnya hilang diam-diam dari saldo** — item test **3r** ditambahkan. Tiga kasus baris pra-fitur (kelebihan, bayar pas, pinjaman campuran) dikunci test. Suite penuh hijau (637 passed).
- **2026-08-28 v11**: Item **1c selesai** — `Loan::payoffAmount()` sebagai satu-satunya sumber jumlah pelunasan; rumus duplikat dicabut dari `LoanPaymentService::settleEarly()` dan `BatchInstallmentPaymentService` (R2 ditutup di kode, bukan cuma di dokumen). Potongan titipan **dibatasi `settledPrincipal()`** — ketetapan baru: tanpa batas ini titipan besar bisa menghapus 1× jasa, satu-satunya jasa yang masih ditagih pada pelunasan; sisa titipan di atas batas dilimpahkan ke Sukarela saat Lunas, jadi anggota tetap menerimanya utuh. **Temuan baru: R21 / OQ-10 — memblokir Phase 2.** `overpaymentCredit()` murni turunan dari `amount_paid`, sementara `pay()` menyimpan seluruh uang yang diterima dan mengkreditkan kelebihannya ke Sukarela secara terpisah — sehingga setiap kelebihan bayar **lama** otomatis muncul sebagai titipan dan keringanannya terbayar dua kali. Non-Goals hanya memutuskan setoran Sukarela lama dibiarkan; bahwa baris yang sama tak boleh dihitung ulang sebagai titipan terlewat sampai sekarang. Baseline dev read-only: 0 dari 17 angsuran terpapar; angka produksi yang menentukan. Suite penuh hijau (634 passed).
- **2026-08-28 v10**: Item **1b selesai** — `Loan::effectiveBill(InstallmentSchedule)` sebagai satu-satunya sumber tagihan efektif: `total_due − min(titipan, principal_due)`. Angka kontraknya dibaca dari **baris jadwal**, bukan konstanta loan — keduanya identik, tapi baris jadwal adalah kontrak untuk bulan itu. Dua keputusan implementasi: (a) saldo titipan **di-floor 0** di sini, supaya saldo negatif sementara (state yang seharusnya ditolak guard 1j) tidak pernah menaikkan tagihan anggota di atas kontraknya — sinyal negatifnya tetap utuh di `overpaymentCredit()` yang dibaca guard; (b) jadwal milik pinjaman lain **ditolak** `InvalidArgumentException`, karena status "satu-satunya sumber" tidak ada artinya kalau ia mau menghitung pasangan loan/jadwal yang tak berhubungan. Test file 1a diganti nama jadi `TitipanPokokLoanTest` (18 test) karena kini mencakup seluruh permukaan titipan di model `Loan`. Suite penuh hijau (629 passed).
- **2026-08-28 v9**: Item **1a selesai** — `Loan::overpaymentCredit()` (saldo turunan `Σ_signed(amount_paid) − netCount × monthlyTotal()`, konvensi tanda & pengecualian baris pelunasan mengikuti `settledPrincipal()`, guard `Lunas`/`Dibatalkan` → `0.00`) dan `Loan::monthlyTotal()` sebagai satu sumber tagihan kontrak. **Keputusan implementasi:** saldo **tidak di-floor ke 0** — nilai negatif adalah sinyal yang dipakai guard `reverse()` (item 1j) untuk menolak pembatalan yang menghapus titipan yang sudah terpakai; pada state ter-commit guard itulah yang menjamin ≥ 0. Dikunci `OverpaymentCreditTest` (11 test), termasuk tabel "mengalir lintas bulan" dari Design dan pemulihan saldo setelah pembatalan di kedua arah. Perilaku pembayaran belum berubah — `LoanPaymentService` belum disentuh; suite penuh hijau (622 passed).
- **2026-08-28 v8**: Item **0a selesai** — migrasi `credit_applied` (decimal 18,2, nullable) + `session_key` (uuid, nullable, index) di `installments`, plus `$fillable`/`$casts` di `Installment`. **Temuan implementasi:** kunci idempotensi turunan `kunci_sesi + "-" + urutan` (38 karakter) **tidak muat** di `idempotency_key` yang dideklarasikan `uuid` = `char(36)`; kolomnya dilebarkan jadi `varchar(64)` dalam migrasi yang sama, indeks UNIQUE dipertahankan. Tanpa ini item 1e tidak bisa dijalankan seperti tertulis. Dikunci test `InstallmentCreditColumnsTest` (kolom ada, NULL aman untuk baris lama, kunci turunan muat, duplikat tetap ditolak UNIQUE). Perilaku belum berubah sama sekali — suite penuh hijau (611 passed).
- **2026-08-28 v7**: Ronde kritik ke-7 (laporan, ekspor, layar daftar/detail angsuran). **R19** — `credit_applied` & `session_key` tak akan terbaca di jejak audit karena peta label di `InstallmentDetail:78` bersifat eksplisit per-layar; ini penting karena jejak audit adalah kontrol utama atas risiko R14 yang diterima (item 2g). **R20 → OQ-9** — `remainingAfter()` berbasis nomor urut jadwal sementara `settledPrincipal()` berbasis jumlah angsuran; keduanya hanya sama tanpa lubang, dan ADR ini memperbesar peluang lubang lewat pembatalan per-transaksi. Divergensinya lama, bukan bawaan ADR ini. **Keputusan pemilik produk: diperbaiki** (item 2h + test 3q) — perbaikan bug lama yang menumpang secara sadar, ditandai agar reviewer tahu kenapa ada layar berubah angkanya. Diverifikasi aman: `InstallmentReportService` (menjumlah uang tunai riil, benar apa adanya) dan `ExportSalaryDeductionRecap` (rekap simpanan, tak bersinggungan).
- **2026-08-28 v6**: Ronde kritik ke-6 (area yang belum pernah disentuh: media, ADR tetangga, widget, status pinjaman). **`addMedia($uploadedFile)` memindahkan berkas sumbernya**, sehingga keputusan "bukti melekat di setiap baris" tidak bisa dijalankan dengan pola kode yang ada — dua jalan sah ditulis eksplisit (R17). Ketergantungan ke [ADR Penutupan Akun Anggota](2026-07-13-penutupan-akun-anggota.md) dicatat di Non-Goals — ADR itu **tidak dikerjakan** atas keputusan pemilik produk, tapi saat dikerjakan nanti wajib memakai `payoffAmount()` dan membaca ulang saldo Sukarela setelah settle. R13 kini menyebut dua lokasi konkret (`SavingsStatsOverview:81`, `OverdueInstallmentsTable:52`) plus item 2f. Guard `overpaymentCredit()` diperluas ke status **Dibatalkan**; aman hari ini karena `canCorrect()` melarang pembatalan pinjaman berangsuran, tapi ketergantungan itu dicatat (R18) dan dijaga test 3p.
- **2026-08-28 v5**: Ronde kritik ke-5 (fokus security & finance). **Risiko korupsi loket (R14) dinaikkan jadi OQ-0 dan DITERIMA secara sadar** — gerbang Pengurus atas pemakaian titipan dipertimbangkan lalu ditolak karena menyulitkan operasional harian; pengaman yang tersisa seluruhnya bersifat pendeteksian pasca-kejadian, dan itu dicatat apa adanya untuk `security-reviewer`. Diperbaiki: kuitansi kini menutup di **kedua** arah — baris "Titipan Pokok disisihkan" (+) ditambahkan, sebelumnya nota multi-angsuran tidak berjumlah (R16); ambang penjaga Pelunasan Dipercepat didefinisikan sebagai `uang ≥ payoffAmount()` beserta bukti aljabar bahwa penjaganya kedap; hak akses pemilihan mode dinyatakan Petugas-level; pembandingan pratinjau basi diubah dari berbasis bentuk jadi berbasis **saldo titipan** (R15); `credit_applied` NULL diperlakukan 0; klaim `Δ = 0` di batch dipersempit — nominal batch bisa dinaikkan petugas. Diverifikasi aman: penjaga pelunasan, bayar-dari-simpanan, pembatalan sebagian, pembulatan `ceil`.
- **2026-08-28 v4**: **Koreksi klaim batch v3** — batch mode tutup-sekalian tidak membuat titipan terpakai; `Δ = uang − tagihan kontrak = 0` pada potong gaji, jadi kedua mode identik di sana. Aturan khusus batch **dicabut**; batch tidak disentuh sama sekali (syarat: payroll tetap memotong angka kontrak, dicatat sebagai R11). Ditambahkan: rumus inti eksplisit dengan jebakan "kontrak vs efektif" (R3), idempotensi kunci sesi + nomor urut, pembatalan **per-transaksi** dengan penanda keterkaitan sesi (bukan pembalikan sepaket), bukti melekat di setiap baris, kuitansi bertitipan dengan baris "Titipan Pokok dipakai" dan "Sisa Titipan Pokok", panel Riwayat Titipan Pokok, keputusan **tanpa pelacakan per-lot**, dan perluasan jejak activity log. Background diberi catatan kejujuran bahwa motif masalahnya adalah inferensi penulis, belum dikonfirmasi.
- **2026-08-28 v3**: Dua mekanisme digabung jadi alokasi bertingkat — bawaan Titipan Pokok, opsi tutup sekalian, penjaga Pelunasan Dipercepat. Koreksi tabel v2 yang keliru soal titipan membengkak. Dua pintu Filament masuk cakupan.
- **2026-08-28 v2**: Jalur batch masuk cakupan; `credit_applied` sebagai jejak audit; `breakdown()` & `reverseClone()` masuk Key Items; 4 file test lama ditandai tulis-ulang; konsep dinamai **Titipan Pokok**; Risk Register.
- **2026-08-28 v1**: Initial draft.
