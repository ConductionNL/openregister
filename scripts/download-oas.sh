#!/bin/bash

# OpenAPI Specification Download Script for OpenRegister
# Downloads OAS specifications for all registers for validation

set -e

echo "🔍 Discovering registers..."

# Get list of all registers
REGISTERS=$(docker exec -u 33 master-nextcloud-1 bash -c "curl -s -u 'admin:admin' -H 'Content-Type: application/json' -X GET 'http://localhost/index.php/apps/openregister/api/registers'" | jq -r '.results[] | "\(.id):\(.slug):\(.title)"')

echo "📥 Downloading OAS specifications..."

SUCCESS_COUNT=0
FAILED_COUNT=0
FAILED_REGISTERS=()

for REGISTER_INFO in $REGISTERS; do
    IFS=':' read -r REGISTER_ID REGISTER_SLUG REGISTER_TITLE <<< "$REGISTER_INFO"
    
    echo "  ⬇️  Register $REGISTER_ID ($REGISTER_SLUG): $REGISTER_TITLE"
    
    OUTPUT_FILE="oas-${REGISTER_SLUG}-${REGISTER_ID}.json"
    
    # Download OAS with error handling
    if docker exec -u 33 master-nextcloud-1 bash -c "curl -s -u 'admin:admin' -H 'Content-Type: application/json' -X GET 'http://localhost/index.php/apps/openregister/api/registers/$REGISTER_ID/oas'" > "$OUTPUT_FILE" 2>/dev/null; then
        
        # Check if the file contains valid JSON (not an error page)
        if jq empty "$OUTPUT_FILE" 2>/dev/null && grep -q '"openapi"' "$OUTPUT_FILE"; then
            FILE_SIZE=$(stat -f%z "$OUTPUT_FILE" 2>/dev/null || stat -c%s "$OUTPUT_FILE" 2>/dev/null || echo "unknown")
            echo "    ✅ Downloaded successfully ($FILE_SIZE bytes)"
            ((SUCCESS_COUNT++))
        else
            echo "    ❌ Downloaded invalid/error response"
            rm "$OUTPUT_FILE"
            FAILED_REGISTERS+=("$REGISTER_ID ($REGISTER_SLUG)")
            ((FAILED_COUNT++))
        fi
    else
        echo "    ❌ Download failed"
        [ -f "$OUTPUT_FILE" ] && rm "$OUTPUT_FILE"
        FAILED_REGISTERS+=("$REGISTER_ID ($REGISTER_SLUG)")
        ((FAILED_COUNT++))
    fi
done

echo ""
echo "📊 Download Summary:"
echo "  ✅ Successful: $SUCCESS_COUNT"
echo "  ❌ Failed: $FAILED_COUNT"

if [ $FAILED_COUNT -gt 0 ]; then
    echo ""
    echo "⚠️  Failed registers:"
    for FAILED in "${FAILED_REGISTERS[@]}"; do
        echo "    - $FAILED"
    done
fi

echo ""
echo "🎯 Available OAS files:"
ls -la oas-*.json 2>/dev/null | awk '{print "  " $9 " (" $5 " bytes)"}'

if [ $SUCCESS_COUNT -gt 0 ]; then
    echo ""
    echo "🧪 To validate all specifications:"
    echo "  npm run validate-oas"
    echo ""
    echo "🧪 To validate a specific specification:"
    echo "  spectral lint oas-[register-slug]-[id].json"
fi
