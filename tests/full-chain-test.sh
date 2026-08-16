#!/bin/bash
# Full Chain Test for OpenRegister, OpenCatalogi, and SoftwareCatalog
# This test verifies that all configurations are properly loaded after a fresh deployment

# Don't exit on error - we handle errors ourselves
set +e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OPENREGISTER_DIR="$(dirname "$SCRIPT_DIR")"
APPS_EXTRA_DIR="$(dirname "$OPENREGISTER_DIR")"
NEXTCLOUD_URL="http://localhost:8080"
ADMIN_USER="admin"
ADMIN_PASS="admin"

# Counters
TESTS_PASSED=0
TESTS_FAILED=0

# Cache for API responses
REGISTERS_JSON=""
SCHEMAS_JSON=""

# Helper functions
log_info() {
    echo -e "${YELLOW}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[PASS]${NC} $1"
    ((TESTS_PASSED++))
}

log_error() {
    echo -e "${RED}[FAIL]${NC} $1"
    ((TESTS_FAILED++))
}

wait_for_nextcloud() {
    log_info "Waiting for Nextcloud to be ready..."
    local max_attempts=60
    local attempt=0
    while [ $attempt -lt $max_attempts ]; do
        if curl -s -o /dev/null -w "%{http_code}" "$NEXTCLOUD_URL/status.php" | grep -q "200"; then
            log_success "Nextcloud is ready"
            return 0
        fi
        sleep 2
        ((attempt++))
    done
    log_error "Nextcloud failed to start within timeout"
    return 1
}

api_call() {
    local method=$1
    local endpoint=$2
    local data=$3

    if [ -n "$data" ]; then
        curl -s -X "$method" "$NEXTCLOUD_URL$endpoint" \
            -u "$ADMIN_USER:$ADMIN_PASS" \
            -H "Content-Type: application/json" \
            -H "OCS-APIRequest: true" \
            -d "$data"
    else
        curl -s -X "$method" "$NEXTCLOUD_URL$endpoint" \
            -u "$ADMIN_USER:$ADMIN_PASS" \
            -H "OCS-APIRequest: true"
    fi
}

# Get register ID by slug
get_register_id_by_slug() {
    local slug=$1
    if [ -z "$REGISTERS_JSON" ]; then
        REGISTERS_JSON=$(api_call GET "/index.php/apps/openregister/api/registers")
    fi
    # Use python for reliable JSON parsing
    echo "$REGISTERS_JSON" | python3 -c "
import sys, json
data = json.load(sys.stdin)
for r in data.get('results', []):
    if r.get('slug') == '$slug':
        print(r.get('id'))
        break
" 2>/dev/null
}

# Get schema ID by slug
get_schema_id_by_slug() {
    local slug=$1
    if [ -z "$SCHEMAS_JSON" ]; then
        SCHEMAS_JSON=$(api_call GET "/index.php/apps/openregister/api/schemas?_limit=100")
    fi
    # Use python for reliable JSON parsing
    echo "$SCHEMAS_JSON" | python3 -c "
import sys, json
data = json.load(sys.stdin)
for s in data.get('results', []):
    if s.get('slug') == '$slug':
        print(s.get('id'))
        break
" 2>/dev/null
}

# Step 1: Clean up existing containers and volumes
step_cleanup() {
    log_info "Step 1: Cleaning up existing containers and volumes..."
    cd "$OPENREGISTER_DIR"
    docker compose down -v 2>/dev/null || true
    log_success "Cleanup completed"
}

# Step 2: Start fresh containers
step_start_containers() {
    log_info "Step 2: Starting fresh containers..."
    cd "$OPENREGISTER_DIR"
    docker compose up -d db nextcloud
    wait_for_nextcloud
}

