#!/bin/bash

# SPDX-FileCopyrightText: 2024 Conduction BV <info@conduction.nl>
# SPDX-License-Identifier: AGPL-3.0-or-later

set -e

echo "🧪 Testing OpenRegister SOLR APIs"
echo "================================="
echo ""

# Configuration
CONTAINER_NAME="master-nextcloud-1"
NEXTCLOUD_USER="admin"
NEXTCLOUD_PASS="admin"
BASE_URL="http://localhost"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Helper function to print colored output
print_status() {
    local status=$1
    local message=$2
    case $status in
        "SUCCESS")
            echo -e "${GREEN}✅ $message${NC}"
            ;;
        "ERROR")
            echo -e "${RED}❌ $message${NC}"
            ;;
        "INFO")
            echo -e "${YELLOW}ℹ️  $message${NC}"
            ;;
    esac
}

# Helper function to test API endpoint
test_api_endpoint() {
    local endpoint=$1
    local method=${2:-GET}
    local data=${3:-"{}"}
    local description=$4
    
    echo ""
    echo "📋 Testing: $description"
    echo "---------------------------------------"
    echo "Endpoint: $endpoint"
    echo "Method: $method"
    
    # Execute API call inside Nextcloud container
    local response
    if [[ "$method" == "POST" ]]; then
        response=$(docker exec -u 33 "$CONTAINER_NAME" curl -s \
            -u "$NEXTCLOUD_USER:$NEXTCLOUD_PASS" \
            -H "Content-Type: application/json" \
            -H "Accept: application/json" \
            -X POST \
            -d "$data" \
            "$BASE_URL$endpoint" 2>&1)
    else
        response=$(docker exec -u 33 "$CONTAINER_NAME" curl -s \
            -u "$NEXTCLOUD_USER:$NEXTCLOUD_PASS" \
            -H "Accept: application/json" \
            "$BASE_URL$endpoint" 2>&1)
    fi
    
    local exit_code=$?
    
    if [[ $exit_code -eq 0 ]]; then
        # Check if response contains error indicators
        if echo "$response" | grep -q "error\|Error\|ERROR\|exception\|Exception"; then
            print_status "ERROR" "API returned error response"
            echo "Response: $response"
            return 1
        else
            print_status "SUCCESS" "API call successful"
            echo "Response: $response" | head -n 5
            return 0
        fi
    else
        print_status "ERROR" "API call failed with exit code $exit_code"
        echo "Response: $response"
        return 1
    fi
}

# Check if container is running
if ! docker ps | grep -q "$CONTAINER_NAME"; then
    print_status "ERROR" "Container $CONTAINER_NAME is not running"
    exit 1
fi

print_status "INFO" "Container $CONTAINER_NAME is running"

# Test 1: SOLR Connection Test API
test_api_endpoint \
    "/index.php/apps/openregister/api/settings/solr/test" \
    "POST" \
    "{}" \
    "SOLR Connection Test API"

# Test 2: SOLR Setup API  
test_api_endpoint \
    "/index.php/apps/openregister/api/solr/setup" \
    "POST" \
    "{}" \
    "SOLR Setup API"

# Test 3: SOLR Status API (if exists)
test_api_endpoint \
    "/index.php/apps/openregister/api/settings/solr/status" \
    "GET" \
    "" \
    "SOLR Status API"

# Test 4: General Settings API
test_api_endpoint \
    "/index.php/apps/openregister/api/settings" \
    "GET" \
    "" \
    "General Settings API"

echo ""
echo "🎯 Test Summary"
echo "==============="
print_status "INFO" "All API tests completed"
print_status "INFO" "Check individual test results above for details"

echo ""
echo "📝 Notes:"
echo "- Tests are executed inside the Nextcloud container"
echo "- API calls use basic authentication with admin:admin"
echo "- Responses are truncated to first 5 lines for readability"
echo "- Full responses are shown for error cases"

echo ""
print_status "SUCCESS" "SOLR API testing script completed!"
