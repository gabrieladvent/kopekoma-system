# Penutupan Akun Anggota (Closing Nasabah)

Alur terminal untuk mengeluarkan anggota dari koperasi — menyelesaikan (settle) kewajiban pinjaman, mengembalikan seluruh simpanan (ke anggota atau ahli waris), lalu mengubah status anggota jadi `Keluar`/`Meninggal` tanpa menghapus data. **Fitur future — tidak mempengaruhi modul yang sudah berjalan.**

**Author:** Gabriel Advent
**Date:** 2026-07-13
**Status:** Draft — planning only, belum dijadwalkan

> **Catatan revisi (2026-07-13):** dua kali review adversarial (`critic`).
> - **Rev 1** — tiga koreksi fondasi: (1) saldo belanja toko `wajib_belanja` adalah **HAK/prepaid**, bukan kewajiban; (2) status pakai enum existing `Keluar`/`Meninggal`, bukan `closed`; (3) tabel HAK dilengkapi + mismatch enum withdrawal jadi blocker.
> - **Rev 2** — **reframing besar**: closing bukan modul keuangan baru, tapi **orkestrator tipis di atas mesin settlement yang sudah ada** (`LoanPaymentService`). Alasan: melunasi lewat `pay()` otomatis memicu `createRefunds()` yang **sudah** membuat draft pengembalian SWP + tabungan berjangka — jadi closing harus **merekonsiliasi draft itu**, bukan bikin withdrawal baru (kalau bikin baru → anggota dibayar 2×). Keputusan: settle pinjaman **wajib lewat `pay()`** (opsi A).

---

## Background

Anggota koperasi **pasti keluar** cepat atau lambat: pensiun, mutasi OPD, resign, atau meninggal dunia. Saat ini sistem **belum punya alur untuk itu** — begitu anggota keluar, simpanannya menggantung tanpa jalur pengembalian yang jelas, pinjaman yang masih outstanding tidak ter-settle, dan potong gaji berpotensi jalan terus padahal orangnya sudah tidak digaji instansi.

