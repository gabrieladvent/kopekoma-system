# Master Data Koperasi (Golongan, OPD, Anggota)

Membangun tiga modul master data inti — Golongan, OPD/Instansi, dan Anggota — sebagai fondasi sebelum modul transaksi (simpanan, pinjaman, angsuran) dapat dikerjakan.

**Author:** gabrieladvent
**Date:** 2026-06-15
**Status:** Accepted

---

## Background

Sistem Informasi Koperasi KPRI KOPEKOMA sedang masuk **Minggu 1 (Fondasi & Master Data)** sesuai `docs/Rencana_Pengerjaan_Koperasi_v5.md`. Lapisan basis data sudah selesai — seluruh migrasi (`agencies`, `grades`, `members`, dst.) dan seluruh Eloquent model sudah ada lengkap dengan relasi, cast, activity log, media library, dan soft delete.

Namun lapisan UI (Filament Resource) baru dimulai: **hanya `GradeResource` yang sudah dibangun**. `Agency` dan `Member` baru punya model, belum punya Resource. Seluruh modul transaksi (simpanan, pinjaman) bergantung pada master data ini — `members` mereferensikan `agency_id` & `grade_id`, dan setoran/pinjaman mereferensikan `member_id`. Karena itu master data **wajib selesai lebih dulu** sebelum modul lain.

ADR ini merancang **master data apa saja yang dikerjakan dan urutannya**, bukan implementasi transaksi.

---

## Goals

- Mendefinisikan cakupan master data: **Golongan**, **OPD/Instansi**, **Anggota**.
- Menetapkan **urutan pengerjaan** berdasarkan ketergantungan data (dependency-driven).
- Memastikan setiap master data punya CRUD Filament yang lengkap, ter-RBAC (Shield), dan ter-audit.
- Menyiapkan fitur khusus Anggota: nomor anggota otomatis, golongan→nominal wajib otomatis, upload dokumen, cetak kartu, import Excel.

## Non-Goals

- Modul transaksi (simpanan, pinjaman, angsuran) — di luar ADR ini.
- Perhitungan SHU, integrasi toko, portal anggota — sesuai Bab 9 dokumentasi, di luar scope.
- Perubahan skema database besar — migrasi & model inti sudah final; ADR ini fokus membangun lapisan UI/Resource di atasnya.
  - **Pengecualian (D1):** satu migrasi **aditif** diizinkan — menambah kolom `members.mandatory_savings_amount` untuk snapshot nominal wajib. Aditif & nullable-friendly, tidak mengubah kolom existing, aman untuk data yang sudah ada.

---

## Design

### Approach

Master data dibangun **mengikuti rantai ketergantungan**: entitas yang dirujuk dibangun lebih dulu daripada entitas yang merujuk.

```
grades (lookup, dirujuk members.grade_id)      ← paling independen
agencies (lookup, dirujuk members.agency_id)
members (merujuk grade_id + agency_id)         ← paling banyak dependensi
```

Urutan: **Golongan → OPD → Anggota**. Golongan & OPD adalah lookup sederhana tanpa dependensi keluar, sehingga aman & cepat dikerjakan duluan dan langsung bisa dipakai sebagai pilihan (select) di form Anggota. Anggota dikerjakan terakhir karena: (a) butuh kedua lookup sudah ada, (b) paling kompleks (penomoran otomatis, auto-nominal, media, import, cetak kartu, soft delete).

Semua Resource memakai pola yang sudah ada di `GradeResource` (navigation group `Master`, label Bahasa Indonesia, form + table) demi konsistensi.

### Keputusan Desain (resolved blockers)

Tiga keputusan berikut sebelumnya menggantung di Open Questions dan memblokir implementasi. Sudah diputuskan:

#### D1 — Nominal simpanan wajib: **snapshot per anggota** (bukan preview live)

