# Modul Simpanan (Saldo Service, Reversal, Setoran, Pencairan, Wajib Belanja)

Membangun modul Simpanan (Minggu 2) di atas fondasi keuangan yang dapat dipakai ulang — service saldo + mekanisme reversal — sebelum CRUD transaksi simpanan dikerjakan.

**Author:** gabrieladvent
**Date:** 2026-06-16
**Status:** Accepted

> **v5 (2026-06-16):** Critic putaran-3 (v4) → **REVISE** ditindaklanjuti. Koreksi konsistensi hasil akumulasi revisi: (1) D9 keliru sebut `shopping_transactions` butuh `savings_type` — tak perlu (semua baris = belanja); (2) asimetri tahun Hari Raya — sisi deposit (`period_month` nullable) juga harus dikunci non-null, bukan hanya withdrawal `period_year`; (3) klaim `allBalances()` "2 query grouped" stale — hari_raya butuh query terpisah per `period_year` + filter `status=cair`; (4) scope `signed_amount` tak boleh memikul filter status — status diterapkan di service/laporan; (5) `MemberHolidaySaving` belum punya `LogsActivity` (langgar konvensi) → masuk 4a; (6) `syncPermissions` destruktif menghapus assignment UI saat re-seed. Invariant finansial terdistribusi diakui eksplisit + tiap punya rumah test. **Risiko timeline:** core ~22 jam coding + setup MySQL test → mepet untuk 5 hari/1 dev (lihat Open Questions).
> **v4 (2026-06-16):** Critic putaran-2 (v3) → **REVISE** ditindaklanjuti. Tiga defect ditutup: (1) `period_year` kolom aditif wajib di `savings_withdrawals` — saldo Hari Raya per-tahun tak terhitung tanpa ini (`withdrawal_date.year` ambigu); (2) disburse **serialize lock per (member,jenis)** menutup TOCTOU over-draw ("validasi 2×" saja tak cukup); (3) **reverse pencairan `cair` di-gate Pengurus+** (Petugas tak boleh batalkan keputusan uang-keluar). Plus: state machine pencairan eksplisit, klaim "reconfigurable" dikoreksi, item 4b dipecah 4b-1/4b-2. **Reverse-of-cair dikonfirmasi pengurus = uniform Petugas+** (konsistensi penuh; tradeoff dikontrol via laporan reversal periodik).
> **v3 (2026-06-16):** 3 Open Question ⛔ dijawab pengurus. (A) Pencairan = workflow `draft→acc→cair`, ACC Pengurus+ (D8-A/D10). (D) Reversal = Petugas **dan** Pengurus, gating **berbasis permission Shield** (bukan role hardcoded) agar hak akses custom mungkin (D7/D11). (Hari Raya) saldo **per `period_year`** (D1/4b).
> **v2 (2026-06-16):** Dikeraskan via pipeline `architect` + `security-reviewer` + `critic` (dijalankan betulan). Verdict: **REVISE → ditindaklanjuti**. Empat temuan _critical_ critic + tujuh kondisi security sudah masuk.

---

## Background

Master data (Golongan, OPD, Anggota) sudah **selesai 100%** dan terverifikasi GREEN lewat `/adr:validate` ([ADR master data](2026-06-15-master-data-koperasi.md)) — 69 test pass. Sesuai [Rencana Pengerjaan v5 §3](../Rencana_Pengerjaan_Koperasi_v5.md), tahap berikutnya adalah **Minggu 2 — Simpanan (22–26 Juni)**.

Lapisan basis data untuk transaksi **sudah ada**: migrasi (`savings_deposits`, `savings_withdrawals`, `member_holiday_savings`, `shopping_transactions`) dan model Eloquent-nya. **Catatan v2 (terkonfirmasi kode):** skema **tidak sepenuhnya final** untuk kebutuhan ADR ini — `shopping_transactions` **tidak punya** `idempotency_key`; tiga tabel transaksi belum punya `unique(reversal_of_id)`. Maka modul ini **butuh beberapa migrasi aditif** (lihat D3, D9). Yang belum ada juga: **lapisan service keuangan** dan **Filament Resource**.

[Dokumentasi Sistem v5 §7](../Dokumentasi_Sistem_Koperasi_v5.md) menetapkan tiga prinsip keuangan non-negotiable yang dipakai ulang **semua** transaksi (Simpanan, Pinjaman, Angsuran):

1. **Saldo dihitung dari transaksi**, bukan angka yang diubah manual.
2. **Reversal, bukan hapus** — koreksi via transaksi-lawan; transaksi keuangan tidak pernah dihapus.
3. **Pencegahan input ganda** — satu transaksi tidak boleh tercatat dua kali akibat double-submit.

Risiko #1 Rencana v5 §5 ("logika keuangan salah → dampak Tinggi") lahir di fondasi ini. Karena itu **service saldo + reversal wajib dibangun & di-unit-test lebih dulu** sebelum CRUD apa pun.

---

## Goals

- **Service saldo reusable** — saldo per anggota per jenis dari transaksi (computed-on-read), net of reversal.
- **Mekanisme reversal generik** — transaksi-lawan + alasan + audit per-baris, dipakai ulang semua transaksi.
- **Idempotency** (anti double-submit) di semua jalur tulis transaksi, termasuk `shopping_transactions`.
- CRUD Setoran: **input tunggal** + **batch per OPD** (potong gaji) + **slip PDF**.
- Simpanan Hari Raya (config tahunan) + **Pencairan** (`savings_withdrawals`), dibatasi jenis yang punya saldo riil di Minggu 2.
- Wajib Belanja: pencatatan **pemakaian saldo manual** (`shopping_transactions`), saldo dua-sisi.

## Non-Goals

- **Pinjaman & Angsuran** (Minggu 3) — di luar ADR ini; tapi service saldo + reversal dirancang agar langsung dipakai modul itu. Jenis `swp` & `tabungan_berjangka` lahir di sana → **N/A di Minggu 2** (D1).
- **Integrasi aplikasi toko** (`shopping_transactions.source='store_api'`) — Bab 9; sekarang hanya `manual`.
- **Perubahan skema besar** — hanya migrasi **aditif** yang diizinkan (preseden D1 ADR master data): `unique(reversal_of_id)` ×3 (D3) + `idempotency_key`/`transaction_number` di `shopping_transactions` (D9) + `status`/`approved_by`/`approved_at`/`disbursed_at`/`period_year` di `savings_withdrawals` (D10, D1). Tidak mengubah kolom existing.
- **Laporan & Dashboard** (Minggu 4) — rekap lintas-anggota & laporan reversal periodik di luar scope ini (tapi audit per-event disiapkan di sini agar laporan itu mungkin nanti).

---

## Design

### Approach

**Fondasi dulu, baru CRUD** — kebenaran keuangan dikunci & di-unit-test sebelum ada UI yang menulis transaksi.

```
SavingsBalanceService (saldo net-of-reversal per type)   ← fondasi, unit-tested duluan
ReverseTransaction Action + interface Reversible          ← fondasi
generator nomor race-safe + migrasi unique(reversal_of_id)← fondasi
        ↓ dipakai semua di bawah
Setoran tunggal → Slip PDF        |  Setoran batch per OPD (engine + UI)
Pencairan (hari_raya+sukarela)    |  Hari Raya (config)   |  Wajib Belanja (pemakaian)
```

Resource mengikuti **konvensi baku** master data ([memory: filament-resource-conventions]): view page + infolist, redirect create→list & edit→view, ActionGroup, `MoneyInput` global, notifikasi ber-body, `AuditTrailRelationManager` + `LogsActivity` **wajib per record**, menu Log Aktivitas. Gating peran pakai `ELEVATED_ROLES`.

