# Laporan Detail Setoran & Angsuran per Periode

Menyediakan laporan detail transaksi setoran simpanan dan angsuran pinjaman yang bisa difilter per bulan / rentang periode, dengan export PDF & Excel. Penghitungan SHU **tidak** masuk scope sistem.

**Author:** gabrieladvent
**Date:** 2026-07-07
**Status:** Approved

---

## Background

Modul inti sudah jalan: Simpanan ([modul-simpanan](2026-06-16-modul-simpanan.md)), Pinjaman & Angsuran ([modul-pinjaman-angsuran](2026-06-19-modul-pinjaman-angsuran.md)), dan Batch Potong Gaji. Data transaksi (`savings_deposits`, `installments`) sudah terkumpul rapi, tapi belum ada cara terstruktur bagi pengurus untuk **menarik rekap** per periode — baik untuk kebutuhan internal (rekonsiliasi bulanan) maupun pertanggungjawaban ke anggota.

Dari sesi klarifikasi kebutuhan tahap laporan, dua hal yang sudah dikonfirmasi pengurus:
1. **Penghitungan SHU tidak perlu di-cover sistem** → dikecualikan dari scope (lihat Non-Goals).
2. **Butuh laporan detail setoran dan angsuran per bulan** (periode tertentu) → inti ADR ini.

Pertanyaan lain (tutup akun/pencairan, RAT, jenis laporan tambahan) **belum dijawab** dan sengaja tidak dimasukkan ke scope sampai ada konfirmasi (lihat Open Questions).

Fondasi teknis yang relevan dan sudah ada:
- Kedua transaksi punya pola **reversal** (`is_reversal` + `reversal_of_id`) → laporan harus melaporkan **net of reversals** (terbayar − reversal), bukan sekadar SUM mentah. (Catatan: net ini **bukan** saldo — saldo = deposit − withdrawal, lihat boundary di Non-Goals.)
- Pola grouped-signed-net yang benar **sudah ada** di [`SavingsBalanceService::allBalances()`](../../app/Services/SavingsBalanceService.php) (`groupBy('savings_type')->selectRaw('SUM(CASE WHEN is_reversal = 0 THEN amount ELSE -amount END)')`) — ini idiom kanonik yang harus di-mirror service laporan, **bukan** `HasSignedAmount::scopeSignedAmount` (aggregate-only, lihat Design).
- Package export sudah tersedia di `composer.json`: `maatwebsite/excel` (Excel) dan `barryvdh/laravel-dompdf` (PDF). Tidak perlu tambah dependency.

---

## Goals

- Laporan **detail setoran simpanan** per periode: baris per transaksi setoran, difilter rentang tanggal + opsional jenis simpanan / OPD / anggota.
- Laporan **detail angsuran pinjaman** per periode: baris per transaksi angsuran, difilter rentang tanggal + opsional OPD / anggota.
- Filter **rentang tanggal** (dari–sampai), bukan cuma satu bulan — mengikuti preferензi yang sudah muncul di catatan Hari Raya ("date range lebih tepat").
- Export **PDF** (cetak/arsip) dan **Excel** (olah lanjut).
- Angka yang dilaporkan net of reversals: net = terbayar − reversal, dengan baris reversal tetap terlihat (transparansi audit).
- Terkunci di belakang **permission Shield** (hanya pengurus yang berhak).

## Non-Goals

- **Penghitungan / pembagian SHU** — eksplisit dikecualikan atas konfirmasi pengurus. Sistem tidak menghitung, membagi, atau mencatat SHU.
- **RAT** (Rapat Anggota Tahunan) — belum dikonfirmasi, di luar scope.
- **Tutup akun & pencairan simpanan** — belum dikonfirmasi, ADR terpisah nanti.
- Laporan **saldo/neraca/laba-rugi akuntansi** penuh — ADR ini hanya laporan **transaksional detail** (list transaksi + subtotal), bukan financial statement.
- **Rekap saldo per anggota** — SUDAH ADA di [`MemberSavingsBalanceResource`](../../app/Filament/Resources/MemberSavingsBalanceResource.php) ("Saldo Anggota", grup Simpanan) via `SavingsBalanceService`. Jangan dibangun ulang.
- **Penarikan/pencairan simpanan (withdrawals)** — laporan "setoran" ini **hanya deposit** (`savings_deposits`), TIDAK memuat `savings_withdrawals`. Konsekuensi eksplisit: (a) angka ini **bukan** mutasi bersih/saldo; (b) jenis `swp` & `tabungan_berjangka` **tidak akan muncul** karena tak punya baris deposit (terakumulasi dari `loans`, bukan setoran); (c) anggota yang setor lalu tarik di periode sama hanya tampil sisi setornya. Laporan withdrawal = scope terpisah bila diminta.
- Dashboard/chart visual — cukup tabel + export.
- Penjadwalan/kirim laporan otomatis (email) — manual on-demand dulu.

