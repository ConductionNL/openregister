#!/bin/bash
# Newman Test Runner - Iteration 1
# Run this script to execute Newman tests and capture results

echo "=========================================="
echo "🚀 ITERATION 1: Running Newman Tests"
echo "=========================================="
echo ""
echo "Testing fixes:"
echo "  ✅ hardValidation enabled on main schema"
echo "  ✅ MariaDbFacetHandler syntax fixed"
echo ""
echo "Expected: 75% pass rate (123/165 tests)"
echo ""

cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister

docker exec -u 33 master-nextcloud-1 newman run \
  /var/www/html/apps-extra/openregister/tests/integration/openregister-crud.postman_collection.json \
  --reporters cli \
  --reporter-cli-no-assertions \
  --reporter-cli-no-console

echo ""
echo "=========================================="
echo "📊 Test Run Complete"
echo "=========================================="