> **⚠️ Catatan environment test (temuan critic, terkonfirmasi `phpunit.xml`):** test suite jalan di **SQLite `:memory:`**, sedang produksi **MySQL** (`config/database.php`). `lockForUpdate` adalah **no-op di SQLite** → klaim "race-safe" (generator nomor 1c, batch lock D5, guard reversal D3) **tidak terbukti oleh test default**. Konsekuensi: test konkurensi untuk item 1d/3a-2 **wajib dijalankan terhadap MySQL** (connection khusus / CI service), atau klaim diturunkan jadi "best-effort + unique-constraint backstop". Unique constraint adalah jaring pengaman sejati (berlaku di kedua engine); lock hanya optimasi.

### Keputusan Desain

#### D1 — Saldo: computed-on-read, net via `CASE`, rumus per jenis

Saldo **dihitung dari agregat transaksi**, tidak pernah kolom mutable (Dokumentasi §7). **Reversal disimpan `amount` POSITIF**; tandanya ditentukan `is_reversal` (bukan amount negatif — amount negatif merusak slip/laporan yang `SUM(amount)`):

```
net(tabel, type) = SUM( CASE WHEN is_reversal = 0 THEN amount ELSE -amount END )  WHERE savings_type = type
```

Rumus saldo **per `savings_type`** (asimetri enum terkonfirmasi: deposits punya `wajib_belanja` tapi tidak `swp`/`tabungan_berjangka`; withdrawals kebalikannya):

| `savings_type` | Saldo | Tabel |
|---|---|---|
| `pokok`, `wajib`, `sukarela` | `deposits.net(type) − withdrawals.net(type, status=cair)` | deposits, withdrawals |
| `hari_raya` | **per `period_year` Y**: `deposits.net('hari_raya', tahun period_month=Y) − withdrawals.net('hari_raya', period_year=Y, status=cair)` | deposits, withdrawals |
| `wajib_belanja` | `deposits.net('wajib_belanja') − shopping.net()` | deposits, shopping_transactions |
| `swp`, `tabungan_berjangka` | **N/A Minggu 2** — service `throw UnsupportedSavingsType` (sumber dari modul Pinjaman) | — |

**Pengurangan withdrawal hanya dihitung saat status `cair`** (D10) — draft/acc belum mengurangi saldo (uang belum keluar). **Hari Raya per-tahun** (keputusan pengurus): saldo di-scope `period_year`; pembagian akhir tahun = withdrawal `hari_raya` untuk tahun itu yang mengembalikan saldo tahun tsb ke 0. Tahun tanpa program Hari Raya (tak diputuskan RAT) → tak ada deposit → saldo 0.

> **⚠️ Konsistensi tahun dua sisi (critic v3 + v4):** `savings_withdrawals` cuma punya `withdrawal_date` → tahun pembagian **ambigu** (program 2026 cair Jan 2027 salah tahun) → `period_year` **wajib kolom aditif eksplisit** (item 0), required write-path Hari Raya, ikut `reverseClone()` (D3).
>
> **Sisi deposit JUGA harus dikunci (koreksi v5):** `savings_deposits.period_month` **nullable** → menurunkan tahun dari `period_month.year` mengulang ambiguitas yang sama bila NULL/silang-tahun. Maka **dua sisi di-scope dengan mekanisme konsisten**: write-path setoran `hari_raya` **mewajibkan `period_month` non-null** (tahun program = `period_month.year`); withdrawal `hari_raya` mewajibkan `period_year`. Validasi menolak setoran `hari_raya` tanpa `period_month`. Tanpa ini, `net(deposit) − net(withdrawal)` bisa diam-diam beda tahun.

`allBalances()` (koreksi v5): bukan 2 query polos. Deposits = 1 query `GROUP BY savings_type`. Withdrawals = **`WHERE status='cair'`** + query `hari_raya` **terpisah** `GROUP BY period_year` (jenis lain tak boleh di-group per tahun). Plus 1 shopping. Digabung di PHP (dua tabel beda, tak bisa JOIN-agregat). Index deposits `['member_id','savings_type','period_month']` ada; `savings_withdrawals` composite `['member_id','savings_type','status']` = kandidat aditif bila EXPLAIN jelek.

> **Scope `signed_amount` hanya menangani tanda `is_reversal`, BUKAN filter status (koreksi v5).** Filter `status='cair'` diterapkan **di luar** scope, oleh `SavingsBalanceService` & laporan agregat — jangan andalkan scope bersama untuk status, agar slip/laporan tak salah hitung draft/acc sebagai outflow. Slip pencairan = **per-record** (record cair tertentu) jadi aman. Nilai = `string`/`decimal:2`, **jangan float**.

API: `balanceByType(member, type)`, `holidayBalance(member, year)`, `shoppingBalance(member)`, `allBalances(member)`, `canWithdraw(member, type, amount)`, `canSpendShopping(member, amount)`.

#### D2 — Format nomor transaksi & reset per tahun

Preseden `member_number` (race-safe `lockForUpdate` dalam transaksi):

| Entitas | Kolom | Format | Contoh |
|---|---|---|---|
| Setoran | `savings_deposits.transaction_number` | `STR-YYYY-NNNNNN` | `STR-2026-000001` |
| Pencairan | `savings_withdrawals.withdrawal_number` | `TRK-YYYY-NNNNNN` | `TRK-2026-000001` |

6 digit (volume > anggota), reset per tahun, immutable. `shopping_transactions` diberi nomor `BLJ-YYYY-NNNNNN` lewat migrasi aditif (D9) agar pemakaian belanja punya identifier yang dapat direkonsiliasi/di-reversal.

#### D3 — Reversal: Action class, guard via unique index, tak bisa reverse-of-reverse

Koreksi **tidak pernah** hapus/edit baris asli. Implementasi: **invokable Action class** `app/Actions/ReverseTransaction.php` (bukan trait — reversal itu *workflow*, bukan *behavior* model) + **interface marker** `app/Contracts/Reversible.php` yang ketiga model implement (`reverseClone(): array` — field yang di-copy ke baris lawan, **termasuk `period_year` untuk withdrawals Hari Raya**; `reversalNumberColumn(): ?string`). Action: guard → `DB::transaction` → `lockForUpdate` re-fetch asli → buat baris baru (`is_reversal=true`, `reversal_of_id=asli`, `amount` sama, `notes=alasan` wajib min-length) → log causer eksplisit `activity()->log("Reversal: $reason")`.

**Guard single-reversal = migrasi aditif `unique('reversal_of_id')`** di tiga tabel (MySQL & SQLite sama-sama izinkan multiple-NULL di UNIQUE → baris non-reversal aman, dua reversal atas asli sama → violation). Tangkap `QueryException` (SQLSTATE 23000) → tolak "Transaksi sudah pernah di-reversal". Lebih kuat dari cek-lalu-insert (yang race).

**Guard tambahan (temuan critic):**
- **Tak boleh reverse sebuah reversal** — Action menolak bila `original->is_reversal === true`.
- **Guard anggota non-aktif** — Action menolak reversal bila member status Keluar/Meninggal (konsisten Dokumentasi §6, di semua jalur, bukan hanya batch).

**Akses = Pengurus+** (setara hak delete; koreksi finansial sensitif). Lihat D8 untuk resolusi kontradiksi §5.6.

#### D4 — Idempotency: Hidden uuid per render, compare-or-warn (bukan silent)

Setiap form create pakai `Forms\Components\Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid())` — `default()` dievaluasi **sekali per render** → satu render = satu key; reload = key baru (submission sah baru). Double-click → dua request key sama → request kedua kena `unique`.