---

## Design

### Approach

Buat modul **Laporan** sebagai kumpulan **Filament custom Page** (bukan Resource, karena ini read-only report bukan CRUD), dalam navigation group baru **"Laporan"**. Tiap laporan = satu Page dengan form filter di atas + preview tabel + tombol export PDF/Excel.

Dua page pada fase awal:
1. **Laporan Setoran Simpanan** (`LaporanSetoranSimpanan`)
2. **Laporan Angsuran Pinjaman** (`LaporanAngsuranPinjaman`)

**Query & agregasi** dipisah ke **service class** (satu service per laporan: `DepositReportService`, `InstallmentReportService`) supaya logika filter + net calculation bisa dites unit tanpa render Filament, dan dipakai ulang oleh page (preview), PDF export, maupun Excel export tanpa duplikasi.

**Net / reversal handling (KOREKSI dari draft v1).** `HasSignedAmount::scopeSignedAmount()` **tidak** dipakai di sini — scope itu **aggregate-only**: dia `selectRaw('SUM(...) as net')` tanpa `GROUP BY` dan **mengganti seluruh SELECT**, jadi mengembalikan satu baris `net` saja dan membuang semua kolom (member, tanggal, dll). Tidak bisa dipakai untuk detail per-baris maupun subtotal per-grup. Pendekatan yang benar:
- **Detail rows**: tampilkan semua baris termasuk `is_reversal = true`, dengan **signed amount per baris** dihitung via `selectRaw('CASE WHEN is_reversal = 0 THEN <col> ELSE -<col> END as signed_amount')` (atau di PHP dengan **bcmath**, mengikuti konvensi bc di seluruh codebase — jangan float). Kolom nominal berbeda per tabel: setoran = **`amount`**, angsuran = **`amount_paid`** (`installments` **tidak punya** kolom `amount` — itu hanya PHP accessor alias; `CASE ... amount` akan error di SQL).
- **Subtotal per grup & grand total**: query **agregat terpisah** `groupBy(...)` + `selectRaw('SUM(CASE WHEN is_reversal = 0 THEN <col> ELSE -<col> END) as net')`, mirror [`SavingsBalanceService::allBalances()`](../../app/Services/SavingsBalanceService.php). Service mengekspos dua method: `rows(filters)` dan `totals(filters)`.

**Basis periode (KOREKSI).** Rekonsiliasi potong-gaji di [`BatchSalaryDeduction`](../../app/Filament/Pages/BatchSalaryDeduction.php) di-key pada **`period_month`** (`whereDate('period_month', $period)`), bukan `deposit_date`. Kalau laporan setoran difilter `deposit_date`, setoran yang direkam beda bulan dari periode payroll-nya akan jatuh ke bucket salah → **tidak rekonsiliasi** dengan batch-nya. Maka:
- **Setoran**: sediakan toggle **basis periode** — default **`period_month`** (untuk rekonsiliasi potong-gaji), opsi **`deposit_date`** (tanggal transaksi aktual). Catatan: `period_month` **nullable** dan umumnya kosong untuk `sukarela`/`hari_raya`; saat basis = `period_month` baris ber-`period_month` NULL dikecualikan (tampilkan peringatan di UI). Untuk laporan sukarela/hari_raya gunakan basis `deposit_date`.
- **Angsuran**: `installments` tidak punya `period_month`; pakai **`payment_date`** range (natural axis).

**Filter yang disediakan:**
- Setoran: basis periode + range (wajib), `savings_type` (opsional multi-select: pokok/wajib/hari_raya/wajib_belanja/sukarela), **`deposit_method`** (opsional: `potong_gaji`/`setor_sendiri`), OPD/agency (opsional), anggota (opsional).
- Angsuran: `payment_date` range (wajib), OPD/agency (opsional), anggota (opsional).

**Rekonsiliasi vs batch potong-gaji (KOREKSI — jangan overclaim).** Batch export [`BatchSalaryDeductionService`](../../app/Services/BatchSalaryDeductionService.php) memfilter **spesifik**: `savings_type = 'wajib'` (`SAVINGS_TYPE`), `deposit_method = 'potong_gaji'` (`METHOD`), `is_reversal = false`. Laporan setoran default (semua jenis, semua metode, net-of-reversal) **tidak akan sama** dengan angka batch. Klaim "rekonsiliasi" hanya valid bila laporan di-set: basis `period_month` + `savings_type = wajib` + `deposit_method = potong_gaji` + kecualikan reversal. Verification poin rekonsiliasi harus pakai filter itu, bukan default.

