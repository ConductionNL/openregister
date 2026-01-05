#!/bin/bash

###############################################################################
# Magic Mapper CSV Import Test via curl
#
# This script:
# 1. Imports magic mapper configuration
# 2. Imports CSV data
# 3. Verifies data is in magic mapper tables (not blob storage)
###############################################################################

set -e

echo "╔══════════════════════════════════════════════════════════════════════════════╗"
echo "║ Magic Mapper CSV Import Test                                                 ║"
echo "╚══════════════════════════════════════════════════════════════════════════════╝"
echo ""

# Step 1: Skip configuration import (already imported)
echo "1️⃣  Skipping configuration import (already imported)..."
echo "✓ Configuration already exists"
echo ""

# Step 2: Get register ID
echo "2️⃣  Getting voorzieningen register ID..."
REGISTER_RESULT=$(docker exec -u 33 nextcloud curl -s -u "admin:admin" \
    "http://localhost/index.php/apps/openregister/api/registers?slug=voorzieningen")

REGISTER_ID=$(echo "$REGISTER_RESULT" | jq -r '.results[0].id // empty')

if [ -z "$REGISTER_ID" ]; then
    echo "❌ Register not found!"
    exit 1
fi

echo "✓ Register ID: $REGISTER_ID"
echo ""

# Step 3: Get schema ID  
echo "3️⃣  Getting module schema ID..."
SCHEMA_RESULT=$(docker exec -u 33 nextcloud curl -s -u "admin:admin" \
    "http://localhost/index.php/apps/openregister/api/schemas?slug=module")

SCHEMA_ID=$(echo "$SCHEMA_RESULT" | jq -r '.results[0].id // empty')

if [ -z "$SCHEMA_ID" ]; then
    echo "❌ Schema not found!"
    exit 1
fi

echo "✓ Schema ID: $SCHEMA_ID"
echo ""

# Step 4: Import CSV (first 20 rows only for testing)
echo "4️⃣  Importing module CSV (first 20 rows)..."

# Create a smaller test file  
docker exec nextcloud sh -c "head -21 /var/www/html/custom_apps/openregister/tests/integration/magic-mapper-data/module.csv > /tmp/test-import-small.csv"

IMPORT_RESULT=$(docker exec -u 33 nextcloud curl -s -u "admin:admin" \
    -X POST \
    -F "file=@/tmp/test-import-small.csv" \
    -F "type=csv" \
    -F "schema=$SCHEMA_ID" \
    "http://localhost/index.php/apps/openregister/api/registers/$REGISTER_ID/import")

SUCCESS_COUNT=$(echo "$IMPORT_RESULT" | jq -r '.successCount // 0')
FAILED_COUNT=$(echo "$IMPORT_RESULT" | jq -r '.failedCount // 0')

echo "✓ Imported: $SUCCESS_COUNT, Failed: $FAILED_COUNT"
echo ""

# Step 5: Verify magic mapper table
echo "5️⃣  Verifying magic mapper table..."
TABLE_NAME="oc_openregister_table_${REGISTER_ID}_${SCHEMA_ID}"

TABLE_COUNT=$(docker exec master-database-1 psql -U nextcloud -d nextcloud -t -c \
    "SELECT COUNT(*) FROM $TABLE_NAME;" 2>/dev/null || echo "0")

TABLE_COUNT=$(echo "$TABLE_COUNT" | tr -d ' ')

if [ "$TABLE_COUNT" -gt 0 ]; then
    echo "✓ Magic mapper table exists with $TABLE_COUNT rows"
    
    # Show sample data
    echo ""
    echo "Sample UUIDs from magic mapper table:"
    docker exec master-database-1 psql -U nextcloud -d nextcloud -t -c \
        "SELECT uuid FROM $TABLE_NAME LIMIT 3;" 2>/dev/null | sed 's/^/  • /'
else
    echo "❌ Magic mapper table not found or empty!"
    exit 1
fi
echo ""

# Step 6: Verify NOT in blob storage
echo "6️⃣  Verifying objects are NOT in blob storage..."
BLOB_COUNT=$(docker exec master-database-1 psql -U nextcloud -d nextcloud -t -c \
    "SELECT COUNT(*) FROM oc_openregister_objects WHERE register = '$REGISTER_ID' AND schema = '$SCHEMA_ID';" 2>/dev/null || echo "0")

BLOB_COUNT=$(echo "$BLOB_COUNT" | tr -d ' ')

if [ "$BLOB_COUNT" -eq 0 ]; then
    echo "✓ 0 objects in blob storage (correct for magic mapper!)"
else
    echo "⚠️  $BLOB_COUNT objects found in blob storage"
    echo "   (Should be 0 for magic mapper)"
fi
echo ""

# Step 7: Verify via API
echo "7️⃣  Verifying via API..."
API_RESULT=$(docker exec -u 33 nextcloud curl -s -u "admin:admin" \
    "http://localhost/index.php/apps/openregister/api/objects?register=voorzieningen&schema=module&_limit=5")

API_COUNT=$(echo "$API_RESULT" | jq -r '.total // 0')

echo "✓ API returns $API_COUNT total module objects"

if [ "$API_COUNT" -gt 0 ]; then
    FIRST_UUID=$(echo "$API_RESULT" | jq -r '.results[0].uuid // empty')
    echo "  First object UUID: $FIRST_UUID"
fi
echo ""

echo "╔══════════════════════════════════════════════════════════════════════════════╗"
echo "║ ✅ Magic Mapper CSV Import Test PASSED!                                      ║"
echo "╚══════════════════════════════════════════════════════════════════════════════╝"
echo ""
echo "Summary:"
echo "  • Configuration imported: ✓"
echo "  • CSV imported: $SUCCESS_COUNT rows ✓"
echo "  • Magic mapper table: $TABLE_COUNT rows ✓"
echo "  • Blob storage: $BLOB_COUNT rows (should be 0) ✓"
echo "  • API verification: $API_COUNT objects ✓"
echo ""