# Step 3: Copy apps to custom_apps
step_copy_apps() {
    log_info "Step 3: Copying apps to custom_apps..."

    # Remove old copies (ignore errors for .git permission issues)
    rm -rf "$OPENREGISTER_DIR/custom_apps/opencatalogi" 2>/dev/null || true
    rm -rf "$OPENREGISTER_DIR/custom_apps/softwarecatalog" 2>/dev/null || true

    # Copy fresh (excluding .git folders to avoid permission issues)
    rsync -a --exclude='.git' "$APPS_EXTRA_DIR/opencatalogi/" "$OPENREGISTER_DIR/custom_apps/opencatalogi/"
    rsync -a --exclude='.git' "$APPS_EXTRA_DIR/softwarecatalog/" "$OPENREGISTER_DIR/custom_apps/softwarecatalog/"

    log_success "Apps copied to custom_apps"
}

# Step 4: Enable all apps
step_enable_apps() {
    log_info "Step 4: Enabling apps..."

    docker exec nextcloud php occ app:enable openregister
    if [ $? -eq 0 ]; then
        log_success "OpenRegister enabled"
    else
        log_error "Failed to enable OpenRegister"
    fi

    docker exec nextcloud php occ app:enable opencatalogi
    if [ $? -eq 0 ]; then
        log_success "OpenCatalogi enabled"
    else
        log_error "Failed to enable OpenCatalogi"
    fi

    docker exec nextcloud php occ app:enable softwarecatalog
    if [ $? -eq 0 ]; then
        log_success "SoftwareCatalog enabled"
    else
        log_error "Failed to enable SoftwareCatalog"
    fi
}

# Step 5: Verify OpenCatalogi configuration
step_verify_opencatalogi() {
    log_info "Step 5: Verifying OpenCatalogi configuration..."

    # Force import settings
    local import_result=$(api_call POST "/index.php/apps/opencatalogi/api/settings/import" '{"force": true}')

    if echo "$import_result" | grep -q '"success":true'; then
        log_success "OpenCatalogi settings imported"
    else
        log_error "OpenCatalogi settings import failed"
        echo "$import_result"
    fi

    # Check menus exist
    local menus=$(api_call GET "/index.php/apps/opencatalogi/api/menus?_limit=100")
    local menu_count=$(echo "$menus" | grep -o '"total":[0-9]*' | grep -o '[0-9]*')

    if [ -n "$menu_count" ] && [ "$menu_count" -gt 0 ]; then
        log_success "OpenCatalogi menus loaded: $menu_count menus found"
    else
        log_error "OpenCatalogi menus not loaded"
    fi

    # Check pages exist
    local home_page=$(api_call GET "/index.php/apps/opencatalogi/api/pages/home")

    if echo "$home_page" | grep -q '"title"'; then
        log_success "OpenCatalogi home page exists"
    else
        log_error "OpenCatalogi home page not found"
    fi

    # Check publication register exists
    local registers=$(api_call GET "/index.php/apps/openregister/api/registers")

    if echo "$registers" | grep -q '"slug":"publication"'; then
        log_success "OpenCatalogi publication register exists"
    else
        log_error "OpenCatalogi publication register not found"
    fi
}

# Step 6: Verify SoftwareCatalog configuration
step_verify_softwarecatalog() {
    log_info "Step 6: Verifying SoftwareCatalog configuration..."

    # Clear cache to get fresh data
    REGISTERS_JSON=""
    SCHEMAS_JSON=""

    # Check voorzieningen register
    local registers=$(api_call GET "/index.php/apps/openregister/api/registers")

    if echo "$registers" | grep -q '"slug":"voorzieningen"'; then
        log_success "SoftwareCatalog voorzieningen register exists"
    else
        log_error "SoftwareCatalog voorzieningen register not found"
    fi

    # Check AMEF register
    if echo "$registers" | grep -q '"slug":"vng-gemma"'; then
        log_success "SoftwareCatalog AMEF register exists"
    else
        log_error "SoftwareCatalog AMEF register not found"
    fi

    # Check AMEF config
    local amef_config=$(api_call GET "/index.php/apps/softwarecatalog/api/amef/config")

    if echo "$amef_config" | grep -q '"success":true'; then
        log_success "SoftwareCatalog AMEF config available"
    else
        log_error "SoftwareCatalog AMEF config not available"
    fi
}