**Relasi & eager-load (hindari N+1).**
- Setoran → `member` → `agency`. Filter OPD ikut pola batch: `whereHas('member', fn ($q) => $q->where('agency_id', $id))`. Eager-load `with('member.agency')`.
- Angsuran tidak punya relasi `member` langsung — rantai **`loan → member → agency`** (3-hop). Eager-load `with('loan.member.agency')`; filter OPD via `whereHas('loan.member', ...)`.

**Anggota resign / soft-deleted.** `Member` pakai `SoftDeletes`. Untuk laporan historis, transaksi anggota yang sudah resign tetap harus muncul (kalau tidak → under-count saat rekonsiliasi). Maka join/relasi ke member di laporan pakai **`withTrashed()`**. Keputusan eksplisit, bukan default Eloquent.

**Export:**
- **Excel** via `maatwebsite/excel` — pakai **`FromCollection` + `WithMapping`** (bukan `FromQuery` polos): kita perlu menampilkan baris reversal **dan** menyisipkan baris total ter-net, yang tidak bisa dilakukan `FromQuery` saja. Collection dibangun dari service (sudah eager-load). Sumber data satu-satunya = service (no query di export class). **Batas memori (KEPUTUSAN, bukan "pertimbangkan")**: range maksimum per export dibatasi keras **1 tahun**; validasi form menolak range lebih panjang. Ini menutup jalur OOM `FromCollection` × setahun × semua OPD.
- **PDF** via `laravel-dompdf` — Blade template + tabel + subtotal per grup + grand total, reuse collection dari service. Pola streamed download sudah dipakai di `BatchSalaryDeduction`.

**Kop PDF.** `CooperativeSettings` **tidak** punya field identitas (cuma nominal & rate); yang ada di [`GeneralSettings`](../../app/Settings/GeneralSettings.php) hanya `app_name` + `logo_path`. Pengurus **butuh alamat + nama penandatangan** di kop (dikonfirmasi) → kop = `app_name` + `logo_path` + **field settings baru** (alamat, penandatangan/pengurus). Field baru ini **prasyarat** item 3b (lihat item 7). Jumlah persis field menunggu jawaban Open Question "Field kop persis".

**Grouping di output:** default grouping per OPD lalu per anggota (memudahkan rekonsiliasi potong-gaji per OPD), dengan subtotal per grup + grand total (semua ter-net). Untuk Excel, sertakan kolom mentah (termasuk `is_reversal`) agar bisa di-pivot user.

**Audit log export (fidelity untuk trace exfil — dari security review).** Mengikuti `BatchSalaryDeduction` (`activity()->causedBy(...)->event('export')`), setiap export **wajib** di-log, tapi dengan properties yang cukup untuk melacak kebocoran PII: (a) **aktor** (user model, bukan sekadar id); (b) **format** (`pdf`/`excel`) — tanpa ini tak bisa tahu artefak mana yang keluar; (c) **setiap** parameter filter, dengan sentinel eksplisit `ALL_OPD`/`ALL_MEMBER` saat dikosongkan (export tanpa filter = kasus paling sensitif, jangan tampak seperti filter sempit); (d) date-range + row count. **Jangan** simpan nilai PII (nama/NIK) di properties — cukup id + hitungan.

**Permission — DUA permission per laporan (view vs export), bukan satu (dari security review).** Precedent [`BatchSalaryDeduction`](../../app/Filament/Pages/BatchSalaryDeduction.php) memisah `PERMISSION = access_batch_salary_deduction` (akses page, boleh petugas) dari `EXPORT_PERMISSION = export_savings_recap` (export, **pengurus-only** — ada di `CUSTOM_PENGURUS`, absen dari `CUSTOM_PETUGAS`). Laporan ini mengekspor PII finansial seluruh koperasi dalam satu file → ikuti split yang sama:
- **`access_laporan_*`** (lihat/preview on-screen) — boleh `petugas` + `pengurus`.
- **`export_laporan_*`** (tombol PDF/Excel) — **`pengurus` only**. Jangan taruh di `CUSTOM_PETUGAS`.

Ini menyelesaikan kontradiksi Goal ("hanya pengurus yang berhak" untuk export) vs draft item 4 sebelumnya yang sempat menyebut "dan/atau petugas".

Shield `custom_permissions` = `false` di [`config/filament-shield.php`](../../config/filament-shield.php), jadi `shield:generate` **tidak** membuat permission ini. Maintained manual di **tiga** tempat:
1. `database/seeders/RolePermissionSeeder.php` → `access_laporan_*` ke `CUSTOM_PETUGAS`+`CUSTOM_PENGURUS`; `export_laporan_*` ke `CUSTOM_PENGURUS` saja. Loop `ensureCustomPermissions()` (baris ~83) `firstOrCreate` dari gabungan array → create + assign sekaligus.
2. `app/Livewire/System/RoleForm.php` → `CUSTOM_LABELS` (baris ~57) untuk label UI (label saja; grant dari langkah 1).
3. Page: konstanta `PERMISSION` (`canAccess()`) + `EXPORT_PERMISSION`. Method export **wajib** `abort_unless(auth()->user()?->can(self::EXPORT_PERMISSION), 403)` server-side (defense-in-depth, persis `BatchSalaryDeduction:63`) — jangan hanya `->visible()`.

