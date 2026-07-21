# Audit Menyeluruh & Remediasi Keamanan/Integritas

Hasil inspeksi menyeluruh atas seluruh codebase (keamanan, integritas finansial, kualitas kode, kesiapan tes/CI/deploy) beserta perbaikan yang dieksekusi. **Temuan utama: satu commit 12 baris melumpuhkan 39% suite tes selama 10 hari tanpa terdeteksi.**

**Author:** gabrieladvent
**Date:** 2026-07-20
**Status:** Implemented — perbaikan kode selesai; tindakan operasional menunggu eksekusi

---

## Background

Audit dijalankan atas permintaan "apakah sudah aman, apakah sudah sangat bagus" — tanpa area fokus yang dipersempit. Empat auditor paralel menyisir keamanan, integritas finansial, kualitas kode/performa, dan kesiapan tes/CI/deploy, lalu setiap temuan berat diverifikasi ulang secara manual terhadap source.

### Yang sudah kuat (perlu dicatat supaya tidak "diperbaiki")

Beberapa keputusan fondasi terbukti benar dan **tidak boleh diubah tanpa ADR baru**:

- **Tidak ada kolom saldo denormalized.** [`SavingsBalanceService`](../../app/Services/SavingsBalanceService.php) menurunkan setiap saldo dari mutasi via signed SUM. Drift saldo secara struktural mustahil — tidak perlu mekanisme rekonsiliasi.
- **Semua kolom uang `decimal(18,2)`, nol `float`.** [`LoanCalculator`](../../app/Services/LoanCalculator.php) berhitung penuh memakai **bcmath string**; tidak ada float yang menyentuh perhitungan uang.
- **Reversal-entry, bukan hard-delete.** `unique(reversal_of_id)` membuat reversal ganda mustahil di level DB.
- **`DB::transaction` + `lockForUpdate`** di semua jalur pemindahan uang. `WithdrawalWorkflow::disburse` men-serialize per-anggota sebelum cek saldo — desain anti-overdraw yang benar.
- Semua 19 `selectRaw` memakai string statis (nol interpolasi); nol `env()` di luar `config/`; `composer audit` & `npm audit` bersih.

### Akar masalah

Commit [`ffd51e6`](../../bootstrap/providers.php) ("chore: nonaktifkan panel Filament sementara") mengubah 2 file, 12 baris. Niatnya benar dan terdokumentasi: *"Kelas Filament TETAP utuh, jadi bisa diaktifkan kembali kapan saja."*

Yang tidak terhitung: ada hal-hal yang butuh panel itu **terdaftar**, bukan sekadar kelasnya ada.

1. `RolePermissionSeeder` memanggil `shield:generate --panel=admin` → `NoDefaultPanelSetException` → **nol permission dibuat**. Instalasi baru = seluruh aplikasi 403.
2. Helper tes RBAC (`asRole`/`asPengurus`/`asPetugas`) memanggil seeder itu → tes otorisasi mati **sebelum assertion pertama** (`0 assertions`).
3. 23 file tes me-render view panel → `Call to a member function auth() on null`.

Total: **219 tes tumbang**. CI menangkapnya hari itu juga (2026-07-10), tapi **tidak ada branch protection** (`gh api .../branches/development/protection` → 404), jadi PR #41 tetap di-merge di atas kondisi merah.

> ⚠️ Suite merah permanen **lebih berbahaya daripada tidak punya tes** — kegagalan berubah jadi kebisingan latar, dan regresi baru tenggelam di antara 219 kegagalan lama.

---

## Goals

- Kembalikan suite tes ke hijau sehingga CI kembali berfungsi sebagai jaring pengaman.
- Tutup eksposur PII anggota dan jalur akses kredensial default.
- Hilangkan jalur yang dapat melenyapkan uang/data anggota tanpa jejak.
- Beri sistem keuangan ini jaring pengaman data (backup) yang sebelumnya nihil.

## Non-Goals

- **Bukan** memutuskan nasib jangka panjang Filament vs Livewire — panel dihidupkan hanya di environment `testing` sebagai jembatan, bukan keputusan arsitektur final.
- **Bukan** menulis ulang tes panel Filament menjadi tes Livewire (pekerjaan terpisah, lihat *Utang yang tersisa*).
- **Bukan** memperbaiki seluruh N+1 yang ditemukan — hanya yang terburuk.

