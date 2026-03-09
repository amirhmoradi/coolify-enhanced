#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# Corelix Platform - Uninstaller
# =============================================================================
# Removes the enhanced addon from a running Coolify v4 instance.
# Optionally cleans up database tables.
#
# Usage:
#   bash uninstall.sh              # Interactive uninstall
#   bash uninstall.sh --keep-db    # Skip database cleanup prompt
#   bash uninstall.sh --clean-db   # Remove database tables without prompting
# =============================================================================

COOLIFY_BASE="/data/coolify"
COOLIFY_SOURCE="${COOLIFY_BASE}/source"
CUSTOM_COMPOSE="${COOLIFY_SOURCE}/docker-compose.custom.yml"
UPGRADE_SCRIPT="${COOLIFY_SOURCE}/upgrade.sh"
ENV_FILE="${COOLIFY_SOURCE}/.env"
BACKUP_SUFFIX=".backup.$(date +%Y%m%d_%H%M%S)"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# Parse arguments
DB_ACTION="prompt"
for arg in "$@"; do
    case "$arg" in
        --keep-db)  DB_ACTION="keep" ;;
        --clean-db) DB_ACTION="clean" ;;
        --help|-h)
            echo "Usage: bash uninstall.sh [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --keep-db     Keep database tables (permission data preserved)"
            echo "  --clean-db    Remove database tables without prompting"
            echo "  --help, -h    Show this help message"
            exit 0
            ;;
    esac
done

# --- Helper functions ---

info()    { echo -e "${BLUE}[INFO]${NC}  $*"; }
success() { echo -e "${GREEN}[OK]${NC}    $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC}  $*"; }
error()   { echo -e "${RED}[ERROR]${NC} $*"; }
step()    { echo -e "\n${CYAN}==>${NC} $*"; }

confirm() {
    local prompt="${1:-Continue?} [y/N] "
    read -r -p "$prompt" response
    case "$response" in
        [yY][eE][sS]|[yY]) return 0 ;;
        *) return 1 ;;
    esac
}

# --- Pre-flight checks ---

step "Checking prerequisites..."

if [ "$(id -u)" -ne 0 ]; then
    error "This script must be run as root (or with sudo)."
    exit 1
fi
success "Running as root"

if ! command -v docker &>/dev/null; then
    error "Docker is not installed."
    exit 1
fi
success "Docker is available"

if [ ! -d "$COOLIFY_SOURCE" ]; then
    error "Coolify installation not found at ${COOLIFY_SOURCE}"
    exit 1
fi
success "Coolify directory found"

# --- Confirmation ---

echo ""
echo -e "${YELLOW}This will uninstall the Corelix Platform addon.${NC}"
echo ""
echo "What will happen:"
echo "  - docker-compose.custom.yml will be removed (backed up first)"
echo "  - CORELIX_PLATFORM env var will be removed"
echo "  - Coolify will be restarted with the original image"
echo "  - All projects, users, and deployments remain intact"
echo ""

if ! confirm "Proceed with uninstall?"; then
    echo "Aborted."
    exit 0
fi

# --- Database cleanup ---

step "Database cleanup..."

if [ "$DB_ACTION" = "prompt" ]; then
    echo ""
    echo "The addon created these database tables:"
    echo "  - project_user (project-level permissions)"
    echo "  - environment_user (environment-level permissions)"
    echo "  - Added columns: users.is_global_admin, users.status"
    echo ""
    echo "These tables are harmless and will be ignored by standard Coolify."
    echo "Keeping them allows you to restore permissions if you reinstall later."
    echo ""

    if confirm "Remove database tables and columns? (data will be lost)"; then
        DB_ACTION="clean"
    else
        DB_ACTION="keep"
        info "Database tables will be preserved."
    fi
fi

