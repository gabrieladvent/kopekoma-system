# Relation Manager Progres Angsuran di Detail Pinjaman

Tampilkan progres angsuran (semua jadwal + status bayar) langsung di halaman detail Pinjaman lewat Relation Manager Filament, supaya petugas tahu sudah sampai mana dan mana yang nunggak — tanpa pindah ke menu Angsuran.

**Author:** Ribka Restu
**Date:** 2026-06-24
**Status:** Draft

---

## Background

Saat ini halaman detail Pinjaman ([ViewLoan](../../app/Filament/Resources/LoanResource/Pages/ViewLoan.php)) hanya menampilkan infolist statis (data pinjaman + konstanta angsuran per bulan) dan satu Relation Manager: `AuditTrailRelationManager`. Tidak ada cara melihat **progres pembayaran** dari halaman ini.

Untuk tahu angsuran sudah sampai mana, petugas harus:
1. Buka menu **Angsuran** (`InstallmentResource`) terpisah,
2. Cari manual berdasarkan nama anggota / nomor pinjaman,
3. Mencocokkan sendiri jadwal vs realisasi pembayaran.

Padahal datanya sudah ada: tabel `installment_schedules` (rencana N baris, dibuat saat akad) dan `installments` (realisasi pembayaran). Pembayaran bersifat **FIFO** — [`unpaidScheduleOptions`](../../app/Filament/Resources/InstallmentResource.php) hanya mengizinkan jadwal terlama yang belum bayar (`limit(1)`), jadi jadwal terisi berurutan. Yang membuat progres "berasa bolong" bukan urutan, tapi **status jatuh tempo**: sebagian sudah lewat tempo & belum dibayar (nunggak), sebagian belum jatuh tempo. Kondisi ini belum tervisualkan di mana pun pada level satu pinjaman.

`LoanArrearsService::overdueCount()` sudah menghitung jumlah angsuran terlewat per pinjaman dan dipakai di kolom tabel list, tapi detail per-baris jadwalnya tidak terlihat.

---

## Goals

- Petugas bisa melihat **seluruh baris jadwal angsuran** satu pinjaman di halaman detailnya, urut `installment_seq`.
- Tiap baris menampilkan status yang jelas: **Terbayar**, **Nunggak** (lewat tempo, belum bayar), atau **Belum Jatuh Tempo**.
- Ada **ringkasan progres** di atas tabel: berapa angsuran lunas dari total, sisa pokok, dan jumlah yang nunggak.
- Untuk baris yang sudah dibayar, tampilkan nominal **aktual** yang masuk (bukan hanya konstanta tagihan) dan tanggal bayar, dengan jalur ke detail/kuitansi angsuran.
- Read-only & konsisten dengan pola `AuditTrailRelationManager` yang sudah ada.

## Non-Goals

- **Tidak** mengubah logika finansial apa pun (kalkulasi, validasi `assertNotBelowBill`, refund). Murni presentasi.
- **Tidak** menambah pembayaran sebagian / partial payment — status jadwal tetap biner (Terbayar/Belum Bayar) sesuai desain saat ini.
- **Tidak** memindahkan/membuang menu Angsuran (`InstallmentResource`) sebagai entry pembayaran utama.
- **Tidak** menambah denda/sanksi atas tunggakan (tetap informatif, sesuai `LoanArrearsService`).
- Aksi "Bayar" langsung dari relation manager = opsional (lihat Open Questions), bukan scope inti.

---

## Design

### Approach

Tambah satu Relation Manager baru, `SchedulesRelationManager`, di `LoanResource`, berbasis relasi `Loan::schedules()` (`HasMany` ke `InstallmentSchedule`) yang sudah ada. Ditampilkan di `ViewLoan` bersama `AuditTrailRelationManager`.

**Sumber data per baris = `installment_schedules`**, di-`with('installments')` untuk menarik realisasi pembayaran (relasi `InstallmentSchedule::installments()` sudah ada). Ini penting: jadwal = patokan tagihan, realisasi = uang nyata. Untuk baris Terbayar, ambil angsuran asli (`is_reversal = false`) untuk nominal aktual + tanggal bayar.

