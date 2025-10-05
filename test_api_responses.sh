#!/bin/bash

# Test script to make API calls and save responses to files
# This will help us debug the type filtering issue

set -e

echo "🧪 Testing OpenRegister API and saving responses"
echo "=============================================="
echo ""

# Configuration
CONTAINER_NAME="master-nextcloud-1"
NEXTCLOUD_USER="admin"
NEXTCLOUD_PASS="admin"
BASE_URL="http://localhost"
OUTPUT_DIR="api_responses"

# Create output directory
mkdir -p "$OUTPUT_DIR"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

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

# Helper function to make API call and save response
make_api_call() {
    local endpoint=$1
    local filename=$2
    local description=$3
    
    echo ""
    print_status "INFO" "Testing: $description"
    echo "Endpoint: $endpoint"
    echo "Saving to: $filename"
    
    # Execute API call inside Nextcloud container
    local response
    response=$(docker exec -u 33 "$CONTAINER_NAME" curl -s \
        -u "$NEXTCLOUD_USER:$NEXTCLOUD_PASS" \
        -H "Accept: application/json" \
        "$BASE_URL$endpoint" 2>&1)
    
    local exit_code=$?
    
    if [[ $exit_code -eq 0 ]]; then
        # Save response to file
        echo "$response" > "$OUTPUT_DIR/$filename"
        print_status "SUCCESS" "Response saved to $filename"
        echo "Response preview:"
        echo "$response" | head -n 10
    else
        print_status "ERROR" "API call failed with exit code $exit_code"
        echo "Response: $response"
        echo "$response" > "$OUTPUT_DIR/$filename.error"
    fi
    echo ""
}

# Check if container is running
if ! docker ps | grep -q "$CONTAINER_NAME"; then
    print_status "ERROR" "Container $CONTAINER_NAME is not running"
    exit 1
fi

print_status "INFO" "Container $CONTAINER_NAME is running"

echo ""
print_status "INFO" "Starting API response testing..."

# Test 1: Get all organizations without type filter
make_api_call \
    "/index.php/apps/openregister/api/objects/voorzieningen/organisatie?_limit=10&_page=1&_extend[]=@self.schema&_source=database" \
    "all_organizations.json" \
    "All organizations (no type filter)"

# Test 2: Try type filtering with samenwerking
make_api_call \
    "/index.php/apps/openregister/api/objects/voorzieningen/organisatie?_limit=10&_page=1&_extend[]=@self.schema&_source=database&type[]=samenwerking" \
    "type_samenwerking.json" \
    "Organizations with type=samenwerking"

# Test 3: Try type filtering with community
make_api_call \
    "/index.php/apps/openregister/api/objects/voorzieningen/organisatie?_limit=10&_page=1&_extend[]=@self.schema&_source=database&type[]=community" \
    "type_community.json" \
    "Organizations with type=community"

# Test 4: Try type filtering with both types
make_api_call \
    "/index.php/apps/openregister/api/objects/voorzieningen/organisatie?_limit=10&_page=1&_extend[]=@self.schema&_source=database&type[]=samenwerking&type[]=community" \
    "type_both.json" \
    "Organizations with type=samenwerking OR type=community"

# Test 5: Try with Solr source instead of database
make_api_call \
    "/index.php/apps/openregister/api/objects/voorzieningen/organisatie?_limit=10&_page=1&_extend[]=@self.schema&_source=index&type[]=samenwerking&type[]=community" \
    "type_both_solr.json" \
    "Organizations with type filter using Solr source"

# Test 6: Get specific organizations by name to see their structure
make_api_call \
    "/index.php/apps/openregister/api/objects/voorzieningen/organisatie?_limit=10&_page=1&_extend[]=@self.schema&_source=database&name[]=Samenwerking 1" \
    "name_samenwerking_1.json" \
    "Samenwerking 1 organization structure"

make_api_call \
    "/index.php/apps/openregister/api/objects/voorzieningen/organisatie?_limit=10&_page=1&_extend[]=@self.schema&_source=database&name[]=Community 1" \
    "name_community_1.json" \
    "Community 1 organization structure"

echo ""
print_status "INFO" "API response testing completed!"
print_status "INFO" "All responses saved to $OUTPUT_DIR/ directory"
echo ""
echo "📝 Files created:"
ls -la "$OUTPUT_DIR/"
echo ""
print_status "SUCCESS" "You can now examine the response files to identify the type filtering issue!"
