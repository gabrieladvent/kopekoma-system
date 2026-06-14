# Rencana Pengerjaan — Sistem Informasi Koperasi Simpan Pinjam

### KPRI KOPEKOMA — Magelang

| | |
|---|---|
| **Dokumen** | Rencana Pengerjaan & Timeline |
| **Versi** | 5.0 |
| **Mulai** | Senin, 15 Juni 2026 |
| **Target Selesai** | ± 15 Juli 2026 (1 bulan / ± 23 hari kerja) |
| **Acuan** | Rancangan Sistem v5, Rancangan Basis Data v5 |

---

## 1. Ringkasan & Kelayakan

Untuk **scope inti** (tanpa integrasi toko & tanpa perhitungan SHU), target **1 bulan realistis namun padat**, dengan syarat:

- **1 developer full-time** yang terbiasa Laravel + Filament.
- **Scope dibekukan** — integrasi toko dan SHU di luar bulan ini; approval pinjaman tetap di luar sistem; laporan dibatasi set inti.
- **Data awal koperasi** (anggota, golongan, OPD) siap paling lambat awal Minggu 2.
- **Client tersedia untuk UAT** di minggu terakhir.

Banyak modul CRUD di-scaffold cepat oleh Filament — inilah yang membuat target sebulan masuk akal.

---

## 2. Asumsi

- Mulai Senin, 15 Juni 2026; sebulan ≈ sampai 15 Juli → ± 23 hari kerja (5 minggu, minggu ke-5 parsial).
- Hari kerja normal Senin–Jumat. Bila ada libur nasional di rentang ini, gunakan buffer Minggu 5.
- Persetujuan pinjaman & verifikasi kemampuan potong gaji dilakukan di luar sistem (oleh admin/bendahara).

---

## 3. Timeline per Minggu

### Minggu 1 — Fondasi & Master Data (15–19 Juni)

| Hari | Pekerjaan |
|---|---|
| Senin | Setup Laravel + Filament + Shield + Spatie Permission, Media Library, Activity Log, Settings, Maatwebsite, DomPDF; lokalisasi Bahasa Indonesia |
| Selasa | Konfigurasi global (Settings: nominal & persentase) + role/permission + seeder `grades` |
| Rabu | CRUD `agencies` + `grades` |
| Kamis–Jumat | CRUD `members`: golongan auto-nominal, data ahli waris, upload dokumen (Media Library), cetak kartu anggota (PDF) |

### Minggu 2 — Simpanan (22–26 Juni)

| Hari | Pekerjaan |
|---|---|
| Senin | Mekanisme **reversal** & service **perhitungan saldo** (dipakai ulang semua transaksi) |
| Selasa–Rabu | `savings_deposits`: input tunggal + **batch per OPD** (potong gaji) + slip setoran PDF |
| Kamis | `member_holiday_savings` + `savings_withdrawals` (pencairan simpanan) |
| Jumat | `shopping_transactions` (Wajib Belanja — input manual) + import Excel data anggota |

### Minggu 3 — Pinjaman & Angsuran (29 Juni–3 Juli) — minggu terberat

| Hari | Pekerjaan |
|---|---|
| Senin | `loans` + service potongan (Admin/SWP) + dana diterima; pembedaan Sebrakan vs Jangka Panjang |
| Selasa | Auto-generate `installment_schedules` + Tanda Terima Pinjaman PDF + `loan_blacklists` |
| Rabu–Kamis | `installments` + halaman **pembayaran cepat** + kuitansi PDF + pelunasan |
| Jumat | Pengembalian SWP + Tabungan Berjangka saat lunas; **unit test kalkulator angsuran** |

### Minggu 4 — Laporan, Dashboard & Hardening (6–10 Juli)

| Hari | Pekerjaan |
|---|---|
| Senin–Selasa | Laporan inti (rekap setoran, rekap angsuran, saldo per anggota, pinjaman aktif, rekap potong gaji per OPD) + export PDF/Excel |
| Rabu | Dashboard widgets (anggota baru untuk RAT, saldo simpanan, pinjaman, jatuh tempo) |
| Kamis | Polish RBAC, verifikasi audit log, perbaikan bug |
| Jumat | Bug fixing + persiapan UAT |

### Minggu 5 — UAT & Serah Terima (13–15 Juli, buffer)

