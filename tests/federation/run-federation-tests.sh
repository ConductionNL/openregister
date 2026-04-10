#!/bin/bash
# =============================================================================
# OpenCatalogi Federation Integration Tests
# =============================================================================
#
# Spins up two isolated Nextcloud instances, installs OpenRegister + OpenCatalogi
# on both, runs the Newman federation test collection, then tears everything down.
#
# Usage:
#   bash tests/federation/run-federation-tests.sh
#
# Requirements:
#   - Docker + Docker Compose v2
#   - npm (for npx newman)
#   - Ports 9081 and 9082 available
#
# =============================================================================

set -euo pipefail

# ---- Configuration ----------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
COMPOSE_FILE="$PROJECT_DIR/docker-compose.federation.yml"
COMPOSE_CMD="docker compose -p federation-test -f $COMPOSE_FILE"
COLLECTION="$SCRIPT_DIR/federation-tests.postman_collection.json"

NC1_URL="http://localhost:9081"
NC2_URL="http://localhost:9082"
NC2_INTERNAL="http://nc-fed-2"

NC1_CONTAINER="nc-fed-1"
NC2_CONTAINER="nc-fed-2"
DB_CONTAINER="federation-db"

ADMIN_USER="admin"
ADMIN_PASS="admin"

MAX_WAIT=300  # Max seconds to wait for containers to be healthy
INSTALL_WAIT=30  # Seconds to wait after app install for initialization

# ---- Colors -----------------------------------------------------------------
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

# ---- Helper functions -------------------------------------------------------
log()     { echo -e "${BLUE}[INFO]${NC} $1"; }
success() { echo -e "${GREEN}[PASS]${NC} $1"; }
warn()    { echo -e "${YELLOW}[WARN]${NC} $1"; }
error()   { echo -e "${RED}[FAIL]${NC} $1"; }

# ---- Cleanup (runs on exit) ------------------------------------------------
cleanup() {
    local exit_code=$?
    echo ""
    log "Tearing down federation test environment..."
    cd "$PROJECT_DIR"
    $COMPOSE_CMD down -v --remove-orphans 2>/dev/null || true

    if [ $exit_code -eq 0 ]; then
        success "Federation tests completed successfully!"
    else
        error "Federation tests failed (exit code: $exit_code)"
    fi

    exit $exit_code
}
trap cleanup EXIT

# ---- Pre-flight checks -----------------------------------------------------
log "Running pre-flight checks..."

if ! command -v docker &>/dev/null; then
    error "Docker is not installed"
    exit 1
fi

if ! docker compose version &>/dev/null; then
    error "Docker Compose v2 is not available"
    exit 1
fi

if ! command -v npx &>/dev/null; then
    error "npx is not available (install Node.js)"
    exit 1
fi

if ! [ -f "$COMPOSE_FILE" ]; then
    error "docker-compose.federation.yml not found at $COMPOSE_FILE"
    exit 1
fi

if ! [ -f "$COLLECTION" ]; then
    error "Newman collection not found at $COLLECTION"
    exit 1
fi

# Check if ports are available
for port in 9081 9082; do
    if ss -tlnp 2>/dev/null | grep -q ":$port "; then
        error "Port $port is already in use"
        exit 1
    fi
done

success "Pre-flight checks passed"

# ---- Start containers -------------------------------------------------------
echo ""
echo "============================================"
echo "  OpenCatalogi Federation Test Suite"
echo "============================================"
echo ""

log "Starting federation test environment..."
cd "$PROJECT_DIR"

# Clean up any previous run
$COMPOSE_CMD down -v --remove-orphans 2>/dev/null || true

# Start fresh
$COMPOSE_CMD up -d

# ---- Wait for database ------------------------------------------------------
log "Waiting for database to be ready..."
elapsed=0
while ! docker exec "$DB_CONTAINER" pg_isready -U nextcloud -d nc1 &>/dev/null; do
    sleep 2
    elapsed=$((elapsed + 2))
    if [ $elapsed -ge $MAX_WAIT ]; then
        error "Database did not become ready within ${MAX_WAIT}s"
        exit 1
    fi
done
success "Database ready (${elapsed}s)"

