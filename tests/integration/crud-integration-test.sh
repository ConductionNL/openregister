#!/bin/bash

##
# OpenRegister - Core CRUD Integration Test
#
# This script tests the complete CRUD lifecycle:
# 1. Create Register
# 2. Create Schema
# 3. Create Objects
# 4. Read Objects
# 5. Update Objects
# 6. List Objects
# 7. Update Register
# 8. Update Schema
# 9. Delete Objects
# 10. Delete Schema
# 11. Delete Register
# 12. Test Cascade Protection
#
# @category Tests
# @package  OCA\OpenRegister\Tests
#
# @author    Conduction Development Team <info@conduction.nl>
# @copyright 2024 Conduction B.V.
# @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
#
# @version GIT: <git_id>
#
# @link https://www.OpenRegister.app
##

set -e

BASE_URL="http://localhost"
AUTH="admin:admin"
TEST_ID="crud-$(date +%s)-$(openssl rand -hex 4)"

# Colors for output.
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Test counters.
TESTS_PASSED=0
TESTS_FAILED=0
TOTAL_TESTS=0

# Function to print test header.
print_header() {
    echo -e "\n${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${BLUE}  $1${NC}"
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
}

# Function to test assertion.
assert_status() {
    local expected=$1
    local actual=$2
    local message=$3
    
    TOTAL_TESTS=$((TOTAL_TESTS + 1))
    
    if [ "$expected" -eq "$actual" ]; then
        echo -e "  ${GREEN}✓${NC} $message (HTTP $actual)"
        TESTS_PASSED=$((TESTS_PASSED + 1))
        return 0
    else
        echo -e "  ${RED}✗${NC} $message (Expected: $expected, Got: $actual)"
        TESTS_FAILED=$((TESTS_FAILED + 1))
        return 1
    fi
}

# Function to test JSON property.
assert_json_property() {
    local json=$1
    local property=$2
    local message=$3
    
    TOTAL_TESTS=$((TOTAL_TESTS + 1))
    
    if echo "$json" | jq -e ".$property" > /dev/null 2>&1; then
        local value=$(echo "$json" | jq -r ".$property")
        echo -e "  ${GREEN}✓${NC} $message: $value"
        TESTS_PASSED=$((TESTS_PASSED + 1))
        return 0
    else
        echo -e "  ${RED}✗${NC} $message (Property not found)"
        TESTS_FAILED=$((TESTS_FAILED + 1))
        return 1
    fi
}

# Function to make API call.
api_call() {
    local method=$1
    local endpoint=$2
    local data=$3
    
    if [ -z "$data" ]; then
        curl -s -w "\n%{http_code}" -X "$method" \
            -u "$AUTH" \
            -H "Content-Type: application/json" \
            "$BASE_URL$endpoint"
    else
        curl -s -w "\n%{http_code}" -X "$method" \
            -u "$AUTH" \
            -H "Content-Type: application/json" \
            -d "$data" \
            "$BASE_URL$endpoint"
    fi
}

# Start tests.
print_header "OpenRegister Core CRUD Integration Tests (with RBAC)"
echo -e "Test ID: ${YELLOW}$TEST_ID${NC}"
echo ""

# Step 0: Create Organization for RBAC testing.
print_header "Step 0: Create Organization"
RESPONSE=$(api_call "POST" "/index.php/apps/openregister/api/organisations" "{
    \"name\": \"Test Organization $TEST_ID\",
    \"description\": \"Organization for CRUD and RBAC testing\"
}")

HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
BODY=$(echo "$RESPONSE" | sed '$d')

assert_status 201 "$HTTP_CODE" "Organization creation"
if [ "$HTTP_CODE" -eq 201 ]; then
    ORG_UUID=$(echo "$BODY" | jq -r '.organisation.uuid')
    ORG_NAME=$(echo "$BODY" | jq -r '.organisation.name')
    echo -e "  ${GREEN}→${NC} Organization UUID: $ORG_UUID"
    echo -e "  ${GREEN}→${NC} Organization Name: $ORG_NAME"
else
    echo -e "${YELLOW}Warning: Could not create organization, continuing without RBAC context${NC}"
    ORG_UUID=""
fi

# Step 1: Create Register (with organization context).
print_header "Step 1: Create Register"

if [ -n "$ORG_UUID" ]; then
    REGISTER_PAYLOAD="{
        \"slug\": \"test-register-$TEST_ID\",
        \"title\": \"CRUD Test Register\",
        \"description\": \"Register for testing complete CRUD lifecycle\",
        \"organisation\": \"$ORG_UUID\"
    }"
    echo -e "  ${BLUE}→${NC} Creating register with organization: $ORG_UUID"
