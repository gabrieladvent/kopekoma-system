#!/bin/bash
#
# KOPEKOMA System — Development Server Deploy Script
#
# Update branch `development` di server dengan sekali jalan:
#   git pull → composer install → npm build → migrate → optimize → reload.
#
# Penggunaan:
#   sudo ./deploy.sh                    # Normal deploy
#   sudo ./deploy.sh --skip-migration   # Skip migration step
#   sudo ./deploy.sh --skip-build       # Skip npm build (kalau tidak ada perubahan frontend)
#   sudo ./deploy.sh --branch=main      # Override branch (default: development)
#
# Pre-requisite:
#   - Jalankan dengan sudo (akses systemctl untuk reload php-fpm & nginx)
#   - Sesuaikan APP_DIR, APP_USER, PHP_FPM_SERVICE di bawah dengan kondisi server
#   - File ini punya permission +x: chmod +x deploy.sh
#
# Catatan:
#   - Queue/cache/session pakai driver database (lihat .env), jadi tidak ada
#     Reverb/Horizon yang perlu di-restart. queue:restart cukup untuk worker.
#

set -euo pipefail

# ─── Config ──────────────────────────────────────────────────────
APP_DIR="/var/www/kopekoma-system"
APP_USER="www-data"
LOCK_FILE="/tmp/kopekoma-deploy.lock"
LOG_FILE="/var/log/kopekoma-deploy.log"
PHP_FPM_SERVICE="php8.2-fpm"
GIT_BRANCH="development"

# Flags
SKIP_MIGRATION=false
SKIP_BUILD=false

for arg in "$@"; do
    case $arg in
        --skip-migration) SKIP_MIGRATION=true ;;
        --skip-build)     SKIP_BUILD=true ;;
        --branch=*)       GIT_BRANCH="${arg#*=}" ;;
        *) echo "Unknown flag: $arg"; exit 1 ;;
    esac
done

# ─── Helpers ─────────────────────────────────────────────────────
log() {
    local msg="$1"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo -e "[$timestamp] $msg" | tee -a "$LOG_FILE"
}

step()    { log "\n→ $1"; }
success() { log "  ✓ $1"; }

error() {
    log "  ✗ ERROR: $1"
    cleanup
    exit 1
}

cleanup() {
    [ -f "$LOCK_FILE" ] && rm -f "$LOCK_FILE"
}

trap cleanup EXIT INT TERM

# ─── Pre-checks ──────────────────────────────────────────────────
if [ "$EUID" -ne 0 ]; then
    error "Script harus dijalankan dengan sudo"
fi

if [ -f "$LOCK_FILE" ]; then
    PID=$(cat "$LOCK_FILE")
    if ps -p "$PID" > /dev/null 2>&1; then
        error "Deploy sudah berjalan (PID: $PID). Tunggu selesai atau hapus $LOCK_FILE"
    else
        log "  ⚠ Lock file ada tapi proses tidak jalan, hapus lock lama"
        rm -f "$LOCK_FILE"
    fi
fi

echo $$ > "$LOCK_FILE"

if [ ! -d "$APP_DIR" ]; then
    error "Folder app tidak ditemukan: $APP_DIR (sesuaikan APP_DIR di script)"
fi

cd "$APP_DIR"

DEPLOY_START=$(date +%s)
log "═══════════════════════════════════════════════════════════════"
log "  KOPEKOMA System — Deploy"
log "  Time:    $(date '+%Y-%m-%d %H:%M:%S')"
log "  User:    $(whoami)"
log "  Branch:  $GIT_BRANCH"
log "  Skip Migration: $SKIP_MIGRATION"
log "  Skip Build:     $SKIP_BUILD"
log "═══════════════════════════════════════════════════════════════"

# ─── 1. Maintenance mode ─────────────────────────────────────────
step "1/9 Activating maintenance mode"
sudo -u $APP_USER php artisan down --retry=60 --secret="kopekoma-deploy" 2>/dev/null || true
success "Maintenance mode aktif (bypass via /kopekoma-deploy)"

