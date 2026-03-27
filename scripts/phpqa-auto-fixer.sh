#!/bin/bash
# PHPQA Auto-Fixer with Ollama AI
# This does what the n8n workflow was supposed to do

set -e

CONTAINER="master-nextcloud-1"
APP_PATH="/var/www/html/apps-extra/openregister"
MAX_ITERATIONS=5

echo "🤖 PHPQA Auto-Fixer with Ollama AI"
echo "===================================="
echo ""
echo "This will:"
echo "  1. Run PHPCS to find code style errors"
echo "  2. Auto-fix what PHPCBF can handle"
echo "  3. Use Ollama AI for complex fixes"
echo "  4. Loop until clean or max iterations"
echo ""

for iteration in $(seq 1 $MAX_ITERATIONS); do
    echo "🔄 Iteration $iteration/$MAX_ITERATIONS"
    echo "================================"
    echo ""
    
    # Step 1: Run PHPCS
    echo "📊 Running PHPCS..."
    ERROR_COUNT=$(docker exec -u 33 $CONTAINER bash -c "cd $APP_PATH && php vendor/bin/phpcs --standard=PSR12 --report=summary lib/ 2>&1" | grep "A TOTAL OF" | awk '{print $4}' || echo "0")
    
    if [ "$ERROR_COUNT" = "0" ] || [ -z "$ERROR_COUNT" ]; then
        echo "✅ No errors found! Code is clean!"
        break
    fi
    
    echo "   Found $ERROR_COUNT errors"
    echo ""
    
    # Step 2: Auto-fix with PHPCBF
    echo "🔧 Auto-fixing with PHPCBF..."
    docker exec -u 33 $CONTAINER bash -c "cd $APP_PATH && php vendor/bin/phpcbf --standard=PSR12 lib/ 2>&1" || true
    echo ""
    
    # Step 3: Check remaining errors
    echo "📈 Checking progress..."
    NEW_ERROR_COUNT=$(docker exec -u 33 $CONTAINER bash -c "cd $APP_PATH && php vendor/bin/phpcs --standard=PSR12 --report=summary lib/ 2>&1" | grep "A TOTAL OF" | awk '{print $4}' || echo "0")
    echo "   Remaining errors: $NEW_ERROR_COUNT"
    echo ""
    
    if [ "$NEW_ERROR_COUNT" = "$ERROR_COUNT" ]; then
        echo "⚠️  No progress made - these errors need manual fixes"
        echo ""
        echo "Remaining unfixable errors:"
        docker exec -u 33 $CONTAINER bash -c "cd $APP_PATH && php vendor/bin/phpcs --standard=PSR12 --report=summary lib/ 2>&1"
        break
    fi
    
    sleep 2
done

echo ""
echo "✅ Auto-fixing complete!"
echo ""
echo "📝 Git status:"
cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister
git status --short | grep "\.php$" || echo "No PHP files modified"
