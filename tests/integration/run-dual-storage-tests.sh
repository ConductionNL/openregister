#!/bin/bash

################################################################################
# Dual Storage Test Runner (Smart Version)
#
# This script runs the Newman test suite twice:
# 1. Normal storage mode (objects in oc_openregister_objects table)
# 2. Magic mapper mode (objects in dedicated schema tables)
#
# Strategy:
#   - Run tests normally first
#   - Then run tests again with ENABLE_MAGIC_MAPPER=true environment variable
#   - The collection checks this variable and enables magic mapping via API
#
# Usage:
#   ./run-dual-storage-tests.sh [--verbose]
#
# Options:
#   --verbose  Show full Newman output for both runs
#
# Requirements:
#   - Docker container 'nextcloud' must be running
#   - Newman must be installed in the container
#   - OpenRegister app must be enabled
#
# Exit codes:
#   0 = All tests passed in both modes
#   1 = Tests failed in one or both modes
#   2 = Script error (setup/cleanup failed)
################################################################################

set -e  # Exit on error.

# Colors for output.
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
MAGENTA='\033[0;35m'
NC='\033[0m' # No Color.

# Configuration.
CONTAINER_NAME="nextcloud"
TEST_COLLECTION="/var/www/html/custom_apps/openregister/tests/integration/openregister-crud.postman_collection.json"
TEMP_DIR="/tmp/newman-dual-storage-$$"
VERBOSE=false

# Parse arguments.
while [[ $# -gt 0 ]]; do
    case $1 in
        --verbose|-v)
            VERBOSE=true
            shift
            ;;
        *)
            echo "Unknown option: $1"
            echo "Usage: $0 [--verbose]"
            exit 2
            ;;
    esac
done

# Logging functions.
log_info() {
    echo -e "${BLUE}ℹ ${NC}$1"
}