UAT bersama koperasi dengan kasus nyata → perbaikan → training pengguna → **deploy produksi** → manual pengguna singkat.

---

## 4. Checklist Deliverable

### Fondasi
- [ ] Setup project Laravel + Filament + paket (Shield, Permission, Media Library, Activity Log, Settings, Maatwebsite, DomPDF)
- [ ] Lokalisasi Bahasa Indonesia
- [ ] Role & permission (Super Admin, Pengurus, Petugas)
- [ ] Konfigurasi global via Settings (nominal & persentase)
- [ ] Mekanisme reversal & service saldo (reusable)
- [ ] Audit log aktif di seluruh transaksi

### Master Data
- [ ] CRUD OPD/Instansi (`agencies`)
- [ ] CRUD Golongan (`grades`) + seed nominal wajib
- [ ] CRUD Anggota (`members`) — golongan auto-nominal, ahli waris
- [ ] Upload & simpan dokumen anggota (Media Library)
- [ ] Cetak kartu anggota (PDF)
- [ ] Import anggota dari Excel

### Simpanan
- [ ] Setoran simpanan — input tunggal
- [ ] Setoran simpanan — batch per OPD (potong gaji)
- [ ] Slip setoran (PDF)
- [ ] Simpanan Hari Raya per anggota/tahun (`member_holiday_savings`)
- [ ] Pencairan simpanan (`savings_withdrawals`)
- [ ] Wajib Belanja — pemakaian saldo manual (`shopping_transactions`)

### Pinjaman
- [ ] Pencatatan pinjaman Sebrakan (jangka pendek)
- [ ] Pencatatan pinjaman jangka panjang + potongan (Admin 1% + SWP 1%)
- [ ] Auto-generate jadwal angsuran
- [ ] Tanda Terima Pinjaman (PDF)
- [ ] Upload dokumen pinjaman (Media Library)
- [ ] Blacklist peminjam (`loan_blacklists`)

### Angsuran
- [ ] Pencatatan angsuran (pokok + jasa + tabungan berjangka)
- [ ] Halaman pembayaran cepat
- [ ] Kuitansi angsuran (PDF)
- [ ] Pelunasan + status Lunas otomatis
- [ ] Pengembalian SWP + Tabungan Berjangka saat lunas
- [ ] Unit test kalkulator angsuran & potongan

### Laporan & Dashboard
- [ ] Rekap setoran (harian/periode)
- [ ] Rekap angsuran
- [ ] Daftar saldo simpanan per anggota
- [ ] Daftar pinjaman aktif
- [ ] Rekap potong gaji per OPD
- [ ] Export PDF/Excel
- [ ] Dashboard widgets

### Serah Terima
- [ ] UAT dengan kasus nyata
- [ ] Perbaikan bug pasca-UAT
- [ ] Training pengguna
- [ ] Deploy ke produksi
- [ ] Manual pengguna singkat

---

## 5. Risiko & Mitigasi

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Logika keuangan (angsuran, reversal, saldo) salah | Tinggi | Unit test sejak Minggu 3, jangan dikejar di akhir |
| Data awal koperasi terlambat | Sedang | Minta data siap awal Minggu 2; sediakan import Excel |
| Client tidak tersedia untuk UAT | Sedang | Jadwalkan UAT lebih awal; deploy bisa mundur bila perlu |
| Variasi laporan membengkak | Sedang | Set laporan dibekukan; tambahan masuk fase berikutnya |
| Libur nasional di rentang kerja | Rendah | Gunakan buffer Minggu 5 |

---

## 6. Rekomendasi

- Target realistis untuk **1 developer full-time** + Filament, asalkan **scope beku** dan **data siap**.
- Bila tenaga part-time atau muncul hal tak terduga, **Laporan & Dashboard** adalah bagian paling aman untuk digeser — inti simpan-pinjam tetap berfungsi.
- Sediakan **2–3 hari buffer** (Minggu 5) khusus UAT + perbaikan; jangan diisi fitur baru.
- Integrasi aplikasi toko (Wajib Belanja) dan perhitungan SHU dijadwalkan **terpisah** setelah sistem inti berjalan.

---

*Rencana Pengerjaan — Sistem Informasi Koperasi Simpan Pinjam KPRI KOPEKOMA (v5.0)*