Nominal wajib mengikuti golongan, **tetapi disimpan sebagai snapshot di record anggota**, bukan dihitung live dari `grades` setiap saat. Alasan: data ini dasar potong-gaji & rekonsiliasi historis — bila anggota pindah golongan, setoran wajib bulan-bulan sebelumnya **tidak boleh ikut berubah**. Form Anggota mengisi nilai default dari golongan terpilih (auto-fill dari `grades.mandatory_savings_amount`), tapi nilai final tersimpan per anggota dan boleh di-override pengurus. Perubahan golongan di masa depan tidak menulis ulang snapshot lama secara retroaktif.

> **⚠️ Konsekuensi skema (terkonfirmasi dari kode):** tabel `members` **belum punya** kolom nominal apa pun (lihat [migrasi members](../../database/migrations/2026_06_14_090003_create_members_table.php)). Snapshot **mewajibkan migrasi aditif baru**: kolom `members.mandatory_savings_amount` (decimal 18,2) + tambah ke `$fillable` + `cast('decimal:2')` di `Member`. Ini **mengubah Non-Goal lama** "tanpa perubahan skema" → lihat revisi Non-Goals. Nama kolom mengikuti `grades.mandatory_savings_amount` demi konsistensi.
>
> Konsekuensi item: 3c bukan sekadar "preview", melainkan migrasi kolom + auto-fill + persist snapshot.

#### D2 — Format `member_number`: **`KM-YYYY-NNNN`**

Pola `KM-<tahun daftar>-<urut 4 digit>`, contoh `KM-2026-0001`. Urutan **reset per tahun** (`NNNN` mulai dari `0001` tiap tahun daftar baru). Penomoran di-generate otomatis saat create (item 3b), unik, dan tidak dapat diubah manual setelah tersimpan. Generasi harus aman dari race (mis. ambil `max(member_number)` tahun berjalan dalam transaksi, atau tabel counter) agar import massal (3g) tidak menabrak nomor duplikat.

#### D3 — Nominal wajib per golongan (sumber aturan bisnis)

Default nominal simpanan wajib yang dipakai item 3c. **Sumber kebenaran = [`GradeSeeder`](../../database/seeders/GradeSeeder.php)** (diverifikasi langsung dari kode, bukan dari ingatan):

| Golongan (`code`) | Simpanan wajib / bulan |
|---|---|
| HR-THL | Rp 30.000 |
| GOL-1 | Rp 50.000 |
| GOL-2 | Rp 75.000 |
| GOL-3 | Rp 100.000 |
| GOL-4 | Rp 150.000 |

> Nilai ini **bukan** referensi statis untuk di-hardcode — form 3c membaca `grades.mandatory_savings_amount` secara dinamis. Tabel di atas hanya snapshot dokumentasi nilai seeder saat ADR ditulis; bila seeder berubah, seeder yang menang.

#### D4 — RBAC: 3 peran standar (matriks akses)

Model akses awal untuk ketiga Resource (item 4). Data anggota = PII finansial (NIK, NIP, ahli waris) sehingga **delete, export, dan import dibatasi ke Pengurus ke atas**:

| Permission | Petugas | Pengurus | Super Admin |
|---|:---:|:---:|:---:|
| view / viewAny | ✅ | ✅ | ✅ |
| create | ✅ | ✅ | ✅ |
| update | ✅ | ✅ | ✅ |
| delete / restore | ❌ | ✅ | ✅ |
| export (Excel/PDF kartu) | ❌ | ✅ | ✅ |
| import Excel (3g) | ❌ | ✅ | ✅ |

> Override nominal wajib (D1) hanya untuk Pengurus ke atas. Matriks ini menjadi ekspektasi yang diverifikasi di checklist Shield.

#### Status per entitas

| Entitas | Model | Migrasi | Resource | Catatan |
|---|---|---|---|---|
| Golongan (`grades`) | ✅ | ✅ | ✅ **sudah dibangun** | CRUD lengkap, sudah ada `GradeSeeder` |
| OPD (`agencies`) | ✅ | ✅ | ❌ belum | CRUD lookup sederhana |
| Anggota (`members`) | ✅ | ✅ | ❌ belum | Modul terberat master data |

