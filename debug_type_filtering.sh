#!/bin/bash

# Debug script for type filtering issue in OpenRegister
# This script tests the type[] filtering for samenwerking and community organizations

set -e

echo "🔍 Debugging OpenRegister Type Filtering Issue"
echo "=============================================="
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
BLUE='\033[0;34m'
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
        "DEBUG")
            echo -e "${BLUE}🔍 $message${NC}"
            ;;
    esac
}

# Helper function to test API endpoint and show full response
test_api_with_response() {
    local endpoint=$1
    local description=$2
    
    echo ""
    print_status "DEBUG" "Testing: $description"
    echo "Endpoint: $endpoint"
    echo "---------------------------------------"
    
    # Execute API call inside Nextcloud container
    local response
    response=$(docker exec -u 33 "$CONTAINER_NAME" curl -s \
        -u "$NEXTCLOUD_USER:$NEXTCLOUD_PASS" \
        -H "Accept: application/json" \
        "$BASE_URL$endpoint" 2>&1)
    
    local exit_code=$?
    
    if [[ $exit_code -eq 0 ]]; then
        print_status "SUCCESS" "API call successful"
        echo "Response:"
        echo "$response" | jq '.' 2>/dev/null || echo "$response"
        echo ""
    else
        print_status "ERROR" "API call failed with exit code $exit_code"
        echo "Response: $response"
        echo ""
    fi
}

# Check if container is running
if ! docker ps | grep -q "$CONTAINER_NAME"; then
    print_status "ERROR" "Container $CONTAINER_NAME is not running"
    exit 1
fi

print_status "INFO" "Container $CONTAINER_NAME is running"

echo ""
print_status "INFO" "Starting type filtering investigation..."

# Test 1: Get all organizations without type filter
test_api_with_response \
    "/index.php/apps/openregister/api/objects/voorzieningen/organisatie?_limit=10&_page=1&_extend[]=@self.schema&_source=database" \
    "All organizations (no type filter)"

# Test 2: Try type filtering with samenwerking
test_api_with_response \
    "/index.php/apps/openregister/api/objects/voorzieningen/organisatie?_limit=10&_page=1&_extend[]=@self.schema&_source=database&type[]=samenwerking" \
    "Organizations with type=samenwerking"

# Test 3: Try type filtering with community
test_api_with_response \
    "/index.php/apps/openregister/api/objects/voorzieningen/organisatie?_limit=10&_page=1&_extend[]=@self.schema&_source=database&type[]=community" \
    "Organizations with type=community"

# Test 4: Try type filtering with both types
test_api_with_response \
    "/index.php/apps/openregister/api/objects/voorzieningen/organisatie?_limit=10&_page=1&_extend[]=@self.schema&_source=database&type[]=samenwerking&type[]=community" \
    "Organizations with type=samenwerking OR type=community"

# Test 5: Try with Solr source instead of database
test_api_with_response \
    "/index.php/apps/openregister/api/objects/voorzieningen/organisatie?_limit=10&_page=1&_extend[]=@self.schema&_source=index&type[]=samenwerking&type[]=community" \
    "Organizations with type filter using Solr source"

# Test 6: Get specific organizations by name to see their structure
test_api_with_response \
    "/index.php/apps/openregister/api/objects/voorzieningen/organisatie?_limit=10&_page=1&_extend[]=@self.schema&_source=database&name[]=Samenwerking 1" \
    "Samenwerking 1 organization structure"

test_api_with_response \
    "/index.php/apps/openregister/api/objects/voorzieningen/organisatie?_limit=10&_page=1&_extend[]=@self.schema&_source=database&name[]=Community 1" \
    "Community 1 organization structure"

echo ""
print_status "INFO" "Type filtering investigation completed!"
print_status "INFO" "Check the responses above to identify the issue with type filtering"