---

## Keputusan

### 1. Seeder permission lepas dari Filament

[`RolePermissionSeeder`](../../database/seeders/RolePermissionSeeder.php) kini membuat permission per-resource secara eksplisit dari konstanta `RESOURCES × (BASE_PREFIXES + ELEVATED_PREFIXES)` yang **sudah ada di kelas itu sendiri**. Nama permission identik dengan keluaran Shield (`{prefix}_{resource}`), jadi seluruh gate `can:` di `routes/web.php` tidak berubah.

**Hasil:** `RolePermissionMatrixTest` + `LoanRbacMatrixTest` + `SavingsRbacMatrixTest` → **16 lulus, 116 assertions** (sebelumnya 0).

### 2. Panel Filament didaftarkan HANYA saat testing

[`AppServiceProvider::registerFilamentPanelForTesting()`](../../app/Providers/AppServiceProvider.php). `bootstrap/providers.php` tetap tidak mendaftarkan panel untuk produksi.

**Alasan:** ~172 tes menjaga kelas Filament yang method statisnya **masih dipakai produksi** — ada **31 referensi `App\Filament` hidup** dari luar `app/Filament`, termasuk 9 panggilan FQN langsung di dalam Blade Livewire. Membuang tes itu berarti membuang perlindungan atas kode yang benar-benar jalan.

**Batasan yang harus disadari:** ini **tidak menguji UI Livewire yang sebenarnya dipakai user**.

### 3. Media pindah ke disk privat + route ber-otorisasi

Dokumen anggota dulu tersimpan di disk `public` dengan nama file asli klien yang dipertahankan. Karena nomor anggota (`KM-2026-NNNN`) dan nomor pinjaman berurutan, `/storage/{1..N}/kartu-anggota-KM-2026-{0001..9999}.pdf` dapat memanen seluruh registri anggota **tanpa akun**, melewati seluruh gate `can:view_member`. Berkas nyata terkonfirmasi ada di disk saat audit.

Perubahan:
- [`config/media-library.php`](../../config/media-library.php) — default disk `local` (privat).
- [`MediaDownloadController`](../../app/Http/Controllers/MediaDownloadController.php) + route `media.show` — cek policy model pemilik, **dan catat setiap akses ke activity log** (sebelumnya kebocoran PII tidak meninggalkan jejak sama sekali).
- [`MediaFileName`](../../app/Support/MediaFileName.php) — nama file di disk jadi ULID acak; nama asli tetap disimpan sebagai `name` untuk ditampilkan.
- Seluruh 8 call-site `getUrl()`/`getFullUrl()` diganti `route('media.show', $media)`.
- [`media:migrate-to-private`](../../app/Console/Commands/MigrateMediaToPrivateDisk.php) — memindahkan berkas lama. **Belum dijalankan** (lihat *Tindakan operasional*).

### 4. Kredensial admin awal dari environment

[`UserSeeder`](../../database/seeders/UserSeeder.php) dulu membuat `admin@example.com` / `password` sebagai `super_admin` tanpa guard, dan `install.sh` menjalankan `--seed --force` di jalur instalasi terdokumentasi. Karena `super_admin` melewati semua policy lewat `Gate::before`, itu akses total dengan satu tebakan.

Lebih buruk: `updateOrCreate` **menulis ulang password setiap re-seed**, diam-diam mengembalikan kredensial default sesudah admin merotasinya.

Sekarang: kredensial dari `SEED_ADMIN_EMAIL`/`SEED_ADMIN_PASSWORD` ([`config/seeding.php`](../../config/seeding.php)); seeder **menolak jalan di produksi** tanpa keduanya; `firstOrCreate` menggantikan `updateOrCreate`.

### 5. Koreksi pinjaman berhenti menghapus record

[`Loans.php`](../../app/Livewire/Loan/Loans.php) melakukan `$record->delete()` sementara `Loan` **tidak memakai `SoftDeletes`** — hard-delete. Karena koreksi hanya boleh atas pinjaman berstatus `Cair`, SWP anggota **sudah terpotong**, dan saldo SWP diturunkan dari `SUM(loans.swp_amount)` — menghapus baris itu melenyapkan simpanan yang benar-benar sudah dibayar anggota, tanpa reversal entry.