**Kolom export = whitelist (dari security review).** `Member` membawa PII berat: `nik`, `nip`, `payroll_account_number`, `bank_name`, `address`, `heir_*`. Export **hanya** boleh identitas minimum: `member_number` + `full_name` + `agency_name` + kolom transaksi (amount, tanggal, jenis, method, `is_reversal`). **Jangan** dump relasi `member` mentah. Ini laporan transaksional, bukan roster anggota.

**Batas kepercayaan (dinyatakan eksplisit).** Aplikasi **tidak** punya row-level scoping per-OPD — setiap user berwenang melihat semua OPD, dan filter OPD/anggota bersifat opsional (dikosongkan = seluruh koperasi). Jadi `export_laporan_*` = de-facto **read+export seluruh OPD**. Diterima **karena** di-gate ke pengurus + di-audit. `withTrashed()` juga berarti data anggota **resign** ikut ter-export di laporan historis — benar untuk rekonsiliasi akuntansi, tapi keputusan sadar (retensi PII orang yang sudah keluar), bukan efek samping.

### Alternatives Considered

| Alternative | Pro | Con | Verdict |
|-------------|-----|-----|---------|
| Filament custom Page + Service | Fleksibel filter kompleks, mudah tambah export PDF+Excel, testable | Perlu tulis view/table manual | **Chosen** |
| Filament Resource (list + table filters + export action) | Cepat, table filter bawaan | Read-only report di Resource terasa dipaksakan; grouping/subtotal & PDF berkop susah; navigasi CRUD membingungkan | Rejected |
| Query langsung di Blade view export | Cepat untuk 1 laporan | Duplikasi logika net/reversal antara preview & export; tak testable | Rejected |
| Reuse `scopeSignedAmount` untuk subtotal | Terlihat DRY | Aggregate-only, buang kolom grouping, tak bisa detail+subtotal sekaligus | Rejected (lihat Design) |
| Excel `FromQuery` polos | Streaming hemat memori | Tak bisa sisip baris total ter-net + tampil baris reversal | Rejected |
| Nunggu semua pertanyaan laporan dijawab dulu | Scope lengkap sekali jalan | Blocking; 2 laporan yang sudah pasti tertunda tanpa alasan | Rejected |

---

## Deploy & Rollout (dari deploy review)

Fitur read-only + 1 migration index + permission baru. Verdict deploy: **RISKY — deploy dengan syarat.** Dua hazard utama:

1. **`deploy.sh` TIDAK menjalankan seeder.** [`deploy.sh`](../../deploy.sh) hanya `php artisan migrate --force` (baris 155), tak ada `db:seed`. Akibatnya `access_laporan_*` / `export_laporan_*` **tidak ada di prod** setelah deploy → `canAccess()` false untuk semua kecuali `super_admin` (bypass via `Gate::before`, `config/filament-shield.php`). Fitur ship "invisible" ke pengurus/petugas. **Wajib** sisipkan seed manual dalam maintenance window. (Ini gap laten yang sama dengan `access_batch_salary_deduction` — konfirmasi bagaimana permission itu masuk prod, replikasi caranya.)
2. **`RolePermissionSeeder::run()` destruktif.** Pakai `syncPermissions()` (baris 68-76) yang **mencabut** permission di luar array kode. Kalau role pernah diedit manual lewat `RoleForm` UI di prod, seed akan reset ke default kode. **Mitigasi:** backup tabel `permissions`/`roles`/`role_has_permissions`/`model_has_roles` dulu; kalau prod pernah di-hand-edit, pakai grant bedah satu-off (`Permission::firstOrCreate` + `givePermissionTo`) alih-alih full seeder.

**Urutan deploy (seed step wajib disisipkan):**
```
1. backup DB (tabel permission + full dump)
2. php artisan down                 (deploy.sh step)
3. pull / composer / npm build
4. php artisan migrate --force      (index installments.payment_date — online DDL, INPLACE/LOCK=NONE)
5. >>> MANUAL, belum ada di deploy.sh <<<
   php artisan db:seed --class=RolePermissionSeeder --force
6. php artisan optimize:clear       (flush cache DB → reset spatie permission cache; rebuild route/view cache untuk Page + blade PDF baru)
7. php artisan queue:restart        (harmless; tak ada Job baru)
8. php artisan up
```
Seed (5) **sebelum** optimize:clear (6) agar permission cache rebuild bersih. Tidak ada `filament:cache-components` di deploy.sh → Page & nav "Laporan" ditemukan runtime, tak ada component cache basi. Tak ada dependency/config baru.

