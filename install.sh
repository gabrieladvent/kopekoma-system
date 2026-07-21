#!/usr/bin/env bash
#
# install.sh — Installer untuk Kopekoma System (Laravel 12 + Filament 3)
#
# Penggunaan:
#   ./install.sh              # install penuh (composer, env, db, migrate+seed, npm, build)
#   ./install.sh --no-seed    # tanpa menjalankan seeder
#   ./install.sh --fresh      # migrate:fresh (HAPUS semua data) lalu seed
#   ./install.sh --no-build   # lewati npm install & build aset
#   ./install.sh --help       # tampilkan bantuan
#
set -euo pipefail

# --- Warna output ------------------------------------------------------------
if [ -t 1 ]; then
  RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; BLUE=$'\033[0;34m'; NC=$'\033[0m'
else
  RED=''; GREEN=''; YELLOW=''; BLUE=''; NC=''
fi

info()  { printf "%s\n" "${BLUE}▶ $*${NC}"; }
ok()    { printf "%s\n" "${GREEN}✓ $*${NC}"; }
warn()  { printf "%s\n" "${YELLOW}⚠ $*${NC}"; }
err()   { printf "%s\n" "${RED}✗ $*${NC}" >&2; }
step()  { printf "\n%s\n" "${BLUE}━━━ $* ━━━${NC}"; }

# --- Opsi --------------------------------------------------------------------
DO_SEED=true
DO_BUILD=true
FRESH=false

for arg in "$@"; do
  case "$arg" in
    --no-seed)  DO_SEED=false ;;
    --no-build) DO_BUILD=false ;;
    --fresh)    FRESH=true ;;
    --help|-h)
      sed -n '3,10p' "$0" | sed 's/^# \{0,1\}//'
      exit 0 ;;
    *) err "Opsi tidak dikenal: $arg (gunakan --help)"; exit 1 ;;
  esac
done

# Jalankan dari direktori script (root project)
cd "$(dirname "$0")"

# --- Cek prasyarat -----------------------------------------------------------
step "Memeriksa prasyarat"

need() {
  if ! command -v "$1" >/dev/null 2>&1; then
    err "$1 tidak ditemukan. Mohon install terlebih dahulu."
    return 1
  fi
  ok "$1 tersedia ($("$1" $2 2>/dev/null | head -n1))"
}

MISSING=0
need php "--version"      || MISSING=1
need composer "--version" || MISSING=1
if [ "$DO_BUILD" = true ]; then
  need node "--version" || MISSING=1
  need npm  "--version" || MISSING=1
fi
[ "$MISSING" -eq 1 ] && { err "Prasyarat belum lengkap. Instalasi dibatalkan."; exit 1; }

# Cek versi PHP minimal 8.2
PHP_OK=$(php -r 'echo version_compare(PHP_VERSION, "8.2.0", ">=") ? "1" : "0";')
[ "$PHP_OK" != "1" ] && { err "Butuh PHP >= 8.2 (terpasang: $(php -r 'echo PHP_VERSION;'))"; exit 1; }

# --- Composer ----------------------------------------------------------------
step "Menginstall dependency PHP (composer)"
composer install --no-interaction --prefer-dist
ok "Dependency PHP terpasang"

# --- File .env ---------------------------------------------------------------
step "Menyiapkan file .env"
if [ -f .env ]; then
  ok ".env sudah ada — dilewati"
else
  cp .env.example .env
  ok ".env dibuat dari .env.example"
fi

# --- App key -----------------------------------------------------------------
step "Membuat application key"
if grep -qE '^APP_KEY=base64:' .env; then
  ok "APP_KEY sudah ada — dilewati"
else
  php artisan key:generate --ansi
  ok "APP_KEY dibuat"
fi

# --- Database SQLite ----------------------------------------------------------
DB_CONN=$(grep -E '^DB_CONNECTION=' .env | head -n1 | cut -d'=' -f2 | tr -d '"'"'"' \r')
if [ "$DB_CONN" = "sqlite" ]; then
  step "Menyiapkan database SQLite"
  if [ ! -f database/database.sqlite ]; then
    touch database/database.sqlite
    ok "database/database.sqlite dibuat"
  else
    ok "database/database.sqlite sudah ada"
  fi
else
  warn "DB_CONNECTION='$DB_CONN' — pastikan kredensial database di .env sudah benar sebelum migrate."
fi

# --- Migrasi & seed ----------------------------------------------------------
# Skrip ini untuk bootstrap LOKAL, bukan deploy server (gunakan deploy.sh).
# Ia bisa menjalankan migrate:fresh (menghapus seluruh data) dan db:seed dengan
# --force yang menekan konfirmasi Laravel — di direktori produksi itu berarti
# catatan simpanan & pinjaman anggota lenyap tanpa satu pun prompt.
if grep -qE '^APP_ENV=(production|prod)' .env 2>/dev/null; then
  echo
  err "DITOLAK: .env menunjukkan APP_ENV=production."
  err "install.sh tidak boleh dijalankan di produksi — gunakan deploy.sh."
  exit 1
fi

step "Menjalankan migrasi database"
MIGRATE_CMD="migrate"

if [ "$FRESH" = true ]; then
  # Konfirmasi ketik-ulang: --fresh menghapus SEMUA tabel beserta isinya.
  echo
  warn "--fresh akan MENGHAPUS SELURUH DATA di database '$(grep -E '^DB_DATABASE=' .env | cut -d= -f2-)'."
  printf "Ketik 'HAPUS SEMUA DATA' untuk melanjutkan: "
  read -r CONFIRM_FRESH
  if [ "$CONFIRM_FRESH" != "HAPUS SEMUA DATA" ]; then
    err "Dibatalkan."
    exit 1
  fi
  MIGRATE_CMD="migrate:fresh"
fi

if [ "$DO_SEED" = true ]; then
  php artisan "$MIGRATE_CMD" --seed --force --ansi
  ok "Migrasi + seeder selesai"
else
  php artisan "$MIGRATE_CMD" --force --ansi
  ok "Migrasi selesai (tanpa seeder)"
fi

# --- Storage link ------------------------------------------------------------
step "Membuat symbolic link storage"
php artisan storage:link --ansi || warn "storage:link gagal/sudah ada — dilanjutkan"

# --- Optimasi Filament -------------------------------------------------------
step "Optimasi Filament & cache"
php artisan filament:upgrade --ansi || true
php artisan optimize:clear --ansi || true
ok "Cache dibersihkan"

# --- Front-end ---------------------------------------------------------------
if [ "$DO_BUILD" = true ]; then
  step "Menginstall dependency front-end (npm)"
  if [ -f package-lock.json ]; then
    HUSKY=0 npm ci
  else
    HUSKY=0 npm install
  fi
  ok "Dependency front-end terpasang"

  step "Build aset (vite)"
  npm run build
  ok "Aset ter-build"
else
  warn "Build front-end dilewati (--no-build)"
fi

# --- Selesai -----------------------------------------------------------------
step "Instalasi selesai 🎉"
cat <<EOF

${GREEN}Project Kopekoma System siap digunakan.${NC}

Jalankan server pengembangan:
  ${YELLOW}composer dev${NC}        # server + queue + logs + vite (sekaligus)
  ${YELLOW}php artisan serve${NC}   # hanya web server

Panel admin Filament: ${BLUE}http://localhost:8000/admin${NC}
EOF