else
    REGISTER_PAYLOAD="{
        \"slug\": \"test-register-$TEST_ID\",
        \"title\": \"CRUD Test Register\",
        \"description\": \"Register for testing complete CRUD lifecycle\"
    }"
fi

RESPONSE=$(api_call "POST" "/index.php/apps/openregister/api/registers" "$REGISTER_PAYLOAD")

HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
BODY=$(echo "$RESPONSE" | sed '$d')

assert_status 201 "$HTTP_CODE" "Register creation"
if [ "$HTTP_CODE" -eq 201 ]; then
    REGISTER_ID=$(echo "$BODY" | jq -r '.id')
    REGISTER_SLUG=$(echo "$BODY" | jq -r '.slug')
    assert_json_property "$BODY" "id" "Register has ID"
    echo -e "  ${GREEN}→${NC} Register ID: $REGISTER_ID"
    echo -e "  ${GREEN}→${NC} Register Slug: $REGISTER_SLUG"
else
    echo -e "${RED}Failed to create register. Exiting.${NC}"
    exit 1
fi

# Step 2: Create Schema.
print_header "Step 2: Create Schema"
RESPONSE=$(api_call "POST" "/index.php/apps/openregister/api/schemas" "{
    \"register\": $REGISTER_ID,
    \"slug\": \"person-$TEST_ID\",
    \"title\": \"Person Schema\",
    \"description\": \"Schema for testing CRUD operations\",
    \"properties\": {
        \"name\": {
            \"type\": \"string\",
            \"description\": \"Person name\"
        },
        \"age\": {
            \"type\": \"integer\",
            \"description\": \"Person age\"
        },
        \"active\": {
            \"type\": \"boolean\",
            \"description\": \"Is person active\"
        }
    },
    \"required\": [\"name\"]
}")

HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
BODY=$(echo "$RESPONSE" | sed '$d')

assert_status 201 "$HTTP_CODE" "Schema creation"
if [ "$HTTP_CODE" -eq 201 ]; then
    SCHEMA_ID=$(echo "$BODY" | jq -r '.id')
    SCHEMA_SLUG=$(echo "$BODY" | jq -r '.slug')
    assert_json_property "$BODY" "id" "Schema has ID"
    echo -e "  ${GREEN}→${NC} Schema ID: $SCHEMA_ID"
    echo -e "  ${GREEN}→${NC} Schema Slug: $SCHEMA_SLUG"
else
    echo -e "${RED}Failed to create schema. Exiting.${NC}"
    exit 1
fi

# Step 3: Create Object 1.
print_header "Step 3: Create Object 1"
RESPONSE=$(api_call "POST" "/index.php/apps/openregister/api/objects/$REGISTER_SLUG/$SCHEMA_SLUG" "{
    \"name\": \"John Doe\",
    \"age\": 30,
    \"active\": true
}")

HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
BODY=$(echo "$RESPONSE" | sed '$d')

assert_status 201 "$HTTP_CODE" "Object 1 creation"
if [ "$HTTP_CODE" -eq 201 ]; then
    OBJECT1_UUID=$(echo "$BODY" | jq -r '.id')
    assert_json_property "$BODY" "id" "Object has ID"
    echo -e "  ${GREEN}→${NC} Object 1 UUID: $OBJECT1_UUID"
fi

# Step 4: Create Object 2.
print_header "Step 4: Create Object 2"
RESPONSE=$(api_call "POST" "/index.php/apps/openregister/api/objects/$REGISTER_SLUG/$SCHEMA_SLUG" "{
    \"name\": \"Jane Smith\",
    \"age\": 25,
    \"active\": true
}")

HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
BODY=$(echo "$RESPONSE" | sed '$d')

assert_status 201 "$HTTP_CODE" "Object 2 creation"
if [ "$HTTP_CODE" -eq 201 ]; then
    OBJECT2_UUID=$(echo "$BODY" | jq -r '.id')
    echo -e "  ${GREEN}→${NC} Object 2 UUID: $OBJECT2_UUID"
fi

# Step 5: Read Object 1.
print_header "Step 5: Read Object 1"
RESPONSE=$(api_call "GET" "/index.php/apps/openregister/api/objects/$REGISTER_SLUG/$SCHEMA_SLUG/$OBJECT1_UUID" "")

HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
BODY=$(echo "$RESPONSE" | sed '$d')

