#!/bin/bash
# Manual PHPQA Auto-Fixer - Does what the n8n workflow should do
# Run this to test the concept without n8n

set -e

CONTAINER="master-nextcloud-1"
APP_PATH="/var/www/html/apps-extra/openregister"
MAX_ITER=3

echo "🚀 Manual PHPQA Auto-Fixer"
echo "==========================="
echo ""

for i in $(seq 1 $MAX_ITER); do
    echo "🔄 Iteration $i/$MAX_ITER"
    echo ""
    
    # Run PHPCS and get first 5 fixable errors
    echo "  📊 Running PHPCS..."
    ERRORS=$(docker exec -u 33 $CONTAINER bash -c "cd $APP_PATH && php vendor/bin/phpcs --standard=PSR12 --report=json lib/Service/ObjectService.php 2>&1" | jq -r '.files[].messages[0:5][] | select(.fixable == true) | "Line \(.line): \(.message)"' 2>/dev/null || echo "")
    
    if [ -z "$ERRORS" ]; then
        echo "  ✅ No fixable errors found!"
        break
    fi
    
    echo "  Found errors:"
    echo "$ERRORS" | head -5
    echo ""
    
    # Auto-fix with PHPCBF
    echo "  🔧 Auto-fixing with PHPCBF..."
    docker exec -u 33 $CONTAINER bash -c "cd $APP_PATH && php vendor/bin/phpcbf --standard=PSR12 lib/Service/ObjectService.php 2>&1" || true
    echo ""
    
    sleep 2
done

echo ""
echo "✅ Done! Check git status for changes:"
cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister
git status --short | grep "\.php$" | head -10 || echo "No PHP files modified"