**Saat unique violation `idempotency_key`:** fetch baris existing, **bandingkan payload**. Jika identik → dedupe diam (sukses idempoten, notif "Transaksi sudah tercatat"). **Jika payload berbeda → WARNING, bukan silent success** (temuan critic: silent-success bisa menyembunyikan nilai berbeda yang dikira user tersimpan). Membedakan dari guard single-reversal (D3) yang memang error bisnis.

#### D5 — Setoran batch per OPD: chunked create (bukan bulk insert), key deterministik + pre-commit check

Custom Page `app/Filament/Pages/BatchSalaryDeduction.php`. UI: `Select` OPD → tabel anggota **aktif** OPD (status ≠ keluar) + prefill `members.mandatory_savings_amount` (snapshot) untuk jenis `wajib` → centang/edit → submit. Komponen tabel: **TableRepeater** bila plugin `awcodes/filament-table-repeater` tersedia; jika tidak, **custom Livewire table** (bukan `Repeater` biasa — berat untuk ratusan baris).

**Engine batch (item 3a-2) — keputusan revisi (temuan critic #1):**
- **Pakai `create()` per baris dalam transaksi (chunked), BUKAN bulk `insert()`.** Skala koperasi = puluhan anggota/OPD, bukan jutaan; `insert()` mem-bypass `LogsActivity` → **menghapus audit per-anggota** yang justru diwajibkan security (#E) & konvensi proyek. Auditability menang atas performa pada transaksi paling sensitif (Danger Zone reconciliation §5).
- **Nomor transaksi race-safe** tanpa N lock berurutan: `lockForUpdate` ambil last-number **sekali** → reservasi range di memori → assign ke tiap `create()`. (Backstop: `unique(transaction_number)`.)
- **Idempotency batch deterministik** per `(member_id, period_month, savings_type, method='potong_gaji')` → re-run periode sama = key sama = ditolak unik. **Plus pre-commit duplicate check**: query apakah sudah ada setoran non-reversal untuk anggota+periode+jenis itu → skip/tolak (cegah double-run lintas hari/sesi — idempotency per-render TIDAK menutup ini, temuan security #B).
- **Lock per batch `(OPD, period_month)`** agar dua run konkuren tak race.
- **`deposited_by`** untuk batch potong-gaji = `bendahara` (enum hanya `bendahara`/`anggota`); `recorded_by` = petugas pelaksana → audit per-baris tetap menyimpan aktor sebenarnya (sebab pakai `create()`).
- Satu baris gagal → seluruh batch rollback (atomic), **dengan pre-flight validation per baris + pesan per-anggota** agar petugas tahu penyebab (jangan dorong matikan validasi).
- **Log batch sebagai satu peristiwa** (`activity()->log("Batch potong gaji OPD X periode Y: N setoran")`) **DI ATAS** log per-baris — agar double-run terdeteksi di audit.

> **Catatan interaksi reversal × key deterministik (temuan critic):** bila batch di-reversal lalu di-run ulang dengan nominal terkoreksi, key deterministik akan bentrok dengan baris asli → koreksi ter-dedupe. Karena itu key deterministik **harus mengecualikan baris yang sudah punya reversal** dalam pre-commit check (anggap "slot periode kosong lagi" setelah di-reversal). Lihat Open Question double-setoran.

#### D6 — Wajib Belanja: saldo dua-sisi + idempotency (butuh D9)

Setoran Wajib Belanja = `savings_deposits` (`wajib_belanja`) menambah; pemakaian = `shopping_transactions` (`manual`) mengurangi. Saldo = `deposits.net('wajib_belanja') − shopping.net()`. Service menolak pemakaian > saldo. **Pemakaian belanja wajib punya proteksi double-submit sama seperti setoran** → butuh `idempotency_key` di `shopping_transactions` (D9). `recorded_by` **dipaksa non-null di service** (skema-nya nullable — temuan security #M3). Dana tidak dapat diuangkan.

#### D7 — RBAC: gating berbasis **permission**, bukan role hardcoded (revisi pengurus)

Keputusan pengurus: jangan kunci ke nama role (`ELEVATED_ROLES = ['super_admin','pengurus']` ala master data). Gunakan **Shield permission** yang di-assign ke role, sehingga **hak akses custom** bisa dibentuk tanpa ubah kode. Tiap aksi sensitif punya ability sendiri; cek pakai `auth()->user()->can('<ability>_<resource>')`, **bukan** `hasAnyRole(...)`.

| Aksi (ability) | Default assignment v3 | Catatan |
|---|---|---|
| Create setoran / pemakaian belanja | Petugas, Pengurus, Super Admin | uang masuk/netral |
| Create pencairan = **draft** | Petugas, Pengurus, Super Admin | belum keluar uang (D10) |
| **ACC + Cair pencairan** (`approve`/`disburse`) | **Pengurus, Super Admin** | mata kedua sebelum uang keluar (D8-A) |
| **Reversal** (`reverse`, semua tabel & semua status) | **Petugas, Pengurus, Super Admin** | Petugas+Pengurus tanpa pengecualian (D8-D, dikonfirmasi pengurus) |
| Export/cetak rekap (`export`) | **Pengurus, Super Admin** | PII finansial; aktivitas export **ter-log** |

> **Reverse-of-cair (dikonfirmasi pengurus 2026-06-16):** reversal **uniform Petugas+** termasuk pencairan yang sudah `cair` — demi konsistensi "reversal = Petugas dan Pengurus", tanpa pengecualian. **Tradeoff diterima:** Petugas bisa membatalkan pencairan cair yang disetujui Pengurus secara sepihak; mitigasi bergantung pada **kontrol detektif** — alasan reversal wajib, audit per-event, dan **laporan reversal periodik** (Minggu 4) yang wajib di-review pengurus. Bukan kontrol preventif.

> **Batas klaim "reconfigurable" (terkonfirmasi `RolePermissionSeeder`):** seeder existing membangun nama permission mekanis `{prefix}_{resource}` dari `BASE_PREFIXES`/`ELEVATED_PREFIXES` hardcoded — ability `reverse`/`approve`/`disburse`/`export` **tidak ada di situ**. Jadi tiap ability custom butuh **perubahan kode**: (a) Policy method, (b) registrasi agar Shield mengenalinya, (c) edit seeder untuk assign. Yang **reconfigurable tanpa kode** hanya **assignment ability→role lewat UI Shield setelah ability ada**; ability-nya sendiri = artefak kode. (Klaim v3 "dikunci kode hanya pemetaan ability→aksi" dikoreksi.)
>
> **⚠️ `syncPermissions` destruktif (koreksi v5):** `RolePermissionSeeder` existing pakai `$role->syncPermissions(...)` yang **menimpa total** — assignment custom yang dibuat admin via UI **terhapus saat `db:seed` berikutnya**. Jadi "reconfigurable via UI" rapuh: tiap perubahan permanen tetap harus masuk seeder, atau seeder diubah ke pola **aditif** (`givePermissionTo` tanpa sync) untuk role custom. Catat sebagai konsekuensi, jangan janjikan UI-config yang awet di atas seeder destruktif.

`shield:generate` menghasilkan CRUD standar; ability custom `reverse`/`approve`/`disburse`/`export` didefinisikan via **Policy method + daftar ke seeder manual**, dibuat **bersama tiap Resource**. **Custom Page batch butuh permission tersendiri** (Shield tak auto-policy untuk Page — security #C): tambah entri page ke `RolePermissionSeeder` (`RESOURCES` hardcoded `['grade','agency','member']`) + **test reject** Petugas tanpa permission batch.

> **Filament enforcement (temuan critic):** `canCreate`/`canEdit` itu static/per-resource (cukup untuk create). Tapi ACC/Cair/reverse = **aksi per-record** → wajib `Action::make()->visible(fn($record)=>auth()->user()->can('disburse',$record))` **plus guard di body action** (visible() hanya sembunyikan tombol, bukan enforcement) **plus** Policy method. Bukan satu baris.

> **Catatan retrofit:** Resource master data lama masih pakai `ELEVATED_ROLES`. Konsistensi penuh (migrasi master data ke permission-based) di luar scope Minggu 2 — dicatat sebagai follow-up. Modul Simpanan **mulai** dengan pola permission-based.

> **Catatan security (tradeoff D8-D):** membuka reversal ke Petugas memperlebar permukaan reversal-abuse dibanding v2 (Pengurus+ saja). Mitigasi tetap berlaku: alasan wajib, audit per-event, dan **laporan reversal periodik** (Minggu 4) sebagai kontrol detektif. Dual-control yang hilang di reversal **dipindahkan ke pencairan** (D10 ACC) — titik uang-keluar yang lebih material.

#### D8 — Resolusi governance (security review) — **RESOLVED pengurus**

- **(A) ✅ Pencairan = workflow `draft → acc → cair`** (bukan single-actor). Petugas+ membuat **draft**; **Pengurus+ meng-ACC lalu menandai cair** (uang keluar). Saldo baru berkurang saat status **cair** (D10). Mata kedua terjaga. → lihat **D10**.
- **(D) ✅ Reversal = Petugas DAN Pengurus** (sejalan Dokumentasi §5.6). Gating **berbasis permission** (D7) sehingga reconfigurable. Tidak ada lagi kontradiksi §5.6 — ADR mengikuti dokumentasi, tradeoff security dicatat di D7.
- **Pencairan dibatasi jenis Minggu 2** — write-path **whitelist `['hari_raya','sukarela']`** (bukan enum penuh). Enum DB masih 6 jenis → form naif (`Select` dari enum) **bocor** `swp`/`tabungan_berjangka`/`pokok`/`wajib` (critic #2: throw ada di service baca, bukan tulis). Whitelist ditegakkan di **form + Action tulis**, bukan hanya service.

#### D10 — Workflow status pencairan `draft → acc → cair` (migrasi aditif)

`savings_withdrawals` belum punya kolom status (terkonfirmasi kode) → **migrasi aditif**: `status` enum `['draft','acc','cair','ditolak']` (default `draft`), `approved_by` (nullable FK users), `approved_at`, `disbursed_at`. Tidak ubah kolom existing.

| Status | Siapa | Efek saldo |
|---|---|---|
| `draft` | Petugas+ create | **Tidak** mengurangi saldo |
| `acc` | Pengurus+ approve (set `approved_by`/`approved_at`) | Belum (uang belum keluar) |
| `cair` | Pengurus+ disburse (set `disbursed_at`) | **Mengurangi saldo** (D1: `withdrawals.net(status=cair)`) |
| `ditolak` | Pengurus+ | Tidak; **terminal** (tak bisa balik ke draft/acc) |

**Transisi yang diizinkan (state machine eksplisit, temuan critic):** `draft→acc`, `draft→ditolak`, `acc→cair`, `acc→ditolak`. **`ditolak` & `cair` = terminal.** `ditolak` tak bisa di-reopen (cegah bypass gate uang-keluar via edit). Edit draft hanya saat status `draft`.

**Validasi saldo & race (revisi — "2×" tidak cukup, temuan critic):** cek saldo saat ACC (early feedback) **dan** saat cair. Tapi cek-lalu-insert adalah **TOCTOU**: dua pencairan konkuren member+jenis sama bisa dua-duanya lolos cek lalu over-draw, dan **tak ada unique-constraint backstop** untuk invariant agregat "Σ cair ≤ saldo". Maka **disburse wajib serialize**: pegang **lock per `(member_id, savings_type)`** (row lock `members`/`lockForUpdate`, atau advisory lock) **melintasi cek-saldo-sampai-insert**. Invariant ini **lock-dependent → wajib diuji di MySQL** (no-op di SQLite); jangan klaim "otoritatif" tanpa lock.

Transisi di-gate permission (`approve`/`disburse`, D7) + ter-log. **`activity_log` = SSOT audit "siapa approve/cair"**; kolom `approved_by`/`approved_at`/`disbursed_at` = state workflow denormalized, ditulis **atomik bersama** entri log dalam transisi (jangan sampai drift).

**Reversal pencairan** hanya untuk status `cair`, dan **boleh Petugas+** (uniform, dikonfirmasi pengurus — lihat D7 tradeoff). `reverseClone()` meng-copy `period_year` (Hari Raya).

#### D11 — Nominal setoran per jenis: settings-driven + locked (revisi pengurus 2026-06-17)

Nominal setoran **tidak bebas** — ditetapkan per jenis dari `App\Settings\CooperativeSettings` (Spatie, sudah ada: `savings_pokok_amount`, `savings_wajib_belanja_amount`, `savings_sukarela_min`) atau dari registrasi Hari Raya. Form setoran (2a) jadi reaktif (`member_id`/`savings_type`/`period_month` = `->live()`):

| Jenis | `amount` form | Sumber | Penegakan server |
|---|---|---|---|
| `pokok` | disabled | `settings.savings_pokok_amount` | `enforceAmountRules()` timpa nilai (abaikan input client) |
| `wajib_belanja` | disabled | `settings.savings_wajib_belanja_amount` | idem |
| `hari_raya` | disabled | `MemberHolidaySaving.monthly_amount` (registrasi aktif yang **rentangnya memuat `deposit_date`**) | timpa dari registrasi; rule `deposit_date` tolak bila di luar semua rentang aktif; `period_month` auto-tag ke tahun program |
| `wajib` | editable | prefill `members.mandatory_savings_amount` (snapshot golongan) | dipakai apa adanya (boleh override) |
| `sukarela` | editable | bebas | `minValue = settings.savings_sukarela_min` (rule form) |

**Gating Hari Raya — berbasis rentang tanggal (revisi pengurus 2026-06-18):** Hari Raya dikumpulkan sepanjang **satu rentang pengumpulan** (`start_date` … `end_date`), bukan sekadar "tahun". Maka registrasi `MemberHolidaySaving` (4a) menyimpan `start_date`/`end_date`; `period_year` **tetap ada tapi auto-derive dari `end_date`** (= tahun pembagian) — user tak input, dipakai internal sebagai kunci pengelompokan saldo (D1) + keunikan `unique(member, period_year)`. **Balance core (1a) & kolom `period_year` withdrawal (item 0) tidak berubah** — yang berubah hanya cara `period_year` ditentukan (dari `end_date` registrasi, bukan input manual).

Opsi jenis `hari_raya` **hanya muncul** bila ada registrasi **aktif** yang **rentangnya memuat `deposit_date`** (`savingsTypeOptions($memberId, $depositDate)`). Tanggal setor di luar semua rentang aktif → ditolak di rule `deposit_date`. Nominal auto-lock dari `monthly_amount` registrasi itu. **`period_month` untuk `hari_raya` di-tag otomatis ke tahun program** (`end_date.year`, lewat `enforceAmountRules`) — bukan bulan setor literal — agar saldo D1 tetap di-scope per `period_year` secara konsisten; rentang yang melintasi tahun pun aman (program diidentifikasi oleh tahun pembagian). **CRUD Hari Raya (4a) tetap prasyarat** jalur setoran Hari Raya.

**Prinsip keamanan:** field `disabled` di client **tak dipercaya** — `SavingsDepositResource::enforceAmountRules()` (dipanggil `mutateFormDataBeforeCreate`) menimpa nominal locked dengan nilai otoritatif di server; validasi min sukarela & registrasi hari_raya ditegakkan sebagai **rule form** agar error menempel ke field yang benar.

#### D9 — Migrasi aditif `shopping_transactions` (temuan critic #3)

`shopping_transactions` **tidak punya** `idempotency_key` (terkonfirmasi kode) → D6 (anti double-submit pemakaian belanja) **mustahil** tanpa ini. Migrasi aditif: tambah `idempotency_key` (uuid, unique, nullable untuk baris existing) + `transaction_number` `BLJ-` (D2). **`savings_type` TIDAK diperlukan** — seluruh baris `shopping_transactions` per definisi adalah pemakaian Wajib Belanja (saldo belanja diidentifikasi dari tabelnya, bukan kolom type). (Koreksi v5: v4 keliru menyebut `savings_type` sebagai blocker.) Tidak ubah kolom existing. **Konsekuensi: deploy-reviewer TIDAK di-skip** (migrasi pada tabel finansial) — wajib sebelum eksekusi.

### Alternatives Considered

| Alternative | Pro | Con | Verdict |
|-------------|-----|-----|---------|
| Saldo computed-on-read (D1) | Single source of truth; auditable; tak drift | Agregasi tiap baca | **Chosen** |
| Kolom `balance` mutable | Baca cepat | Drift; langgar §7; rawan konkuren | Rejected |
| Reversal amount positif + `CASE` net (D1) | Slip/laporan `SUM(amount)` tetap benar; hindari sign-bug | Perlu scope net konsisten | **Chosen** |
| Reversal amount negatif | Net = `SUM` polos | Rusak slip/laporan; unsigned-unfriendly | Rejected |
| Reversal = Action class (D3) | Logic terpusat & ter-unit-test | Perlu interface marker | **Chosen** |
| Reversal = trait di model | "OO" | Logic tersebar 3 model; susah test terpusat | Rejected |
| Batch chunked `create()` (D5) | Audit per-anggota utuh; konvensi terpenuhi | Lebih lambat dari insert | **Chosen** |
| Batch bulk `insert()` | Cepat | Bypass LogsActivity → audit per-anggota hilang (langgar security #E) | Rejected |
| Fondasi dulu, CRUD belakangan | Kebenaran keuangan terkunci & ter-test | Item pertama tak ada UI terlihat | **Chosen** |

---

## Key Items

| # | Item | Effort | Parallel? | Status |
|---|------|--------|-----------|--------|
| 0 | **Migrasi aditif** (deposits & withdrawals **sudah** punya `idempotency_key`+nomor → tak disentuh): `unique(reversal_of_id)` ×3 tabel; `idempotency_key`+`transaction_number` **hanya** di `shopping_transactions` (D3, D9); `status`/`approved_by`/`approved_at`/`disbursed_at`/**`period_year`** di `savings_withdrawals` (D10, D1 Hari Raya) | S | — | **Done** |
| 1a | `SavingsBalanceService` — saldo net-of-reversal per jenis + saldo belanja dua-sisi + `canWithdraw`/`canSpend`; scope `signed_amount` di 3 model (D1) | M | setelah 0 | **Done** |
| 1b | `ReverseTransaction` Action + interface `Reversible`; guard single-reversal (unique), no-reverse-of-reverse, anggota non-aktif (D3) | M | setelah 0 | **Done** |
| 1c | Generator nomor `STR-`/`TRK-`/`BLJ-` race-safe (model hook, backstop unique) (D2) | S | setelah 0 | **Done** |
| 1d | **Unit test fondasi** — 5 skenario saldo, reversal net-nol, no-reverse-of-reverse, **scoping `period_year` Hari Raya (dua sisi konsisten)**, idempotency level service; **test konkurensi di MySQL** | M | setelah 1a,1b,1c | **Done** (konkurensi MySQL: lihat catatan) |
| 2a | `SavingsDepositResource` — setoran tunggal + idempotency Hidden-uuid (D4) + Policy (incl. `reverse()`) | M | setelah 1d | **Done** |
| 2b | Aksi **Reversal** di Resource setoran (~~gate Pengurus+~~ → **gate `reverse` Petugas+, per D7/D8-D**) | S | setelah 2a,1b | **Done** |
| 2c | **Slip setoran PDF** (DomPDF) — *defer-able ke Minggu 4 bila waktu sempit* | M | setelah 2a | **Done** |
| 3a-1 | Batch per OPD **UI** — Page select OPD + tabel anggota aktif + prefill nominal (D5) | M | setelah 1d | Pending |
| 3a-2 | Batch per OPD **engine** — chunked create, reservasi nomor, key deterministik + pre-commit dup-check + lock per batch + log per-event (D5) | M | setelah 3a-1 | Pending |
| 3a-3 | Shield permission **custom Page** batch + assign seeder + test reject Petugas (D7) | S | setelah 3a-1 | Pending |
| 3b | Rekap batch per OPD (cetak/export, gate Pengurus+, **export ter-log**) — *defer-able ke Minggu 4* | M | setelah 3a-2 | Pending |
| 4a | `MemberHolidaySavingResource` — config Hari Raya per anggota/tahun; **`LogsActivity` ditambah ke model `MemberHolidaySaving`** (sebelumnya belum ada → langgar konvensi audit) | S | setelah 1d | **Done** |
| 4b-1 | **Engine pencairan** — state machine `draft→acc→cair/ditolak` (transisi valid + terminal), **disburse serialize lock per (member,jenis)** anti-overdraw (D10), validasi saldo acc+cair, whitelist jenis (D8), `period_year` write-path | M | setelah 1d | Pending |
| 4b-2 | `SavingsWithdrawalResource` **UI** — form+table+infolist, aksi ACC/Cair/Tolak per-record (`visible`+guard+Policy), saldo Hari Raya per-tahun tampil, reversal (reverse-cair gate Pengurus+) | M | setelah 4b-1 | Pending |
| 5a | `ShoppingTransactionResource` — pemakaian belanja manual, idempotency (D9), validasi ≤ saldo (D6), `recorded_by` dipaksa, Policy, reversal | M | setelah 0,1d | Pending |
| 6 | RBAC final: `shield:generate` semua Resource + assign `RolePermissionSeeder` per D7; audit matriks | S | setelah 2a,3a,4a,4b,5a | Pending |

**Effort:** S < 1 jam, M 1-3 jam, L > 3 jam, — non-code.

> **⚠️ Catatan 1d — gap konkurensi MySQL (jujur):** 22 test fondasi (saldo, reversal net-nol, no-reverse-of-reverse, single-reversal guard, `period_year` dua-sisi, idempotency level-service) **GREEN di SQLite**. Yang **terbukti engine-agnostic**: single-reversal guard `unique(reversal_of_id)` (unique constraint berlaku di kedua engine — backstop sejati). Yang **BELUM diuji konkuren**: race generator nomor `STR-`/`TRK-`/`BLJ-` (1c) — `lockForUpdate` no-op di SQLite, sehingga klaim "race-safe" hanya bersandar pada `unique(transaction_number)` backstop, bukan lock. Test konkurensi paralel sungguhan (2 proses) terhadap MySQL **belum dijalankan**; serialize-lock anti-overdraw disburse adalah item **4b-1** (belum dikerjakan). Turunkan klaim 1c jadi "best-effort lock + unique backstop" sampai harness MySQL paralel berdiri.

> **Dependency:** Item **0 (migrasi) & 1 (fondasi) gerbang segalanya**; 1d harus hijau (termasuk **konkurensi di MySQL**) sebelum Resource. Policy dibuat per-Resource (bukan ditunda ke 6).
>
> **⚠️ Invariant finansial terdistribusi (koreksi v5):** premis "kebenaran keuangan ter-unit-test di 1d sebelum CRUD" tak sepenuhnya tercapai — sebagian guard hidup di layer atas: **idempotency UI** (2a/3a-2), **disburse serialize-lock anti-overdraw** (4b-1), **state machine + batch dup-check** (4b-1/3a-2). Tiap-tiap **wajib punya test di item-nya sendiri** (bukan ditunda), dan **invariant konkurensi wajib MySQL**. 1d menutup invariant level-service; item Resource menutup invariant level-write. Tak boleh ada guard finansial tanpa rumah test. **Jalur pemangkasan timeline (temuan critic #5):** bila 5 hari mepet, tunda **2c, 3b, 4a** (presentasi murni, tak sentuh kebenaran saldo) ke Minggu 4 — pertahankan 0/1/2a/2b/3a/4b/5a/6 (core korektnes + keamanan).

---

## Key Files

| File | Fungsi |
|------|--------|
| `database/migrations/2026_06_17_000001_add_reversal_unique_to_savings_deposits.php` | ✅ item 0 — unique(reversal_of_id) (D3) |
| `database/migrations/2026_06_17_000002_add_status_and_reversal_unique_to_savings_withdrawals.php` | ✅ item 0 — status workflow + period_year + unique (D10, D1, D3) |
| `database/migrations/2026_06_17_000003_add_idempotency_and_reversal_unique_to_shopping_transactions.php` | ✅ item 0 — idempotency_key + nomor + unique (D9, D3) |
| `tests/Feature/SavingsSchemaMigrationTest.php` | ✅ item 0 — schema + guard single-reversal |
| `app/Services/SavingsBalanceService.php` | ✅ 1a — saldo net per jenis (D1) |
| `app/Actions/ReverseTransaction.php` + `app/Contracts/Reversible.php` | ✅ 1b — reversal generik (D3) |
| `app/Exceptions/UnsupportedSavingsType.php`, `CannotReverseTransaction.php` | ✅ 1a/1b — domain exceptions |
| `app/Models/Concerns/HasSignedAmount.php` (scope D1), `GeneratesTransactionNumber.php` (1c, D2) | ✅ Baru — trait dipakai 3 model |
| `app/Models/SavingsDeposit.php`, `SavingsWithdrawal.php`, `ShoppingTransaction.php` | ✅ 1a-c — pakai trait + `HasFactory`, implement `Reversible`, `reverseClone()` |
| `database/factories/Savings*Factory.php`, `ShoppingTransactionFactory.php` | ✅ Baru — factory transaksi (dipakai 1d + Resource tests) |
| `tests/Feature/{TransactionNumberGenerator,SavingsBalanceService,ReverseTransaction}Test.php` | ✅ 1d — 22 test |
| `app/Models/MemberHolidaySaving.php` | ✅ Ada — config Hari Raya |
| `app/Filament/Resources/SavingsDepositResource.php` + `Pages/{List,Create,View}SavingsDeposit.php` | ✅ 2a/2b — setoran tunggal + idempotency Hidden-uuid compare-or-warn (D4); **nominal settings-aware per jenis + gating Hari Raya (D11)**; view-only (immutable); aksi Reversal table + view-header gated `reverse` (2b) |
| `app/Policies/SavingsDepositPolicy.php` | ✅ 2a — CRUD + immutability (update/delete=false) + ability custom `reverse()` (D7) |
| `config/filament-shield.php` | ✅ 2a — generator `option` → `permissions` (policy di-maintain manual; ability custom tak ditimpa re-seed) |
| `app/Filament/Resources/MemberHolidaySavingResource.php` + `Pages/*` + `app/Policies/MemberHolidaySavingPolicy.php` | ✅ 4a — CRUD registrasi Hari Raya per anggota/tahun (D11 prasyarat) |
| `database/migrations/2026_06_18_000001_add_collection_range_to_member_holiday_savings.php` | ✅ v7 — `start_date`/`end_date` rentang pengumpulan (D11; `period_year` auto-derive `end_date`) |
| `app/Models/MemberHolidaySaving.php` + `database/factories/MemberHolidaySavingFactory.php` | ✅ 4a/v7 — `LogsActivity` + `HasFactory`; factory `range()`/`year()` rentang tanggal |
| `app/Filament/Pages/BatchSalaryDeduction.php` | **Baru** (3a, D5) |
| `app/Filament/Resources/SavingsWithdrawalResource.php` | **Baru** (4b) |
| `app/Filament/Resources/MemberHolidaySavingResource.php` | **Baru** (4a) |
| `app/Filament/Resources/ShoppingTransactionResource.php` | **Baru** (5a) |
| `app/Policies/*` | **Baru** — Policy per Resource incl. ability `reverse()` (D7) |
| `resources/views/pdf/savings-slip.blade.php` + `SavingsDepositResource::printSlip()` + aksi `printSlip` (tabel + view header) | ✅ 2c — slip bukti setoran PDF per-record (DomPDF), nama berkas dari `transaction_number` (D2) |
| `database/seeders/RolePermissionSeeder.php` | ✅ Ada — tambah resource simpanan + entri custom Page (D7) |
| `tests/Unit/SavingsBalanceServiceTest.php`, `tests/Feature/ReverseTransactionTest.php` | **Baru** (1d) |

---

## Verification

- [ ] Saldo dihitung benar dari transaksi; setoran menambah, pencairan mengurangi (D1). <!-- source: code -->
- [ ] Reversal `amount` positif → net via `CASE`: asli + reversal = nol; baris asli tak terhapus (D1, D3). <!-- source: code -->
- [ ] Satu transaksi tak bisa di-reversal dua kali (`unique(reversal_of_id)` + QueryException) (D3). <!-- source: code -->
- [ ] **Reversal atas sebuah reversal ditolak** (D3). <!-- source: code -->
- [ ] **Reversal atas anggota non-aktif ditolak** (D3). <!-- source: code -->
- [x] Double-submit form key sama → 1 baris, sukses idempoten; **payload beda dengan key sama → warning, bukan silent** (D4). <!-- source: code --> ✅ 2a (validated 2026-06-17, SavingsDepositResourceTest)
- [ ] Nomor `STR-`/`TRK-`/`BLJ-` unik reset per tahun; **konkurensi diuji di MySQL** (D2). <!-- source: code -->
- [ ] Batch per OPD: anggota aktif + nominal default, **chunked create (audit per-baris ada)**, atomic rollback (D5). <!-- source: code -->
- [ ] **Double-run batch periode sama ditolak** (key deterministik + pre-commit check), bukan hanya peringatan UI (D5, security #B). <!-- source: code -->
- [ ] **Batch ter-log sebagai satu peristiwa** + per-baris (D5). <!-- source: code -->
- [ ] Pencairan **hanya `hari_raya`+`sukarela`** di form/Action; `swp`/`tabungan_berjangka` tak bisa di-insert (D8). <!-- source: code -->
- [ ] **Pencairan `draft` tidak mengurangi saldo; baru berkurang saat `cair`** (D10). <!-- source: code -->
- [ ] **Dua pencairan konkuren member+jenis sama tidak bisa over-draw** (serialize lock, diuji di MySQL) (D10). <!-- source: code -->
- [ ] **Transisi status ilegal ditolak** (mis. `ditolak→draft`, `cair→acc`); `ditolak`/`cair` terminal (D10). <!-- source: code -->
- [ ] **Reversal uniform Petugas+** (semua tabel & status, termasuk pencairan `cair`); aksi tercatat di laporan reversal (D7). <!-- source: code -->
- [ ] Reversal pencairan Hari Raya mengembalikan saldo ke **`period_year` yang benar** (reverseClone copy period_year) (D1, D3). <!-- source: code -->
- [ ] **ACC & Cair pencairan hanya Pengurus+ (permission)**; Petugas hanya bisa draft (D7, D10). <!-- source: code -->
- [ ] Saldo **Hari Raya di-scope per `period_year`**; tahun tanpa deposit = saldo 0 (D1). <!-- source: code -->
- [ ] Pencairan ditolak bila saldo tak cukup (dicek saat acc & cair) (D1, D10). <!-- source: code -->
- [ ] Pemakaian Wajib Belanja ditolak bila > saldo; double-submit ditolak (idempotency D9); `recorded_by` selalu terisi (D6). <!-- source: code -->
- [x] **Nominal `pokok`/`wajib_belanja` terkunci dari `CooperativeSettings`** (input client di-override server) (D11). <!-- source: code --> ✅ 2a (validated 2026-06-17)
- [x] **`sukarela` ≥ `savings_sukarela_min`; `wajib` prefill snapshot golongan & editable** (D11). <!-- source: code --> ✅ 2a (validated 2026-06-17)
- [x] **Setoran `hari_raya` butuh registrasi `MemberHolidaySaving` aktif yang rentangnya (`start_date`…`end_date`) memuat `deposit_date`**; nominal = `monthly_amount`; jenis tak muncul & tanggal di luar rentang ditolak; `period_month` auto-tag ke tahun program (`end_date.year`, melintasi tahun pun konsisten) (D11, 4a). <!-- source: code --> ✅ 2a/4a (validated 2026-06-18, date-range)
- [ ] **Gating berbasis permission Shield** (bukan nama role hardcoded); reversal = Petugas+Pengurus; create setoran = Petugas+; ability bisa dipindah ke role custom (D7). <!-- source: code | RolePermissionMatrixTest -->
- [ ] **Custom Page batch tolak Petugas tanpa permission** (bukan hanya tombol hilang) (D7). <!-- source: code -->
- [ ] **Export/cetak rekap ter-log** (aktor, OPD, periode, jumlah baris) (security #E). <!-- source: code -->
- [ ] Seluruh transaksi & reversal tercatat di `activity_log` dengan causer; log tak bisa dihapus dari panel oleh Pengurus. <!-- source: code -->

---

## Open Questions

- ✅ **(D8-A) Pencairan** → RESOLVED: workflow `draft→acc→cair`, ACC/Cair = Pengurus+ (D10).
- ✅ **(D8-D) Reversal** → RESOLVED: Petugas **dan** Pengurus, gating permission-based (D7); ADR mengikuti Dokumentasi §5.6.
- ✅ **Semantik saldo Hari Raya** → RESOLVED: **per `period_year`** (diputuskan RAT tiap tahun; tahun tertentu bisa tanpa program). Pembagian = withdrawal `hari_raya` tahun itu, reset saldo tahun tsb ke 0 (D1).
- **Anti double-setoran periode sama (jalur tunggal)** — untuk batch sudah ditutup key deterministik + pre-commit (D5). Untuk **input tunggal**: peringatan UI saat periode terisi + idempotency; tanpa DB-unique global (reversal+re-entry butuh baris kedua). Konfirmasi cukup/tidak.
- **Minimal nominal pengambilan** (Dokumentasi §8 #4, belum ditentukan) — default sementara `saldo ≥ amount > 0`.
- **⚠️ Risiko timeline (critic v4):** core non-deferrable ≈ **22 jam coding** + berdiri-kan harness test MySQL (untuk invariant konkurensi) → mepet di 5 hari/1 dev, **buffer nyaris nol** mengingat rework skema (item 0) + wiring Filament per-record (visible+guard+Policy ×3 resource) + custom-Page Shield. Jalur pemangkasan (tunda 2c/3b/4a) hanya hemat ~3 item-M; **bila core overflow, kandidat geser berikutnya = 5a (Wajib Belanja)** karena pemakaian manual bisa menyusul integrasi toko. Putuskan saat sprint planning.
- **Wajib Belanja saat anggota keluar** (Dokumentasi §8 #1) — tak memblokir Minggu 2; catat untuk modul keluar/SHU.

---

## Pipeline trace (v2)

| Stage | Agent | Key output | Date |
|---|---|---|---|
| Framing | strategist | (retroactive — not invoked) urutan fondasi-dulu: saldo+reversal sebelum CRUD | 2026-06-16 |
| Data baseline | data-analyst | skipped: greenfield, belum ada transaksi produksi untuk baseline | 2026-06-16 |
| Design | architect | **invoked** — net via `CASE` (reversal positif), rumus saldo per-type, Action+interface (bukan trait), unique(reversal_of_id), Hidden-uuid idempotency, pecah 3a; un-skip deploy-reviewer | 2026-06-16 |
| Critique | critic | **invoked 2×** — R1 (v2): SQLite lock no-op, shopping tanpa idempotency_key, bulk-insert bunuh audit, form-leak enum, no-reverse-of-reverse. R2 (v3) **REVISE**: `period_year` hilang (Hari Raya uncomputable), TOCTOU over-draw disburse, authority-inversion reverse-of-cair → semua ditutup v4 | 2026-06-16 |
| Security review | security-reviewer | **invoked** — verdict **APPROVE-WITH-CONDITIONS**: pencairan jangan single-actor Petugas, batch double-run lintas-hari, custom-Page tanpa policy, kontradiksi §5.6, export/batch ter-log per-event | 2026-06-16 |
| Deploy review | deploy-reviewer | **pending — WAJIB** (ada migrasi aditif pada tabel finansial, item 0) | 2026-06-16 |
| Implementation | implementer / human | pending | 2026-06-16 |
| Review | reviewer | pending | 2026-06-16 |

**Ronde**: 4 (v1 → critic R1 → v2 → pengurus → v3 → critic R2 → v4 → critic R3 REVISE → v5 Accepted). Critic R3 = koreksi konsistensi (doc-accuracy + 2 gap finansial), bukan defect desain baru besar → diminishing returns.
**Skipped stages**: data-analyst (greenfield, no prod data). deploy-reviewer **TIDAK di-skip** (3 migrasi finansial aditif: D3, D9, D10) — WAJIB sebelum eksekusi.
**Calibration notes**: v1 keliru klaim "tidak ada migrasi / deploy skip" — critic membuktikan `shopping_transactions` butuh migrasi & test jalan di SQLite (klaim race-safe tak teruji). v3 menambah migrasi status pencairan (D10). Dikoreksi.

---

## Changelog

- **2026-06-18 v8 (eksekusi — slip setoran PDF)**: Implementasi item **2c**. `SavingsDepositResource::printSlip()` me-render `pdf.savings-slip` (DomPDF) sebagai bukti setoran per-record; nama berkas `slip-setoran-{transaction_number}.pdf` (D2). Aksi `printSlip` dipasang di ActionGroup tabel + header view page (mengikuti pola `printCard` MemberResource). Label jenis/metode/penyetor di-resolve dari map resource agar konsisten dengan UI; baris reversal diberi penanda eksplisit di slip. 2 test baru (stream PDF + aksi ada di view/tabel). 137 test hijau (SQLite); pint passed. **Tidak berubah:** kebenaran saldo/reversal (slip murni presentasi, read-only).
- **2026-06-18 v7 (eksekusi — gating Hari Raya berbasis rentang tanggal)**: Revisi pengurus → gating setoran Hari Raya pakai **rentang pengumpulan** (`start_date`…`end_date`), bukan sekadar tahun (jauh lebih akurat: setoran hanya sah bila `deposit_date` ada di dalam rentang program aktif). **(1)** Migrasi aditif `start_date`/`end_date` di `member_holiday_savings` (`period_year` tetap, kini **auto-derive dari `end_date`** = tahun pembagian). **(2)** `MemberHolidaySaving` model+factory: kolom rentang, helper `range()`/`year()`. **(3)** `MemberHolidaySavingResource`: DatePicker start/end (`end_date ≥ start_date`, unik per `(member, period_year)`), `period_year` diturunkan via `withDerivedYear()` di Create/Edit. **(4)** `SavingsDepositResource`: `savingsTypeOptions($memberId, $depositDate)` + `activeHolidayRegistration()` gate by-range; rule `deposit_date` tolak di luar rentang; `period_month` auto-tag ke tahun program. **(5)** Tests Hari Raya CRUD + setoran range diperbarui (cross-year derive, end<start ditolak, out-of-range ditolak). **(6)** View Registrasi Hari Raya: tambah `DepositsRelationManager` (read-only) di samping Audit Trail — rekap setoran `hari_raya` anggota untuk tahun program, di-scope **sama persis** dengan `holidayBalance` (`member_id` + `savings_type=hari_raya` + `whereYear(period_month)=period_year`) via relasi `MemberHolidaySaving::deposits()`. **Yang TIDAK berubah:** `SavingsBalanceService` (1a), kolom `period_year` withdrawal (item 0), aturan jenis lain (D11). 129 test hijau (SQLite); pint passed.
- **2026-06-17 v6 (eksekusi)**: Implementasi item **2a, 2b, 4a**. **(1)** `SavingsDepositResource` (setoran tunggal) + idempotency Hidden-uuid compare-or-warn (D4) + Policy immutability + aksi Reversal gated `reverse` (Petugas+ uniform, koreksi label 2b yang stale). **(2)** Generator Shield `option`→`permissions` (policy hand-maintained; ability custom `reverse_savings::deposit` tak ditimpa re-seed). **(3)** Revisi pengurus → **D11 nominal settings-driven**: `pokok`/`wajib_belanja` locked dari `CooperativeSettings`, `sukarela` ≥ `savings_sukarela_min`, `wajib` prefill snapshot golongan (editable), `hari_raya` locked dari registrasi. **(4)** `MemberHolidaySavingResource` (4a) = CRUD registrasi Hari Raya per anggota/tahun (jadi prasyarat jalur setoran Hari Raya); `MemberHolidaySaving` dapat `LogsActivity`. 127 test hijau (SQLite); konkurensi MySQL tetap gap terbuka untuk item 4b-1.
- **2026-06-16 v5**: Critic putaran-3 menyerang v4 → REVISE (koreksi konsistensi akumulatif). **(1)** D9: `shopping_transactions` tak butuh `savings_type` (semua baris = belanja); migrasi cukup `idempotency_key`+nomor. **(2)** Asimetri tahun Hari Raya: `savings_deposits.period_month` nullable → write-path setoran `hari_raya` **wajib `period_month` non-null** agar kedua sisi rumus di-scope tahun konsisten (bukan hanya `period_year` withdrawal). **(3)** `allBalances()`: hari_raya butuh query terpisah `GROUP BY period_year` + `WHERE status='cair'` (klaim "2 query grouped" dikoreksi). **(4)** Scope `signed_amount` hanya tanda reversal, **filter `status='cair'` di service/laporan** (jangan di scope bersama). **(5)** `MemberHolidaySaving` belum punya `LogsActivity` → ditambah di 4a (konvensi audit wajib). **(6)** `syncPermissions` destruktif → assignment UI hilang saat re-seed; konsekuensi dicatat. Plus: invariant finansial terdistribusi diakui eksplisit (tiap punya rumah test, konkurensi MySQL); item 0 perjelas deposits/withdrawals sudah punya idempotency; risiko timeline (~22h core, buffer nol) masuk Open Questions.
- **2026-06-16 v4**: Critic putaran-2 menyerang v3 → REVISE, ditindaklanjuti. **(1)** `savings_withdrawals` tak punya `period_year`/`period_month` (cuma `withdrawal_date`) → saldo Hari Raya per-tahun uncomputable & `withdrawal_date.year` ambigu → tambah kolom aditif `period_year` (item 0), required write-path Hari Raya, ikut `reverseClone()`. **(2)** TOCTOU over-draw di disburse: "validasi 2×" tak punya backstop unique untuk invariant agregat → wajib **serialize lock per (member,jenis)** melintasi cek-saldo→insert, diuji MySQL. **(3)** Authority-inversion: Petugas bisa reverse pencairan `cair` (batalkan keputusan uang-keluar Pengurus) → reverse-of-cair di-gate `disburse`-tier (Pengurus+). Plus: state machine pencairan eksplisit (transisi valid, `ditolak`/`cair` terminal); klaim "reconfigurable tanpa kode" dikoreksi (ability custom = artefak kode, hanya assignment role via UI); Filament per-record enforcement diperjelas (visible+guard+Policy); item 4b dipecah **4b-1 engine + 4b-2 UI**. **Reverse-of-cair → dikonfirmasi pengurus: uniform Petugas+** (tanpa pengecualian), tradeoff dual-control dikontrol detektif via laporan reversal periodik (Minggu 4).
- **2026-06-16 v3**: 3 Open Question ⛔ dijawab pengurus → **Status Accepted**. (A) Pencairan jadi workflow **`draft→acc→cair`** + migrasi aditif `status`/`approved_by`/`approved_at`/`disbursed_at` (D10); saldo berkurang hanya saat `cair`; ACC/Cair = Pengurus+. (D) Reversal = **Petugas dan Pengurus**, dan gating dipindah ke **berbasis permission Shield** (bukan `ELEVATED_ROLES` hardcoded) agar hak akses custom mungkin (D7) — retrofit master data dicatat sebagai follow-up. (Hari Raya) saldo **per `period_year`** (D1, `holidayBalance(member, year)`); 4b naik ke effort L. Tradeoff security reversal-ke-Petugas dicatat (dual-control pindah ke pencairan). Pipeline trace deploy-reviewer tetap WAJIB (migrasi bertambah).
- **2026-06-16 v2**: Dikeraskan via pipeline `architect`+`security-reviewer`+`critic` (betulan). Verdict gabungan REVISE → ditindaklanjuti. Perubahan utama: reversal amount-positif + net `CASE` (D1); rumus saldo per-jenis + `swp`/`tabungan_berjangka` N/A; reversal = Action class + interface (D3); guard single-reversal via `unique(reversal_of_id)` + no-reverse-of-reverse + anggota non-aktif; idempotency Hidden-uuid + **compare-or-warn** (D4); batch **chunked create bukan bulk insert** + key deterministik + pre-commit dup-check + lock per batch + log per-event (D5); **migrasi aditif `idempotency_key`/nomor di `shopping_transactions`** (D9); pencairan **Pengurus+** & whitelist `hari_raya`+`sukarela` write-path (D7/D8); custom-Page batch butuh permission+test; Policy per-Resource incl. `reverse()`; **deploy-reviewer un-skipped**; catatan **test konkurensi wajib MySQL** (SQLite lock no-op). Item dipecah jadi 17 (0 migrasi, 3a-1/3a-2/3a-3). Status tetap **Draft** — 3 Open Question ⛔ butuh konfirmasi pengurus sebelum Accepted.
- **2026-06-16 v1**: Initial draft — modul Simpanan Minggu 2, fondasi keuangan reusable sebelum CRUD.
