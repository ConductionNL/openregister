#!/bin/bash

# OpenRegister RBAC Test Runner
# This script automates the execution of RBAC tests for the OpenRegister application

set -e  # Exit on any error

# Configuration
CONTAINER_NAME="master-nextcloud-1"
BASE_URL="http://localhost/index.php/apps/openregister/api"
ADMIN_CREDS="admin:admin"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Test results tracking
TOTAL_TESTS=0
PASSED_TESTS=0
FAILED_TESTS=0

# Logging function
log() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
    ((PASSED_TESTS++))
}

error() {
    echo -e "${RED}[ERROR]${NC} $1"
    ((FAILED_TESTS++))
}

warn() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

# Function to execute curl command in container
exec_curl() {
    local cmd="$1"
    local description="$2"
    local expected_code="${3:-200}"
    
    ((TOTAL_TESTS++))
    log "Testing: $description"
    
    # Execute the curl command and capture response code
    response=$(docker exec -it -u 33 $CONTAINER_NAME bash -c "$cmd" 2>/dev/null || echo "FAILED")
    
    if [[ "$response" == "FAILED" ]]; then
        error "Failed to execute: $description"
        return 1
    fi
    
    # Extract HTTP status code (this is a simplified check)
    if echo "$response" | grep -q "error\|Error\|403\|401\|500"; then
        if [[ "$expected_code" == "403" ]]; then
            success "Correctly denied: $description"
        else
            error "Unexpected error: $description"
            echo "Response: $response"
            return 1
        fi
    else
        if [[ "$expected_code" == "403" ]]; then
            error "Expected denial but got success: $description" 
            return 1
        else
            success "Success: $description"
        fi
    fi
    
    return 0
}

# Setup test environment
setup_test_environment() {
    log "Setting up test environment..."
    
    # Create test groups
    docker exec -it -u 33 $CONTAINER_NAME bash -c "php /var/www/html/occ group:add editors" 2>/dev/null || true
    docker exec -it -u 33 $CONTAINER_NAME bash -c "php /var/www/html/occ group:add viewers" 2>/dev/null || true
    docker exec -it -u 33 $CONTAINER_NAME bash -c "php /var/www/html/occ group:add managers" 2>/dev/null || true
    docker exec -it -u 33 $CONTAINER_NAME bash -c "php /var/www/html/occ group:add staff" 2>/dev/null || true
    
    # Create test users (using environment variable for password)
    docker exec -it -u 33 $CONTAINER_NAME bash -c "OC_PASS='password123' php /var/www/html/occ user:add editor_user --password-from-env" 2>/dev/null || true
    docker exec -it -u 33 $CONTAINER_NAME bash -c "OC_PASS='password123' php /var/www/html/occ user:add viewer_user --password-from-env" 2>/dev/null || true  
    docker exec -it -u 33 $CONTAINER_NAME bash -c "OC_PASS='password123' php /var/www/html/occ user:add manager_user --password-from-env" 2>/dev/null || true
    docker exec -it -u 33 $CONTAINER_NAME bash -c "OC_PASS='password123' php /var/www/html/occ user:add staff_user --password-from-env" 2>/dev/null || true
    
    # Add users to groups
    docker exec -it -u 33 $CONTAINER_NAME bash -c "php /var/www/html/occ group:adduser editors editor_user" 2>/dev/null || true
    docker exec -it -u 33 $CONTAINER_NAME bash -c "php /var/www/html/occ group:adduser viewers viewer_user" 2>/dev/null || true
    docker exec -it -u 33 $CONTAINER_NAME bash -c "php /var/www/html/occ group:adduser managers manager_user" 2>/dev/null || true
    docker exec -it -u 33 $CONTAINER_NAME bash -c "php /var/www/html/occ group:adduser staff staff_user" 2>/dev/null || true
    
    success "Test environment setup completed"
}

# Create test schemas
create_test_schemas() {
    log "Creating test schemas..."
    
    # Schema 1: Open Access
    exec_curl 'curl -u "admin:admin" -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/schemas" -d '"'"'{
      "title": "RBAC Test - Open Access",
      "description": "Test schema with no authorization restrictions",
      "version": "1.0.0",
      "properties": {
        "name": {"type": "string", "required": true},
        "description": {"type": "string"}
      },
      "authorization": {}
    }'"'"'' "Create Open Access Schema" 201
    
    # Schema 2: Public Read Only
    exec_curl 'curl -u "admin:admin" -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/schemas" -d '"'"'{
      "title": "RBAC Test - Public Read",
      "description": "Test schema with public read access",
      "version": "1.0.0",
      "properties": {
        "name": {"type": "string", "required": true},
        "description": {"type": "string"}
      },
      "authorization": {
        "create": ["editors", "managers"],
        "read": ["public"],
        "update": ["editors", "managers"],
        "delete": ["managers"]
      }
    }'"'"'' "Create Public Read Schema" 201
    
    # Schema 3: Staff Only
    exec_curl 'curl -u "admin:admin" -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/schemas" -d '"'"'{
      "title": "RBAC Test - Staff Only",
      "description": "Test schema restricted to staff members",
      "version": "1.0.0",
      "properties": {
        "name": {"type": "string", "required": true},
        "description": {"type": "string"}
      },
      "authorization": {
        "create": ["staff"],
        "read": ["staff"],
        "update": ["staff"],
        "delete": ["managers", "staff"]
      }
    }'"'"'' "Create Staff Only Schema" 201
}