assert_status 200 "$HTTP_CODE" "Object 1 read"
if [ "$HTTP_CODE" -eq 200 ]; then
    assert_json_property "$BODY" "name" "Object has name"
    assert_json_property "$BODY" "age" "Object has age"
fi

# Step 6: Update Object 1.
print_header "Step 6: Update Object 1"
RESPONSE=$(api_call "PUT" "/index.php/apps/openregister/api/objects/$REGISTER_SLUG/$SCHEMA_SLUG/$OBJECT1_UUID" "{
    \"name\": \"John Doe Updated\",
    \"age\": 31,
    \"active\": false
}")

HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
BODY=$(echo "$RESPONSE" | sed '$d')

assert_status 200 "$HTTP_CODE" "Object 1 update"
if [ "$HTTP_CODE" -eq 200 ]; then
    NAME=$(echo "$BODY" | jq -r '.name')
    AGE=$(echo "$BODY" | jq -r '.age')
    ACTIVE=$(echo "$BODY" | jq -r '.active')
    echo -e "  ${GREEN}→${NC} Updated name: $NAME"
    echo -e "  ${GREEN}→${NC} Updated age: $AGE"
    echo -e "  ${GREEN}→${NC} Updated active: $ACTIVE"
fi

# Step 7: List Objects.
print_header "Step 7: List Objects"
RESPONSE=$(api_call "GET" "/index.php/apps/openregister/api/objects/$REGISTER_SLUG/$SCHEMA_SLUG" "")

HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
BODY=$(echo "$RESPONSE" | sed '$d')

assert_status 200 "$HTTP_CODE" "List objects"
if [ "$HTTP_CODE" -eq 200 ]; then
    COUNT=$(echo "$BODY" | jq '.results | length')
    echo -e "  ${GREEN}→${NC} Found $COUNT objects"
fi

# Step 8: Create Source (Data Source).
print_header "Step 8: Create Source"
RESPONSE=$(api_call "POST" "/index.php/apps/openregister/api/sources" "{
    \"title\": \"Test API Source\",
    \"description\": \"Source for testing CRUD\",
    \"type\": \"api\",
    \"location\": \"https://api.example.com\",
    \"databaseUrl\": \"https://db.example.com\"
}")

HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
BODY=$(echo "$RESPONSE" | sed '$d')

# Accept both 200 and 201 for source creation.
if [ "$HTTP_CODE" -eq 200 ] || [ "$HTTP_CODE" -eq 201 ]; then
    echo -e "  ${GREEN}✓${NC} Source creation (HTTP $HTTP_CODE)"
    SOURCE_ID=$(echo "$BODY" | jq -r '.id')
    if [ -n "$SOURCE_ID" ] && [ "$SOURCE_ID" != "null" ]; then
        echo -e "  ${GREEN}→${NC} Source ID: $SOURCE_ID"
    fi
else
    echo -e "  ${RED}✗${NC} Source creation (Expected: 200/201, Got: $HTTP_CODE)"
fi

# Step 9: Create Application.
print_header "Step 9: Create Application"
RESPONSE=$(api_call "POST" "/index.php/apps/openregister/api/applications" "{
    \"name\": \"Test Application\",
    \"description\": \"Application for testing CRUD\"
}")

HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
BODY=$(echo "$RESPONSE" | sed '$d')

# Accept both 200 and 201 for application creation.
if [ "$HTTP_CODE" -eq 200 ] || [ "$HTTP_CODE" -eq 201 ]; then
    echo -e "  ${GREEN}✓${NC} Application creation (HTTP $HTTP_CODE)"
    APPLICATION_ID=$(echo "$BODY" | jq -r '.id')
    if [ -n "$APPLICATION_ID" ] && [ "$APPLICATION_ID" != "null" ]; then
        echo -e "  ${GREEN}→${NC} Application ID: $APPLICATION_ID"
    fi
else
    echo -e "  ${RED}✗${NC} Application creation (Expected: 200/201, Got: $HTTP_CODE)"
fi

# Step 10: Create Agent.
print_header "Step 10: Create Agent"
RESPONSE=$(api_call "POST" "/index.php/apps/openregister/api/agents" "{
    \"name\": \"Test Agent\",
    \"description\": \"Agent for testing CRUD\",
    \"type\": \"ai_assistant\"
}")

HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
BODY=$(echo "$RESPONSE" | sed '$d')