if [ "$DB_ACTION" = "clean" ]; then
    info "Attempting database cleanup..."

    # Try to run rollback via artisan first (if the custom image is still running)
    if docker exec coolify php artisan migrate:rollback \
        --path=vendor/amirhmoradi/coolify-enhanced/database/migrations \
        --force 2>/dev/null; then
        success "Migrations rolled back via artisan"
    else
        warn "Could not rollback via artisan. Attempting direct SQL cleanup..."

        # Try direct SQL
        DB_CONTAINER=""
        if docker ps --format '{{.Names}}' | grep -q "coolify-db"; then
            DB_CONTAINER="coolify-db"
        elif docker ps --format '{{.Names}}' | grep -q "postgres"; then
            DB_CONTAINER=$(docker ps --format '{{.Names}}' | grep "postgres" | head -1)
        fi

        if [ -n "$DB_CONTAINER" ]; then
            # Try to get DB credentials from .env
            DB_USER="coolify"
            DB_NAME="coolify"
            if [ -f "$ENV_FILE" ]; then
                DB_USER=$(grep "^DB_USERNAME=" "$ENV_FILE" 2>/dev/null | cut -d= -f2 || echo "coolify")
                DB_NAME=$(grep "^DB_DATABASE=" "$ENV_FILE" 2>/dev/null | cut -d= -f2 || echo "coolify")
                [ -z "$DB_USER" ] && DB_USER="coolify"
                [ -z "$DB_NAME" ] && DB_NAME="coolify"
            fi

            SQL="DROP TABLE IF EXISTS environment_user; DROP TABLE IF EXISTS project_user; ALTER TABLE users DROP COLUMN IF EXISTS is_global_admin; ALTER TABLE users DROP COLUMN IF EXISTS status;"

            if docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" -c "$SQL" 2>/dev/null; then
                success "Database tables cleaned via SQL"
            else
                warn "Could not clean database automatically."
                echo ""
                echo "To clean up manually, connect to your database and run:"
                echo "  DROP TABLE IF EXISTS environment_user;"
                echo "  DROP TABLE IF EXISTS project_user;"
                echo "  ALTER TABLE users DROP COLUMN IF EXISTS is_global_admin;"
                echo "  ALTER TABLE users DROP COLUMN IF EXISTS status;"
            fi
        else
            warn "Could not find database container."
            echo ""
            echo "To clean up manually, connect to your database and run:"
            echo "  DROP TABLE IF EXISTS environment_user;"
            echo "  DROP TABLE IF EXISTS project_user;"
            echo "  ALTER TABLE users DROP COLUMN IF EXISTS is_global_admin;"
            echo "  ALTER TABLE users DROP COLUMN IF EXISTS status;"
        fi
    fi
fi

# --- Remove docker-compose.custom.yml ---

step "Removing docker-compose.custom.yml..."

if [ -f "$CUSTOM_COMPOSE" ]; then
    cp "$CUSTOM_COMPOSE" "${CUSTOM_COMPOSE}${BACKUP_SUFFIX}"
    info "Backed up to docker-compose.custom.yml${BACKUP_SUFFIX}"
    rm "$CUSTOM_COMPOSE"
    success "Removed ${CUSTOM_COMPOSE}"
else
    info "No docker-compose.custom.yml found (already removed)"
fi

# --- Remove environment variable ---

step "Cleaning environment variables..."

if [ -f "$ENV_FILE" ]; then
    if grep -q "CORELIX_PLATFORM" "$ENV_FILE"; then
        sed -i '/CORELIX_PLATFORM/d' "$ENV_FILE"
        success "Removed CORELIX_PLATFORM from .env"
    else
        info "CORELIX_PLATFORM not found in .env (already removed)"
    fi
else
    info "No .env file found"
fi

# --- Restart Coolify ---

step "Restarting Coolify with original image..."

if [ -f "$UPGRADE_SCRIPT" ]; then
    info "Running upgrade.sh to restart the stack..."
    if ! bash "$UPGRADE_SCRIPT"; then
        warn "upgrade.sh exited with non-zero status. Trying docker compose directly..."
        cd "$COOLIFY_SOURCE"
        docker compose up -d
    fi
else
    cd "$COOLIFY_SOURCE"
    docker compose up -d
fi

success "Coolify stack restarted"

# --- Wait and verify ---

step "Waiting for Coolify to be ready..."

MAX_WAIT=60
WAITED=0
while [ $WAITED -lt $MAX_WAIT ]; do
    if docker ps --format '{{.Names}}' | grep -q "coolify"; then
        if docker ps --format '{{.Names}}\t{{.Status}}' | grep "coolify" | grep -q "Up"; then
            break
        fi
    fi
    sleep 2
    WAITED=$((WAITED + 2))
    echo -n "."
done
echo ""

if [ $WAITED -ge $MAX_WAIT ]; then
    warn "Timed out waiting for Coolify. Check: docker logs coolify --tail 50"
else
    success "Coolify is running"
fi

# Check the running image
RUNNING_IMAGE=$(docker inspect coolify --format='{{.Config.Image}}' 2>/dev/null || echo "unknown")
info "Running image: ${RUNNING_IMAGE}"

# --- Clean up local Docker image (optional) ---

if docker images --format '{{.Repository}}:{{.Tag}}' | grep -q "corelix-platform"; then
    echo ""
    if confirm "Remove local enhanced Docker images?"; then
        docker images --format '{{.Repository}}:{{.Tag}}' | grep "corelix-platform" | while read -r img; do
            docker rmi "$img" 2>/dev/null && info "Removed image: $img" || true
        done
        success "Local images cleaned up"
    else
        info "Local images preserved"
    fi
fi

# --- Done ---

echo ""
echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}  Uninstall Complete!${NC}"
echo -e "${GREEN}============================================${NC}"
echo ""
echo "What was done:"
echo "  - Removed docker-compose.custom.yml (backup saved)"
echo "  - Removed CORELIX_PLATFORM from .env"
if [ "$DB_ACTION" = "clean" ]; then
    echo "  - Cleaned up database tables"
else
    echo "  - Database tables preserved (safe to keep)"
fi
echo "  - Restarted Coolify with original image"
echo ""
echo "Coolify is now running with its default configuration."
echo "All team members have full access to all projects (standard behavior)."
echo ""
echo "To reinstall later, run: bash install.sh"