# ─── 2. Pull latest code ─────────────────────────────────────────
step "2/9 Pulling latest code from origin/$GIT_BRANCH"
sudo -u $APP_USER git fetch --all --prune
CURRENT_COMMIT=$(sudo -u $APP_USER git rev-parse HEAD)
sudo -u $APP_USER git checkout "$GIT_BRANCH"
sudo -u $APP_USER git reset --hard "origin/$GIT_BRANCH"
NEW_COMMIT=$(sudo -u $APP_USER git rev-parse HEAD)

if [ "$CURRENT_COMMIT" = "$NEW_COMMIT" ]; then
    log "  ⚠ Tidak ada perubahan code (commit sama: ${NEW_COMMIT:0:8})"
else
    success "Code update: ${CURRENT_COMMIT:0:8} → ${NEW_COMMIT:0:8}"
fi

# Simpan commit sebelumnya untuk rollback manual kalau perlu
echo "$CURRENT_COMMIT" > "$APP_DIR/.last-commit"

# ─── 3. Install PHP dependencies ─────────────────────────────────
step "3/9 Installing PHP dependencies (composer)"
sudo -u $APP_USER composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-progress \
    --prefer-dist 2>&1 | tee -a "$LOG_FILE"
success "Composer install selesai"

# ─── 4. Build frontend assets ────────────────────────────────────
if [ "$SKIP_BUILD" = false ]; then
    step "4/9 Installing & building frontend assets (npm + vite)"
    sudo -u $APP_USER npm ci --silent 2>&1 | tee -a "$LOG_FILE"
    sudo -u $APP_USER npm run build 2>&1 | tee -a "$LOG_FILE"
    success "Frontend assets ter-build"
else
    log "  ⚠ Skip frontend build (--skip-build)"
fi

# ─── 5. Run database migrations ──────────────────────────────────
if [ "$SKIP_MIGRATION" = false ]; then
    step "5/9 Running database migrations"
    sudo -u $APP_USER php artisan migrate --force --no-interaction 2>&1 | tee -a "$LOG_FILE"
    success "Migration selesai"
else
    log "  ⚠ Skip migration (--skip-migration)"
fi

# ─── 6. Set file permissions ─────────────────────────────────────
step "6/9 Setting file permissions"
chown -R $APP_USER:$APP_USER "$APP_DIR"
chmod -R 775 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
success "Permissions set"

# ─── 7. Optimize Laravel ─────────────────────────────────────────
step "7/9 Optimizing Laravel (config, route, view, event cache)"
sudo -u $APP_USER php artisan optimize:clear 2>&1 | tee -a "$LOG_FILE"
sudo -u $APP_USER php artisan config:cache 2>&1 | tee -a "$LOG_FILE"
sudo -u $APP_USER php artisan route:cache 2>&1 | tee -a "$LOG_FILE"
sudo -u $APP_USER php artisan view:cache 2>&1 | tee -a "$LOG_FILE"
sudo -u $APP_USER php artisan event:cache 2>&1 | tee -a "$LOG_FILE"
success "Laravel optimize selesai"

# ─── 8. Reload PHP-FPM & restart queue ───────────────────────────
step "8/9 Reloading PHP-FPM (clear OPcache) & restarting queue workers"
systemctl reload "$PHP_FPM_SERVICE"
success "PHP-FPM reloaded"
# queue:restart signal worker (database driver) untuk exit setelah job aktif
# selesai; supervisor/systemd timer akan respawn dengan code baru.
sudo -u $APP_USER php artisan queue:restart 2>&1 | tee -a "$LOG_FILE"
success "Queue workers signaled to restart"

# ─── 9. Reload Nginx & disable maintenance ───────────────────────
step "9/9 Reloading Nginx & disabling maintenance mode"
nginx -t 2>&1 | tee -a "$LOG_FILE" || error "Nginx config test gagal"
systemctl reload nginx
sudo -u $APP_USER php artisan up 2>&1 | tee -a "$LOG_FILE"
success "Maintenance mode dinonaktifkan, app live"

# ─── Done ────────────────────────────────────────────────────────
DEPLOY_END=$(date +%s)
ELAPSED=$((DEPLOY_END - DEPLOY_START))

log ""
log "═══════════════════════════════════════════════════════════════"
log "  ✅ DEPLOY SELESAI dalam ${ELAPSED} detik"
log "  Branch: $GIT_BRANCH"
log "  Commit: ${NEW_COMMIT:0:8}"
log "═══════════════════════════════════════════════════════════════"
log ""