# Accept both 200 and 201 for agent creation.
if [ "$HTTP_CODE" -eq 200 ] || [ "$HTTP_CODE" -eq 201 ]; then
    echo -e "  ${GREEN}✓${NC} Agent creation (HTTP $HTTP_CODE)"
    AGENT_ID=$(echo "$BODY" | jq -r '.id')
    if [ -n "$AGENT_ID" ] && [ "$AGENT_ID" != "null" ]; then
        echo -e "  ${GREEN}→${NC} Agent ID: $AGENT_ID"
    fi
else
    echo -e "  ${RED}✗${NC} Agent creation (Expected: 200/201, Got: $HTTP_CODE)"
fi

# Step 11: Create Configuration.
print_header "Step 11: Create Configuration"
RESPONSE=$(api_call "POST" "/index.php/apps/openregister/api/configurations" "{
    \"title\": \"Test Configuration\",
    \"description\": \"Configuration for testing CRUD\",
    \"data\": {\"setting1\": \"value1\"}
}")

HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
BODY=$(echo "$RESPONSE" | sed '$d')

# Accept both 200 and 201 for configuration creation.
if [ "$HTTP_CODE" -eq 200 ] || [ "$HTTP_CODE" -eq 201 ]; then
    echo -e "  ${GREEN}✓${NC} Configuration creation (HTTP $HTTP_CODE)"
    CONFIG_ID=$(echo "$BODY" | jq -r '.id')
    if [ -n "$CONFIG_ID" ] && [ "$CONFIG_ID" != "null" ]; then
        echo -e "  ${GREEN}→${NC} Configuration ID: $CONFIG_ID"
    fi
else
    echo -e "  ${RED}✗${NC} Configuration creation (Expected: 200/201, Got: $HTTP_CODE)"
fi

# Step 12: Update Register (respecting RBAC context).
print_header "Step 12: Update Register"
RESPONSE=$(api_call "PUT" "/index.php/apps/openregister/api/registers/$REGISTER_ID" "{
    \"title\": \"CRUD Test Register - Updated\",
    \"description\": \"Register updated during CRUD test\"
}")

HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
BODY=$(echo "$RESPONSE" | sed '$d')

assert_status 200 "$HTTP_CODE" "Register update"

# Step 13: Update Schema (respecting RBAC context).
print_header "Step 13: Update Schema"
RESPONSE=$(api_call "PUT" "/index.php/apps/openregister/api/schemas/$SCHEMA_ID" "{
    \"title\": \"Person Schema - Updated\",
    \"description\": \"Schema updated during CRUD test\"
}")

HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
BODY=$(echo "$RESPONSE" | sed '$d')

assert_status 200 "$HTTP_CODE" "Schema update"

# Step 14: Update Source.
print_header "Step 14: Update Source"
if [ -n "$SOURCE_ID" ]; then
    RESPONSE=$(api_call "PUT" "/index.php/apps/openregister/api/sources/$SOURCE_ID" "{
        \"title\": \"Test API Source - Updated\",
        \"description\": \"Updated during CRUD test\"
    }")
    
    HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
    assert_status 200 "$HTTP_CODE" "Source update"
fi

# Step 15: Update Application.
print_header "Step 15: Update Application"
if [ -n "$APPLICATION_ID" ]; then
    RESPONSE=$(api_call "PUT" "/index.php/apps/openregister/api/applications/$APPLICATION_ID" "{
        \"name\": \"Test Application - Updated\",
        \"description\": \"Updated during CRUD test\"
    }")
    
    HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
    assert_status 200 "$HTTP_CODE" "Application update"
fi

# Step 16: Update Agent.
print_header "Step 16: Update Agent"
if [ -n "$AGENT_ID" ]; then
    RESPONSE=$(api_call "PUT" "/index.php/apps/openregister/api/agents/$AGENT_ID" "{
        \"name\": \"Test Agent - Updated\",
        \"description\": \"Updated during CRUD test\"
    }")
    
    HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
    assert_status 200 "$HTTP_CODE" "Agent update"
fi

# Step 17: Update Configuration.
print_header "Step 17: Update Configuration"
if [ -n "$CONFIG_ID" ]; then
    RESPONSE=$(api_call "PUT" "/index.php/apps/openregister/api/configurations/$CONFIG_ID" "{
        \"title\": \"Test Configuration - Updated\",
        \"data\": {\"setting1\": \"value1\", \"setting2\": \"value2\"}
    }")
    
    HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
    assert_status 200 "$HTTP_CODE" "Configuration update"
fi

# Step 18: Test Cascade Protection - Cannot Delete Schema with Objects.
print_header "Step 18: Test Cascade Protection"
RESPONSE=$(api_call "DELETE" "/index.php/apps/openregister/api/schemas/$SCHEMA_ID" "")

HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)

TOTAL_TESTS=$((TOTAL_TESTS + 1))
if [ "$HTTP_CODE" -ne 204 ]; then
    echo -e "  ${GREEN}✓${NC} Cannot delete schema with objects (HTTP $HTTP_CODE)"
    TESTS_PASSED=$((TESTS_PASSED + 1))
else
    echo -e "  ${RED}✗${NC} Schema should not be deletable with objects"
    TESTS_FAILED=$((TESTS_FAILED + 1))
fi

# Step 19: Delete Object 1.
print_header "Step 19: Delete Object 1"
RESPONSE=$(api_call "DELETE" "/index.php/apps/openregister/api/objects/$REGISTER_SLUG/$SCHEMA_SLUG/$OBJECT1_UUID" "")

HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
assert_status 204 "$HTTP_CODE" "Object 1 deletion"

# Step 20: Verify Object 1 is Deleted.
print_header "Step 20: Verify Object 1 Deletion"
RESPONSE=$(api_call "GET" "/index.php/apps/openregister/api/objects/$REGISTER_SLUG/$SCHEMA_SLUG/$OBJECT1_UUID" "")

HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
assert_status 404 "$HTTP_CODE" "Object 1 not found after deletion"

# Step 21: Delete Object 2.
print_header "Step 21: Delete Object 2"
RESPONSE=$(api_call "DELETE" "/index.php/apps/openregister/api/objects/$REGISTER_SLUG/$SCHEMA_SLUG/$OBJECT2_UUID" "")

HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
assert_status 204 "$HTTP_CODE" "Object 2 deletion"

# Step 22: Delete Configuration.
print_header "Step 22: Delete Configuration"
if [ -n "$CONFIG_ID" ]; then
    RESPONSE=$(api_call "DELETE" "/index.php/apps/openregister/api/configurations/$CONFIG_ID" "")
    
    HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
    assert_status 204 "$HTTP_CODE" "Configuration deletion"
fi

# Step 23: Delete Agent.
print_header "Step 23: Delete Agent"
if [ -n "$AGENT_ID" ]; then
    RESPONSE=$(api_call "DELETE" "/index.php/apps/openregister/api/agents/$AGENT_ID" "")
    
    HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
    assert_status 204 "$HTTP_CODE" "Agent deletion"
fi

# Step 24: Delete Application.
print_header "Step 24: Delete Application"
if [ -n "$APPLICATION_ID" ]; then
    RESPONSE=$(api_call "DELETE" "/index.php/apps/openregister/api/applications/$APPLICATION_ID" "")
    
    HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
    assert_status 204 "$HTTP_CODE" "Application deletion"
fi

# Step 25: Delete Source.
print_header "Step 25: Delete Source"
if [ -n "$SOURCE_ID" ]; then
    RESPONSE=$(api_call "DELETE" "/index.php/apps/openregister/api/sources/$SOURCE_ID" "")
    
    HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
    assert_status 204 "$HTTP_CODE" "Source deletion"
fi

# Step 26: Delete Schema (After Objects Removed).
print_header "Step 26: Delete Schema"
RESPONSE=$(api_call "DELETE" "/index.php/apps/openregister/api/schemas/$SCHEMA_ID" "")

HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
assert_status 204 "$HTTP_CODE" "Schema deletion"

# Step 27: Delete Register (After Schema Removed).
print_header "Step 27: Delete Register"
RESPONSE=$(api_call "DELETE" "/index.php/apps/openregister/api/registers/$REGISTER_ID" "")

HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
assert_status 204 "$HTTP_CODE" "Register deletion"

# Step 28: Delete Organization (cleanup).
if [ -n "$ORG_UUID" ]; then
    print_header "Step 28: Delete Organization"
    RESPONSE=$(api_call "DELETE" "/index.php/apps/openregister/api/organisations/$ORG_UUID" "")
    
    HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
    assert_status 204 "$HTTP_CODE" "Organization deletion"
fi

# Print summary.
print_header "Test Summary"
echo -e "  Total Tests:  ${BLUE}$TOTAL_TESTS${NC}"
echo -e "  Passed:       ${GREEN}$TESTS_PASSED${NC}"
echo -e "  Failed:       ${RED}$TESTS_FAILED${NC}"

if [ "$TESTS_FAILED" -eq 0 ]; then
    echo -e "\n${GREEN}🎉 All tests passed!${NC}\n"
    exit 0
else
    echo -e "\n${RED}❌ Some tests failed.${NC}\n"
    exit 1
fi

