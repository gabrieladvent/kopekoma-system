#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Installer untuk Kopekoma System (Laravel 12 + Filament 3).

.DESCRIPTION
    Memasang project dari nol: cek prasyarat, dependency PHP (composer),
    file .env, app key, database, migrasi + seeder, dan build aset front-end.

.PARAMETER NoSeed
    Lewati menjalankan seeder.

.PARAMETER Fresh
    Jalankan migrate:fresh (HAPUS semua data) lalu seed.

.PARAMETER NoBuild
    Lewati npm install & build aset.

.EXAMPLE
    .\install.ps1
    .\install.ps1 -NoSeed
    .\install.ps1 -Fresh
    .\install.ps1 -NoBuild
#>
[CmdletBinding()]
param(
    [switch]$NoSeed,
    [switch]$Fresh,
    [switch]$NoBuild
)

$ErrorActionPreference = 'Stop'

# --- Helper output -----------------------------------------------------------
function Write-Info { param($m) Write-Host "▶ $m" -ForegroundColor Blue }
function Write-Ok   { param($m) Write-Host "✓ $m" -ForegroundColor Green }
function Write-Warn { param($m) Write-Host "⚠ $m" -ForegroundColor Yellow }
function Write-Err  { param($m) Write-Host "✗ $m" -ForegroundColor Red }
function Write-Step { param($m) Write-Host "`n━━━ $m ━━━" -ForegroundColor Blue }

# Pindah ke direktori script (root project)
Set-Location -Path $PSScriptRoot

# --- Cek prasyarat -----------------------------------------------------------
Write-Step "Memeriksa prasyarat"

function Test-Command {
    param([string]$Name)
    $cmd = Get-Command $Name -ErrorAction SilentlyContinue
    if (-not $cmd) {
        Write-Err "$Name tidak ditemukan. Mohon install terlebih dahulu."
        return $false
    }
    Write-Ok "$Name tersedia"
    return $true
}

$missing = $false
if (-not (Test-Command 'php'))      { $missing = $true }
if (-not (Test-Command 'composer')) { $missing = $true }
if (-not $NoBuild) {
    if (-not (Test-Command 'node')) { $missing = $true }
    if (-not (Test-Command 'npm'))  { $missing = $true }
}
if ($missing) {
    Write-Err "Prasyarat belum lengkap. Instalasi dibatalkan."
    exit 1
}

# Cek versi PHP minimal 8.2
$phpOk = (php -r 'echo version_compare(PHP_VERSION, "8.2.0", ">=") ? "1" : "0";')
if ($phpOk -ne '1') {
    $phpVer = (php -r 'echo PHP_VERSION;')
    Write-Err "Butuh PHP >= 8.2 (terpasang: $phpVer)"
    exit 1
}

# --- Composer ----------------------------------------------------------------
Write-Step "Menginstall dependency PHP (composer)"
composer install --no-interaction --prefer-dist
if ($LASTEXITCODE -ne 0) { Write-Err "composer install gagal"; exit 1 }
Write-Ok "Dependency PHP terpasang"

# --- File .env ---------------------------------------------------------------
Write-Step "Menyiapkan file .env"
if (Test-Path '.env') {
    Write-Ok ".env sudah ada — dilewati"
} else {
    Copy-Item '.env.example' '.env'
    Write-Ok ".env dibuat dari .env.example"
}

# --- App key -----------------------------------------------------------------
Write-Step "Membuat application key"
if (Select-String -Path '.env' -Pattern '^APP_KEY=base64:' -Quiet) {
    Write-Ok "APP_KEY sudah ada — dilewati"
} else {
    php artisan key:generate --ansi
    Write-Ok "APP_KEY dibuat"
}

# --- Database SQLite ----------------------------------------------------------
$dbLine = Select-String -Path '.env' -Pattern '^DB_CONNECTION=' | Select-Object -First 1
$dbConn = if ($dbLine) { ($dbLine.Line -split '=', 2)[1].Trim().Trim('"').Trim("'") } else { '' }