> Karena Golongan sudah selesai, pekerjaan nyata ADR ini = **OPD lalu Anggota**.

### Alternatives Considered

| Alternative | Pro | Con | Verdict |
|-------------|-----|-----|---------|
| Dependency order (Golongan→OPD→Anggota) | Lookup siap saat bangun form Anggota; tidak ada select kosong; sesuai Rencana v5 | — | **Chosen** |
| Anggota duluan (entitas paling penting) | Fokus ke nilai bisnis utama | Form Anggota butuh select OPD & Golongan yang belum ada → kerja bolak-balik | Rejected |
| Paralel ketiganya | Lebih cepat kalau banyak developer | Hanya 1 developer (Rencana v5 §1); paralel = context-switch & rework | Rejected |
| Nominal wajib: preview live dari golongan (D1) | Tanpa kolom snapshot; selalu sinkron golongan terbaru | Ubah golongan menulis ulang nominal historis → rusak rekonsiliasi potong-gaji & audit | Rejected |
| Nominal wajib: snapshot per anggota (D1) | History setoran wajib stabil; auditable; boleh override | Perlu kolom + logika auto-fill | **Chosen** |

---

## Key Items

| # | Item | Effort | Parallel? | Status |
|---|------|--------|-----------|--------|
| 1a | `GradeResource` — CRUD Golongan (sudah ada) | — | — | Done |
| 1b | `GradeSeeder` — seed HR-THL & GOL-1..4 (sudah ada) | — | — | Done |
| 2a | `AgencyResource` — CRUD OPD (form: kode, nama, alamat, PIC bendahara, no HP, status) | S | ✅ | Pending |
| 2b | Table OPD: search/filter status, kolom ringkasan jumlah anggota | S | setelah 2a | Pending |
| 3a | `MemberResource` skeleton — Resource + form minimal (identitas inti + select OPD/golongan) yang sudah bisa create/edit | M | setelah 2a | Pending |
| 3a2 | Form lengkap — seksi kepegawaian, keuangan, ahli waris (repeater) | M | setelah 3a | Pending |
| 3b | Penomoran anggota otomatis `member_number` = `KM-YYYY-NNNN`, reset per tahun, race-safe (D2) | M | setelah 3a | Pending |
| 3c | Migrasi aditif `members.mandatory_savings_amount` + auto-fill **+ persist snapshot** dari golongan, override Pengurus (D1, D3) | M | setelah 3a2 | Pending |
| 3d | Upload dokumen anggota via Media Library — **tambah `registerMediaCollections()` di `Member`** (belum ada) | M | setelah 3a | Pending |
| 3e | Table Anggota: search NIK/NIP/nama, filter OPD/golongan/status, soft-delete | M | setelah 3a | Pending |
| 3f | Cetak kartu anggota (PDF / DomPDF) | M | setelah 3a2 | Pending |
| 3g | Import data anggota massal dari Excel (Maatwebsite) — lihat §Desain Import | L | setelah 3a2, 3b | Pending |
| 4 | RBAC: generate Shield permission ketiga Resource + assign role sesuai matriks D4 | S | setelah 2a, 3a | Pending |

**Effort:** S = small (< 1 jam), M = medium (1-3 jam), L = large (> 3 jam), — = sudah selesai/non-code

> **Catatan dependency:** 3e (table) hanya butuh Resource ada (3a), bukan form lengkap. 3g (import) wajib menunggu 3b agar penomoran otomatis tidak menabrak nomor duplikat saat batch. Item 4 (Shield) butuh kedua Resource utama (2a Agency + 3a Member) sudah ada agar permission ter-generate lengkap.

### Desain Import Excel (item 3g)