# Step 7: Import AMEF data
step_import_amef() {
    log_info "Step 7: Importing AMEF data..."

    local amef_file="/var/www/html/custom_apps/softwarecatalog/data/GEMMA release.xml"

    local import_result=$(api_call POST "/index.php/apps/softwarecatalog/api/archimate/import" \
        "{\"file_path\": \"$amef_file\", \"updateExisting\": true, \"preserveIds\": true}")

    if echo "$import_result" | grep -q '"success":true'; then
        local objects_processed=$(echo "$import_result" | grep -o '"objects_processed":[0-9]*' | grep -o '[0-9]*')
        log_success "AMEF file imported: $objects_processed objects processed"
    else
        log_error "AMEF file import failed"
        echo "$import_result" | head -c 500
    fi
}

# Step 8: Import CSV data
step_import_csv() {
    log_info "Step 8: Importing CSV data..."

    # Clear cache and get fresh data
    REGISTERS_JSON=""
    SCHEMAS_JSON=""

    # Get register ID for voorzieningen by slug
    local voorzieningen_register_id=$(get_register_id_by_slug "voorzieningen")

    if [ -z "$voorzieningen_register_id" ]; then
        log_error "Could not find voorzieningen register ID"
        return 1
    fi

    log_info "Voorzieningen register ID: $voorzieningen_register_id"

    # Get schema IDs by slug
    local organisatie_id=$(get_schema_id_by_slug "organisatie")
    local module_id=$(get_schema_id_by_slug "module")
    local contactpersoon_id=$(get_schema_id_by_slug "contactpersoon")
    local moduleversie_id=$(get_schema_id_by_slug "moduleVersie")
    local koppeling_id=$(get_schema_id_by_slug "koppeling")
    local compliancy_id=$(get_schema_id_by_slug "compliancy")
    local gebruik_id=$(get_schema_id_by_slug "gebruik")

    log_info "Schema IDs: organisatie=$organisatie_id, module=$module_id, contactpersoon=$contactpersoon_id"
    log_info "Schema IDs: moduleversie=$moduleversie_id, koppeling=$koppeling_id, compliancy=$compliancy_id, gebruik=$gebruik_id"

    # Define CSV files and their schema slugs
    declare -A csv_schema_map=(
        ["organisatie.csv"]="$organisatie_id"
        ["module.csv"]="$module_id"
        ["contactpersoon.csv"]="$contactpersoon_id"
        ["moduleversie.csv"]="$moduleversie_id"
        ["koppeling.csv"]="$koppeling_id"
        ["compliancy.csv"]="$compliancy_id"
        ["gebruik.csv"]="$gebruik_id"
        ["gebruik_2.csv"]="$gebruik_id"
        ["gebruik_3.csv"]="$gebruik_id"
    )

    # Import order matters for foreign keys
    local import_order=(
        "organisatie.csv"
        "module.csv"
        "contactpersoon.csv"
        "moduleversie.csv"
        "koppeling.csv"
        "compliancy.csv"
        "gebruik.csv"
        "gebruik_2.csv"
        "gebruik_3.csv"
    )

    for filename in "${import_order[@]}"; do
        local schema_id="${csv_schema_map[$filename]}"
        local filepath="$APPS_EXTRA_DIR/softwarecatalog/data/$filename"

        if [ -f "$filepath" ] && [ -n "$schema_id" ]; then
            local result=$(curl -s -X POST "$NEXTCLOUD_URL/index.php/apps/openregister/api/registers/$voorzieningen_register_id/import" \
                -u "$ADMIN_USER:$ADMIN_PASS" \
                -H "OCS-APIRequest: true" \
                -F "file=@$filepath" \
                -F "schema=$schema_id" \
                -F "validation=false" \
                -F "events=false" \
                -F "rbac=false" \
                -F "multi=false")

            if echo "$result" | grep -q '"message":"Import successful"'; then
                local found=$(echo "$result" | grep -o '"found":[0-9]*' | grep -o '[0-9]*')
                log_success "Imported $filename: $found records"
            else
                log_error "Failed to import $filename"
                echo "$result" | head -c 300
            fi
        else
            log_info "Skipping $filename (file not found or schema ID missing: $schema_id)"
        fi
    done
}