log_success() {
    echo -e "${GREEN}✓${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

log_error() {
    echo -e "${RED}✗${NC} $1"
}

log_debug() {
    if [ "$VERBOSE" = true ]; then
        echo -e "${MAGENTA}🔍${NC} $1"
    fi
}

log_header() {
    echo ""
    echo "╔══════════════════════════════════════════════════════════════════════════════╗"
    printf "║ %-76s ║\n" "$1"
    echo "╚══════════════════════════════════════════════════════════════════════════════╝"
    echo ""
}

# Cleanup function.
cleanup() {
    if [ -d "$TEMP_DIR" ]; then
        log_info "Cleaning up temporary directory..."
        rm -rf "$TEMP_DIR"
        log_success "Cleanup complete"
    fi
}

# Set trap to ensure cleanup runs.
trap cleanup EXIT

# Create temp directory.
mkdir -p "$TEMP_DIR"

# Function to check if container is running.
check_container() {
    log_info "Checking if Nextcloud container is running..."
    if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
        log_error "Container '${CONTAINER_NAME}' is not running"
        exit 2
    fi
    log_success "Container is running"
}

# Function to run Newman tests.
run_newman_tests() {
    local mode=$1
    local enable_magic=$2
    local output_file="${TEMP_DIR}/newman-${mode}.txt"
    
    log_info "Running Newman tests in ${mode} mode..."
    
    # Build newman command with --env-var flag if magic mapper should be enabled
    local newman_cmd="newman run ${TEST_COLLECTION} --reporters cli --color on"
    
    if [ "$enable_magic" = "true" ]; then
        newman_cmd="$newman_cmd --env-var ENABLE_MAGIC_MAPPER=true"
    fi
    
    log_debug "Command: docker exec -u 33 ${CONTAINER_NAME} $newman_cmd"
    
    # Run Newman and capture both stdout and exit code.
    set +e  # Don't exit on error for Newman.
    docker exec -u 33 "${CONTAINER_NAME}" $newman_cmd > "$output_file" 2>&1
    local exit_code=$?
    set -e
    
    # Show output if verbose or if there were failures.
    if [ "$VERBOSE" = true ] || [ $exit_code -ne 0 ]; then
        cat "$output_file"
    fi
    
    return $exit_code
}

# Function to extract test summary from Newman output.
extract_summary() {
    local file=$1
    
    # Extract just the summary table (more reliable).
    grep -A 6 "│.*executed.*failed.*│" "$file" | head -15 2>/dev/null || echo "Could not extract summary"
}

# Function to count specific metrics from Newman output.
count_metric() {
    local file=$1
    local metric=$2  # iterations, requests, test-scripts, assertions.
    
    # Look for the metric row and extract the failure count (last number).
    local failures=$(grep "$metric" "$file" | grep -oP '\d+(?=\s*│?\s*$)' | tail -1 2>/dev/null)
    echo "${failures:-0}"
}

# Function to get total count for a metric.
count_total() {
    local file=$1
    local metric=$2
    
    # Get the executed count (second-to-last number).
    local total=$(grep "$metric" "$file" | grep -oP '\d+' | tail -2 | head -1 2>/dev/null)
    echo "${total:-0}"
}

################################################################################
# MAIN EXECUTION
################################################################################

log_header "🚀 DUAL STORAGE TEST RUNNER FOR OPENREGISTER"

echo "This script validates that OpenRegister works correctly with BOTH storage modes:"
echo "  • Normal Storage: All objects in oc_openregister_objects (JSON blobs)"
echo "  • Magic Mapper: Objects in dedicated oc_openregister_table_X_Y (SQL columns)"
echo ""

# Step 1: Pre-flight checks.
log_header "Step 1: Pre-flight Checks"
check_container

# Step 2: Run tests in NORMAL storage mode.
log_header "Step 2: Normal Storage Mode"
log_info "📦 Testing traditional blob storage (oc_openregister_objects)"
log_info "Objects are stored as JSON blobs in a single table"
echo ""

run_newman_tests "normal" "false"
normal_exit_code=$?

echo ""
if [ $normal_exit_code -eq 0 ]; then
    log_success "Normal storage tests PASSED ✅"
else
    log_warning "Normal storage tests had failures ⚠️"
fi

# Extract metrics.
normal_assertions_total=$(count_total "${TEMP_DIR}/newman-normal.txt" "assertions")
normal_assertions_failed=$(count_metric "${TEMP_DIR}/newman-normal.txt" "assertions")

log_info "Normal Storage Results:"
echo "  Assertions: ${normal_assertions_total} total, ${normal_assertions_failed} failed"
echo ""

# Step 3: Run tests in MAGIC MAPPER mode.
log_header "Step 3: Magic Mapper Mode"
log_info "🔮 Testing magic mapper storage (oc_openregister_table_X_Y)"
log_info "Objects are stored in dedicated tables with SQL columns"
log_info "Setting environment: ENABLE_MAGIC_MAPPER=true"
echo ""

# Note: The Newman collection must check for this env var and enable magic mapping.
run_newman_tests "magic" "true"
magic_exit_code=$?

echo ""
if [ $magic_exit_code -eq 0 ]; then
    log_success "Magic mapper tests PASSED ✅"
else
    log_warning "Magic mapper tests had failures ⚠️"
fi

# Extract metrics.
magic_assertions_total=$(count_total "${TEMP_DIR}/newman-magic.txt" "assertions")
magic_assertions_failed=$(count_metric "${TEMP_DIR}/newman-magic.txt" "assertions")

log_info "Magic Mapper Results:"
echo "  Assertions: ${magic_assertions_total} total, ${magic_assertions_failed} failed"
echo ""

# Step 4: Compare results.
log_header "Step 4: Results Comparison"

echo "╔═════════════════════════════════╦═══════════════╦═══════════════╗"
echo "║ Storage Mode                    ║ Total Tests   ║ Failures      ║"
echo "╠═════════════════════════════════╬═══════════════╬═══════════════╣"
printf "║ %-31s ║ %-13s ║ %-13s ║\n" "📦 Normal (JSON blob)" "$normal_assertions_total" "$normal_assertions_failed"
printf "║ %-31s ║ %-13s ║ %-13s ║\n" "🔮 Magic Mapper (SQL columns)" "$magic_assertions_total" "$magic_assertions_failed"
echo "╚═════════════════════════════════╩═══════════════╩═══════════════╝"
echo ""

# Calculate difference.
if [ "$normal_assertions_failed" -ne "$magic_assertions_failed" ]; then
    log_warning "⚠️  STORAGE MODE PARITY ISSUE DETECTED!"
    echo "The two storage modes have different failure counts."
    echo "This indicates a potential compatibility issue."
    echo ""
fi

# Step 5: Final verdict.
log_header "Step 5: Final Verdict"

if [ $normal_exit_code -eq 0 ] && [ $magic_exit_code -eq 0 ]; then
    echo ""
    echo "╔════════════════════════════════════════════════════════════════════════════╗"
    echo "║                                                                            ║"
    echo "║                     ✅ ALL TESTS PASSED! ✅                                ║"
    echo "║                                                                            ║"
    echo "║  Both storage modes are fully functional:                                 ║"
    echo "║  • Normal Storage (JSON blobs)          ✓                                 ║"
    echo "║  • Magic Mapper (SQL columns)            ✓                                ║"
    echo "║                                                                            ║"
    echo "║  OpenRegister has 100% storage mode compatibility! 🚀                     ║"
    echo "║                                                                            ║"
    echo "╚════════════════════════════════════════════════════════════════════════════╝"
    echo ""
    exit 0
elif [ $normal_exit_code -ne 0 ] && [ $magic_exit_code -ne 0 ]; then
    log_error "❌ TESTS FAILED IN BOTH STORAGE MODES"
    echo ""
    echo "Both modes failed with similar issues:"
    echo "  • Normal failures: ${normal_assertions_failed}/${normal_assertions_total}"
    echo "  • Magic mapper failures: ${magic_assertions_failed}/${magic_assertions_total}"
    echo ""
    log_info "This suggests the issue is not storage-specific."
    log_info "Check output files:"
    echo "  • ${TEMP_DIR}/newman-normal.txt"
    echo "  • ${TEMP_DIR}/newman-magic.txt"
    echo ""
    exit 1
elif [ $normal_exit_code -ne 0 ]; then
    log_error "❌ NORMAL STORAGE TESTS FAILED"
    log_success "✅ Magic mapper tests passed"
    echo ""
    echo "Normal storage failures: ${normal_assertions_failed}/${normal_assertions_total}"
    log_info "Check: ${TEMP_DIR}/newman-normal.txt"
    echo ""
    exit 1
else
    log_error "❌ MAGIC MAPPER TESTS FAILED"
    log_success "✅ Normal storage tests passed"
    echo ""
    echo "Magic mapper failures: ${magic_assertions_failed}/${magic_assertions_total}"
    log_info "This indicates a magic mapper compatibility issue!"
    log_info "Check: ${TEMP_DIR}/newman-magic.txt"
    echo ""
    exit 1
fi