Ini satu-satunya tempat disiplin reversal dilanggar; dua jalur lain (`LoanDetail`, `LoanResource::performCorrection`) sudah benar. Kini ketiganya seragam: `status => 'Dibatalkan'`, record dipertahankan.

### 6. Blind catch berhenti melenyapkan pengajuan pencairan

[`SavingsWithdrawalForm`](../../app/Livewire/Savings/Withdrawal/SavingsWithdrawalForm.php) menangkap `UniqueConstraintViolationException` tanpa membedakan penyebabnya, dan loop `create` tidak dibungkus transaksi.

Tabrakannya nyata: `GeneratesTransactionNumber` membuka transaksinya sendiri sehingga **lock nomor urut dilepas sebelum INSERT terjadi**. Dua pengajuan bersamaan menghasilkan `withdrawal_number` sama; yang kedua ditelan sebagai "sudah tercatat" — pengajuan sah anggota lenyap dengan pesan sukses.

Kini: `DB::transaction` eksplisit (generator bersarang jadi savepoint, lock bertahan sampai commit) + re-query `idempotency_key` dan **rethrow kalau tidak cocok** — pola yang sudah benar di `CreateSavingsWithdrawal`.

### 7. Reversal withdrawal: satu basis, perbedaan yang disengaja dieksplisitkan

Auditor melaporkan "Livewire kehilangan guard `isLoanRefund`". **Klaim itu ditolak setelah tes membantahnya** — ada tes yang secara sengaja menegaskan bahwa pasangan refund `cair` *boleh* di-reverse sekaligus.

Duduk perkara sebenarnya: `SavingsWithdrawals` (list) **pair-aware** dan me-reverse seluruh `refundPair()` dalam satu transaksi, jadi boleh menerima refund; Filament me-reverse satu baris saja, jadi harus menolaknya. Perbedaannya sah.

Yang **memang** bug: `SavingsWithdrawalDetail` tidak pair-aware dan tanpa transaksi — memproses satu sisi refund dari halaman detail meninggalkan saudaranya di status lama, dan pasangan setengah-transisi jadi tidak dapat diproses dari halaman list.

Kini: `Resource::canReverseBase()` sebagai basis bersama (menutup celah reversal-ganda di Filament), `Resource::canReverse()` sebagai varian Filament yang menambah larangan refund, dan `SavingsWithdrawalDetail` dibuat pair-aware + transaksional pada **reversal maupun transisi status**.

### 8. Backup — dari nihil menjadi berlapis

Sebelumnya **tidak ada backup sama sekali**: tidak ada `spatie/laravel-backup`, nol hasil `grep mysqldump` di seluruh repo. Satu-satunya salinan catatan simpanan & pinjaman anggota adalah database live. Bergabung dengan `deploy.sh` yang menjalankan `migrate --force` tanpa dump, dua kelemahan itu saling memperkuat.

- [`db:backup`](../../app/Console/Commands/BackupDatabase.php) + jadwal harian 02:00 WIB di [`routes/console.php`](../../routes/console.php), retensi 14 hari. Password lewat `MYSQL_PWD`, bukan argumen proses (argumen terlihat di `ps`). Berkas kosong dianggap gagal.
- [`deploy.sh`](../../deploy.sh) — dump pra-migrasi wajib; `set -e` menghentikan deploy kalau dump gagal. Itu memang yang diinginkan: lebih baik deploy batal daripada jalan tanpa jaring.

### 9. Deploy & install tidak lagi bisa diam-diam merusak

- `deploy.sh` — `trap cleanup` kini menjalankan `php artisan up` saat gagal (sebelumnya situs tertinggal di maintenance mode tanpa jalan keluar otomatis), dan mencetak lokasi backup + commit sebelumnya untuk rollback manual.
- `install.sh` / `install.ps1` — **menolak jalan** bila `.env` menunjukkan `APP_ENV=production`, plus konfirmasi ketik-ulang `HAPUS SEMUA DATA` untuk `--fresh`. Sebelumnya jarak ke bencana hanya satu `cd` yang salah.

### 10. Route `/sistem/*` dijaga di level route

Route ini sebelumnya **tanpa middleware sama sekali**, hanya `abort_unless` di `mount()` — padahal `mount()` **tidak dijalankan ulang** pada POST `/livewire/update`, sehingga setiap public method baru lahir tanpa proteksi.