**Runtime — export sinkron (bukan queue).** Mengikuti pola `streamDownload` `BatchSalaryDeduction`: sinkron di request PHP-FPM (tak butuh `queue:work`). Tapi `FromCollection` + dompdf memuat seluruh hasil di memori; worst-case 1 tahun × semua OPD bisa puluhan ribu baris. Hard cap 1 tahun membatasi, tapi **verifikasi** `max_execution_time` / nginx `fastcgi_read_timeout` / PHP `memory_limit` di server tahan worst-case; kalau mepet, perketat cap atau pindah Excel ke queued export (DB queue tersedia).

**Migration `down()`**: `dropIndex(['payment_date'])` (+ index `deposit_date` opsional). Drop index non-destruktif, tak menyentuh data finansial.

**Post-deploy verifikasi**: super_admin buka kedua page (wiring/blade/dompdf OK) → setelah seed, login pengurus (nav "Laporan" muncul) & petugas (bisa lihat, **tak** bisa export) → 1 export PDF + 1 Excel sukses + activity log tertulis → `EXPLAIN` angsuran pakai index `payment_date` → cek angka saldo/angsuran anggota tak berubah (fitur read-only).

---

## Key Items

| # | Item | Effort | Parallel? | Status |
|---|------|--------|-----------|--------|
| 0 | Migration: index `installments.payment_date` (unindexed). `savings_deposits.deposit_date` hanya perlu jika basis `deposit_date` sering dipakai — `period_month` (basis default) sudah ter-index | S | — | Done |
| 1a | `DepositReportService` — `rows()` (per-row signed CASE pakai `amount`, `withTrashed` member, eager `member.agency`) + `totals()` (grouped net, mirror `SavingsBalanceService`), basis periode + `deposit_method` filter | M | setelah 0 | Done |
| 1b | `InstallmentReportService` — `rows()`/`totals()` pakai **`amount_paid`** total (tanpa split pokok/jasa — sudah diputuskan), rantai `loan.member.agency` | M | setelah 0, ∥ 1a | Done |
| 1c | Unit test service: net reversal + bcmath presisi + baris `period_month` NULL + rekonsiliasi vs batch (wajib+potong_gaji) | M | setelah 1a/1b | Done |
| 2a | Page `LaporanSetoranSimpanan` — filter (basis periode, savings_type, deposit_method, OPD, member) + validasi range ≤ 1 thn + preview | M | setelah 1a | Pending |
| 2b | Page `LaporanAngsuranPinjaman` — filter (payment_date range ≤ 1 thn, OPD, member) + preview | M | setelah 1b | Pending |
| 3a | Excel export (`FromCollection`+`WithMapping`) — **kolom whitelist** (`member_number`,`full_name`,`agency_name`+transaksi; TANPA nik/nip/rekening/alamat/heir), `abort_unless(export perm)` | M | setelah 2a/2b | Pending |
| 3b | PDF export (dompdf) + Blade, kop dari `GeneralSettings` (`app_name`+`logo_path`) + field kop baru (item 7), kolom whitelist sama, `abort_unless` | M | setelah 2a/2b, 7 | Pending |
| 3c | Activity log export: aktor(model) + **format(pdf/excel)** + semua filter (sentinel `ALL_OPD`/`ALL_MEMBER`) + row count; tanpa PII | S | setelah 3a/3b | Pending |
| 3d | Verifikasi `config/excel.php` temp-file disk **private** + cleanup aktif (publish config bila perlu) | S | setelah 3a | Pending |
| 4 | Permission **2 buah**: `access_laporan_*` (petugas+pengurus) + `export_laporan_*` (**pengurus-only**), di `RolePermissionSeeder` (array role → auto create+assign) + `RoleForm::CUSTOM_LABELS` + Page `PERMISSION`/`EXPORT_PERMISSION` + nav group "Laporan" | S | setelah 2a/2b | Pending |
| 5 | Feature test: filter + net benar, rekonsiliasi vs batch (scoped), **gating export pengurus-only + petugas ditolak**, export ter-log dgn format | M | setelah 3a/3b/3c | Pending |
| 6 | **Deploy**: sisipkan `db:seed --class=RolePermissionSeeder --force` ke maintenance window (deploy.sh tak seed) + backup tabel permission dulu (syncPermissions destruktif) | S | saat deploy | Pending |
| 7 | **Field settings baru untuk kop PDF** (alamat + penandatangan/pengurus) — migration settings + `CooperativeSettings`/`GeneralSettings` + input di halaman Settings | M | — | Pending |

