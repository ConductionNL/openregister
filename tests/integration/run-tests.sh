#!/bin/bash

##
# Newman Test Runner for OpenRegister
#
# This script provides a consistent way to run Newman integration tests
# for both local development and CI/CD environments.
#
# @category Testing
# @package  OpenRegister
# @author   Conduction
# @license  EUPL-1.2 https://opensource.org/licenses/EUPL-1.2
# @link     https://github.com/ConductionNL/openregister
##

set -e  # Exit on error.

# Color output for better readability.
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color.

# Script directory.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COLLECTION_FILE="${SCRIPT_DIR}/openregister-crud.postman_collection.json"

# Default configuration.
BASE_URL="${NEXTCLOUD_URL:-http://localhost}"
ADMIN_USER="${NEXTCLOUD_ADMIN_USER:-admin}"
ADMIN_PASSWORD="${NEXTCLOUD_ADMIN_PASSWORD:-admin}"
CONTAINER_NAME="${NEXTCLOUD_CONTAINER:-master-nextcloud-1}"
RUN_MODE="${RUN_MODE:-local}" # local or ci.

##
# Print colored message.
#
# @param string $1 Color code
# @param string $2 Message
#
# @return void
##
print_message() {
    echo -e "${1}${2}${NC}"
}

##
# Print script usage.
#
# @return void
##
usage() {
    cat << EOF
Usage: $0 [OPTIONS]

Run Newman integration tests for OpenRegister.

OPTIONS:
    -h, --help              Show this help message
    -u, --url URL           Base URL for Nextcloud (default: http://localhost)
    -U, --user USER         Admin username (default: admin)
    -P, --password PASS     Admin password (default: admin)
    -c, --container NAME    Container name (default: master-nextcloud-1)
    -m, --mode MODE         Run mode: local or ci (default: local)
    -C, --clean             Force clean start (clear all variables)
    -v, --verbose           Verbose output

ENVIRONMENT VARIABLES:
    NEXTCLOUD_URL           Override base URL
    NEXTCLOUD_ADMIN_USER    Override admin username
    NEXTCLOUD_ADMIN_PASSWORD Override admin password
    NEXTCLOUD_CONTAINER     Override container name
    RUN_MODE                Override run mode (local/ci)

EXAMPLES:
    # Run tests locally with defaults:
    $0

    # Run tests with custom URL:
    $0 --url http://nextcloud.local

    # Run in CI mode:
    $0 --mode ci

    # Force clean start:
    $0 --clean

EOF
    exit 0
}

##
# Check if Newman is available.
#
# @return void
##
check_newman() {
    if ! command -v newman &> /dev/null; then
        print_message "$RED" "Error: Newman is not installed."
        print_message "$YELLOW" "Install it with: npm install -g newman"
        exit 1
    fi
}

##
# Check if collection file exists.
#
# @return void
##
check_collection() {
    if [ ! -f "$COLLECTION_FILE" ]; then
        print_message "$RED" "Error: Collection file not found: $COLLECTION_FILE"
        exit 1
    fi
}

##
# Clear Newman collection variables to ensure clean test run.
#
# @return void
##
clear_variables() {
    print_message "$BLUE" "🧹 Clearing collection variables for fresh test run..."
    
    # Create a temporary collection with cleared variables.
    local temp_collection="${COLLECTION_FILE}.tmp"
    
    # Use jq to clear the _test_run_initialized flag if jq is available.
    if command -v jq &> /dev/null; then
        jq '.variable = [.variable[] | if .key == "_test_run_initialized" then .value = "" else . end]' \
            "$COLLECTION_FILE" > "$temp_collection"
        mv "$temp_collection" "$COLLECTION_FILE"
        print_message "$GREEN" "✅ Variables cleared using jq"
    else
        print_message "$YELLOW" "⚠️  jq not found, relying on collection's built-in cleanup"
    fi
}

##
# Copy collection to container if running in local mode with Docker.
#
# @return void
##
copy_to_container() {
    if [ "$RUN_MODE" = "local" ]; then
        if command -v docker &> /dev/null; then
            print_message "$BLUE" "📦 Copying collection to container: $CONTAINER_NAME"
            docker cp "$COLLECTION_FILE" "$CONTAINER_NAME:/tmp/openregister-crud.postman_collection.json" 2>/dev/null || {
                print_message "$YELLOW" "⚠️  Could not copy to container, running from host"
                return 1
            }
            return 0
        fi
    fi
    return 1
}

##
# Run Newman tests.
#
# @param boolean $1 Whether running from container
#
# @return void
##
run_newman() {
    local from_container=$1
    local collection_path="$COLLECTION_FILE"
    
    if [ "$from_container" = true ]; then
        collection_path="/tmp/openregister-crud.postman_collection.json"
        print_message "$BLUE" "🚀 Running Newman tests from container..."
        
        docker exec "$CONTAINER_NAME" newman run "$collection_path" \
            --env-var "base_url=$BASE_URL" \
            --env-var "admin_user=$ADMIN_USER" \
            --env-var "admin_password=$ADMIN_PASSWORD" \
            --reporters cli \
            --color on
    else
        print_message "$BLUE" "🚀 Running Newman tests from host..."
        
        newman run "$collection_path" \
            --env-var "base_url=$BASE_URL" \
            --env-var "admin_user=$ADMIN_USER" \
            --env-var "admin_password=$ADMIN_PASSWORD" \
            --reporters cli \
            --color on
    fi
}

##
# Main execution.
#
# @return void
##
main() {
    local clean_start=false
    local verbose=false
    
    # Parse command line arguments.
    while [[ $# -gt 0 ]]; do
        case $1 in
            -h|--help)
                usage
                ;;
            -u|--url)
                BASE_URL="$2"
                shift 2
                ;;
            -U|--user)
                ADMIN_USER="$2"
                shift 2
                ;;
            -P|--password)
                ADMIN_PASSWORD="$2"
                shift 2
                ;;
            -c|--container)
                CONTAINER_NAME="$2"
                shift 2
                ;;
            -m|--mode)
                RUN_MODE="$2"
                shift 2
                ;;
            -C|--clean)
                clean_start=true
                shift
                ;;
            -v|--verbose)
                verbose=true
                shift
                ;;
            *)
                print_message "$RED" "Unknown option: $1"
                usage
                ;;
        esac
    done
    
    print_message "$GREEN" "╔════════════════════════════════════════════════════════════╗"
    print_message "$GREEN" "║         OpenRegister Newman Integration Tests            ║"
    print_message "$GREEN" "╚════════════════════════════════════════════════════════════╝"
    echo ""
    
    # Display configuration.
    print_message "$BLUE" "Configuration:"
    echo "  Base URL:       $BASE_URL"
    echo "  Admin User:     $ADMIN_USER"
    echo "  Container:      $CONTAINER_NAME"
    echo "  Run Mode:       $RUN_MODE"
    echo "  Clean Start:    $clean_start"
    echo ""
    
    # Pre-flight checks.
    check_newman
    check_collection
    
    # Clear variables if requested or in CI mode.
    if [ "$clean_start" = true ] || [ "$RUN_MODE" = "ci" ]; then
        clear_variables
    fi
    
    # Try to copy to container and run from there, fallback to host.
    local from_container=false
    if copy_to_container; then
        from_container=true
    fi
    
    # Run the tests.
    local exit_code=0
    run_newman "$from_container" || exit_code=$?
    
    echo ""
    if [ $exit_code -eq 0 ]; then
        print_message "$GREEN" "✅ All tests passed!"
    else
        print_message "$YELLOW" "⚠️  Some tests failed (exit code: $exit_code)"
        print_message "$BLUE" "💡 Tip: Run with --clean flag to force a fresh start"
    fi
    
    exit $exit_code
}

# Run main function.
main "$@"
