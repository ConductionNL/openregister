#!/bin/bash
#
# OpenRegister Performance Test Runner
#
# This script runs performance tests using Newman (Postman CLI) to ensure
# that API performance optimizations are maintained and regressions are caught early.
#
# Usage:
#   ./run-performance-tests.sh [environment]
#
# Parameters:
#   environment: local, staging, production (default: local)
#
# Prerequisites:
#   - Newman CLI installed: npm install -g newman
#   - Docker container running (for local tests)
#

set -e  # Exit on any error

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COLLECTION_FILE="${SCRIPT_DIR}/performance-test-collection.json"
ENVIRONMENT="${1:-local}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}=== OpenRegister Performance Test Suite ===${NC}"
echo -e "${YELLOW}Environment: ${ENVIRONMENT}${NC}"
echo ""

# Check if Newman is installed
if ! command -v newman &> /dev/null; then
    echo -e "${RED}Error: Newman CLI is not installed.${NC}"
    echo "Install it with: npm install -g newman"
    exit 1
fi

# Check if collection file exists
if [[ ! -f "$COLLECTION_FILE" ]]; then
    echo -e "${RED}Error: Collection file not found: $COLLECTION_FILE${NC}"
    exit 1
fi

# Set environment-specific variables
case $ENVIRONMENT in
    "local")
        BASE_URL="http://localhost"
        USERNAME="admin"
        PASSWORD="admin"
        
        # Check if Docker container is running
        if ! docker ps | grep -q nextcloud; then
            echo -e "${RED}Error: Nextcloud Docker container is not running.${NC}"
            echo "Start it first and then run this script."
            exit 1
        fi
        ;;
    "staging")
        BASE_URL="http://staging.openregister.app"
        USERNAME="${STAGING_USERNAME:-admin}"
        PASSWORD="${STAGING_PASSWORD:-admin}"
        ;;
    "production")
        BASE_URL="http://openregister.app"
        USERNAME="${PROD_USERNAME:-admin}"
        PASSWORD="${PROD_PASSWORD:-admin}"
        ;;
    *)
        echo -e "${RED}Error: Unknown environment: $ENVIRONMENT${NC}"
        echo "Supported environments: local, staging, production"
        exit 1
        ;;
esac

echo -e "${BLUE}Base URL: ${BASE_URL}${NC}"
echo -e "${BLUE}Running performance tests...${NC}"
echo ""

# Run Newman tests
if newman run "$COLLECTION_FILE" \
    --global-var "baseUrl=${BASE_URL}" \
    --global-var "username=${USERNAME}" \
    --global-var "password=${PASSWORD}" \
    --reporters cli,json \
    --reporter-json-export "${SCRIPT_DIR}/performance-results-$(date +%Y%m%d-%H%M%S).json" \
    --timeout 120000 \
    --verbose; then
    
    echo ""
    echo -e "${GREEN}✅ All performance tests passed!${NC}"
    echo -e "${GREEN}✅ No performance regressions detected.${NC}"
    
else
    echo ""
    echo -e "${RED}❌ Some performance tests failed!${NC}"
    echo -e "${RED}❌ Performance regression detected - please investigate.${NC}"
    exit 1
fi

echo ""
echo -e "${YELLOW}Performance test results saved to: ${SCRIPT_DIR}/performance-results-*.json${NC}"
echo -e "${BLUE}=== Performance Test Complete ===${NC}"