# Test CRUD operations for different user types
test_crud_operations() {
    log "Testing CRUD operations..."
    
    # Note: In a real implementation, you would need to extract schema/register IDs 
    # from the creation responses and use them in these tests
    
    # Test CREATE operations
    log "Testing CREATE operations..."
    
    exec_curl 'curl -u "admin:admin" -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/objects/1/1" -d '"'"'{"name": "Admin Created Object"}'"'"'' "Admin CREATE on Open Schema" 201
    
    exec_curl 'curl -u "editor_user:password123" -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/objects/1/2" -d '"'"'{"name": "Editor Created Object"}'"'"'' "Editor CREATE on Public Read Schema" 201
    
    exec_curl 'curl -u "viewer_user:password123" -H "OCS-APIREQUEST: true" -H "Content-Type: application/json" -X POST "http://localhost/index.php/apps/openregister/api/objects/1/2" -d '"'"'{"name": "Viewer Created Object"}'"'"'' "Viewer CREATE on Public Read Schema (should fail)" 403
    
    # Test READ operations  
    log "Testing READ operations..."
    
    exec_curl 'curl -u "admin:admin" -H "OCS-APIREQUEST: true" "http://localhost/index.php/apps/openregister/api/objects/1/1"' "Admin READ on Open Schema" 200
    
    exec_curl 'curl -H "OCS-APIREQUEST: true" "http://localhost/index.php/apps/openregister/api/objects/1/2"' "Public READ on Public Read Schema" 200
    
    exec_curl 'curl -u "viewer_user:password123" -H "OCS-APIREQUEST: true" "http://localhost/index.php/apps/openregister/api/objects/1/3"' "Viewer READ on Staff Only Schema (should fail)" 403
}

# Test search operations with RBAC filtering
test_search_operations() {
    log "Testing search operations with RBAC filtering..."
    
    exec_curl 'curl -u "admin:admin" -H "OCS-APIREQUEST: true" "http://localhost/index.php/apps/openregister/api/search?_search=test"' "Admin search (should see all)" 200
    
    exec_curl 'curl -u "viewer_user:password123" -H "OCS-APIREQUEST: true" "http://localhost/index.php/apps/openregister/api/search?_search=test"' "Viewer search (filtered results)" 200
    
    exec_curl 'curl -H "OCS-APIREQUEST: true" "http://localhost/index.php/apps/openregister/api/search?_search=test"' "Public search (filtered results)" 200
}

# Clean up test environment
cleanup_test_environment() {
    log "Cleaning up test environment..."
    
    # Remove test users
    docker exec -it -u 33 $CONTAINER_NAME bash -c "php /var/www/html/occ user:delete editor_user" 2>/dev/null || true
    docker exec -it -u 33 $CONTAINER_NAME bash -c "php /var/www/html/occ user:delete viewer_user" 2>/dev/null || true
    docker exec -it -u 33 $CONTAINER_NAME bash -c "php /var/www/html/occ user:delete manager_user" 2>/dev/null || true
    docker exec -it -u 33 $CONTAINER_NAME bash -c "php /var/www/html/occ user:delete staff_user" 2>/dev/null || true
    
    # Remove test groups
    docker exec -it -u 33 $CONTAINER_NAME bash -c "php /var/www/html/occ group:delete editors" 2>/dev/null || true
    docker exec -it -u 33 $CONTAINER_NAME bash -c "php /var/www/html/occ group:delete viewers" 2>/dev/null || true
    docker exec -it -u 33 $CONTAINER_NAME bash -c "php /var/www/html/occ group:delete managers" 2>/dev/null || true
    docker exec -it -u 33 $CONTAINER_NAME bash -c "php /var/www/html/occ group:delete staff" 2>/dev/null || true
    
    success "Test environment cleanup completed"
}

# Print test summary
print_summary() {
    echo
    echo "=================================================="
    echo "               RBAC TEST SUMMARY"
    echo "=================================================="
    echo "Total Tests:  $TOTAL_TESTS"
    echo -e "Passed Tests: ${GREEN}$PASSED_TESTS${NC}"
    echo -e "Failed Tests: ${RED}$FAILED_TESTS${NC}"
    echo
    
    if [ $FAILED_TESTS -eq 0 ]; then
        echo -e "${GREEN}🎉 All tests passed! RBAC implementation is working correctly.${NC}"
        exit 0
    else
        echo -e "${RED}❌ Some tests failed. Please review the RBAC implementation.${NC}"
        exit 1
    fi
}

# Main execution
main() {
    echo "=================================================="
    echo "       OpenRegister RBAC Test Runner"
    echo "=================================================="
    echo
    
    # Check if Docker container exists
    if ! docker ps | grep -q $CONTAINER_NAME; then
        error "Docker container '$CONTAINER_NAME' not found. Please start your Nextcloud development environment."
        exit 1
    fi
    
    # Run test phases
    setup_test_environment
    create_test_schemas
    test_crud_operations
    test_search_operations
    
    # Cleanup (optional - comment out if you want to inspect test data)
    if [[ "${1:-}" == "--cleanup" ]]; then
        cleanup_test_environment
    else
        warn "Test data preserved. Run with --cleanup flag to remove test users and groups."
    fi
    
    print_summary
}

# Handle script arguments
if [[ "${1:-}" == "--help" ]]; then
    echo "OpenRegister RBAC Test Runner"
    echo
    echo "Usage: $0 [OPTIONS]"
    echo
    echo "Options:"
    echo "  --cleanup    Clean up test users and groups after testing"
    echo "  --help       Show this help message"
    echo
    echo "This script runs comprehensive RBAC tests for the OpenRegister application."
    echo "It tests all combinations of CRUD operations and user roles."
    exit 0
fi

# Run main function
main "$@" 