- **Kolom wajib**: NIK, NIP, nama, kode OPD, kode golongan. `member_number` **tidak** diambil dari file — selalu di-generate sistem (D2).
- **Tipe kunci (terkonfirmasi kode):** `members.id` & `agency_id` = **UUID**, sedangkan `grade_id` = **integer** (`foreignId`). Import memetakan via *kode* (`agencies.agency_code`, `grades.code`) lalu resolve ke id internal — jangan menaruh id mentah di file.
- **Validasi per-baris**: NIK 16 digit & unik (cek terhadap DB *dan* terhadap baris lain dalam file yang sama untuk cegah duplikat intra-batch); kode OPD & golongan harus sudah ada (tidak auto-create master dari import).
- **Snapshot nominal (D1):** saat import, isi `mandatory_savings_amount` dari golongan yang ter-resolve, kecuali kolom override diisi.
- **Nominal wajib**: di-snapshot dari golongan saat import (D1), kecuali kolom override diisi.
- **Partial success**: baris valid tetap diproses, baris invalid dikumpulkan & dilaporkan (mis. `SkipsOnFailure` / `SkipsOnError` Maatwebsite) tanpa menggagalkan seluruh batch.
- **Chunking**: gunakan `WithChunkReading` + queue bila volume besar, agar tidak timeout.

---

## Key Files

| File | Fungsi |
|------|--------|
| `app/Filament/Resources/GradeResource.php` | ✅ Acuan pola Resource (sudah ada) |
| `app/Filament/Resources/AgencyResource.php` | **Baru** — CRUD OPD |
| `app/Filament/Resources/AgencyResource/Pages/*` | **Baru** — List/Create/Edit OPD |
| `app/Filament/Resources/MemberResource.php` | **Baru** — CRUD Anggota |
| `app/Filament/Resources/MemberResource/Pages/*` | **Baru** — List/Create/Edit/View Anggota |
| `app/Models/Member.php` | ✅ Sudah ada — tambah hook penomoran (3b), `registerMediaCollections()` (3d, belum ada), + `mandatory_savings_amount` ke fillable & cast (3c) |
| `database/migrations/*_add_mandatory_savings_amount_to_members.php` | **Baru** — migrasi aditif kolom snapshot (item 3c, D1) |
| `app/Models/Agency.php` | ✅ Sudah ada |
| `app/Imports/MembersImport.php` | **Baru** — import Excel anggota (item 3g) |
| `resources/views/pdf/member-card.blade.php` | **Baru** — template kartu anggota (item 3f) |
| `database/seeders/AgencySeeder.php` | **Baru (opsional)** — seed OPD awal bila data tersedia |

---

## Verification

- [ ] CRUD OPD: tambah/ubah/nonaktif berjalan; `agency_code` unik ditolak bila duplikat.
- [ ] CRUD Anggota: tambah anggota baru menghasilkan `member_number` format `KM-YYYY-NNNN` unik otomatis; urut reset per tahun (D2).
- [ ] Memilih golongan di form Anggota meng-auto-fill nominal simpanan wajib sesuai tabel D3; nilai **tersimpan sebagai snapshot** di record anggota (D1).
- [ ] Mengubah golongan anggota **tidak** mengubah snapshot nominal wajib record/historis yang sudah ada (D1).
- [ ] NIK 16 digit & unik tervalidasi; duplikat ditolak.
- [ ] Upload dokumen anggota tersimpan & tertaut via Media Library.
- [ ] Anggota di-nonaktifkan/keluar tidak terhapus permanen (soft delete / status).
- [ ] Cetak kartu anggota menghasilkan PDF yang benar.
- [ ] Import Excel: baris valid masuk, baris invalid dilaporkan tanpa menggagalkan seluruh batch; duplikat NIK intra-batch tertolak; `member_number` di-generate sistem.
- [ ] Shield permission ketiga Resource ter-generate; akses sesuai matriks D4 — Petugas tidak bisa delete/export/import, Pengurus & Super Admin bisa.
- [ ] Override nominal wajib hanya tersedia untuk Pengurus ke atas (D4).
- [ ] Perubahan tercatat di `activity_log`.