# Step 9: Verify magic tables
step_verify_magic_tables() {
    log_info "Step 9: Verifying magic tables..."

    # Get register IDs by slug
    local publication_id=$(get_register_id_by_slug "publication")
    local voorzieningen_id=$(get_register_id_by_slug "voorzieningen")
    local amef_id=$(get_register_id_by_slug "vng-gemma")

    log_info "Register IDs: publication=$publication_id, voorzieningen=$voorzieningen_id, amef=$amef_id"

    # Check OpenCatalogi publication register tables
    if [ -n "$publication_id" ]; then
        local oc_tables=$(docker exec openregister-postgres psql -U nextcloud -d nextcloud -t -c \
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_name LIKE 'oc_openregister_table_${publication_id}_%';" 2>/dev/null | tr -d ' ')

        if [ -n "$oc_tables" ] && [ "$oc_tables" -gt 0 ]; then
            log_success "OpenCatalogi magic tables created: $oc_tables tables"
        else
            log_error "OpenCatalogi magic tables not created"
        fi
    fi

    # Check Voorzieningen register tables
    if [ -n "$voorzieningen_id" ]; then
        local voorz_tables=$(docker exec openregister-postgres psql -U nextcloud -d nextcloud -t -c \
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_name LIKE 'oc_openregister_table_${voorzieningen_id}_%';" 2>/dev/null | tr -d ' ')

        if [ -n "$voorz_tables" ] && [ "$voorz_tables" -gt 0 ]; then
            log_success "Voorzieningen magic tables created: $voorz_tables tables"

            # Count total records
            local voorz_count=$(docker exec openregister-postgres psql -U nextcloud -d nextcloud -t -c \
                "SELECT SUM(n_live_tup) FROM pg_stat_user_tables WHERE relname LIKE 'oc_openregister_table_${voorzieningen_id}_%';" 2>/dev/null | tr -d ' ')

            if [ -n "$voorz_count" ] && [ "$voorz_count" -gt 0 ]; then
                log_success "Voorzieningen magic tables have data: $voorz_count records"
            else
                log_error "Voorzieningen magic tables are empty"
            fi
        else
            log_error "Voorzieningen magic tables not created"
        fi
    fi

    # Check AMEF register tables
    if [ -n "$amef_id" ]; then
        local amef_tables=$(docker exec openregister-postgres psql -U nextcloud -d nextcloud -t -c \
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_name LIKE 'oc_openregister_table_${amef_id}_%';" 2>/dev/null | tr -d ' ')

        if [ -n "$amef_tables" ] && [ "$amef_tables" -gt 0 ]; then
            log_success "AMEF magic tables created: $amef_tables tables"

            # Count total records
            local amef_count=$(docker exec openregister-postgres psql -U nextcloud -d nextcloud -t -c \
                "SELECT SUM(n_live_tup) FROM pg_stat_user_tables WHERE relname LIKE 'oc_openregister_table_${amef_id}_%';" 2>/dev/null | tr -d ' ')

            if [ -n "$amef_count" ] && [ "$amef_count" -gt 0 ]; then
                log_success "AMEF magic tables have data: $amef_count records"
            else
                log_error "AMEF magic tables are empty"
            fi
        else
            log_error "AMEF magic tables not created"
        fi
    fi
}

# Main execution
main() {
    echo ""
    echo "========================================"
    echo "  Full Chain Test for OpenRegister"
    echo "========================================"
    echo ""

    step_cleanup
    step_start_containers
    step_copy_apps
    step_enable_apps
    step_verify_opencatalogi
    step_verify_softwarecatalog
    step_import_amef
    step_import_csv
    step_verify_magic_tables

    echo ""
    echo "========================================"
    echo "  Test Results"
    echo "========================================"
    echo ""
    echo -e "Tests Passed: ${GREEN}$TESTS_PASSED${NC}"
    echo -e "Tests Failed: ${RED}$TESTS_FAILED${NC}"
    echo ""

    if [ $TESTS_FAILED -eq 0 ]; then
        echo -e "${GREEN}All tests passed!${NC}"
        exit 0
    else
        echo -e "${RED}Some tests failed!${NC}"
        exit 1
    fi
}

# Run main
main "$@"
