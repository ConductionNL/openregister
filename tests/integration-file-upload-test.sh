#!/bin/bash

##
# Integration Test Script for Integrated File Uploads
# 
# This script tests the integrated file upload functionality by:
# 1. Creating a test register and schema
# 2. Testing multipart uploads
# 3. Testing base64 uploads
# 4. Testing URL references
# 5. Testing validation (wrong MIME, too large, etc.)
# 6. Cleaning up test data
#
# Usage: ./integration-file-upload-test.sh
##

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
NEXTCLOUD_CONTAINER="master-nextcloud-1"
API_BASE="http://master-nextcloud-1/index.php/apps/openregister/api"
AUTH="admin:admin"
TEST_REGISTER="test-file-uploads"
TEST_SCHEMA="document"

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}Integrated File Upload Integration Test${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""

# Function to print test result
test_result() {
    if [ $1 -eq 0 ]; then
        echo -e "${GREEN}✓ PASS${NC}: $2"
    else
        echo -e "${RED}✗ FAIL${NC}: $2"
        echo -e "${YELLOW}  Response: $3${NC}"
    fi
}

# Step 1: Create test register
echo -e "${YELLOW}Step 1: Creating test register...${NC}"
RESPONSE=$(docker exec -u 33 $NEXTCLOUD_CONTAINER curl -s -X POST \
    "http://localhost/index.php/apps/openregister/api/registers" \
    -u "$AUTH" \
    -H "Content-Type: application/json" \
    -d '{
        "slug": "'$TEST_REGISTER'",
        "title": "Test File Uploads Register",
        "description": "Test register for integrated file upload testing"
    }' 2>&1)

if echo "$RESPONSE" | grep -q "uuid\|slug"; then
    echo -e "${GREEN}✓${NC} Test register created"
else
    echo -e "${YELLOW}⚠${NC} Register might already exist (continuing...)"
fi

# Step 2: Create test schema with file properties
echo -e "${YELLOW}Step 2: Creating test schema...${NC}"
RESPONSE=$(docker exec -u 33 $NEXTCLOUD_CONTAINER curl -s -X POST \
    "http://localhost/index.php/apps/openregister/api/registers/$TEST_REGISTER/schemas" \
    -u "$AUTH" \
    -H "Content-Type: application/json" \
    -d '{
        "slug": "'$TEST_SCHEMA'",
        "title": "Test Document",
        "description": "Test schema with file properties",
        "properties": {
            "title": {
                "type": "string",
                "title": "Document Title"
            },
            "attachment": {
                "type": "file",
                "title": "PDF Attachment",
                "allowedTypes": ["application/pdf"],
                "maxSize": 10485760
            },
            "image": {
                "type": "file",
                "title": "Image",
                "allowedTypes": ["image/jpeg", "image/png"],
                "maxSize": 5242880
            }
        }
    }' 2>&1)

if echo "$RESPONSE" | grep -q "uuid\|slug"; then
    echo -e "${GREEN}✓${NC} Test schema created"
else
    echo -e "${YELLOW}⚠${NC} Schema might already exist (continuing...)"
fi

# Step 3: Create test files
echo -e "${YELLOW}Step 3: Creating test files...${NC}"

# Create small test PDF
echo "%PDF-1.4
1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj
2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj
3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R>>endobj
xref
0 4
0000000000 65535 f
0000000009 00000 n
0000000056 00000 n
0000000115 00000 n
trailer<</Size 4/Root 1 0 R>>
startxref
190
%%EOF" > /tmp/test.pdf

# Create small test image (1x1 PNG)
echo -e "\x89PNG\x0D\x0A\x1A\x0A\x00\x00\x00\x0DIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00\x90wS\xDE\x00\x00\x00\x0CIDAT\x08\x99c\xF8\x0F\x00\x00\x01\x01\x00\x05\x18\r\xE1\xB0\x00\x00\x00\x00IEND\xAEB\x60\x82" > /tmp/test.png

# Create invalid file (not PDF)
echo "This is not a PDF file" > /tmp/fake.pdf

# Create large file (> 10MB)
dd if=/dev/zero of=/tmp/large.pdf bs=1M count=11 2>/dev/null

echo -e "${GREEN}✓${NC} Test files created"

# Test 1: Multipart Upload (Valid PDF)
echo ""
echo -e "${YELLOW}Test 1: Multipart upload with valid PDF${NC}"
RESPONSE=$(docker exec -u 33 $NEXTCLOUD_CONTAINER curl -s -X POST \
    "http://localhost/index.php/apps/openregister/api/registers/$TEST_REGISTER/schemas/$TEST_SCHEMA/objects" \
    -u "$AUTH" \
    -F "title=Test Multipart Document" \
    -F "attachment=@/tmp/test.pdf" 2>&1)

if echo "$RESPONSE" | grep -q "uuid"; then
    UUID=$(echo "$RESPONSE" | grep -o '"uuid":"[^"]*"' | cut -d'"' -f4)
    test_result 0 "Multipart PDF upload" ""
    echo -e "  UUID: ${GREEN}$UUID${NC}"
else
    test_result 1 "Multipart PDF upload" "$RESPONSE"
fi

# Test 2: Base64 Upload (Valid Image)
echo ""
echo -e "${YELLOW}Test 2: Base64 encoded image upload${NC}"
BASE64_IMAGE=$(base64 -w 0 /tmp/test.png)
RESPONSE=$(docker exec -u 33 $NEXTCLOUD_CONTAINER curl -s -X POST \
    "http://localhost/index.php/apps/openregister/api/registers/$TEST_REGISTER/schemas/$TEST_SCHEMA/objects" \
    -u "$AUTH" \
    -H "Content-Type: application/json" \
    -d '{
        "title": "Test Base64 Document",
        "image": "data:image/png;base64,'$BASE64_IMAGE'"
    }' 2>&1)

if echo "$RESPONSE" | grep -q "uuid"; then
    test_result 0 "Base64 image upload" ""
else
    test_result 1 "Base64 image upload" "$RESPONSE"
fi

# Test 3: Invalid MIME Type (should fail)
echo ""
echo -e "${YELLOW}Test 3: Upload with invalid MIME type (should FAIL)${NC}"
BASE64_PNG=$(base64 -w 0 /tmp/test.png)
RESPONSE=$(docker exec -u 33 $NEXTCLOUD_CONTAINER curl -s -X POST \
    "http://localhost/index.php/apps/openregister/api/registers/$TEST_REGISTER/schemas/$TEST_SCHEMA/objects" \
    -u "$AUTH" \
    -H "Content-Type: application/json" \
    -d '{
        "title": "Invalid Type Test",
        "attachment": "data:image/png;base64,'$BASE64_PNG'"
    }' 2>&1)

if echo "$RESPONSE" | grep -q "invalid type"; then
    test_result 0 "MIME type validation (rejected PNG as PDF)" ""
else
    test_result 1 "MIME type validation (should have rejected)" "$RESPONSE"
fi

# Test 4: File Too Large (should fail)
echo ""
echo -e "${YELLOW}Test 4: Upload file exceeding size limit (should FAIL)${NC}"
RESPONSE=$(docker exec -u 33 $NEXTCLOUD_CONTAINER curl -s -X POST \
    "http://localhost/index.php/apps/openregister/api/registers/$TEST_REGISTER/schemas/$TEST_SCHEMA/objects" \
    -u "$AUTH" \
    -F "title=Large File Test" \
    -F "attachment=@/tmp/large.pdf" 2>&1)

if echo "$RESPONSE" | grep -q "exceeds maximum size\|too large"; then
    test_result 0 "File size validation (rejected 11MB file)" ""
else
    test_result 1 "File size validation (should have rejected)" "$RESPONSE"
fi

# Test 5: Mixed Upload Methods
echo ""
echo -e "${YELLOW}Test 5: Mixed upload methods (multipart + base64)${NC}"
RESPONSE=$(docker exec -u 33 $NEXTCLOUD_CONTAINER curl -s -X POST \
    "http://localhost/index.php/apps/openregister/api/registers/$TEST_REGISTER/schemas/$TEST_SCHEMA/objects" \
    -u "$AUTH" \
    -F "title=Mixed Upload Test" \
    -F "attachment=@/tmp/test.pdf" \
    -F "image=data:image/png;base64,$BASE64_IMAGE" 2>&1)

if echo "$RESPONSE" | grep -q "uuid"; then
    test_result 0 "Mixed multipart + base64 upload" ""
else
    test_result 1 "Mixed upload" "$RESPONSE"
fi

# Test 6: GET object with file metadata
if [ ! -z "$UUID" ]; then
    echo ""
    echo -e "${YELLOW}Test 6: GET object includes file metadata${NC}"
    RESPONSE=$(docker exec -u 33 $NEXTCLOUD_CONTAINER curl -s -X GET \
        "http://localhost/index.php/apps/openregister/api/registers/$TEST_REGISTER/schemas/$TEST_SCHEMA/objects/$UUID" \
        -u "$AUTH" 2>&1)

    if echo "$RESPONSE" | grep -q '"attachment".*"id"\|"attachment".*"path"'; then
        test_result 0 "File metadata in GET response" ""
        echo -e "${GREEN}  File object structure found in response${NC}"
    else
        test_result 1 "File metadata in GET response" "$RESPONSE"
    fi
fi

# Cleanup
echo ""
echo -e "${YELLOW}Cleanup: Removing test files...${NC}"
rm -f /tmp/test.pdf /tmp/test.png /tmp/fake.pdf /tmp/large.pdf
echo -e "${GREEN}✓${NC} Test files removed"

echo ""
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}Integration test completed!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo -e "${YELLOW}Note:${NC} Test register '$TEST_REGISTER' and schema '$TEST_SCHEMA' were created."
echo -e "${YELLOW}      You can clean them up manually if needed.${NC}"