---

## Open Questions

- ~~Format `member_number`~~ → **Resolved (D2):** `KM-YYYY-NNNN`, reset per tahun.
- ~~Nominal wajib preview vs snapshot~~ → **Resolved (D1):** snapshot per anggota, auto-fill dari golongan, override Pengurus.
- ~~Akses/RBAC data anggota~~ → **Resolved (D4):** 3 peran standar; delete/export/import dibatasi Pengurus+.
- **Masih terbuka:** Apakah data awal OPD & Anggota tersedia dalam Excel untuk seeder/import, atau diinput manual? (Rencana v5: data awal siap awal Minggu 2.) → Mempengaruhi apakah 3g masuk jalur kritis Minggu 2 atau bisa ditunda.
- ~~Apakah kolom snapshot D1 sudah ada di skema?~~ → **Resolved (terkonfirmasi kode):** belum ada. `members` tidak punya kolom nominal apa pun. Diputuskan tambah migrasi aditif `members.mandatory_savings_amount` (item 3c); Non-Goals direvisi untuk mengizinkan ini.

---

## Pipeline trace (v1)

| Stage | Agent | Key output | Date |
|---|---|---|---|
| Framing | strategist | (retroactive — not invoked) | 2026-06-15 |
| Data baseline | data-analyst | skipped: greenfield build, belum ada data produksi untuk baseline | 2026-06-15 |
| Design | architect | (retroactive — not invoked) urutan dependency-driven Golongan→OPD→Anggota | 2026-06-15 |
| Critique | critic | (retroactive — not invoked) | 2026-06-15 |
| Security review | security-reviewer | diangkat ke fase desain MemberResource (3a/3e/3g/3f), bukan ditunda ke item 4 — data anggota = PII finansial; matriks akses D4 ditetapkan di awal | 2026-06-15 |
| Deploy review | deploy-reviewer | skipped: tidak ada migrasi baru, hanya lapisan Filament Resource di atas skema final | 2026-06-15 |
| Implementation | implementer / human | pending | 2026-06-15 |
| Review | reviewer | pending | 2026-06-15 |

**Ronde**: 1
**Skipped stages**: data-analyst (greenfield, no prod data), deploy-reviewer (no migration diff)
**Calibration notes**: —

---

## Changelog

- **2026-06-15 v1**: Initial draft — rancangan master data & urutan pengerjaan (Golongan✅ → OPD → Anggota).
- **2026-06-15 v2**: Status Draft → Accepted. Tutup 3 blocker via keputusan desain D1–D4: snapshot nominal wajib (D1), format `member_number` `KM-YYYY-NNNN` (D2), tabel nominal per golongan (D3), matriks RBAC 3 peran (D4). Pecah bottleneck 3a → 3a + 3a2; longgarkan dependency 3e; perbaiki dependency 3g (butuh 3b) & item 4 (butuh 2a+3a); tambah §Desain Import Excel; angkat security review ke fase desain; resolve Open Questions terkait.
- **2026-06-15 v3**: Koreksi berbasis verifikasi kode. (1) **Fix angka D3** — nilai nominal sebelumnya keliru; disinkronkan ke `GradeSeeder` aktual (GOL-1=50k, GOL-2=75k, GOL-3=100k). (2) **D1 skema** — terkonfirmasi `members` belum punya kolom nominal & nama kolom yang benar `mandatory_savings_amount`; tambah migrasi aditif (item 3c) & revisi Non-Goals untuk mengizinkannya. (3) Catat 3d butuh `registerMediaCollections()` (belum ada di `Member`). (4) Tambah catatan tipe kunci campuran (UUID vs integer) untuk import 3g. Resolve Open Question kolom snapshot.