**Effort:** S = small (< 1 jam), M = medium (1-3 jam), L = large (> 3 jam), — = observasi/non-code

---

## Key Files

| File | Fungsi |
|------|--------|
| `app/Services/DepositReportService.php` | (baru) Query + filter + net subtotal setoran (flat, ikut konvensi `app/Services/`) |
| `app/Services/InstallmentReportService.php` | (baru) Query + filter + net subtotal angsuran |
| `app/Filament/Pages/LaporanSetoranSimpanan.php` | (baru) Page filter + preview + trigger export |
| `app/Filament/Pages/LaporanAngsuranPinjaman.php` | (baru) Page filter + preview + trigger export |
| `app/Exports/DepositReportExport.php` | (baru) Excel export setoran |
| `app/Exports/InstallmentReportExport.php` | (baru) Excel export angsuran |
| `resources/views/reports/deposit-pdf.blade.php` | (baru) Template PDF setoran (kop + tabel + subtotal) |
| `resources/views/reports/installment-pdf.blade.php` | (baru) Template PDF angsuran |
| `resources/views/filament/pages/laporan-*.blade.php` | (baru) View halaman Filament |
| `database/migrations/..._add_report_indexes.php` | (baru) index `installments.payment_date` (+`deposit_date` opsional) |
| `database/seeders/RolePermissionSeeder.php` | (edit) `access_laporan_*`→petugas+pengurus, `export_laporan_*`→pengurus-only |
| `app/Livewire/System/RoleForm.php` | (edit) `CUSTOM_LABELS` untuk 2 permission baru |
| `deploy.sh` | (ref) tak ada `db:seed` — seed permission harus manual saat deploy |
| `config/excel.php` | (ref/verify) temp-file disk private + cleanup (belum di-publish) |
| `app/Models/SavingsDeposit.php` | (ref) sumber data; `member.agency`; `period_month` nullable |
| `app/Models/Installment.php` | (ref) sumber data; `breakdown()` pokok/jasa; rantai `loan.member` |
| `app/Filament/Pages/BatchSalaryDeduction.php` | (ref) pola Page + PERMISSION + activity export + basis `period_month` |
| `app/Services/BatchSalaryDeductionService.php` | (ref) konstanta `SAVINGS_TYPE=wajib` + `METHOD=potong_gaji` untuk scope rekonsiliasi |
| `app/Services/SavingsBalanceService.php` | (ref) idiom grouped-signed-net kanonik untuk `totals()` |
| `app/Filament/Resources/MemberSavingsBalanceResource.php` | (ref) rekap saldo SUDAH ADA — jangan duplikasi |
| `app/Settings/GeneralSettings.php` | (ref) `app_name` + `logo_path` untuk kop PDF (CooperativeSettings tak punya identitas) |

---

## Verification

- [ ] Filter rentang tanggal mengembalikan hanya transaksi dalam rentang (batas inklusif, cek boundary timezone).
- [ ] Filter savings_type / OPD / anggota bisa dikombinasikan dan hasilnya benar.
- [ ] Grand total & subtotal = signed net (terbayar − reversal), dihitung bcmath; baris reversal tetap tampil di detail.
- [ ] **Rekonsiliasi (scoped)**: laporan setoran basis `period_month` + `savings_type=wajib` + `deposit_method=potong_gaji` + tanpa reversal, untuk satu OPD+periode = hasil `BatchSalaryDeduction` OPD+periode yang sama. (Tanpa scope ini angka TIDAK akan sama — itu ekspektasi benar.)
- [ ] Setoran `sukarela`/`hari_raya` (`period_month` NULL) muncul di basis `deposit_date`, dan UI memperingatkan saat basis `period_month`.
- [ ] Transaksi anggota **soft-deleted (resign)** tetap muncul di laporan historis (`withTrashed`).
- [ ] Export Excel: kolom (termasuk `is_reversal`) & total cocok dengan preview.
- [ ] Export PDF: kop koperasi tampil, subtotal per grup + grand total benar.
- [ ] **Kolom export = whitelist**: NIK/NIP/rekening/alamat/heir TIDAK muncul di PDF/Excel.
- [ ] Setiap export ter-log: aktor + **format (pdf/excel)** + filter (sentinel `ALL_OPD`/`ALL_MEMBER`) + row count; tanpa nilai PII.
- [ ] **Gating export**: petugas bisa lihat page tapi **tak** bisa export (button hilang + `abort_unless` 403 kalau dipaksa); pengurus bisa export.
- [ ] User tanpa `access_laporan_*` tidak bisa akses page (403, bukan white-screen).
- [ ] `config/excel.php` temp-file disk private + cleanup aktif (tak ada residu XLSX PII di disk publik).
- [ ] `EXPLAIN` query angsuran memakai index `payment_date`; query setoran basis `period_month` memakai index existing (bukan full scan).
- [ ] Worst-case export (1 thn, semua OPD, tanpa filter anggota) tak timeout/OOM di server prod.
- [ ] Periode kosong (tak ada transaksi) → laporan tampil rapi "tidak ada data", bukan error.
- [ ] **Deploy**: setelah `db:seed`, pengurus & petugas dapat permission-nya; sebelum seed super_admin tetap bisa (Gate::before).

