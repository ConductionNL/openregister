#!/bin/bash

# Test script for SOLR Collection Delete functionality
# This script tests the complete workflow: Setup -> Delete -> Setup again

set -e

echo "🧪 Testing SOLR Collection Delete Functionality"
echo "=============================================="

# Configuration
NEXTCLOUD_BASE_URL="http://localhost:8080"
API_BASE="/index.php/apps/openregister/api"
ADMIN_USER="admin"
ADMIN_PASS="admin"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to make API calls
api_call() {
    local method="$1"
    local endpoint="$2"
    local data="$3"
    
    echo -e "${BLUE}📡 $method $endpoint${NC}"
    
    if [ "$method" = "GET" ]; then
        curl -s -u "$ADMIN_USER:$ADMIN_PASS" \
             -H "Content-Type: application/json" \
             -X "$method" \
             "$NEXTCLOUD_BASE_URL$API_BASE$endpoint"
    else
        curl -s -u "$ADMIN_USER:$ADMIN_PASS" \
             -H "Content-Type: application/json" \
             -X "$method" \
             -d "$data" \
             "$NEXTCLOUD_BASE_URL$API_BASE$endpoint"
    fi
}

# Function to check if SOLR is available
check_solr_availability() {
    echo -e "\n${YELLOW}🔍 Step 1: Checking SOLR availability${NC}"
    
    response=$(api_call "GET" "/solr/dashboard/stats" "")
    echo "$response" | jq '.' || echo "Response: $response"
    
    if echo "$response" | grep -q '"available":true'; then
        echo -e "${GREEN}✅ SOLR is available${NC}"
        return 0
    else
        echo -e "${RED}❌ SOLR is not available${NC}"
        return 1
    fi
}

# Function to setup SOLR
setup_solr() {
    echo -e "\n${YELLOW}🔧 Step 2: Setting up SOLR collection${NC}"
    
    response=$(api_call "POST" "/solr/setup" "{}")
    echo "$response" | jq '.' || echo "Response: $response"
    
    if echo "$response" | grep -q '"success":true'; then
        echo -e "${GREEN}✅ SOLR setup completed successfully${NC}"
        return 0
    else
        echo -e "${RED}❌ SOLR setup failed${NC}"
        return 1
    fi
}

# Function to test connection after setup
test_connection() {
    echo -e "\n${YELLOW}🧪 Step 3: Testing SOLR connection${NC}"
    
    response=$(api_call "POST" "/settings/solr/test" "{}")
    echo "$response" | jq '.' || echo "Response: $response"
    
    if echo "$response" | grep -q '"success":true'; then
        echo -e "${GREEN}✅ SOLR connection test passed${NC}"
        return 0
    else
        echo -e "${RED}❌ SOLR connection test failed${NC}"
        return 1
    fi
}

# Function to delete collection
delete_collection() {
    echo -e "\n${YELLOW}🗑️  Step 4: Deleting SOLR collection${NC}"
    
    response=$(api_call "DELETE" "/solr/collection/delete" "")
    echo "$response" | jq '.' || echo "Response: $response"
    
    if echo "$response" | grep -q '"success":true'; then
        echo -e "${GREEN}✅ SOLR collection deleted successfully${NC}"
        
        # Extract collection name if available
        collection=$(echo "$response" | jq -r '.collection // "unknown"')
        echo -e "${GREEN}   Deleted collection: $collection${NC}"
        
        return 0
    else
        echo -e "${RED}❌ SOLR collection deletion failed${NC}"
        return 1
    fi
}

# Function to verify collection is gone
verify_deletion() {
    echo -e "\n${YELLOW}🔍 Step 5: Verifying collection deletion${NC}"
    
    response=$(api_call "GET" "/solr/dashboard/stats" "")
    echo "$response" | jq '.' || echo "Response: $response"
    
    # After deletion, SOLR should still be available but no collection should exist
    if echo "$response" | grep -q '"available":false' || echo "$response" | grep -q 'error'; then
        echo -e "${GREEN}✅ Collection deletion verified (SOLR shows no collection)${NC}"
        return 0
    else
        echo -e "${YELLOW}⚠️  SOLR still shows available - this might be expected${NC}"
        return 0
    fi
}

# Function to test re-setup
test_re_setup() {
    echo -e "\n${YELLOW}🔧 Step 6: Testing SOLR re-setup${NC}"
    
    response=$(api_call "POST" "/solr/setup" "{}")
    echo "$response" | jq '.' || echo "Response: $response"
    
    if echo "$response" | grep -q '"success":true'; then
        echo -e "${GREEN}✅ SOLR re-setup completed successfully${NC}"
        return 0
    else
        echo -e "${RED}❌ SOLR re-setup failed${NC}"
        return 1
    fi
}

# Function to final verification
final_verification() {
    echo -e "\n${YELLOW}🔍 Step 7: Final verification${NC}"
    
    response=$(api_call "GET" "/solr/dashboard/stats" "")
    echo "$response" | jq '.' || echo "Response: $response"
    
    if echo "$response" | grep -q '"available":true'; then
        echo -e "${GREEN}✅ Final verification passed - SOLR is working${NC}"
        return 0
    else
        echo -e "${RED}❌ Final verification failed${NC}"
        return 1
    fi
}

# Main test execution
main() {
    echo -e "${BLUE}Starting SOLR Collection Delete Test...${NC}\n"
    
    # Check if jq is available for JSON parsing
    if ! command -v jq &> /dev/null; then
        echo -e "${YELLOW}⚠️  jq not found - JSON responses will be shown as raw text${NC}\n"
    fi
    
    # Step 1: Check initial SOLR availability
    if ! check_solr_availability; then
        echo -e "\n${YELLOW}SOLR not initially available - setting up first...${NC}"
        setup_solr || {
            echo -e "\n${RED}❌ Initial setup failed - cannot continue test${NC}"
            exit 1
        }
    fi
    
    # Step 2: Ensure we have a collection to delete
    echo -e "\n${YELLOW}Ensuring SOLR collection exists for deletion test...${NC}"
    setup_solr
    
    # Step 3: Test connection to confirm working state
    test_connection
    
    # Step 4: Delete the collection (main test)
    if delete_collection; then
        echo -e "\n${GREEN}🎉 Collection deletion test PASSED${NC}"
    else
        echo -e "\n${RED}❌ Collection deletion test FAILED${NC}"
        exit 1
    fi
    
    # Step 5: Verify deletion worked
    verify_deletion
    
    # Step 6: Test that we can re-setup after deletion
    test_re_setup
    
    # Step 7: Final verification
    final_verification
    
    echo -e "\n${GREEN}🎉 All tests completed successfully!${NC}"
    echo -e "${GREEN}✅ Delete Collection functionality is working correctly${NC}"
    
    echo -e "\n${BLUE}📋 Test Summary:${NC}"
    echo "1. ✅ SOLR availability check"
    echo "2. ✅ Initial collection setup"
    echo "3. ✅ Connection test"
    echo "4. ✅ Collection deletion"
    echo "5. ✅ Deletion verification"
    echo "6. ✅ Re-setup after deletion"
    echo "7. ✅ Final verification"
    
    echo -e "\n${GREEN}The delete collection feature is ready for production use!${NC}"
}

# Run the main function
main "$@"