# ---- Wait for Nextcloud instances -------------------------------------------
wait_for_nextcloud() {
    local container=$1
    local url=$2
    local name=$3
    local elapsed=0

    log "Waiting for $name ($container) to be ready..."
    while true; do
        # Check if container is still running
        if ! docker ps --format '{{.Names}}' | grep -q "^${container}$"; then
            error "$name container is not running"
            docker logs "$container" 2>&1 | tail -20
            exit 1
        fi

        # Check HTTP status
        if curl -sf "$url/status.php" 2>/dev/null | grep -q '"installed":true'; then
            success "$name is ready (${elapsed}s)"
            return 0
        fi

        sleep 5
        elapsed=$((elapsed + 5))
        if [ $elapsed -ge $MAX_WAIT ]; then
            error "$name did not become ready within ${MAX_WAIT}s"
            docker logs "$container" 2>&1 | tail -30
            exit 1
        fi

        # Progress indicator every 30 seconds
        if [ $((elapsed % 30)) -eq 0 ]; then
            log "  Still waiting for $name... (${elapsed}s)"
        fi
    done
}

wait_for_nextcloud "$NC1_CONTAINER" "$NC1_URL" "Instance 1"
wait_for_nextcloud "$NC2_CONTAINER" "$NC2_URL" "Instance 2"

# ---- Install apps on both instances -----------------------------------------
install_apps() {
    local container=$1
    local name=$2

    log "Installing apps on $name..."

    # Enable OpenRegister
    if docker exec -u www-data "$container" php occ app:enable openregister 2>&1; then
        success "  OpenRegister enabled on $name"
    else
        warn "  OpenRegister may already be enabled on $name"
    fi

    # Enable OpenCatalogi
    if docker exec -u www-data "$container" php occ app:enable opencatalogi 2>&1; then
        success "  OpenCatalogi enabled on $name"
    else
        warn "  OpenCatalogi may already be enabled on $name"
    fi

    # Set trusted domain to allow cross-container requests
    docker exec -u www-data "$container" php occ config:system:set trusted_domains 1 --value="$container" 2>&1
    docker exec -u www-data "$container" php occ config:system:set trusted_domains 2 --value="nc-fed-1" 2>&1
    docker exec -u www-data "$container" php occ config:system:set trusted_domains 3 --value="nc-fed-2" 2>&1

    # Disable brute force protection for test environment
    docker exec -u www-data "$container" php occ config:system:set auth.bruteforce.protection.enabled --type=boolean --value=false 2>&1

    success "  Apps installed on $name"
}

install_apps "$NC1_CONTAINER" "Instance 1"
install_apps "$NC2_CONTAINER" "Instance 2"

# Restart Apache to clear OPcache and pick up any code changes
log "Restarting Apache on both instances..."
docker exec "$NC1_CONTAINER" apache2ctl graceful 2>/dev/null || true
docker exec "$NC2_CONTAINER" apache2ctl graceful 2>/dev/null || true

# Wait briefly for repair steps to complete
log "Waiting 10s for initial repair steps..."
sleep 10

# Trigger settings load to initialize schemas and registers
log "Loading OpenCatalogi settings (initializing schemas)..."
for url in "$NC1_URL" "$NC2_URL"; do
    result=$(curl -sf -u "$ADMIN_USER:$ADMIN_PASS" "$url/index.php/apps/opencatalogi/api/settings/load" 2>/dev/null || echo '{}')
    schemas=$(echo "$result" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d.get('schemas',[])))" 2>/dev/null || echo "0")
    if [ "$schemas" -gt 0 ]; then
        success "  Loaded $schemas schemas on $url"
    else
        warn "  No schemas loaded on $url (may already exist)"
    fi
done

# Wait for schema initialization to settle
log "Waiting ${INSTALL_WAIT}s for app initialization..."
sleep "$INSTALL_WAIT"

# Verify apps are responding
log "Verifying app endpoints..."
verify_app() {
    local url=$1
    local label=$2
    if curl -sf -u "$ADMIN_USER:$ADMIN_PASS" "$url/index.php/apps/opencatalogi/api/directory" >/dev/null 2>&1; then
        success "  OpenCatalogi responding on $label"
    else
        error "  OpenCatalogi not responding on $label"
        exit 1
    fi
}
verify_app "$NC1_URL" "Instance 1"
verify_app "$NC2_URL" "Instance 2"

# ---- Run Newman tests -------------------------------------------------------
echo ""
echo "============================================"
echo "  Running Newman Federation Tests"
echo "============================================"
echo ""

npx newman run "$COLLECTION" \
    --env-var "nc1Url=$NC1_URL" \
    --env-var "nc2Url=$NC2_URL" \
    --env-var "nc2Internal=$NC2_INTERNAL" \
    --reporters cli \
    --color on \
    --timeout-request 30000 \
    --delay-request 500

NEWMAN_EXIT=$?

echo ""
if [ $NEWMAN_EXIT -eq 0 ]; then
    echo "============================================"
    success "All federation tests passed!"
    echo "============================================"
else
    echo "============================================"
    error "Some federation tests failed"
    echo "============================================"
fi

exit $NEWMAN_EXIT