---

## Keputusan (dikonfirmasi 2026-07-07)

- ✅ **Akses export**: **pengurus-only** (`export_laporan_*`); petugas hanya bisa lihat (`access_laporan_*`), tak bisa unduh file.
- ✅ **Basis periode setoran default**: **`period_month`** (rekonsiliasi payroll). Basis `deposit_date` tetap tersedia sebagai opsi untuk sukarela/hari_raya (`period_month` NULL).
- ✅ **Laporan angsuran**: cukup **total `amount_paid`** — TIDAK pisah pokok/jasa/tab-berjangka. `breakdown()` dan 3 caveat-nya di-drop dari item 1b.
- ✅ **Kop PDF**: perlu **alamat + nama penandatangan/pengurus** → butuh **field settings baru** (item 7 jadi wajib, bukan opsional).

## Open Questions (belum dijawab)

- **Jenis laporan lain** yang dibutuhkan selain 2 ini? (kartu simpanan per anggota, laporan penarikan/pencairan, laporan tunggakan — catatan: rekap saldo per anggota SUDAH ADA di `MemberSavingsBalanceResource`, dan tunggakan sudah ada `LoanArrearsService`; jangan diduplikasi).
- **Format & periode standar** yang diharapkan: cukup PDF+Excel on-demand, atau perlu template baku bulanan/tahunan?
- **Grouping default**: per OPD → per anggota sudah tepat, atau perlu opsi grouping lain (per jenis simpanan)?
- **Field kop persis**: alamat lengkap saja, atau + kota/telp/nomor badan hukum + nama & jabatan penandatangan? (menentukan berapa field settings baru di item 7).

---

## Pipeline trace (v1)

| Stage | Agent | Key output | Date |
|---|---|---|---|
| Framing | strategist | Fokus di 2 laporan yang sudah dikonfirmasi (setoran & angsuran per periode); SHU & sisanya di-park (not invoked — framing inline dari prompt user) | 2026-07-07 |
| Data baseline | data-analyst | skipped: fitur baru report generation, belum butuh baseline metric produksi | 2026-07-07 |
| Design | architect | Filament Page + Service class, net/reversal, export dompdf+maatwebsite (retroactive — not invoked, design inline; v1 salah klaim reuse HasSignedAmount) | 2026-07-07 |
| Critique | critic | **Ronde 1 REVISE** → v2: scopeSignedAmount aggregate-only, period_month axis, index, soft-delete, N+1, bcmath, Shield seeder, log export. **Ronde 2 REVISE** (fokus "apa yang sudah dibuat") → v3: (1) kop PDF: CooperativeSettings tak punya identitas → GeneralSettings app_name+logo_path, alamat/pengurus tak ada; (2) rekonsiliasi overclaim → wajib butuh filter deposit_method=potong_gaji+savings_type=wajib+non-reversal; (3) installment CASE salah kolom → `amount_paid` bukan `amount`; + reuse SavingsBalanceService idiom, deposits-only boundary (withdrawal/swp/tab-berjangka absen), MemberSavingsBalanceResource sudah ada, permission 3 lokasi, hard cap range 1 thn. Semua diverifikasi ke kode. | 2026-07-07 |
| Security review | security-reviewer | **REVISE** → v4: (1) over-grant — export harus **pengurus-only** (split `access_laporan_*` view / `export_laporan_*` export, mirror `export_savings_recap`); (2) kolom export = whitelist, exclude NIK/NIP/rekening/alamat/heir; (3) audit log tambah aktor(model)+format+sentinel ALL_OPD/ALL_MEMBER; (4) `abort_unless` server-side di method export; (5) `config/excel.php` temp private; (6) flag eksplisit: no per-OPD scoping + `withTrashed` ekspor data resign. Semua diverifikasi ke kode. | 2026-07-07 |
| Deploy review | deploy-reviewer | **RISKY** → v4: (1) `deploy.sh` tak ada `db:seed` → permission absen di prod, fitur invisible ke pengurus/petugas; wajib seed manual dalam window; (2) `syncPermissions()` destruktif → backup tabel permission + waspada reset hand-edit; (3) export sinkron → verifikasi timeout/memory worst-case; migration index online DDL aman. Urutan deploy + verifikasi difold ke section Deploy & Rollout. | 2026-07-07 |
| Implementation | human | pending | 2026-07-07 |
| Review | reviewer | pending | 2026-07-07 |