if ($dbConn -eq 'sqlite') {
    Write-Step "Menyiapkan database SQLite"
    $sqlitePath = Join-Path 'database' 'database.sqlite'
    if (-not (Test-Path $sqlitePath)) {
        New-Item -ItemType File -Path $sqlitePath -Force | Out-Null
        Write-Ok "database/database.sqlite dibuat"
    } else {
        Write-Ok "database/database.sqlite sudah ada"
    }
} else {
    Write-Warn "DB_CONNECTION='$dbConn' — pastikan kredensial database di .env sudah benar sebelum migrate."
}

# --- Migrasi & seed ----------------------------------------------------------
# Skrip ini untuk bootstrap LOKAL, bukan deploy server (gunakan deploy.sh).
# migrate:fresh + --force menghapus seluruh data tanpa prompt — di direktori
# produksi itu berarti catatan simpanan & pinjaman anggota lenyap.
if ((Test-Path '.env') -and (Select-String -Path '.env' -Pattern '^APP_ENV=(production|prod)' -Quiet)) {
    Write-Err "DITOLAK: .env menunjukkan APP_ENV=production."
    Write-Err "install.ps1 tidak boleh dijalankan di produksi — gunakan deploy.sh."
    exit 1
}

if ($Fresh) {
    # Konfirmasi ketik-ulang: -Fresh menghapus SEMUA tabel beserta isinya.
    Write-Warn "-Fresh akan MENGHAPUS SELURUH DATA di database."
    $confirmFresh = Read-Host "Ketik 'HAPUS SEMUA DATA' untuk melanjutkan"
    if ($confirmFresh -ne 'HAPUS SEMUA DATA') {
        Write-Err "Dibatalkan."
        exit 1
    }
}

Write-Step "Menjalankan migrasi database"
$migrateCmd = if ($Fresh) { 'migrate:fresh' } else { 'migrate' }

if (-not $NoSeed) {
    php artisan $migrateCmd --seed --force --ansi
    if ($LASTEXITCODE -ne 0) { Write-Err "Migrasi gagal"; exit 1 }
    Write-Ok "Migrasi + seeder selesai"
} else {
    php artisan $migrateCmd --force --ansi
    if ($LASTEXITCODE -ne 0) { Write-Err "Migrasi gagal"; exit 1 }
    Write-Ok "Migrasi selesai (tanpa seeder)"
}

# --- Storage link ------------------------------------------------------------
Write-Step "Membuat symbolic link storage"
php artisan storage:link --ansi
if ($LASTEXITCODE -ne 0) { Write-Warn "storage:link gagal/sudah ada — dilanjutkan" }

# --- Optimasi Filament -------------------------------------------------------
Write-Step "Optimasi Filament & cache"
php artisan filament:upgrade --ansi
php artisan optimize:clear --ansi
Write-Ok "Cache dibersihkan"

# --- Front-end ---------------------------------------------------------------
if (-not $NoBuild) {
    Write-Step "Menginstall dependency front-end (npm)"
    $env:HUSKY = '0'
    if (Test-Path 'package-lock.json') {
        npm ci
    } else {
        npm install
    }
    if ($LASTEXITCODE -ne 0) { Write-Err "npm install gagal"; exit 1 }
    Write-Ok "Dependency front-end terpasang"

    Write-Step "Build aset (vite)"
    npm run build
    if ($LASTEXITCODE -ne 0) { Write-Err "npm run build gagal"; exit 1 }
    Write-Ok "Aset ter-build"
} else {
    Write-Warn "Build front-end dilewati (-NoBuild)"
}

# --- Selesai -----------------------------------------------------------------
Write-Step "Instalasi selesai 🎉"
Write-Host ""
Write-Host "Project Kopekoma System siap digunakan." -ForegroundColor Green
Write-Host ""
Write-Host "Jalankan server pengembangan:"
Write-Host "  composer dev        # server + queue + logs + vite (sekaligus)" -ForegroundColor Yellow
Write-Host "  php artisan serve   # hanya web server" -ForegroundColor Yellow
Write-Host ""
Write-Host "Panel admin Filament: http://localhost:8000/admin" -ForegroundColor Blue
