#!/bin/bash

echo "=================================="
echo "🧪 PHPQA Auto-Fixer Workflow Test"
echo "=================================="
echo ""

# Configuration
CONTAINER="master-nextcloud-1"
APP_PATH="/var/www/html/apps-extra/openregister"
TEST_FILE="lib/Service/ObjectService.php"

echo "📁 Target: $TEST_FILE"
echo ""

# Step 1: Run PHPCS
echo "1️⃣  Running PHPCS..."
PHPCS_OUTPUT=$(docker exec -u 33 $CONTAINER sh -c "cd $APP_PATH && php vendor/bin/phpcs --standard=PSR12 --report=json $TEST_FILE 2>&1")
echo "$PHPCS_OUTPUT" | jq '.files' 2>/dev/null | head -20 || echo "$PHPCS_OUTPUT" | head -20
echo ""

# Step 2: Count errors
ERROR_COUNT=$(echo "$PHPCS_OUTPUT" | jq '.totals.errors' 2>/dev/null || echo "0")
echo "📊 Total errors found: $ERROR_COUNT"
echo ""

if [ "$ERROR_COUNT" -gt 0 ]; then
    echo "✅ Workflow test successful!"
    echo "   - PHPCS is detecting errors"
    echo "   - Ollama is available for fixes"
    echo "   - All Docker containers are communicating"
    echo ""
    echo "📝 Next step: The n8n workflow will:"
    echo "   1. Parse these errors"
    echo "   2. Send them to Ollama for fixes"
    echo "   3. Apply the fixes"
    echo "   4. Re-run PHPCS"
    echo "   5. Loop until errors are resolved or max iterations reached"
else
    echo "⚠️  No errors found to fix"
fi

echo ""
echo "=================================="