**Ronde**: 2 (dua putaran critic REVISE + security REVISE + deploy RISKY; semua difold tanpa re-invoke architect; semua koreksi terverifikasi ke kode)
**Skipped stages**: data-analyst (report baru, no prod baseline applicable)
**Calibration notes**: v1 klaim reuse `scopeSignedAmount` tanpa baca implementasi (aggregate-only); v2 asumsikan sumber kop `CooperativeSettings` & klaim rekonsiliasi tanpa cek scope batch (`deposit_method`/`savings_type`) & prescribe `amount` untuk installment (kolomnya `amount_paid`). Pelajaran: verifikasi SETIAP klaim reuse/integrasi ke sumbernya — termasuk field settings & konstanta service — sebelum masuk Design.

---

## Changelog

- **2026-07-07 v1**: Initial draft. Scope: laporan detail setoran & angsuran per periode dengan export PDF+Excel. SHU dikecualikan atas konfirmasi pengurus.
- **2026-07-07 v2**: Revisi pasca-critique ronde 1 (REVISE). Koreksi terverifikasi ke kode: (1) hapus klaim reuse `scopeSignedAmount` → detail per-row CASE + agregat grouped terpisah; (2) basis periode setoran default `period_month` (rekonsiliasi payroll) + handling `period_month` NULL; (3) tambah item index `deposit_date`/`payment_date`; (4) `withTrashed` member soft-deleted; (5) Excel `FromCollection`+`WithMapping`, batas memori; (6) eager-load anti N+1 (`member.agency`, `loan.member.agency`); (7) bcmath; (8) permission manual di `RolePermissionSeeder`+`RoleForm` (custom_permissions=false, bukan shield:generate); (9) activity log export. Open Question pokok/jasa diperjelas dengan caveat `breakdown()`.
- **2026-07-07 v3**: Revisi pasca-critique ronde 2 (fokus "cek yang sudah dibuat", REVISE). Koreksi terverifikasi: (1) **kop PDF** — `CooperativeSettings` tak punya field identitas; pindah ke `GeneralSettings` (`app_name`+`logo_path`), alamat/pengurus tak ada di settings → item 6 opsional; (2) **rekonsiliasi** di-scope ketat (`savings_type=wajib`+`deposit_method=potong_gaji`+non-reversal) + tambah filter `deposit_method`; (3) **installment** signed CASE pakai `amount_paid` (kolom `amount` tak ada, cuma accessor); (4) net = "net of reversals", **bukan** "konsisten dengan saldo" (saldo = deposit−withdrawal); (5) boundary deposits-only eksplisit (withdrawal + `swp`/`tabungan_berjangka` absen) di Non-Goals; (6) referensikan `SavingsBalanceService::allBalances` sebagai idiom kanonik `totals()`; (7) tandai `MemberSavingsBalanceResource` & `LoanArrearsService` sudah ada — jangan duplikasi; (8) permission 3 lokasi (role array→auto create+assign, label map, canAccess); (9) index: `payment_date` valid, `deposit_date` kondisional (period_month sudah ter-index); (10) hard cap range export 1 tahun.
- **2026-07-07 v5**: Status → **Approved**. Keputusan pengurus dikunci: export pengurus-only; basis periode default `period_month`; angsuran cukup `amount_paid` (drop `breakdown()` dari 1b); kop PDF butuh alamat+penandatangan → item 7 jadi wajib (prasyarat 3b). Sisa Open Question: jenis laporan lain, template baku, grouping, field kop persis.
- **2026-07-07 v4**: Fold security review (REVISE) + deploy review (RISKY). **Security**: (1) split permission → `access_laporan_*` (view, petugas+pengurus) vs `export_laporan_*` (**pengurus-only**, mirror `export_savings_recap`); (2) kolom export whitelist (`member_number`/`full_name`/`agency_name`+transaksi) — exclude NIK/NIP/rekening/alamat/heir; (3) audit log tambah aktor(model)+format(pdf/excel)+sentinel `ALL_OPD`/`ALL_MEMBER`; (4) `abort_unless` server-side di export; (5) verifikasi `config/excel.php` temp private; (6) flag eksplisit trust boundary (no per-OPD scoping) + retensi data resign (`withTrashed`). **Deploy**: section baru **Deploy & Rollout** — deploy.sh tak `db:seed` (permission absen→fitur invisible, wajib seed manual), `syncPermissions()` destruktif (backup + waspada reset hand-edit), export sinkron (verifikasi timeout/memory), urutan deploy + post-deploy verifikasi. Key Items +3d/6, Verification +7 poin sec/deploy.