**Status per baris** dihitung tiga keadaan (bukan hanya kolom `status` mentah):

| Kondisi | Label | Warna |
|---|---|---|
| `status = Terbayar` | Terbayar | success (hijau) |
| `status = Belum Bayar` & `due_date < hari ini` | Nunggak | danger (merah) |
| `status = Belum Bayar` & `due_date >= hari ini` | Belum Jatuh Tempo | gray |

Logika "Nunggak" memakai definisi yang **sama** dengan `InstallmentSchedule::scopeOverdue()` (status Belum Bayar + `due_date < today`) supaya konsisten dengan `LoanArrearsService` dan kolom tunggakan di list — tidak ada definisi tunggakan kedua.

**Kolom tabel** (urut `installment_seq` asc):
- `#` (installment_seq)
- Jatuh Tempo (`due_date`)
- Tagihan (`total_due`) — konstanta
- Status (badge tri-state di atas)
- Dibayar (nominal aktual dari `installments` asli, `—` bila belum)
- Tgl Bayar (`payment_date` aktual, `—` bila belum)

**Ringkasan progres** ditaruh di header relation manager (mis. `Tables\Contracts` header / `->description()` atau header widget sederhana): `"X / N angsuran lunas · Sisa pokok Rp … · M nunggak"`. Nilai diambil dari agregat schedules + `remaining_principal` angsuran terakhir.

**Aksi per baris:** `ViewAction` read-only yang menampilkan detail angsuran terkait (kalau ada). Tidak ada create/edit/delete di relation manager ini (`isReadOnly() = true`), mengikuti `AuditTrailRelationManager`.

### Kenapa berbasis `installment_schedules`, bukan `installments`

Jadwal selalu lengkap N baris sejak akad, termasuk yang belum dibayar — inilah yang memberi gambaran "progres dari total". Kalau berbasis `installments`, baris yang belum dibayar tidak akan muncul, jadi progres & tunggakan tidak terlihat. Realisasi cukup ditarik sebagai relasi anak tiap jadwal.

### Alternatives Considered

| Alternative | Pro | Con | Verdict |
|---|---|---|---|
| Relation Manager berbasis `schedules` + tarik `installments` (dipilih) | Progres penuh N baris, tunggakan terlihat, read-only, konsisten pola | Perlu format status tri-state custom | **Chosen** |
| Relation Manager berbasis `installments` | Sederhana, langsung realisasi | Baris belum bayar hilang → progres & nunggak tak terlihat | Rejected |
| Custom infolist/Blade timeline di `ViewLoan` infolist | UI paling fleksibel (timeline visual) | Keluar dari pola Filament table, lebih banyak kode & maintenance | Rejected (mungkin enhancement nanti) |
| Tambah kolom/section di infolist existing saja | Tanpa file baru | Tidak skala untuk 12–24 baris, tak ada sort/filter | Rejected |

---

## Key Items

| # | Item | Effort | Parallel? | Status |
|---|------|--------|-----------|--------|
| 1a | Buat `SchedulesRelationManager` (tabel tri-state status, kolom seq/due/tagihan/dibayar/tgl bayar, read-only) | M | — | Done |
| 1b | Helper status tri-state + reuse definisi `scopeOverdue` (hindari duplikasi logika tunggakan) | S | bareng 1a | Done |
| 1c | Header progres: **progress bar visual %** + ringkasan (X/N lunas · sisa pokok · M nunggak) | S | setelah 1a | Done |
| 1d | Daftarkan di `LoanResource::getRelations()` + render di `ViewLoan` | S | setelah 1a | Done |
| 1e | `ViewAction` per baris → infolist detail jadwal + realisasi pembayaran | S | setelah 1a | Done |
| 1f | Test Feature: render schedules, badge status benar (terbayar/nunggak/belum tempo), nominal aktual tampil | M | setelah 1a–1e | Blocked (PHP 8.4 env pending) |

**Effort:** S = small (< 1 jam), M = medium (1-3 jam), L = large (> 3 jam), — = observasi/non-code

---

## Key Files