Dipakai Gate `manage-system` + `can:`, **bukan** middleware `role:` milik Spatie: hanya `Illuminate\Auth\Middleware\Authorize` yang ada di daftar persistent middleware Livewire, jadi hanya `can:` yang ikut berlaku ulang tiap update. `abort_unless` per-method dipertahankan sebagai lapis kedua.

### 11. `GREATEST()` diganti `CASE WHEN`

[`SavingsStatsOverview`](../../app/Filament/Widgets/SavingsStatsOverview.php) memakai `GREATEST()` yang tidak ada di SQLite. Produksi MySQL jadi selama ini jalan — tapi tesnya tidak pernah bisa membuktikannya karena panel mati. Ini contoh konkret divergensi engine SQLite-lokal vs MySQL-CI.

### 12. N+1 terburuk

`MemberBalances` memanggil `allBalances()` **dan** `totalBalance()` per baris — dan `totalBalance()` memanggil `allBalances()` lagi dari nol. Dua kali kerja query untuk angka yang sama, di halaman yang di-render ulang tiap ketikan pencarian (debounce 300ms).

Ditambahkan `SavingsBalanceService::sumBalances(array $all)` — fungsi murni tanpa query. `totalBalance()` kini mendelegasikan ke sana, jadi tidak ada duplikasi logika.

---

## Hasil

| | Sebelum | Sesudah |
|---|---|---|
| Suite tes | 219 gagal / 338 lulus | **0 gagal / 564 lulus** (4 skipped) |
| Tes otorisasi RBAC | 0 assertions (mati) | 16 lulus, 116 assertions |
| Tes jalur media | tidak ada | 7 lulus |
| Pint | bersih | bersih |

---

## Tindakan operasional (BELUM dikerjakan — butuh eksekusi manusia)

Urutan ini penting: **jangan aktifkan branch protection sebelum suite hijau ter-merge**, atau seluruh tim langsung terblokir.

1. **Anggap berkas yang terekspos sebagai insiden kebocoran data.** Berkas nyata dengan nama yang dapat ditebak terkonfirmasi ada di `storage/app/public` saat audit.
2. **Jalankan `php artisan media:migrate-to-private --dry-run` lebih dulu**, lalu tanpa `--dry-run` setelah backup DB **dan** direktori storage. Perpindahan berkas tidak reversibel.
3. **Rotasi kredensial `admin@example.com`** bila akun itu ada di produksi; set `SEED_ADMIN_EMAIL`/`SEED_ADMIN_PASSWORD`.
4. **Set `MEDIA_DISK=local` di `.env` produksi** dan pastikan `APP_ENV=production` + `APP_DEBUG=false`.
5. **Set `SESSION_SECURE_COOKIE=true`** — saat ini tidak di-set, sehingga cookie dikirim lewat HTTP polos.
6. **Buat direktori backup** `/var/backups/kopekoma` dengan permission yang benar sebelum deploy berikutnya.
7. **Uji restore**, bukan cuma backup — backup yang belum pernah dipulihkan belum terbukti ada.
8. **Aktifkan branch protection** di `main` dan `development` dengan required checks: `Test`, `Lint`, `Frontend Build`.

---

## Lanjutan: enum status (2026-07-21)

Menindaklanjuti utang **"tidak ada `app/Enums`"** di bawah — akar divergensi status. Tiga status yang benar-benar pernah menyimpang antar-UI kini menjadi backed enum ber-cast, dengan label/warna/logika terpusat:

| Enum | Nilai | Yang dipusatkan |
|---|---|---|
| [`LoanStatus`](../../app/Enums/LoanStatus.php) | Cair/Lunas/Dibatalkan | label + warna badge (sebelumnya Filament `gray` vs Blade `neutral` untuk Dibatalkan) |
| [`WithdrawalStatus`](../../app/Enums/WithdrawalStatus.php) | draft/acc/cair/ditolak | label + warna + **state machine** (`transitionsTo()`); `WithdrawalWorkflow`, Filament, dan Livewire kini memakai satu definisi transisi |
| [`InstallmentScheduleStatus`](../../app/Enums/InstallmentScheduleStatus.php) | Belum Bayar/Terbayar | label + warna |