**Skema sudah mengantisipasi sebagian.** Model [`Member`](../../app/Models/Member.php) sudah punya kolom `exit_date`, `heir_name` / `heir_relationship` / `heir_phone_number` (jalur ahli waris), `SoftDeletes`, dan yang penting: kolom `status` **sudah enum `['Aktif', 'Non-Aktif', 'Keluar', 'Meninggal']`** (default `Aktif`) di [migration members baris 33](../../database/migrations/2026_06_14_090003_create_members_table.php#L33). Jadi state akhir penutupan **sudah tersedia** — `Keluar` untuk keluar biasa, `Meninggal` untuk jalur ahli waris. Yang belum ada murni **business flow**-nya.

Fondasi keuangan yang dibutuhkan sudah berdiri & terverifikasi dari modul Simpanan & Pinjaman:
- [`SavingsBalanceService`](../../app/Services/SavingsBalanceService.php) — saldo computed-on-read net-of-reversal. Punya `allBalances()`, `totalBalance()`, `loanSwpBalance()`, `timeDepositBalance()`, `shoppingBalance()` — semua komponen HAK sudah ada method-nya.
- [`Loan::remainingPrincipal()` / `isLunas()`](../../app/Models/Loan.php) & [`LoanArrearsService`](../../app/Services/LoanArrearsService.php) — sisa kewajiban pinjaman.
- [`Reversible`](../../app/Contracts/Reversible.php) (di `app/Contracts`) + [`ReverseTransaction`](../../app/Actions/ReverseTransaction.php) (di `app/Actions`) — prinsip "reversal bukan hapus".
- [`WithdrawalWorkflow`](../../app/Services/WithdrawalWorkflow.php) — pola pencatatan penarikan simpanan (basis langkah "kembalikan").

Karena semua modul operasional harian sudah stabil, **membangun closing sekarang justru terlalu dini** — beberapa aturan finansial belum diputuskan pengurus dan rawan salah hitung. ADR ini memetakan gambaran utuh + blocker teknis lebih dulu supaya tinggal dieksekusi.

> ⚠️ **Event finansial terminal & jarang** (bukan operasional harian). Prinsip non-negotiable tetap: (1) saldo dihitung dari transaksi, (2) reversal bukan hapus, (3) data tidak pernah di-hard-delete demi audit.

---

## Goals

- **Alur penutupan yang menyelesaikan 2 sisi neraca** sebelum status diubah — kewajiban (pinjaman) dilunasi, hak (seluruh simpanan) dikembalikan.
- **Jalur ahli waris** untuk anggota meninggal (status `Meninggal` + data `heir_*`).
- **Kunci akun tanpa hapus data** — status `Keluar`/`Meninggal` + `exit_date`, blokir transaksi baru, stop otomatis dari batch potong gaji, seluruh riwayat tetap tersimpan untuk audit.
- **Reuse fondasi keuangan** yang sudah ada (`SavingsBalanceService` dkk), bukan bikin logika saldo baru.

## Non-Goals

- **Bukan** merombak modul simpanan, pinjaman, atau toko — hanya menambah alur terminal di atasnya.
- **Bukan** workflow approval berlapis — keputusan "boleh keluar" tetap di tangan pengurus di luar sistem (konsisten dengan governance pinjaman); sistem hanya mencatat & mengeksekusi settle.
- **Bukan** perhitungan SHU/pembagian sisa hasil usaha saat keluar (kalau perlu, ADR terpisah).
- Tidak menyentuh fitur yang sudah rilis — **zero impact** ke modul existing. (Catatan: eksekusi nanti kemungkinan butuh **ALTER enum** kolom finansial hidup — lihat Danger Zone, itu bukan perubahan "aditif" yang bebas risiko.)

---

## Design

### Gambaran inti: lunasi kewajiban, kembalikan semua hak, ubah status

Koreksi penting dari review: **toko itu prepaid, bukan utang.** Anggota top-up saldo `wajib_belanja` dulu baru belanja, dan charge ditolak kalau saldo kurang ([ADR toko §prepaid](2026-06-18-integrasi-api-toko-wajib-belanja.md)). Jadi utang toko **mustahil ada by design** — sisa saldo belanja justru **HAK** yang harus dikembalikan.

**Sisi HAK — seluruh uang koperasi yang harus kembali ke anggota**
| Komponen | Sumber | Method |
|----------|--------|--------|
| Simpanan pokok | `SavingsDeposit` `pokok` | `balanceByType` / `allBalances` |
| Simpanan wajib | `SavingsDeposit` `wajib` | `allBalances` |
| Simpanan sukarela | `SavingsDeposit` `sukarela` | `allBalances` |
| Tabungan hari raya | `SavingsDeposit` `hari_raya` | `holidayBalance` |
| **Saldo belanja (prepaid)** | `SavingsDeposit` `wajib_belanja` − usage | `shoppingBalance` |
| SWP pinjaman | `Loan.swp_amount` terkumpul | `loanSwpBalance` |
| Tabungan berjangka | `Loan.monthly_time_deposit` terkumpul | `timeDepositBalance` |

> `totalBalance()` sudah menjumlah 5 komponen pertama. **SWP & tabungan berjangka TIDAK termasuk** `totalBalance` karena terikat ke pinjaman (baru "cair" saat lunas — lihat ADR pinjaman). Di langkah 1 keduanya dipakai untuk **estimasi**; pengembaliannya **bukan dihitung closing** melainkan lewat draft `createRefunds()` (lihat Prinsip Inti di bawah).

**Sisi KEWAJIBAN — uang anggota yang harus kembali ke koperasi**
| Komponen | Sumber |
|----------|--------|
| Sisa angsuran pinjaman berjalan | `Loan` status `Cair` — dilunasi lewat `LoanPaymentService::pay()` |

> Tidak ada "utang toko". Kewajiban praktis hanya pinjaman. (`remainingPrincipal()` hanya pokok/count-based — bukan angka pelunasan sebenarnya; pelunasan riil = sisa jadwal angsuran `Belum Bayar`, lihat Approach.)

### Prinsip inti: orkestrasi, bukan reinvent

Closing **bukan** menghitung-lalu-menciptakan pengembalian dari nol. Mesin settlement-nya **sudah ada**: begitu semua jadwal angsuran terbayar, [`LoanPaymentService::pay()`](../../app/Services/LoanPaymentService.php#L100-L106) otomatis meng-set `Loan` → `Lunas` **dan** memanggil `createRefunds()` yang membuat `SavingsWithdrawal` **draft** untuk SWP + tabungan berjangka (dengan guard `hasActiveRefund()` anti-dobel). Closing tinggal **menyetir** mesin itu lalu **merekonsiliasi** draft yang dihasilkannya.

> ⚠️ **Jangan bikin withdrawal SWP/tabungan berjangka baru di closing** — `createRefunds()` sudah membuatnya. Bikin lagi = anggota dibayar 2×.

### Alur 4 langkah

```
1. CEK        → tampilkan ESTIMASI ringkasan hak vs kewajiban (pra-settle).
                Angka SWP/tabungan berjangka & sisa pinjaman masih bisa
                bergeser setelah settle — labeli sebagai estimasi.

2. SETTLE     → lunasi pinjaman aktif LEWAT LoanPaymentService::pay()
                (opsi A: potong dari simpanan anggota sebagai pembayaran
                angsuran). Ini memicu Auto-Lunas + createRefunds() →
                draft SWP + tabungan berjangka terbentuk otomatis.

3. KEMBALIKAN → a) CAIRKAN draft refund SWP/tab berjangka yang dibuat
                   createRefunds() (termasuk draft LAMA dari pinjaman
                   yang sudah lunas sebelum closing — enumerate status
                   draft/acc, jangan cuma hitung balance).
                b) BUAT withdrawal untuk simpanan biasa: pokok, wajib,
                   sukarela, hari_raya, dan saldo belanja (wajib_belanja).
                Tujuan: anggota, atau ahli waris jika Meninggal.

4. UBAH STATUS→ Member.status = Keluar | Meninggal; isi exit_date;
                anggota di-exclude dari penyusunan batch potong gaji &
                input transaksi baru; data TIDAK dihapus (audit).
```

### Approach

Bangun sebagai satu **Action `CloseMemberAccount`** yang **mengorkestrasi service existing** di dalam satu `DB::transaction` (pola sama dengan `WithdrawalWorkflow` / `RecordShoppingUsage`), bukan menulis logika keuangan baru. Langkah 2–3 harus atomik: kalau pengembalian gagal, settle ikut batal.

Konsekuensi memilih **opsi A (settle lewat `pay()`)**:
- Pelunasan **wajib** menutup seluruh jadwal angsuran `Belum Bayar` via `pay()` — **bukan** set `status = 'Lunas'` langsung (itu bypass domain: refund tak jalan, `remainingPrincipal()` tetap > 0).
- Karena itu **"potong simpanan untuk melunasi pinjaman saat closing" jadi kapabilitas wajib** — ini prasyarat, bukan detail (lihat Open Q #1 & #2).
- Refund SWP + tabungan berjangka **tidak dihitung/dibuat closing** — biar `createRefunds()` yang urus; closing hanya mencairkan draft-nya.

State pakai enum `status` yang **sudah ada** — tidak perlu nilai baru. Guard "anggota keluar" ditambahkan di titik yang **menyusun** transaksi (Livewire page/query yang membangun daftar), **bukan** di dalam `BatchSalaryDeductionService` (service itu hanya mengonsumsi `array $rows` dari luar, tidak menyeleksi by status).

### Alternatives Considered

| Alternative | Pro | Con | Verdict |
|-------------|-----|-----|---------|
| **Action `CloseMemberAccount` orkestrasi service existing** (dipilih) | Atomik, reuse logika saldo teruji, satu titik audit | Perlu desain guard di beberapa titik + kemungkinan ALTER enum withdrawal | **Chosen** |
| Hard-delete anggota + arsip manual | Sederhana | Melanggar prinsip audit; transaksi jadi yatim | Rejected |
| Ubah `status` manual tanpa settle | Cepat | Uang menggantung, kewajiban tak selesai — justru sumber masalah | Rejected |

---

## Rollout Plan

| Phase | Behavior | Status |
|-------|----------|--------|
| 0 | Baseline — belum ada alur (kondisi sekarang) | — |
| 1 | Read-only: layar "ringkasan hak vs kewajiban" per anggota (langkah 1 saja) | Pending |
| 2 | Eksekusi closing kasus **bersih** (pinjaman lunas, tidak ada selisih) | Pending |
| 3 | Kasus kompleks: pinjaman belum lunas, jalur ahli waris/Meninggal, blacklist | Pending |

### Phase Transition Checklist

**Phase 0 → 1:**
- [ ] 5 Open Questions di bawah terjawab pengurus
- [ ] Titik guard "anggota Keluar/Meninggal" (di lapisan penyusun, bukan service batch) disepakati

**Phase 1 → 2:**
- [ ] `CloseMemberAccount` ter-unit-test skenario bersih — settle lewat `pay()`, draft `createRefunds()` dicairkan tanpa duplikat, simpanan biasa dikembalikan
- [ ] Terbukti **tidak ada** withdrawal SWP/tabungan berjangka ganda (closing vs `createRefunds()`)
- [ ] **Blocker: mismatch enum withdrawal** — `savings_withdrawals.savings_type` = `['hari_raya,sukarela,swp,tabungan_berjangka,pokok,wajib']`, **tidak punya `wajib_belanja`**. Pengembalian saldo belanja lewat `SavingsWithdrawal` tidak bisa dicatat tanpa ALTER enum. Putuskan: ALTER enum vs jalur pengembalian terpisah.
- [ ] Anggota `Keluar`/`Meninggal` terbukti di-exclude dari daftar row batch potong gaji
- [ ] RBAC/permission aksi closing dibuat (Shield)

**Phase 2 → 3:**
- [ ] Aturan pinjaman-belum-lunas & jalur ahli waris final + ter-test
- [ ] Skema penyimpanan "dibayar ke ahli waris" pada withdrawal diputuskan (lihat Open Q #5)

---

## Key Files

| File | Fungsi |
|------|--------|
| `app/Models/Member.php` | `exit_date`, `heir_*`, `status` enum (`Keluar`/`Meninggal` sudah ada), SoftDeletes |
| `app/Services/SavingsBalanceService.php` | Estimasi sisi HAK langkah 1 (`allBalances`, `loanSwpBalance`, `timeDepositBalance`, `shoppingBalance`) |
| `app/Services/LoanPaymentService.php` | **Pusat settle langkah 2** — `pay()` melunasi + Auto-Lunas + `createRefunds()` (draft SWP/tab berjangka) |
| `app/Models/Loan.php` / `app/Services/LoanArrearsService.php` | Konteks pinjaman (arrears murni informatif, tanpa denda) |
| `app/Services/WithdrawalWorkflow.php` | Cairkan draft refund + catat pengembalian simpanan biasa (langkah 3) |
| `database/migrations/…savings_withdrawals…` | **Perlu ALTER** jika saldo `wajib_belanja` dikembalikan lewat withdrawal |
| Lapisan penyusun batch (Livewire page) | **Perlu guard** exclude anggota `Keluar`/`Meninggal` — bukan di `BatchSalaryDeductionService` |
| `app/Actions/…/CloseMemberAccount.php` | **BARU** — orkestrator alur 4 langkah (atomik) |

---

## Verification

- [ ] Estimasi HAK di langkah 1 diukur **sebelum** pencairan draft (`totalBalance()` + `loanSwpBalance()` + `timeDepositBalance()`) dan **dilabeli estimasi** — angka bisa bergeser setelah settle
- [ ] Saldo `wajib_belanja` (prepaid) dihitung sebagai HAK yang **dikembalikan**, bukan dipotong
- [ ] **Tidak ada withdrawal SWP/tabungan berjangka duplikat** — closing mencairkan draft `createRefunds()`, tidak membuat baru (uji: satu hak SWP → tepat satu withdrawal)
- [ ] Draft refund **lama** (dari pinjaman yang sudah `Lunas` sebelum closing) ikut ter-enumerate & dicairkan
- [ ] Pelunasan pinjaman menempuh `LoanPaymentService::pay()` (jadwal `Belum Bayar` tertutup), **bukan** set `status='Lunas'` langsung
- [ ] Skenario bersih: settle + cairkan draft + kembalikan simpanan + status `Keluar` dalam satu transaksi (rollback jika ada yang gagal)
- [ ] Anggota `Keluar`/`Meninggal` ditolak saat setoran/pinjaman baru & tidak masuk daftar batch potong gaji
- [ ] Jalur ahli waris: status `Meninggal`, pengembalian terhubung ke data `heir_*`
- [ ] Tidak ada data hilang — seluruh riwayat tetap ter-query (`withTrashed`)

---

## Open Questions

Yang **harus diputuskan pengurus** sebelum Phase 1:

1. **Boleh tutup akun kalau pinjaman belum lunas?** Opsi A mensyaratkan pelunasan lewat `pay()` — apakah pengurus setuju "potong simpanan untuk melunasi angsuran saat closing" jadi kapabilitas resmi?
2. **Kalau total simpanan < sisa pinjaman → gimana?** Anggota bayar tunai kekurangannya (dicatat sebagai pembayaran angsuran)? Masuk `LoanBlacklist`? Ditahan sampai lunas?
3. **Simpanan pokok saat keluar → dikembalikan atau hangus?** (tergantung AD/ART koperasi)
4. **Pinjaman yang keluar di tengah tenor** — saat `pay()` melunasi sisa jadwal, apakah **jasa/bunga sisa tenor** ikut ditagih penuh atau dibebaskan (mis. hanya bayar sisa pokok)? Termasuk jasa angsuran yang **sudah lewat tempo tapi belum dibayar**. Ini menentukan berapa yang dipotong dari simpanan di langkah 2.
5. **Pengembalian ke ahli waris dicatat bagaimana?** `SavingsWithdrawal` **tidak punya kolom heir** — cukup di `notes`, atau perlu kolom penerima? (`heir_*` hanya ada di `Member`.)

Sudah terjawab oleh skema (tidak lagi jadi pertanyaan):
- ~~Trigger closing & state akhir~~ → enum `status` sudah membedakan `Keluar` vs `Meninggal`; `Non-Aktif` = penonaktifan sementara (bukan closing terminal).

---

## Danger Zone

- **Double-refund SWP/tabungan berjangka** — `LoanPaymentService::createRefunds()` sudah membuat draft-nya saat `pay()` melunasi. Closing **hanya mencairkan draft**, tidak create baru. Ini bahaya paling mahal — dilindungi di kode oleh guard `hasActiveRefund()`, tapi jalur closing harus tetap lewat draft yang sama.
- **Bypass domain via set status langsung** — melunasi dengan `Loan->update(['status'=>'Lunas'])` melewati `pay()` → refund tak terbentuk & `remainingPrincipal()` tetap > 0. Pelunasan **wajib** lewat `pay()`.
- **ALTER enum kolom finansial hidup** (`savings_withdrawals.savings_type` untuk `wajib_belanja`, jika dipilih) — koordinasi dengan **deploy-reviewer**. Bukan perubahan "aditif" bebas risiko.
- **Dua kolom `status`, dua konvensi casing** — `savings_withdrawals.status` enum lowercase (`draft/acc/cair/ditolak`) vs `loans.status` Titlecase (`Cair/Lunas`). **Tabel berbeda, bukan satu hazard rekonsiliasi** — cukup pakai casing yang benar untuk masing-masing tabel.
- **Atomicity settle↔kembalikan** — kalau tidak dalam satu transaksi, bisa terjadi pinjaman ter-settle tapi simpanan tidak kembali (atau sebaliknya). Wajib `DB::transaction`.
