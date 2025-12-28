#!/bin/bash

# n8n Integration Test Script
# This script tests the n8n workflow configuration endpoints

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
NEXTCLOUD_HOST="master-nextcloud-1"
USER="admin"
PASS="admin"
BASE_URL="http://${NEXTCLOUD_HOST}/apps/openregister/api/settings/n8n"

echo "========================================="
echo "n8n Integration Tests"
echo "========================================="
echo ""

# Test 1: Get n8n settings
echo -e "${YELLOW}Test 1: Get n8n settings${NC}"
docker exec -u 33 ${NEXTCLOUD_HOST} curl -s -u "${USER}:${PASS}" \
  -H "OCS-APIRequest: true" \
  "${BASE_URL}"
echo ""
echo ""

# Test 2: Update n8n settings (enable)
echo -e "${YELLOW}Test 2: Update n8n settings${NC}"
docker exec -u 33 ${NEXTCLOUD_HOST} curl -s -u "${USER}:${PASS}" \
  -X POST \
  -H "Content-Type: application/json" \
  -H "OCS-APIRequest: true" \
  -d '{"enabled":false,"url":"http://master-n8n-1:5678","apiKey":"test_key","project":"openregister"}' \
  "${BASE_URL}"
echo ""
echo ""

# Test 3: Verify routes are registered
echo -e "${YELLOW}Test 3: Verify n8n routes are registered${NC}"
docker exec -u 33 ${NEXTCLOUD_HOST} bash -c "cd /var/www/html/apps-extra/openregister && php occ route:list | grep n8n"
echo ""
echo ""

# Test 4: Check if N8nSettingsController exists
echo -e "${YELLOW}Test 4: Verify N8nSettingsController class exists${NC}"
if docker exec -u 33 ${NEXTCLOUD_HOST} bash -c "test -f /var/www/html/apps-extra/openregister/lib/Controller/Settings/N8nSettingsController.php"; then
    echo -e "${GREEN}✓ N8nSettingsController.php exists${NC}"
else
    echo -e "${RED}✗ N8nSettingsController.php not found${NC}"
fi
echo ""

# Test 5: Check if N8nConfiguration component exists
echo -e "${YELLOW}Test 5: Verify N8nConfiguration Vue component exists${NC}"
if docker exec -u 33 ${NEXTCLOUD_HOST} bash -c "test -f /var/www/html/apps-extra/openregister/src/views/settings/sections/N8nConfiguration.vue"; then
    echo -e "${GREEN}✓ N8nConfiguration.vue exists${NC}"
else
    echo -e "${RED}✗ N8nConfiguration.vue not found${NC}"
fi
echo ""

# Test 6: Check PHP syntax
echo -e "${YELLOW}Test 6: Check PHP syntax${NC}"
docker exec -u 33 ${NEXTCLOUD_HOST} bash -c "php -l /var/www/html/apps-extra/openregister/lib/Controller/Settings/N8nSettingsController.php"
echo ""

echo "========================================="
echo "Tests Complete"
echo "========================================="