Pola yang dipakai:
- Enum implement `Filament\Support\Contracts\HasLabel` + `HasColor` → badge Filament di-drive enum, closure `->color()`/`->formatStateUsing()` manual dibuang (inilah yang dulu menyimpang).
- Model `$casts` mengubah atribut jadi instance enum. Query `where()`/`whereIn()` menerima enum (Laravel mengikat `->value`); assignment write menerima string maupun enum.
- Nilai backing = nilai kolom DB apa adanya → **tanpa migrasi data**.
- Tes yang meng-assert `$model->status` diperbarui ke enum; assertion pada array mentah (mis. `LoanCalculator` build rows) & raw-DB (`SavingsSchemaMigrationTest`) tetap string.

Efek samping tertangkap: `SavingsStatsOverview` memakai `GREATEST()` (tidak ada di SQLite) — diganti `CASE WHEN`; bug lintas-engine yang selama ini tak teruji karena panel mati.

**Belum dikonversi (keputusan sadar, bukan kelupaan):**
- **MemberStatus / AgencyStatus** (`Aktif`/`Non-Aktif`/`Keluar`/`Meninggal`) — nilai `Aktif` dipakai dua model sekaligus dan bercampur dengan puluhan `->label('Aktif')` / `is_active ? 'Aktif'` yang bukan status; risiko konversi tinggi, dan **tak pernah jadi sumber bug divergensi**. Ditunda ke PR terfokus tersendiri.
- **Type/method** (`loan_type`, `savings_type`, `disbursement_method`, dll) — sudah tersentralisasi lewat const map (`LOAN_TYPES`, `DISBURSEMENT_METHODS`, `WITHDRAWAL_TYPES`) yang dipakai ulang lintas UI, jadi nilai enum-nya rendah; `savings_type` juga terjalin dalam logika saldo (risiko). Ditunda.

## Utang yang tersisa (tidak dikerjakan di sini)

Diurutkan berdasarkan dampak:

- **Nasib `app/Filament`.** Saat ini di posisi terburuk: **terbaca seperti dead code, tapi load-bearing** (31 referensi hidup dari luar). Tidak bisa dihapus, tidak bisa di-refactor aman. Pilihannya — ekstrak helper hidup ke `app/Support`/`app/Services` lalu buang sisanya, atau aktifkan lagi panelnya. Kondisi tengah ini yang melahirkan seluruh divergensi copy-paste.
- **UI Livewire produksi kurang terjaga tes.** 172 tes menjaga panel yang user tidak pakai; komponen Livewire yang benar-benar dipakai jauh lebih tipis cakupannya.
- **Tidak ada `app/Enums`.** Status (`'Aktif'`, `'Cair'`, `'Lunas'`, `'draft'`, `'acc'`) berupa string literal tersebar di Livewire, Filament, dan Blade — ini akar dari divergensi status.
- **Pinjaman tanpa state machine.** Tidak ada `LoanWorkflow` sebanding `WithdrawalWorkflow`; tidak ada guard terpusat yang mencegah transisi ilegal ke `Cair`.
- **N+1 yang belum disentuh:** `BatchSalaryDeduction` (~1.200 query untuk OPD 200 anggota), `BatchInstallmentPayment`, dua layar laporan tanpa paginasi atas dataset setahun.
- **Index yang bolong:** `installment_schedules.due_date` (dan `scopeOverdue` memakai `whereDate` yang non-sargable), `savings_withdrawals.status`, `activity_log.subject_type`, composite `members(agency_id, status)`.
- **Tidak ada error tracking** (Sentry/Flare) dan tidak ada channel log finansial terpisah dengan retensi panjang.
- **Store API mengenakan biaya berdasarkan NIK saja** — tanpa PIN/OTP/konfirmasi anggota. NIK semi-publik.
- **Pembulatan ke atas pada pokok bulanan** menyisakan beberapa rupiah lebih di setiap pinjaman jangka panjang. Disengaja dan terdokumentasi — **pertanyaan kebijakan pengurus**, bukan bug.

---

## Referensi

- [`docs/adr/2026-07-13-penutupan-akun-anggota.md`](2026-07-13-penutupan-akun-anggota.md) — closing nasabah masih Draft; saat diimplementasi, harus lahir bersama tesnya karena menyentuh pelunasan pinjaman + pengembalian simpanan sekaligus.