| File | Fungsi |
|------|--------|
| `app/Filament/Resources/RelationManagers/SchedulesRelationManager.php` | **Baru** — relation manager progres angsuran (read-only) |
| `app/Filament/Resources/LoanResource.php` | Tambah `SchedulesRelationManager::class` di `getRelations()` |
| `app/Models/Loan.php` | Relasi `schedules()` (sudah ada — dipakai, tak diubah) |
| `app/Models/InstallmentSchedule.php` | Relasi `installments()` + `scopeOverdue()` (sudah ada — direuse) |
| `app/Services/LoanArrearsService.php` | `overdueCount()` untuk ringkasan nunggak (reuse) |
| `tests/Feature/LoanResourceTest.php` | Tambah test render & status badge relation manager |

---

## Verification

- [ ] Buka detail pinjaman jangka panjang berjalan → tab/section jadwal muncul, N baris urut seq.
- [ ] Baris yang sudah dibayar → badge "Terbayar" hijau + nominal aktual + tgl bayar terisi.
- [ ] Baris lewat tempo & belum bayar → badge "Nunggak" merah; cocok dengan `overdueCount`.
- [ ] Baris belum jatuh tempo → badge "Belum Jatuh Tempo" abu-abu.
- [ ] Ringkasan header: "X/N lunas" cocok jumlah schedule Terbayar; sisa pokok cocok `remaining_principal` terakhir.
- [ ] Pinjaman Sebrakan (1 baris) tampil benar.
- [ ] Pinjaman Lunas → semua baris Terbayar, ringkasan 100%.
- [ ] Relation manager read-only (tak ada tombol create/edit/delete).

---

## Open Questions

- Apakah perlu aksi **"Bayar"** langsung dari baris jadwal (deep-link ke `InstallmentResource::create` dengan prefilled member/loan/schedule)? Mempercepat alur tapi menambah jalur pembayaran kedua — perlu hati-hati dengan FIFO & idempotency. Default: tunda, kerjakan menu Angsuran tetap sebagai entry.
- Tampilan: **tab terpisah** (default Filament relation manager) cukup, atau perlu **timeline visual**? Rekomendasi: mulai tabel; timeline = enhancement kalau diminta.
- Perlu tampilkan baris **reversal** angsuran di relation manager, atau cukup angsuran asli? Default: tampilkan nominal asli (`is_reversal = false`); reversal cukup di Audit Trail.

---

## Pipeline trace (v1)

| Stage | Agent | Key output | Date |
|---|---|---|---|
| Framing | strategist | (retroactive — not invoked) | 2026-06-24 |
| Data baseline | data-analyst | skipped: fitur presentasi read-only, tak ada metrik prod yang jadi baseline keputusan | 2026-06-24 |
| Design | architect | (retroactive — not invoked) Relation Manager berbasis `schedules` + tarik realisasi `installments`, status tri-state | 2026-06-24 |
| Critique | critic | (retroactive — not invoked) | 2026-06-24 |
| Security review | security-reviewer | skipped: read-only UI, tak nyentuh role/permission/aksi finansial/ekspor data | 2026-06-24 |
| Deploy review | deploy-reviewer | skipped: 1 file baru + 1 baris registrasi, tanpa migrasi/FQCN drift/perubahan queue-cache | 2026-06-24 |
| Implementation | implementer (Claude) | `SchedulesRelationManager` + registrasi di `LoanResource`; progress bar di header; read-only. Test (1f) blocked PHP 8.4 env | 2026-06-24 |
| Review | reviewer | pending | 2026-06-24 |

**Ronde**: 1
**Skipped stages**: data-analyst (presentasi read-only, no prod baseline), security-reviewer (read-only, no security surface), deploy-reviewer (no migration/FQCN drift)
**Calibration notes**: —

---

## Changelog

- **2026-06-24 v1**: Initial draft.
- **2026-06-24 v1.1**: Konfirmasi desain — Relation Manager (bukan menu baru), header pakai **progress bar visual**, read-only tanpa tombol Bayar. Implementasi item 1a–1e selesai; test (1f) ditunda sampai env PHP 8.4 beres (lokal masih 8.2, vendor belum ter-install).
