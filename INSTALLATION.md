# Panduan Instalasi — Kopekoma System

Panduan memasang **Kopekoma System** (Laravel 12 + Filament 3) di mesin lokal.

## Prasyarat

Pastikan tools berikut sudah terpasang:

| Tool         | Versi minimal | Catatan                                  |
| ------------ | ------------- | ---------------------------------------- |
| PHP          | 8.2+          | Ekstensi: `pdo`, `mbstring`, `dom`, `fileinfo`, `gd`, `zip`, `bcmath`, `intl` |
| Composer     | 2.x           | Dependency manager PHP                    |
| Node.js      | 20+           | Untuk build aset front-end (Vite)         |
| npm          | 10+           | Terbawa bersama Node.js                   |
| Database     | —             | SQLite (default) atau MySQL 8 / MariaDB   |

## Instalasi cepat (script otomatis)

Tersedia script installer yang menjalankan seluruh langkah secara otomatis: cek prasyarat, install dependency, menyiapkan `.env` & app key, membuat database, migrasi + seeder, lalu build aset.

### Linux / macOS

```bash
./install.sh
```

### Windows (PowerShell)

```powershell
.\install.ps1
```

> Jika muncul error _"running scripts is disabled on this system"_, jalankan sekali:
> ```powershell
> Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
> ```

### Opsi script

| Tujuan                                   | Linux / macOS            | Windows                  |
| ---------------------------------------- | ------------------------ | ------------------------ |
| Install penuh                            | `./install.sh`           | `.\install.ps1`          |
| Tanpa menjalankan seeder                 | `./install.sh --no-seed` | `.\install.ps1 -NoSeed`  |
| Reset database (`migrate:fresh`) + seed  | `./install.sh --fresh`   | `.\install.ps1 -Fresh`   |
| Lewati install & build aset front-end    | `./install.sh --no-build`| `.\install.ps1 -NoBuild` |
| Tampilkan bantuan                        | `./install.sh --help`    | `Get-Help .\install.ps1` |

> ⚠️ `--fresh` / `-Fresh` akan **menghapus seluruh data** pada database. Gunakan hanya saat ingin memulai dari awal.

## Instalasi manual

Jika lebih suka menjalankan langkah satu per satu:

```bash
# 1. Install dependency PHP
composer install

# 2. Salin file environment
cp .env.example .env        # Windows: copy .env.example .env

# 3. Generate application key
php artisan key:generate

# 4. (Hanya jika memakai SQLite) buat file database
touch database/database.sqlite   # Windows: New-Item database/database.sqlite

# 5. Migrasi + seeder
php artisan migrate --seed

# 6. Symbolic link storage
php artisan storage:link

# 7. Install & build aset front-end
npm install
npm run build
```

## Konfigurasi database

Secara default project memakai **SQLite** (tanpa setup tambahan). Untuk memakai MySQL/MariaDB, ubah `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=kopekoma
DB_USERNAME=root
DB_PASSWORD=
```

Pastikan database sudah dibuat sebelum menjalankan migrasi.

## Menjalankan aplikasi

```bash
# Semua sekaligus: web server + queue + log + vite
composer dev

# atau hanya web server
php artisan serve
```

- Aplikasi: <http://localhost:8000>
- Panel admin Filament: <http://localhost:8000/admin>

Akun admin awal dibuat oleh seeder (`database/seeders/UserSeeder.php`) — cek file tersebut untuk kredensial login.

## Menjalankan test

```bash
./vendor/bin/pest
```

## Troubleshooting

| Masalah | Solusi |
| ------- | ------ |
| `Permission denied` saat `./install.sh` | Jalankan `chmod +x install.sh` lalu coba lagi. |
| `running scripts is disabled` (Windows) | `Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass` |
| Error koneksi database saat migrate | Periksa kredensial `DB_*` di `.env` dan pastikan service database aktif. |
| Halaman tampil tanpa style | Pastikan `npm run build` sudah dijalankan dan `php artisan storage:link` sukses. |
| `vendor/bin/pint` / `pest` tidak ada | Jalankan `composer install` terlebih dahulu. |
